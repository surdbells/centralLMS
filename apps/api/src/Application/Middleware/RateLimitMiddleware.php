<?php

declare(strict_types=1);

namespace Central\Api\Application\Middleware;

use Central\Api\Application\Http\Json;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Fixed-window request throttling backed by the shared cache.
 *
 * Keyed by authenticated user where possible and by client IP otherwise, so
 * one noisy tenant cannot exhaust the budget of everyone behind the same NAT.
 *
 * Deliberate limitation: the counter is a read-modify-write against PSR-6, not
 * an atomic INCR, so under heavy concurrency a burst can overshoot the limit
 * slightly. That is acceptable for abuse damping — it is not a quota or a
 * billing meter, and treating it as one would be wrong.
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    private readonly bool $enabled;
    private readonly int $max;
    private readonly int $window;

    /** @param array<string, mixed> $config the `rate_limit` section of settings.php */
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        array $config,
    ) {
        $this->enabled = (bool) ($config['enabled'] ?? true);
        $this->max     = max(1, (int) ($config['max'] ?? 120));
        $this->window  = max(1, (int) ($config['window'] ?? 60));
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->enabled || strtoupper($request->getMethod()) === 'OPTIONS') {
            return $handler->handle($request);
        }

        $windowStart = intdiv(time(), $this->window) * $this->window;
        $key         = sprintf('ratelimit.%s.%d', sha1($this->identify($request)), $windowStart);

        $item  = $this->cache->getItem($key);
        $count = $item->isHit() ? (int) $item->get() : 0;
        ++$count;

        $item->set($count);
        $item->expiresAfter($this->window);
        $this->cache->save($item);

        $remaining = max(0, $this->max - $count);
        $resetAt   = $windowStart + $this->window;

        if ($count > $this->max) {
            $retryAfter = max(1, $resetAt - time());

            return $this->decorate(
                Json::error(
                    new Response(),
                    429,
                    'rate_limited',
                    'Too many requests. Please slow down and try again shortly.',
                    ['retryAfter' => $retryAfter],
                ),
                0,
                $resetAt,
            )->withHeader('Retry-After', (string) $retryAfter);
        }

        return $this->decorate($handler->handle($request), $remaining, $resetAt);
    }

    private function decorate(ResponseInterface $response, int $remaining, int $resetAt): ResponseInterface
    {
        return $response
            ->withHeader('X-RateLimit-Limit', (string) $this->max)
            ->withHeader('X-RateLimit-Remaining', (string) $remaining)
            ->withHeader('X-RateLimit-Reset', (string) $resetAt);
    }

    /**
     * An authenticated caller is throttled as themselves; everyone else by IP.
     */
    private function identify(ServerRequestInterface $request): string
    {
        $user = AuthenticationMiddleware::optional($request);

        if ($user !== null) {
            return 'user:' . $user->id;
        }

        return 'ip:' . $this->clientIp($request);
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        $server = $request->getServerParams();

        // Only trust the proxy header when the app is actually behind one;
        // TRUSTED_PROXY is off by default so a client cannot spoof its way
        // into a fresh bucket by sending X-Forwarded-For.
        if (filter_var($server['TRUSTED_PROXY'] ?? false, FILTER_VALIDATE_BOOL)) {
            $forwarded = $request->getHeaderLine('X-Forwarded-For');

            if ($forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);

                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }

        return (string) ($server['REMOTE_ADDR'] ?? 'unknown');
    }
}
