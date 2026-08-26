<?php

declare(strict_types=1);

namespace Central\Api\Application\Handler;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\AuthenticationFailed;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\DomainException;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Interfaces\ErrorHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * Turns every throwable into the standard JSON error envelope.
 *
 * Two categories, treated very differently:
 *
 *  - Expected failures (DomainException, Slim routing errors) map to a 4xx
 *    with a stable `code`. They are not bugs and are not reported to Sentry.
 *  - Everything else is a bug: full detail to the log and Sentry, and a bare
 *    500 to the client with no internals leaked — stack traces are only
 *    included when APP_DEBUG is on.
 */
final class JsonErrorHandler implements ErrorHandlerInterface
{
    /** @var array<class-string<DomainException>, int> */
    private const DOMAIN_STATUS = [
        ValidationFailed::class      => 422,
        AuthenticationFailed::class  => 401,
        AccessDenied::class          => 403,
        EntityNotFound::class        => 404,
        ConflictingState::class      => 409,
    ];

    /**
     * The status a throwable will be answered with.
     *
     * Public because AuditMiddleware has to record the status of a request
     * that failed, and it sits inside the error handler — the exception has
     * not become a response yet when it passes through. One copy of the map,
     * read from two places, rather than two copies that drift.
     */
    public static function statusFor(\Throwable $exception): int
    {
        if ($exception instanceof DomainException) {
            return self::DOMAIN_STATUS[$exception::class] ?? 400;
        }

        return match (true) {
            $exception instanceof HttpNotFoundException         => 404,
            $exception instanceof HttpMethodNotAllowedException => 405,
            default                                            => 500,
        };
    }

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ResponseFactory $responseFactory,
        private readonly bool $debug = false,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        \Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        $response  = $this->responseFactory->createResponse();
        $requestId = (string) ($request->getAttribute('requestId') ?? '');

        if ($exception instanceof DomainException) {
            return $this->handleDomain($request, $response, $exception, $requestId);
        }

        if ($exception instanceof HttpNotFoundException) {
            return Json::error($response, 404, 'route_not_found', 'The requested endpoint does not exist.');
        }

        if ($exception instanceof HttpMethodNotAllowedException) {
            return Json::error(
                $response->withHeader('Allow', implode(', ', $exception->getAllowedMethods())),
                405,
                'method_not_allowed',
                'This endpoint does not accept that HTTP method.',
            );
        }

        // Anything reaching here is unexpected.
        $this->logger->error('Unhandled exception: {message}', [
            'message'   => $exception->getMessage(),
            'exception' => $exception,
            'requestId' => $requestId,
            'method'    => $request->getMethod(),
            'path'      => $request->getUri()->getPath(),
        ]);

        if (\function_exists('Sentry\captureException')) {
            \Sentry\captureException($exception);
        }

        $details = [];
        if ($this->debug) {
            $details = [
                'exception' => $exception::class,
                'message'   => $exception->getMessage(),
                'file'      => $exception->getFile() . ':' . $exception->getLine(),
                'trace'     => explode("\n", $exception->getTraceAsString()),
            ];
        }
        if ($requestId !== '') {
            $details['requestId'] = $requestId;
        }

        return Json::error(
            $response,
            500,
            'internal_error',
            'Something went wrong on our end. The incident has been logged.',
            $details,
        );
    }

    private function handleDomain(
        ServerRequestInterface $request,
        ResponseInterface $response,
        DomainException $exception,
        string $requestId,
    ): ResponseInterface {
        $status = self::statusFor($exception);

        // Failed auth is worth an audit trail even though it is not a bug.
        if ($exception instanceof AuthenticationFailed) {
            $this->logger->info('Authentication rejected', [
                'reason'    => $exception->reason(),
                'requestId' => $requestId,
                'path'      => $request->getUri()->getPath(),
                'ip'        => $request->getServerParams()['REMOTE_ADDR'] ?? null,
            ]);

            $response = $response->withHeader('WWW-Authenticate', 'Bearer');
        }

        $details = $exception->details();
        if ($requestId !== '') {
            $details['requestId'] = $requestId;
        }

        return Json::error($response, $status, $exception->errorCode(), $exception->getMessage(), $details);
    }
}
