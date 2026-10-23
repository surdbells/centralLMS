<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\CustomField;

use Central\Api\Application\Http\Json;
use Central\Api\Application\Middleware\AuthenticationMiddleware;
use Central\Api\Domain\Authz\AuthenticatedUser;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\CustomField\CustomFieldDefinition;
use Central\Api\Domain\CustomField\CustomFieldDefinitionRepository;
use Central\Api\Domain\Exception\AccessDenied;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Ramsey\Uuid\Uuid;

/**
 * A tenant's custom profile fields.
 *
 * GET    /v1/custom-fields
 * POST   /v1/custom-fields
 * PUT    /v1/custom-fields/{id}
 * DELETE /v1/custom-fields/{id}
 *
 * Reading the definitions needs no permission — a learner's own profile screen
 * has to know what fields to render and what to call them. Changing them is an
 * administrator's, under the same `user.manage` that governs the people whose
 * profiles the fields describe, so no new permission has to be granted before
 * the feature works.
 */
final class ManageCustomFieldsAction
{
    public function __construct(private readonly CustomFieldDefinitionRepository $fields)
    {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->tenantId($request);

        // An administrator sees every field, to manage them; everyone else sees
        // only the active ones, which are all a profile form should render.
        $activeOnly = !$this->caller($request)->canAny([Permission::UserManage]);

        return Json::ok(
            $response,
            array_map(
                static fn (CustomFieldDefinition $d): array => $d->toArray(),
                $this->fields->allForTenant($tenantId, $activeOnly),
            ),
        );
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->caller($request)->mustHave(Permission::UserManage);

        $tenantId   = $this->tenantId($request);
        $definition = CustomFieldDefinition::fromInput(Uuid::uuid4()->toString(), $tenantId, $this->body($request));

        if ($this->fields->keyExists($tenantId, $definition->key)) {
            throw new ConflictingState(
                sprintf('A field keyed "%s" already exists.', $definition->key),
                'duplicate_key',
            );
        }

        $created = $this->fields->create($definition);

        return Json::created($response, $created->toArray(), '/v1/custom-fields/' . $created->id);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::UserManage);

        $tenantId = $this->tenantId($request);
        $existing = $this->fields->find($tenantId, $args['id'] ?? '')
            ?? throw new EntityNotFound('Custom field', $args['id'] ?? '');

        // The key and type are fixed: renaming the key would orphan every value
        // already stored under it, and changing the type would strand values
        // that no longer coerce. Only the label, options and flags change.
        $definition = CustomFieldDefinition::fromInput(
            $existing->id,
            $tenantId,
            $this->body($request),
            existingKey: $existing->key,
            existingType: $existing->type,
        );

        return Json::ok($response, $this->fields->update($definition)->toArray());
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->caller($request)->mustHave(Permission::UserManage);

        if (!$this->fields->delete($this->tenantId($request), $args['id'] ?? '')) {
            throw new EntityNotFound('Custom field', $args['id'] ?? '');
        }

        return Json::noContent($response);
    }

    // --- plumbing ---------------------------------------------------------

    private function caller(ServerRequestInterface $request): AuthenticatedUser
    {
        return AuthenticationMiddleware::require($request);
    }

    /**
     * Custom fields are a tenant's own, so a caller without a tenant — a super
     * admin acting globally — has no set to read or edit.
     *
     * @throws AccessDenied
     */
    private function tenantId(ServerRequestInterface $request): string
    {
        $user = $this->caller($request);

        if ($user->tenantId === null) {
            throw new AccessDenied(Permission::UserView->value);
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
