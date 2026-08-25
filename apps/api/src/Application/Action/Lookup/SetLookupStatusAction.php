<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Lookup;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Exception\EntityNotFound;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * PATCH /v1/lookups/{kind}/{id}/status
 *
 * Activates or deactivates. Reference data is never hard-deleted: users,
 * courses and enrolments point at it, and removing a department would orphan
 * everyone assigned to it. Deactivating keeps history intact while taking the
 * entry out of every dropdown.
 */
final class SetLookupStatusAction extends LookupAction
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

        $body = $this->body($request);

        $current = $this->lookups->find($tenantId, $kind, $id);

        if ($current === null) {
            throw new EntityNotFound($kind->label(), $id);
        }

        // Absent means "toggle", which is what the legacy status.php did.
        $isActive = array_key_exists('isActive', $body)
            ? filter_var($body['isActive'], FILTER_VALIDATE_BOOL)
            : !$current->isActive;

        if (!$this->lookups->setActive($tenantId, $kind, $id, $isActive)) {
            throw new EntityNotFound($kind->label(), $id);
        }

        $updated = $this->lookups->find($tenantId, $kind, $id);

        return $updated === null
            ? throw new EntityNotFound($kind->label(), $id)
            : Json::ok($response, $updated->toArray());
    }
}
