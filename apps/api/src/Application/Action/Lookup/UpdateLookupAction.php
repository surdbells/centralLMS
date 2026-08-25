<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Lookup;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\EntityNotFound;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Lookup\Lookup;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PUT /v1/lookups/{kind}/{id}
 *
 * The legacy equivalent ran `UPDATE branches SET … WHERE branch_id = ?` with no
 * tenant clause, so any caller could edit another organisation's records by
 * guessing an id. Here the row is loaded scoped first, and the UPDATE is scoped
 * again.
 */
final class UpdateLookupAction extends LookupAction
{
    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->requireManage($request);

        $kind = $this->kind($args);
        $tenantId = $this->tenantId($request);
        $id = $args['id'] ?? '';

        $existing = $this->lookups->find($tenantId, $kind, $id);

        if ($existing === null) {
            throw new EntityNotFound($kind->label(), $id);
        }

        $lookup = Lookup::fromInput($id, $tenantId, $kind, $this->body($request));

        $this->assertUnique($lookup);
        $this->assertParentIsUsable($lookup);

        return Json::ok($response, $this->lookups->update($lookup)->toArray());
    }

    private function assertUnique(Lookup $lookup): void
    {
        // Excluding its own id, so saving without renaming is not a conflict.
        if ($this->lookups->existsWithName($lookup->tenantId, $lookup->kind, $lookup->name, $lookup->id)) {
            throw new ConflictingState(
                sprintf('A %s named "%s" already exists.', strtolower($lookup->kind->label()), $lookup->name),
                'duplicate_name',
            );
        }

        if (
            $lookup->code !== null
            && $this->lookups->existsWithCode($lookup->tenantId, $lookup->kind, $lookup->code, $lookup->id)
        ) {
            throw new ConflictingState(
                sprintf('The code "%s" is already in use.', $lookup->code),
                'duplicate_code',
            );
        }
    }

    private function assertParentIsUsable(Lookup $lookup): void
    {
        if ($lookup->parentId === null) {
            return;
        }

        $parent = $this->lookups->find($lookup->tenantId, $lookup->kind, $lookup->parentId);

        if ($parent === null) {
            throw ValidationFailed::fromArray(['parentId' => 'The selected parent does not exist.']);
        }

        // One level of cycle protection. A full ancestry walk belongs here once
        // categories nest more than two deep in practice.
        if ($parent->parentId === $lookup->id) {
            throw ValidationFailed::fromArray([
                'parentId' => 'That would create a loop: the selected parent is already a child of this item.',
            ]);
        }
    }
}
