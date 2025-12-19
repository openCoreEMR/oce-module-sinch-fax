<?php

/**
 * Unit tests for FaxDownloadController
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Tests\Unit\Controller;

use OpenCoreEMR\Modules\SinchFax\Controller\FaxDownloadController;
use OpenCoreEMR\Modules\SinchFax\Exception\FaxAccessDeniedException;
use OpenCoreEMR\Modules\SinchFax\Exception\FaxConfigurationException;
use OpenCoreEMR\Modules\SinchFax\Exception\FaxNotFoundException;
use OpenCoreEMR\Modules\SinchFax\Exception\FaxUnauthorizedException;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenCoreEMR\Modules\SinchFax\Tests\Mocks\MockGlobalsAccessor;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FaxDownloadControllerTest extends TestCase
{
    private GlobalConfig $config;
    private FaxDownloadController $controller;
    private string $testFilePath;
    private string $storagePath;

    protected function setUp(): void
    {
        // Clear mock data
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
        SystemLogger::clearLogs();

        // Initialize session
        $_SESSION = [];

        // Create a temp storage path
        $this->storagePath = sys_get_temp_dir() . '/fax_test_' . uniqid();
        mkdir($this->storagePath, 0770, true);

        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'basic',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_FILE_STORAGE_PATH => $this->storagePath,
        ]);

        $this->config = new GlobalConfig($mockGlobals);
        $this->controller = new FaxDownloadController($this->config);

        // Create a test file
        $this->testFilePath = $this->storagePath . '/test-fax.pdf';
        file_put_contents($this->testFilePath, '%PDF-1.4 test content');
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (file_exists($this->testFilePath)) {
            unlink($this->testFilePath);
        }
        if (is_dir($this->storagePath)) {
            rmdir($this->storagePath);
        }
        unset($_SESSION);
    }

    public function testControllerCanBeConstructed(): void
    {
        $this->assertInstanceOf(FaxDownloadController::class, $this->controller);
    }

    public function testDownloadThrowsUnauthorizedWhenNotLoggedIn(): void
    {
        // No session user ID set
        $_SESSION = [];

        $this->expectException(FaxUnauthorizedException::class);
        $this->expectExceptionMessage('Unauthorized');

        $this->controller->download(1);
    }

    public function testDownloadThrowsNotFoundWhenFaxDoesNotExist(): void
    {
        $_SESSION['authUserID'] = 1;

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes WHERE id = ?",
            [999],
            []
        );

        $this->expectException(FaxNotFoundException::class);
        $this->expectExceptionMessage('Fax not found');

        $this->controller->download(999);
    }

    public function testDownloadThrowsNotFoundWhenFilePathEmpty(): void
    {
        $_SESSION['authUserID'] = 1;

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes WHERE id = ?",
            [1],
            [[
                'id' => 1,
                'sinch_fax_id' => 'fax-123',
                'file_path' => '',
                'mime_type' => 'application/pdf',
            ]]
        );

        $this->expectException(FaxNotFoundException::class);
        $this->expectExceptionMessage('Fax file not available');

        $this->controller->download(1);
    }

    public function testDownloadThrowsNotFoundWhenFileDoesNotExist(): void
    {
        $_SESSION['authUserID'] = 1;

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes WHERE id = ?",
            [1],
            [[
                'id' => 1,
                'sinch_fax_id' => 'fax-123',
                'file_path' => '/nonexistent/path/file.pdf',
                'mime_type' => 'application/pdf',
            ]]
        );

        $this->expectException(FaxNotFoundException::class);
        $this->expectExceptionMessage('File not found');

        $this->controller->download(1);
    }

    public function testDownloadThrowsAccessDeniedOnPathTraversal(): void
    {
        $_SESSION['authUserID'] = 1;

        // Create a file outside the storage path
        $outsidePath = sys_get_temp_dir() . '/outside_storage_' . uniqid() . '.pdf';
        file_put_contents($outsidePath, '%PDF-1.4 malicious content');

        try {
            QueryUtils::setMockResult(
                "SELECT * FROM oce_sinch_faxes WHERE id = ?",
                [1],
                [[
                    'id' => 1,
                    'sinch_fax_id' => 'fax-123',
                    'file_path' => $outsidePath,
                    'mime_type' => 'application/pdf',
                ]]
            );

            $this->expectException(FaxAccessDeniedException::class);
            $this->expectExceptionMessage('Access denied');

            $this->controller->download(1);
        } finally {
            // Clean up
            if (file_exists($outsidePath)) {
                unlink($outsidePath);
            }
        }
    }

    public function testDownloadThrowsConfigurationExceptionWhenStoragePathInvalid(): void
    {
        $_SESSION['authUserID'] = 1;

        // Create controller with invalid storage path
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'basic',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_FILE_STORAGE_PATH => '/nonexistent/storage/path',
        ]);

        $config = new GlobalConfig($mockGlobals);
        $controller = new FaxDownloadController($config);

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes WHERE id = ?",
            [1],
            [[
                'id' => 1,
                'sinch_fax_id' => 'fax-123',
                'file_path' => $this->testFilePath, // Valid file but storage path is bad
                'mime_type' => 'application/pdf',
            ]]
        );

        $this->expectException(FaxConfigurationException::class);
        $this->expectExceptionMessage('Configuration error');

        $controller->download(1);
    }

    public function testDownloadSuccessfullyReturnsFile(): void
    {
        $_SESSION['authUserID'] = 1;

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes WHERE id = ?",
            [1],
            [[
                'id' => 1,
                'sinch_fax_id' => 'fax-123',
                'file_path' => $this->testFilePath,
                'mime_type' => 'application/pdf',
            ]]
        );

        $response = $this->controller->download(1);

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));

        // Check that the disposition header contains the filename
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertIsString($disposition);
        $this->assertStringContainsString('fax_fax-123.pdf', $disposition);
        $this->assertStringContainsString('inline', $disposition);
    }

    public function testDownloadSuccessfullyWithDefaultMimeType(): void
    {
        $_SESSION['authUserID'] = 1;

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes WHERE id = ?",
            [1],
            [[
                'id' => 1,
                'sinch_fax_id' => 'fax-456',
                'file_path' => $this->testFilePath,
                'mime_type' => null, // No mime type - should default to application/pdf
            ]]
        );

        $response = $this->controller->download(1);

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
    }

    public function testDownloadLogsUserAccess(): void
    {
        $_SESSION['authUserID'] = 42;

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes WHERE id = ?",
            [1],
            [[
                'id' => 1,
                'sinch_fax_id' => 'fax-123',
                'file_path' => $this->testFilePath,
                'mime_type' => 'application/pdf',
            ]]
        );

        $this->controller->download(1);

        // Check that download was logged
        $logs = SystemLogger::getLogs();
        $this->assertNotEmpty($logs);

        $foundLog = false;
        foreach ($logs as $log) {
            if (
                $log['level'] === 'info' &&
                str_contains($log['message'], 'User 42') &&
                str_contains($log['message'], 'downloading fax 1')
            ) {
                $foundLog = true;
                break;
            }
        }
        $this->assertTrue($foundLog, 'Expected log entry for user download not found');
    }

    public function testHasFileReturnsFalseWhenFaxNotFound(): void
    {
        QueryUtils::setMockResult(
            "SELECT file_path FROM oce_sinch_faxes WHERE id = ?",
            [999],
            []
        );

        $result = $this->controller->hasFile(999);

        $this->assertFalse($result);
    }

    public function testHasFileReturnsFalseWhenFilePathEmpty(): void
    {
        QueryUtils::setMockResult(
            "SELECT file_path FROM oce_sinch_faxes WHERE id = ?",
            [1],
            [['file_path' => '']]
        );

        $result = $this->controller->hasFile(1);

        $this->assertFalse($result);
    }

    public function testHasFileReturnsFalseWhenFileDoesNotExist(): void
    {
        QueryUtils::setMockResult(
            "SELECT file_path FROM oce_sinch_faxes WHERE id = ?",
            [1],
            [['file_path' => '/nonexistent/file.pdf']]
        );

        $result = $this->controller->hasFile(1);

        $this->assertFalse($result);
    }

    public function testHasFileReturnsTrueWhenFileExists(): void
    {
        QueryUtils::setMockResult(
            "SELECT file_path FROM oce_sinch_faxes WHERE id = ?",
            [1],
            [['file_path' => $this->testFilePath]]
        );

        $result = $this->controller->hasFile(1);

        $this->assertTrue($result);
    }

    public function testHasFileReturnsFalseWhenFilePathNull(): void
    {
        QueryUtils::setMockResult(
            "SELECT file_path FROM oce_sinch_faxes WHERE id = ?",
            [1],
            [['file_path' => null]]
        );

        $result = $this->controller->hasFile(1);

        $this->assertFalse($result);
    }
}
