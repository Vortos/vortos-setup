<?php

declare(strict_types=1);

namespace Vortos\Setup\Console;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class TerminalMenu
{
    /**
     * @param string[] $choices
     * @return ?string Null means the caller should use its normal fallback prompt.
     */
    public function choose(
        InputInterface $input,
        OutputInterface $output,
        string $label,
        array $choices,
        string $default,
    ): ?string {
        if (!$this->supports($input, $output) || $choices === []) {
            return null;
        }

        $selected = array_search($default, $choices, true);
        $selected = is_int($selected) ? $selected : 0;

        $stty = $this->stty('-g');
        if ($stty === null) {
            return null;
        }

        $this->stty('-icanon -echo min 1 time 0');
        $output->writeln(sprintf('<info>%s</info>', $label));

        try {
            $rendered = false;

            while (true) {
                if ($rendered) {
                    $this->clear($output, count($choices));
                }

                $this->render($output, $choices, $selected);
                $rendered = true;
                $key = $this->readKey();

                if ($key === "\033[A") {
                    $selected = max(0, $selected - 1);
                } elseif ($key === "\033[B") {
                    $selected = min(count($choices) - 1, $selected + 1);
                } elseif ($key === "\r" || $key === "\n") {
                    $this->clear($output, count($choices));
                    $output->writeln(sprintf('  <info>Selected:</info> %s', $choices[$selected]));

                    return $choices[$selected];
                } elseif ($key === "\003") {
                    return null;
                }
            }
        } finally {
            $this->stty($stty);
        }
    }

    public function supports(InputInterface $input, OutputInterface $output): bool
    {
        if (!$input->isInteractive() || !$output->isDecorated()) {
            return false;
        }

        if (getenv('CI') !== false || PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        return function_exists('stream_isatty') && stream_isatty(STDIN);
    }

    /** @param string[] $choices */
    private function render(OutputInterface $output, array $choices, int $selected): void
    {
        foreach ($choices as $index => $choice) {
            $prefix = $index === $selected ? '<fg=cyan;options=bold>›</>' : ' ';
            $text = $index === $selected ? sprintf('<fg=cyan;options=bold>%s</>', $choice) : $choice;
            $output->writeln(sprintf(' %s %s', $prefix, $text));
        }
    }

    private function clear(OutputInterface $output, int $lines): void
    {
        for ($i = 0; $i < $lines; $i++) {
            $output->write("\033[1A\033[2K");
        }
    }

    private function readKey(): string
    {
        $char = fgetc(STDIN);
        if ($char !== "\033") {
            return $char === false ? '' : $char;
        }

        $next = fgetc(STDIN);
        $last = fgetc(STDIN);

        return "\033" . ($next === false ? '' : $next) . ($last === false ? '' : $last);
    }

    private function stty(string $args): ?string
    {
        $descriptor = [
            0 => STDIN,
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open('stty ' . $args, $descriptor, $pipes);
        if (!is_resource($process)) {
            return null;
        }

        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 ? trim((string) $output) : null;
    }
}
