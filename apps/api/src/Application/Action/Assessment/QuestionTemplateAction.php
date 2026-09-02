<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\Permission;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v1/question-import-template
 *
 * A blank question workbook laid out the way {@see ImportQuestionsAction} reads
 * it: the header row plus a few worked examples across the common types. The
 * column names must stay in step with the importer's aliases, or a mislabelled
 * column is silently skipped.
 */
final class QuestionTemplateAction
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        AuthenticationMiddleware::require($request)->mustHave(Permission::AssessmentManage);

        $path = tempnam(sys_get_temp_dir(), 'q_tpl_') . '.xlsx';

        $writer = new Writer();
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Questions');

        $writer->addRow(Row::fromValues([
            'question_text', 'question_type', 'difficulty', 'points',
            'options', 'correct_answer', 'explanation', 'hint', 'tags',
        ]));

        $writer->addRow(Row::fromValues([
            'What is 2 + 2?', 'multiple_choice_single', 'beginner', '1',
            '2|3|4|5', '4', 'Basic arithmetic.', '', 'math,basics',
        ]));
        $writer->addRow(Row::fromValues([
            'Select the prime numbers.', 'multiple_choice_multiple', 'intermediate', '2',
            '2|3|4|6', '2|3', 'A prime has exactly two divisors.', '', 'math',
        ]));
        $writer->addRow(Row::fromValues([
            'The earth is flat.', 'true_false', 'beginner', '1',
            'True|False', 'False', '', '', 'science',
        ]));
        $writer->addRow(Row::fromValues([
            'How many continents are there?', 'numeric', 'beginner', '1',
            '', '7', '', 'Count them.', 'geography',
        ]));
        $writer->addRow(Row::fromValues([
            'Explain photosynthesis.', 'long_answer', 'professional', '5',
            '', 'Plants convert light into chemical energy...', '', '', 'biology',
        ]));

        $writer->close();

        $stream = fopen($path, 'rb');
        $body = $response->getBody();
        if ($stream !== false) {
            while (!feof($stream)) {
                $body->write((string) fread($stream, 8192));
            }
            fclose($stream);
        }
        @unlink($path);

        return $response
            ->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->withHeader('Content-Disposition', 'attachment; filename="question-import-template.xlsx"');
    }
}
