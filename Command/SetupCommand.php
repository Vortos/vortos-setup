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
use Vortos\Config\Service\ConfigFilePublisher;
use Vortos\Docker\Service\DockerFilePublisher;
use Vortos\Docker\Service\DockerPublishResult;
use Vortos\Setup\Capability\SetupCapabilityInterface;
use Vortos\Setup\Capability\SetupCapabilityRegistry;
use Vortos\Setup\Console\TerminalMenu;
use Vortos\Setup\Service\ComposerPackageInspector;
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

    private const PROFILES = [
        'minimal' => 'minimal',
        'docker' => 'docker-frankenphp',
    ];

    public function __construct(
        private readonly string $projectDir,
        private readonly SetupStateStore $stateStore,
        private readonly EnvironmentFileWriter $envWriter,
        private readonly SetupEnvironmentChecker $checker,
        private readonly DockerFilePublisher $dockerPublisher,
        private readonly ?ConfigFilePublisher $configPublisher = null,
        private readonly ?TerminalMenu $terminalMenu = null,
        private readonly ?SetupCapabilityRegistry $capabilityRegistry = null,
        private readonly ?ComposerPackageInspector $packageInspector = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('first-run', null, InputOption::VALUE_NONE, 'Run as Composer first-run setup')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Profile: minimal, docker, custom')
            ->addOption('preset', null, InputOption::VALUE_REQUIRED, 'Preset: docker-frankenphp, docker-phpfpm, local, minimal')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned changes without writing files')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Allow setup to update previous generated values without extra prompts')
            ->addOption('regenerate-secrets', null, InputOption::VALUE_NONE, 'Generate new local secrets and service passwords')
            ->addOption('skip-docker-publish', null, InputOption::VALUE_NONE, 'Do not publish Docker files even when a Docker preset is selected')
            ->addOption('no-docker-backup', null, InputOption::VALUE_NONE, 'Overwrite Docker files without creating .bak copies')
            ->addOption('no-docker-overwrite', null, InputOption::VALUE_NONE, 'Skip changed Docker files instead of overwriting them')
            ->addOption('publish-migrations', null, InputOption::VALUE_NONE, 'Print migration publish step in the final checklist')
            ->addOption('run-migrations', null, InputOption::VALUE_NONE, 'Print migration run step in the final checklist');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $state = $this->stateStore->read();

        $this->banner($input, $output, $io);

        try {
            $config = $this->resolveConfig($input, $output, $io, $state);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($state !== []) {
            $io->note(sprintf(
                'Existing setup state found. Last updated: %s',
                (string) ($state['updated_at'] ?? 'unknown'),
            ));
        }

        $confirmedConfig = $this->confirmPlan($input, $output, $io, $config, $dryRun);
        if ($confirmedConfig === null) {
            $io->warning('Setup cancelled. No files were changed.');
            return Command::SUCCESS;
        }
        $config = $confirmedConfig;

        $checks = $this->checker->check(
            (bool) $config['docker'],
            $config['cache'] === 'redis',
            (bool) $config['mongo'],
            $config['messaging'] === 'kafka',
        );

        $this->renderChecks($io, $checks);

        $current = $this->envWriter->readKnownValues();
        $envResult = $this->envWriter->write(
            ['APP_NAME' => $this->resolvedProjectName($current)]
            + $this->envValues($config, (bool) $input->getOption('regenerate-secrets')),
            $dryRun,
        );
        $this->renderEnvironmentResult($io, $envResult, $dryRun);

        if ((bool) $config['docker'] && !(bool) $input->getOption('skip-docker-publish')) {
            try {
                $result = $this->dockerPublisher->publish(
                    (string) $config['runtime'],
                    $this->projectDir,
                    $dryRun,
                    !(bool) $input->getOption('no-docker-backup'),
                    !(bool) $input->getOption('no-docker-overwrite'),
                );
            } catch (\InvalidArgumentException $e) {
                $io->error($e->getMessage());
                return Command::FAILURE;
            }

            $this->renderDockerResult($io, $result, $dryRun);
        }

        if ($this->configPublisher !== null) {
            $configResult = $this->configPublisher->publish($this->projectDir, dryRun: $dryRun);
            $this->renderConfigResult($io, $configResult, $dryRun);
        }

        $this->installMissingPackages($config, $io, $dryRun);

        $stateToWrite = [
            'profile' => $config['profile'] ?? null,
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
    private function resolveConfig(InputInterface $input, OutputInterface $output, SymfonyStyle $io, array $state): array
    {
        $preset = $input->getOption('preset');
        $profile = $input->getOption('profile');

        if ($preset !== null && $profile !== null) {
            throw new \InvalidArgumentException('Use either --profile or --preset, not both.');
        }

        if ($profile !== null) {
            $profile = (string) $profile;

            if ($profile === 'custom') {
                if (!$input->isInteractive()) {
                    throw new \InvalidArgumentException('The custom profile requires interactive setup. Use --preset for non-interactive setup.');
                }

                return $this->askCustomConfig($input, $output, $io, $this->configFromPreset((string) ($state['preset'] ?? 'docker-frankenphp')));
            }

            if (!isset(self::PROFILES[$profile])) {
                throw new \InvalidArgumentException(sprintf('Unknown setup profile "%s".', $profile));
            }

            $preset = self::PROFILES[$profile];

            return ['preset' => $preset, 'profile' => $profile] + self::PRESETS[$preset];
        }

        if ($preset !== null) {
            $preset = (string) $preset;
            if (!isset(self::PRESETS[$preset])) {
                throw new \InvalidArgumentException(sprintf('Unknown setup preset "%s".', $preset));
            }

            return ['preset' => $preset, 'profile' => null] + self::PRESETS[$preset];
        }

        if (!$input->isInteractive()) {
            $preset = (string) ($state['preset'] ?? 'docker-frankenphp');
            return ['preset' => $preset, 'profile' => null] + self::PRESETS[$preset];
        }

        return $this->askProfile($input, $output, $io, (string) ($state['profile'] ?? 'docker'));
    }

    /** @return array<string, mixed> */
    private function askProfile(InputInterface $input, OutputInterface $output, SymfonyStyle $io, string $defaultProfile): array
    {
        $choices = ['docker', 'minimal', 'custom'];
        $defaultProfile = in_array($defaultProfile, $choices, true) ? $defaultProfile : 'docker';

        $selected = ($this->terminalMenu ?? new TerminalMenu())->choose(
            $input,
            $output,
            'Choose setup profile',
            $choices,
            $defaultProfile,
        );

        if ($selected === null) {
            $question = new ChoiceQuestion('Choose setup profile', $choices, $defaultProfile);
            $question->setErrorMessage('Profile %s is not valid.');

            /** @var string $selected */
            $selected = $io->askQuestion($question);
        }

        if ($selected === 'custom') {
            return $this->askCustomConfig($input, $output, $io, $this->configFromPreset('docker-frankenphp'));
        }

        $preset = self::PROFILES[$selected];

        return ['preset' => $preset, 'profile' => $selected] + self::PRESETS[$preset];
    }

    /** @return array<string, mixed> */
    private function askPreset(InputInterface $input, OutputInterface $output, SymfonyStyle $io, string $defaultPreset): array
    {
        $choices = array_keys(self::PRESETS);
        $selected = ($this->terminalMenu ?? new TerminalMenu())->choose(
            $input,
            $output,
            'Choose your development setup',
            $choices,
            $defaultPreset,
        );

        if ($selected !== null) {
            return ['preset' => $selected, 'profile' => 'custom'] + self::PRESETS[$selected];
        }

        $question = new ChoiceQuestion('Choose your development setup', array_keys(self::PRESETS), $defaultPreset);
        $question->setErrorMessage('Preset %s is not valid.');

        /** @var string $selected */
        $selected = $io->askQuestion($question);

        return ['preset' => $selected, 'profile' => 'custom'] + self::PRESETS[$selected];
    }

    /** @return array<string, mixed> */
    private function askCustomConfig(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        array $config,
    ): array {
        $runtime = $this->capabilityValue($this->askCapability(
            $input,
            $output,
            $io,
            'runtime',
            'Choose runtime',
            'runtime.' . (string) $config['runtime'],
        ));
        $docker = $runtime !== 'local';
        $writeDatabase = $this->capabilityValue($this->askCapability(
            $input,
            $output,
            $io,
            'write_db',
            'Choose write database',
            'write_db.postgres',
        ));
        $database = $docker ? 'docker-' . $writeDatabase : 'local-' . $writeDatabase;

        $readDatabase = $this->capabilityValue($this->askCapability(
            $input,
            $output,
            $io,
            'read_db',
            'Choose read database',
            (bool) $config['mongo'] ? 'read_db.mongo' : 'read_db.none',
        ));
        $cache = $this->capabilityValue($this->askCapability(
            $input,
            $output,
            $io,
            'cache',
            'Choose cache',
            'cache.' . str_replace('-', '_', (string) $config['cache']),
        ));
        $messaging = $this->capabilityValue($this->askCapability(
            $input,
            $output,
            $io,
            'messaging',
            'Choose messaging',
            'messaging.' . str_replace('-', '_', (string) $config['messaging']),
        ));

        return [
            'preset' => $this->presetForRuntime($runtime),
            'profile' => 'custom',
            'runtime' => $runtime,
            'docker' => $docker,
            'database' => $database,
            'cache' => $cache,
            'messaging' => $messaging,
            'mongo' => $readDatabase === 'mongo',
        ];
    }

    private function askCapability(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $category,
        string $label,
        string $default,
    ): string {
        $capabilities = $this->capabilities()->byCategory($category);
        if ($capabilities === []) {
            throw new \InvalidArgumentException(sprintf('No setup capabilities are available for "%s".', $category));
        }

        $choices = [];
        foreach ($capabilities as $capability) {
            $choices[$capability->key()] = $this->choiceLabel($capability);
        }

        return $this->askOption($input, $output, $io, $label, $choices, $default);
    }

    /**
     * @param array<string, string> $choices
     */
    private function askOption(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        string $label,
        array $choices,
        string $default,
    ): string {
        $keys = array_keys($choices);
        $default = in_array($default, $keys, true) ? $default : $keys[0];

        $selected = ($this->terminalMenu ?? new TerminalMenu())->choose(
            $input,
            $output,
            $label,
            array_map(
                static fn(string $key, string $description): string => sprintf('%s - %s', $key, $description),
                $keys,
                array_values($choices),
            ),
            sprintf('%s - %s', $default, $choices[$default]),
        );

        if ($selected === null) {
            $question = new ChoiceQuestion(
                $label,
                array_map(
                    static fn(string $key, string $description): string => sprintf('%s - %s', $key, $description),
                    $keys,
                    array_values($choices),
                ),
                array_search($default, $keys, true) ?: 0,
            );
            $question->setErrorMessage('Option %s is not valid.');

            /** @var string $selected */
            $selected = $io->askQuestion($question);
        }

        return substr($selected, 0, (int) strpos($selected, ' - '));
    }

    private function choiceLabel(SetupCapabilityInterface $capability): string
    {
        $packages = $capability->composerPackages();

        return $capability->label() . ($packages === [] ? '' : sprintf(' (%s)', implode(', ', $packages)));
    }

    private function capabilityValue(string $key): string
    {
        [, $value] = explode('.', $key, 2);

        return str_replace('_', '-', $value);
    }

    private function capabilities(): SetupCapabilityRegistry
    {
        return $this->capabilityRegistry ?? SetupCapabilityRegistry::builtIn();
    }

    /**
     * @param array<string, mixed> $config
     * @return ?array<string, mixed>
     */
    private function confirmPlan(
        InputInterface $input,
        OutputInterface $output,
        SymfonyStyle $io,
        array $config,
        bool $dryRun,
    ): ?array
    {
        if (!$input->isInteractive() || $input->getOption('preset') !== null) {
            $this->renderPlan($io, $config, $dryRun);
            return $config;
        }

        while (true) {
            $this->renderPlan($io, $config, $dryRun);

            $question = new ChoiceQuestion('Review setup', ['Continue', 'Customize', 'Cancel'], 'Continue');
            $question->setErrorMessage('Action %s is not valid.');

            /** @var string $action */
            $action = $io->askQuestion($question);

            if ($action === 'Continue') {
                return $config;
            }

            if ($action === 'Cancel') {
                return null;
            }

            $config = $this->askCustomConfig($input, $output, $io, $config);
            $io->newLine();
        }
    }

    /** @param array<string, mixed> $config */
    private function renderPlan(SymfonyStyle $io, array $config, bool $dryRun): void
    {
        $io->section($dryRun ? 'Setup plan (dry run)' : 'Setup plan');
        if (($config['profile'] ?? null) !== null) {
            $io->writeln(sprintf('  Profile:   <info>%s</info>', (string) $config['profile']));
        }
        $io->writeln(sprintf('  Preset:    <info>%s</info>', (string) $config['preset']));
        $io->writeln(sprintf('  Runtime:   %s', (string) $config['runtime']));
        $io->writeln(sprintf('  Write DB:  %s', (string) $config['database']));
        $io->writeln(sprintf('  Read DB:   %s', (bool) $config['mongo'] ? 'mongo' : 'none'));
        $io->writeln(sprintf('  Cache:     %s', (string) $config['cache']));
        $io->writeln(sprintf('  Messaging: %s', (string) $config['messaging']));
        $io->writeln(sprintf('  Docker:    %s', (bool) $config['docker'] ? 'yes' : 'no'));
    }

    /** @return array<string, mixed> */
    private function configFromPreset(string $preset): array
    {
        $preset = isset(self::PRESETS[$preset]) ? $preset : 'docker-frankenphp';

        return ['preset' => $preset, 'profile' => 'custom'] + self::PRESETS[$preset];
    }

    private function presetForRuntime(string $runtime): string
    {
        return match ($runtime) {
            'frankenphp' => 'docker-frankenphp',
            'phpfpm' => 'docker-phpfpm',
            default => 'local',
        };
    }

    /** @param array<string, mixed> $config @return array<string, string> */
    private function envValues(array $config, bool $regenerateSecrets = false): array
    {
        $current = $this->envWriter->readKnownValues();
        $projectName = $this->resolvedProjectName($current);

        // Migrate passwords from old vendor-specific key names on repeat runs
        if (!$regenerateSecrets) {
            if (!isset($current['VORTOS_WRITE_DB_PASSWORD'])) {
                $current['VORTOS_WRITE_DB_PASSWORD'] =
                    $current['POSTGRES_PASSWORD']
                    ?? $this->dsnPassword((string) ($current['VORTOS_WRITE_DB_DSN'] ?? ''))
                    ?? null;
            }
            if (!isset($current['VORTOS_READ_DB_PASSWORD'])) {
                $current['VORTOS_READ_DB_PASSWORD'] =
                    $current['MONGO_INITDB_ROOT_PASSWORD']
                    ?? $this->dsnPassword((string) ($current['VORTOS_READ_DB_DSN'] ?? ''))
                    ?? null;
            }
        }

        $writeDbPassword = $this->secret('VORTOS_WRITE_DB_PASSWORD', $current, $regenerateSecrets, 16);
        $readDbPassword  = $this->secret('VORTOS_READ_DB_PASSWORD', $current, $regenerateSecrets, 16);
        $writeDbHost     = (bool) $config['docker'] ? 'write_db' : '127.0.0.1';
        $readDbHost      = (bool) $config['docker'] ? 'read_db' : '127.0.0.1';
        $messagingDsn    = $config['messaging'] === 'in-memory'
            ? 'in-memory://default'
            : sprintf('kafka://%s', (bool) $config['docker'] ? 'kafka:9092' : '127.0.0.1:9092');

        $values = [
            'APP_ENV'              => 'dev',
            'APP_DEBUG'            => 'true',
            'JWT_SECRET'           => $this->secret('JWT_SECRET', $current, $regenerateSecrets, 32),
            'HEALTH_DETAILS'       => 'debug',
            'HEALTH_TOKEN'         => $this->secret('HEALTH_TOKEN', $current, $regenerateSecrets, 24),
            'HEALTH_EXPOSE_ERRORS' => 'false',
            'VORTOS_WRITE_DB_DRIVER' => 'postgres',
            'VORTOS_WRITE_DB_DSN'    => sprintf('pgsql://postgres:%s@%s:5432/%s', $writeDbPassword, $writeDbHost, $projectName),
            'VORTOS_READ_DB_DRIVER'  => (bool) $config['mongo'] ? 'mongo' : 'none',
            'VORTOS_READ_DB_DSN'     => (bool) $config['mongo'] ? sprintf('mongodb://root:%s@%s:27017', $readDbPassword, $readDbHost) : '',
            'VORTOS_READ_DB_NAME'    => (bool) $config['mongo'] ? $projectName : '',
            'VORTOS_CACHE_DRIVER'    => $config['cache'] === 'in-memory' ? 'in-memory' : 'redis',
            'VORTOS_CACHE_DSN'       => sprintf('redis://%s:6379', (bool) $config['docker'] ? 'redis' : '127.0.0.1'),
            'VORTOS_CACHE_PREFIX'    => ($_ENV['APP_ENV'] ?? 'dev') . '_' . $projectName . '_',
            'VORTOS_MESSAGING_DRIVER' => $config['messaging'] === 'in-memory' ? 'in-memory' : 'kafka',
            'VORTOS_MESSAGING_DSN'    => $messagingDsn,
        ];

        if ((bool) $config['docker']) {
            $registry = $this->capabilities();
            foreach ($this->selectedCapabilityKeys($config) as $key) {
                if (!$registry->has($key)) {
                    continue;
                }
                $password = str_starts_with($key, 'write_db.') ? $writeDbPassword : $readDbPassword;
                $values  += $registry->get($key)->dockerEnv($projectName, $password);
            }
        }

        return $values;
    }

    private function dsnPassword(string $dsn): ?string
    {
        if ($dsn === '') {
            return null;
        }

        $password = parse_url($dsn, PHP_URL_PASS);

        return is_string($password) && $password !== '' ? urldecode($password) : null;
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

    private function banner(InputInterface $input, OutputInterface $output, SymfonyStyle $io): void
    {
        $io->writeln('');
        $io->writeln('<fg=cyan;options=bold> __     __  ___  ____ _____ ___  ____  </>');
        $io->writeln('<fg=cyan;options=bold> \ \   / / / _ \|  _ \_   _/ _ \/ ___| </>');
        $io->writeln('<fg=cyan;options=bold>  \ \ / / | | | | |_) || || | | \___ \ </>');
        $io->writeln('<fg=cyan;options=bold>   \ V /  | |_| |  _ < | || |_| |___) |</>');
        $io->writeln('<fg=cyan;options=bold>    \_/    \___/|_| \_\|_| \___/|____/ </>');
        $io->writeln('<fg=gray>Configure Docker or local development.</>');
        $io->writeln('');

        if (!$this->supportsAnimation($input, $output)) {
            return;
        }

        foreach (['[=   ] Preparing setup', '[==  ] Reading project', '[=== ] Loading choices', '[====] Ready'] as $frame) {
            $output->write(sprintf("\r<fg=green>%s</>", $frame));
            usleep(80000);
        }

        $output->write("\r\033[2K");
    }

    private function supportsAnimation(InputInterface $input, OutputInterface $output): bool
    {
        if (!$input->isInteractive() || !$output->isDecorated()) {
            return false;
        }

        if (getenv('CI') !== false || PHP_OS_FAMILY === 'Windows') {
            return false;
        }

        return function_exists('stream_isatty') && stream_isatty(STDIN);
    }

    /** @param array<int, array{name: string, ok: bool, detail: string}> $checks */
    private function renderChecks(SymfonyStyle $io, array $checks): void
    {
        $io->section('Environment checks');

        foreach ($checks as $check) {
            $status = $check['ok'] ? '<info>OK</info>' : '<comment>Needs attention</comment>';
            $io->writeln(sprintf('  %s  %-24s <fg=gray>%s</>', $status, $check['name'], $check['detail']));
        }
    }

    /**
     * @param array{path: string, written: string[], updated: string[], unchanged: string[], backup: ?string} $result
     */
    private function renderEnvironmentResult(SymfonyStyle $io, array $result, bool $dryRun): void
    {
        $io->section($dryRun ? 'Environment plan' : 'Environment');
        $io->writeln(sprintf('  File: <info>%s</info>', $this->relative($result['path'])));
        $io->writeln(sprintf('  Written: %d', count($result['written'])));
        $io->writeln(sprintf('  Updated: %d', count($result['updated'])));
        $io->writeln(sprintf('  Unchanged: %d', count($result['unchanged'])));

        if ($result['backup'] !== null) {
            $io->writeln(sprintf('  Backup: <comment>%s</comment>', $this->relative($result['backup'])));
        }

        $io->writeln('  <fg=gray>.env is gitignored. Commit .env.example as a template. Do not commit .env or .vortos-setup.json.</>');
    }

    private function renderDockerResult(SymfonyStyle $io, DockerPublishResult $result, bool $dryRun): void
    {
        $io->section($dryRun ? 'Docker plan' : 'Docker files');
        $io->writeln(sprintf('  Copied: %d', count($result->copied)));
        $io->writeln(sprintf('  Skipped: %d', count($result->skipped)));
        $io->writeln(sprintf('  Backups: %d', count($result->backedUp)));
    }

    private function renderConfigResult(SymfonyStyle $io, \Vortos\Config\Service\ConfigPublishResult $result, bool $dryRun): void
    {
        $io->section($dryRun ? 'Config plan' : 'Config files');
        $io->writeln(sprintf('  Published: %d', count($result->published)));
        $io->writeln(sprintf('  Skipped (already exist): %d', count($result->skipped)));

        if ($result->published !== []) {
            $io->writeln('  <fg=gray>Edit these files to tune module behaviour before deploying.</>');
        }
    }

    private function relative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', $this->projectDir);

        return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
    }

    private function projectName(): string
    {
        $projectDir = realpath($this->projectDir) ?: $this->projectDir;
        $name = strtolower(basename(str_replace('\\', '/', $projectDir)));
        $name = (string) preg_replace('/[^a-z0-9]+/', '_', $name);
        $name = trim($name, '_');

        return $name !== '' ? $name : 'vortos_app';
    }

    /** @param array<string, string> $current */
    private function resolvedProjectName(array $current): string
    {
        $appName = trim($current['APP_NAME'] ?? '');

        if ($appName !== '' && !in_array(strtolower($appName), ['myapp', 'app', 'vortos_app'], true)) {
            return $appName;
        }

        return $this->projectName();
    }

    /** @param array<string, string> $current */
    private function secret(string $key, array $current, bool $regenerate, int $bytes): string
    {
        if (!$regenerate && isset($current[$key]) && $current[$key] !== '') {
            return $current[$key];
        }

        return bin2hex(random_bytes($bytes));
    }

    /** @param array<string, mixed> $config */
    private function installMissingPackages(array $config, SymfonyStyle $io, bool $dryRun): void
    {
        if ($this->packageInspector === null) {
            return;
        }

        $registry = $this->capabilities();
        $keys     = array_filter($this->selectedCapabilityKeys($config), fn(string $k) => $registry->has($k));
        $missing  = $registry->missingPackagesFor($keys, $this->packageInspector->installedPackages());

        if ($missing === []) {
            return;
        }

        $io->section('Installing packages');

        if ($dryRun) {
            $io->writeln('  <fg=gray>Would run:</> ' . $this->packageInspector->requireCommand($missing));
            return;
        }

        $io->writeln('  Running: <info>' . $this->packageInspector->requireCommand($missing) . '</info>');
        $io->writeln('');

        $success = $this->packageInspector->runRequire($missing);

        $io->writeln('');

        if ($success) {
            $io->writeln('<info>✔ Packages installed successfully.</info>');
        } else {
            $io->warning('composer require failed. Run the command above manually, then re-run vortos:setup.');
        }
    }

    /**
     * Derives the selected capability keys from a resolved config array.
     * Used to determine which composer packages the chosen stack requires.
     *
     * @param array<string, mixed> $config
     * @return string[]
     */
    private function selectedCapabilityKeys(array $config): array
    {
        $dbValue = (string) preg_replace('/^(?:docker|local)-/', '', (string) $config['database']);

        return [
            'runtime.' . str_replace('-', '_', (string) $config['runtime']),
            'write_db.' . str_replace('-', '_', $dbValue),
            (bool) $config['mongo'] ? 'read_db.mongo' : 'read_db.none',
            'cache.' . str_replace('-', '_', (string) $config['cache']),
            'messaging.' . str_replace('-', '_', (string) $config['messaging']),
        ];
    }
}
