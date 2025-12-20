<?php

/**
 * Mock GlobalsInitializedEvent for testing
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenEMR\Events\Globals;

class GlobalsInitializedEvent
{
    public const EVENT_HANDLE = 'globals.initialized';

    private $globalsService;

    public function __construct()
    {
        $this->globalsService = new class {
            public function createSection(string $section, string $icon): void
            {
                // Mock implementation
            }

            public function appendToSection(string $section, string $key, $setting): void
            {
                // Mock implementation
            }
        };
    }

    public function getGlobalsService()
    {
        return $this->globalsService;
    }
}
