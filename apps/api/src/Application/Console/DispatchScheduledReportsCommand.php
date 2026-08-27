<?php

declare(strict_types=1);

namespace Central\Api\Application\Console;

use Central\Api\Application\Report\ScheduledReportDispatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sends the saved reports whose schedule has come due.
 *
 * Run it from cron every ten minutes:
 *
 *   *\/10 * * * * cd /path/to/api && php bin/console reports:dispatch
 *
 * The interval is not load-bearing. A schedule is due when its window has
 * opened and no scheduled run has completed inside that window, so running
 * this more often sends nothing extra, and running it less often only makes a
 * report late — never duplicated and never skipped.
 *
 * Every saved report with a frequency has been sending nothing since the day
 * schedules were added. See {@see ScheduledReportDispatcher}.
 */
#[AsCommand(
    name: 'reports:dispatch',
    description: 'Run and send saved reports whose schedule has come due',
)]
final class DispatchScheduledReportsCommand extends Command
{
    public function __construct(
        private readonly ScheduledReportDispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tally = $this->dispatcher->dispatch();

        if ($tally['due'] === 0) {
            // Quiet on an empty pass: this runs every ten minutes and nothing
            // is due on most of them. A line each time buries the ones that
            // matter.
            return Command::SUCCESS;
        }

        $io->writeln(sprintf(
            'Scheduled reports: %d due, %d sent, %d failed, %d with no recipients',
            $tally['due'],
            $tally['sent'],
            $tally['failed'],
            $tally['skipped'],
        ));

        // A failure here is worth a non-zero exit: cron mails the operator,
        // and a report nobody received is exactly the thing that otherwise
        // goes unnoticed for a quarter.
        return $tally['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
