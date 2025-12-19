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
use OpenCoreEMR\Modules\SinchFax\Exception\FaxNotFoundException;
use OpenCoreEMR\Modules\SinchFax\Exception\FaxUnauthorizedException;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenCoreEMR\Modules\SinchFax\Tests\Mocks\MockGlobalsAccessor;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\TestCase;

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
}
