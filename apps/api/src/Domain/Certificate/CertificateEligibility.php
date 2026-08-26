<?php

declare(strict_types=1);

namespace Central\Api\Domain\Certificate;

/**
 * Whether a learner earned a certificate, and what it should say.
 *
 * The answer comes from the module that owns the thing — courses, training or
 * assessments — rather than from the request. Issuing is not a write of
 * whatever a caller sends: a certificate that can be conjured by POSTing an id
 * and a name is not evidence of anything, and a verification endpoint that
 * vouches for one is worse than having none.
 */
final readonly class CertificateEligibility
{
    private function __construct(
        public bool $eligible,
        public string $title,
        public string $learnerName,
        public ?string $reason = null,
        public ?float $score = null,
        public ?float $percentage = null,
        public ?string $attemptId = null,
        public ?string $enrollmentId = null,
    ) {
    }

    public static function earned(
        string $title,
        string $learnerName,
        ?float $score = null,
        ?float $percentage = null,
        ?string $attemptId = null,
        ?string $enrollmentId = null,
    ): self {
        return new self(
            eligible: true,
            title: $title,
            learnerName: $learnerName,
            score: $score,
            percentage: $percentage,
            attemptId: $attemptId,
            enrollmentId: $enrollmentId,
        );
    }

    /**
     * Not earned, and why.
     *
     * The reason is shown to whoever tried to issue it, so it says what is
     * actually missing — "they have completed 40% of the course" rather than
     * "not eligible".
     */
    public static function notEarned(
        string $title,
        string $learnerName,
        string $reason,
        ?float $percentage = null,
        ?string $enrollmentId = null,
    ): self {
        return new self(
            eligible: false,
            title: $title,
            learnerName: $learnerName,
            reason: $reason,
            percentage: $percentage,
            enrollmentId: $enrollmentId,
        );
    }
}
