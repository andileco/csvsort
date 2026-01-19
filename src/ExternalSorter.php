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
use League\Csv\TabularDataReader;

/**
 * External merge sort implementation for large CSV files.
 */
class ExternalSorter
{
  private readonly string $tempDir;
  private readonly int $mergeFactor;
  private readonly int $chunkSize;

  private SortMetrics $metrics;

  /** @var list<string> */
  private array $tempFiles = [];

  /**
   * @param array{chunk_size?: int, temp_dir?: string, merge_factor?: int} $config
   */
  public function __construct(array $config = [])
  {
    // Performance: Sort 5000 rows at a time (approx 1-5MB chunk)
    $this->chunkSize = $config['chunk_size'] ?? 50000;
    $this->tempDir = $config['temp_dir'] ?? sys_get_temp_dir();
    $this->mergeFactor = $config['merge_factor'] ?? 50;

    $this->validateConfiguration();
    $this->metrics = new SortMetrics();
  }

  /**
   * Sort a CSV file (or ResultSet) by one or more columns
   *
   * @param TabularDataReader $input The input (Reader or ResultSet)
   * @param string|SortColumn|array $columns Column(s) to sort by
   * @param ComparatorInterface|null $comparator Comparator for single column sort
   * @return Reader A reader for the sorted CSV
   */
  public function sort(
    TabularDataReader $input,
    string|SortColumn|array $columns,
    ?ComparatorInterface $comparator = null,
  ): Reader {
    $this->metrics = new SortMetrics();
    $sortColumns = $this->normalizeSortColumns($columns, $comparator);

    // Capture headers.
    $headers = $input->getHeader();
    if (empty($headers)) {
      // If input has no explicit header offset, try to fetch 0th row.
      if ($input instanceof Reader && $input->getHeaderOffset() === null) {
        $input->setHeaderOffset(0);
        $headers = $input->getHeader();
      }

      if (empty($headers)) {
        throw new InvalidConfigurationException("Input CSV must have headers for sorting.");
      }
    }

    $this->verifyColumnsExist($headers, $sortColumns);

    try {
      // Split (Pass headers to preserve them in chunks).
      $chunkFiles = $this->createSortedChunks($input, $sortColumns, $headers);

      // Merge (Pass headers to write them to final output).
      $sortedFile = $this->mergeChunks($chunkFiles, $sortColumns, $headers);

      // Return Reader.
      // Use fopen with 'from' to ensure it reads the FILE, not the string path.
      $stream = fopen($sortedFile, 'r');
      $sortedReader = Reader::from($stream);
      $sortedReader->setHeaderOffset(0);

      $this->metrics->finish();

      return $sortedReader;
    } finally {
      $this->cleanup();
    }
  }

  public function getMetrics(): SortMetrics
  {
    return $this->metrics;
  }

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

  private function verifyColumnsExist(array $headers, array $sortColumns): void
  {
    foreach ($sortColumns as $sortColumn) {
      if (!in_array($sortColumn->name, $headers, true)) {
        throw new ColumnNotFoundException(
          "Column '{$sortColumn->name}' not found in CSV. Available: " .
          implode(', ', $headers)
        );
      }
    }
  }

  private function createSortedChunks(TabularDataReader $input, array $sortColumns, array $headers): array
  {
    $chunkFiles = [];
    $chunk = [];
    $count = 0;

    foreach ($input->getRecords() as $record) {
      $chunk[] = $record;
      $count++;
      $this->metrics->recordsProcessed++;

      if ($count >= $this->chunkSize) {
        $chunkFiles[] = $this->writeSortedChunk($chunk, $sortColumns, $headers);
        $chunk = [];
        $count = 0;
      }
    }

    if (!empty($chunk)) {
      $chunkFiles[] = $this->writeSortedChunk($chunk, $sortColumns, $headers);
    }

    return $chunkFiles;
  }

  private function writeSortedChunk(array $chunk, array $sortColumns, array $headers): string
  {
    usort($chunk, fn($a, $b) => $this->compareRecords($a, $b, $sortColumns));

    $tempFile = $this->createTempFile();

    // Use 'w' mode with fopen + from.
    $writer = Writer::from(fopen($tempFile, 'w'));

    // Write headers to the chunk so the Merge phase can read keys.
    $writer->insertOne($headers);
    $writer->insertAll($chunk);

    $this->metrics->chunksCreated++;
    $this->tempFiles[] = $tempFile;

    return $tempFile;
  }

  private function mergeChunks(array $chunkFiles, array $sortColumns, array $headers): string
  {
    if (count($chunkFiles) === 1) {
      return $chunkFiles[0];
    }

    while (count($chunkFiles) > 1) {
      $mergedChunks = [];
      foreach (array_chunk($chunkFiles, $this->mergeFactor) as $group) {
        $mergedChunks[] = $this->kWayMerge($group, $sortColumns, $headers);
      }
      $chunkFiles = $mergedChunks;
      $this->metrics->mergePasses++;
    }

    return $chunkFiles[0];
  }

  private function kWayMerge(array $files, array $sortColumns, array $headers): string
  {
    $readers = [];
    $heap = new \SplMinHeap();

    foreach ($files as $index => $file) {
      // Explicit fopen.
      $reader = Reader::from(fopen($file, 'r'));
      $reader->setHeaderOffset(0);

      $iterator = $reader->getRecords();
      $iterator->rewind();

      if ($iterator->valid()) {
        $record = $iterator->current();
        $readers[$index] = $iterator;

        $heap->insert([
          'record' => $record,
          'index' => $index,
          'priority' => $this->calculatePriority($record, $sortColumns),
        ]);
      }
    }

    $outputFile = $this->createTempFile();
    $writer = Writer::from(fopen($outputFile, 'w'));

    // Write headers to final output for application.
    $writer->insertOne($headers);

    while (!$heap->isEmpty()) {
      $item = $heap->extract();
      $writer->insertOne($item['record']);

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

  private function calculatePriority(array $record, array $sortColumns): string
  {
    $parts = [];
    foreach ($sortColumns as $column) {
      $parts[] = $record[$column->name] ?? '';
    }
    return implode('|', $parts);
  }

  private function createTempFile(): string
  {
    $tempFile = tempnam($this->tempDir, 'csvsort_');
    if ($tempFile === false) {
      throw new IoException("Failed to create temporary file in {$this->tempDir}");
    }
    $this->metrics->tempFilesCreated++;
    return $tempFile;
  }

  private function validateConfiguration(): void
  {
    if ($this->chunkSize < 1) {
      throw new InvalidConfigurationException("Chunk size must be > 0");
    }
    if (!is_dir($this->tempDir) || !is_writable($this->tempDir)) {
      throw new InvalidConfigurationException("Temp directory not writable: {$this->tempDir}");
    }
    if ($this->mergeFactor < 2) {
      throw new InvalidConfigurationException('Merge factor must be at least 2');
    }
  }

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
