<?php

declare(strict_types=1);

namespace Central\Api\Application\Report;

use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Identity\UserRepository;
use Central\Api\Domain\Notification\NotificationChannel;
use Central\Api\Domain\Notification\NotificationPriority;
use Central\Api\Domain\Notification\NotificationTransport;
use Central\Api\Domain\Notification\NotificationType;
use Central\Api\Domain\Notification\PendingDelivery;
use Central\Api\Domain\Authz\RoleRepository;
use Central\Api\Domain\Report\CsvWriter;
use Central\Api\Domain\Report\ReportCatalogue;
use Central\Api\Domain\Report\ReportCriteria;
use Central\Api\Domain\Report\ReportRepository;
use Central\Api\Domain\Report\ReportResult;
use Central\Api\Domain\Report\ReportScope;
use Central\Api\Domain\Report\RunTrigger;
use Central\Api\Domain\Report\SavedReport;
use Central\Api\Domain\Report\SavedReportRepository;
use Central\Api\Support\Clock;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Runs the saved reports whose schedule has come due, and sends them.
 *
 * Everything a schedule needs has been here since the reports were written:
 * `saved_reports` carries the frequency, the time, the day and the
 * recipients; `ReportSchedule` can say whether a schedule is due; and
 * `report_runs` is ready to record what happened. Nothing ever asked. A
 * person could set up a monthly compliance report, see it saved, see the
 * screen tell them when it would next run — and it would never run, not once,
 * with nothing anywhere saying so.
 *
 * This is the part that was missing.
 *
 * Two things it is careful about:
 *
 *  - **Scope.** A scheduled report runs as the person who saved it, with
 *    their permissions, not as an administrator. Somebody who can only see
 *    their own team must not receive the whole organisation by email every
 *    Monday because the run happened outside a request. The scope is built
 *    the same way a request builds it, from that person's roles.
 *  - **Not twice.** Due-ness is `lastRun < windowOpenedAt`, so a scheduler
 *    ticking every ten minutes sends Monday's report once on Monday. A run
 *    is recorded before the report executes, and finished or failed after,
 *    so a crash mid-send leaves a run marked as started rather than a
 *    schedule that looks untouched.
 */
final readonly class ScheduledReportDispatcher
{
    public function __construct(
        private SavedReportRepository $saved,
        private ReportRepository $reports,
        private ReportCatalogue $catalogue,
        private CsvWriter $csv,
        private UserRepository $users,
        private RoleRepository $roles,
        private NotificationTransport $email,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{due: int, sent: int, failed: int, skipped: int}
     */
    public function dispatch(): array
    {
        $now     = $this->clock->now();
        $tally   = ['due' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($this->saved->scheduled() as $entry) {
            /** @var SavedReport $report */
            $report  = $entry['report'];
            $lastRun = $entry['lastRun'];

            if ($report->schedule === null || !$report->schedule->isDue($lastRun, $now)) {
                continue;
            }

            ++$tally['due'];

            if ($report->schedule->recipients === []) {
                // Saved with a frequency and nobody to send to. Running it
                // would cost a query and reach no one.
                ++$tally['skipped'];
                continue;
            }

            $this->send($report, $report->schedule->recipients) ? ++$tally['sent'] : ++$tally['failed'];
        }

        return $tally;
    }

    /**
     * @param list<string> $recipients
     */
    private function send(SavedReport $report, array $recipients): bool
    {
        $run = $this->saved->startRun((string) $report->tenantId, (string) $report->id, RunTrigger::Schedule);

        try {
            $result = $this->run($report);
        } catch (\Throwable $failure) {
            $this->logger->error('A scheduled report could not be run', [
                'savedReportId' => $report->id,
                'report'        => $report->key->value,
                'error'         => $failure->getMessage(),
            ]);

            $this->saved->failRun((string) $run->id, $failure->getMessage());

            return false;
        }

        $delivered = $this->deliver($report, $result, $recipients);

        if ($delivered === []) {
            $this->saved->failRun((string) $run->id, 'The report ran but could not be sent to anybody.');

            return false;
        }

        $this->saved->finishRun((string) $run->id, count($result->rows), $delivered);

        return true;
    }

    private function run(SavedReport $report): ReportResult
    {
        $definition = $this->catalogue->find($report->key);

        $violations = [];
        $criteria   = ReportCriteria::fromInput(
            $this->scopeFor($report),
            $report->criteria,
            $violations,
        );

        if ($criteria === null) {
            // The saved filters no longer validate — a report whose shape has
            // changed under it. Saying which is worth more than "failed".
            throw new \RuntimeException(
                'The saved filters are no longer valid: ' . implode('; ', $violations),
            );
        }

        // Everything, not the first page. A weekly report that arrives with
        // twenty-five of six hundred rows is worse than none.
        return $this->reports->run($definition, $criteria->forExport());
    }

    /**
     * The report runs as whoever saved it.
     *
     * Built from that person's roles, through the same `forCaller` a request
     * uses, so there is one rule for who may see what rather than a second
     * one that applies only out of hours.
     */
    private function scopeFor(SavedReport $report): ReportScope
    {
        $owner = $this->users->findByTenant($report->tenantId, $report->userId);

        if ($owner === null) {
            // The person who set it up has been removed. Their schedule is
            // not somebody else's to inherit.
            throw new \RuntimeException('The person who saved this report no longer has an account.');
        }

        return ReportScope::forCaller(
            new AuthenticatedUser(
                id: $report->userId,
                tenantId: $report->tenantId,
                email: $owner->email,
                roles: $this->roles->findForTenant($report->tenantId, $owner->roleIds),
                tokenId: '',
            ),
            $report->tenantId,
        );
    }

    /**
     * @param  list<string> $recipients
     * @return list<string>  the addresses it actually reached
     */
    private function deliver(SavedReport $report, ReportResult $result, array $recipients): array
    {
        $csv        = $this->csv->write($result);
        $filename   = $this->csv->filename($result->definition, $this->clock->now());
        $delivered  = [];

        foreach ($recipients as $address) {
            $outcome = $this->email->send(new PendingDelivery(
                // Not a queued delivery: this one is composed and sent in the
                // same breath, so there is no row to name. The transport uses
                // the address, the tenant and the body.
                deliveryId: Uuid::uuid4()->toString(),
                tenantId: $report->tenantId,
                notificationId: (string) $report->id,
                userId: $report->userId,
                channel: NotificationChannel::Email,
                address: $address,
                type: NotificationType::Announcement,
                title: sprintf('%s — %s', $report->name, $this->clock->now()->format('j M Y')),
                body: $this->body($report, $result),
                data: [
                    'attachments' => [[
                        'name'     => $filename,
                        'content'  => base64_encode($csv),
                        'mimeType' => 'text/csv',
                    ]],
                ],
                priority: NotificationPriority::Normal,
                attempts: 0,
                maxAttempts: 1,
            ));

            if ($outcome->sent) {
                $delivered[] = $address;

                continue;
            }

            $this->logger->warning('A scheduled report did not reach a recipient', [
                'savedReportId' => $report->id,
                'address'       => $address,
                'reason'        => $outcome->error,
            ]);
        }

        return $delivered;
    }

    private function body(SavedReport $report, ReportResult $result): string
    {
        $lines = [
            sprintf('%s is attached.', $result->definition->name),
            '',
            sprintf('%d rows, as of %s.', count($result->rows), $this->clock->now()->format('j M Y H:i')),
        ];

        foreach ($result->totals as $label => $value) {
            if (is_scalar($value)) {
                $lines[] = sprintf('%s: %s', $label, (string) $value);
            }
        }

        $lines[] = '';
        $lines[] = sprintf('You are receiving this because "%s" is scheduled to be sent to you.', $report->name);

        return implode("\n", $lines);
    }
}
