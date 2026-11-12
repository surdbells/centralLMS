<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Report;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Report\CsvWriter;
use Central\Api\Domain\Report\CustomReportRepository;
use Central\Api\Domain\Report\CustomReportResult;
use Central\Api\Domain\Report\CustomReportSpec;
use Central\Api\Domain\Report\CustomSource;
use Central\Api\Domain\Report\ReportCatalogue;
use Central\Api\Domain\Report\ReportCriteria;
use Central\Api\Domain\Report\ReportRepository;
use Central\Api\Domain\Report\SavedCustomReport;
use Central\Api\Domain\Report\SavedCustomReportRepository;
use Central\Api\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The custom report builder: pick a data source, the columns, an optional
 * grouping and some filters, and run it.
 *
 * GET    /v1/reports/custom/sources                 the sources this builder offers
 * GET    /v1/reports/custom/sources/{source}        one source's columns, groupings, filters
 * POST   /v1/reports/custom/run                     run a config
 * GET    /v1/reports/custom/saved                   the caller's saved custom reports
 * POST   /v1/reports/custom/saved                   save one
 * PUT    /v1/reports/custom/saved/{id}              change one
 * DELETE /v1/reports/custom/saved/{id}
 * POST   /v1/reports/custom/saved/{id}/duplicate
 * GET    /v1/reports/custom/saved/{id}/run          run a saved one
 * GET    /v1/reports/custom/saved/{id}/export       the same, as a CSV
 *
 * Runs under the same two rules as every other report: the tenant comes from
 * the token, and rows about people are narrowed to the caller's scope — their
 * own and their direct reports' unless they may see the organisation.
 */
final class CustomReportAction extends ReportAction
{
    public function __construct(
        ReportCatalogue $catalogue,
        ReportRepository $reports,
        private readonly CustomReportRepository $custom,
        private readonly SavedCustomReportRepository $saved,
        private readonly CsvWriter $csv,
        private readonly Clock $clock,
    ) {
        parent::__construct($catalogue, $reports);
    }

    public function sources(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::ReportView);

        return Json::ok(
            $response,
            array_map(static fn (CustomSource $s): array => $s->toArray(), $this->custom->sources()),
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function schema(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::ReportView);

        return Json::ok($response, $this->source($args['source'] ?? '')->schema());
    }

    public function run(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::ReportView);

        $result = $this->execute($request, $this->body($request));

        return Json::ok($response, $result->toArray(), 200, $result->meta());
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::ReportView);

        $labels = [];

        foreach ($this->custom->sources() as $source) {
            $labels[$source->key] = $source->label;
        }

        return Json::ok(
            $response,
            array_map(
                static fn (SavedCustomReport $r): array => [
                    ...$r->toArray(),
                    // A source later withdrawn still lists, named by its key,
                    // rather than vanishing from somebody's saved reports.
                    'sourceLabel' => $labels[$r->source] ?? $r->source,
                ],
                $this->saved->forUser($this->tenantId($request), $this->userId($request)),
            ),
        );
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::ReportView);

        $report = $this->fromInput($request, null, $this->body($request));

        return Json::created($response, $this->saved->save($report)->toArray());
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

        // A partial update keeps what it does not mention.
        $body = [
            'name'        => $existing->name,
            'description' => $existing->description,
            'config'      => $existing->config,
            ...$this->body($request),
        ];

        $report = $this->fromInput($request, $existing->id, $body);

        return Json::ok($response, $this->saved->save($report)->toArray());
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

        $this->saved->delete($this->tenantId($request), (string) $existing->id);

        return Json::noContent($response);
    }

    /**
     * A copy under a name that is not taken yet.
     *
     * @param array<string, string> $args
     */
    public function duplicate(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $existing = $this->require($request, $args['id'] ?? '');
        $tenantId = $this->tenantId($request);
        $userId   = $this->userId($request);

        $base = mb_substr($existing->name, 0, 230) . ' (copy)';
        $name = $base;

        for ($n = 2; $this->saved->hasName($tenantId, $userId, $name); $n++) {
            $name = sprintf('%s %d', $base, $n);
        }

        $copy = new SavedCustomReport(
            null,
            $tenantId,
            $userId,
            $name,
            $existing->description,
            $existing->source,
            $existing->config,
        );

        return Json::created($response, $this->saved->save($copy)->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function runSaved(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::ReportView);

        $report = $this->require($request, $args['id'] ?? '');
        $result = $this->execute($request, $report->config);

        return Json::ok(
            $response,
            ['saved' => $report->toArray(), ...$result->toArray()],
            200,
            $result->meta(),
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function exportSaved(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::ReportView);

        $report = $this->require($request, $args['id'] ?? '');
        $source = $this->source((string) ($report->config['source'] ?? $report->source));

        // A file of named people is the export permission's business, exactly
        // as it is for the catalogue's detail reports.
        if ($source->namesPeople) {
            $this->caller($request)->mustHave(Permission::ReportExport);
        }

        // As many rows as an export allows, whatever limit the screen used.
        $result    = $this->execute($request, [...$report->config, 'perPage' => 500]);
        $shown     = count($result->rows);
        // `total` is the matching rows (or groups, when grouped).
        $truncated = $result->total > $shown;

        $csv = $this->csv->writeTable($result->columns(), $result->rows);

        if ($truncated) {
            // Never let a partial file pass for the whole list — someone may
            // hand it in as an audit or compliance return.
            $csv .= sprintf(
                "\"Showing the first %d of %d matching rows. Add filters to export the rest.\"\r\n",
                $shown,
                $result->total,
            );
        }

        $response->getBody()->write($csv);

        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader(
                'Content-Disposition',
                sprintf('attachment; filename="%s"', $this->csv->filenameFor($report->name, $this->clock->now())),
            )
            ->withHeader('X-Total-Count', (string) $result->total)
            ->withHeader('X-Truncated', $truncated ? '1' : '0')
            ->withHeader('Cache-Control', 'no-store');
    }

    // --- plumbing ---------------------------------------------------------

    /**
     * Validates a config and runs it as the caller.
     *
     * @param array<string, mixed> $config
     *
     * @throws ValidationFailed
     * @throws EntityNotFound
     */
    private function execute(ServerRequestInterface $request, array $config): CustomReportResult
    {
        $spec = $this->spec($config);

        $violations = [];
        $criteria   = ReportCriteria::fromInput($this->scope($request), $spec->criteriaInput(), $violations);

        if ($criteria === null) {
            // Dates are the only criteria a spec can get wrong; report them
            // under the filter the client drew them in.
            $mapped = [];

            foreach ($violations as $field => $message) {
                $mapped[in_array($field, ['from', 'to'], true) ? 'filters.' . $field : $field] = $message;
            }

            throw ValidationFailed::fromArray($mapped);
        }

        $result = $this->custom->run($spec, $criteria);
        // Every source is scoped to the caller's people — the course catalogue's
        // enrolment figures included — so a caller who can't see everyone is
        // always told the figures are theirs and their reports' (null otherwise).
        $note   = $this->scopeNote($criteria->scope);

        return $note === null ? $result : $result->withNote($note);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws ValidationFailed
     */
    private function spec(array $config): CustomReportSpec
    {
        $key = trim((string) (is_scalar($config['source'] ?? null) ? $config['source'] : ''));

        if ($key === '') {
            throw ValidationFailed::fromArray(['source' => 'Choose a data source.']);
        }

        $source = $this->custom->source($key)
            ?? throw ValidationFailed::fromArray(['source' => sprintf('"%s" is not a data source.', $key)]);

        $violations = [];
        $spec       = CustomReportSpec::fromInput($source, $config, $violations);

        if ($spec === null) {
            throw ValidationFailed::fromArray($violations);
        }

        return $spec;
    }

    /**
     * @throws EntityNotFound
     */
    private function source(string $key): CustomSource
    {
        return $this->custom->source(trim($key)) ?? throw new EntityNotFound('Data source', $key);
    }

    /**
     * One of the caller's own saved custom reports.
     *
     * @throws EntityNotFound
     */
    private function require(ServerRequestInterface $request, string $id): SavedCustomReport
    {
        $this->caller($request)->mustHave(Permission::ReportView);

        $report = $this->saved->find($this->tenantId($request), $id);

        // Not found rather than forbidden for somebody else's: whether a
        // colleague keeps a report called "Performance concerns" is not
        // something an id should reveal.
        if ($report === null || !$report->isOwnedBy($this->userId($request))) {
            throw new EntityNotFound('Custom report', $id);
        }

        return $report;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws ValidationFailed
     * @throws ConflictingState
     * @throws AccessDenied
     */
    private function fromInput(ServerRequestInterface $request, ?string $id, array $body): SavedCustomReport
    {
        $tenantId = $this->tenantId($request);
        $userId   = $this->userId($request);

        $config = is_array($body['config'] ?? null) ? $body['config'] : [];
        $spec   = $this->spec($config);

        $violations = [];
        $report     = SavedCustomReport::fromInput($tenantId, $userId, $id, $spec, $body, $violations);

        if ($report === null) {
            throw ValidationFailed::fromArray($violations);
        }

        if ($this->saved->hasName($tenantId, $userId, $report->name, $id)) {
            throw new ConflictingState(
                sprintf('You already keep a custom report called "%s".', $report->name),
                'duplicate_name',
            );
        }

        return $report;
    }
}
