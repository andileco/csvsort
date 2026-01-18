# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-01-17

### Added
- Initial release of andileco/csvsort
- External merge sort algorithm for large CSV files
- Memory-efficient processing with configurable memory limits
- Support for multiple comparator types:
  - StringComparator (case-sensitive and case-insensitive)
  - NumericComparator (for integers and floats)
  - NaturalComparator (natural ordering)
  - DateTimeComparator (for dates and timestamps)
  - BooleanComparator (for boolean values)
- Multi-column sorting with per-column direction control
- K-way merge with configurable merge factor
- Performance metrics tracking
- League/CSV integration
- Comprehensive documentation and examples
- PHP 8.4+ support with modern features

### Dependencies
- Requires PHP ^8.4
- Requires league/csv ^9.27

[Unreleased]: https://github.com/andileco/csvsort/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/andileco/csvsort/releases/tag/v1.0.0
