<?php

/**
 * Fax Download Controller - handles viewing and downloading fax files
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchFax\Controller;

use OpenCoreEMR\Modules\SinchFax\Exception\FaxAccessDeniedException;
use OpenCoreEMR\Modules\SinchFax\Exception\FaxConfigurationException;
use OpenCoreEMR\Modules\SinchFax\Exception\FaxNotFoundException;
use OpenCoreEMR\Modules\SinchFax\Exception\FaxUnauthorizedException;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class FaxDownloadController
{
    private readonly SystemLogger $logger;

    public function __construct(private readonly GlobalConfig $config)
    {
        $this->logger = new SystemLogger();
    }

    /**
     * Download a fax file
     */
    public function download(int $faxId): BinaryFileResponse
    {
        // Verify user is authenticated
        if (empty($_SESSION['authUserID'])) {
            throw new FaxUnauthorizedException("Unauthorized");
        }

        // Get fax record from database
        $sql = "SELECT * FROM oce_sinch_faxes WHERE id = ?";
        $fax = QueryUtils::querySingleRow($sql, [$faxId]);

        if (!is_array($fax)) {
            throw new FaxNotFoundException("Fax not found");
        }

        /** @var array<string, mixed> $fax */
        $filePath = is_string($fax['file_path'] ?? null) ? $fax['file_path'] : null;

        if (in_array($filePath, [null, '', '0'], true)) {
            throw new FaxNotFoundException("Fax file not available");
        }

        // Security check: ensure file is within the allowed storage directory
        $storagePath = $this->config->getFileStoragePath();
        $realFilePath = realpath($filePath);
        $realStoragePath = realpath($storagePath);

        if ($realFilePath === false) {
            $this->logger->error("Fax file not found: {$filePath}");
            throw new FaxNotFoundException("File not found");
        }

        if ($realStoragePath === false) {
            $this->logger->error("Storage path not found: {$storagePath}");
            throw new FaxConfigurationException("Configuration error");
        }

        if (!str_starts_with($realFilePath, $realStoragePath)) {
            $this->logger->error("Attempted path traversal attack: {$filePath}");
            throw new FaxAccessDeniedException("Access denied");
        }

        // Check if file exists
        if (!file_exists($realFilePath)) {
            $this->logger->error("Fax file does not exist: {$realFilePath}");
            throw new FaxNotFoundException("File not found");
        }

        // Log the download - authUserID exists per check at line 40
        $authUserId = $_SESSION['authUserID'];
        $authUserIdStr = is_scalar($authUserId) ? (string)$authUserId : 'unknown';
        $this->logger->info("User {$authUserIdStr} downloading fax {$faxId}");

        // Mark fax as read when viewed
        $updateSql = <<<'SQL'
            UPDATE oce_sinch_faxes
            SET read_status = 'read', updated_at = NOW()
            WHERE id = ? AND read_status = 'unread'
            SQL;
        QueryUtils::sqlStatementThrowException($updateSql, [$faxId]);

        // Create binary file response
        $response = new BinaryFileResponse($realFilePath);

        // Set content disposition to inline (view in browser)
        $sinchFaxId = is_scalar($fax['sinch_fax_id'] ?? null) ? (string) $fax['sinch_fax_id'] : '';
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            'fax_' . $sinchFaxId . '.pdf'
        );

        // Set MIME type
        $mimeType = is_string($fax['mime_type'] ?? null) ? $fax['mime_type'] : 'application/pdf';
        $response->headers->set('Content-Type', $mimeType);

        return $response;
    }

    /**
     * Check if a fax has a downloadable file
     */
    public function hasFile(int $faxId): bool
    {
        $sql = "SELECT file_path FROM oce_sinch_faxes WHERE id = ?";
        $result = QueryUtils::querySingleRow($sql, [$faxId]);

        if (!is_array($result) || !is_string($result['file_path'] ?? null) || $result['file_path'] === '') {
            return false;
        }

        return file_exists($result['file_path']);
    }
}
