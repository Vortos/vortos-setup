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

    /**
     * Agnostic env vars this capability needs written when Docker mode is active.
     * The published docker-compose.yaml maps these to vendor-specific names.
     *
     * @return array<string, string>
     */
    public function dockerEnv(string $projectName, string $password): array;
}
