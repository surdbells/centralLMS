<?php

declare(strict_types=1);

namespace Central\Api\Application\Gamification;

use Central\Api\Domain\Gamification\Badge;
use Central\Api\Domain\Gamification\GamificationRepository;
use Central\Api\Domain\Gamification\PointEvent;
use Central\Api\Domain\Gamification\PointReason;
use Psr\Log\LoggerInterface;

/**
 * The one way anything grants points or badges.
 *
 * Every other module calls this and nothing else, the same arrangement as
 * {@see \Central\Api\Application\Notification\Notifier} — and for the same
 * reason. In the legacy, points were written from `Gamification`, `Grading`,
 * `MyLearning` and `tracking.php`, each with its own idea of what a course
 * completion was worth and its own spelling of the type.
 *
 * **Awarding never throws.** A course completion that cannot grant points must
 * still be a completed course. Callers are mid-transaction on something that
 * matters more, so failures are logged and swallowed.
 *
 * **Awarding twice is safe.** A unique index covers `(user, reason, subject)`,
 * so a retried request or a double-click cannot pay twice, and the caller does
 * not have to check first.
 */
final readonly class Awarder
{
    public function __construct(
        private GamificationRepository $gamification,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Grants the points a reason is worth.
     *
     * Returns the event, or null when nothing was granted — already paid,
     * or the write failed.
     */
    public function award(
        string $tenantId,
        string $userId,
        PointReason $reason,
        ?string $subjectId = null,
        ?string $description = null,
    ): ?PointEvent {
        try {
            return $this->gamification->award(
                PointEvent::earn($tenantId, $userId, $reason, $subjectId, description: $description),
            );
        } catch (\Throwable $e) {
            $this->logger->error('Could not award points', [
                'user'   => $userId,
                'reason' => $reason->value,
                'error'  => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Grants any badges this course completion now earns.
     *
     * Two kinds qualify: a badge tied to the course itself, and any milestone
     * whose criteria the person's totals now meet.
     *
     * @return list<Badge> the ones actually awarded
     */
    public function checkCourseBadges(string $tenantId, string $userId, string $courseId): array
    {
        try {
            $awarded = [];

            foreach ($this->gamification->badges($tenantId, 1, 200)['items'] as $badge) {
                if (!$this->qualifies($badge, $courseId)) {
                    continue;
                }

                if ($this->gamification->awardBadge($tenantId, $userId, (string) $badge->id, null, $courseId)) {
                    $awarded[] = $badge;
                }
            }

            return $awarded;
        } catch (\Throwable $e) {
            $this->logger->error('Could not check course badges', [
                'user'   => $userId,
                'course' => $courseId,
                'error'  => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Whether finishing this course earns this badge.
     *
     * Only the course-tied case is decided here. A milestone badge —
     * "ten courses", "a thousand points" — needs totals the caller does not
     * have, and evaluating criteria generically is a rules engine; until
     * something asks for one, those badges are awarded by hand and say so
     * through {@see Badge::isAutomatic()}.
     */
    private function qualifies(Badge $badge, string $courseId): bool
    {
        return $badge->courseId === $courseId;
    }
}
