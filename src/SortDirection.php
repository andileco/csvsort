<?php

declare(strict_types=1);

namespace Andileco\CsvSort;

/**
 * Enum for sort direction.
 */
enum SortDirection: string {
  case ASC = 'asc';
  case DESC = 'desc';
    
  /**
   * Get the multiplier for comparison.
   */
  public function multiplier(): int {
    return match($this) {
      self::ASC => 1,
      self::DESC => -1,
    };
  }
}
