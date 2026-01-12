<?php

/**
 * Environment-based configuration accessor
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
 * Reads module configuration from environment variables.
 *
 * This accessor is used when OCE_SINCH_FAX_ENV_CONFIG=1 is set,
 * bypassing the database-backed globals system entirely for module config.
 * OpenEMR system values (OE_SITE_DIR, webroot, etc.) are still delegated
 * to GlobalsAccessor since they are not module configuration.
 */
class EnvironmentConfigAccessor implements ConfigAccessorInterface
{
    /**
     * Maps internal config keys (oce_sinch_fax_*) to env var names (OCE_SINCH_FAX_*)
     */
    private const KEY_MAP = [
        GlobalConfig::CONFIG_OPTION_ENABLED => 'OCE_SINCH_FAX_ENABLED',
        GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'OCE_SINCH_FAX_PROJECT_ID',
        GlobalConfig::CONFIG_OPTION_SERVICE_ID => 'OCE_SINCH_FAX_SERVICE_ID',
        GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'OCE_SINCH_FAX_AUTH_METHOD',
        GlobalConfig::CONFIG_OPTION_API_KEY => 'OCE_SINCH_FAX_API_KEY',
        GlobalConfig::CONFIG_OPTION_API_SECRET => 'OCE_SINCH_FAX_API_SECRET',
        GlobalConfig::CONFIG_OPTION_OAUTH_TOKEN => 'OCE_SINCH_FAX_OAUTH_TOKEN',
        GlobalConfig::CONFIG_OPTION_REGION => 'OCE_SINCH_FAX_REGION',
        GlobalConfig::CONFIG_OPTION_FILE_STORAGE_PATH => 'OCE_SINCH_FAX_FILE_STORAGE_PATH',
        GlobalConfig::CONFIG_OPTION_DEFAULT_RETRY_COUNT => 'OCE_SINCH_FAX_DEFAULT_RETRY_COUNT',
        GlobalConfig::CONFIG_OPTION_WEBHOOK_USERNAME => 'OCE_SINCH_FAX_WEBHOOK_USERNAME',
        GlobalConfig::CONFIG_OPTION_WEBHOOK_PASSWORD => 'OCE_SINCH_FAX_WEBHOOK_PASSWORD',
        GlobalConfig::CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST => 'OCE_SINCH_FAX_WEBHOOK_IP_ALLOWLIST',
    ];

    private readonly ParameterBag $envBag;
    private readonly GlobalsAccessor $globalsAccessor;

    public function __construct()
    {
        $this->globalsAccessor = new GlobalsAccessor();
        $this->envBag = $this->buildEnvBag();
    }

    /**
     * Build a ParameterBag from environment variables
     *
     * @return ParameterBag<string, mixed>
     */
    private function buildEnvBag(): ParameterBag
    {
        $params = [];
        foreach (self::KEY_MAP as $configKey => $envVar) {
            $value = getenv($envVar);
            if ($value !== false) {
                $params[$configKey] = $value;
            }
        }
        return new ParameterBag($params);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // Check if this is a module config key
        if (isset(self::KEY_MAP[$key])) {
            return $this->envBag->get($key, $default);
        }

        // For OpenEMR system values, delegate to GlobalsAccessor
        return $this->globalsAccessor->get($key, $default);
    }

    public function getString(string $key, string $default = ''): string
    {
        if (isset(self::KEY_MAP[$key])) {
            return $this->envBag->getString($key, $default);
        }

        return $this->globalsAccessor->getString($key, $default);
    }

    public function getBoolean(string $key, bool $default = false): bool
    {
        if (isset(self::KEY_MAP[$key])) {
            return $this->envBag->getBoolean($key, $default);
        }

        return $this->globalsAccessor->getBoolean($key, $default);
    }

    public function getInt(string $key, int $default = 0): int
    {
        if (isset(self::KEY_MAP[$key])) {
            return $this->envBag->getInt($key, $default);
        }

        return $this->globalsAccessor->getInt($key, $default);
    }

    public function has(string $key): bool
    {
        if (isset(self::KEY_MAP[$key])) {
            return $this->envBag->has($key);
        }

        return $this->globalsAccessor->has($key);
    }
}
