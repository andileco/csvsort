<?php

declare(strict_types=1);

/**
 * Basic usage example for andileco/csvsort
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Andileco\CsvSort\ExternalSorter;
use Andileco\CsvSort\SortColumn;
use Andileco\CsvSort\SortDirection;
use Andileco\CsvSort\Comparator\NumericComparator;
use League\Csv\Reader;
use League\Csv\Writer;

// Create a sample CSV file.
$sampleFile = __DIR__ . '/sample.csv';
$writer = Writer::createFromPath($sampleFile, 'w');
$writer->insertOne(['name', 'age', 'city']);
$writer->insertAll([
  ['Alice', '30', 'New York'],
  ['Bob', '25', 'Los Angeles'],
  ['Charlie', '35', 'Chicago'],
  ['Diana', '28', 'Houston'],
  ['Eve', '32', 'Phoenix'],
]);

echo "Sample CSV created: $sampleFile\n\n";

// Example 1: Simple sort by name.
echo "=== Example 1: Sort by name (ascending) ===\n";
$reader = Reader::createFromPath($sampleFile, 'r');
$reader->setHeaderOffset(0);

// Default configuration (Chunk size: 50,000 rows, Merge Factor: 50).
// Will automatically use in-memory sort if file size is below
// threshold (default 20MB)
$sorter = new ExternalSorter();
$sorted = $sorter->sort($reader, 'name');

foreach ($sorted as $record) {
  echo "{$record['name']}, {$record['age']}, {$record['city']}\n";
}

$metrics = $sorter->getMetrics();
echo "\nRecords processed: {$metrics->recordsProcessed}\n";
echo "Time: {$metrics->getTotalTime()}s\n\n";

// Example 2: Sort by age (numeric, descending)
echo "=== Example 2: Sort by age (numeric, descending) ===\n";
$reader = Reader::createFromPath($sampleFile, 'r');
$reader->setHeaderOffset(0);

$sorter = new ExternalSorter();
$sorted = $sorter->sort(
  $reader,
  new SortColumn('age', SortDirection::DESC, new NumericComparator())
);

foreach ($sorted as $record) {
  echo "{$record['name']}, {$record['age']}, {$record['city']}\n";
}
echo "\n";

// Example 3: Multi-column sort.
echo "=== Example 3: Sort by city (asc), then age (desc) ===\n";
$reader = Reader::createFromPath($sampleFile, 'r');
$reader->setHeaderOffset(0);

$sorter = new ExternalSorter();
$sorted = $sorter->sort($reader, [
  new SortColumn('city', SortDirection::ASC),
  new SortColumn('age', SortDirection::DESC, new NumericComparator()),
]);

foreach ($sorted as $record) {
  echo "{$record['city']}, {$record['name']}, {$record['age']}\n";
}
echo "\n";

// Example 4: High Performance Configuration (For Large Files).
echo "=== Example 4: Large File Configuration ===\n";
$reader = Reader::createFromPath($sampleFile, 'r');
$reader->setHeaderOffset(0);

// Use larger chunks and higher merge factor to reduce Disk I/O on massive
// files. You can also adjust 'memory_sort_threshold' to control when disk
// sorting kicks in.
$sorter = new ExternalSorter([
  'chunk_size' => 100000,
  'merge_factor' => 20,
  'memory_sort_threshold' => 10485760,
  'temp_dir' => sys_get_temp_dir(),
]);

$sorted = $sorter->sort($reader, 'name');

echo "Sorted using optimized large-file settings.\n";
foreach ($sorted as $record) {
  // Just printing first match to prove it works.
  echo "First record: {$record['name']}\n";
  break;
}
echo "\n";

// Clean up.
if (file_exists($sampleFile)) {
  unlink($sampleFile);
}

echo "Done! Check the output above.\n";
