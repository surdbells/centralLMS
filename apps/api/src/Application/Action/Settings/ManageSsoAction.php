<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Settings;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Sso\SsoConnection;
use Central\Api\Domain\Sso\SsoProvider;
use Central\Api\Domain\Sso\SsoRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * An organisation's arrangements with Google and Microsoft.
 *
 * GET    /v1/sso                what is set up here
 * PUT    /v1/sso/{provider}     set it up, or change it
 * DELETE /v1/sso/{provider}     stop using it
 *
 * Needs `settings.manage`. The credentials belong to the organisation, and so
 * does the decision to accept sign-ins from a directory at all.
 *
 * No endpoint returns a client secret. There is nowhere to read one back from
 * — an administrator who has lost theirs gets a new one from the provider,
 * which is what that provider's own console tells them to do.
 */
final class ManageSsoAction
{
    public function __construct(private readonly SsoRepository $sso)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->tenantId($request);

        $configured = [];

        foreach ($this->sso->forTenant($tenantId) as $connection) {
            $configured[$connection->provider->value] = $connection->toArray();
        }

        // Every provider, whether set up or not, so the screen can offer the
        // ones that are not rather than hiding them.
        $all = [];

        foreach (SsoProvider::cases() as $provider) {
            $all[] = $configured[$provider->value] ?? [
                'id'            => null,
                'provider'      => $provider->value,
                'providerLabel' => $provider->label(),
                'enabled'       => false,
                'clientId'      => '',
                'hasSecret'     => false,
                'directory'     => null,
                'domains'       => [],
                'autoProvision' => false,
            ];
        }

        return Json::ok($response, $all);
    }

    /**
     * @param array<string, string> $args
     */
    public function save(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->tenantId($request);
        $provider = $this->provider($args);
        $body     = $this->body($request);

        $clientId = trim((string) ($body['clientId'] ?? ''));
        $enabled  = filter_var($body['enabled'] ?? false, FILTER_VALIDATE_BOOL);

        $existing = $this->sso->find($tenantId, $provider);

        // Sent empty means "keep the one already stored", because the endpoint
        // that renders this form was never given the secret to send back.
        $secret = array_key_exists('clientSecret', $body)
            ? trim((string) $body['clientSecret'])
            : '';

        $violations = [];

        if ($clientId === '') {
            $violations['clientId'] = 'Enter the client ID from the provider.';
        }

        // Turning it on without a secret would leave a button that always
        // fails, and the failure would look like the provider's fault.
        if ($enabled && $secret === '' && ($existing === null || $existing->clientSecret === '')) {
            $violations['clientSecret'] = 'Enter the client secret before turning this on.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $saved = $this->sso->save(new SsoConnection(
            id: $existing->id ?? '',
            tenantId: $tenantId,
            provider: $provider,
            enabled: $enabled,
            clientId: $clientId,
            clientSecret: $secret,
            directory: $this->text($body['directory'] ?? null),
            domains: SsoConnection::parseDomains((string) ($body['domains'] ?? '')),
            autoProvision: filter_var($body['autoProvision'] ?? false, FILTER_VALIDATE_BOOL),
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
        $this->sso->delete($this->tenantId($request), $this->provider($args));

        return Json::noContent($response);
    }

    // --- internals --------------------------------------------------------

    /**
     * @param array<string, string> $args
     */
    private function provider(array $args): SsoProvider
    {
        $provider = SsoProvider::tryFrom($args['provider'] ?? '');

        if ($provider === null) {
            throw new EntityNotFound('Provider', $args['provider'] ?? '');
        }

        return $provider;
    }

    private function tenantId(ServerRequestInterface $request): string
    {
        $caller = AuthenticationMiddleware::require($request);
        $caller->mustHave(Permission::SettingsManage);

        if ($caller->tenantId === null) {
            throw new AccessDenied(Permission::SettingsManage->value);
        }

        return $caller->tenantId;
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
