<?php

declare(strict_types=1);

namespace Central\Api\Domain\People;

/**
 * Where somebody sits in the organisation, by name rather than by id.
 *
 * Resolved from `lookups` when a screen needs to show it. Separate from the
 * ids on {@see Person} because they are different things: the ids are what is
 * stored and edited, these are what is displayed, and conflating them is how
 * the legacy ended up with `getDepartmentName()` called once per row inside a
 * loop over the user list.
 */
final readonly class PersonPlacement
{
    public function __construct(
        public ?string $branch = null,
        public ?string $department = null,
        public ?string $team = null,
        public ?string $level = null,
        public ?string $jobTitle = null,
        public ?string $manager = null,
    ) {
    }

    /**
     * @return array<string, ?string>
     */
    public function toArray(): array
    {
        return [
            'branch'     => $this->branch,
            'department' => $this->department,
            'team'       => $this->team,
            'level'      => $this->level,
            'jobTitle'   => $this->jobTitle,
            'manager'    => $this->manager,
        ];
    }
}
