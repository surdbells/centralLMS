<?php

require_once __DIR__ . '/BaseReport.php';

/**
 * Executive & Summary Reports
 * High-level reports for executives and leadership
 */
class ExecutiveReports extends BaseReport {

    public function generate(): array {
        return $this->executiveTrainingDashboard();
    }

    /**
     * Report 65: Executive Training Dashboard
     */
    public function executiveTrainingDashboard(): array {
        // Key metrics
        $metricsQuery = "SELECT 
                           (SELECT COUNT(*) FROM users WHERE tenant_id = ? AND is_active = 1) as total_users,
                           (SELECT COUNT(*) FROM courses WHERE tenant_id = ? AND is_active = 1) as active_courses,
                           (SELECT COUNT(*) FROM course_enrollments WHERE tenant_id = ? AND is_active = 1) as total_enrollments,
                           (SELECT SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) FROM course_enrollments WHERE tenant_id = ? AND is_active = 1) as completions,
                           (SELECT SUM(time_spent_minutes) FROM course_enrollments WHERE tenant_id = ? AND is_active = 1) as total_time,
                           (SELECT AVG(final_score) FROM course_enrollments WHERE tenant_id = ? AND is_active = 1 AND status = 'completed') as avg_score";

        $stmt = $this->conn->prepare($metricsQuery);
        $stmt->bind_param("iiiiii", $this->tenant_id, $this->tenant_id, $this->tenant_id, 
                          $this->tenant_id, $this->tenant_id, $this->tenant_id);
        $stmt->execute();
        $metrics = $stmt->get_result()->fetch_assoc();

        $totalEnrollments = (int) ($metrics['total_enrollments'] ?? 0);
        $completions = (int) ($metrics['completions'] ?? 0);

        // Compliance metrics
        $complianceQuery = "SELECT 
                              COUNT(*) as total_mandatory,
                              SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                              SUM(CASE WHEN status != 'completed' AND due_date < CURDATE() THEN 1 ELSE 0 END) as overdue
                            FROM course_enrollments 
                            WHERE tenant_id = ? AND is_active = 1 AND is_mandatory = 1";
        $stmt = $this->conn->prepare($complianceQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $compliance = $stmt->get_result()->fetch_assoc();

        $totalMandatory = (int) ($compliance['total_mandatory'] ?? 0);
        $mandatoryCompleted = (int) ($compliance['completed'] ?? 0);

        // Trend data (last 6 months)
        $trendQuery = "SELECT DATE_FORMAT(enrolled_at, '%Y-%m') as month,
                         COUNT(*) as enrollments,
                         SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completions
                       FROM course_enrollments
                       WHERE tenant_id = ? AND is_active = 1
                       AND enrolled_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
                       GROUP BY DATE_FORMAT(enrolled_at, '%Y-%m')
                       ORDER BY month";
        $stmt = $this->conn->prepare($trendQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $trends = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Top performing departments
        $deptQuery = "SELECT d.department_name,
                        COUNT(ce.enrollment_id) as enrollments,
                        SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completions
                      FROM departments d
                      JOIN users u ON d.department = u.department
                      JOIN course_enrollments ce ON u.id = ce.user_id
                      WHERE d.tenant_id = ? AND ce.is_active = 1
                      GROUP BY d.department, d.department_name
                      ORDER BY completions DESC
                      LIMIT 5";
        $stmt = $this->conn->prepare($deptQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $topDepts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return [
            'report_name' => 'Executive Training Dashboard',
            'kpis' => [
                'total_users' => (int) $metrics['total_users'],
                'active_courses' => (int) $metrics['active_courses'],
                'total_enrollments' => $totalEnrollments,
                'total_completions' => $completions,
                'completion_rate' => $totalEnrollments > 0 ? round(($completions / $totalEnrollments) * 100, 1) : 0,
                'total_learning_hours' => round((int) ($metrics['total_time'] ?? 0) / 60, 1),
                'avg_score' => round((float) ($metrics['avg_score'] ?? 0), 1)
            ],
            'compliance' => [
                'total_mandatory' => $totalMandatory,
                'completed' => $mandatoryCompleted,
                'compliance_rate' => $totalMandatory > 0 ? round(($mandatoryCompleted / $totalMandatory) * 100, 1) : 100,
                'overdue' => (int) ($compliance['overdue'] ?? 0)
            ],
            'trends' => $trends,
            'top_departments' => $topDepts
        ];
    }

    /**
     * Report 66: Monthly Training Summary
     */
    public function monthlyTrainingSummary(?string $month = null): array {
        $month = $month ?? date('Y-m');
        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));

        // Enrollments and completions
        $query = "SELECT 
                    COUNT(CASE WHEN enrolled_at BETWEEN ? AND ? THEN 1 END) as new_enrollments,
                    COUNT(CASE WHEN completed_at BETWEEN ? AND ? THEN 1 END) as completions,
                    SUM(CASE WHEN completed_at BETWEEN ? AND ? THEN time_spent_minutes ELSE 0 END) as learning_minutes,
                    AVG(CASE WHEN completed_at BETWEEN ? AND ? THEN final_score END) as avg_score
                  FROM course_enrollments
                  WHERE tenant_id = ? AND is_active = 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ssssssssi", $startDate, $endDate, $startDate, $endDate, 
                          $startDate, $endDate, $startDate, $endDate, $this->tenant_id);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();

        // Active learners
        $activeQuery = "SELECT COUNT(DISTINCT user_id) as active_learners
                        FROM learning_sessions
                        WHERE tenant_id = ? AND DATE(started_at) BETWEEN ? AND ?";
        $stmt = $this->conn->prepare($activeQuery);
        $stmt->bind_param("iss", $this->tenant_id, $startDate, $endDate);
        $stmt->execute();
        $active = $stmt->get_result()->fetch_assoc();

        // Compliance for the month
        $complianceQuery = "SELECT 
                              COUNT(*) as mandatory_completed
                            FROM course_enrollments
                            WHERE tenant_id = ? AND is_active = 1 AND is_mandatory = 1
                            AND completed_at BETWEEN ? AND ?";
        $stmt = $this->conn->prepare($complianceQuery);
        $stmt->bind_param("iss", $this->tenant_id, $startDate, $endDate);
        $stmt->execute();
        $compliance = $stmt->get_result()->fetch_assoc();

        // Certificates issued
        $certQuery = "SELECT COUNT(*) as certificates_issued
                      FROM certificates WHERE tenant_id = ? AND issued_at BETWEEN ? AND ?";
        $stmt = $this->conn->prepare($certQuery);
        $stmt->bind_param("iss", $this->tenant_id, $startDate, $endDate);
        $stmt->execute();
        $certs = $stmt->get_result()->fetch_assoc();

        // Top courses
        $topCoursesQuery = "SELECT c.title, COUNT(ce.enrollment_id) as enrollments
                           FROM courses c
                           JOIN course_enrollments ce ON c.course_id = ce.course_id
                           WHERE c.tenant_id = ? AND ce.enrolled_at BETWEEN ? AND ?
                           GROUP BY c.course_id, c.title
                           ORDER BY enrollments DESC LIMIT 5";
        $stmt = $this->conn->prepare($topCoursesQuery);
        $stmt->bind_param("iss", $this->tenant_id, $startDate, $endDate);
        $stmt->execute();
        $topCourses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Previous month comparison
        $prevMonth = date('Y-m', strtotime($startDate . ' -1 month'));
        $prevStart = $prevMonth . '-01';
        $prevEnd = date('Y-m-t', strtotime($prevStart));

        $prevQuery = "SELECT COUNT(*) as completions FROM course_enrollments
                      WHERE tenant_id = ? AND completed_at BETWEEN ? AND ?";
        $stmt = $this->conn->prepare($prevQuery);
        $stmt->bind_param("iss", $this->tenant_id, $prevStart, $prevEnd);
        $stmt->execute();
        $prev = $stmt->get_result()->fetch_assoc();

        $currentCompletions = (int) ($stats['completions'] ?? 0);
        $prevCompletions = (int) ($prev['completions'] ?? 0);
        $trend = $prevCompletions > 0 ? round((($currentCompletions - $prevCompletions) / $prevCompletions) * 100, 1) : 0;

        return [
            'report_name' => 'Monthly Training Summary',
            'month' => $month,
            'month_label' => date('F Y', strtotime($startDate)),
            'summary' => [
                'new_enrollments' => (int) ($stats['new_enrollments'] ?? 0),
                'completions' => $currentCompletions,
                'completions_trend' => $trend,
                'active_learners' => (int) ($active['active_learners'] ?? 0),
                'learning_hours' => round((int) ($stats['learning_minutes'] ?? 0) / 60, 1),
                'avg_score' => round((float) ($stats['avg_score'] ?? 0), 1),
                'mandatory_completed' => (int) ($compliance['mandatory_completed'] ?? 0),
                'certificates_issued' => (int) ($certs['certificates_issued'] ?? 0)
            ],
            'top_courses' => $topCourses
        ];
    }

    /**
     * Report 67: Training ROI Report
     */
    public function trainingROIReport(): array {
        // Training investment (estimated based on time)
        $timeQuery = "SELECT 
                        SUM(time_spent_minutes) as total_minutes,
                        COUNT(DISTINCT user_id) as learners,
                        COUNT(CASE WHEN status = 'completed' THEN 1 END) as completions
                      FROM course_enrollments
                      WHERE tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($timeQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $time = $stmt->get_result()->fetch_assoc();

        $totalHours = (int) ($time['total_minutes'] ?? 0) / 60;
        $completions = (int) ($time['completions'] ?? 0);
        $learners = (int) ($time['learners'] ?? 0);

        // Estimated cost per hour (configurable)
        $hourlyRate = 50; // Could be from tenant settings
        $estimatedInvestment = $totalHours * $hourlyRate;
        $costPerCompletion = $completions > 0 ? $estimatedInvestment / $completions : 0;
        $costPerLearner = $learners > 0 ? $estimatedInvestment / $learners : 0;

        // Performance improvements (assessment score improvement)
        $improvementQuery = "SELECT 
                               AVG(first_attempt.score) as avg_first_score,
                               AVG(best_attempt.score) as avg_best_score
                             FROM (
                               SELECT user_id, assessment_id, MIN(score) as score
                               FROM assessment_attempts aa
                               JOIN assessments a ON aa.assessment_id = a.assessment_id
                               WHERE a.tenant_id = ?
                               GROUP BY user_id, assessment_id
                             ) first_attempt
                             JOIN (
                               SELECT user_id, assessment_id, MAX(score) as score
                               FROM assessment_attempts aa
                               JOIN assessments a ON aa.assessment_id = a.assessment_id
                               WHERE a.tenant_id = ?
                               GROUP BY user_id, assessment_id
                             ) best_attempt ON first_attempt.user_id = best_attempt.user_id 
                               AND first_attempt.assessment_id = best_attempt.assessment_id";
        $stmt = $this->conn->prepare($improvementQuery);
        $stmt->bind_param("ii", $this->tenant_id, $this->tenant_id);
        $stmt->execute();
        $improvement = $stmt->get_result()->fetch_assoc();

        $avgFirst = (float) ($improvement['avg_first_score'] ?? 0);
        $avgBest = (float) ($improvement['avg_best_score'] ?? 0);
        $scoreImprovement = $avgFirst > 0 ? round((($avgBest - $avgFirst) / $avgFirst) * 100, 1) : 0;

        // Compliance value
        $complianceQuery = "SELECT 
                              COUNT(*) as mandatory_completed,
                              COUNT(CASE WHEN due_date >= completed_at THEN 1 END) as on_time
                            FROM course_enrollments
                            WHERE tenant_id = ? AND is_active = 1 AND is_mandatory = 1 AND status = 'completed'";
        $stmt = $this->conn->prepare($complianceQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $compliance = $stmt->get_result()->fetch_assoc();

        return [
            'report_name' => 'Training ROI Report',
            'investment' => [
                'total_learning_hours' => round($totalHours, 1),
                'hourly_rate_used' => $hourlyRate,
                'estimated_investment' => round($estimatedInvestment, 2),
                'cost_per_completion' => round($costPerCompletion, 2),
                'cost_per_learner' => round($costPerLearner, 2)
            ],
            'outcomes' => [
                'total_completions' => $completions,
                'unique_learners' => $learners,
                'avg_first_attempt_score' => round($avgFirst, 1),
                'avg_best_score' => round($avgBest, 1),
                'score_improvement' => $scoreImprovement
            ],
            'compliance_value' => [
                'mandatory_completed' => (int) ($compliance['mandatory_completed'] ?? 0),
                'completed_on_time' => (int) ($compliance['on_time'] ?? 0),
                'on_time_rate' => (int) ($compliance['mandatory_completed'] ?? 0) > 0 ? 
                    round(((int) $compliance['on_time'] / (int) $compliance['mandatory_completed']) * 100, 1) : 0
            ]
        ];
    }

    /**
     * Report 68: Year-over-Year Comparison
     */
    public function yearOverYearComparison(): array {
        $currentYear = date('Y');
        $lastYear = $currentYear - 1;

        $query = "SELECT 
                    YEAR(enrolled_at) as year,
                    COUNT(*) as enrollments,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completions,
                    SUM(time_spent_minutes) as learning_minutes,
                    AVG(CASE WHEN status = 'completed' THEN final_score END) as avg_score,
                    COUNT(DISTINCT user_id) as unique_learners
                  FROM course_enrollments
                  WHERE tenant_id = ? AND is_active = 1
                  AND YEAR(enrolled_at) IN (?, ?)
                  GROUP BY YEAR(enrolled_at)";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iii", $this->tenant_id, $currentYear, $lastYear);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[$row['year']] = [
                'enrollments' => (int) $row['enrollments'],
                'completions' => (int) $row['completions'],
                'learning_hours' => round((int) $row['learning_minutes'] / 60, 1),
                'avg_score' => round((float) ($row['avg_score'] ?? 0), 1),
                'unique_learners' => (int) $row['unique_learners']
            ];
        }

        // Calculate changes
        $changes = [];
        if (isset($data[$currentYear]) && isset($data[$lastYear])) {
            foreach ($data[$currentYear] as $key => $value) {
                $lastValue = $data[$lastYear][$key] ?? 0;
                $changes[$key] = $lastValue > 0 ? round((($value - $lastValue) / $lastValue) * 100, 1) : 0;
            }
        }

        return [
            'report_name' => 'Year-over-Year Comparison',
            'current_year' => $currentYear,
            'last_year' => $lastYear,
            'current_year_data' => $data[$currentYear] ?? [],
            'last_year_data' => $data[$lastYear] ?? [],
            'yoy_changes' => $changes
        ];
    }

    /**
     * Report 69: Training Budget Utilization
     */
    public function trainingBudgetUtilization(): array {
        // Get budget info from settings
        $budgetQuery = "SELECT training_budget, budget_period FROM tenant_settings WHERE tenant_id = ?";
        $stmt = $this->conn->prepare($budgetQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $budgetResult = $stmt->get_result()->fetch_assoc();
        
        $totalBudget = (float) ($budgetResult['training_budget'] ?? 0);

        // Calculate estimated spend by department
        $deptQuery = "SELECT d.department_name,
                        COUNT(DISTINCT u.id) as users,
                        SUM(ce.time_spent_minutes) as training_minutes
                      FROM departments d
                      JOIN users u ON d.department = u.department
                      JOIN course_enrollments ce ON u.id = ce.user_id
                      WHERE d.tenant_id = ? AND ce.is_active = 1
                      GROUP BY d.department, d.department_name
                      ORDER BY training_minutes DESC";
        $stmt = $this->conn->prepare($deptQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $deptData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $hourlyRate = 50; // Configurable
        $totalMinutes = 0;
        $deptBreakdown = [];

        foreach ($deptData as $dept) {
            $minutes = (int) $dept['training_minutes'];
            $totalMinutes += $minutes;
            $cost = ($minutes / 60) * $hourlyRate;

            $deptBreakdown[] = [
                'department' => $dept['department_name'],
                'users' => (int) $dept['users'],
                'training_hours' => round($minutes / 60, 1),
                'estimated_cost' => round($cost, 2)
            ];
        }

        $totalSpend = ($totalMinutes / 60) * $hourlyRate;

        // Course spend
        $courseQuery = "SELECT c.title,
                          COUNT(ce.enrollment_id) as enrollments,
                          SUM(ce.time_spent_minutes) as training_minutes
                        FROM courses c
                        JOIN course_enrollments ce ON c.course_id = ce.course_id
                        WHERE c.tenant_id = ? AND ce.is_active = 1
                        GROUP BY c.course_id, c.title
                        ORDER BY training_minutes DESC
                        LIMIT 10";
        $stmt = $this->conn->prepare($courseQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $courseData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $courseBreakdown = [];
        foreach ($courseData as $course) {
            $minutes = (int) $course['training_minutes'];
            $courseBreakdown[] = [
                'course' => $course['title'],
                'enrollments' => (int) $course['enrollments'],
                'training_hours' => round($minutes / 60, 1),
                'estimated_cost' => round(($minutes / 60) * $hourlyRate, 2)
            ];
        }

        return [
            'report_name' => 'Training Budget Utilization',
            'budget' => [
                'total_budget' => $totalBudget,
                'estimated_spend' => round($totalSpend, 2),
                'remaining' => $totalBudget > 0 ? round($totalBudget - $totalSpend, 2) : 'N/A',
                'utilization_rate' => $totalBudget > 0 ? round(($totalSpend / $totalBudget) * 100, 1) : 0
            ],
            'by_department' => $deptBreakdown,
            'by_course' => $courseBreakdown,
            'note' => 'Costs estimated using hourly rate of $' . $hourlyRate
        ];
    }

    public function getSummary(): array {
        return $this->executiveTrainingDashboard()['kpis'];
    }
}
