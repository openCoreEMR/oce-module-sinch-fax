# Module Architecture

OpenEMR modules follow a **Symfony-inspired MVC architecture** with:
- **Controllers** in `src/Controller/` handling business logic
- **Twig templates** in `templates/` for all HTML rendering
- **Services** in `src/Service/` for business operations
- **Minimal public entry points** in `public/` that bootstrap and dispatch

## File Structure Convention

```
oce-module-{name}/
├── public/
│   ├── index.php          # Main entry point (25-35 lines)
│   ├── {feature}.php      # Feature entry points (25-35 lines)
│   └── assets/            # Static assets (CSS, JS, images)
├── src/
│   ├── Bootstrap.php      # Module initialization and DI
│   ├── Controller/        # Request handlers
│   │   ├── {Feature}Controller.php
│   │   └── ...
│   ├── Service/           # Business logic
│   │   ├── {Feature}Service.php
│   │   └── ...
│   ├── Exception/         # Custom exception types
│   │   ├── {Module}ExceptionInterface.php
│   │   ├── {Module}Exception.php
│   │   └── {Specific}Exception.php
│   └── GlobalConfig.php   # Configuration wrapper
├── templates/
│   └── {feature}/
│       ├── {view}.html.twig
│       └── partials/
│           └── _{component}.html.twig
└── composer.json
```

## Public Entry Point Pattern

Public PHP files should be short! Just dispatch a controller and send a response. Follow this pattern:

```php
<?php
/**
 * [Description of endpoint]
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    [Author Name] <email@example.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use OpenCoreEMR\Modules\{ModuleName}\Bootstrap;
use OpenCoreEMR\Modules\{ModuleName}\GlobalsAccessor;

// Get kernel and bootstrap module
$globalsAccessor = new GlobalsAccessor();
$kernel = $globalsAccessor->get('kernel');
$bootstrap = new Bootstrap($kernel->getEventDispatcher(), $kernel, $globalsAccessor);

// Get controller
$controller = $bootstrap->get{Feature}Controller();

// Determine action
$action = $_GET['action'] ?? $_POST['action'] ?? 'default';

// Dispatch to controller and send response
$response = $controller->dispatch($action);
$response->send();
```

## Bootstrap Pattern

The `Bootstrap.php` class should provide factory methods for controllers:

```php
<?php

namespace OpenCoreEMR\Modules\{ModuleName};

use OpenCoreEMR\Modules\{ModuleName}\Controller\{Feature}Controller;
use OpenCoreEMR\Modules\{ModuleName}\Service\{Feature}Service;
use OpenEMR\Core\Kernel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    public const MODULE_NAME = "oce-module-{name}";

    private readonly GlobalConfig $globalsConfig;
    private readonly \Twig\Environment $twig;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly Kernel $kernel = new Kernel(),
        private readonly GlobalsAccessor $globals = new GlobalsAccessor()
    ) {
        $this->globalsConfig = new GlobalConfig($this->globals);

        $templatePath = \dirname(__DIR__) . DIRECTORY_SEPARATOR . "templates" . DIRECTORY_SEPARATOR;
        $twig = new TwigContainer($templatePath, $this->kernel);
        $this->twig = $twig->getTwig();
    }

    /**
     * Get {Feature}Controller instance
     */
    public function get{Feature}Controller(): {Feature}Controller
    {
        return new {Feature}Controller(
            $this->globalsConfig,
            new {Feature}Service($this->globalsConfig),
            $this->twig
        );
    }
}
```
