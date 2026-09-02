<?php

declare(strict_types=1);

namespace Central\Api\Domain\Proctoring;

/**
 * One proctored sitting.
 *
 * The counters the legacy table kept alongside this — total violations, trust
 * score, per-type tallies — are not here. They are computed from the violation
 * rows by {@see TrustScore}, because a cached count is one more thing that can
 * contradict what it counts.
 */
final readonly class ProctoringSession
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $attemptId,
        public string $userId,
        public SessionStatus $status = SessionStatus::PendingCheck,
        public ?bool $systemCheckPassed = null,
        public ?\DateTimeImmutable $systemCheckAt = null,
        /** @var array<string, mixed>|null */
        public ?array $systemCheckData = null,
        public ?bool $idVerified = null,
        public ?\DateTimeImmutable $idVerificationAt = null,
        public ?string $idPhotoUrl = null,
        public ?string $facePhotoUrl = null,
        public ?float $faceMatchScore = null,
        public ?bool $roomScanCompleted = null,
        public ?string $roomScanVideoUrl = null,
        public ?string $screenRecordingUrl = null,
        public ?string $webcamRecordingUrl = null,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $endedAt = null,
        public int $totalActiveSeconds = 0,
        public ?string $reviewedBy = null,
        public ?\DateTimeImmutable $reviewedAt = null,
        public ?string $reviewNotes = null,
        public string $reviewStatus = 'pending',
    ) {
    }

    /**
     * Whether the sitting may proceed.
     */
    public function isLive(): bool
    {
        return $this->status === SessionStatus::Active;
    }

    /**
     * What still has to happen before the learner may start.
     *
     * Returned as a list of outstanding steps rather than a boolean, so the
     * client can show the one thing that is missing instead of "not ready".
     *
     * @return list<string>
     */
    public function outstandingChecks(ProctoringSettings $settings): array
    {
        $outstanding = [];

        if ($this->systemCheckPassed !== true) {
            $outstanding[] = 'system_check';
        }

        if ($settings->requireIdVerification && $this->idVerified !== true) {
            $outstanding[] = 'id_verification';
        }

        if ($settings->requireRoomScan && $this->roomScanCompleted !== true) {
            $outstanding[] = 'room_scan';
        }

        return $outstanding;
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function toArray(array $extra = []): array
    {
        return [
            'id'          => $this->id,
            'attemptId'   => $this->attemptId,
            'userId'      => $this->userId,
            'status'      => $this->status->value,
            'statusLabel' => $this->status->label(),
            'systemCheckPassed' => $this->systemCheckPassed,
            'systemCheckAt'     => $this->systemCheckAt?->format(\DateTimeInterface::ATOM),
            'idVerified'        => $this->idVerified,
            'idVerificationAt'  => $this->idVerificationAt?->format(\DateTimeInterface::ATOM),
            'faceMatchScore'    => $this->faceMatchScore,
            'roomScanCompleted' => $this->roomScanCompleted,
            'startedAt'         => $this->startedAt?->format(\DateTimeInterface::ATOM),
            'endedAt'           => $this->endedAt?->format(\DateTimeInterface::ATOM),
            'totalActiveSeconds' => $this->totalActiveSeconds,
            'reviewedBy'   => $this->reviewedBy,
            'reviewedAt'   => $this->reviewedAt?->format(\DateTimeInterface::ATOM),
            'reviewNotes'  => $this->reviewNotes,
            'reviewStatus' => $this->reviewStatus,
            ...$extra,
        ];
    }

    /**
     * The payload a reviewer sees, which adds the recordings.
     *
     * Separate from `toArray()` so a learner-facing response cannot hand back
     * the URL of their own webcam recording by accident.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function toArrayForReview(array $extra = []): array
    {
        return $this->toArray([
            'idPhotoUrl'         => $this->idPhotoUrl,
            'facePhotoUrl'       => $this->facePhotoUrl,
            'roomScanVideoUrl'   => $this->roomScanVideoUrl,
            'screenRecordingUrl' => $this->screenRecordingUrl,
            'webcamRecordingUrl' => $this->webcamRecordingUrl,
            'systemCheckData'    => $this->systemCheckData,
            ...$extra,
        ]);
    }
}
