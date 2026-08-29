<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Competency\AssessmentStatus;
use Central\Api\Domain\Competency\Competency;
use Central\Api\Domain\Competency\GapCategory;
use Central\Api\Domain\Competency\JobCompetencyProfile;
use Central\Api\Domain\Competency\ProficiencyLevel;
use Central\Api\Infrastructure\Persistence\DbalCompetencyRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Competency assurance against a real PostgreSQL.
 *
 * The substance is the gap. A profile states what a job needs; an assessment
 * records what a person has; the gap between them is a generated column, so it
 * is never wrong and never stale — the database computes it from the levels the
 * moment they change, and the outcome report only ever reads finalised ones.
 */
#[CoversClass(DbalCompetencyRepository::class)]
#[CoversClass(Competency::class)]
#[CoversClass(JobCompetencyProfile::class)]
final class DbalCompetencyRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalCompetencyRepository $competencies;

    private string $tenantA;
    private string $tenantB;
    private string $ada;
    private string $grace;
    private string $expert;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->competencies = new DbalCompetencyRepository(self::$connection);

        $this->tenantA = $this->createTenant('cmp-a');
        $this->tenantB = $this->createTenant('cmp-b');
        $this->ada     = $this->createUser($this->tenantA, 'Ada');
        $this->grace   = $this->createUser($this->tenantA, 'Grace');
        $this->expert  = $this->createUser($this->tenantA, 'Expert');
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
                'competency_assessment_items', 'competency_assessments',
                'job_competency_requirements', 'job_competency_profiles',
                'competencies', 'users',
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
    public function a_competency_is_stored_with_a_descriptor_per_level(): void
    {
        $saved = $this->competencies->saveCompetency($this->competency('Well Control', 'Drilling', [
            'knowledgeDescriptor' => 'Knows the well control principles.',
            'skillDescriptor'     => 'Applies them at the choke.',
            'masteryDescriptor'   => 'Coaches others through a kick.',
        ]));

        $found = $this->competencies->findCompetency($this->tenantA, $saved->id);

        self::assertNotNull($found);
        self::assertSame('Well Control', $found->name);
        self::assertSame('Drilling', $found->functionName);
        self::assertSame('Applies them at the choke.', $found->skillDescriptor);
    }

    #[Test]
    public function a_competency_is_found_by_name_regardless_of_case(): void
    {
        $this->competencies->saveCompetency($this->competency('Well Control', 'Drilling'));

        self::assertNotNull($this->competencies->findCompetencyByName($this->tenantA, 'Drilling', 'well control'));
        // A different function may reuse the name.
        self::assertNull($this->competencies->findCompetencyByName($this->tenantA, 'Subsea', 'Well Control'));
        // Another tenant's dictionary is its own.
        self::assertNull($this->competencies->findCompetencyByName($this->tenantB, 'Drilling', 'Well Control'));
    }

    #[Test]
    public function two_competencies_in_a_function_cannot_share_a_name(): void
    {
        $this->competencies->saveCompetency($this->competency('Well Control', 'Drilling'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->competencies->saveCompetency($this->competency('well control', 'Drilling'));
    }

    #[Test]
    public function a_deleted_competency_leaves_the_dictionary_and_frees_its_name(): void
    {
        $saved = $this->competencies->saveCompetency($this->competency('Well Control', 'Drilling'));

        self::assertTrue($this->competencies->deleteCompetency($this->tenantA, $saved->id));
        self::assertNull($this->competencies->findCompetency($this->tenantA, $saved->id));
        self::assertNull($this->competencies->findCompetencyByName($this->tenantA, 'Drilling', 'Well Control'));
        // And the name can be taken again.
        $this->competencies->saveCompetency($this->competency('Well Control', 'Drilling'));
        self::assertNotNull($this->competencies->findCompetencyByName($this->tenantA, 'Drilling', 'Well Control'));
    }

    #[Test]
    public function a_competency_is_invisible_to_another_tenant(): void
    {
        $saved = $this->competencies->saveCompetency($this->competency('Well Control', 'Drilling'));

        self::assertNotNull($this->competencies->findCompetency($this->tenantA, $saved->id));
        self::assertNull($this->competencies->findCompetency($this->tenantB, $saved->id));
        self::assertFalse($this->competencies->deleteCompetency($this->tenantB, $saved->id));
    }

    #[Test]
    public function functions_report_their_competency_and_profile_counts(): void
    {
        $well = $this->competencies->saveCompetency($this->competency('Well Control', 'Drilling'));
        $this->competencies->saveCompetency($this->competency('Casing Design', 'Drilling'));
        $this->competencies->saveCompetency($this->competency('Flow Assurance', 'Subsea'));
        $this->saveProfile('Driller', 'Drilling', [[$well->id, ProficiencyLevel::Skill, false]]);

        $functions = $this->competencies->functions($this->tenantA);

        self::assertSame('Drilling', $functions[0]['function']);
        self::assertSame(2, $functions[0]['competencies']);
        self::assertSame(1, $functions[0]['profiles']);
        self::assertSame('Subsea', $functions[1]['function']);
        self::assertSame(0, $functions[1]['profiles']);
    }

    // --- profiles ---------------------------------------------------------

    #[Test]
    public function a_profile_carries_its_requirements(): void
    {
        $well = $this->competencies->saveCompetency($this->competency('Well Control', 'Drilling'));
        $casing = $this->competencies->saveCompetency($this->competency('Casing Design', 'Drilling'));

        $profile = $this->saveProfile('Senior Driller', 'Drilling', [
            [$well->id, ProficiencyLevel::Mastery, true],
            [$casing->id, ProficiencyLevel::Skill, false],
        ]);

        $found = $this->competencies->findProfile($this->tenantA, $profile->id);

        self::assertNotNull($found);
        self::assertCount(2, $found->requirements);
        // Ordered by competency name: Casing before Well.
        self::assertSame('Casing Design', $found->requirements[0]['competencyName'] ?? null);
        self::assertSame(ProficiencyLevel::Mastery->value, $found->requirements[1]['requiredLevel']);
        self::assertTrue($found->requirements[1]['isCritical']);
    }

    #[Test]
    public function saving_a_profile_replaces_its_whole_requirement_set(): void
    {
        $well = $this->competencies->saveCompetency($this->competency('Well Control', 'Drilling'));
        $casing = $this->competencies->saveCompetency($this->competency('Casing Design', 'Drilling'));

        $profile = $this->saveProfile('Driller', 'Drilling', [[$well->id, ProficiencyLevel::Skill, false]]);

        // Re-save with a different single requirement — the first must be gone.
        $this->competencies->saveProfile(new JobCompetencyProfile(
            id: $profile->id,
            tenantId: $this->tenantA,
            jobTitle: 'Driller',
            functionName: 'Drilling',
            requirements: [
                ['competencyId' => $casing->id, 'requiredLevel' => ProficiencyLevel::Mastery->value, 'isCritical' => true],
            ],
        ));

        $found = $this->competencies->findProfile($this->tenantA, $profile->id);

        self::assertNotNull($found);
        self::assertCount(1, $found->requirements);
        self::assertSame('Casing Design', $found->requirements[0]['competencyName'] ?? null);
    }

    #[Test]
    public function a_profile_is_found_by_title_within_its_function(): void
    {
        $well = $this->competencies->saveCompetency($this->competency('Well Control', 'Drilling'));
        $this->saveProfile('Driller', 'Drilling', [[$well->id, ProficiencyLevel::Skill, false]]);

        self::assertNotNull($this->competencies->findProfileByTitle($this->tenantA, 'Drilling', 'driller'));
        self::assertNull($this->competencies->findProfileByTitle($this->tenantA, 'Subsea', 'Driller'));
    }

    #[Test]
    public function two_profiles_in_a_function_cannot_share_a_job_title(): void
    {
        $well = $this->competencies->saveCompetency($this->competency('Well Control', 'Drilling'));
        $this->saveProfile('Driller', 'Drilling', [[$well->id, ProficiencyLevel::Skill, false]]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->saveProfile('driller', 'Drilling', [[$well->id, ProficiencyLevel::Knowledge, false]]);
    }

    // --- the assessment flow ----------------------------------------------

    #[Test]
    public function starting_an_assessment_mirrors_the_profile_into_items(): void
    {
        $well = $this->competencies->saveCompetency($this->competency('Well Control', 'Drilling'));
        $casing = $this->competencies->saveCompetency($this->competency('Casing Design', 'Drilling'));
        $profile = $this->saveProfile('Driller', 'Drilling', [
            [$well->id, ProficiencyLevel::Mastery, true],
            [$casing->id, ProficiencyLevel::Skill, false],
        ]);

        $assessment = $this->competencies->startAssessment($this->tenantA, $this->ada, $profile->id, '2026');

        self::assertSame(AssessmentStatus::Draft, $assessment->status);
        self::assertCount(2, $assessment->items);
        // Every gap is the full required level while nothing has been demonstrated.
        $gaps = array_map(static fn ($i): int => $i->gap(), $assessment->items);
        self::assertSame([3, 2], $gaps); // ordered gap DESC: Mastery(3) then Skill(2)
        self::assertSame(0.0, $assessment->readinessPercent());
    }

    #[Test]
    public function the_same_person_cannot_be_assessed_twice_against_one_profile_in_a_cycle(): void
    {
        $well = $this->competencies->saveCompetency($this->competency('Well Control', 'Drilling'));
        $profile = $this->saveProfile('Driller', 'Drilling', [[$well->id, ProficiencyLevel::Skill, false]]);

        $this->competencies->startAssessment($this->tenantA, $this->ada, $profile->id, '2026');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->competencies->startAssessment($this->tenantA, $this->ada, $profile->id, '2026');
    }

    #[Test]
    public function a_new_cycle_lets_the_same_person_be_assessed_again(): void
    {
        $well = $this->competencies->saveCompetency($this->competency('Well Control', 'Drilling'));
        $profile = $this->saveProfile('Driller', 'Drilling', [[$well->id, ProficiencyLevel::Skill, false]]);

        $this->competencies->startAssessment($this->tenantA, $this->ada, $profile->id, '2025');
        $this->competencies->startAssessment($this->tenantA, $this->ada, $profile->id, '2026');

        self::assertNotNull($this->competencies->findAssessmentFor($this->tenantA, $this->ada, $profile->id, '2025'));
        self::assertNotNull($this->competencies->findAssessmentFor($this->tenantA, $this->ada, $profile->id, '2026'));
    }

    #[Test]
    public function the_gap_is_recomputed_from_whatever_level_is_latest(): void
    {
        $well = $this->competencies->saveCompetency($this->competency('Well Control', 'Drilling'));
        $profile = $this->saveProfile('Driller', 'Drilling', [[$well->id, ProficiencyLevel::Mastery, true]]);
        $assessment = $this->competencies->startAssessment($this->tenantA, $this->ada, $profile->id, '2026');

        // The person claims Skill: gap narrows from 3 to 1.
        $selfed = $this->competencies->recordLevels(
            $this->tenantA,
            $assessment->id,
            'self',
            [$well->id => ProficiencyLevel::Skill->value],
        );
        self::assertNotNull($selfed);
        self::assertSame(1, $selfed->items[0]->gap());
        self::assertSame(GapCategory::Minor, $selfed->items[0]->category());

        // The expert confirms only Knowledge: the assessed level wins over self,
        // so the gap widens back to 2.
        $assessed = $this->competencies->recordLevels(
            $this->tenantA,
            $assessment->id,
            'assessed',
            [$well->id => ProficiencyLevel::Knowledge->value],
        );
        self::assertNotNull($assessed);
        self::assertSame(2, $assessed->items[0]->gap());
        self::assertSame(GapCategory::Major, $assessed->items[0]->category());
    }

    #[Test]
    public function each_status_stamps_its_own_date_once(): void
    {
        $well = $this->competencies->saveCompetency($this->competency('Well Control', 'Drilling'));
        $profile = $this->saveProfile('Driller', 'Drilling', [[$well->id, ProficiencyLevel::Skill, false]]);
        $assessment = $this->competencies->startAssessment($this->tenantA, $this->ada, $profile->id, '2026');

        $self = $this->competencies->setStatus($this->tenantA, $assessment->id, AssessmentStatus::SelfAssessed, null, null);
        self::assertNotNull($self?->selfSubmittedAt);
        self::assertNull($self->reviewedAt);
        $firstSelfStamp = $self->selfSubmittedAt;

        $reviewed = $this->competencies->setStatus($this->tenantA, $assessment->id, AssessmentStatus::Reviewed, $this->expert, 'Confirmed at the desk.');
        self::assertNotNull($reviewed?->reviewedAt);
        self::assertSame($this->expert, $reviewed->assessorId);
        // The self-submitted date is not overwritten when the state moves on.
        self::assertEquals($firstSelfStamp, $reviewed->selfSubmittedAt);

        $finalized = $this->competencies->setStatus($this->tenantA, $assessment->id, AssessmentStatus::Finalized, null, null);
        self::assertNotNull($finalized?->finalizedAt);
        self::assertSame(AssessmentStatus::Finalized, $finalized->status);
        // The assessor set at review survives finalisation.
        self::assertSame($this->expert, $finalized->assessorId);
    }

    // --- the outcome report -----------------------------------------------

    #[Test]
    public function the_outcome_counts_only_finalised_assessments(): void
    {
        $well = $this->competencies->saveCompetency($this->competency('Well Control', 'Drilling'));
        $casing = $this->competencies->saveCompetency($this->competency('Casing Design', 'Drilling'));
        $profile = $this->saveProfile('Driller', 'Drilling', [
            [$well->id, ProficiencyLevel::Mastery, true],
            [$casing->id, ProficiencyLevel::Skill, false],
        ]);

        // Ada: finalised. Met on casing (Skill vs Skill), major gap on well
        // (Knowledge vs Mastery).
        $ada = $this->competencies->startAssessment($this->tenantA, $this->ada, $profile->id, '2026');
        $this->competencies->recordLevels($this->tenantA, $ada->id, 'assessed', [
            $well->id => ProficiencyLevel::Knowledge->value,
            $casing->id => ProficiencyLevel::Skill->value,
        ]);
        $this->competencies->setStatus($this->tenantA, $ada->id, AssessmentStatus::Finalized, $this->expert, null);

        // Grace: still only self-assessed — must not appear in the outcome.
        $grace = $this->competencies->startAssessment($this->tenantA, $this->grace, $profile->id, '2026');
        $this->competencies->recordLevels($this->tenantA, $grace->id, 'self', [
            $well->id => ProficiencyLevel::Mastery->value,
            $casing->id => ProficiencyLevel::Mastery->value,
        ]);
        $this->competencies->setStatus($this->tenantA, $grace->id, AssessmentStatus::SelfAssessed, null, null);

        $outcome = $this->competencies->outcome($this->tenantA, '2026');

        self::assertSame(2, $outcome['coverage']['people']);
        self::assertSame(1, $outcome['coverage']['finalized']);
        // Only Ada's two items are counted: one met, one major gap.
        self::assertSame(2, $outcome['gaps']['requirements']);
        self::assertSame(1, $outcome['gaps']['met']);
        self::assertSame(1, $outcome['gaps']['major']);
        self::assertSame(50.0, $outcome['gaps']['readiness']);
        // Well Control is the development priority — Grace's inflated self-claim
        // is not part of it.
        self::assertSame('Well Control', $outcome['topGaps'][0]['competency']);
        self::assertSame(1, $outcome['topGaps'][0]['withGap']);
    }

    #[Test]
    public function cycles_are_listed_newest_first(): void
    {
        $well = $this->competencies->saveCompetency($this->competency('Well Control', 'Drilling'));
        $profile = $this->saveProfile('Driller', 'Drilling', [[$well->id, ProficiencyLevel::Skill, false]]);

        $this->competencies->startAssessment($this->tenantA, $this->ada, $profile->id, '2025');
        $this->competencies->startAssessment($this->tenantA, $this->grace, $profile->id, '2026');

        self::assertSame(['2026', '2025'], $this->competencies->cycles($this->tenantA));
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param array<string, string> $descriptors
     */
    private function competency(string $name, string $function, array $descriptors = []): Competency
    {
        return new Competency(
            id: Uuid::uuid4()->toString(),
            tenantId: $this->tenantA,
            functionName: $function,
            name: $name,
            knowledgeDescriptor: $descriptors['knowledgeDescriptor'] ?? null,
            skillDescriptor: $descriptors['skillDescriptor'] ?? null,
            masteryDescriptor: $descriptors['masteryDescriptor'] ?? null,
        );
    }

    /**
     * @param list<array{0: string, 1: ProficiencyLevel, 2: bool}> $requirements
     */
    private function saveProfile(string $jobTitle, string $function, array $requirements): JobCompetencyProfile
    {
        return $this->competencies->saveProfile(new JobCompetencyProfile(
            id: Uuid::uuid4()->toString(),
            tenantId: $this->tenantA,
            jobTitle: $jobTitle,
            functionName: $function,
            requirements: array_map(
                static fn (array $r): array => [
                    'competencyId'  => $r[0],
                    'requiredLevel' => $r[1]->value,
                    'isCritical'    => $r[2],
                ],
                $requirements,
            ),
        ));
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
                'e'  => strtolower($first) . '-' . substr($id, 0, 8) . '@cmp.test',
                'f'  => $first,
            ],
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
