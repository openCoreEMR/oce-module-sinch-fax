# OpenEMR Module Development Guide

This document describes the architectural patterns and conventions for OpenEMR modules developed by OpenCoreEMR. Follow these patterns when working on **any** OpenEMR module in this organization.

## Documentation Index

### Development Patterns

| Document | Description |
|----------|-------------|
| [Architecture](docs/development/architecture.md) | Module structure, file conventions, entry points, Bootstrap pattern |
| [Controllers](docs/development/controllers.md) | Controller pattern, Request/Response handling rules |
| [Exceptions](docs/development/exceptions.md) | Exception hierarchy, error handling best practices |
| [Templates](docs/development/templates.md) | Twig templates, filters, dialog/iframe patterns |
| [Database](docs/development/database.md) | QueryUtils usage, why never use sql.inc.php directly |
| [OpenEMR Integration](docs/development/openemr-integration.md) | Tabs/iframes, redirects, dependencies, version constraints |
| [Code Quality](docs/development/code-quality.md) | Standards, security checklist, pre-commit checks |

### Development Environment

| Document | Description |
|----------|-------------|
| [Docker](docs/development/docker.md) | Docker setup, commands, troubleshooting, database operations |
| [Tooling](docs/development/tooling.md) | Taskfile vs Composer scripts, common tasks |
| [Unit Tests](docs/development/unit-tests.md) | Testing philosophy, mocks, coverage goals |

### Testing

| Document | Description |
|----------|-------------|
| [Sinch Fax Testing](docs/testing/sinch-fax-testing.md) | End-to-end testing workflows, configuration |
| [Webhook Testing](docs/testing/webhook-testing.md) | Local webhook testing, simulated payloads |

### Browser Automation (for AI agents)

| Document | Description |
|----------|-------------|
| [OpenEMR Login](docs/browser-automation/openemr-login.md) | Login process, credentials, form elements |
| [OpenEMR Navigation](docs/browser-automation/openemr-navigation.md) | Menu system, config navigation, common issues |

### Sinch Integration

| Document | Description |
|----------|-------------|
| [API Reference](docs/sinch/api-reference.md) | Sinch API documentation links and usage guidance |

## Quick Reference

### Key Patterns

**Controllers:**
- Use `Request::createFromGlobals()` - never `$_GET`, `$_POST`, `$_SERVER`
- Return `Response` objects - never `void`, `die()`, or `exit`
- Throw custom exceptions with `getStatusCode()` method

**Database:**
- Always use `QueryUtils::fetchRecords()` and `QueryUtils::sqlStatementThrowException()`
- Never use `sqlStatement()`, `sqlQuery()`, or other direct SQL functions

**Templates:**
- Use Twig filters: `xlt`, `text`, `attr`, `xlj`
- Dialog templates should NOT use `openemr_header_setup()`

**Error Handling:**
- Always catch `\Throwable`, not `\Exception`

### Common Commands

```bash
# Start development environment
task dev:start

# Install module (register, SQL, enable)
task module:install

# List all modules
task module:list

# Run all code quality checks
task check

# Run tests
task test

# Test webhooks locally
task webhook:tunnel
task webhook:test:incoming
```

### Module Configuration Notes

**Sinch Service ID** is NOT required for fax functionality. Don't flag an empty Service ID as a configuration problem.

**Webhook Authentication:**
- Username/password required for HTTP Basic Auth
- IP allowlist is optional (one per line, supports CIDR like `10.0.0.0/8`)
- Empty allowlist = allow all IPs
