<?php

declare(strict_types=1);

namespace Central\Api\Application\Action;

use Central\Api\Application\Http\Json;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Identifies the running build.
 *
 * OPcache is deployed with `validate_timestamps=0`, so the code on disk and
 * the code being executed can disagree until FPM is reloaded. This endpoint
 * reports what is actually loaded, which is the fastest way to answer "did the
 * deploy take?".
 */
final class VersionAction
{
    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $app = $this->settings['app'];

        return Json::ok($response, [
            'name'      => $app['name'],
            'version'   => $app['version'],
            'env'       => $app['env'],
            'php'       => PHP_VERSION,
            'opcache'   => $this->opcacheState(),
            'startedAt' => gmdate('c'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function opcacheState(): array
    {
        if (!function_exists('opcache_get_status')) {
            return ['enabled' => false];
        }

        $status = @opcache_get_status(false);

        if ($status === false) {
            return ['enabled' => false];
        }

        return [
            'enabled'          => (bool) ($status['opcache_enabled'] ?? false),
            'cachedScripts'    => $status['opcache_statistics']['num_cached_scripts'] ?? null,
            'lastRestartTime'  => isset($status['opcache_statistics']['last_restart_time'])
                && $status['opcache_statistics']['last_restart_time'] > 0
                    ? gmdate('c', (int) $status['opcache_statistics']['last_restart_time'])
                    : null,
        ];
    }
}
