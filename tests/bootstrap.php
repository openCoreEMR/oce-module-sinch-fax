<?php

/**
 * PHPUnit Bootstrap File
 *
 * This file sets up the test environment and loads necessary dependencies.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load mock classes before anything else to prevent "class not found" errors
require_once __DIR__ . '/Mocks/MockSystemLogger.php';
require_once __DIR__ . '/Mocks/MockQueryUtils.php';
require_once __DIR__ . '/Mocks/MockCryptoGen.php';
require_once __DIR__ . '/Mocks/MockCsrfUtils.php';
require_once __DIR__ . '/Mocks/MockGlobalSetting.php';
require_once __DIR__ . '/Mocks/MockDocument.php';

// Define OpenEMR global functions used in controllers
if (!function_exists('xlt')) {
    /**
     * Mock translation function - just returns the input string
     */
    function xlt(string $text): string
    {
        return $text;
    }
}

if (!function_exists('text')) {
    /**
     * Mock text sanitization function - just returns the input string
     */
    function text(string $text): string
    {
        return $text;
    }
}

// Define constants used in tests
if (!defined('DIRECTORY_SEPARATOR')) {
    define('DIRECTORY_SEPARATOR', '/');
}
