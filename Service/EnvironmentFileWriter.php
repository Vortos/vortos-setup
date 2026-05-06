<?php

declare(strict_types=1);

namespace Vortos\Setup\Service;

final class EnvironmentFileWriter
{
    public function __construct(private readonly string $projectDir) {}

    /** @return array<string, string> */
    public function readKnownValues(): array
    {
        $values = [];

        foreach (['.env', '.env.local'] as $file) {
            $path = $this->projectDir . DIRECTORY_SEPARATOR . $file;

            if (!is_file($path)) {
                continue;
            }

            foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $line) {
                if (!preg_match('/^\s*([A-Z0-9_]+)=(.*)$/', $line, $match)) {
                    continue;
                }

                $values[$match[1]] = $this->unquote(trim($match[2]));
            }
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     * @return array{path: string, written: string[], updated: string[], unchanged: string[], backup: ?string}
     */
    public function writeLocal(array $values, bool $dryRun = false, bool $backup = true): array
    {
        $path = $this->projectDir . DIRECTORY_SEPARATOR . '.env.local';
        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        $lines = $existing === '' ? [] : preg_split('/\R/', rtrim($existing, "\r\n"));
        $lines = is_array($lines) ? $lines : [];
        $index = [];

        foreach ($lines as $line => $content) {
            if (preg_match('/^\s*([A-Z0-9_]+)=/', $content, $match)) {
                $index[$match[1]] = $line;
            }
        }

        $written = [];
        $updated = [];
        $unchanged = [];

        foreach ($values as $key => $value) {
            $entry = $key . '=' . $this->quote($value);

            if (isset($index[$key])) {
                if ($lines[$index[$key]] === $entry) {
                    $unchanged[] = $key;
                    continue;
                }

                $lines[$index[$key]] = $entry;
                $updated[] = $key;
                continue;
            }

            $lines[] = $entry;
            $written[] = $key;
        }

        $backupPath = null;

        if (!$dryRun && ($written !== [] || $updated !== [])) {
            if (is_file($path) && $backup) {
                $backupPath = $path . '.bak.' . date('YmdHis');
                copy($path, $backupPath);
            }

            file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL);
        }

        return [
            'path' => $path,
            'written' => $written,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'backup' => $backupPath,
        ];
    }

    private function quote(string $value): string
    {
        if ($value === '' || preg_match('/[\s#"\']/', $value)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        return $value;
    }

    private function unquote(string $value): string
    {
        if (
            strlen($value) >= 2
            && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))
        ) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
