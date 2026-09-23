<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Enrollment\EnrollmentSource;
use Central\Api\Domain\Ilt\AttendanceCalculator;
use Central\Api\Domain\Ilt\AttendanceMode;
use Central\Api\Domain\Ilt\AttendanceStatus;
use Central\Api\Domain\Ilt\AttendanceSummary;
use Central\Api\Domain\Ilt\InstructorRole;
use Central\Api\Domain\Ilt\LiveTraining;
use Central\Api\Domain\Ilt\Meeting;
use Central\Api\Domain\Ilt\MeetingProviderId;
use Central\Api\Domain\Ilt\SessionStatus;
use Central\Api\Domain\Ilt\TrainingDelivery;
use Central\Api\Domain\Ilt\TrainingRepository;
use Central\Api\Domain\Ilt\TrainingSession;
use Central\Api\Domain\Ilt\TrainingStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DbalTrainingRepository implements TrainingRepository
{
    private const TRAINING_COLUMNS = 't.id, t.tenant_id, t.title, t.description, t.summary,
                                     t.thumbnail_url, t.category_id, t.delivery, t.status,
                                     t.max_capacity, t.allow_waitlist, t.min_attendance_percentage,
                                     t.mandatory_sessions_only, t.issues_certificate,
                                     t.reminder_offsets_minutes, t.reminder_channels,
                                     t.qr_grace_period_minutes, t.qr_valid_before_start_minutes,
                                     t.min_virtual_duration_minutes, t.created_by,
                                     t.published_at, t.created_at, t.updated_at';

    private const TRAINING_DERIVED = "(
            SELECT COUNT(*) FROM training_sessions s
            WHERE s.training_id = t.id AND s.deleted_at IS NULL
        ) AS session_count,
        (
            SELECT COUNT(*) FROM training_enrollments e
            WHERE e.training_id = t.id AND e.deleted_at IS NULL
              AND e.status <> 'dropped' AND NOT e.waitlisted
        ) AS enrolled_count";

    /**
     * The lead instructor, read from the roster rather than kept beside it.
     *
     * Two places recording who teaches a session is two answers to the
     * question, and the one that gets updated is whichever the last person to
     * touch the code remembered.
     */
    private const SESSION_INSTRUCTOR = "(
            SELECT i.user_id FROM session_instructors i
            WHERE i.session_id = training_sessions.id AND i.role = 'primary'
        ) AS instructor_id";

    private const SESSION_COLUMNS = 'id, tenant_id, training_id, title, description, sequence,
                                     delivery, starts_at, ends_at, timezone,
                                     venue_name, venue_address, venue_room, venue_capacity, venue_map_url,
                                     platform, integration_id, external_meeting_id, join_url, host_url,
                                     meeting_password, meeting_scheduled_at, meeting_error,
                                     is_mandatory, allow_late_join, late_threshold_minutes,
                                     qr_token, qr_generated_at, qr_expires_at, status,
                                     attendance_locked, actual_started_at, actual_ended_at';

    public function __construct(
        private readonly Connection $connection,
        private readonly AttendanceCalculator $calculator,
    ) {
    }

    // --- programmes -------------------------------------------------------

    public function paginate(
        string $tenantId,
        int $page,
        int $perPage,
        ?string $search = null,
        ?TrainingStatus $status = null,
        bool $openToLearnersOnly = false,
    ): array {
        $where = 't.tenant_id = :tenant AND t.deleted_at IS NULL';
        $params = ['tenant' => $tenantId];

        if ($status !== null) {
            $where .= ' AND t.status = :status';
            $params['status'] = $status->value;
        }

        if ($openToLearnersOnly) {
            // Expressed as its own flag rather than an optional filter, so a
            // caller cannot forget it and show drafts to learners.
            $where .= " AND t.status IN ('published', 'ongoing')";
        }

        if ($search !== null && trim($search) !== '') {
            $where .= ' AND (t.title ILIKE :search OR t.summary ILIKE :search OR t.description ILIKE :search)';
            $params['search'] = '%' . trim($search) . '%';
        }

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM live_trainings t WHERE %s', $where),
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s FROM live_trainings t WHERE %s
                 ORDER BY t.title ASC LIMIT :limit OFFSET :offset',
                self::TRAINING_COLUMNS,
                self::TRAINING_DERIVED,
                $where,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(fn (array $r): LiveTraining => $this->hydrateTraining($r), $rows),
            'total' => $total,
        ];
    }

    public function find(string $tenantId, string $id): ?LiveTraining
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s FROM live_trainings t
                 WHERE t.id = :id AND t.tenant_id = :tenant AND t.deleted_at IS NULL',
                self::TRAINING_COLUMNS,
                self::TRAINING_DERIVED,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrateTraining($row);
    }

    public function create(LiveTraining $training): LiveTraining
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO live_trainings
                 (tenant_id, title, description, summary, thumbnail_url, category_id,
                  delivery, status, max_capacity, allow_waitlist, min_attendance_percentage,
                  mandatory_sessions_only, issues_certificate,
                  reminder_offsets_minutes, reminder_channels,
                  qr_grace_period_minutes, qr_valid_before_start_minutes,
                  min_virtual_duration_minutes, created_by)
             VALUES (:tenant, :title, :description, :summary, :thumbnail, :category,
                     :delivery, :status, :capacity, :waitlist, :attendance,
                     :mandatoryOnly, :certificate,
                     :offsets::int[], :channels::text[],
                     :grace, :validBefore, :minVirtual, :createdBy)
             RETURNING id',
            $this->trainingParams($training) + ['createdBy' => $training->createdBy],
        );

        return $this->find($training->tenantId, (string) $id)
            ?? throw new \RuntimeException('Newly created training could not be read back.');
    }

    public function update(LiveTraining $training): LiveTraining
    {
        $this->connection->executeStatement(
            'UPDATE live_trainings SET
                title = :title, description = :description, summary = :summary,
                thumbnail_url = :thumbnail, category_id = :category, delivery = :delivery,
                max_capacity = :capacity, allow_waitlist = :waitlist,
                min_attendance_percentage = :attendance, mandatory_sessions_only = :mandatoryOnly,
                issues_certificate = :certificate,
                reminder_offsets_minutes = :offsets::int[], reminder_channels = :channels::text[],
                qr_grace_period_minutes = :grace, qr_valid_before_start_minutes = :validBefore,
                min_virtual_duration_minutes = :minVirtual, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            // Status excluded: publishing goes through setStatus().
            array_diff_key($this->trainingParams($training), ['status' => null]) + ['id' => $training->id],
        );

        return $this->find($training->tenantId, $training->id)
            ?? throw new \RuntimeException('Updated training could not be read back.');
    }

    public function setStatus(string $tenantId, string $id, TrainingStatus $status): ?LiveTraining
    {
        $affected = $this->connection->executeStatement(
            "UPDATE live_trainings
             SET status = :status,
                 published_at = CASE
                     WHEN :status2 IN ('published', 'ongoing') AND published_at IS NULL THEN NOW()
                     ELSE published_at
                 END,
                 updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL",
            ['status' => $status->value, 'status2' => $status->value, 'id' => $id, 'tenant' => $tenantId],
        );

        return $affected > 0 ? $this->find($tenantId, $id) : null;
    }

    public function softDelete(string $tenantId, string $id): bool
    {
        return (bool) $this->connection->transactional(
            function (Connection $tx) use ($tenantId, $id): bool {
                $affected = $tx->executeStatement(
                    "UPDATE live_trainings SET deleted_at = NOW(), status = 'cancelled', updated_at = NOW()
                     WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL",
                    ['id' => $id, 'tenant' => $tenantId],
                );

                if ($affected === 0) {
                    return false;
                }

                // The sessions go with it. A session whose programme is gone
                // is unreachable but would still appear in "what is on".
                $tx->executeStatement(
                    "UPDATE training_sessions
                     SET deleted_at = NOW(), status = 'cancelled', updated_at = NOW()
                     WHERE training_id = :id AND tenant_id = :tenant AND deleted_at IS NULL",
                    ['id' => $id, 'tenant' => $tenantId],
                );

                return true;
            },
        );
    }

    // --- sessions ---------------------------------------------------------

    public function sessionsFor(string $tenantId, string $trainingId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s FROM training_sessions
                 WHERE training_id = :training AND tenant_id = :tenant AND deleted_at IS NULL
                 ORDER BY sequence ASC, starts_at ASC',
                self::SESSION_COLUMNS,
                self::SESSION_INSTRUCTOR,
            ),
            ['training' => $trainingId, 'tenant' => $tenantId],
        );

        return array_map(fn (array $r): TrainingSession => $this->hydrateSession($r), $rows);
    }

    public function findSession(string $tenantId, string $id): ?TrainingSession
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s FROM training_sessions
                 WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                self::SESSION_COLUMNS,
                self::SESSION_INSTRUCTOR,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrateSession($row);
    }

    public function createSession(TrainingSession $session): TrainingSession
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO training_sessions
                 (tenant_id, training_id, title, description, sequence, delivery,
                  starts_at, ends_at, timezone,
                  venue_name, venue_address, venue_room, venue_capacity, venue_map_url,
                  is_mandatory, allow_late_join, late_threshold_minutes)
             VALUES (:tenant, :training, :title, :description,
                     COALESCE(:sequence, (
                         SELECT COALESCE(MAX(sequence), 0) + 1
                         FROM training_sessions WHERE training_id = :training AND deleted_at IS NULL
                     )),
                     :delivery, :startsAt, :endsAt, :timezone,
                     :venueName, :venueAddress, :venueRoom, :venueCapacity, :venueMapUrl,
                     :mandatory, :lateJoin, :lateThreshold)
             RETURNING id',
            $this->sessionParams($session) + [
                'sequence' => $session->sequence > 1 ? $session->sequence : null,
            ],
        );

        $this->syncLeadInstructor($session->tenantId, (string) $id, $session->instructorId);

        return $this->findSession($session->tenantId, (string) $id)
            ?? throw new \RuntimeException('Newly created session could not be read back.');
    }

    public function updateSession(TrainingSession $session): TrainingSession
    {
        $affected = $this->connection->executeStatement(
            'UPDATE training_sessions SET
                title = :title, description = :description, sequence = :sequence,
                delivery = :delivery, starts_at = :startsAt, ends_at = :endsAt,
                timezone = :timezone,
                venue_name = :venueName, venue_address = :venueAddress, venue_room = :venueRoom,
                venue_capacity = :venueCapacity, venue_map_url = :venueMapUrl,
                is_mandatory = :mandatory, allow_late_join = :lateJoin,
                late_threshold_minutes = :lateThreshold, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            // The meeting columns are absent on purpose: they are the
            // provider's answer, written only by saveMeeting().
            array_diff_key($this->sessionParams($session), ['training' => null])
                + ['id' => $session->id, 'sequence' => $session->sequence],
        );

        if ($affected === 0) {
            throw new \RuntimeException('Updated session could not be found.');
        }

        $this->syncLeadInstructor($session->tenantId, $session->id, $session->instructorId);

        return $this->findSession($session->tenantId, $session->id)
            ?? throw new \RuntimeException('Updated session could not be read back.');
    }

    /**
     * Keeps the session form's single "instructor" field and the roster in
     * step.
     *
     * The form names one person; the roster may hold several. Saving the form
     * makes the named person the lead and the host, and leaves everybody else
     * on the session alone — clearing the roster because somebody edited the
     * start time would be a surprising thing for a date picker to do.
     *
     * A null instructor means the form left it blank, which is not the same as
     * "remove whoever is teaching this" and does not become it.
     */
    private function syncLeadInstructor(string $tenantId, string $sessionId, ?string $userId): void
    {
        if ($userId === null) {
            return;
        }

        $this->assignInstructor($tenantId, $sessionId, $userId, InstructorRole::Primary, true);
    }

    // --- who teaches it ---------------------------------------------------

    public function instructorsFor(string $tenantId, string $sessionId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT i.session_id, i.user_id, i.role, i.is_host, i.created_at,
                    u.first_name, u.last_name, u.email, u.avatar_url
             FROM session_instructors i
             JOIN users u ON u.id = i.user_id
             WHERE i.session_id = :session AND i.tenant_id = :tenant
               AND u.deleted_at IS NULL
             ORDER BY CASE i.role
                          WHEN 'primary' THEN 0
                          WHEN 'co_instructor' THEN 1
                          ELSE 2
                      END,
                      u.first_name",
            ['session' => $sessionId, 'tenant' => $tenantId],
        );

        return array_map(static fn (array $row): array => [
            'sessionId' => (string) $row['session_id'],
            'userId'    => (string) $row['user_id'],
            'role'      => (string) $row['role'],
            'roleLabel' => InstructorRole::from((string) $row['role'])->label(),
            'isHost'    => (bool) $row['is_host'],
            'firstName' => (string) $row['first_name'],
            'lastName'  => (string) $row['last_name'],
            'email'     => (string) $row['email'],
            'avatarUrl' => $row['avatar_url'] === null ? null : (string) $row['avatar_url'],
            'addedAt'   => (string) $row['created_at'],
        ], $rows);
    }

    public function assignInstructor(
        string $tenantId,
        string $sessionId,
        string $userId,
        InstructorRole $role,
        bool $isHost,
    ): bool {
        return (bool) $this->connection->transactional(
            function (Connection $tx) use ($tenantId, $sessionId, $userId, $role, $isHost): bool {
                $exists = (bool) $tx->fetchOne(
                    'SELECT 1 FROM training_sessions
                     WHERE id = :session AND tenant_id = :tenant AND deleted_at IS NULL',
                    ['session' => $sessionId, 'tenant' => $tenantId],
                );

                if (!$exists) {
                    return false;
                }

                // Naming a new lead moves the old one aside rather than being
                // refused: an administrator changing who runs a workshop means
                // to change it, and being made to demote the previous lead
                // first is a step with no decision in it.
                if ($role->isExclusive()) {
                    $tx->executeStatement(
                        "UPDATE session_instructors SET role = 'co_instructor'
                         WHERE session_id = :session AND role = 'primary' AND user_id <> :user",
                        ['session' => $sessionId, 'user' => $userId],
                    );
                }

                // The same for the host, which the provider can only issue
                // once — two hosts is a state it cannot represent, and the
                // second would simply not work on the morning.
                if ($isHost) {
                    $tx->executeStatement(
                        'UPDATE session_instructors SET is_host = FALSE
                         WHERE session_id = :session AND user_id <> :user',
                        ['session' => $sessionId, 'user' => $userId],
                    );
                }

                $tx->executeStatement(
                    'INSERT INTO session_instructors (session_id, user_id, tenant_id, role, is_host)
                     VALUES (:session, :user, :tenant, :role, :host)
                     ON CONFLICT (session_id, user_id) DO UPDATE SET
                         role = EXCLUDED.role, is_host = EXCLUDED.is_host',
                    [
                        'session' => $sessionId,
                        'user'    => $userId,
                        'tenant'  => $tenantId,
                        'role'    => $role->value,
                        'host'    => $isHost,
                    ],
                    ['host' => ParameterType::BOOLEAN],
                );

                return true;
            },
        );
    }

    public function removeInstructor(string $tenantId, string $sessionId, string $userId): bool
    {
        // No promotion afterwards. A session left with no lead is visibly
        // wrong on the screen, which is the right place for somebody to
        // decide who takes over — guessing would put a name on it that
        // nobody chose.
        return $this->connection->executeStatement(
            'DELETE FROM session_instructors
             WHERE session_id = :session AND user_id = :user AND tenant_id = :tenant',
            ['session' => $sessionId, 'user' => $userId, 'tenant' => $tenantId],
        ) > 0;
    }

    public function saveMeeting(
        string $tenantId,
        string $sessionId,
        ?MeetingProviderId $platform,
        ?Meeting $meeting,
        ?string $error,
    ): ?TrainingSession {
        $affected = $this->connection->executeStatement(
            'UPDATE training_sessions SET
                platform = :platform,
                external_meeting_id = :externalId,
                join_url = :joinUrl,
                host_url = :hostUrl,
                meeting_password = :password,
                meeting_scheduled_at = CASE WHEN :externalId2::text IS NULL THEN NULL ELSE NOW() END,
                meeting_error = :error,
                updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            [
                'platform'    => $meeting?->provider->value ?? $platform?->value,
                'externalId'  => $meeting?->externalId,
                'externalId2' => $meeting?->externalId,
                'joinUrl'     => $meeting?->joinUrl,
                'hostUrl'     => $meeting?->hostUrl,
                'password'    => $meeting?->password,
                'error'       => $error,
                'id'          => $sessionId,
                'tenant'      => $tenantId,
            ],
        );

        return $affected > 0 ? $this->findSession($tenantId, $sessionId) : null;
    }

    /**
     * Records a meeting error without touching the booking itself.
     *
     * Used when a reschedule fails: the existing join link is still valid and
     * in people's calendars, so it must survive — only the error is updated so
     * an organiser can see and retry, rather than being left with no meeting.
     */
    public function recordMeetingError(string $tenantId, string $sessionId, ?string $error): ?TrainingSession
    {
        $affected = $this->connection->executeStatement(
            'UPDATE training_sessions SET meeting_error = :error, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['error' => $error, 'id' => $sessionId, 'tenant' => $tenantId],
        );

        return $affected > 0 ? $this->findSession($tenantId, $sessionId) : null;
    }

    public function setSessionStatus(string $tenantId, string $id, SessionStatus $status): ?TrainingSession
    {
        $affected = $this->connection->executeStatement(
            "UPDATE training_sessions
             SET status = :status,
                 -- Stamped from the server clock when someone actually starts
                 -- or ends it, so a report can compare planned with actual.
                 actual_started_at = CASE
                     WHEN :status2 = 'ongoing' AND actual_started_at IS NULL THEN NOW()
                     ELSE actual_started_at
                 END,
                 actual_ended_at = CASE
                     WHEN :status3 = 'completed' AND actual_ended_at IS NULL THEN NOW()
                     ELSE actual_ended_at
                 END,
                 updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL",
            [
                'status'  => $status->value,
                'status2' => $status->value,
                'status3' => $status->value,
                'id'      => $id,
                'tenant'  => $tenantId,
            ],
        );

        return $affected > 0 ? $this->findSession($tenantId, $id) : null;
    }

    public function issueQrToken(string $tenantId, string $sessionId, LiveTraining $training): ?TrainingSession
    {
        $affected = $this->connection->executeStatement(
            'UPDATE training_sessions SET
                qr_token = :token,
                qr_generated_at = NOW(),
                -- The window runs to the grace period after the start, not
                -- from an unrelated clock. The one legacy token expired six
                -- hours before it was generated.
                qr_expires_at = starts_at + make_interval(mins => :grace),
                updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL
               AND starts_at + make_interval(mins => :grace2) > NOW()',
            [
                'token'  => bin2hex(random_bytes(32)),
                'grace'  => $training->qrGracePeriodMinutes,
                'grace2' => $training->qrGracePeriodMinutes,
                'id'     => $sessionId,
                'tenant' => $tenantId,
            ],
        );

        return $affected > 0 ? $this->findSession($tenantId, $sessionId) : null;
    }

    public function findSessionByQrToken(string $tenantId, string $token): ?TrainingSession
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s FROM training_sessions
                 WHERE qr_token = :token AND tenant_id = :tenant AND deleted_at IS NULL',
                self::SESSION_COLUMNS,
                self::SESSION_INSTRUCTOR,
            ),
            ['token' => $token, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrateSession($row);
    }

    // --- enrolment --------------------------------------------------------

    public function enrol(
        string $tenantId,
        LiveTraining $training,
        array $userIds,
        EnrollmentSource $source,
        ?string $actorId,
        ?string $ruleId = null,
        ?\DateTimeImmutable $dueDate = null,
        bool $isMandatory = false,
    ): array {
        /** @var array{enrolled: list<string>, waitlisted: list<string>, alreadyEnrolled: list<string>, notFound: list<string>, rejected: list<string>} $result */
        $result = $this->connection->transactional(
            function (Connection $tx) use (
                $tenantId,
                $training,
                $userIds,
                $source,
                $actorId,
                $ruleId,
                $dueDate,
                $isMandatory,
            ): array {
                /** @var list<string> $real */
                $real = $tx->fetchFirstColumn(
                    'SELECT id FROM users
                     WHERE tenant_id = :tenant AND deleted_at IS NULL AND id IN (:ids)',
                    ['tenant' => $tenantId, 'ids' => $userIds],
                    ['ids' => ArrayParameterType::STRING],
                );

                $notFound = array_values(array_diff($userIds, $real));

                /** @var array<string, string> $existing */
                $existing = $tx->fetchAllKeyValue(
                    'SELECT user_id, status FROM training_enrollments
                     WHERE training_id = :training AND tenant_id = :tenant
                       AND deleted_at IS NULL AND user_id IN (:ids)',
                    ['training' => $training->id, 'tenant' => $tenantId, 'ids' => $real ?: ['-']],
                    ['ids' => ArrayParameterType::STRING],
                );

                $taken = (int) $tx->fetchOne(
                    "SELECT COUNT(*) FROM training_enrollments
                     WHERE training_id = :training AND tenant_id = :tenant
                       AND deleted_at IS NULL AND status <> 'dropped' AND NOT waitlisted",
                    ['training' => $training->id, 'tenant' => $tenantId],
                );

                $enrolled = [];
                $waitlisted = [];
                $already = [];
                $rejected = [];

                foreach ($real as $userId) {
                    if (array_key_exists($userId, $existing) && $existing[$userId] !== 'dropped') {
                        $already[] = $userId;

                        continue;
                    }

                    // Capacity is checked per learner as the batch runs, so a
                    // list of fifty into ten remaining places fills the ten and
                    // says what happened to the rest.
                    $verdict = $training->admits($taken);

                    if (!$verdict['allowed']) {
                        $rejected[] = $userId;

                        continue;
                    }

                    if ($verdict['waitlist']) {
                        $waitlisted[] = $userId;
                    } else {
                        $enrolled[] = $userId;
                        $taken++;
                    }

                    $this->writeEnrolment(
                        $tx,
                        $tenantId,
                        $training->id,
                        $userId,
                        $source,
                        $actorId,
                        $verdict['waitlist'],
                        array_key_exists($userId, $existing),
                        $ruleId,
                        $dueDate,
                        $isMandatory,
                    );
                }

                return [
                    'enrolled'        => $enrolled,
                    'waitlisted'      => $waitlisted,
                    'alreadyEnrolled' => $already,
                    'notFound'        => $notFound,
                    'rejected'        => $rejected,
                ];
            },
        );

        return $result;
    }

    public function withdraw(string $tenantId, string $trainingId, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        /** @var list<string> $removed */
        $removed = $this->connection->fetchFirstColumn(
            "UPDATE training_enrollments
             SET status = 'dropped', completed_at = NULL, updated_at = NOW()
             WHERE training_id = :training AND tenant_id = :tenant
               AND deleted_at IS NULL AND status <> 'dropped' AND user_id IN (:ids)
             RETURNING user_id",
            ['training' => $trainingId, 'tenant' => $tenantId, 'ids' => $userIds],
            ['ids' => ArrayParameterType::STRING],
        );

        return $removed;
    }

    public function rosterFor(string $tenantId, string $trainingId, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));

        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM training_enrollments
             WHERE training_id = :training AND tenant_id = :tenant AND deleted_at IS NULL',
            ['training' => $trainingId, 'tenant' => $tenantId],
        );

        $rows = $this->connection->fetchAllAssociative(
            "SELECT e.id, e.user_id, e.status, e.source, e.waitlisted, e.waitlist_position,
                    e.attendance_percentage, e.sessions_attended, e.enrolled_at, e.completed_at,
                    NULLIF(btrim(concat_ws(' ', u.first_name, u.last_name)), '') AS user_name,
                    u.email AS user_email
             FROM training_enrollments e
             LEFT JOIN users u ON u.id = e.user_id
             WHERE e.training_id = :training AND e.tenant_id = :tenant AND e.deleted_at IS NULL
             ORDER BY e.waitlisted ASC, e.waitlist_position ASC NULLS FIRST, u.first_name ASC
             LIMIT :limit OFFSET :offset",
            [
                'training' => $trainingId,
                'tenant'   => $tenantId,
                'limit'    => $perPage,
                'offset'   => ($page - 1) * $perPage,
            ],
        );

        return [
            'items' => array_map(static fn (array $r): array => [
                'id'         => (string) $r['id'],
                'userId'     => (string) $r['user_id'],
                'userName'   => is_string($r['user_name'] ?? null) ? $r['user_name'] : null,
                'userEmail'  => is_string($r['user_email'] ?? null) ? $r['user_email'] : null,
                'status'     => (string) $r['status'],
                'source'     => (string) $r['source'],
                'waitlisted' => (bool) $r['waitlisted'],
                'waitlistPosition' => $r['waitlist_position'] === null ? null : (int) $r['waitlist_position'],
                'attendancePercentage' => (float) $r['attendance_percentage'],
                'sessionsAttended'     => (int) $r['sessions_attended'],
                'enrolledAt'  => (string) $r['enrolled_at'],
                'completedAt' => is_string($r['completed_at'] ?? null) ? $r['completed_at'] : null,
            ], $rows),
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findEnrolment(string $tenantId, string $trainingId, string $userId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, user_id, status, source, waitlisted, waitlist_position,
                    attendance_percentage, sessions_attended, enrolled_at, completed_at
             FROM training_enrollments
             WHERE training_id = :training AND user_id = :user
               AND tenant_id = :tenant AND deleted_at IS NULL',
            ['training' => $trainingId, 'user' => $userId, 'tenant' => $tenantId],
        );

        if ($row === false) {
            return null;
        }

        return [
            'id'         => (string) $row['id'],
            'userId'     => (string) $row['user_id'],
            'status'     => (string) $row['status'],
            'source'     => (string) $row['source'],
            'waitlisted' => (bool) $row['waitlisted'],
            'waitlistPosition' => $row['waitlist_position'] === null ? null : (int) $row['waitlist_position'],
            'attendancePercentage' => (float) $row['attendance_percentage'],
            'sessionsAttended'     => (int) $row['sessions_attended'],
            'enrolledAt'  => (string) $row['enrolled_at'],
            'completedAt' => is_string($row['completed_at'] ?? null) ? $row['completed_at'] : null,
        ];
    }

    public function enrolmentsForUser(string $tenantId, string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT e.id, e.training_id, e.user_id, e.status, e.source, e.waitlisted,
                    e.attendance_percentage, e.sessions_attended, e.enrolled_at, e.completed_at,
                    t.title AS training_title, t.delivery, t.min_attendance_percentage,
                    t.mandatory_sessions_only
             FROM training_enrollments e
             JOIN live_trainings t ON t.id = e.training_id
             WHERE e.tenant_id = :tenant AND e.user_id = :user
               AND e.deleted_at IS NULL AND t.deleted_at IS NULL
             ORDER BY e.enrolled_at DESC',
            ['tenant' => $tenantId, 'user' => $userId],
        );

        if ($rows === []) {
            return [];
        }

        // The sessions of every one of those programmes, in one query rather
        // than one per row — a learner on ten programmes should not cost ten
        // round trips to the database to draw one screen.
        $sessions = $this->sessionsForTrainings(
            $tenantId,
            array_map(static fn (array $r): string => (string) $r['training_id'], $rows),
        );

        return array_map(
            function (array $r) use ($sessions): array {
                $trainingId = (string) $r['training_id'];
                $attended = (int) $r['sessions_attended'];
                // Count only the sessions that actually count toward the
                // requirement — never cancelled ones, and only mandatory ones
                // when the programme is mandatory-only — so the count agrees
                // with the attendance percentage rather than contradicting it.
                $mandatoryOnly = (bool) $r['mandatory_sessions_only'];
                $counted = count(array_filter(
                    $sessions[$trainingId] ?? [],
                    static function (array $s) use ($mandatoryOnly): bool {
                        if (($s['status'] ?? null) === 'cancelled') {
                            return false;
                        }

                        return !$mandatoryOnly || ($s['isMandatory'] ?? false) === true;
                    },
                ));
                $required = (float) $r['min_attendance_percentage'];
                $percentage = (float) $r['attendance_percentage'];

                return [
                    'id'            => (string) $r['id'],
                    'trainingId'    => $trainingId,
                    'trainingTitle' => (string) $r['training_title'],
                    'delivery'      => (string) $r['delivery'],
                    'userId'        => (string) $r['user_id'],
                    'status'        => (string) $r['status'],
                    'source'        => (string) $r['source'],
                    'waitlisted'    => (bool) $r['waitlisted'],
                    'attendancePercentage' => $percentage,
                    'sessionsAttended'     => $attended,
                    'sessionsCounted'      => $counted,
                    // Worked out here rather than stored: a stored flag goes
                    // stale the moment a session is added to the programme.
                    'meetsRequirement'     => $percentage >= $required,
                    'enrolledAt'  => (string) $r['enrolled_at'],
                    'completedAt' => is_string($r['completed_at'] ?? null) ? $r['completed_at'] : null,
                    'sessions'    => $sessions[$trainingId] ?? [],
                ];
            },
            $rows,
        );
    }

    /**
     * The sessions of several programmes at once, keyed by programme.
     *
     * @param list<string> $trainingIds
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function sessionsForTrainings(string $tenantId, array $trainingIds): array
    {
        if ($trainingIds === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s FROM training_sessions
                 WHERE tenant_id = :tenant AND training_id IN (:ids) AND deleted_at IS NULL
                 ORDER BY starts_at',
                self::SESSION_COLUMNS,
                self::SESSION_INSTRUCTOR,
            ),
            ['tenant' => $tenantId, 'ids' => array_values(array_unique($trainingIds))],
            ['ids' => ArrayParameterType::STRING],
        );

        $byTraining = [];

        foreach ($rows as $row) {
            $session = $this->hydrateSession($row);
            $byTraining[$session->trainingId][] = $session->toArray();
        }

        return $byTraining;
    }

    public function countEnrolled(string $tenantId, string $trainingId): int
    {
        return (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM training_enrollments
             WHERE training_id = :training AND tenant_id = :tenant
               AND deleted_at IS NULL AND status <> 'dropped' AND NOT waitlisted",
            ['training' => $trainingId, 'tenant' => $tenantId],
        );
    }

    public function enrolledUserIds(string $tenantId, string $trainingId): array
    {
        return array_map(
            static fn (mixed $id): string => (string) $id,
            $this->connection->fetchFirstColumn(
                "SELECT user_id FROM training_enrollments
                 WHERE training_id = :training AND tenant_id = :tenant
                   AND deleted_at IS NULL AND status <> 'dropped' AND NOT waitlisted
                 ORDER BY user_id",
                ['training' => $trainingId, 'tenant' => $tenantId],
            ),
        );
    }

    // --- attendance -------------------------------------------------------

    public function mark(
        string $tenantId,
        TrainingSession $session,
        LiveTraining $training,
        string $userId,
        AttendanceStatus $status,
        AttendanceMode $mode,
        array $details = [],
    ): array {
        /** @var array{attendance: array<string, mixed>, summary: AttendanceSummary} $result */
        $result = $this->connection->transactional(
            function (Connection $tx) use ($tenantId, $session, $training, $userId, $status, $mode, $details): array {
                $enrolment = $this->findEnrolment($tenantId, $session->trainingId, $userId);

                if ($enrolment === null) {
                    throw new \RuntimeException('That learner is not on this programme.');
                }

                $override = (bool) ($details['isOverride'] ?? false);

                $tx->executeStatement(
                    'INSERT INTO session_attendance
                         (tenant_id, session_id, enrollment_id, user_id, status, mode,
                          qr_scanned_at, qr_scan_location, joined_at, left_at, duration_minutes,
                          provider_data, is_manual_override, override_by, override_at, override_reason,
                          marked_at)
                     VALUES (:tenant, :session, :enrollment, :user, :status, :mode,
                             :qrAt, :qrLocation, :joinedAt, :leftAt, :duration,
                             :providerData::jsonb, :override, :overrideBy, :overrideAt, :overrideReason,
                             NOW())
                     ON CONFLICT (session_id, user_id) DO UPDATE SET
                         status = EXCLUDED.status,
                         mode = EXCLUDED.mode,
                         qr_scanned_at = COALESCE(EXCLUDED.qr_scanned_at, session_attendance.qr_scanned_at),
                         qr_scan_location = COALESCE(EXCLUDED.qr_scan_location, session_attendance.qr_scan_location),
                         joined_at = COALESCE(EXCLUDED.joined_at, session_attendance.joined_at),
                         left_at = COALESCE(EXCLUDED.left_at, session_attendance.left_at),
                         duration_minutes = COALESCE(EXCLUDED.duration_minutes, session_attendance.duration_minutes),
                         provider_data = COALESCE(EXCLUDED.provider_data, session_attendance.provider_data),
                         is_manual_override = EXCLUDED.is_manual_override,
                         override_by = EXCLUDED.override_by,
                         override_at = EXCLUDED.override_at,
                         override_reason = EXCLUDED.override_reason,
                         marked_at = NOW(),
                         updated_at = NOW()',
                    [
                        'tenant'     => $tenantId,
                        'session'    => $session->id,
                        'enrollment' => $enrolment['id'],
                        'user'       => $userId,
                        'status'     => $status->value,
                        'mode'       => $mode->value,
                        'qrAt'       => $details['qrScannedAt'] ?? null,
                        'qrLocation' => $details['qrScanLocation'] ?? null,
                        'joinedAt'   => $details['joinedAt'] ?? null,
                        'leftAt'     => $details['leftAt'] ?? null,
                        'duration'   => $details['durationMinutes'] ?? null,
                        'providerData' => isset($details['providerData'])
                            ? json_encode($details['providerData'], JSON_THROW_ON_ERROR)
                            : null,
                        'override'       => $override ? 'true' : 'false',
                        'overrideBy'     => $override ? ($details['overrideBy'] ?? null) : null,
                        'overrideAt'     => $override ? 'now' : null,
                        'overrideReason' => $override ? ($details['overrideReason'] ?? null) : null,
                    ],
                );

                // Same transaction: the programme figure must never be seen
                // disagreeing with the sessions underneath it.
                $summary = $this->recalculateWithin($tx, $tenantId, $training, $userId);

                $records = $this->attendanceFor($tenantId, $session->id);
                $mine = [];

                foreach ($records as $record) {
                    if ($record['userId'] === $userId) {
                        $mine = $record;
                        break;
                    }
                }

                return ['attendance' => $mine, 'summary' => $summary];
            },
        );

        return $result;
    }

    public function attendanceFor(string $tenantId, string $sessionId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT a.id, a.user_id, a.status, a.mode, a.qr_scanned_at, a.joined_at, a.left_at,
                    a.duration_minutes, a.is_manual_override, a.override_reason, a.marked_at,
                    NULLIF(btrim(concat_ws(' ', u.first_name, u.last_name)), '') AS user_name,
                    u.email AS user_email
             FROM session_attendance a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE a.session_id = :session AND a.tenant_id = :tenant
             ORDER BY u.first_name ASC",
            ['session' => $sessionId, 'tenant' => $tenantId],
        );

        return array_map(static fn (array $r): array => [
            'id'        => (string) $r['id'],
            'userId'    => (string) $r['user_id'],
            'userName'  => is_string($r['user_name'] ?? null) ? $r['user_name'] : null,
            'userEmail' => is_string($r['user_email'] ?? null) ? $r['user_email'] : null,
            'status'    => (string) $r['status'],
            'mode'      => is_string($r['mode'] ?? null) ? $r['mode'] : null,
            'qrScannedAt' => is_string($r['qr_scanned_at'] ?? null) ? $r['qr_scanned_at'] : null,
            'joinedAt'    => is_string($r['joined_at'] ?? null) ? $r['joined_at'] : null,
            'leftAt'      => is_string($r['left_at'] ?? null) ? $r['left_at'] : null,
            'durationMinutes' => $r['duration_minutes'] === null ? null : (int) $r['duration_minutes'],
            // Surfaced so a register shows which marks a person made by hand.
            'isManualOverride' => (bool) $r['is_manual_override'],
            'overrideReason'   => is_string($r['override_reason'] ?? null) ? $r['override_reason'] : null,
            'markedAt'         => is_string($r['marked_at'] ?? null) ? $r['marked_at'] : null,
        ], $rows);
    }

    public function recalculate(string $tenantId, LiveTraining $training, string $userId): ?AttendanceSummary
    {
        return $this->recalculateWithin($this->connection, $tenantId, $training, $userId);
    }

    // --- internals --------------------------------------------------------

    private function recalculateWithin(
        Connection $connection,
        string $tenantId,
        LiveTraining $training,
        string $userId,
    ): ?AttendanceSummary {
        $enrolment = $this->findEnrolment($tenantId, $training->id, $userId);

        if ($enrolment === null) {
            return null;
        }

        $sessionRows = $connection->fetchAllAssociative(
            'SELECT id, is_mandatory, status FROM training_sessions
             WHERE training_id = :training AND tenant_id = :tenant AND deleted_at IS NULL',
            ['training' => $training->id, 'tenant' => $tenantId],
        );

        /** @var array<string, string> $markRows */
        $markRows = $connection->fetchAllKeyValue(
            'SELECT session_id, status FROM session_attendance
             WHERE enrollment_id = :enrollment AND tenant_id = :tenant',
            ['enrollment' => $enrolment['id'], 'tenant' => $tenantId],
        );

        $summary = $this->calculator->calculate(
            array_map(static fn (array $r): array => [
                'sessionId'   => (string) $r['id'],
                'isMandatory' => (bool) $r['is_mandatory'],
                'status'      => SessionStatus::fromLegacy((string) $r['status']),
            ], $sessionRows),
            array_map(
                static fn (string $s): AttendanceStatus => AttendanceStatus::fromLegacy($s),
                $markRows,
            ),
            $training->minAttendancePercentage,
            $training->mandatorySessionsOnly,
        );

        // Completion is only claimed once every session has happened. Meeting
        // the bar halfway through a programme is not finishing it.
        $allDone = $connection->fetchOne(
            "SELECT COUNT(*) FROM training_sessions
             WHERE training_id = :training AND tenant_id = :tenant AND deleted_at IS NULL
               AND status NOT IN ('completed', 'cancelled')",
            ['training' => $training->id, 'tenant' => $tenantId],
        ) === 0;

        $completed = $allDone && $summary->meetsRequirement() && $sessionRows !== [];

        $connection->executeStatement(
            "UPDATE training_enrollments SET
                attendance_percentage = :percentage,
                sessions_attended = :attended,
                status = CASE
                    WHEN status = 'dropped' THEN 'dropped'
                    WHEN :completed THEN 'completed'
                    WHEN :attended2 > 0 THEN 'in_progress'
                    ELSE 'not_started'
                END,
                completed_at = CASE
                    WHEN status <> 'dropped' AND :completed2 THEN COALESCE(completed_at, NOW())
                    ELSE NULL
                END,
                updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant",
            [
                'percentage' => $summary->percentage,
                'attended'   => $summary->sessionsAttended,
                'attended2'  => $summary->sessionsAttended,
                'completed'  => $completed ? 'true' : 'false',
                'completed2' => $completed ? 'true' : 'false',
                'id'         => $enrolment['id'],
                'tenant'     => $tenantId,
            ],
        );

        return $summary;
    }

    private function writeEnrolment(
        Connection $tx,
        string $tenantId,
        string $trainingId,
        string $userId,
        EnrollmentSource $source,
        ?string $actorId,
        bool $waitlisted,
        bool $reinstating,
        ?string $ruleId = null,
        ?\DateTimeImmutable $dueDate = null,
        bool $isMandatory = false,
    ): void {
        $position = null;

        if ($waitlisted) {
            $position = 1 + (int) $tx->fetchOne(
                'SELECT COALESCE(MAX(waitlist_position), 0) FROM training_enrollments
                 WHERE training_id = :training AND tenant_id = :tenant AND waitlisted',
                ['training' => $trainingId, 'tenant' => $tenantId],
            );
        }

        if ($reinstating) {
            $tx->executeStatement(
                "UPDATE training_enrollments
                 SET status = 'not_started', completed_at = NULL, source = :source,
                     assigned_by = :actor, waitlisted = :waitlisted, waitlist_position = :position,
                     rule_id = :rule::uuid, due_date = :dueDate::date, is_mandatory = :mandatory,
                     updated_at = NOW()
                 WHERE training_id = :training AND user_id = :user AND tenant_id = :tenant",
                [
                    'source'     => $source->value,
                    'actor'      => $actorId,
                    'waitlisted' => $waitlisted ? 'true' : 'false',
                    'position'   => $position,
                    'rule'       => $ruleId,
                    'dueDate'    => $dueDate?->format('Y-m-d'),
                    'mandatory'  => $isMandatory ? 'true' : 'false',
                    'training'   => $trainingId,
                    'user'       => $userId,
                    'tenant'     => $tenantId,
                ],
            );

            return;
        }

        $tx->executeStatement(
            'INSERT INTO training_enrollments
                 (tenant_id, training_id, user_id, source, assigned_by, waitlisted,
                  waitlist_position, rule_id, due_date, is_mandatory)
             VALUES (:tenant, :training, :user, :source, :actor, :waitlisted,
                     :position, :rule::uuid, :dueDate::date, :mandatory)',
            [
                'tenant'     => $tenantId,
                'training'   => $trainingId,
                'user'       => $userId,
                'source'     => $source->value,
                'actor'      => $actorId,
                'waitlisted' => $waitlisted ? 'true' : 'false',
                'position'   => $position,
                'rule'       => $ruleId,
                'dueDate'    => $dueDate?->format('Y-m-d'),
                'mandatory'  => $isMandatory ? 'true' : 'false',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function trainingParams(LiveTraining $t): array
    {
        return [
            'tenant'      => $t->tenantId,
            'title'       => $t->title,
            'description' => $t->description,
            'summary'     => $t->summary,
            'thumbnail'   => $t->thumbnailUrl,
            'category'    => $t->categoryId,
            'delivery'    => $t->delivery->value,
            'status'      => $t->status->value,
            'capacity'    => $t->maxCapacity,
            'waitlist'    => $t->allowWaitlist ? 'true' : 'false',
            'attendance'  => $t->minAttendancePercentage,
            'mandatoryOnly' => $t->mandatorySessionsOnly ? 'true' : 'false',
            'certificate'   => $t->issuesCertificate ? 'true' : 'false',
            'offsets'  => '{' . implode(',', $t->reminderOffsetsMinutes) . '}',
            'channels' => '{' . implode(',', $t->reminderChannels) . '}',
            'grace'       => $t->qrGracePeriodMinutes,
            'validBefore' => $t->qrValidBeforeStartMinutes,
            'minVirtual'  => $t->minVirtualDurationMinutes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionParams(TrainingSession $s): array
    {
        return [
            'tenant'      => $s->tenantId,
            'training'    => $s->trainingId,
            'title'       => $s->title,
            'description' => $s->description,
            'delivery'    => $s->delivery->value,
            'startsAt'    => $s->startsAt->format(\DateTimeInterface::ATOM),
            'endsAt'      => $s->endsAt->format(\DateTimeInterface::ATOM),
            'timezone'    => $s->timezone,
            'venueName'    => $s->venueName,
            'venueAddress' => $s->venueAddress,
            'venueRoom'    => $s->venueRoom,
            'venueCapacity' => $s->venueCapacity,
            'venueMapUrl'   => $s->venueMapUrl,
            'mandatory'     => $s->isMandatory ? 'true' : 'false',
            'lateJoin'      => $s->allowLateJoin ? 'true' : 'false',
            'lateThreshold' => $s->lateThresholdMinutes,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateTraining(array $row): LiveTraining
    {
        return new LiveTraining(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            title: (string) $row['title'],
            delivery: TrainingDelivery::fromLegacy((string) $row['delivery']),
            status: TrainingStatus::fromLegacy((string) $row['status']),
            description: $this->text($row['description'] ?? null),
            summary: $this->text($row['summary'] ?? null),
            thumbnailUrl: $this->text($row['thumbnail_url'] ?? null),
            categoryId: $this->text($row['category_id'] ?? null),
            maxCapacity: isset($row['max_capacity']) ? (int) $row['max_capacity'] : null,
            allowWaitlist: (bool) $row['allow_waitlist'],
            minAttendancePercentage: (float) $row['min_attendance_percentage'],
            mandatorySessionsOnly: (bool) $row['mandatory_sessions_only'],
            issuesCertificate: (bool) $row['issues_certificate'],
            reminderOffsetsMinutes: array_map(
                static fn (string $v): int => (int) $v,
                $this->pgArray($row['reminder_offsets_minutes'] ?? null),
            ),
            reminderChannels: $this->pgArray($row['reminder_channels'] ?? null),
            qrGracePeriodMinutes: (int) $row['qr_grace_period_minutes'],
            qrValidBeforeStartMinutes: (int) $row['qr_valid_before_start_minutes'],
            minVirtualDurationMinutes: (int) $row['min_virtual_duration_minutes'],
            createdBy: $this->text($row['created_by'] ?? null),
            publishedAt: $this->date($row['published_at'] ?? null),
            createdAt: $this->date($row['created_at'] ?? null),
            updatedAt: $this->date($row['updated_at'] ?? null),
            sessionCount: (int) ($row['session_count'] ?? 0),
            enrolledCount: (int) ($row['enrolled_count'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateSession(array $row): TrainingSession
    {
        $platform = $this->text($row['platform'] ?? null);

        return new TrainingSession(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            trainingId: (string) $row['training_id'],
            title: (string) $row['title'],
            startsAt: $this->date($row['starts_at'] ?? null) ?? new \DateTimeImmutable(),
            endsAt: $this->date($row['ends_at'] ?? null) ?? new \DateTimeImmutable(),
            timezone: (string) $row['timezone'],
            delivery: TrainingDelivery::fromLegacy((string) $row['delivery']),
            status: SessionStatus::fromLegacy((string) $row['status']),
            sequence: (int) $row['sequence'],
            description: $this->text($row['description'] ?? null),
            instructorId: $this->text($row['instructor_id'] ?? null),
            venueName: $this->text($row['venue_name'] ?? null),
            venueAddress: $this->text($row['venue_address'] ?? null),
            venueRoom: $this->text($row['venue_room'] ?? null),
            venueCapacity: isset($row['venue_capacity']) ? (int) $row['venue_capacity'] : null,
            venueMapUrl: $this->text($row['venue_map_url'] ?? null),
            platform: $platform === null ? null : MeetingProviderId::tryFrom($platform),
            integrationId: $this->text($row['integration_id'] ?? null),
            externalMeetingId: $this->text($row['external_meeting_id'] ?? null),
            joinUrl: $this->text($row['join_url'] ?? null),
            hostUrl: $this->text($row['host_url'] ?? null),
            meetingPassword: $this->text($row['meeting_password'] ?? null),
            meetingScheduledAt: $this->date($row['meeting_scheduled_at'] ?? null),
            meetingError: $this->text($row['meeting_error'] ?? null),
            isMandatory: (bool) $row['is_mandatory'],
            allowLateJoin: (bool) $row['allow_late_join'],
            lateThresholdMinutes: (int) $row['late_threshold_minutes'],
            qrToken: $this->text($row['qr_token'] ?? null),
            qrGeneratedAt: $this->date($row['qr_generated_at'] ?? null),
            qrExpiresAt: $this->date($row['qr_expires_at'] ?? null),
            attendanceLocked: (bool) $row['attendance_locked'],
            actualStartedAt: $this->date($row['actual_started_at'] ?? null),
            actualEndedAt: $this->date($row['actual_ended_at'] ?? null),
        );
    }

    /**
     * Reads a PostgreSQL array literal.
     *
     * @return list<string>
     */
    private function pgArray(mixed $value): array
    {
        if (!is_string($value) || $value === '' || $value === '{}') {
            return [];
        }

        $inner = trim($value, '{}');

        if ($inner === '') {
            return [];
        }

        return array_values(array_map(
            static fn (string $v): string => trim($v, '"'),
            explode(',', $inner),
        ));
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
