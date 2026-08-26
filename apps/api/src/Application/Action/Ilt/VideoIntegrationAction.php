<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Ilt;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Ilt\MeetingProviderId;
use Central\Api\Domain\Ilt\VideoIntegration;
use Central\Api\Domain\Ilt\VideoIntegrationRepository;
use Central\Api\Infrastructure\Ilt\MeetingProviderFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Connecting a tenant's own Zoom, Google or Teams account.
 *
 * GET    /v1/ilt/integrations                     what is connected
 * POST   /v1/ilt/integrations/{platform}/connect  start the OAuth handshake
 * POST   /v1/ilt/integrations/{platform}/callback finish it
 * PATCH  /v1/ilt/integrations/{platform}          make default, enable, disable
 * DELETE /v1/ilt/integrations/{platform}          disconnect
 *
 * Per tenant, not per platform install: each organisation connects its own
 * account, so meetings are created by them and appear in their own admin
 * console — which is also the only arrangement their IT department will
 * approve.
 */
final class VideoIntegrationAction
{
    public function __construct(
        private readonly VideoIntegrationRepository $integrations,
        private readonly MeetingProviderFactory $providers,
    ) {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::IltSessionManage);

        // The factory reports which platforms this deployment has credentials
        // for, alongside what the tenant has actually connected.
        return Json::ok($response, $this->providers->describeForTenant($this->tenantId($request)));
    }

    /**
     * @param array<string, string> $args
     */
    public function connect(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::IltSessionManage);

        $tenantId = $this->tenantId($request);
        $platform = $this->platform($args);

        if (!$this->providers->isPlatformAvailable($platform)) {
            throw new ConflictingState(
                sprintf('%s is not configured on this deployment.', $platform->label()),
                'platform_unavailable',
            );
        }

        $redirectUri = trim((string) ($this->body($request)['redirectUri'] ?? ''));

        if ($redirectUri === '' || !str_starts_with($redirectUri, 'https://')) {
            // An http redirect would carry the authorisation code in clear,
            // and a missing one lets the provider fall back to whatever was
            // registered — which may not be this tenant's.
            throw ValidationFailed::fromArray([
                'redirectUri' => 'Give the https URL the provider should send the code back to.',
            ]);
        }

        // Carries the tenant so the callback knows whose account was
        // connected, and random bytes so a state value cannot be replayed.
        $state = $tenantId . ':' . bin2hex(random_bytes(16));

        return Json::ok($response, [
            'platform'         => $platform->value,
            'authorizationUrl' => $this->providers->connector($tenantId, $platform)
                ->authorizationUrl($state, $redirectUri),
            'state' => $state,
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function callback(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::IltSessionManage);

        $tenantId = $this->tenantId($request);
        $platform = $this->platform($args);
        $body = $this->body($request);

        $code = trim((string) ($body['code'] ?? ''));
        $state = trim((string) ($body['state'] ?? ''));
        $redirectUri = trim((string) ($body['redirectUri'] ?? ''));

        if ($code === '' || $redirectUri === '') {
            throw ValidationFailed::fromArray([
                'code' => 'Send the code and the redirect URI the provider was given.',
            ]);
        }

        // The state carries the tenant it was issued for. Without this check a
        // code obtained for one organisation could be redeemed into another's
        // integration row.
        if (!str_starts_with($state, $tenantId . ':')) {
            throw new AccessDenied(Permission::IltSessionManage->value);
        }

        try {
            $grant = $this->providers->connector($tenantId, $platform)->exchangeCode($code, $redirectUri);
        } catch (\Throwable $e) {
            throw new ConflictingState(
                'The provider refused the authorisation. Try connecting again.',
                'oauth_exchange_failed',
            );
        }

        $existing = $this->integrations->find($tenantId, $platform);

        $this->integrations->save(new VideoIntegration(
            id: $existing === null ? '' : $existing->id,
            tenantId: $tenantId,
            platform: $platform,
            enabled: true,
            // The first platform a tenant connects becomes their default, so
            // scheduling works before anyone visits the settings screen.
            isDefault: $existing !== null
                ? $existing->isDefault
                : $this->integrations->findDefault($tenantId) === null,
            accessToken: $grant->accessToken,
            refreshToken: $grant->refreshToken,
            tokenExpiresAt: $grant->expiresAt,
            settings: $existing === null ? [] : $existing->settings,
        ));

        return Json::ok($response, [
            'platform'  => $platform->value,
            'connected' => true,
            // Never the tokens themselves: they are encrypted at rest and have
            // no business being in a response.
            'expiresAt' => $grant->expiresAt->format(\DateTimeInterface::ATOM),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::IltSessionManage);

        $tenantId = $this->tenantId($request);
        $platform = $this->platform($args);

        $existing = $this->integrations->find($tenantId, $platform)
            ?? throw new EntityNotFound('Integration', $platform->value);

        $body = $this->body($request);

        $this->integrations->save(new VideoIntegration(
            id: $existing->id,
            tenantId: $tenantId,
            platform: $platform,
            enabled: array_key_exists('enabled', $body)
                ? filter_var($body['enabled'], FILTER_VALIDATE_BOOL)
                : $existing->enabled,
            isDefault: array_key_exists('isDefault', $body)
                ? filter_var($body['isDefault'], FILTER_VALIDATE_BOOL)
                : $existing->isDefault,
            accessToken: $existing->accessToken,
            refreshToken: $existing->refreshToken,
            tokenExpiresAt: $existing->tokenExpiresAt,
            clientId: $existing->clientId,
            clientSecret: $existing->clientSecret,
            settings: is_array($body['settings'] ?? null) ? $body['settings'] : $existing->settings,
        ));

        return Json::ok($response, $this->providers->describeForTenant($tenantId));
    }

    /**
     * @param array<string, string> $args
     */
    public function disconnect(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::IltSessionManage);

        $tenantId = $this->tenantId($request);
        $platform = $this->platform($args);

        if ($this->integrations->find($tenantId, $platform) === null) {
            throw new EntityNotFound('Integration', $platform->value);
        }

        // Meetings already booked keep working — they live in the provider's
        // account, not here. Disconnecting stops new ones being created.
        $this->integrations->delete($tenantId, $platform);

        return Json::noContent($response);
    }

    /**
     * @param array<string, string> $args
     *
     * @throws EntityNotFound
     */
    private function platform(array $args): MeetingProviderId
    {
        $raw = strtolower(trim($args['platform'] ?? ''));

        return MeetingProviderId::tryFrom($raw)
            ?? throw new EntityNotFound('Platform', $raw);
    }

    private function caller(ServerRequestInterface $request): \Central\Api\Domain\Authz\AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    private function tenantId(ServerRequestInterface $request): string
    {
        $user = $this->caller($request);

        if ($user->tenantId === null) {
            throw new AccessDenied(Permission::IltSessionManage->value);
        }

        return $user->tenantId;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        return (array) ($request->getParsedBody() ?? []);
    }
}
