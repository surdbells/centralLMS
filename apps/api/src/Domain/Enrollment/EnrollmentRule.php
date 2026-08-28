<?php

declare(strict_types=1);

namespace Central\Api\Domain\Enrollment;

use Central\Api\Domain\Audience\AudienceTarget;
use Central\Api\Domain\Exception\ValidationFailed;

/**
 * A standing rule that puts people onto something.
 *
 * Not the same thing as enrolling everybody in Operations this afternoon. A
 * rule outlives the moment it was made: somebody who joins Operations next
 * month is enrolled by it too, which is the whole reason a rule exists rather
 * than a list.
 *
 * What it enrols people onto — a learning path, a training programme — is a
 * field, not a class. Everything a rule does is the same either way, and two
 * classes would be two sweeps to keep in step and two places for the answer to
 * "what does pausing do" to drift apart.
 *
 * Due dates come in two shapes and only one at a time. A fixed date is the
 * same day for everybody — right for "before the audit in March". An offset is
 * counted from each person's own enrolment — right for "within 30 days of
 * joining". A rule carrying both has two answers to when it is due.
 */
final readonly class EnrollmentRule
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public RuleSubject $subjectKind,
        public string $subjectId,
        public AudienceTarget $targetType,
        /** @var array<string, mixed> */
        public array $targetCriteria = [],
        public ?string $dueDate = null,
        public ?int $dueDaysFromEnrollment = null,
        public RulePriority $priority = RulePriority::Normal,
        public bool $isMandatory = false,
        public bool $sendNotification = true,
        public bool $sendReminder = false,
        public int $reminderDaysBefore = 3,
        public ?string $notes = null,
        public RuleStatus $status = RuleStatus::Active,
        public ?string $createdBy = null,
        public ?\DateTimeImmutable $createdAt = null,
        public ?\DateTimeImmutable $lastAppliedAt = null,
        public ?\DateTimeImmutable $updatedAt = null,
        /** Joined for display. */
        public ?string $subjectTitle = null,
        public ?string $createdByName = null,
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
        RuleSubject $subjectKind,
        string $subjectId,
        array $input,
        ?string $createdBy = null,
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
        $offset = self::offset($input['dueDaysFromEnrollment'] ?? $input['dueDaysFromAssignment'] ?? null);

        if ($dueDate !== null && $offset !== null) {
            $violations['dueDate'] = 'Use a fixed date or a number of days, not both.';
        }

        if ($offset !== null && $offset < 1) {
            $violations['dueDaysFromEnrollment'] = 'Give at least one day.';
        }

        if ($dueDate !== null && !self::isDate($dueDate)) {
            $violations['dueDate'] = 'That is not a date.';
        }

        $priority = RulePriority::tryFrom((string) ($input['priority'] ?? 'normal'));

        if ($priority === null) {
            $violations['priority'] = 'Unknown priority.';
            $priority = RulePriority::Normal;
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
            subjectKind: $subjectKind,
            subjectId: $subjectId,
            targetType: $target,
            targetCriteria: $criteria,
            dueDate: $dueDate,
            dueDaysFromEnrollment: $offset,
            priority: $priority,
            isMandatory: (bool) ($input['isMandatory'] ?? false),
            sendNotification: (bool) ($input['sendNotification'] ?? true),
            sendReminder: (bool) ($input['sendReminder'] ?? false),
            reminderDaysBefore: $reminderDays,
            notes: self::text($input['notes'] ?? null),
            createdBy: $createdBy,
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

        if ($this->dueDaysFromEnrollment === null) {
            return null;
        }

        return $enrolledAt
            ->modify(sprintf('+%d days', $this->dueDaysFromEnrollment))
            ->format('Y-m-d');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'subjectKind'  => $this->subjectKind->value,
            'subjectId'    => $this->subjectId,
            'subjectTitle' => $this->subjectTitle,
            // The old names, for screens that were written against a rule
            // that could only ever mean a path.
            'pathId'    => $this->subjectKind === RuleSubject::Path ? $this->subjectId : null,
            'pathTitle' => $this->subjectKind === RuleSubject::Path ? $this->subjectTitle : null,
            'targetType'     => $this->targetType->value,
            'targetLabel'    => $this->targetType->label(),
            'targetCriteria' => $this->targetCriteria,
            'dueDate'               => $this->dueDate,
            'dueDaysFromEnrollment' => $this->dueDaysFromEnrollment,
            'priority'      => $this->priority->value,
            'isMandatory'   => $this->isMandatory,
            'sendNotification'   => $this->sendNotification,
            'sendReminder'       => $this->sendReminder,
            'reminderDaysBefore' => $this->reminderDaysBefore,
            'notes'   => $this->notes,
            'status'  => $this->status->value,
            'createdBy'     => $this->createdBy,
            'createdByName' => $this->createdByName,
            // When the sweep last looked. Null means it has never run for this
            // rule, which is different from "it ran and enrolled nobody".
            'lastAppliedAt' => $this->lastAppliedAt?->format(\DateTimeInterface::ATOM),
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
