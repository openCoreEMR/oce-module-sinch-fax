<?php

/**
 * Fax Service - handles business logic for fax operations
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Service;

use OpenCoreEMR\Modules\SinchFax\Client\SinchFaxClient;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenEMR\Common\Logging\SystemLogger;

class FaxService
{
    private SinchFaxClient $client;
    private SystemLogger $logger;
    private GlobalConfig $config;

    public function __construct(?GlobalConfig $config = null)
    {
        $this->config = $config ?? new GlobalConfig();
        $this->client = new SinchFaxClient($this->config);
        $this->logger = new SystemLogger();
    }

    /**
     * Send a fax
     *
     * @param string $to Recipient fax number
     * @param array $files Array of file paths to send
     * @param array $options Additional options
     * @return array Fax information
     */
    public function sendFax(string $to, array $files, array $options = []): array
    {
        $this->logger->info("Sending fax to {$to}");

        $params = [
            'to' => $to,
            'files' => array_map(fn($file) => ['path' => $file], $files),
        ];

        if (isset($options['from'])) {
            $params['from'] = $options['from'];
        }

        if (isset($options['coverPageId'])) {
            $params['coverPageId'] = $options['coverPageId'];
        }

        if (isset($options['callbackUrl'])) {
            $params['callbackUrl'] = $options['callbackUrl'];
        } else {
            $params['callbackUrl'] = $this->getDefaultCallbackUrl();
        }

        $params['maxRetries'] = $options['maxRetries'] ?? $this->config->getDefaultRetryCount();

        $response = $this->client->sendFax($params);

        $this->saveFaxToDatabase($response, 'OUTBOUND', $options);

        return $response;
    }

    /**
     * Retrieve a fax by ID
     *
     * @param string $faxId
     * @return array
     */
    public function getFax(string $faxId): array
    {
        return $this->client->getFax($faxId);
    }

    /**
     * List faxes with optional filters
     *
     * @param array $filters
     * @return array
     */
    public function listFaxes(array $filters = []): array
    {
        return $this->client->listFaxes($filters);
    }

    /**
     * Download fax content and save to file system
     *
     * @param string $faxId
     * @return string Path to saved file
     */
    public function downloadAndSaveFax(string $faxId): string
    {
        $content = $this->client->downloadFax($faxId);
        
        $storagePath = $this->config->getFileStoragePath();
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0770, true);
        }

        $filename = $faxId . '.pdf';
        $filePath = $storagePath . DIRECTORY_SEPARATOR . $filename;

        file_put_contents($filePath, $content);
        chmod($filePath, 0660);

        $this->logger->info("Saved fax {$faxId} to {$filePath}");

        return $filePath;
    }

    /**
     * Process incoming fax webhook
     *
     * @param array $webhookData
     * @return bool
     */
    public function processIncomingFax(array $webhookData): bool
    {
        $this->logger->info("Processing incoming fax", ['data' => $webhookData]);

        $faxData = $webhookData['fax'] ?? [];
        $faxId = $faxData['id'] ?? null;

        if (!$faxId) {
            $this->logger->error("Invalid webhook data: missing fax ID");
            return false;
        }

        $filePath = null;
        if (isset($webhookData['file'])) {
            $storagePath = $this->config->getFileStoragePath();
            if (!is_dir($storagePath)) {
                mkdir($storagePath, 0770, true);
            }

            $filename = $faxId . '.pdf';
            $filePath = $storagePath . DIRECTORY_SEPARATOR . $filename;

            if (isset($webhookData['fileType']) && $webhookData['fileType'] === 'PDF') {
                $content = base64_decode($webhookData['file']);
                file_put_contents($filePath, $content);
                chmod($filePath, 0660);
            }
        }

        $this->saveFaxToDatabase($faxData, 'INBOUND', ['file_path' => $filePath]);

        return true;
    }

    /**
     * Process fax completed webhook
     *
     * @param array $webhookData
     * @return bool
     */
    public function processFaxCompleted(array $webhookData): bool
    {
        $this->logger->info("Processing fax completed", ['data' => $webhookData]);

        $faxData = $webhookData['fax'] ?? [];
        $faxId = $faxData['id'] ?? null;

        if (!$faxId) {
            $this->logger->error("Invalid webhook data: missing fax ID");
            return false;
        }

        $this->updateFaxStatus($faxId, $faxData);

        return true;
    }

    private function saveFaxToDatabase(array $faxData, string $direction, array $options = []): void
    {
        $sql = "INSERT INTO oce_sinch_faxes (
            sinch_fax_id, direction, from_number, to_number, status, num_pages,
            file_path, mime_type, patient_id, user_id, callback_url, cover_page_id,
            sinch_create_time, sinch_completed_time
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $bind = [
            $faxData['id'] ?? '',
            $direction,
            $faxData['from'] ?? '',
            $faxData['to'] ?? '',
            $faxData['status'] ?? 'UNKNOWN',
            $faxData['numberOfPages'] ?? 0,
            $options['file_path'] ?? null,
            $options['mime_type'] ?? 'application/pdf',
            $options['patient_id'] ?? null,
            $options['user_id'] ?? null,
            $faxData['callbackUrl'] ?? null,
            $faxData['coverPageId'] ?? null,
            $faxData['createTime'] ?? null,
            $faxData['completedTime'] ?? null,
        ];

        sqlStatement($sql, $bind);
    }

    private function updateFaxStatus(string $faxId, array $faxData): void
    {
        $sql = "UPDATE oce_sinch_faxes SET 
            status = ?, 
            num_pages = ?,
            error_code = ?,
            error_message = ?,
            sinch_completed_time = ?,
            updated_at = NOW()
        WHERE sinch_fax_id = ?";

        $bind = [
            $faxData['status'] ?? 'UNKNOWN',
            $faxData['numberOfPages'] ?? 0,
            $faxData['errorCode'] ?? null,
            $faxData['errorMessage'] ?? null,
            $faxData['completedTime'] ?? null,
            $faxId,
        ];

        sqlStatement($sql, $bind);
    }

    private function getDefaultCallbackUrl(): string
    {
        global $GLOBALS;
        $webroot = $GLOBALS['webroot'] ?? '';
        $site = $_SESSION['site_id'] ?? 'default';
        
        return $GLOBALS['site_addr_oath'] . $webroot . 
               '/interface/modules/custom_modules/oce-module-sinch-fax/public/webhook.php';
    }
}
