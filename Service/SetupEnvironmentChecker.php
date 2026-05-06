<?php

declare(strict_types=1);

namespace Vortos\Setup\Service;

final class SetupEnvironmentChecker
{
    private readonly string $projectDir;

    public function __construct(string $projectDir)
    {
        $this->projectDir = realpath($projectDir) ?: $projectDir;
    }

    /** @return array<int, array{name: string, ok: bool, detail: string}> */
    public function check(bool $dockerSelected, bool $redisSelected, bool $mongoSelected, bool $kafkaSelected): array
    {
        $checks = [
            ['name' => 'PHP >= 8.2', 'ok' => PHP_VERSION_ID >= 80200, 'detail' => PHP_VERSION],
            ['name' => 'JSON extension', 'ok' => extension_loaded('json'), 'detail' => extension_loaded('json') ? 'loaded' : 'missing'],
            ['name' => 'OpenSSL extension', 'ok' => extension_loaded('openssl'), 'detail' => extension_loaded('openssl') ? 'loaded' : 'missing'],
            ['name' => 'Writable project', 'ok' => is_writable($this->projectDir), 'detail' => $this->projectDir],
        ];

        if ($dockerSelected) {
            $checks[] = ['name' => 'Docker CLI', 'ok' => $this->commandExists('docker'), 'detail' => 'required for Docker presets'];
        }

        if ($redisSelected) {
            $checks[] = ['name' => 'Redis PHP extension', 'ok' => extension_loaded('redis') || $dockerSelected, 'detail' => extension_loaded('redis') ? 'loaded' : 'provided by Docker or install ext-redis'];
        }

        if ($mongoSelected) {
            $checks[] = ['name' => 'MongoDB PHP extension', 'ok' => extension_loaded('mongodb') || $dockerSelected, 'detail' => extension_loaded('mongodb') ? 'loaded' : 'provided by Docker or install ext-mongodb'];
        }

        if ($kafkaSelected) {
            $checks[] = ['name' => 'rdkafka PHP extension', 'ok' => extension_loaded('rdkafka') || $dockerSelected, 'detail' => extension_loaded('rdkafka') ? 'loaded' : 'provided by Docker or install ext-rdkafka'];
        }

        return $checks;
    }

    private function commandExists(string $command): bool
    {
        $paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));
        $extensions = PHP_OS_FAMILY === 'Windows'
            ? array_filter(explode(';', (string) getenv('PATHEXT'))) ?: ['.exe', '.bat', '.cmd']
            : [''];

        foreach ($paths as $path) {
            foreach ($extensions as $extension) {
                if (is_file($path . DIRECTORY_SEPARATOR . $command . $extension)) {
                    return true;
                }
            }
        }

        return false;
    }
}
