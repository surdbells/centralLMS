<?php

declare(strict_types=1);

namespace Central\Api\Domain\LearningPath;

use Central\Api\Domain\Audience\AudienceTarget;
use Central\Api\Domain\Exception\ValidationFailed;

/**
 * A standing rule that puts people on a learning path.
 *
 * Not the same thing as enrolling everybody in Operations this afternoon. A
 * rule outlives the moment it was made: somebody who joins Operations next
 * month is enrolled by it too, which is the whole reason a rule exists rather
 * than a list.
 *
 * Due dates come in two shapes and only one at a time. A fixed date is the
 * same day for everybody — right for "before the audit in March". An offset
 * is counted from each person's own enrolment — right for "within 30 days of
 * joining". A rule carrying both has two answers to when it is due.
 */
final readonly class PathAssignment
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $pathId,
        public AudienceTarget $targetType,
        /** @var array<string, mixed> */
        public array $targetCriteria = [],
        public ?string $dueDate = null,
        public ?int $dueDaysFromAssignment = null,
        public AssignmentPriority $priority = AssignmentPriority::Normal,
        public bool $isMandatory = false,
        public bool $sendNotification = true,
        public bool $sendReminder = false,
        public int $reminderDaysBefore = 3,
        public ?string $notes = null,
        public AssignmentStatus $status = AssignmentStatus::Active,
        public ?string $assignedBy = null,
        public ?\DateTimeImmutable $assignedAt = null,
        public ?\DateTimeImmutable $lastAppliedAt = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
        /** Joined for display. */
        public ?string $pathTitle = null,
        public ?string $assignedByName = null,
        /** Counted from the enrolments this rule made, never stored. */
        public int $enrolledCount = 0,
        public int $completedCount = 0,
        public int $overdueCount = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationFailed
     */
    public static function fromInput(
        string $id,
        string $tenantId,
        string $pathId,
        array $input,
        ?string $assignedBy = null,
    ): self {
        $violations = [];

        $target = AudienceTarget::tryFrom((string) ($input['targetType'] ?? 'all'));

        if ($target === null) {
            $violations['targetType'] = 'Choose who this covers.';
            $target = AudienceTarget::All;
        }

        $criteria = is_array($input['targetCriteria'] ?? null) ? $input['targetCriteria'] : [];

        if ($target->needsCriteria() && $criteria === []) {
            // A department rule with no department covers the whole
            // organisation, silently, on its first sweep.
            $violations['targetCriteria'] = sprintf('Choose at least one %s.', $target->noun());
        }

        $dueDate = self::text($input['dueDate'] ?? null);
        $offset = self::offset($input['dueDaysFromAssignment'] ?? null);

        if ($dueDate !== null && $offset !== null) {
            $violations['dueDate'] = 'Use a fixed date or a number of days, not both.';
        }

        if ($offset !== null && $offset < 1) {
            $violations['dueDaysFromAssignment'] = 'Give at least one day.';
        }

        if ($dueDate !== null && !self::isDate($dueDate)) {
            $violations['dueDate'] = 'That is not a date.';
        }

        $priority = AssignmentPriority::tryFrom((string) ($input['priority'] ?? 'normal'));

        if ($priority === null) {
            $violations['priority'] = 'Unknown priority.';
            $priority = AssignmentPriority::Normal;
        }

        $reminderDays = self::offset($input['reminderDaysBefore'] ?? null) ?? 3;

        if ($reminderDays < 0) {
            $violations['reminderDaysBefore'] = 'Give nought days or more.';
        }

        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        return new self(
            id: $id,
            tenantId: $tenantId,
            pathId: $pathId,
            targetType: $target,
            targetCriteria: $criteria,
            dueDate: $dueDate,
            dueDaysFromAssignment: $offset,
            priority: $priority,
            isMandatory: (bool) ($input['isMandatory'] ?? false),
            sendNotification: (bool) ($input['sendNotification'] ?? true),
            sendReminder: (bool) ($input['sendReminder'] ?? false),
            reminderDaysBefore: $reminderDays,
            notes: self::text($input['notes'] ?? null),
            assignedBy: $assignedBy,
        );
    }

    /**
     * The due date for somebody enrolled today.
     *
     * Null when the rule sets no deadline at all — which is not the same as a
     * deadline of today, and must not become one.
     */
    public function dueDateFor(\DateTimeImmutable $enrolledAt): ?string
    {
        if ($this->dueDate !== null) {
            return $this->dueDate;
        }

        if ($this->dueDaysFromAssignment === null) {
            return null;
        }

        return $enrolledAt
            ->modify(sprintf('+%d days', $this->dueDaysFromAssignment))
            ->format('Y-m-d');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'pathId'   => $this->pathId,
            'pathTitle' => $this->pathTitle,
            'targetType'     => $this->targetType->value,
            'targetLabel'    => $this->targetType->label(),
            'targetCriteria' => $this->targetCriteria,
            'dueDate'                => $this->dueDate,
            'dueDaysFromAssignment'  => $this->dueDaysFromAssignment,
            'priority'      => $this->priority->value,
            'isMandatory'   => $this->isMandatory,
            'sendNotification'   => $this->sendNotification,
            'sendReminder'       => $this->sendReminder,
            'reminderDaysBefore' => $this->reminderDaysBefore,
            'notes'   => $this->notes,
            'status'  => $this->status->value,
            'assignedBy'     => $this->assignedBy,
            'assignedByName' => $this->assignedByName,
            'assignedAt'     => $this->assignedAt?->format(\DateTimeInterface::ATOM),
            // When the sweep last looked. Null means it has never run for this
            // rule, which is different from "it ran and enrolled nobody".
            'lastAppliedAt'  => $this->lastAppliedAt?->format(\DateTimeInterface::ATOM),
            'enrolledCount'  => $this->enrolledCount,
            'completedCount' => $this->completedCount,
            'overdueCount'   => $this->overdueCount,
            'createdAt' => $this->createdAt?->format(\DateTimeInterface::ATOM),
            'updatedAt' => $this->updatedAt?->format(\DateTimeInterface::ATOM),
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

    private static function offset(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private static function isDate(string $value): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4));
    }
}
