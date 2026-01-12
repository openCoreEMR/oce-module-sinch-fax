# Development Tooling: Taskfile vs Composer Scripts

## Separation of Concerns

This module uses **both Taskfile and Composer scripts**, each with distinct responsibilities:

**Taskfile is for:**
- Docker orchestration (start, stop, logs, exec)
- Database operations (import, export, queries via Docker)
- Module management (cleanup, table inspection)
- Complex workflows with prompts (destructive operations)
- Infrastructure commands that don't run in all environments

**Composer scripts are for:**
- Code quality checks (phpcs, phpstan, rector)
- PHP operations that work in any environment
- CI/CD pipeline tasks
- Commands that don't require Docker context

**Why This Separation?**
- Composer scripts work **everywhere** (local, Docker, CI, production)
- Taskfile provides **convenience wrappers** and **orchestration**
- Clear separation prevents tool bloat in composer.json
- Taskfile can call Composer scripts (but not vice versa)

## Common Tasks Reference

**Docker Environment:**
```bash
task dev:start          # Start Docker with health check
task dev:stop           # Stop (keeps data)
task dev:restart        # Restart services
task dev:reset          # Complete wipe (prompts)
task dev:logs           # Follow logs
task dev:logs:errors    # Error logs only
task dev:port           # Get OpenEMR URL
task dev:shell          # Container bash
task dev:status         # Health check
```

**Module Management:**
```bash
task module:list        # List all modules and their status
task module:install     # Register, install SQL, and enable module
task module:register    # Register only (no SQL install)
task module:enable      # Enable an installed module
task module:disable     # Disable the module (keeps data)
task module:unregister  # Remove from database (must disable first)
task module:reinstall   # Full cleanup and reinstall
task module:cleanup     # Drop all tables (prompts)
task module:tables      # List module tables
task module:data        # Show data counts
```

The module management tasks use `bin/install-module.php`, a CLI tool that replicates
OpenEMR's web-based module installer. This enables automated/scripted installations.

**Database:**
```bash
task db:shell           # MariaDB shell
task db:export          # Export to backup.sql
task db:import          # Import from backup.sql
task db:query -- "SQL"  # Run ad-hoc query
```

**Code Quality (calls Composer scripts):**
```bash
task check              # All checks (calls: pre-commit run -a)
task check:phpcs        # Code style (calls: composer phpcs)
task check:phpstan      # Static analysis (calls: composer phpstan)
task check:fix          # Auto-fix (calls: composer phpcbf)
```

**Testing:**
```bash
task test               # Run PHPUnit tests
task test:coverage      # Run with coverage report
task test:docker        # Run tests in Docker
```

**Webhook Development:**
```bash
task webhook:tunnel          # Start Tailscale Funnel
task webhook:tunnel:status   # Check tunnel status
task webhook:tunnel:off      # Stop tunnel
task webhook:test:incoming   # Test incoming fax webhook
task webhook:test:completed  # Test completed fax webhook
task webhook:test:failed     # Test failed fax webhook
```

**Quick Workflows:**
```bash
task setup              # Complete setup
task workflow:reinstall # Clean reinstall
task workflow:reset     # Full reset
```

## Global Tool Requirements

**composer-require-checker** is NOT included as a dev dependency because it requires
Symfony Console ^7.x, which conflicts with OpenEMR 7.0.3's requirement for Symfony ^6.4.

To run `composer require-checker` or use pre-commit hooks that check dependencies:

```bash
# Install globally
composer global require maglnet/composer-require-checker

# Ensure global bin is in PATH
export PATH="$HOME/.composer/vendor/bin:$PATH"
```

The `composer require-checker` script will display a helpful error if the tool is missing.

## Best Practices

1. **Prefer Taskfile over raw commands** - It's more user-friendly and self-documenting
2. **Show available tasks** - Use `task --list` to see all tasks organized by category
3. **Mention safety features** - Destructive tasks prompt for confirmation
4. **Combine with explanations** - Explain what the task does, not just the command
5. **Know when to use Composer instead** - For code quality checks that work in CI, suggest `composer phpcs` or `task check` interchangeably

**Good Example:**
```
To clean up the database tables for a fresh reinstall:
task module:cleanup

This will drop all module tables and prompt for confirmation.
After cleanup, reinstall via OpenEMR's module manager.
```

**Bad Example:**
```
Run: docker compose exec -T mysql mariadb -uroot -proot openemr < cleanup.sql
```

## CLI Tools

When creating CLI tools for this module, **always use Symfony Console** instead of
writing command-line parsing by hand.

**Why Symfony Console:**
- Provides consistent, professional CLI interface
- Handles argument/option parsing, validation, and help generation
- Supports colored output, progress bars, tables
- Well-documented and familiar to PHP developers

**Structure:**
- Place commands in `src/Console/Command/`
- Use `bin/` for entrypoint scripts
- Share logic via service classes in `src/Console/`

**Example command:**
```php
namespace OpenCoreEMR\Modules\SinchFax\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ExampleCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('example:task')
             ->setDescription('Does something useful');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Hello!');
        return Command::SUCCESS;
    }
}
```

**Never do this:**
```php
// Don't write CLI parsing by hand
$options = getopt('', ['module:', 'action:']);
if (isset($options['help'])) { ... }
```
