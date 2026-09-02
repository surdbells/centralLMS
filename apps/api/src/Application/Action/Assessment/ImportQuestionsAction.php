<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\Question;
use Central\Api\Domain\Assessment\QuestionType;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\ValidationFailed;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Ramsey\Uuid\Uuid;

/**
 * POST /v1/question-banks/{bankId}/questions/import
 *
 * Reads a .xlsx or .csv workbook on the server and creates one question per
 * row. Every row is validated by the same {@see Question::fromInput()} the
 * authoring form uses, so an imported question obeys exactly the rules a typed
 * one does. The response reports what went in and what was skipped and why.
 */
final class ImportQuestionsAction extends AssessmentAction
{
    private const MAX_BYTES = 15 * 1024 * 1024;

    /** Header aliases → the canonical column name the importer reads. */
    private const ALIASES = [
        'question_text' => 'text', 'question' => 'text', 'text' => 'text',
        'question_type' => 'type', 'type' => 'type',
        'difficulty' => 'difficulty',
        'points' => 'points', 'mark' => 'points', 'marks' => 'points',
        'options' => 'options', 'choices' => 'options', 'answers' => 'options',
        'correct_answer' => 'correct_answer', 'correct' => 'correct_answer', 'answer' => 'correct_answer',
        'explanation' => 'explanation', 'feedback' => 'explanation',
        'hint' => 'hint',
        'tags' => 'tags',
    ];

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentManage);

        $tenantId = $this->tenantId($request);
        $bank = $this->requireBank($request, $args['bankId'] ?? '');

        $body = $this->body($request);
        $onError = ($body['onError'] ?? 'skip') === 'abort' ? 'abort' : 'skip';
        $defaultDifficulty = trim((string) ($body['defaultDifficulty'] ?? ''));

        $upload = $this->upload($request);
        $name = (string) $upload->getClientFilename();
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $path = $this->stash($upload);

        try {
            if ($extension !== 'csv' && $this->isLegacyXls($path)) {
                throw ValidationFailed::fromArray([
                    'file' => 'That looks like an old .xls file. Save it as .xlsx and try again.',
                ]);
            }

            $reader = match ($extension) {
                'xlsx'        => new XlsxReader(),
                'csv', 'txt'  => new CsvReader(),
                default       => throw ValidationFailed::fromArray([
                    'file' => 'Upload a .xlsx or .csv file.',
                ]),
            };

            return $this->run(
                $reader,
                $path,
                $tenantId,
                $bank->id,
                $onError,
                $defaultDifficulty,
                $response,
            );
        } finally {
            @unlink($path);
        }
    }

    private function run(
        ReaderInterface $reader,
        string $path,
        string $tenantId,
        string $bankId,
        string $onError,
        string $defaultDifficulty,
        ResponseInterface $response,
    ): ResponseInterface {
        $reader->open($path);

        $total = 0;
        $imported = 0;
        /** @var list<array{row: int, message: string}> $errors */
        $errors = [];

        $columns = null; // header name → column index
        $rowNumber = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $rowObj) {
                $rowNumber++;
                $cells = $this->cells($rowObj);

                if ($columns === null) {
                    $columns = $this->mapHeader($cells);

                    if (!isset($columns['text']) || !isset($columns['type'])) {
                        $reader->close();

                        throw ValidationFailed::fromArray([
                            'file' => 'The header row needs at least "question_text" and "question_type" columns.',
                        ]);
                    }

                    continue;
                }

                if ($this->isBlank($cells)) {
                    continue;
                }

                $total++;

                try {
                    $input = $this->rowToInput($columns, $cells, $defaultDifficulty);
                    $question = Question::fromInput(Uuid::uuid4()->toString(), $tenantId, $bankId, $input);
                    $this->questions->createQuestion($question);
                    $imported++;
                } catch (ValidationFailed $e) {
                    $errors[] = ['row' => $rowNumber, 'message' => $this->firstMessage($e)];

                    if ($onError === 'abort') {
                        break 2;
                    }
                }
            }

            break; // first sheet only
        }

        $reader->close();

        return Json::ok($response, [
            'total'    => $total,
            'imported' => $imported,
            'skipped'  => $total - $imported,
            'errors'   => $errors,
        ]);
    }

    /**
     * Builds the {@see Question::fromInput()} payload from one spreadsheet row.
     *
     * @param array<string, int> $columns
     * @param list<string>       $cells
     *
     * @return array<string, mixed>
     */
    private function rowToInput(array $columns, array $cells, string $defaultDifficulty): array
    {
        $get = fn (string $key): string => isset($columns[$key]) ? trim($cells[$columns[$key]] ?? '') : '';

        $rawType = $get('type');
        $type = $rawType !== '' ? QuestionType::fromLegacy($rawType) : null;

        $difficulty = $get('difficulty');
        if ($difficulty === '' && $defaultDifficulty !== '') {
            $difficulty = $defaultDifficulty;
        }

        // Tags are split here rather than deferred to fromInput: in a CSV a
        // comma is the column delimiter, so a pipe-or-comma split (on the
        // already-isolated cell) is what lets both "math|basics" and a quoted
        // "math,basics" work.
        $tags = array_values(array_filter(
            array_map('trim', preg_split('/[|,]/', $get('tags')) ?: []),
            static fn (string $t): bool => $t !== '',
        ));

        $input = [
            'text'        => $get('text'),
            'type'        => $rawType,
            'difficulty'  => $difficulty,
            'points'      => $get('points') !== '' ? $get('points') : 1,
            'explanation' => $get('explanation'),
            'hint'        => $get('hint'),
            'tags'        => $tags,
        ];

        $correct = $get('correct_answer');
        $optionsCell = $get('options');

        if ($type !== null && $type->requiresOptions()) {
            $input['options'] = $this->buildOptions($type, $optionsCell, $correct);
        } elseif ($type === QuestionType::Numeric) {
            $input['numericAnswer'] = $correct;
        } elseif ($type === QuestionType::ShortAnswer || $type === QuestionType::LongAnswer) {
            $input['modelAnswer'] = $correct;
        }

        return $input;
    }

    /**
     * Splits the pipe-separated options cell and marks the correct one(s).
     *
     * The correct-answer cell holds the correct option text(s), pipe-separated;
     * a plain 1-based index is also accepted. Matching is case-insensitive.
     *
     * @return list<array<string, mixed>>
     */
    private function buildOptions(QuestionType $type, string $optionsCell, string $correct): array
    {
        $texts = array_values(array_filter(array_map('trim', explode('|', $optionsCell)), static fn (string $t): bool => $t !== ''));

        // True/false questions may omit an options column entirely.
        if ($texts === [] && $type === QuestionType::TrueFalse) {
            $texts = ['True', 'False'];
        }

        $correctTokens = array_values(array_map(
            static fn (string $t): string => mb_strtolower(trim($t)),
            array_filter(explode('|', $correct), static fn (string $t): bool => trim($t) !== ''),
        ));

        // Match the correct answer by option text first. Only if nothing matches
        // by text do we read the tokens as 1-based positions — otherwise a
        // numeric option ("4") and a positional token ("4" = the 4th option)
        // collide and two options come back correct.
        $byText = [];
        $anyTextMatch = false;

        foreach ($texts as $text) {
            $matched = in_array(mb_strtolower($text), $correctTokens, true);
            $byText[] = $matched;
            $anyTextMatch = $anyTextMatch || $matched;
        }

        $options = [];

        foreach ($texts as $index => $text) {
            $isCorrect = $anyTextMatch
                ? $byText[$index]
                : in_array((string) ($index + 1), $correctTokens, true);

            $options[] = [
                'text'      => $text,
                'isCorrect' => $isCorrect,
                'sortOrder' => $index,
            ];
        }

        return $options;
    }

    /**
     * @param list<string> $cells
     *
     * @return array<string, int>
     */
    private function mapHeader(array $cells): array
    {
        $columns = [];

        foreach ($cells as $index => $label) {
            $key = strtolower(trim($label));
            $key = preg_replace('/[\s\-]+/', '_', $key) ?? $key;

            if (isset(self::ALIASES[$key]) && !isset($columns[self::ALIASES[$key]])) {
                $columns[self::ALIASES[$key]] = $index;
            }
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function cells(Row $rowObj): array
    {
        return array_values(array_map(
            static function (mixed $value): string {
                if (is_string($value)) {
                    return trim($value);
                }
                if (is_int($value) || is_float($value)) {
                    return trim((string) $value);
                }
                if ($value instanceof \DateTimeInterface) {
                    return $value->format('Y-m-d');
                }

                return '';
            },
            $rowObj->toArray(),
        ));
    }

    /**
     * @param list<string> $cells
     */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }

    private function isLegacyXls(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $magic = (string) fread($handle, 8);
        fclose($handle);

        return $magic === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    }

    private function firstMessage(ValidationFailed $e): string
    {
        $violations = $e->details()['violations'] ?? [];

        foreach ((array) $violations as $messages) {
            foreach ((array) $messages as $message) {
                return (string) $message;
            }
        }

        return 'The row is invalid.';
    }

    private function upload(ServerRequestInterface $request): UploadedFileInterface
    {
        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? reset($files);

        if (!$file instanceof UploadedFileInterface) {
            throw ValidationFailed::fromArray(['file' => 'Attach a .xlsx or .csv file.']);
        }

        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw ValidationFailed::fromArray(['file' => 'The file did not arrive in one piece.']);
        }

        $size = $file->getSize();
        if ($size !== null && $size > self::MAX_BYTES) {
            throw ValidationFailed::fromArray(['file' => 'The file is larger than the 15 MB limit.']);
        }

        return $file;
    }

    private function stash(UploadedFileInterface $file): string
    {
        $path = tempnam(sys_get_temp_dir(), 'qimport_') ?: sys_get_temp_dir() . '/qimport_' . bin2hex(random_bytes(8));
        $file->moveTo($path);

        return $path;
    }
}
