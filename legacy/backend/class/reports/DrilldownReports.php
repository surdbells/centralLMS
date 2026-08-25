<?php
/**
 * DrilldownReports — Universal learner-level drilldown from any summary report.
 * Called via: action=run&report=drilldown_learner_list&status=overdue&department_id=3
 *
 * Uses BaseReport properties & methods:
 *   $this->department_id, $this->team_id, $this->branch_id,
 *   $this->course_id, $this->learner_id  (set via setXFilter())
 *   $this->per_page, $this->getOffset()  (set via setPagination())
 *   $this->buildOrgFilter('u','ce')  — returns " AND u.department = 5" etc.
 *   $this->buildDateFilter()
 *   $this->buildMeta(), $this->formatDuration()
 *
 * Column mapping (users table):
 *   u.department → departments.department_id
 *   u.team       → teams.team_id
 *   u.branch     → branches.branch_id
 *   u.staffId    → camelCase column
 */
class DrilldownReports extends BaseReport
{
    public function generate(): array
    {
        $status = $this->filters['status'] ?? 'all';
        return $this->drilldownLearnerList($status);
    }

    /**
     * Universal drilldown: returns learner-level rows filtered by status + org dimensions.
     *
     * @param string $status  One of: completed, in_progress, not_started, overdue, enrolled,
     *                        passed, failed, non_compliant, active, inactive, all
     */
    public function drilldownLearnerList(string $status = 'all'): array
    {
        // ── Status filter on course_enrollments ──
        $statusWhere = match ($status) {
            'completed'     => "AND ce.status = 'completed'",
            'in_progress'   => "AND ce.status = 'in_progress'",
            'not_started'   => "AND ce.status = 'not_started'",
            'overdue'       => "AND ce.status != 'completed' AND ce.due_date IS NOT NULL AND ce.due_date < CURDATE()",
            'enrolled'      => "",
            'non_compliant' => "AND ce.is_mandatory = 1 AND ce.status != 'completed' AND ce.due_date IS NOT NULL AND ce.due_date < CURDATE()",
            'passed'        => "AND ce.status = 'completed'",
            'failed'        => "AND ce.status != 'completed' AND ce.due_date IS NOT NULL AND ce.due_date < CURDATE()",
            'active'        => "AND u.is_active = 1",
            'inactive'      => "AND u.is_active = 0",
            default         => ""
        };

        // buildOrgFilter() returns " AND u.department = 5 AND u.branch = 2" etc.
        // It uses $this->department_id, $this->team_id, $this->branch_id,
        // $this->learner_id, $this->course_id — all set by ReportService
        $orgFilter  = $this->buildOrgFilter('u', 'ce');
        $dateFilter = $this->buildDateFilter('ce.enrolled_at');

        $limit  = $this->per_page;
        $offset = $this->getOffset();

        // ── Main query ──
        $sql = "SELECT 
                    u.id as user_id,
                    CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email,
                    u.staffId as staff_id,
                    d.department_name,
                    t.team_name,
                    b.branch_name,
                    c.title as course_title,
                    ce.status,
                    ce.progress_percentage as progress,
                    ce.enrolled_at,
                    ce.completed_at,
                    ce.due_date,
                    ce.is_mandatory,
                    ce.time_spent_minutes,
                    CASE 
                        WHEN ce.status = 'completed' THEN 'completed'
                        WHEN ce.due_date IS NOT NULL AND ce.due_date < CURDATE() THEN 'overdue'
                        WHEN ce.status = 'in_progress' THEN 'in_progress'
                        ELSE 'not_started'
                    END as effective_status,
                    CASE 
                        WHEN ce.due_date IS NOT NULL AND ce.due_date < CURDATE() 
                        THEN DATEDIFF(CURDATE(), ce.due_date) ELSE NULL 
                    END as days_overdue
                FROM users u
                LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1
                LEFT JOIN courses c ON ce.course_id = c.course_id
                LEFT JOIN departments d ON u.department = d.department_id
                LEFT JOIN teams t ON u.team = t.team_id
                LEFT JOIN branches b ON u.branch = b.branch_id
                WHERE u.tenant_id = ?
                AND u.is_active = 1
                $statusWhere
                $orgFilter
                $dateFilter
                ORDER BY learner_name ASC
                LIMIT $limit OFFSET $offset";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // ── Count query ──
        $countSql = "SELECT COUNT(*) as total
                     FROM users u
                     LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.is_active = 1
                     LEFT JOIN courses c ON ce.course_id = c.course_id
                     LEFT JOIN departments d ON u.department = d.department_id
                     LEFT JOIN teams t ON u.team = t.team_id
                     LEFT JOIN branches b ON u.branch = b.branch_id
                     WHERE u.tenant_id = ?
                     AND u.is_active = 1
                     $statusWhere
                     $orgFilter
                     $dateFilter";

        $countStmt = $this->conn->prepare($countSql);
        $countStmt->bind_param("i", $this->tenant_id);
        $countStmt->execute();
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];

        // ── Add display fields ──
        foreach ($data as &$row) {
            $row['status_label'] = match ($row['effective_status']) {
                'completed'   => 'Completed',
                'overdue'     => 'Overdue',
                'in_progress' => 'In Progress',
                default       => 'Not Started'
            };
            $row['time_spent'] = $this->formatDuration((int)($row['time_spent_minutes'] ?? 0));
        }

        return [
            'report_name' => 'Drilldown: ' . ucfirst(str_replace('_', ' ', $status)) . ' Learners',
            'status_filter' => $status,
            'data' => $data,
            'meta' => $this->buildMeta($total),
            'summary' => [
                'total_results' => $total,
                'status' => $status
            ]
        ];
    }
}