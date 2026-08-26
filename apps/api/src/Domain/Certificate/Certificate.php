<?php

declare(strict_types=1);

namespace Central\Api\Domain\Certificate;

/**
 * One issued certificate.
 *
 * The learner's name and the title are held on the row rather than joined,
 * deliberately. A certificate is a record of a moment: if the holder changes
 * their name or an author retitles the course, what was issued last year must
 * still read as it did. Everywhere else in this schema that would be a
 * shortcut; here it is the requirement.
 */
final readonly class Certificate
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public CertificateIdentity $identity,
        public CertificateSource $source,
        public string $userId,
        public string $learnerName,
        public string $title,
        public CertificateStatus $status = CertificateStatus::Active,
        public ?string $learnerEmail = null,
        public ?string $templateId = null,
        public ?string $courseId = null,
        public ?string $trainingId = null,
        public ?string $assessmentId = null,
        public ?string $attemptId = null,
        public ?string $enrollmentId = null,
        public ?float $score = null,
        public ?float $percentage = null,
        public ?string $grade = null,
        /** @var array<string, mixed> */
        public array $customData = [],
        public ?string $pdfUrl = null,
        public ?\DateTimeImmutable $issuedAt = null,
        public ?\DateTimeImmutable $validFrom = null,
        public ?\DateTimeImmutable $validUntil = null,
        public ?\DateTimeImmutable $revokedAt = null,
        public ?string $revocationReason = null,
        /** Counted from the event log, never stored. */
        public int $downloadCount = 0,
        public int $verificationCount = 0,
    ) {
    }

    /**
     * Whether it has passed its validity date.
     *
     * Computed rather than stored: the legacy `expired` column duplicated both
     * `status` and `valid_until`, and a row only becomes stale the moment
     * nobody is looking at it.
     */
    public function hasLapsed(\DateTimeImmutable $now): bool
    {
        return $this->validUntil !== null && $now->setTime(0, 0) > $this->validUntil->setTime(0, 0);
    }

    /**
     * The status a verifier should be told, taking the date into account.
     *
     * An active certificate past its date is expired in substance even if a
     * sweep has not yet marked it so, and a verifier must be told the truth
     * rather than whatever the last cron run left behind.
     */
    public function effectiveStatus(\DateTimeImmutable $now): CertificateStatus
    {
        if ($this->status === CertificateStatus::Active && $this->hasLapsed($now)) {
            return CertificateStatus::Expired;
        }

        return $this->status;
    }

    public function isValid(\DateTimeImmutable $now): bool
    {
        return $this->effectiveStatus($now)->isValid();
    }

    /**
     * Days until it lapses; negative once it has. Null when it never does.
     */
    public function daysRemaining(\DateTimeImmutable $now): ?int
    {
        if ($this->validUntil === null) {
            return null;
        }

        return (int) $now->setTime(0, 0)->diff($this->validUntil->setTime(0, 0))->format('%r%a');
    }

    /**
     * The values a template may use.
     *
     * @return array<string, mixed>
     */
    public function placeholders(string $verificationUrl, ?string $organisation = null): array
    {
        return [
            'learner_name'       => $this->learnerName,
            'learner_email'      => $this->learnerEmail,
            'title'              => $this->title,
            'certificate_number' => $this->identity->number,
            'verification_code'  => $this->identity->code,
            'verification_url'   => $verificationUrl,
            'issue_date'         => $this->issuedAt?->format('j F Y'),
            'valid_from'         => $this->validFrom?->format('j F Y'),
            'valid_until'        => $this->validUntil?->format('j F Y'),
            // Suppressed for attendance certificates, where a score would be
            // meaningless and "0%" actively misleading.
            'score'      => $this->source->carriesScore() ? $this->score : null,
            'percentage' => $this->source->carriesScore() && $this->percentage !== null
                ? rtrim(rtrim(number_format($this->percentage, 2, '.', ''), '0'), '.') . '%'
                : null,
            'grade'        => $this->grade,
            'organisation' => $organisation,
            'source'       => $this->source->label(),
            'custom'       => $this->customData,
        ];
    }

    /**
     * The holder's and issuer's view.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function toArray(array $extra = []): array
    {
        $now = new \DateTimeImmutable();
        $effective = $this->effectiveStatus($now);

        return [
            'id'     => $this->id,
            'number' => $this->identity->number,
            'verificationCode' => $this->identity->code,
            'source'        => $this->source->value,
            'sourceLabel'   => $this->source->label(),
            'courseId'      => $this->courseId,
            'trainingId'    => $this->trainingId,
            'assessmentId'  => $this->assessmentId,
            'userId'        => $this->userId,
            'learnerName'   => $this->learnerName,
            'learnerEmail'  => $this->learnerEmail,
            'title'         => $this->title,
            'score'         => $this->source->carriesScore() ? $this->score : null,
            'percentage'    => $this->source->carriesScore() ? $this->percentage : null,
            'grade'         => $this->grade,
            'status'        => $effective->value,
            'statusLabel'   => $effective->label(),
            'isValid'       => $effective->isValid(),
            'pdfUrl'        => $this->pdfUrl,
            'issuedAt'      => $this->issuedAt?->format(\DateTimeInterface::ATOM),
            'validFrom'     => $this->validFrom?->format('Y-m-d'),
            'validUntil'    => $this->validUntil?->format('Y-m-d'),
            'daysRemaining' => $this->daysRemaining($now),
            'revokedAt'        => $this->revokedAt?->format(\DateTimeInterface::ATOM),
            'revocationReason' => $this->revocationReason,
            'downloadCount'     => $this->downloadCount,
            'verificationCount' => $this->verificationCount,
            'customData'        => $this->customData,
            ...$extra,
        ];
    }

    /**
     * What a stranger checking a certificate is told.
     *
     * Deliberately narrow. Someone verifying needs to know that the named
     * person holds this certificate for this thing and whether it stands —
     * not the holder's email address, their score, or anything else that
     * turns a verification endpoint into a directory.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(\DateTimeImmutable $now): array
    {
        $effective = $this->effectiveStatus($now);

        return [
            'number'      => $this->identity->number,
            'learnerName' => $this->learnerName,
            'title'       => $this->title,
            'source'      => $this->source->label(),
            'issuedAt'    => $this->issuedAt?->format('Y-m-d'),
            'validUntil'  => $this->validUntil?->format('Y-m-d'),
            'status'      => $effective->value,
            'isValid'     => $effective->isValid(),
            'message'     => $effective->verificationMessage(),
        ];
    }
}
