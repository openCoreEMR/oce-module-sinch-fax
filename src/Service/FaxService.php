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
use OpenCoreEMR\Modules\SinchFax\Exception\FaxConfigurationException;
use OpenCoreEMR\Modules\SinchFax\Exception\FaxNotFoundException;
use OpenCoreEMR\Modules\SinchFax\Exception\FaxValidationException;
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
            'files' => array_map(function (array|string $file): array {
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
     * @param array<string, mixed> $options
     */
    private function saveFaxToDatabase(array $faxData, string $direction, array $options = []): void
    {
        // Outbound faxes default to 'read', inbound to 'unread'
        $readStatus = $direction === 'OUTBOUND' ? 'read' : 'unread';

        $sql = <<<'SQL'
            INSERT INTO oce_sinch_faxes (
                sinch_fax_id, direction, from_number, to_number, status, read_status, num_pages,
                file_path, mime_type, patient_id, user_id, callback_url, cover_page_id,
                error_code, error_message, sinch_create_time, sinch_completed_time
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            SQL;

        $bind = [
            $faxData['id'] ?? '',
            $direction,
            $faxData['from'] ?? '',
            $faxData['to'] ?? '',
            $faxData['status'] ?? 'UNKNOWN',
            $readStatus,
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

            if (is_array($existingFax)) {
                // If fax exists but has no file, try to download it (only if Sinch has the file)
                if (($existingFax['file_path'] ?? '') === '' && $fileAvailable) {
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
                } elseif (($existingFax['file_path'] ?? '') === '' && !$fileAvailable) {
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
     * Reconcile local fax records with Sinch API
     *
     * Queries Sinch for inbound faxes since last sync and creates records
     * for any faxes we don't have locally (missed webhooks).
     *
     * @return array<string> List of missed fax IDs that were reconciled
     */
    public function reconcileInboundFaxes(): array
    {
        $lastSyncTime = $this->getLastSyncTime();

        $filters = [
            'direction' => 'INBOUND',
            'pageSize' => 100,
        ];

        if ($lastSyncTime instanceof \DateTimeImmutable) {
            $filters['createTime'] = '>=' . $lastSyncTime->format('c');
        }

        $response = $this->client->listFaxes($filters);
        /** @var array<int, array<string, mixed>> $faxes */
        $faxes = is_array($response['faxes'] ?? null) ? $response['faxes'] : [];

        $missedFaxIds = [];

        foreach ($faxes as $faxItem) {
            $faxIdRaw = $faxItem['id'] ?? null;
            if (!is_scalar($faxIdRaw)) {
                continue;
            }
            $faxId = (string)$faxIdRaw;

            if (!$this->faxExistsLocally($faxId)) {
                // Create record for missed fax - no file available
                $errorMessage = 'Fax acknowledged, but document was not received by OpenEMR. '
                    . 'Contact sender to re-send.';
                $this->saveIncomingFaxToDatabase($faxItem, null, $errorMessage);
                $missedFaxIds[] = $faxId;
                $this->logger->warning("Reconciled missed fax: {$faxId}");
            }
        }

        $this->updateLastSyncTime();

        return $missedFaxIds;
    }

    /**
     * Get the last sync time from the reconciliation table
     */
    private function getLastSyncTime(): ?\DateTimeImmutable
    {
        $sql = 'SELECT last_sync_time FROM oce_sinch_reconciliation WHERE id = 1';
        $row = QueryUtils::querySingleRow($sql, []);

        if (is_array($row) && is_string($row['last_sync_time'] ?? null) && $row['last_sync_time'] !== '') {
            try {
                return new \DateTimeImmutable($row['last_sync_time']);
            } catch (\Throwable $e) {
                $this->logger->error("Invalid last_sync_time in database: " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Update the last sync time to now
     */
    private function updateLastSyncTime(): void
    {
        $sql = <<<'SQL'
            INSERT INTO oce_sinch_reconciliation (id, last_sync_time)
            VALUES (1, NOW())
            ON DUPLICATE KEY UPDATE last_sync_time = NOW()
            SQL;
        QueryUtils::sqlStatementThrowException($sql, []);
    }

    /**
     * Check if a fax exists locally by Sinch fax ID
     */
    private function faxExistsLocally(string $sinchFaxId): bool
    {
        $sql = 'SELECT 1 FROM oce_sinch_faxes WHERE sinch_fax_id = ? LIMIT 1';
        $row = QueryUtils::querySingleRow($sql, [$sinchFaxId]);
        return is_array($row);
    }

    /**
     * @param array<string, mixed> $faxData
     * @param string|null $errorMessage Optional error message override
     */
    private function saveIncomingFaxToDatabase(
        array $faxData,
        ?string $filePath,
        ?string $errorMessage = null
    ): void {
        $sql = <<<'SQL'
            INSERT INTO oce_sinch_faxes (
                sinch_fax_id, direction, from_number, to_number, status, read_status, num_pages,
                file_path, mime_type, error_code, error_message,
                sinch_create_time, sinch_completed_time, created_at
            ) VALUES (?, ?, ?, ?, ?, 'unread', ?, ?, ?, ?, ?, ?, ?, NOW())
            SQL;

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
            $errorMessage ?? $faxData['errorMessage'] ?? null,
            $faxData['createTime'] ?? null,
            $faxData['completedTime'] ?? null,
        ];

        QueryUtils::sqlStatementThrowException($sql, $bind);
    }

    /**
     * Get or create "Received Faxes" category
     *
     * @return int Category ID
     * @throws FaxConfigurationException
     */
    private function getReceivedFaxesCategoryId(): int
    {
        // Try to find existing category
        $sql = "SELECT id FROM categories WHERE name = ? AND parent = 1";
        $category = QueryUtils::querySingleRow($sql, ['Received Faxes']);

        if (is_array($category) && is_numeric($category['id'] ?? null)) {
            return (int) $category['id'];
        }

        // Create the category if it doesn't exist
        $insertSql = "INSERT INTO categories (name, parent, lft, rght, aco_spec) VALUES (?, 1, 0, 0, 'patients|docs')";
        QueryUtils::sqlStatementThrowException($insertSql, ['Received Faxes']);

        // Get the newly created category ID
        $category = QueryUtils::querySingleRow($sql, ['Received Faxes']);

        if (!is_array($category) || !is_numeric($category['id'] ?? null)) {
            throw new FaxConfigurationException("Failed to create 'Received Faxes' category");
        }

        $this->logger->info("Created 'Received Faxes' document category");

        return (int) $category['id'];
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

        if (is_array($existingFax)) {
            // Update existing record if we now have a file (handles reconciled faxes)
            if ($filePath && ($existingFax['file_path'] ?? '') === '') {
                $updateSql = <<<'SQL'
                    UPDATE oce_sinch_faxes
                    SET file_path = ?, error_message = NULL, updated_at = NOW()
                    WHERE id = ?
                    SQL;
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

        if (is_array($existingFax)) {
            // Update existing record with completion status
            $updateSql = <<<'SQL'
                UPDATE oce_sinch_faxes SET
                    status = ?,
                    num_pages = ?,
                    error_code = ?,
                    error_message = ?,
                    sinch_completed_time = ?,
                    updated_at = NOW()
                WHERE id = ?
                SQL;

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
            // Create new record for this fax (outbound defaults to 'read')
            $this->logger->info("Fax {$faxId} not found, creating new record");

            $directionRaw = $faxData['direction'] ?? 'OUTBOUND';
            $direction = is_string($directionRaw) ? $directionRaw : 'OUTBOUND';
            $readStatus = $direction === 'OUTBOUND' ? 'read' : 'unread';

            $insertSql = <<<'SQL'
                INSERT INTO oce_sinch_faxes (
                    sinch_fax_id, direction, from_number, to_number, status, read_status, num_pages,
                    error_code, error_message, sinch_create_time, sinch_completed_time, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                SQL;

            QueryUtils::sqlStatementThrowException($insertSql, [
                $faxId,
                $direction,
                $faxData['from'] ?? '',
                $faxData['to'] ?? '',
                $status,
                $readStatus,
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
     * @throws FaxNotFoundException|FaxValidationException|FaxConfigurationException
     */
    public function moveToPatientDocuments(int $faxId, int $patientId): int
    {
        $faxSql = "SELECT * FROM oce_sinch_faxes WHERE id = ?";
        $fax = QueryUtils::querySingleRow($faxSql, [$faxId]);

        if (!is_array($fax)) {
            throw new FaxNotFoundException("Fax not found");
        }

        /** @var array<string, mixed> $fax */

        $documentIdVal = $fax['document_id'] ?? null;
        if (!in_array($documentIdVal, [null, '', 0], true)) {
            $docIdStr = is_scalar($documentIdVal) ? (string) $documentIdVal : '?';
            throw new FaxValidationException(
                "Fax has already been moved to patient chart (Document ID: {$docIdStr})"
            );
        }

        $faxFilePath = is_string($fax['file_path'] ?? null) ? $fax['file_path'] : '';
        if ($faxFilePath === '' || !file_exists($faxFilePath)) {
            throw new FaxNotFoundException("Fax file not found");
        }

        $fileContents = file_get_contents($faxFilePath);
        if ($fileContents === false) {
            throw new FaxValidationException("Unable to read fax file");
        }

        $direction = is_string($fax['direction'] ?? null) ? $fax['direction'] : '';
        $fromNumber = is_string($fax['from_number'] ?? null) ? $fax['from_number'] : '';
        $toNumber = is_string($fax['to_number'] ?? null) ? $fax['to_number'] : '';
        $sinchFaxId = is_string($fax['sinch_fax_id'] ?? null) ? $fax['sinch_fax_id'] : '';

        if ($direction === 'INBOUND') {
            $filename = "Incoming_Fax_{$fromNumber}_{$sinchFaxId}.pdf";
        } else {
            $filename = "Outgoing_Fax_{$toNumber}_{$sinchFaxId}.pdf";
        }

        // Get or create the "Received Faxes" category
        $categoryId = $this->getReceivedFaxesCategoryId();

        $mimeType = is_string($fax['mime_type'] ?? null) ? $fax['mime_type'] : 'application/pdf';

        $document = new \Document();
        $result = $document->createDocument(
            $patientId,
            $categoryId,
            $filename,
            $mimeType,
            $fileContents
        );

        if ($result !== '' && $result !== null) {
            $resultStr = is_scalar($result) ? (string) $result : 'unknown error';
            throw new FaxConfigurationException("Failed to create document: " . $resultStr);
        }

        $documentId = $document->get_id();
        $documentIdInt = is_numeric($documentId) ? (int) $documentId : 0;

        $updateSql = "UPDATE oce_sinch_faxes SET document_id = ?, patient_id = ?, updated_at = NOW() WHERE id = ?";
        QueryUtils::sqlStatementThrowException($updateSql, [$documentIdInt, $patientId, $faxId]);

        $this->logger->info("Moved fax {$faxId} to patient {$patientId} as document {$documentIdInt}");

        return $documentIdInt;
    }

    /**
     * Get configured fax phone numbers from Sinch
     *
     * If Service ID is configured, returns numbers for that service only.
     * Otherwise, discovers all services and returns all associated numbers.
     *
     * @return array{
     *     numbers: array<int, array{phoneNumber: string, serviceName?: string}>,
     *     error: string|null
     * }
     */
    public function getConfiguredFaxNumbers(): array
    {
        $result = ['numbers' => [], 'error' => null];

        try {
            $serviceId = $this->config->getServiceId();

            if ($serviceId !== '' && $serviceId !== '0') {
                // Service ID is configured - get numbers for this service only
                $response = $this->client->listServiceNumbers($serviceId);
                $numbers = is_array($response['numbers'] ?? null) ? $response['numbers'] : [];
                foreach ($numbers as $number) {
                    if (!is_array($number)) {
                        continue;
                    }
                    $phoneNumber = $number['phoneNumber'] ?? null;
                    if (is_string($phoneNumber)) {
                        $result['numbers'][] = ['phoneNumber' => $phoneNumber];
                    }
                }
            } else {
                // No Service ID - discover all services and their numbers
                $servicesResponse = $this->client->listServices();
                $services = is_array($servicesResponse['services'] ?? null) ? $servicesResponse['services'] : [];

                foreach ($services as $service) {
                    if (!is_array($service)) {
                        continue;
                    }
                    $svcId = $service['id'] ?? null;
                    $svcName = $service['name'] ?? 'Unnamed Service';
                    if (!is_string($svcId)) {
                        continue;
                    }

                    try {
                        $numbersResponse = $this->client->listServiceNumbers($svcId);
                        $numbers = is_array($numbersResponse['numbers'] ?? null) ? $numbersResponse['numbers'] : [];
                        foreach ($numbers as $number) {
                            if (!is_array($number)) {
                                continue;
                            }
                            $phoneNumber = $number['phoneNumber'] ?? null;
                            if (is_string($phoneNumber)) {
                                $result['numbers'][] = [
                                    'phoneNumber' => $phoneNumber,
                                    'serviceName' => is_string($svcName) ? $svcName : 'Unnamed Service',
                                ];
                            }
                        }
                    } catch (\Throwable $e) {
                        $this->logger->warning("Failed to get numbers for service {$svcId}: " . $e->getMessage());
                        // Continue with other services
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get fax numbers: ' . $e->getMessage());
            $result['error'] = 'Unable to retrieve fax numbers';
        }

        return $result;
    }
}
