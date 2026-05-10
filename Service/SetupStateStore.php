<?php

declare(strict_types=1);

namespace Vortos\Setup\Service;

final class SetupStateStore
{
    private const FILE = '.vortos-setup.json';

    public function __construct(private readonly string $projectDir) {}

    /** @return array<string, mixed> */
    public function read(): array
    {
        $path = $this->path();

        if (!is_file($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $state */
    public function write(array $state, bool $dryRun = false): void
    {
        if ($dryRun) {
            return;
        }

        $state['updated_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        file_put_contents($this->path(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    public function path(): string
    {
        return $this->projectDir . DIRECTORY_SEPARATOR . self::FILE;
    }
}
