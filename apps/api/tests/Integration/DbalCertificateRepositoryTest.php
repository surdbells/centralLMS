<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Certificate\Certificate;
use Central\Api\Domain\Certificate\CertificateIdentity;
use Central\Api\Domain\Certificate\CertificateRenderer;
use Central\Api\Domain\Certificate\CertificateSource;
use Central\Api\Domain\Certificate\CertificateStatus;
use Central\Api\Domain\Certificate\CertificateTemplate;
use Central\Api\Infrastructure\Persistence\DbalCertificateRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * Certificates against a real PostgreSQL.
 *
 * Two things here cannot be checked with a fake: that re-issuing supersedes
 * rather than duplicating — which the partial unique index enforces, so the
 * ordering inside the transaction has to be right — and that the public
 * verification path finds a certificate without being told a tenant.
 *
 * Skipped when the database is unreachable, so a checkout without the Docker
 * stack still gets a green suite.
 */
#[CoversClass(DbalCertificateRepository::class)]
#[CoversClass(Certificate::class)]
#[CoversClass(CertificateTemplate::class)]
final class DbalCertificateRepositoryTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalCertificateRepository $certificates;
    private CertificateRenderer $renderer;

    private string $tenantA;
    private string $tenantB;
    private string $learner;
    private string $other;
    private string $admin;
    private string $courseA;
    private string $courseB;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->certificates = new DbalCertificateRepository(self::$connection);
        $this->renderer = new CertificateRenderer();

        $this->tenantA = $this->createTenant('crt-a');
        $this->tenantB = $this->createTenant('crt-b');
        $this->learner = $this->createUser($this->tenantA, 'learner');
        $this->other = $this->createUser($this->tenantA, 'other');
        $this->admin = $this->createUser($this->tenantA, 'admin');
        $this->courseA = $this->createCourse($this->tenantA, 'Fire Safety');
        $this->courseB = $this->createCourse($this->tenantA, 'Manual Handling');
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

            self::$connection->executeStatement('DELETE FROM users WHERE tenant_id = :id', ['id' => $tenantId]);
            self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
        }
    }

    // --- templates --------------------------------------------------------

    #[Test]
    public function a_template_round_trips(): void
    {
        $template = $this->template('Standard', '<h1>{{learner_name}}</h1>');

        $read = $this->certificates->findTemplate($this->tenantA, $template->id);

        self::assertNotNull($read);
        self::assertSame('Standard', $read->name);
        self::assertSame('<h1>{{learner_name}}</h1>', $read->bodyHtml);
    }

    #[Test]
    public function making_one_template_default_stands_the_previous_one_down(): void
    {
        // The partial unique index allows only one, so this has to happen in
        // the same transaction or the save is rejected.
        $first = $this->template('First', '<p>{{learner_name}}</p>', isDefault: true);
        $second = $this->template('Second', '<p>{{learner_name}}</p>', isDefault: true);

        self::assertSame($second->id, $this->certificates->findDefaultTemplate($this->tenantA)?->id);
        self::assertFalse($this->certificates->findTemplate($this->tenantA, $first->id)?->isDefault);
    }

    #[Test]
    public function another_tenant_cannot_read_the_template(): void
    {
        $template = $this->template('Private', '<p>{{learner_name}}</p>');

        self::assertNull($this->certificates->findTemplate($this->tenantB, $template->id));
        self::assertSame(0, $this->certificates->paginateTemplates($this->tenantB, 1, 50)['total']);
    }

    #[Test]
    public function template_names_are_unique_within_a_tenant_but_not_across_them(): void
    {
        $template = $this->template('Shared Name', '<p>{{learner_name}}</p>');

        self::assertTrue($this->certificates->templateHasName($this->tenantA, 'shared name'));
        self::assertFalse($this->certificates->templateHasName($this->tenantA, 'Shared Name', $template->id));
        self::assertFalse($this->certificates->templateHasName($this->tenantB, 'Shared Name'));
    }

    // --- issuing ----------------------------------------------------------

    #[Test]
    public function a_certificate_carries_the_name_and_title_it_was_issued_with(): void
    {
        $certificate = $this->issue($this->courseA, $this->learner, ['learnerName' => 'Ada Lovelace']);

        self::assertSame('Ada Lovelace', $certificate->learnerName);
        self::assertSame('Fire Safety', $certificate->title);
        self::assertSame(CertificateStatus::Active, $certificate->status);
    }

    #[Test]
    public function renaming_the_learner_does_not_rewrite_a_certificate_already_issued(): void
    {
        // The whole reason the name is copied onto the row: a certificate is a
        // record of a moment, and someone who marries still holds what they
        // were given.
        $certificate = $this->issue($this->courseA, $this->learner, ['learnerName' => 'Ada Byron']);

        self::$connection?->executeStatement(
            "UPDATE users SET last_name = 'Lovelace' WHERE id = :id",
            ['id' => $this->learner],
        );

        self::assertSame(
            'Ada Byron',
            $this->certificates->find($this->tenantA, $certificate->id)?->learnerName,
        );
    }

    #[Test]
    public function re_issuing_supersedes_the_previous_one_rather_than_duplicating(): void
    {
        $first = $this->issue($this->courseA, $this->learner, ['percentage' => 71.0]);
        $second = $this->issue($this->courseA, $this->learner, ['percentage' => 94.0]);

        self::assertNotSame($first->id, $second->id);
        self::assertSame(
            CertificateStatus::Superseded,
            $this->certificates->find($this->tenantA, $first->id)?->status,
        );
        self::assertSame(CertificateStatus::Active, $second->status);

        // And the active lookup finds the newer one.
        self::assertSame(
            $second->id,
            $this->certificates->findForSource(
                $this->tenantA,
                CertificateSource::Course,
                $this->courseA,
                $this->learner,
            )?->id,
        );
    }

    #[Test]
    public function superseding_is_recorded_in_the_history(): void
    {
        $first = $this->issue($this->courseA, $this->learner);
        $this->issue($this->courseA, $this->learner);

        self::assertSame(
            ['superseded', 'issued'],
            array_column($this->certificates->history($this->tenantA, $first->id), 'action'),
        );
    }

    #[Test]
    public function two_learners_may_hold_a_certificate_for_the_same_course(): void
    {
        $mine = $this->issue($this->courseA, $this->learner);
        $theirs = $this->issue($this->courseA, $this->other);

        self::assertNotSame($mine->id, $theirs->id);
        self::assertSame(CertificateStatus::Active, $this->certificates->find($this->tenantA, $mine->id)?->status);
        self::assertSame(CertificateStatus::Active, $this->certificates->find($this->tenantA, $theirs->id)?->status);
    }

    #[Test]
    public function one_learner_may_hold_certificates_for_different_courses(): void
    {
        $this->issue($this->courseA, $this->learner);
        $this->issue($this->courseB, $this->learner);

        self::assertCount(2, $this->certificates->forLearner($this->tenantA, $this->learner));
    }

    #[Test]
    public function numbers_carry_the_tenant_and_do_not_repeat(): void
    {
        $first = $this->issue($this->courseA, $this->learner);
        $second = $this->issue($this->courseB, $this->learner);

        self::assertNotSame($first->identity->number, $second->identity->number);
        self::assertStringStartsWith('CRTA', $first->identity->number);
    }

    // --- public verification ----------------------------------------------

    #[Test]
    public function a_certificate_verifies_by_its_hash_without_a_tenant(): void
    {
        // The public path: whoever is checking has no account here.
        $certificate = $this->issue($this->courseA, $this->learner);

        $found = $this->certificates->verifyByHash($certificate->identity->hash);

        self::assertNotNull($found);
        self::assertSame($certificate->id, $found->id);
        self::assertTrue($found->isValid(new \DateTimeImmutable()));
    }

    #[Test]
    public function a_certificate_verifies_by_its_spoken_code_in_any_case(): void
    {
        $certificate = $this->issue($this->courseA, $this->learner);

        self::assertSame(
            $certificate->id,
            $this->certificates->verifyByCode(strtolower($certificate->identity->code))?->id,
        );
    }

    #[Test]
    public function an_unknown_reference_finds_nothing(): void
    {
        self::assertNull($this->certificates->verifyByHash(str_repeat('f', 64)));
        self::assertNull($this->certificates->verifyByCode('NOTACODE99'));
    }

    #[Test]
    public function the_public_payload_gives_away_nothing_beyond_the_check(): void
    {
        $certificate = $this->issue($this->courseA, $this->learner, [
            'learnerEmail' => 'ada@example.test',
            'percentage'   => 92.0,
        ]);

        $public = $certificate->toPublicArray(new \DateTimeImmutable());

        // A verification endpoint must not become a directory.
        self::assertArrayNotHasKey('learnerEmail', $public);
        self::assertArrayNotHasKey('percentage', $public);
        self::assertArrayNotHasKey('score', $public);
        self::assertArrayNotHasKey('userId', $public);
        self::assertArrayNotHasKey('id', $public);

        self::assertSame($certificate->learnerName, $public['learnerName']);
        self::assertTrue($public['isValid']);
    }

    // --- lifecycle --------------------------------------------------------

    #[Test]
    public function revoking_records_who_and_why_and_stops_it_verifying(): void
    {
        $certificate = $this->issue($this->courseA, $this->learner);

        $revoked = $this->certificates->revoke(
            $this->tenantA,
            $certificate->id,
            'Issued in error.',
            $this->admin,
        );

        self::assertNotNull($revoked);
        self::assertSame(CertificateStatus::Revoked, $revoked->status);
        self::assertSame('Issued in error.', $revoked->revocationReason);
        self::assertNotNull($revoked->revokedAt);

        $found = $this->certificates->verifyByHash($certificate->identity->hash);

        self::assertNotNull($found);
        self::assertFalse($found->isValid(new \DateTimeImmutable()));
        self::assertStringContainsString(
            'withdrawn',
            $found->toPublicArray(new \DateTimeImmutable())['message'],
        );
    }

    #[Test]
    public function revoking_twice_reports_nothing_to_do(): void
    {
        $certificate = $this->issue($this->courseA, $this->learner);
        $this->certificates->revoke($this->tenantA, $certificate->id, 'Once.', $this->admin);

        self::assertNull($this->certificates->revoke($this->tenantA, $certificate->id, 'Twice.', $this->admin));
    }

    #[Test]
    public function reinstating_clears_the_revocation_entirely(): void
    {
        // The check constraint requires revoked_at to be null unless the
        // status is revoked, so all three fields have to clear together.
        $certificate = $this->issue($this->courseA, $this->learner);
        $this->certificates->revoke($this->tenantA, $certificate->id, 'Mistake.', $this->admin);

        $reinstated = $this->certificates->reinstate($this->tenantA, $certificate->id, $this->admin);

        self::assertNotNull($reinstated);
        self::assertSame(CertificateStatus::Active, $reinstated->status);
        self::assertNull($reinstated->revokedAt);
        self::assertNull($reinstated->revocationReason);
    }

    #[Test]
    public function a_certificate_past_its_date_reads_as_expired_before_any_sweep_runs(): void
    {
        // A verifier must be told the truth rather than whatever the last cron
        // run left behind.
        $certificate = $this->issue($this->courseA, $this->learner, [
            'validFrom'  => '2020-01-01',
            'validUntil' => '2020-12-31',
        ]);

        self::assertSame(CertificateStatus::Active, $certificate->status);
        self::assertSame(
            CertificateStatus::Expired,
            $certificate->effectiveStatus(new \DateTimeImmutable()),
        );
        self::assertFalse($certificate->isValid(new \DateTimeImmutable()));
    }

    #[Test]
    public function the_sweep_marks_lapsed_certificates(): void
    {
        $this->issue($this->courseA, $this->learner, ['validFrom' => '2020-01-01', 'validUntil' => '2020-12-31']);
        $current = $this->issue($this->courseB, $this->learner, ['validUntil' => '2099-12-31']);

        self::assertSame(1, $this->certificates->expireLapsed($this->tenantA));
        self::assertSame(CertificateStatus::Active, $this->certificates->find($this->tenantA, $current->id)?->status);
    }

    // --- counters ---------------------------------------------------------

    #[Test]
    public function the_counters_are_computed_from_the_log(): void
    {
        // The legacy kept download_count and verification_count on the row
        // beside the log that recorded the same events.
        $certificate = $this->issue($this->courseA, $this->learner);

        $this->certificates->log($this->tenantA, $certificate->id, 'downloaded', $this->learner);
        $this->certificates->log($this->tenantA, $certificate->id, 'downloaded', $this->learner);
        $this->certificates->log($this->tenantA, $certificate->id, 'verified', null, ['verifierName' => 'An Employer']);

        $read = $this->certificates->find($this->tenantA, $certificate->id);

        self::assertNotNull($read);
        self::assertSame(2, $read->downloadCount);
        self::assertSame(1, $read->verificationCount);
    }

    #[Test]
    public function a_verification_records_what_the_checker_claimed_about_themselves(): void
    {
        $certificate = $this->issue($this->courseA, $this->learner);

        $this->certificates->log($this->tenantA, $certificate->id, 'verified', null, [
            'verifierName'    => 'Jane Smith',
            'verifierCompany' => 'Acme Recruitment',
            'ip'              => '203.0.113.7',
        ]);

        $history = $this->certificates->history($this->tenantA, $certificate->id);

        self::assertSame('verified', $history[0]['action']);
        self::assertSame('Jane Smith', $history[0]['verifierName']);
        self::assertSame('Acme Recruitment', $history[0]['verifierCompany']);
        // Nobody signed in, so there is no actor.
        self::assertNull($history[0]['actorName']);
    }

    // --- rendering --------------------------------------------------------

    #[Test]
    public function a_certificate_renders_through_its_template(): void
    {
        $this->template('Standard', '<h1>{{learner_name}}</h1><p>{{title}}</p><small>{{certificate_number}}</small>');
        $certificate = $this->issue($this->courseA, $this->learner, ['learnerName' => 'Ada Lovelace']);

        $template = $this->certificates->findDefaultTemplate($this->tenantA);

        self::assertNotNull($template);

        $html = $this->renderer->render(
            $template->bodyHtml,
            $certificate->placeholders('https://example.test/verify/x'),
        );

        self::assertStringContainsString('<h1>Ada Lovelace</h1>', $html);
        self::assertStringContainsString('<p>Fire Safety</p>', $html);
        self::assertStringContainsString($certificate->identity->number, $html);
    }

    // --- tenant isolation -------------------------------------------------

    #[Test]
    public function another_tenant_sees_none_of_it(): void
    {
        $certificate = $this->issue($this->courseA, $this->learner);

        self::assertNull($this->certificates->find($this->tenantB, $certificate->id));
        self::assertSame(0, $this->certificates->paginate($this->tenantB, 1, 50)['total']);
        self::assertSame([], $this->certificates->forLearner($this->tenantB, $this->learner));
        self::assertSame([], $this->certificates->history($this->tenantB, $certificate->id));
    }

    #[Test]
    public function another_tenant_cannot_revoke_it(): void
    {
        $certificate = $this->issue($this->courseA, $this->learner);

        self::assertNull($this->certificates->revoke($this->tenantB, $certificate->id, 'Not yours.', $this->admin));
        self::assertSame(CertificateStatus::Active, $this->certificates->find($this->tenantA, $certificate->id)?->status);
    }

    #[Test]
    public function the_expiry_sweep_stays_within_its_tenant(): void
    {
        $certificate = $this->issue($this->courseA, $this->learner, [
            'validFrom'  => '2020-01-01',
            'validUntil' => '2020-12-31',
        ]);

        self::assertSame(0, $this->certificates->expireLapsed($this->tenantB));
        self::assertSame(CertificateStatus::Active, $this->certificates->find($this->tenantA, $certificate->id)?->status);
    }

    // --- helpers ----------------------------------------------------------

    private function template(string $name, string $body, bool $isDefault = true): CertificateTemplate
    {
        return $this->certificates->saveTemplate(CertificateTemplate::fromInput(
            '',
            $this->tenantA,
            ['name' => $name, 'bodyHtml' => $body, 'isDefault' => $isDefault],
            $this->renderer,
        ));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function issue(string $courseId, string $userId, array $overrides = []): Certificate
    {
        $year = (int) date('Y');

        return $this->certificates->issue(
            $this->tenantA,
            CertificateSource::Course,
            $courseId,
            $userId,
            [
                'identity' => CertificateIdentity::mint(
                    $this->certificates->tenantCode($this->tenantA),
                    $year,
                    $this->certificates->nextSequence($this->tenantA, $year),
                ),
                'learnerName' => 'A Learner',
                'title'       => $courseId === $this->courseA ? 'Fire Safety' : 'Manual Handling',
                ...$overrides,
            ],
            $this->admin,
        );
    }

    private function createTenant(string $prefix): string
    {
        $id = Uuid::uuid4()->toString();

        $this->db()->executeStatement(
            'INSERT INTO tenants (id, name, code, status) VALUES (:id, :name, :code, \'active\')',
            ['id' => $id, 'name' => $prefix . ' test tenant', 'code' => $prefix . '-' . substr($id, 0, 8)],
        );

        return $id;
    }

    private function createUser(string $tenantId, string $who): string
    {
        return (string) $this->db()->fetchOne(
            "INSERT INTO users (tenant_id, email, first_name, last_name, status)
             VALUES (:tenant, :email, :first, 'Tester', 'active') RETURNING id",
            [
                'tenant' => $tenantId,
                'email'  => $who . '-' . Uuid::uuid4()->toString() . '@crt.test',
                'first'  => ucfirst($who),
            ],
        );
    }

    private function createCourse(string $tenantId, string $title): string
    {
        return (string) $this->db()->fetchOne(
            'INSERT INTO courses (tenant_id, title) VALUES (:tenant, :title) RETURNING id',
            ['tenant' => $tenantId, 'title' => $title],
        );
    }

    private function db(): Connection
    {
        return self::$connection ?? self::fail('PostgreSQL is not reachable.');
    }

    private static function connect(): ?Connection
    {
        $root = dirname(__DIR__, 2);

        if (is_file($root . '/.env')) {
            \Dotenv\Dotenv::createImmutable($root)->safeLoad();
        }

        try {
            $connection = DriverManager::getConnection([
                'driver'   => 'pdo_pgsql',
                'host'     => $_ENV['DB_HOST'] ?? '127.0.0.1',
                'port'     => (int) ($_ENV['DB_PORT'] ?? 5432),
                'dbname'   => $_ENV['DB_NAME'] ?? 'central_lms',
                'user'     => $_ENV['DB_USER'] ?? 'central_lms',
                'password' => $_ENV['DB_PASSWORD'] ?? '',
            ], new \Doctrine\DBAL\Configuration());

            $connection->executeQuery('SELECT 1');

            return $connection;
        } catch (\Throwable) {
            return null;
        }
    }
}
