<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Application\Action\Proctoring\ReviewProctoringAction;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Authz\Role;
use Central\Api\Domain\Proctoring\ModuleEntitlements;
use Central\Api\Domain\Proctoring\ProctoringRepository;
use Central\Api\Domain\Proctoring\ProctoringSession;
use Central\Api\Domain\Proctoring\ViolationSeverity;
use Central\Api\Domain\Proctoring\ViolationType;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The reviewer proctoring actions dispatched through the real container, with
 * the module entitled — proves autowiring, the permission + entitlement gates,
 * and the action → repository path for the sitting verdict, snapshot flag,
 * analytics and export.
 */
final class ProctoringReviewActionsTest extends TestCase
{
    private static ?ContainerInterface $container = null;

    private ProctoringRepository $proctoring;
    private Connection $connection;
    private string $tenantId;
    private AuthenticatedUser $reviewer;
    private string $attemptId;
    private ProctoringSession $session;
    private string $snapshotId;

    public static function setUpBeforeClass(): void
    {
        try {
            self::$container = (require dirname(__DIR__, 2) . '/config/bootstrap.php')();
            self::$container->get(Connection::class)->executeQuery('SELECT 1');
        } catch (\Throwable) {
            self::$container = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$container === null) {
            self::markTestSkipped('Container/PostgreSQL not available.');
        }

        $this->proctoring = self::$container->get(ProctoringRepository::class);
        $this->connection = self::$container->get(Connection::class);

        $this->tenantId = Uuid::uuid4()->toString();
        $this->connection->executeStatement(
            "INSERT INTO tenants (id, name, code, status) VALUES (:id, 'proc', :code, 'active')",
            ['id' => $this->tenantId, 'code' => 'prc-' . substr($this->tenantId, 0, 8)],
        );
        // Entitle the module, or every route 409s before it does anything.
        $this->proctoring->setEntitlement($this->tenantId, ModuleEntitlements::PROCTORING, true);

        $userId = $this->user();
        $assessmentId = (string) $this->connection->fetchOne(
            "INSERT INTO assessments (tenant_id, title, status) VALUES (:t, 'A', 'published') RETURNING id",
            ['t' => $this->tenantId],
        );
        $this->attemptId = (string) $this->connection->fetchOne(
            "INSERT INTO attempts (tenant_id, assessment_id, user_id, attempt_number, status, started_at)
             VALUES (:t, :a, :u, 1, 'in_progress', now()) RETURNING id",
            ['t' => $this->tenantId, 'a' => $assessmentId, 'u' => $userId],
        );

        $this->session = $this->proctoring->openSession($this->tenantId, $this->attemptId, $userId);
        $this->proctoring->recordViolation(
            $this->tenantId, $this->session, ViolationType::TabSwitch, ViolationSeverity::High, 'x', null, [],
        );
        $this->snapshotId = $this->proctoring->recordSnapshot($this->tenantId, $this->session, [
            'imageUrl' => '/proctoring/x.jpg', 'timeElapsedSeconds' => 10,
        ]);

        // A real user row, because a sitting verdict writes reviewed_by (an FK).
        $this->reviewer = $this->caller($this->user(), [Permission::ProctoringReview]);
    }

    protected function tearDown(): void
    {
        if (self::$container === null) {
            return;
        }
        foreach (['proctoring_snapshots', 'proctoring_violations', 'proctoring_sessions', 'attempts', 'assessments', 'users'] as $t) {
            $this->connection->executeStatement("DELETE FROM {$t} WHERE tenant_id = :id", ['id' => $this->tenantId]);
        }
        $this->connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $this->tenantId]);
    }

    #[Test]
    public function the_review_action_is_autowired(): void
    {
        self::assertInstanceOf(ReviewProctoringAction::class, self::$container->get(ReviewProctoringAction::class));
    }

    #[Test]
    public function reviewing_the_sitting_records_a_flagged_verdict(): void
    {
        $resp = (self::$container->get(ReviewProctoringAction::class))->reviewSession(
            $this->request('PUT', ['status' => 'flagged', 'notes' => 'Tab switching']),
            $this->response(),
            ['id' => $this->attemptId],
        );

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame('flagged', $this->json($resp)['reviewStatus']);
    }

    #[Test]
    public function an_unknown_verdict_is_rejected(): void
    {
        $this->expectException(\Central\Api\Domain\Exception\ValidationFailed::class);

        (self::$container->get(ReviewProctoringAction::class))->reviewSession(
            $this->request('PUT', ['status' => 'nonsense']),
            $this->response(),
            ['id' => $this->attemptId],
        );
    }

    #[Test]
    public function analytics_and_export_return_the_sitting(): void
    {
        $analytics = (self::$container->get(ReviewProctoringAction::class))->analytics(
            $this->request('GET'), $this->response(), ['id' => $this->attemptId],
        );
        self::assertSame(200, $analytics->getStatusCode());
        self::assertSame(1, $this->json($analytics)['violationCount']);

        $export = (self::$container->get(ReviewProctoringAction::class))->export(
            $this->request('GET'), $this->response(), ['id' => $this->attemptId],
        );
        self::assertStringContainsString('application/json', $export->getHeaderLine('Content-Type'));
        self::assertStringContainsString('attachment', $export->getHeaderLine('Content-Disposition'));
        $bundle = json_decode((string) $export->getBody(), true);
        self::assertArrayHasKey('snapshots', $bundle);
        self::assertCount(1, $bundle['violations']);
    }

    #[Test]
    public function flagging_a_snapshot_works_and_a_missing_one_is_404(): void
    {
        $resp = (self::$container->get(ReviewProctoringAction::class))->flagSnapshot(
            $this->request('PUT', ['flagged' => true, 'reason' => 'Face']),
            $this->response(),
            ['snapshotId' => $this->snapshotId],
        );
        self::assertSame(200, $resp->getStatusCode());
        self::assertTrue($this->json($resp)['flagged']);

        $this->expectException(\Central\Api\Domain\Exception\EntityNotFound::class);
        (self::$container->get(ReviewProctoringAction::class))->flagSnapshot(
            $this->request('PUT', ['flagged' => true]),
            $this->response(),
            ['snapshotId' => Uuid::uuid4()->toString()],
        );
    }

    #[Test]
    public function an_unentitled_tenant_is_blocked_before_anything_happens(): void
    {
        // A fresh tenant with the permission but no entitlement.
        $otherTenant = Uuid::uuid4()->toString();
        $this->connection->executeStatement(
            "INSERT INTO tenants (id, name, code, status) VALUES (:id, 'no', :code, 'active')",
            ['id' => $otherTenant, 'code' => 'no-' . substr($otherTenant, 0, 8)],
        );

        try {
            $caller = new AuthenticatedUser(Uuid::uuid4()->toString(), $otherTenant, 'x@x.test', [
                new Role(Uuid::uuid4()->toString(), null, 'r', 'R', [Permission::ProctoringReview], true),
            ]);

            $this->expectException(\Central\Api\Domain\Exception\ConflictingState::class);
            (self::$container->get(ReviewProctoringAction::class))->analytics(
                (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/v1/x')
                    ->withAttribute(AuthenticationMiddleware::ATTRIBUTE, $caller),
                $this->response(),
                ['id' => $this->attemptId],
            );
        } finally {
            $this->connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $otherTenant]);
        }
    }

    // --- helpers ----------------------------------------------------------

    private function user(): string
    {
        return (string) $this->connection->fetchOne(
            "INSERT INTO users (tenant_id, email, first_name, last_name, status)
             VALUES (:t, :e, 'L', 'T', 'active') RETURNING id",
            ['t' => $this->tenantId, 'e' => 'l-' . Uuid::uuid4()->toString() . '@prc.test'],
        );
    }

    /**
     * @param list<Permission> $permissions
     */
    private function caller(string $userId, array $permissions): AuthenticatedUser
    {
        return new AuthenticatedUser(
            id: $userId,
            tenantId: $this->tenantId,
            email: 'rev@prc.test',
            roles: [new Role(Uuid::uuid4()->toString(), null, 'r', 'R', $permissions, true)],
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, array $body = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'http://localhost/v1/x')
            ->withAttribute(AuthenticationMiddleware::ATTRIBUTE, $this->reviewer);

        return $body === [] ? $request : $request->withParsedBody($body);
    }

    private function response(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        if (is_array($decoded) && array_key_exists('data', $decoded) && is_array($decoded['data'])) {
            return $decoded['data'];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
