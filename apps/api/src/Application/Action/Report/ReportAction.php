<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Report;

use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Report\ReportCatalogue;
use Central\Api\Domain\Report\ReportCriteria;
use Central\Api\Domain\Report\ReportDefinition;
use Central\Api\Domain\Report\ReportKey;
use Central\Api\Domain\Report\ReportRepository;
use Central\Api\Domain\Report\ReportScope;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Shared plumbing for the reporting endpoints — and the place two live
 * defects are closed.
 *
 * **The tenant comes from the token.** `api/reports.php` reads `tenant_id`
 * from the request body and hands it to `setTenant()`, so a valid user of one
 * organisation can report on another's learners. Here {@see scope()} is the
 * only way to obtain one and it reads the authenticated caller.
 *
 * **The permission is checked when the report runs.** In the legacy each
 * report carried an `audience` list that filtered the catalogue listing and
 * was never consulted by `runReport()` — so naming a key was enough to run the
 * executive dashboard or the audit trail. Here {@see definition()} refuses
 * first.
 */
abstract class ReportAction
{
    public function __construct(
        protected readonly ReportCatalogue $catalogue,
        protected readonly ReportRepository $reports,
    ) {
    }

    final protected function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    /**
     * @throws AccessDenied
     */
    final protected function tenantId(ServerRequestInterface $request): string
    {
        $user = $this->caller($request);

        if ($user->tenantId === null) {
            throw new AccessDenied(Permission::ReportView->value);
        }

        return $user->tenantId;
    }

    final protected function userId(ServerRequestInterface $request): string
    {
        return $this->caller($request)->id;
    }

    /**
     * The rows this caller may see.
     *
     * Built from the token. There is no parameter and no setter.
     */
    final protected function scope(ServerRequestInterface $request): ReportScope
    {
        return ReportScope::forCaller($this->caller($request), $this->tenantId($request));
    }

    /**
     * The report, if the caller may run it.
     *
     * @throws EntityNotFound when the key is not a report
     * @throws AccessDenied   when it is one they may not run
     */
    final protected function definition(ServerRequestInterface $request, string $key): ReportDefinition
    {
        $reportKey = ReportKey::tryFrom(trim($key)) ?? throw new EntityNotFound('Report', $key);

        $definition = $this->catalogue->find($reportKey);

        $this->caller($request)->mustHave($definition->permission);

        return $definition;
    }

    /**
     * Criteria for one run, with the scope narrowed if the report is about a
     * named person.
     *
     * The narrowing happens here rather than in the query, so "may you see
     * this learner" is answered once at the edge instead of being re-derived
     * — and identically — by every report that takes a `learnerId`.
     *
     * @param array<string, mixed> $input
     *
     * @throws ValidationFailed
     * @throws AccessDenied
     */
    final protected function criteria(
        ServerRequestInterface $request,
        ReportDefinition $definition,
        array $input,
    ): ReportCriteria {
        $violations = [];

        $missing = $definition->missing($input);

        foreach ($missing as $name) {
            $violations[$name] = 'This report needs it.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $scope = $this->scope($request);

        if (in_array('learnerId', $definition->requires, true)) {
            $learnerId = trim((string) ($input['learnerId'] ?? ''));

            if (!$this->reports->scopeCovers($scope, $learnerId)) {
                // The same answer whether the person is in another tenant, is
                // deleted, or simply is not theirs to see — so trying ids
                // teaches nothing.
                throw new AccessDenied(Permission::ReportExport->value);
            }

            $scope = $scope->narrowedTo($learnerId);
        }

        $criteria = ReportCriteria::fromInput($scope, $input, $violations);

        if ($criteria === null) {
            throw ValidationFailed::fromArray($violations);
        }

        return $criteria;
    }

    /**
     * A note explaining a narrowed view, or null when there is nothing to say.
     *
     * A team lead reading a completion rate of 40% should know it is 40% of
     * their own people rather than of the organisation. A figure without its
     * denominator is how a report misleads while every number in it is right.
     */
    final protected function scopeNote(ReportScope $scope): ?string
    {
        if ($scope->subjectId !== null) {
            return null;
        }

        return $scope->tenantWide
            ? null
            : 'These figures cover you and the people who report to you.';
    }

    /**
     * @return array<string, mixed>
     */
    final protected function body(ServerRequestInterface $request): array
    {
        return (array) ($request->getParsedBody() ?? []);
    }

    /**
     * Query parameters and body merged, so a report runs the same from a GET
     * link and a POST form.
     *
     * @return array<string, mixed>
     */
    final protected function input(ServerRequestInterface $request): array
    {
        return [...$request->getQueryParams(), ...$this->body($request)];
    }
}
