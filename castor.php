<?php

use Castor\Attribute\AsTask;
use Castor\Context;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Castor\import;
use function Castor\notify;

import(__DIR__ . '/.castor');

function create_default_parameters(): array
{
    $projectName = 'app';
    $tld = 'test';

    return [
        'project_name' => $projectName,
        'root_domain' => "{$projectName}.{$tld}",
        'extra_domains' => [
            "www.{$projectName}.{$tld}",
        ],
        'php_version' => $_SERVER['DS_PHP_VERSION'] ?? '8.2',
        'project_directory' => 'application',
    ];
}

#[AsTask(description: 'Builds and starts the infrastructure, then install the application (composer, yarn, ...)')]
function start(Context $c, SymfonyStyle $io)
{
    infra\workers_stop();
    infra\generate_certificates($c, $io, false);
    infra\build($c);
    infra\up();
    cache_clear();
    install($c);
    migrate();
    infra\workers_start();

    notify('The stack is now up and running.');
    $io->success('The stack is now up and running.');

    about($c, $io);
}

#[AsTask(description: 'Installs the application (composer, yarn, ...)', namespace: 'app')]
function install(Context $c)
{
    $basePath = sprintf('%s/%s', $c['root_dir'], $c['project_directory']);

    if (is_file("{$basePath}/composer.json")) {
        docker_compose_run('composer install -n --prefer-dist --optimize-autoloader');
    }
    if (is_file("{$basePath}/yarn.lock")) {
        docker_compose_run('yarn');
    } elseif (is_file("{$basePath}/package.json")) {
        docker_compose_run('npm install');
    }
}

#[AsTask(description: 'Clear the application cache', namespace: 'app')]
function cache_clear()
{
    // docker_compose_run('rm -rf var/cache/ && bin/console cache:warmup');
}

#[AsTask(description: 'Migrates database schema', namespace: 'app:db')]
function migrate()
{
    // docker_compose_run('bin/console doctrine:database:create --if-not-exists');
    // docker_compose_run('bin/console doctrine:migration:migrate -n --allow-no-migration');
}
