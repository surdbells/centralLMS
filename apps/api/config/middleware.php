<?php

/**
 * The global middleware stack.
 *
 * Slim runs middleware last-added-first, so this list executes bottom-up on
 * the way in. Reading the calls below from the bottom gives the inbound order:
 *
 *   RequestId   outermost — every log line and error response carries the id,
 *               including ones produced by the middleware beneath it
 *   Cors        answers preflight and returns, before anything can reject it
 *   Error       turns a throwable into JSON
 *   RateLimit   rejects abuse before any parsing work is done
 *   Routing     resolves the route
 *   BodyParser  innermost — only reached by requests that will be handled
 *
 * **Routing sits beneath Cors on purpose.** A browser preflights with OPTIONS,
 * which no route declares, so routing answers 405 — and if it runs first, that
 * 405 is what the browser gets, carrying no `Access-Control-Allow-Origin`. The
 * request that follows is then never sent, and every call from the SPA fails
 * with an opaque CORS error. Cors has to see OPTIONS first.
 *
 * **Cors sits outside the error handler, and that is not cosmetic.** A response
 * the error handler produces never travels back through the middleware beneath
 * it, so with Cors underneath, every 401, 403, 404, 409 and 422 arrived at the
 * browser with no `Access-Control-Allow-Origin` — which a browser reports not
 * as the refusal it is but as an opaque network failure. Every validation
 * message in the application read "could not reach the server". curl does not
 * enforce CORS, so no amount of testing with it would have shown this.
 *
 * The error handler still sits outside routing, so an unmatched path is still
 * turned into JSON.
 */

declare(strict_types=1);

use Central\Api\Application\Handler\JsonErrorHandler;
use Central\Api\Application\Middleware\CorsMiddleware;
use Central\Api\Application\Middleware\JsonBodyParserMiddleware;
use Central\Api\Application\Middleware\RateLimitMiddleware;
use Central\Api\Application\Middleware\RequestIdMiddleware;
use Psr\Container\ContainerInterface;
use Slim\App;

return static function (App $app, ContainerInterface $container): void {
    $settings = $container->get('settings');

    $app->add(JsonBodyParserMiddleware::class);
    $app->addRoutingMiddleware();
    $app->add(RateLimitMiddleware::class);

    // Added after routing so that HttpNotFoundException reaches it, and before
    // Cors so that Cors ends up outside it — see above.
    $errorMiddleware = $app->addErrorMiddleware(
        displayErrorDetails: (bool) $settings['app']['debug'],
        logErrors: true,
        logErrorDetails: true,
        logger: $container->get(\Psr\Log\LoggerInterface::class),
    );

    $errorMiddleware->setDefaultErrorHandler($container->get(JsonErrorHandler::class));

    $app->add(CorsMiddleware::class);
    $app->add(RequestIdMiddleware::class);
};
