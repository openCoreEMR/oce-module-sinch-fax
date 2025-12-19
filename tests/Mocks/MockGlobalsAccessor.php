<?php

/**
 * Mock GlobalsAccessor for testing
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Tests\Mocks;

use OpenCoreEMR\Modules\SinchFax\GlobalsAccessor;

class MockGlobalsAccessor extends GlobalsAccessor
{
    /**
     * @var array<string, mixed>
     */
    private array $mockData = [];

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->mockData = $data;
    }

    /**
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->mockData[$key] ?? $default;
    }

    /**
     * @param string $key
     * @param string $default
     */
    public function getString(string $key, string $default = ''): string
    {
        return (string)($this->mockData[$key] ?? $default);
    }

    /**
     * @param string $key
     * @param int $default
     */
    public function getInt(string $key, int $default = 0): int
    {
        return (int)($this->mockData[$key] ?? $default);
    }

    /**
     * @param string $key
     * @param bool $default
     */
    public function getBoolean(string $key, bool $default = false): bool
    {
        return (bool)($this->mockData[$key] ?? $default);
    }

    /**
     * @param string $key
     * @param mixed $value
     */
    public function set(string $key, mixed $value): void
    {
        $this->mockData[$key] = $value;
    }
}
