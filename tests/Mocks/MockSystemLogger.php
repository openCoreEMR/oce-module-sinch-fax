<?php

/**
 * Mock SystemLogger for testing
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenEMR\Common\Logging;

/**
 * Mock SystemLogger to avoid dependencies on OpenEMR core during tests
 */
class SystemLogger
{
    /**
     * @var array<int, array{level: string, message: string}>
     */
    private static array $logs = [];

    public function __construct()
    {
    }

    public function debug(string $message): void
    {
        self::$logs[] = ['level' => 'debug', 'message' => $message];
    }

    public function info(string $message): void
    {
        self::$logs[] = ['level' => 'info', 'message' => $message];
    }

    public function warning(string $message): void
    {
        self::$logs[] = ['level' => 'warning', 'message' => $message];
    }

    public function error(string $message): void
    {
        self::$logs[] = ['level' => 'error', 'message' => $message];
    }

    /**
     * @return array<int, array{level: string, message: string}>
     */
    public static function getLogs(): array
    {
        return self::$logs;
    }

    public static function clearLogs(): void
    {
        self::$logs = [];
    }
}
