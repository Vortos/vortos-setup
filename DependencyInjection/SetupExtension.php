<?php

declare(strict_types=1);

namespace Vortos\Setup\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Docker\Service\DockerFilePublisher;
use Vortos\Setup\Command\SetupCommand;
use Vortos\Setup\Console\TerminalMenu;
use Vortos\Setup\Service\ComposerPackageInspector;
use Vortos\Setup\Service\EnvironmentFileWriter;
use Vortos\Setup\Service\SetupEnvironmentChecker;
use Vortos\Setup\Service\SetupStateStore;

final class SetupExtension extends Extension
{
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

        $container->register(SetupCommand::class, SetupCommand::class)
            ->setArgument('$projectDir', $projectDir)
            ->setArgument('$stateStore', new Reference(SetupStateStore::class))
            ->setArgument('$envWriter', new Reference(EnvironmentFileWriter::class))
            ->setArgument('$checker', new Reference(SetupEnvironmentChecker::class))
            ->setArgument('$dockerPublisher', new Reference(DockerFilePublisher::class))
            ->setArgument('$terminalMenu', new Reference(TerminalMenu::class))
            ->setPublic(true)
            ->addTag('console.command');
    }
}
