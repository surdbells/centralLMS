<?php

declare(strict_types=1);

namespace Central\Api\Tests\Doubles;

use Central\Api\Domain\Identity\UserStatus;
use Central\Api\Domain\People\Person;
use Central\Api\Domain\People\PersonFilters;
use Central\Api\Domain\People\PersonRepository;

/** @internal Just enough of the people store for the workbook importers. */
final class InMemoryPersonRepository implements PersonRepository
{
    /** @var array<string, Person> by id */
    public array $people = [];

    /** @var array<string, array<string, string|float|bool>> by id */
    public array $custom = [];

    /** @var list<string> */
    public array $deleted = [];

    public int $creates = 0;

    public function add(Person $person): Person
    {
        $id = $person->id ?? sprintf('00000000-0000-4000-8000-%012d', count($this->people) + 1);
        $this->people[$id] = new Person(
            id: $id,
            tenantId: $person->tenantId,
            email: strtolower($person->email),
            firstName: $person->firstName,
            lastName: $person->lastName,
            status: $person->status,
            departmentId: $person->departmentId,
            levelId: $person->levelId,
            jobTitleId: $person->jobTitleId,
            employeeId: $person->employeeId,
            roleIds: $person->roleIds,
        );

        return $this->people[$id];
    }

    /**
     * @return array<string, array{id: string, name: string, status: string}>
     */
    public function emailIndex(): array
    {
        $out = [];

        foreach ($this->people as $p) {
            $out[$p->email] = ['id' => (string) $p->id, 'name' => trim($p->firstName . ' ' . $p->lastName), 'status' => $p->status->value];
        }

        return $out;
    }

    public function paginate(string $tenantId, PersonFilters $filters, int $page, int $perPage): array
    {
        return ['items' => array_values($this->people), 'total' => count($this->people)];
    }

    public function find(string $tenantId, string $id): ?Person
    {
        return $this->people[$id] ?? null;
    }

    public function emailExists(string $tenantId, string $email, ?string $exceptId = null): bool
    {
        return $this->findByEmail($tenantId, $email) !== null;
    }

    public function findByEmail(string $tenantId, string $email): ?Person
    {
        foreach ($this->people as $p) {
            if (strtolower($p->email) === strtolower(trim($email))) {
                return $p;
            }
        }

        return null;
    }

    public function create(Person $person, ?string $passwordHash, ?string $actorId): Person
    {
        if ($this->findByEmail($person->tenantId, $person->email) !== null) {
            throw new \RuntimeException('duplicate key value violates unique constraint "users_tenant_email_uniq"');
        }

        ++$this->creates;

        return $this->add($person);
    }

    public function update(Person $person): Person
    {
        return $this->people[(string) $person->id] = $person;
    }

    public function setStatus(string $tenantId, string $id, UserStatus $status): ?Person
    {
        return null;
    }

    public function softDelete(string $tenantId, string $id): bool
    {
        return false;
    }

    public function setPasswordHash(string $tenantId, string $id, string $hash): bool
    {
        return false;
    }

    public function consentsFor(string $tenantId, string $id): array
    {
        return [];
    }

    public function saveConsents(string $tenantId, string $id, array $consents): bool
    {
        return true;
    }

    public function customFieldsFor(string $tenantId, string $id): array
    {
        return $this->custom[$id] ?? [];
    }

    public function saveCustomFields(string $tenantId, string $id, array $values): bool
    {
        $this->custom[$id] = $values;

        return true;
    }

    public function placementSnapshot(string $tenantId): array
    {
        $out = [];

        foreach ($this->people as $p) {
            $out[] = [
                'id'           => (string) $p->id,
                'email'        => $p->email,
                'name'         => trim($p->firstName . ' ' . $p->lastName),
                'status'       => $p->status->value,
                'employeeId'   => $p->employeeId,
                'departmentId' => $p->departmentId,
                'jobTitleId'   => $p->jobTitleId,
                'customFields' => $this->custom[(string) $p->id] ?? [],
            ];
        }

        return $out;
    }

    public function deletedEmails(string $tenantId): array
    {
        return $this->deleted;
    }

    public function applyPlacement(string $tenantId, string $userId, array $columns): bool
    {
        $p = $this->people[$userId];
        $this->people[$userId] = new Person(
            id: $p->id,
            tenantId: $p->tenantId,
            email: $p->email,
            firstName: $p->firstName,
            lastName: $p->lastName,
            status: $p->status,
            departmentId: $columns['department_id'] ?? $p->departmentId,
            levelId: $p->levelId,
            jobTitleId: $columns['job_title_id'] ?? $p->jobTitleId,
            employeeId: $columns['employee_id'] ?? $p->employeeId,
            roleIds: $p->roleIds,
        );

        return true;
    }

    public function passwordHashFor(string $tenantId, string $id): ?string
    {
        return null;
    }

    public function wouldFormManagementCycle(string $tenantId, string $personId, string $managerId): bool
    {
        return false;
    }

    public function statistics(string $tenantId): array
    {
        return [];
    }
}
