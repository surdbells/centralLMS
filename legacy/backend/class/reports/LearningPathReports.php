<?php
require_once __DIR__ . '/BaseReport.php';

/**
 * Learning Path Reports
 * Reports for tracking learning path enrollments, progress, completion, drop-off, and effectiveness.
 *
 * PHASE 5 — Requires: learning_paths, learning_path_items,
 *            learning_path_enrollments, learning_path_item_progress tables
 *
 * Methods:
 *  1. learningPathEnrollment()   – Overview of enrollments across all paths
 *  2. learningPathProgress()     – Detailed per-learner progress within a path
 *  3. learningPathCompletion()   – Completion rates and timing by path
 *  4. pathDropOffAnalysis()      – Identifies where learners abandon paths
 *  5. pathEffectivenessReport()  – Measures path effectiveness via outcomes
 */
class LearningPathReports extends BaseReport
{
    public function generate(): array
    {
        return $this->learningPathEnrollment();
    }

    /* ──────────────────────────────────────────────
     * 1. Learning Path Enrollment
     * ────────────────────────────────────────────── */
    public function learningPathEnrollment(): array
    {
        $dateFilter = $this->buildDateFilter('lpe.enrolled_at');
        $orgFilter  = $this->buildOrgFilter();

        $query = "SELECT lp.path_id, lp.path_name,
                    COUNT(lpe.enrollment_id) AS total_enrolled,
                    SUM(CASE WHEN lpe.status = 'not_started'  THEN 1 ELSE 0 END) AS not_started,
                    SUM(CASE WHEN lpe.status = 'in_progress'  THEN 1 ELSE 0 END) AS in_progress,
                    SUM(CASE WHEN lpe.status = 'completed'    THEN 1 ELSE 0 END) AS completed,
                    ROUND(AVG(CASE WHEN lpe.status = 'completed'
                        THEN DATEDIFF(lpe.completed_at, lpe.enrolled_at) END), 1) AS avg_days_to_complete
                  FROM learning_paths lp
                  LEFT JOIN learning_path_enrollments lpe
                       ON lp.path_id = lpe.path_id AND lpe.tenant_id = lp.tenant_id
                  LEFT JOIN users u ON lpe.user_id = u.id
                  WHERE lp.tenant_id = ? AND lp.is_active = 1 $dateFilter
                  {$orgFilter}
                  GROUP BY lp.path_id, lp.path_name
                  ORDER BY total_enrolled DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $total = (int)$row['total_enrolled'];
            $data[] = [
                'path_id'              => (int)$row['path_id'],
                'path_name'            => $row['path_name'],
                'total_enrolled'       => $total,
                'not_started'          => (int)$row['not_started'],
                'in_progress'          => (int)$row['in_progress'],
                'completed'            => (int)$row['completed'],
                'completion_rate'      => $total > 0 ? round(($row['completed'] / $total) * 100, 1) : 0,
                'avg_days_to_complete' => (float)($row['avg_days_to_complete'] ?? 0)
            ];
        }

        $countQuery = "SELECT COUNT(DISTINCT lp.path_id) AS total
                       FROM learning_paths lp
                       WHERE lp.tenant_id = ? AND lp.is_active = 1";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Learning Path Enrollment Report',
            'data' => $data,
            'meta' => $this->buildMeta($total)
        ];
    }

    /* ──────────────────────────────────────────────
     * 2. Learning Path Progress (per learner)
     * ────────────────────────────────────────────── */
    public function learningPathProgress(): array
    {
        $dateFilter = $this->buildDateFilter('lpe.enrolled_at');
        $orgFilter  = $this->buildOrgFilter();
        $pathFilter = '';
        $types = "i";
        $params = [$this->tenant_id];

        if (!empty($this->filters['path_id'])) {
            $pathFilter = " AND lpe.path_id = ?";
            $types .= "i";
            $params[] = (int)$this->filters['path_id'];
        }

        $query = "SELECT u.id AS user_id,
                    CONCAT(u.firstname, ' ', u.lastname) AS learner_name,
                    u.email,
                    lp.path_id, lp.path_name,
                    (SELECT COUNT(*) FROM learning_path_items li
                     WHERE li.path_id = lp.path_id AND li.is_required = 1) AS items_total,
                    COUNT(CASE WHEN lpip.status = 'completed' THEN 1 END) AS items_completed,
                    lpe.status AS path_status,
                    lpe.enrolled_at,
                    lpe.completed_at
                  FROM learning_path_enrollments lpe
                  JOIN learning_paths lp ON lpe.path_id = lp.path_id
                  JOIN users u ON lpe.user_id = u.id
                  LEFT JOIN learning_path_item_progress lpip
                       ON lpe.enrollment_id = lpip.enrollment_id
                  WHERE lpe.tenant_id = ? AND lp.is_active = 1
                  $pathFilter $dateFilter
                  {$orgFilter}
                  GROUP BY lpe.enrollment_id, u.id, lp.path_id, lp.path_name,
                           lpe.status, lpe.enrolled_at, lpe.completed_at
                  ORDER BY lp.path_name, learner_name
                  LIMIT ? OFFSET ?";

        $offset = $this->getOffset();
        $types .= "ii";
        $params[] = $this->per_page;
        $params[] = $offset;

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $itemsTotal     = (int)$row['items_total'];
            $itemsCompleted = (int)$row['items_completed'];
            $data[] = [
                'user_id'             => (int)$row['user_id'],
                'learner_name'        => $row['learner_name'],
                'email'               => $row['email'],
                'path_id'             => (int)$row['path_id'],
                'path_name'           => $row['path_name'],
                'items_total'         => $itemsTotal,
                'items_completed'     => $itemsCompleted,
                'progress_percentage' => $itemsTotal > 0 ? round(($itemsCompleted / $itemsTotal) * 100, 1) : 0,
                'path_status'         => $row['path_status'],
                'enrolled_at'         => $row['enrolled_at'],
                'completed_at'        => $row['completed_at']
            ];
        }

        $countQuery = "SELECT COUNT(DISTINCT lpe.enrollment_id) AS total
                       FROM learning_path_enrollments lpe
                       JOIN learning_paths lp ON lpe.path_id = lp.path_id
                       WHERE lpe.tenant_id = ? AND lp.is_active = 1 $pathFilter";
        $countTypes  = "i";
        $countParams = [$this->tenant_id];
        if (!empty($this->filters['path_id'])) {
            $countTypes  .= "i";
            $countParams[] = (int)$this->filters['path_id'];
        }
        $total = $this->getTotalCount($countQuery, $countTypes, $countParams);

        return [
            'report_name' => 'Learning Path Progress Report',
            'data' => $data,
            'meta' => $this->buildMeta($total)
        ];
    }

    /* ──────────────────────────────────────────────
     * 3. Learning Path Completion
     * ────────────────────────────────────────────── */
    public function learningPathCompletion(): array
    {
        $dateFilter = $this->buildDateFilter('lpe.enrolled_at');
        $orgFilter  = $this->buildOrgFilter();

        $query = "SELECT lp.path_id, lp.path_name,
                    COUNT(lpe.enrollment_id) AS total_enrollments,
                    SUM(CASE WHEN lpe.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                    ROUND(AVG(CASE WHEN lpe.status = 'completed'
                        THEN DATEDIFF(lpe.completed_at, lpe.enrolled_at) END), 1) AS avg_days,
                    MIN(CASE WHEN lpe.status = 'completed'
                        THEN DATEDIFF(lpe.completed_at, lpe.enrolled_at) END) AS fastest_days,
                    MAX(CASE WHEN lpe.status = 'completed'
                        THEN DATEDIFF(lpe.completed_at, lpe.enrolled_at) END) AS slowest_days
                  FROM learning_paths lp
                  LEFT JOIN learning_path_enrollments lpe
                       ON lp.path_id = lpe.path_id AND lpe.tenant_id = lp.tenant_id
                  LEFT JOIN users u ON lpe.user_id = u.id
                  WHERE lp.tenant_id = ? AND lp.is_active = 1 $dateFilter
                  {$orgFilter}
                  GROUP BY lp.path_id, lp.path_name
                  ORDER BY completed_count DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $totalEnroll = (int)$row['total_enrollments'];
            $completed   = (int)$row['completed_count'];
            $data[] = [
                'path_id'            => (int)$row['path_id'],
                'path_name'          => $row['path_name'],
                'total_enrollments'  => $totalEnroll,
                'completed_count'    => $completed,
                'completion_rate'    => $totalEnroll > 0 ? round(($completed / $totalEnroll) * 100, 1) : 0,
                'avg_days_to_complete'     => (float)($row['avg_days'] ?? 0),
                'fastest_completion_days'  => $row['fastest_days'] !== null ? (int)$row['fastest_days'] : null,
                'slowest_completion_days'  => $row['slowest_days'] !== null ? (int)$row['slowest_days'] : null
            ];
        }

        $countQuery = "SELECT COUNT(DISTINCT lp.path_id) AS total
                       FROM learning_paths lp
                       WHERE lp.tenant_id = ? AND lp.is_active = 1";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Learning Path Completion Report',
            'data' => $data,
            'meta' => $this->buildMeta($total)
        ];
    }

    /* ──────────────────────────────────────────────
     * 4. Path Drop-Off Analysis
     * ────────────────────────────────────────────── */
    public function pathDropOffAnalysis(): array
    {
        $orgFilter  = $this->buildOrgFilter();
        $pathFilter = '';
        $types  = "i";
        $params = [$this->tenant_id];

        if (!empty($this->filters['path_id'])) {
            $pathFilter = " AND lpi.path_id = ?";
            $types .= "i";
            $params[] = (int)$this->filters['path_id'];
        }

        // For each item in a path: how many enrolled, started, completed
        $query = "SELECT lp.path_id, lp.path_name,
                    lpi.item_id, lpi.sequence_order,
                    c.title AS course_title,
                    -- total enrolled in this path
                    (SELECT COUNT(*) FROM learning_path_enrollments lpe2
                     WHERE lpe2.path_id = lp.path_id AND lpe2.tenant_id = lp.tenant_id) AS enrolled_count,
                    SUM(CASE WHEN lpip.status IN ('in_progress','completed') THEN 1 ELSE 0 END) AS started_count,
                    SUM(CASE WHEN lpip.status = 'completed' THEN 1 ELSE 0 END) AS completed_count
                  FROM learning_path_items lpi
                  JOIN learning_paths lp ON lpi.path_id = lp.path_id
                  JOIN courses c ON lpi.course_id = c.course_id
                  LEFT JOIN learning_path_item_progress lpip ON lpi.item_id = lpip.item_id
                  LEFT JOIN users u ON lpip.user_id = u.id
                  WHERE lp.tenant_id = ? AND lp.is_active = 1 AND lpi.is_required = 1
                  $pathFilter
                  {$orgFilter}
                  GROUP BY lp.path_id, lp.path_name, lpi.item_id, lpi.sequence_order, c.title
                  ORDER BY lp.path_name, lpi.sequence_order
                  LIMIT ? OFFSET ?";

        $offset = $this->getOffset();
        $types .= "ii";
        $params[] = $this->per_page;
        $params[] = $offset;

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $enrolled  = (int)$row['enrolled_count'];
            $started   = (int)$row['started_count'];
            $completed = (int)$row['completed_count'];
            $data[] = [
                'path_id'         => (int)$row['path_id'],
                'path_name'       => $row['path_name'],
                'item_id'         => (int)$row['item_id'],
                'sequence_order'  => (int)$row['sequence_order'],
                'course_title'    => $row['course_title'],
                'enrolled_count'  => $enrolled,
                'started_count'   => $started,
                'completed_count' => $completed,
                'drop_off_rate'   => $enrolled > 0 ? round((1 - ($completed / $enrolled)) * 100, 1) : 0
            ];
        }

        $countQuery = "SELECT COUNT(*) AS total
                       FROM learning_path_items lpi
                       JOIN learning_paths lp ON lpi.path_id = lp.path_id
                       WHERE lp.tenant_id = ? AND lp.is_active = 1 AND lpi.is_required = 1 $pathFilter";
        $countTypes  = "i";
        $countParams = [$this->tenant_id];
        if (!empty($this->filters['path_id'])) {
            $countTypes  .= "i";
            $countParams[] = (int)$this->filters['path_id'];
        }
        $total = $this->getTotalCount($countQuery, $countTypes, $countParams);

        return [
            'report_name' => 'Path Drop-Off Analysis',
            'data' => $data,
            'meta' => $this->buildMeta($total)
        ];
    }

    /* ──────────────────────────────────────────────
     * 5. Path Effectiveness Report
     * ────────────────────────────────────────────── */
    public function pathEffectivenessReport(): array
    {
        $dateFilter = $this->buildDateFilter('lpe.enrolled_at');
        $orgFilter  = $this->buildOrgFilter();

        $query = "SELECT lp.path_id, lp.path_name,
                    COUNT(lpe.enrollment_id) AS total_enrolled,
                    SUM(CASE WHEN lpe.status = 'completed' THEN 1 ELSE 0 END) AS completed,
                    -- avg assessment score across all items in path
                    (SELECT ROUND(AVG(lpip2.score), 1)
                     FROM learning_path_item_progress lpip2
                     JOIN learning_path_enrollments lpe2 ON lpip2.enrollment_id = lpe2.enrollment_id
                     WHERE lpe2.path_id = lp.path_id AND lpip2.score IS NOT NULL
                       AND lpip2.tenant_id = lp.tenant_id) AS avg_assessment_score,
                    -- avg days to complete
                    ROUND(AVG(CASE WHEN lpe.status = 'completed'
                        THEN DATEDIFF(lpe.completed_at, lpe.enrolled_at) END), 1) AS avg_days,
                    -- items in path
                    (SELECT COUNT(*) FROM learning_path_items li
                     WHERE li.path_id = lp.path_id AND li.is_required = 1) AS total_items,
                    -- avg items completed per learner
                    ROUND(AVG(
                        (SELECT COUNT(*) FROM learning_path_item_progress lpip3
                         WHERE lpip3.enrollment_id = lpe.enrollment_id AND lpip3.status = 'completed')
                    ), 1) AS avg_items_completed
                  FROM learning_paths lp
                  LEFT JOIN learning_path_enrollments lpe
                       ON lp.path_id = lpe.path_id AND lpe.tenant_id = lp.tenant_id
                  LEFT JOIN users u ON lpe.user_id = u.id
                  WHERE lp.tenant_id = ? AND lp.is_active = 1 $dateFilter
                  {$orgFilter}
                  GROUP BY lp.path_id, lp.path_name
                  ORDER BY completed DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $totalEnrolled = (int)$row['total_enrolled'];
            $completed     = (int)$row['completed'];
            $totalItems    = (int)$row['total_items'];
            $avgItems      = (float)($row['avg_items_completed'] ?? 0);

            // Time efficiency: lower avg_days relative to total_items = higher score
            $avgDays          = (float)($row['avg_days'] ?? 0);
            $timeEfficiency   = ($avgDays > 0 && $totalItems > 0)
                ? round(min(100, ($totalItems / $avgDays) * 25), 1)  // normalised 0-100
                : 0;

            $data[] = [
                'path_id'              => (int)$row['path_id'],
                'path_name'            => $row['path_name'],
                'total_enrolled'       => $totalEnrolled,
                'completed'            => $completed,
                'completion_rate'      => $totalEnrolled > 0 ? round(($completed / $totalEnrolled) * 100, 1) : 0,
                'avg_assessment_score' => (float)($row['avg_assessment_score'] ?? 0),
                'avg_days_to_complete' => $avgDays,
                'total_items'          => $totalItems,
                'avg_items_completed'  => $avgItems,
                'time_efficiency_score'=> $timeEfficiency
            ];
        }

        $countQuery = "SELECT COUNT(DISTINCT lp.path_id) AS total
                       FROM learning_paths lp
                       WHERE lp.tenant_id = ? AND lp.is_active = 1";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Path Effectiveness Report',
            'data' => $data,
            'meta' => $this->buildMeta($total)
        ];
    }
}
