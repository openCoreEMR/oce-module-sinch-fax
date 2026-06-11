<?php

/**
 * Main interface for Sinch Fax module
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

$sessionAllowWrite = true;

require_once __DIR__ . '/../../../../globals.php';

use OpenCoreEMR\Modules\SinchFax\Bootstrap;
use OpenCoreEMR\Modules\SinchFax\ConfigFactory;
use OpenCoreEMR\Modules\SinchFax\GlobalsAccessor;
use OpenCoreEMR\Modules\SinchFax\ModuleAccessGuard;

// Check if module is installed and enabled - return 404 if not
$guardResponse = ModuleAccessGuard::check(Bootstrap::MODULE_NAME);
if ($guardResponse instanceof \Symfony\Component\HttpFoundation\Response) {
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

// globals.php establishes the active session via the SessionWrapperFactory
$session = \OpenEMR\Common\Session\SessionWrapperFactory::getInstance()->getActiveSession();

// Get controller
$controller = $bootstrap->getFaxListController($session);

// Determine action
$actionParam = $_GET['action'] ?? $_POST['action'] ?? 'list';
$action = is_string($actionParam) ? $actionParam : 'list';

// Dispatch to controller and send response
$response = $controller->dispatch($action);
$response->send();
