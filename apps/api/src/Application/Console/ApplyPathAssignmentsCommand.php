<?php

declare(strict_types=1);

namespace Central\Api\Application\Console;

use Central\Api\Domain\LearningPath\PathAssignment;
use Central\Api\Domain\LearningPath\PathAssignmentRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs every active path assignment rule.
 *
 * This is what makes a rule a rule. Creating one enrols everybody it covers
 * at that moment; without this sweep, somebody who joins the department next
 * week is never enrolled, and the rule is a one-off enrolment wearing a
 * different name.
 *
 * Run it from cron, hourly is plenty:
 *
 *   0 * * * * cd /path/to/api && php bin/console paths:apply-assignments
 *
 * The interval is not load-bearing. Applying a rule is idempotent — it enrols
 * the people it covers who are not on the path yet, and nobody else — so
 * running it more often enrols nobody twice, and running it less often only
 * makes somebody's first day on a path later than it might have been.
 */
#[AsCommand(
    name: 'paths:apply-assignments',
    description: 'Enrol everybody newly covered by a standing path assignment rule',
)]
final class ApplyPathAssignmentsCommand extends Command
{
    public function __construct(
        private readonly PathAssignmentRepository $assignments,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'tenant',
            null,
            InputOption::VALUE_REQUIRED,
            'Only this tenant. Every tenant by default.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tenant = $input->getOption('tenant');
        $rules = $this->assignments->running(is_string($tenant) ? $tenant : null);

        $enrolled = 0;
        $failed = 0;

        foreach ($rules as $rule) {
            try {
                $enrolled += $this->assignments->apply($rule->tenantId, $rule->id);
            } catch (\Throwable $e) {
                // One broken rule must not stop the rest. A rule whose
                // department was deleted should not keep every other rule in
                // the organisation from running.
                ++$failed;
                $io->warning(sprintf('%s: %s', $this->describe($rule), $e->getMessage()));
            }
        }

        if ($enrolled === 0 && $failed === 0) {
            // Quiet on an empty pass: this runs hourly and most passes enrol
            // nobody. A line each time buries the ones that matter.
            return Command::SUCCESS;
        }

        $io->writeln(sprintf(
            'Assignment rules: %d considered, %d people enrolled, %d rules failed',
            count($rules),
            $enrolled,
            $failed,
        ));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function describe(PathAssignment $rule): string
    {
        return sprintf('%s (%s)', $rule->pathTitle ?? $rule->pathId, $rule->targetType->value);
    }
}
