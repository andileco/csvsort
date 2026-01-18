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

// Create a sample CSV file
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

// Example 1: Simple sort by name
echo "=== Example 1: Sort by name (ascending) ===\n";
$reader = Reader::createFromPath($sampleFile, 'r');
$reader->setHeaderOffset(0);

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

// Example 3: Multi-column sort
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

// Clean up
unlink($sampleFile);

echo "Done! Check the output above.\n";
