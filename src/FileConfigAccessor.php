<?php

/**
 * File-based configuration accessor (YAML config files)
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax;

use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Read module configuration from YAML files, with env var overrides.
 *
 * Precedence: environment variables > YAML files > defaults.
 * OpenEMR system values (OE_SITE_DIR, webroot, etc.) delegate to GlobalsAccessor.
 *
 * @internal Use ConfigFactory::createConfigAccessor() instead of instantiating directly
 */
class FileConfigAccessor implements ConfigAccessorInterface
{
    /**
     * Map short YAML keys to internal config keys (oce_sinch_fax_*)
     *
     * @var array<string, string>
     */
    private const KEY_MAP = [
        'enabled' => GlobalConfig::CONFIG_OPTION_ENABLED,
        'project_id' => GlobalConfig::CONFIG_OPTION_PROJECT_ID,
        'service_id' => GlobalConfig::CONFIG_OPTION_SERVICE_ID,
        'api_key' => GlobalConfig::CONFIG_OPTION_API_KEY,
        'api_secret' => GlobalConfig::CONFIG_OPTION_API_SECRET,
        'region' => GlobalConfig::CONFIG_OPTION_REGION,
        'file_storage_path' => GlobalConfig::CONFIG_OPTION_FILE_STORAGE_PATH,
        'default_retry_count' => GlobalConfig::CONFIG_OPTION_DEFAULT_RETRY_COUNT,
        'webhook_username' => GlobalConfig::CONFIG_OPTION_WEBHOOK_USERNAME,
        'webhook_password' => GlobalConfig::CONFIG_OPTION_WEBHOOK_PASSWORD,
        'webhook_ip_allowlist' => GlobalConfig::CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST,
    ];

    /**
     * Map internal config keys to environment variable names for override support.
     * Same mapping as EnvironmentConfigAccessor::KEY_MAP.
     *
     * @var array<string, string>
     */
    private const ENV_OVERRIDE_MAP = [
        GlobalConfig::CONFIG_OPTION_ENABLED => 'OCE_SINCH_FAX_ENABLED',
        GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'OCE_SINCH_FAX_PROJECT_ID',
        GlobalConfig::CONFIG_OPTION_SERVICE_ID => 'OCE_SINCH_FAX_SERVICE_ID',
        GlobalConfig::CONFIG_OPTION_API_KEY => 'OCE_SINCH_FAX_API_KEY',
        GlobalConfig::CONFIG_OPTION_API_SECRET => 'OCE_SINCH_FAX_API_SECRET',
        GlobalConfig::CONFIG_OPTION_REGION => 'OCE_SINCH_FAX_REGION',
        GlobalConfig::CONFIG_OPTION_FILE_STORAGE_PATH => 'OCE_SINCH_FAX_FILE_STORAGE_PATH',
        GlobalConfig::CONFIG_OPTION_DEFAULT_RETRY_COUNT => 'OCE_SINCH_FAX_DEFAULT_RETRY_COUNT',
        GlobalConfig::CONFIG_OPTION_WEBHOOK_USERNAME => 'OCE_SINCH_FAX_WEBHOOK_USERNAME',
        GlobalConfig::CONFIG_OPTION_WEBHOOK_PASSWORD => 'OCE_SINCH_FAX_WEBHOOK_PASSWORD',
        GlobalConfig::CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST => 'OCE_SINCH_FAX_WEBHOOK_IP_ALLOWLIST',
    ];

    /**
     * Reverse map: internal config key => short YAML key
     *
     * @var array<string, string>
     */
    private const REVERSE_KEY_MAP = [
        GlobalConfig::CONFIG_OPTION_ENABLED => 'enabled',
        GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'project_id',
        GlobalConfig::CONFIG_OPTION_SERVICE_ID => 'service_id',
        GlobalConfig::CONFIG_OPTION_API_KEY => 'api_key',
        GlobalConfig::CONFIG_OPTION_API_SECRET => 'api_secret',
        GlobalConfig::CONFIG_OPTION_REGION => 'region',
        GlobalConfig::CONFIG_OPTION_FILE_STORAGE_PATH => 'file_storage_path',
        GlobalConfig::CONFIG_OPTION_DEFAULT_RETRY_COUNT => 'default_retry_count',
        GlobalConfig::CONFIG_OPTION_WEBHOOK_USERNAME => 'webhook_username',
        GlobalConfig::CONFIG_OPTION_WEBHOOK_PASSWORD => 'webhook_password',
        GlobalConfig::CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST => 'webhook_ip_allowlist',
    ];

    /** @var ParameterBag<string, mixed> */
    private readonly ParameterBag $bag;
    private readonly GlobalsAccessor $globalsAccessor;

    /**
     * @param array<string, mixed> $yamlData merged data from YamlConfigLoader::load()
     */
    public function __construct(array $yamlData)
    {
        $this->globalsAccessor = new GlobalsAccessor();
        $this->bag = $this->buildBag($yamlData);
    }

    /**
     * Build a ParameterBag from YAML data with env var overrides
     *
     * Start with YAML values (mapped to internal keys), then override with
     * any set environment variables.
     *
     * @param array<string, mixed> $yamlData
     * @return ParameterBag<string, mixed>
     */
    private function buildBag(array $yamlData): ParameterBag
    {
        $params = [];

        // Map short YAML keys to internal config keys
        foreach (self::KEY_MAP as $yamlKey => $configKey) {
            if (array_key_exists($yamlKey, $yamlData)) {
                $params[$configKey] = $yamlData[$yamlKey];
            }
        }

        // Override with environment variables where set
        foreach (self::ENV_OVERRIDE_MAP as $configKey => $envVar) {
            $value = getenv($envVar);
            if ($value !== false) {
                $params[$configKey] = $value;
            }
        }

        return new ParameterBag($params);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if (isset(self::REVERSE_KEY_MAP[$key])) {
            return $this->bag->get($key, $default);
        }

        return $this->globalsAccessor->get($key, $default);
    }

    public function getString(string $key, string $default = ''): string
    {
        if (isset(self::REVERSE_KEY_MAP[$key])) {
            return $this->bag->getString($key, $default);
        }

        return $this->globalsAccessor->getString($key, $default);
    }

    public function getBoolean(string $key, bool $default = false): bool
    {
        if (isset(self::REVERSE_KEY_MAP[$key])) {
            return $this->bag->getBoolean($key, $default);
        }

        return $this->globalsAccessor->getBoolean($key, $default);
    }

    public function getInt(string $key, int $default = 0): int
    {
        if (isset(self::REVERSE_KEY_MAP[$key])) {
            return $this->bag->getInt($key, $default);
        }

        return $this->globalsAccessor->getInt($key, $default);
    }

    public function has(string $key): bool
    {
        if (isset(self::REVERSE_KEY_MAP[$key])) {
            return $this->bag->has($key);
        }

        return $this->globalsAccessor->has($key);
    }
}
