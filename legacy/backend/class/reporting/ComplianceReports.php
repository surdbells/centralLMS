<?php

/**
 * Compliance & Mandatory Training Reports
 * Executive compliance dashboards, mandatory training status, non-compliant identification
 *
 * KEY REPORT: Executive Compliance Summary — identifies non-compliant departments, branches, teams, learners
 *
 * Supports scoping by: course, department, team, branch, learner
 */

require_once __DIR__ . '/BaseReport.php';

class ComplianceReports extends BaseReport
{
    /**
     * 1. Executive Compliance Summary
     * THE executive report: organization-wide compliance with drill-down to dept/branch/team/learner
     */
    public function executiveComplianceSummary(): array
    {
        $course = $this->buildCourseScopeWhere('ce');

        // === ORGANIZATION OVERVIEW ===
        $overviewSql = "SELECT
            COUNT(*) as total_mandatory_enrollments,
            SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN ce.status != 'completed' THEN 1 ELSE 0 END) as not_completed,
            SUM(CASE WHEN ce.status != 'completed' AND ce.due_date IS NOT NULL AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
            SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN ce.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
            COUNT(DISTINCT ce.user_id) as total_learners,
            COUNT(DISTINCT ce.course_id) as total_mandatory_courses,
            COUNT(DISTINCT CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN ce.user_id END) as non_compliant_learners,
            AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score ELSE NULL END) as avg_score
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.is_mandatory = 1 {$course['where']}";

        $overview = $this->executeOne($overviewSql, 'i' . $course['types'], [$this->tenant_id, ...$course['params']]);
        $totalMandatory = max((int) $overview['total_mandatory_enrollments'], 1);

        $orgSummary = [
            'total_mandatory_enrollments' => (int) $overview['total_mandatory_enrollments'],
            'completed' => (int) $overview['completed'],
            'not_completed' => (int) $overview['not_completed'],
            'overdue' => (int) $overview['overdue'],
            'in_progress' => (int) $overview['in_progress'],
            'not_started' => (int) $overview['not_started'],
            'compliance_rate' => $this->safePercent((int) $overview['completed'], $totalMandatory),
            'total_learners' => (int) $overview['total_learners'],
            'total_mandatory_courses' => (int) $overview['total_mandatory_courses'],
            'non_compliant_learners' => (int) $overview['non_compliant_learners'],
            'avg_score' => round((float) $overview['avg_score'], 1),
            'risk_level' => $this->getRiskLevel($this->safePercent((int) $overview['completed'], $totalMandatory))
        ];

        // === BY DEPARTMENT ===
        $deptSql = "SELECT d.department_id, COALESCE(d.department_name, 'Unassigned') as name,
            COUNT(*) as total,
            SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
            COUNT(DISTINCT ce.user_id) as learners,
            COUNT(DISTINCT CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN ce.user_id END) as non_compliant,
            AVG(ce.progress_percentage) as avg_progress
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.is_mandatory = 1 {$course['where']}
            GROUP BY d.department_id, d.department_name
            ORDER BY overdue DESC, completed ASC";

        $departments = $this->executeQuery($deptSql, 'i' . $course['types'], [$this->tenant_id, ...$course['params']]);
        foreach ($departments as &$d) {
            $d['compliance_rate'] = $this->safePercent((int) $d['completed'], max((int) $d['total'], 1));
            $d['risk_level'] = $this->getRiskLevel($d['compliance_rate']);
            $d['avg_progress'] = round((float) $d['avg_progress'], 1);
        }

        // === BY BRANCH ===
        $branchSql = "SELECT b.branch_id, COALESCE(b.branch_name, 'Unassigned') as name,
            COALESCE(b.city, '') as city,
            COUNT(*) as total,
            SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
            COUNT(DISTINCT ce.user_id) as learners,
            COUNT(DISTINCT CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN ce.user_id END) as non_compliant,
            AVG(ce.progress_percentage) as avg_progress
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            LEFT JOIN branches b ON b.branch_id = u.branch
            WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.is_mandatory = 1 {$course['where']}
            GROUP BY b.branch_id, b.branch_name, b.city
            ORDER BY overdue DESC, completed ASC";

        $branches = $this->executeQuery($branchSql, 'i' . $course['types'], [$this->tenant_id, ...$course['params']]);
        foreach ($branches as &$br) {
            $br['compliance_rate'] = $this->safePercent((int) $br['completed'], max((int) $br['total'], 1));
            $br['risk_level'] = $this->getRiskLevel($br['compliance_rate']);
            $br['avg_progress'] = round((float) $br['avg_progress'], 1);
        }

        // === BY TEAM ===
        $teamSql = "SELECT t.team_id, COALESCE(t.team_name, 'Unassigned') as name,
            COALESCE(d.department_name, '') as department_name,
            COUNT(*) as total,
            SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
            COUNT(DISTINCT ce.user_id) as learners,
            COUNT(DISTINCT CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN ce.user_id END) as non_compliant,
            AVG(ce.progress_percentage) as avg_progress
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            LEFT JOIN teams t ON t.team_id = u.team
            LEFT JOIN departments d ON d.department_id = u.department
            WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.is_mandatory = 1 {$course['where']}
            GROUP BY t.team_id, t.team_name, d.department_name
            ORDER BY overdue DESC, completed ASC";

        $teams = $this->executeQuery($teamSql, 'i' . $course['types'], [$this->tenant_id, ...$course['params']]);
        foreach ($teams as &$tm) {
            $tm['compliance_rate'] = $this->safePercent((int) $tm['completed'], max((int) $tm['total'], 1));
            $tm['risk_level'] = $this->getRiskLevel($tm['compliance_rate']);
            $tm['avg_progress'] = round((float) $tm['avg_progress'], 1);
        }

        // === NON-COMPLIANT LEARNERS (top 50) ===
        $learnerSql = "SELECT u.id as user_id,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            COALESCE(t.team_name, '') as team_name,
            COALESCE(b.branch_name, '') as branch_name,
            COUNT(*) as mandatory_total,
            SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
            GROUP_CONCAT(DISTINCT CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE()
                THEN c.title END SEPARATOR ', ') as overdue_courses,
            MAX(DATEDIFF(CURDATE(), ce.due_date)) as max_days_overdue
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN teams t ON t.team_id = u.team
            LEFT JOIN branches b ON b.branch_id = u.branch
            JOIN courses c ON c.course_id = ce.course_id AND c.tenant_id = ce.tenant_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.is_mandatory = 1
            AND ce.status != 'completed' AND ce.due_date IS NOT NULL AND ce.due_date < CURDATE()
            {$course['where']}
            GROUP BY u.id, u.first_name, u.last_name, u.email, d.department_name, t.team_name, b.branch_name
            ORDER BY overdue DESC, max_days_overdue DESC
            LIMIT 50";

        $nonCompliantLearners = $this->executeQuery($learnerSql, 'i' . $course['types'], [$this->tenant_id, ...$course['params']]);
        foreach ($nonCompliantLearners as &$l) {
            $l['compliance_rate'] = $this->safePercent((int) $l['completed'], max((int) $l['mandatory_total'], 1));
            $l['risk_level'] = $this->getRiskLevel($l['compliance_rate']);
            $l['max_days_overdue'] = (int) $l['max_days_overdue'];
        }

        // === BY MANDATORY COURSE ===
        $courseSql = "SELECT c.course_id, c.title as course_title,
            COUNT(*) as total_assigned,
            SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
            AVG(ce.progress_percentage) as avg_progress,
            AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score ELSE NULL END) as avg_score
            FROM course_enrollments ce
            JOIN courses c ON c.course_id = ce.course_id AND c.tenant_id = ce.tenant_id
            JOIN users u ON u.id = ce.user_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.is_mandatory = 1 {$course['where']}
            GROUP BY c.course_id, c.title
            ORDER BY overdue DESC";

        $mandatoryCourses = $this->executeQuery($courseSql, 'i' . $course['types'], [$this->tenant_id, ...$course['params']]);
        foreach ($mandatoryCourses as &$mc) {
            $mc['compliance_rate'] = $this->safePercent((int) $mc['completed'], max((int) $mc['total_assigned'], 1));
            $mc['risk_level'] = $this->getRiskLevel($mc['compliance_rate']);
            $mc['avg_progress'] = round((float) $mc['avg_progress'], 1);
            $mc['avg_score'] = round((float) $mc['avg_score'], 1);
        }

        return [
            'organization' => $orgSummary,
            'by_department' => $departments,
            'by_branch' => $branches,
            'by_team' => $teams,
            'non_compliant_learners' => $nonCompliantLearners,
            'by_course' => $mandatoryCourses
        ];
    }

    /**
     * 2. Mandatory Training Compliance (detailed, paginated)
     * User-level mandatory training status
     */
    public function mandatoryTrainingCompliance(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $course = $this->buildCourseScopeWhere('ce');
        $m = $this->mergeWhereClauses($org, $course);

        $baseSql = "SELECT ce.enrollment_id, ce.course_id, ce.user_id, ce.status,
            ce.progress_percentage, ce.final_score, ce.due_date,
            ce.enrolled_at, ce.started_at, ce.completed_at, ce.time_spent_minutes,
            CASE WHEN ce.status = 'completed' THEN 'compliant'
                 WHEN ce.due_date IS NOT NULL AND ce.due_date < CURDATE() THEN 'overdue'
                 WHEN ce.status = 'in_progress' THEN 'in_progress'
                 ELSE 'pending' END as compliance_status,
            CASE WHEN ce.due_date IS NOT NULL AND ce.due_date < CURDATE() AND ce.status != 'completed'
                THEN DATEDIFF(CURDATE(), ce.due_date) ELSE 0 END as days_overdue,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            COALESCE(t.team_name, '') as team_name,
            COALESCE(b.branch_name, '') as branch_name,
            c.title as course_title
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN teams t ON t.team_id = u.team
            LEFT JOIN branches b ON b.branch_id = u.branch
            JOIN courses c ON c.course_id = ce.course_id AND c.tenant_id = ce.tenant_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.is_mandatory = 1 {$m['where']}";

        // Compliance status filter
        if (!empty($this->filters['compliance_status'])) {
            $cs = $this->filters['compliance_status'];
            if ($cs === 'compliant') $baseSql .= " AND ce.status = 'completed'";
            elseif ($cs === 'overdue') $baseSql .= " AND ce.status != 'completed' AND ce.due_date < CURDATE()";
            elseif ($cs === 'in_progress') $baseSql .= " AND ce.status = 'in_progress'";
            elseif ($cs === 'pending') $baseSql .= " AND ce.status = 'not_started'";
        }

        $allTypes = 'i' . $m['types'];
        $allParams = [$this->tenant_id, ...$m['params']];
        $total = $this->getCount($baseSql, $allTypes, $allParams);
        $sql = $baseSql . " ORDER BY days_overdue DESC, ce.due_date ASC" . $this->getLimitClause();
        $data = $this->executeQuery($sql, $allTypes, $allParams);

        foreach ($data as &$row) {
            $row['progress_percentage'] = (float) $row['progress_percentage'];
            $row['final_score'] = $row['final_score'] !== null ? (float) $row['final_score'] : null;
            $row['days_overdue'] = (int) $row['days_overdue'];
            $row['time_spent_formatted'] = $this->formatMinutes((int) $row['time_spent_minutes']);
        }

        return $this->paginatedResponse($data, $total);
    }

    /**
     * 3. Certification Expiry Report
     */
    public function certificationExpiry(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $daysAhead = (int) ($this->filters['days_ahead'] ?? 90);

        $baseSql = "SELECT cert.certificate_id, cert.certificate_number,
            cert.issue_date, cert.expiry_date,
            DATEDIFF(cert.expiry_date, CURDATE()) as days_remaining,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            COALESCE(b.branch_name, '') as branch_name,
            c.title as course_title
            FROM certificates cert
            JOIN users u ON u.id = cert.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN branches b ON b.branch_id = u.branch
            JOIN courses c ON c.course_id = cert.course_id
            WHERE cert.tenant_id = ? AND cert.expiry_date IS NOT NULL
            AND cert.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
            {$org['where']}
            ORDER BY cert.expiry_date ASC";

        $allTypes = 'ii' . $org['types'];
        $allParams = [$this->tenant_id, $daysAhead, ...$org['params']];
        $data = $this->executeQuery($baseSql, $allTypes, $allParams);

        foreach ($data as &$row) {
            $row['days_remaining'] = (int) $row['days_remaining'];
            $row['urgency'] = $row['days_remaining'] <= 7 ? 'critical' : ($row['days_remaining'] <= 30 ? 'high' : 'medium');
        }

        return $data;
    }

    /**
     * 4. Non-Compliant Users Report (paginated)
     */
    public function nonCompliantUsers(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $course = $this->buildCourseScopeWhere('ce');
        $m = $this->mergeWhereClauses($org, $course);

        $baseSql = "SELECT u.id as user_id,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            COALESCE(t.team_name, '') as team_name,
            COALESCE(b.branch_name, '') as branch_name,
            COUNT(*) as overdue_count,
            GROUP_CONCAT(DISTINCT c.title ORDER BY ce.due_date ASC SEPARATOR '||') as overdue_courses,
            MIN(ce.due_date) as earliest_due_date,
            MAX(DATEDIFF(CURDATE(), ce.due_date)) as max_days_overdue,
            AVG(ce.progress_percentage) as avg_progress
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN teams t ON t.team_id = u.team
            LEFT JOIN branches b ON b.branch_id = u.branch
            JOIN courses c ON c.course_id = ce.course_id AND c.tenant_id = ce.tenant_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.is_mandatory = 1
            AND ce.status != 'completed' AND ce.due_date IS NOT NULL AND ce.due_date < CURDATE()
            {$m['where']}
            GROUP BY u.id, u.first_name, u.last_name, u.email, d.department_name, t.team_name, b.branch_name";

        $allTypes = 'i' . $m['types'];
        $allParams = [$this->tenant_id, ...$m['params']];
        $total = $this->getCount($baseSql, $allTypes, $allParams);
        $sql = $baseSql . " ORDER BY max_days_overdue DESC" . $this->getLimitClause();
        $data = $this->executeQuery($sql, $allTypes, $allParams);

        foreach ($data as &$row) {
            $row['overdue_count'] = (int) $row['overdue_count'];
            $row['max_days_overdue'] = (int) $row['max_days_overdue'];
            $row['avg_progress'] = round((float) $row['avg_progress'], 1);
            $row['overdue_courses_list'] = explode('||', $row['overdue_courses']);
            $row['risk_level'] = $row['max_days_overdue'] > 30 ? 'critical' : ($row['max_days_overdue'] > 14 ? 'high' : 'medium');
        }

        return $this->paginatedResponse($data, $total);
    }

    /**
     * 5. Compliance by Department (summary)
     */
    public function complianceByDepartment(): array
    {
        // Reuses executive summary but returns only department data
        $result = $this->executiveComplianceSummary();
        return [
            'organization' => $result['organization'],
            'departments' => $result['by_department']
        ];
    }

    /**
     * 6. Regulatory Training Audit
     * Detailed audit trail for mandatory training (legal/compliance review)
     */
    public function regulatoryTrainingAudit(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $course = $this->buildCourseScopeWhere('ce');
        $date = $this->buildDateRangeWhere('ce.enrolled_at');
        $m = $this->mergeWhereClauses($org, $course, $date);

        $baseSql = "SELECT ce.enrollment_id,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email, u.id as user_id,
            COALESCE(d.department_name, '') as department_name,
            COALESCE(b.branch_name, '') as branch_name,
            c.title as course_title, c.course_id,
            ce.status, ce.progress_percentage, ce.final_score,
            ce.enrolled_at, ce.started_at, ce.completed_at, ce.due_date,
            ce.time_spent_minutes, ce.enrollment_source,
            CASE WHEN ce.status = 'completed' THEN 'Compliant'
                 WHEN ce.due_date IS NOT NULL AND ce.due_date < CURDATE() THEN 'Non-Compliant (Overdue)'
                 ELSE 'Pending' END as audit_status,
            cert.certificate_number, cert.issue_date as cert_issue_date, cert.expiry_date as cert_expiry_date
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN branches b ON b.branch_id = u.branch
            JOIN courses c ON c.course_id = ce.course_id AND c.tenant_id = ce.tenant_id
            LEFT JOIN certificates cert ON cert.user_id = ce.user_id AND cert.course_id = ce.course_id AND cert.tenant_id = ce.tenant_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.is_mandatory = 1 {$m['where']}";

        $allTypes = 'i' . $m['types'];
        $allParams = [$this->tenant_id, ...$m['params']];
        $total = $this->getCount($baseSql, $allTypes, $allParams);
        $sql = $baseSql . " ORDER BY u.last_name ASC, c.title ASC" . $this->getLimitClause();
        $data = $this->executeQuery($sql, $allTypes, $allParams);

        foreach ($data as &$row) {
            $row['progress_percentage'] = (float) $row['progress_percentage'];
            $row['final_score'] = $row['final_score'] !== null ? (float) $row['final_score'] : null;
            $row['time_spent_formatted'] = $this->formatMinutes((int) $row['time_spent_minutes']);
        }

        return $this->paginatedResponse($data, $total);
    }

    /**
     * 7. Compliance Gap Analysis
     * Gap between required and completed training per org unit
     */
    public function complianceGapAnalysis(): array
    {
        $course = $this->buildCourseScopeWhere('ce');

        // Gap by department
        $sql = "SELECT COALESCE(d.department_name, 'Unassigned') as unit_name, 'department' as unit_type,
            COUNT(DISTINCT c.course_id) as required_courses,
            COUNT(DISTINCT CASE WHEN ce.status = 'completed' THEN c.course_id END) as completed_courses,
            COUNT(DISTINCT ce.user_id) as assigned_learners,
            COUNT(DISTINCT CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN ce.user_id END) as non_compliant_learners,
            ROUND(SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as compliance_rate
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            JOIN courses c ON c.course_id = ce.course_id AND c.tenant_id = ce.tenant_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.is_mandatory = 1 {$course['where']}
            GROUP BY d.department_id, d.department_name
            ORDER BY compliance_rate ASC";

        $gaps = $this->executeQuery($sql, 'i' . $course['types'], [$this->tenant_id, ...$course['params']]);
        foreach ($gaps as &$g) {
            $g['gap_percentage'] = round(100 - (float) $g['compliance_rate'], 1);
            $g['risk_level'] = $this->getRiskLevel((float) $g['compliance_rate']);
        }
        return $gaps;
    }

    /**
     * 8. Recertification Due Report
     */
    public function recertificationDue(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $daysAhead = (int) ($this->filters['days_ahead'] ?? 90);

        $sql = "SELECT cert.certificate_id, cert.certificate_number,
            cert.issue_date, cert.expiry_date,
            DATEDIFF(cert.expiry_date, CURDATE()) as days_remaining,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            COALESCE(t.team_name, '') as team_name,
            COALESCE(b.branch_name, '') as branch_name,
            c.title as course_title, c.course_id,
            CASE WHEN cert.expiry_date < CURDATE() THEN 'expired'
                 WHEN DATEDIFF(cert.expiry_date, CURDATE()) <= 30 THEN 'expiring_soon'
                 ELSE 'active' END as cert_status
            FROM certificates cert
            JOIN users u ON u.id = cert.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN teams t ON t.team_id = u.team
            LEFT JOIN branches b ON b.branch_id = u.branch
            JOIN courses c ON c.course_id = cert.course_id
            WHERE cert.tenant_id = ?
            AND cert.expiry_date IS NOT NULL
            AND cert.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
            {$org['where']}
            ORDER BY cert.expiry_date ASC";

        $data = $this->executeQuery($sql, 'ii' . $org['types'], [$this->tenant_id, $daysAhead, ...$org['params']]);
        foreach ($data as &$row) {
            $row['days_remaining'] = (int) $row['days_remaining'];
            $row['is_expired'] = $row['days_remaining'] < 0;
        }
        return $data;
    }

    // Helper
    private function getRiskLevel(float $complianceRate): string
    {
        if ($complianceRate >= 90) return 'low';
        if ($complianceRate >= 70) return 'medium';
        if ($complianceRate >= 50) return 'high';
        return 'critical';
    }

    public function getExportColumns(string $report): array
    {
        return match ($report) {
            'mandatory_training_compliance', 'regulatory_training_audit' => [
                ['key' => 'learner_name', 'label' => 'Learner'], ['key' => 'email', 'label' => 'Email'],
                ['key' => 'course_title', 'label' => 'Course'], ['key' => 'department_name', 'label' => 'Department'],
                ['key' => 'branch_name', 'label' => 'Branch'], ['key' => 'status', 'label' => 'Status'],
                ['key' => 'progress_percentage', 'label' => 'Progress %'], ['key' => 'final_score', 'label' => 'Score'],
                ['key' => 'due_date', 'label' => 'Due Date'], ['key' => 'completed_at', 'label' => 'Completed'],
                ['key' => 'audit_status', 'label' => 'Compliance Status'],
            ],
            'non_compliant_users' => [
                ['key' => 'learner_name', 'label' => 'Learner'], ['key' => 'email', 'label' => 'Email'],
                ['key' => 'department_name', 'label' => 'Department'], ['key' => 'team_name', 'label' => 'Team'],
                ['key' => 'branch_name', 'label' => 'Branch'], ['key' => 'overdue_count', 'label' => 'Overdue Count'],
                ['key' => 'max_days_overdue', 'label' => 'Max Days Overdue'], ['key' => 'overdue_courses', 'label' => 'Overdue Courses'],
                ['key' => 'risk_level', 'label' => 'Risk Level'],
            ],
            default => []
        };
    }
}
