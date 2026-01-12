<?php

/**
 * Manages the configuration options for the OpenCoreEMR Sinch Fax Module.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax;

use OpenEMR\Services\Globals\GlobalSetting;
use OpenEMR\Common\Crypto\CryptoGen;
use Symfony\Component\HttpFoundation\IpUtils;

class GlobalConfig
{
    private readonly bool $isEnvConfigMode;

    public function __construct(
        private readonly ConfigAccessorInterface $configAccessor = new GlobalsAccessor()
    ) {
        $this->isEnvConfigMode = ConfigFactory::isEnvConfigMode();
    }

    public const CONFIG_OPTION_ENABLED = 'oce_sinch_fax_enabled';

    /**
     * Check if configuration is managed via environment variables
     */
    public function isEnvConfigMode(): bool
    {
        return $this->isEnvConfigMode;
    }
    public const CONFIG_OPTION_PROJECT_ID = 'oce_sinch_fax_project_id';
    public const CONFIG_OPTION_SERVICE_ID = 'oce_sinch_fax_service_id';
    public const CONFIG_OPTION_AUTH_METHOD = 'oce_sinch_fax_auth_method';
    public const CONFIG_OPTION_API_KEY = 'oce_sinch_fax_api_key';
    public const CONFIG_OPTION_API_SECRET = 'oce_sinch_fax_api_secret';
    public const CONFIG_OPTION_OAUTH_TOKEN = 'oce_sinch_fax_oauth_token';
    public const CONFIG_OPTION_REGION = 'oce_sinch_fax_region';
    public const CONFIG_OPTION_FILE_STORAGE_PATH = 'oce_sinch_fax_file_storage_path';
    public const CONFIG_OPTION_DEFAULT_RETRY_COUNT = 'oce_sinch_fax_default_retry_count';
    public const CONFIG_OPTION_WEBHOOK_USERNAME = 'oce_sinch_fax_webhook_username';
    public const CONFIG_OPTION_WEBHOOK_PASSWORD = 'oce_sinch_fax_webhook_password';
    public const CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST = 'oce_sinch_fax_webhook_ip_allowlist';

    public function isEnabled(): bool
    {
        return $this->configAccessor->getBoolean(self::CONFIG_OPTION_ENABLED, false);
    }

    public function getProjectId(): string
    {
        return $this->configAccessor->getString(self::CONFIG_OPTION_PROJECT_ID, '');
    }

    public function getServiceId(): string
    {
        return $this->configAccessor->getString(self::CONFIG_OPTION_SERVICE_ID, '');
    }

    public function getAuthMethod(): string
    {
        return $this->configAccessor->getString(self::CONFIG_OPTION_AUTH_METHOD, 'basic');
    }

    public function getApiKey(): string
    {
        return $this->configAccessor->getString(self::CONFIG_OPTION_API_KEY, '');
    }

    public function getApiSecret(): string
    {
        $value = $this->configAccessor->getString(self::CONFIG_OPTION_API_SECRET, '');
        if ($value !== '' && $value !== '0') {
            // In env config mode, secrets are stored as plaintext (no encryption)
            if ($this->isEnvConfigMode) {
                return $value;
            }
            $cryptoGen = new CryptoGen();
            $decrypted = $cryptoGen->decryptStandard($value);
            return $decrypted !== false ? $decrypted : '';
        }
        return '';
    }

    public function getOAuthToken(): string
    {
        $value = $this->configAccessor->getString(self::CONFIG_OPTION_OAUTH_TOKEN, '');
        if ($value !== '' && $value !== '0') {
            // In env config mode, secrets are stored as plaintext (no encryption)
            if ($this->isEnvConfigMode) {
                return $value;
            }
            $cryptoGen = new CryptoGen();
            $decrypted = $cryptoGen->decryptStandard($value);
            return $decrypted !== false ? $decrypted : '';
        }
        return '';
    }

    public function getRegion(): string
    {
        return $this->configAccessor->getString(self::CONFIG_OPTION_REGION, 'global');
    }

    public function getFileStoragePath(): string
    {
        $path = $this->configAccessor->getString(self::CONFIG_OPTION_FILE_STORAGE_PATH, '');
        if ($path === '' || $path === '0') {
            $path = $this->configAccessor->getString('OE_SITE_DIR', '') . '/documents/sinch_faxes';
        }
        return $path;
    }

    public function getDefaultRetryCount(): int
    {
        return $this->configAccessor->getInt(self::CONFIG_OPTION_DEFAULT_RETRY_COUNT, 3);
    }

    public function getWebhookUsername(): string
    {
        return $this->configAccessor->getString(self::CONFIG_OPTION_WEBHOOK_USERNAME, '');
    }

    public function getWebhookPassword(): string
    {
        $value = $this->configAccessor->getString(self::CONFIG_OPTION_WEBHOOK_PASSWORD, '');
        if ($value !== '' && $value !== '0') {
            // In env config mode, secrets are stored as plaintext (no encryption)
            if ($this->isEnvConfigMode) {
                return $value;
            }
            $cryptoGen = new CryptoGen();
            $decrypted = $cryptoGen->decryptStandard($value);
            return $decrypted !== false ? $decrypted : '';
        }
        return '';
    }

    /**
     * Get the webhook IP allowlist as an array of IP addresses or CIDR ranges
     * Supports both newline-delimited (from UI textarea) and comma-delimited (from env vars)
     *
     * @return array<int, string>
     */
    public function getWebhookIpAllowlist(): array
    {
        $value = $this->configAccessor->getString(self::CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST, '');
        if ($value === '' || $value === '0') {
            return [];
        }
        // Split by newlines or commas and filter empty values
        $parts = preg_split('/[\n,]+/', $value);
        if ($parts === false) {
            return [];
        }
        $entries = [];
        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ($trimmed !== '') {
                $entries[] = $trimmed;
            }
        }
        return $entries;
    }

    /**
     * Check if webhook authentication is configured
     */
    public function isWebhookAuthConfigured(): bool
    {
        return !in_array($this->getWebhookUsername(), ['', '0'], true)
            && !in_array($this->getWebhookPassword(), ['', '0'], true);
    }

    /**
     * Verify webhook Basic Auth credentials
     */
    public function verifyWebhookAuth(string $username, string $password): bool
    {
        if (!$this->isWebhookAuthConfigured()) {
            return false;
        }
        return $username === $this->getWebhookUsername()
            && $password === $this->getWebhookPassword();
    }

    /**
     * Check if an IP address is in the allowlist
     * Supports both raw IPs and CIDR notation (e.g., 192.168.1.0/24)
     * Returns true if allowlist is empty (no restriction) or IP matches
     */
    public function isIpInAllowlist(string $ip): bool
    {
        $allowlist = $this->getWebhookIpAllowlist();
        if ($allowlist === []) {
            return true; // No allowlist = allow all
        }

        return IpUtils::checkIp($ip, $allowlist);
    }

    public function getSiteAddrOath(): string
    {
        return $this->configAccessor->getString('site_addr_oath', '');
    }

    public function getWebroot(): string
    {
        return $this->configAccessor->getString('webroot', '');
    }

    public function getAssetsStaticRelative(): string
    {
        return $this->configAccessor->getString('assets_static_relative', '');
    }

    public function isConfigured(): bool
    {
        $hasProjectId = !in_array($this->getProjectId(), ['', '0'], true);
        $hasBasicAuth = $this->getAuthMethod() === 'basic'
            && !in_array($this->getApiKey(), ['', '0'], true)
            && !in_array($this->getApiSecret(), ['', '0'], true);
        $hasOAuth = $this->getAuthMethod() === 'oauth'
            && !in_array($this->getOAuthToken(), ['', '0'], true);

        return $hasProjectId && ($hasBasicAuth || $hasOAuth);
    }

    /**
     * @return array<string, array<string, string|bool|int|array<string, string>>>
     */
    public function getGlobalSettingSectionConfiguration(): array
    {
        return [
            self::CONFIG_OPTION_ENABLED => [
                'title' => 'Enable Sinch Fax',
                'description' => 'Enable the Sinch Fax module',
                'type' => GlobalSetting::DATA_TYPE_BOOL,
                'default' => false
            ],
            self::CONFIG_OPTION_PROJECT_ID => [
                'title' => 'Sinch Project ID',
                'description' => 'Your Sinch project ID from the dashboard',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => ''
            ],
            self::CONFIG_OPTION_SERVICE_ID => [
                'title' => 'Sinch Service ID',
                'description' => 'Your Sinch service ID (optional)',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => ''
            ],
            self::CONFIG_OPTION_AUTH_METHOD => [
                'title' => 'Authentication Method',
                'description' => 'Choose between Basic Auth or OAuth2',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => 'basic',
                'options' => [
                    'basic' => 'Basic Authentication',
                    'oauth' => 'OAuth2'
                ]
            ],
            self::CONFIG_OPTION_API_KEY => [
                'title' => 'API Key',
                'description' => 'Your Sinch API key (for Basic Auth)',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => ''
            ],
            self::CONFIG_OPTION_API_SECRET => [
                'title' => 'API Secret',
                'description' => 'Your Sinch API secret (for Basic Auth)',
                'type' => GlobalSetting::DATA_TYPE_ENCRYPTED,
                'default' => ''
            ],
            self::CONFIG_OPTION_OAUTH_TOKEN => [
                'title' => 'OAuth Token',
                'description' => 'Your OAuth2 access token (for OAuth authentication)',
                'type' => GlobalSetting::DATA_TYPE_ENCRYPTED,
                'default' => ''
            ],
            self::CONFIG_OPTION_REGION => [
                'title' => 'API Region',
                'description' => 'Select your preferred Sinch API region',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => 'global',
                'options' => [
                    'global' => 'Global (Auto-routed)',
                    'use1' => 'US East Coast',
                    'eu1' => 'Europe',
                    'sae1' => 'South America',
                    'apse1' => 'South East Asia 1',
                    'apse2' => 'South East Asia 2'
                ]
            ],
            self::CONFIG_OPTION_FILE_STORAGE_PATH => [
                'title' => 'File Storage Path',
                'description' => 'Path where fax files will be stored (leave empty for default)',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => ''
            ],
            self::CONFIG_OPTION_DEFAULT_RETRY_COUNT => [
                'title' => 'Default Retry Count',
                'description' => 'Number of times to retry sending a failed fax',
                'type' => GlobalSetting::DATA_TYPE_NUMBER,
                'default' => 3
            ],
            self::CONFIG_OPTION_WEBHOOK_USERNAME => [
                'title' => 'Webhook Username',
                'description' => 'Username for HTTP Basic Auth on webhook endpoint (required for Sinch callbacks)',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => ''
            ],
            self::CONFIG_OPTION_WEBHOOK_PASSWORD => [
                'title' => 'Webhook Password',
                'description' => 'Password for HTTP Basic Auth on webhook endpoint (required for Sinch callbacks)',
                'type' => GlobalSetting::DATA_TYPE_ENCRYPTED,
                'default' => ''
            ],
            self::CONFIG_OPTION_WEBHOOK_IP_ALLOWLIST => [
                'title' => 'Webhook IP Allowlist',
                'description' => 'Allowed IPs for webhooks (one per line, supports CIDR). Empty = allow all.',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => ''
            ]
        ];
    }
}
