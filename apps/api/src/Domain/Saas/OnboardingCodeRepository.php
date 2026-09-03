<?php

declare(strict_types=1);

namespace Central\Api\Domain\Saas;

interface OnboardingCodeRepository
{
    /**
     * Every code, newest first — the operator's list.
     *
     * @return list<OnboardingCode>
     */
    public function all(): array;

    public function find(string $id): ?OnboardingCode;

    /** By its code string, case-insensitively; how sign-up looks one up. */
    public function findByCode(string $code): ?OnboardingCode;

    public function create(OnboardingCode $code, ?string $createdBy): OnboardingCode;

    public function update(string $id, OnboardingCode $code): ?OnboardingCode;

    public function softDelete(string $id): bool;

    /**
     * Spends one use, atomically, refusing when the code is gone or exhausted.
     *
     * A single conditional UPDATE so two sign-ups racing on the last use cannot
     * both win — the database, not the application, decides who gets it.
     * Returns true when a use was taken. Sign-up spends inside the same
     * transaction as the provision it gates, so a rollback hands the use back;
     * there is deliberately no separate refund.
     */
    public function spendUse(string $id): bool;
}
