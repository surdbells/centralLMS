<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Assessment\Attempt;
use Central\Api\Domain\Assessment\AttemptRepository;
use Central\Api\Domain\Assessment\GradingAssignment;
use Central\Api\Domain\Assessment\GradingAssignmentRepository;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Who is marking what.
 *
 * **The queue itself is derived** — it is the attempts holding an ungraded
 * written answer, and `AttemptRepository::awaitingGrading()` answers that.
 * Storing it would be a cache going stale the moment somebody submits or a
 * marker finishes, which is why there is no queue table and nothing here
 * writes one.
 *
 * What this does store is the part nobody can work out: who a coordinator
 * handed a paper to, what order they want them done in, and whether a marker
 * set one aside. Those are decisions, not derivations.
 */
final class GradingQueueAction
{
    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly GradingAssignmentRepository $assignments,
    ) {
    }

    /**
     * The queue, with each paper's assignment attached where it has one.
     *
     * `?mine=1` narrows it to the caller's own, which is what a marker wants
     * and a coordinator does not.
     */
    public function queue(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::AssessmentGrade);

        $tenantId = $this->tenantId($request);
        $query = $request->getQueryParams();

        $waiting = $this->attempts->awaitingMarking($tenantId, 1, 200);
        /** @var list<array{attempt: Attempt, awaiting: int}> $items */
        $items = $waiting['items'];

        $assignments = $this->assignments->forAttempts(
            $tenantId,
            array_map(static fn (array $line): string => $line['attempt']->id, $items),
        );

        $mineOnly = ($query['mine'] ?? null) === '1';
        $unassignedOnly = ($query['unassigned'] ?? null) === '1';

        $rows = [];

        foreach ($items as $line) {
            $attempt = $line['attempt'];
            $assignment = $assignments[$attempt->id] ?? null;

            if ($mineOnly && ($assignment === null || $assignment->assignedTo !== $caller->id)) {
                continue;
            }

            if ($unassignedOnly && $assignment !== null && $assignment->isOpen()) {
                continue;
            }

            $rows[] = $attempt->toArray([
                // How many answers in this paper are still unmarked — the
                // figure that says whether it is nearly done or untouched.
                'awaitingMarking' => $line['awaiting'],
                'assignment'      => $assignment?->toArray(),
            ]);
        }

        // Highest priority first, then longest waiting — a coordinator's
        // ordering beats the clock, and the clock decides everything else.
        usort($rows, static function (array $a, array $b): int {
            $byPriority = ($b['assignment']['priority'] ?? 0) <=> ($a['assignment']['priority'] ?? 0);

            if ($byPriority !== 0) {
                return $byPriority;
            }

            return ($a['submittedAt'] ?? '') <=> ($b['submittedAt'] ?? '');
        });

        return Json::ok($response, [
            'items' => $rows,
            'total' => count($rows),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function assign(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::AssessmentGrade);

        $tenantId = $this->tenantId($request);
        $attemptId = $this->requireAttempt($request, $args['id'] ?? '');
        $body = $this->body($request);

        $assignedTo = trim((string) ($body['assignedTo'] ?? ''));

        if ($assignedTo === '') {
            throw ValidationFailed::fromArray(['assignedTo' => 'Say who should mark this.']);
        }

        return Json::ok($response, $this->assignments->assign(
            $tenantId,
            $attemptId,
            $assignedTo,
            $caller->id,
            (int) ($body['priority'] ?? 0),
            is_string($body['notes'] ?? null) ? trim($body['notes']) : null,
        )->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function release(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentGrade);

        $this->assignments->release(
            $this->tenantId($request),
            $this->requireAttempt($request, $args['id'] ?? ''),
        );

        return Json::noContent($response);
    }

    /**
     * @param array<string, string> $args
     */
    public function skip(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentGrade);

        $tenantId = $this->tenantId($request);
        $attemptId = $this->requireAttempt($request, $args['id'] ?? '');
        $reason = is_string($this->body($request)['reason'] ?? null)
            ? trim($this->body($request)['reason'])
            : null;

        $skipped = $this->assignments->skip($tenantId, $attemptId, $reason);

        if ($skipped === null) {
            throw new EntityNotFound('Grading assignment', $attemptId);
        }

        return Json::ok($response, $skipped->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function prioritise(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentGrade);

        $tenantId = $this->tenantId($request);
        $attemptId = $this->requireAttempt($request, $args['id'] ?? '');

        $updated = $this->assignments->setPriority(
            $tenantId,
            $attemptId,
            (int) ($this->body($request)['priority'] ?? 0),
        );

        if ($updated === null) {
            throw new EntityNotFound('Grading assignment', $attemptId);
        }

        return Json::ok($response, $updated->toArray());
    }

    /**
     * Shares a batch out between markers.
     *
     * Round-robin, and deliberately not "everything to whoever has least":
     * that needs a count of open work per marker, which is another derived
     * figure, and two coordinators assigning at once would both read it stale.
     */
    public function assignMany(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::AssessmentGrade);

        $body = $this->body($request);
        $attemptIds = array_values(array_filter(
            (array) ($body['attemptIds'] ?? []),
            static fn (mixed $v): bool => is_string($v) && $v !== '',
        ));
        $markerIds = array_values(array_filter(
            (array) ($body['markerIds'] ?? []),
            static fn (mixed $v): bool => is_string($v) && $v !== '',
        ));

        $violations = [];

        if ($attemptIds === []) {
            $violations['attemptIds'] = 'Choose at least one paper.';
        }

        if ($markerIds === []) {
            $violations['markerIds'] = 'Choose at least one marker.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $assigned = $this->assignments->assignEvenly(
            $this->tenantId($request),
            $attemptIds,
            $markerIds,
            $caller->id,
        );

        return Json::ok($response, [
            'assigned' => $assigned,
            'markers'  => count($markerIds),
        ]);
    }

    // --- internals ----------------------------------------------------------

    private function requireAttempt(ServerRequestInterface $request, string $id): string
    {
        $attempt = $this->attempts->find($this->tenantId($request), $id);

        if ($attempt === null) {
            throw new EntityNotFound('Attempt', $id);
        }

        return $attempt->id;
    }

    private function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    private function tenantId(ServerRequestInterface $request): string
    {
        $user = $this->caller($request);

        if ($user->tenantId === null) {
            throw new AccessDenied(Permission::AssessmentGrade->value);
        }

        return $user->tenantId;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }
}
