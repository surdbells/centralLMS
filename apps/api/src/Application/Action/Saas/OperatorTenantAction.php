<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Saas;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Application\Saas\TenantProvisioner;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Saas\PlatformRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The operator's console over every organisation.
 *
 * Cross-tenant, so every method needs a super admin — the only caller allowed
 * to reach past a single tenant. Listing, provisioning a new organisation,
 * moving one between statuses, setting a bespoke allowance, and putting one on
 * a plan.
 */
final class OperatorTenantAction
{
    private const STATUSES = ['trial', 'active', 'suspended', 'cancelled'];

    public function __construct(
        private readonly PlatformRepository $platform,
        private readonly TenantProvisioner $provisioner,
    ) {
    }

    /** Platform-wide figures for the dashboard. */
    public function dashboard(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireOperator($request);

        return Json::ok($response, $this->platform->metrics());
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireOperator($request);

        $query = $request->getQueryParams();
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($query['perPage'] ?? 25)));

        $result = $this->platform->tenants(
            $this->str($query['search'] ?? null),
            $this->str($query['status'] ?? null),
            $page,
            $perPage,
        );

        return Json::page($response, $result['items'], $result['total'], $page, $perPage);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireOperator($request);

        $tenant = $this->platform->findTenant($args['id']);
        if ($tenant === null) {
            throw new EntityNotFound('Organisation', $args['id']);
        }

        return Json::ok($response, $tenant);
    }

    /** Provisions a new organisation and its first administrator. */
    public function provision(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireOperator($request);
        $body = $this->body($request);

        $name = $this->str($body['name'] ?? null);
        $code = $this->str($body['code'] ?? null);
        $email = $this->str($body['contactEmail'] ?? $body['adminEmail'] ?? null);
        $first = $this->str($body['adminFirstName'] ?? null) ?? 'Admin';
        $last = $this->str($body['adminLastName'] ?? null) ?? '';

        $violations = [];
        if ($name === null) {
            $violations['name'] = 'Name the organisation.';
        }
        if ($code === null || preg_match('/^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$/', strtolower((string) $code)) !== 1) {
            $violations['code'] = 'Give a short code — lowercase letters, numbers and hyphens.';
        }
        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $violations['contactEmail'] = 'Give the administrator a valid email address.';
        }
        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        $status = $this->str($body['status'] ?? null);
        if ($status === null || !in_array($status, self::STATUSES, true)) {
            $status = 'active';
        }

        try {
            $result = $this->provisioner->provision(
                name: (string) $name,
                code: strtolower((string) $code),
                contactEmail: (string) $email,
                adminFirstName: $first,
                adminLastName: $last,
                password: $this->str($body['password'] ?? null),
                planId: $this->str($body['planId'] ?? null),
                status: $status,
            );
        } catch (UniqueConstraintViolationException) {
            throw ValidationFailed::fromArray(['code' => 'That code, or that admin email, is already taken.']);
        }

        $tenant = $this->platform->findTenant($result['tenantId']);

        return Json::created($response, [
            'tenant' => $tenant,
            // Shown once, so the operator can hand it over; never stored in the
            // clear. Absent when the operator set the password themselves.
            'generatedPassword' => $result['generatedPassword'],
        ], '/v1/operator/tenants/' . $result['tenantId']);
    }

    /**
     * @param array<string, string> $args
     */
    public function updateStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireOperator($request);

        $status = $this->str($this->body($request)['status'] ?? null);
        if ($status === null || !in_array($status, self::STATUSES, true)) {
            throw ValidationFailed::fromArray(['status' => 'Choose trial, active, suspended or cancelled.']);
        }

        if (!$this->platform->updateStatus($args['id'], $status)) {
            throw new EntityNotFound('Organisation', $args['id']);
        }

        return Json::ok($response, $this->platform->findTenant($args['id']) ?? []);
    }

    /**
     * @param array<string, string> $args
     */
    public function updateLimits(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireOperator($request);
        $body = $this->body($request);

        $users = $this->limit($body, 'usersLimit');
        $storage = $this->limit($body, 'storageLimitMb');

        if (!$this->platform->updateLimits($args['id'], $users, $storage)) {
            throw new EntityNotFound('Organisation', $args['id']);
        }

        return Json::ok($response, $this->platform->findTenant($args['id']) ?? []);
    }

    /**
     * @param array<string, string> $args
     */
    public function assignPlan(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireOperator($request);

        $planId = $this->str($this->body($request)['planId'] ?? null);

        if (!$this->platform->assignPlan($args['id'], $planId)) {
            throw new EntityNotFound('Organisation or plan', $args['id']);
        }

        return Json::ok($response, $this->platform->findTenant($args['id']) ?? []);
    }

    // --- helpers --------------------------------------------------------------

    private function requireOperator(ServerRequestInterface $request): AuthenticatedUser
    {
        $caller = AuthenticationMiddleware::require($request);
        if (!$caller->isSuperAdmin()) {
            throw new AccessDenied('platform.operator');
        }

        return $caller;
    }

    /**
     * A limit from the body: a number, or null (unlimited) when the key is sent
     * empty. Absent key leaves it unchanged is not possible here — both are
     * always sent together — so a missing key reads as unlimited.
     *
     * @param array<string, mixed> $body
     */
    private function limit(array $body, string $key): ?int
    {
        $value = $body[$key] ?? null;

        return is_numeric($value) && (int) $value >= 0 ? (int) $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();

        return is_array($parsed) ? $parsed : [];
    }

    private function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
