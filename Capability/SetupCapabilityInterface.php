<?php

declare(strict_types=1);

namespace Vortos\Setup\Capability;

interface SetupCapabilityInterface
{
    public function key(): string;

    public function label(): string;

    public function category(): string;

    /** @return string[] */
    public function composerPackages(): array;

    public function available(): bool;
}
