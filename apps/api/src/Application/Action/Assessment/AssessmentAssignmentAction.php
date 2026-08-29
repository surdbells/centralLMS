<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\AssessmentAssignment;
use Central\Api\Domain\Assessment\AssessmentAssignmentRepository;
use Central\Api\Domain\Assessment\AssessmentRepository;
use Central\Api\Domain\Assessment\AttemptRepository;
use Central\Api\Domain\Assessment\QuestionRepository;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Assigning an assessment — who must take it, and by when.
 *
 *   GET    /v1/assessments/{id}/assignments
 *   POST   /v1/assessments/{id}/assignments
 *   DELETE /v1/assessments/{id}/assignments/{assignmentId}
 *   GET    /v1/assessments/assignment-targets
 */
final class AssessmentAssignmentAction extends AssessmentAction
{
    private const TYPES = ['user', 'department', 'team', 'branch', 'all'];

    public function __construct(
        AssessmentRepository $assessments,
        QuestionRepository $questions,
        AttemptRepository $attempts,
        private readonly AssessmentAssignmentRepository $assignments,
    ) {
        parent::__construct($assessments, $questions, $attempts);
    }

    /**
     * @param array<string, string> $args
     */
    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentManage);
        $assessment = $this->requireAssessment($request, $args['id'] ?? '');

        $assignments = $this->assignments->forAssessment($this->tenantId($request), $assessment->id);

        return Json::ok($response, array_map(
            static fn (AssessmentAssignment $a): array => $a->toArray(),
            $assignments,
        ));
    }

    /**
     * @param array<string, string> $args
     */
    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentManage);
        $tenantId = $this->tenantId($request);
        $assessment = $this->requireAssessment($request, $args['id'] ?? '');

        $body = $this->body($request);
        $type = strtolower(trim((string) ($body['assignmentType'] ?? '')));
        $targetId = trim((string) ($body['targetId'] ?? '')) ?: null;

        $violations = [];
        if (!in_array($type, self::TYPES, true)) {
            $violations['assignmentType'] = 'Choose a person, department, team, branch, or everyone.';
        }
        if ($type !== 'all' && $type !== '' && $targetId === null) {
            $violations['targetId'] = 'Choose who to assign it to.';
        }
        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        try {
            $assignment = $this->assignments->create(
                $tenantId,
                $assessment->id,
                $type,
                $type === 'all' ? null : $targetId,
                $this->parseDate($body['dueDate'] ?? null),
                $this->userId($request),
            );
        } catch (UniqueConstraintViolationException) {
            throw new ConflictingState('That target is already assigned to this assessment.', 'duplicate_assignment');
        }

        return Json::created($response, $assignment->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::AssessmentManage);
        $tenantId = $this->tenantId($request);
        $assessment = $this->requireAssessment($request, $args['id'] ?? '');

        if (!$this->assignments->delete($tenantId, $assessment->id, $args['assignmentId'] ?? '')) {
            throw new EntityNotFound('Assignment', $args['assignmentId'] ?? '');
        }

        return Json::noContent($response);
    }

    public function targets(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::AssessmentManage);

        return Json::ok($response, $this->assignments->targets($this->tenantId($request)));
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
