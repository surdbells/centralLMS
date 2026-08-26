<?php

declare(strict_types=1);

namespace Central\Api\Domain\Enrollment;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * An administrator's request to put people on a course.
 *
 * The legacy `CourseEnrollment::enrollUsers()` accepted a user list, a
 * department, a team or a branch and branched internally on which was present.
 * This makes the choice explicit and validates it once, so the action does not
 * have to work out what was meant.
 */
final readonly class AssignmentRequest
{
    /**
     * @param list<string> $userIds explicit targets, empty when a group was named
     */
    private function __construct(
        public EnrollmentSource $source,
        public array $userIds = [],
        public ?string $groupId = null,
        public bool $isMandatory = true,
        public ?\DateTimeImmutable $dueDate = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationFailed
     */
    public static function fromInput(array $input): self
    {
        $violations = [];

        $isMandatory = filter_var($input['isMandatory'] ?? true, FILTER_VALIDATE_BOOL);
        $dueDate = self::parseDate($input['dueDate'] ?? null);

        if (isset($input['dueDate']) && $input['dueDate'] !== '' && $dueDate === null) {
            $violations['dueDate'] = 'Use a date in the form 2026-03-01.';
        }

        $userIds = self::ids($input['userIds'] ?? null);
        $departmentId = self::text($input['departmentId'] ?? null);
        $teamId = self::text($input['teamId'] ?? null);
        $branchId = self::text($input['branchId'] ?? null);

        // Exactly one target. Accepting both a user list and a department
        // would leave "which won?" to whichever branch happened to run first.
        $named = [];

        if ($userIds !== []) {
            $named[] = 'userIds';
        }

        if ($departmentId !== null) {
            $named[] = 'departmentId';
        }

        if ($teamId !== null) {
            $named[] = 'teamId';
        }

        if ($branchId !== null) {
            $named[] = 'branchId';
        }

        if ($named === []) {
            $violations['target'] = 'Name who to enrol: userIds, or one of departmentId, teamId, branchId.';
        } elseif (count($named) > 1) {
            $violations['target'] = 'Name one group of people, not several: ' . implode(', ', $named);
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        if ($departmentId !== null) {
            return new self(EnrollmentSource::Department, [], $departmentId, $isMandatory, $dueDate);
        }

        if ($teamId !== null) {
            return new self(EnrollmentSource::Team, [], $teamId, $isMandatory, $dueDate);
        }

        if ($branchId !== null) {
            return new self(EnrollmentSource::Branch, [], $branchId, $isMandatory, $dueDate);
        }

        return new self(
            // A list of one is a direct assignment, a longer list a bulk one.
            // The distinction is what the legacy reports were already drawing.
            source: count($userIds) === 1 ? EnrollmentSource::Direct : EnrollmentSource::Bulk,
            userIds: $userIds,
            isMandatory: $isMandatory,
            dueDate: $dueDate,
        );
    }

    /**
     * Self-enrolment: the learner, on their own behalf, with no deadline.
     */
    public static function selfEnrolment(string $userId): self
    {
        return new self(
            source: EnrollmentSource::SelfEnrolled,
            userIds: [$userId],
            // Nobody makes a course mandatory for themselves, and marking it
            // so would put it on their compliance list.
            isMandatory: false,
        );
    }

    /**
     * @return list<string>
     */
    private static function ids(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $id) {
            $id = is_scalar($id) ? trim((string) $id) : '';

            if ($id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function text(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : null;

        return $value === null || $value === '' ? null : $value;
    }

    private static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($value));
        } catch (\Exception) {
            return null;
        }
    }
}
