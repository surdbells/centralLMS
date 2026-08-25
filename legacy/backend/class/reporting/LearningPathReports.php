<?php
require_once __DIR__ . '/BaseReport.php';

/**
 * Learning Path Reports
 * Track learning path enrollment, progress, completion rates, and sequence effectiveness
 */
class LearningPathReports extends BaseReport
{

    public function generate(): array
    {
        return $this->learningPathEnrollment();
    }

    /**
     * Report 1: Learning Path Enrollment
     */
    public function learningPathEnrollment(): array
    {
        $dateFilter = $this->buildDateFilter('lpe.enrolled_at');

        $query = "SELECT lp.path_id, lp.title as path_title, lp.description,
                    COUNT(lpe.enrollment_id) as total_enrolled,
                    SUM(CASE WHEN lpe.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN lpe.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN lpe.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
                    COUNT(DISTINCT lp.path_id) as total_paths,
                    ROUND(AVG(lpe.progress_percentage), 1) as avg_progress
                  FROM learning_paths lp
                  LEFT JOIN learning_path_enrollments lpe ON lp.path_id = lpe.path_id AND lpe.tenant_id = ?
                  WHERE lp.tenant_id = ? AND lp.is_active = 1 {$dateFilter}
                  GROUP BY lp.path_id, lp.title, lp.description
                  ORDER BY total_enrolled DESC";

        $data = $this->executeQuery($query, [$this->tenant_id, $this->tenant_id]);

        $totalEnrolled = array_sum(array_column($data, 'total_enrolled'));
        $totalCompleted = array_sum(array_column($data, 'completed'));

        return [
            'report_name' => 'Learning Path Enrollment',
            'data' => $this->paginate($data),
            'meta' => $this->getMeta($data),
            'summary' => [
                'total_paths' => count($data),
                'total_enrolled' => $totalEnrolled,
                'total_completed' => $totalCompleted,
                'avg_completion_rate' => $totalEnrolled > 0 ? round(($totalCompleted / $totalEnrolled) * 100, 1) : 0
            ]
        ];
    }

    /**
     * Report 2: Learning Path Progress
     */
    public function learningPathProgress(): array
    {
        $dateFilter = $this->buildDateFilter('lpe.enrolled_at');
        $pathFilter = isset($this->params['path_id']) ? "AND lpe.path_id = " . (int)$this->params['path_id'] : "";

        $query = "SELECT u.user_id, CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, d.name as department_name,
                    lp.title as path_title, lpe.status,
                    lpe.progress_percentage as progress,
                    COUNT(lpi.item_id) as total_items,
                    SUM(CASE WHEN lpi.status = 'completed' THEN 1 ELSE 0 END) as completed_items,
                    lpe.enrolled_at, lpe.completed_at,
                    DATEDIFF(COALESCE(lpe.completed_at, NOW()), lpe.enrolled_at) as days_in_path
                  FROM learning_path_enrollments lpe
                  JOIN users u ON lpe.user_id = u.user_id
                  JOIN learning_paths lp ON lpe.path_id = lp.path_id
                  LEFT JOIN departments d ON u.department_id = d.department_id
                  LEFT JOIN learning_path_items lpi ON lp.path_id = lpi.path_id
                  LEFT JOIN learning_path_item_progress lpip ON lpi.item_id = lpip.item_id AND lpip.user_id = u.user_id
                  WHERE lpe.tenant_id = ? {$dateFilter} {$pathFilter}
                  GROUP BY u.user_id, u.first_name, u.last_name, u.email, d.name, lp.title, lpe.status,
                           lpe.progress_percentage, lpe.enrolled_at, lpe.completed_at
                  ORDER BY lpe.progress_percentage DESC";

        $data = $this->executeQuery($query, [$this->tenant_id]);

        return [
            'report_name' => 'Learning Path Progress',
            'data' => $this->paginate($data),
            'meta' => $this->getMeta($data),
            'summary' => [
                'total_learners' => count($data),
                'avg_progress' => count($data) > 0 ? round(array_sum(array_column($data, 'progress')) / count($data), 1) : 0,
                'avg_days_in_path' => count($data) > 0 ? round(array_sum(array_column($data, 'days_in_path')) / count($data)) : 0
            ]
        ];
    }

    /**
     * Report 3: Learning Path Completion
     */
    public function learningPathCompletion(): array
    {
        $dateFilter = $this->buildDateFilter('lpe.completed_at');

        $query = "SELECT lp.path_id, lp.title as path_title,
                    COUNT(lpe.enrollment_id) as total_enrolled,
                    SUM(CASE WHEN lpe.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    ROUND(SUM(CASE WHEN lpe.status = 'completed' THEN 1 ELSE 0 END) / NULLIF(COUNT(lpe.enrollment_id), 0) * 100, 1) as completion_rate,
                    ROUND(AVG(CASE WHEN lpe.status = 'completed' THEN DATEDIFF(lpe.completed_at, lpe.enrolled_at) END), 1) as avg_days_to_complete,
                    MIN(CASE WHEN lpe.status = 'completed' THEN DATEDIFF(lpe.completed_at, lpe.enrolled_at) END) as min_days,
                    MAX(CASE WHEN lpe.status = 'completed' THEN DATEDIFF(lpe.completed_at, lpe.enrolled_at) END) as max_days,
                    (SELECT COUNT(*) FROM learning_path_items WHERE path_id = lp.path_id) as total_items
                  FROM learning_paths lp
                  LEFT JOIN learning_path_enrollments lpe ON lp.path_id = lpe.path_id AND lpe.tenant_id = ?
                  WHERE lp.tenant_id = ? AND lp.is_active = 1 {$dateFilter}
                  GROUP BY lp.path_id, lp.title
                  ORDER BY completion_rate DESC";

        $data = $this->executeQuery($query, [$this->tenant_id, $this->tenant_id]);

        return [
            'report_name' => 'Learning Path Completion',
            'data' => $this->paginate($data),
            'meta' => $this->getMeta($data),
            'summary' => [
                'total_paths' => count($data),
                'overall_completion_rate' => count($data) > 0 ? round(array_sum(array_column($data, 'completion_rate')) / count($data), 1) : 0,
                'avg_days_to_complete' => count($data) > 0 ? round(array_sum(array_column($data, 'avg_days_to_complete')) / max(count(array_filter(array_column($data, 'avg_days_to_complete'))), 1)) : 0
            ]
        ];
    }

    /**
     * Report 4: Path Drop-off Analysis
     */
    public function pathDropOffAnalysis(): array
    {
        $dateFilter = $this->buildDateFilter('lpe.enrolled_at');

        $query = "SELECT lpi.item_id, lpi.item_order as step_number,
                    COALESCE(c.title, lpi.title) as step_title,
                    lpi.item_type,
                    COUNT(DISTINCT lpe.user_id) as total_reached,
                    SUM(CASE WHEN lpip.status = 'completed' THEN 1 ELSE 0 END) as completed_step,
                    ROUND(SUM(CASE WHEN lpip.status = 'completed' THEN 1 ELSE 0 END) / NULLIF(COUNT(DISTINCT lpe.user_id), 0) * 100, 1) as step_completion_rate,
                    lp.title as path_title
                  FROM learning_path_items lpi
                  JOIN learning_paths lp ON lpi.path_id = lp.path_id
                  LEFT JOIN courses c ON lpi.course_id = c.course_id
                  LEFT JOIN learning_path_enrollments lpe ON lp.path_id = lpe.path_id AND lpe.tenant_id = ?
                  LEFT JOIN learning_path_item_progress lpip ON lpi.item_id = lpip.item_id AND lpip.user_id = lpe.user_id
                  WHERE lp.tenant_id = ? {$dateFilter}
                  GROUP BY lpi.item_id, lpi.item_order, step_title, lpi.item_type, lp.title
                  ORDER BY lp.path_id, lpi.item_order";

        $data = $this->executeQuery($query, [$this->tenant_id, $this->tenant_id]);

        // Calculate drop-off between steps
        $previous = null;
        foreach ($data as &$row) {
            if ($previous !== null && $previous['path_title'] === $row['path_title']) {
                $row['drop_off_rate'] = $previous['total_reached'] > 0
                    ? round((1 - $row['total_reached'] / $previous['total_reached']) * 100, 1)
                    : 0;
            } else {
                $row['drop_off_rate'] = 0;
            }
            $previous = $row;
        }

        return [
            'report_name' => 'Path Drop-off Analysis',
            'data' => $this->paginate($data),
            'meta' => $this->getMeta($data),
            'summary' => [
                'total_steps_analyzed' => count($data),
                'avg_drop_off_rate' => count($data) > 0 ? round(array_sum(array_column($data, 'drop_off_rate')) / count($data), 1) : 0,
                'highest_drop_off_step' => !empty($data) ? array_column($data, 'step_title')[array_search(max(array_column($data, 'drop_off_rate')), array_column($data, 'drop_off_rate'))] : 'N/A'
            ]
        ];
    }

    /**
     * Report 5: Path Effectiveness Report
     */
    public function pathEffectivenessReport(): array
    {
        $dateFilter = $this->buildDateFilter('lpe.enrolled_at');

        $query = "SELECT lp.path_id, lp.title as path_title,
                    COUNT(DISTINCT lpe.user_id) as total_learners,
                    ROUND(AVG(pre_scores.avg_score), 1) as avg_pre_score,
                    ROUND(AVG(post_scores.avg_score), 1) as avg_post_score,
                    ROUND(AVG(post_scores.avg_score) - AVG(pre_scores.avg_score), 1) as score_improvement,
                    ROUND(SUM(CASE WHEN lpe.status = 'completed' THEN 1 ELSE 0 END) / NULLIF(COUNT(lpe.enrollment_id), 0) * 100, 1) as completion_rate,
                    ROUND(AVG(CASE WHEN lpe.status = 'completed' THEN DATEDIFF(lpe.completed_at, lpe.enrolled_at) END), 1) as avg_days_to_complete
                  FROM learning_paths lp
                  JOIN learning_path_enrollments lpe ON lp.path_id = lpe.path_id
                  LEFT JOIN (
                      SELECT user_id, path_id, ROUND(AVG(score), 1) as avg_score
                      FROM pre_assessments WHERE tenant_id = ?
                      GROUP BY user_id, path_id
                  ) pre_scores ON lpe.user_id = pre_scores.user_id AND lpe.path_id = pre_scores.path_id
                  LEFT JOIN (
                      SELECT user_id, path_id, ROUND(AVG(score), 1) as avg_score
                      FROM post_assessments WHERE tenant_id = ?
                      GROUP BY user_id, path_id
                  ) post_scores ON lpe.user_id = post_scores.user_id AND lpe.path_id = post_scores.path_id
                  WHERE lp.tenant_id = ? {$dateFilter}
                  GROUP BY lp.path_id, lp.title
                  ORDER BY score_improvement DESC";

        $data = $this->executeQuery($query, [$this->tenant_id, $this->tenant_id, $this->tenant_id]);

        return [
            'report_name' => 'Path Effectiveness Report',
            'data' => $this->paginate($data),
            'meta' => $this->getMeta($data),
            'summary' => [
                'total_paths' => count($data),
                'avg_score_improvement' => count($data) > 0 ? round(array_sum(array_column($data, 'score_improvement')) / count($data), 1) : 0,
                'avg_completion_rate' => count($data) > 0 ? round(array_sum(array_column($data, 'completion_rate')) / count($data), 1) : 0
            ]
        ];
    }
}
