<?php

namespace report;

require_once __DIR__ . '/BaseReport.php';

class AssessmentReports extends BaseReport
{

    public function generate(): array
    {
        return $this->assessmentResultsSummary();
    }

    /**
     * Report 20: Assessment Results Summary
     */
    public function assessmentResultsSummary(): array
    {
        $dateFilter = $this->buildDateFilter('aa.completed_at');

        $query = "SELECT a.assessment_id, a.title, a.type, a.passing_score,
                    c.title as course_title,
                    COUNT(aa.attempt_id) as total_attempts,
                    COUNT(DISTINCT aa.user_id) as unique_learners,
                    AVG(aa.score) as avg_score,
                    MIN(aa.score) as min_score,
                    MAX(aa.score) as max_score,
                    SUM(CASE WHEN aa.passed = 1 THEN 1 ELSE 0 END) as passed_count,
                    AVG(aa.time_taken_minutes) as avg_time
                  FROM assessments a
                  JOIN courses c ON a.course_id = c.course_id
                  LEFT JOIN assessment_attempts aa ON a.assessment_id = aa.assessment_id
                  WHERE a.tenant_id = ? AND a.is_active = 1 $dateFilter
                  GROUP BY a.assessment_id, a.title, a.type, a.passing_score, c.title
                  ORDER BY total_attempts DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $attempts = (int)$row['total_attempts'];
            $passed = (int)$row['passed_count'];
            $data[] = [
                'assessment_id' => (int)$row['assessment_id'],
                'title' => $row['title'],
                'type' => $row['type'],
                'course_title' => $row['course_title'],
                'passing_score' => (int)$row['passing_score'],
                'total_attempts' => $attempts,
                'unique_learners' => (int)$row['unique_learners'],
                'avg_score' => round((float)($row['avg_score'] ?? 0), 1),
                'min_score' => (int)($row['min_score'] ?? 0),
                'max_score' => (int)($row['max_score'] ?? 0),
                'passed_count' => $passed,
                'pass_rate' => $attempts > 0 ? round(($passed / $attempts) * 100, 1) : 0,
                'avg_time' => $this->formatDuration((int)($row['avg_time'] ?? 0))
            ];
        }

        $countQuery = "SELECT COUNT(*) as total FROM assessments WHERE tenant_id = ? AND is_active = 1";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Assessment Results Summary',
            'data' => $data,
            'meta' => $this->buildMeta($total),
            'summary' => $this->getAssessmentSummaryStats()
        ];
    }

    /**
     * Report 21: Individual Assessment Report
     */
    public function individualAssessmentReport(int $user_id): array
    {
        $query = "SELECT aa.attempt_id, a.assessment_id, a.title, a.type,
                    c.title as course_title, a.passing_score,
                    aa.score, aa.passed, aa.attempt_number,
                    aa.started_at, aa.completed_at, aa.time_taken,
                    aa.answers_json
                  FROM assessment_attempts aa
                  JOIN assessments a ON aa.assessment_id = a.assessment_id
                  JOIN courses c ON a.course_id = c.course_id
                  WHERE aa.user_id = ? AND a.tenant_id = ?
                  ORDER BY aa.completed_at DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $user_id, $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'attempt_id' => (int)$row['attempt_id'],
                'assessment_id' => (int)$row['assessment_id'],
                'title' => $row['title'],
                'type' => $row['type'],
                'course_title' => $row['course_title'],
                'passing_score' => (int)$row['passing_score'],
                'score' => (int)$row['score'],
                'passed' => (bool)$row['passed'],
                'status_badge' => $this->getStatusBadge($row['passed'] ? 'passed' : 'failed'),
                'attempt_number' => (int)$row['attempt_number'],
                'started_at' => $row['started_at'],
                'completed_at' => $row['completed_at'],
                'time_taken' => $this->formatDuration((int)$row['time_taken_minutes'])
            ];
        }

        // Summary stats for user
        $summaryQuery = "SELECT COUNT(*) as total_attempts,
                           AVG(score) as avg_score,
                           SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) as passed_count
                         FROM assessment_attempts aa
                         JOIN assessments a ON aa.assessment_id = a.assessment_id
                         WHERE aa.user_id = ? AND a.tenant_id = ?";
        $stmt = $this->conn->prepare($summaryQuery);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_assoc();

        $attempts = (int)($summary['total_attempts'] ?? 0);
        $passed = (int)($summary['passed_count'] ?? 0);

        return [
            'report_name' => 'Individual Assessment Report',
            'user_id' => $user_id,
            'summary' => [
                'total_attempts' => $attempts,
                'avg_score' => round((float)($summary['avg_score'] ?? 0), 1),
                'passed_count' => $passed,
                'pass_rate' => $attempts > 0 ? round(($passed / $attempts) * 100, 1) : 0
            ],
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 22: Failed Assessments Report
     */
    public function failedAssessmentsReport(): array
    {
        $query = "SELECT aa.attempt_id, u.id as user_id,
                    CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, d.department_name,
                    a.assessment_id, a.title, a.passing_score, a.max_attempts,
                    c.title as course_title,
                    aa.score, aa.attempt_number, aa.completed_at,
                    (SELECT MAX(score) FROM assessment_attempts WHERE user_id = u.id AND assessment_id = a.assessment_id) as highest_score,
                    (SELECT COUNT(*) FROM assessment_attempts WHERE user_id = u.id AND assessment_id = a.assessment_id) as total_attempts
                  FROM assessment_attempts aa
                  JOIN users u ON aa.user_id = u.id
                  JOIN assessments a ON aa.assessment_id = a.assessment_id
                  JOIN courses c ON a.course_id = c.course_id
                  LEFT JOIN departments d ON u.department = d.department_id
                  WHERE a.tenant_id = ? AND aa.passed = 0
                  AND aa.attempt_id = (
                    SELECT MAX(attempt_id) FROM assessment_attempts 
                    WHERE user_id = aa.user_id AND assessment_id = aa.assessment_id
                  )
                  ORDER BY aa.completed_at DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $maxAttempts = (int)$row['max_attempts'];
            $usedAttempts = (int)$row['total_attempts'];
            $data[] = [
                'attempt_id' => (int)$row['attempt_id'],
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'department' => $row['department_name'] ?? 'N/A',
                'assessment_id' => (int)$row['assessment_id'],
                'assessment_title' => $row['title'],
                'course_title' => $row['course_title'],
                'passing_score' => (int)$row['passing_score'],
                'last_score' => (int)$row['score'],
                'highest_score' => (int)$row['highest_score'],
                'attempt_number' => (int)$row['attempt_number'],
                'max_attempts' => $maxAttempts,
                'attempts_remaining' => $maxAttempts > 0 ? max(0, $maxAttempts - $usedAttempts) : 'Unlimited',
                'last_attempt_at' => $row['completed_at']
            ];
        }

        return [
            'report_name' => 'Failed Assessments Report',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 23: Question Analysis Report
     */
    public function questionAnalysisReport(int $assessment_id): array
    {
        // Get assessment info
        $assessmentQuery = "SELECT a.*, c.title as course_title FROM assessments a 
                           JOIN courses c ON a.course_id = c.course_id
                           WHERE a.assessment_id = ? AND a.tenant_id = ?";
        $stmt = $this->conn->prepare($assessmentQuery);
        $stmt->bind_param("ii", $assessment_id, $this->tenant_id);
        $stmt->execute();
        $assessment = $stmt->get_result()->fetch_assoc();

        if (!$assessment) {
            return ['error' => 'Assessment not found'];
        }

        // Get questions with performance data
        $query = "SELECT q.question_id, q.question_text, q.question_type, q.points,
                    COUNT(DISTINCT aa.attempt_id) as times_answered,
                    SUM(CASE WHEN JSON_EXTRACT(aa.answers_json, CONCAT('$.q', q.question_id, '.correct')) = true THEN 1 ELSE 0 END) as correct_count
                  FROM questions q
                  LEFT JOIN assessment_attempts aa ON aa.assessment_id = q.assessment_id
                  WHERE q.assessment_id = ? AND q.is_active = 1
                  GROUP BY q.question_id, q.question_text, q.question_type, q.points
                  ORDER BY q.question_id";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $assessment_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $answered = (int)$row['times_answered'];
            $correct = (int)$row['correct_count'];
            $data[] = [
                'question_id' => (int)$row['question_id'],
                'question_text' => $row['question_text'],
                'question_type' => $row['question_type'],
                'points' => (int)$row['points'],
                'times_answered' => $answered,
                'correct_count' => $correct,
                'correct_percentage' => $answered > 0 ? round(($correct / $answered) * 100, 1) : 0,
                'difficulty' => $this->getDifficultyLevel($answered > 0 ? ($correct / $answered) * 100 : 50)
            ];
        }

        return [
            'report_name' => 'Question Analysis Report',
            'assessment' => [
                'assessment_id' => (int)$assessment['assessment_id'],
                'title' => $assessment['title'],
                'type' => $assessment['type'],
                'course_title' => $assessment['course_title']
            ],
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 24: Score Distribution Report
     */
    public function scoreDistributionReport(?int $assessment_id = null): array
    {
        $where = "WHERE a.tenant_id = ?";
        $params = [$this->tenant_id];
        $types = "i";

        if ($assessment_id) {
            $where .= " AND a.assessment_id = ?";
            $params[] = $assessment_id;
            $types .= "i";
        }

        $query = "SELECT 
                    CASE 
                      WHEN aa.score >= 90 THEN '90-100'
                      WHEN aa.score >= 80 THEN '80-89'
                      WHEN aa.score >= 70 THEN '70-79'
                      WHEN aa.score >= 60 THEN '60-69'
                      WHEN aa.score >= 50 THEN '50-59'
                      ELSE 'Below 50'
                    END as score_range,
                    COUNT(*) as count,
                    AVG(aa.score) as avg_in_range
                  FROM assessment_attempts aa
                  JOIN assessments a ON aa.assessment_id = a.assessment_id
                  $where
                  GROUP BY score_range
                  ORDER BY FIELD(score_range, '90-100', '80-89', '70-79', '60-69', '50-59', 'Below 50')";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        $totalAttempts = 0;
        while ($row = $result->fetch_assoc()) {
            $count = (int)$row['count'];
            $totalAttempts += $count;
            $data[] = [
                'score_range' => $row['score_range'],
                'count' => $count,
                'avg_score' => round((float)$row['avg_in_range'], 1)
            ];
        }

        // Calculate percentages
        foreach ($data as &$item) {
            $item['percentage'] = $totalAttempts > 0 ? round(($item['count'] / $totalAttempts) * 100, 1) : 0;
        }

        return [
            'report_name' => 'Score Distribution Report',
            'assessment_id' => $assessment_id,
            'total_attempts' => $totalAttempts,
            'data' => $data
        ];
    }

    /**
     * Report 25: Assessment Attempts Report
     */
    public function assessmentAttemptsReport(): array
    {
        $query = "SELECT a.assessment_id, a.title, a.max_attempts, c.title as course_title,
                    COUNT(aa.attempt_id) as total_attempts,
                    COUNT(DISTINCT aa.user_id) as unique_users,
                    AVG(sub.user_attempts) as avg_attempts_per_user,
                    MAX(sub.user_attempts) as max_attempts_used
                  FROM assessments a
                  JOIN courses c ON a.course_id = c.course_id
                  LEFT JOIN assessment_attempts aa ON a.assessment_id = aa.assessment_id
                  LEFT JOIN (
                    SELECT user_id, assessment_id, COUNT(*) as user_attempts 
                    FROM assessment_attempts GROUP BY user_id, assessment_id
                  ) sub ON sub.assessment_id = a.assessment_id
                  WHERE a.tenant_id = ? AND a.is_active = 1
                  GROUP BY a.assessment_id, a.title, a.max_attempts, c.title
                  ORDER BY avg_attempts_per_user DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'assessment_id' => (int)$row['assessment_id'],
                'title' => $row['title'],
                'course_title' => $row['course_title'],
                'max_attempts' => (int)$row['max_attempts'] ?: 'Unlimited',
                'total_attempts' => (int)$row['total_attempts'],
                'unique_users' => (int)$row['unique_users'],
                'avg_attempts_per_user' => round((float)($row['avg_attempts_per_user'] ?? 0), 1),
                'max_attempts_used' => (int)($row['max_attempts_used'] ?? 0)
            ];
        }

        return [
            'report_name' => 'Assessment Attempts Report',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 26: Top Performers Report
     */
    public function topPerformersReport(int $limit = 20): array
    {
        $query = "SELECT u.id as user_id, CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, u.avatar, d.department_name,
                    COUNT(DISTINCT aa.assessment_id) as assessments_taken,
                    COUNT(aa.attempt_id) as total_attempts,
                    AVG(aa.score) as avg_score,
                    SUM(CASE WHEN aa.passed = 1 THEN 1 ELSE 0 END) as passed_count,
                    COUNT(DISTINCT CASE WHEN ce.status = 'completed' THEN ce.course_id END) as courses_completed
                  FROM users u
                  JOIN assessment_attempts aa ON u.id = aa.user_id
                  JOIN assessments a ON aa.assessment_id = a.assessment_id
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.tenant_id = ? AND ce.is_active = 1
                  WHERE u.tenant_id = ? AND u.is_active = 1 AND a.tenant_id = ?
                  GROUP BY u.id, u.first_name, u.last_name, u.email, u.avatar, d.department_name
                  HAVING total_attempts >= 3
                  ORDER BY avg_score DESC, passed_count DESC
                  LIMIT ?";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iiii", $this->tenant_id, $this->tenant_id, $this->tenant_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        $rank = 1;
        while ($row = $result->fetch_assoc()) {
            $attempts = (int)$row['total_attempts'];
            $passed = (int)$row['passed_count'];
            $data[] = [
                'rank' => $rank++,
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'avatar_url' => $row['avatar_url'],
                'department' => $row['department_name'] ?? 'N/A',
                'assessments_taken' => (int)$row['assessments_taken'],
                'total_attempts' => $attempts,
                'avg_score' => round((float)$row['avg_score'], 1),
                'pass_rate' => $attempts > 0 ? round(($passed / $attempts) * 100, 1) : 0,
                'courses_completed' => (int)$row['courses_completed']
            ];
        }

        return [
            'report_name' => 'Top Performers Report',
            'data' => $data,
            'meta' => ['total' => count($data), 'limit' => $limit]
        ];
    }

    /**
     * Report 27: Struggling Learners Report
     */
    public function strugglingLearnersReport(): array
    {
        $query = "SELECT u.id as user_id, CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, d.department_name,
                    CONCAT(mgr.first_name, ' ', mgr.last_name) as manager_name,
                    COUNT(aa.attempt_id) as total_attempts,
                    AVG(aa.score) as avg_score,
                    SUM(CASE WHEN aa.passed = 0 THEN 1 ELSE 0 END) as failed_count,
                    COUNT(DISTINCT CASE WHEN aa.passed = 0 THEN aa.assessment_id END) as failed_assessments
                  FROM users u
                  JOIN assessment_attempts aa ON u.id = aa.user_id
                  JOIN assessments a ON aa.assessment_id = a.assessment_id
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN users mgr ON u.manager_id = mgr.id
                  WHERE u.tenant_id = ? AND u.is_active = 1 AND a.tenant_id = ?
                  GROUP BY u.id, u.first_name, u.last_name, u.email, d.department_name, 
                           mgr.first_name, mgr.last_name
                  HAVING avg_score < 70 AND total_attempts >= 2
                  ORDER BY avg_score ASC, failed_count DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $this->tenant_id, $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $avgScore = (float)$row['avg_score'];
            $data[] = [
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'department' => $row['department_name'] ?? 'N/A',
                'manager' => $row['manager_name'] ?? 'N/A',
                'total_attempts' => (int)$row['total_attempts'],
                'avg_score' => round($avgScore, 1),
                'failed_count' => (int)$row['failed_count'],
                'failed_assessments' => (int)$row['failed_assessments'],
                'needs_attention' => $avgScore < 50 ? 'critical' : ($avgScore < 60 ? 'high' : 'medium')
            ];
        }

        return [
            'report_name' => 'Struggling Learners Report',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    private function getDifficultyLevel(float $correctPercentage): string
    {
        if ($correctPercentage >= 80) return 'Easy';
        if ($correctPercentage >= 60) return 'Medium';
        if ($correctPercentage >= 40) return 'Hard';
        return 'Very Hard';
    }

    private function getAssessmentSummaryStats(): array
    {
        $query = "SELECT COUNT(*) as total_attempts,
                    COUNT(DISTINCT user_id) as unique_learners,
                    AVG(score) as avg_score,
                    SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) as passed_count
                  FROM assessment_attempts aa
                  JOIN assessments a ON aa.assessment_id = a.assessment_id
                  WHERE a.tenant_id = ?";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        $attempts = (int)($row['total_attempts'] ?? 0);
        $passed = (int)($row['passed_count'] ?? 0);

        return [
            'total_attempts' => $attempts,
            'unique_learners' => (int)($row['unique_learners'] ?? 0),
            'avg_score' => round((float)($row['avg_score'] ?? 0), 1),
            'overall_pass_rate' => $attempts > 0 ? round(($passed / $attempts) * 100, 1) : 0
        ];
    }

    public function getSummary(): array
    {
        return $this->getAssessmentSummaryStats();
    }
}
