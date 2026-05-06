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
            new StaticSetupCapability('write_db.postgres', 'PostgreSQL', 'write_db', ['vortos/vortos-persistence-dbal']),
            new StaticSetupCapability('read_db.mongo', 'MongoDB', 'read_db', ['vortos/vortos-persistence-mongo']),
            new StaticSetupCapability('cache.redis', 'Redis', 'cache', ['vortos/vortos-cache']),
            new StaticSetupCapability('cache.in_memory', 'In-memory cache', 'cache', ['vortos/vortos-cache']),
            new StaticSetupCapability('messaging.kafka', 'Kafka', 'messaging', ['vortos/vortos-messaging']),
            new StaticSetupCapability('messaging.in_memory', 'In-memory messaging', 'messaging', ['vortos/vortos-messaging']),
        ]);
    }

    public function add(SetupCapabilityInterface $capability): void
    {
        if (isset($this->capabilities[$capability->key()])) {
            throw new \InvalidArgumentException(sprintf('Duplicate setup capability "%s".', $capability->key()));
        }

        $this->capabilities[$capability->key()] = $capability;
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
