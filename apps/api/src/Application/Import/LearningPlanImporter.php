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
 * created. People are matched by e-mail, and no existing profile is ever
 * written. With `createMissing`, somebody the organisation does not have yet is
 * created as an `invited` account ({@see ImportedPersonCreator}, shared with the
 * placement import) from what the plan sheets say about them — designation,
 * department, division, grade, entity — and made a member with their items, in
 * the same per-sheet transaction. Each person's trainings become per-person items keyed
 * by (email, category, normalised title), so importing the same file twice
 * changes nothing and a corrected file updates in place. Items no longer in the
 * file are only removed when asked.
 *
 * @phpstan-import-type ParsedSheet from LearningPlanWorkbook
 * @phpstan-import-type PlanItem from LearningPlanWorkbook
 * @phpstan-import-type PlanProfile from LearningPlanWorkbook
 * @phpstan-type BestPerson array{name: ?string, profile: PlanProfile, nameMatchesEmail: bool, entityCodes: array<string, string>}
 * @phpstan-type ToCreate array{row: int, email: string, firstName: string, lastName: string, jobTitle: ?string, department: ?string, division: ?string, level: ?string, entity: ?string, previouslyDeleted: bool}
 * @phpstan-type CannotCreate array{row: int, email: string, name: ?string, reason: string, message: string}
 */
final class LearningPlanImporter
{
    private const UNPARSED_LIMIT = 200;

    /** The stand-in id of somebody a preview with createMissing treats as created. */
    private const PENDING = 'pending:';

    public function __construct(
        private readonly LearningPlanRepository $plans,
        private readonly LearningPlanWorkbook $workbook = new LearningPlanWorkbook(),
        private readonly ?ImportedPersonCreator $creator = null,
    ) {
    }

    /**
     * Reads the workbook and reports, per sheet, what an import would do.
     * Writes nothing.
     *
     * @return array{file: string, createMissing: bool, sheets: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function preview(string $tenantId, string $path, string $fileName, bool $createMissing = false): array
    {
        return $this->previewParsed($tenantId, $this->workbook->read($path), $fileName, $createMissing);
    }

    /**
     * The preview of an already-read workbook. Kept apart from read() so a
     * database fault is not mistaken for an unreadable file.
     *
     * @param list<ParsedSheet> $parsed  from read()
     *
     * @return array{file: string, createMissing: bool, sheets: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function previewParsed(string $tenantId, array $parsed, string $fileName, bool $createMissing = false): array
    {
        $users    = $this->plans->userEmailIndex($tenantId);
        $profiles = self::bestPeople($parsed);
        $deleted  = array_flip($this->creator?->deletedEmails($tenantId) ?? []);

        $sheets = [];
        foreach ($parsed as $sheet) {
            $sheets[] = $this->analyse($tenantId, $sheet, $users, $createMissing, $profiles, $deleted)['preview'];
        }

        return [
            'file'          => $fileName,
            'createMissing' => $createMissing,
            'sheets'        => $sheets,
            'totals'        => $this->totals($sheets) + self::creationTotals($sheets),
        ];
    }

    /**
     * Per address, the best the plan sheets say about a person: the latest
     * year's sheet first (then workbook order), with any field it leaves blank
     * filled from the other sheets. So somebody on the 2026 and 2025 sheets is
     * created once, with their 2026 designation, whichever sheet creates them.
     *
     * @param list<ParsedSheet> $parsed
     *
     * @return array<string, BestPerson>
     */
    public static function bestPeople(array $parsed): array
    {
        $sheets = array_values(array_filter($parsed, static fn (array $s): bool => $s['kind'] === 'plan'));
        usort($sheets, static fn (array $a, array $b): int => [-(int) $a['year'], $a['index']] <=> [-(int) $b['year'], $b['index']]);

        $codes = self::entityCodes($parsed);
        $best  = [];
        $names = [];

        foreach ($sheets as $sheet) {
            foreach ($sheet['people'] as $person) {
                $email   = $person['email'];
                $profile = $person['profile'];
                $dropped = [];

                // An entity code typed into Department / Division ("UPIL",
                // "MESL") is not a department: left unset rather than invented.
                foreach (['department', 'division'] as $field) {
                    $key = $profile[$field] !== null ? PlacementImporter::normalise($profile[$field]) : '';

                    if ($key !== '' && isset($codes[$key])) {
                        $dropped[$field] = (string) $profile[$field];
                        $profile[$field] = null;
                    }
                }

                if ($person['name'] !== null && $person['name'] !== '') {
                    $names[$email][] = $person['name'];
                }

                if (!isset($best[$email])) {
                    $best[$email] = ['name' => null, 'profile' => $profile, 'nameMatchesEmail' => true, 'entityCodes' => $dropped];

                    continue;
                }

                foreach ($profile as $field => $value) {
                    $best[$email]['profile'][$field] ??= $value;
                }

                $best[$email]['entityCodes'] += $dropped;
            }
        }

        foreach ($best as $email => $b) {
            // The name the address bears out best, the latest sheet's on a tie
            // — so a name cell copied from the row above ("Ada Bola Obi" for
            // ada.test@, when 2025 says "Ada Test") loses to the right one.
            $candidates = $names[$email] ?? [];
            $chosen     = $candidates[0] ?? null;
            $score      = 0;

            foreach ($candidates as $candidate) {
                $s = NameCase::emailScore($candidate, $email);

                if ($s > $score) {
                    $chosen = $candidate;
                    $score  = $s;
                }
            }

            $best[$email]['name']             = $chosen;
            $best[$email]['nameMatchesEmail'] = $chosen === null || $score > 0;
            // Only what no sheet filled with a real value is worth reporting.
            $best[$email]['entityCodes'] = array_filter(
                $b['entityCodes'],
                static fn (string $field): bool => $b['profile'][$field] === null,
                ARRAY_FILTER_USE_KEY,
            );
        }

        return $best;
    }

    /**
     * The organisation's entity codes as the workbook spells them — every
     * plan sheet's entity and every row's Entity cell — plus the group's codes
     * that only ever appear in Department / Division, all normalised.
     *
     * @param list<ParsedSheet> $parsed
     *
     * @return array<string, true>
     */
    private static function entityCodes(array $parsed): array
    {
        $codes = array_fill_keys(['mwog', 'upil', 'mesl', 'mart', 'midsun', 'mwmdp'], true);

        foreach ($parsed as $sheet) {
            if ($sheet['entity'] !== null && $sheet['entity'] !== '') {
                $codes[PlacementImporter::normalise($sheet['entity'])] = true;
            }

            foreach ($sheet['people'] as $person) {
                if ($person['profile']['entity'] !== null && $person['profile']['entity'] !== '') {
                    $codes[PlacementImporter::normalise($person['profile']['entity'])] = true;
                }
            }
        }

        unset($codes['']);

        return $codes;
    }

    /**
     * @param list<array<string, mixed>> $sheets previews
     *
     * @return array{toCreate: int, cannotCreate: int}
     */
    private static function creationTotals(array $sheets): array
    {
        $emails = [];
        $cannot = 0;

        foreach ($sheets as $s) {
            foreach (is_array($s['toCreate'] ?? null) ? $s['toCreate'] : [] as $p) {
                if (is_array($p) && is_string($p['email'] ?? null)) {
                    $emails[$p['email']] = true;
                }
            }
            $cannot += is_array($s['cannotCreate'] ?? null) ? count($s['cannotCreate']) : 0;
        }

        // Distinct: somebody on three sheets is one account.
        return ['toCreate' => count($emails), 'cannotCreate' => $cannot];
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
     * With `$createMissing`, the people a sheet names who have no account are
     * created inside that sheet's transaction, then made members with their
     * items; somebody on several sheets is created once.
     *
     * @param list<ParsedSheet> $parsed  from read()
     * @param list<string>      $sheetNames
     *
     * @return array{file: string, createMissing: bool, sheets: list<array<string, mixed>>, totals: array<string, int>, createdPeople: list<array{row: int, email: string, userId: string, sheet: string}>}
     */
    public function commit(
        string $tenantId,
        array $parsed,
        string $fileName,
        array $sheetNames,
        bool $publish,
        bool $removeMissing,
        bool $createMissing = false,
        ?string $roleId = null,
        ?string $actorId = null,
    ): array {
        $creator = null;
        $roleIds = [];

        if ($createMissing) {
            $creator = $this->creator ?? throw new \LogicException('Creating people needs an ImportedPersonCreator.');
            // Before any write, so an unknown role refuses the whole commit.
            $roleIds = $creator->roleIds($tenantId, $roleId);
        }

        $users    = $this->plans->userEmailIndex($tenantId);
        $profiles = self::bestPeople($parsed);
        $deleted  = array_flip($this->creator?->deletedEmails($tenantId) ?? []);
        $wanted   = array_flip($sheetNames);
        $sheets   = [];
        $created  = [];
        $sums     = ['itemsCreated' => 0, 'itemsUpdated' => 0, 'itemsRemoved' => 0, 'membersAdded' => 0, 'peopleCreated' => 0];

        foreach ($parsed as $sheet) {
            if (!isset($wanted[$sheet['sheet']]) || $sheet['kind'] !== 'plan') {
                continue;
            }

            // Re-analysed just before writing, so two sheets that land in the
            // same plan each see the other's writes — and somebody created by
            // an earlier sheet is simply matched here.
            $analysis = $this->analyse($tenantId, $sheet, $users, $createMissing, $profiles, $deleted);
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
                'peopleCreated'  => 0,
                'createdPeople'  => [],
            ];

            $toCreate = $creator !== null ? $analysis['toCreate'] : [];

            if ((int) $analysis['preview']['people']['matched'] === 0 && $toCreate === []) {
                // Not importable: never create an empty (let alone published) plan.
                $written['error'] = $createMissing
                    ? 'No one on this sheet matches or can be created; nothing was imported.'
                    : 'No one on this sheet matches a person in the organisation; nothing was imported.';
            } else {
                try {
                    [$written, $newUsers] = $this->plans->transactional(
                        function () use ($tenantId, $fileName, $sheet, $analysis, $publish, $removeMissing, $users, $toCreate, $creator, $roleIds, $actorId): array {
                            $newUsers = [];
                            $made     = [];

                            foreach ($toCreate as $c) {
                                \assert($creator !== null);
                                $result = $creator->create($tenantId, self::spec($c), $roleIds, $actorId);
                                $person = $result['person'];

                                $newUsers[$c['email']] = [
                                    'id'     => (string) $person->id,
                                    'name'   => trim($person->firstName . ' ' . $person->lastName),
                                    'status' => $person->status->value,
                                ];

                                if ($result['created']) {
                                    $made[] = ['row' => $c['row'], 'email' => $c['email'], 'userId' => (string) $person->id];
                                }
                            }

                            // With the new people in the index they are
                            // matched like anybody else: members, with items.
                            $final = $newUsers === [] ? $analysis : $this->analyse($tenantId, $sheet, $users + $newUsers);

                            return [
                                $this->write($tenantId, $fileName, $sheet, $final, $publish, $removeMissing)
                                    + ['peopleCreated' => count($made), 'createdPeople' => $made],
                                $newUsers,
                            ];
                        },
                    );

                    $users += $newUsers;

                    foreach ($written['createdPeople'] as $p) {
                        $created[] = $p + ['sheet' => $sheet['sheet']];
                    }
                } catch (\Throwable $e) {
                    // Rolled back: reference data the creator cached may be gone.
                    $creator?->forget();
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

        return [
            'file'          => $fileName,
            'createMissing' => $createMissing,
            'sheets'        => $sheets,
            'totals'        => $this->totals($sheets) + self::creationTotals($sheets) + $sums,
            'createdPeople' => $created,
        ];
    }

    /**
     * What the creator is given for one person.
     *
     * Division and entity are text profile fields. Entity is the row's own
     * "Entity" cell (JV / Sole / MidSun …): it is how the organisation splits
     * staff across its joint-venture and sole-risk arrangements, which the
     * sheet's own entity (MWOG, UPIL …) does not say, and as a profile field
     * it can slice reports. It is never inferred from the sheet name.
     *
     * @param ToCreate $c
     *
     * @return array{email: string, firstName: string, lastName: string, lookups: array<string, ?string>, custom: array<string, array{label: string, value: ?string}>}
     */
    private static function spec(array $c): array
    {
        return [
            'email'     => $c['email'],
            'firstName' => $c['firstName'],
            'lastName'  => $c['lastName'],
            'lookups'   => ['jobTitle' => $c['jobTitle'], 'department' => $c['department'], 'level' => $c['level']],
            'custom'    => [
                'division' => ['label' => 'Division', 'value' => $c['division']],
                'entity'   => ['label' => 'Entity', 'value' => $c['entity']],
            ],
        ];
    }

    // --- analysis ----------------------------------------------------------------

    /**
     * @param ParsedSheet                                                  $sheet
     * @param array<string, array{id: string, name: string, status: string}> $users
     * @param array<string, BestPerson>                                    $profiles from bestPeople()
     * @param array<string, int>                                           $deleted  addresses of deleted accounts (keys)
     *
     * @return array{preview: array<string, mixed>, plan: ?LearningPlan, writes: list<array{importKey: string, userId: string, title: string, category: string, provider: ?string, scheduleText: ?string, plannedStart: ?string, plannedEnd: ?string, sortOrder: int}>, stale: list<string>, memberIds: list<string>, toCreate: list<ToCreate>}
     */
    private function analyse(
        string $tenantId,
        array $sheet,
        array $users,
        bool $createMissing = false,
        array $profiles = [],
        array $deleted = [],
    ): array {
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
            // Unmatched people who could / could not be created as accounts.
            'toCreate'     => [],
            'cannotCreate' => [],
        ];

        foreach ($sheet['skipped'] as $s) {
            $preview['people'][$s['reason'] === 'no_email' ? 'noEmail' : 'duplicate']++;

            if ($s['reason'] === 'no_email') {
                $preview['cannotCreate'][] = [
                    'row' => $s['row'], 'email' => '', 'name' => $s['name'],
                    'reason' => 'missing_email', 'message' => 'No email address on this row.',
                ];
            }
        }

        $result = ['preview' => $preview, 'plan' => null, 'writes' => [], 'stale' => [], 'memberIds' => [], 'toCreate' => []];

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
        $toCreate   = [];

        foreach ($sheet['people'] as $person) {
            foreach ($person['items'] as $item) {
                $this->countDate($preview, $person, $item);
            }

            $user    = $users[$person['email']] ?? null;
            $pending = false;

            if ($user === null) {
                $preview['people']['unmatched']++;
                $preview['unmatched'][] = ['row' => $person['row'], 'email' => $person['email'], 'name' => $person['name']];

                $best    = $profiles[$person['email']] ?? ['name' => $person['name'], 'profile' => $person['profile'], 'nameMatchesEmail' => true, 'entityCodes' => []];
                $verdict = ImportedPersonCreator::assess($person['email'], $best['name'] ?? $person['name']);

                if (!$verdict['ok']) {
                    $preview['cannotCreate'][] = [
                        'row'     => $person['row'],
                        'email'   => $person['email'],
                        'name'    => $person['name'],
                        'reason'  => (string) $verdict['reason'],
                        'message' => (string) $verdict['message'],
                    ];

                    continue;
                }

                $toCreate[] = [
                    'row'               => $person['row'],
                    'email'             => $verdict['email'],
                    'firstName'         => $verdict['firstName'],
                    'lastName'          => $verdict['lastName'],
                    'jobTitle'          => $best['profile']['jobTitle'],
                    'department'        => $best['profile']['department'],
                    'division'          => $best['profile']['division'],
                    'level'             => $best['profile']['level'],
                    'entity'            => $best['profile']['entity'],
                    'previouslyDeleted' => isset($deleted[$verdict['email']]),
                ];

                // Worth a look before creating: the account would be named
                // after somebody the address does not name, or left without the
                // department the sheet gave only as an entity code.
                if (!$best['nameMatchesEmail']) {
                    $warnings[] = [
                        'row'     => $person['row'],
                        'code'    => 'name_email_mismatch',
                        'message' => sprintf('"%s %s" shares no name with %s; check the name before creating.', $verdict['firstName'], $verdict['lastName'], $verdict['email']),
                    ];
                }

                foreach ($best['entityCodes'] as $field => $value) {
                    $warnings[] = [
                        'row'     => $person['row'],
                        'code'    => 'department_entity_code',
                        'message' => sprintf('%s not set for %s: "%s" is an entity code, not a %s.', ucfirst($field), $verdict['email'], $value, $field),
                    ];
                }

                if (!$createMissing) {
                    continue;
                }

                // Counted as if already created, so the preview shows the
                // items they will get. Never written: the commit creates them
                // first and re-analyses with their real ids.
                $pending = true;
                $user    = ['id' => self::PENDING . $person['email'], 'name' => (string) $person['name'], 'status' => 'invited'];
            }

            if (!in_array($user['status'], ['active', 'invited'], true)) {
                $warnings[] = [
                    'row'     => $person['row'],
                    'code'    => 'user_inactive',
                    'message' => sprintf('%s is %s; their plan is imported anyway.', $person['email'], $user['status']),
                ];
            }

            if (!$pending) {
                $preview['people']['matched']++;
            }
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

            if ($pending) {
                continue;
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
        $preview['toCreate']       = $toCreate;
        $preview['importable']     = $preview['people']['matched'] > 0 || ($createMissing && $toCreate !== []);
        $preview['warnings']       = $warnings;

        return [
            'preview'   => $preview,
            'plan'      => $plan,
            'writes'    => $writes,
            'stale'     => $stale,
            'memberIds' => array_keys($matchedIds),
            'toCreate'  => $toCreate,
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
     * @param array{preview: array<string, mixed>, plan: ?LearningPlan, writes: list<array{importKey: string, userId: string, title: string, category: string, provider: ?string, scheduleText: ?string, plannedStart: ?string, plannedEnd: ?string, sortOrder: int}>, stale: list<string>, memberIds: list<string>, toCreate: list<ToCreate>} $analysis
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
