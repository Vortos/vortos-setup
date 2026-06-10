<?php

declare(strict_types=1);

namespace Vortos\Setup\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Docker\Service\DockerFilePublisher;
use Vortos\Setup\Command\SetupCommand;
use Vortos\Setup\Service\ComposerPackageInspector;
use Vortos\Setup\Service\EnvironmentFileWriter;
use Vortos\Setup\Service\SetupEnvironmentChecker;
use Vortos\Setup\Service\SetupStateStore;

final class SetupCommandTest extends TestCase
{
    private string $projectDir;
    private string $stubRoot;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/vortos_setup_project_' . uniqid('', true);
        $this->stubRoot = sys_get_temp_dir() . '/vortos_setup_stubs_' . uniqid('', true);

        mkdir($this->projectDir, 0777, true);
        mkdir($this->stubRoot . '/frankenphp/docker/php', 0777, true);
        file_put_contents($this->stubRoot . '/frankenphp/docker-compose.yaml', 'services: []' . PHP_EOL);
        file_put_contents($this->stubRoot . '/frankenphp/docker/php/Dockerfile', 'FROM php' . PHP_EOL);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir);
        $this->removeDirectory($this->stubRoot);
    }

    public function test_dry_run_does_not_write_files(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--preset' => 'local',
            '--dry-run' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertFileDoesNotExist($this->projectDir . '/.env');
        $this->assertFileDoesNotExist($this->projectDir . '/.vortos-setup.json');
    }

    public function test_dry_run_uses_compact_output_instead_of_wide_tables(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--preset' => 'local',
            '--dry-run' => true,
            '--no-interaction' => true,
        ]);

        $output = $tester->getDisplay();

        $this->assertStringContainsString('Environment plan', $output);
        $this->assertStringContainsString('Setup plan (dry run)', $output);
        $this->assertStringContainsString('Written:', $output);
        $this->assertStringContainsString('Do not commit .env or .vortos-setup.json.', $output);
        $this->assertStringNotContainsString('Written   Updated   Unchanged', $output);
    }

    public function test_minimal_profile_uses_local_in_memory_defaults(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--profile' => 'minimal',
            '--no-interaction' => true,
        ]);

        $env = (string) file_get_contents($this->projectDir . '/.env');
        $state = json_decode((string) file_get_contents($this->projectDir . '/.vortos-setup.json'), true);

        $this->assertSame('minimal', $state['profile']);
        $this->assertSame('minimal', $state['preset']);
        $this->assertSame('in-memory', $this->envValue($env, 'VORTOS_CACHE_DRIVER'));
        $this->assertSame('in-memory', $this->envValue($env, 'VORTOS_MESSAGING_DRIVER'));
    }

    public function test_docker_profile_uses_frankenphp_docker_defaults(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--profile' => 'docker',
            '--skip-docker-publish' => true,
            '--no-interaction' => true,
        ]);

        $state = json_decode((string) file_get_contents($this->projectDir . '/.vortos-setup.json'), true);

        $this->assertSame('docker', $state['profile']);
        $this->assertSame('docker-frankenphp', $state['preset']);
        $this->assertTrue($state['docker']);
        $this->assertTrue($state['mcp']);
    }

    public function test_docker_profile_ignores_platform_requirements_provided_by_docker_when_installing_packages(): void
    {
        $command = new SetupCommand(
            $this->projectDir,
            new SetupStateStore($this->projectDir),
            new EnvironmentFileWriter($this->projectDir),
            new SetupEnvironmentChecker($this->projectDir),
            new DockerFilePublisher($this->stubRoot),
            packageInspector: new ComposerPackageInspector($this->projectDir),
        );

        $application = new Application();
        $application->add($command);
        $tester = new CommandTester($application->find('vortos:setup'));

        $tester->execute([
            '--profile' => 'docker',
            '--dry-run' => true,
            '--skip-docker-publish' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();

        $this->assertStringContainsString("--ignore-platform-req='ext-mongodb'", $output);
        $this->assertStringContainsString("--ignore-platform-req='ext-redis'", $output);
        $this->assertStringContainsString("--ignore-platform-req='ext-rdkafka'", $output);
    }

    public function test_mcp_can_be_disabled_for_docker_profile(): void
    {
        $command = new SetupCommand(
            $this->projectDir,
            new SetupStateStore($this->projectDir),
            new EnvironmentFileWriter($this->projectDir),
            new SetupEnvironmentChecker($this->projectDir),
            new DockerFilePublisher($this->stubRoot),
            packageInspector: new ComposerPackageInspector($this->projectDir),
        );

        $application = new Application();
        $application->add($command);
        $tester = new CommandTester($application->find('vortos:setup'));

        $tester->execute([
            '--profile' => 'docker',
            '--no-mcp' => true,
            '--dry-run' => true,
            '--skip-docker-publish' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();

        $this->assertStringContainsString('MCP:       no', $output);
        $this->assertStringNotContainsString('vortos/vortos-mcp', $output);
        $this->assertStringNotContainsString('vortos:mcp:install', $output);
    }

    public function test_otlp_option_installs_exporter_packages_after_allowing_composer_plugins(): void
    {
        $command = new SetupCommand(
            $this->projectDir,
            new SetupStateStore($this->projectDir),
            new EnvironmentFileWriter($this->projectDir),
            new SetupEnvironmentChecker($this->projectDir),
            new DockerFilePublisher($this->stubRoot),
            packageInspector: new ComposerPackageInspector($this->projectDir),
        );

        $application = new Application();
        $application->add($command);
        $tester = new CommandTester($application->find('vortos:setup'));

        $tester->execute([
            '--preset' => 'minimal',
            '--otlp' => true,
            '--dry-run' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();

        $this->assertStringContainsString('Monitoring: Native + OTLP Exporter', $output);
        $this->assertStringContainsString('composer config allow-plugins.php-http/discovery true', $output);
        $this->assertStringContainsString('composer config allow-plugins.tbachert/spi true', $output);
        $this->assertStringContainsString("'open-telemetry/api'", $output);
        $this->assertStringContainsString("'open-telemetry/sdk'", $output);
        $this->assertStringContainsString("'open-telemetry/exporter-otlp'", $output);
        $this->assertStringContainsString("'guzzlehttp/guzzle'", $output);
    }

    public function test_no_otlp_option_keeps_normal_observability(): void
    {
        $command = new SetupCommand(
            $this->projectDir,
            new SetupStateStore($this->projectDir),
            new EnvironmentFileWriter($this->projectDir),
            new SetupEnvironmentChecker($this->projectDir),
            new DockerFilePublisher($this->stubRoot),
            packageInspector: new ComposerPackageInspector($this->projectDir),
        );

        $application = new Application();
        $application->add($command);
        $tester = new CommandTester($application->find('vortos:setup'));

        $tester->execute([
            '--preset' => 'minimal',
            '--no-otlp' => true,
            '--dry-run' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();

        $this->assertStringContainsString('Monitoring: Native only', $output);
        $this->assertStringNotContainsString('open-telemetry/exporter-otlp', $output);
        $this->assertStringNotContainsString('allow-plugins.php-http/discovery', $output);
    }

    public function test_custom_monitoring_prompt_uses_plain_labels(): void
    {
        $tester = $this->tester();
        $tester->setInputs([
            'custom',
            '0',
            '0',
            '0',
            '0',
            '0',
            '1',
            '0',
            'Cancel',
        ]);

        $tester->execute(['--skip-docker-publish' => true]);

        $output = $tester->getDisplay();

        $this->assertStringContainsString('Choose monitoring', $output);
        $this->assertStringContainsString('Native + OpenTelemetry (Adds OTLP Push Exporter)', $output);
        $this->assertStringNotContainsString('observability.normal', $output);
        $this->assertStringNotContainsString('observability.otlp', $output);
        $this->assertStringNotContainsString('open-telemetry/exporter-otlp', $output);
    }

    public function test_mcp_next_step_is_shown_when_selected(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--profile' => 'docker',
            '--skip-docker-publish' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('php bin/console vortos:mcp:install', $tester->getDisplay());
    }

    public function test_interactive_setup_asks_whether_to_configure_mcp_client_now(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['no']);

        $tester->execute([
            '--preset' => 'local',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Configure MCP in an AI client now?', $tester->getDisplay());
    }

    public function test_interactive_setup_can_run_mcp_client_install_step(): void
    {
        mkdir($this->projectDir . '/bin', 0777, true);
        file_put_contents(
            $this->projectDir . '/bin/console',
            "<?php\nfile_put_contents(__DIR__ . '/../mcp-install-ran', implode(' ', array_slice(\$argv, 1)));\n",
        );

        $tester = $this->tester();
        $tester->setInputs(['yes']);

        $tester->execute([
            '--preset' => 'local',
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('MCP client', $tester->getDisplay());
        $this->assertStringNotContainsString('Wire MCP to your AI client', $tester->getDisplay());
        $this->assertSame('vortos:mcp:install', file_get_contents($this->projectDir . '/mcp-install-ran'));
    }

    public function test_invalid_profile_returns_failure_without_writing_files(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--profile' => 'unknown',
            '--no-interaction' => true,
        ]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Unknown setup profile "unknown".', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->projectDir . '/.env');
    }

    public function test_custom_profile_requires_interactive_mode(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--profile' => 'custom',
        ], ['interactive' => false]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('The custom profile requires interactive setup.', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->projectDir . '/.env');
    }

    public function test_setup_is_idempotent_and_preserves_generated_secrets(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--preset' => 'local',
            '--no-interaction' => true,
        ]);

        $firstEnv = (string) file_get_contents($this->projectDir . '/.env');
        $this->assertStringContainsString('VORTOS_CACHE_DRIVER=in-memory', $firstEnv);
        $this->assertFileExists($this->projectDir . '/.vortos-setup.json');

        $tester = $this->tester();
        $tester->execute([
            '--preset' => 'local',
            '--no-interaction' => true,
        ]);

        $secondEnv = (string) file_get_contents($this->projectDir . '/.env');
        $this->assertSame($this->envValue($firstEnv, 'JWT_SECRET'), $this->envValue($secondEnv, 'JWT_SECRET'));
        $this->assertSame($this->envValue($firstEnv, 'HEALTH_TOKEN'), $this->envValue($secondEnv, 'HEALTH_TOKEN'));
        $this->assertSame($this->envValue($firstEnv, 'VORTOS_WRITE_DB_DSN'), $this->envValue($secondEnv, 'VORTOS_WRITE_DB_DSN'));
    }

    public function test_setup_generates_local_passwords_and_sections_env_file(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--preset' => 'docker-frankenphp',
            '--no-interaction' => true,
            '--skip-docker-publish' => true,
        ]);

        $env = (string) file_get_contents($this->projectDir . '/.env');

        $this->assertStringContainsString('# Write Database', $env);
        $this->assertStringContainsString('# Security', $env);
        $this->assertNotContains($this->envValue($env, 'VORTOS_WRITE_DB_PASSWORD'), ['12345', 'password', 'postgres', 'secret']);
        $this->assertNotContains($this->envValue($env, 'VORTOS_READ_DB_PASSWORD'), ['12345', 'password', 'postgres', 'secret']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $this->envValue($env, 'VORTOS_WRITE_DB_PASSWORD'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $this->envValue($env, 'VORTOS_READ_DB_PASSWORD'));
    }

    public function test_regenerate_secrets_replaces_existing_secrets(): void
    {
        $tester = $this->tester();
        $tester->execute([
            '--preset' => 'local',
            '--no-interaction' => true,
        ]);

        $firstEnv = (string) file_get_contents($this->projectDir . '/.env');

        $tester = $this->tester();
        $tester->execute([
            '--preset' => 'local',
            '--regenerate-secrets' => true,
            '--no-interaction' => true,
        ]);

        $secondEnv = (string) file_get_contents($this->projectDir . '/.env');

        $this->assertNotSame($this->envValue($firstEnv, 'JWT_SECRET'), $this->envValue($secondEnv, 'JWT_SECRET'));
        $this->assertNotSame($this->envValue($firstEnv, 'HEALTH_TOKEN'), $this->envValue($secondEnv, 'HEALTH_TOKEN'));
        $this->assertNotSame($this->envValue($firstEnv, 'VORTOS_WRITE_DB_DSN'), $this->envValue($secondEnv, 'VORTOS_WRITE_DB_DSN'));
    }

    public function test_setup_derives_project_name_for_environment_defaults(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--preset' => 'docker-frankenphp',
            '--no-interaction' => true,
            '--skip-docker-publish' => true,
        ]);

        $env = (string) file_get_contents($this->projectDir . '/.env');
        $projectName = $this->expectedProjectName();

        $this->assertSame($projectName, $this->envValue($env, 'APP_NAME'));
        $this->assertSame($projectName, $this->envValue($env, 'VORTOS_WRITE_DB_NAME'));
        $this->assertSame('postgres', $this->envValue($env, 'VORTOS_WRITE_DB_DRIVER'));
        $this->assertSame('mongo', $this->envValue($env, 'VORTOS_READ_DB_DRIVER'));
        $this->assertSame($projectName, $this->envValue($env, 'VORTOS_READ_DB_NAME'));
        $this->assertSame('dev_' . $projectName . '_', $this->envValue($env, 'VORTOS_CACHE_PREFIX'));
        $this->assertStringContainsString('@write_db:5432/' . $projectName, $this->envValue($env, 'VORTOS_WRITE_DB_DSN'));
        $this->assertStringContainsString('@read_db:27017', $this->envValue($env, 'VORTOS_READ_DB_DSN'));
    }

    public function test_setup_ignores_skeleton_app_name_placeholder(): void
    {
        file_put_contents($this->projectDir . '/.env', 'APP_NAME=myapp' . PHP_EOL);

        $tester = $this->tester();
        $tester->execute([
            '--preset' => 'local',
            '--no-interaction' => true,
        ]);

        $env = (string) file_get_contents($this->projectDir . '/.env');
        $projectName = $this->expectedProjectName();

        $this->assertSame($projectName, $this->envValue($env, 'APP_NAME'));
        $this->assertStringContainsString('/' . $projectName, $this->envValue($env, 'VORTOS_WRITE_DB_DSN'));
    }

    public function test_existing_app_name_is_preserved_on_setup(): void
    {
        file_put_contents($this->projectDir . '/.env', 'APP_NAME=custom_app' . PHP_EOL);

        $tester = $this->tester();
        $tester->execute([
            '--preset' => 'local',
            '--no-interaction' => true,
        ]);

        $env = (string) file_get_contents($this->projectDir . '/.env');

        $this->assertSame('custom_app', $this->envValue($env, 'APP_NAME'));
        $this->assertStringContainsString('/custom_app', $this->envValue($env, 'VORTOS_WRITE_DB_DSN'));
    }

    public function test_setup_writes_agnostic_app_env_without_legacy_service_helpers(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--preset' => 'docker-frankenphp',
            '--no-interaction' => true,
            '--skip-docker-publish' => true,
        ]);

        $env = (string) file_get_contents($this->projectDir . '/.env');

        $this->assertStringContainsString('VORTOS_WRITE_DB_DSN=', $env);
        $this->assertStringContainsString('VORTOS_READ_DB_DSN=', $env);
        $this->assertStringContainsString('VORTOS_CACHE_DSN=', $env);
        $this->assertStringContainsString('VORTOS_MESSAGING_DSN=', $env);
        $this->assertStringContainsString('VORTOS_WRITE_DB_USER=', $env);
        $this->assertStringContainsString('VORTOS_WRITE_DB_NAME=', $env);
        $this->assertStringContainsString('VORTOS_WRITE_DB_PASSWORD=', $env);
        $this->assertStringContainsString('VORTOS_READ_DB_USER=', $env);
        $this->assertStringContainsString('VORTOS_READ_DB_PASSWORD=', $env);
        $writeSection = $this->section($env, 'Write Database');
        $readSection = $this->section($env, 'Read Database');

        $this->assertStringContainsString('VORTOS_WRITE_DB_DRIVER=', $writeSection);
        $this->assertStringContainsString('VORTOS_WRITE_DB_USER=', $writeSection);
        $this->assertStringContainsString('VORTOS_WRITE_DB_PASSWORD=', $writeSection);
        $this->assertStringContainsString('VORTOS_WRITE_DB_NAME=', $writeSection);
        $this->assertStringContainsString('VORTOS_WRITE_DB_DSN=', $writeSection);
        $this->assertStringContainsString('VORTOS_READ_DB_DRIVER=', $readSection);
        $this->assertStringContainsString('VORTOS_READ_DB_USER=', $readSection);
        $this->assertStringContainsString('VORTOS_READ_DB_PASSWORD=', $readSection);
        $this->assertStringContainsString('VORTOS_READ_DB_DSN=', $readSection);
        $this->assertStringContainsString('VORTOS_READ_DB_NAME=', $readSection);
        $this->assertSame('postgres', $this->envValue($env, 'VORTOS_WRITE_DB_USER'));
        $this->assertSame($this->expectedProjectName(), $this->envValue($env, 'VORTOS_WRITE_DB_NAME'));
        $this->assertSame('root', $this->envValue($env, 'VORTOS_READ_DB_USER'));

        foreach ([
            'DATABASE_URL',
            'MONGODB_URL',
            'POSTGRES_HOST',
            'POSTGRES_DB_NAME',
            'REDIS_HOST',
            'REDIS_PORT',
            'MONGO_HOST',
            'MONGO_PORT',
            'MONGO_DB_NAME',
            'KAFKA_BROKERS',
        ] as $legacyKey) {
            $this->assertStringNotContainsString($legacyKey . '=', $env);
        }
    }

    public function test_docker_preset_publishes_docker_files_with_backup_on_rerun(): void
    {
        file_put_contents($this->projectDir . '/docker-compose.yaml', 'custom' . PHP_EOL);

        $tester = $this->tester();
        $tester->execute([
            '--preset' => 'docker-frankenphp',
            '--no-interaction' => true,
        ]);

        $this->assertStringContainsString('services: []', (string) file_get_contents($this->projectDir . '/docker-compose.yaml'));
        $this->assertNotEmpty(glob($this->projectDir . '/docker-compose.yaml.bak.*') ?: []);
        $this->assertFileExists($this->projectDir . '/docker/php/Dockerfile');
    }

    public function test_local_profile_does_not_publish_docker_files(): void
    {
        $tester = $this->tester();

        $tester->execute([
            '--profile' => 'minimal',
            '--no-interaction' => true,
        ]);

        $this->assertFileDoesNotExist($this->projectDir . '/docker-compose.yaml');
        $this->assertFileDoesNotExist($this->projectDir . '/docker/php/Dockerfile');
    }

    public function test_custom_docker_profile_removes_unselected_optional_docker_services(): void
    {
        $command = new SetupCommand(
            $this->projectDir,
            new SetupStateStore($this->projectDir),
            new EnvironmentFileWriter($this->projectDir),
            new SetupEnvironmentChecker($this->projectDir),
            new DockerFilePublisher(dirname(__DIR__, 2) . '/Docker/stubs'),
        );

        $application = new Application();
        $application->add($command);
        $tester = new CommandTester($application->find('vortos:setup'));
        $tester->setInputs([
            'custom',
            '0',
            '0',
            '0',
            '1',
            '1',
            '0',
            '0',
            'Continue',
            'no',
        ]);

        $tester->execute([]);

        $compose = (string) file_get_contents($this->projectDir . '/docker-compose.yaml');

        $this->assertStringNotContainsString('  read_db:', $compose);
        $this->assertStringNotContainsString('  read_pg:', $compose);
        $this->assertStringNotContainsString('  redis:', $compose);
        $this->assertStringNotContainsString('  kafka:', $compose);
        $this->assertStringNotContainsString('  worker:', $compose);
        $this->assertStringContainsString('  write_db:', $compose);
    }

    public function test_docker_publish_can_skip_existing_changed_files_from_setup(): void
    {
        file_put_contents($this->projectDir . '/docker-compose.yaml', 'custom' . PHP_EOL);

        $tester = $this->tester();
        $tester->execute([
            '--preset' => 'docker-frankenphp',
            '--no-docker-overwrite' => true,
            '--no-interaction' => true,
        ]);

        $this->assertSame('custom' . PHP_EOL, file_get_contents($this->projectDir . '/docker-compose.yaml'));
        $this->assertEmpty(glob($this->projectDir . '/docker-compose.yaml.bak.*') ?: []);
        $this->assertStringContainsString('Skipped: 1', $tester->getDisplay());
    }

    public function test_environment_checker_normalizes_project_path(): void
    {
        mkdir($this->projectDir . '/bootstrap', 0777, true);

        $checks = (new SetupEnvironmentChecker($this->projectDir . '/bootstrap/..'))->check(false, false, false, false);

        $writable = array_values(array_filter(
            $checks,
            static fn(array $check): bool => $check['name'] === 'Writable project',
        ))[0];

        $this->assertSame($this->projectDir, $writable['detail']);
    }

    public function test_interactive_setup_can_be_cancelled_before_writes(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['minimal', 'Cancel']);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Setup cancelled', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->projectDir . '/.env');
        $this->assertFileDoesNotExist($this->projectDir . '/.vortos-setup.json');
    }

    public function test_interactive_setup_can_customize_from_profile_review(): void
    {
        $tester = $this->tester();
        $tester->setInputs([
            'docker',
            'Customize',
            '2',
            '0',
            '0',
            '1',
            '1',
            '0',
            '1',
            'Continue',
        ]);

        $tester->execute([]);

        $this->assertSame(0, $tester->getStatusCode());
        $state = json_decode((string) file_get_contents($this->projectDir . '/.vortos-setup.json'), true);

        $this->assertSame('custom', $state['profile']);
        $this->assertSame('local', $state['preset']);
        $this->assertSame('local-postgres', $state['database']);
        $this->assertSame('none', $state['read_db']);
        $this->assertSame('in-memory', $state['cache']);
        $this->assertSame('in-memory', $state['messaging']);
        $this->assertFalse($state['mcp']);
    }

    public function test_interactive_custom_profile_asks_each_configurable_category(): void
    {
        $tester = $this->tester();
        $tester->setInputs([
            'custom',
            '1',
            '0',
            '1',
            '0',
            '0',
            '0',
            '0',
            'Continue',
        ]);

        $tester->execute(['--skip-docker-publish' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $state = json_decode((string) file_get_contents($this->projectDir . '/.vortos-setup.json'), true);

        $this->assertStringContainsString('Choose runtime', $output);
        $this->assertStringContainsString('Choose write database', $output);
        $this->assertStringContainsString('Choose read database', $output);
        $this->assertStringContainsString('Choose cache', $output);
        $this->assertStringContainsString('Choose messaging', $output);
        $this->assertStringContainsString('Choose monitoring', $output);
        $this->assertStringContainsString('Install MCP server', $output);
        $this->assertSame('custom', $state['profile']);
        $this->assertSame('docker-phpfpm', $state['preset']);
        $this->assertSame('phpfpm', $state['runtime']);
        $this->assertSame('docker-postgres', $state['database']);
        $this->assertSame('mongo', $state['read_db']);
        $this->assertSame('redis', $state['cache']);
        $this->assertSame('kafka', $state['messaging']);
        $this->assertSame('normal', $state['observability']);
        $this->assertTrue($state['mcp']);
    }

    private function tester(): CommandTester
    {
        $command = new SetupCommand(
            $this->projectDir,
            new SetupStateStore($this->projectDir),
            new EnvironmentFileWriter($this->projectDir),
            new SetupEnvironmentChecker($this->projectDir),
            new DockerFilePublisher($this->stubRoot),
        );

        $application = new Application();
        $application->add($command);

        return new CommandTester($application->find('vortos:setup'));
    }

    private function envValue(string $env, string $key): string
    {
        preg_match('/^' . preg_quote($key, '/') . '=(.+)$/m', $env, $match);

        return $match[1] ?? '';
    }

    private function expectedProjectName(): string
    {
        $name = strtolower(basename($this->projectDir));
        $name = (string) preg_replace('/[^a-z0-9]+/', '_', $name);

        return trim($name, '_');
    }

    private function section(string $env, string $section): string
    {
        $pattern = '/# ' . preg_quote($section, '/') . '\R# -+\R(?<body>.*?)(?=\R# -+\R# |\z)/s';
        preg_match($pattern, $env, $match);

        return $match['body'] ?? '';
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
