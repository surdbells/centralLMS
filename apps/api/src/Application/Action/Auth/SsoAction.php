<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Auth;

use Central\Api\Application\Auth\AuthenticationService;
use Central\Api\Application\Http\Json;
use Central\Api\Domain\Authz\RoleRepository;
use Central\Api\Domain\Authz\SystemRole;
use Central\Api\Domain\Certificate\CertificateRepository;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Identity\UserStatus;
use Central\Api\Domain\People\Person;
use Central\Api\Domain\People\PersonRepository;
use Central\Api\Domain\Sso\SsoConnection;
use Central\Api\Domain\Sso\SsoFailed;
use Central\Api\Domain\Sso\SsoProvider;
use Central\Api\Domain\Sso\SsoRepository;
use Central\Api\Infrastructure\Sso\OidcClient;
use Central\Api\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Signing in from Google or Microsoft.
 *
 * GET  /v1/tenants/{tenantId}/sso    which directories this organisation uses
 * POST /v1/auth/sso/start            where to send somebody
 * POST /v1/auth/sso/callback         what came back
 *
 * Public, all three. Somebody signing in has nothing to authenticate with yet,
 * which is the point of the exercise.
 *
 * The organisation is named because the credentials are the organisation's:
 * one company's Google Workspace does not vouch for another's staff, and a
 * shared client id would mean it did.
 */
final class SsoAction
{
    /** How long a redirect may be out before its state is worthless. */
    private const STATE_LIFETIME = 'PT15M';

    public function __construct(
        private readonly SsoRepository $sso,
        private readonly OidcClient $oidc,
        private readonly PersonRepository $people,
        private readonly RoleRepository $roles,
        private readonly CertificateRepository $tenants,
        private readonly AuthenticationService $auth,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Which directories an organisation accepts, for the sign-in screen.
     *
     * Names and nothing else — no client id, no directory id, and certainly no
     * secret. Enough to draw a button.
     *
     * @param array<string, string> $args
     */
    public function providers(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $args['tenantId'] ?? '';

        if ($this->tenants->organisationName($tenantId) === null) {
            throw new EntityNotFound('Organisation', $tenantId);
        }

        $providers = [];

        foreach ($this->sso->forTenant($tenantId) as $connection) {
            if ($connection->enabled && $connection->clientId !== '') {
                $providers[] = [
                    'provider' => $connection->provider->value,
                    'label'    => $connection->provider->label(),
                ];
            }
        }

        return Json::ok($response, $providers);
    }

    /**
     * Where to send somebody to be identified.
     *
     * The state and nonce are written down before the URL is handed out. A
     * callback carrying a state we did not issue is a forgery, and without a
     * record there is no way to tell.
     */
    public function start(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $body     = $this->body($request);
        $tenantId = (string) ($body['tenantId'] ?? '');
        $provider = SsoProvider::tryFrom((string) ($body['provider'] ?? ''));

        if ($provider === null) {
            throw ValidationFailed::fromArray(['provider' => 'Choose Google or Microsoft.']);
        }

        $connection = $this->sso->find($tenantId, $provider);

        if ($connection === null || !$connection->enabled || $connection->clientId === '') {
            throw new SsoFailed(sprintf('%s sign-in is not set up here.', $provider->label()));
        }

        $state = bin2hex(random_bytes(32));
        $nonce = bin2hex(random_bytes(16));

        $this->sso->rememberState(
            $state,
            $tenantId,
            $provider,
            $nonce,
            // Only a path, never a whole URL. An open redirect on a sign-in
            // endpoint is how somebody's token ends up delivered to another
            // site — see {@see path()}.
            $this->path($body['redirectTo'] ?? null),
            $this->clock->now()->add(new \DateInterval(self::STATE_LIFETIME)),
        );

        return Json::ok($response, [
            'url' => $this->oidc->authorizeUrl(
                $connection,
                $this->redirectUri($request),
                $state,
                $nonce,
            ),
        ]);
    }

    /**
     * What came back, and a session if it holds up.
     */
    public function callback(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $body  = $this->body($request);
        $code  = (string) ($body['code'] ?? '');
        $state = (string) ($body['state'] ?? '');

        if ($code === '' || $state === '') {
            throw new SsoFailed('That sign-in is incomplete. Try again.');
        }

        $remembered = $this->sso->claimState($state, $this->clock->now());

        if ($remembered === null) {
            // Never issued, expired, or already used — one answer, because
            // telling them apart tells somebody replaying a callback which of
            // those they are doing.
            throw new SsoFailed('That sign-in has expired. Try again.');
        }

        $connection = $this->sso->find($remembered['tenantId'], $remembered['provider']);

        if ($connection === null || !$connection->enabled) {
            throw new SsoFailed('That sign-in is no longer available here.');
        }

        $identity = $this->oidc->exchange(
            $connection,
            $code,
            $this->redirectUri($request),
            $remembered['nonce'],
        );

        if (!$identity['emailVerified']) {
            // An unverified address proves nothing: anybody can put anybody's
            // address on an account they control.
            throw new SsoFailed('That account has no confirmed email address.');
        }

        $refusal = $connection->reasonToRefuse($identity['email']);

        if ($refusal !== null) {
            throw new SsoFailed($refusal);
        }

        $userId = $this->resolve($connection->tenantId, $connection, $identity);

        $server = $request->getServerParams();

        // From here the session is issued exactly as a password sign-in
        // issues one — same tokens, same refresh record, same status checks.
        // How somebody proved who they are is not a reason to have a second
        // kind of session.
        return Json::ok($response, $this->auth->issueForUserId(
            $userId,
            $request->getHeaderLine('User-Agent') ?: null,
            isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : null,
        )->toArray());
    }

    /**
     * Finds, links or creates the account this identity belongs to.
     *
     * @param  array{subject: string, email: string, emailVerified: bool,
     *               firstName: string, lastName: string, hostedDomain: string|null} $identity
     * @return string the user id
     */
    private function resolve(
        string $tenantId,
        SsoConnection $connection,
        array $identity,
    ): string {
        // By the directory's own id first. It survives a change of name or
        // address, which the email does not — matching on email alone means
        // somebody who marries comes back as a stranger.
        $existing = $this->sso->findUserBySubject($connection->provider, $identity['subject']);

        if ($existing !== null) {
            return $existing;
        }

        $person = $this->people->findByEmail($tenantId, $identity['email']);

        if ($person !== null && $person->id !== null) {
            // First time through for somebody who already had an account.
            // Linking here is what makes the id checked above work next time.
            $this->sso->linkUser($person->id, $connection->provider, $identity['subject']);

            if ($person->status === UserStatus::Invited) {
                // The directory has vouched for them, which is what the
                // invitation was waiting for.
                $this->people->setStatus($tenantId, $person->id, UserStatus::Active);
            }

                return $person->id;
        }

        if (!$connection->autoProvision) {
            throw new SsoFailed(
                'That account is not set up here yet. Ask an administrator for an invitation.',
            );
        }

        $learner = $this->roles->find($tenantId, SystemRole::Learner->value);

        $created = $this->people->create(
            new Person(
                id: null,
                tenantId: $tenantId,
                email: $identity['email'],
                firstName: $identity['firstName'],
                lastName: $identity['lastName'],
                // Active immediately, and only here: the directory has already
                // done what an invitation email exists to do.
                status: UserStatus::Active,
                roleIds: $learner === null ? [] : [$learner->id],
            ),
            // No password, ever. This account signs in through the directory,
            // and a password would be a second way in that nobody set.
            null,
            null,
        );

        if ($created->id === null) {
            throw new SsoFailed('Could not finish setting up that account.');
        }

        $this->sso->linkUser($created->id, $connection->provider, $identity['subject']);

        return $created->id;
    }

    /**
     * Where the provider sends people back to.
     *
     * One address for the whole platform, registered once with each provider.
     * Which organisation and which directory it belongs to is carried by the
     * state, not by the URL, so adding a tenant needs no change at Google.
     */
    private function redirectUri(ServerRequestInterface $request): string
    {
        $base = rtrim((string) ($_ENV['APP_WEB_URL'] ?? ''), '/');

        if ($base === '') {
            $uri  = $request->getUri();
            $base = $uri->getScheme() . '://' . $uri->getHost()
                . ($uri->getPort() === null ? '' : ':' . $uri->getPort());
        }

        return $base . '/sso/callback';
    }

    /**
     * A path within the application, or null.
     *
     * Anything with a scheme, a host, or a second leading slash is dropped:
     * `//evil.example` is a protocol-relative URL that browsers follow off
     * this site, and a sign-in endpoint that forwards to one hands the session
     * it just created to somebody else.
     */
    private function path(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        if (!str_starts_with($value, '/') || str_starts_with($value, '//')) {
            return null;
        }

        return str_contains($value, '://') ? null : $value;
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }
}
