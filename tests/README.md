# Unit Tests for OpenEMR Sinch Fax Module

This directory contains unit tests for the OpenEMR Sinch Fax Module.

## Running Tests

### Basic Test Execution

Run all tests:
```bash
composer test
```

Or directly with PHPUnit:
```bash
vendor/bin/phpunit
```

### With Test Documentation
```bash
composer phpunit
```

This displays test results in a readable format showing test names and status.

## Code Coverage

### Running Tests with Coverage (Docker)

The recommended approach is to use Docker, which provides a consistent environment with Xdebug pre-installed:

```bash
# First time: build the test image
docker compose build phpunit

# Run tests with coverage
docker compose run --rm phpunit-coverage

# View the HTML coverage report
open htmlcov/index.html  # macOS
xdg-open htmlcov/index.html  # Linux
```

The Docker approach ensures consistent results across all environments and matches the GitHub Actions CI environment. No local PHP or Xdebug installation required!

## Test Structure

```
tests/
├── Unit/                          # Unit tests
│   ├── Client/                    # Client tests
│   │   └── SinchFaxClientTest.php
│   ├── Controller/                # Controller tests
│   │   ├── DocumentFaxControllerTest.php
│   │   ├── FaxDownloadControllerTest.php
│   │   └── FaxListControllerTest.php
│   ├── Service/                   # Service tests
│   │   └── FaxServiceTest.php
│   └── GlobalConfigTest.php       # Configuration tests
├── Mocks/                         # Mock classes for OpenEMR dependencies
│   ├── MockCryptoGen.php
│   ├── MockCsrfUtils.php
│   ├── MockGlobalsAccessor.php
│   ├── MockGlobalSetting.php
│   ├── MockQueryUtils.php
│   └── MockSystemLogger.php
├── bootstrap.php                  # Test bootstrap file
└── README.md                      # This file
```

## Mock Classes

The tests use mock classes to avoid dependencies on OpenEMR core:

- **MockGlobalsAccessor** - Mocks configuration access
- **MockQueryUtils** - Mocks database queries
- **MockSystemLogger** - Mocks logging
- **MockCryptoGen** - Mocks encryption/decryption
- **MockCsrfUtils** - Mocks CSRF token validation
- **MockGlobalSetting** - Mocks OpenEMR global settings

These mocks ensure tests run quickly and don't require a full OpenEMR installation or database connection.

## GitHub Actions

Tests are automatically run on every push and pull request via GitHub Actions. The workflow:

- Runs tests on PHP 8.2, 8.3, and 8.4
- Generates code coverage report (PHP 8.3 only)
- Runs code quality checks (PHPStan, PHP_CodeSniffer, Rector)

See `.github/workflows/tests.yml` for the complete configuration.

## Docker Details

The Docker setup includes:
- **PHP 8.3-cli** base image
- **Xdebug 3.3.2** pre-installed and configured for coverage
- **Composer 2** for dependency management
- Volume mounts to sync code and coverage reports

### Docker Commands Reference

```bash
# Build the test image
docker compose build phpunit

# Run tests
docker compose run --rm phpunit

# Run tests with coverage
docker compose run --rm phpunit-coverage

# Run a specific test file
docker compose run --rm phpunit vendor/bin/phpunit tests/Unit/GlobalConfigTest.php

# Run tests with verbose output
docker compose run --rm phpunit vendor/bin/phpunit --testdox --verbose

# Clean up
docker compose down
docker system prune -f
```

## Writing New Tests

When adding new tests:

1. Place test files in the appropriate `Unit/` subdirectory
2. Name test classes with `Test` suffix (e.g., `FaxServiceTest`)
3. Extend `PHPUnit\Framework\TestCase`
4. Use descriptive test method names starting with `test`
5. Clear mock data in `setUp()` method
6. Use mocks to avoid OpenEMR core dependencies

Example:
```php
<?php

namespace OpenCoreEMR\Modules\SinchFax\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

class MyNewTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear mock data
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
    }

    public function testSomething(): void
    {
        // Arrange
        $expected = 'value';

        // Act
        $actual = methodToTest();

        // Assert
        $this->assertEquals($expected, $actual);
    }
}
```

## Coverage Goals

The project aims for **80% code coverage** for:
- All service classes
- All controllers
- Configuration classes

Exception classes are excluded from coverage requirements as they are simple data classes.

## Continuous Integration

The test suite runs automatically on GitHub Actions for:
- Every push to `main`, `develop`, or feature branches
- Every pull request
- Multiple PHP versions (8.2, 8.3, 8.4)

Pull requests must pass all tests before being merged.
