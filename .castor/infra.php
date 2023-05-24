<?php

namespace infra;

use Castor\Attribute\AsOption;
use Castor\Attribute\AsTask;
use Castor\Context;
use Castor\GlobalHelper;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\ExecutableFinder;

use function Castor\fs;
use function Castor\run;

#[AsTask(description: 'Builds the infrastructure', aliases: ['build'])]
function build(Context $c)
{
    $command = [
        'build',
        '--build-arg', "USER_ID={$c['user_id']}",
        '--build-arg', "PHP_VERSION={$c['php_version']}",
    ];

    docker_compose($command, withBuilder: true);
}

#[AsTask(description: 'Builds and starts the infrastructure', aliases: ['up'])]
function up()
{
    docker_compose(['up', '--remove-orphans', '--detach']);
}

#[AsTask(description: 'Stops the infrastructure', aliases: ['stop'])]
function stop()
{
    docker_compose(['stop']);
}

#[AsTask(description: 'Displays infrastructure logs')]
function logs(Context $c)
{
    docker_compose(['logs', '-f', '--tail', '150'], c: $c->withTty());
}

#[AsTask(description: 'Lists containers status')]
function ps()
{
    docker_compose(['ps'], withBuilder: false);
}

#[AsTask(description: 'Clean the infrastructure (remove container, volume, networks)')]
function destroy(
    Context $c,
    SymfonyStyle $io,
    #[AsOption(description: 'Force the destruction without confirmation', shortcut: 'f', mode: InputOption::VALUE_NONE)]
    bool $force,
) {
    if (!$force) {
        $io->warning('This will permanently remove all containers, volumes, networks... created for this project.');
        $io->note('You can use the --force option to avoid this confirmation.');
        if (!$io->confirm('Are you sure?', false)) {
            $io->comment('Aborted.');

            return;
        }
    }

    docker_compose(['down', '--remove-orphans', '--volumes', '--rmi=local'], withBuilder: true);
    fs()->remove(glob($c['root_dir'] . '/infrastructure/docker/services/router/etc/ssl/certs/*.pem'));
}

#[AsTask(description: 'Generate SSL certificates (with mkcert if available or self-signed if not)')]
function generate_certificates(
    Context $c,
    SymfonyStyle $io,
    #[AsOption(description: 'Force the certificates re-generation without confirmation', shortcut: 'f', mode: InputOption::VALUE_NONE)]
    bool $force,
) {
    if (file_exists($c['root_dir'] . '/infrastructure/docker/services/router/etc/ssl/certs/cert.pem') && !$force) {
        $io->comment('SSL certificates already exists.');
        $io->note('Run "castor infrastructure:generate-certificates --force" to generate new certificates.');

        return;
    }

    if ($force) {
        if (file_exists($f = $c['root_dir'] . '/infrastructure/docker/services/router/etc/ssl/certs/cert.pem')) {
            $io->comment('Removing existing certificates in infrastructure/docker/services/router/etc/ssl/certs/*.pem.');
            unlink($f);
        }

        if (file_exists($f = $c['root_dir'] . '/infrastructure/docker/services/router/etc/ssl/certs/key.pem')) {
            unlink($f);
        }
    }

    $finder = new ExecutableFinder();
    $mkcert = $finder->find('mkcert');

    if ($mkcert) {
        $pathCaRoot = trim(run(['mkcert', '-CAROOT'], quiet: true)->getOutput());

        if (!is_dir($pathCaRoot)) {
            $io->warning('You must have mkcert CA Root installed on your host with "mkcert -install" command.');

            return;
        }

        run([
            'mkcert',
            '-cert-file', 'infrastructure/docker/services/router/etc/ssl/certs/cert.pem',
            '-key-file', 'infrastructure/docker/services/router/etc/ssl/certs/key.pem',
            $c['root_domain'],
            "*.{$c['root_domain']}",
            ...$c['extra_domains'],
        ]);

        $io->success('Successfully generated SSL certificates with mkcert.');

        if ($force) {
            $io->note('Please restart the infrastructure to use the new certificates with "castor up" or "castor start".');
        }

        return;
    }

    run(['infrastructure/docker/services/router/generate-ssl.sh']);

    $io->success('Successfully generated self-signed SSL certificates in infrastructure/docker/services/router/etc/ssl/certs/*.pem.');
    $io->comment('Consider installing mkcert to generate locally trusted SSL certificates and run "castor infra:generate-certificates --force".');

    if ($force) {
        $io->note('Please restart the infrastructure to use the new certificates with "castor up" or "castor start".');
    }
}

#[AsTask(description: 'Starts the workers', namespace: 'infra:worker', name: 'start')]
function workers_start()
{
    $workers = get_workers();

    if (!$workers) {
        return;
    }

    run([
        'docker',
        'update',
        '--restart=unless-stopped',
        ...$workers,
    ], quiet: true);

    run([
        'docker',
        'start',
        ...$workers,
    ], quiet: true);
}

#[AsTask(description: 'Stops the workers', namespace: 'infra:worker', name: 'stop')]
function workers_stop()
{
    $workers = get_workers();

    if (!$workers) {
        return;
    }

    run([
        'docker',
        'update',
        '--restart=no',
        ...$workers,
    ]);

    run([
        'docker',
        'stop',
        ...$workers,
    ]);
}

/**
 * Find worker containers for the current project.
 */
function get_workers(): array
{
    $c = GlobalHelper::getInitialContext();

    $command = [
        'docker',
        'ps',
        '-a',
        '--filter', 'label=docker-starter.worker.' . $c['project_name'],
        '--quiet',
    ];

    $out = trim(run($command, context: $c, quiet: true)->getOutput());
    if (!$out) {
        return [];
    }

    $workers = explode("\n", $out);

    return array_map('trim', $workers);
}
