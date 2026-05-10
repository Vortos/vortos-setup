<?php

declare(strict_types=1);

namespace Vortos\Setup\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Config\Service\ConfigFilePublisher;
use Vortos\Docker\Service\DockerFilePublisher;
use Vortos\Setup\Capability\SetupCapabilityRegistry;
use Vortos\Setup\Capability\StaticSetupCapability;
use Vortos\Setup\Command\SetupCommand;
use Vortos\Setup\Console\TerminalMenu;
use Vortos\Setup\Service\ComposerPackageInspector;
use Vortos\Setup\Service\EnvironmentFileWriter;
use Vortos\Setup\Service\SetupEnvironmentChecker;
use Vortos\Setup\Service\SetupStateStore;

final class SetupExtension extends Extension
{
    private const CAPABILITY_TAG = 'vortos.setup_capability';

    /** @var array<string, array{key: string, label: string, category: string, packages: string[], docker_env?: array<string, string>}> */
    private const BUILT_IN_CAPABILITIES = [
        'vortos.setup_capability.runtime.frankenphp' => [
            'key' => 'runtime.frankenphp',
            'label' => 'FrankenPHP Docker',
            'category' => 'runtime',
            'packages' => ['vortos/vortos-docker'],
        ],
        'vortos.setup_capability.runtime.phpfpm' => [
            'key' => 'runtime.phpfpm',
            'label' => 'PHP-FPM Docker',
            'category' => 'runtime',
            'packages' => ['vortos/vortos-docker'],
        ],
        'vortos.setup_capability.runtime.local' => [
            'key' => 'runtime.local',
            'label' => 'Local PHP',
            'category' => 'runtime',
            'packages' => [],
        ],
        'vortos.setup_capability.write_db.postgres' => [
            'key' => 'write_db.postgres',
            'label' => 'PostgreSQL (DBAL)',
            'category' => 'write_db',
            'packages' => ['vortos/vortos-persistence-dbal'],
            'docker_env' => [
                'VORTOS_WRITE_DB_USER' => 'postgres',
                'VORTOS_WRITE_DB_PASSWORD' => '{password}',
                'VORTOS_WRITE_DB_NAME' => '{project}',
            ],
        ],
        'vortos.setup_capability.write_db.postgres_orm' => [
            'key' => 'write_db.postgres_orm',
            'label' => 'PostgreSQL (Doctrine ORM)',
            'category' => 'write_db',
            'packages' => ['vortos/vortos-persistence-orm'],
            'docker_env' => [
                'VORTOS_WRITE_DB_USER' => 'postgres',
                'VORTOS_WRITE_DB_PASSWORD' => '{password}',
                'VORTOS_WRITE_DB_NAME' => '{project}',
            ],
        ],
        'vortos.setup_capability.read_db.none' => [
            'key' => 'read_db.none',
            'label' => 'None',
            'category' => 'read_db',
            'packages' => [],
        ],
        'vortos.setup_capability.read_db.mongo' => [
            'key' => 'read_db.mongo',
            'label' => 'MongoDB',
            'category' => 'read_db',
            'packages' => ['vortos/vortos-persistence-mongo'],
            'docker_env' => [
                'VORTOS_READ_DB_USER' => 'root',
                'VORTOS_READ_DB_PASSWORD' => '{password}',
            ],
        ],
        'vortos.setup_capability.cache.redis' => [
            'key' => 'cache.redis',
            'label' => 'Redis',
            'category' => 'cache',
            'packages' => ['vortos/vortos-cache'],
        ],
        'vortos.setup_capability.cache.in_memory' => [
            'key' => 'cache.in_memory',
            'label' => 'In-memory cache',
            'category' => 'cache',
            'packages' => ['vortos/vortos-cache'],
        ],
        'vortos.setup_capability.messaging.kafka' => [
            'key' => 'messaging.kafka',
            'label' => 'Kafka',
            'category' => 'messaging',
            'packages' => ['vortos/vortos-messaging'],
        ],
        'vortos.setup_capability.messaging.in_memory' => [
            'key' => 'messaging.in_memory',
            'label' => 'In-memory messaging',
            'category' => 'messaging',
            'packages' => ['vortos/vortos-messaging'],
        ],
        'vortos.setup_capability.mcp.enabled' => [
            'key' => 'mcp.enabled',
            'label' => 'Install Vortos MCP server',
            'category' => 'mcp',
            'packages' => ['vortos/vortos-mcp'],
        ],
        'vortos.setup_capability.mcp.disabled' => [
            'key' => 'mcp.disabled',
            'label' => 'Skip Vortos MCP server',
            'category' => 'mcp',
            'packages' => [],
        ],
    ];

    public function getAlias(): string
    {
        return 'vortos_setup';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $projectDir = (string) $container->getParameter('kernel.project_dir');
        $projectDir = realpath($projectDir) ?: $projectDir;

        $container->register(SetupStateStore::class, SetupStateStore::class)
            ->setArgument('$projectDir', $projectDir)
            ->setPublic(false);

        $container->register(EnvironmentFileWriter::class, EnvironmentFileWriter::class)
            ->setArgument('$projectDir', $projectDir)
            ->setPublic(false);

        $container->register(SetupEnvironmentChecker::class, SetupEnvironmentChecker::class)
            ->setArgument('$projectDir', $projectDir)
            ->setPublic(false);

        $container->register(ComposerPackageInspector::class, ComposerPackageInspector::class)
            ->setArgument('$projectDir', $projectDir)
            ->setPublic(false);

        $container->register(TerminalMenu::class, TerminalMenu::class)
            ->setPublic(false);

        $this->registerBuiltInCapabilities($container);

        $container->register(SetupCapabilityRegistry::class, SetupCapabilityRegistry::class)
            ->setArgument('$capabilities', new TaggedIteratorArgument(self::CAPABILITY_TAG))
            ->setPublic(false);

        $container->register(SetupCommand::class, SetupCommand::class)
            ->setArgument('$projectDir', $projectDir)
            ->setArgument('$stateStore', new Reference(SetupStateStore::class))
            ->setArgument('$envWriter', new Reference(EnvironmentFileWriter::class))
            ->setArgument('$checker', new Reference(SetupEnvironmentChecker::class))
            ->setArgument('$dockerPublisher', new Reference(DockerFilePublisher::class))
            ->setArgument('$configPublisher', new Reference(ConfigFilePublisher::class))
            ->setArgument('$terminalMenu', new Reference(TerminalMenu::class))
            ->setArgument('$capabilityRegistry', new Reference(SetupCapabilityRegistry::class))
            ->setArgument('$packageInspector', new Reference(ComposerPackageInspector::class))
            ->setPublic(true)
            ->addTag('console.command');
    }

    private function registerBuiltInCapabilities(ContainerBuilder $container): void
    {
        foreach (self::BUILT_IN_CAPABILITIES as $id => $capability) {
            $container->register($id, StaticSetupCapability::class)
                ->setArgument('$key', $capability['key'])
                ->setArgument('$label', $capability['label'])
                ->setArgument('$category', $capability['category'])
                ->setArgument('$composerPackages', $capability['packages'])
                ->setArgument('$dockerEnvTemplate', $capability['docker_env'] ?? [])
                ->addTag(self::CAPABILITY_TAG)
                ->setPublic(false);
        }
    }
}
