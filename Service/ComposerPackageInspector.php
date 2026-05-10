<?php

declare(strict_types=1);

namespace Vortos\Setup\Service;

final class ComposerPackageInspector
{
    public function __construct(private readonly string $projectDir) {}

    /** @return string[] */
    public function installedPackages(): array
    {
        $packages = [];

        foreach ($this->composerFiles() as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (!is_array($data)) {
                continue;
            }

            if (isset($data['packages']) && is_array($data['packages'])) {
                foreach ($data['packages'] as $package) {
                    if (isset($package['name']) && is_string($package['name'])) {
                        $packages[$package['name']] = $package['name'];
                    }
                }
                continue;
            }

            foreach ($data as $package) {
                if (isset($package['name']) && is_string($package['name'])) {
                    $packages[$package['name']] = $package['name'];
                }
            }
        }

        $rootComposer = $this->projectDir . DIRECTORY_SEPARATOR . 'composer.json';
        if (is_file($rootComposer)) {
            $root = json_decode((string) file_get_contents($rootComposer), true);
            foreach (['require', 'require-dev'] as $section) {
                foreach (($root[$section] ?? []) as $package => $_constraint) {
                    if (is_string($package)) {
                        $packages[$package] = $package;
                    }
                }
            }
        }

        sort($packages);

        return array_values($packages);
    }

    /**
     * @param string[] $packages
     * @param string[] $ignorePlatformReqs
     */
    public function requireCommand(array $packages, array $ignorePlatformReqs = []): string
    {
        return 'composer require '
            . implode(' ', [
                ...array_map(
                    static fn(string $requirement): string => '--ignore-platform-req=' . escapeshellarg($requirement),
                    $ignorePlatformReqs,
                ),
                ...array_map('escapeshellarg', $packages),
            ]);
    }

    /**
     * Runs `composer require` for the given packages, streaming output directly
     * to the terminal. Returns true on success.
     *
     * @param string[] $packages
     * @param string[] $ignorePlatformReqs
     */
    public function runRequire(array $packages, array $ignorePlatformReqs = []): bool
    {
        if ($packages === []) {
            return true;
        }

        $args = array_map('escapeshellarg', $packages);
        $ignoreArgs = array_map(
            static fn(string $requirement): string => '--ignore-platform-req=' . escapeshellarg($requirement),
            $ignorePlatformReqs,
        );
        $cmd  = implode(' ', [
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->findComposer()),
            'require',
            '--no-interaction',
            ...$ignoreArgs,
            ...$args,
        ]);

        passthru($cmd, $exitCode);

        return $exitCode === 0;
    }

    private function findComposer(): string
    {
        // Composer sets this when running post-install/post-create-project scripts
        $env = getenv('COMPOSER_BINARY');
        if ($env !== false && $env !== '' && is_file($env)) {
            return $env;
        }

        foreach (['composer.phar', 'composer'] as $name) {
            $path = $this->projectDir . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) {
                return $path;
            }
        }

        // Fall back to composer on $PATH
        return 'composer';
    }

    /** @return string[] */
    private function composerFiles(): array
    {
        return array_values(array_filter([
            $this->projectDir . '/vendor/composer/installed.json',
            $this->projectDir . '/composer.lock',
        ], static fn(string $file): bool => is_file($file)));
    }
}
