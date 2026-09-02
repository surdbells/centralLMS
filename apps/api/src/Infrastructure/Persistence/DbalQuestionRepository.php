<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Assessment\Question;
use Central\Api\Domain\Assessment\QuestionBank;
use Central\Api\Domain\Assessment\QuestionOption;
use Central\Api\Domain\Assessment\QuestionRepository;
use Central\Api\Domain\Assessment\QuestionType;
use Central\Api\Domain\Shared\Difficulty;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class DbalQuestionRepository implements QuestionRepository
{
    private const BANK_COLUMNS = 'b.id, b.tenant_id, b.name, b.description, b.category_id,
                                  b.is_active, b.created_by, b.created_at, b.updated_at';

    private const QUESTION_COLUMNS = 'id, tenant_id, bank_id, type, text, points, explanation, hint,
                                      media_url, difficulty, numeric_answer, numeric_tolerance,
                                      model_answer, is_active, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    // --- banks ------------------------------------------------------------

    public function paginateBanks(string $tenantId, int $page, int $perPage, ?string $search = null): array
    {
        $where = 'b.tenant_id = :tenant AND b.deleted_at IS NULL';
        $params = ['tenant' => $tenantId];

        if ($search !== null && trim($search) !== '') {
            $where .= ' AND (b.name ILIKE :search OR b.description ILIKE :search)';
            $params['search'] = '%' . trim($search) . '%';
        }

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM question_banks b WHERE %s', $where),
            $params,
        );

        // The count comes from the same query rather than one per bank.
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, (
                     SELECT COUNT(*) FROM questions q
                     WHERE q.bank_id = b.id AND q.deleted_at IS NULL AND q.is_active
                 ) AS question_count
                 FROM question_banks b
                 WHERE %s ORDER BY b.name ASC LIMIT :limit OFFSET :offset',
                self::BANK_COLUMNS,
                $where,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(fn (array $r): QuestionBank => $this->hydrateBank($r), $rows),
            'total' => $total,
        ];
    }

    public function findBank(string $tenantId, string $id): ?QuestionBank
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, (
                     SELECT COUNT(*) FROM questions q
                     WHERE q.bank_id = b.id AND q.deleted_at IS NULL AND q.is_active
                 ) AS question_count
                 FROM question_banks b
                 WHERE b.id = :id AND b.tenant_id = :tenant AND b.deleted_at IS NULL',
                self::BANK_COLUMNS,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrateBank($row);
    }

    public function createBank(QuestionBank $bank): QuestionBank
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO question_banks (tenant_id, name, description, category_id, is_active, created_by)
             VALUES (:tenant, :name, :description, :category, :active, :createdBy)
             RETURNING id',
            [
                'tenant'      => $bank->tenantId,
                'name'        => $bank->name,
                'description' => $bank->description,
                'category'    => $bank->categoryId,
                'active'      => $bank->isActive ? 'true' : 'false',
                'createdBy'   => $bank->createdBy,
            ],
        );

        return $this->findBank($bank->tenantId, (string) $id)
            ?? throw new \RuntimeException('Newly created bank could not be read back.');
    }

    public function updateBank(QuestionBank $bank): QuestionBank
    {
        $this->connection->executeStatement(
            'UPDATE question_banks
             SET name = :name, description = :description, category_id = :category,
                 is_active = :active, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            [
                'name'        => $bank->name,
                'description' => $bank->description,
                'category'    => $bank->categoryId,
                'active'      => $bank->isActive ? 'true' : 'false',
                'id'          => $bank->id,
                'tenant'      => $bank->tenantId,
            ],
        );

        return $this->findBank($bank->tenantId, $bank->id)
            ?? throw new \RuntimeException('Updated bank could not be read back.');
    }

    public function softDeleteBank(string $tenantId, string $id): bool
    {
        return (bool) $this->connection->transactional(
            function (Connection $tx) use ($tenantId, $id): bool {
                $affected = $tx->executeStatement(
                    'UPDATE question_banks SET deleted_at = NOW(), updated_at = NOW()
                     WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                    ['id' => $id, 'tenant' => $tenantId],
                );

                if ($affected === 0) {
                    return false;
                }

                // The questions go with it. A question whose bank is gone is
                // unreachable but would still be counted by anything reading
                // questions directly.
                $tx->executeStatement(
                    'UPDATE questions SET deleted_at = NOW(), updated_at = NOW()
                     WHERE bank_id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                    ['id' => $id, 'tenant' => $tenantId],
                );

                return true;
            },
        );
    }

    public function bankHasName(string $tenantId, string $name, ?string $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM question_banks
                WHERE tenant_id = :tenant AND lower(name) = lower(:name) AND deleted_at IS NULL';
        $params = ['tenant' => $tenantId, 'name' => $name];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :exceptId';
            $params['exceptId'] = $exceptId;
        }

        return $this->connection->fetchOne($sql . ' LIMIT 1', $params) !== false;
    }

    // --- questions --------------------------------------------------------

    public function paginateQuestions(
        string $tenantId,
        string $bankId,
        int $page,
        int $perPage,
        ?string $search = null,
        ?QuestionType $type = null,
        ?string $tag = null,
    ): array {
        $where = 'tenant_id = :tenant AND bank_id = :bank AND deleted_at IS NULL';
        $params = ['tenant' => $tenantId, 'bank' => $bankId];

        if ($search !== null && trim($search) !== '') {
            $where .= ' AND text ILIKE :search';
            $params['search'] = '%' . trim($search) . '%';
        }

        if ($type !== null) {
            $where .= ' AND type = :type';
            $params['type'] = $type->value;
        }

        if ($tag !== null && trim($tag) !== '') {
            $where .= ' AND EXISTS (
                SELECT 1 FROM question_tags qt
                WHERE qt.question_id = questions.id AND qt.tenant_id = :tenant AND qt.tag = :tag
            )';
            $params['tag'] = trim($tag);
        }

        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM questions WHERE %s', $where),
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM questions WHERE %s ORDER BY created_at ASC LIMIT :limit OFFSET :offset',
                self::QUESTION_COLUMNS,
                $where,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => $this->withOptions($tenantId, array_map(
                fn (array $r): Question => $this->hydrateQuestion($r),
                $rows,
            )),
            'total' => $total,
        ];
    }

    public function findQuestion(string $tenantId, string $id): ?Question
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM questions WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                self::QUESTION_COLUMNS,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        if ($row === false) {
            return null;
        }

        return $this->withOptions($tenantId, [$this->hydrateQuestion($row)])[0];
    }

    public function findQuestionsByIds(string $tenantId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM questions
                 WHERE tenant_id = :tenant AND deleted_at IS NULL AND id IN (:ids)',
                self::QUESTION_COLUMNS,
            ),
            ['tenant' => $tenantId, 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        $questions = $this->withOptions($tenantId, array_map(
            fn (array $r): Question => $this->hydrateQuestion($r),
            $rows,
        ));

        $byId = [];

        foreach ($questions as $question) {
            $byId[$question->id] = $question;
        }

        return $byId;
    }

    public function createQuestion(Question $question): Question
    {
        /** @var string $id */
        $id = $this->connection->transactional(
            function (Connection $tx) use ($question): string {
                $id = (string) $tx->fetchOne(
                    'INSERT INTO questions (tenant_id, bank_id, type, text, points, explanation, hint,
                                            media_url, difficulty, numeric_answer, numeric_tolerance,
                                            model_answer, is_active)
                     VALUES (:tenant, :bank, :type, :text, :points, :explanation, :hint,
                             :media, :difficulty, :numericAnswer, :numericTolerance, :modelAnswer, :active)
                     RETURNING id',
                    $this->questionParams($question),
                );

                $this->writeOptions($tx, $question->tenantId, $id, $question->options);
                $this->writeTags($tx, $question->tenantId, $id, $question->tags);

                return $id;
            },
        );

        return $this->findQuestion($question->tenantId, $id)
            ?? throw new \RuntimeException('Newly created question could not be read back.');
    }

    public function updateQuestion(Question $question): Question
    {
        $this->connection->transactional(function (Connection $tx) use ($question): void {
            $affected = $tx->executeStatement(
                'UPDATE questions SET
                    type = :type, text = :text, points = :points, explanation = :explanation,
                    hint = :hint, media_url = :media, difficulty = :difficulty,
                    numeric_answer = :numericAnswer, numeric_tolerance = :numericTolerance,
                    model_answer = :modelAnswer, is_active = :active, updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                // bank_id is excluded: moving a question between banks would
                // change what every assessment drawing on those banks contains.
                array_diff_key($this->questionParams($question), ['bank' => null]) + ['id' => $question->id],
            );

            if ($affected === 0) {
                throw new \RuntimeException('Updated question could not be found.');
            }

            $tx->executeStatement(
                'DELETE FROM question_options WHERE question_id = :id AND tenant_id = :tenant',
                ['id' => $question->id, 'tenant' => $question->tenantId],
            );

            $this->writeOptions($tx, $question->tenantId, $question->id, $question->options);

            $tx->executeStatement(
                'DELETE FROM question_tags WHERE question_id = :id AND tenant_id = :tenant',
                ['id' => $question->id, 'tenant' => $question->tenantId],
            );

            $this->writeTags($tx, $question->tenantId, $question->id, $question->tags);
        });

        return $this->findQuestion($question->tenantId, $question->id)
            ?? throw new \RuntimeException('Updated question could not be read back.');
    }

    public function softDeleteQuestion(string $tenantId, string $id): bool
    {
        return $this->connection->executeStatement(
            'UPDATE questions SET deleted_at = NOW(), updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    public function moveQuestion(string $tenantId, string $questionId, string $fromBankId, string $toBankId): bool
    {
        return $this->connection->executeStatement(
            'UPDATE questions SET bank_id = :to, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND bank_id = :from AND deleted_at IS NULL',
            ['to' => $toBankId, 'id' => $questionId, 'tenant' => $tenantId, 'from' => $fromBankId],
        ) > 0;
    }

    public function distinctTags(string $tenantId, string $bankId): array
    {
        /** @var list<string> $tags */
        $tags = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT qt.tag
             FROM question_tags qt
             JOIN questions q ON q.id = qt.question_id
             WHERE q.tenant_id = :tenant AND q.bank_id = :bank AND q.deleted_at IS NULL
             ORDER BY qt.tag ASC',
            ['tenant' => $tenantId, 'bank' => $bankId],
        );

        return array_map(static fn (mixed $t): string => (string) $t, $tags);
    }

    public function bankStatistics(string $tenantId, string $bankId): array
    {
        $totals = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE is_active) AS active
             FROM questions
             WHERE tenant_id = :tenant AND bank_id = :bank AND deleted_at IS NULL",
            ['tenant' => $tenantId, 'bank' => $bankId],
        ) ?: ['total' => 0, 'active' => 0];

        return [
            'total'        => (int) $totals['total'],
            'active'       => (int) $totals['active'],
            'byType'       => $this->groupCounts(
                "SELECT type AS key, COUNT(*) AS count FROM questions
                 WHERE tenant_id = :tenant AND bank_id = :bank AND deleted_at IS NULL
                 GROUP BY type ORDER BY type",
                ['tenant' => $tenantId, 'bank' => $bankId],
                'type',
            ),
            'byDifficulty' => $this->groupCounts(
                "SELECT COALESCE(difficulty, 'unspecified') AS key, COUNT(*) AS count FROM questions
                 WHERE tenant_id = :tenant AND bank_id = :bank AND deleted_at IS NULL
                 GROUP BY COALESCE(difficulty, 'unspecified') ORDER BY key",
                ['tenant' => $tenantId, 'bank' => $bankId],
                'difficulty',
            ),
        ];
    }

    public function overallStatistics(string $tenantId): array
    {
        $totals = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE is_active) AS active
             FROM questions WHERE tenant_id = :tenant AND deleted_at IS NULL",
            ['tenant' => $tenantId],
        ) ?: ['total' => 0, 'active' => 0];

        $banks = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM question_banks WHERE tenant_id = :tenant AND deleted_at IS NULL',
            ['tenant' => $tenantId],
        );

        return [
            'totalQuestions'  => (int) $totals['total'],
            'activeQuestions' => (int) $totals['active'],
            'totalBanks'      => $banks,
            'byType'          => $this->groupCounts(
                "SELECT type AS key, COUNT(*) AS count FROM questions
                 WHERE tenant_id = :tenant AND deleted_at IS NULL
                 GROUP BY type ORDER BY type",
                ['tenant' => $tenantId],
                'type',
            ),
            'byDifficulty'    => $this->groupCounts(
                "SELECT COALESCE(difficulty, 'unspecified') AS key, COUNT(*) AS count FROM questions
                 WHERE tenant_id = :tenant AND deleted_at IS NULL
                 GROUP BY COALESCE(difficulty, 'unspecified') ORDER BY key",
                ['tenant' => $tenantId],
                'difficulty',
            ),
        ];
    }

    public function questionIsInUse(string $tenantId, string $id): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM assessment_questions
             WHERE question_id = :id AND tenant_id = :tenant LIMIT 1',
            ['id' => $id, 'tenant' => $tenantId],
        ) !== false;
    }

    // --- internals --------------------------------------------------------

    /**
     * Runs a `GROUP BY` count query and returns rows keyed under $keyName.
     *
     * @param array<string, mixed> $params
     *
     * @return list<array<string, int|string>>
     */
    private function groupCounts(string $sql, array $params, string $keyName): array
    {
        return array_map(
            static fn (array $r): array => [$keyName => (string) $r['key'], 'count' => (int) $r['count']],
            $this->connection->fetchAllAssociative($sql, $params),
        );
    }

    /**
     * Loads options for a batch of questions in one query.
     *
     * @param list<Question> $questions
     *
     * @return list<Question>
     */
    private function withOptions(string $tenantId, array $questions): array
    {
        if ($questions === []) {
            return [];
        }

        $ids = array_map(static fn (Question $q): string => $q->id, $questions);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, tenant_id, question_id, text, is_correct, feedback, sort_order, match_text
             FROM question_options
             WHERE tenant_id = :tenant AND question_id IN (:ids)
             ORDER BY sort_order ASC, text ASC',
            ['tenant' => $tenantId, 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        $byQuestion = [];

        foreach ($rows as $row) {
            $byQuestion[(string) $row['question_id']][] = new QuestionOption(
                id: (string) $row['id'],
                tenantId: (string) $row['tenant_id'],
                questionId: (string) $row['question_id'],
                text: (string) $row['text'],
                isCorrect: (bool) $row['is_correct'],
                feedback: is_string($row['feedback'] ?? null) && $row['feedback'] !== '' ? $row['feedback'] : null,
                sortOrder: (int) $row['sort_order'],
                matchText: is_string($row['match_text'] ?? null) && $row['match_text'] !== ''
                    ? $row['match_text']
                    : null,
            );
        }

        // Tags load in the same one-extra-query way the options do, so listing
        // a bank stays two round trips whatever the page size.
        $tagRows = $this->connection->fetchAllAssociative(
            'SELECT question_id, tag FROM question_tags
             WHERE tenant_id = :tenant AND question_id IN (:ids)
             ORDER BY tag ASC',
            ['tenant' => $tenantId, 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        );

        $tagsByQuestion = [];

        foreach ($tagRows as $row) {
            $tagsByQuestion[(string) $row['question_id']][] = (string) $row['tag'];
        }

        return array_map(
            static fn (Question $q): Question => $q
                ->withOptions($byQuestion[$q->id] ?? [])
                ->withTags($tagsByQuestion[$q->id] ?? []),
            $questions,
        );
    }

    /**
     * @param list<string> $tags
     */
    private function writeTags(Connection $tx, string $tenantId, string $questionId, array $tags): void
    {
        // The primary key on (question_id, tag) is case-sensitive, so "Safety"
        // and "safety" would both land. Dedupe case-insensitively here too — the
        // first spelling wins — so persistence stays clean whatever built the
        // question, not only the fromInput() path.
        $seen = [];

        foreach ($tags as $tag) {
            $tag = trim($tag);
            $key = mb_strtolower($tag);

            if ($tag === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $tx->executeStatement(
                'INSERT INTO question_tags (tenant_id, question_id, tag)
                 VALUES (:tenant, :question, :tag)
                 ON CONFLICT (question_id, tag) DO NOTHING',
                ['tenant' => $tenantId, 'question' => $questionId, 'tag' => $tag],
            );
        }
    }

    /**
     * @param list<QuestionOption> $options
     */
    private function writeOptions(Connection $tx, string $tenantId, string $questionId, array $options): void
    {
        foreach ($options as $position => $option) {
            $tx->executeStatement(
                'INSERT INTO question_options
                     (tenant_id, question_id, text, is_correct, feedback, sort_order, match_text)
                 VALUES (:tenant, :question, :text, :correct, :feedback, :sortOrder, :matchText)',
                [
                    'tenant'    => $tenantId,
                    'question'  => $questionId,
                    'text'      => $option->text,
                    'correct'   => $option->isCorrect ? 'true' : 'false',
                    'feedback'  => $option->feedback,
                    'sortOrder' => $option->sortOrder !== 0 ? $option->sortOrder : $position,
                    'matchText' => $option->matchText,
                ],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function questionParams(Question $question): array
    {
        return [
            'tenant'           => $question->tenantId,
            'bank'             => $question->bankId,
            'type'             => $question->type->value,
            'text'             => $question->text,
            'points'           => $question->points,
            'explanation'      => $question->explanation,
            'hint'             => $question->hint,
            'media'            => $question->mediaUrl,
            'difficulty'       => $question->difficulty?->value,
            'numericAnswer'    => $question->numericAnswer,
            'numericTolerance' => $question->numericTolerance,
            'modelAnswer'      => $question->modelAnswer,
            'active'           => $question->isActive ? 'true' : 'false',
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateBank(array $row): QuestionBank
    {
        return new QuestionBank(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            name: (string) $row['name'],
            description: $this->text($row['description'] ?? null),
            categoryId: $this->text($row['category_id'] ?? null),
            isActive: (bool) $row['is_active'],
            createdBy: $this->text($row['created_by'] ?? null),
            createdAt: $this->date($row['created_at'] ?? null),
            updatedAt: $this->date($row['updated_at'] ?? null),
            questionCount: (int) ($row['question_count'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateQuestion(array $row): Question
    {
        $type = QuestionType::tryFrom((string) $row['type']);

        if ($type === null) {
            // The check constraint makes this unreachable, and guessing a type
            // would mean grading an answer against the wrong rules.
            throw new \RuntimeException(sprintf('Unknown question type "%s".', $row['type']));
        }

        $difficulty = $this->text($row['difficulty'] ?? null);

        return new Question(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            bankId: (string) $row['bank_id'],
            type: $type,
            text: (string) $row['text'],
            points: (float) $row['points'],
            explanation: $this->text($row['explanation'] ?? null),
            hint: $this->text($row['hint'] ?? null),
            mediaUrl: $this->text($row['media_url'] ?? null),
            difficulty: $difficulty === null ? null : Difficulty::tryFrom($difficulty),
            numericAnswer: isset($row['numeric_answer']) ? (float) $row['numeric_answer'] : null,
            numericTolerance: isset($row['numeric_tolerance']) ? (float) $row['numeric_tolerance'] : null,
            modelAnswer: $this->text($row['model_answer'] ?? null),
            isActive: (bool) $row['is_active'],
            createdAt: $this->date($row['created_at'] ?? null),
            updatedAt: $this->date($row['updated_at'] ?? null),
        );
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
