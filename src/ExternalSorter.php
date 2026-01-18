<?php

declare(strict_types=1);

namespace Andileco\CsvSort;

use Andileco\CsvSort\Comparator\ComparatorInterface;
use Andileco\CsvSort\Comparator\StringComparator;
use Andileco\CsvSort\Exception\ColumnNotFoundException;
use Andileco\CsvSort\Exception\InvalidConfigurationException;
use Andileco\CsvSort\Exception\IoException;
use League\Csv\Reader;
use League\Csv\Writer;

/**
 * External merge sort implementation for large CSV files
 * 
 * This class implements a multi-pass external merge sort algorithm that can handle
 * CSV files larger than available memory by:
 * 1. Splitting the file into memory-sized chunks
 * 2. Sorting each chunk in memory
 * 3. Writing chunks to temporary files
 * 4. Merging sorted chunks using a k-way merge with min-heap
 * 5. Returning a Reader for the sorted result
 */
class ExternalSorter
{
    private readonly int $memoryLimit;
    private readonly string $tempDir;
    private readonly int $mergeFactor;
    private readonly int $bufferSize;
    
    private SortMetrics $metrics;
    
    /** @var list<string> */
    private array $tempFiles = [];
    
    /**
     * @param array{memory_limit?: int, temp_dir?: string, merge_factor?: int, buffer_size?: int} $config
     */
    public function __construct(array $config = [])
    {
        $this->memoryLimit = $config['memory_limit'] ?? $this->calculateDefaultMemoryLimit();
        $this->tempDir = $config['temp_dir'] ?? sys_get_temp_dir();
        $this->mergeFactor = $config['merge_factor'] ?? 10;
        $this->bufferSize = $config['buffer_size'] ?? 8192;
        
        $this->validateConfiguration();
        $this->metrics = new SortMetrics();
    }
    
    /**
     * Sort a CSV file by one or more columns
     * 
     * @param Reader $reader The input CSV reader
     * @param string|SortColumn|list<SortColumn> $columns Column(s) to sort by
     * @param ComparatorInterface|null $comparator Comparator for single column sort
     * @return Reader A reader for the sorted CSV
     */
    public function sort(
        Reader $reader,
        string|SortColumn|array $columns,
        ?ComparatorInterface $comparator = null,
    ): Reader {
        $this->metrics = new SortMetrics();
        
        // Normalize columns parameter
        $sortColumns = $this->normalizeSortColumns($columns, $comparator);
        
        // Verify columns exist
        $this->verifyColumnsExist($reader, $sortColumns);
        
        try {
            // Phase 1: Split into sorted chunks
            $chunkFiles = $this->createSortedChunks($reader, $sortColumns);
            
            // Phase 2: Merge chunks
            $sortedFile = $this->mergeChunks($chunkFiles, $sortColumns);
            
            // Phase 3: Return reader
            $sortedReader = Reader::createFromPath($sortedFile, 'r');
            $sortedReader->setHeaderOffset(0);
            
            $this->metrics->finish();
            
            return $sortedReader;
        } finally {
            $this->cleanup();
        }
    }
    
    /**
     * Get performance metrics
     */
    public function getMetrics(): SortMetrics
    {
        return $this->metrics;
    }
    
    /**
     * Normalize columns parameter to array of SortColumn objects
     * 
     * @param string|SortColumn|list<SortColumn> $columns
     * @return list<SortColumn>
     */
    private function normalizeSortColumns(
        string|SortColumn|array $columns,
        ?ComparatorInterface $comparator,
    ): array {
        if (is_string($columns)) {
            return [new SortColumn(
                $columns,
                SortDirection::ASC,
                $comparator ?? new StringComparator(),
            )];
        }
        
        if ($columns instanceof SortColumn) {
            return [$columns];
        }
        
        if (empty($columns)) {
            throw new InvalidConfigurationException('At least one sort column must be specified');
        }
        
        return $columns;
    }
    
    /**
     * Verify that all sort columns exist in the CSV
     * 
     * @param list<SortColumn> $sortColumns
     */
    private function verifyColumnsExist(Reader $reader, array $sortColumns): void
    {
        $headers = $reader->getHeader();
        
        foreach ($sortColumns as $sortColumn) {
            if (!in_array($sortColumn->name, $headers, true)) {
                throw new ColumnNotFoundException(
                    "Column '{$sortColumn->name}' not found in CSV. Available: " . 
                    implode(', ', $headers)
                );
            }
        }
    }
    
    /**
     * Phase 1: Create sorted chunks
     * 
     * @param list<SortColumn> $sortColumns
     * @return list<string> Paths to chunk files
     */
    private function createSortedChunks(Reader $reader, array $sortColumns): array
    {
        $chunkFiles = [];
        $chunk = [];
        $chunkSize = 0;
        $maxChunkSize = $this->memoryLimit;
        
        foreach ($reader->getRecords() as $record) {
            $chunk[] = $record;
            $chunkSize += strlen(json_encode($record));
            $this->metrics->recordsProcessed++;
            
            if ($chunkSize >= $maxChunkSize) {
                $chunkFiles[] = $this->writeSortedChunk($chunk, $sortColumns);
                $chunk = [];
                $chunkSize = 0;
                $this->metrics->updatePeakMemory();
            }
        }
        
        // Write remaining records
        if (!empty($chunk)) {
            $chunkFiles[] = $this->writeSortedChunk($chunk, $sortColumns);
        }
        
        return $chunkFiles;
    }
    
    /**
     * Sort a chunk in memory and write to temp file
     * 
     * @param list<array<string, string>> $chunk
     * @param list<SortColumn> $sortColumns
     * @return string Path to chunk file
     */
    private function writeSortedChunk(array $chunk, array $sortColumns): string
    {
        // Sort chunk in memory
        usort($chunk, fn($a, $b) => $this->compareRecords($a, $b, $sortColumns));
        
        // Write to temporary file
        $tempFile = $this->createTempFile();
        $writer = Writer::createFromPath($tempFile, 'w');
        $writer->insertAll($chunk);
        
        $this->metrics->chunksCreated++;
        $this->tempFiles[] = $tempFile;
        
        return $tempFile;
    }
    
    /**
     * Phase 2: Merge sorted chunks
     * 
     * @param list<string> $chunkFiles
     * @param list<SortColumn> $sortColumns
     * @return string Path to final sorted file
     */
    private function mergeChunks(array $chunkFiles, array $sortColumns): string
    {
        // If only one chunk, it's already sorted
        if (count($chunkFiles) === 1) {
            return $chunkFiles[0];
        }
        
        // Perform multi-pass merge if needed
        while (count($chunkFiles) > 1) {
            $mergedChunks = [];
            
            // Merge in groups
            foreach (array_chunk($chunkFiles, $this->mergeFactor) as $group) {
                $mergedChunks[] = $this->kWayMerge($group, $sortColumns);
            }
            
            $chunkFiles = $mergedChunks;
            $this->metrics->mergePasses++;
        }
        
        return $chunkFiles[0];
    }
    
    /**
     * Perform k-way merge on a group of sorted files
     * 
     * @param list<string> $files
     * @param list<SortColumn> $sortColumns
     * @return string Path to merged file
     */
    private function kWayMerge(array $files, array $sortColumns): string
    {
        $readers = [];
        $heap = new \SplMinHeap();
        
        // Open all files and read first record from each
        foreach ($files as $index => $file) {
            $reader = Reader::createFromPath($file, 'r');
            $reader->setHeaderOffset(0);
            $iterator = $reader->getRecords();
            $iterator->rewind();
            
            if ($iterator->valid()) {
                $record = $iterator->current();
                $readers[$index] = $iterator;
                
                // Add to heap: [priority based on sort columns, file index, record]
                $heap->insert([
                    'record' => $record,
                    'index' => $index,
                    'priority' => $this->calculatePriority($record, $sortColumns),
                ]);
            }
        }
        
        // Create output file
        $outputFile = $this->createTempFile();
        $writer = Writer::createFromPath($outputFile, 'w');
        
        // Merge using heap
        while (!$heap->isEmpty()) {
            $item = $heap->extract();
            $writer->insertOne($item['record']);
            
            // Get next record from same file
            $index = $item['index'];
            $readers[$index]->next();
            
            if ($readers[$index]->valid()) {
                $record = $readers[$index]->current();
                $heap->insert([
                    'record' => $record,
                    'index' => $index,
                    'priority' => $this->calculatePriority($record, $sortColumns),
                ]);
            }
        }
        
        $this->tempFiles[] = $outputFile;
        return $outputFile;
    }
    
    /**
     * Compare two records based on sort columns
     * 
     * @param array<string, string> $a
     * @param array<string, string> $b
     * @param list<SortColumn> $sortColumns
     */
    private function compareRecords(array $a, array $b, array $sortColumns): int
    {
        foreach ($sortColumns as $column) {
            $valueA = $a[$column->name] ?? '';
            $valueB = $b[$column->name] ?? '';
            
            $comparison = $column->comparator->compare($valueA, $valueB);
            
            if ($comparison !== 0) {
                return $comparison * $column->direction->multiplier();
            }
        }
        
        return 0;
    }
    
    /**
     * Calculate priority for heap (for k-way merge)
     * 
     * @param array<string, string> $record
     * @param list<SortColumn> $sortColumns
     */
    private function calculatePriority(array $record, array $sortColumns): string
    {
        $parts = [];
        
        foreach ($sortColumns as $column) {
            $value = $record[$column->name] ?? '';
            $parts[] = $value;
        }
        
        return implode('|', $parts);
    }
    
    /**
     * Create a unique temporary file
     */
    private function createTempFile(): string
    {
        $tempFile = tempnam($this->tempDir, 'csvsort_');
        
        if ($tempFile === false) {
            throw new IoException("Failed to create temporary file in {$this->tempDir}");
        }
        
        $this->metrics->tempFilesCreated++;
        
        return $tempFile;
    }
    
    /**
     * Calculate default memory limit (25% of PHP memory limit)
     */
    private function calculateDefaultMemoryLimit(): int
    {
        $phpLimit = ini_get('memory_limit');
        
        if ($phpLimit === '-1') {
            return 256 * 1024 * 1024; // 256MB default
        }
        
        $bytes = $this->parseMemoryLimit($phpLimit);
        
        return (int) ($bytes * 0.25);
    }
    
    /**
     * Parse memory limit string to bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;
        
        return match($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
    
    /**
     * Validate configuration
     */
    private function validateConfiguration(): void
    {
        if ($this->memoryLimit < 1024 * 1024) {
            throw new InvalidConfigurationException('Memory limit must be at least 1MB');
        }
        
        if (!is_dir($this->tempDir) || !is_writable($this->tempDir)) {
            throw new InvalidConfigurationException("Temp directory not writable: {$this->tempDir}");
        }
        
        if ($this->mergeFactor < 2) {
            throw new InvalidConfigurationException('Merge factor must be at least 2');
        }
    }
    
    /**
     * Clean up temporary files
     */
    private function cleanup(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        
        $this->tempFiles = [];
    }
}
