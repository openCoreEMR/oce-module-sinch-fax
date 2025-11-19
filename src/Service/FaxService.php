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
    private readonly SinchFaxClient $client;
    private readonly SystemLogger $logger;
    private readonly GlobalConfig $config;

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
     * @param array<int, string> $files Array of file paths to send
     * @param array<string, mixed> $options Additional options
     * @return array<string, mixed> Fax information
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

        // Only set callback URL if it's explicitly provided and is a valid public URL
        if (isset($options['callbackUrl']) && !empty($options['callbackUrl'])) {
            $params['callbackUrl'] = $options['callbackUrl'];
        } elseif (!empty($this->config->getSiteAddrOath())) {
            $callbackUrl = $this->getDefaultCallbackUrl();
            // Only set if it's not localhost/internal IP
            if (!preg_match('/localhost|127\.0\.0\.1|192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\./', $callbackUrl)) {
                $params['callbackUrl'] = $callbackUrl;
            } else {
                $this->logger->debug("Skipping callback URL (localhost detected): {$callbackUrl}");
            }
        }
        // If no valid callback URL, don't set it (Sinch will use default behavior)

        $params['maxRetries'] = $options['maxRetries'] ?? $this->config->getDefaultRetryCount();

        $response = $this->client->sendFax($params);

        $this->saveFaxToDatabase($response, 'OUTBOUND', $options);

        return $response;
    }

    /**
     * Retrieve a fax by ID
     *
     * @param string $faxId
     * @return array<string, mixed>
     */
    public function getFax(string $faxId): array
    {
        return $this->client->getFax($faxId);
    }

    /**
     * List faxes with optional filters
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
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
     * @param array<string, mixed> $webhookData
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
                $content = base64_decode((string) $webhookData['file']);
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
     * @param array<string, mixed> $webhookData
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

    /**
     * @param array<string, mixed> $faxData
     * @param string $direction
     * @param array<string, mixed> $options
     */
    private function saveFaxToDatabase(array $faxData, string $direction, array $options = []): void
    {
        $sql = "INSERT INTO oce_sinch_faxes (
            sinch_fax_id, direction, from_number, to_number, status, num_pages,
            file_path, mime_type, patient_id, user_id, callback_url, cover_page_id,
            error_code, error_message,
            sinch_create_time, sinch_completed_time
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

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
            $faxData['errorCode'] ?? null,
            $faxData['errorMessage'] ?? null,
            $faxData['createTime'] ?? null,
            $faxData['completedTime'] ?? null,
        ];

        sqlStatement($sql, $bind);
    }

    /**
     * @param string $faxId
     * @param array<string, mixed> $faxData
     */
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

    /**
     * Poll for new incoming faxes
     *
     * @return int Number of new faxes found
     */
    public function pollIncomingFaxes(): int
    {
        $lastPollTime = $this->config->getLastPollTime();
        $filters = [
            'direction' => 'INBOUND',
            'pageSize' => 100,
        ];

        // If we have a last poll time, only get faxes created after that time
        if ($lastPollTime) {
            $filters['createTime'] = 'gt:' . $lastPollTime;
        }

        $response = $this->listFaxes($filters);
        $faxes = $response['faxes'] ?? [];
        $newFaxCount = 0;

        foreach ($faxes as $faxData) {
            $faxId = $faxData['id'] ?? null;
            if (!$faxId) {
                continue;
            }

            // Check if we already have this fax
            $existingSql = "SELECT COUNT(*) as count FROM oce_sinch_faxes WHERE sinch_fax_id = ?";
            $existingResult = sqlQuery($existingSql, [$faxId]);

            if ($existingResult['count'] > 0) {
                continue;
            }

            // Download the fax content if available
            $filePath = null;
            if (($faxData['hasFile'] ?? 'false') === 'true') {
                try {
                    $filePath = $this->downloadAndSaveFax($faxId);
                } catch (\Exception $e) {
                    $this->logger->error("Failed to download incoming fax {$faxId}: " . $e->getMessage());
                }
            }

            // Save to database
            $this->saveIncomingFaxToDatabase($faxData, $filePath);
            $newFaxCount++;
        }

        // Update last poll time
        $currentTime = date('Y-m-d\TH:i:s\Z');
        $this->config->setLastPollTime($currentTime);

        return $newFaxCount;
    }

    /**
     * @param array<string, mixed> $faxData
     * @param string|null $filePath
     */
    private function saveIncomingFaxToDatabase(array $faxData, ?string $filePath): void
    {
        $sql = "INSERT INTO oce_sinch_faxes (
            sinch_fax_id, direction, from_number, to_number, status, num_pages,
            file_path, mime_type, error_code, error_message,
            sinch_create_time, sinch_completed_time, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $bind = [
            $faxData['id'] ?? '',
            'INBOUND',
            $faxData['from'] ?? '',
            $faxData['to'] ?? '',
            $faxData['status'] ?? 'UNKNOWN',
            $faxData['numberOfPages'] ?? 0,
            $filePath,
            'application/pdf',
            $faxData['errorCode'] ?? null,
            $faxData['errorMessage'] ?? null,
            $faxData['createTime'] ?? null,
            $faxData['completedTime'] ?? null,
        ];

        sqlStatement($sql, $bind);
    }

    private function getDefaultCallbackUrl(): string
    {
        $webroot = $this->config->getWebroot();

        return $this->config->getSiteAddrOath() . $webroot .
               '/interface/modules/custom_modules/oce-module-sinch-fax/public/webhook.php';
    }
}
