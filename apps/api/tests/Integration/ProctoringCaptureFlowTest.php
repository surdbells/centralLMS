<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Application\Action\Proctoring\ProctoringSessionAction;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Authz\Role;
use Central\Api\Domain\Proctoring\ModuleEntitlements;
use Central\Api\Domain\Proctoring\ProctoringRepository;
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
 * The learner side of a proctored sitting, exactly as the live monitor drives
 * it: open the session, report the pre-flight so it goes 'active', then record
 * a violation and a snapshot. Proves the session must be activated first
 * (events are refused at 'pending_check') — the gap that made real sittings
 * capture nothing.
 */
final class ProctoringCaptureFlowTest extends TestCase
{
    private static ?ContainerInterface $container = null;

    private ProctoringRepository $proctoring;
    private Connection $connection;
    private string $tenantId;
    private string $attemptId;
    private AuthenticatedUser $learner;

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
            "INSERT INTO tenants (id, name, code, status) VALUES (:id, 'cap', :code, 'active')",
            ['id' => $this->tenantId, 'code' => 'cap-' . substr($this->tenantId, 0, 8)],
        );
        $this->proctoring->setEntitlement($this->tenantId, ModuleEntitlements::PROCTORING, true);

        $userId = (string) $this->connection->fetchOne(
            "INSERT INTO users (tenant_id, email, first_name, last_name, status)
             VALUES (:t, :e, 'L', 'T', 'active') RETURNING id",
            ['t' => $this->tenantId, 'e' => 'l-' . Uuid::uuid4()->toString() . '@cap.test'],
        );
        // A Standard-proctored assessment (proctoring_level drives isEnabled()).
        $assessmentId = (string) $this->connection->fetchOne(
            "INSERT INTO assessments (tenant_id, title, status, proctoring_level)
             VALUES (:t, 'Proctored', 'published', 'standard') RETURNING id",
            ['t' => $this->tenantId],
        );
        $this->attemptId = (string) $this->connection->fetchOne(
            "INSERT INTO attempts (tenant_id, assessment_id, user_id, attempt_number, status, started_at)
             VALUES (:t, :a, :u, 1, 'in_progress', now()) RETURNING id",
            ['t' => $this->tenantId, 'a' => $assessmentId, 'u' => $userId],
        );

        // The learner sitting the exam: assessment.take, and it is their attempt.
        $this->learner = new AuthenticatedUser(
            id: $userId,
            tenantId: $this->tenantId,
            email: 'l@cap.test',
            roles: [new Role(Uuid::uuid4()->toString(), null, 'r', 'R', [Permission::AssessmentTake], true)],
        );
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
    public function the_full_capture_pipeline_records_only_after_the_session_is_activated(): void
    {
        $action = self::$container->get(ProctoringSessionAction::class);

        // 1) Open — the session starts at pending_check.
        $open = $action->open($this->request('POST'), $this->response(), ['id' => $this->attemptId]);
        self::assertSame(200, $open->getStatusCode());
        $session = $this->proctoring->findSessionForAttempt($this->tenantId, $this->attemptId);
        self::assertNotNull($session);
        self::assertSame('pending_check', $session->status->value);

        // 2) A violation before activation is refused — this was the bug: real
        //    sittings sat at pending_check and captured nothing.
        try {
            $action->violation(
                $this->request('POST', ['type' => 'tab_switch']),
                $this->response(),
                ['id' => $this->attemptId],
            );
            self::fail('Expected the violation to be refused before activation.');
        } catch (\Central\Api\Domain\Exception\ConflictingState $e) {
            self::assertSame('session_not_active', $e->errorCode());
        }

        // 3) Report the pre-flight passed — the session goes active.
        $check = $action->check(
            $this->request('POST', ['passed' => true, 'data' => ['userAgent' => 'test']]),
            $this->response(),
            ['id' => $this->attemptId],
        );
        self::assertSame(200, $check->getStatusCode());
        self::assertSame('active', $this->proctoring->findSessionForAttempt($this->tenantId, $this->attemptId)?->status->value);

        // 4) Now a violation is accepted.
        $viol = $action->violation(
            $this->request('POST', ['type' => 'tab_switch', 'description' => 'Left the exam tab']),
            $this->response(),
            ['id' => $this->attemptId],
        );
        self::assertSame(200, $viol->getStatusCode());
        self::assertTrue($this->json($viol)['recorded']);

        // 5) And a webcam snapshot is stored.
        $snap = $action->snapshot(
            $this->request('POST', [
                'imageUrl' => 'data:image/jpeg;base64,AAAA',
                'kind' => 'periodic',
                'faceDetected' => true,
                'faceCount' => 1,
            ]),
            $this->response(),
            ['id' => $this->attemptId],
        );
        self::assertSame(201, $snap->getStatusCode());

        // The reviewer would now see one violation and one snapshot.
        $sessionId = $this->proctoring->findSessionForAttempt($this->tenantId, $this->attemptId)?->id ?? '';
        self::assertCount(1, $this->proctoring->violationsFor($this->tenantId, $sessionId));
        self::assertCount(1, $this->proctoring->snapshotsFor($this->tenantId, $sessionId));
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, array $body = []): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'http://localhost/v1/x')
            ->withAttribute(AuthenticationMiddleware::ATTRIBUTE, $this->learner);

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
