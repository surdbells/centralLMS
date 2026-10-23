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
     * The person at this address, if this organisation has one.
     *
     * Distinct from {@see emailExists()} because an import has to decide
     * whether to skip somebody or update them, and needs the record either
     * way. Matching is case-insensitive: the column is `citext`, and "Ada@"
     * and "ada@" are one person.
     */
    public function findByEmail(string $tenantId, string $email): ?Person;

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
     * The consents somebody has given, and the record of when.
     *
     * Stored as JSON on `users.consents`, which the schema has carried since
     * the identity slice and nothing has ever written. Kept apart from
     * {@see update()} on purpose: consent is the person's own to give, and an
     * administrator saving somebody's department must not be able to tick it
     * on their behalf.
     *
     * @return array<string, bool>
     */
    public function consentsFor(string $tenantId, string $id): array;

    /**
     * @param array<string, bool> $consents
     */
    public function saveConsents(string $tenantId, string $id, array $consents): bool;

    /**
     * Somebody's answers to the tenant's custom profile fields.
     *
     * Stored as JSON on `users.custom_fields`, keyed by each field's key —
     * the same shape as {@see consentsFor()}. Kept apart from {@see update()}
     * because the values are validated against the tenant's definitions, which
     * the people repository has no business knowing about: the action loads the
     * definitions, checks the values and hands the clean map here.
     *
     * @return array<string, string|float|bool>
     */
    public function customFieldsFor(string $tenantId, string $id): array;

    /**
     * @param array<string, string|float|bool> $values a map already validated
     *                                                  against the definitions
     */
    public function saveCustomFields(string $tenantId, string $id, array $values): bool;

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
