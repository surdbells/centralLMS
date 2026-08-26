<?php

declare(strict_types=1);

namespace Central\Api\Application\Middleware;

use Central\Api\Application\Handler\JsonErrorHandler;
use Central\Api\Domain\Audit\AuditEvent;
use Central\Api\Domain\Audit\AuditRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;

/**
 * Records every request that changed something.
 *
 * One middleware, not a call in each action. The legacy wrote to
 * `user_activity_log` from exactly one place — the login handler — because
 * every other write would have had to remember to, and none of them did. Here
 * the trail is derived from what the router matched, so an endpoint added
 * tomorrow is audited the moment it is routed and cannot describe itself as
 * something other than what it is.
 *
 * What is recorded and what is not:
 *
 *  - **Mutating methods only.** A GET that reads a list is not an event; if
 *    every read were recorded the trail would be mostly noise and the writes
 *    would be impossible to find.
 *  - **Failures included.** Four hundred refused attempts to suspend the same
 *    account is the single most interesting thing this table can show, and a
 *    trail of only successes cannot show it.
 *  - **Never the request body.** It carries passwords. An audit trail that
 *    becomes the largest store of plaintext credentials in the system is worse
 *    than none.
 *
 * Sits inside {@see AuthenticationMiddleware} so it can see who was calling,
 * and inside the routing middleware so it can see what was matched.
 */
final readonly class AuditMiddleware implements MiddlewareInterface
{
    private const MUTATING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private AuditRepository $audit)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $response = $handler->handle($request);
        } catch (\Throwable $failure) {
            // A refusal is thrown, not returned: the error handler that turns
            // it into a response is *outside* this middleware, so waiting for
            // a response would mean recording only the requests that worked —
            // and four hundred refused attempts to suspend the same account is
            // the single most interesting thing this table can show.
            $this->recordIfInteresting($request, JsonErrorHandler::statusFor($failure));

            throw $failure;
        }

        $this->recordIfInteresting($request, $response->getStatusCode(), $response);

        return $response;
    }

    private function recordIfInteresting(
        ServerRequestInterface $request,
        int $status,
        ?ResponseInterface $response = null,
    ): void {
        if (!in_array(strtoupper($request->getMethod()), self::MUTATING, true)) {
            return;
        }

        $caller = AuthenticationMiddleware::optional($request);

        // Nothing to attribute and no tenant to file it under. Sign-in itself
        // is unauthenticated, so it is not recorded here — the sessions list
        // on somebody's own account is the record of that, and it carries the
        // browser and the address.
        if ($caller === null || $caller->tenantId === null) {
            return;
        }

        $route = RouteContext::fromRequest($request)->getRoute();
        $name  = $route?->getName();

        // An unnamed route is one nobody has decided what to call. Recording
        // it as its path would put ids into the action column and make the
        // filter useless.
        if ($name === null || $name === '') {
            return;
        }

        $this->audit->record(new AuditEvent(
            id: null,
            tenantId: $caller->tenantId,
            actorId: $caller->id,
            // The address, not the name: the name is joined from `users` when
            // the trail is read, so it shows what somebody is called now
            // rather than what they were called at the time.
            actorEmail: $caller->email,
            action: $name,
            method: strtoupper($request->getMethod()),
            path: $request->getUri()->getPath(),
            // What was created wins over what was in the path: `POST
            // /courses/{courseId}/modules` is about the module it just made,
            // and the course id in the path is only where it was made.
            subjectId: $this->createdId($status, $response)
                ?? $this->subject($route?->getArguments() ?? []),
            status: $status,
            ipAddress: $this->clientAddress($request),
            userAgent: $this->trim($request->getHeaderLine('User-Agent'), 512),
            requestId: $this->trim(
                (string) ($request->getAttribute(RequestIdMiddleware::ATTRIBUTE) ?? ''),
                64,
            ),
        ));
    }

    /**
     * The thing acted on, when the route named one.
     *
     * `id` first, then the other uuid-shaped argument — `/courses/{courseId}/
     * modules/{id}` is about the module, and `/courses/{id}/enroll` is about
     * the course.
     *
     * @param array<string, string> $arguments
     */
    private function subject(array $arguments): ?string
    {
        foreach (['id', ...array_keys($arguments)] as $key) {
            $value = $arguments[$key] ?? null;

            if (is_string($value) && preg_match('/^[0-9a-f-]{36}$/i', $value) === 1) {
                return $value;
            }
        }

        return null;
    }

    /**
     * The id of a thing that has just been created.
     *
     * A create has no id in its path — there was nothing to name yet — so
     * without this the one event that brought a course into existence would be
     * the only one missing from "everything that happened to this course".
     * Read from the response envelope, which is the only place it exists.
     *
     * Confined to 201s with a JSON body: a 200 carries the record that was
     * already there, and its id is already in the path.
     */
    private function createdId(int $status, ?ResponseInterface $response): ?string
    {
        if ($status !== 201 || $response === null) {
            return null;
        }

        if (!str_contains($response->getHeaderLine('Content-Type'), 'json')) {
            return null;
        }

        $body = $response->getBody();

        if (!$body->isSeekable() || $body->getSize() > 65_536) {
            return null;
        }

        try {
            $body->rewind();
            $decoded = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);
            $body->rewind();
        } catch (\Throwable) {
            // Nothing here is worth failing a request that already succeeded.
            return null;
        }

        $id = is_array($decoded) && is_array($decoded['data'] ?? null) ? $decoded['data']['id'] ?? null : null;

        return is_string($id) && preg_match('/^[0-9a-f-]{36}$/i', $id) === 1 ? $id : null;
    }

    /**
     * The address the request came from.
     *
     * `X-Forwarded-For` is honoured only for its first entry, and only when it
     * parses as an address — the header is client-supplied, and a trail that
     * records whatever it says is a trail somebody can write for you.
     */
    private function clientAddress(ServerRequestInterface $request): ?string
    {
        $forwarded = trim(explode(',', $request->getHeaderLine('X-Forwarded-For'))[0]);

        if ($forwarded !== '' && filter_var($forwarded, FILTER_VALIDATE_IP) !== false) {
            return $forwarded;
        }

        $server = $request->getServerParams();
        $remote = is_string($server['REMOTE_ADDR'] ?? null) ? $server['REMOTE_ADDR'] : '';

        return filter_var($remote, FILTER_VALIDATE_IP) === false ? null : $remote;
    }

    private function trim(string $value, int $length): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
