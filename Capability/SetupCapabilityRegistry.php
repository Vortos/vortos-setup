<?php

declare(strict_types=1);

namespace Vortos\Setup\Capability;

final class SetupCapabilityRegistry
{
    /** @var array<string, SetupCapabilityInterface> */
    private array $capabilities = [];

    /** @param iterable<SetupCapabilityInterface> $capabilities */
    public function __construct(iterable $capabilities = [])
    {
        foreach ($capabilities as $capability) {
            $this->add($capability);
        }
    }

    public static function builtIn(): self
    {
        return new self([
            new StaticSetupCapability('runtime.frankenphp', 'FrankenPHP Docker', 'runtime', ['vortos/vortos-docker']),
            new StaticSetupCapability('runtime.phpfpm', 'PHP-FPM Docker', 'runtime', ['vortos/vortos-docker']),
            new StaticSetupCapability('runtime.local', 'Local PHP', 'runtime'),
            new StaticSetupCapability('write_db.postgres', 'PostgreSQL (DBAL)', 'write_db', ['vortos/vortos-persistence-dbal'],
                dockerEnvFactory: static fn(string $project, string $pwd) => [
                    'VORTOS_WRITE_DB_USER'     => 'postgres',
                    'VORTOS_WRITE_DB_PASSWORD' => $pwd,
                    'VORTOS_WRITE_DB_NAME'     => $project,
                ],
            ),
            new StaticSetupCapability('write_db.postgres_orm', 'PostgreSQL (Doctrine ORM)', 'write_db', ['vortos/vortos-persistence-orm'],
                dockerEnvFactory: static fn(string $project, string $pwd) => [
                    'VORTOS_WRITE_DB_USER'     => 'postgres',
                    'VORTOS_WRITE_DB_PASSWORD' => $pwd,
                    'VORTOS_WRITE_DB_NAME'     => $project,
                ],
            ),
            new StaticSetupCapability('read_db.none', 'None', 'read_db'),
            new StaticSetupCapability('read_db.mongo', 'MongoDB', 'read_db', ['vortos/vortos-persistence-mongo'],
                dockerEnvFactory: static fn(string $project, string $pwd) => [
                    'VORTOS_READ_DB_USER'     => 'root',
                    'VORTOS_READ_DB_PASSWORD' => $pwd,
                ],
            ),
            new StaticSetupCapability('cache.redis', 'Redis', 'cache', ['vortos/vortos-cache']),
            new StaticSetupCapability('cache.in_memory', 'In-memory cache', 'cache', ['vortos/vortos-cache']),
            new StaticSetupCapability('messaging.kafka', 'Kafka', 'messaging', ['vortos/vortos-messaging']),
            new StaticSetupCapability('messaging.in_memory', 'In-memory messaging', 'messaging', ['vortos/vortos-messaging']),
            new StaticSetupCapability('observability.normal', 'Built-in metrics and tracing', 'observability'),
            new StaticSetupCapability('observability.otlp', 'Send metrics and traces to monitoring tools', 'observability', [
                'open-telemetry/api',
                'open-telemetry/sdk',
                'open-telemetry/exporter-otlp',
                'guzzlehttp/guzzle',
            ]),
            new StaticSetupCapability('mcp.enabled', 'Install Vortos MCP server', 'mcp', ['vortos/vortos-mcp']),
            new StaticSetupCapability('mcp.disabled', 'Skip Vortos MCP server', 'mcp'),
        ]);
    }

    public function add(SetupCapabilityInterface $capability): void
    {
        if (isset($this->capabilities[$capability->key()])) {
            throw new \InvalidArgumentException(sprintf('Duplicate setup capability "%s".', $capability->key()));
        }

        $this->capabilities[$capability->key()] = $capability;
    }

    public function has(string $key): bool
    {
        return isset($this->capabilities[$key]);
    }

    public function get(string $key): SetupCapabilityInterface
    {
        if (!isset($this->capabilities[$key])) {
            throw new \InvalidArgumentException(sprintf('Unknown setup capability "%s".', $key));
        }

        return $this->capabilities[$key];
    }

    /** @return SetupCapabilityInterface[] */
    public function all(): array
    {
        return array_values($this->capabilities);
    }

    /** @return SetupCapabilityInterface[] */
    public function byCategory(string $category, bool $availableOnly = true): array
    {
        return array_values(array_filter(
            $this->capabilities,
            static fn(SetupCapabilityInterface $capability): bool => $capability->category() === $category
                && (!$availableOnly || $capability->available()),
        ));
    }

    /** @return string[] */
    public function missingPackagesFor(array $keys, array $installedPackages): array
    {
        $packages = [];
        $installed = array_fill_keys($installedPackages, true);

        foreach ($keys as $key) {
            foreach ($this->get($key)->composerPackages() as $package) {
                if (!isset($installed[$package])) {
                    $packages[$package] = $package;
                }
            }
        }

        return array_values($packages);
    }
}
