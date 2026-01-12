<?php

/**
 * Webhook endpoint for receiving Sinch fax events
 *
 * This endpoint is called by Sinch to notify of fax events.
 * It does NOT require OpenEMR authentication since it's called by Sinch servers.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

// Don't require session for webhooks - Sinch calls this endpoint directly
$ignoreAuth = true;
require_once __DIR__ . '/../../../../globals.php';

use OpenCoreEMR\Modules\SinchFax\Bootstrap;
use OpenCoreEMR\Modules\SinchFax\ConfigFactory;
use OpenCoreEMR\Modules\SinchFax\Exception\FaxExceptionInterface;
use OpenCoreEMR\Modules\SinchFax\GlobalsAccessor;
use OpenEMR\Common\Logging\SystemLogger;
use Symfony\Component\HttpFoundation\Response;

$logger = new SystemLogger();

try {
    // Get kernel and bootstrap module
    $globalsAccessor = new GlobalsAccessor();
    $kernel = $globalsAccessor->get('kernel');
    if (!$kernel instanceof \OpenEMR\Core\Kernel) {
        throw new \RuntimeException('OpenEMR Kernel not available');
    }
    $configAccessor = ConfigFactory::createConfigAccessor();
    $bootstrap = new Bootstrap($kernel->getEventDispatcher(), $kernel, $configAccessor);

    // Get webhook controller and dispatch
    $controller = $bootstrap->getWebhookController();
    $response = $controller->dispatch();
    $response->send();
} catch (FaxExceptionInterface $e) {
    $logger->error("Webhook error: " . $e->getMessage());

    $response = new Response(
        (string) json_encode(['error' => $e->getMessage()]),
        $e->getStatusCode(),
        ['Content-Type' => 'application/json']
    );
    $response->send();
} catch (\Throwable $e) {
    $logger->error("Unexpected webhook error: " . $e->getMessage());

    $response = new Response(
        (string) json_encode(['error' => 'Internal server error']),
        Response::HTTP_INTERNAL_SERVER_ERROR,
        ['Content-Type' => 'application/json']
    );
    $response->send();
}
