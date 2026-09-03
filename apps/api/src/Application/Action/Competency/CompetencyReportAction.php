<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Competency;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Competency\CompetencyRepository;
use Central\Api\Domain\Exception\AccessDenied;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The competency reports section — the analytical views that read across a
 * cycle: readiness broken down by org axis, the gap-analysis table, the
 * critical-gap list, the proficiency spread, and how far the exercise has run.
 *
 * These sit alongside the outcome report (`CompetencyAssessmentAction::outcome`,
 * the headline) and the individual report (`::report`, one person). Everything
 * here trusts only finalised assessments and reads the stored gap column, so no
 * figure in the section can disagree with the headline. Reading needs
 * `competency.view`; the reports never write.
 */
final class CompetencyReportAction
{
    /** The axes readiness may be grouped by, in the order the picker offers them. */
    private const DIMENSIONS = ['department', 'branch', 'team', 'level', 'job_title', 'function'];

    public function __construct(private readonly CompetencyRepository $repository)
    {
    }

    /**
     * Readiness by org or competency axis. `?dimension=` chooses which; unknown
     * values fall back to department rather than erroring, so a stale bookmark
     * still returns something.
     */
    public function readiness(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::CompetencyView);
        $tenantId = $this->tenantId($request);
        $cycle = $this->resolveCycle($request, $tenantId);

        $dimension = $this->str($request->getQueryParams()['dimension'] ?? null) ?? 'department';
        if (!in_array($dimension, self::DIMENSIONS, true)) {
            $dimension = 'department';
        }

        return Json::ok($response, [
            'cycle'      => $cycle,
            'dimension'  => $dimension,
            'dimensions' => self::DIMENSIONS,
            'groups'     => $this->repository->readinessByDimension($tenantId, $cycle, $dimension),
            'cycles'     => $this->repository->cycles($tenantId),
        ]);
    }

    /** The gap-analysis table: every competency, widest average gap first. */
    public function gaps(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::CompetencyView);
        $tenantId = $this->tenantId($request);
        $cycle = $this->resolveCycle($request, $tenantId);

        return Json::ok($response, [
            'cycle'        => $cycle,
            'competencies' => $this->repository->gapAnalysis($tenantId, $cycle),
            'cycles'       => $this->repository->cycles($tenantId),
        ]);
    }

    /** People still short on a competency their job marks critical. */
    public function critical(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::CompetencyView);
        $tenantId = $this->tenantId($request);
        $cycle = $this->resolveCycle($request, $tenantId);

        return Json::ok($response, [
            'cycle'  => $cycle,
            'rows'   => $this->repository->criticalGaps($tenantId, $cycle),
            'cycles' => $this->repository->cycles($tenantId),
        ]);
    }

    /** How demonstrated levels are spread against what the jobs require. */
    public function distribution(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::CompetencyView);
        $tenantId = $this->tenantId($request);
        $cycle = $this->resolveCycle($request, $tenantId);

        return Json::ok($response, [
            'cycle'        => $cycle,
            ...$this->repository->proficiencyDistribution($tenantId, $cycle),
            'cycles'       => $this->repository->cycles($tenantId),
        ]);
    }

    /** How far through the exercise the cycle is, by status and department. */
    public function completion(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::CompetencyView);
        $tenantId = $this->tenantId($request);
        $cycle = $this->resolveCycle($request, $tenantId);

        return Json::ok($response, [
            'cycle'  => $cycle,
            ...$this->repository->completion($tenantId, $cycle),
            'cycles' => $this->repository->cycles($tenantId),
        ]);
    }

    /**
     * The requested cycle, or the newest one when none is given — so a report
     * opens on something rather than asking the reader to choose first. Matches
     * the outcome report's behaviour.
     */
    private function resolveCycle(ServerRequestInterface $request, string $tenantId): string
    {
        $cycle = $this->str($request->getQueryParams()['cycle'] ?? null);
        if ($cycle !== null) {
            return $cycle;
        }

        $cycles = $this->repository->cycles($tenantId);

        return $cycles[0] ?? 'default';
    }

    private function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    private function tenantId(ServerRequestInterface $request): string
    {
        $user = $this->caller($request);

        if ($user->tenantId === null) {
            throw new AccessDenied(Permission::CompetencyView->value);
        }

        return $user->tenantId;
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
