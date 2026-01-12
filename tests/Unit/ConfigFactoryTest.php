<?php

/**
 * Unit tests for ConfigFactory
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Tests\Unit;

use OpenCoreEMR\Modules\SinchFax\ConfigFactory;
use OpenCoreEMR\Modules\SinchFax\EnvironmentConfigAccessor;
use OpenCoreEMR\Modules\SinchFax\GlobalsAccessor;
use PHPUnit\Framework\TestCase;

class ConfigFactoryTest extends TestCase
{
    protected function setUp(): void
    {
        putenv(ConfigFactory::ENV_CONFIG_VAR);
    }

    protected function tearDown(): void
    {
        putenv(ConfigFactory::ENV_CONFIG_VAR);
    }

    public function testIsEnvConfigModeReturnsFalseByDefault(): void
    {
        $this->assertFalse(ConfigFactory::isEnvConfigMode());
    }

    public function testIsEnvConfigModeReturnsTrueWhenSetTo1(): void
    {
        putenv(ConfigFactory::ENV_CONFIG_VAR . '=1');

        $this->assertTrue(ConfigFactory::isEnvConfigMode());
    }

    public function testIsEnvConfigModeReturnsTrueWhenSetToTrue(): void
    {
        putenv(ConfigFactory::ENV_CONFIG_VAR . '=true');

        $this->assertTrue(ConfigFactory::isEnvConfigMode());
    }

    public function testIsEnvConfigModeReturnsFalseWhenSetTo0(): void
    {
        putenv(ConfigFactory::ENV_CONFIG_VAR . '=0');

        $this->assertFalse(ConfigFactory::isEnvConfigMode());
    }

    public function testIsEnvConfigModeReturnsFalseWhenSetToFalse(): void
    {
        putenv(ConfigFactory::ENV_CONFIG_VAR . '=false');

        $this->assertFalse(ConfigFactory::isEnvConfigMode());
    }

    public function testCreateConfigAccessorReturnsGlobalsAccessorByDefault(): void
    {
        $accessor = ConfigFactory::createConfigAccessor();

        $this->assertInstanceOf(GlobalsAccessor::class, $accessor);
    }

    public function testCreateConfigAccessorReturnsEnvironmentAccessorWhenEnvSet(): void
    {
        putenv(ConfigFactory::ENV_CONFIG_VAR . '=1');

        $accessor = ConfigFactory::createConfigAccessor();

        $this->assertInstanceOf(EnvironmentConfigAccessor::class, $accessor);
    }

    public function testEnvConfigVarConstant(): void
    {
        $this->assertEquals('OCE_SINCH_FAX_ENV_CONFIG', ConfigFactory::ENV_CONFIG_VAR);
    }
}
