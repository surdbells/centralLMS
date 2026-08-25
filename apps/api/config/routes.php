<?php

/**
 * Every route in the application, in one readable file.
 *
 * Authorisation is declared here with RequirePermissionMiddleware rather than
 * inside actions, so this file doubles as the access-control audit: what a
 * route needs is on the same screen as its path and method.
 *
 * Convention: everything under /v1 that is not inside the authenticated group
 * is genuinely public. Adding a route outside that group is a deliberate act.
 */

declare(strict_types=1);

use Central\Api\Application\Action\Auth\LoginAction;
use Central\Api\Application\Action\Auth\LogoutAction;
use Central\Api\Application\Action\Auth\RefreshAction;
use Central\Api\Application\Action\HealthAction;
use Central\Api\Application\Action\Ilt\ListMeetingProvidersAction;
use Central\Api\Application\Action\Lookup\ActiveLookupsAction;
use Central\Api\Application\Action\Lookup\CreateLookupAction;
use Central\Api\Application\Action\Lookup\ListLookupsAction;
use Central\Api\Application\Action\Lookup\SetLookupStatusAction;
use Central\Api\Application\Action\Lookup\ShowLookupAction;
use Central\Api\Application\Action\Lookup\UpdateLookupAction;
use Central\Api\Application\Action\MeAction;
use Central\Api\Application\Action\VersionAction;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    // --- operational, unauthenticated -------------------------------------
    $app->get('/health', HealthAction::class)->setName('health');
    $app->get('/version', VersionAction::class)->setName('version');

    $app->group('/v1', function (RouteCollectorProxy $v1): void {
        // --- public -------------------------------------------------------
        $v1->group('/auth', function (RouteCollectorProxy $auth): void {
            $auth->post('/login', LoginAction::class)->setName('auth.login');

            // Public by necessity: the refresh token is the credential, and
            // this is called precisely when the access token has expired.
            $auth->post('/refresh', RefreshAction::class)->setName('auth.refresh');

            // Public so signing out always succeeds. The `allDevices` variant
            // asks for the caller inside the action, which requires a valid
            // access token.
            $auth->post('/logout', LogoutAction::class)->setName('auth.logout');
        });

        // Registration, password reset and OTP land here with the people
        // module — they need invitations and the Termii/ZeptoMail senders.

        // --- authenticated ------------------------------------------------
        $v1->group('', function (RouteCollectorProxy $secure): void {
            $secure->get('/me', MeAction::class)->setName('me');

            // Reference data. Six routes replace 36 legacy PHP endpoints —
            // {kind} is branch | department | team | level | job_title |
            // category, singular or plural.
            $secure->group('/lookups/{kind}', function (RouteCollectorProxy $l): void {
                $l->get('', ListLookupsAction::class)->setName('lookups.list');
                $l->get('/active', ActiveLookupsAction::class)->setName('lookups.active');
                $l->post('', CreateLookupAction::class)->setName('lookups.create');
                $l->get('/{id}', ShowLookupAction::class)->setName('lookups.show');
                $l->put('/{id}', UpdateLookupAction::class)->setName('lookups.update');
                $l->patch('/{id}/status', SetLookupStatusAction::class)->setName('lookups.status');
            });

            $secure->group('/ilt', function (RouteCollectorProxy $ilt): void {
                $ilt->get('/providers', ListMeetingProvidersAction::class)
                    ->setName('ilt.providers');

                // Session CRUD is added here once the ILT domain lands. Each
                // route declares its own permission, e.g.
                //   ->add(RequirePermissionMiddleware::of(Permission::IltSessionManage))
            });
        })->add(AuthenticationMiddleware::class);
    });
};
