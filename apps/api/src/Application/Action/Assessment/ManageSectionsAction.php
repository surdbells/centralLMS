<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Assessment;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Assessment\AssessmentSection;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

/**
 * Sections divide an assessment into named parts.
 *
 * GET    /v1/assessments/{id}/sections
 * POST   /v1/assessments/{id}/sections
 * PUT    /v1/assessments/{id}/sections/order
 * PUT    /v1/assessments/{id}/sections/{sectionId}
 * DELETE /v1/assessments/{id}/sections/{sectionId}
 *
 * Optional throughout: an assessment with no sections behaves exactly as
 * before, and its questions are asked in their own order.
 */
final class ManageSectionsAction extends AssessmentAction
{
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

        return Json::ok($response, array_map(
            static fn (AssessmentSection $s): array => $s->toArray(),
            $this->assessments->sectionsFor($this->tenantId($request), $assessment->id),
        ));
    }

    /**
     * @param array<string, string> $args
     */
    public function save(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->editable($request, $args);
        $assessmentId = $args['id'] ?? '';
        $sectionId = $args['sectionId'] ?? null;

        $existing = $sectionId === null
            ? null
            : ($this->assessments->findSection($tenantId, $assessmentId, $sectionId)
                ?? throw new EntityNotFound('Section', $sectionId));

        $section = AssessmentSection::fromInput(
            $existing->id ?? Uuid::uuid4()->toString(),
            $tenantId,
            $assessmentId,
            $existing === null
                ? $this->body($request)
                : [...$existing->toArray(), ...$this->body($request)],
        );

        if ($existing === null) {
            $created = $this->assessments->createSection($section);

            return Json::created(
                $response,
                $created->toArray(),
                sprintf('/v1/assessments/%s/sections/%s', $assessmentId, $created->id),
            );
        }

        return Json::ok($response, $this->assessments->updateSection($section)->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->editable($request, $args);
        $assessmentId = $args['id'] ?? '';
        $sectionId = $args['sectionId'] ?? '';

        // The questions under it survive, unfiled — deleting a heading must
        // not delete the work beneath it.
        return $this->assessments->deleteSection($tenantId, $assessmentId, $sectionId)
            ? Json::noContent($response)
            : throw new EntityNotFound('Section', $sectionId);
    }

    /**
     * @param array<string, string> $args
     */
    public function reorder(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->editable($request, $args);
        $assessmentId = $args['id'] ?? '';

        $raw = $this->body($request)['order'] ?? null;

        if (!is_array($raw) || $raw === []) {
            throw ValidationFailed::fromArray(['order' => 'Send the section ids in their new order.']);
        }

        $order = array_values(array_map(static fn (mixed $v): string => (string) $v, $raw));

        if (count(array_unique($order)) !== count($order)) {
            throw ValidationFailed::fromArray(['order' => 'The same section appears more than once.']);
        }

        $current = array_map(
            static fn (AssessmentSection $s): string => $s->id,
            $this->assessments->sectionsFor($tenantId, $assessmentId),
        );

        if (array_diff($order, $current) !== []) {
            throw ValidationFailed::fromArray([
                'order' => 'The list names a section that is not on this assessment.',
            ]);
        }

        if (array_diff($current, $order) !== []) {
            throw ValidationFailed::fromArray([
                'order' => 'The list must name every section.',
            ]);
        }

        $this->assessments->reorderSections($tenantId, $assessmentId, $order);

        return Json::ok($response, array_map(
            static fn (AssessmentSection $s): array => $s->toArray(),
            $this->assessments->sectionsFor($tenantId, $assessmentId),
        ));
    }

    /**
     * Files a question under a section, or unfiles it.
     *
     * PUT /v1/assessments/{id}/questions/{questionId}/section
     *
     * @param array<string, string> $args
     */
    public function assignQuestion(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->editable($request, $args);
        $assessmentId = $args['id'] ?? '';
        $questionId = $args['questionId'] ?? '';

        $raw = $this->body($request)['sectionId'] ?? null;
        $sectionId = is_string($raw) && trim($raw) !== '' ? trim($raw) : null;

        if ($sectionId !== null && $this->assessments->findSection($tenantId, $assessmentId, $sectionId) === null) {
            // Resolved within this assessment, so a section id from another
            // one is a 404 rather than a foreign-key error.
            throw new EntityNotFound('Section', $sectionId);
        }

        if (!$this->assessments->setQuestionSection($tenantId, $assessmentId, $questionId, $sectionId)) {
            throw new EntityNotFound('Question', $questionId);
        }

        return Json::ok($response, [
            'assessmentId' => $assessmentId,
            'questionId'   => $questionId,
            'sectionId'    => $sectionId,
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    private function editable(ServerRequestInterface $request, array $args): string
    {
        $this->caller($request)->mustHave(Permission::AssessmentManage);

        $assessment = $this->requireAssessment($request, $args['id'] ?? '');

        if (!$assessment->status->isEditable()) {
            throw new ConflictingState(
                'A published assessment cannot be restructured. Move it back to draft first.',
                'assessment_not_editable',
            );
        }

        return $this->tenantId($request);
    }
}
