<?php

declare(strict_types=1);

namespace Central\Api\Application\Console;

use Central\Api\Application\Saas\BillingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rolls subscriptions whose period has ended forward, raising the next invoice.
 *
 * Run it from cron, hourly is ample:
 *
 *   0 * * * * cd /path/to/api && php bin/console billing:run
 *
 * The interval is not load-bearing. A subscription is due when its period has
 * ended, and an invoice is only ever raised once per period, so running this
 * more often does nothing extra and running it less often only means a renewal
 * lands late — never twice, never skipped. A trial that has ended becomes a live
 * subscription and gets its first invoice; a live one is billed for the next
 * period; one set to cancel at period end stops here.
 */
#[AsCommand(
    name: 'billing:run',
    description: 'Renew subscriptions whose period has ended and raise their invoices',
)]
final class RunBillingCommand extends Command
{
    public function __construct(private readonly BillingService $billing)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tally = $this->billing->runRenewals();

        if ($tally['due'] === 0) {
            // Quiet on an empty pass — most hours nothing is due, and a line
            // each time would bury the ones that matter.
            return Command::SUCCESS;
        }

        $io->writeln(sprintf(
            'Billing: %d due, %d renewed, %d invoiced, %d cancelled, %d failed',
            $tally['due'],
            $tally['renewed'],
            $tally['invoiced'],
            $tally['canceled'],
            $tally['failed'],
        ));

        return $tally['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
