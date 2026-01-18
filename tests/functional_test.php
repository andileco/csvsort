<?php

declare(strict_types=1);

/**
 * Simple functional test to verify the library works
 * Run with: php tests/functional_test.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Andileco\CsvSort\ExternalSorter;
use Andileco\CsvSort\SortDirection;
use Andileco\CsvSort\SortColumn;
use Andileco\CsvSort\Comparator\NumericComparator;
use League\Csv\Reader;
use League\Csv\Writer;

class FunctionalTest
{
    private string $tempDir;
    private array $cleanup = [];
    
    public function __construct()
    {
        $this->tempDir = __DIR__ . '/temp';
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }
    
    public function run(): void
    {
        echo "Running functional tests...\n\n";
        
        $tests = [
            'testBasicSorting' => 'Basic ascending sort',
            'testDescendingSort' => 'Descending sort',
            'testNumericSort' => 'Numeric sort',
            'testMultiColumnSort' => 'Multi-column sort',
            'testEmptyFile' => 'Empty file handling',
            'testSingleRecord' => 'Single record',
            'testDuplicates' => 'Duplicate values',
        ];
        
        $passed = 0;
        $failed = 0;
        
        foreach ($tests as $method => $description) {
            echo "Testing: $description... ";
            try {
                $this->$method();
                echo "✓ PASS\n";
                $passed++;
            } catch (\Exception $e) {
                echo "✗ FAIL\n";
                echo "  Error: {$e->getMessage()}\n";
                $failed++;
            }
        }
        
        echo "\n";
        echo "Results: $passed passed, $failed failed\n";
        
        $this->cleanup();
        
        if ($failed > 0) {
            exit(1);
        }
    }
    
    private function testBasicSorting(): void
    {
        $file = $this->createTestFile([
            ['name' => 'Charlie'],
            ['name' => 'Alice'],
            ['name' => 'Bob'],
        ]);
        
        $reader = Reader::createFromPath($file, 'r');
        $reader->setHeaderOffset(0);
        
        $sorter = new ExternalSorter(['memory_limit' => 1024 * 1024]);
        $sorted = $sorter->sort($reader, 'name');
        
        $names = [];
        foreach ($sorted as $record) {
            $names[] = $record['name'];
        }
        
        $expected = ['Alice', 'Bob', 'Charlie'];
        if ($names !== $expected) {
            throw new \Exception("Expected " . json_encode($expected) . " but got " . json_encode($names));
        }
    }
    
    private function testDescendingSort(): void
    {
        $file = $this->createTestFile([
            ['name' => 'Alice'],
            ['name' => 'Charlie'],
            ['name' => 'Bob'],
        ]);
        
        $reader = Reader::createFromPath($file, 'r');
        $reader->setHeaderOffset(0);
        
        $sorter = new ExternalSorter();
        $sorted = $sorter->sort($reader, new SortColumn('name', SortDirection::DESC));
        
        $names = [];
        foreach ($sorted as $record) {
            $names[] = $record['name'];
        }
        
        $expected = ['Charlie', 'Bob', 'Alice'];
        if ($names !== $expected) {
            throw new \Exception("Expected " . json_encode($expected) . " but got " . json_encode($names));
        }
    }
    
    private function testNumericSort(): void
    {
        $file = $this->createTestFile([
            ['name' => 'Alice', 'age' => '30'],
            ['name' => 'Bob', 'age' => '25'],
            ['name' => 'Charlie', 'age' => '35'],
        ]);
        
        $reader = Reader::createFromPath($file, 'r');
        $reader->setHeaderOffset(0);
        
        $sorter = new ExternalSorter();
        $sorted = $sorter->sort($reader, new SortColumn('age', SortDirection::ASC, new NumericComparator()));
        
        $ages = [];
        foreach ($sorted as $record) {
            $ages[] = $record['age'];
        }
        
        $expected = ['25', '30', '35'];
        if ($ages !== $expected) {
            throw new \Exception("Expected " . json_encode($expected) . " but got " . json_encode($ages));
        }
    }
    
    private function testMultiColumnSort(): void
    {
        $file = $this->createTestFile([
            ['city' => 'New York', 'age' => '30'],
            ['city' => 'Chicago', 'age' => '25'],
            ['city' => 'New York', 'age' => '25'],
            ['city' => 'Chicago', 'age' => '30'],
        ]);
        
        $reader = Reader::createFromPath($file, 'r');
        $reader->setHeaderOffset(0);
        
        $sorter = new ExternalSorter();
        $sorted = $sorter->sort($reader, [
            new SortColumn('city', SortDirection::ASC),
            new SortColumn('age', SortDirection::DESC, new NumericComparator()),
        ]);
        
        $results = [];
        foreach ($sorted as $record) {
            $results[] = $record['city'] . '-' . $record['age'];
        }
        
        $expected = ['Chicago-30', 'Chicago-25', 'New York-30', 'New York-25'];
        if ($results !== $expected) {
            throw new \Exception("Expected " . json_encode($expected) . " but got " . json_encode($results));
        }
    }
    
    private function testEmptyFile(): void
    {
        $file = $this->createTestFile([]);
        
        $reader = Reader::createFromPath($file, 'r');
        $reader->setHeaderOffset(0);
        
        $sorter = new ExternalSorter();
        $sorted = $sorter->sort($reader, 'name');
        
        $count = 0;
        foreach ($sorted as $record) {
            $count++;
        }
        
        if ($count !== 0) {
            throw new \Exception("Expected 0 records but got $count");
        }
    }
    
    private function testSingleRecord(): void
    {
        $file = $this->createTestFile([
            ['name' => 'Alice'],
        ]);
        
        $reader = Reader::createFromPath($file, 'r');
        $reader->setHeaderOffset(0);
        
        $sorter = new ExternalSorter();
        $sorted = $sorter->sort($reader, 'name');
        
        $names = [];
        foreach ($sorted as $record) {
            $names[] = $record['name'];
        }
        
        $expected = ['Alice'];
        if ($names !== $expected) {
            throw new \Exception("Expected " . json_encode($expected) . " but got " . json_encode($names));
        }
    }
    
    private function testDuplicates(): void
    {
        $file = $this->createTestFile([
            ['name' => 'Alice'],
            ['name' => 'Bob'],
            ['name' => 'Alice'],
            ['name' => 'Charlie'],
            ['name' => 'Bob'],
        ]);
        
        $reader = Reader::createFromPath($file, 'r');
        $reader->setHeaderOffset(0);
        
        $sorter = new ExternalSorter();
        $sorted = $sorter->sort($reader, 'name');
        
        $names = [];
        foreach ($sorted as $record) {
            $names[] = $record['name'];
        }
        
        $expected = ['Alice', 'Alice', 'Bob', 'Bob', 'Charlie'];
        if ($names !== $expected) {
            throw new \Exception("Expected " . json_encode($expected) . " but got " . json_encode($names));
        }
    }
    
    private function createTestFile(array $data): string
    {
        $file = tempnam($this->tempDir, 'test_');
        $this->cleanup[] = $file;
        
        $writer = Writer::createFromPath($file, 'w');
        
        if (!empty($data)) {
            $headers = array_keys($data[0]);
            $writer->insertOne($headers);
            $writer->insertAll($data);
        } else {
            $writer->insertOne(['name']); // Empty file with header
        }
        
        return $file;
    }
    
    private function cleanup(): void
    {
        foreach ($this->cleanup as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        
        if (is_dir($this->tempDir)) {
            @rmdir($this->tempDir);
        }
    }
}

// Run the tests
$test = new FunctionalTest();
$test->run();
