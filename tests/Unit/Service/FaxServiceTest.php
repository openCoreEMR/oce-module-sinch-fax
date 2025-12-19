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

    public function testGetReceivedFaxesCategoryIdReturnsExisting(): void
    {
        // Set up mock to return existing category
        QueryUtils::setMockResult(
            "SELECT id FROM categories WHERE name = ? AND parent = 1",
            ['Received Faxes'],
            [['id' => 42]]
        );

        // Use reflection to call private method
        $reflection = new \ReflectionClass($this->faxService);
        $method = $reflection->getMethod('getReceivedFaxesCategoryId');
        $method->setAccessible(true);

        $categoryId = $method->invoke($this->faxService);

        $this->assertEquals(42, $categoryId);
    }

    public function testGetReceivedFaxesCategoryIdCreatesNew(): void
    {
        // Set up mock to return nothing first, then return new category using queue
        QueryUtils::queueMockResult(
            "SELECT id FROM categories WHERE name = ? AND parent = 1",
            ['Received Faxes'],
            []  // First call returns nothing
        );

        QueryUtils::queueMockResult(
            "SELECT id FROM categories WHERE name = ? AND parent = 1",
            ['Received Faxes'],
            [['id' => 99]]  // Second call returns newly created category
        );

        // Use reflection to call private method
        $reflection = new \ReflectionClass($this->faxService);
        $method = $reflection->getMethod('getReceivedFaxesCategoryId');
        $method->setAccessible(true);

        $categoryId = $method->invoke($this->faxService);

        $this->assertEquals(99, $categoryId);

        // Verify INSERT was called
        $queries = QueryUtils::getQueries();
        $insertQuery = array_filter($queries, fn($q) => str_contains($q['sql'], 'INSERT INTO categories'));
        $this->assertNotEmpty($insertQuery);
    }

    public function testGetReceivedFaxesCategoryIdThrowsWhenCreationFails(): void
    {
        // Set up mock to return nothing both times (creation fails) using queue
        QueryUtils::queueMockResult(
            "SELECT id FROM categories WHERE name = ? AND parent = 1",
            ['Received Faxes'],
            []  // First call returns nothing
        );

        QueryUtils::queueMockResult(
            "SELECT id FROM categories WHERE name = ? AND parent = 1",
            ['Received Faxes'],
            []  // Second call also returns nothing (creation failed)
        );

        // Use reflection to call private method
        $reflection = new \ReflectionClass($this->faxService);
        $method = $reflection->getMethod('getReceivedFaxesCategoryId');
        $method->setAccessible(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Failed to create 'Received Faxes' category");

        $method->invoke($this->faxService);
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
