<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Skill\LearnerSkill;
use Central\Api\Domain\Skill\Proficiency;
use Central\Api\Domain\Skill\Skill;
use Central\Api\Domain\Skill\SkillCategory;
use Central\Api\Domain\Skill\SkillFilters;
use Central\Api\Domain\Skill\SkillSubject;
use Central\Api\Infrastructure\Persistence\DbalSkillRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Skills against a real PostgreSQL.
 *
 * The substance is the derivation: a learner's skills are the skills of the
 * things they have finished — a course, a training programme, a learning path.
 * `user_skills_acquired` was built to store them, holds nothing, and was never
 * read. Nobody is awarded a skill; they hold it because they did the work, and
 * that is recomputed on every read rather than written down and left to rot.
 */
#[CoversClass(DbalSkillRepository::class)]
#[CoversClass(Skill::class)]
#[CoversClass(SkillCategory::class)]
final class DbalSkillRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalSkillRepository $skills;

    private string $tenantA;
    private string $tenantB;
    private string $ada;
    private string $grace;
    private string $fireCourse;
    private string $gdprCourse;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->skills = new DbalSkillRepository(self::$connection);

        $this->tenantA = $this->createTenant('skl-a');
        $this->tenantB = $this->createTenant('skl-b');
        $this->ada     = $this->createUser($this->tenantA, 'Ada');
        $this->grace   = $this->createUser($this->tenantA, 'Grace');

        $this->fireCourse = $this->createCourse('Fire Safety');
        $this->gdprCourse = $this->createCourse('GDPR Basics');
    }

    protected function tearDown(): void
    {
        if (self::$connection === null) {
            return;
        }

        foreach ([$this->tenantA ?? null, $this->tenantB ?? null] as $tenantId) {
            if ($tenantId === null) {
                continue;
            }

            $tables = [
                'subject_skills', 'enrollments', 'training_enrollments', 'live_trainings',
                'skills', 'skill_categories', 'courses', 'users',
            ];

            foreach ($tables as $table) {
                self::$connection->executeStatement(
                    sprintf('DELETE FROM %s WHERE tenant_id = :id', $table),
                    ['id' => $tenantId],
                );
            }

            self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
        }
    }

    // --- the dictionary ---------------------------------------------------

    #[Test]
    public function a_skill_can_be_filed_under_a_category(): void
    {
        $category = $this->skills->saveCategory($this->category('Safety', '#ff0000'));
        $skill    = $this->skills->save($this->skill('Fire safety', categoryId: $category->id));

        self::assertSame('Safety', $skill->categoryName);
        self::assertSame('#ff0000', $skill->categoryColour);
        self::assertSame(1, $this->skills->findCategory($this->tenantA, (string) $category->id)?->skillCount);
    }

    #[Test]
    public function a_skill_cannot_be_filed_under_another_tenants_category(): void
    {
        // The legacy joined on `category_id` alone. The composite key makes it
        // unrepresentable rather than merely unlikely.
        $other = $this->skills->saveCategory(new SkillCategory(
            id: null,
            tenantId: $this->tenantB,
            name: 'Theirs',
        ));

        $this->expectException(\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException::class);

        $this->skills->save($this->skill('Fire safety', categoryId: $other->id));
    }

    #[Test]
    public function two_skills_cannot_share_a_name_however_it_is_typed(): void
    {
        $this->skills->save($this->skill('Fire Safety'));

        self::assertTrue($this->skills->nameExists($this->tenantA, 'fire safety'));
        self::assertTrue($this->skills->nameExists($this->tenantA, '  FIRE SAFETY  '));
        // But another organisation may have one of its own.
        self::assertFalse($this->skills->nameExists($this->tenantB, 'Fire Safety'));
    }

    #[Test]
    public function a_skill_being_edited_does_not_clash_with_itself(): void
    {
        $skill = $this->skills->save($this->skill('Fire Safety'));

        self::assertFalse($this->skills->nameExists($this->tenantA, 'Fire Safety', $skill->id));
    }

    #[Test]
    public function editing_keeps_the_id(): void
    {
        $skill   = $this->skills->save($this->skill('Fire safety'));
        $updated = $this->skills->save(new Skill(
            id: $skill->id,
            tenantId: $this->tenantA,
            name: 'Fire safety and evacuation',
            level: Proficiency::Advanced,
        ));

        self::assertSame($skill->id, $updated->id);
        self::assertSame('Fire safety and evacuation', $updated->name);
        self::assertSame(Proficiency::Advanced, $updated->level);
    }

    #[Test]
    public function a_skill_is_invisible_to_another_tenant(): void
    {
        $skill = $this->skills->save($this->skill('Fire safety'));

        self::assertNotNull($this->skills->find($this->tenantA, (string) $skill->id));
        self::assertNull($this->skills->find($this->tenantB, (string) $skill->id));
        self::assertFalse($this->skills->softDelete($this->tenantB, (string) $skill->id));
    }

    #[Test]
    public function the_filters_narrow_the_dictionary(): void
    {
        $category = $this->skills->saveCategory($this->category('Safety'));

        $this->skills->save($this->skill('Fire safety', categoryId: $category->id));
        $this->skills->save($this->skill('Data protection', level: Proficiency::Expert));
        $this->skills->save($this->skill('Retired thing', active: false));

        $count = fn (SkillFilters $f): int => $this->skills->paginate($this->tenantA, $f, 1, 25)['total'];

        self::assertSame(3, $count(SkillFilters::all()));
        self::assertSame(2, $count(SkillFilters::active()));
        self::assertSame(1, $count(SkillFilters::fromQuery(['categoryId' => $category->id])));
        self::assertSame(1, $count(SkillFilters::fromQuery(['level' => 'expert'])));
        self::assertSame(1, $count(SkillFilters::fromQuery(['search' => 'protect'])));
    }

    // --- categories -------------------------------------------------------

    #[Test]
    public function removing_a_category_keeps_the_skills_in_it(): void
    {
        // Removing a grouping is not removing what was grouped.
        $category = $this->skills->saveCategory($this->category('Safety'));
        $skill    = $this->skills->save($this->skill('Fire safety', categoryId: $category->id));

        self::assertTrue($this->skills->deleteCategory($this->tenantA, (string) $category->id));

        $after = $this->skills->find($this->tenantA, (string) $skill->id);

        self::assertNotNull($after);
        self::assertNull($after->categoryId);
        self::assertNull($after->categoryName);
    }

    #[Test]
    public function a_removed_category_frees_its_name(): void
    {
        $category = $this->skills->saveCategory($this->category('Safety'));
        $this->skills->deleteCategory($this->tenantA, (string) $category->id);

        self::assertFalse($this->skills->categoryNameExists($this->tenantA, 'Safety'));
    }

    // --- what teaches what ------------------------------------------------

    #[Test]
    public function a_course_teaches_the_skills_it_is_given(): void
    {
        $fire = $this->skills->save($this->skill('Fire safety'));
        $data = $this->skills->save($this->skill('Data protection'));

        $taught = $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Course, $this->fireCourse, [
            ['skillId' => (string) $fire->id, 'proficiency' => 'advanced', 'isPrimary' => true],
            ['skillId' => (string) $data->id, 'proficiency' => 'beginner', 'isPrimary' => false],
        ]);

        // Primary first.
        self::assertSame(['Fire safety', 'Data protection'], array_column($taught, 'name'));
        self::assertSame('advanced', $taught[0]['proficiency']);
        self::assertTrue($taught[0]['isPrimary']);
    }

    #[Test]
    public function setting_the_skills_replaces_the_whole_set(): void
    {
        $fire = $this->skills->save($this->skill('Fire safety'));
        $data = $this->skills->save($this->skill('Data protection'));

        $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Course, $this->fireCourse, [
            ['skillId' => (string) $fire->id, 'proficiency' => 'advanced', 'isPrimary' => false],
        ]);

        $taught = $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Course, $this->fireCourse, [
            ['skillId' => (string) $data->id, 'proficiency' => 'beginner', 'isPrimary' => false],
        ]);

        self::assertSame(['Data protection'], array_column($taught, 'name'));
    }

    #[Test]
    public function a_course_can_be_about_only_one_thing(): void
    {
        $fire = $this->skills->save($this->skill('Fire safety'));
        $data = $this->skills->save($this->skill('Data protection'));

        // The legacy let every row set the flag, so a course could be "really
        // about" four things.
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);

        $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Course, $this->fireCourse, [
            ['skillId' => (string) $fire->id, 'proficiency' => 'advanced', 'isPrimary' => true],
            ['skillId' => (string) $data->id, 'proficiency' => 'beginner', 'isPrimary' => true],
        ]);
    }

    #[Test]
    public function removing_a_skill_removes_it_from_the_courses_that_taught_it(): void
    {
        $fire = $this->skills->save($this->skill('Fire safety'));
        $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Course, $this->fireCourse, [
            ['skillId' => (string) $fire->id, 'proficiency' => 'advanced', 'isPrimary' => false],
        ]);

        // Soft-deleted, so the row survives and the join drops it — a course
        // must not go on claiming to teach something that no longer exists.
        $this->skills->softDelete($this->tenantA, (string) $fire->id);

        self::assertSame([], $this->skills->forSubject($this->tenantA, SkillSubject::Course, $this->fireCourse));
    }

    #[Test]
    public function the_courses_teaching_a_skill_can_be_listed(): void
    {
        $fire = $this->skills->save($this->skill('Fire safety'));

        foreach ([$this->fireCourse, $this->gdprCourse] as $courseId) {
            $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Course, $courseId, [
                ['skillId' => (string) $fire->id, 'proficiency' => 'intermediate', 'isPrimary' => false],
            ]);
        }

        self::assertCount(2, $this->skills->subjectsTeaching($this->tenantA, (string) $fire->id));
    }

    // --- a skill can come from more than a course -----------------------------

    #[Test]
    public function a_training_programme_confers_a_skill_when_it_is_finished(): void
    {
        $skill = $this->savedSkill('Working at height');
        $training = $this->createTraining('Scaffold safety');

        $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Training, $training, [
            ['skillId' => $skill, 'proficiency' => 'advanced', 'isPrimary' => true],
        ]);

        // Enrolled but not finished: on the way to it, not holding it.
        $this->enrolOnTraining($this->ada, $training, 'in_progress');

        $held = $this->skills->forLearner($this->tenantA, $this->ada, true);
        self::assertSame([], $held);

        $inProgress = $this->skills->forLearner($this->tenantA, $this->ada);
        self::assertCount(1, $inProgress);
        self::assertSame('Working at height', $inProgress[0]->name);

        // Finished: held, at the level the programme teaches.
        self::$connection?->executeStatement(
            "UPDATE training_enrollments SET status = 'completed', completed_at = NOW()
             WHERE training_id = :t AND user_id = :u",
            ['t' => $training, 'u' => $this->ada],
        );

        $held = $this->skills->forLearner($this->tenantA, $this->ada, true);
        self::assertCount(1, $held);
        self::assertSame('advanced', $held[0]->proficiency?->value);
    }

    #[Test]
    public function a_course_and_a_programme_teaching_the_same_skill_are_one_skill(): void
    {
        $skill = $this->savedSkill('First aid');
        $training = $this->createTraining('Practical first aid');

        $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Course, $this->fireCourse, [
            ['skillId' => $skill, 'proficiency' => 'beginner', 'isPrimary' => true],
        ]);
        $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Training, $training, [
            ['skillId' => $skill, 'proficiency' => 'expert', 'isPrimary' => true],
        ]);

        $this->enrol($this->ada, $this->fireCourse, 'completed');
        $this->enrolOnTraining($this->ada, $training, 'completed');

        $held = $this->skills->forLearner($this->tenantA, $this->ada, true);

        // One skill, at the highest level anything they finished teaches.
        self::assertCount(1, $held);
        self::assertSame('expert', $held[0]->proficiency?->value);

        // And both things that teach it are listed, whatever kind they are.
        $teaching = $this->skills->subjectsTeaching($this->tenantA, $skill);
        self::assertCount(2, $teaching);
        self::assertSame(
            ['course', 'training'],
            array_values(array_unique(array_map(
                static fn (array $r): string => (string) $r['subjectKind'],
                $teaching,
            ))),
        );
    }

    #[Test]
    public function a_skill_only_a_programme_teaches_is_not_a_gap(): void
    {
        $skill = $this->savedSkill('Confined spaces');
        $training = $this->createTraining('Confined space entry');

        $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Training, $training, [
            ['skillId' => $skill, 'proficiency' => 'advanced', 'isPrimary' => true],
        ]);

        $gaps = array_map(
            static fn (array $r): string => (string) $r['name'],
            $this->skills->statistics($this->tenantA)['untaught'],
        );

        // A gap is a skill nothing can give anybody. Counting only courses
        // would report one here and send somebody off to write a course that
        // already exists as a workshop.
        self::assertNotContains('Confined spaces', $gaps);
    }

    #[Test]
    public function an_optional_skill_is_touched_on_not_conferred(): void
    {
        $skill = $this->savedSkill('Negotiation');
        $training = $this->createTraining('Advanced management');

        // The workshop covers negotiation but is not about it. Finishing it
        // does not make somebody a negotiator, and saying so would make the
        // register worthless to whoever reads it looking for one.
        $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Training, $training, [
            ['skillId' => $skill, 'proficiency' => 'beginner', 'isPrimary' => false, 'isRequired' => false],
        ]);

        $this->enrolOnTraining($this->ada, $training, 'completed');

        // Not held, though the programme was finished.
        self::assertSame([], $this->skills->forLearner($this->tenantA, $this->ada, true));

        // And it counts as a gap: nothing actually confers it.
        $gaps = array_map(
            static fn (array $r): string => (string) $r['name'],
            $this->skills->statistics($this->tenantA)['untaught'],
        );
        self::assertContains('Negotiation', $gaps);

        // But the editor still sees it listed against the programme.
        $listed = $this->skills->forSubject($this->tenantA, SkillSubject::Training, $training);
        self::assertCount(1, $listed);
        self::assertFalse($listed[0]['isRequired']);
    }

    // --- who holds what ---------------------------------------------------

    #[Test]
    public function finishing_a_course_is_what_grants_the_skill(): void
    {
        $fire = $this->skills->save($this->skill('Fire safety'));
        $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Course, $this->fireCourse, [
            ['skillId' => (string) $fire->id, 'proficiency' => 'advanced', 'isPrimary' => true],
        ]);

        $this->enrol($this->ada, $this->fireCourse, 'completed');

        $held = $this->skills->forLearner($this->tenantA, $this->ada);

        self::assertCount(1, $held);
        self::assertTrue($held[0]->isHeld());
        self::assertSame(Proficiency::Advanced, $held[0]->proficiency);
        self::assertSame(1, $held[0]->coursesCompleted);
        self::assertNotNull($held[0]->acquiredAt);
    }

    #[Test]
    public function being_enrolled_is_not_holding_it(): void
    {
        // An enrolment on an expert course does not make somebody an expert.
        $fire = $this->skills->save($this->skill('Fire safety'));
        $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Course, $this->fireCourse, [
            ['skillId' => (string) $fire->id, 'proficiency' => 'expert', 'isPrimary' => false],
        ]);

        $this->enrol($this->ada, $this->fireCourse, 'in_progress');

        $skills = $this->skills->forLearner($this->tenantA, $this->ada);

        self::assertCount(1, $skills);
        self::assertFalse($skills[0]->isHeld());
        self::assertSame(1, $skills[0]->coursesInProgress);
        self::assertSame(Proficiency::Beginner, $skills[0]->proficiency);

        // And asking only for what they hold returns nothing.
        self::assertSame([], $this->skills->forLearner($this->tenantA, $this->ada, heldOnly: true));
    }

    #[Test]
    public function the_highest_level_actually_finished_wins(): void
    {
        $fire = $this->skills->save($this->skill('Fire safety'));

        $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Course, $this->fireCourse, [
            ['skillId' => (string) $fire->id, 'proficiency' => 'beginner', 'isPrimary' => false],
        ]);
        $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Course, $this->gdprCourse, [
            ['skillId' => (string) $fire->id, 'proficiency' => 'expert', 'isPrimary' => false],
        ]);

        $this->enrol($this->ada, $this->fireCourse, 'completed');
        $this->enrol($this->ada, $this->gdprCourse, 'completed');

        $held = $this->skills->forLearner($this->tenantA, $this->ada, heldOnly: true);

        self::assertCount(1, $held);
        self::assertSame(Proficiency::Expert, $held[0]->proficiency);
        self::assertSame(2, $held[0]->coursesCompleted);
    }

    #[Test]
    public function a_dropped_enrolment_counts_for_nothing(): void
    {
        $fire = $this->skills->save($this->skill('Fire safety'));
        $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Course, $this->fireCourse, [
            ['skillId' => (string) $fire->id, 'proficiency' => 'advanced', 'isPrimary' => false],
        ]);

        $this->enrol($this->ada, $this->fireCourse, 'dropped');

        self::assertSame([], $this->skills->forLearner($this->tenantA, $this->ada));
    }

    #[Test]
    public function an_inactive_skill_is_not_granted(): void
    {
        // Retiring a skill stops it appearing on anybody's profile; the
        // completed course stays completed.
        $fire = $this->skills->save($this->skill('Fire safety', active: false));
        $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Course, $this->fireCourse, [
            ['skillId' => (string) $fire->id, 'proficiency' => 'advanced', 'isPrimary' => false],
        ]);

        $this->enrol($this->ada, $this->fireCourse, 'completed');

        self::assertSame([], $this->skills->forLearner($this->tenantA, $this->ada));
    }

    #[Test]
    public function each_person_holds_their_own(): void
    {
        $fire = $this->skills->save($this->skill('Fire safety'));
        $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Course, $this->fireCourse, [
            ['skillId' => (string) $fire->id, 'proficiency' => 'advanced', 'isPrimary' => false],
        ]);

        $this->enrol($this->ada, $this->fireCourse, 'completed');
        $this->enrol($this->grace, $this->fireCourse, 'in_progress');

        self::assertTrue($this->skills->forLearner($this->tenantA, $this->ada)[0]->isHeld());
        self::assertFalse($this->skills->forLearner($this->tenantA, $this->grace)[0]->isHeld());
    }

    #[Test]
    public function the_people_holding_a_skill_can_be_listed(): void
    {
        $fire = $this->skills->save($this->skill('Fire safety'));
        $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Course, $this->fireCourse, [
            ['skillId' => (string) $fire->id, 'proficiency' => 'advanced', 'isPrimary' => false],
        ]);

        $this->enrol($this->ada, $this->fireCourse, 'completed');
        $this->enrol($this->grace, $this->fireCourse, 'in_progress');

        $holders = $this->skills->learnersWith($this->tenantA, (string) $fire->id, 1, 25);

        self::assertSame(1, $holders['total']);
        self::assertSame('Ada Tester', $holders['items'][0]['fullName']);
        self::assertSame('advanced', $holders['items'][0]['proficiency']);
    }

    #[Test]
    public function somebody_who_finished_two_courses_is_counted_once(): void
    {
        $fire = $this->skills->save($this->skill('Fire safety'));

        foreach ([$this->fireCourse, $this->gdprCourse] as $courseId) {
            $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Course, $courseId, [
                ['skillId' => (string) $fire->id, 'proficiency' => 'intermediate', 'isPrimary' => false],
            ]);
            $this->enrol($this->ada, $courseId, 'completed');
        }

        $holders = $this->skills->learnersWith($this->tenantA, (string) $fire->id, 1, 25);

        self::assertSame(1, $holders['total']);
        self::assertSame(2, $holders['items'][0]['courses']);
    }

    // --- totals -----------------------------------------------------------

    #[Test]
    public function a_skill_nothing_teaches_is_reported_as_a_gap(): void
    {
        // A skill the organisation has declared and has no way to give anybody.
        $taught   = $this->skills->save($this->skill('Fire safety'));
        $untaught = $this->skills->save($this->skill('Welding'));

        $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Course, $this->fireCourse, [
            ['skillId' => (string) $taught->id, 'proficiency' => 'advanced', 'isPrimary' => false],
        ]);

        $statistics = $this->skills->statistics($this->tenantA);

        self::assertSame(2, $statistics['skills']);
        self::assertSame(1, $statistics['coursesMapped']);
        self::assertSame(
            [['skillId' => $untaught->id, 'name' => 'Welding', 'colour' => '#c9a227']],
            $statistics['untaught'],
        );
    }

    #[Test]
    public function the_most_widely_held_skills_are_ranked(): void
    {
        $common = $this->skills->save($this->skill('Fire safety'));
        $rare   = $this->skills->save($this->skill('Welding'));

        $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Course, $this->fireCourse, [
            ['skillId' => (string) $common->id, 'proficiency' => 'advanced', 'isPrimary' => false],
        ]);
        $this->skills->setSubjectSkills($this->tenantA, SkillSubject::Course, $this->gdprCourse, [
            ['skillId' => (string) $rare->id, 'proficiency' => 'advanced', 'isPrimary' => false],
        ]);

        $this->enrol($this->ada, $this->fireCourse, 'completed');
        $this->enrol($this->grace, $this->fireCourse, 'completed');
        $this->enrol($this->ada, $this->gdprCourse, 'completed');

        $ranked = $this->skills->statistics($this->tenantA)['mostHeld'];

        self::assertSame(['Fire safety', 'Welding'], array_column($ranked, 'name'));
        self::assertSame([2, 1], array_column($ranked, 'learners'));
    }

    #[Test]
    public function another_tenants_figures_are_its_own(): void
    {
        $this->skills->save($this->skill('Fire safety'));

        self::assertSame(0, $this->skills->statistics($this->tenantB)['skills']);
        self::assertSame(0, $this->skills->paginate($this->tenantB, SkillFilters::all(), 1, 25)['total']);
    }

    // --- helpers ----------------------------------------------------------

    /** A skill saved and its id handed back, which is all these tests want. */
    private function savedSkill(string $name): string
    {
        return (string) $this->skills->save($this->skill($name))->id;
    }

    private function createTraining(string $title): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO live_trainings (id, tenant_id, title, status)
             VALUES (:id, :t, :ti, 'published')",
            ['id' => $id, 't' => $this->tenantA, 'ti' => $title],
        );

        return $id;
    }

    private function enrolOnTraining(string $userId, string $trainingId, string $status): void
    {
        self::$connection?->executeStatement(
            "INSERT INTO training_enrollments
                 (tenant_id, training_id, user_id, status, source, completed_at)
             VALUES (:t, :tr, :u, :s,
                     'direct',
                     CASE WHEN :s2 = 'completed' THEN now() ELSE NULL END)",
            [
                't'  => $this->tenantA,
                'tr' => $trainingId,
                'u'  => $userId,
                's'  => $status,
                's2' => $status,
            ],
        );
    }

    private function skill(
        string $name,
        ?string $categoryId = null,
        Proficiency $level = Proficiency::Intermediate,
        bool $active = true,
    ): Skill {
        return new Skill(
            id: null,
            tenantId: $this->tenantA,
            name: $name,
            level: $level,
            categoryId: $categoryId,
            isActive: $active,
        );
    }

    private function category(string $name, string $colour = '#6c757d'): SkillCategory
    {
        return new SkillCategory(
            id: null,
            tenantId: $this->tenantA,
            name: $name,
            colour: $colour,
        );
    }

    private function enrol(string $userId, string $courseId, string $status): void
    {
        self::$connection?->executeStatement(
            "INSERT INTO enrollments
                 (tenant_id, course_id, user_id, status, source, progress_percentage,
                  started_at, completed_at)
             VALUES (:t, :c, :u, :s, 'direct',
                     CASE WHEN :s2 = 'completed' THEN 100 ELSE 0 END,
                     CASE WHEN :s3 = 'not_started' THEN NULL ELSE now() - interval '1 day' END,
                     CASE WHEN :s4 = 'completed' THEN now() ELSE NULL END)",
            [
                't'  => $this->tenantA,
                'c'  => $courseId,
                'u'  => $userId,
                's'  => $status,
                's2' => $status,
                's3' => $status,
                's4' => $status,
            ],
        );
    }

    private function createTenant(string $prefix): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO tenants (id, name, code, status) VALUES (:id, :name, :code, 'active')",
            ['id' => $id, 'name' => 'Test ' . $prefix, 'code' => $prefix . '-' . substr($id, 0, 8)],
        );

        return $id;
    }

    private function createUser(string $tenantId, string $first): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO users (id, tenant_id, email, password_hash, first_name, last_name, status)
             VALUES (:id, :t, :e, 'x', :f, 'Tester', 'active')",
            [
                'id' => $id,
                't'  => $tenantId,
                'e'  => strtolower($first) . '-' . substr($id, 0, 8) . '@skl.test',
                'f'  => $first,
            ],
        );

        return $id;
    }

    private function createCourse(string $title): string
    {
        $id = Uuid::uuid4()->toString();

        self::$connection?->executeStatement(
            "INSERT INTO courses (id, tenant_id, title, status) VALUES (:id, :t, :ti, 'published')",
            ['id' => $id, 't' => $this->tenantA, 'ti' => $title],
        );

        return $id;
    }

    private static function connect(): ?Connection
    {
        try {
            $connection = DriverManager::getConnection([
                'driver'   => 'pdo_pgsql',
                'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
                'port'     => (int) ($_ENV['DB_PORT'] ?? 5442),
                'dbname'   => $_ENV['DB_NAME'] ?? 'central_lms',
                'user'     => $_ENV['DB_USER'] ?? 'central_lms',
                'password' => $_ENV['DB_PASSWORD'] ?? 'central_lms',
            ]);

            $connection->executeQuery('SELECT 1');

            return $connection;
        } catch (\Throwable) {
            return null;
        }
    }
}
