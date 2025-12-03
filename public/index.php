<?php

/**
 * Main interface for Sinch Fax module
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

$sessionAllowWrite = true;
require_once __DIR__ . '/../../../../globals.php';

use OpenCoreEMR\Modules\SinchFax\Bootstrap;
use OpenCoreEMR\Modules\SinchFax\GlobalsAccessor;

// Get kernel and bootstrap module
$globalsAccessor = new GlobalsAccessor();
$kernel = $globalsAccessor->get('kernel');
$bootstrap = new Bootstrap($kernel->getEventDispatcher(), $kernel, $globalsAccessor);

// Get controller
$controller = $bootstrap->getFaxListController();

// Determine action
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

// Dispatch to controller and send response
$response = $controller->dispatch($action);
$response->send();
