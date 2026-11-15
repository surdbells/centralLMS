<?php

declare(strict_types=1);

namespace Central\Api\Application\Import;

use Central\Api\Domain\LearningPlan\LearningPlan;
use Central\Api\Domain\LearningPlan\LearningPlanRepository;

/**
 * Matches a parsed Learning Plan workbook to the tenant's people and plans,
 * works out what would change (preview), and writes it (commit).
 *
 * One plan per sheet, found by year + title ("2026 Learning Plan – MWOG") or
 * created. People are matched by e-mail; nobody is ever created, and no profile
 * field is ever written. Each person's trainings become per-person items keyed
 * by (email, category, normalised title), so importing the same file twice
 * changes nothing and a corrected file updates in place. Items no longer in the
 * file are only removed when asked.
 *
 * @phpstan-import-type ParsedSheet from LearningPlanWorkbook
 * @phpstan-import-type PlanItem from LearningPlanWorkbook
 */
final class LearningPlanImporter
{
    private const UNPARSED_LIMIT = 200;

    public function __construct(
        private readonly LearningPlanRepository $plans,
        private readonly LearningPlanWorkbook $workbook = new LearningPlanWorkbook(),
    ) {
    }

    /**
     * Reads the workbook and reports, per sheet, what an import would do.
     * Writes nothing.
     *
     * @return array{file: string, sheets: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function preview(string $tenantId, string $path, string $fileName): array
    {
        return $this->previewParsed($tenantId, $this->workbook->read($path), $fileName);
    }

    /**
     * The preview of an already-read workbook. Kept apart from read() so a
     * database fault is not mistaken for an unreadable file.
     *
     * @param list<ParsedSheet> $parsed  from read()
     *
     * @return array{file: string, sheets: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function previewParsed(string $tenantId, array $parsed, string $fileName): array
    {
        $users = $this->plans->userEmailIndex($tenantId);

        $sheets = [];
        foreach ($parsed as $sheet) {
            $sheets[] = $this->analyse($tenantId, $sheet, $users)['preview'];
        }

        return ['file' => $fileName, 'sheets' => $sheets, 'totals' => $this->totals($sheets)];
    }

    /**
     * The sheets of the workbook by name, with their kind — so the caller can
     * reject a commit naming a sheet that is not a plan before writing.
     *
     * @return list<ParsedSheet>
     */
    public function read(string $path): array
    {
        return $this->workbook->read($path);
    }

    /**
     * Imports the named plan sheets. Each sheet is its own transaction: one
     * that fails is rolled back and reported; the others still land.
     *
     * @param list<ParsedSheet> $parsed  from read()
     * @param list<string>      $sheetNames
     *
     * @return array{file: string, sheets: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function commit(
        string $tenantId,
        array $parsed,
        string $fileName,
        array $sheetNames,
        bool $publish,
        bool $removeMissing,
    ): array {
        $users  = $this->plans->userEmailIndex($tenantId);
        $wanted = array_flip($sheetNames);
        $sheets = [];
        $sums   = ['itemsCreated' => 0, 'itemsUpdated' => 0, 'itemsRemoved' => 0, 'membersAdded' => 0];

        foreach ($parsed as $sheet) {
            if (!isset($wanted[$sheet['sheet']]) || $sheet['kind'] !== 'plan') {
                continue;
            }

            // Re-analysed just before writing, so two sheets that land in the
            // same plan each see the other's writes.
            $analysis = $this->analyse($tenantId, $sheet, $users);
            $written  = [
                'planId'         => null,
                'planCreated'    => false,
                'membersAdded'   => 0,
                'itemsCreated'   => 0,
                'itemsUpdated'   => 0,
                'itemsUnchanged' => 0,
                'itemsRemoved'   => 0,
                'published'      => false,
                'error'          => null,
            ];

            if ((int) $analysis['preview']['people']['matched'] === 0) {
                // Not importable: never create an empty (let alone published) plan.
                $written['error'] = 'No one on this sheet matches a person in the organisation; nothing was imported.';
            } else {
                try {
                    $written = $this->plans->transactional(
                        fn (): array => $this->write($tenantId, $fileName, $sheet, $analysis, $publish, $removeMissing),
                    );
                } catch (\Throwable $e) {
                    $written['error'] = $e instanceof \Central\Api\Domain\Exception\ValidationFailed
                        ? $e->getMessage()
                        : 'The sheet could not be imported; nothing from it was saved.';
                }
            }

            foreach (array_keys($sums) as $k) {
                $sums[$k] += (int) $written[$k];
            }

            $sheets[] = $analysis['preview'] + ['written' => $written];
        }

        return ['file' => $fileName, 'sheets' => $sheets, 'totals' => $this->totals($sheets) + $sums];
    }

    // --- analysis ----------------------------------------------------------------

    /**
     * @param ParsedSheet                                                  $sheet
     * @param array<string, array{id: string, name: string, status: string}> $users
     *
     * @return array{preview: array<string, mixed>, plan: ?LearningPlan, writes: list<array{importKey: string, userId: string, title: string, category: string, provider: ?string, scheduleText: ?string, plannedStart: ?string, plannedEnd: ?string, sortOrder: int}>, stale: list<string>, memberIds: list<string>}
     */
    private function analyse(string $tenantId, array $sheet, array $users): array
    {
        $warnings = $sheet['warnings'];
        $preview  = [
            'sheet'              => $sheet['sheet'],
            'index'              => $sheet['index'],
            'kind'               => $sheet['kind'],
            'importable'         => false,
            'year'               => $sheet['year'],
            'entity'             => $sheet['entity'],
            'planTitle'          => $sheet['planTitle'],
            'existingPlanId'     => null,
            'existingPlanStatus' => null,
            'columns'            => array_map(static fn (array $c): array => [
                'index'         => $c['index'],
                'header'        => $c['header'],
                'category'      => $c['category'],
                'providerIndex' => $c['providerIndex'],
            ], $sheet['columns']),
            'rows'     => $sheet['rows'],
            'people'   => ['matched' => 0, 'unmatched' => 0, 'noEmail' => 0, 'duplicate' => 0],
            'matched'  => [],
            'unmatched' => [],
            'skipped'  => $sheet['skipped'],
            'items'    => ['create' => 0, 'update' => 0, 'unchanged' => 0, 'stale' => 0, 'byCategory' => []],
            'dates'    => ['parsed' => 0, 'textOnly' => 0, 'none' => 0],
            'unparsedDates' => [],
            'warnings' => [],
        ];

        foreach ($sheet['skipped'] as $s) {
            $preview['people'][$s['reason'] === 'no_email' ? 'noEmail' : 'duplicate']++;
        }

        $result = ['preview' => $preview, 'plan' => null, 'writes' => [], 'stale' => [], 'memberIds' => []];

        if ($sheet['kind'] !== 'plan' || $sheet['year'] === null || $sheet['planTitle'] === null) {
            $result['preview']['warnings'] = $warnings;

            return $result;
        }

        $plan     = $this->plans->findByYearTitle($tenantId, $sheet['year'], $sheet['planTitle']);
        $existing = $plan === null ? [] : $this->plans->importedItems($tenantId, $plan->id);

        $preview['existingPlanId']     = $plan?->id;
        $preview['existingPlanStatus'] = $plan?->status;

        $writes     = [];
        $fileKeys   = [];
        $matchedIds = [];

        foreach ($sheet['people'] as $person) {
            foreach ($person['items'] as $item) {
                $this->countDate($preview, $person, $item);
            }

            $user = $users[$person['email']] ?? null;

            if ($user === null) {
                $preview['people']['unmatched']++;
                $preview['unmatched'][] = ['row' => $person['row'], 'email' => $person['email'], 'name' => $person['name']];

                continue;
            }

            if (!in_array($user['status'], ['active', 'invited'], true)) {
                $warnings[] = [
                    'row'     => $person['row'],
                    'code'    => 'user_inactive',
                    'message' => sprintf('%s is %s; their plan is imported anyway.', $person['email'], $user['status']),
                ];
            }

            $preview['people']['matched']++;
            $matchedIds[$user['id']] = true;
            $counts = ['create' => 0, 'update' => 0, 'unchanged' => 0];

            foreach ($person['items'] as $item) {
                $fileKeys[$item['key']] = true;
                $old    = $existing[$item['key']] ?? null;
                $action = $old === null ? 'create' : ($this->same($old, $user['id'], $item) ? 'unchanged' : 'update');

                $counts[$action]++;
                $preview['items'][$action]++;
                $preview['items']['byCategory'][$item['category']][$action] =
                    ($preview['items']['byCategory'][$item['category']][$action] ?? 0) + 1;

                if ($action !== 'unchanged') {
                    $writes[] = [
                        'importKey'    => $item['key'],
                        'userId'       => $user['id'],
                        'title'        => $item['title'],
                        'category'     => $item['category'],
                        'provider'     => $item['provider'],
                        'scheduleText' => $item['scheduleText'],
                        'plannedStart' => $item['start'],
                        'plannedEnd'   => $item['end'],
                        'sortOrder'    => $item['sortOrder'],
                    ];
                }
            }

            $preview['matched'][] = [
                'row'        => $person['row'],
                'email'      => $person['email'],
                'userId'     => $user['id'],
                'name'       => $person['name'] ?? $user['name'],
                'userStatus' => $user['status'],
                'items'      => $counts,
            ];
        }

        // Fill every category with all three counts, for a stable shape.
        foreach ($preview['items']['byCategory'] as $cat => $c) {
            $preview['items']['byCategory'][$cat] = $c + ['create' => 0, 'update' => 0, 'unchanged' => 0];
        }

        // Imported items of people matched in this sheet whose key is gone.
        $stale = [];
        foreach ($existing as $key => $old) {
            if ($old['user_id'] !== null && isset($matchedIds[$old['user_id']]) && !isset($fileKeys[$key])) {
                $stale[] = $old['id'];
            }
        }

        $preview['items']['stale'] = count($stale);
        $preview['importable']     = $preview['people']['matched'] > 0;
        $preview['warnings']       = $warnings;

        return [
            'preview'   => $preview,
            'plan'      => $plan,
            'writes'    => $writes,
            'stale'     => $stale,
            'memberIds' => array_keys($matchedIds),
        ];
    }

    /**
     * @param array{id: string, user_id: ?string, title: string, category: ?string, provider: ?string, schedule_text: ?string, planned_start: ?string, planned_end: ?string, sort_order: int} $old
     * @param PlanItem $item
     */
    private function same(array $old, string $userId, array $item): bool
    {
        return $old['user_id'] === $userId
            && $old['title'] === $item['title']
            && $old['category'] === $item['category']
            && $old['provider'] === $item['provider']
            && $old['schedule_text'] === $item['scheduleText']
            && $old['planned_start'] === $item['start']
            && $old['planned_end'] === $item['end']
            && $old['sort_order'] === $item['sortOrder'];
    }

    /**
     * @param array<string, mixed>                                $preview
     * @param array{row: int, email: string, name: ?string}       $person
     * @param PlanItem                                            $item
     */
    private function countDate(array &$preview, array $person, array $item): void
    {
        if ($item['start'] !== null) {
            $preview['dates']['parsed']++;

            return;
        }

        $preview['dates'][$item['scheduleText'] !== null ? 'textOnly' : 'none']++;

        if (count($preview['unparsedDates']) < self::UNPARSED_LIMIT && ($item['scheduleText'] !== null || $item['provider'] !== null)) {
            $preview['unparsedDates'][] = [
                'row'          => $person['row'],
                'email'        => $person['email'],
                'category'     => $item['category'],
                'title'        => $item['title'],
                'provider'     => $item['provider'],
                'scheduleText' => $item['scheduleText'],
            ];
        }
    }

    // --- writing -----------------------------------------------------------------

    /**
     * @param ParsedSheet $sheet
     * @param array{preview: array<string, mixed>, plan: ?LearningPlan, writes: list<array{importKey: string, userId: string, title: string, category: string, provider: ?string, scheduleText: ?string, plannedStart: ?string, plannedEnd: ?string, sortOrder: int}>, stale: list<string>, memberIds: list<string>} $analysis
     *
     * @return array{planId: ?string, planCreated: bool, membersAdded: int, itemsCreated: int, itemsUpdated: int, itemsUnchanged: int, itemsRemoved: int, published: bool, error: ?string}
     */
    private function write(
        string $tenantId,
        string $fileName,
        array $sheet,
        array $analysis,
        bool $publish,
        bool $removeMissing,
    ): array {
        $plan    = $analysis['plan'];
        $created = false;

        if ($plan === null) {
            $plan = $this->plans->save(LearningPlan::fromInput('', $tenantId, [
                'year'        => $sheet['year'],
                'title'       => $sheet['planTitle'],
                'description' => sprintf('Imported from %s · sheet "%s"', $fileName, trim($sheet['sheet'])),
                'status'      => $publish ? 'published' : 'draft',
            ]));
            $created = true;
        }

        $out = [
            'planId'         => $plan->id,
            'planCreated'    => $created,
            'membersAdded'   => $this->plans->assignMembers($tenantId, $plan->id, $analysis['memberIds']),
            'itemsCreated'   => 0,
            'itemsUpdated'   => 0,
            'itemsUnchanged' => (int) ($analysis['preview']['items']['unchanged'] ?? 0),
            'itemsRemoved'   => 0,
            'published'      => $plan->status === 'published',
            'error'          => null,
        ];

        foreach ($analysis['writes'] as $row) {
            $what = $this->plans->upsertImportedItem($tenantId, $plan->id, $row);
            $out[$what === 'created' ? 'itemsCreated' : 'itemsUpdated']++;
        }

        if ($removeMissing && $analysis['stale'] !== []) {
            $out['itemsRemoved'] = $this->plans->deleteItemsByIds($tenantId, $plan->id, $analysis['stale']);
        }

        if ($publish && $plan->status !== 'published') {
            $this->plans->setStatus($tenantId, $plan->id, 'published');
            $out['published'] = true;
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $sheets
     *
     * @return array<string, int>
     */
    private function totals(array $sheets): array
    {
        $t = [
            'sheets' => count($sheets), 'importableSheets' => 0, 'matchedPeople' => 0, 'unmatchedPeople' => 0,
            'itemsCreate' => 0, 'itemsUpdate' => 0, 'itemsUnchanged' => 0, 'itemsStale' => 0,
        ];

        foreach ($sheets as $s) {
            /** @var array{importable: bool, people: array{matched: int, unmatched: int}, items: array{create: int, update: int, unchanged: int, stale: int}} $s */
            $t['importableSheets'] += $s['importable'] ? 1 : 0;
            $t['matchedPeople']    += $s['people']['matched'];
            $t['unmatchedPeople']  += $s['people']['unmatched'];
            $t['itemsCreate']      += $s['items']['create'];
            $t['itemsUpdate']      += $s['items']['update'];
            $t['itemsUnchanged']   += $s['items']['unchanged'];
            $t['itemsStale']       += $s['items']['stale'];
        }

        return $t;
    }
}
