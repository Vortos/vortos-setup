<?php

declare(strict_types=1);

namespace Vortos\Setup\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Foundation\Contract\PackageInterface;

final class SetupPackage implements PackageInterface
{
    public function build(ContainerBuilder $container): void {}

    public function getContainerExtension(): SetupExtension
    {
        return new SetupExtension();
    }
}
