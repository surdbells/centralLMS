<?php

declare(strict_types=1);

namespace Central\Api\Application\Console;

use Central\Api\Application\Import\CompetencyWorkbookImporter;
use Central\Api\Domain\Competency\CompetencyRepository;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports the real Job Competency Profiles from the completed workbooks.
 *
 * The parsing and the writing both live in {@see CompetencyWorkbookImporter},
 * which the competency import endpoint shares; this command is the way in from
 * a folder of workbooks on disk.
 *
 *   php bin/console competency:import --tenant=<uuid> \
 *       --dir="/path/to/Job Competency Profiles (JCPs) - Completed" --dry-run
 */
#[AsCommand(
    name: 'competency:import',
    description: 'Import competency dictionaries and job profiles from the JCP workbooks',
)]
final class ImportCompetencyProfilesCommand extends Command
{
    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Tenant id to import into')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Directory of JCP workbooks')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'A single workbook to import')
            ->addOption('function', null, InputOption::VALUE_REQUIRED, 'Override the function name (single file only)')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Password to open any encrypted workbooks')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change; write nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $tenantId = (string) ($input->getOption('tenant') ?? '');
        if ($tenantId === '') {
            $io->error('Say which tenant to import into: --tenant=<uuid>.');

            return Command::FAILURE;
        }

        $files = $this->resolveFiles($input, $io);
        if ($files === null) {
            return Command::FAILURE;
        }

        $importer = new CompetencyWorkbookImporter($this->container->get(CompetencyRepository::class));
        $overrideFunction = $this->str($input->getOption('function'));
        $password = $this->str($input->getOption('password'));

        $io->title($dryRun ? 'Competency import — DRY RUN (nothing will be written)' : 'Competency import');

        $totals = ['functions' => 0, 'competencies' => 0, 'profiles' => 0, 'requirements' => 0, 'people' => 0];

        foreach ($files as $path) {
            $io->section(basename($path));

            try {
                $summary = $importer->importFile($tenantId, $path, $overrideFunction, $dryRun, null, $password);
            } catch (\Throwable $e) {
                $io->warning('Could not read the workbook: ' . $e->getMessage());

                continue;
            }

            if ($summary['legacyXls']) {
                $io->warning('This is an old .xls workbook (renamed to .xlsx). Re-save it as .xlsx and import again.');

                continue;
            }

            if ($summary['encrypted']) {
                $io->warning($password === null
                    ? 'This workbook is password-protected. Re-run with --password=<password>.'
                    : 'This workbook is password-protected and the password did not open it.');

                continue;
            }

            if ($summary['empty']) {
                $io->warning('No competency sheets found — skipping.');

                continue;
            }

            $totals['people'] += $summary['people'];

            foreach ($summary['functions'] as $function) {
                $io->text(sprintf('<info>function "%s"</info>', $function['function']));
                $io->text(sprintf('  %d competencies', $function['competencies']));
                $totals['functions']++;
                $totals['competencies'] += $function['competencies'];

                foreach ($function['profiles'] as $profile) {
                    $io->text(sprintf('  profile "%s" — %d requirements', $profile['jobTitle'], $profile['requirements']));
                    $totals['profiles']++;
                    $totals['requirements'] += $profile['requirements'];
                }
            }

            if ($summary['people'] > 0) {
                $io->text(sprintf(
                    '  <comment>%d individual assessment sheet(s) present — not imported (need the workforce loaded first)</comment>',
                    $summary['people'],
                ));
            }
        }

        $io->newLine();
        $io->success(sprintf(
            '%s%d functions · %d competencies · %d profiles · %d requirements. %d person sheets noted.',
            $dryRun ? '[dry run] ' : '',
            $totals['functions'],
            $totals['competencies'],
            $totals['profiles'],
            $totals['requirements'],
            $totals['people'],
        ));

        return Command::SUCCESS;
    }

    /**
     * @return list<string>|null
     */
    private function resolveFiles(InputInterface $input, SymfonyStyle $io): ?array
    {
        $file = $this->str($input->getOption('file'));
        if ($file !== null) {
            if (!is_file($file)) {
                $io->error('No such file: ' . $file);

                return null;
            }

            return [$file];
        }

        $dir = $this->str($input->getOption('dir'));
        if ($dir === null) {
            $io->error('Give either --file=<workbook> or --dir=<folder of workbooks>.');

            return null;
        }

        if (!is_dir($dir)) {
            $io->error('No such directory: ' . $dir);

            return null;
        }

        $files = glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.xlsx') ?: [];
        // Skip Excel's lock files (~$…).
        $files = array_values(array_filter($files, static fn (string $f): bool => !str_starts_with(basename($f), '~$')));

        if ($files === []) {
            $io->error('No .xlsx workbooks in ' . $dir);

            return null;
        }

        return $files;
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
