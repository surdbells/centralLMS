<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Application\Action\Assessment\AssessmentDashboardAction;
use Central\Api\Application\Action\Assessment\AssessmentReportAction;
use Central\Api\Application\Action\Assessment\LearnerCatalogueAction;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Authz\Role;
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
 * The reporting/catalogue/dashboard actions built through the real container,
 * so this proves autowiring — including the Clock injected into the report and
 * dashboard actions — and that each dispatches against an empty tenant without
 * error. Auth is injected as a request attribute.
 */
final class AssessmentReportingActionsTest extends TestCase
{
    private static ?ContainerInterface $container = null;

    private string $tenantId;
    private AuthenticatedUser $admin;
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

        $this->tenantId = Uuid::uuid4()->toString();
        $this->admin = $this->caller([Permission::AssessmentManage]);
        $this->learner = $this->caller([Permission::AssessmentTake]);
    }

    #[Test]
    public function the_actions_are_autowired_including_their_clock(): void
    {
        foreach ([AssessmentReportAction::class, AssessmentDashboardAction::class, LearnerCatalogueAction::class] as $class) {
            self::assertInstanceOf($class, self::$container->get($class));
        }
    }

    #[Test]
    public function the_dashboard_returns_zeroed_scalars_and_six_month_buckets_for_an_empty_tenant(): void
    {
        $resp = (self::$container->get(AssessmentDashboardAction::class))(
            $this->request('GET', $this->admin),
            $this->response(),
        );

        self::assertSame(200, $resp->getStatusCode());
        $data = $this->json($resp);
        self::assertSame(0, $data['stats']['totalLearners']);
        self::assertCount(6, $data['monthlyTrends']);
        self::assertSame([], $data['recentActivity']);
    }

    #[Test]
    public function the_learner_report_returns_an_empty_list_for_an_empty_tenant(): void
    {
        $resp = (self::$container->get(AssessmentReportAction::class))->learners(
            $this->request('GET', $this->admin),
            $this->response(),
        );

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame([], $this->json($resp));
    }

    #[Test]
    public function the_catalogue_returns_an_empty_list_for_a_learner_with_nothing_assigned(): void
    {
        $resp = (self::$container->get(LearnerCatalogueAction::class))(
            $this->request('GET', $this->learner),
            $this->response(),
        );

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame([], $this->json($resp));
    }

    #[Test]
    public function the_learner_report_rejects_an_unknown_status_filter(): void
    {
        $this->expectException(\Central\Api\Domain\Exception\ValidationFailed::class);

        (self::$container->get(AssessmentReportAction::class))->learners(
            $this->request('GET', $this->admin, ['status' => 'nonsense']),
            $this->response(),
        );
    }

    #[Test]
    public function the_report_needs_manage_and_the_catalogue_needs_take(): void
    {
        // A learner (take only) cannot open the admin report.
        $this->expectException(\Central\Api\Domain\Exception\AccessDenied::class);

        (self::$container->get(AssessmentReportAction::class))->learners(
            $this->request('GET', $this->learner),
            $this->response(),
        );
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param list<Permission> $permissions
     */
    private function caller(array $permissions): AuthenticatedUser
    {
        $role = new Role(
            id: Uuid::uuid4()->toString(),
            tenantId: null,
            slug: 'r',
            name: 'R',
            permissions: $permissions,
            isSystem: true,
        );

        return new AuthenticatedUser(
            id: Uuid::uuid4()->toString(),
            tenantId: $this->tenantId,
            email: 'x@rpt.test',
            roles: [$role],
        );
    }

    /**
     * @param array<string, string> $query
     */
    private function request(string $method, AuthenticatedUser $caller, array $query = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest($method, 'http://localhost/v1/x')
            ->withAttribute(AuthenticationMiddleware::ATTRIBUTE, $caller)
            ->withQueryParams($query);
    }

    private function response(): ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    /**
     * @return array<mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $decoded = json_decode((string) $response->getBody(), true);

        if (is_array($decoded) && array_key_exists('data', $decoded)) {
            return is_array($decoded['data']) ? $decoded['data'] : [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
