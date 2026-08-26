<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Application\Middleware;

use Central\Api\Application\Middleware\CorsMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The browser preflight, through the real middleware stack.
 *
 * This exists because the stack was wrong and the docblock said it was right.
 * `addRoutingMiddleware()` was added after the CORS middleware, and Slim runs
 * middleware last-added-first — so routing ran first inbound, answered the
 * OPTIONS request with 405 (no route declares OPTIONS), and the response
 * carried no `Access-Control-Allow-Origin`. The browser then never sent the
 * real request, and every call from the SPA failed with an opaque CORS error.
 *
 * Testing the middleware alone would not have caught it: in isolation it
 * answers preflight correctly. What was broken was the order.
 */
#[CoversClass(CorsMiddleware::class)]
final class CorsPreflightTest extends TestCase
{
    private const ORIGIN = 'http://localhost:4200';

    /**
     * @param  array<string, mixed>        $overrides
     * @return App<ContainerInterface|null>
     */
    private function app(array $overrides = []): App
    {
        $app = AppFactory::create();

        $cors = new CorsMiddleware([
            'allowed_origins' => [self::ORIGIN],
            'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
            'allowed_headers' => ['Authorization', 'Content-Type'],
            'expose_headers'  => ['X-Request-Id'],
            'max_age'         => 600,
            ...$overrides,
        ]);

        // The same order as config/middleware.php: routing is added first so
        // that inbound it runs *after* CORS.
        $app->addRoutingMiddleware();
        $app->add($cors);

        $app->post('/v1/auth/login', static function (
            ServerRequestInterface $request,
            ResponseInterface $response,
        ): ResponseInterface {
            $response->getBody()->write('{"data":{}}');

            return $response;
        });

        return $app;
    }

    private function preflight(string $path, string $origin = self::ORIGIN): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('OPTIONS', $path)
            ->withHeader('Origin', $origin)
            ->withHeader('Access-Control-Request-Method', 'POST');

        return $this->app()->handle($request);
    }

    #[Test]
    public function a_preflight_is_answered_rather_than_routed(): void
    {
        // 405 here means routing saw it first, and a 405 with no CORS headers
        // is what makes every request from the browser fail.
        $response = $this->preflight('/v1/auth/login');

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function a_preflight_carries_the_headers_the_browser_needs(): void
    {
        $response = $this->preflight('/v1/auth/login');

        self::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('POST', $response->getHeaderLine('Access-Control-Allow-Methods'));
        self::assertStringContainsString('Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
    }

    #[Test]
    public function a_preflight_to_a_path_no_route_declares_is_still_answered(): void
    {
        // Otherwise a typo in a client path produces a CORS error rather than
        // the 404 that would say what is actually wrong.
        $response = $this->preflight('/v1/nothing-here');

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function an_origin_that_is_not_allowed_gets_no_cors_headers(): void
    {
        // The allowlist is echoed back per-request and never `*`: the API is
        // called with Authorization headers, and a wildcard origin with
        // credentials turns any page into an authenticated client.
        $response = $this->preflight('/v1/auth/login', 'https://evil.example');

        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function a_real_request_still_reaches_its_route(): void
    {
        // Moving routing beneath CORS must not stop ordinary requests being
        // routed.
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/v1/auth/login')
            ->withHeader('Origin', self::ORIGIN);

        $response = $this->app()->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"data":{}}', (string) $response->getBody());
        self::assertSame(self::ORIGIN, $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function the_response_varies_by_origin(): void
    {
        // Without it a shared cache can serve one origin's CORS headers to
        // another.
        $response = $this->preflight('/v1/auth/login');

        self::assertStringContainsString('Origin', $response->getHeaderLine('Vary'));
    }

    #[Test]
    public function a_request_with_no_origin_is_left_alone(): void
    {
        // curl, a server-to-server call, a health check. Adding CORS headers
        // to those is noise at best.
        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };

        $middleware = new CorsMiddleware([
            'allowed_origins' => [self::ORIGIN],
            'allowed_methods' => ['GET'],
            'allowed_headers' => ['Content-Type'],
            'expose_headers'  => [],
            'max_age'         => 600,
        ]);

        $response = $middleware->process(
            (new ServerRequestFactory())->createServerRequest('GET', '/v1/health'),
            $handler,
        );

        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }
}
