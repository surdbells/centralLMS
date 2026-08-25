<?php
require_once __DIR__ . '/BaseReport.php';

/**
 * Survey & Feedback Reports — Phase 11
 * 6 reports for learner satisfaction, instructor evaluation, and feedback trends.
 * Requires: surveys, survey_questions, survey_responses tables.
 */
class FeedbackReports extends BaseReport
{
    public function generate(): array { return $this->learnerSatisfactionNps(); }

    private function surveyTypeFilter(string $type = ''): string {
        return $type ? "AND sv.survey_type = '" . $this->conn->real_escape_string($type) . "'" : '';
    }

    /* ═══════════════════════════════════════════════════
     * 1. LEARNER SATISFACTION NPS
     * Net Promoter Score per course and overall.
     * NPS = %Promoters(9-10) - %Detractors(0-6)
     * ═══════════════════════════════════════════════════ */
    public function learnerSatisfactionNps(): array
    {
        $orgF = $this->buildOrgFilter();
        $courseFilter = '';
        if (!empty($this->filters['course_id'])) {
            $courseFilter = 'AND sv.course_id = ' . (int)$this->filters['course_id'];
        }

        $q = "SELECT
                sv.course_id,
                COALESCE(c.title, 'Overall / General') AS course_name,
                COUNT(DISTINCT sr.user_id) AS respondents,
                COUNT(sr.response_id) AS total_responses,
                ROUND(AVG(sr.rating_value), 1) AS avg_score,
                SUM(CASE WHEN sr.rating_value >= 9 THEN 1 ELSE 0 END) AS promoters,
                SUM(CASE WHEN sr.rating_value BETWEEN 7 AND 8 THEN 1 ELSE 0 END) AS passives,
                SUM(CASE WHEN sr.rating_value <= 6 THEN 1 ELSE 0 END) AS detractors,
                ROUND(
                    (SUM(CASE WHEN sr.rating_value >= 9 THEN 1 ELSE 0 END)
                   - SUM(CASE WHEN sr.rating_value <= 6 THEN 1 ELSE 0 END))
                   * 100.0 / NULLIF(COUNT(sr.response_id), 0), 1
                ) AS nps_score
              FROM survey_responses sr
              INNER JOIN survey_questions sq ON sr.question_id = sq.question_id AND sq.question_type = 'nps'
              INNER JOIN surveys sv ON sr.survey_id = sv.survey_id AND sv.tenant_id = sr.tenant_id
              LEFT JOIN courses c ON sv.course_id = c.course_id
              LEFT JOIN users u ON sr.user_id = u.id AND u.tenant_id = sr.tenant_id
              LEFT JOIN departments d ON u.department = d.id
              LEFT JOIN teams t ON u.team = t.id
              WHERE sr.tenant_id = ? {$courseFilter} {$orgF}
              GROUP BY sv.course_id, c.title
              ORDER BY nps_score DESC
              LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($q);
        $off = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $off);
        $stmt->execute(); $res = $stmt->get_result();

        $data = [];
        while ($row = $res->fetch_assoc()) {
            $nps = (float)($row['nps_score'] ?? 0);
            $data[] = [
                'course_id'    => $row['course_id'] ? (int)$row['course_id'] : null,
                'course_name'  => $row['course_name'],
                'respondents'  => (int)$row['respondents'],
                'avg_score'    => (float)$row['avg_score'],
                'promoters'    => (int)$row['promoters'],
                'passives'     => (int)$row['passives'],
                'detractors'   => (int)$row['detractors'],
                'nps_score'    => $nps,
                'nps_category' => $nps >= 50 ? 'excellent' : ($nps >= 0 ? 'good' : 'needs_improvement'),
                'nps_badge'    => $nps >= 50 ? 'success' : ($nps >= 0 ? 'warning' : 'danger')
            ];
        }

        return [
            'report_name' => 'Learner Satisfaction NPS',
            'description' => 'Net Promoter Score per course and overall',
            'chart_type'  => 'bar',
            'chart_config' => ['x_axis'=>'course_name','y_axis'=>'nps_score','color_by'=>'nps_category'],
            'summary' => [
                'overall_nps'        => count($data) > 0 ? round(array_sum(array_column($data, 'nps_score')) / count($data), 1) : 0,
                'total_respondents'  => array_sum(array_column($data, 'respondents')),
                'excellent_courses'  => count(array_filter($data, fn($d) => $d['nps_category'] === 'excellent')),
                'needs_improvement'  => count(array_filter($data, fn($d) => $d['nps_category'] === 'needs_improvement'))
            ],
            'data' => $data, 'meta' => $this->buildMeta(count($data))
        ];
    }

    /* ═══════════════════════════════════════════════════
     * 2. COURSE FEEDBACK SUMMARY
     * Ratings + qualitative feedback per course.
     * ═══════════════════════════════════════════════════ */
    public function courseFeedbackSummary(): array
    {
        $orgF = $this->buildOrgFilter();
        $courseFilter = '';
        if (!empty($this->filters['course_id'])) {
            $courseFilter = 'AND sv.course_id = ' . (int)$this->filters['course_id'];
        }

        $q = "SELECT
                sv.course_id, c.title AS course_name,
                sq.question_id, sq.question_text, sq.question_type,
                COUNT(sr.response_id) AS response_count,
                ROUND(AVG(sr.rating_value), 2) AS avg_rating,
                MIN(sr.rating_value) AS min_rating,
                MAX(sr.rating_value) AS max_rating,
                SUM(CASE WHEN sr.rating_value >= 4 THEN 1 ELSE 0 END) AS positive_count,
                SUM(CASE WHEN sr.rating_value <= 2 THEN 1 ELSE 0 END) AS negative_count
              FROM survey_responses sr
              INNER JOIN survey_questions sq ON sr.question_id = sq.question_id
              INNER JOIN surveys sv ON sr.survey_id = sv.survey_id AND sv.survey_type = 'course_feedback'
              LEFT JOIN courses c ON sv.course_id = c.course_id
              LEFT JOIN users u ON sr.user_id = u.id AND u.tenant_id = sr.tenant_id
              LEFT JOIN departments d ON u.department = d.id
              LEFT JOIN teams t ON u.team = t.id
              WHERE sr.tenant_id = ? {$courseFilter} {$orgF}
              GROUP BY sv.course_id, c.title, sq.question_id, sq.question_text, sq.question_type
              ORDER BY c.title, sq.sort_order
              LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($q);
        $off = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $off);
        $stmt->execute(); $res = $stmt->get_result();

        $data = [];
        while ($row = $res->fetch_assoc()) {
            $satisfaction = (int)$row['response_count'] > 0
                ? round((int)$row['positive_count'] * 100.0 / (int)$row['response_count'], 1) : 0;
            $data[] = [
                'course_id'       => (int)$row['course_id'],
                'course_name'     => $row['course_name'],
                'question_id'     => (int)$row['question_id'],
                'question_text'   => $row['question_text'],
                'question_type'   => $row['question_type'],
                'response_count'  => (int)$row['response_count'],
                'avg_rating'      => (float)$row['avg_rating'],
                'min_rating'      => (int)$row['min_rating'],
                'max_rating'      => (int)$row['max_rating'],
                'positive_count'  => (int)$row['positive_count'],
                'negative_count'  => (int)$row['negative_count'],
                'satisfaction_pct'=> $satisfaction
            ];
        }

        return [
            'report_name' => 'Course Feedback Summary',
            'description' => 'Ratings and qualitative feedback per course per question',
            'chart_type'  => 'grouped_bar',
            'chart_config' => ['x_axis'=>'question_text','y_axis'=>'avg_rating','group_by'=>'course_name'],
            'summary' => [
                'total_responses' => array_sum(array_column($data, 'response_count')),
                'avg_satisfaction' => count($data) > 0 ? round(array_sum(array_column($data, 'satisfaction_pct')) / count($data), 1) : 0
            ],
            'data' => $data, 'meta' => $this->buildMeta(count($data))
        ];
    }

    /* ═══════════════════════════════════════════════════
     * 3. INSTRUCTOR EVALUATION
     * Learner ratings for ILT instructors.
     * ═══════════════════════════════════════════════════ */
    public function instructorEvaluation(): array
    {
        $orgF = $this->buildOrgFilter();

        $q = "SELECT
                sv.session_id,
                COALESCE(s.title, CONCAT('Session #', sv.session_id)) AS session_title,
                s.instructor_id,
                CONCAT(instr.first_name, ' ', instr.last_name) AS instructor_name,
                COUNT(DISTINCT sr.user_id) AS evaluators,
                ROUND(AVG(CASE WHEN sq.question_type = 'rating' THEN sr.rating_value END), 2) AS avg_rating,
                COUNT(CASE WHEN sq.question_type = 'text' AND sr.text_value IS NOT NULL THEN 1 END) AS text_feedback_count,
                SUM(CASE WHEN sr.rating_value >= 4 THEN 1 ELSE 0 END) AS positive,
                SUM(CASE WHEN sr.rating_value <= 2 THEN 1 ELSE 0 END) AS negative,
                MIN(sr.submitted_at) AS first_eval,
                MAX(sr.submitted_at) AS last_eval
              FROM survey_responses sr
              INNER JOIN survey_questions sq ON sr.question_id = sq.question_id
              INNER JOIN surveys sv ON sr.survey_id = sv.survey_id AND sv.survey_type = 'instructor_eval'
              LEFT JOIN ilt_sessions s ON sv.session_id = s.id
              LEFT JOIN users instr ON s.instructor_id = instr.id
              LEFT JOIN users u ON sr.user_id = u.id AND u.tenant_id = sr.tenant_id
              LEFT JOIN departments d ON u.department = d.id
              LEFT JOIN teams t ON u.team = t.id
              WHERE sr.tenant_id = ? {$orgF}
              GROUP BY sv.session_id, s.title, s.instructor_id, instr.first_name, instr.last_name
              ORDER BY avg_rating DESC
              LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($q);
        $off = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $off);
        $stmt->execute(); $res = $stmt->get_result();

        $data = [];
        while ($row = $res->fetch_assoc()) {
            $rating = (float)$row['avg_rating'];
            $data[] = [
                'session_id'      => (int)$row['session_id'],
                'session_title'   => $row['session_title'],
                'instructor_id'   => (int)$row['instructor_id'],
                'instructor_name' => $row['instructor_name'] ?? 'Unknown',
                'evaluators'      => (int)$row['evaluators'],
                'avg_rating'      => $rating,
                'rating_badge'    => $rating >= 4.0 ? 'success' : ($rating >= 3.0 ? 'warning' : 'danger'),
                'positive'        => (int)$row['positive'],
                'negative'        => (int)$row['negative'],
                'text_feedback_count' => (int)$row['text_feedback_count'],
                'eval_period'     => $row['first_eval'] . ' to ' . $row['last_eval']
            ];
        }

        return [
            'report_name' => 'Instructor Evaluation',
            'description' => 'Learner ratings and feedback for ILT instructors',
            'chart_type'  => 'horizontal_bar',
            'chart_config' => ['x_axis'=>'avg_rating','y_axis'=>'instructor_name','color_by'=>'rating_badge'],
            'summary' => [
                'total_instructors' => count(array_unique(array_column($data, 'instructor_id'))),
                'avg_rating'        => count($data) > 0 ? round(array_sum(array_column($data, 'avg_rating')) / count($data), 2) : 0,
                'top_rated'         => !empty($data) ? $data[0]['instructor_name'] : 'N/A'
            ],
            'data' => $data, 'meta' => $this->buildMeta(count($data))
        ];
    }

    /* ═══════════════════════════════════════════════════
     * 4. TRAINING EFFECTIVENESS SURVEY
     * Post-training survey results by course/department.
     * ═══════════════════════════════════════════════════ */
    public function trainingEffectivenessSurvey(): array
    {
        $orgF = $this->buildOrgFilter();

        $q = "SELECT
                sv.course_id, c.title AS course_name,
                d.name AS department_name,
                COUNT(DISTINCT sr.user_id) AS respondents,
                ROUND(AVG(sr.rating_value), 2) AS avg_effectiveness,
                SUM(CASE WHEN sr.rating_value >= 4 THEN 1 ELSE 0 END) AS effective_count,
                SUM(CASE WHEN sr.rating_value <= 2 THEN 1 ELSE 0 END) AS ineffective_count,
                ROUND(SUM(CASE WHEN sr.rating_value >= 4 THEN 1 ELSE 0 END)*100.0/NULLIF(COUNT(sr.response_id),0),1) AS effectiveness_pct
              FROM survey_responses sr
              INNER JOIN survey_questions sq ON sr.question_id = sq.question_id AND sq.question_type = 'rating'
              INNER JOIN surveys sv ON sr.survey_id = sv.survey_id AND sv.survey_type = 'effectiveness'
              LEFT JOIN courses c ON sv.course_id = c.course_id
              LEFT JOIN users u ON sr.user_id = u.id AND u.tenant_id = sr.tenant_id
              LEFT JOIN departments d ON u.department = d.id
              LEFT JOIN teams t ON u.team = t.id
              WHERE sr.tenant_id = ? {$orgF}
              GROUP BY sv.course_id, c.title, d.name
              ORDER BY effectiveness_pct DESC
              LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($q);
        $off = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $off);
        $stmt->execute(); $res = $stmt->get_result();

        $data = [];
        while ($row = $res->fetch_assoc()) {
            $data[] = [
                'course_id'         => (int)$row['course_id'],
                'course_name'       => $row['course_name'],
                'department_name'   => $row['department_name'] ?? 'All',
                'respondents'       => (int)$row['respondents'],
                'avg_effectiveness' => (float)$row['avg_effectiveness'],
                'effective_count'   => (int)$row['effective_count'],
                'ineffective_count' => (int)$row['ineffective_count'],
                'effectiveness_pct' => (float)$row['effectiveness_pct']
            ];
        }

        return [
            'report_name' => 'Training Effectiveness Survey',
            'description' => 'Post-training survey results by course and department',
            'chart_type'  => 'bar',
            'chart_config' => ['x_axis'=>'course_name','y_axis'=>'effectiveness_pct'],
            'summary' => [
                'avg_effectiveness' => count($data) > 0 ? round(array_sum(array_column($data, 'effectiveness_pct')) / count($data), 1) : 0,
                'total_respondents' => array_sum(array_column($data, 'respondents'))
            ],
            'data' => $data, 'meta' => $this->buildMeta(count($data))
        ];
    }

    /* ═══════════════════════════════════════════════════
     * 5. FEEDBACK TRENDS
     * Satisfaction scores trending over time.
     * ═══════════════════════════════════════════════════ */
    public function feedbackTrends(): array
    {
        $orgF = $this->buildOrgFilter();
        $pExpr = ($this->filters['period'] ?? 'month') === 'quarter'
            ? "CONCAT(YEAR(sr.submitted_at),'-Q',QUARTER(sr.submitted_at))"
            : "DATE_FORMAT(sr.submitted_at,'%Y-%m')";

        $q = "SELECT
                {$pExpr} AS period,
                COUNT(DISTINCT sr.user_id) AS respondents,
                COUNT(sr.response_id) AS total_responses,
                ROUND(AVG(sr.rating_value), 2) AS avg_rating,
                ROUND(AVG(CASE WHEN sq.question_type='nps' THEN sr.rating_value END), 2) AS avg_nps_raw,
                SUM(CASE WHEN sr.rating_value >= 4 THEN 1 ELSE 0 END) AS positive,
                SUM(CASE WHEN sr.rating_value <= 2 THEN 1 ELSE 0 END) AS negative
              FROM survey_responses sr
              INNER JOIN survey_questions sq ON sr.question_id = sq.question_id
              INNER JOIN surveys sv ON sr.survey_id = sv.survey_id
              LEFT JOIN users u ON sr.user_id = u.id AND u.tenant_id = sr.tenant_id
              LEFT JOIN departments d ON u.department = d.id
              LEFT JOIN teams t ON u.team = t.id
              WHERE sr.tenant_id = ?
                AND sr.submitted_at >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
                {$orgF}
              GROUP BY period ORDER BY period ASC";

        $stmt = $this->conn->prepare($q);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute(); $res = $stmt->get_result();

        $data = [];
        while ($row = $res->fetch_assoc()) {
            $total = (int)$row['total_responses'];
            $data[] = [
                'period'        => $row['period'],
                'respondents'   => (int)$row['respondents'],
                'total_responses' => $total,
                'avg_rating'    => (float)$row['avg_rating'],
                'avg_nps_raw'   => $row['avg_nps_raw'] ? (float)$row['avg_nps_raw'] : null,
                'positive_pct'  => $total > 0 ? round((int)$row['positive'] * 100.0 / $total, 1) : 0,
                'negative_pct'  => $total > 0 ? round((int)$row['negative'] * 100.0 / $total, 1) : 0
            ];
        }

        return [
            'report_name' => 'Feedback Trends',
            'description' => 'Satisfaction scores trending over time',
            'chart_type'  => 'line',
            'chart_config' => ['x_axis'=>'period','y_axes'=>['avg_rating','positive_pct']],
            'summary' => ['total_periods' => count($data),
                'trend_direction' => count($data) >= 2 ? (end($data)['avg_rating'] > $data[0]['avg_rating'] ? 'improving' : 'declining') : 'insufficient_data'],
            'data' => $data, 'meta' => $this->buildMeta(count($data))
        ];
    }

    /* ═══════════════════════════════════════════════════
     * 6. LEARNER SENTIMENT
     * Aggregate feedback themes from text responses.
     * Groups by keyword frequency in text feedback.
     * ═══════════════════════════════════════════════════ */
    public function learnerSentiment(): array
    {
        $orgF = $this->buildOrgFilter();

        // Get text responses with their associated rating context
        $q = "SELECT
                sv.course_id, c.title AS course_name,
                sr.text_value,
                sr.rating_value,
                sr.submitted_at
              FROM survey_responses sr
              INNER JOIN survey_questions sq ON sr.question_id = sq.question_id AND sq.question_type = 'text'
              INNER JOIN surveys sv ON sr.survey_id = sv.survey_id
              LEFT JOIN courses c ON sv.course_id = c.course_id
              LEFT JOIN users u ON sr.user_id = u.id AND u.tenant_id = sr.tenant_id
              LEFT JOIN departments d ON u.department = d.id
              LEFT JOIN teams t ON u.team = t.id
              WHERE sr.tenant_id = ? AND sr.text_value IS NOT NULL AND sr.text_value != ''
                {$orgF}
              ORDER BY sr.submitted_at DESC
              LIMIT 500";

        $stmt = $this->conn->prepare($q);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute(); $res = $stmt->get_result();

        // Simple keyword-based sentiment (server-side, no ML needed)
        $positive_kw = ['excellent','great','helpful','clear','engaging','informative','practical','useful','enjoyed','recommend','good','well'];
        $negative_kw = ['boring','confusing','unclear','too long','difficult','waste','outdated','irrelevant','poor','slow','bad','hard'];

        $themes = ['positive' => [], 'negative' => [], 'neutral' => []];
        $courseBreakdown = [];
        $total = 0;

        while ($row = $res->fetch_assoc()) {
            $text = strtolower($row['text_value']);
            $total++;
            $sentiment = 'neutral';
            $posHits = 0; $negHits = 0;
            foreach ($positive_kw as $kw) { if (strpos($text, $kw) !== false) $posHits++; }
            foreach ($negative_kw as $kw) { if (strpos($text, $kw) !== false) $negHits++; }
            if ($posHits > $negHits) $sentiment = 'positive';
            elseif ($negHits > $posHits) $sentiment = 'negative';

            $themes[$sentiment][] = [
                'course_name' => $row['course_name'] ?? 'General',
                'excerpt'     => mb_substr($row['text_value'], 0, 150),
                'submitted_at'=> $row['submitted_at']
            ];

            $cid = $row['course_id'] ?? 0;
            if (!isset($courseBreakdown[$cid])) $courseBreakdown[$cid] = ['course_name'=>$row['course_name']??'General','positive'=>0,'negative'=>0,'neutral'=>0,'total'=>0];
            $courseBreakdown[$cid][$sentiment]++;
            $courseBreakdown[$cid]['total']++;
        }

        $data = [];
        foreach ($courseBreakdown as $cid => $cb) {
            $data[] = [
                'course_id'    => $cid ?: null,
                'course_name'  => $cb['course_name'],
                'positive'     => $cb['positive'],
                'negative'     => $cb['negative'],
                'neutral'      => $cb['neutral'],
                'total'        => $cb['total'],
                'sentiment_score' => $cb['total'] > 0 ? round(($cb['positive'] - $cb['negative']) * 100.0 / $cb['total'], 1) : 0
            ];
        }
        usort($data, fn($a, $b) => $b['sentiment_score'] <=> $a['sentiment_score']);

        return [
            'report_name' => 'Learner Sentiment Analysis',
            'description' => 'Aggregate feedback themes from text responses with keyword-based sentiment',
            'chart_type'  => 'stacked_bar',
            'chart_config' => ['x_axis'=>'course_name','y_axes'=>['positive','neutral','negative'],'stacked'=>true],
            'summary' => [
                'total_text_responses' => $total,
                'positive_pct' => $total > 0 ? round(count($themes['positive']) * 100.0 / $total, 1) : 0,
                'negative_pct' => $total > 0 ? round(count($themes['negative']) * 100.0 / $total, 1) : 0,
                'recent_positive' => array_slice($themes['positive'], 0, 3),
                'recent_negative' => array_slice($themes['negative'], 0, 3)
            ],
            'data' => $data, 'meta' => $this->buildMeta(count($data))
        ];
    }
}
