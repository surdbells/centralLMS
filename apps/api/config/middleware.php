<?php

/**
 * The global middleware stack.
 *
 * Slim runs middleware last-added-first, so this list executes bottom-up on
 * the way in. The order below is deliberate:
 *
 *   RequestId   outermost — every log line and error response carries the id,
 *               including ones produced by the middleware beneath it
 *   Cors        answers preflight before anything can reject it
 *   RateLimit   rejects abuse before any parsing work is done
 *   BodyParser  innermost — only reached by requests that will be handled
 *
 * Routing and the error handler are added last so they wrap everything.
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
    $app->add(RateLimitMiddleware::class);
    $app->add(CorsMiddleware::class);
    $app->add(RequestIdMiddleware::class);

    $app->addRoutingMiddleware();

    // Must be added after routing so that HttpNotFoundException reaches it.
    $errorMiddleware = $app->addErrorMiddleware(
        displayErrorDetails: (bool) $settings['app']['debug'],
        logErrors: true,
        logErrorDetails: true,
        logger: $container->get(\Psr\Log\LoggerInterface::class),
    );

    $errorMiddleware->setDefaultErrorHandler($container->get(JsonErrorHandler::class));
};
