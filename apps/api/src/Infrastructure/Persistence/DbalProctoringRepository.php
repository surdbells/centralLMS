<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Assessment\ProctoringLevel;
use Central\Api\Domain\Proctoring\ModuleEntitlements;
use Central\Api\Domain\Proctoring\ProctoringRepository;
use Central\Api\Domain\Proctoring\ProctoringSession;
use Central\Api\Domain\Proctoring\ProctoringSettings;
use Central\Api\Domain\Proctoring\SessionStatus;
use Central\Api\Domain\Proctoring\TrustScore;
use Central\Api\Domain\Proctoring\ViolationSeverity;
use Central\Api\Domain\Proctoring\ViolationType;
use Doctrine\DBAL\Connection;

final class DbalProctoringRepository implements ProctoringRepository
{
    private const SESSION_COLUMNS = 'id, tenant_id, attempt_id, user_id, status,
                                     system_check_passed, system_check_at, system_check_data,
                                     id_verified, id_verification_at, id_photo_url, face_photo_url,
                                     face_match_score, room_scan_completed, room_scan_video_url,
                                     screen_recording_url, webcam_recording_url,
                                     started_at, ended_at, total_active_seconds,
                                     reviewed_by, reviewed_at, review_notes, review_status';

    /**
     * The columns `updateSession()` will write, and no others.
     *
     * An allow-list rather than trusting the caller's keys: the method takes an
     * array, and an array is one typo away from writing a column nobody meant
     * to expose.
     */
    private const WRITABLE = [
        'status'               => 'status',
        'systemCheckPassed'    => 'system_check_passed',
        'systemCheckAt'        => 'system_check_at',
        'systemCheckData'      => 'system_check_data',
        'idVerified'           => 'id_verified',
        'idVerificationAt'     => 'id_verification_at',
        'idPhotoUrl'           => 'id_photo_url',
        'facePhotoUrl'         => 'face_photo_url',
        'faceMatchScore'       => 'face_match_score',
        'roomScanCompleted'    => 'room_scan_completed',
        'roomScanVideoUrl'     => 'room_scan_video_url',
        'screenRecordingUrl'   => 'screen_recording_url',
        'webcamRecordingUrl'   => 'webcam_recording_url',
        'startedAt'            => 'started_at',
        'endedAt'              => 'ended_at',
        'totalActiveSeconds'   => 'total_active_seconds',
        'reviewedBy'           => 'reviewed_by',
        'reviewedAt'           => 'reviewed_at',
        'reviewNotes'          => 'review_notes',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    // --- entitlement ------------------------------------------------------

    public function entitlementsFor(string $tenantId): ModuleEntitlements
    {
        $raw = $this->connection->fetchOne(
            'SELECT entitlements FROM tenants WHERE id = :id',
            ['id' => $tenantId],
        );

        if (!is_string($raw)) {
            return ModuleEntitlements::none();
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? ModuleEntitlements::fromStored($decoded) : ModuleEntitlements::none();
    }

    public function setEntitlement(string $tenantId, string $module, bool $enabled): ModuleEntitlements
    {
        $this->connection->executeStatement(
            'UPDATE tenants
             SET entitlements = jsonb_set(entitlements, :path::text[], :value::jsonb, true),
                 updated_at = NOW()
             WHERE id = :id',
            [
                'path'  => '{' . $module . '}',
                'value' => $enabled ? 'true' : 'false',
                'id'    => $tenantId,
            ],
        );

        return $this->entitlementsFor($tenantId);
    }

    // --- settings ---------------------------------------------------------

    public function settingsFor(string $tenantId, string $assessmentId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT level, settings FROM proctoring_settings
             WHERE assessment_id = :assessment AND tenant_id = :tenant',
            ['assessment' => $assessmentId, 'tenant' => $tenantId],
        );

        if ($row === false) {
            return null;
        }

        $level = ProctoringLevel::tryFrom((string) $row['level']) ?? ProctoringLevel::None;
        $decoded = json_decode((string) $row['settings'], true);

        return [
            'level'    => $level,
            'settings' => ProctoringSettings::fromStored($level, is_array($decoded) ? $decoded : []),
        ];
    }

    public function saveSettings(
        string $tenantId,
        string $assessmentId,
        ProctoringLevel $level,
        ProctoringSettings $settings,
    ): void {
        $this->connection->transactional(
            function (Connection $tx) use ($tenantId, $assessmentId, $level, $settings): void {
                $tx->executeStatement(
                    'INSERT INTO proctoring_settings (tenant_id, assessment_id, level, settings)
                     VALUES (:tenant, :assessment, :level, :settings::jsonb)
                     ON CONFLICT (assessment_id) DO UPDATE SET
                         level = EXCLUDED.level,
                         settings = EXCLUDED.settings,
                         updated_at = NOW()',
                    [
                        'tenant'     => $tenantId,
                        'assessment' => $assessmentId,
                        'level'      => $level->value,
                        'settings'   => json_encode($settings->toArray(), JSON_THROW_ON_ERROR),
                    ],
                );

                // Same transaction: the core reads the level from the
                // assessment and must never see it disagree with this row.
                $tx->executeStatement(
                    'UPDATE assessments SET proctoring_level = :level, updated_at = NOW()
                     WHERE id = :assessment AND tenant_id = :tenant',
                    ['level' => $level->value, 'assessment' => $assessmentId, 'tenant' => $tenantId],
                );
            },
        );
    }

    // --- presets ----------------------------------------------------------

    public function presetsFor(string $tenantId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, tenant_id, name, description, level, settings, is_default
             FROM proctoring_presets
             WHERE tenant_id = :tenant OR tenant_id IS NULL
             ORDER BY tenant_id NULLS LAST, name ASC',
            ['tenant' => $tenantId],
        );

        return array_map(static function (array $r): array {
            $decoded = json_decode((string) $r['settings'], true);

            return [
                'id'          => (string) $r['id'],
                'name'        => (string) $r['name'],
                'description' => is_string($r['description'] ?? null) ? $r['description'] : null,
                'level'       => (string) $r['level'],
                'settings'    => is_array($decoded) ? $decoded : [],
                'isDefault'   => (bool) $r['is_default'],
                // Whether the platform ships it or the tenant made it.
                'isSystem'    => $r['tenant_id'] === null,
            ];
        }, $rows);
    }

    // --- sessions ---------------------------------------------------------

    public function findSession(string $tenantId, string $id): ?ProctoringSession
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM proctoring_sessions WHERE id = :id AND tenant_id = :tenant',
                self::SESSION_COLUMNS,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrateSession($row);
    }

    public function findSessionForAttempt(string $tenantId, string $attemptId): ?ProctoringSession
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM proctoring_sessions WHERE attempt_id = :attempt AND tenant_id = :tenant',
                self::SESSION_COLUMNS,
            ),
            ['attempt' => $attemptId, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrateSession($row);
    }

    public function openSession(string $tenantId, string $attemptId, string $userId): ProctoringSession
    {
        // Idempotent: reloading during the pre-flight checks must not split
        // the evidence across two sessions.
        $this->connection->executeStatement(
            'INSERT INTO proctoring_sessions (tenant_id, attempt_id, user_id)
             VALUES (:tenant, :attempt, :user)
             ON CONFLICT (attempt_id) DO NOTHING',
            ['tenant' => $tenantId, 'attempt' => $attemptId, 'user' => $userId],
        );

        return $this->findSessionForAttempt($tenantId, $attemptId)
            ?? throw new \RuntimeException('Proctoring session could not be read back.');
    }

    public function updateSession(string $tenantId, string $sessionId, array $changes): ProctoringSession
    {
        $sets = [];
        $params = ['id' => $sessionId, 'tenant' => $tenantId];

        foreach ($changes as $key => $value) {
            // Anything not on the allow-list is ignored, not written.
            if (!isset(self::WRITABLE[$key])) {
                continue;
            }

            $column = self::WRITABLE[$key];

            if ($key === 'systemCheckData') {
                $sets[] = sprintf('%s = :%s::jsonb', $column, $key);
                $params[$key] = $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR);

                continue;
            }

            $sets[] = sprintf('%s = :%s', $column, $key);
            $params[$key] = $value instanceof \DateTimeInterface
                ? $value->format(\DateTimeInterface::ATOM)
                : (is_bool($value) ? ($value ? 'true' : 'false') : $value);
        }

        if ($sets === []) {
            return $this->findSession($tenantId, $sessionId)
                ?? throw new \RuntimeException('Session not found.');
        }

        $this->connection->executeStatement(
            sprintf(
                'UPDATE proctoring_sessions SET %s, updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tenant',
                implode(', ', $sets),
            ),
            $params,
        );

        return $this->findSession($tenantId, $sessionId)
            ?? throw new \RuntimeException('Updated session could not be read back.');
    }

    // --- violations -------------------------------------------------------

    public function recordViolation(
        string $tenantId,
        ProctoringSession $session,
        ViolationType $type,
        ViolationSeverity $severity,
        ?string $description,
        ?string $questionId,
        array $event,
    ): array {
        /** @var array{violation: array<string, mixed>, trust: TrustScore, action: string} $result */
        $result = $this->connection->transactional(
            function (Connection $tx) use (
                $tenantId,
                $session,
                $type,
                $severity,
                $description,
                $questionId,
                $event,
            ): array {
                $action = $severity->endsTheSitting() ? 'terminated' : ($type->warnsTheLearner()
                    ? 'warning_shown'
                    : 'none');

                $id = (string) $tx->fetchOne(
                    'INSERT INTO proctoring_violations
                         (tenant_id, session_id, type, severity, score, description,
                          question_id, event_data, action_taken)
                     VALUES (:tenant, :session, :type, :severity, :score, :description,
                             :question, :event::jsonb, :action)
                     RETURNING id',
                    [
                        'tenant'      => $tenantId,
                        'session'     => $session->id,
                        'type'        => $type->value,
                        'severity'    => $severity->value,
                        'score'       => $severity->weight(),
                        'description' => $description,
                        'question'    => $questionId,
                        'event'       => $event === [] ? null : json_encode($event, JSON_THROW_ON_ERROR),
                        'action'      => $action,
                    ],
                );

                // A critical event ends the sitting there and then, in the
                // same transaction that records why.
                if ($severity->endsTheSitting()) {
                    $tx->executeStatement(
                        "UPDATE proctoring_sessions
                         SET status = 'terminated', ended_at = NOW(), updated_at = NOW()
                         WHERE id = :id AND tenant_id = :tenant",
                        ['id' => $session->id, 'tenant' => $tenantId],
                    );
                }

                return [
                    'violation' => [
                        'id'       => $id,
                        'type'     => $type->value,
                        'severity' => $severity->value,
                        'score'    => $severity->weight(),
                        'warnsLearner' => $type->warnsTheLearner(),
                    ],
                    'trust'  => $this->trustWithin($tx, $tenantId, $session->id),
                    'action' => $action,
                ];
            },
        );

        return $result;
    }

    public function violationsFor(string $tenantId, string $sessionId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, type, severity, score, description, question_id, snapshot_url,
                    event_data, action_taken, user_acknowledged, review_status, review_notes,
                    reviewed_by, reviewed_at, occurred_at
             FROM proctoring_violations
             WHERE session_id = :session AND tenant_id = :tenant
             ORDER BY occurred_at ASC',
            ['session' => $sessionId, 'tenant' => $tenantId],
        );

        return array_map(static function (array $r): array {
            $event = json_decode((string) ($r['event_data'] ?? 'null'), true);
            $type = ViolationType::tryFrom((string) $r['type']);

            return [
                'id'          => (string) $r['id'],
                'type'        => (string) $r['type'],
                'typeLabel'   => $type?->label() ?? (string) $r['type'],
                'severity'    => (string) $r['severity'],
                'score'       => (float) $r['score'],
                'description' => is_string($r['description'] ?? null) ? $r['description'] : null,
                'questionId'  => is_string($r['question_id'] ?? null) ? $r['question_id'] : null,
                'snapshotUrl' => is_string($r['snapshot_url'] ?? null) ? $r['snapshot_url'] : null,
                'eventData'   => is_array($event) ? $event : null,
                'actionTaken' => (string) $r['action_taken'],
                'acknowledged' => (bool) $r['user_acknowledged'],
                'reviewStatus' => (string) $r['review_status'],
                'reviewNotes'  => is_string($r['review_notes'] ?? null) ? $r['review_notes'] : null,
                'reviewedAt'   => is_string($r['reviewed_at'] ?? null) ? $r['reviewed_at'] : null,
                'occurredAt'   => (string) $r['occurred_at'],
            ];
        }, $rows);
    }

    public function trustFor(string $tenantId, string $sessionId): TrustScore
    {
        return $this->trustWithin($this->connection, $tenantId, $sessionId);
    }

    public function reviewViolation(
        string $tenantId,
        string $violationId,
        string $status,
        ?string $notes,
        string $reviewerId,
    ): bool {
        return $this->connection->executeStatement(
            'UPDATE proctoring_violations
             SET review_status = :status, review_notes = :notes,
                 reviewed_by = :reviewer, reviewed_at = NOW()
             WHERE id = :id AND tenant_id = :tenant',
            [
                'status'   => $status,
                'notes'    => $notes,
                'reviewer' => $reviewerId,
                'id'       => $violationId,
                'tenant'   => $tenantId,
            ],
        ) > 0;
    }

    public function reviewSession(
        string $tenantId,
        string $sessionId,
        string $status,
        ?string $notes,
        string $reviewerId,
    ): ?ProctoringSession {
        $affected = $this->connection->executeStatement(
            'UPDATE proctoring_sessions
             SET review_status = :status, review_notes = :notes,
                 reviewed_by = :reviewer, reviewed_at = NOW(), updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant',
            [
                'status'   => $status,
                'notes'    => $notes,
                'reviewer' => $reviewerId,
                'id'       => $sessionId,
                'tenant'   => $tenantId,
            ],
        );

        return $affected > 0 ? $this->findSession($tenantId, $sessionId) : null;
    }

    /**
     * A per-sitting breakdown: violations by type and by severity, the snapshot
     * tallies and the trust score — computed from the rows, never cached.
     *
     * @return array{
     *     violationCount: int, flaggedViolations: int,
     *     byType: list<array{type: string, count: int}>,
     *     bySeverity: list<array{severity: string, count: int}>,
     *     snapshotCount: int, flaggedSnapshots: int,
     *     trust: array<string, mixed>
     * }
     */
    public function sessionAnalytics(string $tenantId, string $sessionId): array
    {
        $counts = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE review_status = 'valid') AS flagged
             FROM proctoring_violations WHERE session_id = :session AND tenant_id = :tenant",
            ['session' => $sessionId, 'tenant' => $tenantId],
        ) ?: ['total' => 0, 'flagged' => 0];

        $snaps = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE flagged) AS flagged
             FROM proctoring_snapshots WHERE session_id = :session AND tenant_id = :tenant',
            ['session' => $sessionId, 'tenant' => $tenantId],
        ) ?: ['total' => 0, 'flagged' => 0];

        return [
            'violationCount'    => (int) $counts['total'],
            'flaggedViolations' => (int) $counts['flagged'],
            'byType'            => $this->violationGroup($tenantId, $sessionId, 'type'),
            'bySeverity'        => $this->violationGroup($tenantId, $sessionId, 'severity'),
            'snapshotCount'     => (int) $snaps['total'],
            'flaggedSnapshots'  => (int) $snaps['flagged'],
            'trust'             => $this->trustFor($tenantId, $sessionId)->toArray(),
        ];
    }

    /**
     * @return list<array{type?: string, severity?: string, count: int}>
     */
    private function violationGroup(string $tenantId, string $sessionId, string $column): array
    {
        // $column is a fixed literal chosen by the caller (never user input).
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %1$s AS key, COUNT(*) AS count FROM proctoring_violations
                 WHERE session_id = :session AND tenant_id = :tenant
                 GROUP BY %1$s ORDER BY count DESC',
                $column,
            ),
            ['session' => $sessionId, 'tenant' => $tenantId],
        );

        return array_map(
            static fn (array $r): array => [$column => (string) $r['key'], 'count' => (int) $r['count']],
            $rows,
        );
    }

    // --- snapshots --------------------------------------------------------

    public function recordSnapshot(string $tenantId, ProctoringSession $session, array $snapshot): string
    {
        return (string) $this->connection->fetchOne(
            'INSERT INTO proctoring_snapshots
                 (tenant_id, session_id, kind, image_url, thumbnail_url, file_size_bytes,
                  question_id, time_elapsed_seconds, face_detected, face_count, face_confidence,
                  face_position, gaze_direction, objects_detected, flagged, flag_reason)
             VALUES (:tenant, :session, :kind, :image, :thumbnail, :size,
                     :question, :elapsed, :faceDetected, :faceCount, :confidence,
                     :position::jsonb, :gaze::jsonb, :objects::jsonb, :flagged, :reason)
             RETURNING id',
            [
                'tenant'       => $tenantId,
                'session'      => $session->id,
                'kind'         => $snapshot['kind'] ?? 'periodic',
                'image'        => $snapshot['imageUrl'],
                'thumbnail'    => $snapshot['thumbnailUrl'] ?? null,
                'size'         => $snapshot['fileSizeBytes'] ?? null,
                'question'     => $snapshot['questionId'] ?? null,
                'elapsed'      => $snapshot['timeElapsedSeconds'] ?? 0,
                'faceDetected' => $this->boolOrNull($snapshot['faceDetected'] ?? null),
                'faceCount'    => $snapshot['faceCount'] ?? null,
                'confidence'   => $snapshot['faceConfidence'] ?? null,
                'position'     => isset($snapshot['facePosition'])
                    ? json_encode($snapshot['facePosition'], JSON_THROW_ON_ERROR)
                    : null,
                'gaze' => isset($snapshot['gazeDirection'])
                    ? json_encode($snapshot['gazeDirection'], JSON_THROW_ON_ERROR)
                    : null,
                'objects' => isset($snapshot['objectsDetected'])
                    ? json_encode($snapshot['objectsDetected'], JSON_THROW_ON_ERROR)
                    : null,
                'flagged' => ($snapshot['flagged'] ?? false) ? 'true' : 'false',
                'reason'  => $snapshot['flagReason'] ?? null,
            ],
        );
    }

    public function snapshotsFor(string $tenantId, string $sessionId, bool $flaggedOnly = false): array
    {
        $sql = 'SELECT id, kind, image_url, thumbnail_url, question_id, time_elapsed_seconds,
                       face_detected, face_count, face_confidence, objects_detected,
                       flagged, flag_reason, captured_at
                FROM proctoring_snapshots
                WHERE session_id = :session AND tenant_id = :tenant';

        if ($flaggedOnly) {
            $sql .= ' AND flagged';
        }

        $rows = $this->connection->fetchAllAssociative(
            $sql . ' ORDER BY captured_at ASC',
            ['session' => $sessionId, 'tenant' => $tenantId],
        );

        return array_map(static function (array $r): array {
            $objects = json_decode((string) ($r['objects_detected'] ?? 'null'), true);

            return [
                'id'            => (string) $r['id'],
                'kind'          => (string) $r['kind'],
                'imageUrl'      => (string) $r['image_url'],
                'thumbnailUrl'  => is_string($r['thumbnail_url'] ?? null) ? $r['thumbnail_url'] : null,
                'questionId'    => is_string($r['question_id'] ?? null) ? $r['question_id'] : null,
                'timeElapsedSeconds' => (int) $r['time_elapsed_seconds'],
                'faceDetected'  => $r['face_detected'] === null ? null : (bool) $r['face_detected'],
                'faceCount'     => $r['face_count'] === null ? null : (int) $r['face_count'],
                'faceConfidence' => $r['face_confidence'] === null ? null : (float) $r['face_confidence'],
                'objectsDetected' => is_array($objects) ? $objects : null,
                'flagged'       => (bool) $r['flagged'],
                'flagReason'    => is_string($r['flag_reason'] ?? null) ? $r['flag_reason'] : null,
                'capturedAt'    => (string) $r['captured_at'],
            ];
        }, $rows);
    }

    public function flagSnapshot(string $tenantId, string $snapshotId, bool $flagged, ?string $reason): bool
    {
        return $this->connection->executeStatement(
            'UPDATE proctoring_snapshots
             SET flagged = :flagged, flag_reason = :reason
             WHERE id = :id AND tenant_id = :tenant',
            [
                'flagged' => $flagged ? 'true' : 'false',
                // Clearing a flag also clears its reason, so a stale note cannot
                // linger on an unflagged frame.
                'reason'  => $flagged ? $reason : null,
                'id'      => $snapshotId,
                'tenant'  => $tenantId,
            ],
        ) > 0;
    }

    public function findSnapshotSession(string $tenantId, string $snapshotId): ?string
    {
        $sessionId = $this->connection->fetchOne(
            'SELECT session_id FROM proctoring_snapshots WHERE id = :id AND tenant_id = :tenant',
            ['id' => $snapshotId, 'tenant' => $tenantId],
        );

        return is_string($sessionId) ? $sessionId : null;
    }

    public function reviewQueue(
        string $tenantId,
        ?string $assessmentId,
        int $page,
        int $perPage,
        bool $pendingOnly = false,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $where = 'ps.tenant_id = :tenant';
        $params = ['tenant' => $tenantId];

        if ($assessmentId !== null) {
            $where .= ' AND at.assessment_id = :assessment';
            $params['assessment'] = $assessmentId;
        }

        if ($pendingOnly) {
            $where .= ' AND ps.reviewed_at IS NULL';
        }

        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM proctoring_sessions ps
             JOIN attempts at ON at.id = ps.attempt_id
             WHERE ' . $where,
            $params,
        );

        // Ordered by what the violations add up to, worst first, so the queue
        // opens on the sitting most in need of a person.
        $rows = $this->connection->fetchAllAssociative(
            "SELECT ps.id, ps.attempt_id, ps.user_id, ps.status, ps.reviewed_at,
                    NULLIF(btrim(concat_ws(' ', u.first_name, u.last_name)), '') AS user_name,
                    COALESCE(v.penalty, 0) AS penalty,
                    COALESCE(v.total, 0) AS violation_count,
                    COALESCE(v.critical, 0) AS critical_count
             FROM proctoring_sessions ps
             JOIN attempts at ON at.id = ps.attempt_id
             LEFT JOIN users u ON u.id = ps.user_id
             LEFT JOIN (
                 SELECT session_id,
                        SUM(score) FILTER (WHERE review_status <> 'false_positive') AS penalty,
                        COUNT(*) FILTER (WHERE review_status <> 'false_positive') AS total,
                        COUNT(*) FILTER (
                            WHERE severity = 'critical' AND review_status <> 'false_positive'
                        ) AS critical
                 FROM proctoring_violations
                 GROUP BY session_id
             ) v ON v.session_id = ps.id
             WHERE " . $where . '
             ORDER BY critical_count DESC, penalty DESC, ps.created_at DESC
             LIMIT :limit OFFSET :offset',
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        $items = array_map(static function (array $r): array {
            $score = max(0.0, round(100.0 - (float) $r['penalty'], 2));

            return [
                'sessionId'      => (string) $r['id'],
                'attemptId'      => (string) $r['attempt_id'],
                'userId'         => (string) $r['user_id'],
                'userName'       => is_string($r['user_name'] ?? null) ? $r['user_name'] : null,
                'status'         => (string) $r['status'],
                'trustScore'     => $score,
                'violationCount' => (int) $r['violation_count'],
                'criticalCount'  => (int) $r['critical_count'],
                'reviewed'       => $r['reviewed_at'] !== null,
            ];
        }, $rows);

        return ['items' => $items, 'total' => $total];
    }

    // --- internals --------------------------------------------------------

    private function trustWithin(Connection $connection, string $tenantId, string $sessionId): TrustScore
    {
        $rows = $connection->fetchAllAssociative(
            'SELECT severity, score, review_status FROM proctoring_violations
             WHERE session_id = :session AND tenant_id = :tenant',
            ['session' => $sessionId, 'tenant' => $tenantId],
        );

        return TrustScore::fromViolations(array_map(static fn (array $r): array => [
            'severity'     => ViolationSeverity::fromLegacy((string) $r['severity']),
            'score'        => (float) $r['score'],
            'reviewStatus' => (string) $r['review_status'],
        ], $rows));
    }

    private function boolOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL) ? 'true' : 'false';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateSession(array $row): ProctoringSession
    {
        $checkData = json_decode((string) ($row['system_check_data'] ?? 'null'), true);

        return new ProctoringSession(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            attemptId: (string) $row['attempt_id'],
            userId: (string) $row['user_id'],
            status: SessionStatus::fromLegacy((string) $row['status']),
            systemCheckPassed: $row['system_check_passed'] === null ? null : (bool) $row['system_check_passed'],
            systemCheckAt: $this->date($row['system_check_at'] ?? null),
            systemCheckData: is_array($checkData) ? $checkData : null,
            idVerified: $row['id_verified'] === null ? null : (bool) $row['id_verified'],
            idVerificationAt: $this->date($row['id_verification_at'] ?? null),
            idPhotoUrl: $this->text($row['id_photo_url'] ?? null),
            facePhotoUrl: $this->text($row['face_photo_url'] ?? null),
            faceMatchScore: $row['face_match_score'] === null ? null : (float) $row['face_match_score'],
            roomScanCompleted: $row['room_scan_completed'] === null ? null : (bool) $row['room_scan_completed'],
            roomScanVideoUrl: $this->text($row['room_scan_video_url'] ?? null),
            screenRecordingUrl: $this->text($row['screen_recording_url'] ?? null),
            webcamRecordingUrl: $this->text($row['webcam_recording_url'] ?? null),
            startedAt: $this->date($row['started_at'] ?? null),
            endedAt: $this->date($row['ended_at'] ?? null),
            totalActiveSeconds: (int) $row['total_active_seconds'],
            reviewedBy: $this->text($row['reviewed_by'] ?? null),
            reviewedAt: $this->date($row['reviewed_at'] ?? null),
            reviewNotes: $this->text($row['review_notes'] ?? null),
            reviewStatus: (string) ($row['review_status'] ?? 'pending'),
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
