<?php

declare(strict_types=1);

namespace Andileco\CsvSort\Comparator;

/**
 * Boolean comparison (handles various boolean representations)
 */
class BooleanComparator implements ComparatorInterface
{
    private const TRUE_VALUES = ['true', '1', 'yes', 'on', 'y'];
    
    public function compare(mixed $a, mixed $b): int
    {
        $boolA = $this->toBoolean($a);
        $boolB = $this->toBoolean($b);
        
        return $boolA <=> $boolB;
    }
    
    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        
        $value = strtolower(trim((string) $value));
        
        return in_array($value, self::TRUE_VALUES, true);
    }
}
