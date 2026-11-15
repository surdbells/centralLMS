<?php

declare(strict_types=1);

namespace Central\Api\Application\Import;

use Central\Api\Domain\CustomField\CustomFieldDefinition;
use Central\Api\Domain\CustomField\CustomFieldDefinitionRepository;
use Central\Api\Domain\CustomField\CustomFieldType;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Lookup\Lookup;
use Central\Api\Domain\Lookup\LookupKind;
use Central\Api\Domain\Lookup\LookupRepository;
use Central\Api\Domain\People\PersonRepository;
use Ramsey\Uuid\Uuid;

/**
 * Applies a parsed placement sheet ({@see PlacementWorkbook}) to the people of
 * one organisation.
 *
 * What it may change, and nothing else: staff ID, department, job title, and
 * four custom profile fields — division, job group, salary group and job
 * category. Names are shown, never written.
 *
 * **Nobody is created unless asked.** With `createMissing`, a row whose address
 * has no account becomes an `invited` account first ({@see ImportedPersonCreator},
 * shared with the learning-plan import) and is then given its placement by the
 * very same update path as everybody else.
 *
 * **A blank cell never clears anything.** The sheet is a correction feed, not
 * the system of record; a column somebody left empty means "no news".
 *
 * **Grade bands are not Level.** Level already holds the grade ladder (Senior
 * Officer, Manager…) that the people CSV fills; writing "JG 13" into it would
 * destroy that. Job group, salary group and job category go to dropdown custom
 * fields instead, whose options grow as new bands appear.
 *
 * {@see plan()} is pure — every comparison is made there, against snapshots
 * handed in — so the preview and the commit cannot disagree, and the rules are
 * testable without a database.
 */
final class PlacementImporter
{
    /** @var array<string, array{kind: LookupKind, column: string, current: string, label: string}> */
    private const LOOKUP_FIELDS = [
        'department' => ['kind' => LookupKind::Department, 'column' => 'department_id', 'current' => 'departmentId', 'label' => 'Department'],
        'jobTitle'   => ['kind' => LookupKind::JobTitle, 'column' => 'job_title_id', 'current' => 'jobTitleId', 'label' => 'Job title'],
    ];

    /** @var array<string, array{key: string, label: string, changeLabel: string, type: CustomFieldType}> */
    private const CUSTOM_FIELDS = [
        'division'    => ['key' => 'division', 'label' => 'Division', 'changeLabel' => 'Function / Division', 'type' => CustomFieldType::Text],
        'jobGroup'    => ['key' => 'job_group', 'label' => 'Job group', 'changeLabel' => 'Job group', 'type' => CustomFieldType::Dropdown],
        'salaryGroup' => ['key' => 'salary_group', 'label' => 'Salary group', 'changeLabel' => 'Salary group', 'type' => CustomFieldType::Dropdown],
        'jobCategory' => ['key' => 'job_category', 'label' => 'Job category', 'changeLabel' => 'Job category', 'type' => CustomFieldType::Dropdown],
    ];

    /** Statuses that are "here": anybody else is still matched, with a warning. */
    private const PRESENT = ['active', 'invited'];

    public function __construct(
        private readonly PersonRepository $people,
        private readonly LookupRepository $lookups,
        private readonly CustomFieldDefinitionRepository $fields,
        private readonly ?ImportedPersonCreator $creator = null,
    ) {
    }

    /**
     * What a commit would do. Writes nothing.
     *
     * @param array<string, mixed> $parsed from {@see PlacementWorkbook::parse()}
     *
     * @return array<string, mixed>
     */
    public function preview(string $tenantId, array $parsed, bool $createMissing = false): array
    {
        $snapshot   = $this->people->placementSnapshot($tenantId);
        $candidates = self::candidates($parsed['rows'], $snapshot, $this->people->deletedEmails($tenantId));
        $plan       = $this->planFor($tenantId, $parsed, $snapshot, $createMissing ? self::emailSet($candidates['toCreate']) : []);

        return [
            'sheet'                => $parsed['sheet'],
            'sheets'               => $parsed['sheets'],
            'columns'              => $parsed['columns'],
            'ignoredColumns'       => [...$parsed['ignored'], ...$parsed['unmapped']],
            'truncated'            => $parsed['truncated'],
            'summary'              => $plan['summary'],
            'lookupsToCreate'      => $plan['lookupsToCreate'],
            'customFieldsToCreate' => $plan['customFieldsToCreate'],
            'optionsToAdd'         => $plan['optionsToAdd'],
            'rows'                 => array_map(self::publicRow(...), $plan['rows']),
            'createMissing'        => $createMissing,
            'toCreate'             => $candidates['toCreate'],
            'cannotCreate'         => $candidates['cannotCreate'],
            'totals'               => [
                'toCreate'     => count($candidates['toCreate']),
                'cannotCreate' => count($candidates['cannotCreate']),
            ],
        ];
    }

    /**
     * Recomputes the plan from nothing and writes it.
     *
     * Reference data and custom fields are created once, before any row; then
     * each row is applied on its own, so one failure does not abandon the rest.
     *
     * @param array<string, mixed> $parsed
     *
     * @return array<string, mixed>
     */
    public function commit(
        string $tenantId,
        array $parsed,
        bool $createMissing = false,
        ?string $roleId = null,
        ?string $actorId = null,
    ): array {
        // --- missing people -------------------------------------------------
        // Created first, bare (name, email, role); the plan below is then
        // computed against a snapshot that includes them, so their placement
        // is written by exactly the update path everybody else goes through.
        $createdIds     = [];
        $createdPeople  = [];
        $createFailures = [];
        $candidates     = ['toCreate' => [], 'cannotCreate' => []];

        if ($createMissing) {
            if ($this->creator === null) {
                throw new \LogicException('Creating people needs an ImportedPersonCreator.');
            }

            // Before any write, so an unknown role refuses the whole commit.
            $roleIds    = $this->creator->roleIds($tenantId, $roleId);
            $candidates = self::candidates(
                $parsed['rows'],
                $this->people->placementSnapshot($tenantId),
                $this->people->deletedEmails($tenantId),
            );

            foreach ($candidates['toCreate'] as $candidate) {
                try {
                    $made = $this->creator->create($tenantId, [
                        'email'     => $candidate['email'],
                        'firstName' => $candidate['firstName'],
                        'lastName'  => $candidate['lastName'],
                    ], $roleIds, $actorId);

                    if ($made['created'] && $made['person']->id !== null) {
                        $createdIds[$candidate['email']] = $made['person']->id;
                        $createdPeople[] = ['row' => $candidate['row'], 'email' => $candidate['email'], 'userId' => $made['person']->id];
                    }
                } catch (\Throwable $e) {
                    $createFailures[$candidate['email']] = 'Could not create this person: ' . self::message($e);
                }
            }
        }

        $plan = $this->planFor($tenantId, $parsed, $this->people->placementSnapshot($tenantId));

        // --- reference data -------------------------------------------------
        $created        = ['department' => 0, 'job_title' => 0];
        $lookupIds      = [];
        $lookupFailures = [];

        foreach (self::LOOKUP_FIELDS as $config) {
            $kind = $config['kind'];

            foreach ($plan['pendingLookups'][$kind->value] ?? [] as $key => $name) {
                try {
                    $existing = $this->lookups->findByName($tenantId, $kind, $name);

                    if ($existing === null) {
                        $existing = $this->lookups->create(
                            Lookup::fromInput(Uuid::uuid4()->toString(), $tenantId, $kind, ['name' => $name]),
                        );
                        ++$created[$kind->value];
                    }

                    $lookupIds[$kind->value][$key] = $existing->id;
                } catch (\Throwable $e) {
                    $lookupFailures[$kind->value][$key] = sprintf(
                        'Could not create the %s "%s": %s',
                        strtolower($kind->label()),
                        $name,
                        self::message($e),
                    );
                }
            }
        }

        // --- custom fields --------------------------------------------------
        $fieldsCreated = [];
        $optionsAdded  = [];
        $fieldFailures = [];
        $definitions   = $this->fields->allForTenant($tenantId, false);
        $nextSort      = 0;

        foreach ($definitions as $definition) {
            $nextSort = max($nextSort, $definition->sortOrder + 1);
        }

        foreach ($plan['customFieldsToCreate'] as $spec) {
            $key = $spec['key'];

            try {
                $definition = CustomFieldDefinition::fromInput(Uuid::uuid4()->toString(), $tenantId, [
                    'key'       => $key,
                    'label'     => $spec['label'],
                    'type'      => $spec['type'],
                    'options'   => $plan['optionsToAdd'][$key] ?? [],
                    'sortOrder' => $nextSort++,
                ]);

                if ($this->fields->keyExists($tenantId, $key)) {
                    throw new \RuntimeException('A field with this key already exists.');
                }

                $this->fields->create($definition);
                $fieldsCreated[] = $key;

                if ($definition->type->usesOptions()) {
                    $optionsAdded[$key] = $definition->options;
                }
            } catch (\Throwable $e) {
                $fieldFailures[$key] = sprintf('Could not create the profile field "%s": %s', $spec['label'], self::message($e));
            }
        }

        foreach ($plan['optionsToAdd'] as $key => $values) {
            if ($values === [] || in_array($key, $fieldsCreated, true) || isset($fieldFailures[$key])) {
                continue;
            }

            $definition = self::definitionByKey($definitions, $key);

            if ($definition === null) {
                continue;
            }

            try {
                $this->fields->update(new CustomFieldDefinition(
                    id: $definition->id,
                    tenantId: $definition->tenantId,
                    key: $definition->key,
                    label: $definition->label,
                    type: $definition->type,
                    options: array_values(array_unique([...$definition->options, ...$values])),
                    required: $definition->required,
                    sortOrder: $definition->sortOrder,
                    isActive: $definition->isActive,
                ));
                $optionsAdded[$key] = $values;
            } catch (\Throwable $e) {
                $fieldFailures[$key] = sprintf('Could not add options to "%s": %s', $definition->label, self::message($e));
            }
        }

        // --- people ---------------------------------------------------------
        $counts   = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'unmatched' => 0, 'failed' => 0];
        $outcomes = [];

        foreach ($plan['rows'] as $row) {
            $isNew    = $row['error'] === null && isset($createdIds[$row['email']]);
            $reported = ['row' => $row['row'], 'email' => $row['email'], 'name' => $row['name'], 'userId' => $row['userId'], 'created' => $isNew];

            if ($isNew && $row['action'] !== 'update') {
                // Created, with nothing on the row to place them by.
                ++$counts['created'];
                $outcomes[] = [...$reported, 'outcome' => 'created', 'reason' => null, 'changes' => []];

                continue;
            }

            if ($row['action'] === 'unmatched' && isset($createFailures[$row['email']])) {
                ++$counts['failed'];
                $outcomes[] = [...$reported, 'outcome' => 'error', 'reason' => $createFailures[$row['email']], 'changes' => []];

                continue;
            }

            if ($row['action'] !== 'update') {
                $outcome = $row['action'] === 'error' ? 'error' : $row['action'];
                $counts[$outcome === 'error' ? 'failed' : $outcome]++;
                $outcomes[] = [...$reported, 'outcome' => $outcome, 'reason' => $row['error'], 'changes' => []];

                continue;
            }

            $columns  = [];
            $problems = [];

            foreach ($row['apply']['columns'] as $column => $value) {
                if (is_array($value)) {
                    $id = $lookupIds[$value['kind']][$value['key']] ?? null;

                    if ($id === null) {
                        $problems[] = $lookupFailures[$value['kind']][$value['key']] ?? 'The new entry could not be created.';

                        continue;
                    }

                    $value = $id;
                }

                $columns[$column] = $value;
            }

            $custom = [];

            foreach ($row['apply']['custom'] as $key => $value) {
                if (isset($fieldFailures[$key])) {
                    $problems[] = $fieldFailures[$key];

                    continue;
                }

                $custom[$key] = $value;
            }

            if ($columns === [] && $custom === []) {
                ++$counts[$isNew ? 'created' : 'failed'];
                $outcomes[] = [...$reported, 'outcome' => $isNew ? 'created' : 'error', 'reason' => implode(' ', $problems) ?: 'Nothing could be applied.', 'changes' => []];

                continue;
            }

            try {
                if ($columns !== []) {
                    $this->people->applyPlacement($tenantId, (string) $row['userId'], $columns);
                }

                if ($custom !== []) {
                    // Merged into what is stored now, not what the plan read:
                    // the map is written whole, and other fields must survive.
                    $values = [...$this->people->customFieldsFor($tenantId, (string) $row['userId']), ...$custom];
                    $this->people->saveCustomFields($tenantId, (string) $row['userId'], $values);
                }

                ++$counts[$isNew ? 'created' : 'updated'];
                $outcomes[] = [
                    ...$reported,
                    'outcome' => $isNew ? 'created' : 'updated',
                    // Partly applied is still applied; what was held back says why.
                    'reason'  => $problems === [] ? null : implode(' ', $problems),
                    'changes' => $row['changes'],
                ];
            } catch (\Throwable $e) {
                // A new person whose placement failed still exists; say so.
                ++$counts[$isNew ? 'created' : 'failed'];
                $outcomes[] = [...$reported, 'outcome' => $isNew ? 'created' : 'error', 'reason' => self::message($e), 'changes' => []];
            }
        }

        return [
            ...$counts,
            'lookupsCreated'      => $created,
            'customFieldsCreated' => $fieldsCreated,
            'optionsAdded'        => (object) $optionsAdded,
            'rows'                => $outcomes,
            'createMissing'       => $createMissing,
            'createdPeople'       => $createdPeople,
            'cannotCreate'        => $candidates['cannotCreate'],
        ];
    }

    /**
     * Which unmatched rows could become accounts, and which could not and why.
     *
     * A row is a candidate when its address has no live account here. Rows the
     * sheet already rejected as a repeat of an earlier address are left out —
     * the earlier row speaks for that person.
     *
     * @param list<array{row: int, email: string, name: string, values: array<string, string>, error: ?string}> $rows
     * @param list<array{id: string, email: string, name: string, status: string, employeeId: ?string, departmentId: ?string, jobTitleId: ?string, customFields: array<string, string|float|bool>}> $people
     * @param list<string> $deletedEmails lower-cased addresses of deleted accounts
     *
     * @return array{
     *     toCreate: list<array{row: int, email: string, firstName: string, lastName: string, jobTitle: ?string, department: ?string, employeeId: ?string, previouslyDeleted: bool}>,
     *     cannotCreate: list<array{row: int, email: string, name: string, reason: string, message: string}>
     * }
     */
    public static function candidates(array $rows, array $people, array $deletedEmails = []): array
    {
        $known = [];

        foreach ($people as $person) {
            $known[strtolower(trim($person['email']))] = true;
        }

        $deleted  = array_flip($deletedEmails);
        $toCreate = [];
        $cannot   = [];
        $seen     = [];

        foreach ($rows as $row) {
            if ($row['error'] !== null && $row['email'] !== '') {
                continue;
            }

            if ($row['email'] !== '' && (isset($known[$row['email']]) || isset($seen[$row['email']]))) {
                continue;
            }

            $verdict = ImportedPersonCreator::assess($row['email'], $row['name']);

            if (!$verdict['ok']) {
                $cannot[] = [
                    'row'     => $row['row'],
                    'email'   => $row['email'],
                    'name'    => $row['name'],
                    'reason'  => (string) $verdict['reason'],
                    'message' => (string) $verdict['message'],
                ];

                continue;
            }

            $seen[$verdict['email']] = true;
            $toCreate[] = [
                'row'               => $row['row'],
                'email'             => $verdict['email'],
                'firstName'         => $verdict['firstName'],
                'lastName'          => $verdict['lastName'],
                'jobTitle'          => $row['values']['jobTitle'] ?? null,
                'department'        => $row['values']['department'] ?? null,
                'employeeId'        => $row['values']['employeeId'] ?? null,
                'previouslyDeleted' => isset($deleted[$verdict['email']]),
            ];
        }

        return ['toCreate' => $toCreate, 'cannotCreate' => $cannot];
    }

    /**
     * @param list<array{email: string}> $people
     *
     * @return array<string, true>
     */
    private static function emailSet(array $people): array
    {
        $set = [];

        foreach ($people as $person) {
            $set[$person['email']] = true;
        }

        return $set;
    }

    /**
     * @param array<string, mixed> $parsed
     * @param list<array{id: string, email: string, name: string, status: string, employeeId: ?string, departmentId: ?string, jobTitleId: ?string, customFields: array<string, string|float|bool>}> $snapshot
     * @param array<string, true> $toCreate addresses previewed as new people
     *
     * @return array<string, mixed>
     */
    private function planFor(string $tenantId, array $parsed, array $snapshot, array $toCreate = []): array
    {
        $lookups = [];

        foreach (self::LOOKUP_FIELDS as $config) {
            $lookups[$config['kind']->value] = $this->allLookups($tenantId, $config['kind']);
        }

        return self::plan(
            $parsed['rows'],
            $snapshot,
            $lookups,
            $this->fields->allForTenant($tenantId, false),
            $toCreate,
        );
    }

    /**
     * Every entry of a kind, active or not — an inactive department called
     * "Finance" is reused, not duplicated.
     *
     * @return list<array{id: string, name: string, isActive: bool}>
     */
    private function allLookups(string $tenantId, LookupKind $kind): array
    {
        $out  = [];
        $page = 1;

        do {
            $result = $this->lookups->paginate($tenantId, $kind, $page, 100, null, 'name', 'asc');

            foreach ($result['items'] as $lookup) {
                $out[] = ['id' => $lookup->id, 'name' => $lookup->name, 'isActive' => $lookup->isActive];
            }

            ++$page;
        } while ($result['items'] !== [] && count($out) < $result['total'] && $page <= 1000);

        return $out;
    }

    // --- the pure part ------------------------------------------------------

    /**
     * Decides, row by row, what would change.
     *
     * @param list<array{row: int, email: string, name: string, values: array<string, string>, error: ?string}> $rows
     * @param list<array{id: string, email: string, name: string, status: string, employeeId: ?string, departmentId: ?string, jobTitleId: ?string, customFields: array<string, string|float|bool>}> $people
     * @param array<string, list<array{id: string, name: string, isActive: bool}>> $lookups by kind value
     * @param list<CustomFieldDefinition> $definitions
     * @param array<string, true> $toCreate addresses that will be created: their rows are
     *                                      planned against a blank profile, as action `create`
     *
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     summary: array{create: int, update: int, unchanged: int, unmatched: int, error: int, warnings: int},
     *     lookupsToCreate: array{department: list<string>, job_title: list<string>},
     *     pendingLookups: array<string, array<string, string>>,
     *     customFieldsToCreate: list<array{key: string, label: string, type: string}>,
     *     optionsToAdd: array<string, list<string>>
     * }
     */
    public static function plan(array $rows, array $people, array $lookups, array $definitions, array $toCreate = []): array
    {
        $byEmail = [];
        $owners  = [];

        foreach ($people as $person) {
            $byEmail[strtolower(trim($person['email']))] = $person;

            if ($person['employeeId'] !== null && trim($person['employeeId']) !== '') {
                $owners[strtolower(trim($person['employeeId']))][] = $person['id'];
            }
        }

        $index = [];
        $names = [];

        foreach ($lookups as $kind => $entries) {
            foreach ($entries as $entry) {
                $names[$kind][$entry['id']] = $entry['name'];
                $key = self::normalise($entry['name']);
                $held = $index[$kind][$key] ?? null;

                // Two entries that normalise alike: an active one beats an
                // inactive one, and otherwise the first one listed stays.
                if ($held === null || (!$held['isActive'] && $entry['isActive'])) {
                    $index[$kind][$key] = $entry;
                }
            }
        }

        $defs = [];

        foreach ($definitions as $definition) {
            $defs[strtolower($definition->key)] = $definition;
        }

        $pendingLookups = [];
        $fieldsToCreate = [];
        $optionsToAdd   = [];
        $staffSeen      = [];
        $planned        = [];
        $summary        = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'unmatched' => 0, 'error' => 0, 'warnings' => 0];

        foreach ($rows as $row) {
            $person = $row['error'] === null ? ($byEmail[$row['email']] ?? null) : null;
            $entry  = [
                'row'      => $row['row'],
                'email'    => $row['email'],
                'name'     => $row['name'] !== '' ? $row['name'] : (string) ($person['name'] ?? ''),
                'userId'   => $person['id'] ?? null,
                'action'   => 'unchanged',
                'changes'  => [],
                'warnings' => [],
                'error'    => $row['error'],
                'apply'    => ['columns' => [], 'custom' => []],
            ];

            if ($row['error'] !== null) {
                $entry['action'] = 'error';
                ++$summary['error'];
                $planned[] = $entry;

                continue;
            }

            $staffId = $row['values']['employeeId'] ?? null;

            if ($staffId !== null) {
                $staffKey = strtolower($staffId);

                if (isset($staffSeen[$staffKey])) {
                    $entry['warnings'][] = sprintf('Staff ID %s is also on row %d of the sheet.', $staffId, $staffSeen[$staffKey]);
                } else {
                    $staffSeen[$staffKey] = $row['row'];
                }
            }

            $creating = false;

            if ($person === null && isset($toCreate[$row['email']])) {
                // A person the commit will create: planned against a blank
                // profile so the preview shows what they will be given.
                $creating = true;
                $person   = [
                    'id'           => '',
                    'email'        => $row['email'],
                    'name'         => $row['name'],
                    'status'       => 'invited',
                    'employeeId'   => null,
                    'departmentId' => null,
                    'jobTitleId'   => null,
                    'customFields' => [],
                ];
            }

            if ($person === null) {
                $entry['action'] = 'unmatched';
                ++$summary['unmatched'];
                $planned[] = $entry;

                continue;
            }

            if (!in_array($person['status'], self::PRESENT, true)) {
                $entry['warnings'][] = sprintf('This account is %s; it is updated anyway.', $person['status']);
            }

            // Staff ID.
            if ($staffId !== null) {
                $others = array_values(array_filter(
                    $owners[strtolower($staffId)] ?? [],
                    static fn (string $id): bool => $id !== $person['id'],
                ));

                if ($others !== []) {
                    $entry['warnings'][] = sprintf('Staff ID %s is already held by another person; it is applied anyway.', $staffId);
                }

                if ($staffId !== ($person['employeeId'] === null ? null : trim($person['employeeId']))) {
                    $entry['changes'][] = self::change('employeeId', 'Staff ID', $person['employeeId'], $staffId);
                    $entry['apply']['columns']['employee_id'] = $staffId;
                }
            }

            // Department and job title — reference data.
            foreach (self::LOOKUP_FIELDS as $field => $config) {
                $value = $row['values'][$field] ?? null;

                if ($value === null) {
                    continue;
                }

                $kind = $config['kind']->value;
                $key  = self::normalise($value);

                if ($key === '') {
                    continue;
                }

                $currentId   = $person[$config['current']];
                $currentName = $currentId === null ? null : ($names[$kind][$currentId] ?? null);
                $found       = $index[$kind][$key] ?? null;

                if ($found !== null) {
                    if (!$found['isActive']) {
                        $entry['warnings'][] = sprintf('%s "%s" is inactive; it is reused.', $config['label'], $found['name']);
                    }

                    if ($currentId !== $found['id']) {
                        $entry['changes'][] = self::change($field, $config['label'], $currentName, $found['name']);
                        $entry['apply']['columns'][$config['column']] = $found['id'];
                    }

                    continue;
                }

                // The first spelling seen names it: "Commercial and …" and
                // "Commercial & …" become one department, not two.
                $pendingLookups[$kind][$key] ??= $value;
                $entry['changes'][] = self::change($field, $config['label'], $currentName, $pendingLookups[$kind][$key]);
                $entry['apply']['columns'][$config['column']] = ['kind' => $kind, 'key' => $key];
            }

            // Division and the grade bands — custom profile fields.
            foreach (self::CUSTOM_FIELDS as $field => $config) {
                $value = $row['values'][$field] ?? null;

                if ($value === null) {
                    continue;
                }

                $key        = $config['key'];
                $definition = $defs[$key] ?? null;
                $type       = $definition !== null ? $definition->type : $config['type'];
                $options    = $definition !== null ? $definition->options : [];

                if ($definition === null) {
                    $fieldsToCreate[$key] ??= ['key' => $key, 'label' => $config['label'], 'type' => $config['type']->value];
                }

                if ($type->usesOptions()) {
                    // "Midsun" picks up an existing "MidSun" rather than
                    // adding a second option that differs only in case.
                    foreach ([...$options, ...($optionsToAdd[$key] ?? [])] as $option) {
                        if (strcasecmp($option, $value) === 0) {
                            $value = $option;

                            break;
                        }
                    }

                    if (!in_array($value, $options, true) && !in_array($value, $optionsToAdd[$key] ?? [], true)) {
                        $optionsToAdd[$key][] = $value;
                    }

                    $options = [...$options, ...($optionsToAdd[$key] ?? [])];
                }

                try {
                    $coerced = $type->coerce($value, $options);
                } catch (\InvalidArgumentException $e) {
                    $entry['warnings'][] = sprintf('%s "%s": %s Left unchanged.', $config['changeLabel'], $value, $e->getMessage());

                    continue;
                }

                if ($coerced === null) {
                    continue;
                }

                $old = array_key_exists($key, $person['customFields']) ? self::display($person['customFields'][$key]) : null;
                $new = self::display($coerced);

                if ($old !== $new) {
                    $entry['changes'][] = self::change($field, $config['changeLabel'], $old, $new);
                    $entry['apply']['custom'][$key] = $coerced;
                }
            }

            $entry['action'] = $creating ? 'create' : ($entry['changes'] === [] ? 'unchanged' : 'update');
            ++$summary[$entry['action']];

            if ($entry['warnings'] !== []) {
                ++$summary['warnings'];
            }

            $planned[] = $entry;
        }

        foreach ($optionsToAdd as $key => $values) {
            $optionsToAdd[$key] = self::naturalSort($values);
        }

        // The three bands are always listed, so a client can render them
        // without checking which keys exist.
        $optionsToAdd += ['job_group' => [], 'salary_group' => [], 'job_category' => []];

        $lookupsToCreate = ['department' => [], 'job_title' => []];

        foreach ($pendingLookups as $kind => $byKey) {
            $lookupsToCreate[$kind] = array_values($byKey);
        }

        return [
            'rows'                 => $planned,
            'summary'              => $summary,
            'lookupsToCreate'      => $lookupsToCreate,
            'pendingLookups'       => $pendingLookups,
            'customFieldsToCreate' => array_values($fieldsToCreate),
            'optionsToAdd'         => $optionsToAdd,
        ];
    }

    /**
     * The key two names are the same department by: case, punctuation and
     * spacing ignored, "&" read as "and".
     */
    public static function normalise(string $name): string
    {
        $name = str_replace('&', ' and ', mb_strtolower($name));

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $name);
    }

    /**
     * Band names in the order a person would list them: anything with a number
     * in natural order ("JG 2" before "JG 10"), then the rest ("EM-B") after.
     *
     * @param list<string> $values
     *
     * @return list<string>
     */
    public static function naturalSort(array $values): array
    {
        usort($values, static function (string $a, string $b): int {
            $aNum = preg_match('/\d/', $a) === 1;
            $bNum = preg_match('/\d/', $b) === 1;

            if ($aNum !== $bNum) {
                return $aNum ? -1 : 1;
            }

            return strnatcasecmp($a, $b);
        });

        return $values;
    }

    /**
     * @return array{field: string, label: string, old: ?string, new: string}
     */
    private static function change(string $field, string $label, ?string $old, string $new): array
    {
        return ['field' => $field, 'label' => $label, 'old' => $old, 'new' => $new];
    }

    private static function display(string|float|bool $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'Yes' : 'No',
            is_float($value) => floor($value) === $value ? number_format($value, 0, '.', '') : (string) $value,
            default => $value,
        };
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function publicRow(array $row): array
    {
        unset($row['apply']);

        return $row;
    }

    /**
     * @param list<CustomFieldDefinition> $definitions
     */
    private static function definitionByKey(array $definitions, string $key): ?CustomFieldDefinition
    {
        foreach ($definitions as $definition) {
            if (strtolower($definition->key) === $key) {
                return $definition;
            }
        }

        return null;
    }

    private static function message(\Throwable $e): string
    {
        if ($e instanceof ValidationFailed) {
            foreach ($e->details()['violations'] as $messages) {
                foreach ((array) $messages as $message) {
                    return (string) $message;
                }
            }
        }

        return $e->getMessage();
    }
}
