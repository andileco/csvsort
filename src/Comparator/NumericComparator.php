<?php

declare(strict_types=1);

namespace Andileco\CsvSort\Comparator;

/**
 * Numeric comparison (handles integers and floats)
 */
class NumericComparator implements ComparatorInterface
{
    public function compare(mixed $a, mixed $b): int
    {
        $a = is_numeric($a) ? (float) $a : 0.0;
        $b = is_numeric($b) ? (float) $b : 0.0;
        
        return $a <=> $b;
    }
}
