<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Saas\OnboardingCode;
use Central\Api\Domain\Saas\OnboardingCodeRepository;
use Doctrine\DBAL\Connection;

final class DbalOnboardingCodeRepository implements OnboardingCodeRepository
{
    private const COLUMNS = 'id, code, description, max_uses, used_count, allowed_plan_ids,
        valid_from, valid_until, is_active, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function all(): array
    {
        return $this->hydrateAll($this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM onboarding_codes
             WHERE deleted_at IS NULL ORDER BY created_at DESC',
        ));
    }

    public function find(string $id): ?OnboardingCode
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM onboarding_codes WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByCode(string $code): ?OnboardingCode
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM onboarding_codes
             WHERE upper(code) = upper(:code) AND deleted_at IS NULL',
            ['code' => $code],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(OnboardingCode $code, ?string $createdBy): OnboardingCode
    {
        $id = (string) $this->connection->fetchOne(
            'INSERT INTO onboarding_codes
                 (code, description, max_uses, used_count, allowed_plan_ids,
                  valid_from, valid_until, is_active, created_by)
             VALUES (:code, :description, :maxUses, :usedCount, :allowedPlanIds::jsonb,
                     :validFrom, :validUntil, :isActive, :createdBy)
             RETURNING id',
            [...$this->params($code), 'createdBy' => $createdBy],
        );

        return $this->find($id) ?? throw new \RuntimeException('The onboarding code vanished immediately after being created.');
    }

    public function update(string $id, OnboardingCode $code): ?OnboardingCode
    {
        // used_count is deliberately NOT in the SET list: it belongs to
        // spendUse/refundUse, which change it atomically. Writing the value read
        // a moment ago would clobber a use spent by a concurrent sign-up in
        // between, undoing the reservation and letting max_uses be exceeded.
        $changed = $this->connection->executeStatement(
            'UPDATE onboarding_codes SET
                 code = :code, description = :description,
                 max_uses = :maxUses,
                 allowed_plan_ids = :allowedPlanIds::jsonb,
                 valid_from = :validFrom, valid_until = :validUntil,
                 is_active = :isActive, updated_at = now()
             WHERE id = :id AND deleted_at IS NULL',
            [...$this->params($code), 'id' => $id],
        );

        return $changed === 0 ? null : $this->find($id);
    }

    public function softDelete(string $id): bool
    {
        return $this->connection->executeStatement(
            'UPDATE onboarding_codes SET deleted_at = now() WHERE id = :id AND deleted_at IS NULL',
            ['id' => $id],
        ) > 0;
    }

    public function spendUse(string $id): bool
    {
        // The condition is the guard: a spent or retired code updates no rows,
        // and two sign-ups on the last use cannot both succeed.
        return $this->connection->executeStatement(
            'UPDATE onboarding_codes
             SET used_count = used_count + 1, updated_at = now()
             WHERE id = :id AND deleted_at IS NULL AND is_active
               AND (max_uses IS NULL OR used_count < max_uses)',
            ['id' => $id],
        ) > 0;
    }

    // --- internals ------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>> $rows
     * @return list<OnboardingCode>
     */
    private function hydrateAll(array $rows): array
    {
        return array_map(fn (array $row): OnboardingCode => $this->hydrate($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): OnboardingCode
    {
        $allowed = $row['allowed_plan_ids'] ?? null;
        if (is_string($allowed)) {
            $decoded = json_decode($allowed, true);
            $allowed = is_array($decoded) ? array_values(array_map('strval', $decoded)) : null;
        }

        return new OnboardingCode(
            id: (string) $row['id'],
            code: (string) $row['code'],
            description: $row['description'] !== null ? (string) $row['description'] : null,
            maxUses: $row['max_uses'] !== null ? (int) $row['max_uses'] : null,
            usedCount: (int) $row['used_count'],
            allowedPlanIds: is_array($allowed) ? $allowed : null,
            validFrom: $this->time($row['valid_from'] ?? null),
            validUntil: $this->time($row['valid_until'] ?? null),
            isActive: (bool) $row['is_active'],
            createdAt: $this->time($row['created_at'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function params(OnboardingCode $code): array
    {
        return [
            'code'        => $code->code,
            'description' => $code->description,
            'maxUses'     => $code->maxUses,
            'usedCount'   => $code->usedCount,
            // Actual SQL NULL when there is no allow-list — not the JSON string
            // "null", which would be a jsonb null and fail the array check.
            'allowedPlanIds' => $code->allowedPlanIds === null
                ? null
                : json_encode(array_values($code->allowedPlanIds), JSON_THROW_ON_ERROR),
            'validFrom'   => $code->validFrom?->format(\DateTimeInterface::ATOM),
            'validUntil'  => $code->validUntil?->format(\DateTimeInterface::ATOM),
            'isActive'    => $code->isActive ? 'true' : 'false',
        ];
    }

    private function time(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
