<?php

declare(strict_types=1);

namespace Central\Api\Application\Import;

use Central\Api\Domain\Authz\RoleRepository;
use Central\Api\Domain\Authz\SystemRole;
use Central\Api\Domain\CustomField\CustomFieldDefinition;
use Central\Api\Domain\CustomField\CustomFieldDefinitionRepository;
use Central\Api\Domain\CustomField\CustomFieldType;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Lookup\Lookup;
use Central\Api\Domain\Lookup\LookupKind;
use Central\Api\Domain\Lookup\LookupRepository;
use Central\Api\Domain\People\Person;
use Central\Api\Domain\People\PersonInput;
use Central\Api\Domain\People\PersonRepository;
use Ramsey\Uuid\Uuid;

/**
 * Creates the people a workbook names but the organisation does not have yet —
 * shared by the staff placement and the learning-plan importers so both decide
 * and create identically.
 *
 * - **Who can be created** is decided by {@see assess()}: the name cell split
 *   and re-cased by {@see NameCase}, then validated by {@see PersonInput}, the
 *   code the user form and the people CSV import go through. A row the form
 *   would refuse is reported, never forced in.
 * - **What is created** is an `invited` account with no password, as the
 *   people CSV import makes; it becomes usable through the invitation link.
 * - **Never twice.** {@see create()} looks the address up again (case-
 *   insensitively, any status) just before writing, so the same person on
 *   several sheets, in both importers or in a re-run is one account.
 * - **Profile**: staff ID, department / job title / level by name (found by
 *   normalised name, created when missing — reference data grows the way the
 *   placement import grows it), and text custom fields (the definition is
 *   created when missing; a dropdown gains the option).
 *
 * The lookup and field caches can outlive a rolled-back transaction, so a
 * caller whose transaction failed calls {@see forget()}.
 */
final class ImportedPersonCreator
{
    /** @var array<string, LookupKind> */
    private const LOOKUPS = [
        'department' => LookupKind::Department,
        'jobTitle'   => LookupKind::JobTitle,
        'level'      => LookupKind::Level,
    ];

    /** @var array<string, array<string, array<string, string>>> tenant → kind → normalised name → id */
    private array $lookupIndex = [];

    /** @var array<string, array<string, CustomFieldDefinition>> tenant → key → definition */
    private array $definitions = [];

    public function __construct(
        private readonly PersonRepository $people,
        private readonly LookupRepository $lookups,
        private readonly CustomFieldDefinitionRepository $fields,
        private readonly RoleRepository $roles,
    ) {
    }

    // --- deciding (pure) ------------------------------------------------------

    /**
     * Whether a workbook row can become an account, and as whom.
     *
     * @return array{ok: bool, email: string, firstName: string, lastName: string, reason: ?string, message: ?string}
     */
    public static function assess(string $email, ?string $name): array
    {
        $email = mb_strtolower(trim((string) preg_replace('/\s+/u', '', $email)));
        $parts = NameCase::split((string) $name, $email);
        $out   = ['ok' => false, 'email' => $email, ...$parts, 'reason' => null, 'message' => null];

        if ($email === '') {
            return ['reason' => 'missing_email', 'message' => 'No email address on this row.'] + $out;
        }

        if ($parts['firstName'] === '') {
            return ['reason' => 'missing_name', 'message' => 'No name on this row.'] + $out;
        }

        // The very validation the user form applies.
        $violations = [];
        PersonInput::toPerson('00000000-0000-4000-8000-000000000000', null, [
            'email'     => $email,
            'firstName' => $parts['firstName'],
            'lastName'  => $parts['lastName'],
        ], $violations);

        if (isset($violations['email'])) {
            return ['reason' => 'invalid_email', 'message' => $violations['email']] + $out;
        }

        if (isset($violations['lastName'])) {
            return ['reason' => 'incomplete_name', 'message' => 'Only one name is given; a first and a last name are needed.'] + $out;
        }

        if ($violations !== []) {
            return ['reason' => 'invalid', 'message' => (string) reset($violations)] + $out;
        }

        return ['ok' => true] + $out;
    }

    /**
     * Addresses of deleted accounts: creating one again makes a new account
     * (as the user form would), which the preview flags.
     *
     * @return list<string>
     */
    public function deletedEmails(string $tenantId): array
    {
        return $this->people->deletedEmails($tenantId);
    }

    // --- roles ------------------------------------------------------------------

    /**
     * The roles new people get: the one asked for, which must exist in this
     * organisation, or the built-in Learner role — what self-registration and
     * single sign-on give a new account.
     *
     * @return list<string>
     */
    public function roleIds(string $tenantId, ?string $roleId): array
    {
        $roleId = $roleId === null ? '' : trim($roleId);

        if ($roleId === '') {
            $learner = $this->roles->find($tenantId, SystemRole::Learner->value);

            if ($learner === null) {
                throw ValidationFailed::fromArray(['roleId' => 'This organisation has no Learner role; choose a role for the new people.']);
            }

            return [$learner->id];
        }

        foreach ($this->roles->findAllForTenant($tenantId) as $role) {
            if ($role->id === $roleId || $role->slug === $roleId) {
                return [$role->id];
            }
        }

        throw ValidationFailed::fromArray(['roleId' => 'That role does not exist in this organisation.']);
    }

    // --- writing ----------------------------------------------------------------

    /**
     * Creates one person, or returns the one already at the address.
     *
     * @param array{email: string, firstName: string, lastName: string, employeeId?: ?string, lookups?: array<string, ?string>, custom?: array<string, array{label: string, value: ?string}>} $spec
     * @param list<string> $roleIds from {@see roleIds()}
     *
     * @return array{person: Person, created: bool}
     */
    public function create(string $tenantId, array $spec, array $roleIds, ?string $actorId): array
    {
        $email    = mb_strtolower(trim($spec['email']));
        $existing = $this->people->findByEmail($tenantId, $email);

        if ($existing !== null) {
            return ['person' => $existing, 'created' => false];
        }

        $input = [
            'email'     => $email,
            'firstName' => $spec['firstName'],
            'lastName'  => $spec['lastName'],
            'roleIds'   => $roleIds,
        ];

        $employeeId = trim((string) ($spec['employeeId'] ?? ''));

        if ($employeeId !== '') {
            $input['employeeId'] = $employeeId;
        }

        foreach ($spec['lookups'] ?? [] as $field => $name) {
            $kind = self::LOOKUPS[$field] ?? null;
            $name = trim((string) $name);

            if ($kind !== null && $name !== '') {
                $input[$field . 'Id'] = $this->lookupId($tenantId, $kind, $name);
            }
        }

        $violations = [];
        $person     = PersonInput::toPerson($tenantId, null, $input, $violations);

        if ($person === null || $violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        // No password: the holder sets their own through the invitation.
        $created = $this->people->create($person, null, $actorId);
        $custom  = [];

        foreach ($spec['custom'] ?? [] as $key => $field) {
            $value = $this->customValue($tenantId, $key, $field['label'], (string) $field['value']);

            if ($value !== null) {
                $custom[$key] = $value;
            }
        }

        if ($custom !== [] && $created->id !== null) {
            $this->people->saveCustomFields($tenantId, $created->id, $custom);
        }

        return ['person' => $created, 'created' => true];
    }

    /**
     * Drops cached ids — after a rolled-back transaction they may point at
     * reference data that no longer exists.
     */
    public function forget(): void
    {
        $this->lookupIndex = [];
        $this->definitions = [];
    }

    private function lookupId(string $tenantId, LookupKind $kind, string $name): string
    {
        $index = $this->lookupIndex[$tenantId][$kind->value] ??= $this->indexLookups($tenantId, $kind);
        $key   = PlacementImporter::normalise($name);

        if (isset($index[$key])) {
            return $index[$key];
        }

        $found = $this->lookups->findByName($tenantId, $kind, $name)
            ?? $this->lookups->create(Lookup::fromInput(Uuid::uuid4()->toString(), $tenantId, $kind, ['name' => $name]));

        return $this->lookupIndex[$tenantId][$kind->value][$key] = $found->id;
    }

    /**
     * Every entry of a kind by normalised name; an active one wins over an
     * inactive one of the same name, as in the placement import.
     *
     * @return array<string, string>
     */
    private function indexLookups(string $tenantId, LookupKind $kind): array
    {
        $index  = [];
        $active = [];
        $page   = 1;
        $seen   = 0;

        do {
            $result = $this->lookups->paginate($tenantId, $kind, $page, 100, null, 'name', 'asc');

            foreach ($result['items'] as $lookup) {
                ++$seen;
                $key = PlacementImporter::normalise($lookup->name);

                if (!isset($index[$key]) || ($lookup->isActive && !($active[$key] ?? false))) {
                    $index[$key]  = $lookup->id;
                    $active[$key] = $lookup->isActive;
                }
            }

            ++$page;
        } while ($result['items'] !== [] && $seen < $result['total'] && $page <= 1000);

        return $index;
    }

    private function customValue(string $tenantId, string $key, string $label, string $value): string|float|bool|null
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $this->definitions[$tenantId] ??= $this->loadDefinitions($tenantId);
        $definition = $this->definitions[$tenantId][$key] ?? null;

        if ($definition === null) {
            $sort = 0;

            foreach ($this->definitions[$tenantId] as $d) {
                $sort = max($sort, $d->sortOrder + 1);
            }

            $definition = $this->fields->create(CustomFieldDefinition::fromInput(Uuid::uuid4()->toString(), $tenantId, [
                'key'       => $key,
                'label'     => $label,
                'type'      => CustomFieldType::Text->value,
                'options'   => [],
                'sortOrder' => $sort,
            ]));
            $this->definitions[$tenantId][$key] = $definition;
        }

        if ($definition->type->usesOptions()) {
            foreach ($definition->options as $option) {
                if (strcasecmp($option, $value) === 0) {
                    $value = $option;

                    break;
                }
            }

            if (!in_array($value, $definition->options, true)) {
                $definition = $this->fields->update(new CustomFieldDefinition(
                    id: $definition->id,
                    tenantId: $definition->tenantId,
                    key: $definition->key,
                    label: $definition->label,
                    type: $definition->type,
                    options: [...$definition->options, $value],
                    required: $definition->required,
                    sortOrder: $definition->sortOrder,
                    isActive: $definition->isActive,
                ));
                $this->definitions[$tenantId][$key] = $definition;
            }
        }

        try {
            return $definition->type->coerce($value, $definition->options);
        } catch (\InvalidArgumentException) {
            // A value the tenant's field cannot hold (a number field given
            // text) is left unset rather than failing the person.
            return null;
        }
    }

    /**
     * @return array<string, CustomFieldDefinition>
     */
    private function loadDefinitions(string $tenantId): array
    {
        $out = [];

        foreach ($this->fields->allForTenant($tenantId, false) as $definition) {
            $out[strtolower($definition->key)] = $definition;
        }

        return $out;
    }
}
