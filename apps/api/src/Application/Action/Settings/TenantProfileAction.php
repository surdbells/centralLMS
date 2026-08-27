<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Settings;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Tenant\TenantRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The organisation's own record.
 *
 * GET /v1/tenant    what it is called and how to reach it
 * PUT /v1/tenant    change that
 *
 * Distinct from `/v1/settings`, which is preferences with defaults. This is
 * the organisation itself. The legacy settings screen offered `company_name`
 * and `company_email` as key/value settings *alongside* the real columns, so
 * there were two answers to "what is this company called" and nothing kept
 * them in step.
 */
final class TenantProfileAction
{
    public function __construct(private readonly TenantRepository $tenants)
    {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->tenantId($request, Permission::SettingsManage);
        $tenant   = $this->tenants->find($tenantId);

        if ($tenant === null) {
            throw new EntityNotFound('Organisation', $tenantId);
        }

        return Json::ok($response, [
            ...$tenant->toArray(),
            // Counted at read time. The legacy kept `user_count` on the tenant
            // row and it had drifted on every tenant it was checked against.
            'usage' => $this->tenants->usage($tenantId),
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->tenantId($request, Permission::SettingsManage);
        $body     = $this->body($request);

        $violations = [];
        $changes    = [];

        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);

            if ($name === '') {
                $violations['name'] = 'Your organisation needs a name.';
            } else {
                $changes['name'] = $name;
            }
        }

        if (array_key_exists('contactEmail', $body)) {
            $email = trim((string) $body['contactEmail']);

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $violations['contactEmail'] = 'Enter a valid email address.';
            } else {
                $changes['contactEmail'] = $email === '' ? null : $email;
            }
        }

        foreach (['contactPhone', 'address', 'domain', 'logoUrl', 'industry', 'companySize'] as $field) {
            if (array_key_exists($field, $body)) {
                $value = trim((string) $body[$field]);
                $changes[$field] = $value === '' ? null : $value;
            }
        }

        if (isset($changes['logoUrl']) && !$this->isUsableUrl($changes['logoUrl'])) {
            $violations['logoUrl'] = 'Enter a full https:// address, or leave it empty.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $tenant = $this->tenants->updateProfile($tenantId, $changes);

        if ($tenant === null) {
            throw new EntityNotFound('Organisation', $tenantId);
        }

        return Json::ok($response, [
            ...$tenant->toArray(),
            'usage' => $this->tenants->usage($tenantId),
        ]);
    }

    /**
     * A logo has to load from a page served over https, or browsers refuse it
     * and the organisation sees a broken image it cannot explain.
     */
    private function isUsableUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && str_starts_with(strtolower($url), 'https://');
    }

    private function tenantId(ServerRequestInterface $request, Permission $permission): string
    {
        $caller = AuthenticationMiddleware::require($request);
        $caller->mustHave($permission);

        if ($caller->tenantId === null) {
            throw new AccessDenied($permission->value);
        }

        return $caller->tenantId;
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();

        return is_array($body) ? $body : [];
    }
}
