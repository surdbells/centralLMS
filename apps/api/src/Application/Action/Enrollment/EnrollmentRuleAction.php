<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Enrollment;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Application\Notification\Notifier;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Enrollment\EnrollmentRule;
use Central\Api\Domain\Enrollment\EnrollmentRuleRepository;
use Central\Api\Domain\Enrollment\RuleStatus;
use Central\Api\Domain\Enrollment\RuleSubject;
use Central\Api\Domain\Enrollment\SubjectEnroller;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Notification\NotificationType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Standing rules that put people onto a learning path or a training programme.
 *
 * A rule is not the same as enrolling everybody in Operations this afternoon —
 * the enrolment endpoints already do that. A rule keeps going: somebody who
 * joins Operations next month is enrolled by it too. Creating one applies it
 * at once, so an administrator sees it work rather than being told it will.
 *
 * One action for both subjects, because a rule behaves identically either way.
 * The only difference is which table the enrolment lands in, and that is
 * settled by {@see SubjectEnroller} rather than by a second copy of this file.
 *
 * All of it needs `enrollment.manage`. Deciding what other people must study
 * is the same authority as enrolling them.
 */
final class EnrollmentRuleAction
{
    /** @var array<string, SubjectEnroller> */
    private readonly array $enrollers;

    /**
     * @param iterable<SubjectEnroller> $enrollers
     */
    public function __construct(
        private readonly EnrollmentRuleRepository $rules,
        private readonly Notifier $notifier,
        iterable $enrollers,
    ) {
        $byKind = [];

        foreach ($enrollers as $enroller) {
            $byKind[$enroller->subject()->value] = $enroller;
        }

        $this->enrollers = $byKind;
    }

    // --- by subject -----------------------------------------------------------

    /**
     * @param array<string, string> $args
     */
    public function listForPath(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->listFor($request, $response, RuleSubject::Path, $args['id'] ?? '');
    }

    /**
     * @param array<string, string> $args
     */
    public function createForPath(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->createFor($request, $response, RuleSubject::Path, $args['id'] ?? '');
    }

    /**
     * @param array<string, string> $args
     */
    public function listForTraining(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->listFor($request, $response, RuleSubject::Training, $args['id'] ?? '');
    }

    /**
     * @param array<string, string> $args
     */
    public function createForTraining(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->createFor($request, $response, RuleSubject::Training, $args['id'] ?? '');
    }

    // --- by rule --------------------------------------------------------------

    /**
     * @param array<string, string> $args
     */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::EnrollmentManage);

        $tenantId = $this->tenantId($request);
        $existing = $this->requireRule($request, $args['ruleId'] ?? '');

        $saved = $this->rules->save(EnrollmentRule::fromInput(
            $existing->id,
            $tenantId,
            $existing->subjectKind,
            $existing->subjectId,
            $this->body($request),
            $existing->createdBy ?? $caller->id,
        ));

        return Json::ok($response, $saved->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::EnrollmentManage);

        $id = $args['ruleId'] ?? '';

        if (!$this->rules->delete($this->tenantId($request), $id)) {
            throw new EntityNotFound('Enrolment rule', $id);
        }

        return Json::noContent($response);
    }

    /**
     * @param array<string, string> $args
     */
    public function pause(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->moveTo($request, $response, $args, RuleStatus::Paused);
    }

    /**
     * @param array<string, string> $args
     */
    public function resume(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->moveTo($request, $response, $args, RuleStatus::Active);
    }

    /**
     * @param array<string, string> $args
     */
    public function cancel(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->moveTo($request, $response, $args, RuleStatus::Cancelled);
    }

    /**
     * Runs the rule now, without waiting for the sweep.
     *
     * @param array<string, string> $args
     */
    public function apply(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::EnrollmentManage);

        $tenantId = $this->tenantId($request);
        $rule = $this->requireRule($request, $args['ruleId'] ?? '');

        return Json::ok($response, [
            'ruleId'             => $rule->id,
            'enrollmentsCreated' => $this->rules->apply($tenantId, $rule->id),
        ]);
    }

    /**
     * Who the rule would cover, before anybody commits to it.
     *
     * @param array<string, string> $args
     */
    public function preview(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::EnrollmentManage);

        $rule = $this->requireRule($request, $args['ruleId'] ?? '');

        return Json::ok($response, $this->rules->preview($this->tenantId($request), $rule->id));
    }

    /**
     * @param array<string, string> $args
     */
    public function statistics(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::EnrollmentManage);

        $tenantId = $this->tenantId($request);
        $rule = $this->requireRule($request, $args['ruleId'] ?? '');

        return Json::ok($response, [
            ...$this->rules->statistics($tenantId, $rule->id),
            'pending' => $this->rules->pendingLearners($tenantId, $rule->id),
        ]);
    }

    /**
     * Reminds everybody this rule enrolled who has not finished.
     *
     * The screen has always had the button and it has never sent anything.
     * People who have finished are left out, because a reminder to somebody
     * who is done teaches them to ignore the next one.
     *
     * @param array<string, string> $args
     */
    public function remind(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::EnrollmentManage);

        $tenantId = $this->tenantId($request);
        $rule = $this->requireRule($request, $args['ruleId'] ?? '');

        $pending = $this->rules->pendingLearners($tenantId, $rule->id);
        $userIds = array_values(array_map(
            static fn (array $row): string => (string) $row['userId'],
            $pending,
        ));

        $sent = $this->notifier->notify(
            $tenantId,
            $userIds,
            NotificationType::EnrollmentDueSoon,
            [
                'subjectTitle' => $rule->subjectTitle ?? $rule->subjectKind->label(),
                // The rule's own deadline where it has one. An offset gives
                // each person a different date, so it is left off rather than
                // stated wrongly for everybody.
                'dueDate' => $rule->dueDate ?? '',
            ],
            $rule->subjectId,
        );

        return Json::ok($response, ['sent' => $sent, 'pending' => count($pending)]);
    }

    // --- internals ------------------------------------------------------------

    private function listFor(
        ServerRequestInterface $request,
        ResponseInterface $response,
        RuleSubject $kind,
        string $subjectId,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::EnrollmentManage);

        $tenantId = $this->tenantId($request);
        $this->requireSubject($tenantId, $kind, $subjectId);

        return Json::ok($response, array_map(
            static fn (EnrollmentRule $r): array => $r->toArray(),
            $this->rules->forSubject($tenantId, $kind, $subjectId),
        ));
    }

    /**
     * Creates a rule and runs it once.
     *
     * Applying immediately is the point: a rule that only takes effect at the
     * next sweep looks broken to whoever just made it, and the answer to "did
     * that work" should be on the screen, not tomorrow morning.
     */
    private function createFor(
        ServerRequestInterface $request,
        ResponseInterface $response,
        RuleSubject $kind,
        string $subjectId,
    ): ResponseInterface {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::EnrollmentManage);

        $tenantId = $this->tenantId($request);
        $this->requireSubject($tenantId, $kind, $subjectId);

        $rule = $this->rules->save(EnrollmentRule::fromInput(
            $this->uuid(),
            $tenantId,
            $kind,
            $subjectId,
            $this->body($request),
            $caller->id,
        ));

        $enrolled = $this->rules->apply($tenantId, $rule->id);
        $saved = $this->rules->find($tenantId, $rule->id) ?? $rule;

        return Json::created($response, [
            ...$saved->toArray(),
            'enrollmentsCreated' => $enrolled,
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    private function moveTo(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
        RuleStatus $status,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::EnrollmentManage);

        $id = $args['ruleId'] ?? '';
        $moved = $this->rules->setStatus($this->tenantId($request), $id, $status);

        if ($moved === null) {
            throw new EntityNotFound('Enrolment rule', $id);
        }

        return Json::ok($response, $moved->toArray());
    }

    private function requireRule(ServerRequestInterface $request, string $id): EnrollmentRule
    {
        $rule = $this->rules->find($this->tenantId($request), $id);

        if ($rule === null) {
            throw new EntityNotFound('Enrolment rule', $id);
        }

        return $rule;
    }

    private function requireSubject(string $tenantId, RuleSubject $kind, string $subjectId): void
    {
        $enroller = $this->enrollers[$kind->value] ?? null;

        if ($enroller === null || !$enroller->exists($tenantId, $subjectId)) {
            throw new EntityNotFound($kind->label(), $subjectId);
        }
    }

    private function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    private function tenantId(ServerRequestInterface $request): string
    {
        $user = $this->caller($request);

        if ($user->tenantId === null) {
            throw new AccessDenied(Permission::EnrollmentManage->value);
        }

        return $user->tenantId;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }

    private function uuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff),
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
        );
    }
}
