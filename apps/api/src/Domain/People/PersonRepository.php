<?php

declare(strict_types=1);

namespace Central\Api\Domain\People;

/**
 * Storage for people records.
 *
 * Every method takes a tenant id and every query uses it. The legacy's
 * `User::getById(int $id)` did not — `SELECT * FROM users WHERE id = ?` with no
 * tenant clause, called from `single.php`, so any id returned any organisation's
 * person record.
 */
interface PersonRepository
{
    /**
     * @return array{items: list<Person>, total: int}
     */
    public function paginate(string $tenantId, PersonFilters $filters, int $page, int $perPage): array;

    public function find(string $tenantId, string $id): ?Person;

    /**
     * Whether the address is already taken in this tenant.
     *
     * Per tenant, not globally: the same person can hold accounts in two
     * organisations, which the unique index allows and this must match.
     */
    public function emailExists(string $tenantId, string $email, ?string $exceptId = null): bool;

    /**
     * Creates a person and assigns their roles in one transaction.
     *
     * Both or neither: an account with no role can sign in and see nothing,
     * which reads as the application being broken rather than as a half-
     * finished write.
     */
    public function create(Person $person, ?string $passwordHash, ?string $actorId): Person;

    public function update(Person $person): Person;

    public function setStatus(string $tenantId, string $id, \Central\Api\Domain\Identity\UserStatus $status): ?Person;

    /**
     * Soft delete.
     *
     * Never hard: enrolments, attempts and certificates all point here, and a
     * certificate is a record of something that happened to a named person.
     */
    public function softDelete(string $tenantId, string $id): bool;

    public function setPasswordHash(string $tenantId, string $id, string $hash): bool;

    /**
     * The stored hash, for verifying somebody's current password.
     *
     * Returned rather than compared here so the hashing algorithm stays in one
     * place. Null for an invited account that has never set one.
     */
    public function passwordHashFor(string $tenantId, string $id): ?string;

    /**
     * Whether making `$managerId` this person's manager would form a cycle.
     *
     * Walks the chain. Two people managing each other is representable in one
     * update each and produces a reporting query that never terminates.
     */
    public function wouldFormManagementCycle(string $tenantId, string $personId, string $managerId): bool;

    /**
     * Headline counts for the people screen.
     *
     * @return array<string, int>
     */
    public function statistics(string $tenantId): array;
}
