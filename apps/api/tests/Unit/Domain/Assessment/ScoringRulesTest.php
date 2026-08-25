<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Assessment;

use Central\Api\Domain\Assessment\PassingType;
use Central\Api\Domain\Assessment\RevealAnswers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The two rules a learner will notice immediately if they are wrong: whether
 * they passed, and whether they can see the answers.
 */
#[CoversClass(PassingType::class)]
#[CoversClass(RevealAnswers::class)]
final class ScoringRulesTest extends TestCase
{
    #[Test]
    public function percentage_passing_compares_against_the_maximum(): void
    {
        $type = PassingType::Percentage;

        self::assertTrue($type->isPassed(rawScore: 70.0, maxScore: 100.0, threshold: 70.0));
        self::assertFalse($type->isPassed(rawScore: 69.5, maxScore: 100.0, threshold: 70.0));
        // The boundary is inclusive: exactly the pass mark is a pass.
        self::assertTrue($type->isPassed(rawScore: 35.0, maxScore: 50.0, threshold: 70.0));
    }

    #[Test]
    public function points_passing_ignores_the_maximum(): void
    {
        $type = PassingType::Points;

        self::assertTrue($type->isPassed(rawScore: 40.0, maxScore: 100.0, threshold: 40.0));
        self::assertFalse($type->isPassed(rawScore: 39.0, maxScore: 100.0, threshold: 40.0));
        // Same points, different maximum — still a pass.
        self::assertTrue($type->isPassed(rawScore: 40.0, maxScore: 400.0, threshold: 40.0));
    }

    #[Test]
    public function a_zero_point_assessment_cannot_fail_anyone(): void
    {
        // Surveys and feedback forms carry no points. Dividing by the maximum
        // would be a fatal; treating it as 0% would fail every respondent.
        self::assertTrue(
            PassingType::Percentage->isPassed(rawScore: 0.0, maxScore: 0.0, threshold: 70.0),
        );
    }

    #[Test]
    public function answers_are_never_revealed_when_set_to_never(): void
    {
        foreach ([[false, false], [true, false], [false, true], [true, true]] as [$deadline, $exhausted]) {
            self::assertFalse(RevealAnswers::Never->allowsRevealNow($deadline, $exhausted));
        }
    }

    #[Test]
    public function immediate_reveal_ignores_deadline_and_attempts(): void
    {
        self::assertTrue(RevealAnswers::Immediately->allowsRevealNow(false, false));
    }

    #[Test]
    public function after_all_attempts_also_releases_once_the_deadline_passes(): void
    {
        $rule = RevealAnswers::AfterAllAttempts;

        // A learner who never used their last attempt would otherwise never
        // see the answers at all.
        self::assertFalse($rule->allowsRevealNow(deadlinePassed: false, attemptsExhausted: false));
        self::assertTrue($rule->allowsRevealNow(deadlinePassed: false, attemptsExhausted: true));
        self::assertTrue($rule->allowsRevealNow(deadlinePassed: true, attemptsExhausted: false));
    }

    #[Test]
    public function after_deadline_does_not_release_early_to_a_learner_who_used_every_attempt(): void
    {
        // Deliberately stricter than AfterAllAttempts: with a shared deadline,
        // one learner finishing early must not get the answer key while others
        // are still sitting the assessment.
        self::assertFalse(
            RevealAnswers::AfterDeadline->allowsRevealNow(deadlinePassed: false, attemptsExhausted: true),
        );
        self::assertTrue(
            RevealAnswers::AfterDeadline->allowsRevealNow(deadlinePassed: true, attemptsExhausted: true),
        );
    }

    #[Test]
    public function the_legacy_boolean_maps_conservatively(): void
    {
        // The old tinyint(1) could not express "when", so a true value becomes
        // AfterAllAttempts rather than Immediately — showing answers straight
        // away on a multi-attempt quiz hands over the answer key.
        self::assertSame(RevealAnswers::AfterAllAttempts, RevealAnswers::fromLegacyFlag(1));
        self::assertSame(RevealAnswers::AfterAllAttempts, RevealAnswers::fromLegacyFlag(true));
        self::assertSame(RevealAnswers::AfterAllAttempts, RevealAnswers::fromLegacyFlag('1'));

        self::assertSame(RevealAnswers::Never, RevealAnswers::fromLegacyFlag(0));
        self::assertSame(RevealAnswers::Never, RevealAnswers::fromLegacyFlag(false));
        self::assertSame(RevealAnswers::Never, RevealAnswers::fromLegacyFlag(null));
    }
}
