<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

/**
 * When a learner may see the correct answers.
 *
 * The generic side stored this as a boolean (`show_correct_answers tinyint(1)`),
 * which cannot express "after the deadline" — so on a course quiz with several
 * attempts, showing answers at all meant handing the answer key to anyone
 * willing to submit once. The proctored side already had the four-value form;
 * that becomes the unified one, and the legacy boolean folds in safely.
 */
enum RevealAnswers: string
{
    case Never            = 'never';
    case Immediately      = 'immediately';
    case AfterDeadline    = 'after_deadline';
    case AfterAllAttempts = 'after_all_attempts';

    /**
     * @param bool|int|string|null $legacyFlag the generic tinyint(1) column
     */
    public static function fromLegacyFlag(bool|int|string|null $legacyFlag): self
    {
        $enabled = filter_var($legacyFlag, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;

        // Deliberately conservative: the old boolean meant "show them", with no
        // way to say when. Mapping true to AfterAllAttempts preserves the
        // intent without leaking the answer key mid-course. Authors who want
        // the old immediate behaviour can opt back in explicitly.
        return $enabled ? self::AfterAllAttempts : self::Never;
    }

    public static function fromLegacy(string $value): self
    {
        return self::tryFrom(strtolower(trim($value)))
            ?? throw new \ValueError(sprintf('Unknown legacy reveal-answers value "%s".', $value));
    }

    /**
     * @param bool $deadlinePassed   the assessment's `available_until` has passed
     * @param bool $attemptsExhausted the learner has used every allowed attempt
     */
    public function allowsRevealNow(bool $deadlinePassed, bool $attemptsExhausted): bool
    {
        return match ($this) {
            self::Never            => false,
            self::Immediately      => true,
            self::AfterDeadline    => $deadlinePassed,
            self::AfterAllAttempts => $attemptsExhausted || $deadlinePassed,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Never            => 'Never',
            self::Immediately      => 'Immediately after submitting',
            self::AfterDeadline    => 'After the deadline',
            self::AfterAllAttempts => 'After all attempts are used',
        };
    }
}
