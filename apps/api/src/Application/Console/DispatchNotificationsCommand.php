<?php

declare(strict_types=1);

namespace Central\Api\Application\Console;

use Central\Api\Application\Notification\NotificationDispatcher;
use Central\Api\Application\Notification\ReminderExpander;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Expands reminders that have come due, then sends what is waiting.
 *
 * The two steps in that order on purpose: expanding a reminder writes delivery
 * rows, and doing it first means a reminder that becomes due at 09:00 goes out
 * on the 09:00 run rather than the next one.
 *
 * Run it from cron every minute:
 *
 *   * * * * * cd /path/to/api && php bin/console notifications:dispatch
 *
 * Safe to overlap. Deliveries are claimed with FOR UPDATE SKIP LOCKED, so two
 * runs take disjoint work rather than sending the same message twice.
 */
#[AsCommand(
    name: 'notifications:dispatch',
    description: 'Expand due reminders and send queued notifications',
)]
final class DispatchNotificationsCommand extends Command
{
    public function __construct(
        private readonly ReminderExpander $reminders,
        private readonly NotificationDispatcher $dispatcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Deliveries to send in one pass', '50')
            ->addOption('reminders', null, InputOption::VALUE_REQUIRED, 'Schedules to expand in one pass', '50')
            ->addOption('skip-reminders', null, InputOption::VALUE_NONE, 'Send only; do not expand schedules')
            ->addOption('worker', 'w', InputOption::VALUE_REQUIRED, 'Worker name recorded against claims');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Identifies who holds a claim, so a stuck delivery names the process
        // that took it. Host plus pid, because several run per host.
        $worker = (string) ($input->getOption('worker')
            ?: gethostname() . ':' . getmypid());

        if (!$input->getOption('skip-reminders')) {
            $expanded = $this->reminders->expandDue((int) $input->getOption('reminders'));

            if ($expanded['considered'] > 0) {
                $io->writeln(sprintf(
                    'Reminders: %d considered, %d expanded (%d people), %d lapsed, %d with nobody enrolled, %d failed',
                    $expanded['considered'],
                    $expanded['expanded'],
                    $expanded['notified'],
                    $expanded['lapsed'],
                    $expanded['empty'],
                    $expanded['failed'],
                ));
            }
        }

        $sent = $this->dispatcher->dispatch($worker, (int) $input->getOption('limit'));

        if ($sent['claimed'] > 0 || $sent['released'] > 0) {
            $io->writeln(sprintf(
                'Deliveries: %d claimed, %d sent, %d retrying, %d failed, %d skipped, %d released',
                $sent['claimed'],
                $sent['sent'],
                $sent['retrying'],
                $sent['failed'],
                $sent['skipped'],
                $sent['released'],
            ));
        }

        if ($output->isVerbose()) {
            foreach ($this->dispatcher->configuredChannels() as $channel => $configured) {
                $io->writeln(sprintf(
                    '  %-7s %s',
                    $channel,
                    $configured ? '<info>configured</info>' : '<comment>not configured</comment>',
                ));
            }
        }

        // Always success. A failed send is recorded on the row and retried;
        // exiting non-zero would make cron mail an administrator about a
        // bounced address, every minute, forever.
        return Command::SUCCESS;
    }
}
