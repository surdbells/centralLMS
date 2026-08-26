<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * One execution of a saved report.
 *
 * This is what `last_run_at`, `last_run_status` and `scheduled_report_runs`
 * were three copies of. The newest row is the answer; nothing is written back
 * to the schedule.
 */
final readonly class ReportRun
{
    /**
     * @param list<string> $deliveredTo
     */
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public string $savedReportId,
        public RunTrigger $trigger,
        public RunStatus $status,
        public \DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $completedAt = null,
        public ?int $rowCount = null,
        public ?string $error = null,
        public array $deliveredTo = [],
    ) {
    }

    /**
     * How long it took, in seconds.
     *
     * Null while it is still running — not zero, which would read as instant.
     */
    public function durationSeconds(): ?int
    {
        if ($this->completedAt === null) {
            return null;
        }

        return $this->completedAt->getTimestamp() - $this->startedAt->getTimestamp();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'trigger'     => $this->trigger->value,
            'status'      => $this->status->value,
            'startedAt'   => $this->startedAt->format(\DateTimeInterface::ATOM),
            'completedAt' => $this->completedAt?->format(\DateTimeInterface::ATOM),
            'seconds'     => $this->durationSeconds(),
            'rowCount'    => $this->rowCount,
            'error'       => $this->error,
            'deliveredTo' => $this->deliveredTo,
        ];
    }
}
