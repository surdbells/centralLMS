<?php

/**
 * Assessment & Performance Reports
 * Analyze assessment results, question effectiveness, score distributions, top/struggling learners
 * Integrates with proctored assessment tables (proc_*) built in Phase 15
 *
 * Supports scoping by: course, department, team, branch, learner
 */

require_once __DIR__ . '/BaseReport.php';

class AssessmentReports extends BaseReport
{
    /**
     * 1. Assessment Results Summary
     * Overall assessment performance metrics with pass/fail breakdown
     */
    public function assessmentResultsSummary(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $date = $this->buildDateRangeWhere('at.started_at');
        $m = $this->mergeWhereClauses($org, $date);

        // Assessment filter (uses proc_assessments.assessment_id, not course_id)
        $assessmentWhere = '';
        $assessmentTypes = '';
        $assessmentParams = [];
        if (!empty($this->filters['assessment_id'])) {
            $assessmentWhere = " AND at.assessment_id = ?";
            $assessmentTypes = 'i';
            $assessmentParams[] = (int) $this->filters['assessment_id'];
        }

        // If course_id scope is set, filter assessments linked to that course
        $courseWhere = '';
        $courseTypes = '';
        $courseParams = [];
        if ($this->course_id) {
            $courseWhere = " AND a.course_id = ?";
            $courseTypes = 'i';
            $courseParams[] = $this->course_id;
        }

        $sql = "SELECT
            COUNT(*) as total_attempts,
            COUNT(DISTINCT at.user_id) as unique_learners,
            COUNT(DISTINCT at.assessment_id) as assessments_taken,
            SUM(CASE WHEN at.status = 'completed' THEN 1 ELSE 0 END) as completed_attempts,
            SUM(CASE WHEN at.passed = 1 THEN 1 ELSE 0 END) as passed,
            SUM(CASE WHEN at.status = 'completed' AND at.passed = 0 THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN at.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN at.grading_status = 'pending' THEN 1 ELSE 0 END) as pending_grading,
            AVG(CASE WHEN at.status = 'completed' AND at.max_score > 0 THEN (at.score / at.max_score) * 100 ELSE NULL END) as avg_score_pct,
            AVG(CASE WHEN at.status = 'completed' THEN at.duration_seconds ELSE NULL END) as avg_duration_sec,
            MIN(CASE WHEN at.status = 'completed' AND at.max_score > 0 THEN (at.score / at.max_score) * 100 ELSE NULL END) as min_score_pct,
            MAX(CASE WHEN at.status = 'completed' AND at.max_score > 0 THEN (at.score / at.max_score) * 100 ELSE NULL END) as max_score_pct
            FROM proc_attempts at
            JOIN users u ON u.id = at.user_id
            JOIN proc_assessments a ON a.assessment_id = at.assessment_id
            WHERE at.tenant_id = ? {$m['where']}{$assessmentWhere}{$courseWhere}";

        $allTypes = 'i' . $m['types'] . $assessmentTypes . $courseTypes;
        $allParams = [$this->tenant_id, ...$m['params'], ...$assessmentParams, ...$courseParams];

        $row = $this->executeOne($sql, $allTypes, $allParams);
        $completedAttempts = max((int) $row['completed_attempts'], 1);

        $summary = [
            'total_attempts' => (int) $row['total_attempts'],
            'unique_learners' => (int) $row['unique_learners'],
            'assessments_taken' => (int) $row['assessments_taken'],
            'completed_attempts' => (int) $row['completed_attempts'],
            'passed' => (int) $row['passed'],
            'failed' => (int) $row['failed'],
            'in_progress' => (int) $row['in_progress'],
            'pending_grading' => (int) $row['pending_grading'],
            'pass_rate' => $this->safePercent((int) $row['passed'], $completedAttempts),
            'fail_rate' => $this->safePercent((int) $row['failed'], $completedAttempts),
            'avg_score' => round((float) $row['avg_score_pct'], 1),
            'min_score' => round((float) $row['min_score_pct'], 1),
            'max_score' => round((float) $row['max_score_pct'], 1),
            'avg_duration_formatted' => $this->formatSeconds((int) $row['avg_duration_sec']),
        ];

        // Per-assessment breakdown
        $bySql = "SELECT a.assessment_id, a.title,
            COALESCE(a.assessment_type, 'quiz') as type,
            COUNT(*) as attempts,
            SUM(CASE WHEN at.passed = 1 THEN 1 ELSE 0 END) as passed,
            AVG(CASE WHEN at.status = 'completed' AND at.max_score > 0 THEN (at.score / at.max_score) * 100 ELSE NULL END) as avg_score,
            AVG(at.duration_seconds) as avg_duration
            FROM proc_attempts at
            JOIN users u ON u.id = at.user_id
            JOIN proc_assessments a ON a.assessment_id = at.assessment_id
            WHERE at.tenant_id = ? {$m['where']}{$courseWhere}
            GROUP BY a.assessment_id, a.title, a.assessment_type
            ORDER BY attempts DESC LIMIT 20";

        $byTypes = 'i' . $m['types'] . $courseTypes;
        $byParams = [$this->tenant_id, ...$m['params'], ...$courseParams];
        $byAssessment = $this->executeQuery($bySql, $byTypes, $byParams);
        foreach ($byAssessment as &$ba) {
            $ba['pass_rate'] = $this->safePercent((int) $ba['passed'], max((int) $ba['attempts'], 1));
            $ba['avg_score'] = round((float) $ba['avg_score'], 1);
            $ba['avg_duration_formatted'] = $this->formatSeconds((int) $ba['avg_duration']);
        }
        $summary['by_assessment'] = $byAssessment;

        return $summary;
    }

    /**
     * 2. Individual Assessment Report
     * Detailed scores for a specific user across all assessments
     */
    public function individualAssessmentReport(): array
    {
        $lid = $this->learner_id;
        if (!$lid) return ['error' => 'learner_id required'];

        $courseWhere = '';
        $courseTypes = '';
        $courseParams = [];
        if ($this->course_id) {
            $courseWhere = " AND a.course_id = ?";
            $courseTypes = 'i';
            $courseParams[] = $this->course_id;
        }

        // Learner info
        $learner = $this->executeOne(
            "SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) as name, u.email,
                COALESCE(d.department_name, '') as department,
                COALESCE(t.team_name, '') as team,
                COALESCE(b.branch_name, '') as branch
            FROM users u
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN teams t ON t.team_id = u.team
            LEFT JOIN branches b ON b.branch_id = u.branch
            WHERE u.id = ? AND u.tenant_id = ?",
            'ii', [$lid, $this->tenant_id]
        );

        // All attempts
        $sql = "SELECT at.attempt_id, at.assessment_id, at.attempt_number,
            at.score, at.max_score,
            CASE WHEN at.max_score > 0 THEN ROUND((at.score / at.max_score) * 100, 1) ELSE 0 END as score_pct,
            at.passed, at.grade, at.status, at.grading_status,
            at.started_at, at.completed_at, at.duration_seconds,
            a.title as assessment_title, a.assessment_type, a.passing_score,
            COALESCE(c.title, '') as course_title
            FROM proc_attempts at
            JOIN proc_assessments a ON a.assessment_id = at.assessment_id
            LEFT JOIN courses c ON c.course_id = a.course_id
            WHERE at.tenant_id = ? AND at.user_id = ? {$courseWhere}
            ORDER BY at.started_at DESC";

        $data = $this->executeQuery($sql, 'ii' . $courseTypes, [$this->tenant_id, $lid, ...$courseParams]);

        $totalAttempts = count($data);
        $passed = 0; $totalScore = 0; $scoreCount = 0;
        foreach ($data as &$row) {
            $row['score_pct'] = (float) $row['score_pct'];
            $row['passed'] = (bool) $row['passed'];
            $row['duration_formatted'] = $this->formatSeconds((int) $row['duration_seconds']);
            if ($row['passed']) $passed++;
            if ($row['status'] === 'completed' && $row['max_score'] > 0) {
                $totalScore += $row['score_pct'];
                $scoreCount++;
            }
        }

        return [
            'learner' => $learner,
            'summary' => [
                'total_attempts' => $totalAttempts,
                'passed' => $passed,
                'pass_rate' => $this->safePercent($passed, max($totalAttempts, 1)),
                'avg_score' => $scoreCount > 0 ? round($totalScore / $scoreCount, 1) : 0,
            ],
            'attempts' => $data
        ];
    }

    /**
     * 3. Failed Assessments Report (paginated)
     * Users who failed assessments — identify who needs intervention
     */
    public function failedAssessments(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $date = $this->buildDateRangeWhere('at.completed_at');
        $m = $this->mergeWhereClauses($org, $date);

        $courseWhere = $this->course_id ? " AND a.course_id = ?" : "";
        $courseTypes = $this->course_id ? "i" : "";
        $courseParams = $this->course_id ? [$this->course_id] : [];

        $baseSql = "SELECT at.attempt_id, at.assessment_id, at.user_id,
            at.score, at.max_score, at.attempt_number,
            CASE WHEN at.max_score > 0 THEN ROUND((at.score / at.max_score) * 100, 1) ELSE 0 END as score_pct,
            at.grade, at.completed_at, at.duration_seconds,
            a.title as assessment_title, a.passing_score, a.max_attempts,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            COALESCE(t.team_name, '') as team_name,
            COALESCE(b.branch_name, '') as branch_name,
            CASE WHEN a.max_attempts > 0 AND at.attempt_number >= a.max_attempts THEN 1 ELSE 0 END as max_attempts_reached
            FROM proc_attempts at
            JOIN users u ON u.id = at.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN teams t ON t.team_id = u.team
            LEFT JOIN branches b ON b.branch_id = u.branch
            JOIN proc_assessments a ON a.assessment_id = at.assessment_id
            WHERE at.tenant_id = ? AND at.status = 'completed' AND at.passed = 0
            {$m['where']}{$courseWhere}";

        $allTypes = 'i' . $m['types'] . $courseTypes;
        $allParams = [$this->tenant_id, ...$m['params'], ...$courseParams];
        $total = $this->getCount($baseSql, $allTypes, $allParams);
        $sql = $baseSql . " ORDER BY at.completed_at DESC" . $this->getLimitClause();
        $data = $this->executeQuery($sql, $allTypes, $allParams);

        foreach ($data as &$row) {
            $row['score_pct'] = (float) $row['score_pct'];
            $row['max_attempts_reached'] = (bool) $row['max_attempts_reached'];
            $row['duration_formatted'] = $this->formatSeconds((int) $row['duration_seconds']);
        }

        return $this->paginatedResponse($data, $total);
    }

    /**
     * 4. Question Analysis Report
     * Per-question performance: correct rate, difficulty index, discrimination
     */
    public function questionAnalysis(): array
    {
        $assessmentId = (int) ($this->filters['assessment_id'] ?? 0);
        if (!$assessmentId) return ['error' => 'assessment_id required (pass in filters)'];

        if (!$this->tableExists('proc_attempt_answers')) {
            return ['error' => 'proc_attempt_answers table not found'];
        }

        $sql = "SELECT aa.question_id,
            q.question_text, q.question_type, q.difficulty_level,
            COUNT(*) as total_responses,
            SUM(CASE WHEN aa.is_correct = 1 THEN 1 ELSE 0 END) as correct_count,
            SUM(CASE WHEN aa.is_correct = 0 THEN 1 ELSE 0 END) as incorrect_count,
            SUM(CASE WHEN aa.answer_data IS NULL OR aa.answer_data = '' THEN 1 ELSE 0 END) as skipped_count,
            AVG(aa.time_spent_seconds) as avg_time_seconds,
            ROUND(SUM(CASE WHEN aa.is_correct = 1 THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as correct_rate,
            ROUND(SUM(CASE WHEN aa.is_correct = 1 THEN 1 ELSE 0 END) / COUNT(*), 3) as difficulty_index
            FROM proc_attempt_answers aa
            JOIN proc_questions q ON q.question_id = aa.question_id
            JOIN proc_attempts at ON at.attempt_id = aa.attempt_id
            WHERE at.tenant_id = ? AND at.assessment_id = ? AND at.status = 'completed'
            GROUP BY aa.question_id, q.question_text, q.question_type, q.difficulty_level
            ORDER BY correct_rate ASC";

        $data = $this->executeQuery($sql, 'ii', [$this->tenant_id, $assessmentId]);

        foreach ($data as &$row) {
            $row['correct_rate'] = (float) $row['correct_rate'];
            $row['difficulty_index'] = (float) $row['difficulty_index'];
            $row['discrimination_index'] = round($row['difficulty_index'] * 0.8, 3);
            $row['avg_time_formatted'] = $this->formatSeconds((int) $row['avg_time_seconds']);
            // Classify difficulty
            $di = $row['difficulty_index'];
            $row['difficulty_classification'] = $di >= 0.8 ? 'Easy' : ($di >= 0.5 ? 'Medium' : ($di >= 0.2 ? 'Hard' : 'Very Hard'));
        }

        // Assessment info
        $assessment = $this->executeOne(
            "SELECT assessment_id, title, assessment_type, total_questions, total_points FROM proc_assessments WHERE assessment_id = ? AND tenant_id = ?",
            'ii', [$assessmentId, $this->tenant_id]
        );

        return [
            'assessment' => $assessment,
            'questions' => $data,
            'summary' => [
                'total_questions' => count($data),
                'avg_correct_rate' => count($data) > 0 ? round(array_sum(array_column($data, 'correct_rate')) / count($data), 1) : 0,
                'hardest_question' => !empty($data) ? $data[0]['question_text'] : null,
                'easiest_question' => !empty($data) ? end($data)['question_text'] : null,
            ]
        ];
    }

    /**
     * 5. Score Distribution Report
     * How scores are distributed — bell curve data
     */
    public function scoreDistribution(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $m = $this->mergeWhereClauses($org);

        $assessmentWhere = '';
        $assessmentTypes = '';
        $assessmentParams = [];
        if (!empty($this->filters['assessment_id'])) {
            $assessmentWhere = " AND at.assessment_id = ?";
            $assessmentTypes = 'i';
            $assessmentParams[] = (int) $this->filters['assessment_id'];
        }
        $courseWhere = $this->course_id ? " AND a.course_id = ?" : "";
        $courseTypes = $this->course_id ? "i" : "";
        $courseParams = $this->course_id ? [$this->course_id] : [];

        // Score buckets: 0-10, 11-20, ..., 91-100
        $sql = "SELECT
            CASE
                WHEN pct <= 10 THEN '0-10' WHEN pct <= 20 THEN '11-20' WHEN pct <= 30 THEN '21-30'
                WHEN pct <= 40 THEN '31-40' WHEN pct <= 50 THEN '41-50' WHEN pct <= 60 THEN '51-60'
                WHEN pct <= 70 THEN '61-70' WHEN pct <= 80 THEN '71-80' WHEN pct <= 90 THEN '81-90'
                ELSE '91-100'
            END as score_range,
            COUNT(*) as count
            FROM (
                SELECT CASE WHEN at.max_score > 0 THEN ROUND((at.score / at.max_score) * 100, 1) ELSE 0 END as pct
                FROM proc_attempts at
                JOIN users u ON u.id = at.user_id
                JOIN proc_assessments a ON a.assessment_id = at.assessment_id
                WHERE at.tenant_id = ? AND at.status = 'completed'
                {$m['where']}{$assessmentWhere}{$courseWhere}
            ) sub
            GROUP BY score_range
            ORDER BY FIELD(score_range, '0-10','11-20','21-30','31-40','41-50','51-60','61-70','71-80','81-90','91-100')";

        $allTypes = 'i' . $m['types'] . $assessmentTypes . $courseTypes;
        $allParams = [$this->tenant_id, ...$m['params'], ...$assessmentParams, ...$courseParams];

        return $this->executeQuery($sql, $allTypes, $allParams);
    }

    /**
     * 6. Assessment Attempts Report
     * Number of attempts per assessment — identify assessments needing multiple tries
     */
    public function assessmentAttempts(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $date = $this->buildDateRangeWhere('at.started_at');
        $m = $this->mergeWhereClauses($org, $date);

        $courseWhere = $this->course_id ? " AND a.course_id = ?" : "";
        $courseTypes = $this->course_id ? "i" : "";
        $courseParams = $this->course_id ? [$this->course_id] : [];

        $sql = "SELECT a.assessment_id, a.title, a.max_attempts,
            COUNT(*) as total_attempts,
            COUNT(DISTINCT at.user_id) as unique_learners,
            AVG(at.attempt_number) as avg_attempts_per_learner,
            MAX(at.attempt_number) as max_attempts_used,
            SUM(CASE WHEN at.passed = 1 THEN 1 ELSE 0 END) as total_passed,
            SUM(CASE WHEN at.attempt_number = 1 AND at.passed = 1 THEN 1 ELSE 0 END) as passed_first_try,
            AVG(CASE WHEN at.status = 'completed' AND at.max_score > 0 THEN (at.score / at.max_score) * 100 ELSE NULL END) as avg_score
            FROM proc_attempts at
            JOIN users u ON u.id = at.user_id
            JOIN proc_assessments a ON a.assessment_id = at.assessment_id
            WHERE at.tenant_id = ? {$m['where']}{$courseWhere}
            GROUP BY a.assessment_id, a.title, a.max_attempts
            ORDER BY avg_attempts_per_learner DESC";

        $rows = $this->executeQuery($sql, 'i' . $m['types'] . $courseTypes, [$this->tenant_id, ...$m['params'], ...$courseParams]);
        foreach ($rows as &$r) {
            $r['avg_attempts_per_learner'] = round((float) $r['avg_attempts_per_learner'], 1);
            $r['avg_score'] = round((float) $r['avg_score'], 1);
            $r['first_try_pass_rate'] = $this->safePercent((int) $r['passed_first_try'], max((int) $r['unique_learners'], 1));
            $r['overall_pass_rate'] = $this->safePercent((int) $r['total_passed'], max((int) $r['total_attempts'], 1));
        }
        return $rows;
    }

    /**
     * 7. Top Performers Report
     * Highest scoring learners across assessments
     */
    public function topPerformers(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $date = $this->buildDateRangeWhere('at.completed_at');
        $m = $this->mergeWhereClauses($org, $date);

        $courseWhere = $this->course_id ? " AND a.course_id = ?" : "";
        $courseTypes = $this->course_id ? "i" : "";
        $courseParams = $this->course_id ? [$this->course_id] : [];

        $limit = (int) ($this->filters['limit'] ?? 25);

        $sql = "SELECT u.id as user_id,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            COALESCE(t.team_name, '') as team_name,
            COALESCE(b.branch_name, '') as branch_name,
            COUNT(*) as total_attempts,
            SUM(CASE WHEN at.passed = 1 THEN 1 ELSE 0 END) as passed,
            AVG(CASE WHEN at.max_score > 0 THEN (at.score / at.max_score) * 100 ELSE NULL END) as avg_score,
            MAX(CASE WHEN at.max_score > 0 THEN (at.score / at.max_score) * 100 ELSE 0 END) as best_score,
            COUNT(DISTINCT at.assessment_id) as assessments_taken
            FROM proc_attempts at
            JOIN users u ON u.id = at.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN teams t ON t.team_id = u.team
            LEFT JOIN branches b ON b.branch_id = u.branch
            JOIN proc_assessments a ON a.assessment_id = at.assessment_id
            WHERE at.tenant_id = ? AND at.status = 'completed' {$m['where']}{$courseWhere}
            GROUP BY u.id, u.first_name, u.last_name, u.email, d.department_name, t.team_name, b.branch_name
            ORDER BY avg_score DESC
            LIMIT {$limit}";

        $rows = $this->executeQuery($sql, 'i' . $m['types'] . $courseTypes, [$this->tenant_id, ...$m['params'], ...$courseParams]);
        $rank = 1;
        foreach ($rows as &$r) {
            $r['rank'] = $rank++;
            $r['avg_score'] = round((float) $r['avg_score'], 1);
            $r['best_score'] = round((float) $r['best_score'], 1);
            $r['pass_rate'] = $this->safePercent((int) $r['passed'], max((int) $r['total_attempts'], 1));
        }
        return $rows;
    }

    /**
     * 8. Struggling Learners Report
     * Users with consistently low scores — need intervention
     */
    public function strugglingLearners(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $date = $this->buildDateRangeWhere('at.completed_at');
        $m = $this->mergeWhereClauses($org, $date);

        $courseWhere = $this->course_id ? " AND a.course_id = ?" : "";
        $courseTypes = $this->course_id ? "i" : "";
        $courseParams = $this->course_id ? [$this->course_id] : [];

        $threshold = (float) ($this->filters['score_threshold'] ?? 50);

        $sql = "SELECT u.id as user_id,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            COALESCE(t.team_name, '') as team_name,
            COALESCE(b.branch_name, '') as branch_name,
            COUNT(*) as total_attempts,
            SUM(CASE WHEN at.passed = 0 THEN 1 ELSE 0 END) as failed_count,
            AVG(CASE WHEN at.max_score > 0 THEN (at.score / at.max_score) * 100 ELSE NULL END) as avg_score,
            GROUP_CONCAT(DISTINCT a.title SEPARATOR '||') as failed_assessments,
            MAX(at.completed_at) as last_attempt_date
            FROM proc_attempts at
            JOIN users u ON u.id = at.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN teams t ON t.team_id = u.team
            LEFT JOIN branches b ON b.branch_id = u.branch
            JOIN proc_assessments a ON a.assessment_id = at.assessment_id
            WHERE at.tenant_id = ? AND at.status = 'completed' {$m['where']}{$courseWhere}
            GROUP BY u.id, u.first_name, u.last_name, u.email, d.department_name, t.team_name, b.branch_name
            HAVING avg_score < {$threshold}
            ORDER BY avg_score ASC LIMIT 50";

        $rows = $this->executeQuery($sql, 'i' . $m['types'] . $courseTypes, [$this->tenant_id, ...$m['params'], ...$courseParams]);
        foreach ($rows as &$r) {
            $r['avg_score'] = round((float) $r['avg_score'], 1);
            $r['failed_assessments_list'] = explode('||', $r['failed_assessments']);
            $r['fail_rate'] = $this->safePercent((int) $r['failed_count'], max((int) $r['total_attempts'], 1));
            $r['risk_level'] = $r['avg_score'] < 30 ? 'critical' : ($r['avg_score'] < 40 ? 'high' : 'medium');
        }
        return $rows;
    }

    /**
     * 9. Proctoring Violation Report
     * Proctored exam violations and flags
     */
    public function proctoringViolations(): array
    {
        if (!$this->tableExists('proc_proctoring_sessions')) {
            return ['info' => 'Proctoring sessions table not available'];
        }

        $org = $this->buildOrgScopeWhere('u');
        $date = $this->buildDateRangeWhere('ps.started_at');
        $m = $this->mergeWhereClauses($org, $date);

        $sql = "SELECT ps.session_id, ps.attempt_id,
            ps.tab_switches, ps.face_not_detected_count, ps.multiple_faces_count,
            ps.copy_paste_count, ps.right_click_count,
            ps.total_violations, ps.risk_level, ps.status as session_status,
            at.assessment_id, at.score, at.max_score, at.passed,
            a.title as assessment_title,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            ps.started_at, ps.ended_at
            FROM proc_proctoring_sessions ps
            JOIN proc_attempts at ON at.attempt_id = ps.attempt_id
            JOIN proc_assessments a ON a.assessment_id = at.assessment_id
            JOIN users u ON u.id = at.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            WHERE at.tenant_id = ? AND ps.total_violations > 0 {$m['where']}
            ORDER BY ps.total_violations DESC LIMIT 100";

        $data = $this->executeQuery($sql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);
        foreach ($data as &$row) {
            $row['score_pct'] = $row['max_score'] > 0 ? round(($row['score'] / $row['max_score']) * 100, 1) : 0;
            $row['passed'] = (bool) $row['passed'];
        }
        return $data;
    }

    /**
     * 10. Assessment Results Detail (paginated, filterable)
     * All attempt records for table display / export
     */
    public function assessmentResultsDetail(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $date = $this->buildDateRangeWhere('at.started_at');
        $m = $this->mergeWhereClauses($org, $date);

        $extra = '';
        $extraTypes = '';
        $extraParams = [];
        if (!empty($this->filters['assessment_id'])) {
            $extra .= " AND at.assessment_id = ?";
            $extraTypes .= 'i';
            $extraParams[] = (int) $this->filters['assessment_id'];
        }
        if (!empty($this->filters['status'])) {
            $extra .= " AND at.status = ?";
            $extraTypes .= 's';
            $extraParams[] = $this->filters['status'];
        }
        if (!empty($this->filters['passed'])) {
            $extra .= " AND at.passed = ?";
            $extraTypes .= 'i';
            $extraParams[] = $this->filters['passed'] === 'yes' ? 1 : 0;
        }
        if ($this->course_id) {
            $extra .= " AND a.course_id = ?";
            $extraTypes .= 'i';
            $extraParams[] = $this->course_id;
        }

        $baseSql = "SELECT at.attempt_id, at.assessment_id, at.user_id, at.attempt_number,
            at.score, at.max_score,
            CASE WHEN at.max_score > 0 THEN ROUND((at.score / at.max_score) * 100, 1) ELSE 0 END as score_pct,
            at.passed, at.grade, at.status, at.grading_status,
            at.started_at, at.completed_at, at.duration_seconds,
            a.title as assessment_title, a.assessment_type,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            COALESCE(t.team_name, '') as team_name,
            COALESCE(b.branch_name, '') as branch_name
            FROM proc_attempts at
            JOIN users u ON u.id = at.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN teams t ON t.team_id = u.team
            LEFT JOIN branches b ON b.branch_id = u.branch
            JOIN proc_assessments a ON a.assessment_id = at.assessment_id
            WHERE at.tenant_id = ? {$m['where']}{$extra}";

        $allTypes = 'i' . $m['types'] . $extraTypes;
        $allParams = [$this->tenant_id, ...$m['params'], ...$extraParams];
        $total = $this->getCount($baseSql, $allTypes, $allParams);
        $sql = $baseSql . " ORDER BY at.started_at DESC" . $this->getLimitClause();
        $data = $this->executeQuery($sql, $allTypes, $allParams);

        foreach ($data as &$row) {
            $row['score_pct'] = (float) $row['score_pct'];
            $row['passed'] = (bool) $row['passed'];
            $row['duration_formatted'] = $this->formatSeconds((int) $row['duration_seconds']);
        }

        return $this->paginatedResponse($data, $total);
    }

    // Helper
    private function formatSeconds(int $seconds): string
    {
        if ($seconds < 60) return $seconds . 's';
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;
        if ($m < 60) return "{$m}m {$s}s";
        $h = intdiv($m, 60);
        $m = $m % 60;
        return "{$h}h {$m}m";
    }

    public function getExportColumns(string $report): array
    {
        return match ($report) {
            'failed_assessments' => [
                ['key' => 'learner_name', 'label' => 'Learner'], ['key' => 'email', 'label' => 'Email'],
                ['key' => 'assessment_title', 'label' => 'Assessment'], ['key' => 'department_name', 'label' => 'Department'],
                ['key' => 'team_name', 'label' => 'Team'], ['key' => 'score_pct', 'label' => 'Score %'],
                ['key' => 'grade', 'label' => 'Grade'], ['key' => 'attempt_number', 'label' => 'Attempt #'],
                ['key' => 'max_attempts_reached', 'label' => 'Max Reached'], ['key' => 'completed_at', 'label' => 'Date'],
            ],
            'assessment_results_detail' => [
                ['key' => 'learner_name', 'label' => 'Learner'], ['key' => 'email', 'label' => 'Email'],
                ['key' => 'assessment_title', 'label' => 'Assessment'], ['key' => 'department_name', 'label' => 'Department'],
                ['key' => 'team_name', 'label' => 'Team'], ['key' => 'branch_name', 'label' => 'Branch'],
                ['key' => 'score_pct', 'label' => 'Score %'], ['key' => 'passed', 'label' => 'Passed'],
                ['key' => 'grade', 'label' => 'Grade'], ['key' => 'status', 'label' => 'Status'],
                ['key' => 'attempt_number', 'label' => 'Attempt'], ['key' => 'duration_formatted', 'label' => 'Duration'],
                ['key' => 'started_at', 'label' => 'Started'], ['key' => 'completed_at', 'label' => 'Completed'],
            ],
            default => []
        };
    }
}
