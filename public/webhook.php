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

// Check if webhooks are enabled
$config = new GlobalConfig();
if (!$config->isWebhooksEnabled()) {
    http_response_code(404);
    exit;
}

$controller = new WebhookController();
$controller->handleWebhook();
