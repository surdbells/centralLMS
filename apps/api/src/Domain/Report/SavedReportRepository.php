<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * Storage for saved report criteria, their schedules and the log of runs.
 */
interface SavedReportRepository
{
    /**
     * The saved reports one person can open.
     *
     * Their own, plus anything shared in the tenant. Not filtered by the
     * report's permission — a shared report they may not run still belongs in
     * the list, greyed out, rather than vanishing without explanation.
     *
     * @return array{items: list<SavedReport>, total: int}
     */
    public function forUser(string $tenantId, string $userId, int $page, int $perPage): array;

    public function find(string $tenantId, string $id): ?SavedReport;

    public function save(SavedReport $report): SavedReport;

    public function delete(string $tenantId, string $id): bool;

    /**
     * Whether this person already keeps one by that name.
     */
    public function hasName(string $tenantId, string $userId, string $name, ?string $exceptId = null): bool;

    // --- runs -------------------------------------------------------------

    /**
     * Every saved report with a schedule on it, and when it last ran.
     *
     * Whether one is *due* is decided in the domain from the frequency and
     * that last run — never from a stored `next_run_at`, which is the column
     * that made `scheduled_reports` drift. See {@see ReportSchedule}.
     *
     * @return list<array{report: SavedReport, lastRun: ?\DateTimeImmutable}>
     */
    public function scheduled(): array;

    public function startRun(string $tenantId, string $savedReportId, RunTrigger $trigger): ReportRun;

    /**
     * @param list<string> $deliveredTo
     */
    public function finishRun(string $runId, int $rowCount, array $deliveredTo): void;

    public function failRun(string $runId, string $error): void;

    /**
     * @return array{items: list<ReportRun>, total: int}
     */
    public function runsFor(string $tenantId, string $savedReportId, int $page, int $perPage): array;
}
