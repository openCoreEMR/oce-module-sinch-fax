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
use OpenEMR\Common\Database\QueryUtils;
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
     * @param array<int, string|array{path: string, filename?: string}> $files Array of file paths or file info arrays
     * @param array<string, mixed> $options Additional options
     * @return array<string, mixed> Fax information
     */
    public function sendFax(string $to, array $files, array $options = []): array
    {
        $this->logger->info("Sending fax to {$to}");

        $params = [
            'to' => $to,
            'files' => array_map(function ($file) {
                // Handle both old format (string path) and new format (array with path and filename)
                if (is_array($file)) {
                    return $file;
                }
                return ['path' => $file];
            }, $files),
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

        // Download and save the file if it's completed and we don't have it yet
        $status = $faxData['status'] ?? '';
        if ($status === 'COMPLETED' || $status === 'SUCCESS') {
            // Check if we already have the file
            $checkSql = "SELECT file_path FROM oce_sinch_faxes WHERE sinch_fax_id = ?";
            $existingFax = QueryUtils::querySingleRow($checkSql, [$faxId]);

            if ($existingFax && empty($existingFax['file_path'])) {
                try {
                    $filePath = $this->downloadAndSaveFax($faxId);
                    $updatePathSql = "UPDATE oce_sinch_faxes " .
                                     "SET file_path = ?, updated_at = NOW() WHERE sinch_fax_id = ?";
                    QueryUtils::sqlStatementThrowException($updatePathSql, [$filePath, $faxId]);
                    $this->logger->info("Downloaded completed fax {$faxId} to {$filePath}");
                } catch (\Throwable $e) {
                    $this->logger->error("Failed to download completed fax {$faxId}: " . $e->getMessage());
                }
            }
        }

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

        QueryUtils::sqlStatementThrowException($sql, $bind);
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

        QueryUtils::sqlStatementThrowException($sql, $bind);
    }

    /**
     * Download files for completed faxes that don't have files yet
     *
     * @return int Number of files downloaded
     */
    public function downloadMissingFiles(): int
    {
        $sql = "SELECT sinch_fax_id FROM oce_sinch_faxes
                WHERE (status = 'COMPLETED' OR status = 'SUCCESS')
                AND (file_path IS NULL OR file_path = '')
                AND document_id IS NULL";

        $faxes = QueryUtils::fetchRecords($sql);
        $downloadedCount = 0;

        foreach ($faxes as $fax) {
            $faxId = $fax['sinch_fax_id'];
            try {
                $filePath = $this->downloadAndSaveFax($faxId);
                $updatePathSql = "UPDATE oce_sinch_faxes " .
                                 "SET file_path = ?, updated_at = NOW() WHERE sinch_fax_id = ?";
                QueryUtils::sqlStatementThrowException($updatePathSql, [$filePath, $faxId]);
                $this->logger->info("Downloaded missing file for fax {$faxId}");
                $downloadedCount++;
            } catch (\Throwable $e) {
                $this->logger->error("Failed to download file for fax {$faxId}: " . $e->getMessage());
            }
        }

        return $downloadedCount;
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
            $existingSql = "SELECT id, file_path, status FROM oce_sinch_faxes WHERE sinch_fax_id = ?";
            $existingFax = QueryUtils::querySingleRow($existingSql, [$faxId]);

            if ($existingFax) {
                // If fax exists but has no file and is completed, retry download
                $status = $faxData['status'] ?? '';
                $isCompleted = ($status === 'COMPLETED' || $status === 'SUCCESS');
                $hasFile = $faxData['hasFile'] ?? null;
                $fileAvailable = (in_array($hasFile, [true, 'true', 1], true));

                if (empty($existingFax['file_path']) && ($isCompleted || $fileAvailable)) {
                    try {
                        $filePath = $this->downloadAndSaveFax($faxId);
                        $updateSql = "UPDATE oce_sinch_faxes " .
                                     "SET file_path = ?, updated_at = NOW() WHERE sinch_fax_id = ?";
                        QueryUtils::sqlStatementThrowException($updateSql, [$filePath, $faxId]);
                        $this->logger->info("Retried and downloaded fax {$faxId} to {$filePath}");
                    } catch (\Throwable $e) {
                        $this->logger->error("Retry failed to download fax {$faxId}: " . $e->getMessage());
                    }
                }
                continue;
            }

            // Download the fax content if available
            // Try to download for completed faxes or when hasFile indicates availability
            $filePath = null;
            $status = $faxData['status'] ?? '';
            $hasFile = $faxData['hasFile'] ?? null;

            // hasFile can be boolean true, string "true", or number 1
            $fileAvailable = (in_array($hasFile, [true, 'true', 1], true));
            $isCompleted = ($status === 'COMPLETED' || $status === 'SUCCESS');

            if ($fileAvailable || $isCompleted) {
                try {
                    $filePath = $this->downloadAndSaveFax($faxId);
                    $this->logger->info("Downloaded incoming fax {$faxId} to {$filePath}");
                } catch (\Throwable $e) {
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

        QueryUtils::sqlStatementThrowException($sql, $bind);
    }

    /**
     * Get or create "Received Faxes" category
     *
     * @return int Category ID
     * @throws \Exception
     */
    private function getReceivedFaxesCategoryId(): int
    {
        // Try to find existing category
        $sql = "SELECT id FROM categories WHERE name = ? AND parent = 1";
        $category = QueryUtils::querySingleRow($sql, ['Received Faxes']);

        if ($category) {
            return (int)$category['id'];
        }

        // Create the category if it doesn't exist
        $insertSql = "INSERT INTO categories (name, parent, lft, rght, aco_spec) VALUES (?, 1, 0, 0, 'patients|docs')";
        QueryUtils::sqlStatementThrowException($insertSql, ['Received Faxes']);

        // Get the newly created category ID
        $category = QueryUtils::querySingleRow($sql, ['Received Faxes']);

        if (!$category) {
            throw new \Exception("Failed to create 'Received Faxes' category");
        }

        $this->logger->info("Created 'Received Faxes' document category");

        return (int)$category['id'];
    }

    /**
     * Move fax to patient document tree
     *
     * @param int $faxId Internal fax database ID
     * @param int $patientId Patient ID
     * @return int Created document ID
     * @throws \Exception
     */
    public function moveToPatientDocuments(int $faxId, int $patientId): int
    {
        $faxSql = "SELECT * FROM oce_sinch_faxes WHERE id = ?";
        $fax = QueryUtils::querySingleRow($faxSql, [$faxId]);

        if (!$fax) {
            throw new \Exception("Fax not found");
        }

        if ($fax['document_id']) {
            throw new \Exception("Fax has already been moved to patient chart (Document ID: {$fax['document_id']})");
        }

        if (empty($fax['file_path']) || !file_exists($fax['file_path'])) {
            throw new \Exception("Fax file not found");
        }

        $fileContents = file_get_contents($fax['file_path']);
        if ($fileContents === false) {
            throw new \Exception("Unable to read fax file");
        }

        $filename = basename((string) $fax['file_path']);
        if ($fax['direction'] === 'INBOUND') {
            $filename = "Incoming_Fax_{$fax['from_number']}_{$fax['sinch_fax_id']}.pdf";
        } else {
            $filename = "Outgoing_Fax_{$fax['to_number']}_{$fax['sinch_fax_id']}.pdf";
        }

        // Get or create the "Received Faxes" category
        $categoryId = $this->getReceivedFaxesCategoryId();

        $document = new \Document();
        $result = $document->createDocument(
            $patientId,
            $categoryId,
            $filename,
            $fax['mime_type'] ?? 'application/pdf',
            $fileContents
        );

        if (!empty($result)) {
            throw new \Exception("Failed to create document: " . $result);
        }

        $documentId = $document->get_id();

        $updateSql = "UPDATE oce_sinch_faxes SET document_id = ?, patient_id = ?, updated_at = NOW() WHERE id = ?";
        QueryUtils::sqlStatementThrowException($updateSql, [$documentId, $patientId, $faxId]);

        $this->logger->info("Moved fax {$faxId} to patient {$patientId} as document {$documentId}");

        return $documentId;
    }

    private function getDefaultCallbackUrl(): string
    {
        $webroot = $this->config->getWebroot();

        return $this->config->getSiteAddrOath() . $webroot .
               '/interface/modules/custom_modules/oce-module-sinch-fax/public/webhook.php';
    }
}
