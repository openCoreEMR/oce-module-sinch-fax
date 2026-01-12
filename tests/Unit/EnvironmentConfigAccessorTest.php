<?php

/**
 * Unit tests for EnvironmentConfigAccessor
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Tests\Unit;

use OpenCoreEMR\Modules\SinchFax\EnvironmentConfigAccessor;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use PHPUnit\Framework\TestCase;

class EnvironmentConfigAccessorTest extends TestCase
{
    /**
     * @var array<string, string> Environment variables to clean up
     */
    private array $envVarsToClean = [
        'OCE_SINCH_FAX_ENABLED',
        'OCE_SINCH_FAX_PROJECT_ID',
        'OCE_SINCH_FAX_SERVICE_ID',
        'OCE_SINCH_FAX_AUTH_METHOD',
        'OCE_SINCH_FAX_API_KEY',
        'OCE_SINCH_FAX_API_SECRET',
        'OCE_SINCH_FAX_OAUTH_TOKEN',
        'OCE_SINCH_FAX_REGION',
        'OCE_SINCH_FAX_FILE_STORAGE_PATH',
        'OCE_SINCH_FAX_DEFAULT_RETRY_COUNT',
        'OCE_SINCH_FAX_WEBHOOK_USERNAME',
        'OCE_SINCH_FAX_WEBHOOK_PASSWORD',
        'OCE_SINCH_FAX_WEBHOOK_IP_ALLOWLIST',
        'OCE_SINCH_FAX_WEBHOOK_IP_WHITELIST', // Deprecated
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

    public function testGetStringFromEnvironment(): void
    {
        putenv('OCE_SINCH_FAX_PROJECT_ID=test-project-123');

        $accessor = new EnvironmentConfigAccessor();

        $this->assertEquals(
            'test-project-123',
            $accessor->getString(GlobalConfig::CONFIG_OPTION_PROJECT_ID)
        );
    }

    public function testGetBooleanTrueFromEnvironment(): void
    {
        putenv('OCE_SINCH_FAX_ENABLED=1');

        $accessor = new EnvironmentConfigAccessor();

        $this->assertTrue(
            $accessor->getBoolean(GlobalConfig::CONFIG_OPTION_ENABLED)
        );
    }

    public function testGetBooleanFalseFromEnvironment(): void
    {
        putenv('OCE_SINCH_FAX_ENABLED=0');

        $accessor = new EnvironmentConfigAccessor();

        $this->assertFalse(
            $accessor->getBoolean(GlobalConfig::CONFIG_OPTION_ENABLED)
        );
    }

    public function testGetBooleanTrueStringFromEnvironment(): void
    {
        putenv('OCE_SINCH_FAX_ENABLED=true');

        $accessor = new EnvironmentConfigAccessor();

        $this->assertTrue(
            $accessor->getBoolean(GlobalConfig::CONFIG_OPTION_ENABLED)
        );
    }

    public function testGetIntFromEnvironment(): void
    {
        putenv('OCE_SINCH_FAX_DEFAULT_RETRY_COUNT=5');

        $accessor = new EnvironmentConfigAccessor();

        $this->assertEquals(
            5,
            $accessor->getInt(GlobalConfig::CONFIG_OPTION_DEFAULT_RETRY_COUNT)
        );
    }

    public function testReturnsDefaultForUnsetVariable(): void
    {
        $accessor = new EnvironmentConfigAccessor();

        $this->assertEquals(
            'default-value',
            $accessor->getString(GlobalConfig::CONFIG_OPTION_PROJECT_ID, 'default-value')
        );
    }

    public function testReturnsDefaultBooleanForUnsetVariable(): void
    {
        $accessor = new EnvironmentConfigAccessor();

        $this->assertFalse(
            $accessor->getBoolean(GlobalConfig::CONFIG_OPTION_ENABLED, false)
        );
    }

    public function testReturnsDefaultIntForUnsetVariable(): void
    {
        $accessor = new EnvironmentConfigAccessor();

        $this->assertEquals(
            3,
            $accessor->getInt(GlobalConfig::CONFIG_OPTION_DEFAULT_RETRY_COUNT, 3)
        );
    }

    public function testHasReturnsTrueWhenSet(): void
    {
        putenv('OCE_SINCH_FAX_PROJECT_ID=test');

        $accessor = new EnvironmentConfigAccessor();

        $this->assertTrue(
            $accessor->has(GlobalConfig::CONFIG_OPTION_PROJECT_ID)
        );
    }

    public function testHasReturnsFalseWhenNotSet(): void
    {
        $accessor = new EnvironmentConfigAccessor();

        $this->assertFalse(
            $accessor->has(GlobalConfig::CONFIG_OPTION_PROJECT_ID)
        );
    }

    public function testGetMapsAllConfigKeys(): void
    {
        $envVars = [
            'OCE_SINCH_FAX_ENABLED' => '1',
            'OCE_SINCH_FAX_PROJECT_ID' => 'proj-123',
            'OCE_SINCH_FAX_SERVICE_ID' => 'svc-456',
            'OCE_SINCH_FAX_AUTH_METHOD' => 'basic',
            'OCE_SINCH_FAX_API_KEY' => 'key-789',
            'OCE_SINCH_FAX_API_SECRET' => 'secret',
            'OCE_SINCH_FAX_OAUTH_TOKEN' => 'token',
            'OCE_SINCH_FAX_REGION' => 'use1',
            'OCE_SINCH_FAX_FILE_STORAGE_PATH' => '/tmp/faxes',
            'OCE_SINCH_FAX_DEFAULT_RETRY_COUNT' => '5',
            'OCE_SINCH_FAX_WEBHOOK_USERNAME' => 'webhook_user',
            'OCE_SINCH_FAX_WEBHOOK_PASSWORD' => 'webhook_pass',
            'OCE_SINCH_FAX_WEBHOOK_IP_ALLOWLIST' => '10.0.0.1',
        ];

        foreach ($envVars as $key => $value) {
            putenv("$key=$value");
        }

        $accessor = new EnvironmentConfigAccessor();

        $this->assertTrue($accessor->getBoolean(GlobalConfig::CONFIG_OPTION_ENABLED));
        $this->assertEquals('proj-123', $accessor->getString(GlobalConfig::CONFIG_OPTION_PROJECT_ID));
        $this->assertEquals('svc-456', $accessor->getString(GlobalConfig::CONFIG_OPTION_SERVICE_ID));
        $this->assertEquals('basic', $accessor->getString(GlobalConfig::CONFIG_OPTION_AUTH_METHOD));
        $this->assertEquals('key-789', $accessor->getString(GlobalConfig::CONFIG_OPTION_API_KEY));
        $this->assertEquals('secret', $accessor->getString(GlobalConfig::CONFIG_OPTION_API_SECRET));
        $this->assertEquals('token', $accessor->getString(GlobalConfig::CONFIG_OPTION_OAUTH_TOKEN));
        $this->assertEquals('use1', $accessor->getString(GlobalConfig::CONFIG_OPTION_REGION));
        $this->assertEquals('/tmp/faxes', $accessor->getString(GlobalConfig::CONFIG_OPTION_FILE_STORAGE_PATH));
        $this->assertEquals(5, $accessor->getInt(GlobalConfig::CONFIG_OPTION_DEFAULT_RETRY_COUNT));
        $this->assertEquals('webhook_user', $accessor->getString(GlobalConfig::CONFIG_OPTION_WEBHOOK_USERNAME));
        $this->assertEquals('webhook_pass', $accessor->getString(GlobalConfig::CONFIG_OPTION_WEBHOOK_PASSWORD));
        $this->assertEquals('10.0.0.1', $accessor->getString(GlobalConfig::CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST));
    }

    public function testBackwardCompatibilityWithDeprecatedWhitelistEnvVar(): void
    {
        // Set the deprecated environment variable
        putenv('OCE_SINCH_FAX_WEBHOOK_IP_WHITELIST=192.168.1.0/24');

        $accessor = new EnvironmentConfigAccessor();

        // Should read from the deprecated env var
        $this->assertEquals(
            '192.168.1.0/24',
            $accessor->getString(GlobalConfig::CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST)
        );
    }

    public function testAllowlistEnvVarTakesPrecedenceOverDeprecated(): void
    {
        // Set both old and new environment variables
        putenv('OCE_SINCH_FAX_WEBHOOK_IP_WHITELIST=192.168.1.0/24');
        putenv('OCE_SINCH_FAX_WEBHOOK_IP_ALLOWLIST=10.0.0.0/8');

        $accessor = new EnvironmentConfigAccessor();

        // New env var should take precedence
        $this->assertEquals(
            '10.0.0.0/8',
            $accessor->getString(GlobalConfig::CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST)
        );
    }
}
