<?php

declare(strict_types=1);

namespace Andileco\CsvSort;

use Andileco\CsvSort\Comparator\ComparatorInterface;
use Andileco\CsvSort\Comparator\StringComparator;

/**
 * Represents a column to sort by
 */
readonly class SortColumn
{
    public function __construct(
        public string $name,
        public SortDirection $direction = SortDirection::ASC,
        public ComparatorInterface $comparator = new StringComparator(),
    ) {
    }
}
