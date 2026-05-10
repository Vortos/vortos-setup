<?php

declare(strict_types=1);

namespace Vortos\Setup\Capability;

final class StaticSetupCapability implements SetupCapabilityInterface
{
    /**
     * @param string[] $composerPackages
     * @param \Closure(string, string): array<string, string>|null $dockerEnvFactory
     * @param array<string, string> $dockerEnvTemplate
     */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $category,
        private readonly array $composerPackages = [],
        private readonly bool $available = true,
        private readonly ?\Closure $dockerEnvFactory = null,
        private readonly array $dockerEnvTemplate = [],
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

    public function dockerEnv(string $projectName, string $password): array
    {
        if ($this->dockerEnvFactory !== null) {
            return ($this->dockerEnvFactory)($projectName, $password);
        }

        $values = [];
        foreach ($this->dockerEnvTemplate as $key => $value) {
            $values[$key] = strtr($value, [
                '{project}' => $projectName,
                '{password}' => $password,
            ]);
        }

        return $values;
    }
}
