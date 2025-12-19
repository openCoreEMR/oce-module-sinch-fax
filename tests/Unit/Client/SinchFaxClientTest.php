<?php

/**
 * Unit tests for SinchFaxClient
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Tests\Unit\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpenCoreEMR\Modules\SinchFax\Client\SinchFaxClient;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenCoreEMR\Modules\SinchFax\Tests\Mocks\MockGlobalsAccessor;
use PHPUnit\Framework\TestCase;

class SinchFaxClientTest extends TestCase
{
    private GlobalConfig $config;
    private string $testFilePath;

    protected function setUp(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'basic',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_REGION => 'use1',
        ]);

        $this->config = new GlobalConfig($mockGlobals);

        // Create a test PDF file
        $this->testFilePath = sys_get_temp_dir() . '/test-fax.pdf';
        file_put_contents($this->testFilePath, '%PDF-1.4 test content');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testFilePath)) {
            unlink($this->testFilePath);
        }
    }

    public function testConstructorWithBasicAuth(): void
    {
        $client = new SinchFaxClient($this->config);
        $this->assertInstanceOf(SinchFaxClient::class, $client);
    }

    public function testConstructorWithOAuth(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'oauth',
            GlobalConfig::CONFIG_OPTION_OAUTH_TOKEN => base64_encode('test-token'),
            GlobalConfig::CONFIG_OPTION_REGION => 'global',
        ]);

        $config = new GlobalConfig($mockGlobals);
        $client = new SinchFaxClient($config);
        $this->assertInstanceOf(SinchFaxClient::class, $client);
    }

    public function testConstructorSetsGlobalBaseUrl(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'basic',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_REGION => 'global',
        ]);

        $config = new GlobalConfig($mockGlobals);
        $client = new SinchFaxClient($config);

        // Use reflection to verify baseUrl
        $reflection = new \ReflectionClass($client);
        $baseUrlProperty = $reflection->getProperty('baseUrl');
        $baseUrlProperty->setAccessible(true);

        $this->assertEquals('https://fax.api.sinch.com', $baseUrlProperty->getValue($client));
    }

    public function testConstructorSetsRegionalBaseUrl(): void
    {
        $client = new SinchFaxClient($this->config);

        // Use reflection to verify baseUrl
        $reflection = new \ReflectionClass($client);
        $baseUrlProperty = $reflection->getProperty('baseUrl');
        $baseUrlProperty->setAccessible(true);

        $this->assertEquals('https://use1.fax.api.sinch.com', $baseUrlProperty->getValue($client));
    }

    public function testGetAuthHeadersWithBasicAuth(): void
    {
        $client = new SinchFaxClient($this->config);

        // Use reflection to call private method
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('getAuthHeaders');
        $method->setAccessible(true);

        $headers = $method->invoke($client);

        $this->assertArrayHasKey('Accept', $headers);
        $this->assertEquals('application/json', $headers['Accept']);
        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertStringStartsWith('Basic ', $headers['Authorization']);

        // Verify the credentials are properly encoded
        $expectedCredentials = base64_encode('test-key:test-secret');
        $this->assertEquals("Basic {$expectedCredentials}", $headers['Authorization']);
    }

    public function testGetAuthHeadersWithOAuth(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'oauth',
            GlobalConfig::CONFIG_OPTION_OAUTH_TOKEN => base64_encode('my-oauth-token'),
            GlobalConfig::CONFIG_OPTION_REGION => 'global',
        ]);

        $config = new GlobalConfig($mockGlobals);
        $client = new SinchFaxClient($config);

        // Use reflection to call private method
        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('getAuthHeaders');
        $method->setAccessible(true);

        $headers = $method->invoke($client);

        $this->assertArrayHasKey('Accept', $headers);
        $this->assertEquals('application/json', $headers['Accept']);
        $this->assertArrayHasKey('Authorization', $headers);
        $this->assertEquals('Bearer my-oauth-token', $headers['Authorization']);
    }

    public function testConstructorSetsProjectId(): void
    {
        $client = new SinchFaxClient($this->config);

        // Use reflection to verify projectId
        $reflection = new \ReflectionClass($client);
        $projectIdProperty = $reflection->getProperty('projectId');
        $projectIdProperty->setAccessible(true);

        $this->assertEquals('test-project-id', $projectIdProperty->getValue($client));
    }

    public function testConstructorSetsAuthMethod(): void
    {
        $client = new SinchFaxClient($this->config);

        // Use reflection to verify authMethod
        $reflection = new \ReflectionClass($client);
        $authMethodProperty = $reflection->getProperty('authMethod');
        $authMethodProperty->setAccessible(true);

        $this->assertEquals('basic', $authMethodProperty->getValue($client));
    }

    public function testConstructorWithDifferentRegions(): void
    {
        $regions = ['use1', 'euc1', 'apse1', 'global'];
        $expectedUrls = [
            'use1' => 'https://use1.fax.api.sinch.com',
            'euc1' => 'https://euc1.fax.api.sinch.com',
            'apse1' => 'https://apse1.fax.api.sinch.com',
            'global' => 'https://fax.api.sinch.com',
        ];

        foreach ($regions as $region) {
            $mockGlobals = new MockGlobalsAccessor([
                GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
                GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'basic',
                GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
                GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
                GlobalConfig::CONFIG_OPTION_REGION => $region,
            ]);

            $config = new GlobalConfig($mockGlobals);
            $client = new SinchFaxClient($config);

            $reflection = new \ReflectionClass($client);
            $baseUrlProperty = $reflection->getProperty('baseUrl');
            $baseUrlProperty->setAccessible(true);

            $this->assertEquals(
                $expectedUrls[$region],
                $baseUrlProperty->getValue($client),
                "Failed for region: {$region}"
            );
        }
    }

    /**
     * Create a SinchFaxClient with a mocked HTTP client
     */
    private function createClientWithMockHandler(MockHandler $mockHandler): SinchFaxClient
    {
        $handlerStack = HandlerStack::create($mockHandler);
        $httpClient = new Client(['handler' => $handlerStack]);

        return new SinchFaxClient($this->config, $httpClient);
    }

    public function testSendFaxSuccess(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'id' => 'fax-123',
                'to' => '+15551234567',
                'status' => 'QUEUED',
                'numberOfPages' => 1,
            ])),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $result = $client->sendFax([
            'to' => '+15551234567',
            'files' => [['path' => $this->testFilePath]],
        ]);

        $this->assertEquals('fax-123', $result['id']);
        $this->assertEquals('QUEUED', $result['status']);
    }

    public function testSendFaxWithAllOptions(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'id' => 'fax-456',
                'to' => '+15551234567',
                'from' => '+15559876543',
                'status' => 'QUEUED',
            ])),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $result = $client->sendFax([
            'to' => '+15551234567',
            'from' => '+15559876543',
            'files' => [['path' => $this->testFilePath, 'filename' => 'custom.pdf']],
            'callbackUrl' => 'https://example.com/callback',
            'coverPageId' => 'cover-123',
            'maxRetries' => 3,
        ]);

        $this->assertEquals('fax-456', $result['id']);
    }

    public function testSendFaxWithContentUrl(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'id' => 'fax-789',
                'status' => 'QUEUED',
            ])),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $result = $client->sendFax([
            'to' => '+15551234567',
            'contentUrl' => 'https://example.com/document.pdf',
        ]);

        $this->assertEquals('fax-789', $result['id']);
    }

    public function testSendFaxThrowsOnApiError(): void
    {
        $mockHandler = new MockHandler([
            new RequestException(
                'Bad Request',
                new Request('POST', '/v3/projects/test-project-id/faxes'),
                new Response(400, [], json_encode(['error' => 'Invalid phone number']))
            ),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to send fax');

        $client->sendFax([
            'to' => 'invalid',
            'files' => [['path' => $this->testFilePath]],
        ]);
    }

    public function testSendFaxThrowsOnUnreadableFile(): void
    {
        $mockHandler = new MockHandler([]);
        $client = $this->createClientWithMockHandler($mockHandler);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to read file');

        $client->sendFax([
            'to' => '+15551234567',
            'files' => [['path' => '/nonexistent/file.pdf']],
        ]);
    }

    public function testGetFaxSuccess(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'id' => 'fax-123',
                'to' => '+15551234567',
                'status' => 'COMPLETED',
                'numberOfPages' => 2,
            ])),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $result = $client->getFax('fax-123');

        $this->assertEquals('fax-123', $result['id']);
        $this->assertEquals('COMPLETED', $result['status']);
        $this->assertEquals(2, $result['numberOfPages']);
    }

    public function testGetFaxThrowsOnNotFound(): void
    {
        $mockHandler = new MockHandler([
            new RequestException(
                'Not Found',
                new Request('GET', '/v3/projects/test-project-id/faxes/invalid-id'),
                new Response(404, [], json_encode(['error' => 'Fax not found']))
            ),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to get fax');

        $client->getFax('invalid-id');
    }

    public function testListFaxesSuccess(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'faxes' => [
                    ['id' => 'fax-1', 'status' => 'COMPLETED'],
                    ['id' => 'fax-2', 'status' => 'QUEUED'],
                ],
                'totalCount' => 2,
            ])),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $result = $client->listFaxes();

        $this->assertCount(2, $result['faxes']);
        $this->assertEquals(2, $result['totalCount']);
    }

    public function testListFaxesWithFilters(): void
    {
        $mockHandler = new MockHandler([
            new Response(200, [], json_encode([
                'faxes' => [['id' => 'fax-1', 'status' => 'COMPLETED']],
                'totalCount' => 1,
            ])),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $result = $client->listFaxes([
            'serviceId' => 'service-123',
            'direction' => 'OUTBOUND',
            'status' => 'COMPLETED',
            'to' => '+15551234567',
            'from' => '+15559876543',
            'createTime' => '2025-01-01',
            'page' => 1,
            'pageSize' => 10,
        ]);

        $this->assertCount(1, $result['faxes']);
    }

    public function testListFaxesThrowsOnError(): void
    {
        $mockHandler = new MockHandler([
            new RequestException(
                'Server Error',
                new Request('GET', '/v3/projects/test-project-id/faxes'),
                new Response(500, [], json_encode(['error' => 'Internal error']))
            ),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to list faxes');

        $client->listFaxes();
    }

    public function testDownloadFaxSuccess(): void
    {
        $pdfContent = '%PDF-1.4 downloaded fax content';
        $mockHandler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/pdf'], $pdfContent),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $result = $client->downloadFax('fax-123');

        $this->assertEquals($pdfContent, $result);
    }

    public function testDownloadFaxThrowsOnError(): void
    {
        $mockHandler = new MockHandler([
            new RequestException(
                'Not Found',
                new Request('GET', '/v3/projects/test-project-id/faxes/fax-123/file'),
                new Response(404, [], json_encode(['error' => 'File not found']))
            ),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to download fax');

        $client->downloadFax('fax-123');
    }

    public function testDeleteFaxSuccess(): void
    {
        $mockHandler = new MockHandler([
            new Response(204, []),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $result = $client->deleteFax('fax-123');

        $this->assertTrue($result);
    }

    public function testDeleteFaxThrowsOnError(): void
    {
        $mockHandler = new MockHandler([
            new RequestException(
                'Not Found',
                new Request('DELETE', '/v3/projects/test-project-id/faxes/fax-123'),
                new Response(404, [], json_encode(['error' => 'Fax not found']))
            ),
        ]);

        $client = $this->createClientWithMockHandler($mockHandler);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to delete fax');

        $client->deleteFax('fax-123');
    }

    public function testSendFaxWithDifferentFileTypes(): void
    {
        // Create test files with different extensions
        $tiffFile = sys_get_temp_dir() . '/test-fax.tiff';
        $pngFile = sys_get_temp_dir() . '/test-fax.png';
        $jpgFile = sys_get_temp_dir() . '/test-fax.jpg';
        $docFile = sys_get_temp_dir() . '/test-fax.doc';
        $docxFile = sys_get_temp_dir() . '/test-fax.docx';

        file_put_contents($tiffFile, 'tiff content');
        file_put_contents($pngFile, 'png content');
        file_put_contents($jpgFile, 'jpg content');
        file_put_contents($docFile, 'doc content');
        file_put_contents($docxFile, 'docx content');

        try {
            $mockHandler = new MockHandler([
                new Response(200, [], json_encode(['id' => 'fax-multi', 'status' => 'QUEUED'])),
            ]);

            $client = $this->createClientWithMockHandler($mockHandler);

            $result = $client->sendFax([
                'to' => '+15551234567',
                'files' => [
                    ['path' => $tiffFile],
                    ['path' => $pngFile],
                    ['path' => $jpgFile],
                    ['path' => $docFile],
                    ['path' => $docxFile],
                ],
            ]);

            $this->assertEquals('fax-multi', $result['id']);
        } finally {
            // Clean up
            @unlink($tiffFile);
            @unlink($pngFile);
            @unlink($jpgFile);
            @unlink($docFile);
            @unlink($docxFile);
        }
    }
}
