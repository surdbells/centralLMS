<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Settings;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Settings\PasswordPolicy;
use Central\Api\Domain\Settings\SettingCatalogue;
use Central\Api\Domain\Settings\SettingCategory;
use Central\Api\Domain\Settings\SettingDefinition;
use Central\Api\Domain\Settings\SettingsRepository;
use Central\Api\Domain\Tenant\TenantRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What a tenant has configured.
 *
 * GET   /v1/settings
 * GET   /v1/settings/history
 * PUT   /v1/settings
 * POST  /v1/settings/{category}/reset
 * GET   /v1/settings/public
 *
 * **The tenant comes from the token.** `user/tenant-settings.php` read
 * `tenant_id` from the request body and checked only `is_admin($userId)` —
 * whether the caller administers *something*, not whether they administer the
 * organisation they named. Since that endpoint writes, any tenant
 * administrator could repaint another organisation's branding, rename it, or
 * import a settings file over the top of it. Recorded as action 0d.
 *
 * `/settings/public` is the exception and is deliberately unauthenticated: the
 * sign-in page needs a logo and a colour before anybody has signed in. It
 * returns branding and nothing else.
 */
final class TenantSettingsAction
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly SettingCatalogue $catalogue,
        private readonly TenantRepository $tenants,
    ) {
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->readableTenant($request);

        return Json::ok($response, $this->settings->forTenant($tenantId)->toArray($this->catalogue));
    }

    /**
     * Who changed what, and when.
     */
    public function history(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->writableTenant($request);

        return Json::ok($response, $this->settings->history($tenantId));
    }

    /**
     * Saves whichever settings the request named.
     *
     * A partial write on purpose: a screen saves the tab somebody edited, and
     * requiring the whole catalogue would mean every save carries the value of
     * every other tab and can overwrite a colleague's edit on a different one.
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->writableTenant($request);
        $body     = (array) ($request->getParsedBody() ?? []);

        $submitted = $body['settings'] ?? $body;

        if (!is_array($submitted) || $submitted === []) {
            throw ValidationFailed::fromArray(['settings' => 'Send at least one setting to change.']);
        }

        $violations = [];
        $accepted   = [];

        foreach ($submitted as $key => $value) {
            $definition = $this->catalogue->findByName((string) $key);

            if ($definition === null) {
                // Named rather than ignored. A settings screen that accepts a
                // misspelled key and reports success is how the legacy table
                // came to hold keys nothing reads.
                $violations[(string) $key] = 'There is no such setting.';

                continue;
            }

            $reason   = null;
            $storable = $definition->accept($value, $reason);

            if ($storable === null) {
                $violations[(string) $key] = $reason ?? 'That value is not allowed.';

                continue;
            }

            $accepted[$definition->key->value] = $storable;
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $saved = $this->settings->save($tenantId, $accepted, $this->caller($request)->id);

        return Json::ok($response, $saved->toArray($this->catalogue));
    }

    /**
     * Puts a category back to the defaults.
     *
     * @param array<string, string> $args
     */
    public function reset(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $tenantId = $this->writableTenant($request);
        $category = SettingCategory::tryFrom(strtolower(trim($args['category'] ?? '')));

        if ($category === null) {
            throw new EntityNotFound('Setting category', $args['category'] ?? '');
        }

        $this->settings->resetCategory($tenantId, $category);

        return Json::ok($response, $this->settings->forTenant($tenantId)->toArray($this->catalogue));
    }

    /**
     * Branding, and the password rules, for a page nobody has signed in to.
     *
     * Unauthenticated, and therefore deliberately narrow: a logo, some colours
     * and what a password must look like. The tenant is named in the query,
     * which is safe here precisely because nothing else is returned — and it
     * has to be, since there is no token to take it from.
     */
    public function publicSettings(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = trim((string) ($request->getQueryParams()['tenantId'] ?? ''));

        if (preg_match('/^[0-9a-f-]{36}$/i', $tenantId) !== 1) {
            throw ValidationFailed::fromArray(['tenantId' => 'Say which organisation.']);
        }

        $settings = $this->settings->forTenant($tenantId);
        $branding = [];

        foreach ($this->catalogue->inCategory(SettingCategory::Branding) as $definition) {
            $branding[$definition->key->value] = $settings->get($definition->key);
        }

        return Json::ok($response, [
            // The organisation's name and its own logo, so a sign-in or
            // registration screen can carry them. Branding that stops at
            // the door of the application is not branding.
            'organisation'   => $this->tenants->nameOf($tenantId),
            'branding'       => $branding,
            'passwordPolicy' => PasswordPolicy::from($settings)->toArray(),
        ]);
    }

    /**
     * The catalogue itself, for a form to render from.
     */
    public function catalogue(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->readableTenant($request);

        $catalogue = $this->catalogue;

        return Json::ok($response, [
            'categories' => array_map(
                static fn (SettingCategory $c): array => [
                    'category'    => $c->value,
                    'label'       => $c->label(),
                    'description' => $c->description(),
                ],
                SettingCategory::cases(),
            ),
            'settings' => array_map(
                static fn (SettingDefinition $d): array => $d->toArray(),
                $catalogue->all(),
            ),
        ]);
    }

    // --- plumbing ---------------------------------------------------------

    private function caller(ServerRequestInterface $request): \Central\Api\Domain\Authz\AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    /**
     * Reading is open to anybody signed in: the branding is on every page they
     * look at, and the date format decides how they read a deadline.
     */
    private function readableTenant(ServerRequestInterface $request): string
    {
        $caller = $this->caller($request);

        if ($caller->tenantId === null) {
            throw new AccessDenied(Permission::SettingsManage->value);
        }

        return $caller->tenantId;
    }

    private function writableTenant(ServerRequestInterface $request): string
    {
        $caller = $this->caller($request);
        $caller->mustHave(Permission::SettingsManage);

        if ($caller->tenantId === null) {
            throw new AccessDenied(Permission::SettingsManage->value);
        }

        return $caller->tenantId;
    }
}
