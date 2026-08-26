<?php

declare(strict_types=1);

namespace Central\Api\Domain\Proctoring;

/**
 * Which paid modules a tenant may use.
 *
 * Proctoring is licensed separately, so the check has to exist somewhere. It
 * lives here rather than in a permission because the two answer different
 * questions: a permission says "may this person do it", an entitlement says
 * "has this organisation bought it". Both must hold.
 */
final readonly class ModuleEntitlements
{
    public const PROCTORING = 'proctoring';

    /**
     * @param array<string, bool> $modules
     */
    private function __construct(private array $modules)
    {
    }

    /**
     * @param array<string, mixed> $stored the tenant's `entitlements` column
     */
    public static function fromStored(array $stored): self
    {
        $modules = [];

        foreach ($stored as $name => $enabled) {
            if (is_string($name)) {
                $modules[$name] = filter_var($enabled, FILTER_VALIDATE_BOOL);
            }
        }

        return new self($modules);
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Absent means not entitled.
     *
     * Defaulting the other way would turn a missing row, a failed read or a
     * typo into a licence, which is the wrong direction for a paid module to
     * fail in.
     */
    public function has(string $module): bool
    {
        return $this->modules[$module] ?? false;
    }

    /**
     * @return list<string>
     */
    public function enabled(): array
    {
        return array_keys(array_filter($this->modules));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['modules' => $this->enabled()];
    }
}
