<?php

/**
 * Shared boot sequence for both entry points (public/index.php and
 * bin/console). Keeping it in one file means a CLI command and an HTTP request
 * cannot end up with differently-configured services — a class of bug that is
 * miserable to diagnose because it only appears in cron.
 */

declare(strict_types=1);

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

return static function (): ContainerInterface {
    $root = dirname(__DIR__);

    require_once $root . '/vendor/autoload.php';

    // .env is for local development. On a real host the variables come from
    // the process environment, so a missing file is not an error.
    if (file_exists($root . '/.env')) {
        Dotenv\Dotenv::createImmutable($root)->safeLoad();
    }

    $builder = new ContainerBuilder();
    $builder->addDefinitions($root . '/config/container.php');

    // Compiling the container removes reflection from the hot path. Only in
    // prod: in development the cost of a stale compiled container (edit a
    // definition, see no change) is far worse than the reflection overhead.
    if (($_ENV['APP_ENV'] ?? 'prod') === 'prod') {
        $builder->enableCompilation($root . '/var/cache/container');
        $builder->writeProxiesToFile(true, $root . '/var/cache/proxies');
    }

    $container = $builder->build();

    $settings = $container->get('settings');

    date_default_timezone_set((string) $settings['app']['timezone']);

    if (!empty($settings['sentry']['dsn'])) {
        Sentry\init([
            'dsn'                => $settings['sentry']['dsn'],
            'environment'        => $settings['app']['env'],
            'release'            => $settings['app']['version'],
            'traces_sample_rate' => $settings['sentry']['traces_sample_rate'],
            // Request bodies routinely carry passwords and OTPs.
            'send_default_pii'   => false,
        ]);
    }

    foreach ([$settings['paths']['cache'], $settings['paths']['log']] as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    return $container;
};
