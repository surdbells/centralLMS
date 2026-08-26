<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Report;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Report\CsvWriter;
use Central\Api\Domain\Report\ReportCatalogue;
use Central\Api\Domain\Report\ReportCategory;
use Central\Api\Domain\Report\ReportRepository;
use Central\Api\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reading the catalogue and running a report.
 *
 * GET  /v1/reports                       what this caller may run
 * GET  /v1/reports/learners              the people they may report about
 * GET  /v1/reports/{key}                 run it
 * POST /v1/reports/{key}                 run it with a longer set of criteria
 * GET  /v1/reports/{key}/export          the same, as a CSV
 */
final class RunReportAction extends ReportAction
{
    public function __construct(
        ReportCatalogue $catalogue,
        ReportRepository $reports,
        private readonly CsvWriter $csv,
        private readonly Clock $clock,
    ) {
        parent::__construct($catalogue, $reports);
    }

    /**
     * The catalogue, grouped by category.
     */
    public function catalogue(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query    = $request->getQueryParams();
        $category = null;

        if (isset($query['category']) && trim((string) $query['category']) !== '') {
            // Refused rather than ignored. A filter that silently widens is
            // how somebody asks for "just compliance" and exports the lot.
            $category = ReportCategory::tryFrom(trim((string) $query['category']))
                ?? throw ValidationFailed::fromArray([
                    'category' => 'Choose one of: ' . implode(', ', ReportCategory::values()) . '.',
                ]);
        }

        $available = $this->catalogue->availableTo($this->caller($request), $category);
        $grouped   = [];

        foreach ($available as $definition) {
            $grouped[$definition->category->value][] = $definition->toArray();
        }

        $categories = [];

        foreach (ReportCategory::cases() as $case) {
            if (!isset($grouped[$case->value])) {
                continue;
            }

            $categories[] = [
                'category'    => $case->value,
                'label'       => $case->label(),
                'description' => $case->description(),
                'reports'     => $grouped[$case->value],
            ];
        }

        return Json::ok($response, [
            'categories' => $categories,
            'total'      => count($available),
            // Said plainly, because a caller who sees eleven reports where a
            // colleague sees fifty-seven should know why.
            'scope'      => $this->scope($request)->tenantWide
                ? 'the whole organisation'
                : 'you and the people who report to you',
        ]);
    }

    /**
     * The people this caller may report about.
     */
    public function learners(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::ReportView);

        $query   = $request->getQueryParams();
        $page    = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['perPage'] ?? 25)));

        $result = $this->reports->selectableLearners(
            $this->scope($request),
            isset($query['search']) ? (string) $query['search'] : null,
            $page,
            $perPage,
        );

        return Json::page($response, $result['items'], $result['total'], $page, $perPage);
    }

    /**
     * @param array<string, string> $args
     */
    public function run(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $definition = $this->definition($request, $args['key'] ?? '');
        $criteria   = $this->criteria($request, $definition, $this->input($request));

        $result = $this->reports->run($definition, $criteria);
        $note   = $this->scopeNote($criteria->scope);

        if ($note !== null && $result->note === null) {
            $result = $result->withNote($note);
        }

        return Json::ok($response, $result->toArray(), 200, $result->meta());
    }

    /**
     * @param array<string, string> $args
     */
    public function export(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $definition = $this->definition($request, $args['key'] ?? '');

        if (!$definition->exportable) {
            throw new ConflictingState(
                sprintf('"%s" is a figure to read, not a file to download.', $definition->name),
                'not_exportable',
            );
        }

        // A named export is one person's record, so it needs the permission
        // that covers reading across people rather than the one that covers
        // looking at a chart.
        if ($definition->namesPeople) {
            $this->caller($request)->mustHave(Permission::ReportExport);
        }

        $criteria = $this->criteria($request, $definition, $this->input($request))->forExport();
        $result   = $this->reports->run($definition, $criteria);

        $csv = $this->csv->write($result);

        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader(
                'Content-Disposition',
                sprintf('attachment; filename="%s"', $this->csv->filename($definition, $this->clock->now())),
            )
            // A report is a snapshot of live data. A cached copy sitting in a
            // proxy is a compliance figure from last week presented as today's.
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * One report's metadata, for a screen building its filter form.
     *
     * @param array<string, string> $args
     */
    public function describe(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $definition = $this->definition($request, $args['key'] ?? '');

        return Json::ok($response, [
            ...$definition->toArray(),
            'categoryLabel' => $definition->category->label(),
        ]);
    }
}
