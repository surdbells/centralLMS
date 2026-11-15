<?php

declare(strict_types=1);

namespace Central\Api\Tests\Doubles;

use Central\Api\Domain\Lookup\Lookup;
use Central\Api\Domain\Lookup\LookupKind;
use Central\Api\Domain\Lookup\LookupRepository;

/** @internal Reference data in memory, matched by name case-insensitively like the real one. */
final class InMemoryLookupRepository implements LookupRepository
{
    /** @var array<string, Lookup> by id */
    public array $lookups = [];

    public int $creates = 0;

    /**
     * @return list<string>
     */
    public function names(LookupKind $kind): array
    {
        $out = [];

        foreach ($this->lookups as $l) {
            if ($l->kind === $kind) {
                $out[] = $l->name;
            }
        }

        return $out;
    }

    public function paginate(
        string $tenantId,
        LookupKind $kind,
        int $page,
        int $perPage,
        ?string $search = null,
        string $sortBy = 'name',
        string $sortDirection = 'asc',
    ): array {
        $all = array_values(array_filter($this->lookups, static fn (Lookup $l): bool => $l->kind === $kind));

        return ['items' => array_slice($all, ($page - 1) * $perPage, $perPage), 'total' => count($all)];
    }

    public function active(string $tenantId, LookupKind $kind): array
    {
        return array_values(array_filter($this->lookups, static fn (Lookup $l): bool => $l->kind === $kind && $l->isActive));
    }

    public function find(string $tenantId, LookupKind $kind, string $id): ?Lookup
    {
        return $this->lookups[$id] ?? null;
    }

    public function findByName(string $tenantId, LookupKind $kind, string $name): ?Lookup
    {
        foreach ($this->lookups as $l) {
            if ($l->kind === $kind && strtolower($l->name) === strtolower($name)) {
                return $l;
            }
        }

        return null;
    }

    public function create(Lookup $lookup): Lookup
    {
        ++$this->creates;

        return $this->lookups[$lookup->id] = $lookup;
    }

    public function update(Lookup $lookup): Lookup
    {
        return $this->lookups[$lookup->id] = $lookup;
    }

    public function setActive(string $tenantId, LookupKind $kind, string $id, bool $isActive): bool
    {
        return false;
    }

    public function softDelete(string $tenantId, LookupKind $kind, string $id): bool
    {
        return false;
    }

    public function existsWithName(string $tenantId, LookupKind $kind, string $name, ?string $exceptId = null): bool
    {
        return $this->findByName($tenantId, $kind, $name) !== null;
    }

    public function existsWithCode(string $tenantId, LookupKind $kind, string $code, ?string $exceptId = null): bool
    {
        return false;
    }
}
