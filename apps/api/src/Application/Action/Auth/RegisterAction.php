<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Auth;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\RoleRepository;
use Central\Api\Domain\Authz\SystemRole;
use Central\Api\Domain\Certificate\CertificateRepository;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Identity\PasswordTokenPurpose;
use Central\Api\Domain\Identity\RegistrationPolicy;
use Central\Api\Domain\Identity\UserStatus;
use Central\Api\Domain\People\Person;
use Central\Api\Domain\People\PersonRepository;
use Central\Api\Domain\Settings\SettingsRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Making yourself an account in a named organisation.
 *
 * GET  /v1/tenants/{tenantId}/registration   is this link open?
 * POST /v1/tenants/{tenantId}/register       use it
 *
 * The organisation is in the address because an account has to belong to one.
 * The legacy's `register.php` asked for no organisation and inserted rows with
 * no `tenant_id` at all, so a self-registered account belonged to nobody and
 * `WHERE email = ?` found it from every organisation at once.
 *
 * Closed unless the organisation has opened it, and optionally narrowed to
 * particular email domains — see {@see RegistrationPolicy}.
 *
 * **No password is taken here.** Registering sends the same link an invitation
 * sends, and the password is set through that. It is one mechanism rather than
 * two, and it means a password is never accepted for an address until somebody
 * has shown they read the post there.
 */
final class RegisterAction
{
    public function __construct(
        private readonly PersonRepository $people,
        private readonly SettingsRepository $settings,
        private readonly RoleRepository $roles,
        private readonly CertificateRepository $tenants,
        private readonly PasswordResetAction $passwords,
    ) {
    }

    /**
     * What the registration screen needs before it draws anything.
     *
     * Deliberately thin: the organisation's name, whether it is open, and the
     * domains it accepts. The name is there because somebody following a link
     * should be told whose front door they are at; everything else about the
     * organisation stays private.
     *
     * @param array<string, string> $args
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $args['tenantId'] ?? '';
        $name     = $this->tenants->organisationName($tenantId);

        if ($name === null) {
            throw new EntityNotFound('Organisation', $tenantId);
        }

        $policy = RegistrationPolicy::from($this->settings->forTenant($tenantId)->all());

        return Json::ok($response, [
            'tenantId'     => $tenantId,
            'organisation' => $name,
            'isOpen'       => $policy->isOpen,
            'domains'      => $policy->domains,
        ]);
    }

    /**
     * Registers, and sends a link to finish.
     *
     * Answers 204 whether the address was new or already had an account. An
     * endpoint that says "that email is taken" is a way to ask an organisation
     * whether a particular person works there — and this one is public.
     *
     * @param array<string, string> $args
     */
    public function register(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $args['tenantId'] ?? '';
        $name     = $this->tenants->organisationName($tenantId);

        if ($name === null) {
            throw new EntityNotFound('Organisation', $tenantId);
        }

        $body      = $this->body($request);
        $email     = strtolower(trim((string) ($body['email'] ?? '')));
        $firstName = trim((string) ($body['firstName'] ?? ''));
        $lastName  = trim((string) ($body['lastName'] ?? ''));
        $phone     = trim((string) ($body['phone'] ?? ''));

        $violations = [];

        if ($firstName === '') {
            $violations['firstName'] = 'Enter your first name.';
        }

        if ($lastName === '') {
            $violations['lastName'] = 'Enter your last name.';
        }

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $violations['email'] = 'Enter a valid email address.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $policy = RegistrationPolicy::from($this->settings->forTenant($tenantId)->all());
        $refusal = $policy->reasonToRefuse($email);

        if ($refusal !== null) {
            // Said plainly. Being closed, or open only to one domain, is not a
            // secret — the link was given out on purpose, and somebody turned
            // away needs to know whether to find another way in.
            throw ValidationFailed::fromArray(['email' => $refusal]);
        }

        // An address that already has an account here gets nothing new and no
        // second email. It is the same 204 as success, so the endpoint cannot
        // be used to ask who works here.
        if ($this->people->emailExists($tenantId, $email)) {
            return Json::noContent($response);
        }

        $learner = $this->roles->find($tenantId, SystemRole::Learner->value);

        $created = $this->people->create(
            new Person(
                id: null,
                tenantId: $tenantId,
                email: $email,
                firstName: $firstName,
                lastName: $lastName,
                status: UserStatus::Invited,
                phone: $phone === '' ? null : $phone,
                roleIds: $learner === null ? [] : [$learner->id],
            ),
            // No hash: the password is chosen through the link, which is also
            // the only thing that turns this account into one that can sign in.
            null,
            // Nobody assigned this; they registered.
            null,
        );

        if ($created->id !== null) {
            $this->passwords->send(
                $request,
                $created->id,
                $tenantId,
                PasswordTokenPurpose::Invitation,
            );
        }

        return Json::noContent($response);
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }
}
