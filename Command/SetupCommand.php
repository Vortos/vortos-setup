<?php

declare(strict_types=1);

namespace Vortos\Setup\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Docker\Service\DockerFilePublisher;
use Vortos\Setup\Service\EnvironmentFileWriter;
use Vortos\Setup\Service\SetupEnvironmentChecker;
use Vortos\Setup\Service\SetupStateStore;

#[AsCommand(name: 'vortos:setup', description: 'Configure a Vortos project for Docker or local development')]
final class SetupCommand extends Command
{
    /** @var array<string, array<string, mixed>> */
    private const PRESETS = [
        'docker-frankenphp' => [
            'runtime' => 'frankenphp',
            'docker' => true,
            'database' => 'docker-postgres',
            'cache' => 'redis',
            'messaging' => 'kafka',
            'mongo' => true,
        ],
        'docker-phpfpm' => [
            'runtime' => 'phpfpm',
            'docker' => true,
            'database' => 'docker-postgres',
            'cache' => 'redis',
            'messaging' => 'kafka',
            'mongo' => true,
        ],
        'local' => [
            'runtime' => 'local',
            'docker' => false,
            'database' => 'local-postgres',
            'cache' => 'in-memory',
            'messaging' => 'in-memory',
            'mongo' => false,
        ],
        'minimal' => [
            'runtime' => 'local',
            'docker' => false,
            'database' => 'local-postgres',
            'cache' => 'in-memory',
            'messaging' => 'in-memory',
            'mongo' => false,
        ],
    ];

    public function __construct(
        private readonly string $projectDir,
        private readonly SetupStateStore $stateStore,
        private readonly EnvironmentFileWriter $envWriter,
        private readonly SetupEnvironmentChecker $checker,
        private readonly DockerFilePublisher $dockerPublisher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('first-run', null, InputOption::VALUE_NONE, 'Run as Composer first-run setup')
            ->addOption('preset', null, InputOption::VALUE_REQUIRED, 'Preset: docker-frankenphp, docker-phpfpm, local, minimal')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned changes without writing files')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Allow setup to update previous generated values without extra prompts')
            ->addOption('skip-docker-publish', null, InputOption::VALUE_NONE, 'Do not publish Docker files even when a Docker preset is selected')
            ->addOption('publish-migrations', null, InputOption::VALUE_NONE, 'Print migration publish step in the final checklist')
            ->addOption('run-migrations', null, InputOption::VALUE_NONE, 'Print migration run step in the final checklist');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $state = $this->stateStore->read();
        $config = $this->resolveConfig($input, $io, $state);

        $this->banner($io);

        if ($state !== []) {
            $io->note(sprintf(
                'Existing setup state found. Last updated: %s',
                (string) ($state['updated_at'] ?? 'unknown'),
            ));
        }

        $checks = $this->checker->check(
            (bool) $config['docker'],
            $config['cache'] === 'redis',
            (bool) $config['mongo'],
            $config['messaging'] === 'kafka',
        );

        $io->section('Environment checks');
        $io->table(['Check', 'Result', 'Detail'], array_map(
            static fn(array $check): array => [
                $check['name'],
                $check['ok'] ? '<info>OK</info>' : '<comment>Needs attention</comment>',
                $check['detail'],
            ],
            $checks,
        ));

        $envResult = $this->envWriter->writeLocal($this->envValues($config), $dryRun);
        $io->section($dryRun ? 'Planned environment changes' : 'Environment');
        $io->table(['File', 'Written', 'Updated', 'Unchanged'], [[
            $this->relative($envResult['path']),
            implode(', ', $envResult['written']) ?: '-',
            implode(', ', $envResult['updated']) ?: '-',
            implode(', ', $envResult['unchanged']) ?: '-',
        ]]);

        if ($envResult['backup'] !== null) {
            $io->text('Backup: ' . $this->relative($envResult['backup']));
        }

        if ((bool) $config['docker'] && !(bool) $input->getOption('skip-docker-publish')) {
            $io->section($dryRun ? 'Planned Docker publish' : 'Docker publish');
            $result = $this->dockerPublisher->publish((string) $config['runtime'], $this->projectDir, $dryRun);
            $io->table(['Copied', 'Skipped', 'Backups'], [[
                (string) count($result->copied),
                (string) count($result->skipped),
                (string) count($result->backedUp),
            ]]);
        }

        $stateToWrite = [
            'preset' => $config['preset'],
            'runtime' => $config['runtime'],
            'docker' => $config['docker'],
            'database' => $config['database'],
            'cache' => $config['cache'],
            'messaging' => $config['messaging'],
            'mongo' => $config['mongo'],
        ];
        $this->stateStore->write($stateToWrite, $dryRun);

        $io->section('Next steps');
        $io->listing($this->nextSteps($config, $input));

        $io->success($dryRun ? 'Setup dry run complete.' : 'Vortos setup complete.');

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private function resolveConfig(InputInterface $input, SymfonyStyle $io, array $state): array
    {
        $preset = $input->getOption('preset');

        if ($preset !== null) {
            $preset = (string) $preset;
            if (!isset(self::PRESETS[$preset])) {
                throw new \InvalidArgumentException(sprintf('Unknown setup preset "%s".', $preset));
            }

            return ['preset' => $preset] + self::PRESETS[$preset];
        }

        if (!$input->isInteractive()) {
            $preset = (string) ($state['preset'] ?? 'docker-frankenphp');
            return ['preset' => $preset] + self::PRESETS[$preset];
        }

        $defaultPreset = (string) ($state['preset'] ?? 'docker-frankenphp');
        $question = new ChoiceQuestion(
            'Choose your development setup',
            [
                'docker-frankenphp',
                'docker-phpfpm',
                'local',
                'minimal',
            ],
            $defaultPreset,
        );
        $question->setErrorMessage('Preset %s is not valid.');

        /** @var string $selected */
        $selected = $io->askQuestion($question);

        return ['preset' => $selected] + self::PRESETS[$selected];
    }

    /** @param array<string, mixed> $config @return array<string, string> */
    private function envValues(array $config): array
    {
        $current = $this->envWriter->readKnownValues();
        $values = [
            'APP_ENV' => 'dev',
            'APP_DEBUG' => 'true',
            'JWT_SECRET' => $current['JWT_SECRET'] ?? bin2hex(random_bytes(32)),
            'HEALTH_DETAILS' => 'debug',
            'HEALTH_TOKEN' => $current['HEALTH_TOKEN'] ?? bin2hex(random_bytes(24)),
            'HEALTH_EXPOSE_ERRORS' => 'false',
            'VORTOS_CACHE_DRIVER' => $config['cache'] === 'in-memory' ? 'in-memory' : 'redis',
            'VORTOS_MESSAGING_DRIVER' => $config['messaging'] === 'in-memory' ? 'in-memory' : 'kafka',
        ];

        if ((bool) $config['docker']) {
            return $values + [
                'DATABASE_URL' => 'pgsql://postgres:12345@write_db:5432/squaura',
                'POSTGRES_HOST' => 'write_db',
                'POSTGRES_USER' => 'postgres',
                'POSTGRES_PASSWORD' => '12345',
                'POSTGRES_DB' => 'postgres',
                'POSTGRES_DB_NAME' => 'squaura',
                'REDIS_HOST' => 'redis',
                'REDIS_PORT' => '6379',
                'MONGO_HOST' => 'read_db',
                'MONGO_PORT' => '27017',
                'MONGO_INITDB_ROOT_USERNAME' => 'root',
                'MONGO_INITDB_ROOT_PASSWORD' => '12345',
                'MONGO_DB_NAME' => 'squaura',
                'KAFKA_BROKERS' => 'kafka:9092',
                'MESSENGER_TRANSPORT_DSN' => 'kafka://kafka:9092',
                'MESSENGER_TRANSPORT_ASYNC_PRODUCER_DSN' => 'kafka://kafka:9092',
                'MESSENGER_TRANSPORT_ASYNC_CONSUMER_DSN' => 'kafka://kafka:9092',
            ];
        }

        return $values + [
            'DATABASE_URL' => 'pgsql://postgres:postgres@127.0.0.1:5432/vortos',
            'POSTGRES_HOST' => '127.0.0.1',
            'POSTGRES_USER' => 'postgres',
            'POSTGRES_PASSWORD' => 'postgres',
            'POSTGRES_DB' => 'vortos',
            'POSTGRES_DB_NAME' => 'vortos',
            'REDIS_HOST' => '127.0.0.1',
            'REDIS_PORT' => '6379',
            'MONGO_HOST' => '127.0.0.1',
            'MONGO_PORT' => '27017',
            'MONGO_INITDB_ROOT_USERNAME' => 'root',
            'MONGO_INITDB_ROOT_PASSWORD' => 'password',
            'MONGO_DB_NAME' => 'vortos',
            'KAFKA_BROKERS' => '127.0.0.1:9092',
            'MESSENGER_TRANSPORT_DSN' => 'in-memory://default',
        ];
    }

    /** @param array<string, mixed> $config @return string[] */
    private function nextSteps(array $config, InputInterface $input): array
    {
        $steps = [];

        if ((bool) $config['docker']) {
            $steps[] = 'Start services: php vortos up';
        } else {
            $steps[] = 'Start your local PHP server: php -S 127.0.0.1:8000 -t public';
        }

        $steps[] = 'Publish module migrations: php vortos migrate:publish';

        if ((bool) $input->getOption('publish-migrations') || (bool) $input->getOption('run-migrations')) {
            $steps[] = 'Apply migrations: php vortos migrate';
        }

        $steps[] = 'Check readiness: curl http://localhost:8000/health/ready';

        return $steps;
    }

    private function banner(SymfonyStyle $io): void
    {
        $io->writeln('');
        $io->writeln('<fg=cyan;options=bold>Vortos Setup</>');
        $io->writeln('<fg=gray>Cross-platform project setup for Linux, macOS, and Windows.</>');
        $io->writeln('');
    }

    private function relative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', $this->projectDir);

        return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
    }
}
