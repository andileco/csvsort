<?php

declare(strict_types=1);

namespace Andileco\CsvSort\Comparator;

/**
 * Natural order comparison (file1, file2, file10 instead of file1, file10, file2)
 */
readonly class NaturalComparator implements ComparatorInterface
{
    public function __construct(
        private bool $caseSensitive = true,
    ) {
    }
    
    public function compare(mixed $a, mixed $b): int
    {
        $a = (string) $a;
        $b = (string) $b;
        
        if ($this->caseSensitive) {
            return strnatcmp($a, $b);
        }
        
        return strnatcasecmp($a, $b);
    }
}
