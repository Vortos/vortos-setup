<?php

declare(strict_types=1);

namespace Vortos\Setup\Service;

final class ComposerPackageInspector
{
    /** @var array<string, string> */
    private const PACKAGE_PLUGIN_ALLOW_LIST = [
        'open-telemetry/sdk' => 'tbachert/spi',
        'open-telemetry/exporter-otlp' => 'php-http/discovery',
    ];

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
     * @param string[] $packages
     * @return string[]
     */
    public function pluginAllowCommandsFor(array $packages): array
    {
        return array_map(
            static fn(string $plugin): string => 'composer config allow-plugins.' . $plugin . ' true',
            $this->pluginsRequiredBy($packages),
        );
    }

    /**
     * Writes Composer allow-plugins config for plugins needed by the packages
     * about to be installed. This prevents Composer from stopping for trust
     * prompts during non-interactive setup.
     *
     * @param string[] $packages
     */
    // public function allowPluginsFor(array $packages): bool
    // {
    //     $plugins = $this->pluginsRequiredBy($packages);
    //     if ($plugins === []) {
    //         return true;
    //     }

    //     foreach ($plugins as $plugin) {
    //         $cmd = implode(' ', [
    //             escapeshellarg(PHP_BINARY),
    //             escapeshellarg($this->findComposer()),
    //             'config',
    //             '--no-interaction',
    //             escapeshellarg('allow-plugins.' . $plugin),
    //             'true',
    //         ]);

    //         passthru($cmd, $exitCode);
    //         if ($exitCode !== 0) {
    //             return false;
    //         }
    //     }

    //     return true;
    // }
    public function allowPluginsFor(array $packages): bool
    {
        $plugins = $this->pluginsRequiredBy($packages);
        if ($plugins === []) {
            return true;
        }

        $base = $this->buildComposerArgv($this->findComposer());

        foreach ($plugins as $plugin) {
            if (!$this->execComposer([...$base, 'config', '--no-interaction', 'allow-plugins.' . $plugin, 'true'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Runs `composer require` for the given packages, streaming output directly
     * to the terminal. Returns true on success.
     *
     * @param string[] $packages
     * @param string[] $ignorePlatformReqs
     */
    // public function runRequire(array $packages, array $ignorePlatformReqs = []): bool
    // {
    //     if ($packages === []) {
    //         return true;
    //     }

    //     $args = array_map('escapeshellarg', $packages);
    //     $ignoreArgs = array_map(
    //         static fn(string $requirement): string => '--ignore-platform-req=' . escapeshellarg($requirement),
    //         $ignorePlatformReqs,
    //     );
    //     $cmd  = implode(' ', [
    //         escapeshellarg(PHP_BINARY),
    //         escapeshellarg($this->findComposer()),
    //         'require',
    //         '--no-interaction',
    //         ...$ignoreArgs,
    //         ...$args,
    //     ]);

    //     passthru($cmd, $exitCode);

    //     return $exitCode === 0;
    // }
    public function runRequire(array $packages, array $ignorePlatformReqs = []): bool
    {
        if ($packages === []) {
            return true;
        }

        $base = $this->buildComposerArgv($this->findComposer());
        $ignoreArgs = array_map(
            static fn(string $r): string => '--ignore-platform-req=' . $r,
            $ignorePlatformReqs,
        );

        return $this->execComposer([...$base, 'require', '--no-interaction', ...$ignoreArgs, ...$packages]);
    }

    /** @param string[] $argv */
    private function execComposer(array $argv): bool
    {
        // On Windows, proc_open with an argv array cannot invoke .bat files — CreateProcess
        // requires a shell. Strip any cmd /c wrapper added by buildComposerArgv(), then build
        // a flat escaped string and pass it as a single command string so Windows handles it.
        if (PHP_OS_FAMILY === 'Windows') {
            if (($argv[0] ?? null) === 'cmd' && ($argv[1] ?? null) === '/c') {
                $argv = array_slice($argv, 2);
            }
            $bin    = array_shift($argv);
            $argv   = array_map('escapeshellarg', $argv);
            $command = implode(' ', [$bin, ...$argv]);
        } else {
            $command = $argv;
        }

        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes);

        if (!is_resource($process)) {
            return false;
        }

        return proc_close($process) === 0;
    }

    /** @return string[] */
    private function buildComposerArgv(string $composer): array
    {
        if (str_ends_with(strtolower($composer), '.phar')) {
            return [PHP_BINARY, $composer];
        }

        // On Windows, .bat/.cmd files and bare PATH names (e.g. "composer") need
        // cmd.exe — CreateProcess does not resolve PATHEXT extensions on its own.
        if (PHP_OS_FAMILY === 'Windows') {
            if (
                preg_match('/\.(bat|cmd)$/i', $composer) === 1
                || (!str_contains($composer, '/') && !str_contains($composer, '\\'))
            ) {
                return ['cmd', '/c', $composer];
            }
        }

        return [$composer];
    }

    private function findComposer(): string
    {
        // Composer sets this when running post-install/post-create-project scripts.
        // On Windows, skip it when the path contains spaces — proc_open (CreateProcess) does
        // not reliably handle spaced paths even in array mode, so we fall through to PATH.
        $env = getenv('COMPOSER_BINARY');
        if ($env !== false && $env !== '' && is_file($env)) {
            if (PHP_OS_FAMILY !== 'Windows' || !str_contains($env, ' ')) {
                return $env;
            }
        }

        // On Windows, prefer composer.bat explicitly — proc_open with a string command handles
        // .bat files correctly via CreateProcess, while the bare "composer" name is an extensionless
        // shell script that only works under Git Bash / WSL, not under cmd.exe.
        if (PHP_OS_FAMILY === 'Windows') {
            return 'composer.bat';
        }

        foreach (['composer.phar', 'composer'] as $name) {
            $path = $this->projectDir . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) {
                return $path;
            }
        }

        return 'composer';
    }

    /**
     * @param string[] $packages
     * @return string[]
     */
    private function pluginsRequiredBy(array $packages): array
    {
        $plugins = [];
        $selected = array_fill_keys($packages, true);

        foreach (self::PACKAGE_PLUGIN_ALLOW_LIST as $package => $plugin) {
            if (isset($selected[$package])) {
                $plugins[$plugin] = $plugin;
            }
        }

        sort($plugins);

        return array_values($plugins);
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
