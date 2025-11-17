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

use OpenEMR\Services\Globals\GlobalSetting;

class GlobalConfig
{
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

    public function isEnabled(): bool
    {
        return (bool)($GLOBALS[self::CONFIG_OPTION_ENABLED] ?? false);
    }

    public function getProjectId(): string
    {
        return $GLOBALS[self::CONFIG_OPTION_PROJECT_ID] ?? '';
    }

    public function getServiceId(): string
    {
        return $GLOBALS[self::CONFIG_OPTION_SERVICE_ID] ?? '';
    }

    public function getAuthMethod(): string
    {
        return $GLOBALS[self::CONFIG_OPTION_AUTH_METHOD] ?? 'basic';
    }

    public function getApiKey(): string
    {
        return $GLOBALS[self::CONFIG_OPTION_API_KEY] ?? '';
    }

    public function getApiSecret(): string
    {
        return $GLOBALS[self::CONFIG_OPTION_API_SECRET] ?? '';
    }

    public function getOAuthToken(): string
    {
        return $GLOBALS[self::CONFIG_OPTION_OAUTH_TOKEN] ?? '';
    }

    public function getRegion(): string
    {
        return $GLOBALS[self::CONFIG_OPTION_REGION] ?? 'global';
    }

    public function getWebhookSecret(): string
    {
        return $GLOBALS[self::CONFIG_OPTION_WEBHOOK_SECRET] ?? '';
    }

    public function getFileStoragePath(): string
    {
        $path = $GLOBALS[self::CONFIG_OPTION_FILE_STORAGE_PATH] ?? '';
        if (empty($path)) {
            $path = $GLOBALS['OE_SITE_DIR'] . '/documents/sinch_faxes';
        }
        return $path;
    }

    public function getAutoReceive(): bool
    {
        return (bool)($GLOBALS[self::CONFIG_OPTION_AUTO_RECEIVE] ?? true);
    }

    public function getDefaultRetryCount(): int
    {
        return (int)($GLOBALS[self::CONFIG_OPTION_DEFAULT_RETRY_COUNT] ?? 3);
    }

    public function isConfigured(): bool
    {
        return !empty($this->getProjectId())
            && !empty($this->getServiceId())
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
                'description' => 'Your Sinch service ID from the dashboard',
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
                'type' => GlobalSetting::DATA_TYPE_ENCRYPTED,
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
            ]
        ];
    }
}
