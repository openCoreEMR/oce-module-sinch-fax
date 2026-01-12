<?php

/**
 * Sinch Fax Contact Dialog for Document Viewer
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

$sessionAllowWrite = true;

// Load module autoloader before globals.php so our classes are available
// even when OpenEMR hasn't bootstrapped the module (e.g., module not registered)
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../../../../globals.php';
require_once __DIR__ . '/../../../../../library/classes/Document.class.php';

use OpenCoreEMR\Modules\SinchFax\Bootstrap;
use OpenCoreEMR\Modules\SinchFax\ConfigFactory;
use OpenCoreEMR\Modules\SinchFax\GlobalsAccessor;
use OpenCoreEMR\Modules\SinchFax\ModuleAccessGuard;

// Check if module is installed and enabled - return 404 if not
$guardResponse = ModuleAccessGuard::check(Bootstrap::MODULE_NAME);
if ($guardResponse !== null) {
    $guardResponse->send();
    exit;
}

// Get kernel and bootstrap module
$globalsAccessor = new GlobalsAccessor();
$kernel = $globalsAccessor->get('kernel');
if (!$kernel instanceof \OpenEMR\Core\Kernel) {
    throw new \RuntimeException('OpenEMR Kernel not available');
}
$configAccessor = ConfigFactory::createConfigAccessor();
$bootstrap = new Bootstrap($kernel->getEventDispatcher(), $kernel, $configAccessor);

// Get controller
$controller = $bootstrap->getDocumentFaxController();

// Determine action
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? 'send' : 'show';

// Dispatch to controller and send response
$response = $controller->dispatch($action, $_REQUEST);
$response->send();
