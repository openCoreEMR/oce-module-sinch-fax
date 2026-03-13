<?php

/**
 * Verify entry points work when installed via oe-module-installer-plugin
 *
 * The installer plugin places module files into custom_modules/ but does NOT
 * create a module-level vendor/ directory. This test resolves every require
 * path in public/*.php entry points against the installed directory layout
 * (using the OpenEMR source from vendor/openemr/openemr/) and verifies that
 * all required files would exist at runtime.
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class EntryPointInstallContextTest extends TestCase
{
    /**
     * Installed module path relative to OpenEMR root, matching what
     * oe-module-installer-plugin produces from the package name.
     */
    private const INSTALLED_MODULE_PREFIX = 'interface/modules/custom_modules/oce-module-sinch-fax/';

    private const OPENEMR_ROOT = __DIR__ . '/../../vendor/openemr/openemr';
    private const MODULE_ROOT = __DIR__ . '/../..';
    private const PUBLIC_DIR = __DIR__ . '/../../public';

    /**
     * @return array<string, array{string}>
     */
    public static function entryPointProvider(): array
    {
        $files = glob(self::PUBLIC_DIR . '/*.php');
        if ($files === false || $files === []) {
            return [];
        }

        $cases = [];
        foreach ($files as $file) {
            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('entryPointProvider')]
    public function requirePathsResolveInInstalledContext(string $filePath): void
    {
        $openemrRoot = realpath(self::OPENEMR_ROOT);
        if ($openemrRoot === false) {
            self::markTestSkipped('OpenEMR source not available at ' . self::OPENEMR_ROOT);
        }

        $contents = file_get_contents($filePath);
        self::assertIsString($contents);

        // Extract all require/require_once __DIR__ . '...' statements
        preg_match_all(
            "/require(?:_once)?\s+__DIR__\s*\.\s*['\"]([^'\"]+)['\"]/",
            $contents,
            $matches
        );

        if ($matches[1] === []) {
            // Entry point has no __DIR__-relative requires — nothing to verify
            return;
        }

        foreach ($matches[1] as $relativePath) {
            $resolvedPath = self::resolveInstalledPath($relativePath);

            if (str_starts_with($resolvedPath, self::INSTALLED_MODULE_PREFIX)) {
                // Path lands inside the module directory
                $moduleRelative = substr($resolvedPath, strlen(self::INSTALLED_MODULE_PREFIX));

                // The installer plugin does NOT create vendor/ — any require
                // targeting it will fail at runtime
                self::assertFalse(
                    str_starts_with($moduleRelative, 'vendor/'),
                    sprintf(
                        "%s requires '%s' which resolves to module vendor/ — "
                        . "this directory does not exist when installed via "
                        . "oe-module-installer-plugin. Use the root OpenEMR "
                        . "autoloader via globals.php instead.",
                        basename($filePath),
                        $relativePath
                    )
                );

                // Also verify the file exists in the module source
                $moduleRoot = realpath(self::MODULE_ROOT);
                self::assertNotFalse($moduleRoot);
                self::assertFileExists(
                    $moduleRoot . '/' . $moduleRelative,
                    sprintf(
                        "%s requires '%s' which resolves to '%s' inside the module, "
                        . "but this file does not exist",
                        basename($filePath),
                        $relativePath,
                        $moduleRelative
                    )
                );
            } else {
                // Path lands in the OpenEMR tree — verify it exists
                self::assertFileExists(
                    $openemrRoot . '/' . $resolvedPath,
                    sprintf(
                        "%s requires '%s' which resolves to '%s' in the OpenEMR "
                        . "tree, but this file does not exist in vendor/openemr/openemr/",
                        basename($filePath),
                        $relativePath,
                        $resolvedPath
                    )
                );
            }
        }
    }

    /**
     * Resolve a __DIR__-relative path from public/ to an OpenEMR-root-relative path.
     *
     * Simulates the installed layout where public/ is at:
     * {openemr_root}/interface/modules/custom_modules/oce-module-sinch-fax/public/
     */
    private static function resolveInstalledPath(string $relativePath): string
    {
        $segments = explode('/', self::INSTALLED_MODULE_PREFIX . 'public' . $relativePath);

        $resolved = [];
        foreach ($segments as $segment) {
            if ($segment === '..') {
                array_pop($resolved);
            } elseif ($segment !== '' && $segment !== '.') {
                $resolved[] = $segment;
            }
        }

        return implode('/', $resolved);
    }
}
