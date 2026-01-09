<?php

/**
 * Fax Service - handles business logic for fax operations
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
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

    public function __construct(private readonly GlobalConfig $config = new GlobalConfig())
    {
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

        // Ensure storage directory exists
        $storagePath = $this->config->getFileStoragePath();
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0770, true);
        }

        // Save a copy of the first file for our records (before sending)
        $savedFilePath = null;
        $firstFile = $files[0] ?? null;
        if ($firstFile) {
            $sourcePath = is_array($firstFile) ? $firstFile['path'] : $firstFile;
            $originalFilename = is_array($firstFile)
                ? ($firstFile['filename'] ?? basename($sourcePath))
                : basename($sourcePath);

            if (file_exists($sourcePath)) {
                // Generate unique filename with timestamp
                $extension = pathinfo($originalFilename, PATHINFO_EXTENSION) ?: 'pdf';
                $uniqueFilename = 'outbound_' . date('Ymd_His') . '_' . uniqid() . '.' . $extension;
                $savedFilePath = $storagePath . DIRECTORY_SEPARATOR . $uniqueFilename;

                if (copy($sourcePath, $savedFilePath)) {
                    chmod($savedFilePath, 0660);
                    $this->logger->debug("Saved outbound fax file to {$savedFilePath}");
                } else {
                    $this->logger->warning("Failed to save copy of outbound fax file");
                    $savedFilePath = null;
                }
            }
        }

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

        $params['maxRetries'] = $options['maxRetries'] ?? $this->config->getDefaultRetryCount();

        $response = $this->client->sendFax($params);

        // Add the saved file path to options for database storage
        $options['file_path'] = $savedFilePath;

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
     * Poll for new incoming faxes from Sinch API
     *
     * @return int Number of new faxes found
     */
    public function pollIncomingFaxes(): int
    {
        $filters = [
            'direction' => 'INBOUND',
            'pageSize' => 100,
        ];

        $response = $this->client->listFaxes($filters);
        /** @var array<int, array<string, mixed>> $faxes */
        $faxes = is_array($response['faxes'] ?? null) ? $response['faxes'] : [];
        $newFaxCount = 0;

        $this->logger->debug("Polling for incoming faxes, found " . count($faxes) . " from API");

        foreach ($faxes as $faxItem) {
            $faxIdRaw = $faxItem['id'] ?? null;
            if (!is_scalar($faxIdRaw)) {
                continue;
            }
            $faxId = (string)$faxIdRaw;

            $statusRaw = $faxItem['status'] ?? '';
            $status = is_string($statusRaw) ? $statusRaw : '';
            // hasFile indicates if file content is available on Sinch servers
            $hasFile = $faxItem['hasFile'] ?? false;
            $fileAvailable = ($hasFile === true || $hasFile === 'true');

            $this->logger->debug(
                "Processing fax {$faxId}: status={$status}, hasFile=" . var_export($hasFile, true)
            );

            // Check if we already have this fax
            $existingSql = "SELECT id, file_path, status FROM oce_sinch_faxes WHERE sinch_fax_id = ?";
            $existingFax = QueryUtils::querySingleRow($existingSql, [$faxId]);

            if ($existingFax) {
                // If fax exists but has no file, try to download it (only if Sinch has the file)
                if (empty($existingFax['file_path']) && $fileAvailable) {
                    $this->logger->debug("Existing fax {$faxId} has no file, attempting download");
                    try {
                        $filePath = $this->downloadAndSaveFax($faxId);
                        $updateSql = "UPDATE oce_sinch_faxes " .
                                     "SET file_path = ?, updated_at = NOW() WHERE sinch_fax_id = ?";
                        QueryUtils::sqlStatementThrowException($updateSql, [$filePath, $faxId]);
                        $this->logger->info("Downloaded missing file for fax {$faxId} to {$filePath}");
                    } catch (\Throwable $e) {
                        $this->logger->error("Failed to download fax {$faxId}: " . $e->getMessage());
                    }
                } elseif (empty($existingFax['file_path']) && !$fileAvailable) {
                    $this->logger->debug("Fax {$faxId} has no file available on Sinch (hasFile=false)");
                }
                continue;
            }

            // New fax - try to download the content if available
            $filePath = null;
            if ($fileAvailable) {
                $this->logger->debug("New fax {$faxId}, attempting download");
                try {
                    $filePath = $this->downloadAndSaveFax($faxId);
                    $this->logger->info("Downloaded incoming fax {$faxId} to {$filePath}");
                } catch (\Throwable $e) {
                    $this->logger->error("Failed to download incoming fax {$faxId}: " . $e->getMessage());
                    // Continue to save the fax record even without the file
                }
            } else {
                $this->logger->info(
                    "Fax {$faxId} has no file available on Sinch (hasFile=false) - file may have been deleted"
                );
            }

            // Save to database
            /** @var array<string, mixed> $faxItem */
            $this->saveIncomingFaxToDatabase($faxItem, $filePath);
            $newFaxCount++;
        }

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
     * Process an incoming fax webhook event
     *
     * @param array<string, mixed> $faxData Fax data from webhook
     * @param string|null $fileContent Binary content of fax file (if included in webhook)
     */
    public function processIncomingFax(array $faxData, ?string $fileContent = null): void
    {
        $faxIdRaw = $faxData['id'] ?? null;
        if (!is_scalar($faxIdRaw)) {
            throw new \InvalidArgumentException("Missing fax ID in webhook data");
        }
        $faxId = (string)$faxIdRaw;

        $this->logger->info("Processing incoming fax webhook: {$faxId}");

        // Check if we already have this fax
        $existingSql = "SELECT id, file_path FROM oce_sinch_faxes WHERE sinch_fax_id = ?";
        $existingFax = QueryUtils::querySingleRow($existingSql, [$faxId]);

        // Save file if content was provided
        $filePath = null;
        if ($fileContent) {
            $filePath = $this->saveFileContent($faxId, $fileContent);
        }

        if ($existingFax) {
            // Update existing record if we now have a file
            if ($filePath && empty($existingFax['file_path'])) {
                $updateSql = "UPDATE oce_sinch_faxes SET file_path = ?, updated_at = NOW() WHERE id = ?";
                QueryUtils::sqlStatementThrowException($updateSql, [$filePath, $existingFax['id']]);
                $this->logger->info("Updated fax {$faxId} with file: {$filePath}");
            }
            return;
        }

        // If no file content in webhook, try to download from Sinch API
        if (!$filePath) {
            try {
                $filePath = $this->downloadAndSaveFax($faxId);
                $this->logger->info("Downloaded incoming fax {$faxId} to {$filePath}");
            } catch (\Throwable $e) {
                $this->logger->warning("Could not download fax {$faxId}: " . $e->getMessage());
                // Continue to save the record even without file
            }
        }

        // Save to database
        $this->saveIncomingFaxToDatabase($faxData, $filePath);
        $this->logger->info("Saved incoming fax {$faxId} to database");
    }

    /**
     * Process a fax completed webhook event
     *
     * @param array<string, mixed> $faxData Fax data from webhook
     */
    public function processFaxCompleted(array $faxData): void
    {
        $faxIdRaw = $faxData['id'] ?? null;
        if (!is_scalar($faxIdRaw)) {
            throw new \InvalidArgumentException("Missing fax ID in webhook data");
        }
        $faxId = (string)$faxIdRaw;

        $this->logger->info("Processing fax completed webhook: {$faxId}");

        // Find existing fax record
        $sql = "SELECT id FROM oce_sinch_faxes WHERE sinch_fax_id = ?";
        $existingFax = QueryUtils::querySingleRow($sql, [$faxId]);

        $statusRaw = $faxData['status'] ?? 'UNKNOWN';
        $status = is_string($statusRaw) ? $statusRaw : 'UNKNOWN';
        $errorCode = $faxData['errorCode'] ?? null;
        $errorMessage = $faxData['errorMessage'] ?? null;
        $numPages = $faxData['numberOfPages'] ?? 0;
        $completedTime = $faxData['completedTime'] ?? null;

        if ($existingFax) {
            // Update existing record with completion status
            $updateSql = "UPDATE oce_sinch_faxes SET
                status = ?,
                num_pages = ?,
                error_code = ?,
                error_message = ?,
                sinch_completed_time = ?,
                updated_at = NOW()
                WHERE id = ?";

            QueryUtils::sqlStatementThrowException($updateSql, [
                $status,
                $numPages,
                $errorCode,
                $errorMessage,
                $completedTime,
                $existingFax['id'],
            ]);

            $this->logger->info("Updated fax {$faxId} status to {$status}");
        } else {
            // Create new record for this fax
            $this->logger->info("Fax {$faxId} not found, creating new record");

            $direction = $faxData['direction'] ?? 'OUTBOUND';
            $insertSql = "INSERT INTO oce_sinch_faxes (
                sinch_fax_id, direction, from_number, to_number, status, num_pages,
                error_code, error_message, sinch_create_time, sinch_completed_time, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

            QueryUtils::sqlStatementThrowException($insertSql, [
                $faxId,
                $direction,
                $faxData['from'] ?? '',
                $faxData['to'] ?? '',
                $status,
                $numPages,
                $errorCode,
                $errorMessage,
                $faxData['createTime'] ?? null,
                $completedTime,
            ]);
        }
    }

    /**
     * Save file content to storage
     *
     * @param string $faxId Sinch fax ID
     * @param string $content Binary file content
     * @return string Path to saved file
     */
    private function saveFileContent(string $faxId, string $content): string
    {
        $storagePath = $this->config->getFileStoragePath();
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0770, true);
        }

        $filename = 'inbound_' . date('Ymd_His') . '_' . $faxId . '.pdf';
        $filePath = $storagePath . DIRECTORY_SEPARATOR . $filename;

        file_put_contents($filePath, $content);
        chmod($filePath, 0660);

        $this->logger->debug("Saved fax file to {$filePath}");

        return $filePath;
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
}
