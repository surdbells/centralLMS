<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Lookup;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Competency\CompetencyRepository;
use Central\Api\Domain\Exception\ConflictingState;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Lookup\Lookup;
use Central\Api\Domain\Lookup\LookupKind;
use Central\Api\Domain\Lookup\LookupRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * POST /v1/lookups/{kind}
 */
final class CreateLookupAction extends LookupAction
{
    public function __construct(
        LookupRepository $lookups,
        private readonly CompetencyRepository $competency,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($lookups);
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $kind = $this->kind($args);
        $this->requireManage($request, $kind);

        $tenantId = $this->tenantId($request);
        $input = $this->body($request);

        // Validation lives on the entity, so HTTP and the data migration apply
        // identical rules.
        $lookup = Lookup::fromInput(Uuid::uuid4()->toString(), $tenantId, $kind, $input);

        $this->assertUnique($lookup);
        $this->assertParentExists($lookup);

        $created = $this->lookups->create($lookup);

        // Creating a cycle opens the exercise organisation-wide: start a draft
        // assessment for everyone who holds a job profile, so self-assessment
        // begins without anyone assigning people one by one. Best-effort — a
        // seeding failure must not undo a cycle that was created, and it is
        // idempotent, so it can be re-run.
        if ($kind === LookupKind::Cycle) {
            try {
                $this->competency->startCycleForAssignedEmployees($tenantId, $created->name);
            } catch (\Throwable $failure) {
                $this->logger->error('Could not auto-start assessments for a new cycle', [
                    'tenant' => $tenantId,
                    'cycle'  => $created->name,
                    'error'  => $failure->getMessage(),
                ]);
            }
        }

        return Json::created($response, $created->toArray(), sprintf(
            '/v1/lookups/%s/%s',
            $kind->plural(),
            $created->id,
        ));
    }

    private function assertUnique(Lookup $lookup): void
    {
        // Checked before insert for a clear message; the partial unique indexes
        // are what actually guarantee it under concurrency.
        if ($this->lookups->existsWithName($lookup->tenantId, $lookup->kind, $lookup->name)) {
            throw new ConflictingState(
                sprintf('A %s named "%s" already exists.', strtolower($lookup->kind->label()), $lookup->name),
                'duplicate_name',
            );
        }

        if (
            $lookup->code !== null
            && $this->lookups->existsWithCode($lookup->tenantId, $lookup->kind, $lookup->code)
        ) {
            throw new ConflictingState(
                sprintf('The code "%s" is already in use.', $lookup->code),
                'duplicate_code',
            );
        }
    }

    private function assertParentExists(Lookup $lookup): void
    {
        if ($lookup->parentId === null) {
            return;
        }

        // Scoped, so a parent id from another tenant reads as absent.
        if ($this->lookups->find($lookup->tenantId, $lookup->kind, $lookup->parentId) === null) {
            throw ValidationFailed::fromArray(['parentId' => 'The selected parent does not exist.']);
        }
    }
}
