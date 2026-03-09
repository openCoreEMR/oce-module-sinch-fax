<?php

/**
 * Unit tests for FileConfigAccessor
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Tests\Unit;

use OpenCoreEMR\Modules\SinchFax\FileConfigAccessor;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use PHPUnit\Framework\TestCase;

class FileConfigAccessorTest extends TestCase
{
    /**
     * @var list<string> Environment variables to clean up
     */
    private array $envVarsToClean = [
        'OCE_SINCH_FAX_ENABLED',
        'OCE_SINCH_FAX_PROJECT_ID',
        'OCE_SINCH_FAX_SERVICE_ID',
        'OCE_SINCH_FAX_API_KEY',
        'OCE_SINCH_FAX_API_SECRET',
        'OCE_SINCH_FAX_REGION',
        'OCE_SINCH_FAX_FILE_STORAGE_PATH',
        'OCE_SINCH_FAX_DEFAULT_RETRY_COUNT',
        'OCE_SINCH_FAX_WEBHOOK_USERNAME',
        'OCE_SINCH_FAX_WEBHOOK_PASSWORD',
        'OCE_SINCH_FAX_WEBHOOK_IP_ALLOWLIST',
    ];

    protected function setUp(): void
    {
        $this->clearEnvVars();
    }

    protected function tearDown(): void
    {
        $this->clearEnvVars();
    }

    private function clearEnvVars(): void
    {
        foreach ($this->envVarsToClean as $var) {
            putenv($var);
        }
    }

    public function testGetStringFromYamlData(): void
    {
        $accessor = new FileConfigAccessor([
            'project_id' => 'yaml-project-123',
        ]);

        $this->assertSame(
            'yaml-project-123',
            $accessor->getString(GlobalConfig::CONFIG_OPTION_PROJECT_ID)
        );
    }

    public function testGetBooleanFromYamlData(): void
    {
        $accessor = new FileConfigAccessor([
            'enabled' => true,
        ]);

        $this->assertTrue(
            $accessor->getBoolean(GlobalConfig::CONFIG_OPTION_ENABLED)
        );
    }

    public function testGetIntFromYamlData(): void
    {
        $accessor = new FileConfigAccessor([
            'default_retry_count' => 7,
        ]);

        $this->assertSame(
            7,
            $accessor->getInt(GlobalConfig::CONFIG_OPTION_DEFAULT_RETRY_COUNT)
        );
    }

    public function testReturnsDefaultWhenKeyNotInYaml(): void
    {
        $accessor = new FileConfigAccessor([]);

        $this->assertSame(
            'fallback',
            $accessor->getString(GlobalConfig::CONFIG_OPTION_PROJECT_ID, 'fallback')
        );
    }

    public function testHasReturnsTrueForYamlKey(): void
    {
        $accessor = new FileConfigAccessor([
            'project_id' => 'abc',
        ]);

        $this->assertTrue($accessor->has(GlobalConfig::CONFIG_OPTION_PROJECT_ID));
    }

    public function testHasReturnsFalseForMissingKey(): void
    {
        $accessor = new FileConfigAccessor([]);

        $this->assertFalse($accessor->has(GlobalConfig::CONFIG_OPTION_PROJECT_ID));
    }

    public function testEnvVarOverridesYamlValue(): void
    {
        putenv('OCE_SINCH_FAX_PROJECT_ID=env-project-456');

        $accessor = new FileConfigAccessor([
            'project_id' => 'yaml-project-123',
        ]);

        $this->assertSame(
            'env-project-456',
            $accessor->getString(GlobalConfig::CONFIG_OPTION_PROJECT_ID)
        );
    }

    public function testEnvVarOverridesYamlBoolean(): void
    {
        putenv('OCE_SINCH_FAX_ENABLED=0');

        $accessor = new FileConfigAccessor([
            'enabled' => true,
        ]);

        $this->assertFalse(
            $accessor->getBoolean(GlobalConfig::CONFIG_OPTION_ENABLED)
        );
    }

    public function testYamlValueUsedWhenEnvVarNotSet(): void
    {
        // No env var set
        $accessor = new FileConfigAccessor([
            'region' => 'eu1',
        ]);

        $this->assertSame(
            'eu1',
            $accessor->getString(GlobalConfig::CONFIG_OPTION_REGION)
        );
    }

    public function testAllYamlKeysAreMapped(): void
    {
        $yamlData = [
            'enabled' => true,
            'project_id' => 'proj-123',
            'service_id' => 'svc-456',
            'api_key' => 'key-789',
            'api_secret' => 'secret',
            'region' => 'use1',
            'file_storage_path' => '/tmp/faxes',
            'default_retry_count' => 5,
            'webhook_username' => 'webhook_user',
            'webhook_password' => 'webhook_pass',
            'webhook_ip_allowlist' => '10.0.0.1',
        ];

        $accessor = new FileConfigAccessor($yamlData);

        $this->assertTrue($accessor->getBoolean(GlobalConfig::CONFIG_OPTION_ENABLED));
        $this->assertSame('proj-123', $accessor->getString(GlobalConfig::CONFIG_OPTION_PROJECT_ID));
        $this->assertSame('svc-456', $accessor->getString(GlobalConfig::CONFIG_OPTION_SERVICE_ID));
        $this->assertSame('key-789', $accessor->getString(GlobalConfig::CONFIG_OPTION_API_KEY));
        $this->assertSame('secret', $accessor->getString(GlobalConfig::CONFIG_OPTION_API_SECRET));
        $this->assertSame('use1', $accessor->getString(GlobalConfig::CONFIG_OPTION_REGION));
        $this->assertSame('/tmp/faxes', $accessor->getString(GlobalConfig::CONFIG_OPTION_FILE_STORAGE_PATH));
        $this->assertSame(5, $accessor->getInt(GlobalConfig::CONFIG_OPTION_DEFAULT_RETRY_COUNT));
        $this->assertSame('webhook_user', $accessor->getString(GlobalConfig::CONFIG_OPTION_WEBHOOK_USERNAME));
        $this->assertSame('webhook_pass', $accessor->getString(GlobalConfig::CONFIG_OPTION_WEBHOOK_PASSWORD));
        $this->assertSame('10.0.0.1', $accessor->getString(GlobalConfig::CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST));
    }

    public function testUnknownYamlKeysAreIgnored(): void
    {
        $accessor = new FileConfigAccessor([
            'project_id' => 'abc',
            'unknown_key' => 'should-be-ignored',
        ]);

        $this->assertSame('abc', $accessor->getString(GlobalConfig::CONFIG_OPTION_PROJECT_ID));
        // Unknown keys don't map to any config option and are silently ignored
    }

    public function testGetDelegatesToGlobalsForNonModuleKeys(): void
    {
        $accessor = new FileConfigAccessor([
            'project_id' => 'abc',
        ]);

        // OE_SITE_DIR is a system key that delegates to GlobalsAccessor
        // In test context (no globals), returns default
        $this->assertSame('', $accessor->getString('OE_SITE_DIR', ''));
    }
}
