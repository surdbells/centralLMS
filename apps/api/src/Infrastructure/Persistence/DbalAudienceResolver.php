<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Audience\AudienceResolver;
use Central\Api\Domain\Audience\AudienceTarget;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class DbalAudienceResolver implements AudienceResolver
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function resolve(string $tenantId, AudienceTarget $target, array $criteria): array
    {
        $query = $this->query($tenantId, $target, $criteria);

        if ($query === null) {
            return [];
        }

        [$where, $params, $types] = $query;

        $rows = $this->connection->fetchFirstColumn(
            sprintf('SELECT u.id FROM users u WHERE %s', $where),
            $params,
            $types,
        );

        return array_map(static fn (mixed $id): string => (string) $id, $rows);
    }

    public function count(string $tenantId, AudienceTarget $target, array $criteria): int
    {
        $query = $this->query($tenantId, $target, $criteria);

        if ($query === null) {
            return 0;
        }

        [$where, $params, $types] = $query;

        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM users u WHERE %s', $where),
            $params,
            $types,
        );
    }

    /**
     * The WHERE that describes this audience, or null when it describes nobody.
     *
     * Null rather than a clause that matches nothing, so the caller does not
     * run a query to learn what the criteria already said.
     *
     * @param array<string, mixed> $criteria
     *
     * @return array{string, array<string, mixed>, array<string, mixed>}|null
     */
    private function query(string $tenantId, AudienceTarget $target, array $criteria): ?array
    {
        // Active users only: a rule that keeps enrolling people who have left
        // creates obligations nobody can discharge, and an announcement to a
        // leaver is an outstanding acknowledgement nobody can give.
        $where = ['u.tenant_id = :tenant', "u.status = 'active'", 'u.deleted_at IS NULL'];
        $params = ['tenant' => $tenantId];
        $types = [];

        $ids = $this->ids($criteria, $target->criteriaKeys());

        if ($target->needsCriteria() && $ids === []) {
            // Nobody was named, which is not the same as everybody — and an
            // empty `IN ()` is a syntax error besides.
            return null;
        }

        switch ($target) {
            case AudienceTarget::All:
                break;

            case AudienceTarget::Role:
                $where[] = 'EXISTS (
                    SELECT 1 FROM user_roles ur
                    WHERE ur.user_id = u.id AND ur.role_id IN (:ids)
                )';
                break;

            case AudienceTarget::Department:
                $where[] = 'u.department_id IN (:ids)';
                break;

            case AudienceTarget::Branch:
                $where[] = 'u.branch_id IN (:ids)';
                break;

            case AudienceTarget::Team:
                $where[] = 'u.team_id IN (:ids)';
                break;

            case AudienceTarget::Course:
                // Everybody enrolled on it, whatever their progress: something
                // addressed to a course is for the people taking it.
                $where[] = 'EXISTS (
                    SELECT 1 FROM enrollments e
                    WHERE e.user_id = u.id AND e.course_id IN (:ids) AND e.deleted_at IS NULL
                )';
                break;

            case AudienceTarget::Path:
                $where[] = 'EXISTS (
                    SELECT 1 FROM learning_path_enrollments pe
                    WHERE pe.user_id = u.id AND pe.path_id IN (:ids) AND pe.deleted_at IS NULL
                )';
                break;

            case AudienceTarget::Custom:
                $where[] = 'u.id IN (:ids)';
                break;
        }

        if ($ids !== []) {
            $params['ids'] = $ids;
            $types['ids'] = ArrayParameterType::STRING;
        }

        return [implode(' AND ', $where), $params, $types];
    }

    /**
     * The ids out of the criteria, under whichever key the client used.
     *
     * @param array<string, mixed> $criteria
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function ids(array $criteria, array $keys): array
    {
        foreach ($keys as $key) {
            $value = $criteria[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return [$value];
            }

            if (is_array($value) && $value !== []) {
                return array_values(array_filter(
                    array_map(static fn (mixed $v): string => (string) $v, $value),
                    static fn (string $v): bool => $v !== '',
                ));
            }
        }

        return [];
    }
}
