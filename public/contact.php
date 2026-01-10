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
require_once(__DIR__ . "/../../../../globals.php");
require_once(__DIR__ . "/../../../../../library/classes/Document.class.php");

use OpenCoreEMR\Modules\SinchFax\Bootstrap;
use OpenCoreEMR\Modules\SinchFax\GlobalsAccessor;

// Get kernel and bootstrap module
$globalsAccessor = new GlobalsAccessor();
$kernel = $globalsAccessor->get('kernel');
if (!$kernel instanceof \OpenEMR\Core\Kernel) {
    throw new \RuntimeException('OpenEMR Kernel not available');
}
$bootstrap = new Bootstrap($kernel->getEventDispatcher(), $kernel, $globalsAccessor);

// Get controller
$controller = $bootstrap->getDocumentFaxController();

// Determine action
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? 'send' : 'show';

// Dispatch to controller and send response
$response = $controller->dispatch($action, $_REQUEST);
$response->send();
