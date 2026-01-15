<?php

/**
 * Mock GlobalSetting for testing
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenEMR\Services\Globals;

/**
 * Mock GlobalSetting to avoid OpenEMR dependencies during tests
 */
class GlobalSetting
{
    public const DATA_TYPE_BOOL = 1;
    public const DATA_TYPE_TEXT = 11;
    public const DATA_TYPE_NUMBER = 2;
    public const DATA_TYPE_ENCRYPTED = 36;
    public const DATA_TYPE_ENCRYPTED_HASH = 'encrypted_hash';
}
