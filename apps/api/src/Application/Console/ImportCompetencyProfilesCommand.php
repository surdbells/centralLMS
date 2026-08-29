<?php

declare(strict_types=1);

namespace Central\Api\Application\Console;

use Central\Api\Domain\Competency\Competency;
use Central\Api\Domain\Competency\CompetencyRepository;
use Central\Api\Domain\Competency\JobCompetencyProfile;
use Central\Api\Domain\Competency\ProficiencyLevel;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Reader\XLSX\Sheet;
use Psr\Container\ContainerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports the real Job Competency Profiles from the completed workbooks.
 *
 * Each function ships one workbook with two sheets that matter:
 *
 *   • "… Detailed Competences" — the dictionary. One row per competency:
 *     a number, a name, a definition, and a paragraph describing what
 *     Knowledge, Skill and Mastery of it look like.
 *
 *   • "… Competence Mapping" — the profiles. One row per competency, and one
 *     column per job level (General/Senior Management, Management,
 *     Supervisor/Team Leader, Professional Staff). Each cell holds K, S or M —
 *     the level that job level is required to reach. A blank means the job
 *     does not need that competency.
 *
 * So one workbook becomes: the function's competencies, and one job profile
 * per job-level column, each requiring the competencies at the mapped level.
 *
 * The per-person sheets in the same workbooks are individual assessments, but
 * their identity is a VLOOKUP into a separate staffing workbook that is not
 * here, so a person cannot be matched to a user from the file alone. They are
 * counted and reported, not imported — load the workforce, then assess.
 *
 * Idempotent: competencies are matched by name within the function and
 * profiles by job title, so a re-run updates rather than duplicates.
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
    /**
     * Tokens that trail a function name in the file names — placement notes,
     * revision markers, dates — and are not part of the function itself.
     */
    private const NOISE = [
        'placement', 'final', 'completed', 'copy of', 'template', 'all',
        'competency assessment', 'uon comments', 'v12-nov-2025',
    ];

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

        /** @var CompetencyRepository $repository */
        $repository = $this->container->get(CompetencyRepository::class);

        $io->title($dryRun ? 'Competency import — DRY RUN (nothing will be written)' : 'Competency import');

        $totals = ['functions' => 0, 'competencies' => 0, 'profiles' => 0, 'requirements' => 0, 'people' => 0];
        $overrideFunction = $this->str($input->getOption('function'));

        foreach ($files as $path) {
            $io->section(basename($path));

            if ($this->isLegacyXls($path)) {
                $io->warning('This is an old .xls workbook (renamed to .xlsx). Re-save it as .xlsx and import again.');

                continue;
            }

            try {
                $parsed = $this->parseWorkbook($path);
            } catch (\Throwable $e) {
                $io->warning('Could not read the workbook: ' . $e->getMessage());

                continue;
            }

            if ($parsed['sections'] === []) {
                $io->warning('No competency sheets found — skipping.');

                continue;
            }

            $totals['people'] += $parsed['people'];

            // A workbook with a single competency section is a whole function,
            // and its file name names it better than the abbreviated sheet
            // name. A workbook with several sections holds several sub-functions
            // in one file, so each keeps the name read off its own sheet.
            $single = count($parsed['sections']) === 1;

            foreach ($parsed['sections'] as $section) {
                $function = $single
                    ? ($overrideFunction ?? $this->functionFromFileName($path))
                    : $section['function'];

                $this->importSection($section, $function, $tenantId, $repository, $dryRun, $io, $totals);
            }

            if ($parsed['people'] > 0) {
                $io->text(sprintf(
                    '  <comment>%d individual assessment sheet(s) present — not imported (need the workforce loaded first)</comment>',
                    $parsed['people'],
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

    // --- importing one section ------------------------------------------------

    /**
     * @param array{function: string, competencies: list<array{number: ?string, name: string, definition: ?string, knowledge: ?string, skill: ?string, mastery: ?string}>, profiles: array<string, array<string, ProficiencyLevel>>} $section
     * @param array{functions: int, competencies: int, profiles: int, requirements: int, people: int} $totals
     */
    private function importSection(
        array $section,
        string $function,
        string $tenantId,
        CompetencyRepository $repository,
        bool $dryRun,
        SymfonyStyle $io,
        array &$totals,
    ): void {
        if ($section['competencies'] === []) {
            return;
        }

        $io->text(sprintf('<info>function "%s"</info>', $function));
        $totals['functions']++;

        // --- the dictionary -------------------------------------------------
        // Keyed by the competency's number in column A, because the mapping
        // sheet often names competencies with a cross-sheet formula that reads
        // back unresolved — but both sheets number their rows, and those
        // numbers line up.
        $idByNumber = [];
        foreach ($section['competencies'] as $comp) {
            $existing = $repository->findCompetencyByName($tenantId, $function, $comp['name']);
            $id = $existing !== null ? $existing->id : Uuid::uuid4()->toString();
            if ($comp['number'] !== null) {
                $idByNumber[$comp['number']] = $id;
            }

            if (!$dryRun) {
                $repository->saveCompetency(new Competency(
                    id: $id,
                    tenantId: $tenantId,
                    functionName: $function,
                    name: $comp['name'],
                    description: $comp['definition'],
                    knowledgeDescriptor: $comp['knowledge'],
                    skillDescriptor: $comp['skill'],
                    masteryDescriptor: $comp['mastery'],
                ));
            }
            $totals['competencies']++;
        }
        $io->text(sprintf('  %d competencies', count($section['competencies'])));

        // --- the profiles ---------------------------------------------------
        foreach ($section['profiles'] as $jobTitle => $levels) {
            $requirements = [];
            foreach ($levels as $number => $level) {
                $id = $idByNumber[$number] ?? null;
                if ($id === null) {
                    // A mapping row whose number has no competency in the
                    // dictionary sheet — reported, never guessed at.
                    $io->text(sprintf('    <comment>· competency #%s not in the dictionary, skipped for %s</comment>', $number, $jobTitle));

                    continue;
                }
                $requirements[] = [
                    'competencyId'  => $id,
                    'requiredLevel' => $level->value,
                    'isCritical'    => false,
                ];
            }

            if ($requirements === []) {
                continue;
            }

            $existing = $repository->findProfileByTitle($tenantId, $function, $jobTitle);
            $profileId = $existing !== null ? $existing->id : Uuid::uuid4()->toString();

            if (!$dryRun) {
                $repository->saveProfile(new JobCompetencyProfile(
                    id: $profileId,
                    tenantId: $tenantId,
                    jobTitle: $jobTitle,
                    functionName: $function,
                    requirements: $requirements,
                ));
            }
            $totals['profiles']++;
            $totals['requirements'] += count($requirements);
            $io->text(sprintf('  profile "%s" — %d requirements', $jobTitle, count($requirements)));
        }
    }

    // --- reading the workbook -------------------------------------------------

    /**
     * A workbook is a run of sections, each a dictionary sheet optionally
     * followed by its mapping sheet. Most workbooks hold one; the technical
     * workbook holds several sub-functions in a single file.
     *
     * @return array{
     *     sections: list<array{function: string, competencies: list<array{number: ?string, name: string, definition: ?string, knowledge: ?string, skill: ?string, mastery: ?string}>, profiles: array<string, array<string, ProficiencyLevel>>}>,
     *     people: int
     * }
     */
    private function parseWorkbook(string $path): array
    {
        $reader = new Reader();
        $reader->open($path);

        $sections = [];
        $current = null;
        $pendingMapping = null; // a mapping sheet seen before its dictionary
        $people = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            $name = $sheet->getName();
            $lower = mb_strtolower($name);

            $isMapping = str_contains($lower, 'mapping') || str_contains($lower, 'framework');
            $isDictionary = !$isMapping
                && (str_contains($lower, 'competence') || str_contains($lower, 'competenc'));

            if ($isDictionary) {
                // A new dictionary opens a new section.
                if ($current !== null) {
                    $sections[] = $current;
                }
                $current = [
                    'function'     => $this->functionFromSheetName($name),
                    'competencies' => $this->readDictionary($sheet),
                    // A mapping that appeared before its dictionary belongs here.
                    'profiles'     => $pendingMapping ?? [],
                ];
                $pendingMapping = null;
            } elseif ($isMapping) {
                // Attach to the open section if it is still waiting for one;
                // otherwise hold it for the dictionary that follows.
                if ($current !== null && $current['profiles'] === []) {
                    $current['profiles'] = $this->readMapping($sheet);
                } else {
                    $pendingMapping = $this->readMapping($sheet);
                }
            } elseif ($this->isMetadataSheet($lower)) {
                // A summary or lookup view, nothing to import and not a person.
                continue;
            } else {
                // Anything else in these workbooks is a person's assessment.
                $people++;
            }
        }

        if ($current !== null) {
            $sections[] = $current;
        }

        $reader->close();

        return ['sections' => $sections, 'people' => $people];
    }

    private function isMetadataSheet(string $lower): bool
    {
        foreach (['dashboard', 'current org', 'evaluated jobs', 'job family', 'sheet1', 'lookup'] as $token) {
            if (str_contains($lower, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{number: ?string, name: string, definition: ?string, knowledge: ?string, skill: ?string, mastery: ?string}>
     */
    private function readDictionary(Sheet $sheet): array
    {
        $out = [];
        $row = 0;

        foreach ($sheet->getRowIterator() as $rowObj) {
            $row++;
            // R1 is a banner, R2 the header; data starts at R3.
            if ($row <= 2) {
                continue;
            }

            $cells = $this->cells($rowObj);
            $name = $this->str($cells[1] ?? null); // col B

            if ($name === null || str_starts_with($name, '=')) {
                continue;
            }

            $out[] = [
                'number'     => $this->number($cells[0] ?? null), // col A
                'name'       => $name,
                'definition' => $this->str($cells[2] ?? null), // col C
                'knowledge'  => $this->str($cells[3] ?? null), // col D
                'skill'      => $this->str($cells[4] ?? null), // col E
                'mastery'    => $this->str($cells[5] ?? null), // col F
            ];
        }

        return $out;
    }

    /**
     * @return array<string, array<string, ProficiencyLevel>>
     */
    private function readMapping(Sheet $sheet): array
    {
        $jobLevels = [];   // column index → job title
        $profiles = [];    // job title → [competency number → level]

        foreach ($sheet->getRowIterator() as $rowObj) {
            $cells = $this->cells($rowObj);

            // The header row is the one carrying two or more job levels from
            // column C onward — a banner row has only its title in column A.
            // The marker in column A ("#", "SN", "S/N", "No") varies by
            // workbook, so it is not what we key on.
            if ($jobLevels === []) {
                $titles = [];
                for ($col = 2; $col < count($cells); $col++) {
                    $title = $this->str($cells[$col]);
                    if ($title !== null && !str_starts_with($title, '=')) {
                        $titles[$col] = $title;
                    }
                }

                if (count($titles) >= 2) {
                    foreach ($titles as $col => $title) {
                        $jobLevels[$col] = $title;
                        $profiles[$title] = [];
                    }
                }

                continue;
            }

            // Data rows: the competency's number in column A, a level letter
            // per job column. We key on the number because the name in column
            // B is frequently a cross-sheet formula that reads back unresolved.
            $number = $this->number($cells[0] ?? null);
            if ($number === null) {
                continue;
            }

            foreach ($jobLevels as $col => $title) {
                $level = ProficiencyLevel::parse($cells[$col] ?? null);
                if ($level !== null && $level !== ProficiencyLevel::None) {
                    $profiles[$title][$number] = $level;
                }
            }
        }

        // Drop any job level that ended up requiring nothing.
        return array_filter($profiles, static fn (array $reqs): bool => $reqs !== []);
    }

    /**
     * @return list<string>
     */
    private function cells(Row $rowObj): array
    {
        return array_values(array_map(
            static function (mixed $value): string {
                if (is_string($value)) {
                    return trim($value);
                }
                if (is_int($value) || is_float($value)) {
                    return trim((string) $value);
                }
                if ($value instanceof \DateTimeInterface) {
                    return $value->format('Y-m-d');
                }

                // Rich-text runs, intervals, booleans, nulls — not cell text we read.
                return '';
            },
            $rowObj->toArray(),
        ));
    }

    // --- resolving the file list ----------------------------------------------

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

    /**
     * The business function a workbook is for, read off its file name.
     *
     * "Competency Assessment Template_All_Human Resources.xlsx" → "Human
     * Resources". Everything before "_All_" is the template's own name; the
     * placement notes, dates and revision markers after it are stripped.
     */
    private function functionFromFileName(string $path): string
    {
        $base = pathinfo($path, PATHINFO_FILENAME);

        // Take what follows the "_All_" marker, else the whole name.
        if (preg_match('/_All_(.+)$/', $base, $m) === 1) {
            $base = $m[1];
        }

        // Separators to spaces first, so a date glued on with an underscore
        // ("_22.10.2025") is no longer hidden from the word-boundary matches.
        $lower = mb_strtolower($base);
        $lower = preg_replace('/[_\-.]+/', ' ', $lower) ?? $lower;

        foreach (self::NOISE as $noise) {
            $lower = str_replace($noise, ' ', $lower);
        }

        // Drop dates, four-digit years, and version markers ("v12").
        $lower = preg_replace('/\b\d{1,2}\s+\d{1,2}\s+\d{2,4}\b/', ' ', $lower) ?? $lower;
        $lower = preg_replace('/\bv?\d{2,4}\b/', ' ', $lower) ?? $lower;
        $lower = preg_replace('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)\b/', ' ', $lower) ?? $lower;
        $clean = trim(preg_replace('/\s+/', ' ', $lower) ?? $lower);

        // Title Case, so "human resources" reads as "Human Resources".
        return $clean === '' ? 'General' : mb_convert_case($clean, MB_CASE_TITLE);
    }

    /**
     * The function a section is for, read off its dictionary sheet name.
     *
     * "HR Detailed Competences" → "HR"; "Prod Technology Competencies" →
     * "Prod Technology". Used only for multi-function workbooks, where the file
     * name names no single function; single-function files use the file name,
     * which spells the function out in full.
     */
    private function functionFromSheetName(string $name): string
    {
        $lower = mb_strtolower($name);
        // Strip the competency/mapping words that trail the function.
        $lower = preg_replace(
            '/\b(detailed|competenc(?:e|es|ies|y)|comp\.?|mapping|framework)\b/',
            ' ',
            $lower,
        ) ?? $lower;
        $clean = trim(preg_replace('/\s+/', ' ', $lower) ?? $lower);

        return $clean === '' ? 'General' : mb_convert_case($clean, MB_CASE_TITLE);
    }

    /**
     * True for an old BIFF .xls file wearing an .xlsx name.
     *
     * Several of the completed workbooks were saved in the old format and
     * renamed; they open by hand but are not zip archives, so the reader
     * cannot touch them. Detected by the OLE2 compound-document signature.
     */
    private function isLegacyXls(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $magic = (string) fread($handle, 8);
        fclose($handle);

        return $magic === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    }

    /**
     * The competency number in column A, as a bare string — "1", "12".
     *
     * Floats sneak in (a cell typed as a number reads back "1.0"), so it is
     * normalised to the integer it stands for; anything non-numeric is not a
     * data row.
     */
    private function number(mixed $value): ?string
    {
        $text = $this->str($value);
        if ($text === null || !is_numeric($text)) {
            return null;
        }

        return (string) (int) (float) $text;
    }

    private function str(mixed $value): ?string
    {
        if (!is_string($value)) {
            return $value === null ? null : (string) $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
