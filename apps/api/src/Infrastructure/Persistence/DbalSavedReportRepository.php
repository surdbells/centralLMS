<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Report\ReportKey;
use Central\Api\Domain\Report\ReportRun;
use Central\Api\Domain\Report\ReportSchedule;
use Central\Api\Domain\Report\RunStatus;
use Central\Api\Domain\Report\RunTrigger;
use Central\Api\Domain\Report\SavedReport;
use Central\Api\Domain\Report\SavedReportRepository;
use Central\Api\Domain\Report\ScheduleFrequency;
use Doctrine\DBAL\Connection;

final class DbalSavedReportRepository implements SavedReportRepository
{
    private const COLUMNS = 'r.id, r.tenant_id, r.user_id, r.report_key, r.name, r.description,
                             r.criteria, r.is_shared, r.frequency, r.day_of_week, r.day_of_month,
                             r.time_of_day, r.recipients, r.format, r.schedule_paused, r.created_at';

    /**
     * The newest run, joined rather than copied onto the row.
     *
     * This is what `last_run_at` and `last_run_status` were duplicating.
     */
    private const LAST_RUN = "(
        SELECT to_jsonb(x) FROM (
            SELECT run.id, run.trigger, run.status, run.started_at, run.completed_at,
                   run.row_count, run.error, run.delivered_to
            FROM report_runs run
            WHERE run.saved_report_id = r.id
            ORDER BY run.started_at DESC
            LIMIT 1
        ) AS x
    ) AS last_run";

    public function __construct(private readonly Connection $connection)
    {
    }

    public function forUser(string $tenantId, string $userId, int $page, int $perPage): array
    {
        // Their own, plus anything shared. Written as one clause rather than a
        // union so the count and the page cannot disagree.
        $where = 'r.tenant_id = :tenant AND r.deleted_at IS NULL AND (r.user_id = :user OR r.is_shared)';

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM saved_reports r WHERE %s', $where),
            ['tenant' => $tenantId, 'user' => $userId],
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s FROM saved_reports r WHERE %s
                 ORDER BY r.name LIMIT :limit OFFSET :offset',
                self::COLUMNS,
                self::LAST_RUN,
                $where,
            ),
            [
                'tenant' => $tenantId,
                'user'   => $userId,
                'limit'  => $perPage,
                'offset' => ($page - 1) * $perPage,
            ],
        );

        return [
            'items' => array_map(fn (array $row): SavedReport => $this->hydrate($row), $rows),
            'total' => $total,
        ];
    }

    public function find(string $tenantId, string $id): ?SavedReport
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s FROM saved_reports r
                 WHERE r.id = :id AND r.tenant_id = :tenant AND r.deleted_at IS NULL',
                self::COLUMNS,
                self::LAST_RUN,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function save(SavedReport $report): SavedReport
    {
        $schedule = $report->schedule;

        $params = [
            'tenant'      => $report->tenantId,
            'user'        => $report->userId,
            'reportKey'   => $report->key->value,
            'name'        => $report->name,
            'description' => $report->description,
            // JSON_FORCE_OBJECT: an empty criteria set encodes as [] without
            // it, and the column insists on an object.
            'criteria'    => json_encode($report->criteria, JSON_FORCE_OBJECT),
            'isShared'    => $report->isShared,
            'frequency'   => $schedule?->frequency->value,
            'dayOfWeek'   => $schedule?->dayOfWeek,
            'dayOfMonth'  => $schedule?->dayOfMonth,
            'timeOfDay'   => $schedule?->timeOfDayString(),
            'recipients'  => '{' . implode(',', array_map(
                static fn (string $r): string => '"' . $r . '"',
                $schedule === null ? [] : $schedule->recipients,
            )) . '}',
            'paused'      => $schedule !== null && $schedule->paused,
        ];

        $types = [
            'isShared' => \Doctrine\DBAL\ParameterType::BOOLEAN,
            'paused'   => \Doctrine\DBAL\ParameterType::BOOLEAN,
        ];

        if ($report->id === null) {
            $id = (string) $this->connection->fetchOne(
                'INSERT INTO saved_reports
                    (tenant_id, user_id, report_key, name, description, criteria, is_shared,
                     frequency, day_of_week, day_of_month, time_of_day, recipients, schedule_paused)
                 VALUES (:tenant, :user, :reportKey, :name, :description, :criteria::jsonb, :isShared,
                         :frequency, :dayOfWeek, :dayOfMonth, :timeOfDay, :recipients::text[], :paused)
                 RETURNING id',
                $params,
                $types,
            );
        } else {
            $id = $report->id;

            $this->connection->executeStatement(
                'UPDATE saved_reports
                 SET report_key = :reportKey, name = :name, description = :description,
                     criteria = :criteria::jsonb, is_shared = :isShared,
                     frequency = :frequency, day_of_week = :dayOfWeek, day_of_month = :dayOfMonth,
                     time_of_day = :timeOfDay, recipients = :recipients::text[],
                     schedule_paused = :paused, updated_at = now()
                 WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                [...$params, 'id' => $id],
                $types,
            );
        }

        return $this->find($report->tenantId, $id)
            ?? throw new \RuntimeException('The saved report vanished immediately after being written.');
    }

    public function delete(string $tenantId, string $id): bool
    {
        // Soft: the run log points at it, and a report somebody deleted last
        // month is still the reason a file landed in an inbox.
        return $this->connection->executeStatement(
            'UPDATE saved_reports SET deleted_at = now()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    public function hasName(string $tenantId, string $userId, string $name, ?string $exceptId = null): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM saved_reports
             WHERE tenant_id = :tenant AND user_id = :user
               AND lower(btrim(name)) = lower(btrim(:name))
               AND deleted_at IS NULL
               AND (:exceptId::uuid IS NULL OR id <> :exceptId::uuid)',
            ['tenant' => $tenantId, 'user' => $userId, 'name' => $name, 'exceptId' => $exceptId],
        ) > 0;
    }

    // --- runs -------------------------------------------------------------

    public function scheduled(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT %s, %s,
                        (SELECT MAX(run.completed_at) FROM report_runs run
                          WHERE run.saved_report_id = r.id
                            AND run.status = 'completed'
                            AND run.trigger = 'schedule') AS last_scheduled_run
                 FROM saved_reports r
                 WHERE r.frequency IS NOT NULL
                   AND NOT r.schedule_paused
                   AND r.deleted_at IS NULL
                 ORDER BY r.id",
                self::COLUMNS,
                self::LAST_RUN,
            ),
        );

        return array_map(
            fn (array $row): array => [
                'report'  => $this->hydrate($row),
                // Only completed *scheduled* runs count. A manual run should
                // not satisfy a weekly schedule — somebody clicking "run now"
                // on a Monday must not cancel Monday's delivery.
                'lastRun' => $this->date($row['last_scheduled_run'] ?? null),
            ],
            $rows,
        );
    }

    public function startRun(string $tenantId, string $savedReportId, RunTrigger $trigger): ReportRun
    {
        $row = $this->connection->fetchAssociative(
            "INSERT INTO report_runs (tenant_id, saved_report_id, trigger, status)
             VALUES (:tenant, :report, :trigger, 'running')
             RETURNING id, started_at",
            ['tenant' => $tenantId, 'report' => $savedReportId, 'trigger' => $trigger->value],
        );

        \assert(is_array($row));

        return new ReportRun(
            id: (string) $row['id'],
            tenantId: $tenantId,
            savedReportId: $savedReportId,
            trigger: $trigger,
            status: RunStatus::Running,
            startedAt: $this->date($row['started_at']) ?? new \DateTimeImmutable(),
        );
    }

    public function finishRun(string $runId, int $rowCount, array $deliveredTo): void
    {
        $this->connection->executeStatement(
            "UPDATE report_runs
             SET status = 'completed', completed_at = now(), row_count = :rows,
                 delivered_to = :delivered::text[], error = NULL
             WHERE id = :id",
            [
                'id'        => $runId,
                'rows'      => $rowCount,
                'delivered' => '{' . implode(',', array_map(
                    static fn (string $r): string => '"' . $r . '"',
                    $deliveredTo,
                )) . '}',
            ],
        );
    }

    public function failRun(string $runId, string $error): void
    {
        $this->connection->executeStatement(
            "UPDATE report_runs
             SET status = 'failed', completed_at = now(), error = :error
             WHERE id = :id",
            ['id' => $runId, 'error' => $error],
        );
    }

    public function runsFor(string $tenantId, string $savedReportId, int $page, int $perPage): array
    {
        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM report_runs WHERE tenant_id = :tenant AND saved_report_id = :report',
            ['tenant' => $tenantId, 'report' => $savedReportId],
        );

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM report_runs
             WHERE tenant_id = :tenant AND saved_report_id = :report
             ORDER BY started_at DESC, id
             LIMIT :limit OFFSET :offset',
            [
                'tenant' => $tenantId,
                'report' => $savedReportId,
                'limit'  => $perPage,
                'offset' => ($page - 1) * $perPage,
            ],
        );

        return [
            'items' => array_map(fn (array $row): ReportRun => $this->hydrateRun($row), $rows),
            'total' => $total,
        ];
    }

    // --- internals --------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SavedReport
    {
        $frequency = $row['frequency'] === null
            ? null
            : ScheduleFrequency::tryFrom((string) $row['frequency']);

        $schedule = $frequency === null ? null : new ReportSchedule(
            frequency: $frequency,
            timeOfDay: $this->minutes((string) ($row['time_of_day'] ?? '08:00')),
            dayOfWeek: $row['day_of_week'] === null ? null : (int) $row['day_of_week'],
            dayOfMonth: $row['day_of_month'] === null ? null : (int) $row['day_of_month'],
            recipients: $this->pgArray($row['recipients'] ?? null),
            paused: (bool) ($row['schedule_paused'] ?? false),
        );

        $lastRun = null;

        if (is_string($row['last_run'] ?? null) && $row['last_run'] !== '') {
            $decoded = json_decode((string) $row['last_run'], true);

            if (is_array($decoded)) {
                $lastRun = $this->hydrateRun([
                    ...$decoded,
                    'tenant_id'       => $row['tenant_id'],
                    'saved_report_id' => $row['id'],
                ]);
            }
        }

        return new SavedReport(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            userId: (string) $row['user_id'],
            key: ReportKey::from((string) $row['report_key']),
            name: (string) $row['name'],
            description: $this->text($row['description'] ?? null),
            criteria: $this->json($row['criteria'] ?? null),
            isShared: (bool) $row['is_shared'],
            schedule: $schedule,
            createdAt: $this->date($row['created_at'] ?? null),
            lastRun: $lastRun,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateRun(array $row): ReportRun
    {
        $delivered = $row['delivered_to'] ?? null;

        return new ReportRun(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            savedReportId: (string) $row['saved_report_id'],
            trigger: RunTrigger::from((string) $row['trigger']),
            status: RunStatus::from((string) $row['status']),
            startedAt: $this->date($row['started_at'] ?? null) ?? new \DateTimeImmutable('@0'),
            completedAt: $this->date($row['completed_at'] ?? null),
            rowCount: $row['row_count'] === null ? null : (int) $row['row_count'],
            error: $this->text($row['error'] ?? null),
            // The jsonb form of the row gives a real array; the table form
            // gives Postgres array syntax.
            deliveredTo: is_array($delivered)
                ? array_values(array_map('strval', $delivered))
                : $this->pgArray($delivered),
        );
    }

    private function minutes(string $time): int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})/', trim($time), $m) !== 1) {
            return 8 * 60;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    /**
     * @return list<string>
     */
    private function pgArray(mixed $value): array
    {
        if (!is_string($value) || $value === '' || $value === '{}') {
            return [];
        }

        $inner = trim($value, '{}');

        return $inner === ''
            ? []
            : array_values(array_map(static fn (string $v): string => trim($v, '"'), explode(',', $inner)));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
