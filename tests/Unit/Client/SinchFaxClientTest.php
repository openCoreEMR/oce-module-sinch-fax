<?php

/**
 * Unit tests for SinchFaxClient
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
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

}
