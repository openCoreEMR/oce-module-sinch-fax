-- This table definition is loaded and then executed when the OpenEMR interface's install button is clicked.

-- Table to store fax documents
CREATE TABLE IF NOT EXISTS `oce_sinch_faxes` (
    `id` INT(11) PRIMARY KEY AUTO_INCREMENT NOT NULL,
    `sinch_fax_id` VARCHAR(255) NOT NULL COMMENT 'Sinch fax ID from the API',
    `direction` ENUM('INBOUND', 'OUTBOUND') NOT NULL COMMENT 'Direction of the fax',
    `from_number` VARCHAR(20) NOT NULL COMMENT 'Sender phone number',
    `to_number` VARCHAR(20) NOT NULL COMMENT 'Recipient phone number',
    `status` VARCHAR(50) NOT NULL COMMENT 'Status of the fax (QUEUED, IN_PROGRESS, COMPLETED, FAILED, etc)',
    `num_pages` INT(11) DEFAULT 0 COMMENT 'Number of pages in the fax',
    `file_path` VARCHAR(255) DEFAULT NULL COMMENT 'Path to stored fax file',
    `mime_type` VARCHAR(100) DEFAULT NULL COMMENT 'MIME type of the fax file',
    `error_code` VARCHAR(50) DEFAULT NULL COMMENT 'Error code if fax failed',
    `error_message` TEXT DEFAULT NULL COMMENT 'Error message if fax failed',
    `patient_id` BIGINT(20) DEFAULT NULL COMMENT 'Associated patient ID',
    `user_id` BIGINT(20) DEFAULT NULL COMMENT 'User who sent the fax (for outbound)',
    `callback_url` VARCHAR(500) DEFAULT NULL COMMENT 'Callback URL used for this fax',
    `cover_page_id` VARCHAR(255) DEFAULT NULL COMMENT 'Cover page ID if used',
    `sinch_create_time` DATETIME DEFAULT NULL COMMENT 'When Sinch created the fax',
    `sinch_completed_time` DATETIME DEFAULT NULL COMMENT 'When Sinch completed the fax',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When this record was created',
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'When this record was last updated',
    INDEX `idx_sinch_fax_id` (`sinch_fax_id`),
    INDEX `idx_direction` (`direction`),
    INDEX `idx_status` (`status`),
    INDEX `idx_patient_id` (`patient_id`),
    INDEX `idx_user_id` (`user_id`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to store Sinch service configuration
CREATE TABLE IF NOT EXISTS `oce_sinch_services` (
    `id` INT(11) PRIMARY KEY AUTO_INCREMENT NOT NULL,
    `service_name` VARCHAR(100) NOT NULL COMMENT 'Friendly name for this service',
    `project_id` VARCHAR(255) NOT NULL COMMENT 'Sinch project ID',
    `service_id` VARCHAR(255) NOT NULL COMMENT 'Sinch service ID',
    `api_key` VARCHAR(255) DEFAULT NULL COMMENT 'Sinch API key (encrypted)',
    `api_secret` TEXT DEFAULT NULL COMMENT 'Sinch API secret (encrypted)',
    `oauth_token` TEXT DEFAULT NULL COMMENT 'OAuth token if using OAuth2 authentication',
    `oauth_token_expires` DATETIME DEFAULT NULL COMMENT 'When the OAuth token expires',
    `region` VARCHAR(50) DEFAULT 'global' COMMENT 'Sinch API region (global, use1, eu1, sae1, apse1, apse2)',
    `webhook_url` VARCHAR(500) DEFAULT NULL COMMENT 'URL for receiving webhooks',
    `is_active` BOOLEAN DEFAULT TRUE COMMENT 'Whether this service is active',
    `is_default` BOOLEAN DEFAULT FALSE COMMENT 'Whether this is the default service',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When this record was created',
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'When this record was last updated',
    UNIQUE INDEX `idx_service_name` (`service_name`),
    INDEX `idx_is_active` (`is_active`),
    INDEX `idx_is_default` (`is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to store cover pages
CREATE TABLE IF NOT EXISTS `oce_sinch_cover_pages` (
    `id` INT(11) PRIMARY KEY AUTO_INCREMENT NOT NULL,
    `sinch_cover_page_id` VARCHAR(255) DEFAULT NULL COMMENT 'Sinch cover page ID',
    `name` VARCHAR(100) NOT NULL COMMENT 'Name of the cover page',
    `file_path` VARCHAR(255) NOT NULL COMMENT 'Path to the cover page PDF file',
    `is_active` BOOLEAN DEFAULT TRUE COMMENT 'Whether this cover page is active',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP COMMENT 'When this record was created',
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'When this record was last updated',
    UNIQUE INDEX `idx_name` (`name`),
    INDEX `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
