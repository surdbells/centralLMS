<?php
require_once __DIR__ . '/BaseReport.php';

/**
 * DrilldownReportsOLD — Generic drill-down from any summary report to individual learners
 *
 * Phase 3b: Makes every count in every summary report clickable.
 * When a dashboard shows "IT: 15 overdue", the frontend calls:
 *   action=run&report=drilldown_learner_list&department_id=3&status=overdue&course_id=5
 *
 * Supports status: all, completed, in_progress, not_started, overdue, non_compliant, failed
 */
class DrilldownReportsOLD extends BaseReport
{

    public function generate(): array
    {
        return $this->drilldownLearnerList();
    }

    /**
     * Drilldown Learner List — Universal learner-level detail report
     *
     * Uses ALL org filters from BaseReport (course_id, department_id, team_id, branch_id, learner_id)
     * Plus status filter to slice by enrollment state.
     *
     * @param string $status  One of: all, completed, in_progress, not_started, overdue, non_compliant, failed
     */
    public function drilldownLearnerList(string $status = 'all'): array
    {
        $orgFilter = $this->buildOrgFilter();
        $orgJoins = $this->buildAllOrgJoins();
        $dateFilter = $this->buildDateFilter('ce.enrolled_at');
        $courseFilter = $this->course_id
            ? " AND ce.course_id = " . (int)$this->course_id
            : "";

        // Build status-specific WHERE clause
        $statusFilter = $this->buildStatusFilter($status);

        $query = "SELECT u.id as user_id,
                    CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, u.staffId, u.job_title,
                    d.department_name, t.team_name, b.branch_name,
                    CONCAT(mgr.first_name, ' ', mgr.last_name) as manager_name,
                    c.course_id, c.title as course_title,
                    ce.enrollment_id, ce.status, ce.enrolled_at, ce.started_at,
                    ce.completed_at, ce.due_date,
                    ce.progress_percentage, ce.final_score,
                    ce.time_spent_minutes, ce.is_mandatory,
                    ce.enrollment_source,
                    CASE
                        WHEN ce.status = 'completed' THEN 'completed'
                        WHEN ce.status != 'completed' AND ce.due_date IS NOT NULL AND ce.due_date < CURDATE() THEN 'overdue'
                        WHEN ce.status = 'in_progress' THEN 'in_progress'
                        WHEN ce.status = 'not_started' THEN 'not_started'
                        ELSE ce.status
                    END as effective_status,
                    CASE
                        WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN DATEDIFF(CURDATE(), ce.due_date)
                        ELSE NULL
                    END as days_overdue
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  JOIN courses c ON ce.course_id = c.course_id
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN teams t ON u.team = t.team_id
                  LEFT JOIN branches b ON u.branch = b.branch_id
                  LEFT JOIN users mgr ON u.manager_id = mgr.id
                  WHERE ce.tenant_id = ? AND ce.is_active = 1 AND u.is_active = 1
                    {$courseFilter} {$statusFilter} {$dateFilter} {$orgFilter}
                  ORDER BY
                    CASE WHEN ce.due_date IS NOT NULL AND ce.status != 'completed' AND ce.due_date < CURDATE()
                         THEN DATEDIFF(CURDATE(), ce.due_date) ELSE 0 END DESC,
                    u.last_name ASC, u.first_name ASC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $daysOverdue = $row['days_overdue'] !== null ? (int)$row['days_overdue'] : null;
            $data[] = [
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'staff_id' => $row['staffId'],
                'job_title' => $row['job_title'],
                'department_name' => $row['department_name'] ?? 'Unassigned',
                'team_name' => $row['team_name'] ?? 'Unassigned',
                'branch_name' => $row['branch_name'] ?? 'Unassigned',
                'manager_name' => $row['manager_name'] ?? null,
                'course_id' => (int)$row['course_id'],
                'course_title' => $row['course_title'],
                'enrollment_id' => (int)$row['enrollment_id'],
                'status' => $row['status'],
                'effective_status' => $row['effective_status'],
                'enrolled_at' => $row['enrolled_at'],
                'started_at' => $row['started_at'],
                'completed_at' => $row['completed_at'],
                'due_date' => $row['due_date'],
                'progress_percentage' => round((float)($row['progress_percentage'] ?? 0), 1),
                'final_score' => $row['final_score'] !== null ? round((float)$row['final_score'], 1) : null,
                'time_spent' => $this->formatDuration((int)$row['time_spent_minutes']),
                'time_spent_minutes' => (int)$row['time_spent_minutes'],
                'is_mandatory' => (bool)$row['is_mandatory'],
                'enrollment_source' => $row['enrollment_source'],
                'days_overdue' => $daysOverdue,
                'risk_level' => $daysOverdue !== null ? $this->getRiskLevel($daysOverdue) : null
            ];
        }
        $stmt->close();

        // Count query
        $countQuery = "SELECT COUNT(*) as total
                       FROM course_enrollments ce
                       JOIN users u ON ce.user_id = u.id
                       WHERE ce.tenant_id = ? AND ce.is_active = 1 AND u.is_active = 1
                         {$courseFilter} {$statusFilter} {$dateFilter} {$orgFilter}";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        // Status breakdown summary
        $summaryQuery = "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN ce.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  WHERE ce.tenant_id = ? AND ce.is_active = 1 AND u.is_active = 1
                    {$courseFilter} {$orgFilter}";
        $stmt = $this->conn->prepare($summaryQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $statusLabel = $status === 'all' ? 'All Statuses' : ucfirst(str_replace('_', ' ', $status));

        return [
            'report_name' => "Drilldown Learner List — {$statusLabel}",
            'status_filter' => $status,
            'data' => $data,
            'meta' => $this->buildMeta($total),
            'status_breakdown' => [
                'total' => (int)($summary['total'] ?? 0),
                'completed' => (int)($summary['completed'] ?? 0),
                'in_progress' => (int)($summary['in_progress'] ?? 0),
                'not_started' => (int)($summary['not_started'] ?? 0),
                'overdue' => (int)($summary['overdue'] ?? 0)
            ]
        ];
    }

    /**
     * Build WHERE clause fragment for status filter
     */
    private function buildStatusFilter(string $status): string
    {
        switch ($status) {
            case 'completed':
                return " AND ce.status = 'completed'";
            case 'in_progress':
                return " AND ce.status = 'in_progress'";
            case 'not_started':
                return " AND ce.status = 'not_started'";
            case 'overdue':
                return " AND ce.status != 'completed' AND ce.due_date IS NOT NULL AND ce.due_date < CURDATE()";
            case 'non_compliant':
                return " AND ce.is_mandatory = 1 AND ce.status != 'completed' AND ce.due_date IS NOT NULL AND ce.due_date < CURDATE()";
            case 'failed':
                return " AND ce.status = 'failed'";
            case 'all':
            default:
                return "";
        }
    }

    /**
     * Risk level from days overdue
     */
    private function getRiskLevel(int $daysOverdue): string
    {
        if ($daysOverdue > 60) return 'critical';
        if ($daysOverdue > 30) return 'high';
        if ($daysOverdue > 7) return 'medium';
        return 'low';
    }

    public function getSummary(): array
    {
        $result = $this->drilldownLearnerList();
        return $result['status_breakdown'];
    }
}
