<?php

declare(strict_types=1);

namespace Central\Api\Tests\Integration;

use Central\Api\Domain\Assessment\Question;
use Central\Api\Domain\Assessment\QuestionBank;
use Central\Api\Domain\Assessment\QuestionOption;
use Central\Api\Domain\Assessment\QuestionType;
use Central\Api\Domain\Shared\Difficulty;
use Central\Api\Infrastructure\Persistence\DbalQuestionRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

/**
 * The question-bank parity features — tags, move, statistics — against a real
 * PostgreSQL. Skipped when the database is unreachable.
 */
#[CoversClass(DbalQuestionRepository::class)]
final class DbalQuestionExtrasTest extends TestCase
{
    private static ?Connection $connection = null;

    private DbalQuestionRepository $questions;
    private string $tenantA;
    private string $tenantB;
    private QuestionBank $bankA;
    private QuestionBank $bankB;

    public static function setUpBeforeClass(): void
    {
        self::$connection = self::connect();
    }

    protected function setUp(): void
    {
        if (self::$connection === null) {
            self::markTestSkipped('PostgreSQL is not reachable — start it with: pnpm up');
        }

        $this->questions = new DbalQuestionRepository(self::$connection);
        $this->tenantA = $this->createTenant('qx-a');
        $this->tenantB = $this->createTenant('qx-b');

        $this->bankA = $this->questions->createBank(
            new QuestionBank(id: '', tenantId: $this->tenantA, name: 'Bank A ' . substr(Uuid::uuid4()->toString(), 0, 8)),
        );
        $this->bankB = $this->questions->createBank(
            new QuestionBank(id: '', tenantId: $this->tenantA, name: 'Bank B ' . substr(Uuid::uuid4()->toString(), 0, 8)),
        );
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

            self::$connection->executeStatement('DELETE FROM tenants WHERE id = :id', ['id' => $tenantId]);
        }
    }

    #[Test]
    public function tags_round_trip_and_are_deduplicated_case_insensitively(): void
    {
        $question = $this->questions->createQuestion($this->shortAnswer(
            'Name a colour.',
            tags: ['Safety', 'safety', ' colour ', 'colour', ''],
        ));

        $read = $this->questions->findQuestion($this->tenantA, $question->id);

        self::assertNotNull($read);
        // "Safety"/"safety" collapse to one; blanks dropped; first spelling kept.
        self::assertSame(['Safety', 'colour'], $read->tags);
    }

    #[Test]
    public function updating_a_question_replaces_its_tags_wholesale(): void
    {
        $question = $this->questions->createQuestion($this->shortAnswer('Q?', tags: ['old']));

        $this->questions->updateQuestion($question->withTags(['new1', 'new2']));

        $read = $this->questions->findQuestion($this->tenantA, $question->id);

        self::assertNotNull($read);
        self::assertSame(['new1', 'new2'], $read->tags);
    }

    #[Test]
    public function distinct_tags_lists_a_banks_tags_alphabetically(): void
    {
        $this->questions->createQuestion($this->shortAnswer('One', tags: ['zebra', 'apple']));
        $this->questions->createQuestion($this->shortAnswer('Two', tags: ['mango', 'apple']));

        self::assertSame(['apple', 'mango', 'zebra'], $this->questions->distinctTags($this->tenantA, $this->bankA->id));
        self::assertSame([], $this->questions->distinctTags($this->tenantA, $this->bankB->id));
    }

    #[Test]
    public function paginating_by_tag_returns_only_the_tagged_questions(): void
    {
        $this->questions->createQuestion($this->shortAnswer('Tagged', tags: ['physics']));
        $this->questions->createQuestion($this->shortAnswer('Untagged'));

        $result = $this->questions->paginateQuestions($this->tenantA, $this->bankA->id, 1, 50, null, null, 'physics');

        self::assertSame(1, $result['total']);
        self::assertSame('Tagged', $result['items'][0]->text);
    }

    #[Test]
    public function moving_a_question_changes_its_bank_and_carries_its_tags(): void
    {
        $question = $this->questions->createQuestion($this->shortAnswer('Movable', tags: ['t1']));

        self::assertTrue(
            $this->questions->moveQuestion($this->tenantA, $question->id, $this->bankA->id, $this->bankB->id),
        );

        $read = $this->questions->findQuestion($this->tenantA, $question->id);
        self::assertNotNull($read);
        self::assertSame($this->bankB->id, $read->bankId);
        self::assertSame(['t1'], $read->tags);

        // The stale from-bank no longer matches, so a repeat move is a no-op.
        self::assertFalse(
            $this->questions->moveQuestion($this->tenantA, $question->id, $this->bankA->id, $this->bankB->id),
        );
    }

    #[Test]
    public function moving_across_tenants_is_refused_by_the_from_scope(): void
    {
        $question = $this->questions->createQuestion($this->shortAnswer('Mine'));

        // Another tenant cannot move it: the tenant + from-bank guard fails.
        self::assertFalse(
            $this->questions->moveQuestion($this->tenantB, $question->id, $this->bankA->id, $this->bankB->id),
        );
    }

    #[Test]
    public function bank_statistics_count_by_type_and_difficulty(): void
    {
        $this->questions->createQuestion($this->shortAnswer('A', difficulty: Difficulty::Beginner));
        $this->questions->createQuestion($this->shortAnswer('B', difficulty: Difficulty::Beginner));
        $this->questions->createQuestion($this->multipleChoice('C', difficulty: Difficulty::Professional));

        $stats = $this->questions->bankStatistics($this->tenantA, $this->bankA->id);

        self::assertSame(3, $stats['total']);
        self::assertSame(3, $stats['active']);

        $byType = $this->keyed($stats['byType'], 'type');
        self::assertSame(2, $byType['short_answer']);
        self::assertSame(1, $byType['multiple_choice_single']);

        $byDiff = $this->keyed($stats['byDifficulty'], 'difficulty');
        self::assertSame(2, $byDiff['beginner']);
        self::assertSame(1, $byDiff['professional']);
    }

    #[Test]
    public function overall_statistics_span_every_bank_in_the_tenant(): void
    {
        $this->questions->createQuestion($this->shortAnswer('A'));
        $this->questions->createQuestion($this->multipleChoice('B'));
        // A question in the other bank counts toward the tenant total.
        $this->questions->createQuestion(new Question(
            id: '', tenantId: $this->tenantA, bankId: $this->bankB->id,
            type: QuestionType::ShortAnswer, text: 'C',
        ));

        $stats = $this->questions->overallStatistics($this->tenantA);

        self::assertSame(3, $stats['totalQuestions']);
        self::assertSame(3, $stats['activeQuestions']);
        self::assertSame(2, $stats['totalBanks']);
    }

    #[Test]
    public function short_answer_accepted_answers_persist_and_grade(): void
    {
        // The shape the question form now sends: accepted answers as correct
        // options, plus a model answer.
        $question = $this->questions->createQuestion(\Central\Api\Domain\Assessment\Question::fromInput(
            \Ramsey\Uuid\Uuid::uuid4()->toString(),
            $this->tenantA,
            $this->bankA->id,
            [
                'type' => 'short_answer',
                'text' => 'Capital of France?',
                'modelAnswer' => 'Paris',
                'options' => [
                    ['text' => 'Paris', 'isCorrect' => true],
                    ['text' => 'City of Light', 'isCorrect' => true],
                ],
            ],
        ));

        $read = $this->questions->findQuestion($this->tenantA, $question->id);
        self::assertNotNull($read);
        self::assertCount(2, $read->options);

        // The grader marks the second accepted option (case-insensitively) right.
        $result = (new \Central\Api\Domain\Assessment\Grader())->grade($read, ['text' => 'city of light'], 1.0);
        self::assertTrue($result->isCorrect);
    }

    #[Test]
    public function a_hotspot_question_with_regions_is_saveable(): void
    {
        // requiresOptions() is true for hotspot, so it needs >= 2 options; the
        // form now sends one per region.
        $question = $this->questions->createQuestion(\Central\Api\Domain\Assessment\Question::fromInput(
            \Ramsey\Uuid\Uuid::uuid4()->toString(),
            $this->tenantA,
            $this->bankA->id,
            [
                'type' => 'hotspot',
                'text' => 'Click the nucleus.',
                'mediaUrl' => '/question-images/x',
                'options' => [
                    ['text' => 'Nucleus', 'isCorrect' => true, 'matchText' => '{"shape":"circle","x":10,"y":10,"width":20,"height":20}'],
                    ['text' => 'Membrane', 'isCorrect' => false, 'matchText' => '{"shape":"rect","x":0,"y":0,"width":5,"height":5}'],
                ],
            ],
        ));

        $read = $this->questions->findQuestion($this->tenantA, $question->id);
        self::assertNotNull($read);
        self::assertCount(2, $read->options);
        // The geometry round-trips through matchText for the editor to restore.
        self::assertStringContainsString('circle', (string) $read->options[0]->matchText);
    }

    // --- fixtures ---------------------------------------------------------

    /**
     * @param list<string> $tags
     */
    private function shortAnswer(string $text, array $tags = [], ?Difficulty $difficulty = null): Question
    {
        return new Question(
            id: '',
            tenantId: $this->tenantA,
            bankId: $this->bankA->id,
            type: QuestionType::ShortAnswer,
            text: $text,
            difficulty: $difficulty,
            tags: $tags,
        );
    }

    private function multipleChoice(string $text, ?Difficulty $difficulty = null): Question
    {
        return new Question(
            id: '',
            tenantId: $this->tenantA,
            bankId: $this->bankA->id,
            type: QuestionType::MultipleChoiceSingle,
            text: $text,
            difficulty: $difficulty,
            options: [
                new QuestionOption(id: '', tenantId: $this->tenantA, questionId: '', text: 'a', isCorrect: true),
                new QuestionOption(id: '', tenantId: $this->tenantA, questionId: '', text: 'b', isCorrect: false),
            ],
        );
    }

    /**
     * @param list<array<string, int|string>> $rows
     *
     * @return array<string, int>
     */
    private function keyed(array $rows, string $keyName): array
    {
        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row[$keyName]] = (int) $row['count'];
        }

        return $out;
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
