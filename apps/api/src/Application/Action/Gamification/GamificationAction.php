<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Gamification;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Gamification\Badge;
use Central\Api\Domain\Gamification\GamificationLevel;
use Central\Api\Domain\Gamification\GamificationRepository;
use Central\Api\Domain\Gamification\LeaderboardEntry;
use Central\Api\Domain\Gamification\LeaderboardPeriod;
use Central\Api\Domain\Gamification\PointEvent;
use Central\Api\Domain\Gamification\PointReason;
use Central\Api\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Points, badges, levels and standings.
 *
 * GET    /v1/me/gamification         points, level and badges, for the account page
 * GET    /v1/me/gamification/history where the points came from
 * GET    /v1/leaderboard             the standings, and the reader's place in them
 * GET    /v1/badges
 * POST   /v1/badges
 * PUT    /v1/badges/{id}
 * DELETE /v1/badges/{id}
 * POST   /v1/users/{userId}/badges/{id}
 * DELETE /v1/users/{userId}/badges/{id}
 * GET    /v1/gamification/levels
 * PUT    /v1/gamification/levels
 * POST   /v1/users/{userId}/points
 *
 * Reading your own standing needs no permission; anything that grants
 * something needs `user.manage`, because points and badges are what the
 * leaderboard ranks people by.
 */
final class GamificationAction
{
    public function __construct(
        private readonly GamificationRepository $gamification,
        private readonly Clock $clock,
    ) {
    }

    // --- the account page -------------------------------------------------

    public function mine(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->tenantId($request);
        $userId   = $this->caller($request)->id;

        $points   = $this->gamification->totalPoints($tenantId, $userId);
        $progress = $this->gamification->ladder($tenantId)->progressFor($points);

        $breakdown = [];

        foreach ($this->gamification->pointsByReason($tenantId, $userId) as $reason => $earned) {
            $parsed = PointReason::tryFrom($reason);

            $breakdown[] = [
                'reason' => $reason,
                'label'  => $parsed?->label() ?? $reason,
                'points' => $earned,
            ];
        }

        $badges = $this->gamification->badgesFor($tenantId, $userId);

        return Json::ok($response, [
            'points'    => $points,
            'level'     => $progress->toArray(),
            'breakdown' => $breakdown,
            'badges'    => array_map(static fn (Badge $b): array => $b->toArray(), $badges),
            'badgeCount' => count($badges),
            // Their own place, which the top-ten list would not show them.
            'rank'      => $this->gamification
                ->rankOf($tenantId, $userId, LeaderboardPeriod::AllTime, $this->clock->now())
                ?->toArray(),
        ]);
    }

    public function history(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->tenantId($request);
        $listing  = $this->listing($request);

        $result = $this->gamification->history(
            $tenantId,
            $this->caller($request)->id,
            $listing['page'],
            $listing['perPage'],
        );

        return Json::page(
            $response,
            array_map(static fn (PointEvent $e): array => $e->toArray(), $result['items']),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
        );
    }

    // --- standings --------------------------------------------------------

    public function leaderboard(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query    = $request->getQueryParams();
        $tenantId = $this->tenantId($request);
        $viewerId = $this->caller($request)->id;

        $period = LeaderboardPeriod::tryFrom(strtolower(trim((string) ($query['period'] ?? 'all_time'))));

        if ($period === null) {
            throw ValidationFailed::fromArray([
                'period' => 'Choose one of: ' . implode(', ', LeaderboardPeriod::values()) . '.',
            ]);
        }

        $limit = max(1, min(100, (int) ($query['limit'] ?? 20)));
        $now   = $this->clock->now();

        $entries = $this->gamification->leaderboard(
            $tenantId,
            $period,
            $now,
            $limit,
            $viewerId,
            isset($query['departmentId']) ? (string) $query['departmentId'] : null,
        );

        $viewerIsListed = false;

        foreach ($entries as $entry) {
            if ($entry->isViewer) {
                $viewerIsListed = true;

                break;
            }
        }

        return Json::ok($response, [
            'period'      => $period->value,
            'periodLabel' => $period->label(),
            'entries'     => array_map(static fn (LeaderboardEntry $e): array => $e->toArray(), $entries),
            // Sent separately when they are off the end. A board showing the
            // top twenty tells the person in twenty-first place nothing.
            'viewer'      => $viewerIsListed
                ? null
                : $this->gamification->rankOf($tenantId, $viewerId, $period, $now)?->toArray(),
        ]);
    }

    // --- badges -----------------------------------------------------------

    public function badges(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $listing = $this->listing($request);

        $result = $this->gamification->badges(
            $this->tenantId($request),
            $listing['page'],
            $listing['perPage'],
        );

        return Json::page(
            $response,
            array_map(static fn (Badge $b): array => $b->toArray(), $result['items']),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function saveBadge(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = [],
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::UserManage);

        $tenantId = $this->tenantId($request);
        $id       = $args['id'] ?? null;

        $existing = $id === null
            ? null
            : ($this->gamification->findBadge($tenantId, $id) ?? throw new EntityNotFound('Badge', $id));

        $violations = [];
        $badge      = Badge::fromInput($tenantId, $id, $this->body($request), $violations, $existing);

        if ($badge === null) {
            throw ValidationFailed::fromArray($violations);
        }

        if ($this->gamification->badgeNameExists($tenantId, $badge->name, $id)) {
            throw new ConflictingState(
                sprintf('A badge called "%s" already exists.', $badge->name),
                'duplicate_name',
            );
        }

        $saved = $this->gamification->saveBadge($badge);

        return $id === null
            ? Json::created($response, $saved->toArray(), '/v1/badges/' . $saved->id)
            : Json::ok($response, $saved->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function deleteBadge(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::UserManage);

        if (!$this->gamification->deleteBadge($this->tenantId($request), $args['id'] ?? '')) {
            throw new EntityNotFound('Badge', $args['id'] ?? '');
        }

        return Json::noContent($response);
    }

    /**
     * @param array<string, string> $args
     */
    public function awardBadge(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::UserManage);

        $tenantId = $this->tenantId($request);
        $badgeId  = $args['id'] ?? '';
        $userId   = $args['userId'] ?? '';

        $this->gamification->findBadge($tenantId, $badgeId)
            ?? throw new EntityNotFound('Badge', $badgeId);

        $awarded = $this->gamification->awardBadge(
            $tenantId,
            $userId,
            $badgeId,
            $this->caller($request)->id,
        );

        // Not a conflict: two things completing at once both legitimately
        // try, and "they already have it" is the same end state.
        return Json::ok($response, ['badgeId' => $badgeId, 'userId' => $userId, 'awarded' => $awarded]);
    }

    /**
     * @param array<string, string> $args
     */
    public function revokeBadge(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::UserManage);

        if (
            !$this->gamification->revokeBadge(
                $this->tenantId($request),
                $args['userId'] ?? '',
                $args['id'] ?? '',
            )
        ) {
            throw new EntityNotFound('Badge award', $args['id'] ?? '');
        }

        return Json::noContent($response);
    }

    // --- the ladder -------------------------------------------------------

    public function levels(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::ok(
            $response,
            array_map(
                static fn (GamificationLevel $l): array => $l->toArray(),
                $this->gamification->ladder($this->tenantId($request))->levels,
            ),
        );
    }

    /**
     * Replaces the whole ladder.
     *
     * Sent as one list rather than a rung at a time: the thresholds have to be
     * consistent with each other, and editing them one by one goes through
     * states where two rungs need the same points.
     */
    public function saveLevels(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::UserManage);

        $tenantId = $this->tenantId($request);
        $body     = $this->body($request);
        $rungs    = $body['levels'] ?? null;

        if (!is_array($rungs)) {
            throw ValidationFailed::fromArray(['levels' => 'Send the whole ladder as a list.']);
        }

        $violations = [];
        $levels     = [];
        $thresholds = [];
        $numbers    = [];

        foreach (array_values($rungs) as $index => $rung) {
            if (!is_array($rung)) {
                $violations['levels.' . $index] = 'Each rung is an object.';

                continue;
            }

            $name      = trim((string) ($rung['name'] ?? ''));
            $number    = (int) ($rung['number'] ?? $index + 1);
            $required  = (int) ($rung['requiredPoints'] ?? 0);

            if ($name === '') {
                $violations['levels.' . $index . '.name'] = 'A level needs a name.';
            }

            if ($number < 1) {
                $violations['levels.' . $index . '.number'] = 'Numbering starts at 1.';
            }

            if ($required < 0) {
                $violations['levels.' . $index . '.requiredPoints'] = 'A threshold cannot be negative.';
            }

            if (in_array($required, $thresholds, true)) {
                // Two rungs at the same threshold leaves no answer for
                // somebody standing on it.
                $violations['levels.' . $index . '.requiredPoints'] = 'Another level already needs that many points.';
            }

            if (in_array($number, $numbers, true)) {
                $violations['levels.' . $index . '.number'] = 'Another level already has that number.';
            }

            $thresholds[] = $required;
            $numbers[]    = $number;

            $levels[] = new GamificationLevel(
                id: isset($rung['id']) ? (string) $rung['id'] : null,
                tenantId: $tenantId,
                number: $number,
                name: $name,
                requiredPoints: $required,
                iconUrl: isset($rung['iconUrl']) ? (string) $rung['iconUrl'] : null,
                rewards: isset($rung['rewards']) ? (string) $rung['rewards'] : null,
            );
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        // Anything not sent back is gone. That is what "replaces the whole
        // ladder" means, and it is why the endpoint takes all of it.
        $keep = [];

        foreach ($levels as $level) {
            $keep[] = $this->gamification->saveLevel($level)->id;
        }

        foreach ($this->gamification->ladder($tenantId)->levels as $existing) {
            if (!in_array($existing->id, $keep, true)) {
                $this->gamification->deleteLevel($tenantId, (string) $existing->id);
            }
        }

        return Json::ok(
            $response,
            array_map(
                static fn (GamificationLevel $l): array => $l->toArray(),
                $this->gamification->ladder($tenantId)->levels,
            ),
        );
    }

    // --- granting by hand -------------------------------------------------

    /**
     * @param array<string, string> $args
     */
    public function awardPoints(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::UserManage);

        $body   = $this->body($request);
        $points = (int) ($body['points'] ?? 0);

        if ($points === 0) {
            // Zero is not an award. The column refuses it too; saying so here
            // gives a better message than a constraint violation.
            throw ValidationFailed::fromArray(['points' => 'Give a number of points, positive or negative.']);
        }

        $event = $this->gamification->award(new PointEvent(
            id: null,
            tenantId: $this->tenantId($request),
            userId: $args['userId'] ?? '',
            points: $points,
            reason: PointReason::Manual,
            description: isset($body['description']) ? (string) $body['description'] : null,
            awardedBy: $this->caller($request)->id,
        ));

        if ($event === null) {
            throw new ConflictingState('Those points have already been granted.', 'already_awarded');
        }

        return Json::created($response, $event->toArray());
    }

    // --- plumbing ---------------------------------------------------------

    private function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    private function tenantId(ServerRequestInterface $request): string
    {
        $user = $this->caller($request);

        if ($user->tenantId === null) {
            throw new AccessDenied(Permission::UserView->value);
        }

        return $user->tenantId;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        return (array) ($request->getParsedBody() ?? []);
    }

    /**
     * @return array{page: int, perPage: int}
     */
    private function listing(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();

        return [
            'page'    => max(1, (int) ($query['page'] ?? 1)),
            'perPage' => max(1, min(100, (int) ($query['perPage'] ?? 25))),
        ];
    }
}
