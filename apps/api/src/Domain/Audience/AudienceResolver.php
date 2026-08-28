<?php

declare(strict_types=1);

namespace Central\Api\Domain\Audience;

/**
 * Turns "everybody in Operations" into a list of people.
 *
 * Two features ask this question — a broadcast deciding who receives it, and a
 * standing path assignment deciding who to enrol — and they must get the same
 * answer. Written twice they would drift: one would count suspended accounts
 * and the other would not, and nobody would notice until a leaver was chased
 * for a course they cannot open.
 *
 * The criteria are a loose map because they come from a JSONB column, and the
 * keys have two spellings in the wild (`departmentIds` and `departments`).
 * {@see DbalAudienceResolver} accepts either rather than making every caller
 * remember which.
 */
interface AudienceResolver
{
    /**
     * Who matches, right now.
     *
     * Active users only: a rule that keeps enrolling people who have left
     * creates obligations nobody can discharge.
     *
     * @param array<string, mixed> $criteria
     *
     * @return list<string> user ids
     */
    public function resolve(string $tenantId, AudienceTarget $target, array $criteria): array;

    /**
     * How many match, without listing them.
     *
     * For screens that show "this rule covers 412 people" beside a rule they
     * have not applied yet.
     *
     * @param array<string, mixed> $criteria
     */
    public function count(string $tenantId, AudienceTarget $target, array $criteria): int;
}
