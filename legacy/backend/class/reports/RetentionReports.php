<?php
require_once __DIR__ . '/BaseReport.php';

/**
 * Knowledge Retention & Training Impact — Phase 12
 * 4 assessment-focused + 5 executive-focused methods.
 * Uses existing tables: assessments, assessment_attempts, course_enrollments, users.
 */
class RetentionReports extends BaseReport
{
    public function generate(): array { return $this->prePostComparison(); }

    /* ═══════════════════════════════════════════════════
     * 1. PRE/POST COMPARISON
     * Pre-test vs post-test scores per course.
     * ═══════════════════════════════════════════════════ */
    public function prePostComparison(): array
    {
        $orgF = $this->buildOrgFilter();

        $q = "SELECT
                c.course_id AS course_id, c.title AS course_name,
                COUNT(DISTINCT aa_pre.user_id) AS learners_tested,
                ROUND(AVG(aa_pre.score), 1) AS avg_pre_score,
                ROUND(AVG(aa_post.score), 1) AS avg_post_score,
                ROUND(AVG(aa_post.score) - AVG(aa_pre.score), 1) AS avg_improvement,
                ROUND((AVG(aa_post.score) - AVG(aa_pre.score)) * 100.0 / NULLIF(AVG(aa_pre.score), 0), 1) AS improvement_pct,
                SUM(CASE WHEN aa_post.score > aa_pre.score THEN 1 ELSE 0 END) AS improved_count,
                SUM(CASE WHEN aa_post.score <= aa_pre.score THEN 1 ELSE 0 END) AS no_change_count
              FROM assessment_attempts aa_pre
              INNER JOIN assessments a_pre ON aa_pre.assessment_id = a_pre.id AND a_pre.assessment_type = 'pre_test'
              INNER JOIN courses c ON a_pre.course_id = c.course_id
              INNER JOIN assessments a_post ON a_post.course_id = c.course_id AND a_post.assessment_type = 'post_test'
              INNER JOIN assessment_attempts aa_post ON aa_post.assessment_id = a_post.id AND aa_post.user_id = aa_pre.user_id
              LEFT JOIN users u ON aa_pre.user_id = u.id AND u.tenant_id = aa_pre.tenant_id
              LEFT JOIN departments d ON u.department = d.id
              LEFT JOIN teams t ON u.team = t.id
              WHERE aa_pre.tenant_id = ? {$orgF}
              GROUP BY c.course_id, c.title
              ORDER BY improvement_pct DESC
              LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($q);
        $off = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $off);
        $stmt->execute(); $res = $stmt->get_result();

        $data = [];
        while ($row = $res->fetch_assoc()) {
            $data[] = [
                'course_id'       => (int)$row['course_id'],
                'course_name'     => $row['course_name'],
                'learners_tested' => (int)$row['learners_tested'],
                'avg_pre_score'   => (float)$row['avg_pre_score'],
                'avg_post_score'  => (float)$row['avg_post_score'],
                'avg_improvement' => (float)$row['avg_improvement'],
                'improvement_pct' => (float)($row['improvement_pct'] ?? 0),
                'improved_count'  => (int)$row['improved_count'],
                'no_change_count' => (int)$row['no_change_count'],
                'effectiveness'   => (float)$row['improvement_pct'] >= 20 ? 'high' : ((float)$row['improvement_pct'] >= 5 ? 'moderate' : 'low')
            ];
        }

        return [
            'report_name' => 'Pre/Post Test Comparison',
            'description' => 'Pre-test vs post-test score comparison per course',
            'chart_type'  => 'grouped_bar',
            'chart_config' => ['x_axis'=>'course_name','y_axes'=>['avg_pre_score','avg_post_score']],
            'summary' => [
                'courses_measured' => count($data),
                'avg_improvement'  => count($data) > 0 ? round(array_sum(array_column($data, 'improvement_pct')) / count($data), 1) : 0
            ],
            'data' => $data, 'meta' => $this->buildMeta(count($data))
        ];
    }

    /* ═══════════════════════════════════════════════════
     * 2. KNOWLEDGE RETENTION
     * Retest scores at 30/60/90 days after initial pass.
     * ═══════════════════════════════════════════════════ */
    public function knowledgeRetention(): array
    {
        $orgF = $this->buildOrgFilter();

        $q = "SELECT
                c.course_id AS course_id, c.title AS course_name,
                a.id AS assessment_id, a.title AS assessment_title,
                aa.user_id,
                CONCAT(u.first_name, ' ', u.last_name) AS learner_name,
                aa.attempt_number,
                aa.score,
                aa.completed_at,
                DATEDIFF(aa.completed_at, first_attempt.first_completed) AS days_since_first
              FROM assessment_attempts aa
              INNER JOIN assessments a ON aa.assessment_id = a.id
              INNER JOIN courses c ON a.course_id = c.course_id
              INNER JOIN users u ON aa.user_id = u.id AND u.tenant_id = aa.tenant_id
              LEFT JOIN departments d ON u.department = d.id
              LEFT JOIN teams t ON u.team = t.id
              INNER JOIN (
                  SELECT user_id, assessment_id, MIN(completed_at) AS first_completed
                  FROM assessment_attempts WHERE tenant_id = ? AND status = 'completed'
                  GROUP BY user_id, assessment_id
              ) first_attempt ON first_attempt.user_id = aa.user_id AND first_attempt.assessment_id = aa.assessment_id
              WHERE aa.tenant_id = ? AND aa.status = 'completed' AND aa.attempt_number > 1
                {$orgF}
              ORDER BY c.title, aa.user_id, aa.attempt_number
              LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($q);
        $off = $this->getOffset();
        $stmt->bind_param("iiii", $this->tenant_id, $this->tenant_id, $this->per_page, $off);
        $stmt->execute(); $res = $stmt->get_result();

        $data = [];
        while ($row = $res->fetch_assoc()) {
            $days = (int)$row['days_since_first'];
            $bucket = $days <= 30 ? '30_days' : ($days <= 60 ? '60_days' : ($days <= 90 ? '90_days' : '90_plus'));
            $data[] = [
                'course_name'     => $row['course_name'],
                'assessment_title'=> $row['assessment_title'],
                'learner_name'    => $row['learner_name'],
                'attempt_number'  => (int)$row['attempt_number'],
                'score'           => (float)$row['score'],
                'days_since_first'=> $days,
                'retention_bucket'=> $bucket
            ];
        }

        // Aggregate by bucket
        $buckets = [];
        foreach (['30_days','60_days','90_days','90_plus'] as $b) {
            $bData = array_filter($data, fn($d) => $d['retention_bucket'] === $b);
            $buckets[$b] = [
                'count'     => count($bData),
                'avg_score' => count($bData) > 0 ? round(array_sum(array_column($bData, 'score')) / count($bData), 1) : 0
            ];
        }

        return [
            'report_name' => 'Knowledge Retention',
            'description' => 'Retest scores at 30/60/90 days after initial pass',
            'chart_type'  => 'line',
            'chart_config' => ['x_axis'=>'retention_bucket','y_axis'=>'avg_score'],
            'summary' => ['retention_buckets' => $buckets, 'total_retests' => count($data)],
            'data' => $data, 'meta' => $this->buildMeta(count($data))
        ];
    }

    /* ═══════════════════════════════════════════════════
     * 3. KNOWLEDGE DECAY
     * Courses where retention drops fastest.
     * ═══════════════════════════════════════════════════ */
    public function knowledgeDecay(): array
    {
        $orgF = $this->buildOrgFilter();

        $q = "SELECT
                c.course_id AS course_id, c.title AS course_name,
                ROUND(AVG(CASE WHEN aa.attempt_number = 1 THEN aa.score END), 1) AS first_attempt_avg,
                ROUND(AVG(CASE WHEN aa.attempt_number > 1 THEN aa.score END), 1) AS retest_avg,
                ROUND(AVG(CASE WHEN aa.attempt_number = 1 THEN aa.score END) - AVG(CASE WHEN aa.attempt_number > 1 THEN aa.score END), 1) AS decay_points,
                COUNT(DISTINCT CASE WHEN aa.attempt_number > 1 THEN aa.user_id END) AS retested_learners,
                COUNT(DISTINCT CASE WHEN aa.attempt_number > 1 AND aa.score < aa2_first.first_score THEN aa.user_id END) AS declined_learners
              FROM assessment_attempts aa
              INNER JOIN assessments a ON aa.assessment_id = a.id
              INNER JOIN courses c ON a.course_id = c.course_id
              LEFT JOIN users u ON aa.user_id = u.id AND u.tenant_id = aa.tenant_id
              LEFT JOIN departments d ON u.department = d.id
              LEFT JOIN teams t ON u.team = t.id
              LEFT JOIN (
                  SELECT user_id, assessment_id, score AS first_score
                  FROM assessment_attempts WHERE attempt_number = 1 AND tenant_id = ?
              ) aa2_first ON aa2_first.user_id = aa.user_id AND aa2_first.assessment_id = aa.assessment_id
              WHERE aa.tenant_id = ? AND aa.status = 'completed' {$orgF}
              GROUP BY c.course_id, c.title
              HAVING retested_learners > 0
              ORDER BY decay_points DESC
              LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($q);
        $off = $this->getOffset();
        $stmt->bind_param("iiii", $this->tenant_id, $this->tenant_id, $this->per_page, $off);
        $stmt->execute(); $res = $stmt->get_result();

        $data = [];
        while ($row = $res->fetch_assoc()) {
            $decay = (float)($row['decay_points'] ?? 0);
            $data[] = [
                'course_id'        => (int)$row['course_id'],
                'course_name'      => $row['course_name'],
                'first_attempt_avg'=> (float)$row['first_attempt_avg'],
                'retest_avg'       => (float)$row['retest_avg'],
                'decay_points'     => $decay,
                'retested_learners'=> (int)$row['retested_learners'],
                'declined_learners'=> (int)$row['declined_learners'],
                'decay_severity'   => $decay > 15 ? 'critical' : ($decay > 5 ? 'moderate' : 'low')
            ];
        }

        return [
            'report_name' => 'Knowledge Decay Analysis',
            'description' => 'Courses where knowledge retention drops fastest',
            'chart_type'  => 'bar',
            'chart_config' => ['x_axis'=>'course_name','y_axis'=>'decay_points','color_by'=>'decay_severity'],
            'summary' => [
                'courses_analyzed' => count($data),
                'critical_decay'   => count(array_filter($data, fn($d) => $d['decay_severity'] === 'critical'))
            ],
            'data' => $data, 'meta' => $this->buildMeta(count($data))
        ];
    }

    /* ═══════════════════════════════════════════════════
     * 4. REPEAT ASSESSMENT TREND
     * Scores across multiple attempts over time.
     * ═══════════════════════════════════════════════════ */
    public function repeatAssessmentTrend(): array
    {
        $orgF = $this->buildOrgFilter();

        $q = "SELECT
                aa.attempt_number,
                COUNT(DISTINCT aa.user_id) AS learners,
                ROUND(AVG(aa.score), 1) AS avg_score,
                ROUND(MIN(aa.score), 1) AS min_score,
                ROUND(MAX(aa.score), 1) AS max_score,
                SUM(CASE WHEN aa.status = 'passed' THEN 1 ELSE 0 END) AS pass_count,
                ROUND(SUM(CASE WHEN aa.status='passed' THEN 1 ELSE 0 END)*100.0/NULLIF(COUNT(*),0),1) AS pass_rate
              FROM assessment_attempts aa
              INNER JOIN assessments a ON aa.assessment_id = a.id
              LEFT JOIN users u ON aa.user_id = u.id AND u.tenant_id = aa.tenant_id
              LEFT JOIN departments d ON u.department = d.id
              LEFT JOIN teams t ON u.team = t.id
              WHERE aa.tenant_id = ? AND aa.status IN ('completed','passed','failed') {$orgF}
              GROUP BY aa.attempt_number
              ORDER BY aa.attempt_number ASC
              LIMIT 10";

        $stmt = $this->conn->prepare($q);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute(); $res = $stmt->get_result();

        $data = [];
        while ($row = $res->fetch_assoc()) {
            $data[] = [
                'attempt_number' => (int)$row['attempt_number'],
                'learners'       => (int)$row['learners'],
                'avg_score'      => (float)$row['avg_score'],
                'min_score'      => (float)$row['min_score'],
                'max_score'      => (float)$row['max_score'],
                'pass_rate'      => (float)$row['pass_rate']
            ];
        }

        return [
            'report_name' => 'Repeat Assessment Trend',
            'description' => 'Score improvement across multiple assessment attempts',
            'chart_type'  => 'line',
            'chart_config' => ['x_axis'=>'attempt_number','y_axes'=>['avg_score','pass_rate']],
            'summary' => ['max_attempts' => count($data),
                'first_attempt_avg' => !empty($data) ? $data[0]['avg_score'] : 0,
                'score_trend' => count($data) >= 2 ? (end($data)['avg_score'] > $data[0]['avg_score'] ? 'improving' : 'declining') : 'insufficient_data'],
            'data' => $data, 'meta' => $this->buildMeta(count($data))
        ];
    }

    /* ═══════════════════════════════════════════════════
     * 5. KIRKPATRICK SUMMARY
     * Level 1-4 evaluation per training program.
     * ═══════════════════════════════════════════════════ */
    public function kirkpatrickSummary(): array
    {
        $orgF = $this->buildOrgFilter();

        // Level 1: Reaction (survey ratings)
        // Level 2: Learning (assessment score improvement)
        // Level 3: Behavior (completion & engagement)
        // Level 4: Results (available via manual input or proxy metrics)

        $q = "SELECT
                c.course_id AS course_id, c.title AS course_name,
                -- Level 1: Reaction
                (SELECT ROUND(AVG(sr2.rating_value), 2) FROM survey_responses sr2
                 INNER JOIN surveys sv2 ON sr2.survey_id=sv2.survey_id AND sv2.course_id=c.course_id
                 INNER JOIN survey_questions sq2 ON sr2.question_id=sq2.question_id AND sq2.question_type='rating'
                 WHERE sr2.tenant_id=c.tenant_id) AS l1_reaction_avg,
                -- Level 2: Learning
                ROUND(AVG(CASE WHEN a.assessment_type='post_test' THEN aa.score END), 1) AS l2_post_test_avg,
                ROUND(AVG(CASE WHEN a.assessment_type='post_test' THEN aa.score END) -
                      AVG(CASE WHEN a.assessment_type='pre_test' THEN aa.score END), 1) AS l2_improvement,
                -- Level 3: Behavior (completion rate as proxy)
                (SELECT ROUND(SUM(CASE WHEN ce2.status='completed' THEN 1 ELSE 0 END)*100.0/NULLIF(COUNT(*),0),1)
                 FROM course_enrollments ce2 WHERE ce2.course_id=c.course_id AND ce2.tenant_id=c.tenant_id) AS l3_completion_rate,
                COUNT(DISTINCT aa.user_id) AS learners_assessed
              FROM courses c
              LEFT JOIN assessments a ON a.course_id = c.course_id AND a.tenant_id = c.tenant_id
              LEFT JOIN assessment_attempts aa ON aa.assessment_id = a.course_id AND aa.tenant_id = c.tenant_id AND aa.status IN ('completed','passed')
              WHERE c.tenant_id = ? AND c.is_active = 1
              GROUP BY c.course_id, c.title
              HAVING l1_reaction_avg IS NOT NULL OR l2_post_test_avg IS NOT NULL
              ORDER BY c.title
              LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($q);
        $off = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $off);
        $stmt->execute(); $res = $stmt->get_result();

        $data = [];
        while ($row = $res->fetch_assoc()) {
            $l1 = $row['l1_reaction_avg'] ? round((float)$row['l1_reaction_avg'] * 20, 1) : null; // Scale 1-5 to 0-100
            $l2 = $row['l2_improvement'] ? min(100, max(0, (float)$row['l2_improvement'] + 50)) : null;
            $l3 = $row['l3_completion_rate'] ? (float)$row['l3_completion_rate'] : null;
            $scores = array_filter([$l1, $l2, $l3], fn($s) => $s !== null);
            $composite = count($scores) > 0 ? round(array_sum($scores) / count($scores), 1) : 0;

            $data[] = [
                'course_id'        => (int)$row['course_id'],
                'course_name'      => $row['course_name'],
                'l1_reaction'      => $row['l1_reaction_avg'] ? (float)$row['l1_reaction_avg'] : null,
                'l2_learning'      => $row['l2_post_test_avg'] ? (float)$row['l2_post_test_avg'] : null,
                'l2_improvement'   => $row['l2_improvement'] ? (float)$row['l2_improvement'] : null,
                'l3_behavior'      => $l3,
                'l4_results'       => null, // Requires external KPI data
                'composite_score'  => $composite,
                'learners_assessed'=> (int)$row['learners_assessed']
            ];
        }

        return [
            'report_name' => 'Kirkpatrick Training Evaluation',
            'description' => 'Level 1-4 evaluation per training program (Kirkpatrick model)',
            'chart_type'  => 'radar',
            'chart_config' => ['axes'=>['l1_reaction','l2_learning','l3_behavior','l4_results'],'per'=>'course_name'],
            'summary' => ['courses_evaluated' => count($data),
                'avg_composite' => count($data) > 0 ? round(array_sum(array_column($data, 'composite_score')) / count($data), 1) : 0],
            'data' => $data, 'meta' => $this->buildMeta(count($data))
        ];
    }

    /* ═══════════════════════════════════════════════════
     * 6. TRAINING IMPACT
     * Correlate training completion with performance.
     * ═══════════════════════════════════════════════════ */
    public function trainingImpact(): array
    {
        $orgF = $this->buildOrgFilter();

        $q = "SELECT
                d.name AS department_name,
                COUNT(DISTINCT u.id) AS total_employees,
                ROUND(AVG(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END)*100,1) AS training_completion_rate,
                ROUND(AVG(aa.score), 1) AS avg_assessment_score,
                COUNT(DISTINCT ce.id) AS total_enrollments,
                COUNT(DISTINCT CASE WHEN ce.status='completed' THEN ce.id END) AS completed_enrollments,
                ROUND(AVG(TIMESTAMPDIFF(DAY, ce.enrolled_at, ce.completed_at)),1) AS avg_completion_days
              FROM users u
              INNER JOIN departments d ON u.department = d.id
              LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.tenant_id = u.tenant_id
              LEFT JOIN assessments a ON a.course_id = ce.course_id AND a.tenant_id = u.tenant_id
              LEFT JOIN assessment_attempts aa ON aa.assessment_id = a.id AND aa.user_id = u.id AND aa.status IN ('completed','passed')
              LEFT JOIN teams t ON u.team = t.id
              WHERE u.tenant_id = ? AND u.is_active = 1 {$orgF}
              GROUP BY d.id, d.name
              ORDER BY training_completion_rate DESC
              LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($q);
        $off = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $off);
        $stmt->execute(); $res = $stmt->get_result();

        $data = [];
        while ($row = $res->fetch_assoc()) {
            $data[] = [
                'department_name'         => $row['department_name'],
                'total_employees'         => (int)$row['total_employees'],
                'training_completion_rate'=> (float)$row['training_completion_rate'],
                'avg_assessment_score'    => (float)($row['avg_assessment_score'] ?? 0),
                'total_enrollments'       => (int)$row['total_enrollments'],
                'completed_enrollments'   => (int)$row['completed_enrollments'],
                'avg_completion_days'     => (float)($row['avg_completion_days'] ?? 0)
            ];
        }

        return [
            'report_name' => 'Training Impact Analysis',
            'description' => 'Correlate training completion with assessment performance by department',
            'chart_type'  => 'scatter',
            'chart_config' => ['x_axis'=>'training_completion_rate','y_axis'=>'avg_assessment_score','bubble_size'=>'total_employees'],
            'summary' => ['departments_analyzed' => count($data)],
            'data' => $data, 'meta' => $this->buildMeta(count($data))
        ];
    }

    /* ═══════════════════════════════════════════════════
     * 7. TRAINING EFFECTIVENESS INDEX
     * Composite score per course combining multiple signals.
     * ═══════════════════════════════════════════════════ */
    public function trainingEffectivenessIndex(): array
    {
        $orgF = $this->buildOrgFilter();

        $q = "SELECT
                c.course_id AS course_id, c.title AS course_name,
                COUNT(DISTINCT ce.user_id) AS enrolled,
                SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END) AS completed,
                ROUND(SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END)*100.0/NULLIF(COUNT(DISTINCT ce.user_id),0),1) AS completion_rate,
                ROUND(AVG(aa.score), 1) AS avg_score,
                ROUND(AVG(sr.rating_value), 2) AS avg_feedback,
                ROUND(AVG(TIMESTAMPDIFF(MINUTE, ce.enrolled_at, ce.completed_at)), 0) AS avg_time_mins
              FROM courses c
              LEFT JOIN course_enrollments ce ON c.course_id = ce.course_id AND ce.tenant_id = c.tenant_id
              LEFT JOIN users u ON ce.user_id = u.id AND u.tenant_id = c.tenant_id
              LEFT JOIN departments d ON u.department = d.id
              LEFT JOIN teams t ON u.team = t.id
              LEFT JOIN assessments a ON a.course_id = c.course_id AND a.tenant_id = c.tenant_id
              LEFT JOIN assessment_attempts aa ON aa.assessment_id = a.id AND aa.user_id = ce.user_id AND aa.status IN ('completed','passed')
              LEFT JOIN surveys sv ON sv.course_id = c.course_id AND sv.tenant_id = c.tenant_id
              LEFT JOIN survey_responses sr ON sr.survey_id = sv.survey_id AND sr.tenant_id = c.tenant_id
              WHERE c.tenant_id = ? AND c.is_active = 1 {$orgF}
              GROUP BY c.course_id, c.title
              HAVING enrolled > 0
              ORDER BY completion_rate DESC
              LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($q);
        $off = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $off);
        $stmt->execute(); $res = $stmt->get_result();

        $data = [];
        while ($row = $res->fetch_assoc()) {
            // Composite: 40% completion + 30% score + 30% feedback
            $cr = (float)($row['completion_rate'] ?? 0);
            $sc = (float)($row['avg_score'] ?? 0);
            $fb = $row['avg_feedback'] ? (float)$row['avg_feedback'] * 20 : null; // 1-5 → 0-100
            $weights = [0.4 * $cr];
            if ($sc > 0) $weights[] = 0.3 * $sc;
            if ($fb !== null) $weights[] = 0.3 * $fb;
            $tei = count($weights) > 0 ? round(array_sum($weights) / (0.4 + ($sc > 0 ? 0.3 : 0) + ($fb !== null ? 0.3 : 0)) * 1, 1) : 0;

            $data[] = [
                'course_id'       => (int)$row['course_id'],
                'course_name'     => $row['course_name'],
                'enrolled'        => (int)$row['enrolled'],
                'completed'       => (int)$row['completed'],
                'completion_rate' => $cr,
                'avg_score'       => $sc,
                'avg_feedback'    => $row['avg_feedback'] ? (float)$row['avg_feedback'] : null,
                'avg_time_mins'   => (int)($row['avg_time_mins'] ?? 0),
                'effectiveness_index' => $tei,
                'tier' => $tei >= 75 ? 'excellent' : ($tei >= 50 ? 'good' : ($tei >= 25 ? 'fair' : 'poor'))
            ];
        }

        return [
            'report_name' => 'Training Effectiveness Index',
            'description' => 'Composite effectiveness score per course (completion + scores + feedback)',
            'chart_type'  => 'bar',
            'chart_config' => ['x_axis'=>'course_name','y_axis'=>'effectiveness_index','color_by'=>'tier'],
            'summary' => ['courses_analyzed' => count($data),
                'excellent_count' => count(array_filter($data, fn($d) => $d['tier'] === 'excellent')),
                'poor_count'      => count(array_filter($data, fn($d) => $d['tier'] === 'poor'))],
            'data' => $data, 'meta' => $this->buildMeta(count($data))
        ];
    }

    /* ═══════════════════════════════════════════════════
     * 8. TIME TO PROFICIENCY
     * Enrollment to assessment pass duration.
     * ═══════════════════════════════════════════════════ */
    public function timeToProficiency(): array
    {
        $orgF = $this->buildOrgFilter();

        $q = "SELECT
                c.course_id AS course_id, c.title AS course_name,
                u.id AS user_id, CONCAT(u.first_name,' ',u.last_name) AS learner_name,
                d.name AS department_name,
                ce.enrolled_at,
                MIN(aa.completed_at) AS first_pass_date,
                TIMESTAMPDIFF(DAY, ce.enrolled_at, MIN(aa.completed_at)) AS days_to_proficiency,
                MIN(aa.score) AS pass_score
              FROM course_enrollments ce
              INNER JOIN courses c ON ce.course_id = c.course_id
              INNER JOIN users u ON ce.user_id = u.id AND u.tenant_id = ce.tenant_id
              LEFT JOIN departments d ON u.department = d.id
              LEFT JOIN teams t ON u.team = t.id
              INNER JOIN assessments a ON a.course_id = c.course_id AND a.tenant_id = ce.tenant_id
              INNER JOIN assessment_attempts aa ON aa.assessment_id = a.id AND aa.user_id = u.id
                AND aa.status = 'passed'
              WHERE ce.tenant_id = ? {$orgF}
              GROUP BY c.course_id, c.title, u.id, u.first_name, u.last_name, d.name, ce.enrolled_at
              ORDER BY days_to_proficiency ASC
              LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($q);
        $off = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $off);
        $stmt->execute(); $res = $stmt->get_result();

        $data = [];
        while ($row = $res->fetch_assoc()) {
            $days = (int)$row['days_to_proficiency'];
            $data[] = [
                'course_id'         => (int)$row['course_id'],
                'course_name'       => $row['course_name'],
                'user_id'           => (int)$row['user_id'],
                'learner_name'      => $row['learner_name'],
                'department_name'   => $row['department_name'] ?? 'Unassigned',
                'enrolled_at'       => $row['enrolled_at'],
                'first_pass_date'   => $row['first_pass_date'],
                'days_to_proficiency'=> $days,
                'pass_score'        => (float)$row['pass_score'],
                'speed_tier'        => $days <= 7 ? 'fast' : ($days <= 30 ? 'normal' : 'slow')
            ];
        }

        return [
            'report_name' => 'Time to Proficiency',
            'description' => 'Days from enrollment to first assessment pass',
            'chart_type'  => 'bar',
            'chart_config' => ['x_axis'=>'course_name','y_axis'=>'days_to_proficiency','color_by'=>'speed_tier'],
            'summary' => [
                'learners_proficient' => count($data),
                'avg_days'            => count($data) > 0 ? round(array_sum(array_column($data, 'days_to_proficiency')) / count($data), 1) : 0,
                'fast_learners'       => count(array_filter($data, fn($d) => $d['speed_tier'] === 'fast'))
            ],
            'data' => $data, 'meta' => $this->buildMeta(count($data))
        ];
    }

    /* ═══════════════════════════════════════════════════
     * 9. POST-TRAINING PERFORMANCE
     * Assessment score changes before/after training.
     * ═══════════════════════════════════════════════════ */
    public function postTrainingPerformance(): array
    {
        $orgF = $this->buildOrgFilter();

        $q = "SELECT
                c.course_id AS course_id, c.title AS course_name,
                d.name AS department_name,
                COUNT(DISTINCT u.id) AS employees,
                ROUND(AVG(CASE WHEN aa.completed_at < ce.completed_at THEN aa.score END), 1) AS avg_score_before,
                ROUND(AVG(CASE WHEN aa.completed_at >= ce.completed_at THEN aa.score END), 1) AS avg_score_after,
                ROUND(AVG(CASE WHEN aa.completed_at >= ce.completed_at THEN aa.score END) -
                      AVG(CASE WHEN aa.completed_at < ce.completed_at THEN aa.score END), 1) AS score_change
              FROM course_enrollments ce
              INNER JOIN courses c ON ce.course_id = c.course_id
              INNER JOIN users u ON ce.user_id = u.id AND u.tenant_id = ce.tenant_id
              LEFT JOIN departments d ON u.department = d.id
              LEFT JOIN teams t ON u.team = t.id
              LEFT JOIN assessments a ON a.course_id = c.course_id AND a.tenant_id = ce.tenant_id
              LEFT JOIN assessment_attempts aa ON aa.assessment_id = a.id AND aa.user_id = u.id AND aa.status IN ('completed','passed')
              WHERE ce.tenant_id = ? AND ce.status = 'completed' {$orgF}
              GROUP BY c.course_id, c.title, d.name
              HAVING avg_score_before IS NOT NULL AND avg_score_after IS NOT NULL
              ORDER BY score_change DESC
              LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($q);
        $off = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $off);
        $stmt->execute(); $res = $stmt->get_result();

        $data = [];
        while ($row = $res->fetch_assoc()) {
            $change = (float)($row['score_change'] ?? 0);
            $data[] = [
                'course_id'        => (int)$row['course_id'],
                'course_name'      => $row['course_name'],
                'department_name'  => $row['department_name'] ?? 'All',
                'employees'        => (int)$row['employees'],
                'avg_score_before' => (float)$row['avg_score_before'],
                'avg_score_after'  => (float)$row['avg_score_after'],
                'score_change'     => $change,
                'impact'           => $change > 10 ? 'high_positive' : ($change > 0 ? 'positive' : ($change > -5 ? 'neutral' : 'negative'))
            ];
        }

        return [
            'report_name' => 'Post-Training Performance',
            'description' => 'Assessment score changes before vs after training completion',
            'chart_type'  => 'grouped_bar',
            'chart_config' => ['x_axis'=>'course_name','y_axes'=>['avg_score_before','avg_score_after']],
            'summary' => ['courses_analyzed' => count($data),
                'avg_improvement' => count($data) > 0 ? round(array_sum(array_column($data, 'score_change')) / count($data), 1) : 0],
            'data' => $data, 'meta' => $this->buildMeta(count($data))
        ];
    }
}
