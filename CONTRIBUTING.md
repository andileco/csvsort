# Contributing to andileco/csvsort

Thank you for considering contributing to andileco/csvsort! This document provides guidelines for contributing to the project.

## Code of Conduct

- Be respectful and professional
- Focus on constructive feedback
- Help create a welcoming environment

## How to Contribute

### Reporting Bugs

1. Check if the bug has already been reported in [Issues](https://github.com/andileco/csvsort/issues)
2. If not, create a new issue with:
   - Clear title and description
   - Steps to reproduce
   - Expected vs actual behavior
   - PHP version and environment details
   - Sample CSV file (if applicable)

### Suggesting Features

1. Open an issue with the `enhancement` label
2. Describe the feature and its use case
3. Explain why it would be valuable
4. Be open to discussion and feedback

### Pull Requests

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. Add tests if applicable
5. Ensure code follows PSR-12 style guidelines
6. Run static analysis: `composer analyse`
7. Commit your changes (`git commit -m 'Add amazing feature'`)
8. Push to your branch (`git push origin feature/amazing-feature`)
9. Open a Pull Request

## Development Setup

```bash
# Clone your fork
git clone https://github.com/YOUR-USERNAME/csvsort.git
cd csvsort

# Install dependencies
composer install

# Run static analysis
composer analyse
```

## Coding Standards

- Follow PSR-12 coding style
- Use strict types (`declare(strict_types=1);`)
- Add type hints for all parameters and return values
- Write clear, self-documenting code
- Add PHPDoc comments for complex logic
- Use readonly properties where appropriate

## Testing

- Add tests for new features
- Ensure existing tests pass
- Test with different CSV sizes and formats
- Consider edge cases

## Documentation

- Update README.md if adding features
- Add examples for new functionality
- Update CHANGELOG.md
- Keep docs clear and concise

## Commit Messages

- Use present tense ("Add feature" not "Added feature")
- Use imperative mood ("Move cursor to..." not "Moves cursor to...")
- Limit first line to 72 characters
- Reference issues and PRs liberally

## Release Process

1. Update version in `composer.json`
2. Update `CHANGELOG.md`
3. Create git tag (`git tag v1.0.0`)
4. Push tag (`git push origin v1.0.0`)
5. Create GitHub release

## Questions?

Feel free to open an issue for questions or discussions.

## License

By contributing, you agree that your contributions will be licensed under the MIT License.

Thank you for contributing! 🎉
