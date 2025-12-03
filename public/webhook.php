<?php

/**
 * Webhook endpoint for Sinch Fax callbacks
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use OpenCoreEMR\Modules\SinchFax\Controller\WebhookController;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenCoreEMR\Modules\SinchFax\GlobalsAccessor;
use Symfony\Component\HttpFoundation\Response;

// Check if webhooks are enabled
$globalsAccessor = new GlobalsAccessor();
$config = new GlobalConfig($globalsAccessor);
if (!$config->isWebhooksEnabled()) {
    $response = new Response('Not Found', Response::HTTP_NOT_FOUND);
    $response->send();
    return;
}

$controller = new WebhookController($globalsAccessor);
$response = $controller->handleWebhook();
$response->send();
