<?php

declare(strict_types=1);

namespace Central\Api\Domain\Enrollment;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * One learner's progress through one lesson.
 */
final readonly class LessonProgress
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $enrollmentId,
        public string $userId,
        public string $lessonId,
        public ProgressStatus $status = ProgressStatus::NotStarted,
        public float $progressPercentage = 0.0,
        public ?float $score = null,
        public int $timeSpentSeconds = 0,
        public int $lastPositionSeconds = 0,
        public ?\DateTimeImmutable $completedAt = null,
        public ?\DateTimeImmutable $lastActivityAt = null,
        public ?\DateTimeImmutable $createdAt = null,
        /**
         * The SCORM runtime state the package last committed, or null for a
         * lesson that is not SCORM (or has never committed anything).
         *
         * @var array{location: string, suspendData: string, lessonStatus: string, exit: string, totalTime: string}|null
         */
        public ?array $scorm = null,
    ) {
    }

    /**
     * The SCORM keys a player may store, and the most each may hold.
     *
     * A whitelist rather than "whatever the CMI map contains": the wrapper's
     * map has learner-supplied free text in it (interactions, comments), and
     * only these five are needed to put a learner back where they left off.
     * suspendData is capped at the SCORM 2004 limit (64000; 1.2 allows 4096),
     * location at 1000, the 2004 `cmi.location` limit; the rest are short
     * vocabulary values or ISO durations.
     */
    public const SCORM_LIMITS = [
        'location'     => 1000,
        'suspendData'  => 64000,
        'lessonStatus' => 64,
        'exit'         => 64,
        'totalTime'    => 64,
    ];

    /**
     * Validates a progress report from a player.
     *
     * Returns the accepted values rather than an entity: the row may not exist
     * yet, and whether it does is the repository's business.
     *
     * @param array<string, mixed> $input
     *
     * @return array{
     *     percentage: float,
     *     status: ProgressStatus,
     *     score: ?float,
     *     secondsToAdd: int,
     *     position: ?int,
     *     scorm: ?array<string, string>
     * }
     *
     * @throws ValidationFailed
     */
    public static function reportFromInput(array $input): array
    {
        $violations = [];

        $percentage = null;

        if (array_key_exists('progressPercentage', $input) && $input['progressPercentage'] !== '') {
            $percentage = (float) $input['progressPercentage'];

            if ($percentage < 0 || $percentage > 100) {
                $violations['progressPercentage'] = 'Progress must be between 0 and 100.';
            }
        }

        // `completed: true` is the shorthand a player sends when a video ends.
        if (array_key_exists('completed', $input) && filter_var($input['completed'], FILTER_VALIDATE_BOOL)) {
            $percentage = 100.0;
        }

        $score = null;

        if (array_key_exists('score', $input) && $input['score'] !== null && $input['score'] !== '') {
            $score = (float) $input['score'];

            if ($score < 0 || $score > 100) {
                $violations['score'] = 'A score must be between 0 and 100.';
            }
        }

        // Time is sent as an increment, not a total. A player that reports a
        // running total loses everything if the page reloads, and one that
        // reports the same total twice would double it.
        $secondsToAdd = (int) ($input['secondsSpent'] ?? 0);

        if ($secondsToAdd < 0) {
            $violations['secondsSpent'] = 'Time spent cannot be negative.';
        }

        if ($secondsToAdd > self::MAX_REPORTED_SECONDS) {
            // Twelve hours in one report is a broken clock or a forged
            // request, not a study session.
            $violations['secondsSpent'] = 'That is more time than a single session can contain.';
        }

        $position = null;

        // null counts as absent too: a client that serialises "no position"
        // as null must not rewind the stored position to zero.
        if (
            array_key_exists('positionSeconds', $input)
            && $input['positionSeconds'] !== ''
            && $input['positionSeconds'] !== null
        ) {
            $position = (int) $input['positionSeconds'];

            if ($position < 0) {
                $violations['positionSeconds'] = 'Position cannot be negative.';
            }
        }

        $scorm = self::scormFromInput($input, $violations);

        // A SCORM commit on its own (the wrapper's pagehide flush, say) is a
        // report of where the learner is, not of how far through they are.
        // Zero is safe: the repository never lets a percentage go backwards.
        if ($percentage === null && $scorm !== null) {
            $percentage = 0.0;
        }

        if ($percentage === null) {
            $violations['progressPercentage'] = 'Send a percentage, or completed: true.';
        }

        if ($violations !== [] || $percentage === null) {
            throw ValidationFailed::fromArray($violations);
        }

        // Derived, never taken from the request: percentage and status are
        // two views of one fact, and the legacy rows where they disagree are
        // what this prevents.
        $status = ProgressStatus::fromPercentage($percentage);

        // ...with one widening. A lesson the learner has demonstrably been in
        // (time on it, a playback position, a SCORM commit) has been started,
        // even at 0%. Video heartbeats and a SCORM package sitting at
        // 'incomplete' rarely report a percentage, and calling those lessons
        // "not started" is what left opened courses on the nudge list.
        if (
            $status === ProgressStatus::NotStarted
            && ($secondsToAdd > 0 || ($position ?? 0) > 0 || $scorm !== null)
        ) {
            $status = ProgressStatus::InProgress;
        }

        return [
            'percentage'   => $percentage,
            'status'       => $status,
            'score'        => $score,
            'secondsToAdd' => $secondsToAdd,
            'position'     => $position,
            'scorm'        => $scorm,
        ];
    }

    /**
     * The optional `scorm` object of a progress report: only the whitelisted
     * keys, each a string within its limit.
     *
     * Returns only the keys that were sent, so the repository can merge them
     * over the stored state: a report that carries just a status must not
     * wipe the suspend data the previous commit saved. Null when the report
     * carries no SCORM state at all.
     *
     * @param array<string, mixed>  $input
     * @param array<string, string> $violations
     *
     * @return array<string, string>|null
     */
    private static function scormFromInput(array $input, array &$violations): ?array
    {
        if (!array_key_exists('scorm', $input) || $input['scorm'] === null) {
            return null;
        }

        $raw = $input['scorm'];

        if (!is_array($raw)) {
            $violations['scorm'] = 'SCORM state must be an object.';

            return null;
        }

        $scorm = [];

        foreach (self::SCORM_LIMITS as $key => $limit) {
            $value = $raw[$key] ?? null;

            if ($value === null) {
                continue;
            }

            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                $violations['scorm.' . $key] = 'Must be a string.';

                continue;
            }

            // NUL is the one character PostgreSQL's jsonb cannot store; a
            // package that writes one would otherwise lose the whole commit.
            $value = str_replace("\0", '', (string) $value);

            if (!mb_check_encoding($value, 'UTF-8')) {
                $violations['scorm.' . $key] = 'Must be valid UTF-8 text.';

                continue;
            }

            if (mb_strlen($value) > $limit) {
                $violations['scorm.' . $key] = sprintf('Must be at most %d characters.', $limit);

                continue;
            }

            $scorm[$key] = $value;
        }

        // An object with none of the keys kept is no state at all.
        return $scorm === [] ? null : $scorm;
    }

    /** Twelve hours. */
    private const MAX_REPORTED_SECONDS = 43200;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'                  => $this->id,
            'lessonId'            => $this->lessonId,
            'status'              => $this->status->value,
            'statusLabel'         => $this->status->label(),
            'progressPercentage'  => $this->progressPercentage,
            'score'               => $this->score,
            'timeSpentSeconds'    => $this->timeSpentSeconds,
            'lastPositionSeconds' => $this->lastPositionSeconds,
            'completedAt'         => $this->completedAt?->format(\DateTimeInterface::ATOM),
            'lastActivityAt'      => $this->lastActivityAt?->format(\DateTimeInterface::ATOM),
            // What the SCORM wrapper is seeded with on the next launch, so the
            // package resumes where the learner left it instead of restarting.
            'scorm'               => $this->scorm,
        ];
    }
}
