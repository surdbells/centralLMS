<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\Question;
use Central\Api\Domain\Assessment\QuestionOption;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

/**
 * POST /v1/question-banks/{bankId}/questions/{id}/duplicate
 *
 * Copies a question, its options and its tags into the same bank. The copy is
 * a fresh question with fresh option ids; it is attached to no assessment.
 */
final class DuplicateQuestionAction extends AssessmentAction
{
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
        $id = $args['id'] ?? '';

        $source = $this->questions->findQuestion($tenantId, $id);

        if ($source === null || $source->bankId !== $bank->id) {
            throw new EntityNotFound('Question', $id);
        }

        $copy = new Question(
            id: Uuid::uuid4()->toString(),
            tenantId: $tenantId,
            bankId: $bank->id,
            type: $source->type,
            text: $source->text . ' (copy)',
            points: $source->points,
            explanation: $source->explanation,
            hint: $source->hint,
            mediaUrl: $source->mediaUrl,
            difficulty: $source->difficulty,
            numericAnswer: $source->numericAnswer,
            numericTolerance: $source->numericTolerance,
            modelAnswer: $source->modelAnswer,
            isActive: $source->isActive,
            options: array_map(
                static fn (QuestionOption $o): QuestionOption => new QuestionOption(
                    id: '',
                    tenantId: $tenantId,
                    questionId: '',
                    text: $o->text,
                    isCorrect: $o->isCorrect,
                    feedback: $o->feedback,
                    sortOrder: $o->sortOrder,
                    matchText: $o->matchText,
                ),
                $source->options,
            ),
            tags: $source->tags,
        );

        $created = $this->questions->createQuestion($copy);

        return Json::created(
            $response,
            $created->toArrayForReview(),
            sprintf('/v1/question-banks/%s/questions/%s', $bank->id, $created->id),
        );
    }
}
