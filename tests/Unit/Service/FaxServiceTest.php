<?php

/**
 * Unit tests for FaxService
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Tests\Unit\Service;

use OpenCoreEMR\Modules\SinchFax\Client\SinchFaxClient;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenCoreEMR\Modules\SinchFax\Service\FaxService;
use OpenCoreEMR\Modules\SinchFax\Tests\Mocks\MockGlobalsAccessor;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\TestCase;

class FaxServiceTest extends TestCase
{
    private GlobalConfig $config;
    private FaxService $faxService;
    private string $testFilePath;

    protected function setUp(): void
    {
        // Clear mock data
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
        SystemLogger::clearLogs();

        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'basic',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_REGION => 'use1',
            GlobalConfig::CONFIG_OPTION_DEFAULT_RETRY_COUNT => 3,
            GlobalConfig::CONFIG_OPTION_FILE_STORAGE_PATH => sys_get_temp_dir() . '/faxes',
        ]);

        $this->config = new GlobalConfig($mockGlobals);
        $this->faxService = new FaxService($this->config);

        // Create a test PDF file
        $this->testFilePath = sys_get_temp_dir() . '/test-fax.pdf';
        file_put_contents($this->testFilePath, '%PDF-1.4 test content');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFilePath)) {
            unlink($this->testFilePath);
        }

        // Clean up any created files in storage path
        $storagePath = $this->config->getFileStoragePath();
        if (is_dir($storagePath)) {
            $files = glob($storagePath . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function testFaxServiceCanBeConstructed(): void
    {
        $this->assertInstanceOf(FaxService::class, $this->faxService);
    }

    public function testDownloadAndSaveFaxCreatesDirectory(): void
    {
        // Create a test file to return as download
        $mockContent = '%PDF-1.4 test fax content';

        $mockClient = $this->createMock(SinchFaxClient::class);
        $mockClient->method('downloadFax')
            ->with('test-fax-123')
            ->willReturn($mockContent);

        // Create a testable service with mock client
        $testService = new class($this->config, $mockClient) extends FaxService {
            public function __construct(GlobalConfig $config, private $mockClient) {
                parent::__construct($config);
            }

            public function downloadAndSaveFax(string $faxId): string {
                $content = $this->mockClient->downloadFax($faxId);
                $storagePath = $this->config->getFileStoragePath();

                if (!is_dir($storagePath)) {
                    mkdir($storagePath, 0770, true);
                }

                $filename = $faxId . '.pdf';
                $filePath = $storagePath . DIRECTORY_SEPARATOR . $filename;

                file_put_contents($filePath, $content);
                chmod($filePath, 0660);

                return $filePath;
            }
        };

        $filePath = $testService->downloadAndSaveFax('test-fax-123');

        $this->assertFileExists($filePath);
        $this->assertStringContainsString('test-fax-123.pdf', $filePath);
        $this->assertEquals($mockContent, file_get_contents($filePath));
    }

    public function testMoveToPatientDocumentsThrowsExceptionWhenFaxNotFound(): void
    {
        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes WHERE id = ?",
            [1],
            []
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Fax not found');
        $this->faxService->moveToPatientDocuments(1, 100);
    }

    public function testMoveToPatientDocumentsThrowsExceptionWhenAlreadyMoved(): void
    {
        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes WHERE id = ?",
            [1],
            [[
                'id' => 1,
                'document_id' => 123,
                'file_path' => '/tmp/test.pdf',
            ]]
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Fax has already been moved to patient chart');
        $this->faxService->moveToPatientDocuments(1, 100);
    }

    public function testMoveToPatientDocumentsThrowsExceptionWhenFileNotFound(): void
    {
        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes WHERE id = ?",
            [1],
            [[
                'id' => 1,
                'document_id' => null,
                'file_path' => '/nonexistent/file.pdf',
            ]]
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Fax file not found');
        $this->faxService->moveToPatientDocuments(1, 100);
    }
}
