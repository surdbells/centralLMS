<?php

declare(strict_types=1);

namespace Central\Api\Domain\Skill;

/**
 * A skill somebody has, or is on the way to having.
 *
 * Derived, never stored. `user_skills_acquired` was built to hold these and
 * holds nothing, and `getLearnerSkills()` never read it — it joins completed
 * enrolments to `course_skills`, which is the design that works. Finishing the
 * course is what makes somebody trained; a second table saying so can only
 * disagree with it.
 */
final readonly class LearnerSkill
{
    public function __construct(
        public string $skillId,
        public string $name,
        public Proficiency $proficiency,
        public ?string $description = null,
        public ?string $icon = null,
        public string $colour = '#c9a227',
        public ?string $categoryId = null,
        public ?string $categoryName = null,
        public ?string $categoryColour = null,
        /** Courses finished that teach it. */
        public int $coursesCompleted = 0,
        /** Courses started but not finished that teach it. */
        public int $coursesInProgress = 0,
        /** Courses that teach it, whether or not this person is on them. */
        public int $coursesAvailable = 0,
        public ?\DateTimeImmutable $acquiredAt = null,
    ) {
    }

    /**
     * Whether they hold it, rather than merely being on the way.
     */
    public function isHeld(): bool
    {
        return $this->coursesCompleted > 0;
    }

    /**
     * How far through the courses that teach it they are.
     *
     * Of the ones they are enrolled on, not of every course in the catalogue:
     * somebody who has finished the only course they were given is not 20%
     * skilled because four other courses exist.
     */
    public function percentComplete(): float
    {
        $taken = $this->coursesCompleted + $this->coursesInProgress;

        return $taken === 0 ? 0.0 : round(100 * $this->coursesCompleted / $taken, 1);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'skillId'           => $this->skillId,
            'name'              => $this->name,
            'description'       => $this->description,
            'icon'              => $this->icon,
            'colour'            => $this->colour,
            'categoryId'        => $this->categoryId,
            'categoryName'      => $this->categoryName,
            'categoryColour'    => $this->categoryColour,
            'proficiency'       => $this->proficiency->value,
            'proficiencyLabel'  => $this->proficiency->label(),
            'isHeld'            => $this->isHeld(),
            'coursesCompleted'  => $this->coursesCompleted,
            'coursesInProgress' => $this->coursesInProgress,
            'coursesAvailable'  => $this->coursesAvailable,
            'percentComplete'   => $this->percentComplete(),
            'acquiredAt'        => $this->acquiredAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
