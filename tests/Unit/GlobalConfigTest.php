<?php

/**
 * Unit tests for GlobalConfig
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Tests\Unit;

use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenCoreEMR\Modules\SinchFax\Tests\Mocks\MockGlobalsAccessor;
use PHPUnit\Framework\TestCase;

class GlobalConfigTest extends TestCase
{
    private MockGlobalsAccessor $mockGlobals;
    private GlobalConfig $config;

    protected function setUp(): void
    {
        $this->mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_ENABLED => true,
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
            GlobalConfig::CONFIG_OPTION_SERVICE_ID => 'test-service-id',
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'basic',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-api-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-api-secret'),
            GlobalConfig::CONFIG_OPTION_OAUTH_TOKEN => base64_encode('test-oauth-token'),
            GlobalConfig::CONFIG_OPTION_REGION => 'use1',
            GlobalConfig::CONFIG_OPTION_FILE_STORAGE_PATH => '/tmp/faxes',
            GlobalConfig::CONFIG_OPTION_AUTO_RECEIVE => true,
            GlobalConfig::CONFIG_OPTION_DEFAULT_RETRY_COUNT => 5,
            GlobalConfig::CONFIG_OPTION_ENABLE_STATUS_POLLING => true,
            'site_addr_oath' => 'https://example.com',
            'webroot' => '/var/www',
            'assets_static_relative' => '/assets',
            'OE_SITE_DIR' => '/var/www/sites/default',
        ]);

        $this->config = new GlobalConfig($this->mockGlobals);
    }

    public function testIsEnabled(): void
    {
        $this->assertTrue($this->config->isEnabled());
    }

    public function testGetProjectId(): void
    {
        $this->assertEquals('test-project-id', $this->config->getProjectId());
    }

    public function testGetServiceId(): void
    {
        $this->assertEquals('test-service-id', $this->config->getServiceId());
    }

    public function testGetAuthMethod(): void
    {
        $this->assertEquals('basic', $this->config->getAuthMethod());
    }

    public function testGetApiKey(): void
    {
        $this->assertEquals('test-api-key', $this->config->getApiKey());
    }

    public function testGetApiSecret(): void
    {
        $this->assertEquals('test-api-secret', $this->config->getApiSecret());
    }

    public function testGetApiSecretEmpty(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_API_SECRET => '',
        ]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertEquals('', $config->getApiSecret());
    }

    public function testGetOAuthToken(): void
    {
        $this->assertEquals('test-oauth-token', $this->config->getOAuthToken());
    }

    public function testGetOAuthTokenEmpty(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_OAUTH_TOKEN => '',
        ]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertEquals('', $config->getOAuthToken());
    }

    public function testGetRegion(): void
    {
        $this->assertEquals('use1', $this->config->getRegion());
    }

    public function testGetRegionDefault(): void
    {
        $mockGlobals = new MockGlobalsAccessor([]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertEquals('global', $config->getRegion());
    }

    public function testGetFileStoragePath(): void
    {
        $this->assertEquals('/tmp/faxes', $this->config->getFileStoragePath());
    }

    public function testGetFileStoragePathDefault(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            'OE_SITE_DIR' => '/var/www/sites/default',
        ]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertEquals('/var/www/sites/default/documents/sinch_faxes', $config->getFileStoragePath());
    }

    public function testGetAutoReceive(): void
    {
        $this->assertTrue($this->config->getAutoReceive());
    }

    public function testGetDefaultRetryCount(): void
    {
        $this->assertEquals(5, $this->config->getDefaultRetryCount());
    }

    public function testGetDefaultRetryCountDefault(): void
    {
        $mockGlobals = new MockGlobalsAccessor([]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertEquals(3, $config->getDefaultRetryCount());
    }

    public function testIsStatusPollingEnabled(): void
    {
        $this->assertTrue($this->config->isStatusPollingEnabled());
    }

    public function testGetSiteAddrOath(): void
    {
        $this->assertEquals('https://example.com', $this->config->getSiteAddrOath());
    }

    public function testGetWebroot(): void
    {
        $this->assertEquals('/var/www', $this->config->getWebroot());
    }

    public function testGetAssetsStaticRelative(): void
    {
        $this->assertEquals('/assets', $this->config->getAssetsStaticRelative());
    }

    public function testIsConfiguredWithBasicAuth(): void
    {
        $this->assertTrue($this->config->isConfigured());
    }

    public function testIsConfiguredWithOAuth(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'oauth',
            GlobalConfig::CONFIG_OPTION_OAUTH_TOKEN => base64_encode('test-token'),
        ]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertTrue($config->isConfigured());
    }

    public function testIsConfiguredMissingProjectId(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'basic',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('secret'),
        ]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertFalse($config->isConfigured());
    }

    public function testIsConfiguredMissingBasicAuthCredentials(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'basic',
        ]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertFalse($config->isConfigured());
    }

    public function testIsConfiguredMissingOAuthToken(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'oauth',
        ]);
        $config = new GlobalConfig($mockGlobals);

        $this->assertFalse($config->isConfigured());
    }

    public function testGetGlobalSettingSectionConfiguration(): void
    {
        $configuration = $this->config->getGlobalSettingSectionConfiguration();

        $this->assertIsArray($configuration);
        $this->assertArrayHasKey(GlobalConfig::CONFIG_OPTION_ENABLED, $configuration);
        $this->assertArrayHasKey(GlobalConfig::CONFIG_OPTION_PROJECT_ID, $configuration);
        $this->assertArrayHasKey(GlobalConfig::CONFIG_OPTION_API_KEY, $configuration);

        $this->assertArrayHasKey('title', $configuration[GlobalConfig::CONFIG_OPTION_ENABLED]);
        $this->assertArrayHasKey('description', $configuration[GlobalConfig::CONFIG_OPTION_ENABLED]);
        $this->assertArrayHasKey('type', $configuration[GlobalConfig::CONFIG_OPTION_ENABLED]);
        $this->assertArrayHasKey('default', $configuration[GlobalConfig::CONFIG_OPTION_ENABLED]);
    }
}
