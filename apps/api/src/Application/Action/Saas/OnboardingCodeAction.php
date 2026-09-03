<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Saas;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Saas\OnboardingCode;
use Central\Api\Domain\Saas\OnboardingCodeRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The operator managing the onboarding codes that gate public sign-up.
 *
 * Cross-tenant platform administration — a code exists before any organisation
 * it creates — so every method needs a super admin. Issue a code, set how many
 * organisations it may create and which plans it unlocks, and retire it.
 */
final class OnboardingCodeAction
{
    public function __construct(private readonly OnboardingCodeRepository $codes)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->requireOperator($request);

        return Json::ok($response, [
            'codes' => array_map(static fn (OnboardingCode $c): array => $c->toArray(), $this->codes->all()),
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $caller = $this->requireOperator($request);
        $code = $this->fromBody($this->body($request), null);

        try {
            $created = $this->codes->create($code, $caller->id);
        } catch (UniqueConstraintViolationException) {
            throw ValidationFailed::fromArray(['code' => 'A code with that value already exists.']);
        }

        return Json::created($response, $created->toArray(), '/v1/operator/onboarding-codes/' . $created->id);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireOperator($request);

        $existing = $this->codes->find($args['id']);
        if ($existing === null) {
            throw new EntityNotFound('Onboarding code', $args['id']);
        }

        try {
            $updated = $this->codes->update($args['id'], $this->fromBody($this->body($request), $existing));
        } catch (UniqueConstraintViolationException) {
            throw ValidationFailed::fromArray(['code' => 'A code with that value already exists.']);
        }

        if ($updated === null) {
            throw new EntityNotFound('Onboarding code', $args['id']);
        }

        return Json::ok($response, $updated->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->requireOperator($request);

        if (!$this->codes->softDelete($args['id'])) {
            throw new EntityNotFound('Onboarding code', $args['id']);
        }

        return Json::noContent($response);
    }

    // --- helpers --------------------------------------------------------------

    /**
     * Builds a code from the request body. On an update the existing code fills
     * anything not sent, and its use count is carried over untouched — that is
     * the sign-up's to change, not the operator's.
     *
     * @param array<string, mixed> $body
     */
    private function fromBody(array $body, ?OnboardingCode $existing): OnboardingCode
    {
        $code = $this->str($body['code'] ?? null) ?? $existing?->code;
        if ($code === null) {
            throw ValidationFailed::fromArray(['code' => 'Give the code a value.']);
        }

        return new OnboardingCode(
            id: $existing?->id,
            code: $code,
            description: array_key_exists('description', $body) ? $this->str($body['description']) : $existing?->description,
            maxUses: $this->nullableInt($body, 'maxUses', $existing?->maxUses),
            usedCount: $existing?->usedCount ?? 0,
            allowedPlanIds: $this->planIds($body, $existing?->allowedPlanIds),
            validFrom: $this->date($body, 'validFrom', $existing?->validFrom),
            validUntil: $this->date($body, 'validUntil', $existing?->validUntil),
            isActive: $this->bool($body['isActive'] ?? null, $existing?->isActive ?? true),
        );
    }

    /**
     * The plan allow-list: an array of ids, or null (any plan). An absent key
     * leaves it unchanged; an explicit null or empty array clears it to "any".
     *
     * @param array<string, mixed> $body
     * @param list<string>|null    $current
     * @return list<string>|null
     */
    private function planIds(array $body, ?array $current): ?array
    {
        if (!array_key_exists('allowedPlanIds', $body)) {
            return $current;
        }

        $value = $body['allowedPlanIds'];
        if (!is_array($value) || $value === []) {
            return null;
        }

        $ids = [];
        foreach ($value as $id) {
            if (is_string($id) && trim($id) !== '') {
                $ids[] = trim($id);
            }
        }

        return $ids === [] ? null : array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function date(array $body, string $key, ?\DateTimeImmutable $current): ?\DateTimeImmutable
    {
        if (!array_key_exists($key, $body)) {
            return $current;
        }

        $value = $this->str($body[$key]);
        if ($value === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw ValidationFailed::fromArray([$key => 'Give a valid date.']);
        }
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

    /**
     * @param array<string, mixed> $body
     */
    private function nullableInt(array $body, string $key, ?int $default): ?int
    {
        if (!array_key_exists($key, $body)) {
            return $default;
        }

        return is_numeric($body[$key]) ? max(0, (int) $body[$key]) : null;
    }

    private function bool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
