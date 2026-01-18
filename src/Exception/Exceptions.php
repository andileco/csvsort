<?php

declare(strict_types=1);

namespace Andileco\CsvSort\Exception;

/**
 * Base exception for all library exceptions
 */
class CsvSortException extends \RuntimeException
{
}

/**
 * Thrown when a specified column doesn't exist
 */
class ColumnNotFoundException extends CsvSortException
{
}

/**
 * Thrown when configuration is invalid
 */
class InvalidConfigurationException extends CsvSortException
{
}

/**
 * Thrown when I/O operations fail
 */
class IoException extends CsvSortException
{
}

/**
 * Thrown when memory limit is exceeded
 */
class MemoryLimitException extends CsvSortException
{
}
