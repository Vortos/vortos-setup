<?php

declare(strict_types=1);

namespace Vortos\Setup\Capability;

final class StaticSetupCapability implements SetupCapabilityInterface
{
    /** @param string[] $composerPackages */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $category,
        private readonly array $composerPackages = [],
        private readonly bool $available = true,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function composerPackages(): array
    {
        return $this->composerPackages;
    }

    public function available(): bool
    {
        return $this->available;
    }
}
