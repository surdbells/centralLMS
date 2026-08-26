<?php

declare(strict_types=1);

namespace Central\Api\Domain\Certificate;

/**
 * What earned a certificate.
 *
 * The legacy had two certificate families — one bound to a course, one to an
 * assessment attempt — describing the same object. This is the column that
 * lets one table serve all three without either family's shape leaking into
 * the other.
 */
enum CertificateSource: string
{
    case Course = 'course';
    case Training = 'training';
    case Assessment = 'assessment';

    public static function fromLegacy(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Course;
    }

    /**
     * Whether a score belongs on it.
     *
     * A course or assessment certificate can carry a mark; an attendance-based
     * training certificate generally cannot, and printing "0%" on one because
     * the column was NULL is worse than printing nothing.
     */
    public function carriesScore(): bool
    {
        return $this !== self::Training;
    }

    public function label(): string
    {
        return match ($this) {
            self::Course     => 'Course completion',
            self::Training   => 'Training attendance',
            self::Assessment => 'Assessment result',
        };
    }
}
