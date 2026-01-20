# andileco/csvsort
andileco/csvsort is a production-ready PHP 8.4+ library designed to sort massive CSV files (multi-gigabyte) using an
external merge sort algorithm. It keeps memory usage constant by offloading data to disk, preventing "Out of Memory"
errors common with standard PHP sorting.
## Installation
```
composer require andileco/csvsort
```
## Quick Start
```php
use Andileco\CsvSort\ExternalSorter;
use League\Csv\Reader;

// 1. Load your CSV
$reader = Reader::createFromPath('huge-dataset.csv', 'r');
$reader->setHeaderOffset(0);

// 2. Sort it
$sorter = new ExternalSorter();
$sortedReader = $sorter->sort($reader, 'column_name');

// 3. Iterate results
foreach ($sortedReader as $record) {
// Process sorted records...
}
```
## Configuration & Performance
For massive files (millions of rows), you can tune the sorter to optimize disk I/O and memory usage.
```php
$sorter = new ExternalSorter([
'chunk_size' => 50000,   // Rows to sort in RAM per pass (Default: 50,000)
'merge_factor' => 20,    // Number of temp files to merge at once (Default: 50)
'temp_dir' => '/tmp',    // Directory for intermediate files
]);
```
Memory Usage: Stays constant (approx. 20-50MB buffer) regardless of whether the input file is 100MB or 100GB.
## Features at a Glance
Memory Efficient: Uses row-based chunking to ensure stable RAM usage.
High Performance: Optimized Min-Heap merge algorithm (~40k rows/sec).
Flexible Input: Accepts League\Csv\Reader or League\Csv\ResultSet.
Comparators:
- StringComparator (Default, case-sensitive/insensitive)
- NumericComparator (Integers/Floats)-
- NaturalComparator (e.g., "img1, img2, img10")-
- DateTimeComparator (Date strings)
- BooleanComparator
## Multi-Column Sorting Example
```php
use Andileco\CsvSort\{SortColumn, SortDirection};
use Andileco\CsvSort\Comparator\NumericComparator;

$sorted = $sorter->sort($reader, [
new SortColumn('age', SortDirection::DESC, new NumericComparator()),
new SortColumn('last_name', SortDirection::ASC),
]);
```
