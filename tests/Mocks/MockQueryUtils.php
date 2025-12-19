<?php

/**
 * Mock QueryUtils for testing
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenEMR\Common\Database;

/**
 * Mock QueryUtils to avoid database calls during tests
 */
class QueryUtils
{
    /**
     * @var array<int, array{sql: string, binds: array<mixed>}>
     */
    private static array $queries = [];

    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private static array $mockResults = [];

    /**
     * @param string $sql
     * @param array<mixed> $binds
     * @return array<int, array<string, mixed>>
     */
    public static function fetchRecords(string $sql, array $binds = []): array
    {
        self::$queries[] = ['sql' => $sql, 'binds' => $binds];

        // Return mock results if set
        $key = self::generateKey($sql, $binds);
        return self::$mockResults[$key] ?? [];
    }

    /**
     * @param string $sql
     * @param array<mixed> $binds
     * @return array<string, mixed>|null
     */
    public static function querySingleRow(string $sql, array $binds = []): ?array
    {
        self::$queries[] = ['sql' => $sql, 'binds' => $binds];

        // Return mock results if set
        $key = self::generateKey($sql, $binds);
        $results = self::$mockResults[$key] ?? [];
        return !empty($results) ? $results[0] : null;
    }

    /**
     * @param string $sql
     * @param array<mixed> $binds
     * @return mixed
     */
    public static function sqlStatementThrowException(string $sql, array $binds = []): mixed
    {
        self::$queries[] = ['sql' => $sql, 'binds' => $binds];
        return true;
    }

    /**
     * @param string $sql
     * @param array<mixed> $binds
     * @return mixed
     */
    public static function sqlStatement(string $sql, array $binds = []): mixed
    {
        self::$queries[] = ['sql' => $sql, 'binds' => $binds];
        return true;
    }

    /**
     * @return array<int, array{sql: string, binds: array<mixed>}>
     */
    public static function getQueries(): array
    {
        return self::$queries;
    }

    public static function clearQueries(): void
    {
        self::$queries = [];
    }

    /**
     * @param string $sql
     * @param array<mixed> $binds
     * @param array<int, array<string, mixed>> $results
     */
    public static function setMockResult(string $sql, array $binds, array $results): void
    {
        $key = self::generateKey($sql, $binds);
        self::$mockResults[$key] = $results;
    }

    public static function clearMockResults(): void
    {
        self::$mockResults = [];
    }

    /**
     * @param string $sql
     * @param array<mixed> $binds
     */
    private static function generateKey(string $sql, array $binds): string
    {
        return md5($sql . serialize($binds));
    }
}
