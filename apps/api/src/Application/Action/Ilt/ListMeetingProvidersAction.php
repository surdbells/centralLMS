<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Ilt;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Infrastructure\Ilt\MeetingProviderFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The conferencing platforms available to the caller's organisation, and
 * whether each is connected.
 *
 * The ILT session form calls this on load, so an admin is never offered a
 * platform that would fail on save — the legacy UI listed all three
 * unconditionally and only failed when someone pressed Save, which is half of
 * why the feature was reported as broken.
 */
final class ListMeetingProvidersAction
{
    public function __construct(private readonly MeetingProviderFactory $providers)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $user = AuthenticationMiddleware::require($request);
        $user->mustHave(Permission::IltSessionManage);

        if ($user->tenantId === null) {
            // A super admin acting outside any tenant has no integrations to
            // list; they must pick a tenant first.
            throw new AccessDenied(Permission::IltSessionManage->value);
        }

        return Json::ok($response, $this->providers->describeForTenant($user->tenantId));
    }
}
