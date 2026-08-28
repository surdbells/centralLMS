<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Enrollment;

/**
 * The shape every subject reports its rule's figures in.
 *
 * Each subject counts differently — a path's progress is items finished, a
 * programme's is sessions attended — but a screen showing "what has this rule
 * done" should not have to know which it is looking at.
 */
final class RuleStatistics
{
    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function from(array $row): array
    {
        $enrolled = (int) ($row['enrolled'] ?? 0);
        $completed = (int) ($row['completed'] ?? 0);

        return [
            'enrolled'   => $enrolled,
            'completed'  => $completed,
            'inProgress' => (int) ($row['in_progress'] ?? 0),
            'notStarted' => (int) ($row['not_started'] ?? 0),
            'overdue'    => (int) ($row['overdue'] ?? 0),
            // Null rather than zero when nobody is enrolled or nobody has
            // finished: no completion rate is not a completion rate of nought,
            // and "nobody has finished yet" is not an average of nought days.
            'completionRate' => $enrolled === 0
                ? null
                : round(($completed / $enrolled) * 100, 1),
            'averageProgress' => ($row['avg_progress'] ?? null) === null
                ? null
                : round((float) $row['avg_progress'], 1),
            'averageDaysToComplete' => ($row['avg_days'] ?? null) === null
                ? null
                : round((float) $row['avg_days'], 1),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    public static function pending(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'enrollmentId' => (string) $row['enrollment_id'],
            'userId'    => (string) $row['user_id'],
            'name'      => trim((string) $row['name']),
            'email'     => (string) $row['email'],
            'dueDate'   => $row['due_date'] === null ? null : (string) $row['due_date'],
            'startedAt' => $row['started_at'] === null ? null : (string) $row['started_at'],
        ], $rows);
    }
}
