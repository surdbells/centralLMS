<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\QuestionBank;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\ConflictingState;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

/**
 * POST /v1/question-banks and PUT /v1/question-banks/{id}
 *
 * One action for both: a bank has four fields and no lifecycle, so splitting
 * create from update would be two files differing by one line.
 */
final class SaveBankAction extends AssessmentAction
{
    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = [],
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentManage);

        $tenantId = $this->tenantId($request);
        $id = $args['id'] ?? null;

        // Resolved first, so updating a bank in another tenant is a 404 rather
        // than a silent no-op.
        $existing = $id === null ? null : $this->requireBank($request, $id);

        $bank = QuestionBank::fromInput(
            $existing->id ?? Uuid::uuid4()->toString(),
            $tenantId,
            $existing === null
                ? $this->body($request)
                : [...$existing->toArray(), ...$this->body($request)],
        );

        if ($this->questions->bankHasName($tenantId, $bank->name, $existing?->id)) {
            throw new ConflictingState(
                sprintf('A question bank called "%s" already exists.', $bank->name),
                'duplicate_name',
            );
        }

        if ($existing === null) {
            $created = $this->questions->createBank(new QuestionBank(
                id: $bank->id,
                tenantId: $bank->tenantId,
                name: $bank->name,
                description: $bank->description,
                categoryId: $bank->categoryId,
                isActive: $bank->isActive,
                createdBy: $this->userId($request),
            ));

            return Json::created($response, $created->toArray(), '/v1/question-banks/' . $created->id);
        }

        return Json::ok($response, $this->questions->updateBank($bank)->toArray());
    }
}
