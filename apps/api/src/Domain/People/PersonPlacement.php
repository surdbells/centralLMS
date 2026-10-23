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
        public ?string $lineExecutive = null,
    ) {
    }

    /**
     * The `…Name` keys every screen reads.
     *
     * The suffix is deliberate and load-bearing: the web and mobile apps bind
     * `placement.departmentName`, `placement.managerName` and so on throughout.
     * Emitting the bare `department` these were resolved from would leave every
     * one of those bindings undefined — which is exactly how the org side of
     * the profile came to show a column of dashes.
     *
     * @return array<string, ?string>
     */
    public function toArray(): array
    {
        return [
            'branchName'        => $this->branch,
            'departmentName'    => $this->department,
            'teamName'          => $this->team,
            'levelName'         => $this->level,
            'jobTitleName'      => $this->jobTitle,
            'managerName'       => $this->manager,
            'lineExecutiveName' => $this->lineExecutive,
        ];
    }
}
