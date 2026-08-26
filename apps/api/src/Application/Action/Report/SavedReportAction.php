<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Report;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Report\ReportCatalogue;
use Central\Api\Domain\Report\ReportRepository;
use Central\Api\Domain\Report\ReportRun;
use Central\Api\Domain\Report\SavedReport;
use Central\Api\Domain\Report\SavedReportRepository;
use Central\Api\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Questions somebody wants to ask again, and on a schedule.
 *
 * GET    /v1/reports/saved
 * POST   /v1/reports/saved
 * GET    /v1/reports/saved/{id}          the criteria, and the result
 * PUT    /v1/reports/saved/{id}
 * DELETE /v1/reports/saved/{id}
 * GET    /v1/reports/saved/{id}/runs
 *
 * A saved report stores the criteria, never the tenant and never the scope.
 * Opening one runs it as *you*, so sharing a saved report with a colleague
 * shares the question rather than the answer — and cannot hand them rows they
 * are not entitled to.
 */
final class SavedReportAction extends ReportAction
{
    public function __construct(
        ReportCatalogue $catalogue,
        ReportRepository $reports,
        private readonly SavedReportRepository $saved,
        private readonly Clock $clock,
    ) {
        parent::__construct($catalogue, $reports);
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::ReportView);

        $query   = $request->getQueryParams();
        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['perPage'] ?? 25)));

        $result = $this->saved->forUser($this->tenantId($request), $this->userId($request), $page, $perPage);
        $now    = $this->clock->now();
        $caller = $this->caller($request);

        return Json::page(
            $response,
            array_map(
                function (SavedReport $report) use ($caller, $now): array {
                    $definition = $this->catalogue->find($report->key);

                    return [
                        ...$report->toArrayAt($now),
                        'reportName' => $definition->name,
                        // A shared report they lack the permission for stays
                        // in the list and says so, rather than disappearing
                        // and leaving them to wonder where it went.
                        'canRun'     => $caller->can($definition->permission),
                        'canEdit'    => $report->isEditableBy($caller->id),
                    ];
                },
                $result['items'],
            ),
            $result['total'],
            $page,
            $perPage,
        );
    }

    /**
     * The saved criteria, and the report run with them.
     *
     * @param array<string, string> $args
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $report     = $this->require($request, $args['id'] ?? '');
        $definition = $this->definition($request, $report->key->value);

        $criteria = $this->criteria(
            $request,
            $definition,
            // Anything in the URL wins over what was saved, so a saved report
            // can be re-run for a different month without editing it.
            [...$report->criteria, ...$this->input($request)],
        );

        $result = $this->reports->run($definition, $criteria);
        $note   = $this->scopeNote($criteria->scope);

        if ($note !== null && $result->note === null) {
            $result = $result->withNote($note);
        }

        return Json::ok(
            $response,
            [
                'saved'  => $report->toArrayAt($this->clock->now()),
                'result' => $result->toArray(),
            ],
            200,
            $result->meta(),
        );
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::ReportView);

        $report = $this->fromInput($request, null);

        return Json::created(
            $response,
            $this->saved->save($report)->toArrayAt($this->clock->now()),
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $existing = $this->require($request, $args['id'] ?? '');

        // Only the owner. A shared report any reader could edit is one whose
        // owner finds it quietly changed underneath them.
        if (!$existing->isEditableBy($this->userId($request))) {
            throw new AccessDenied(Permission::ReportView->value);
        }

        $report = $this->fromInput($request, $existing->id, $existing);

        return Json::ok($response, $this->saved->save($report)->toArrayAt($this->clock->now()));
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $existing = $this->require($request, $args['id'] ?? '');

        if (!$existing->isEditableBy($this->userId($request))) {
            throw new AccessDenied(Permission::ReportView->value);
        }

        $this->saved->delete($this->tenantId($request), (string) $existing->id);

        return Json::noContent($response);
    }

    /**
     * When it ran, and what happened.
     *
     * @param array<string, string> $args
     */
    public function runs(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $report = $this->require($request, $args['id'] ?? '');

        $query   = $request->getQueryParams();
        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['perPage'] ?? 25)));

        $result = $this->saved->runsFor($this->tenantId($request), (string) $report->id, $page, $perPage);

        return Json::page(
            $response,
            array_map(static fn (ReportRun $run): array => $run->toArray(), $result['items']),
            $result['total'],
            $page,
            $perPage,
        );
    }

    /**
     * @throws EntityNotFound
     * @throws AccessDenied
     */
    private function require(ServerRequestInterface $request, string $id): SavedReport
    {
        $report = $this->saved->find($this->tenantId($request), $id)
            ?? throw new EntityNotFound('Saved report', $id);

        if (!$report->isVisibleTo($this->userId($request))) {
            // Not found rather than forbidden: whether a colleague keeps a
            // saved report called "Performance concerns — engineering" is not
            // something an id should be able to reveal.
            throw new EntityNotFound('Saved report', $id);
        }

        return $report;
    }

    private function fromInput(
        ServerRequestInterface $request,
        ?string $id,
        ?SavedReport $existing = null,
    ): SavedReport {
        $tenantId = $this->tenantId($request);
        $userId   = $this->userId($request);

        // A partial update keeps what it does not mention. The alternative is
        // an editor that silently clears the schedule because the form only
        // sent the name.
        $body = $existing === null
            ? $this->body($request)
            : [
                'reportKey'   => $existing->key->value,
                'name'        => $existing->name,
                'description' => $existing->description,
                'criteria'    => $existing->criteria,
                'isShared'    => $existing->isShared,
                'schedule'    => $existing->schedule?->toArray(),
                ...$this->body($request),
            ];

        $violations = [];
        $report     = SavedReport::fromInput($tenantId, $userId, $id, $body, $violations);

        if ($report === null) {
            throw ValidationFailed::fromArray($violations);
        }

        // A schedule that mails a report to people needs the permission to
        // run it — otherwise saving one is a way to have the system read
        // something to you that you may not read yourself.
        $definition = $this->catalogue->find($report->key);
        $this->caller($request)->mustHave($definition->permission);

        if ($this->saved->hasName($tenantId, $userId, $report->name, $id)) {
            throw new ConflictingState(
                sprintf('You already keep a report called "%s".', $report->name),
                'duplicate_name',
            );
        }

        return $report;
    }
}
