<?php

declare(strict_types=1);

namespace Andileco\CsvSort\Comparator;

/**
 * Interface for column value comparators
 */
interface ComparatorInterface
{
    /**
     * Compare two values
     * 
     * @param mixed $a First value
     * @param mixed $b Second value
     * @return int Negative if $a < $b, 0 if equal, positive if $a > $b
     */
    public function compare(mixed $a, mixed $b): int;
}
