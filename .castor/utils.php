<?php

use Castor\Attribute\AsContext;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\GlobalHelper;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

use function Castor\log;
use function Castor\run;

#[AsTask(description: 'Display some help and available urls for the current project')]
function about(Context $c, SymfonyStyle $io)
{
    $io->section('About this project');

    $io->comment('Run <comment>castor</comment> to display all available commands.');
    $io->comment('Run <comment>castor about</comment> to display this help.');
    $io->comment('Run <comment>castor help [command]</comment> to display Symfony help.');

    $io->section('Available URLs for this project:');
    $urls = [$c['root_domain'], ...$c['extra_domains']];

    $payload = @file_get_contents(sprintf('http://%s:8080/api/http/routers', $c['root_domain']));
    if ($payload) {
        $routers = json_decode($payload, true);
        $projectName = $c['project_name'];
        foreach ($routers as $router) {
            if (!preg_match("{^{$projectName}-(.*)@docker$}", $router['name'])) {
                continue;
            }
            if ("frontend-{$projectName}" === $router['service']) {
                continue;
            }
            if (!preg_match('{^Host\\(`(?P<hosts>.*)`\\)$}', $router['rule'], $matches)) {
                continue;
            }
            $hosts = explode('`, `', $matches['hosts']);
            $urls = [...$urls, ...$hosts];
        }
    }
    $io->listing(array_map(fn ($url) => "https://{$url}", $urls));
}

#[AsTask(description: 'Opens a shell (bash) into a builder container')]
function builder(Context $c, string $user = 'app')
{
    $c = $c
        ->withTimeout(null)
        ->withTty()
        ->withEnvironment($_ENV + $_SERVER)
        ->withQuiet()
        ->withAllowFailure()
    ;
    docker_compose_run('bash', c: $c, user: $user);
}

#[AsContext(default: true)]
function create_default_context(): Context
{
    $c = create_default_parameters() + [
        'docker_compose_files' => [
            'docker-compose.yml',
            'docker-compose.worker.yml',
        ],
        'macos' => false,
        'power_shell' => false,
        'user_id' => posix_geteuid(),
        'root_dir' => dirname(__DIR__),
        'env' => $_SERVER['CI'] ?? false ? 'ci' : 'dev',
        'composer_cache_dir' => sys_get_temp_dir() . '/castor/composer',
    ];

    if (file_exists($c['root_dir'] . '/infrastructure/docker/docker-compose.override.yml')) {
        $c['docker_compose_files'][] = 'docker-compose.override.yml';
    }

    $process = run(['composer', 'global', 'config', 'cache-dir', '-q'], quiet: true, allowFailure: true);
    if ($process->isSuccessful()) {
        $c['composer_cache_dir'] = trim($process->getOutput());
    }

    $platform = strtolower(php_uname('s'));
    if (str_contains($platform, 'darwin')) {
        $c['macos'] = true;
        $c['docker_compose_files'][] = 'docker-compose.docker-for-x.yml';
    } elseif (in_array($platform, ['win32', 'win64'])) {
        $c['docker_compose_files'][] = 'docker-compose.docker-for-x.yml';
        $c['power_shell'] = true;
    }

    if ($c['user_id'] > 256000) {
        $c['user_id'] = 1000;
    }

    if (0 === $c['user_id']) {
        log('Running as root? Fallback to fake user id.', 'warning');
        $c['user_id'] = 1000;
    }

    return new Context($c, pty: 'dev' === $c['env']);
}

function docker_compose_run(
    string $runCommand,
    Context $c = null,
    string $service = 'builder',
    string $user = 'app',
    bool $noDeps = true,
    string $workDir = null,
    bool $portMapping = false,
    bool $withBuilder = true,
): Process {
    $command = [
        'run',
        '--rm',
        '-u', $user,
    ];

    if ($noDeps) {
        $command[] = '--no-deps';
    }

    if ($portMapping) {
        $command[] = '--service-ports';
    }

    if (null !== $workDir) {
        $command[] = '-w';
        $command[] = $workDir;
    }

    $command[] = $service;
    $command[] = '/bin/sh';
    $command[] = '-c';
    $command[] = "exec {$runCommand}";

    return docker_compose($command, c: $c, withBuilder: $withBuilder);
}

function docker_compose(array $subCommand, Context $c = null, bool $withBuilder = false): Process
{
    $c ??= GlobalHelper::getInitialContext();

    $domains = [$c['root_domain'], ...$c['extra_domains']];
    $domains = '`' . implode('`, `', $domains) . '`';

    $c = $c
        ->withTimeout(null)
        ->withEnvironment([
            'PROJECT_NAME' => $c['project_name'],
            'PROJECT_DIRECTORY' => $c['project_directory'],
            'PROJECT_ROOT_DOMAIN' => $c['root_domain'],
            'PROJECT_DOMAINS' => $domains,
            'COMPOSER_CACHE_DIR' => $c['composer_cache_dir'],
            'PHP_VERSION' => $c['php_version'],
        ], false)
    ;

    $command = [
        'docker',
        'compose',
        '-p', $c['project_name'],
    ];

    foreach ($c['docker_compose_files'] as $file) {
        $command[] = '-f';
        $command[] = $c['root_dir'] . '/infrastructure/docker/' . $file;
    }
    if ($withBuilder) {
        $command[] = '-f';
        $command[] = $c['root_dir'] . '/infrastructure/docker/docker-compose.builder.yml';
    }

    $command = array_merge($command, $subCommand);

    return run($command, context: $c);
}

// Mac users have a lot of problems running Yarn / Webpack on the Docker stack
// so this func allow them to run these tools on their host
function run_in_docker_or_locally_for_mac(string $command, Context $c = null, $noDeps = false): void
{
    $c ??= GlobalHelper::getInitialContext();

    if ($c['macos']) {
        run($command, context: $c->withPath($c['root_dir']));
    } else {
        docker_compose_run($command, c: $c, noDeps: $noDeps);
    }
}
