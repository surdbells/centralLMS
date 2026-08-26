<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * Turns an event and its data into the words a person reads.
 *
 * Pure, and deliberately not a template engine. Notification wording is short,
 * fixed, and needs to be reviewable in one place; the legacy's approach —
 * whatever string the calling code happened to pass, stored forever — is why
 * the ten seeded rows include both "Course Completed!" and "Course
 * Completion" for the same event.
 *
 * Composition happens once, at raise time, and the result is stored on the
 * row. That is not a shortcut: a notification is a record of what someone was
 * told, so a course renamed next year must not silently rewrite the message
 * they read today.
 */
final readonly class NotificationComposer
{
    /**
     * @param  array<string, mixed> $data
     * @return array{title: string, body: string}
     */
    public function compose(NotificationType $type, array $data): array
    {
        $subject = $this->text($data, 'subjectTitle', 'the course');

        return match ($type) {
            NotificationType::Welcome => [
                'title' => 'Welcome to ' . $this->text($data, 'organisation', 'Central LMS'),
                'body'  => 'Your account is ready. Everything assigned to you is on your dashboard.',
            ],

            NotificationType::EnrollmentAssigned => [
                'title' => 'You have been assigned ' . $subject,
                'body'  => $this->withDueDate($data, sprintf('%s has been added to your learning.', $subject)),
            ],

            NotificationType::EnrollmentDueSoon => [
                'title' => $subject . ' is due soon',
                'body'  => $this->withDueDate($data, sprintf('You have not finished %s yet.', $subject)),
            ],

            NotificationType::EnrollmentOverdue => [
                'title' => $subject . ' is overdue',
                'body'  => $this->withDueDate($data, sprintf('%s has passed its due date.', $subject)),
            ],

            NotificationType::CourseCompleted => [
                'title' => 'You finished ' . $subject,
                'body'  => $this->withScore($data, sprintf('%s is complete.', $subject)),
            ],

            NotificationType::AssessmentGraded => [
                'title' => $this->bool($data, 'passed')
                    ? 'You passed ' . $subject
                    : $subject . ' has been marked',
                'body'  => $this->withScore($data, sprintf('Your attempt at %s has been marked.', $subject)),
            ],

            NotificationType::CertificateIssued => [
                'title' => 'Your certificate for ' . $subject . ' is ready',
                'body'  => trim(sprintf(
                    'Certificate %s has been issued. You can download or share it from your certificates.',
                    $this->text($data, 'certificateNumber', ''),
                )),
            ],

            NotificationType::CertificateRevoked => [
                'title' => 'Your certificate for ' . $subject . ' has been withdrawn',
                'body'  => $this->because($data, 'It will no longer verify.'),
            ],

            NotificationType::SessionScheduled => [
                'title' => $subject . ' has been scheduled',
                'body'  => $this->withWhen($data, sprintf('You have a place on %s.', $subject)),
            ],

            NotificationType::SessionReminder => [
                'title' => $subject . ' ' . $this->startsIn($data),
                'body'  => $this->withWhen($data, sprintf('%s is coming up.', $subject)),
            ],

            NotificationType::SessionRescheduled => [
                'title' => $subject . ' has moved',
                'body'  => $this->withWhen($data, sprintf('%s is now at a different time.', $subject)),
            ],

            NotificationType::SessionCancelled => [
                'title' => $subject . ' has been cancelled',
                'body'  => $this->because($data, 'Do not travel to it.'),
            ],

            NotificationType::Announcement => [
                'title' => $this->text($data, 'title', 'Announcement'),
                'body'  => $this->text($data, 'body', ''),
            ],
        };
    }

    /**
     * Which keys a type's wording actually reads.
     *
     * Used to check at raise time that the caller supplied what the message
     * needs, so a missing course title is a 422 at the source rather than
     * "You have been assigned the course" sitting in someone's inbox.
     *
     * @return list<string>
     */
    public function requiredData(NotificationType $type): array
    {
        return match ($type) {
            NotificationType::Welcome      => [],
            NotificationType::Announcement => ['title'],
            default                        => ['subjectTitle'],
        };
    }

    /**
     * @param  array<string, mixed> $data
     * @return list<string>         keys the wording needs and did not get
     */
    public function missingData(NotificationType $type, array $data): array
    {
        $missing = [];

        foreach ($this->requiredData($type) as $key) {
            if (trim((string) ($data[$key] ?? '')) === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function withDueDate(array $data, string $sentence): string
    {
        $due = $this->text($data, 'dueDate', '');

        return $due === '' ? $sentence : $sentence . ' It is due on ' . $due . '.';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function withScore(array $data, string $sentence): string
    {
        if (!isset($data['percentage']) || !is_numeric($data['percentage'])) {
            return $sentence;
        }

        // Trailing zeroes off a whole number: "80%" reads better than "80.0%".
        $score = rtrim(rtrim(number_format((float) $data['percentage'], 1, '.', ''), '0'), '.');

        return $sentence . ' You scored ' . $score . '%.';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function withWhen(array $data, string $sentence): string
    {
        $when = $this->text($data, 'startsAt', '');

        return $when === '' ? $sentence : $sentence . ' It starts at ' . $when . '.';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function because(array $data, string $tail): string
    {
        $reason = $this->text($data, 'reason', '');

        return $reason === '' ? $tail : 'Reason: ' . $reason . ' ' . $tail;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function startsIn(array $data): string
    {
        $minutes = isset($data['offsetMinutes']) && is_numeric($data['offsetMinutes'])
            ? (int) $data['offsetMinutes']
            : null;

        return match (true) {
            $minutes === null  => 'is coming up',
            $minutes >= 1440   => sprintf('starts in %d day%s', intdiv($minutes, 1440), $minutes >= 2880 ? 's' : ''),
            $minutes >= 60     => sprintf('starts in %d hour%s', intdiv($minutes, 60), $minutes >= 120 ? 's' : ''),
            $minutes > 0       => sprintf('starts in %d minutes', $minutes),
            default            => 'is starting now',
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function text(array $data, string $key, string $fallback): string
    {
        $value = $data[$key] ?? null;

        if (!is_scalar($value)) {
            return $fallback;
        }

        $value = trim((string) $value);

        return $value === '' ? $fallback : $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function bool(array $data, string $key): bool
    {
        return filter_var($data[$key] ?? false, FILTER_VALIDATE_BOOL);
    }
}
