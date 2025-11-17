<?php

/**
 * Webhook Controller - handles incoming webhooks from Sinch
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Controller;

use OpenCoreEMR\Modules\SinchFax\Service\FaxService;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenEMR\Common\Logging\SystemLogger;

class WebhookController
{
    private readonly FaxService $faxService;
    private readonly GlobalConfig $config;
    private readonly SystemLogger $logger;

    public function __construct()
    {
        $this->config = new GlobalConfig();
        $this->faxService = new FaxService($this->config);
        $this->logger = new SystemLogger();
    }

    /**
     * Handle incoming webhook
     */
    public function handleWebhook(): void
    {
        $this->logger->info("Webhook received", [
            'method' => $_SERVER['REQUEST_METHOD'],
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? ''
        ]);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            return;
        }

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

        if (str_contains((string) $contentType, 'multipart/form-data')) {
            $data = $this->parseMultipartFormData();
        } elseif (str_contains((string) $contentType, 'application/json')) {
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Unsupported content type']);
            return;
        }

        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid request data']);
            return;
        }

        try {
            $event = $data['event'] ?? '';

            match ($event) {
                'INCOMING_FAX' => $this->faxService->processIncomingFax($data),
                'FAX_COMPLETED' => $this->faxService->processFaxCompleted($data),
                default => $this->logger->warning("Unknown webhook event: {$event}"),
            };

            http_response_code(200);
            echo json_encode(['status' => 'success']);
        } catch (\Exception $e) {
            $this->logger->error("Webhook processing error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }

    private function parseMultipartFormData(): array
    {
        $data = [];

        $data['event'] = $_POST['event'] ?? '';
        $data['eventTime'] = $_POST['eventTime'] ?? '';

        if (isset($_POST['fax'])) {
            $data['fax'] = json_decode((string) $_POST['fax'], true);
        }

        if (isset($_FILES['file'])) {
            $file = $_FILES['file'];
            if ($file['error'] === UPLOAD_ERR_OK) {
                $data['file'] = base64_encode(file_get_contents($file['tmp_name']));
                $data['fileType'] = 'PDF';
            }
        }

        return $data;
    }
}
