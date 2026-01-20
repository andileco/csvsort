<?php

declare(strict_types=1);

namespace Andileco\CsvSort;

use Andileco\CsvSort\Comparator\ComparatorInterface;
use Andileco\CsvSort\Comparator\StringComparator;
use Andileco\CsvSort\Exception\ColumnNotFoundException;
use Andileco\CsvSort\Exception\CsvSortException;
use Andileco\CsvSort\Exception\InvalidConfigurationException;
use Andileco\CsvSort\Exception\IoException;
use Andileco\CsvSort\Exception\MemoryLimitException;
use League\Csv\Reader;
use League\Csv\Writer;
use League\Csv\TabularDataReader;

/**
 * External merge sort implementation for large CSV files.
 *
 * This implementation includes a hybrid strategy that will sort in-memory
 * if the file size is below a configured threshold, falling back to disk-based
 * merge sort for larger datasets.
 */
class ExternalSorter {

  /**
   * The directory for temporary files.
   *
   * @var string
   */
  private readonly string $tempDir;

  /**
   * The number of chunks to merge at once.
   *
   * @var int
   */
  private readonly int $mergeFactor;

  /**
   * The number of rows per chunk.
   *
   * @var int
   */
  private readonly int $chunkSize;

  /**
   * The threshold in bytes for in-memory sorting.
   *
   * @var int
   */
  private readonly int $memorySortThreshold;

  /**
   * A hint for the input size (in bytes) if the source is not a file.
   *
   * @var int|null
   */
  private readonly ?int $inputSizeHint;

  /**
   * Metrics for the sort operation.
   *
   * @var \Andileco\CsvSort\SortMetrics
   */
  private SortMetrics $metrics;

  /**
   * List of temporary files created.
   *
   * @var list<string>
   */
  private array $tempFiles = [];

  /**
   * Constructs a new ExternalSorter.
   *
   * @param array{
   * chunk_size?: int,
   * temp_dir?: string,
   * merge_factor?: int,
   * memory_sort_threshold?: int,
   * input_size?: int
   * } $config
   *   The configuration array.
   */
  public function __construct(array $config = []) {
    // Sort 50000 rows at a time (approx 1-5MB chunk).
    $this->chunkSize = $config['chunk_size'] ?? 50000;
    $this->tempDir = $config['temp_dir'] ?? sys_get_temp_dir();
    $this->mergeFactor = $config['merge_factor'] ?? 50;

    // Default to 20MB threshold for in-memory sorting.
    $this->memorySortThreshold = $config['memory_sort_threshold'] ?? 20 * 1024 * 1024;
    $this->inputSizeHint = $config['input_size'] ?? NULL;

    $this->validateConfiguration();
    $this->metrics = new SortMetrics();
  }

  /**
   * Sort a CSV file (or ResultSet) by one or more columns.
   *
   * @param \League\Csv\TabularDataReader $input
   *   The input (Reader or ResultSet).
   * @param string|\Andileco\CsvSort\SortColumn|array $columns
   *   Column(s) to sort by.
   * @param \Andileco\CsvSort\Comparator\ComparatorInterface|null $comparator
   *   Comparator for single column sort.
   *
   * @return \League\Csv\Reader
   *   A reader for the sorted CSV.
   *
   * @throws \Andileco\CsvSort\Exception\InvalidConfigurationException
   * @throws \Andileco\CsvSort\Exception\ColumnNotFoundException
   */
  public function sort(
    TabularDataReader $input,
    string|SortColumn|array $columns,
    ?ComparatorInterface $comparator = null,
  ): Reader {
    $this->metrics = new SortMetrics();
    $sort_columns = $this->normalizeSortColumns($columns, $comparator);

    // Capture headers.
    $headers = $input->getHeader();
    if (empty($headers)) {
      // If input has no explicit header offset, try to fetch 0th row.
      if ($input instanceof Reader && $input->getHeaderOffset() === NULL) {
        $input->setHeaderOffset(0);
        $headers = $input->getHeader();
      }

      if (empty($headers)) {
        throw new InvalidConfigurationException("Input CSV must have headers for sorting.");
      }
    }

    $this->verifyColumnsExist($headers, $sort_columns);

    // Check if we should sort in memory or on disk.
    if ($this->shouldUseMemorySort($input)) {
      return $this->sortInMemory($input, $sort_columns, $headers);
    }

    return $this->sortExternally($input, $sort_columns, $headers);
  }

  /**
   * Gets the sort metrics.
   *
   * @return \Andileco\CsvSort\SortMetrics
   *   The metrics object.
   */
  public function getMetrics(): SortMetrics {
    return $this->metrics;
  }

  /**
   * Determines if we should sort in memory based on size threshold.
   *
   * @param \League\Csv\TabularDataReader $input
   *   The input data.
   *
   * @return bool
   *   TRUE if we should sort in memory, FALSE otherwise.
   */
  private function shouldUseMemorySort(TabularDataReader $input): bool {
    $size = $this->inputSizeHint;

    // Try to detect size from the input stream if no hint provided.
    // We can only reliably check size if it's a Reader with a stream.
    if ($size === NULL && $input instanceof Reader) {
      try {
        // Warning: This presumes access to the underlying path/stream.
        // league\csv Readers usually wrap a stream resource.
        $path = $input->getPathname();
        if ($path && file_exists($path)) {
          $size = filesize($path);
        }
      }
      catch (\Throwable $e) {
        // Ignore detection errors and fall back to external sort.
      }
    }

    // If we still don't know the size, assume it's large (safety first).
    if ($size === NULL || $size === FALSE) {
      return FALSE;
    }

    return $size < $this->memorySortThreshold;
  }

  /**
   * Performs the sort entirely in memory using PHP's usort.
   *
   * @param \League\Csv\TabularDataReader $input
   *   The input data.
   * @param array $sort_columns
   *   The normalized sort columns.
   * @param array $headers
   *   The headers.
   *
   * @return \League\Csv\Reader
   *   The sorted reader.
   */
  private function sortInMemory(TabularDataReader $input, array $sort_columns, array $headers): Reader {
    // Load all records into memory.
    // Note: getRecords returns an iterator, iterator_to_array pulls them all.
    $records = iterator_to_array($input->getRecords());

    // Sort in RAM.
    usort($records, fn($a, $b) => $this->compareRecords($a, $b, $sort_columns));

    // Write to a memory stream.
    $csv = Writer::createFromStream(fopen('php://temp', 'r+'));
    $csv->insertOne($headers);
    $csv->insertAll($records);

    // Return a Reader for the memory stream.
    // The stream is already open, so we create from it directly.
    $reader = Reader::createFromStream($csv->getStream());
    $reader->setHeaderOffset(0);

    // Manually set metrics for consistency.
    $this->metrics->recordsProcessed = count($records);
    $this->metrics->finish();

    return $reader;
  }

  /**
   * Performs the external merge sort logic.
   *
   * @param \League\Csv\TabularDataReader $input
   *   The input data.
   * @param array $sort_columns
   *   The normalized sort columns.
   * @param array $headers
   *   The headers.
   *
   * @return \League\Csv\Reader
   *   The sorted reader.
   */
  private function sortExternally(TabularDataReader $input, array $sort_columns, array $headers): Reader {
    try {
      // Split (Pass headers to preserve them in chunks).
      $chunk_files = $this->createSortedChunks($input, $sort_columns, $headers);

      // Merge (Pass headers to write them to final output).
      $sorted_file = $this->mergeChunks($chunk_files, $sort_columns, $headers);

      // Return Reader.
      // Use fopen with 'r' to ensure it reads the FILE.
      $stream = fopen($sorted_file, 'r');
      $sorted_reader = Reader::from($stream);
      $sorted_reader->setHeaderOffset(0);

      $this->metrics->finish();

      return $sorted_reader;
    }
    finally {
      $this->cleanup();
    }
  }

  /**
   * Normalizes the sort columns.
   *
   * @param string|\Andileco\CsvSort\SortColumn|array $columns
   *   The columns input.
   * @param \Andileco\CsvSort\Comparator\ComparatorInterface|null $comparator
   *   The comparator.
   *
   * @return array
   *   The normalized columns.
   */
  private function normalizeSortColumns(
    string|SortColumn|array $columns,
    ?ComparatorInterface $comparator,
  ): array {
    if (is_string($columns)) {
      return [
        new SortColumn(
          $columns,
          SortDirection::ASC,
          $comparator ?? new StringComparator(),
        ),
      ];
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
   * Verifies that the sort columns exist in the headers.
   *
   * @param array $headers
   *   The CSV headers.
   * @param array $sort_columns
   *   The sort columns.
   *
   * @throws \Andileco\CsvSort\Exception\ColumnNotFoundException
   */
  private function verifyColumnsExist(array $headers, array $sort_columns): void {
    foreach ($sort_columns as $sort_column) {
      if (!in_array($sort_column->name, $headers, TRUE)) {
        throw new ColumnNotFoundException(
          "Column '{$sort_column->name}' not found in CSV. Available: " .
          implode(', ', $headers)
        );
      }
    }
  }

  /**
   * Creates sorted chunks from the input.
   *
   * @param \League\Csv\TabularDataReader $input
   *   The input data.
   * @param array $sort_columns
   *   The sort columns.
   * @param array $headers
   *   The headers.
   *
   * @return array
   *   The list of chunk files.
   */
  private function createSortedChunks(TabularDataReader $input, array $sort_columns, array $headers): array {
    $chunk_files = [];
    $chunk = [];
    $count = 0;

    foreach ($input->getRecords() as $record) {
      $chunk[] = $record;
      $count++;
      $this->metrics->recordsProcessed++;

      if ($count >= $this->chunkSize) {
        $chunk_files[] = $this->writeSortedChunk($chunk, $sort_columns, $headers);
        $chunk = [];
        $count = 0;
      }
    }

    if (!empty($chunk)) {
      $chunk_files[] = $this->writeSortedChunk($chunk, $sort_columns, $headers);
    }

    return $chunk_files;
  }

  /**
   * Writes a sorted chunk to disk.
   *
   * @param array $chunk
   *   The chunk of records.
   * @param array $sort_columns
   *   The sort columns.
   * @param array $headers
   *   The headers.
   *
   * @return string
   *   The path to the temporary file.
   */
  private function writeSortedChunk(array $chunk, array $sort_columns, array $headers): string {
    usort($chunk, fn($a, $b) => $this->compareRecords($a, $b, $sort_columns));

    $temp_file = $this->createTempFile();

    // Use 'w' mode with fopen + from.
    $writer = Writer::from(fopen($temp_file, 'w'));

    // Write headers to the chunk so the Merge phase can read keys.
    $writer->insertOne($headers);
    $writer->insertAll($chunk);

    $this->metrics->chunksCreated++;
    $this->tempFiles[] = $temp_file;

    return $temp_file;
  }

  /**
   * Merges sorted chunks.
   *
   * @param array $chunk_files
   *   The chunk files to merge.
   * @param array $sort_columns
   *   The sort columns.
   * @param array $headers
   *   The headers.
   *
   * @return string
   *   The path to the final merged file.
   */
  private function mergeChunks(array $chunk_files, array $sort_columns, array $headers): string {
    if (count($chunk_files) === 1) {
      return $chunk_files[0];
    }

    while (count($chunk_files) > 1) {
      $merged_chunks = [];
      foreach (array_chunk($chunk_files, $this->mergeFactor) as $group) {
        $merged_chunks[] = $this->kWayMerge($group, $sort_columns, $headers);
      }
      $chunk_files = $merged_chunks;
      $this->metrics->mergePasses++;
    }

    return $chunk_files[0];
  }

  /**
   * Performs a k-way merge on a set of files.
   *
   * @param array $files
   *   The files to merge.
   * @param array $sort_columns
   *   The sort columns.
   * @param array $headers
   *   The headers.
   *
   * @return string
   *   The path to the merged file.
   */
  private function kWayMerge(array $files, array $sort_columns, array $headers): string {
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
          'priority' => $this->calculatePriority($record, $sort_columns),
        ]);
      }
    }

    $output_file = $this->createTempFile();
    $writer = Writer::from(fopen($output_file, 'w'));

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
          'priority' => $this->calculatePriority($record, $sort_columns),
        ]);
      }
    }

    $this->tempFiles[] = $output_file;
    return $output_file;
  }

  /**
   * Compares two records.
   *
   * @param array $a
   *   Record A.
   * @param array $b
   *   Record B.
   * @param array $sort_columns
   *   The sort columns.
   *
   * @return int
   *   The comparison result.
   */
  private function compareRecords(array $a, array $b, array $sort_columns): int {
    foreach ($sort_columns as $column) {
      $value_a = $a[$column->name] ?? '';
      $value_b = $b[$column->name] ?? '';

      $comparison = $column->comparator->compare($value_a, $value_b);

      if ($comparison !== 0) {
        return $comparison * $column->direction->multiplier();
      }
    }
    return 0;
  }

  /**
   * Calculates the priority for the heap.
   *
   * @param array $record
   *   The record.
   * @param array $sort_columns
   *   The sort columns.
   *
   * @return string
   *   The priority string.
   */
  private function calculatePriority(array $record, array $sort_columns): string {
    $parts = [];
    foreach ($sort_columns as $column) {
      $parts[] = $record[$column->name] ?? '';
    }
    return implode('|', $parts);
  }

  /**
   * Creates a temporary file.
   *
   * @return string
   *   The path to the temporary file.
   *
   * @throws \Andileco\CsvSort\Exception\IoException
   */
  private function createTempFile(): string {
    $temp_file = tempnam($this->tempDir, 'csvsort_');
    if ($temp_file === FALSE) {
      throw new IoException("Failed to create temporary file in {$this->tempDir}");
    }
    $this->metrics->tempFilesCreated++;
    return $temp_file;
  }

  /**
   * Validates the configuration.
   *
   * @throws \Andileco\CsvSort\Exception\InvalidConfigurationException
   */
  private function validateConfiguration(): void {
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

  /**
   * Cleans up temporary files.
   */
  private function cleanup(): void {
    foreach ($this->tempFiles as $file) {
      if (file_exists($file)) {
        @unlink($file);
      }
    }
    $this->tempFiles = [];
  }

}
