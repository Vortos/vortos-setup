<?php

declare(strict_types=1);

namespace Vortos\Setup\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Setup\Service\ComposerPackageInspector;

final class ComposerPackageInspectorTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/vortos_composer_project_' . uniqid('', true);
        mkdir($this->projectDir . '/vendor/composer', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
    }

    public function test_installed_packages_are_read_from_root_and_installed_json(): void
    {
        file_put_contents($this->projectDir . '/composer.json', json_encode([
            'require' => [
                'vortos/vortos-framework' => '^1.0',
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->projectDir . '/vendor/composer/installed.json', json_encode([
            'packages' => [
                ['name' => 'vortos/vortos-cache'],
            ],
        ], JSON_THROW_ON_ERROR));

        $packages = (new ComposerPackageInspector($this->projectDir))->installedPackages();

        $this->assertContains('vortos/vortos-framework', $packages);
        $this->assertContains('vortos/vortos-cache', $packages);
    }

    public function test_require_command_quotes_packages(): void
    {
        $command = (new ComposerPackageInspector($this->projectDir))->requireCommand([
            'vortos/vortos-docker',
            'vortos/vortos-cache',
        ]);

        $this->assertSame("composer require 'vortos/vortos-docker' 'vortos/vortos-cache'", $command);
    }

    public function test_require_command_can_ignore_platform_requirements(): void
    {
        $command = (new ComposerPackageInspector($this->projectDir))->requireCommand(
            ['vortos/vortos-persistence-mongo'],
            ['ext-mongodb'],
        );

        $this->assertSame(
            "composer require --ignore-platform-req='ext-mongodb' 'vortos/vortos-persistence-mongo'",
            $command,
        );
    }

    public function test_plugin_allow_commands_are_reported_for_otlp_packages(): void
    {
        $commands = (new ComposerPackageInspector($this->projectDir))->pluginAllowCommandsFor([
            'open-telemetry/api',
            'open-telemetry/sdk',
            'open-telemetry/exporter-otlp',
            'guzzlehttp/guzzle',
        ]);

        $this->assertSame([
            'composer config allow-plugins.php-http/discovery true',
            'composer config allow-plugins.tbachert/spi true',
        ], $commands);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
