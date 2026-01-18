# Installation Guide

## Requirements

- PHP 8.4 or higher
- Composer
- league/csv ^9.27
- Sufficient disk space for temporary files (recommend 2x your largest CSV file size)

## Installation via Composer

### Standard Installation

```bash
composer require andileco/csvsort
```

### Development Installation

If you want to contribute or run tests:

```bash
composer require --dev andileco/csvsort
```

## Verify Installation

Create a simple PHP file to verify the installation:

```php
<?php

require 'vendor/autoload.php';

use Andileco\CsvSort\ExternalSorter;

$sorter = new ExternalSorter();
echo "andileco/csvsort is installed successfully!\n";
```

Run it:

```bash
php verify.php
```

## System Requirements Check

### Check PHP Version

```bash
php -v
```

You should see PHP 8.4.0 or higher.

### Check Available Memory

```bash
php -i | grep memory_limit
```

Recommended: At least 512M for PHP memory_limit.

### Check Disk Space

```bash
df -h /tmp
```

Ensure you have sufficient space in your temp directory.

### Check Extensions

The library requires these PHP extensions (usually enabled by default):

- json
- mbstring

Verify:

```bash
php -m | grep -E "(json|mbstring)"
```

## Configuration

### Temporary Directory

By default, the library uses the system temp directory. To use a custom location:

```php
$sorter = new ExternalSorter([
    'temp_dir' => '/path/to/custom/temp',
]);
```

Make sure this directory is:
- Writable by PHP
- On fast storage (SSD recommended)
- Has sufficient space

### Memory Limit

Configure memory usage:

```php
$sorter = new ExternalSorter([
    'memory_limit' => 256 * 1024 * 1024, // 256MB
]);
```

Rule of thumb: Use 25-50% of your PHP memory_limit.

## Troubleshooting

### "Class not found" Error

Make sure Composer's autoloader is included:

```php
require 'vendor/autoload.php';
```

### "Permission denied" on Temp Directory

Ensure the temp directory is writable:

```bash
chmod 777 /tmp
# or for custom directory:
chmod 777 /path/to/custom/temp
```

### Memory Limit Errors

Increase PHP memory limit in php.ini:

```ini
memory_limit = 512M
```

Or in your script:

```php
ini_set('memory_limit', '512M');
```

### Disk Space Issues

Clean up temp directory or specify a different location with more space:

```php
$sorter = new ExternalSorter([
    'temp_dir' => '/path/with/more/space',
]);
```

## Next Steps

- Read the [Usage Guide](USAGE.md)
- Check out [Examples](../examples/)
- Review [Performance Tuning](PERFORMANCE.md)

## Uninstallation

To remove the library:

```bash
composer remove andileco/csvsort
```
