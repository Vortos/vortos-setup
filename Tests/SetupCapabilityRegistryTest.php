<?php

declare(strict_types=1);

namespace Vortos\Setup\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Setup\Capability\SetupCapabilityRegistry;
use Vortos\Setup\Capability\StaticSetupCapability;

final class SetupCapabilityRegistryTest extends TestCase
{
    public function test_built_in_registry_groups_available_capabilities_by_category(): void
    {
        $registry = SetupCapabilityRegistry::builtIn();

        $runtimeKeys = array_map(
            static fn($capability): string => $capability->key(),
            $registry->byCategory('runtime'),
        );

        $this->assertSame(['runtime.frankenphp', 'runtime.phpfpm', 'runtime.local'], $runtimeKeys);

        $mcpKeys = array_map(
            static fn($capability): string => $capability->key(),
            $registry->byCategory('mcp'),
        );

        $this->assertSame(['mcp.enabled', 'mcp.disabled'], $mcpKeys);

        $observabilityKeys = array_map(
            static fn($capability): string => $capability->key(),
            $registry->byCategory('observability'),
        );

        $this->assertSame(['observability.normal', 'observability.otlp'], $observabilityKeys);
    }

    public function test_capabilities_can_be_filtered_by_availability(): void
    {
        $registry = new SetupCapabilityRegistry([
            new StaticSetupCapability('read_db.none', 'None', 'read_db'),
            new StaticSetupCapability('read_db.mongo', 'MongoDB', 'read_db'),
            new StaticSetupCapability('read_db.mysql', 'MySQL', 'read_db', available: false),
        ]);

        $this->assertSame(['read_db.none', 'read_db.mongo'], array_map(
            static fn($capability): string => $capability->key(),
            $registry->byCategory('read_db'),
        ));
        $this->assertSame(['read_db.none', 'read_db.mongo', 'read_db.mysql'], array_map(
            static fn($capability): string => $capability->key(),
            $registry->byCategory('read_db', availableOnly: false),
        ));
    }

    public function test_duplicate_capability_keys_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate setup capability "cache.redis".');

        new SetupCapabilityRegistry([
            new StaticSetupCapability('cache.redis', 'Redis', 'cache'),
            new StaticSetupCapability('cache.redis', 'Redis duplicate', 'cache'),
        ]);
    }

    public function test_missing_packages_are_collected_once(): void
    {
        $registry = SetupCapabilityRegistry::builtIn();

        $missing = $registry->missingPackagesFor(
            ['runtime.frankenphp', 'runtime.phpfpm', 'cache.redis', 'observability.otlp', 'mcp.enabled'],
            ['vortos/vortos-cache'],
        );

        $this->assertSame([
            'vortos/vortos-docker',
            'open-telemetry/api',
            'open-telemetry/sdk',
            'open-telemetry/exporter-otlp',
            'guzzlehttp/guzzle',
            'vortos/vortos-mcp',
        ], $missing);
    }
}
