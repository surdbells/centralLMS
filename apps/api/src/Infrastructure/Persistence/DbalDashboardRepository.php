<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Dashboard\DashboardPeriod;
use Central\Api\Domain\Dashboard\DashboardRepository;
use Central\Api\Domain\Report\ReportCriteria;
use Doctrine\DBAL\Connection;

final class DbalDashboardRepository implements DashboardRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    // --- admin ------------------------------------------------------------

    public function overviewSupplement(ReportCriteria $criteria, DashboardPeriod $period): array
    {
        $eq = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->where('e.deleted_at IS NULL');

        // One pass with filtered counts, not eight queries. The legacy ran
        // `getLastMonthEnrollments()`, `getLastMonthCompletions()`,
        // `getInProgressCount()` and `getNotStartedCount()` as four separate
        // round trips beside the four they were being compared against.
        $row = $this->connection->fetchAssociative(
            sprintf(
                "SELECT
                    COUNT(*) FILTER (WHERE e.status = 'in_progress')  AS in_progress,
                    COUNT(*) FILTER (WHERE e.status = 'not_started')  AS not_started,
                    AVG(e.final_score) FILTER (WHERE e.final_score IS NOT NULL) AS average_score,
                    COUNT(*) FILTER (WHERE e.enrolled_at >= :from AND e.enrolled_at < :to)   AS enrolled_now,
                    COUNT(*) FILTER (WHERE e.enrolled_at >= :prevFrom AND e.enrolled_at < :prevTo) AS enrolled_before,
                    COUNT(*) FILTER (WHERE e.completed_at >= :from AND e.completed_at < :to) AS completed_now,
                    COUNT(*) FILTER (WHERE e.completed_at >= :prevFrom AND e.completed_at < :prevTo) AS completed_before
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 WHERE %s",
                $eq->sql(),
            ),
            $eq->paramsWith($this->window($period)),
            $eq->types(),
        );

        $cq = ReportQuery::tenant($criteria, 'c')
            ->withScopeFilters($criteria, null, 'c')
            ->where('c.deleted_at IS NULL');

        $published = (int) $this->connection->fetchOne(
            sprintf("SELECT COUNT(*) FROM courses c WHERE %s AND c.status = 'published'", $cq->sql()),
            $cq->params(),
            $cq->types(),
        );

        $row = $row === false ? [] : $row;

        return [
            'publishedCourses'          => $published,
            'inProgress'                => (int) ($row['in_progress'] ?? 0),
            'notStarted'                => (int) ($row['not_started'] ?? 0),
            'averageScore'              => $this->round($row['average_score'] ?? null),
            'enrolmentsThisPeriod'      => (int) ($row['enrolled_now'] ?? 0),
            'enrolmentsPreviousPeriod'  => (int) ($row['enrolled_before'] ?? 0),
            'completionsThisPeriod'     => (int) ($row['completed_now'] ?? 0),
            'completionsPreviousPeriod' => (int) ($row['completed_before'] ?? 0),
        ];
    }

    public function courseMix(ReportCriteria $criteria): array
    {
        $q = ReportQuery::tenant($criteria, 'c')
            ->withScopeFilters($criteria, null, 'c')
            ->where('c.deleted_at IS NULL');

        $byCategory = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT COALESCE(cat.name, 'Uncategorised') AS label,
                        COUNT(*) AS courses,
                        COUNT(e.id) AS enrolments
                 FROM courses c
                 LEFT JOIN lookups cat ON cat.id = c.category_id
                 LEFT JOIN enrollments e ON e.course_id = c.id AND e.deleted_at IS NULL
                 WHERE %s
                 GROUP BY label
                 ORDER BY courses DESC, label",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        $byLevel = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT COALESCE(NULLIF(btrim(c.level), ''), 'Unspecified') AS label, COUNT(*) AS courses
                 FROM courses c
                 WHERE %s
                 GROUP BY label
                 ORDER BY courses DESC, label",
                $q->sql(),
            ),
            $q->params(),
            $q->types(),
        );

        return [
            'byCategory' => array_map(
                static fn (array $r): array => [
                    'label'      => (string) $r['label'],
                    'courses'    => (int) $r['courses'],
                    'enrolments' => (int) $r['enrolments'],
                ],
                $byCategory,
            ),
            'byLevel' => array_map(
                static fn (array $r): array => ['label' => (string) $r['label'], 'courses' => (int) $r['courses']],
                $byLevel,
            ),
        ];
    }

    public function learnerMix(ReportCriteria $criteria, DashboardPeriod $period): array
    {
        $q = ReportQuery::tenant($criteria, 'u', 'u')
            ->withScopeFilters($criteria, 'u')
            ->where('u.deleted_at IS NULL');

        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT u.status, COUNT(*) AS people FROM users u WHERE %s GROUP BY u.status', $q->sql()),
            $q->params(),
            $q->types(),
        );

        $joined = (int) $this->connection->fetchOne(
            sprintf(
                'SELECT COUNT(*) FROM users u
                 WHERE %s AND u.created_at >= :from AND u.created_at < :to',
                $q->sql(),
            ),
            $q->paramsWith($this->window($period)),
            $q->types(),
        );

        return [
            'byStatus' => array_map(
                static fn (array $r): array => ['status' => (string) $r['status'], 'people' => (int) $r['people']],
                $rows,
            ),
            'joinedThisPeriod' => $joined,
        ];
    }

    public function upcomingDeadlines(ReportCriteria $criteria, int $withinDays, int $limit): array
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->where('e.deleted_at IS NULL')
            ->where("e.status NOT IN ('completed', 'dropped')")
            ->where('e.due_date IS NOT NULL')
            // Due, not yet missed. The overdue report is the other side of
            // this line, and showing both in one list is how a dashboard says
            // "3 days remaining" about something a fortnight late.
            ->where('e.due_date >= CURRENT_DATE')
            ->where('e.due_date <= CURRENT_DATE + make_interval(days => :withinDays::int)');

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT e.id, e.user_id, e.course_id, e.due_date, e.progress_percentage,
                        NULLIF(btrim(COALESCE(u.first_name, '') || ' ' || COALESCE(u.last_name, '')), '') AS learner,
                        c.title AS course,
                        (e.due_date - CURRENT_DATE) AS days_remaining
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 WHERE %s
                 ORDER BY e.due_date ASC, e.id
                 LIMIT :limit",
                $q->sql(),
            ),
            $q->paramsWith(['withinDays' => $withinDays, 'limit' => $limit]),
            $q->types(),
        );

        return array_map(
            fn (array $r): array => [
                'enrollmentId' => (string) $r['id'],
                'userId'       => (string) $r['user_id'],
                'courseId'     => (string) $r['course_id'],
                'learnerName'  => $this->text($r['learner'] ?? null),
                'courseTitle'  => (string) $r['course'],
                'dueDate'      => (string) $r['due_date'],
                'daysRemaining' => (int) $r['days_remaining'],
                'progress'     => (float) $r['progress_percentage'],
                'urgency'      => $this->urgency((int) $r['days_remaining']),
            ],
            $rows,
        );
    }

    public function recentCompletions(ReportCriteria $criteria, int $limit): array
    {
        $q = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->where('e.deleted_at IS NULL')
            ->where('e.completed_at IS NOT NULL');

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT e.user_id, e.course_id, e.completed_at, e.final_score,
                        NULLIF(btrim(COALESCE(u.first_name, '') || ' ' || COALESCE(u.last_name, '')), '') AS learner,
                        u.avatar_url,
                        c.title AS course,
                        EXISTS (
                            SELECT 1 FROM certificates cert
                            WHERE cert.user_id = e.user_id
                              AND cert.course_id = e.course_id
                              AND cert.status = 'active'
                        ) AS has_certificate
                 FROM enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN courses c ON c.id = e.course_id
                 WHERE %s
                 ORDER BY e.completed_at DESC, e.id
                 LIMIT :limit",
                $q->sql(),
            ),
            $q->paramsWith(['limit' => $limit]),
            $q->types(),
        );

        return array_map(
            fn (array $r): array => [
                'userId'         => (string) $r['user_id'],
                'courseId'       => (string) $r['course_id'],
                'learnerName'    => $this->text($r['learner'] ?? null),
                'avatarUrl'      => $this->text($r['avatar_url'] ?? null),
                'courseTitle'    => (string) $r['course'],
                'completedAt'    => $this->instant($r['completed_at'] ?? null),
                'finalScore'     => $this->round($r['final_score'] ?? null),
                'hasCertificate' => (bool) $r['has_certificate'],
            ],
            $rows,
        );
    }

    public function recentActivity(ReportCriteria $criteria, int $limit): array
    {
        $eq = ReportQuery::tenant($criteria, 'e', 'u')
            ->withScopeFilters($criteria, 'u', 'c')
            ->where('e.deleted_at IS NULL');

        $cq = ReportQuery::tenant($criteria, 'cert', 'u')->withScopeFilters($criteria, 'u');

        // One query with a UNION rather than three lists merged in PHP, which
        // is what makes "the twenty most recent things" actually the twenty
        // most recent rather than the top twenty of each.
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT * FROM (
                     SELECT 'enrolled' AS kind, e.enrolled_at AS at, u.id AS user_id,
                            NULLIF(btrim(COALESCE(u.first_name, '') || ' ' || COALESCE(u.last_name, '')), '') AS person,
                            c.title AS subject
                     FROM enrollments e
                     JOIN users u ON u.id = e.user_id
                     JOIN courses c ON c.id = e.course_id
                     WHERE %s

                     UNION ALL

                     SELECT 'completed', e.completed_at, u.id,
                            NULLIF(btrim(COALESCE(u.first_name, '') || ' ' || COALESCE(u.last_name, '')), ''),
                            c.title
                     FROM enrollments e
                     JOIN users u ON u.id = e.user_id
                     JOIN courses c ON c.id = e.course_id
                     WHERE %s AND e.completed_at IS NOT NULL

                     UNION ALL

                     SELECT 'certified', cert.issued_at, u.id,
                            NULLIF(btrim(COALESCE(u.first_name, '') || ' ' || COALESCE(u.last_name, '')), ''),
                            cert.title
                     FROM certificates cert
                     JOIN users u ON u.id = cert.user_id
                     WHERE %s AND cert.status = 'active'
                 ) feed
                 WHERE at IS NOT NULL
                 ORDER BY at DESC
                 LIMIT :limit",
                $eq->sql(),
                $eq->sql(),
                $cq->sql(),
            ),
            $eq->paramsWith([...$cq->params(), 'limit' => $limit]),
            [...$eq->types(), ...$cq->types()],
        );

        return array_map(
            fn (array $r): array => [
                'kind'    => (string) $r['kind'],
                'at'      => $this->instant($r['at'] ?? null),
                'userId'  => (string) $r['user_id'],
                'person'  => $this->text($r['person'] ?? null),
                'subject' => $this->text($r['subject'] ?? null),
            ],
            $rows,
        );
    }

    // --- learner ----------------------------------------------------------

    public function learnerTotals(string $tenantId, string $userId): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT
                COUNT(*) FILTER (WHERE e.status <> 'dropped')     AS enrolled,
                COUNT(*) FILTER (WHERE e.status = 'completed')    AS completed,
                COUNT(*) FILTER (WHERE e.status = 'in_progress')  AS in_progress,
                COUNT(*) FILTER (WHERE e.status = 'not_started')  AS not_started,
                COUNT(*) FILTER (WHERE e.status NOT IN ('completed', 'dropped')
                                   AND e.due_date IS NOT NULL
                                   AND e.due_date < CURRENT_DATE) AS overdue,
                COALESCE(SUM(e.time_spent_seconds), 0)            AS seconds,
                AVG(e.final_score) FILTER (WHERE e.final_score IS NOT NULL) AS average_score
             FROM enrollments e
             WHERE e.tenant_id = :tenant AND e.user_id = :user AND e.deleted_at IS NULL",
            ['tenant' => $tenantId, 'user' => $userId],
        );

        $row = $row === false ? [] : $row;

        return [
            'enrolled'     => (int) ($row['enrolled'] ?? 0),
            'completed'    => (int) ($row['completed'] ?? 0),
            'inProgress'   => (int) ($row['in_progress'] ?? 0),
            'notStarted'   => (int) ($row['not_started'] ?? 0),
            'overdue'      => (int) ($row['overdue'] ?? 0),
            'secondsSpent' => (int) ($row['seconds'] ?? 0),
            'averageScore' => $this->round($row['average_score'] ?? null),
        ];
    }

    public function activeDays(string $tenantId, string $userId, int $days): array
    {
        // Lesson activity and assessment attempts both count. Enrolment
        // timestamps do not: being assigned a course on a Tuesday is not a
        // Tuesday spent learning, and counting it would hand somebody a streak
        // for work an administrator did.
        $rows = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT day FROM (
                 SELECT date_trunc('day', lp.last_activity_at)::date AS day
                 FROM lesson_progress lp
                 WHERE lp.tenant_id = :tenant AND lp.user_id = :user
                   AND lp.last_activity_at >= now() - make_interval(days => :days::int)

                 UNION ALL

                 SELECT date_trunc('day', a.submitted_at)::date
                 FROM attempts a
                 WHERE a.tenant_id = :tenant AND a.user_id = :user
                   AND a.submitted_at >= now() - make_interval(days => :days::int)
             ) activity
             ORDER BY day DESC",
            ['tenant' => $tenantId, 'user' => $userId, 'days' => $days],
        );

        return array_values(array_map(strval(...), $rows));
    }

    public function dailyActivity(string $tenantId, string $userId, int $days): array
    {
        // Every day in the window, including the quiet ones — a chart drawn
        // only through the days with activity has no shape, and a week with
        // two entries is not a week.
        $rows = $this->connection->fetchAllAssociative(
            "SELECT d::date AS day,
                    COALESCE(SUM(lp.time_spent_seconds), 0) AS seconds,
                    COUNT(lp.id) FILTER (WHERE lp.status = 'completed') AS lessons
             FROM generate_series(
                      CURRENT_DATE - make_interval(days => :days::int - 1),
                      CURRENT_DATE,
                      interval '1 day'
                  ) d
             LEFT JOIN lesson_progress lp
                    ON lp.tenant_id = :tenant
                   AND lp.user_id = :user
                   AND date_trunc('day', lp.last_activity_at)::date = d::date
             GROUP BY d
             ORDER BY d",
            ['tenant' => $tenantId, 'user' => $userId, 'days' => $days],
        );

        return array_map(
            static fn (array $r): array => [
                'date'    => (string) $r['day'],
                'seconds' => (int) $r['seconds'],
                'lessons' => (int) $r['lessons'],
            ],
            $rows,
        );
    }

    public function learnerDeadlines(string $tenantId, string $userId, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT e.id, e.course_id, e.due_date, e.progress_percentage,
                    c.title AS course, c.thumbnail_url,
                    (e.due_date - CURRENT_DATE) AS days_remaining
             FROM enrollments e
             JOIN courses c ON c.id = e.course_id
             WHERE e.tenant_id = :tenant AND e.user_id = :user AND e.deleted_at IS NULL
               AND e.status NOT IN ('completed', 'dropped')
               AND e.due_date IS NOT NULL
             ORDER BY e.due_date ASC, e.id
             LIMIT :limit",
            ['tenant' => $tenantId, 'user' => $userId, 'limit' => $limit],
        );

        return array_map(
            fn (array $r): array => [
                'enrollmentId'  => (string) $r['id'],
                'courseId'      => (string) $r['course_id'],
                'courseTitle'   => (string) $r['course'],
                'thumbnailUrl'  => $this->text($r['thumbnail_url'] ?? null),
                'dueDate'       => (string) $r['due_date'],
                'daysRemaining' => (int) $r['days_remaining'],
                'progress'      => (float) $r['progress_percentage'],
                // Their own overdue work is shown rather than hidden: the
                // admin list separates the two because it is a queue of work,
                // and this one is a list of what somebody still owes.
                'urgency'       => $this->urgency((int) $r['days_remaining']),
            ],
            $rows,
        );
    }

    public function recommendedCourses(string $tenantId, string $userId, int $limit): array
    {
        // Published, not already taken, most enrolled first. The legacy called
        // this "recommended"; it is popular-and-new-to-you, and saying so in
        // the field name is better than implying a model that does not exist.
        $rows = $this->connection->fetchAllAssociative(
            "SELECT c.id, c.title, c.level, c.duration_minutes, c.thumbnail_url,
                    COALESCE(cat.name, 'Uncategorised') AS category,
                    COUNT(e.id) AS enrolments
             FROM courses c
             LEFT JOIN lookups cat ON cat.id = c.category_id
             LEFT JOIN enrollments e ON e.course_id = c.id AND e.deleted_at IS NULL
             WHERE c.tenant_id = :tenant
               AND c.deleted_at IS NULL
               AND c.status = 'published'
               AND NOT EXISTS (
                   SELECT 1 FROM enrollments mine
                   WHERE mine.course_id = c.id
                     AND mine.user_id = :user
                     AND mine.deleted_at IS NULL
               )
             GROUP BY c.id, c.title, c.level, c.duration_minutes, c.thumbnail_url, cat.name
             ORDER BY enrolments DESC, c.created_at DESC, c.id
             LIMIT :limit",
            ['tenant' => $tenantId, 'user' => $userId, 'limit' => $limit],
        );

        return array_map(
            fn (array $r): array => [
                'courseId'        => (string) $r['id'],
                'title'           => (string) $r['title'],
                'category'        => (string) $r['category'],
                'level'           => $this->text($r['level'] ?? null),
                'durationMinutes' => $this->int($r['duration_minutes'] ?? null),
                'thumbnailUrl'    => $this->text($r['thumbnail_url'] ?? null),
                'enrolments'      => (int) $r['enrolments'],
            ],
            $rows,
        );
    }

    public function learnerActivity(string $tenantId, string $userId, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT * FROM (
                 SELECT 'lesson' AS kind, lp.last_activity_at AS at, l.title AS subject, NULL::text AS detail
                 FROM lesson_progress lp
                 JOIN lessons l ON l.id = lp.lesson_id
                 WHERE lp.tenant_id = :tenant AND lp.user_id = :user AND lp.status = 'completed'

                 UNION ALL

                 SELECT 'course', e.completed_at, c.title, NULL::text
                 FROM enrollments e
                 JOIN courses c ON c.id = e.course_id
                 WHERE e.tenant_id = :tenant AND e.user_id = :user
                   AND e.deleted_at IS NULL AND e.completed_at IS NOT NULL

                 UNION ALL

                 SELECT 'certificate', cert.issued_at, cert.title, cert.number
                 FROM certificates cert
                 WHERE cert.tenant_id = :tenant AND cert.user_id = :user AND cert.status = 'active'

                 UNION ALL

                 SELECT 'points', pe.earned_at, pe.reason, pe.points::text
                 FROM point_events pe
                 WHERE pe.tenant_id = :tenant AND pe.user_id = :user
             ) feed
             WHERE at IS NOT NULL
             ORDER BY at DESC
             LIMIT :limit",
            ['tenant' => $tenantId, 'user' => $userId, 'limit' => $limit],
        );

        return array_map(
            fn (array $r): array => [
                'kind'    => (string) $r['kind'],
                'at'      => $this->instant($r['at'] ?? null),
                'subject' => $this->text($r['subject'] ?? null),
                'detail'  => $this->text($r['detail'] ?? null),
            ],
            $rows,
        );
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @return array<string, string>
     */
    private function window(DashboardPeriod $period): array
    {
        return [
            'from'     => $period->from->format('Y-m-d H:i:s.uP'),
            'to'       => $period->to->format('Y-m-d H:i:s.uP'),
            'prevFrom' => $period->previousFrom->format('Y-m-d H:i:s.uP'),
            'prevTo'   => $period->previousTo->format('Y-m-d H:i:s.uP'),
        ];
    }

    private function urgency(int $daysRemaining): string
    {
        return match (true) {
            $daysRemaining < 0  => 'overdue',
            $daysRemaining <= 3 => 'critical',
            $daysRemaining <= 7 => 'warning',
            default             => 'normal',
        };
    }

    private function instant(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return null;
        }
    }

    private function round(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 1);
    }

    private function int(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
