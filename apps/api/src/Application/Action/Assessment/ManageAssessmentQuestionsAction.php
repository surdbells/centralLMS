<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\Question;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST   /v1/assessments/{id}/questions          attach one from a bank
 * DELETE /v1/assessments/{id}/questions/{questionId}
 * PUT    /v1/assessments/{id}/questions/order
 *
 * Attaching draws from a bank rather than creating: that is the whole reason
 * banks exist, and it is what makes a question reusable across assessments.
 */
final class ManageAssessmentQuestionsAction extends AssessmentAction
{
    /**
     * @param array<string, string> $args
     */
    public function attach(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->editableAssessment($request, $args);
        $assessmentId = $args['id'] ?? '';

        $body = $this->body($request);
        $questionId = trim((string) ($body['questionId'] ?? ''));

        if ($questionId === '') {
            throw ValidationFailed::fromArray(['questionId' => 'Name the question to add.']);
        }

        // Resolved in this tenant, so a question id from elsewhere is a 404
        // rather than a foreign-key error.
        if ($this->questions->findQuestion($tenantId, $questionId) === null) {
            throw new EntityNotFound('Question', $questionId);
        }

        $override = $body['pointsOverride'] ?? null;

        if ($override !== null && $override !== '' && (!is_numeric($override) || (float) $override < 0)) {
            throw ValidationFailed::fromArray(['pointsOverride' => 'Points cannot be negative.']);
        }

        $attached = $this->assessments->attachQuestion(
            $tenantId,
            $assessmentId,
            $questionId,
            $override === null || $override === '' ? null : (float) $override,
            isset($body['sortOrder']) && $body['sortOrder'] !== '' ? (int) $body['sortOrder'] : null,
            filter_var($body['isRequired'] ?? true, FILTER_VALIDATE_BOOL),
        );

        if (!$attached) {
            throw new ConflictingState(
                'That question is already on this assessment.',
                'question_already_attached',
            );
        }

        return Json::ok($response, $this->currentQuestions($tenantId, $assessmentId));
    }

    /**
     * @param array<string, string> $args
     */
    public function detach(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->editableAssessment($request, $args);
        $assessmentId = $args['id'] ?? '';
        $questionId = $args['questionId'] ?? '';

        if (!$this->assessments->detachQuestion($tenantId, $assessmentId, $questionId)) {
            throw new EntityNotFound('Question', $questionId);
        }

        return Json::ok($response, $this->currentQuestions($tenantId, $assessmentId));
    }

    /**
     * @param array<string, string> $args
     */
    public function reorder(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->editableAssessment($request, $args);
        $assessmentId = $args['id'] ?? '';

        $raw = $this->body($request)['order'] ?? null;

        if (!is_array($raw) || $raw === []) {
            throw ValidationFailed::fromArray(['order' => 'Send the question ids in their new order.']);
        }

        $order = array_values(array_map(static fn (mixed $v): string => (string) $v, $raw));

        if (count(array_unique($order)) !== count($order)) {
            throw ValidationFailed::fromArray(['order' => 'The same question appears more than once.']);
        }

        $current = array_map(
            static fn (array $row): string => $row['question']->id,
            $this->assessments->questionsFor($tenantId, $assessmentId),
        );

        $missing = array_diff($current, $order);
        $unknown = array_diff($order, $current);

        if ($unknown !== []) {
            throw ValidationFailed::fromArray([
                'order' => 'The list names a question that is not on this assessment.',
            ]);
        }

        if ($missing !== []) {
            // A partial list leaves the rest at their old positions, which is
            // how two questions end up sharing one.
            throw ValidationFailed::fromArray([
                'order' => 'The list must name every question on the assessment.',
            ]);
        }

        $this->assessments->reorderQuestions($tenantId, $assessmentId, $order);

        return Json::ok($response, $this->currentQuestions($tenantId, $assessmentId));
    }

    /**
     * Checks the permission and that the assessment can still be changed.
     *
     * @param array<string, string> $args
     */
    private function editableAssessment(ServerRequestInterface $request, array $args): string
    {
        $this->caller($request)->mustHave(Permission::AssessmentManage);

        $assessment = $this->requireAssessment($request, $args['id'] ?? '');

        if (!$assessment->status->isEditable()) {
            throw new ConflictingState(
                'A closed or archived assessment cannot be changed.',
                'assessment_not_editable',
            );
        }

        return $this->tenantId($request);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function currentQuestions(string $tenantId, string $assessmentId): array
    {
        return array_map(
            static function (array $row): array {
                /** @var Question $question */
                $question = $row['question'];

                return [
                    'id'        => $question->id,
                    'text'      => $question->text,
                    'type'      => $question->type->value,
                    'points'    => $question->pointsIn($row['pointsOverride']),
                    'sortOrder' => $row['sortOrder'],
                    'isRequired' => $row['isRequired'],
                ];
            },
            $this->assessments->questionsFor($tenantId, $assessmentId),
        );
    }
}
