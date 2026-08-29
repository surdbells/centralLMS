<?php

declare(strict_types=1);

namespace Central\Api\Domain\Competency;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * A Job Competency Profile: the competencies a role needs, and to what level.
 *
 * The standard a person in the job is measured against. Its requirements are
 * the whole of it — a profile with no competencies measures nothing — so they
 * travel with it rather than being fetched separately.
 */
final readonly class JobCompetencyProfile
{
    /**
     * @param list<array{competencyId: string, competencyName?: string, requiredLevel: int, isCritical: bool}> $requirements
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $jobTitle,
        public string $functionName,
        public ?string $description = null,
        public array $requirements = [],
        public ?\DateTimeImmutable $createdAt = null,
        public int $assessmentCount = 0,
        /**
         * How many competencies the profile requires. The list view counts
         * this in SQL and leaves {@see $requirements} empty to avoid loading
         * every requirement of every profile; the detail view loads the
         * requirements and lets this fall back to counting them.
         */
        public ?int $competencyCount = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationFailed
     */
    public static function fromInput(string $id, string $tenantId, array $input): self
    {
        $title = trim((string) ($input['jobTitle'] ?? ''));
        $function = trim((string) ($input['functionName'] ?? $input['function'] ?? ''));

        $violations = [];

        if ($title === '') {
            $violations['jobTitle'] = 'A profile needs a job title.';
        }

        if ($function === '') {
            $violations['functionName'] = 'Say which function it belongs to.';
        }

        $requirements = [];
        $rawRequirements = is_array($input['requirements'] ?? null) ? $input['requirements'] : [];
        $seen = [];

        foreach (array_values($rawRequirements) as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $competencyId = trim((string) ($entry['competencyId'] ?? ''));
            $level = ProficiencyLevel::parse($entry['requiredLevel'] ?? null);

            if ($competencyId === '') {
                $violations["requirements.$index"] = 'Choose a competency.';

                continue;
            }

            if ($level === null || $level === ProficiencyLevel::None) {
                $violations["requirements.$index"] = 'A requirement needs a level of Knowledge, Skill or Mastery.';

                continue;
            }

            if (isset($seen[$competencyId])) {
                $violations["requirements.$index"] = 'That competency is listed twice.';

                continue;
            }

            $seen[$competencyId] = true;
            $requirements[] = [
                'competencyId'  => $competencyId,
                'requiredLevel' => $level->value,
                'isCritical'    => (bool) ($entry['isCritical'] ?? false),
            ];
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        return new self(
            id: $id,
            tenantId: $tenantId,
            jobTitle: $title,
            functionName: $function,
            description: self::text($input['description'] ?? null),
            requirements: $requirements,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'jobTitle'     => $this->jobTitle,
            'functionName' => $this->functionName,
            'description'  => $this->description,
            'requirements' => array_map(
                static fn (array $r): array => [
                    'competencyId'   => $r['competencyId'],
                    'competencyName' => $r['competencyName'] ?? null,
                    'requiredLevel'  => $r['requiredLevel'],
                    'requiredLabel'  => ProficiencyLevel::from((int) $r['requiredLevel'])->label(),
                    'isCritical'     => $r['isCritical'],
                ],
                $this->requirements,
            ),
            'competencyCount' => $this->competencyCount ?? count($this->requirements),
            'assessmentCount' => $this->assessmentCount,
            'createdAt'       => $this->createdAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
