<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Application\Action\Assessment\DeleteQuestionAction;
use Central\Api\Application\Action\Assessment\DuplicateQuestionAction;
use Central\Api\Application\Action\Assessment\ImportQuestionsAction;
use Central\Api\Application\Action\Assessment\ListQuestionTagsAction;
use Central\Api\Application\Action\Assessment\MoveQuestionAction;
use Central\Api\Application\Action\Assessment\QuestionImageAction;
use Central\Api\Application\Action\Assessment\QuestionStatisticsAction;
use Central\Api\Application\Action\Assessment\QuestionTemplateAction;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Assessment\Question;
use Central\Api\Domain\Assessment\QuestionBank;
use Central\Api\Domain\Assessment\QuestionOption;
use Central\Api\Domain\Assessment\QuestionRepository;
use Central\Api\Domain\Assessment\QuestionType;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Authz\Role;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * The question-bank actions dispatched through the real DI container, so this
 * proves container autowiring (including MediaStorage into QuestionImageAction)
 * and the action → repository path, not just the SQL. Auth middleware is
 * bypassed by injecting the caller as a request attribute, the standard
 * functional-test technique.
 */
final class QuestionActionsFunctionalTest extends TestCase
{
    private static ?ContainerInterface $container = null;

    private QuestionRepository $questions;
    private Connection $connection;
    private AuthenticatedUser $caller;
    private string $tenantId;
    private QuestionBank $bankA;
    private QuestionBank $bankB;

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

        $this->questions = self::$container->get(QuestionRepository::class);
        $this->connection = self::$container->get(Connection::class);

        $this->tenantId = Uuid::uuid4()->toString();
        $this->connection->executeStatement(
            "INSERT INTO tenants (id, name, code, status) VALUES (:id, :name, :code, 'active')",
            ['id' => $this->tenantId, 'name' => 'qa-func', 'code' => 'qaf-' . substr($this->tenantId, 0, 8)],
        );

        $role = new Role(
            id: Uuid::uuid4()->toString(),
            tenantId: null,
            slug: 'tester',
            name: 'Tester',
            permissions: [Permission::AssessmentManage, Permission::AssessmentTake],
            isSystem: true,
        );
        $this->caller = new AuthenticatedUser(
            id: Uuid::uuid4()->toString(),
            tenantId: $this->tenantId,
            email: 'tester@qa.test',
            roles: [$role],
        );

        $this->bankA = $this->questions->createBank(new QuestionBank(id: '', tenantId: $this->tenantId, name: 'A ' . substr(Uuid::uuid4()->toString(), 0, 8)));
        $this->bankB = $this->questions->createBank(new QuestionBank(id: '', tenantId: $this->tenantId, name: 'B ' . substr(Uuid::uuid4()->toString(), 0, 8)));
    }

    protected function tearDown(): void
    {
        if (self::$container === null) {
            return;
        }

        $this->connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $this->tenantId]);
    }

    #[Test]
    public function every_new_action_is_autowired_by_the_container(): void
    {
        foreach ([
            DeleteQuestionAction::class,
            DuplicateQuestionAction::class,
            MoveQuestionAction::class,
            ListQuestionTagsAction::class,
            QuestionStatisticsAction::class,
            QuestionImageAction::class,
            ImportQuestionsAction::class,
            QuestionTemplateAction::class,
        ] as $class) {
            self::assertInstanceOf($class, self::$container->get($class));
        }
    }

    #[Test]
    public function duplicate_then_delete_round_trips_through_the_actions(): void
    {
        $q = $this->makeQuestion('Original', tags: ['alpha']);

        // Duplicate
        $dupResp = (self::$container->get(DuplicateQuestionAction::class))(
            $this->request('POST'),
            $this->response(),
            ['bankId' => $this->bankA->id, 'id' => $q->id],
        );
        self::assertSame(201, $dupResp->getStatusCode());
        $dup = $this->json($dupResp);
        self::assertSame('Original (copy)', $dup['text']);
        self::assertSame(['alpha'], $dup['tags']);
        self::assertNotSame($q->id, $dup['id']);

        // The bank now holds two questions.
        self::assertSame(2, $this->questions->paginateQuestions($this->tenantId, $this->bankA->id, 1, 50)['total']);

        // Delete the copy.
        $delResp = (self::$container->get(DeleteQuestionAction::class))(
            $this->request('DELETE'),
            $this->response(),
            ['bankId' => $this->bankA->id, 'id' => (string) $dup['id']],
        );
        self::assertSame(204, $delResp->getStatusCode());
        self::assertNull($this->questions->findQuestion($this->tenantId, (string) $dup['id']));
        self::assertSame(1, $this->questions->paginateQuestions($this->tenantId, $this->bankA->id, 1, 50)['total']);
    }

    #[Test]
    public function move_action_relocates_the_question_to_the_target_bank(): void
    {
        $q = $this->makeQuestion('Movable');

        $resp = (self::$container->get(MoveQuestionAction::class))(
            $this->request('PUT', ['targetBankId' => $this->bankB->id]),
            $this->response(),
            ['bankId' => $this->bankA->id, 'id' => $q->id],
        );

        self::assertSame(200, $resp->getStatusCode());
        self::assertSame($this->bankB->id, $this->json($resp)['bankId']);
        self::assertSame($this->bankB->id, $this->questions->findQuestion($this->tenantId, $q->id)?->bankId);
    }

    #[Test]
    public function tags_and_statistics_actions_return_the_expected_shapes(): void
    {
        $this->makeQuestion('One', tags: ['x', 'y']);
        $this->makeQuestion('Two', tags: ['x']);

        $tagsResp = (self::$container->get(ListQuestionTagsAction::class))(
            $this->request('GET'),
            $this->response(),
            ['bankId' => $this->bankA->id],
        );
        self::assertSame(['x', 'y'], $this->json($tagsResp)['tags']);

        $stats = new QuestionStatisticsAction(
            self::$container->get(\Central\Api\Domain\Assessment\AssessmentRepository::class),
            $this->questions,
            self::$container->get(\Central\Api\Domain\Assessment\AttemptRepository::class),
        );
        $bankResp = $stats->bank($this->request('GET'), $this->response(), ['bankId' => $this->bankA->id]);
        self::assertSame(2, $this->json($bankResp)['total']);

        $overallResp = $stats->overall($this->request('GET'), $this->response());
        self::assertGreaterThanOrEqual(2, $this->json($overallResp)['totalQuestions']);
        self::assertGreaterThanOrEqual(2, $this->json($overallResp)['totalBanks']);
    }

    #[Test]
    public function importing_a_csv_creates_the_valid_rows_and_reports_the_bad_ones(): void
    {
        $csv = "question_text,question_type,difficulty,points,options,correct_answer,explanation,hint,tags\n"
            . "What is 2+2?,multiple_choice_single,beginner,1,2|3|4|5,4,Basic maths,,math|basics\n"
            . "The sky is green.,true_false,beginner,1,True|False,False,,,science\n"
            . "How many legs on a spider?,numeric,beginner,1,,8,,,biology\n"
            . ",multiple_choice_single,beginner,1,a|b,a,,,\n"; // blank text -> invalid

        $upload = $this->uploadFromString($csv, 'questions.csv', 'text/csv');

        $request = $this->request('POST')->withUploadedFiles(['file' => $upload]);
        $resp = (self::$container->get(ImportQuestionsAction::class))(
            $request,
            $this->response(),
            ['bankId' => $this->bankA->id],
        );

        self::assertSame(200, $resp->getStatusCode());
        $summary = $this->json($resp);
        self::assertSame(4, $summary['total']);
        self::assertSame(3, $summary['imported']);
        self::assertSame(1, $summary['skipped']);
        self::assertCount(1, $summary['errors']);
        self::assertSame(5, $summary['errors'][0]['row']); // header is row 1

        // The multiple-choice row stored its options with the right one marked.
        $result = $this->questions->paginateQuestions($this->tenantId, $this->bankA->id, 1, 50);
        self::assertSame(3, $result['total']);

        $mc = null;
        foreach ($result['items'] as $item) {
            if ($item->text === 'What is 2+2?') {
                $mc = $item;
            }
        }
        self::assertNotNull($mc);
        self::assertSame(['basics', 'math'], $mc->tags); // stored, listed alphabetically
        $correct = array_values(array_filter($mc->options, static fn ($o) => $o->isCorrect));
        self::assertCount(1, $correct);
        self::assertSame('4', $correct[0]->text);
    }

    #[Test]
    public function another_bank_id_in_the_path_cannot_touch_the_question(): void
    {
        $q = $this->makeQuestion('Guarded');

        $this->expectException(\Central\Api\Domain\Exception\EntityNotFound::class);

        (self::$container->get(DeleteQuestionAction::class))(
            $this->request('DELETE'),
            $this->response(),
            ['bankId' => $this->bankB->id, 'id' => $q->id], // wrong bank
        );
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param list<string> $tags
     */
    private function makeQuestion(string $text, array $tags = []): Question
    {
        return $this->questions->createQuestion(new Question(
            id: '',
            tenantId: $this->tenantId,
            bankId: $this->bankA->id,
            type: QuestionType::MultipleChoiceSingle,
            text: $text,
            options: [
                new QuestionOption(id: '', tenantId: $this->tenantId, questionId: '', text: 'a', isCorrect: true),
                new QuestionOption(id: '', tenantId: $this->tenantId, questionId: '', text: 'b', isCorrect: false),
            ],
            tags: $tags,
        ));
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, ?array $body = null): ServerRequestInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, 'http://localhost/v1/x')
            ->withAttribute(AuthenticationMiddleware::ATTRIBUTE, $this->caller);

        return $body === null ? $request : $request->withParsedBody($body);
    }

    private function response(): \Psr\Http\Message\ResponseInterface
    {
        return (new ResponseFactory())->createResponse();
    }

    private function uploadFromString(string $content, string $name, string $type): \Slim\Psr7\UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'qtest_');
        file_put_contents($path, $content);

        return new \Slim\Psr7\UploadedFile($path, $name, $type, strlen($content), UPLOAD_ERR_OK);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(\Psr\Http\Message\ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);

        // Responses wrap payloads under `data`; unwrap when present.
        if (is_array($decoded) && array_key_exists('data', $decoded) && is_array($decoded['data'])) {
            return $decoded['data'];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
