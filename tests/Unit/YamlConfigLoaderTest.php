<?php

/**
 * Unit tests for YamlConfigLoader
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Tests\Unit;

use OpenCoreEMR\Modules\SinchFax\Exception\FaxConfigurationException;
use OpenCoreEMR\Modules\SinchFax\YamlConfigLoader;
use PHPUnit\Framework\TestCase;

class YamlConfigLoaderTest extends TestCase
{
    private YamlConfigLoader $loader;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->loader = new YamlConfigLoader();
        $this->tmpDir = sys_get_temp_dir() . '/yaml_config_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    private function writeYaml(string $filename, string $content): string
    {
        $path = $this->tmpDir . '/' . $filename;
        file_put_contents($path, $content);
        return $path;
    }

    public function testLoadSingleFile(): void
    {
        $path = $this->writeYaml('config.yaml', "enabled: true\nproject_id: abc123\n");

        $data = $this->loader->load([$path]);

        $this->assertTrue($data['enabled']);
        $this->assertSame('abc123', $data['project_id']);
    }

    public function testLoadMergesMultipleFiles(): void
    {
        $config = $this->writeYaml('config.yaml', "enabled: true\nregion: global\n");
        $secrets = $this->writeYaml('secrets.yaml', "api_secret: secret456\n");

        $data = $this->loader->load([$config, $secrets]);

        $this->assertTrue($data['enabled']);
        $this->assertSame('global', $data['region']);
        $this->assertSame('secret456', $data['api_secret']);
    }

    public function testLaterFileOverridesEarlier(): void
    {
        $first = $this->writeYaml('first.yaml', "region: us\n");
        $second = $this->writeYaml('second.yaml', "region: eu\n");

        $data = $this->loader->load([$first, $second]);

        $this->assertSame('eu', $data['region']);
    }

    public function testLoadProcessesImports(): void
    {
        $this->writeYaml('secrets.yaml', "api_secret: imported_secret\n");
        $config = $this->writeYaml('config.yaml', "imports:\n  - { resource: secrets.yaml }\nenabled: true\n");

        $data = $this->loader->load([$config]);

        $this->assertTrue($data['enabled']);
        $this->assertSame('imported_secret', $data['api_secret']);
    }

    public function testParentKeysOverrideImportedKeys(): void
    {
        $this->writeYaml('base.yaml', "region: from_base\nenabled: false\n");
        $config = $this->writeYaml('config.yaml', "imports:\n  - { resource: base.yaml }\nregion: from_parent\n");

        $data = $this->loader->load([$config]);

        $this->assertSame('from_parent', $data['region']);
        $this->assertFalse($data['enabled']);
    }

    public function testImportsKeyIsRemovedFromResult(): void
    {
        $this->writeYaml('base.yaml', "enabled: true\n");
        $config = $this->writeYaml('config.yaml', "imports:\n  - { resource: base.yaml }\nregion: global\n");

        $data = $this->loader->load([$config]);

        $this->assertArrayNotHasKey('imports', $data);
    }

    public function testLoadEmptyFileReturnsEmptyArray(): void
    {
        $path = $this->writeYaml('empty.yaml', '');

        $data = $this->loader->load([$path]);

        $this->assertSame([], $data);
    }

    public function testLoadThrowsOnUnreadableFile(): void
    {
        $path = $this->writeYaml('unreadable.yaml', "enabled: true\n");
        chmod($path, 0000);

        $this->expectException(FaxConfigurationException::class);
        $this->expectExceptionMessage('not readable');

        try {
            $this->loader->load([$path]);
        } finally {
            chmod($path, 0644);
        }
    }

    public function testLoadThrowsOnMalformedYaml(): void
    {
        $path = $this->writeYaml('bad.yaml', "enabled: true\n  bad_indent: here\n");

        $this->expectException(FaxConfigurationException::class);
        $this->expectExceptionMessage('Invalid YAML');

        $this->loader->load([$path]);
    }

    public function testLoadThrowsOnNonMappingYaml(): void
    {
        $path = $this->writeYaml('scalar.yaml', "just a string\n");

        $this->expectException(FaxConfigurationException::class);
        $this->expectExceptionMessage('must contain a YAML mapping');

        $this->loader->load([$path]);
    }

    public function testHasConfigFilesReturnsTrueWhenFileExists(): void
    {
        $path = $this->writeYaml('config.yaml', "enabled: true\n");

        $this->assertTrue($this->loader->hasConfigFiles([$path]));
    }

    public function testHasConfigFilesReturnsFalseWhenNoFilesExist(): void
    {
        $this->assertFalse($this->loader->hasConfigFiles([
            '/nonexistent/config.yaml',
            '/also/nonexistent/secrets.yaml',
        ]));
    }

    public function testHasConfigFilesReturnsTrueIfAnyFileExists(): void
    {
        $path = $this->writeYaml('secrets.yaml', "api_secret: x\n");

        $this->assertTrue($this->loader->hasConfigFiles([
            '/nonexistent/config.yaml',
            $path,
        ]));
    }

    public function testResolveFilePathsFiltersToExisting(): void
    {
        $existing = $this->writeYaml('config.yaml', "enabled: true\n");

        $result = $this->loader->resolveFilePaths([
            '/nonexistent/config.yaml',
            $existing,
            '/also/nonexistent/secrets.yaml',
        ]);

        $this->assertSame([$existing], $result);
    }

    public function testResolveFilePathsReturnsEmptyWhenNoneExist(): void
    {
        $result = $this->loader->resolveFilePaths([
            '/nonexistent/a.yaml',
            '/nonexistent/b.yaml',
        ]);

        $this->assertSame([], $result);
    }

    public function testLoadWithNoPathsReturnsEmptyArray(): void
    {
        $data = $this->loader->load([]);

        $this->assertSame([], $data);
    }

    public function testImportStringFormat(): void
    {
        $this->writeYaml('base.yaml', "enabled: true\n");
        $config = $this->writeYaml('config.yaml', "imports:\n  - base.yaml\nregion: global\n");

        $data = $this->loader->load([$config]);

        $this->assertTrue($data['enabled']);
        $this->assertSame('global', $data['region']);
    }
}
