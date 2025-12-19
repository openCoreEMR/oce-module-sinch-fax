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

require_once __DIR__ . '/../../../../globals.php';

use OpenCoreEMR\Modules\SinchFax\Bootstrap;
use OpenCoreEMR\Modules\SinchFax\Exception\FaxExceptionInterface;
use OpenCoreEMR\Modules\SinchFax\Exception\FaxValidationException;
use OpenCoreEMR\Modules\SinchFax\GlobalsAccessor;
use Symfony\Component\HttpFoundation\Response;

// Get kernel and bootstrap module
$globalsAccessor = new GlobalsAccessor();
$kernel = $globalsAccessor->get('kernel');
$bootstrap = new Bootstrap($kernel->getEventDispatcher(), $kernel, $globalsAccessor);

// Get controller
$controller = $bootstrap->getFaxDownloadController();

// Get fax ID from request
$faxId = (int)($_GET['fax_id'] ?? 0);

if (empty($faxId)) {
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
