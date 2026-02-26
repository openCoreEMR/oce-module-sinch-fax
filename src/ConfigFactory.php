<?php

/**
 * Factory for creating configuration accessors
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax;

/**
 * Factory for creating the appropriate configuration accessor.
 *
 * Configuration sources in precedence order:
 *   1. YAML files (with env var overrides) — FileConfigAccessor
 *   2. Environment variables only — EnvironmentConfigAccessor
 *   3. Database globals — GlobalsAccessor
 */
class ConfigFactory
{
    public const ENV_CONFIG_VAR = 'OCE_SINCH_FAX_ENV_CONFIG';

    public const CONVENTIONAL_CONFIG_PATH = '/etc/oce/sinch-fax/config.yaml';
    public const CONVENTIONAL_SECRETS_PATH = '/etc/oce/sinch-fax/secrets.yaml';
    public const CONFIG_FILE_ENV_VAR = 'OCE_SINCH_FAX_CONFIG_FILE';
    public const SECRETS_FILE_ENV_VAR = 'OCE_SINCH_FAX_SECRETS_FILE';

    /**
     * Check if environment-only config mode is enabled
     */
    public static function isEnvConfigMode(): bool
    {
        $value = getenv(self::ENV_CONFIG_VAR);
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Check if YAML file config mode is active (any config files exist)
     */
    public static function isFileConfigMode(): bool
    {
        $loader = new YamlConfigLoader();
        return $loader->hasConfigFiles(self::getConfigFileCandidates());
    }

    /**
     * Check if any external config mode is active (file or env)
     */
    public static function isExternalConfigMode(): bool
    {
        return self::isFileConfigMode() || self::isEnvConfigMode();
    }

    /**
     * Create the appropriate config accessor based on environment
     *
     * Precedence: file config > env config > database
     */
    public static function createConfigAccessor(): ConfigAccessorInterface
    {
        if (self::isFileConfigMode()) {
            $loader = new YamlConfigLoader();
            $paths = $loader->resolveFilePaths(self::getConfigFileCandidates());
            $data = $loader->load($paths);
            return new FileConfigAccessor($data);
        }

        if (self::isEnvConfigMode()) {
            return new EnvironmentConfigAccessor();
        }

        return new GlobalsAccessor();
    }

    /**
     * Get candidate config file paths (overridden or conventional)
     *
     * @return list<string>
     */
    private static function getConfigFileCandidates(): array
    {
        $paths = [];

        $configFile = getenv(self::CONFIG_FILE_ENV_VAR);
        $paths[] = $configFile !== false ? $configFile : self::CONVENTIONAL_CONFIG_PATH;

        $secretsFile = getenv(self::SECRETS_FILE_ENV_VAR);
        $paths[] = $secretsFile !== false ? $secretsFile : self::CONVENTIONAL_SECRETS_PATH;

        return $paths;
    }
}
