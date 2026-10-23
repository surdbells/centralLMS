<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\People;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Authz\Role;
use Central\Api\Domain\Authz\RoleRepository;
use Central\Api\Domain\CustomField\CustomFieldDefinitionRepository;
use Central\Api\Domain\CustomField\CustomFieldValues;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Application\Action\Auth\PasswordResetAction;
use Central\Api\Domain\Identity\PasswordTokenPurpose;
use Central\Api\Domain\Identity\UserStatus;
use Central\Api\Domain\People\Person;
use Central\Api\Domain\People\PersonFilters;
use Central\Api\Domain\People\PersonInput;
use Central\Api\Domain\People\PersonRepository;
use Central\Api\Domain\Settings\PasswordPolicy;
use Central\Api\Domain\Settings\SettingsRepository;
use Central\Api\Infrastructure\Security\PasswordHasher;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The people in an organisation.
 *
 * GET    /v1/users
 * POST   /v1/users
 * GET    /v1/users/statistics
 * GET    /v1/users/instructors
 * GET    /v1/users/{id}
 * PUT    /v1/users/{id}
 * PATCH  /v1/users/{id}/status
 * DELETE /v1/users/{id}
 * POST   /v1/users/{id}/password
 *
 * Two things the legacy did not do, both of which mattered:
 *
 *  - **Every query is scoped to the caller's tenant.** `User::getById(int $id)`
 *    ran `SELECT * FROM users WHERE id = ?` with no tenant clause, and
 *    `single.php` handed it whatever the request body said — so any id returned
 *    any organisation's record.
 *  - **A role assignment is checked against the tenant.** Roles are per-tenant
 *    or built-in; assigning an id from another organisation is refused rather
 *    than written and later ignored.
 */
final class ManagePeopleAction extends PeopleAction
{
    public function __construct(
        PersonRepository $people,
        private readonly RoleRepository $roles,
        private readonly PasswordHasher $hasher,
        private readonly SettingsRepository $settings,
        private readonly PasswordResetAction $passwords,
        private readonly CustomFieldDefinitionRepository $customFields,
    ) {
        parent::__construct($people);
    }

    /**
     * The password rules this organisation has set.
     *
     * The legacy declared five `password_*` settings and enforced none of
     * them: nothing in the backend read `password_min_length`, and
     * `change_password.php` accepted a single character. This is the first
     * place any of them does anything.
     */
    private function policyFor(ServerRequestInterface $request): PasswordPolicy
    {
        return PasswordPolicy::from($this->settings->forTenant($this->tenantId($request)));
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::UserView);

        $listing = $this->listing($request);
        $filters = PersonFilters::fromQuery($request->getQueryParams());

        $result = $this->people->paginate(
            $this->tenantId($request),
            $filters,
            $listing['page'],
            $listing['perPage'],
        );

        return Json::page(
            $response,
            array_map(static fn (Person $p): array => $p->toArray(), $result['items']),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
        );
    }

    /**
     * Headline counts for the people screen.
     */
    public function statistics(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::UserView);

        return Json::ok($response, $this->people->statistics($this->tenantId($request)));
    }

    /**
     * The instructors, for a course's instructor picker.
     */
    public function instructors(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Needs only `course.manage`: whoever is authoring a course has to be
        // able to choose who teaches it, without also being trusted with the
        // people records.
        $caller = $this->caller($request);

        if (!$caller->canAny([Permission::UserView, Permission::CourseManage, Permission::IltSessionManage])) {
            throw new AccessDenied(Permission::UserView->value);
        }

        $tenantId = $this->tenantId($request);
        $listing  = $this->listing($request);

        $instructor = $this->roles->find($tenantId, 'instructor');

        if ($instructor === null) {
            return Json::page($response, [], 0, $listing['page'], $listing['perPage']);
        }

        $result = $this->people->paginate(
            $tenantId,
            PersonFilters::fromQuery($request->getQueryParams())->withOnly($instructor->id),
            $listing['page'],
            $listing['perPage'],
        );

        return Json::page(
            $response,
            array_map(
                // A picker needs a name and an id, not somebody's hire date.
                static fn (Person $p): array => [
                    'id'       => $p->id,
                    'fullName' => $p->fullName(),
                    'email'    => $p->email,
                ],
                $result['items'],
            ),
            $result['total'],
            $listing['page'],
            $listing['perPage'],
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
        $id = $args['id'] ?? '';

        // Reading your own record needs no permission; reading anybody else's
        // does. Without this the id in every list response is a way to read a
        // colleague's phone number and hire date.
        if ($id !== $this->userId($request)) {
            $this->caller($request)->mustHave(Permission::UserView);
        }

        $person = $this->require($request, $id);

        return Json::ok($response, [
            ...$person->toArray(),
            'customFields' => $this->people->customFieldsFor($this->tenantId($request), (string) $person->id),
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::UserManage);

        $tenantId = $this->tenantId($request);
        $body     = $this->body($request);

        $violations = [];
        $person     = PersonInput::toPerson($tenantId, null, $body, $violations);

        // A password is optional: an invited account sets its own later, which
        // is the only way an administrator does not learn somebody's password.
        $password = isset($body['password']) ? (string) $body['password'] : null;

        if ($password !== null && $password !== '') {
            PersonInput::checkPassword($password, $violations, policy: $this->policyFor($request));
        }

        // Validated against the definitions before the person is written, so a
        // bad custom value fails the whole request rather than leaving a new
        // account with half its profile.
        $customValues = CustomFieldValues::validate(
            $this->customFields->allForTenant($tenantId, true),
            (array) ($body['customFields'] ?? []),
            [],
            $violations,
        );

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        \assert($person instanceof Person);

        // Somebody given a password can use it. Leaving them `invited` — the
        // right default for an account that will set its own — would mean an
        // administrator creates an account, hands over a password, and the
        // person is then refused at the sign-in screen.
        if (
            $password !== null
            && $password !== ''
            && !isset($body['status'])
            && $person->status === UserStatus::Invited
        ) {
            $person = $person->withStatus(UserStatus::Active);
        }

        $this->mustBeAssignable($tenantId, $person->roleIds);

        if ($this->people->emailExists($tenantId, $person->email)) {
            throw new ConflictingState(
                sprintf('%s already has an account here.', $person->email),
                'email_taken',
            );
        }

        $created = $this->people->create(
            $person,
            $password === null || $password === '' ? null : $this->hasher->hash($password),
            $this->userId($request),
        );

        if ($customValues !== [] && $created->id !== null) {
            $this->people->saveCustomFields($tenantId, $created->id, $customValues);
        }

        // The invitation `UserStatus::Invited` has always promised.
        //
        // Its own message tells the person to check their email for it, an
        // administrator creating an account left them in exactly that
        // state, and nothing ever sent one — so an invited account could
        // not be signed into and had no way of becoming one that could.
        $invited = $created->id !== null
            && $created->status === UserStatus::Invited
            && $this->passwords->send(
                $request,
                $created->id,
                $tenantId,
                PasswordTokenPurpose::Invitation,
            );

        return Json::created(
            $response,
            [...$created->toArray(), 'customFields' => $customValues, 'invitationSent' => $invited],
            '/v1/users/' . $created->id,
        );
    }

    /**
     * @param array<string, string> $args
     */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::UserManage);

        $tenantId = $this->tenantId($request);
        $existing = $this->require($request, $args['id'] ?? '');

        $violations = [];

        // The existing record is the base, so a form that sends three fields
        // does not blank the other seventeen.
        $body = $this->body($request);

        $person = PersonInput::toPerson(
            $tenantId,
            $existing->id,
            $body,
            $violations,
            $existing,
        );

        // Only touched when the request actually carries custom fields. An
        // edit that does not mention them — a status change, a renamed
        // department from a caller that sends nothing else — leaves the stored
        // values exactly as they are, rather than revalidating (and possibly
        // rejecting) a profile the caller never meant to change. A partial save
        // keeps what it does not mention, so the existing map is the base.
        $customValues = null;

        if (array_key_exists('customFields', $body)) {
            $customValues = CustomFieldValues::validate(
                $this->customFields->allForTenant($tenantId, true),
                (array) $body['customFields'],
                $this->people->customFieldsFor($tenantId, (string) $existing->id),
                $violations,
            );
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        \assert($person instanceof Person);

        $this->mustBeAssignable($tenantId, $person->roleIds);

        if ($this->people->emailExists($tenantId, $person->email, $existing->id)) {
            throw new ConflictingState(
                sprintf('%s already has an account here.', $person->email),
                'email_taken',
            );
        }

        if (
            $person->managerId !== null
            && $this->people->wouldFormManagementCycle($tenantId, (string) $existing->id, $person->managerId)
        ) {
            // Two people managing each other is representable in one update
            // each, and it makes every reporting query walk in circles.
            throw new ConflictingState(
                'That would make two people manage each other.',
                'management_cycle',
            );
        }

        $updated = $this->people->update($person);

        if ($customValues !== null) {
            $this->people->saveCustomFields($tenantId, (string) $existing->id, $customValues);
        }

        return Json::ok($response, [
            ...$updated->toArray(),
            'customFields' => $customValues
                ?? $this->people->customFieldsFor($tenantId, (string) $existing->id),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function setStatus(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::UserManage);

        $id       = $args['id'] ?? '';
        $tenantId = $this->tenantId($request);

        $requested = strtolower(trim((string) ($this->body($request)['status'] ?? '')));
        $status    = UserStatus::tryFrom($requested);

        if ($status === null) {
            throw ValidationFailed::fromArray([
                'status' => 'Choose one of: ' . implode(', ', array_map(
                    static fn (UserStatus $s): string => $s->value,
                    UserStatus::cases(),
                )) . '.',
            ]);
        }

        // Suspending yourself locks you out of the screen you did it from, and
        // in a one-administrator tenant locks everybody out permanently.
        if ($id === $this->userId($request) && !$status->canSignIn()) {
            throw new ConflictingState(
                'You cannot suspend your own account.',
                'cannot_suspend_self',
            );
        }

        $updated = $this->people->setStatus($tenantId, $id, $status)
            ?? throw new \Central\Api\Domain\Exception\EntityNotFound('User', $id);

        return Json::ok($response, $updated->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::UserManage);

        $id = $args['id'] ?? '';

        if ($id === $this->userId($request)) {
            throw new ConflictingState('You cannot delete your own account.', 'cannot_delete_self');
        }

        if (!$this->people->softDelete($this->tenantId($request), $id)) {
            throw new \Central\Api\Domain\Exception\EntityNotFound('User', $id);
        }

        return Json::noContent($response);
    }

    /**
     * Sets somebody's password.
     *
     * An administrator resetting an account, or a person changing their own —
     * in which case the current password has to be given, because a session
     * left open on a shared machine should not be enough to lock the owner out.
     *
     * @param array<string, string> $args
     */
    public function setPassword(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->tenantId($request);
        $id       = $args['id'] ?? '';
        $body     = $this->body($request);
        $own      = $id === $this->userId($request);

        if (!$own) {
            $this->caller($request)->mustHave(Permission::UserManage);
        }

        $password   = (string) ($body['password'] ?? '');
        $violations = [];

        PersonInput::checkPassword($password, $violations, policy: $this->policyFor($request));

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        if ($own) {
            $this->mustKnowCurrentPassword($tenantId, $id, (string) ($body['currentPassword'] ?? ''));
        }

        $this->require($request, $id);

        $this->people->setPasswordHash($tenantId, $id, $this->hasher->hash($password));

        return Json::ok($response, ['id' => $id, 'passwordChanged' => true]);
    }

    /**
     * The roles that can be assigned in this tenant.
     */
    public function roles(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::UserView);

        return Json::ok(
            $response,
            array_map(
                static fn (Role $role): array => [
                    'id'          => $role->id,
                    'slug'        => $role->slug,
                    'name'        => $role->name,
                    'isSystem'    => $role->isSystem,
                    'permissions' => array_map(
                        static fn (Permission $p): string => $p->value,
                        $role->permissions,
                    ),
                ],
                $this->roles->findAllForTenant($this->tenantId($request)),
            ),
        );
    }

    /**
     * Refuses a role from another organisation.
     *
     * @param list<string> $roleIds
     */
    private function mustBeAssignable(string $tenantId, array $roleIds): void
    {
        $available = [];

        foreach ($this->roles->findAllForTenant($tenantId) as $role) {
            $available[$role->id] = true;
        }

        $unknown = array_values(array_filter(
            $roleIds,
            static fn (string $id): bool => !isset($available[$id]),
        ));

        if ($unknown !== []) {
            // Written and later ignored is worse than refused: the account
            // would appear to have a role it does not.
            throw ValidationFailed::fromArray([
                'roleIds' => 'One of those roles does not exist in this organisation.',
            ]);
        }
    }

    private function mustKnowCurrentPassword(string $tenantId, string $id, string $current): void
    {
        $hash = $this->people->passwordHashFor($tenantId, $id);

        if ($hash === null || !$this->hasher->verify($current, $hash)) {
            throw ValidationFailed::fromArray([
                'currentPassword' => 'That is not your current password.',
            ]);
        }
    }
}
