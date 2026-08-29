<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Competency;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Competency\AssessmentStatus;
use Central\Api\Domain\Competency\CompetencyAssessment;
use Central\Api\Domain\Competency\CompetencyRepository;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Competency assessments — a person measured against their job's profile.
 *
 * The flow the exercise runs: start an assessment, the learner records their
 * own levels, a subject-matter expert reviews and confirms, and it is
 * finalised. Reading needs `competency.view`; running and reviewing needs
 * `competency.assess`, which is the SME's permission.
 */
final class CompetencyAssessmentAction
{
    public function __construct(private readonly CompetencyRepository $repository)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::CompetencyView);

        $query = $request->getQueryParams();
        [$page, $perPage] = $this->paging($request);

        $result = $this->repository->assessments(
            $this->tenantId($request),
            $this->str($query['cycle'] ?? null),
            $this->str($query['function'] ?? null),
            $this->str($query['status'] ?? null),
            $this->str($query['search'] ?? null),
            $page,
            $perPage,
        );

        return Json::page(
            $response,
            array_map(static fn (CompetencyAssessment $a): array => $a->toArray(), $result['items']),
            $result['total'],
            $page,
            $perPage,
        );
    }

    /**
     * The caller's own assessments, for them to self-assess.
     *
     * Any signed-in person may read their own — this is the door the learner
     * comes through, so it does not ask for `competency.view`, which is the
     * assessor's permission for reading everyone's.
     */
    public function mine(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = $this->caller($request);
        [$page, $perPage] = $this->paging($request);

        $result = $this->repository->assessments(
            $this->tenantId($request),
            $this->str($request->getQueryParams()['cycle'] ?? null),
            null,
            null,
            null,
            $page,
            $perPage,
            $caller->id,
        );

        return Json::page(
            $response,
            array_map(static fn (CompetencyAssessment $a): array => $a->toArray(), $result['items']),
            $result['total'],
            $page,
            $perPage,
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $assessment = $this->require($request, $args['id'] ?? '');

        // The subject may read their own; everyone else needs the assessor's
        // reading permission.
        if ($this->caller($request)->id !== $assessment->userId) {
            $this->caller($request)->mustHave(Permission::CompetencyView);
        }

        return Json::ok($response, $assessment->toArray());
    }

    /**
     * Starts an assessment for a person against a job profile.
     */
    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::CompetencyAssess);

        $tenantId = $this->tenantId($request);
        $body = $this->body($request);

        $userId = trim((string) ($body['userId'] ?? ''));
        $profileId = trim((string) ($body['profileId'] ?? ''));
        $cycle = trim((string) ($body['cycle'] ?? '')) ?: 'default';

        $violations = [];
        if ($userId === '') {
            $violations['userId'] = 'Choose who is being assessed.';
        }

        if ($profileId === '') {
            $violations['profileId'] = 'Choose the job profile to assess against.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $profile = $this->repository->findProfile($tenantId, $profileId);

        if ($profile === null) {
            throw new EntityNotFound('Job competency profile', $profileId);
        }

        if ($profile->requirements === []) {
            // An assessment against a profile that requires nothing measures
            // nothing — the gap column would be empty and the readiness
            // undefined.
            throw new ConflictingState(
                'That job profile has no competencies to assess against yet.',
                'empty_profile',
            );
        }

        try {
            $assessment = $this->repository->startAssessment($tenantId, $userId, $profileId, $cycle);
        } catch (UniqueConstraintViolationException) {
            throw new ConflictingState(
                'This person already has an assessment against that profile for this cycle.',
                'duplicate_assessment',
            );
        }

        return Json::created($response, $assessment->toArray());
    }

    /**
     * Assigns a job profile to many people at once, starting an assessment for
     * each so it appears on their self-assessment.
     *
     * This is how an employee is linked to the profile of their role: the
     * assessment is the link, and the learner's `/mine` returns exactly the
     * ones started for them. Assigning in bulk keeps that from being one small
     * act repeated a hundred times. Someone who already has one for the profile
     * and cycle is skipped, not failed, so re-running the assignment to catch
     * new joiners is safe.
     */
    public function bulkStart(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::CompetencyAssess);

        $tenantId = $this->tenantId($request);
        $body     = $this->body($request);

        $profileId = trim((string) ($body['profileId'] ?? ''));
        $cycle     = trim((string) ($body['cycle'] ?? '')) ?: 'default';
        $userIds   = $this->ids($body['userIds'] ?? null);

        $violations = [];
        if ($profileId === '') {
            $violations['profileId'] = 'Choose the job profile to assign.';
        }
        if ($userIds === []) {
            $violations['userIds'] = 'Choose at least one person.';
        }
        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $profile = $this->repository->findProfile($tenantId, $profileId);
        if ($profile === null) {
            throw new EntityNotFound('Job competency profile', $profileId);
        }
        if ($profile->requirements === []) {
            throw new ConflictingState(
                'That job profile has no competencies to assess against yet.',
                'empty_profile',
            );
        }

        $created = 0;
        $skipped = 0;

        foreach ($userIds as $userId) {
            // Already linked for this profile and cycle — leave it be.
            if ($this->repository->findAssessmentFor($tenantId, $userId, $profileId, $cycle) !== null) {
                ++$skipped;
                continue;
            }
            try {
                $this->repository->startAssessment($tenantId, $userId, $profileId, $cycle);
                ++$created;
            } catch (UniqueConstraintViolationException) {
                ++$skipped;
            }
        }

        return Json::ok($response, [
            'created' => $created,
            'skipped' => $skipped,
            'total'   => count($userIds),
        ]);
    }

    /**
     * Records the learner's own levels and moves it to self-assessed.
     *
     * @param array<string, string> $args
     */
    public function selfAssess(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller     = $this->caller($request);
        $tenantId   = $this->tenantId($request);
        $assessment = $this->require($request, $args['id'] ?? '');

        // The person being assessed does this for themselves. An assessor may
        // also do it on their behalf; nobody else.
        if ($caller->id !== $assessment->userId) {
            $caller->mustHave(Permission::CompetencyAssess);
        }

        // Self-assessment belongs before the expert has reviewed. Re-opening a
        // reviewed or finalised assessment to a fresh self-claim would discard
        // a confirmed finding.
        if (
            $assessment->status !== AssessmentStatus::Draft
            && $assessment->status !== AssessmentStatus::SelfAssessed
        ) {
            throw new ConflictingState(
                'This assessment has already been reviewed.',
                'already_reviewed',
            );
        }

        $body   = $this->body($request);
        $levels = $this->levels($body['levels'] ?? null);

        if ($levels !== []) {
            $this->repository->recordLevels($tenantId, $assessment->id, 'self', $levels);
        }

        // The employee's own overall account of the evidence, if they gave one.
        if (array_key_exists('comment', $body)) {
            $this->repository->saveNarrative($tenantId, $assessment->id, [
                'self_comment' => $this->str($body['comment'] ?? null),
            ]);
        }

        $updated = $this->repository->setStatus(
            $tenantId,
            $assessment->id,
            AssessmentStatus::SelfAssessed,
            null,
            null,
        );

        return Json::ok($response, ($updated ?? $assessment)->toArray());
    }

    /**
     * Records the expert's confirmed levels and moves it to reviewed.
     *
     * @param array<string, string> $args
     */
    public function review(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->record($request, $response, $args, 'assessed', AssessmentStatus::Reviewed);
    }

    /**
     * @param array<string, string> $args
     */
    public function finalize(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::CompetencyAssess);

        $tenantId = $this->tenantId($request);
        $assessment = $this->require($request, $args['id'] ?? '');

        if ($assessment->status === AssessmentStatus::Draft) {
            throw new ConflictingState(
                'Nothing has been assessed yet.',
                'nothing_to_finalize',
            );
        }

        $comment = trim((string) ($this->body($request)['comment'] ?? '')) ?: null;

        $finalized = $this->repository->setStatus(
            $tenantId,
            $assessment->id,
            AssessmentStatus::Finalized,
            // The expert who finalises is recorded as the assessor, so a
            // finalised assessment always names who confirmed it.
            $assessment->assessorId ?? $caller->id,
            $comment,
        );

        return Json::ok($response, ($finalized ?? $assessment)->toArray());
    }

    /**
     * The individual competency report for one assessment.
     *
     * Everything a person's report shows, assembled: the assessment with its
     * levels and narrative, the descriptor for each competency so the report
     * can say what each level means, and the header facts about the person.
     * The subject may read their own; everyone else needs the view permission.
     *
     * @param array<string, string> $args
     */
    public function report(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId   = $this->tenantId($request);
        $assessment = $this->require($request, $args['id'] ?? '');

        if ($this->caller($request)->id !== $assessment->userId) {
            $this->caller($request)->mustHave(Permission::CompetencyView);
        }

        return Json::ok($response, [
            'assessment'  => $assessment->toArray(),
            'subject'     => $this->repository->reportSubject($tenantId, $assessment->userId),
            // competencyId => {knowledge, skill, mastery}, so the report can
            // show the proof points for the level a person is credited with.
            'descriptors' => $this->repository->descriptorsFor(
                $tenantId,
                array_map(static fn ($i): string => $i['competencyId'], $assessment->toArray()['items']),
            ),
        ]);
    }

    /**
     * Records the qualitative side of an individual's report: the manager's
     * and executive's notes, the placement decision, the two gaps paragraphs,
     * and the line executive's confirming levels. The assessor's work.
     *
     * @param array<string, string> $args
     */
    public function saveReport(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::CompetencyAssess);

        $tenantId   = $this->tenantId($request);
        $assessment = $this->require($request, $args['id'] ?? '');
        $body       = $this->body($request);

        // Only the narrative keys that were sent are written.
        $map = [
            'selfComment'              => 'self_comment',
            'lineManagerComment'       => 'line_manager_comment',
            'lineExecutiveDecision'    => 'line_executive_decision',
            'gapsIdentified'           => 'gaps_identified',
            'gapsClosureInterventions' => 'gaps_closure_interventions',
        ];

        $fields = [];
        foreach ($map as $in => $column) {
            if (array_key_exists($in, $body)) {
                $fields[$column] = $this->str($body[$in] ?? null);
            }
        }

        if ($fields !== []) {
            $this->repository->saveNarrative($tenantId, $assessment->id, $fields);
        }

        // The line executive's confirming levels, where any were given.
        $lineExec = $this->levels($body['lineExecutiveLevels'] ?? null);
        if ($lineExec !== []) {
            $this->repository->recordLevels($tenantId, $assessment->id, 'line_executive', $lineExec);
        }

        return Json::ok($response, $this->require($request, $assessment->id)->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::CompetencyAssess);

        if (!$this->repository->deleteAssessment($this->tenantId($request), $args['id'] ?? '')) {
            throw new EntityNotFound('Assessment', $args['id'] ?? '');
        }

        return Json::noContent($response);
    }

    // --- the outcome report ---------------------------------------------------

    public function outcome(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::CompetencyView);

        $tenantId = $this->tenantId($request);
        $cycle = $this->str($request->getQueryParams()['cycle'] ?? null);

        if ($cycle === null) {
            // The most recent cycle by default, so the report opens on
            // something rather than asking the reader to pick first.
            $cycles = $this->repository->cycles($tenantId);
            $cycle = $cycles[0] ?? 'default';
        }

        return Json::ok($response, [
            ...$this->repository->outcome($tenantId, $cycle),
            'cycles' => $this->repository->cycles($tenantId),
        ]);
    }

    // --- internals ------------------------------------------------------------

    /**
     * @param array<string, string> $args
     */
    private function record(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
        string $field,
        AssessmentStatus $status,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::CompetencyAssess);

        $tenantId = $this->tenantId($request);
        $assessment = $this->require($request, $args['id'] ?? '');

        $levels = $this->levels($this->body($request)['levels'] ?? null);

        if ($levels !== []) {
            $this->repository->recordLevels($tenantId, $assessment->id, $field, $levels);
        }

        $updated = $this->repository->setStatus(
            $tenantId,
            $assessment->id,
            $status,
            $field === 'assessed' ? $caller->id : null,
            null,
        );

        return Json::ok($response, ($updated ?? $assessment)->toArray());
    }

    private function require(ServerRequestInterface $request, string $id): CompetencyAssessment
    {
        $assessment = $this->repository->findAssessment($this->tenantId($request), $id);

        if ($assessment === null) {
            throw new EntityNotFound('Assessment', $id);
        }

        return $assessment;
    }

    /**
     * @return array<string, int>
     */
    private function levels(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];

        foreach ($raw as $competencyId => $level) {
            if (is_string($competencyId) && $competencyId !== '' && is_numeric($level)) {
                $out[$competencyId] = (int) $level;
            }
        }

        return $out;
    }

    /**
     * A de-duplicated list of the non-empty string ids in a request array.
     *
     * @return list<string>
     */
    private function ids(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $value) {
            $id = is_string($value) ? trim($value) : '';
            if ($id !== '' && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    private function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    private function tenantId(ServerRequestInterface $request): string
    {
        $user = $this->caller($request);

        if ($user->tenantId === null) {
            throw new AccessDenied(Permission::CompetencyView->value);
        }

        return $user->tenantId;
    }

    /**
     * @return array{int, int}
     */
    private function paging(ServerRequestInterface $request): array
    {
        $query = $request->getQueryParams();

        return [
            max(1, (int) ($query['page'] ?? 1)),
            max(1, min(200, (int) ($query['perPage'] ?? 50))),
        ];
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }
}
