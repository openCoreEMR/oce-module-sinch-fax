# Exception Handling Pattern

## Error Handling Best Practice: Always Catch `\Throwable`

**CRITICAL: Always catch `\Throwable` instead of `\Exception`**

- `\Throwable` is the base interface for both `\Exception` and `\Error`
- Catching `\Exception` will miss fatal errors like `\TypeError`, `\ParseError`, etc.
- Always use `catch (\Throwable $e)` for comprehensive error handling

**Example:**
```php
try {
    $this->service->doSomething();
} catch (\Throwable $e) {  // Catches both exceptions and errors
    $this->logger->error("Operation failed: " . $e->getMessage());
}
```

**Never do:**
```php
try {
    $this->service->doSomething();
} catch (\Exception $e) {  // Misses TypeError, ParseError, etc.
    $this->logger->error("Operation failed: " . $e->getMessage());
}
```

## Define Custom Exception Hierarchy

All modules should have their own exception types in `src/Exception/`:

```php
<?php
// src/Exception/{Module}ExceptionInterface.php

namespace OpenCoreEMR\Modules\{ModuleName}\Exception;

interface {Module}ExceptionInterface extends \Throwable
{
    /**
     * Get the HTTP status code for this exception
     */
    public function getStatusCode(): int;
}
```

```php
<?php
// src/Exception/{Module}Exception.php

namespace OpenCoreEMR\Modules\{ModuleName}\Exception;

abstract class {Module}Exception extends \RuntimeException implements {Module}ExceptionInterface
{
    abstract public function getStatusCode(): int;
}
```

```php
<?php
// src/Exception/{Module}NotFoundException.php

namespace OpenCoreEMR\Modules\{ModuleName}\Exception;

class {Module}NotFoundException extends {Module}Exception
{
    public function getStatusCode(): int
    {
        return 404;
    }
}
```

## Common Exception Types to Implement

- `{Module}NotFoundException` (404) - Resource not found
- `{Module}UnauthorizedException` (401) - User not authenticated
- `{Module}AccessDeniedException` (403) - CSRF failed, insufficient permissions
- `{Module}ValidationException` (400) - Invalid input data
- `{Module}ConfigurationException` (500) - Configuration errors

## Exception Handling in Public Files

```php
try {
    $response = $controller->dispatch($action, $_REQUEST);
    $response->send();
} catch ({Module}ExceptionInterface $e) {
    error_log("Error: " . $e->getMessage());

    $response = new Response(
        "Error: " . htmlspecialchars($e->getMessage()),
        $e->getStatusCode()
    );
    $response->send();
} catch (\Throwable $e) {
    error_log("Unexpected error: " . $e->getMessage());

    $response = new Response(
        "Error: An unexpected error occurred",
        500
    );
    $response->send();
}
```
