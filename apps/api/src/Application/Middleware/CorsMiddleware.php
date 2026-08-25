<?php

declare(strict_types=1);

namespace Central\Api\Application\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Cross-origin access for the three browser clients.
 *
 * The allowlist is explicit and echoed back per-request — never `*`. The API
 * is called with `Authorization` headers, and a wildcard origin combined with
 * credentials is exactly the configuration that turns any malicious page into
 * an authenticated client.
 */
final class CorsMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private array $allowedOrigins;

    /** @param array<string, mixed> $config the `cors` section of settings.php */
    public function __construct(private readonly array $config)
    {
        $this->allowedOrigins = array_values(array_map(
            static fn (string $o): string => rtrim($o, '/'),
            (array) ($config['allowed_origins'] ?? []),
        ));
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = rtrim($request->getHeaderLine('Origin'), '/');

        // Preflight is answered here and never reaches the router, so an
        // OPTIONS request to an authenticated route does not 401.
        $response = strtoupper($request->getMethod()) === 'OPTIONS'
            ? new Response(204)
            : $handler->handle($request);

        if ($origin === '' || !$this->isAllowed($origin)) {
            return $response;
        }

        return $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Allow-Methods', implode(', ', (array) $this->config['allowed_methods']))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', (array) $this->config['allowed_headers']))
            ->withHeader('Access-Control-Expose-Headers', implode(', ', (array) $this->config['expose_headers']))
            ->withHeader('Access-Control-Max-Age', (string) $this->config['max_age'])
            // Responses differ by Origin; without this a shared cache can serve
            // one tenant's CORS headers to another origin.
            ->withHeader('Vary', 'Origin');
    }

    private function isAllowed(string $origin): bool
    {
        foreach ($this->allowedOrigins as $allowed) {
            if ($allowed === $origin) {
                return true;
            }

            // Supports a single leading wildcard for tenant subdomains,
            // e.g. https://*.centrallms.app
            if (str_starts_with($allowed, 'https://*.') || str_starts_with($allowed, 'http://*.')) {
                $suffix = substr($allowed, strpos($allowed, '*.') + 1);
                $scheme = substr($allowed, 0, strpos($allowed, '://') + 3);

                if (str_starts_with($origin, $scheme) && str_ends_with($origin, $suffix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
