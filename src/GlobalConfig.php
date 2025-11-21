<?php

/**
 * Manages the configuration options for the OpenCoreEMR Sinch Fax Module.
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax;

use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Services\Globals\GlobalSetting;
use OpenEMR\Common\Crypto\CryptoGen;

class GlobalConfig
{
    private readonly OEGlobalsBag $globals;

    public function __construct(?OEGlobalsBag $globals = null)
    {
        $this->globals = $globals ?? OEGlobalsBag::getInstance();
    }

    public const CONFIG_OPTION_ENABLED = 'oce_sinch_fax_enabled';
    public const CONFIG_OPTION_PROJECT_ID = 'oce_sinch_fax_project_id';
    public const CONFIG_OPTION_SERVICE_ID = 'oce_sinch_fax_service_id';
    public const CONFIG_OPTION_AUTH_METHOD = 'oce_sinch_fax_auth_method';
    public const CONFIG_OPTION_API_KEY = 'oce_sinch_fax_api_key';
    public const CONFIG_OPTION_API_SECRET = 'oce_sinch_fax_api_secret';
    public const CONFIG_OPTION_OAUTH_TOKEN = 'oce_sinch_fax_oauth_token';
    public const CONFIG_OPTION_REGION = 'oce_sinch_fax_region';
    public const CONFIG_OPTION_WEBHOOK_SECRET = 'oce_sinch_fax_webhook_secret';
    public const CONFIG_OPTION_FILE_STORAGE_PATH = 'oce_sinch_fax_file_storage_path';
    public const CONFIG_OPTION_AUTO_RECEIVE = 'oce_sinch_fax_auto_receive';
    public const CONFIG_OPTION_DEFAULT_RETRY_COUNT = 'oce_sinch_fax_default_retry_count';
    public const CONFIG_OPTION_ENABLE_STATUS_POLLING = 'oce_sinch_fax_enable_status_polling';
    public const CONFIG_OPTION_ENABLE_WEBHOOKS = 'oce_sinch_fax_enable_webhooks';
    public const CONFIG_OPTION_ENABLE_INCOMING_POLLING = 'oce_sinch_fax_enable_incoming_polling';
    public const CONFIG_OPTION_LAST_POLL_TIME = 'oce_sinch_fax_last_poll_time';

    public function isEnabled(): bool
    {
        return $this->globals->getBoolean(self::CONFIG_OPTION_ENABLED, false);
    }

    public function getProjectId(): string
    {
        return $this->globals->getString(self::CONFIG_OPTION_PROJECT_ID, '');
    }

    public function getServiceId(): string
    {
        return $this->globals->getString(self::CONFIG_OPTION_SERVICE_ID, '');
    }

    public function getAuthMethod(): string
    {
        return $this->globals->getString(self::CONFIG_OPTION_AUTH_METHOD, 'basic');
    }

    public function getApiKey(): string
    {
        return $this->globals->getString(self::CONFIG_OPTION_API_KEY, '');
    }

    public function getApiSecret(): string
    {
        $value = $this->globals->getString(self::CONFIG_OPTION_API_SECRET, '');
        if (!empty($value)) {
            $cryptoGen = new CryptoGen();
            $decrypted = $cryptoGen->decryptStandard($value);
            return $decrypted !== false ? $decrypted : '';
        }
        return '';
    }

    public function getOAuthToken(): string
    {
        $value = $this->globals->getString(self::CONFIG_OPTION_OAUTH_TOKEN, '');
        if (!empty($value)) {
            $cryptoGen = new CryptoGen();
            $decrypted = $cryptoGen->decryptStandard($value);
            return $decrypted !== false ? $decrypted : '';
        }
        return '';
    }

    public function getRegion(): string
    {
        return $this->globals->getString(self::CONFIG_OPTION_REGION, 'global');
    }

    public function getWebhookSecret(): string
    {
        $value = $this->globals->getString(self::CONFIG_OPTION_WEBHOOK_SECRET, '');
        if (!empty($value)) {
            $cryptoGen = new CryptoGen();
            $decrypted = $cryptoGen->decryptStandard($value);
            return $decrypted !== false ? $decrypted : '';
        }
        return '';
    }

    public function getFileStoragePath(): string
    {
        $path = $this->globals->getString(self::CONFIG_OPTION_FILE_STORAGE_PATH, '');
        if (empty($path)) {
            $path = $this->globals->getString('OE_SITE_DIR', '') . '/documents/sinch_faxes';
        }
        return $path;
    }

    public function getAutoReceive(): bool
    {
        return $this->globals->getBoolean(self::CONFIG_OPTION_AUTO_RECEIVE, true);
    }

    public function getDefaultRetryCount(): int
    {
        return $this->globals->getInt(self::CONFIG_OPTION_DEFAULT_RETRY_COUNT, 3);
    }

    public function isStatusPollingEnabled(): bool
    {
        return $this->globals->getBoolean(self::CONFIG_OPTION_ENABLE_STATUS_POLLING, false);
    }

    public function isWebhooksEnabled(): bool
    {
        return $this->globals->getBoolean(self::CONFIG_OPTION_ENABLE_WEBHOOKS, true);
    }

    public function isIncomingPollingEnabled(): bool
    {
        return $this->globals->getBoolean(self::CONFIG_OPTION_ENABLE_INCOMING_POLLING, false);
    }

    public function getLastPollTime(): ?string
    {
        return $this->globals->get(self::CONFIG_OPTION_LAST_POLL_TIME);
    }

    public function setLastPollTime(string $time): void
    {
        $this->globals->set(self::CONFIG_OPTION_LAST_POLL_TIME, $time);
        sqlQuery("UPDATE globals SET gl_value = ? WHERE gl_name = ?", [$time, self::CONFIG_OPTION_LAST_POLL_TIME]);
    }

    public function hasPublicCallbackUrl(): bool
    {
        // Check if we have a public (non-localhost) site address for callbacks
        $siteAddr = $this->globals->getString('site_addr_oath', '');
        $localhostPattern = '/localhost|127\.0\.0\.1|192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\./';
        return !empty($siteAddr) && !preg_match($localhostPattern, $siteAddr);
    }

    public function getSiteAddrOath(): string
    {
        return $this->globals->getString('site_addr_oath', '');
    }

    public function getWebroot(): string
    {
        return $this->globals->getString('webroot', '');
    }

    public function getAssetsStaticRelative(): string
    {
        return $this->globals->getString('assets_static_relative', '');
    }

    public function isConfigured(): bool
    {
        return !empty($this->getProjectId())
            && (
                ($this->getAuthMethod() === 'basic' && !empty($this->getApiKey()) && !empty($this->getApiSecret()))
                || ($this->getAuthMethod() === 'oauth' && !empty($this->getOAuthToken()))
            );
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
                'description' => 'Your Sinch service ID (optional - only required for fax-to-email features)',
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
            self::CONFIG_OPTION_WEBHOOK_SECRET => [
                'title' => 'Webhook Secret',
                'description' => 'Secret key for validating incoming webhooks (auto-generated if empty)',
                'type' => GlobalSetting::DATA_TYPE_ENCRYPTED,
                'default' => ''
            ],
            self::CONFIG_OPTION_FILE_STORAGE_PATH => [
                'title' => 'File Storage Path',
                'description' => 'Path where fax files will be stored (leave empty for default)',
                'type' => GlobalSetting::DATA_TYPE_TEXT,
                'default' => ''
            ],
            self::CONFIG_OPTION_AUTO_RECEIVE => [
                'title' => 'Auto-Receive Faxes',
                'description' => 'Automatically receive and store incoming faxes',
                'type' => GlobalSetting::DATA_TYPE_BOOL,
                'default' => true
            ],
            self::CONFIG_OPTION_DEFAULT_RETRY_COUNT => [
                'title' => 'Default Retry Count',
                'description' => 'Number of times to retry sending a failed fax',
                'type' => GlobalSetting::DATA_TYPE_NUMBER,
                'default' => 3
            ],
            self::CONFIG_OPTION_ENABLE_STATUS_POLLING => [
                'title' => 'Enable Status Polling',
                'description' => 'Automatically poll Sinch API for fax status updates when viewing faxes ' .
                    '(enabled automatically for localhost/testing)',
                'type' => GlobalSetting::DATA_TYPE_BOOL,
                'default' => false
            ],
            self::CONFIG_OPTION_ENABLE_WEBHOOKS => [
                'title' => 'Enable Webhooks',
                'description' => 'Enable webhook endpoint for receiving fax status updates and ' .
                    'incoming faxes from Sinch',
                'type' => GlobalSetting::DATA_TYPE_BOOL,
                'default' => true
            ],
            self::CONFIG_OPTION_ENABLE_INCOMING_POLLING => [
                'title' => 'Enable Incoming Fax Polling',
                'description' => 'Poll Sinch API for new incoming faxes (useful when webhooks are ' .
                    'disabled or unavailable)',
                'type' => GlobalSetting::DATA_TYPE_BOOL,
                'default' => false
            ]
        ];
    }
}
