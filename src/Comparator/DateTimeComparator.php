<?php

declare(strict_types=1);

namespace Andileco\CsvSort\Comparator;

use DateTime;
use DateTimeInterface;

/**
 * DateTime comparison
 */
readonly class DateTimeComparator implements ComparatorInterface
{
    public function __construct(
        private string $format = 'Y-m-d H:i:s',
    ) {
    }
    
    public function compare(mixed $a, mixed $b): int
    {
        $dateA = $this->parseDate($a);
        $dateB = $this->parseDate($b);
        
        if ($dateA === null && $dateB === null) {
            return 0;
        }
        
        if ($dateA === null) {
            return -1;
        }
        
        if ($dateB === null) {
            return 1;
        }
        
        return $dateA <=> $dateB;
    }
    
    private function parseDate(mixed $value): ?DateTimeInterface
    {
        if ($value instanceof DateTimeInterface) {
            return $value;
        }
        
        $value = (string) $value;
        
        if (empty($value)) {
            return null;
        }
        
        $date = DateTime::createFromFormat($this->format, $value);
        
        return $date !== false ? $date : null;
    }
}
