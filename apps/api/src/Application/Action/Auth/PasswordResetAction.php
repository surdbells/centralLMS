<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Auth;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Notification\Notifier;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Identity\PasswordToken;
use Central\Api\Domain\Identity\PasswordTokenPurpose;
use Central\Api\Domain\Identity\PasswordTokenRepository;
use Central\Api\Domain\Identity\RefreshTokenRepository;
use Central\Api\Domain\Identity\UserRepository;
use Central\Api\Domain\Identity\UserStatus;
use Central\Api\Domain\Notification\NotificationType;
use Central\Api\Domain\People\PersonInput;
use Central\Api\Domain\People\PersonRepository;
use Central\Api\Domain\Settings\PasswordPolicy;
use Central\Api\Domain\Settings\SettingsRepository;
use Central\Api\Infrastructure\Security\PasswordHasher;
use Central\Api\Support\Clock;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Setting a password without being signed in.
 *
 * POST /v1/auth/password/forgot   ask for a link
 * GET  /v1/auth/password/token    is this link still good?
 * POST /v1/auth/password/reset    redeem it
 *
 * All three are public, which is why every one of them is written to give
 * nothing away.
 *
 * **What this replaces.** `authentication/confirm` returned the one-time code
 * in its own response body; the browser held it in `r_response.otp` and posted
 * it back to `authentication/validate`, where the server compared it against
 * the value it had just handed over. Then `authentication/reset` took an email
 * address and a new password — no code, no token, nothing tying it to the two
 * steps before it. Anybody who could reach that endpoint could set anybody's
 * password by knowing their email address.
 *
 * Here the secret is generated server-side, only its hash is stored, it is
 * never in a response, and redeeming it is a single atomic claim.
 */
final class PasswordResetAction
{
    /**
     * How many resets one account may ask for in an hour.
     *
     * The endpoint answers identically whether or not an account exists, so
     * this is the only thing stopping it being used to post somebody a hundred
     * emails.
     */
    private const REQUESTS_PER_HOUR = 5;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PersonRepository $people,
        private readonly PasswordTokenRepository $tokens,
        private readonly RefreshTokenRepository $refreshTokens,
        private readonly SettingsRepository $settings,
        private readonly PasswordHasher $hasher,
        private readonly Notifier $notifier,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Asks for a reset link.
     *
     * Always 204, whatever happened. An endpoint that says "no such account"
     * is a way to find out who holds an account here, and for a corporate LMS
     * that is a staff list.
     */
    public function forgot(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $body  = $this->body($request);
        $email = trim((string) ($body['email'] ?? ''));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationFailed::fromArray(['email' => 'Enter a valid email address.']);
        }

        // Resolved exactly as signing in resolves it, including refusing an
        // address that names accounts in more than one organisation — picking
        // one would send the link to an arbitrary account.
        $user = $this->users->findForSignIn(
            $email,
            isset($body['tenantId']) ? (string) $body['tenantId'] : null,
        );

        if ($user !== null) {
            $this->send($request, $user->id, $user->tenantId, PasswordTokenPurpose::Reset);
        }

        return Json::noContent($response);
    }

    /**
     * Whether a link is still good, so the form can say so before somebody
     * types a new password into it.
     *
     * Nothing about the account comes back — not the email, not the name. The
     * answer is yes or no.
     */
    public function check(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $token = (string) ($request->getQueryParams()['token'] ?? '');
        $found = $token === '' ? null : $this->tokens->findByHash(PasswordToken::hash($token));
        $now   = $this->clock->now();

        return Json::ok($response, [
            'valid'   => $found !== null && $found->isLive($now),
            'purpose' => $found?->purpose->value,
        ]);
    }

    /**
     * Redeems a link and sets the password.
     */
    public function reset(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $body     = $this->body($request);
        $token    = (string) ($body['token'] ?? '');
        $password = (string) ($body['password'] ?? '');
        $now      = $this->clock->now();

        $found = $token === '' ? null : $this->tokens->findByHash(PasswordToken::hash($token));

        if ($found === null || !$found->isLive($now)) {
            // One message for missing, expired, already used and never valid.
            // Telling them apart tells somebody holding a stolen link which
            // kind of thing they are holding.
            throw ValidationFailed::fromArray([
                'token' => 'This link is no longer valid. Ask for a new one.',
            ]);
        }

        $violations = [];
        PersonInput::checkPassword(
            $password,
            $violations,
            policy: PasswordPolicy::from($this->settings->forTenant($found->tenantId)),
        );

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        // Claimed before the password is set, and only the call that wins the
        // claim proceeds — otherwise the same link opened twice sets it twice,
        // and the second set is by whoever was slower.
        if (!$this->tokens->claim($found->id, $now)) {
            throw ValidationFailed::fromArray([
                'token' => 'This link is no longer valid. Ask for a new one.',
            ]);
        }

        $this->people->setPasswordHash($found->tenantId, $found->userId, $this->hasher->hash($password));

        // An invitation is also the moment the account becomes usable. It was
        // created `invited`, which refuses sign-in.
        if ($found->purpose === PasswordTokenPurpose::Invitation) {
            $this->people->setStatus($found->tenantId, $found->userId, UserStatus::Active);
        }

        // Everything else that could set this password is now stale, and so is
        // every session opened with the old one. Somebody resetting a password
        // because it was taken should not leave the taker signed in.
        $this->tokens->retireFor($found->userId, null, $now);
        $this->refreshTokens->revokeAllForUser($found->userId, $now);

        return Json::noContent($response);
    }

    /**
     * Issues a token and emails it.
     *
     * Shared by the reset flow and by inviting somebody, because they are the
     * same act: a single-use secret, sent to an address, that authorises
     * setting one password.
     */
    public function send(
        ServerRequestInterface $request,
        string $userId,
        string $tenantId,
        PasswordTokenPurpose $purpose,
    ): bool {
        $now = $this->clock->now();

        if ($purpose === PasswordTokenPurpose::Reset) {
            $asked = $this->tokens->countRecent($userId, $purpose, $now->modify('-1 hour'));

            if ($asked >= self::REQUESTS_PER_HOUR) {
                // Silently. The caller returns 204 either way, and saying
                // "too many" would confirm the account exists.
                return false;
            }
        }

        // Any outstanding one stops working the moment a newer is asked for,
        // so a link in an older email cannot be used after this.
        $this->tokens->retireFor($userId, $purpose, $now);

        $secret = PasswordToken::generate();

        $this->tokens->issue(
            $tenantId,
            $userId,
            $purpose,
            PasswordToken::hash($secret),
            $now->add($purpose->lifetime()),
            $this->clientIp($request),
            $this->userAgent($request),
        );

        $this->notifier->notifyOne(
            $tenantId,
            $userId,
            $purpose === PasswordTokenPurpose::Invitation
                ? NotificationType::Invitation
                : NotificationType::PasswordReset,
            [
                // The only place the secret ever appears outside this method.
                'link'      => $this->link($request, $secret),
                'expiresIn' => $purpose === PasswordTokenPurpose::Reset ? 'one hour' : 'fourteen days',
            ],
        );

        return true;
    }

    // --- internals --------------------------------------------------------

    private function link(
        ServerRequestInterface $request,
        string $secret,
    ): string {
        $base = rtrim((string) ($_ENV['APP_WEB_URL'] ?? ''), '/');

        if ($base === '') {
            // Falls back to the host the request arrived on, so a deployment
            // that has not set the web address still sends a working link
            // rather than one beginning "/reset".
            $uri  = $request->getUri();
            $base = $uri->getScheme() . '://' . $uri->getHost()
                . ($uri->getPort() === null ? '' : ':' . $uri->getPort());
        }

        // Both an invitation and a reset redeem a single-use password token,
        // and the web app does that at /reset: it checks the token, reads its
        // purpose, and — for an invitation — sets the password and flips the
        // account to active. /register is the *self-registration* page; it
        // resolves an organisation from a /{tenantId}/register path and is
        // gated by the tenant's allow-self-registration flag, so an invitation
        // sent there lands on the bare page with no tenant and reads "not a
        // recognised organisation". The token carries the tenant server-side,
        // so /reset needs nothing else in the URL.
        return $base . '/reset?token=' . rawurlencode($secret);
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    private function clientIp(ServerRequestInterface $request): ?string
    {
        $params = $request->getServerParams();

        return isset($params['REMOTE_ADDR']) ? (string) $params['REMOTE_ADDR'] : null;
    }

    private function userAgent(ServerRequestInterface $request): ?string
    {
        $agent = $request->getHeaderLine('User-Agent');

        return $agent === '' ? null : mb_substr($agent, 0, 500);
    }
}
