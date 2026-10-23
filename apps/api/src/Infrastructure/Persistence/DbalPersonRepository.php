<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Identity\UserStatus;
use Central\Api\Domain\People\Person;
use Central\Api\Domain\People\PersonActivity;
use Central\Api\Domain\People\PersonFilters;
use Central\Api\Domain\People\PersonPlacement;
use Central\Api\Domain\People\PersonRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DbalPersonRepository implements PersonRepository
{
    private const COLUMNS = 'u.id, u.tenant_id, u.email, u.first_name, u.last_name, u.status,
                             u.phone, u.avatar_url, u.locale, u.manager_id,
                             u.branch_id, u.department_id, u.team_id, u.level_id, u.job_title_id,
                             u.gender, u.employee_id, u.line_executive_id,
                             u.hire_date, u.probation_end_date, u.two_factor_enabled,
                             u.last_login_at, u.login_count, u.created_at';

    /**
     * Org-unit names, resolved in the same query.
     *
     * The legacy called `getDepartmentName()`, `getTeamName()`, `getLevelName()`,
     * `getBranchName()` and `getJobTitleName()` once per row, inside the loop
     * that built the list — five round trips per person, 125 for a page of 25.
     */
    private const PLACEMENT = "b.name AS branch_name,
                               d.name AS department_name,
                               t.name AS team_name,
                               l.name AS level_name,
                               j.name AS job_title_name,
                               NULLIF(btrim(COALESCE(m.first_name, '') || ' ' || COALESCE(m.last_name, '')), '')
                                   AS manager_name,
                               NULLIF(btrim(COALESCE(x.first_name, '') || ' ' || COALESCE(x.last_name, '')), '')
                                   AS line_executive_name";

    private const PLACEMENT_JOINS = 'LEFT JOIN lookups b ON b.id = u.branch_id
                                     LEFT JOIN lookups d ON d.id = u.department_id
                                     LEFT JOIN lookups t ON t.id = u.team_id
                                     LEFT JOIN lookups l ON l.id = u.level_id
                                     LEFT JOIN lookups j ON j.id = u.job_title_id
                                     LEFT JOIN users m ON m.id = u.manager_id
                                     LEFT JOIN users x ON x.id = u.line_executive_id';

    /**
     * Enrolment counts, as one correlated subquery rather than four.
     */
    private const ACTIVITY = "(
        SELECT to_jsonb(a) FROM (
            SELECT COUNT(*) FILTER (WHERE e.status <> 'dropped')   AS enrolments,
                   COUNT(*) FILTER (WHERE e.status = 'in_progress') AS in_progress,
                   COUNT(*) FILTER (WHERE e.status = 'completed')   AS completed,
                   COUNT(*) FILTER (WHERE e.status NOT IN ('completed', 'dropped')
                                      AND e.due_date IS NOT NULL
                                      AND e.due_date < CURRENT_DATE) AS overdue,
                   COALESCE(SUM(e.time_spent_seconds), 0)          AS seconds_spent,
                   MAX(e.last_activity_at)                          AS last_activity_at
            FROM enrollments e
            WHERE e.user_id = u.id AND e.deleted_at IS NULL
        ) AS a
    ) AS activity";

    public function __construct(private readonly Connection $connection)
    {
    }

    public function paginate(string $tenantId, PersonFilters $filters, int $page, int $perPage): array
    {
        [$where, $params, $types] = $this->conditions($tenantId, $filters);

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM users u WHERE %s', $where),
            $params,
            $types,
        );

        $select = self::COLUMNS
            . ($filters->withPlacement ? ', ' . self::PLACEMENT : '')
            . ($filters->withActivity ? ', ' . self::ACTIVITY : '');

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM users u %s WHERE %s
                 ORDER BY u.first_name, u.last_name, u.id
                 LIMIT :limit OFFSET :offset',
                $select,
                $filters->withPlacement ? self::PLACEMENT_JOINS : '',
                $where,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
            $types,
        );

        $people = array_map(fn (array $row): Person => $this->hydrate($row), $rows);

        return [
            'items' => $this->withRoles($tenantId, $people),
            'total' => $total,
        ];
    }

    public function find(string $tenantId, string $id): ?Person
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s, %s FROM users u %s
                 WHERE u.id = :id AND u.tenant_id = :tenant AND u.deleted_at IS NULL',
                self::COLUMNS,
                self::PLACEMENT,
                self::ACTIVITY,
                self::PLACEMENT_JOINS,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        if ($row === false) {
            return null;
        }

        return $this->withRoles($tenantId, [$this->hydrate($row)])[0];
    }

    public function findByEmail(string $tenantId, string $email): ?Person
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s, %s FROM users u %s
                 WHERE u.email = :email AND u.tenant_id = :tenant AND u.deleted_at IS NULL',
                self::COLUMNS,
                self::PLACEMENT,
                self::ACTIVITY,
                self::PLACEMENT_JOINS,
            ),
            ['email' => $email, 'tenant' => $tenantId],
        );

        if ($row === false) {
            return null;
        }

        return $this->withRoles($tenantId, [$this->hydrate($row)])[0];
    }

    public function emailExists(string $tenantId, string $email, ?string $exceptId = null): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM users
             WHERE tenant_id = :tenant AND email = :email AND deleted_at IS NULL
               AND (:exceptId::uuid IS NULL OR id <> :exceptId::uuid)',
            ['tenant' => $tenantId, 'email' => $email, 'exceptId' => $exceptId],
        ) > 0;
    }

    public function create(Person $person, ?string $passwordHash, ?string $actorId): Person
    {
        $id = $this->connection->transactional(
            function (Connection $tx) use ($person, $passwordHash, $actorId): string {
                $id = (string) $tx->fetchOne(
                    'INSERT INTO users
                        (tenant_id, email, password_hash, first_name, last_name, status,
                         phone, avatar_url, locale, manager_id,
                         branch_id, department_id, team_id, level_id, job_title_id,
                         gender, employee_id, line_executive_id,
                         hire_date, probation_end_date, two_factor_enabled, created_by)
                     VALUES (:tenant, :email, :hash, :first, :last, :status,
                             :phone, :avatar, :locale, :manager,
                             :branch, :department, :team, :level, :jobTitle,
                             :gender, :employeeId, :lineExecutive,
                             :hireDate, :probationEnd, :twoFactor, :createdBy)
                     RETURNING id',
                    $this->writableColumns($person, ['hash' => $passwordHash, 'createdBy' => $actorId]),
                    ['twoFactor' => ParameterType::BOOLEAN],
                );

                $this->replaceRoles($tx, $id, $person->roleIds);

                return $id;
            },
        );

        return $this->find($person->tenantId, $id)
            ?? throw new \RuntimeException('The person vanished immediately after being created.');
    }

    public function update(Person $person): Person
    {
        $id = (string) $person->id;

        $this->connection->transactional(
            function (Connection $tx) use ($person, $id): void {
                $tx->executeStatement(
                    'UPDATE users SET
                        email = :email, first_name = :first, last_name = :last, status = :status,
                        phone = :phone, avatar_url = :avatar, locale = :locale, manager_id = :manager,
                        branch_id = :branch, department_id = :department, team_id = :team,
                        level_id = :level, job_title_id = :jobTitle,
                        gender = :gender, employee_id = :employeeId, line_executive_id = :lineExecutive,
                        hire_date = :hireDate, probation_end_date = :probationEnd,
                        two_factor_enabled = :twoFactor, updated_at = now()
                     WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                    [...$this->writableColumns($person), 'id' => $id],
                    ['twoFactor' => ParameterType::BOOLEAN],
                );

                $this->replaceRoles($tx, $id, $person->roleIds);
            },
        );

        return $this->find($person->tenantId, $id)
            ?? throw new \RuntimeException('The person vanished immediately after being updated.');
    }

    public function setStatus(string $tenantId, string $id, UserStatus $status): ?Person
    {
        $changed = $this->connection->executeStatement(
            'UPDATE users SET status = :status, updated_at = now()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId, 'status' => $status->value],
        );

        return $changed === 0 ? null : $this->find($tenantId, $id);
    }

    public function softDelete(string $tenantId, string $id): bool
    {
        return $this->connection->executeStatement(
            'UPDATE users SET deleted_at = now(), updated_at = now()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    public function consentsFor(string $tenantId, string $id): array
    {
        $raw = $this->connection->fetchOne(
            'SELECT consents FROM users WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        );

        if (!is_string($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        $consents = [];

        foreach ($decoded as $name => $given) {
            // Coerced rather than trusted: the column is free-form JSON, and a
            // consent that reads as the string "false" must not count as given.
            $consents[(string) $name] = filter_var($given, FILTER_VALIDATE_BOOL);
        }

        return $consents;
    }

    public function saveConsents(string $tenantId, string $id, array $consents): bool
    {
        return $this->connection->executeStatement(
            'UPDATE users SET consents = :consents::jsonb, updated_at = now()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            [
                'id'       => $id,
                'tenant'   => $tenantId,
                'consents' => json_encode($consents, JSON_THROW_ON_ERROR),
            ],
        ) > 0;
    }

    public function setPasswordHash(string $tenantId, string $id, string $hash): bool
    {
        // Status moves off `invited` in the same statement: somebody who has
        // set a password is no longer waiting to.
        return $this->connection->executeStatement(
            "UPDATE users
             SET password_hash = :hash,
                 status = CASE WHEN status = 'invited' THEN 'active' ELSE status END,
                 updated_at = now()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL",
            ['id' => $id, 'tenant' => $tenantId, 'hash' => $hash],
        ) > 0;
    }

    public function passwordHashFor(string $tenantId, string $id): ?string
    {
        $hash = $this->connection->fetchOne(
            "SELECT password_hash FROM users
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL",
            ["id" => $id, "tenant" => $tenantId],
        );

        return is_string($hash) && $hash !== "" ? $hash : null;
    }

    public function wouldFormManagementCycle(string $tenantId, string $personId, string $managerId): bool
    {
        if ($personId === $managerId) {
            return true;
        }

        // Walks up from the proposed manager. If the person appears anywhere
        // in that chain, pointing at them closes the loop.
        //
        // The depth cap is a guard against a cycle that already exists in the
        // data — without it this query is the thing that hangs.
        return (int) $this->connection->fetchOne(
            'WITH RECURSIVE chain AS (
                 SELECT id, manager_id, 1 AS depth
                 FROM users
                 WHERE id = :manager AND tenant_id = :tenant

                 UNION ALL

                 SELECT u.id, u.manager_id, chain.depth + 1
                 FROM users u
                 JOIN chain ON chain.manager_id = u.id
                 WHERE u.tenant_id = :tenant AND chain.depth < 64
             )
             SELECT COUNT(*) FROM chain WHERE id = :person',
            ['tenant' => $tenantId, 'manager' => $managerId, 'person' => $personId],
        ) > 0;
    }

    public function statistics(string $tenantId): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT
                COUNT(*)                                                AS total,
                COUNT(*) FILTER (WHERE status = 'active')               AS active,
                COUNT(*) FILTER (WHERE status = 'invited')              AS invited,
                COUNT(*) FILTER (WHERE status = 'suspended')            AS suspended,
                COUNT(*) FILTER (WHERE status = 'locked')               AS locked,
                COUNT(*) FILTER (WHERE last_login_at IS NULL)           AS never_signed_in,
                COUNT(*) FILTER (WHERE last_login_at > now() - interval '30 days') AS active_last_30,
                COUNT(*) FILTER (WHERE created_at > now() - interval '30 days')    AS joined_last_30
             FROM users
             WHERE tenant_id = :tenant AND deleted_at IS NULL",
            ['tenant' => $tenantId],
        );

        $row = $row === false ? [] : $row;

        return array_map(static fn (mixed $v): int => (int) $v, [
            'total'         => $row['total'] ?? 0,
            'active'        => $row['active'] ?? 0,
            'invited'       => $row['invited'] ?? 0,
            'suspended'     => $row['suspended'] ?? 0,
            'locked'        => $row['locked'] ?? 0,
            'neverSignedIn' => $row['never_signed_in'] ?? 0,
            'activeLast30'  => $row['active_last_30'] ?? 0,
            'joinedLast30'  => $row['joined_last_30'] ?? 0,
        ]);
    }

    // --- internals --------------------------------------------------------

    /**
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function conditions(string $tenantId, PersonFilters $filters): array
    {
        $where  = ['u.tenant_id = :tenant', 'u.deleted_at IS NULL'];
        $params = ['tenant' => $tenantId];
        $types  = [];

        if ($filters->search !== null) {
            // Name or address. The legacy searched `first_name` only, so
            // looking somebody up by their surname found nothing.
            $where[]          = "(u.first_name || ' ' || u.last_name ILIKE :search OR u.email ILIKE :search)";
            $params['search'] = '%' . $filters->search . '%';
        }

        if ($filters->status !== null) {
            $where[]          = 'u.status = :status';
            $params['status'] = $filters->status->value;
        }

        foreach (
            [
            'branchId'     => 'u.branch_id',
            'departmentId' => 'u.department_id',
            'teamId'       => 'u.team_id',
            'levelId'      => 'u.level_id',
            'jobTitleId'   => 'u.job_title_id',
            'managerId'    => 'u.manager_id',
            ] as $property => $column
        ) {
            $value = $filters->{$property};

            if ($value !== null) {
                $where[]            = sprintf('%s = :%s', $column, $property);
                $params[$property]  = $value;
            }
        }

        if ($filters->roleId !== null) {
            $where[]          = 'EXISTS (SELECT 1 FROM user_roles ur
                                          WHERE ur.user_id = u.id AND ur.role_id = :roleId)';
            $params['roleId'] = $filters->roleId;
        }

        return [implode(' AND ', $where), $params, $types];
    }

    /**
     * Attaches role ids to a page of people in one query.
     *
     * @param  list<Person> $people
     * @return list<Person>
     */
    private function withRoles(string $tenantId, array $people): array
    {
        $ids = array_values(array_filter(array_map(
            static fn (Person $p): ?string => $p->id,
            $people,
        )));

        if ($ids === []) {
            return $people;
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ur.user_id, ur.role_id FROM user_roles ur WHERE ur.user_id IN (:ids)',
            ['ids' => $ids],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        $byUser = [];

        foreach ($rows as $row) {
            $byUser[(string) $row['user_id']][] = (string) $row['role_id'];
        }

        return array_map(
            static fn (Person $p): Person => new Person(
                id: $p->id,
                tenantId: $p->tenantId,
                email: $p->email,
                firstName: $p->firstName,
                lastName: $p->lastName,
                status: $p->status,
                phone: $p->phone,
                avatarUrl: $p->avatarUrl,
                locale: $p->locale,
                managerId: $p->managerId,
                branchId: $p->branchId,
                departmentId: $p->departmentId,
                teamId: $p->teamId,
                levelId: $p->levelId,
                jobTitleId: $p->jobTitleId,
                gender: $p->gender,
                employeeId: $p->employeeId,
                lineExecutiveId: $p->lineExecutiveId,
                hireDate: $p->hireDate,
                probationEndDate: $p->probationEndDate,
                roleIds: $byUser[(string) $p->id] ?? [],
                twoFactorEnabled: $p->twoFactorEnabled,
                lastLoginAt: $p->lastLoginAt,
                loginCount: $p->loginCount,
                createdAt: $p->createdAt,
                placement: $p->placement,
                activity: $p->activity,
            ),
            $people,
        );
    }

    /**
     * @param list<string> $roleIds
     */
    private function replaceRoles(Connection $tx, string $userId, array $roleIds): void
    {
        $tx->executeStatement('DELETE FROM user_roles WHERE user_id = :user', ['user' => $userId]);

        foreach ($roleIds as $roleId) {
            $tx->executeStatement(
                'INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)
                 ON CONFLICT DO NOTHING',
                ['user' => $userId, 'role' => $roleId],
            );
        }
    }

    /**
     * @param  array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function writableColumns(Person $person, array $extra = []): array
    {
        return [
            'tenant'       => $person->tenantId,
            'email'        => $person->email,
            'first'        => $person->firstName,
            'last'         => $person->lastName,
            'status'       => $person->status->value,
            'phone'        => $person->phone,
            'avatar'       => $person->avatarUrl,
            'locale'       => $person->locale,
            'manager'      => $person->managerId,
            'branch'       => $person->branchId,
            'department'   => $person->departmentId,
            'team'         => $person->teamId,
            'level'        => $person->levelId,
            'jobTitle'     => $person->jobTitleId,
            'gender'       => $person->gender,
            'employeeId'   => $person->employeeId,
            'lineExecutive' => $person->lineExecutiveId,
            'hireDate'     => $person->hireDate?->format('Y-m-d'),
            'probationEnd' => $person->probationEndDate?->format('Y-m-d'),
            'twoFactor'    => $person->twoFactorEnabled,
            ...$extra,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Person
    {
        $placement = array_key_exists('department_name', $row)
            ? new PersonPlacement(
                branch: $this->text($row['branch_name'] ?? null),
                department: $this->text($row['department_name'] ?? null),
                team: $this->text($row['team_name'] ?? null),
                level: $this->text($row['level_name'] ?? null),
                jobTitle: $this->text($row['job_title_name'] ?? null),
                manager: $this->text($row['manager_name'] ?? null),
                lineExecutive: $this->text($row['line_executive_name'] ?? null),
            )
            : null;

        return new Person(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            email: (string) $row['email'],
            firstName: (string) ($row['first_name'] ?? ''),
            lastName: (string) ($row['last_name'] ?? ''),
            status: UserStatus::from((string) $row['status']),
            phone: $this->text($row['phone'] ?? null),
            avatarUrl: $this->text($row['avatar_url'] ?? null),
            locale: (string) ($row['locale'] ?? 'en'),
            managerId: $this->text($row['manager_id'] ?? null),
            branchId: $this->text($row['branch_id'] ?? null),
            departmentId: $this->text($row['department_id'] ?? null),
            teamId: $this->text($row['team_id'] ?? null),
            levelId: $this->text($row['level_id'] ?? null),
            jobTitleId: $this->text($row['job_title_id'] ?? null),
            gender: $this->text($row['gender'] ?? null),
            employeeId: $this->text($row['employee_id'] ?? null),
            lineExecutiveId: $this->text($row['line_executive_id'] ?? null),
            hireDate: $this->date($row['hire_date'] ?? null),
            probationEndDate: $this->date($row['probation_end_date'] ?? null),
            twoFactorEnabled: (bool) ($row['two_factor_enabled'] ?? false),
            lastLoginAt: $this->date($row['last_login_at'] ?? null),
            loginCount: (int) ($row['login_count'] ?? 0),
            createdAt: $this->date($row['created_at'] ?? null),
            placement: $placement,
            activity: $this->activity($row['activity'] ?? null),
        );
    }

    private function activity(mixed $raw): ?PersonActivity
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        return new PersonActivity(
            enrolments: (int) ($decoded['enrolments'] ?? 0),
            inProgress: (int) ($decoded['in_progress'] ?? 0),
            completed: (int) ($decoded['completed'] ?? 0),
            overdue: (int) ($decoded['overdue'] ?? 0),
            secondsSpent: (int) ($decoded['seconds_spent'] ?? 0),
            lastActivityAt: $this->date($decoded['last_activity_at'] ?? null),
        );
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function date(mixed $value): ?\DateTimeImmutable
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
