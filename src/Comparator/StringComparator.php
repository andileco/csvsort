<?php

declare(strict_types=1);

namespace Andileco\CsvSort\Comparator;

/**
 * String comparison (case-sensitive by default)
 */
readonly class StringComparator implements ComparatorInterface
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
            return strcmp($a, $b);
        }
        
        return strcasecmp($a, $b);
    }
}
