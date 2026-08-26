<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;

/**
 * Which people's rows a caller is allowed to see.
 *
 * Separate from the permission on a report, and both apply. The permission
 * answers "may you run this at all"; the scope answers "over whom". A team
 * lead may legitimately run the overdue-training report — over their own
 * reports, not over the whole organisation.
 *
 * The legacy had neither. `setScope()` took `department_id`, `team_id` and
 * `learner_id` straight from the request body and used them as *filters* —
 * conveniences for narrowing a view, with nothing stopping a caller from
 * omitting them and seeing everybody.
 */
final readonly class ReportScope
{
    private function __construct(
        public string $tenantId,
        public string $viewerId,
        /**
         * True when the caller may see every person in the tenant. When it is
         * false, rows are limited to the viewer and their direct reports —
         * or, if `subjectId` is set, to that one person.
         */
        public bool $tenantWide,
        /** Set when the report is about one named person. */
        public ?string $subjectId = null,
    ) {
    }

    /**
     * The widest scope this caller is entitled to.
     *
     * Three tiers, and the middle one is the point: someone holding
     * `report.view` and nothing else sees themselves and the people who report
     * to them. Without that tier the only choices are "the whole organisation"
     * and "nothing", and a line manager gets one of the two.
     */
    public static function forCaller(AuthenticatedUser $caller, string $tenantId): self
    {
        // Seeing across the whole organisation is what these three are for.
        // `user.manage` is included because someone administering the people
        // records already sees every row this would show.
        $tenantWide = $caller->canAny([
            Permission::ReportExport,
            Permission::UserManage,
            Permission::EnrollmentManage,
        ]);

        return new self($tenantId, $caller->id, $tenantWide);
    }

    /**
     * A scope covering exactly one person.
     *
     * For the reports about a named learner. Narrowing to the subject means
     * the question "may you see this person" is answered once, at the edge,
     * rather than re-derived by every query.
     */
    public function narrowedTo(string $userId): self
    {
        return new self($this->tenantId, $this->viewerId, $this->tenantWide, $userId);
    }

    /**
     * Whether the caller may see rows about this person.
     */
    public function covers(string $userId, ?string $theirManagerId): bool
    {
        if ($this->tenantWide) {
            return true;
        }

        return $userId === $this->viewerId || $theirManagerId === $this->viewerId;
    }

    /**
     * Whether every row in the tenant is in scope.
     *
     * Used by the repository to decide whether a narrowing clause is needed
     * at all — not to decide whether to apply the tenant filter, which is
     * never optional.
     */
    public function isUnrestricted(): bool
    {
        return $this->tenantWide && $this->subjectId === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tenantWide' => $this->tenantWide,
            'viewerId'   => $this->viewerId,
            'subjectId'  => $this->subjectId,
        ];
    }
}
