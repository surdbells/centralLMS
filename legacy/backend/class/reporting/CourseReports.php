<?php

/**
 * Course & Content Reports
 * Evaluate course effectiveness, content engagement, module performance, unused content
 *
 * Supports scoping by: course, department, team, branch, learner
 */

require_once __DIR__ . '/BaseReport.php';

class CourseReports extends BaseReport
{
    /**
     * 1. Course Catalog Report
     * All courses with key metrics — the master course overview
     */
    public function courseCatalog(): array
    {
        $baseSql = "SELECT c.course_id, c.title, c.description,
            COALESCE(cat.name, 'Uncategorized') as category,
            c.difficulty_level, c.duration_hours, c.status, c.is_mandatory,
            c.created_at, c.pass_mark,
            COUNT(DISTINCT ce.enrollment_id) as total_enrolled,
            SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN ce.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
            AVG(ce.progress_percentage) as avg_progress,
            AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score ELSE NULL END) as avg_score,
            AVG(CASE WHEN ce.status = 'completed' THEN ce.time_spent_minutes ELSE NULL END) as avg_completion_time,
            (SELECT AVG(cr.rating) FROM course_ratings cr WHERE cr.course_id = c.course_id) as avg_rating,
            (SELECT COUNT(*) FROM course_ratings cr WHERE cr.course_id = c.course_id) as rating_count
            FROM courses c
            LEFT JOIN categories cat ON cat.category_id = c.category_id
            LEFT JOIN course_enrollments ce ON ce.course_id = c.course_id AND ce.tenant_id = c.tenant_id AND ce.is_active = 1
            WHERE c.tenant_id = ? AND c.is_active = 1";

        // Course status filter
        $extra = '';
        $extraTypes = '';
        $extraParams = [];
        if (!empty($this->filters['course_status'])) {
            $extra .= " AND c.status = ?";
            $extraTypes .= 's';
            $extraParams[] = $this->filters['course_status'];
        }
        if (!empty($this->filters['category_id'])) {
            $extra .= " AND c.category_id = ?";
            $extraTypes .= 'i';
            $extraParams[] = (int) $this->filters['category_id'];
        }
        if (!empty($this->filters['search'])) {
            $s = '%' . $this->filters['search'] . '%';
            $extra .= " AND (c.title LIKE ? OR c.description LIKE ?)";
            $extraTypes .= 'ss';
            $extraParams = array_merge($extraParams, [$s, $s]);
        }

        $baseSql .= $extra . " GROUP BY c.course_id";

        $allTypes = 'i' . $extraTypes;
        $allParams = [$this->tenant_id, ...$extraParams];
        $total = $this->getCount($baseSql, $allTypes, $allParams);
        $sql = $baseSql . " ORDER BY total_enrolled DESC" . $this->getLimitClause();
        $data = $this->executeQuery($sql, $allTypes, $allParams);

        foreach ($data as &$r) {
            $r['completion_rate'] = $this->safePercent((int) $r['completed'], max((int) $r['total_enrolled'], 1));
            $r['avg_progress'] = round((float) $r['avg_progress'], 1);
            $r['avg_score'] = round((float) $r['avg_score'], 1);
            $r['avg_rating'] = $r['avg_rating'] ? round((float) $r['avg_rating'], 1) : null;
            $r['avg_completion_time_formatted'] = $this->formatMinutes((int) $r['avg_completion_time']);
            $r['is_mandatory'] = (bool) $r['is_mandatory'];
        }

        return $this->paginatedResponse($data, $total);
    }

    /**
     * 2. Course Effectiveness Report
     * How well courses perform — scores, ratings, completion, time
     */
    public function courseEffectiveness(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $date = $this->buildDateRangeWhere('ce.enrolled_at');
        $m = $this->mergeWhereClauses($org, $date);

        $sql = "SELECT c.course_id, c.title, c.difficulty_level, c.pass_mark,
            COALESCE(cat.name, 'Uncategorized') as category,
            COUNT(DISTINCT ce.enrollment_id) as total_enrolled,
            SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN ce.status = 'dropped' THEN 1 ELSE 0 END) as dropped,
            AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score ELSE NULL END) as avg_score,
            AVG(CASE WHEN ce.status = 'completed' THEN ce.time_spent_minutes ELSE NULL END) as avg_time_minutes,
            AVG(CASE WHEN ce.status = 'completed' THEN DATEDIFF(ce.completed_at, ce.enrolled_at) ELSE NULL END) as avg_days_to_complete,
            (SELECT AVG(cr.rating) FROM course_ratings cr WHERE cr.course_id = c.course_id) as avg_rating,
            (SELECT COUNT(*) FROM course_ratings cr WHERE cr.course_id = c.course_id) as review_count
            FROM courses c
            LEFT JOIN categories cat ON cat.category_id = c.category_id
            LEFT JOIN course_enrollments ce ON ce.course_id = c.course_id AND ce.tenant_id = c.tenant_id AND ce.is_active = 1
            LEFT JOIN users u ON u.id = ce.user_id
            WHERE c.tenant_id = ? AND c.is_active = 1 {$m['where']}
            GROUP BY c.course_id, c.title, c.difficulty_level, c.pass_mark, cat.name
            HAVING total_enrolled > 0
            ORDER BY completed DESC";

        $rows = $this->executeQuery($sql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);
        foreach ($rows as &$r) {
            $enrolled = max((int) $r['total_enrolled'], 1);
            $r['completion_rate'] = $this->safePercent((int) $r['completed'], $enrolled);
            $r['drop_rate'] = $this->safePercent((int) $r['dropped'], $enrolled);
            $r['avg_score'] = round((float) $r['avg_score'], 1);
            $r['avg_time_formatted'] = $this->formatMinutes((int) $r['avg_time_minutes']);
            $r['avg_days_to_complete'] = round((float) $r['avg_days_to_complete'], 1);
            $r['avg_rating'] = $r['avg_rating'] ? round((float) $r['avg_rating'], 1) : null;
            // Effectiveness score (composite: completion + score + rating)
            $compScore = min($r['completion_rate'] / 100, 1) * 40;
            $scoreScore = min(($r['avg_score'] ?: 0) / 100, 1) * 30;
            $rateScore = $r['avg_rating'] ? ($r['avg_rating'] / 5) * 30 : 15;
            $r['effectiveness_score'] = round($compScore + $scoreScore + $rateScore, 1);
        }
        return $rows;
    }

    /**
     * 3. Course Usage Report
     * Enrollment, view, completion metrics per course
     */
    public function courseUsage(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $date = $this->buildDateRangeWhere('ce.enrolled_at');
        $m = $this->mergeWhereClauses($org, $date);

        $sql = "SELECT c.course_id, c.title,
            COUNT(DISTINCT ce.enrollment_id) as total_enrollments,
            COUNT(DISTINCT ce.user_id) as unique_learners,
            SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completions,
            SUM(CASE WHEN ce.status = 'dropped' THEN 1 ELSE 0 END) as drops,
            SUM(ce.time_spent_minutes) as total_time_minutes,
            MIN(ce.enrolled_at) as first_enrollment,
            MAX(ce.enrolled_at) as last_enrollment
            FROM courses c
            LEFT JOIN course_enrollments ce ON ce.course_id = c.course_id AND ce.tenant_id = c.tenant_id AND ce.is_active = 1
            LEFT JOIN users u ON u.id = ce.user_id
            WHERE c.tenant_id = ? AND c.is_active = 1 {$m['where']}
            GROUP BY c.course_id, c.title
            ORDER BY total_enrollments DESC";

        $rows = $this->executeQuery($sql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);
        foreach ($rows as &$r) {
            $enrolled = max((int) $r['total_enrollments'], 1);
            $r['completion_rate'] = $this->safePercent((int) $r['completions'], $enrolled);
            $r['drop_rate'] = $this->safePercent((int) $r['drops'], $enrolled);
            $r['total_time_formatted'] = $this->formatMinutes((int) $r['total_time_minutes']);
        }
        return $rows;
    }

    /**
     * 4. Module Performance Report
     * Performance and drop-off by course module
     */
    public function modulePerformance(): array
    {
        if (!$this->course_id) return ['error' => 'course_id required'];

        $org = $this->buildOrgScopeWhere('u');

        // Get modules with lesson completion data
        $sql = "SELECT m.module_id, m.title as module_title, m.sort_order,
            COUNT(DISTINCT lp.user_id) as learners_reached,
            SUM(CASE WHEN lp.status = 'completed' THEN 1 ELSE 0 END) as lessons_completed,
            COUNT(lp.progress_id) as total_lesson_progress,
            AVG(lp.progress_percentage) as avg_progress,
            AVG(lp.time_spent_minutes) as avg_time_minutes
            FROM course_modules m
            LEFT JOIN lessons l ON l.module_id = m.module_id
            LEFT JOIN lesson_progress lp ON lp.lesson_id = l.lesson_id AND lp.tenant_id = m.tenant_id
            LEFT JOIN users u ON u.id = lp.user_id
            WHERE m.course_id = ? AND m.tenant_id = ? {$org['where']}
            GROUP BY m.module_id, m.title, m.sort_order
            ORDER BY m.sort_order ASC";

        $allTypes = 'ii' . $org['types'];
        $allParams = [$this->course_id, $this->tenant_id, ...$org['params']];
        $data = $this->executeQuery($sql, $allTypes, $allParams);

        $maxReached = 0;
        foreach ($data as &$r) {
            $r['learners_reached'] = (int) $r['learners_reached'];
            $r['avg_progress'] = round((float) $r['avg_progress'], 1);
            $r['avg_time_formatted'] = $this->formatMinutes((int) $r['avg_time_minutes']);
            if ($r['learners_reached'] > $maxReached) $maxReached = $r['learners_reached'];
        }
        // Calculate drop-off relative to first module
        foreach ($data as &$r) {
            $r['retention_rate'] = $maxReached > 0 ? $this->safePercent($r['learners_reached'], $maxReached) : 0;
        }

        return $data;
    }

    /**
     * 5. Content Engagement Report
     * Interaction with course content (video, docs, SCORM)
     */
    public function contentEngagement(): array
    {
        if (!$this->tableExists('content_interactions')) {
            return ['info' => 'content_interactions table not available'];
        }

        $courseWhere = $this->course_id ? " AND ci.course_id = ?" : "";
        $courseTypes = $this->course_id ? "i" : "";
        $courseParams = $this->course_id ? [$this->course_id] : [];

        $org = $this->buildOrgScopeWhere('u');
        $date = $this->buildDateRangeWhere('ci.created_at');
        $m = $this->mergeWhereClauses($org, $date);

        $sql = "SELECT ci.interaction_type,
            COUNT(*) as total_interactions,
            COUNT(DISTINCT ci.user_id) as unique_users,
            AVG(ci.duration_seconds) as avg_duration_sec,
            SUM(ci.duration_seconds) as total_duration_sec
            FROM content_interactions ci
            JOIN users u ON u.id = ci.user_id
            WHERE ci.tenant_id = ? {$courseWhere} {$m['where']}
            GROUP BY ci.interaction_type
            ORDER BY total_interactions DESC";

        $allTypes = 'i' . $courseTypes . $m['types'];
        $allParams = [$this->tenant_id, ...$courseParams, ...$m['params']];
        $rows = $this->executeQuery($sql, $allTypes, $allParams);

        foreach ($rows as &$r) {
            $r['avg_duration_formatted'] = $this->formatMinutes((int) ($r['avg_duration_sec'] / 60));
            $r['total_duration_formatted'] = $this->formatMinutes((int) ($r['total_duration_sec'] / 60));
        }
        return $rows;
    }

    /**
     * 6. Course Ratings & Feedback
     * User ratings and review summaries
     */
    public function courseRatings(): array
    {
        if (!$this->tableExists('course_ratings')) {
            return ['info' => 'course_ratings table not available'];
        }

        $courseWhere = $this->course_id ? " AND cr.course_id = ?" : "";
        $courseTypes = $this->course_id ? "i" : "";
        $courseParams = $this->course_id ? [$this->course_id] : [];

        // Aggregate by course
        $sql = "SELECT c.course_id, c.title,
            COUNT(cr.rating_id) as review_count,
            AVG(cr.rating) as avg_rating,
            SUM(CASE WHEN cr.rating = 5 THEN 1 ELSE 0 END) as five_star,
            SUM(CASE WHEN cr.rating = 4 THEN 1 ELSE 0 END) as four_star,
            SUM(CASE WHEN cr.rating = 3 THEN 1 ELSE 0 END) as three_star,
            SUM(CASE WHEN cr.rating = 2 THEN 1 ELSE 0 END) as two_star,
            SUM(CASE WHEN cr.rating = 1 THEN 1 ELSE 0 END) as one_star
            FROM courses c
            JOIN course_ratings cr ON cr.course_id = c.course_id
            WHERE c.tenant_id = ? {$courseWhere}
            GROUP BY c.course_id, c.title
            ORDER BY avg_rating DESC";

        $rows = $this->executeQuery($sql, 'i' . $courseTypes, [$this->tenant_id, ...$courseParams]);
        foreach ($rows as &$r) {
            $r['avg_rating'] = round((float) $r['avg_rating'], 1);
        }

        // If specific course, also get individual reviews
        if ($this->course_id) {
            $reviewSql = "SELECT cr.rating, cr.review, cr.created_at,
                CONCAT(u.first_name, ' ', u.last_name) as reviewer_name
                FROM course_ratings cr
                JOIN users u ON u.id = cr.user_id
                WHERE cr.course_id = ? AND cr.tenant_id = ?
                ORDER BY cr.created_at DESC LIMIT 50";
            $reviews = $this->executeQuery($reviewSql, 'ii', [$this->course_id, $this->tenant_id]);
            return ['summary' => $rows[0] ?? null, 'reviews' => $reviews];
        }

        return $rows;
    }

    /**
     * 7. Unused Courses Report
     * Courses with low or no enrollment
     */
    public function unusedCourses(): array
    {
        $threshold = (int) ($this->filters['min_enrollments'] ?? 5);

        $sql = "SELECT c.course_id, c.title,
            COALESCE(cat.name, 'Uncategorized') as category,
            c.status, c.created_at,
            COUNT(ce.enrollment_id) as total_enrolled,
            MAX(ce.enrolled_at) as last_enrollment_date,
            DATEDIFF(NOW(), COALESCE(MAX(ce.enrolled_at), c.created_at)) as days_since_activity
            FROM courses c
            LEFT JOIN categories cat ON cat.category_id = c.category_id
            LEFT JOIN course_enrollments ce ON ce.course_id = c.course_id AND ce.tenant_id = c.tenant_id AND ce.is_active = 1
            WHERE c.tenant_id = ? AND c.is_active = 1
            GROUP BY c.course_id, c.title, cat.name, c.status, c.created_at
            HAVING total_enrolled < ?
            ORDER BY total_enrolled ASC, days_since_activity DESC";

        $rows = $this->executeQuery($sql, 'ii', [$this->tenant_id, $threshold]);
        foreach ($rows as &$r) {
            $r['days_since_activity'] = (int) $r['days_since_activity'];
            $r['recommendation'] = $r['total_enrolled'] == 0 ? 'archive' : ($r['days_since_activity'] > 90 ? 'review' : 'monitor');
        }
        return $rows;
    }

    /**
     * 8. Course Version History
     * Track updates to courses (uses updated_at from courses table)
     */
    public function courseVersionHistory(): array
    {
        $cid = $this->course_id;
        if (!$cid) return ['error' => 'course_id required'];

        // Course info
        $course = $this->executeOne(
            "SELECT course_id, title, description, status, created_at, updated_at, version
            FROM courses WHERE course_id = ? AND tenant_id = ?",
            'ii', [$cid, $this->tenant_id]
        );

        // Module history
        $modules = $this->executeQuery(
            "SELECT module_id, title, sort_order, created_at, updated_at
            FROM course_modules WHERE course_id = ? AND tenant_id = ? ORDER BY sort_order",
            'ii', [$cid, $this->tenant_id]
        );

        // Lesson count
        $lessons = $this->executeOne(
            "SELECT COUNT(*) as cnt FROM lessons l JOIN course_modules m ON m.module_id = l.module_id
            WHERE m.course_id = ? AND m.tenant_id = ?",
            'ii', [$cid, $this->tenant_id]
        );

        return [
            'course' => $course,
            'modules' => $modules,
            'total_lessons' => (int) ($lessons['cnt'] ?? 0),
        ];
    }

    /**
     * 9. SCORM/xAPI Activity Report
     */
    public function scormActivity(): array
    {
        if (!$this->tableExists('scorm_tracking')) {
            return ['info' => 'scorm_tracking table not available'];
        }

        $courseWhere = $this->course_id ? " AND st.course_id = ?" : "";
        $courseTypes = $this->course_id ? "i" : "";
        $courseParams = $this->course_id ? [$this->course_id] : [];

        $sql = "SELECT l.lesson_id, l.title as lesson_title,
            COUNT(DISTINCT st.user_id) as learners,
            COUNT(*) as interactions,
            MAX(st.updated_at) as last_activity
            FROM scorm_tracking st
            JOIN lessons l ON l.lesson_id = st.lesson_id
            WHERE st.tenant_id = ? {$courseWhere}
            GROUP BY l.lesson_id, l.title
            ORDER BY interactions DESC";

        return $this->executeQuery($sql, 'i' . $courseTypes, [$this->tenant_id, ...$courseParams]);
    }

    public function getExportColumns(string $report): array
    {
        return match ($report) {
            'course_catalog' => [
                ['key' => 'title', 'label' => 'Course'], ['key' => 'category', 'label' => 'Category'],
                ['key' => 'difficulty_level', 'label' => 'Level'], ['key' => 'total_enrolled', 'label' => 'Enrolled'],
                ['key' => 'completed', 'label' => 'Completed'], ['key' => 'completion_rate', 'label' => 'Completion %'],
                ['key' => 'avg_score', 'label' => 'Avg Score'], ['key' => 'avg_rating', 'label' => 'Rating'],
                ['key' => 'status', 'label' => 'Status'],
            ],
            'course_effectiveness' => [
                ['key' => 'title', 'label' => 'Course'], ['key' => 'category', 'label' => 'Category'],
                ['key' => 'total_enrolled', 'label' => 'Enrolled'], ['key' => 'completion_rate', 'label' => 'Completion %'],
                ['key' => 'drop_rate', 'label' => 'Drop %'], ['key' => 'avg_score', 'label' => 'Avg Score'],
                ['key' => 'avg_rating', 'label' => 'Rating'], ['key' => 'effectiveness_score', 'label' => 'Effectiveness'],
            ],
            default => []
        };
    }
}
