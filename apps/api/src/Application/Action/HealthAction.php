<?php

declare(strict_types=1);

namespace Central\Api\Application\Action;

use Central\Api\Application\Http\Json;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Readiness probe for the load balancer and for `docker compose` healthchecks.
 *
 * Each dependency is checked independently and a failure in one does not mask
 * the others — "the API is down" is far less useful at 3am than "Postgres is
 * reachable, Redis is not". Returns 503 when any hard dependency is unhealthy
 * so an orchestrator stops routing traffic here.
 */
final class HealthAction
{
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $checks = [
            'database' => $this->check(static function (ContainerInterface $c): void {
                $c->get(Connection::class)->executeQuery('SELECT 1');
            }),
            'cache' => $this->check(static function (ContainerInterface $c): void {
                $pool = $c->get(CacheItemPoolInterface::class);
                $item = $pool->getItem('health.probe');
                $item->set(1);
                $item->expiresAfter(10);
                $pool->save($item);
            }),
        ];

        $healthy = !in_array(false, array_column($checks, 'ok'), true);

        if (!$healthy) {
            $this->logger->error('Health check failed', ['checks' => $checks]);
        }

        return Json::ok(
            $response,
            ['status' => $healthy ? 'ok' : 'degraded', 'checks' => $checks],
            $healthy ? 200 : 503,
        );
    }

    /**
     * @param \Closure(ContainerInterface): void $probe
     *
     * @return array{ok: bool, latencyMs: float, error?: string}
     */
    private function check(\Closure $probe): array
    {
        $startedAt = hrtime(true);

        try {
            $probe($this->container);

            return ['ok' => true, 'latencyMs' => $this->elapsedMs($startedAt)];
        } catch (\Throwable $e) {
            return [
                'ok'        => false,
                'latencyMs' => $this->elapsedMs($startedAt),
                // The message names the failing component, never credentials.
                'error'     => $e::class,
            ];
        }
    }

    private function elapsedMs(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }
}
