<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Saas;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Saas\Plan;
use Central\Api\Domain\Saas\PlanRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The SaaS plan catalogue.
 *
 * `catalogue` is public — the pricing page and the sign-up wizard read it with
 * no session, and it returns only the active, public plans. Everything else is
 * the operator managing the catalogue and needs a super admin.
 */
final class PlanAction
{
    public function __construct(private readonly PlanRepository $plans)
    {
    }

    /** Public: the plans on offer, for the pricing page and sign-up. */
    public function catalogue(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return Json::ok($response, [
            'plans' => array_map(static fn (Plan $p): array => $p->toArray(), $this->plans->publicPlans()),
        ]);
    }

    /** Operator: the whole catalogue, retired plans included. */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireOperator($request);

        return Json::ok($response, [
            'plans' => array_map(static fn (Plan $p): array => $p->toArray(), $this->plans->all()),
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireOperator($request);
        $plan = $this->fromBody($this->body($request), null);

        try {
            $created = $this->plans->create($plan);
        } catch (UniqueConstraintViolationException) {
            throw ValidationFailed::fromArray(['code' => 'A plan with that code already exists.']);
        }

        return Json::created($response, $created->toArray(), '/v1/operator/plans/' . $created->id);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireOperator($request);

        $existing = $this->plans->find($args['id']);
        if ($existing === null) {
            throw new EntityNotFound('Plan', $args['id']);
        }

        $updated = $this->plans->update($args['id'], $this->fromBody($this->body($request), $existing));
        if ($updated === null) {
            throw new EntityNotFound('Plan', $args['id']);
        }

        return Json::ok($response, $updated->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireOperator($request);

        if (!$this->plans->softDelete($args['id'])) {
            throw new EntityNotFound('Plan', $args['id']);
        }

        return Json::noContent($response);
    }

    // --- helpers --------------------------------------------------------------

    /**
     * Builds a Plan from the request body. On an update the existing plan fills
     * anything not sent; the code cannot be changed, so it always comes from
     * the existing plan when there is one.
     *
     * @param array<string, mixed> $body
     */
    private function fromBody(array $body, ?Plan $existing): Plan
    {
        $code = $existing?->code ?? $this->str($body['code'] ?? null);
        $name = $this->str($body['name'] ?? null) ?? $existing?->name;

        $violations = [];
        if ($code === null) {
            $violations['code'] = 'Give the plan a code.';
        }
        if ($name === null) {
            $violations['name'] = 'Give the plan a name.';
        }
        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        return new Plan(
            id: $existing?->id,
            code: (string) $code,
            name: (string) $name,
            description: array_key_exists('description', $body) ? $this->str($body['description']) : $existing?->description,
            priceMonthly: $this->int($body['priceMonthly'] ?? null, $existing?->priceMonthly ?? 0),
            priceYearly: $this->int($body['priceYearly'] ?? null, $existing?->priceYearly ?? 0),
            currency: $this->str($body['currency'] ?? null) ?? $existing?->currency ?? 'NGN',
            usersLimit: $this->nullableInt($body, 'usersLimit', $existing?->usersLimit),
            storageLimitMb: $this->nullableInt($body, 'storageLimitMb', $existing?->storageLimitMb),
            features: is_array($body['features'] ?? null) ? $body['features'] : ($existing?->features ?? []),
            trialDays: $this->int($body['trialDays'] ?? null, $existing?->trialDays ?? 14),
            isActive: $this->bool($body['isActive'] ?? null, $existing?->isActive ?? true),
            isPublic: $this->bool($body['isPublic'] ?? null, $existing?->isPublic ?? true),
            sortOrder: $this->int($body['sortOrder'] ?? null, $existing?->sortOrder ?? 0),
        );
    }

    private function requireOperator(ServerRequestInterface $request): AuthenticatedUser
    {
        $caller = AuthenticationMiddleware::require($request);
        if (!$caller->isSuperAdmin()) {
            throw new AccessDenied('platform.operator');
        }

        return $caller;
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

    private function int(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function nullableInt(array $body, string $key, ?int $default): ?int
    {
        if (!array_key_exists($key, $body)) {
            return $default;
        }

        return is_numeric($body[$key]) ? (int) $body[$key] : null;
    }

    private function bool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
