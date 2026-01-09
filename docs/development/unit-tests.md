# Unit Testing

## Testing Philosophy

All OpenEMR modules **must** have comprehensive unit tests with **80%+ code coverage**. Tests must:
- **Never make real network calls** - use mocks
- **Never touch OpenEMR database** - use MockQueryUtils
- **Run in isolation** - no OpenEMR core dependencies
- **Run in Docker** - consistent environment with Xdebug

## Running Tests

```bash
# Run all tests
docker compose run --rm phpunit

# Run tests with coverage report
docker compose run --rm phpunit-coverage

# View coverage report
open htmlcov/index.html
```

## Test Structure

```
tests/
├── Unit/                          # Unit tests
│   ├── Client/                    # Client tests
│   ├── Controller/                # Controller tests
│   ├── Service/                   # Service tests
│   └── {Class}Test.php
├── Mocks/                         # Mock classes
│   ├── MockQueryUtils.php         # Database mock
│   ├── MockSystemLogger.php       # Logger mock
│   ├── MockCsrfUtils.php          # CSRF mock
│   ├── MockCryptoGen.php          # Crypto mock
│   └── MockGlobalsAccessor.php    # Config mock
├── bootstrap.php                  # Test bootstrap
└── README.md                      # Test documentation
```

## Mock Classes Usage

**Always use mocks to avoid OpenEMR dependencies:**

```php
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use OpenCoreEMR\Modules\SinchFax\Tests\Mocks\MockGlobalsAccessor;

class MyServiceTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear mock data before each test
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
        SystemLogger::clearLogs();
    }

    public function testSomething(): void
    {
        // Set up mock database response
        QueryUtils::setMockResult(
            "SELECT * FROM table WHERE id = ?",
            [123],
            [['id' => 123, 'name' => 'Test']]
        );

        // Your test code here
        $result = $this->service->getById(123);

        $this->assertEquals('Test', $result['name']);
    }
}
```

## Writing Tests for Controllers

Controllers require special handling for Request/Response:

```php
public function testControllerAction(): void
{
    // Initialize superglobals
    $_POST = ['field' => 'value'];
    $_SERVER['REQUEST_METHOD'] = 'POST';

    // Mock database/services as needed
    QueryUtils::setMockResult($sql, $binds, $results);

    $response = $this->controller->dispatch('action');

    $this->assertInstanceOf(Response::class, $response);
    $this->assertEquals(200, $response->getStatusCode());

    // Clean up
    unset($_POST, $_SERVER['REQUEST_METHOD']);
}
```

## Writing Tests for Services

Services often have readonly properties that can't be mocked via injection. Options:

1. **Test public API only** - Don't try to inject mocks for readonly properties
2. **Use reflection for private methods** - When needed for coverage
3. **Mock at higher level** - Mock the client calls in integration tests

```php
public function testServiceMethod(): void
{
    // Option 1: Test public API
    $this->expectException(\Exception::class);
    $this->service->doSomething('invalid');

    // Option 2: Use reflection for private methods
    $reflection = new \ReflectionClass($this->service);
    $method = $reflection->getMethod('privateMethod');
    $method->setAccessible(true);
    $result = $method->invoke($this->service, 'arg');

    $this->assertEquals('expected', $result);
}
```

## Coverage Goals

Aim for these coverage levels:

| Component | Target Coverage |
|-----------|----------------|
| **GlobalConfig** | 100% (configuration is critical) |
| **Services** | 80%+ (core business logic) |
| **Controllers** | 70%+ (focus on dispatch and validation) |
| **Clients** | 60%+ (HTTP clients are harder to test) |
| **Exceptions** | 0% (excluded from coverage - just data classes) |

## Improving Coverage

When working on increasing coverage:

1. **Run coverage report first:**
   ```bash
   docker compose run --rm phpunit-coverage
   open htmlcov/index.html
   ```

2. **Identify low-coverage files** - Click through the HTML report

3. **Add tests incrementally** - One method at a time

4. **Focus on untested methods** - Look for red lines in coverage report

5. **Test edge cases:**
   - Empty inputs
   - Null values
   - Exception paths
   - Boundary conditions

6. **Commit incrementally:**
   ```bash
   git add tests/
   git commit -m "test: improve XYZ coverage from 40% to 75%"
   ```

## Common Testing Patterns

**Testing Exception Paths:**
```php
public function testThrowsExceptionOnInvalidInput(): void
{
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Expected error message');

    $this->service->methodThatThrows('bad-input');
}
```

**Testing Database Operations:**
```php
public function testSavesToDatabase(): void
{
    $this->service->save(['data' => 'value']);

    $queries = QueryUtils::getQueries();
    $this->assertNotEmpty($queries);
    $this->assertStringContainsString('INSERT INTO', $queries[0]['sql']);
}
```

**Testing with Multiple Mock Results:**
```php
public function testCreatesIfNotExists(): void
{
    // First query returns nothing
    QueryUtils::setMockResult("SELECT...", [], []);

    // Second query returns created record
    QueryUtils::setMockResult("SELECT...", [], [['id' => 1]]);

    $result = $this->service->getOrCreate('name');
    $this->assertEquals(1, $result['id']);
}
```

## Test Checklist

Before committing tests:

- [ ] All tests pass: `docker compose run --rm phpunit`
- [ ] Coverage is at 80%+ for modified files
- [ ] No real database calls (use MockQueryUtils)
- [ ] No real HTTP calls (mock clients)
- [ ] No OpenEMR core dependencies
- [ ] Tests run in under 1 second
- [ ] Mock data is cleared in `setUp()`
- [ ] Descriptive test names (`testMethodNameDoesExpectedBehavior`)
