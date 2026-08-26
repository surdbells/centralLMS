<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Skill;

use Central\Api\Domain\Skill\LearnerSkill;
use Central\Api\Domain\Skill\Proficiency;
use Central\Api\Domain\Shared\Difficulty;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * How well somebody holds a skill.
 *
 * The legacy worked the highest of these out by `GROUP_CONCAT`ing the levels of
 * every course somebody had finished and walking a hardcoded list of four
 * strings in PHP — in two places, which disagreed about whether an in-progress
 * course counted.
 */
#[CoversClass(Proficiency::class)]
#[CoversClass(LearnerSkill::class)]
final class ProficiencyTest extends TestCase
{
    #[Test]
    public function the_better_of_two_is_the_higher_one(): void
    {
        self::assertSame(
            Proficiency::Expert,
            Proficiency::Beginner->bestOf(Proficiency::Expert),
        );
        self::assertSame(
            Proficiency::Expert,
            Proficiency::Expert->bestOf(Proficiency::Beginner),
        );
    }

    #[Test]
    public function the_highest_of_a_set_is_found(): void
    {
        // Somebody who has finished a beginner course and an expert one in the
        // same skill is an expert at it.
        self::assertSame(
            Proficiency::Advanced,
            Proficiency::highest([
                Proficiency::Beginner,
                Proficiency::Advanced,
                Proficiency::Intermediate,
            ]),
        );
    }

    #[Test]
    public function the_highest_of_nothing_is_nothing(): void
    {
        self::assertNull(Proficiency::highest([]));
    }

    #[Test]
    public function the_ladder_is_ordered(): void
    {
        $ranks = array_map(static fn (Proficiency $p): int => $p->rank(), Proficiency::cases());

        self::assertSame([1, 2, 3, 4], $ranks);
        self::assertSame($ranks, array_values(array_unique($ranks)));
    }

    #[Test]
    public function it_is_not_the_difficulty_scale(): void
    {
        // Two different measurements that share two words: difficulty
        // describes the material, proficiency describes the person. Folding
        // them together would let a course's difficulty decide a learner's
        // competence.
        self::assertNotSame(
            count(Difficulty::cases()),
            count(Proficiency::cases()),
        );
        self::assertNull(Proficiency::tryFrom('professional'));
        self::assertNull(Difficulty::tryFrom('expert'));
    }

    #[Test]
    public function the_legacy_spellings_are_the_same_four(): void
    {
        self::assertSame(Proficiency::Expert, Proficiency::fromLegacy('EXPERT'));
        self::assertSame(Proficiency::Beginner, Proficiency::fromLegacy(' beginner '));
        self::assertNull(Proficiency::fromLegacy('guru'));
        self::assertNull(Proficiency::fromLegacy(null));
    }

    // --- what a learner holds ---------------------------------------------

    #[Test]
    public function a_skill_is_held_once_a_course_teaching_it_is_finished(): void
    {
        self::assertTrue($this->learnerSkill(completed: 1, inProgress: 0)->isHeld());
        self::assertFalse($this->learnerSkill(completed: 0, inProgress: 2)->isHeld());
    }

    #[Test]
    public function progress_is_measured_against_the_courses_taken(): void
    {
        // Not against every course in the catalogue: somebody who has finished
        // the only course they were given is not 20% skilled because four
        // other courses happen to exist.
        self::assertSame(50.0, $this->learnerSkill(completed: 1, inProgress: 1, available: 10)->percentComplete());
        self::assertSame(100.0, $this->learnerSkill(completed: 2, inProgress: 0, available: 10)->percentComplete());
    }

    #[Test]
    public function somebody_on_nothing_is_at_zero_rather_than_undefined(): void
    {
        self::assertSame(0.0, $this->learnerSkill(completed: 0, inProgress: 0)->percentComplete());
    }

    private function learnerSkill(int $completed, int $inProgress, int $available = 3): LearnerSkill
    {
        return new LearnerSkill(
            skillId: 'skill-1',
            name: 'Fire safety',
            proficiency: Proficiency::Intermediate,
            coursesCompleted: $completed,
            coursesInProgress: $inProgress,
            coursesAvailable: $available,
        );
    }
}
