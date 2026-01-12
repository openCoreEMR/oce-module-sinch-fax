<?php

/**
 * Fax download endpoint - serves fax files for viewing/downloading
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

// Load module autoloader before globals.php so our classes are available
// even when OpenEMR hasn't bootstrapped the module (e.g., module not registered)
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../../../../globals.php';

use OpenCoreEMR\Modules\SinchFax\Bootstrap;
use OpenCoreEMR\Modules\SinchFax\ConfigFactory;
use OpenCoreEMR\Modules\SinchFax\Exception\FaxExceptionInterface;
use OpenCoreEMR\Modules\SinchFax\Exception\FaxValidationException;
use OpenCoreEMR\Modules\SinchFax\GlobalsAccessor;
use OpenCoreEMR\Modules\SinchFax\ModuleAccessGuard;
use Symfony\Component\HttpFoundation\Response;

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

// Get controller
$controller = $bootstrap->getFaxDownloadController();

// Get fax ID from request
$faxIdParam = $_GET['fax_id'] ?? 0;
$faxId = is_numeric($faxIdParam) ? (int)$faxIdParam : 0;

if ($faxId === 0) {
    throw new FaxValidationException("Missing fax ID");
}

try {
    // Download the fax and send response
    $response = $controller->download($faxId);
    $response->send();
} catch (FaxExceptionInterface $e) {
    // Log the error
    error_log("Error downloading fax: " . $e->getMessage());

    // Create error response using exception's status code
    $response = new Response(
        "Error: " . htmlspecialchars($e->getMessage()),
        $e->getStatusCode()
    );
    $response->send();
} catch (\Exception $e) {
    // Log unexpected errors
    error_log("Unexpected error downloading fax: " . $e->getMessage());

    // Create generic error response
    $response = new Response(
        "Error: An unexpected error occurred",
        500
    );
    $response->send();
}
