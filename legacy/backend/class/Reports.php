<?php
/**
 * Reports & Analytics Manager
 * Multi-Tenant LMS - Admin Portal
 */

class Reports
{
    private mysqli $conn;
    private int $tenant_id;
    private int $user_id;
    private string $export_path = '/exports/reports/';

    public function __construct(mysqli $conn) { $this->conn = $conn; }
    public function setTenant(int $tenant_id): void { $this->tenant_id = $tenant_id; }
    public function setUser(int $user_id): void { $this->user_id = $user_id; }
    public function setExportPath(string $path): void { $this->export_path = $path; }

    // ==========================================
    // ASSESSMENT REPORTS
    // ==========================================

    public function getAssessmentSummary(int $assessment_id, array $filters = []): array
    {
        $date_from = $filters['date_from'] ?? null;
        $date_to = $filters['date_to'] ?? null;

        // Basic info
        $stmt = $this->conn->prepare("SELECT * FROM proc_assessments WHERE assessment_id = ? AND tenant_id = ?");
        $stmt->bind_param("ii", $assessment_id, $this->tenant_id);
        $stmt->execute();
        $assessment = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$assessment) return ['error' => 'Assessment not found'];

        // Build date filter
        $dateWhere = ""; $dateParams = []; $dateTypes = "";
        if ($date_from) { $dateWhere .= " AND a.started_at >= ?"; $dateParams[] = $date_from; $dateTypes .= "s"; }
        if ($date_to) { $dateWhere .= " AND a.started_at <= ?"; $dateParams[] = $date_to . " 23:59:59"; $dateTypes .= "s"; }

        // Attempt statistics
        $sql = "SELECT 
            COUNT(*) as total_attempts,
            SUM(CASE WHEN status IN ('submitted','auto_submitted','graded') THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) as passed,
            SUM(CASE WHEN passed = 0 AND status IN ('submitted','auto_submitted','graded') THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status = 'abandoned' THEN 1 ELSE 0 END) as abandoned,
            AVG(percentage_score) as avg_score,
            MIN(percentage_score) as min_score,
            MAX(percentage_score) as max_score,
            AVG(time_spent_seconds) as avg_time,
            AVG(proctoring_score) as avg_trust_score
            FROM proc_attempts a WHERE a.assessment_id = ? AND a.tenant_id = ? $dateWhere";
        
        $params = array_merge([$assessment_id, $this->tenant_id], $dateParams);
        $types = "ii" . $dateTypes;
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Calculate rates
        $total = (int) $stats['total_attempts'];
        $completed = (int) $stats['completed'];
        
        // Grade distribution
        $gradeDistribution = $this->getGradeDistribution($assessment_id, $date_from, $date_to);
        
        // Score distribution (histogram)
        $scoreDistribution = $this->getScoreDistribution($assessment_id, $date_from, $date_to);

        return [
            'assessment' => [
                'assessment_id' => (int) $assessment['assessment_id'],
                'title' => $assessment['title'],
                'passing_score' => (float) $assessment['passing_score'],
                'time_limit' => (int) $assessment['time_limit_minutes']
            ],
            'period' => ['from' => $date_from, 'to' => $date_to],
            'summary' => [
                'total_attempts' => $total,
                'completed' => $completed,
                'passed' => (int) $stats['passed'],
                'failed' => (int) $stats['failed'],
                'abandoned' => (int) $stats['abandoned'],
                'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
                'pass_rate' => $completed > 0 ? round(((int) $stats['passed'] / $completed) * 100, 1) : 0,
                'avg_score' => $stats['avg_score'] ? round((float) $stats['avg_score'], 1) : null,
                'min_score' => $stats['min_score'] ? round((float) $stats['min_score'], 1) : null,
                'max_score' => $stats['max_score'] ? round((float) $stats['max_score'], 1) : null,
                'avg_time_minutes' => $stats['avg_time'] ? round((float) $stats['avg_time'] / 60, 1) : null,
                'avg_trust_score' => $stats['avg_trust_score'] ? round((float) $stats['avg_trust_score'], 1) : null
            ],
            'grade_distribution' => $gradeDistribution,
            'score_distribution' => $scoreDistribution
        ];
    }

    private function getGradeDistribution(int $assessment_id, ?string $date_from, ?string $date_to): array
    {
        $dateWhere = "";
        if ($date_from) $dateWhere .= " AND started_at >= '$date_from'";
        if ($date_to) $dateWhere .= " AND started_at <= '$date_to 23:59:59'";

        $result = $this->conn->query("SELECT letter_grade, COUNT(*) as count FROM proc_attempts 
            WHERE assessment_id = $assessment_id AND tenant_id = {$this->tenant_id} 
            AND status IN ('submitted','auto_submitted','graded') AND letter_grade IS NOT NULL $dateWhere
            GROUP BY letter_grade ORDER BY FIELD(letter_grade, 'A+','A','A-','B+','B','B-','C+','C','C-','D+','D','D-','F')");
        
        $distribution = [];
        while ($row = $result->fetch_assoc()) {
            $distribution[$row['letter_grade']] = (int) $row['count'];
        }
        return $distribution;
    }

    private function getScoreDistribution(int $assessment_id, ?string $date_from, ?string $date_to): array
    {
        $dateWhere = "";
        if ($date_from) $dateWhere .= " AND started_at >= '$date_from'";
        if ($date_to) $dateWhere .= " AND started_at <= '$date_to 23:59:59'";

        $result = $this->conn->query("SELECT 
            FLOOR(percentage_score / 10) * 10 as bucket,
            COUNT(*) as count
            FROM proc_attempts 
            WHERE assessment_id = $assessment_id AND tenant_id = {$this->tenant_id}
            AND status IN ('submitted','auto_submitted','graded') $dateWhere
            GROUP BY bucket ORDER BY bucket");
        
        $distribution = [];
        for ($i = 0; $i <= 100; $i += 10) {
            $distribution["$i-" . ($i + 9)] = 0;
        }
        while ($row = $result->fetch_assoc()) {
            $bucket = (int) $row['bucket'];
            $key = "$bucket-" . ($bucket + 9);
            if ($bucket >= 100) $key = "100";
            $distribution[$key] = (int) $row['count'];
        }
        return $distribution;
    }

    public function getAssessmentAttempts(int $assessment_id, array $filters = []): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $status = $filters['status'] ?? null;
        $passed = isset($filters['passed']) ? (int) $filters['passed'] : null;

        $sql = "SELECT a.*, u.first_name, u.last_name, u.email 
                FROM proc_attempts a JOIN users u ON a.user_id = u.id
                WHERE a.assessment_id = ? AND a.tenant_id = ?";
        $params = [$assessment_id, $this->tenant_id]; $types = "ii";

        if ($status) { $sql .= " AND a.status = ?"; $params[] = $status; $types .= "s"; }
        if ($passed !== null) { $sql .= " AND a.passed = ?"; $params[] = $passed; $types .= "i"; }

        $sql .= " ORDER BY a.started_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit; $params[] = $offset; $types .= "ii";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $attempts = [];
        while ($row = $result->fetch_assoc()) {
            $row['attempt_id'] = (int) $row['attempt_id'];
            $row['learner_name'] = trim($row['first_name'] . ' ' . $row['last_name']);
            $row['percentage_score'] = (float) $row['percentage_score'];
            $row['passed'] = (bool) $row['passed'];
            $attempts[] = $row;
        }
        $stmt->close();

        return $attempts;
    }

    // ==========================================
    // QUESTION ANALYSIS
    // ==========================================

    public function calculateQuestionStatistics(int $assessment_id): array
    {
        // Get all questions for this assessment
        $questions = $this->conn->query("SELECT aq.question_id, q.question_type, aq.points as max_points
            FROM proc_assessment_questions aq
            JOIN proc_questions q ON aq.question_id = q.question_id
            WHERE aq.assessment_id = $assessment_id")->fetch_all(MYSQLI_ASSOC);

        $calculated = 0;
        foreach ($questions as $q) {
            $this->calculateSingleQuestionStats((int) $q['question_id'], $assessment_id, (float) $q['max_points']);
            $calculated++;
        }

        return ['success' => true, 'calculated' => $calculated];
    }

    private function calculateSingleQuestionStats(int $question_id, int $assessment_id, float $max_points): void
    {
        // Get answer statistics
        $stats = $this->conn->query("SELECT 
            COUNT(*) as times_presented,
            SUM(CASE WHEN is_answered = 1 THEN 1 ELSE 0 END) as times_answered,
            SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as times_correct,
            SUM(CASE WHEN is_partial = 1 THEN 1 ELSE 0 END) as times_partial,
            SUM(CASE WHEN is_correct = 0 AND is_partial = 0 AND is_answered = 1 THEN 1 ELSE 0 END) as times_incorrect,
            SUM(CASE WHEN is_answered = 0 THEN 1 ELSE 0 END) as times_skipped,
            AVG(time_spent_seconds) as avg_time,
            MIN(time_spent_seconds) as min_time,
            MAX(time_spent_seconds) as max_time,
            AVG(points_earned) as avg_points
            FROM proc_attempt_answers aa
            JOIN proc_attempts a ON aa.attempt_id = a.attempt_id
            WHERE aa.question_id = $question_id AND a.assessment_id = $assessment_id
            AND a.status IN ('submitted','auto_submitted','graded')")->fetch_assoc();

        $presented = (int) $stats['times_presented'];
        $answered = (int) $stats['times_answered'];
        $correct = (int) $stats['times_correct'];

        // Calculate difficulty index (P-value)
        $difficulty_index = $answered > 0 ? $correct / $answered : null;
        $difficulty_label = $this->getDifficultyLabel($difficulty_index);

        // Calculate discrimination index (simplified point-biserial)
        $discrimination_index = $this->calculateDiscrimination($question_id, $assessment_id);
        $discrimination_label = $this->getDiscriminationLabel($discrimination_index);

        // Check if needs review
        $needs_review = ($difficulty_index !== null && ($difficulty_index < 0.2 || $difficulty_index > 0.9)) ||
                        ($discrimination_index !== null && $discrimination_index < 0.2);
        $review_reason = null;
        if ($difficulty_index !== null && $difficulty_index < 0.2) $review_reason = 'Too difficult';
        elseif ($difficulty_index !== null && $difficulty_index > 0.9) $review_reason = 'Too easy';
        elseif ($discrimination_index !== null && $discrimination_index < 0.2) $review_reason = 'Poor discrimination';

        // Insert or update
        $stmt = $this->conn->prepare("INSERT INTO proc_question_statistics 
            (question_id, assessment_id, tenant_id, times_presented, times_answered, times_correct, times_partial, times_incorrect, times_skipped,
             correct_rate, partial_rate, skip_rate, difficulty_index, difficulty_label, discrimination_index, discrimination_label,
             avg_time_seconds, min_time_seconds, max_time_seconds, avg_points_earned, max_points, avg_points_percentage,
             needs_review, review_reason, last_calculated_at, sample_size)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ON DUPLICATE KEY UPDATE
             times_presented=VALUES(times_presented), times_answered=VALUES(times_answered), times_correct=VALUES(times_correct),
             times_partial=VALUES(times_partial), times_incorrect=VALUES(times_incorrect), times_skipped=VALUES(times_skipped),
             correct_rate=VALUES(correct_rate), partial_rate=VALUES(partial_rate), skip_rate=VALUES(skip_rate),
             difficulty_index=VALUES(difficulty_index), difficulty_label=VALUES(difficulty_label),
             discrimination_index=VALUES(discrimination_index), discrimination_label=VALUES(discrimination_label),
             avg_time_seconds=VALUES(avg_time_seconds), min_time_seconds=VALUES(min_time_seconds), max_time_seconds=VALUES(max_time_seconds),
             avg_points_earned=VALUES(avg_points_earned), avg_points_percentage=VALUES(avg_points_percentage),
             needs_review=VALUES(needs_review), review_reason=VALUES(review_reason), last_calculated_at=NOW(), sample_size=VALUES(sample_size)");

        $correct_rate = $presented > 0 ? round(($correct / $presented) * 100, 2) : 0;
        $times_partial = (int)$stats['times_partial'];
        $partial_rate = $presented > 0 ? round(($times_partial / $presented) * 100, 2) : 0;
        $times_skipped = (int)$stats['times_skipped'];
        $skip_rate = $presented > 0 ? round(($times_skipped / $presented) * 100, 2) : 0;
        $avg_points = $stats['avg_points'] ? round((float) $stats['avg_points'], 2) : 0;
        $avg_pct = $max_points > 0 ? round(($avg_points / $max_points) * 100, 2) : 0;

        $times_incorrect = (int)$stats['times_incorrect'];
        $avg_time = (int)$stats['avg_time'];
        $min_time = (int)$stats['min_time'];
        $max_time = (int)$stats['max_time'];
        $stmt->bind_param("iiiiiiiiiidddsdsiiiiddisi",
            $question_id, $assessment_id, $this->tenant_id,
            $presented, $answered, $correct, $times_partial, $times_incorrect, $times_skipped,
            $correct_rate, $partial_rate, $skip_rate,
            $difficulty_index, $difficulty_label, $discrimination_index, $discrimination_label,
            $avg_time, $min_time, $max_time,
            $avg_points, $max_points, $avg_pct,
            $needs_review, $review_reason, $presented
        );
        $stmt->execute();
        $stmt->close();
    }

    private function calculateDiscrimination(int $question_id, int $assessment_id): ?float
    {
        // Simplified: Compare top 27% vs bottom 27% performance
        $total = $this->conn->query("SELECT COUNT(*) as cnt FROM proc_attempts 
            WHERE assessment_id = $assessment_id AND status IN ('submitted','auto_submitted','graded')")->fetch_assoc()['cnt'];
        
        if ($total < 10) return null;
        
        $groupSize = max(1, (int) floor($total * 0.27));
        
        // Top performers correct rate
        $topCorrect = $this->conn->query("SELECT AVG(aa.is_correct) as rate FROM proc_attempt_answers aa
            JOIN proc_attempts a ON aa.attempt_id = a.attempt_id
            WHERE aa.question_id = $question_id AND a.assessment_id = $assessment_id
            AND a.attempt_id IN (SELECT attempt_id FROM proc_attempts WHERE assessment_id = $assessment_id 
                AND status IN ('submitted','auto_submitted','graded') ORDER BY percentage_score DESC LIMIT $groupSize)")->fetch_assoc()['rate'];
        
        // Bottom performers correct rate
        $bottomCorrect = $this->conn->query("SELECT AVG(aa.is_correct) as rate FROM proc_attempt_answers aa
            JOIN proc_attempts a ON aa.attempt_id = a.attempt_id
            WHERE aa.question_id = $question_id AND a.assessment_id = $assessment_id
            AND a.attempt_id IN (SELECT attempt_id FROM proc_attempts WHERE assessment_id = $assessment_id 
                AND status IN ('submitted','auto_submitted','graded') ORDER BY percentage_score ASC LIMIT $groupSize)")->fetch_assoc()['rate'];
        
        if ($topCorrect === null || $bottomCorrect === null) return null;
        
        return round((float) $topCorrect - (float) $bottomCorrect, 4);
    }

    private function getDifficultyLabel(?float $index): ?string
    {
        if ($index === null) return null;
        if ($index >= 0.9) return 'very_easy';
        if ($index >= 0.7) return 'easy';
        if ($index >= 0.3) return 'moderate';
        if ($index >= 0.1) return 'hard';
        return 'very_hard';
    }

    private function getDiscriminationLabel(?float $index): ?string
    {
        if ($index === null) return null;
        if ($index >= 0.4) return 'excellent';
        if ($index >= 0.3) return 'good';
        if ($index >= 0.2) return 'fair';
        return 'poor';
    }

    public function getQuestionAnalysis(int $assessment_id): array
    {
        $stmt = $this->conn->prepare("SELECT qs.*, q.question_text, q.question_type
            FROM proc_question_statistics qs
            JOIN proc_questions q ON qs.question_id = q.question_id
            WHERE qs.assessment_id = ? AND qs.tenant_id = ?
            ORDER BY qs.difficulty_index ASC");
        $stmt->bind_param("ii", $assessment_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $questions = [];
        while ($row = $result->fetch_assoc()) {
            $row['stat_id'] = (int) $row['stat_id'];
            $row['difficulty_index'] = $row['difficulty_index'] ? (float) $row['difficulty_index'] : null;
            $row['discrimination_index'] = $row['discrimination_index'] ? (float) $row['discrimination_index'] : null;
            $row['needs_review'] = (bool) $row['needs_review'];
            $questions[] = $row;
        }
        $stmt->close();

        return $questions;
    }

    // ==========================================
    // LEARNER REPORTS
    // ==========================================

    public function getLearnerProgress(int $user_id): array
    {
        // Overall stats
        $stmt = $this->conn->prepare("SELECT * FROM proc_learner_analytics WHERE user_id = ? AND tenant_id = ?");
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $analytics = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Recent attempts
        $stmt = $this->conn->prepare("SELECT a.*, ass.title as assessment_title
            FROM proc_attempts a JOIN proc_assessments ass ON a.assessment_id = ass.assessment_id
            WHERE a.user_id = ? AND a.tenant_id = ?
            ORDER BY a.started_at DESC LIMIT 10");
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $recentAttempts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Certificates
        $stmt = $this->conn->prepare("SELECT c.*, t.name as template_name
            FROM proc_certificates c JOIN proc_certificate_templates t ON c.template_id = t.template_id
            WHERE c.user_id = ? AND c.tenant_id = ? AND c.status = 'active'
            ORDER BY c.issued_at DESC");
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $certificates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return [
            'analytics' => $analytics,
            'recent_attempts' => $recentAttempts,
            'certificates' => $certificates
        ];
    }

    public function getLearnerTranscript(int $user_id): array
    {
        $stmt = $this->conn->prepare("SELECT a.attempt_id, a.started_at, a.submitted_at, a.percentage_score, a.letter_grade,
            a.passed, a.status, ass.title as assessment_title, ass.assessment_id,
            c.certificate_id, c.certificate_number
            FROM proc_attempts a
            JOIN proc_assessments ass ON a.assessment_id = ass.assessment_id
            LEFT JOIN proc_certificates c ON a.attempt_id = c.attempt_id
            WHERE a.user_id = ? AND a.tenant_id = ? AND a.status IN ('submitted','auto_submitted','graded')
            ORDER BY a.submitted_at DESC");
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $records = [];
        while ($row = $result->fetch_assoc()) {
            $row['attempt_id'] = (int) $row['attempt_id'];
            $row['passed'] = (bool) $row['passed'];
            $row['percentage_score'] = (float) $row['percentage_score'];
            $records[] = $row;
        }
        $stmt->close();

        return $records;
    }

    public function calculateLearnerAnalytics(int $user_id): array
    {
        $stats = $this->conn->query("SELECT 
            COUNT(DISTINCT la.assessment_id) as assigned,
            COUNT(DISTINCT a.assessment_id) as attempted,
            SUM(CASE WHEN a.status IN ('submitted','auto_submitted','graded') THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN a.passed = 1 THEN 1 ELSE 0 END) as passed,
            AVG(a.percentage_score) as avg_score,
            MIN(a.percentage_score) as min_score,
            MAX(a.percentage_score) as max_score,
            SUM(a.time_spent_seconds) as total_time,
            AVG(a.proctoring_score) as avg_trust
            FROM proc_learner_assessments la
            LEFT JOIN proc_attempts a ON la.assessment_id = a.assessment_id AND la.user_id = a.user_id
            WHERE la.user_id = $user_id AND la.tenant_id = {$this->tenant_id}")->fetch_assoc();

        $certStats = $this->conn->query("SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 'active' AND valid_until IS NOT NULL AND valid_until < DATE_ADD(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as expiring
            FROM proc_certificates WHERE user_id = $user_id AND tenant_id = {$this->tenant_id}")->fetch_assoc();

        // Recent scores for trend
        $recentScores = $this->conn->query("SELECT percentage_score FROM proc_attempts 
            WHERE user_id = $user_id AND tenant_id = {$this->tenant_id} AND status IN ('submitted','auto_submitted','graded')
            ORDER BY submitted_at DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);
        $scores = array_column($recentScores, 'percentage_score');
        $trend = $this->calculateTrend($scores);

        $completed = (int) $stats['completed'];
        $passed = (int) $stats['passed'];

        $stmt = $this->conn->prepare("INSERT INTO proc_learner_analytics 
            (user_id, tenant_id, total_assessments_assigned, total_assessments_attempted, total_assessments_completed, total_assessments_passed,
             overall_avg_score, overall_pass_rate, best_score, worst_score, total_time_spent_seconds,
             avg_trust_score, total_certificates, active_certificates, expiring_certificates,
             score_trend, recent_scores, last_calculated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
             total_assessments_assigned=VALUES(total_assessments_assigned), total_assessments_attempted=VALUES(total_assessments_attempted),
             total_assessments_completed=VALUES(total_assessments_completed), total_assessments_passed=VALUES(total_assessments_passed),
             overall_avg_score=VALUES(overall_avg_score), overall_pass_rate=VALUES(overall_pass_rate),
             best_score=VALUES(best_score), worst_score=VALUES(worst_score), total_time_spent_seconds=VALUES(total_time_spent_seconds),
             avg_trust_score=VALUES(avg_trust_score), total_certificates=VALUES(total_certificates),
             active_certificates=VALUES(active_certificates), expiring_certificates=VALUES(expiring_certificates),
             score_trend=VALUES(score_trend), recent_scores=VALUES(recent_scores), last_calculated_at=NOW()");

        $avgScore = $stats['avg_score'] ? round((float) $stats['avg_score'], 2) : null;
        $passRate = $completed > 0 ? round(($passed / $completed) * 100, 2) : null;
        $recentJson = json_encode($scores);

        $assigned = (int)$stats['assigned'];
        $attempted = (int)$stats['attempted'];
        $total_time = (int)$stats['total_time'];
        $active = (int)$certStats['active'];
        $total = (int)$certStats['total'];
        $expiring = (int)$certStats['expiring'];
        $stmt->bind_param("iiiiiiddddiiiisss",
            $user_id, $this->tenant_id, $assigned, $attempted, $completed, $passed,
            $avgScore, $passRate, $stats['max_score'], $stats['min_score'], $total_time,
            $stats['avg_trust'], $total, $active, $expiring,
            $trend, $recentJson
        );
        $stmt->execute();
        $stmt->close();

        return ['success' => true];
    }

    private function calculateTrend(array $scores): string
    {
        if (count($scores) < 3) return 'stable';
        $recent = array_slice($scores, 0, 3);
        $older = array_slice($scores, -3);
        $recentAvg = array_sum($recent) / count($recent);
        $olderAvg = array_sum($older) / count($older);
        $diff = $recentAvg - $olderAvg;
        if ($diff > 5) return 'improving';
        if ($diff < -5) return 'declining';
        return 'stable';
    }

    // ==========================================
    // PROCTORING REPORTS
    // ==========================================

    public function getProctoringReport(int $assessment_id): array
    {
        $stmt = $this->conn->prepare("SELECT ps.*, u.first_name, u.last_name, a.percentage_score
            FROM proc_proctoring_sessions ps
            JOIN proc_attempts att ON ps.attempt_id = att.attempt_id
            JOIN proc_attempts a ON ps.attempt_id = a.attempt_id
            JOIN users u ON ps.user_id = u.id
            WHERE att.assessment_id = ? AND ps.tenant_id = ?
            ORDER BY ps.trust_score ASC");
        $stmt->bind_param("ii", $assessment_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $sessions = [];
        while ($row = $result->fetch_assoc()) {
            $row['session_id'] = (int) $row['session_id'];
            $row['trust_score'] = (float) $row['trust_score'];
            $row['learner_name'] = trim($row['first_name'] . ' ' . $row['last_name']);
            $sessions[] = $row;
        }
        $stmt->close();

        // Violation summary
        $violations = $this->conn->query("SELECT pv.violation_type, COUNT(*) as count
            FROM proc_proctoring_violations pv
            JOIN proc_attempts a ON pv.attempt_id = a.attempt_id
            WHERE a.assessment_id = $assessment_id AND pv.tenant_id = {$this->tenant_id}
            GROUP BY pv.violation_type ORDER BY count DESC")->fetch_all(MYSQLI_ASSOC);

        return ['sessions' => $sessions, 'violations_by_type' => $violations];
    }

    // ==========================================
    // DASHBOARD DATA
    // ==========================================

    public function getDashboardStats(): array
    {
        $stats = [];

        // Total assessments
        $stats['total_assessments'] = (int) $this->conn->query("SELECT COUNT(*) as cnt FROM proc_assessments WHERE tenant_id = {$this->tenant_id} AND status = 'published'")->fetch_assoc()['cnt'];

        // Active learners (attempted in last 30 days)
        $stats['active_learners'] = (int) $this->conn->query("SELECT COUNT(DISTINCT user_id) as cnt FROM proc_attempts WHERE tenant_id = {$this->tenant_id} AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetch_assoc()['cnt'];

        // Overall pass rate
        $passStats = $this->conn->query("SELECT 
            SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) as passed,
            SUM(CASE WHEN status IN ('submitted','auto_submitted','graded') THEN 1 ELSE 0 END) as completed
            FROM proc_attempts WHERE tenant_id = {$this->tenant_id}")->fetch_assoc();
        $stats['overall_pass_rate'] = (int) $passStats['completed'] > 0 ? round(((int) $passStats['passed'] / (int) $passStats['completed']) * 100, 1) : 0;

        // Certificates
        $stats['total_certificates'] = (int) $this->conn->query("SELECT COUNT(*) as cnt FROM proc_certificates WHERE tenant_id = {$this->tenant_id} AND status = 'active'")->fetch_assoc()['cnt'];

        // Pending grading
        $stats['pending_grading'] = (int) $this->conn->query("SELECT COUNT(*) as cnt FROM proc_grading_queue WHERE tenant_id = {$this->tenant_id} AND status = 'pending'")->fetch_assoc()['cnt'];

        // Recent activity
        $stats['attempts_today'] = (int) $this->conn->query("SELECT COUNT(*) as cnt FROM proc_attempts WHERE tenant_id = {$this->tenant_id} AND DATE(started_at) = CURDATE()")->fetch_assoc()['cnt'];
        $stats['attempts_this_week'] = (int) $this->conn->query("SELECT COUNT(*) as cnt FROM proc_attempts WHERE tenant_id = {$this->tenant_id} AND started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['cnt'];

        return $stats;
    }

    public function getAttemptsOverTime(int $days = 30): array
    {
        $result = $this->conn->query("SELECT DATE(started_at) as date, COUNT(*) as total,
            SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) as passed
            FROM proc_attempts WHERE tenant_id = {$this->tenant_id} AND started_at >= DATE_SUB(NOW(), INTERVAL $days DAY)
            GROUP BY DATE(started_at) ORDER BY date");
        
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = ['date' => $row['date'], 'total' => (int) $row['total'], 'passed' => (int) $row['passed']];
        }
        return $data;
    }

    public function getTopPerformers(int $limit = 10): array
    {
        $stmt = $this->conn->prepare("SELECT la.*, u.first_name, u.last_name
            FROM proc_learner_analytics la JOIN users u ON la.user_id = u.id
            WHERE la.tenant_id = ? AND la.total_assessments_completed >= 3
            ORDER BY la.overall_avg_score DESC LIMIT ?");
        $stmt->bind_param("ii", $this->tenant_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $performers = [];
        while ($row = $result->fetch_assoc()) {
            $row['learner_name'] = trim($row['first_name'] . ' ' . $row['last_name']);
            $performers[] = $row;
        }
        $stmt->close();

        return $performers;
    }

    // ==========================================
    // REPORT GENERATION
    // ==========================================

    public function getReportDefinitions(): array
    {
        $stmt = $this->conn->prepare("SELECT * FROM proc_report_definitions WHERE (tenant_id = ? OR tenant_id = 0) AND status = 'active' ORDER BY is_system DESC, name ASC");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $reports = [];
        while ($row = $result->fetch_assoc()) {
            $row['report_id'] = (int) $row['report_id'];
            $row['is_system'] = (bool) $row['is_system'];
            $row['config'] = $row['config'] ? json_decode($row['config'], true) : null;
            $reports[] = $row;
        }
        $stmt->close();

        return $reports;
    }

    public function generateReport(int $report_id, array $params = []): array
    {
        $stmt = $this->conn->prepare("SELECT * FROM proc_report_definitions WHERE report_id = ? AND (tenant_id = ? OR tenant_id = 0)");
        $stmt->bind_param("ii", $report_id, $this->tenant_id);
        $stmt->execute();
        $report = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$report) return ['success' => false, 'message' => 'Report not found'];

        $startTime = microtime(true);
        $data = [];

        switch ($report['report_type']) {
            case 'assessment_summary':
                $data = $this->getAssessmentSummary((int) $params['assessment_id'], $params);
                break;
            case 'assessment_detail':
                $data = [
                    'summary' => $this->getAssessmentSummary((int) $params['assessment_id'], $params),
                    'questions' => $this->getQuestionAnalysis((int) $params['assessment_id']),
                    'attempts' => $this->getAssessmentAttempts((int) $params['assessment_id'], $params)
                ];
                break;
            case 'learner_progress':
                $data = $this->getLearnerProgress((int) $params['user_id']);
                break;
            case 'learner_transcript':
                $data = $this->getLearnerTranscript((int) $params['user_id']);
                break;
            case 'question_analysis':
                $this->calculateQuestionStatistics((int) $params['assessment_id']);
                $data = $this->getQuestionAnalysis((int) $params['assessment_id']);
                break;
            case 'proctoring_summary':
                $data = $this->getProctoringReport((int) $params['assessment_id']);
                break;
            default:
                return ['success' => false, 'message' => 'Unknown report type'];
        }

        $generationTime = round((microtime(true) - $startTime) * 1000);

        // Log generated report
        $recordCount = is_array($data) ? (isset($data['summary']) ? 1 : count($data)) : 0;
        $filtersJson = json_encode($params);
        $format = $params['format'] ?? 'json';

        $stmt = $this->conn->prepare("INSERT INTO proc_generated_reports (report_id, tenant_id, report_name, report_type, filters_used, date_from, date_to, output_format, record_count, generation_time_ms, status, generated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?)");
        $stmt->bind_param("iisssssiisi", $report_id, $this->tenant_id, $report['name'], $report['report_type'], $filtersJson, $params['date_from'], $params['date_to'], $format, $recordCount, $generationTime, $this->user_id);
        $stmt->execute();
        $generated_id = $stmt->insert_id;
        $stmt->close();

        return ['success' => true, 'generated_id' => $generated_id, 'data' => $data, 'generation_time_ms' => $generationTime];
    }

    public function getGeneratedReports(int $limit = 20): array
    {
        $stmt = $this->conn->prepare("SELECT gr.*, rd.name as report_name FROM proc_generated_reports gr LEFT JOIN proc_report_definitions rd ON gr.report_id = rd.report_id WHERE gr.tenant_id = ? ORDER BY gr.generated_at DESC LIMIT ?");
        $stmt->bind_param("ii", $this->tenant_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $reports = [];
        while ($row = $result->fetch_assoc()) {
            $row['generated_id'] = (int) $row['generated_id'];
            $reports[] = $row;
        }
        $stmt->close();

        return $reports;
    }
}
