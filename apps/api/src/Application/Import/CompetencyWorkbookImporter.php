<?php

declare(strict_types=1);

namespace Central\Api\Application\Import;

use Central\Api\Domain\Competency\Competency;
use Central\Api\Domain\Competency\CompetencyRepository;
use Central\Api\Domain\Competency\JobCompetencyProfile;
use Central\Api\Domain\Competency\ProficiencyLevel;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Reader\XLSX\Sheet;
use Ramsey\Uuid\Uuid;

/**
 * Turns a completed Job Competency Profile workbook into competencies and
 * job profiles.
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
 * The two sheets are joined by the row number in column A, because the mapping
 * frequently names competencies with a cross-sheet formula that reads back
 * unresolved — but both sheets number their rows, and those numbers line up.
 *
 * The per-person sheets are individual assessments, but their identity is a
 * VLOOKUP into a staffing workbook that does not travel with the file, so a
 * person cannot be matched to a user from the workbook alone. They are counted
 * and reported, never imported.
 *
 * Idempotent: competencies are matched by name within the function and
 * profiles by job title, so a re-run updates rather than duplicates.
 *
 * This is the whole of the import — the console command and the HTTP endpoint
 * are two ways in to the same method.
 */
final class CompetencyWorkbookImporter
{
    /**
     * Tokens that trail a function name in the file names — placement notes,
     * revision markers, dates — and are not part of the function itself.
     */
    private const NOISE = [
        'placement', 'final', 'completed', 'copy of', 'template', 'all',
        'competency assessment', 'uon comments',
    ];

    public function __construct(private readonly CompetencyRepository $repository)
    {
    }

    /**
     * Imports one workbook for one tenant, returning a summary of what it held.
     *
     * `$originalName` is the workbook's own file name, from which a single-
     * function workbook takes its function. It is passed separately because an
     * upload is read from a temporary path whose name is random — deriving the
     * function from that would name every function after the temp file.
     *
     * @return array{
     *     file: string,
     *     legacyXls: bool,
     *     empty: bool,
     *     functions: list<array{function: string, competencies: int, profiles: list<array{jobTitle: string, requirements: int}>}>,
     *     people: int
     * }
     */
    public function importFile(
        string $tenantId,
        string $path,
        ?string $functionOverride = null,
        bool $dryRun = false,
        ?string $originalName = null,
    ): array {
        $name = $originalName ?? basename($path);

        $summary = [
            'file'      => $name,
            'legacyXls' => false,
            'empty'     => false,
            'functions' => [],
            'people'    => 0,
        ];

        if ($this->isLegacyXls($path)) {
            $summary['legacyXls'] = true;

            return $summary;
        }

        $parsed = $this->parseWorkbook($path);
        $summary['people'] = $parsed['people'];

        if ($parsed['sections'] === []) {
            $summary['empty'] = true;

            return $summary;
        }

        // A workbook with a single competency section is a whole function, and
        // its file name names it better than the abbreviated sheet name. A
        // workbook with several sections holds several sub-functions in one
        // file, so each keeps the name read off its own sheet.
        $single = count($parsed['sections']) === 1;

        foreach ($parsed['sections'] as $section) {
            if ($section['competencies'] === []) {
                continue;
            }

            $function = $single
                ? ($functionOverride ?? $this->functionFromFileName($name))
                : $section['function'];

            $summary['functions'][] = $this->importSection($section, $function, $tenantId, $dryRun);
        }

        return $summary;
    }

    /**
     * True for an old BIFF .xls file wearing an .xlsx name.
     *
     * Several completed workbooks were saved in the old format and renamed;
     * they open by hand but are not zip archives, so the reader cannot touch
     * them. Detected by the OLE2 compound-document signature.
     */
    public function isLegacyXls(string $path): bool
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
     * @param array{function: string, competencies: list<array{number: ?string, name: string, definition: ?string, knowledge: ?string, skill: ?string, mastery: ?string}>, profiles: array<string, array<string, ProficiencyLevel>>} $section
     *
     * @return array{function: string, competencies: int, profiles: list<array{jobTitle: string, requirements: int}>}
     */
    private function importSection(array $section, string $function, string $tenantId, bool $dryRun): array
    {
        // Keyed by the competency's number in column A — see the class comment.
        $idByNumber = [];
        foreach ($section['competencies'] as $comp) {
            $existing = $this->repository->findCompetencyByName($tenantId, $function, $comp['name']);
            $id = $existing !== null ? $existing->id : Uuid::uuid4()->toString();
            if ($comp['number'] !== null) {
                $idByNumber[$comp['number']] = $id;
            }

            if (!$dryRun) {
                $this->repository->saveCompetency(new Competency(
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
        }

        $profiles = [];
        foreach ($section['profiles'] as $jobTitle => $levels) {
            $requirements = [];
            foreach ($levels as $number => $level) {
                $id = $idByNumber[$number] ?? null;
                if ($id === null) {
                    // A mapping row whose number has no competency in the
                    // dictionary sheet — dropped, never guessed at.
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

            $existing = $this->repository->findProfileByTitle($tenantId, $function, $jobTitle);
            $profileId = $existing !== null ? $existing->id : Uuid::uuid4()->toString();

            if (!$dryRun) {
                $this->repository->saveProfile(new JobCompetencyProfile(
                    id: $profileId,
                    tenantId: $tenantId,
                    jobTitle: $jobTitle,
                    functionName: $function,
                    requirements: $requirements,
                ));
            }

            $profiles[] = ['jobTitle' => $jobTitle, 'requirements' => count($requirements)];
        }

        return [
            'function'     => $function,
            'competencies' => count($section['competencies']),
            'profiles'     => $profiles,
        ];
    }

    // --- reading the workbook -------------------------------------------------

    /**
     * A workbook is a run of sections, each a dictionary sheet optionally
     * paired with its mapping sheet (in either order). Most workbooks hold one;
     * the technical workbook holds several sub-functions in a single file.
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
            $lower = mb_strtolower($sheet->getName());

            $isMapping = str_contains($lower, 'mapping') || str_contains($lower, 'framework');
            $isDictionary = !$isMapping
                && (str_contains($lower, 'competence') || str_contains($lower, 'competenc'));

            if ($isDictionary) {
                if ($current !== null) {
                    $sections[] = $current;
                }
                $current = [
                    'function'     => $this->functionFromSheetName($sheet->getName()),
                    'competencies' => $this->readDictionary($sheet),
                    'profiles'     => $pendingMapping ?? [],
                ];
                $pendingMapping = null;
            } elseif ($isMapping) {
                if ($current !== null && $current['profiles'] === []) {
                    $current['profiles'] = $this->readMapping($sheet);
                } else {
                    $pendingMapping = $this->readMapping($sheet);
                }
            } elseif ($this->isMetadataSheet($lower)) {
                continue;
            } else {
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
            // per job column.
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

    // --- naming ---------------------------------------------------------------

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

        // Drop dates, four-digit years, version markers and month names.
        $lower = preg_replace('/\b\d{1,2}\s+\d{1,2}\s+\d{2,4}\b/', ' ', $lower) ?? $lower;
        $lower = preg_replace('/\bv?\d{2,4}\b/', ' ', $lower) ?? $lower;
        $lower = preg_replace('/\b(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)\b/', ' ', $lower) ?? $lower;
        $clean = trim(preg_replace('/\s+/', ' ', $lower) ?? $lower);

        return $clean === '' ? 'General' : mb_convert_case($clean, MB_CASE_TITLE);
    }

    /**
     * The function a section is for, read off its dictionary sheet name.
     *
     * "HR Detailed Competences" → "HR"; "Prod Technology Competencies" →
     * "Prod Technology". Used only for multi-function workbooks, where the file
     * name names no single function.
     */
    private function functionFromSheetName(string $name): string
    {
        $lower = mb_strtolower($name);
        $lower = preg_replace(
            '/\b(detailed|competenc(?:e|es|ies|y)|comp\.?|mapping|framework)\b/',
            ' ',
            $lower,
        ) ?? $lower;
        $clean = trim(preg_replace('/\s+/', ' ', $lower) ?? $lower);

        return $clean === '' ? 'General' : mb_convert_case($clean, MB_CASE_TITLE);
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
