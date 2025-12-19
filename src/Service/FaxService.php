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
}
