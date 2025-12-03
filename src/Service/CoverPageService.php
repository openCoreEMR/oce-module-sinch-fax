<?php

/**
 * Cover Page Service - handles cover page management
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Service;

use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;

class CoverPageService
{
    private readonly SystemLogger $logger;
    private readonly GlobalConfig $config;

    public function __construct(?GlobalConfig $config = null)
    {
        $this->config = $config ?? new GlobalConfig();
        $this->logger = new SystemLogger();
    }

    /**
     * Upload and store a cover page template
     *
     * @param string $name Cover page name
     * @param string $tmpPath Temporary file path
     * @param string $filename Original filename
     * @return array<string, mixed> Cover page information
     * @throws \Exception
     */
    public function uploadCoverPage(string $name, string $tmpPath, string $filename): array
    {
        // Validate file is a PDF
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        if ($mimeType !== 'application/pdf') {
            throw new \Exception('Cover page must be a PDF file');
        }

        // Check if name already exists
        $existingSql = "SELECT COUNT(*) as count FROM oce_sinch_cover_pages WHERE name = ?";
        $existingResult = QueryUtils::querySingleRow($existingSql, [$name]);
        if ($existingResult['count'] > 0) {
            throw new \Exception('A cover page with this name already exists');
        }

        // Create storage directory if it doesn't exist
        $storagePath = $this->getCoverPageStoragePath();
        if (!is_dir($storagePath)) {
            mkdir($storagePath, 0770, true);
        }

        // Generate unique filename
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $safeFilename = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($filename, PATHINFO_FILENAME));
        $uniqueFilename = $safeFilename . '_' . time() . '.' . $extension;
        $filePath = $storagePath . DIRECTORY_SEPARATOR . $uniqueFilename;

        // Move uploaded file to storage
        if (!move_uploaded_file($tmpPath, $filePath)) {
            throw new \Exception('Failed to save cover page file');
        }

        // Set secure permissions
        chmod($filePath, 0660);

        // Save to database
        $sql = "INSERT INTO oce_sinch_cover_pages (name, file_path, is_active) VALUES (?, ?, 1)";
        QueryUtils::sqlStatementThrowException($sql, [$name, $filePath]);

        $id = QueryUtils::getLastInsertId();

        $this->logger->info("Cover page uploaded: {$name} (ID: {$id})");

        return [
            'id' => $id,
            'name' => $name,
            'file_path' => $filePath,
        ];
    }

    /**
     * List all cover pages
     *
     * @param bool $activeOnly Only return active cover pages
     * @return array<int, array<string, mixed>>
     */
    public function listCoverPages(bool $activeOnly = false): array
    {
        $sql = "SELECT id, name, file_path, is_active, created_at, updated_at 
                FROM oce_sinch_cover_pages";

        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }

        $sql .= " ORDER BY name ASC";

        return QueryUtils::fetchRecords($sql);
    }

    /**
     * Get a cover page by ID
     *
     * @param int $id Cover page ID
     * @return array<string, mixed>|null
     */
    public function getCoverPage(int $id): ?array
    {
        $sql = "SELECT id, name, file_path, is_active, created_at, updated_at 
                FROM oce_sinch_cover_pages WHERE id = ?";
        $result = QueryUtils::querySingleRow($sql, [$id]);

        return $result ?: null;
    }

    /**
     * Delete a cover page
     *
     * @param int $id Cover page ID
     * @return bool
     * @throws \Exception
     */
    public function deleteCoverPage(int $id): bool
    {
        $coverPage = $this->getCoverPage($id);

        if (!$coverPage) {
            throw new \Exception('Cover page not found');
        }

        // Delete file from filesystem
        if (file_exists($coverPage['file_path'])) {
            unlink($coverPage['file_path']);
        }

        // Delete from database
        $sql = "DELETE FROM oce_sinch_cover_pages WHERE id = ?";
        QueryUtils::sqlStatementThrowException($sql, [$id]);

        $this->logger->info("Cover page deleted: {$coverPage['name']} (ID: {$id})");

        return true;
    }

    /**
     * Process cover page template with variable substitution
     *
     * @param int $coverPageId Cover page ID
     * @param array<string, mixed> $variables Variables for substitution
     * @return string Path to processed cover page
     * @throws \Exception
     */
    public function processCoverPage(int $coverPageId, array $variables = []): string
    {
        $coverPage = $this->getCoverPage($coverPageId);

        if (!$coverPage) {
            throw new \Exception('Cover page not found');
        }

        if (!file_exists($coverPage['file_path'])) {
            throw new \Exception('Cover page file not found');
        }

        // For now, return the original file path
        // TODO: Implement PDF variable substitution using a PDF library
        // This would involve:
        // 1. Reading the PDF template
        // 2. Finding and replacing template variables ({{from}}, {{to}}, etc.)
        // 3. Saving to a temporary processed file
        // 4. Returning the path to the processed file

        return $coverPage['file_path'];
    }

    /**
     * Get the storage path for cover pages
     *
     * @return string
     */
    private function getCoverPageStoragePath(): string
    {
        $basePath = $this->config->getFileStoragePath();
        return $basePath . DIRECTORY_SEPARATOR . 'cover_pages';
    }
}
