<?php
/**
 * Grading Service
 * Handles auto-grading for all question types and manual grading workflow
 */

namespace App\Services\ProctoredAssessment;

class GradingService
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Auto-grade an answer based on question type
     */
    public function gradeAnswer($questionId, $answerData, $tenantId)
    {
        // Get question with options
        $sql = "SELECT * FROM proc_questions WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $questionId, 'tenant_id' => $tenantId]);
        $question = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$question) {
            return $this->createGradeResult(false, false, 0, $question['points'], false, 'Question not found');
        }

        // Get options
        $sql = "SELECT * FROM proc_question_options WHERE question_id = :id ORDER BY sort_order";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $questionId]);
        $options = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $points = (float)$question['points'];
        $negativePoints = (float)$question['negative_points'];
        $partialCredit = (bool)$question['partial_credit'];

        switch ($question['question_type']) {
            case 'multiple_choice_single':
                return $this->gradeMcqSingle($answerData, $options, $points, $negativePoints);

            case 'multiple_choice_multiple':
                return $this->gradeMcqMultiple($answerData, $options, $points, $negativePoints, $partialCredit);

            case 'true_false':
                return $this->gradeTrueFalse($answerData, $options, $points, $negativePoints);

            case 'fill_in_blanks':
                return $this->gradeFillBlanks($answerData, $options, $points, $question['case_sensitive'], $partialCredit);

            case 'short_answer':
                return $this->gradeShortAnswer($answerData, $options, $points, $question['case_sensitive'], $question['allow_regex']);

            case 'matching':
                return $this->gradeMatching($answerData, $options, $points, $partialCredit);

            case 'ordering':
                return $this->gradeOrdering($answerData, $options, $points, $partialCredit);

            case 'hotspot':
                return $this->gradeHotspot($answerData, $options, $question);

            case 'numeric':
                return $this->gradeNumeric($answerData, $options, $question);

            case 'long_answer':
            case 'file_upload':
                // Requires manual grading
                return $this->createGradeResult(null, false, 0, $points, false, 'Requires manual grading');

            default:
                return $this->createGradeResult(null, false, 0, $points, false, 'Unknown question type');
        }
    }

    /**
     * Grade Multiple Choice Single
     */
    private function gradeMcqSingle($answer, $options, $points, $negativePoints)
    {
        $selectedId = $answer['selected_option_id'] ?? null;
        
        if (!$selectedId) {
            return $this->createGradeResult(null, false, 0, $points, true, 'No answer provided');
        }

        $correctOption = null;
        $selectedOption = null;

        foreach ($options as $opt) {
            if ($opt['is_correct']) {
                $correctOption = $opt;
            }
            if ($opt['id'] == $selectedId) {
                $selectedOption = $opt;
            }
        }

        if (!$correctOption) {
            return $this->createGradeResult(false, false, 0, $points, true, 'No correct answer defined');
        }

        $isCorrect = $selectedId == $correctOption['id'];
        $pointsEarned = $isCorrect ? $points : ($negativePoints > 0 ? -$negativePoints : 0);
        $feedback = $selectedOption['feedback'] ?? ($isCorrect ? 'Correct!' : 'Incorrect');

        return $this->createGradeResult($isCorrect, false, $pointsEarned, $points, true, $feedback);
    }

    /**
     * Grade Multiple Choice Multiple
     */
    private function gradeMcqMultiple($answer, $options, $points, $negativePoints, $partialCredit)
    {
        $selectedIds = $answer['selected_option_ids'] ?? [];
        
        if (empty($selectedIds)) {
            return $this->createGradeResult(null, false, 0, $points, true, 'No answer provided');
        }

        $correctIds = [];
        $incorrectIds = [];

        foreach ($options as $opt) {
            if ($opt['is_correct']) {
                $correctIds[] = $opt['id'];
            } else {
                $incorrectIds[] = $opt['id'];
            }
        }

        $selectedCorrect = array_intersect($selectedIds, $correctIds);
        $selectedIncorrect = array_intersect($selectedIds, $incorrectIds);
        $missedCorrect = array_diff($correctIds, $selectedIds);

        // All correct and no incorrect = full marks
        $isAllCorrect = count($selectedCorrect) == count($correctIds) && empty($selectedIncorrect);

        if ($isAllCorrect) {
            return $this->createGradeResult(true, false, $points, $points, true, 'All correct!');
        }

        if ($partialCredit) {
            // Partial credit: (correct selected - incorrect selected) / total correct
            $correctCount = count($selectedCorrect);
            $incorrectCount = count($selectedIncorrect);
            $totalCorrect = count($correctIds);

            $partialScore = max(0, ($correctCount - $incorrectCount) / $totalCorrect);
            $pointsEarned = round($points * $partialScore, 2);
            $isPartial = $pointsEarned > 0;

            return $this->createGradeResult(false, $isPartial, $pointsEarned, $points, true, 
                "Partial credit: {$correctCount} of {$totalCorrect} correct answers selected");
        }

        // All or nothing
        $pointsEarned = $negativePoints > 0 ? -$negativePoints : 0;
        return $this->createGradeResult(false, false, $pointsEarned, $points, true, 'Incorrect');
    }

    /**
     * Grade True/False
     */
    private function gradeTrueFalse($answer, $options, $points, $negativePoints)
    {
        if (!isset($answer['answer'])) {
            return $this->createGradeResult(null, false, 0, $points, true, 'No answer provided');
        }

        $userAnswer = (bool)$answer['answer'];
        $correctAnswer = null;

        foreach ($options as $opt) {
            if ($opt['is_correct']) {
                $correctAnswer = strtolower($opt['option_text']) === 'true';
                break;
            }
        }

        if ($correctAnswer === null) {
            return $this->createGradeResult(false, false, 0, $points, true, 'No correct answer defined');
        }

        $isCorrect = $userAnswer === $correctAnswer;
        $pointsEarned = $isCorrect ? $points : ($negativePoints > 0 ? -$negativePoints : 0);

        return $this->createGradeResult($isCorrect, false, $pointsEarned, $points, true, 
            $isCorrect ? 'Correct!' : 'Incorrect');
    }

    /**
     * Grade Fill in Blanks
     */
    private function gradeFillBlanks($answer, $options, $points, $caseSensitive, $partialCredit)
    {
        $blanks = $answer['blanks'] ?? [];
        
        if (empty($blanks)) {
            return $this->createGradeResult(null, false, 0, $points, true, 'No answer provided');
        }

        $totalBlanks = 0;
        $correctBlanks = 0;

        foreach ($options as $opt) {
            if ($opt['blank_index'] !== null) {
                $totalBlanks++;
                $blankIndex = $opt['blank_index'];
                $userAnswer = $blanks[$blankIndex] ?? '';
                
                // Get acceptable answers
                $acceptableAnswers = json_decode($opt['acceptable_answers'], true) ?? [$opt['option_text']];
                
                $isBlankCorrect = false;
                foreach ($acceptableAnswers as $acceptable) {
                    if ($caseSensitive) {
                        if (trim($userAnswer) === trim($acceptable)) {
                            $isBlankCorrect = true;
                            break;
                        }
                    } else {
                        if (strtolower(trim($userAnswer)) === strtolower(trim($acceptable))) {
                            $isBlankCorrect = true;
                            break;
                        }
                    }
                }

                if ($isBlankCorrect) {
                    $correctBlanks++;
                }
            }
        }

        if ($totalBlanks == 0) {
            return $this->createGradeResult(false, false, 0, $points, true, 'No blanks defined');
        }

        $isAllCorrect = $correctBlanks == $totalBlanks;
        
        if ($isAllCorrect) {
            return $this->createGradeResult(true, false, $points, $points, true, 'All blanks correct!');
        }

        if ($partialCredit && $correctBlanks > 0) {
            $pointsEarned = round($points * ($correctBlanks / $totalBlanks), 2);
            return $this->createGradeResult(false, true, $pointsEarned, $points, true, 
                "{$correctBlanks} of {$totalBlanks} blanks correct");
        }

        return $this->createGradeResult(false, false, 0, $points, true, 'Incorrect');
    }

    /**
     * Grade Short Answer
     */
    private function gradeShortAnswer($answer, $options, $points, $caseSensitive, $allowRegex)
    {
        $userAnswer = trim($answer['text'] ?? '');
        
        if (empty($userAnswer)) {
            return $this->createGradeResult(null, false, 0, $points, true, 'No answer provided');
        }

        foreach ($options as $opt) {
            $acceptableAnswers = json_decode($opt['acceptable_answers'], true) ?? [$opt['option_text']];
            
            foreach ($acceptableAnswers as $acceptable) {
                $acceptable = trim($acceptable);
                
                if ($allowRegex && preg_match('/^\/.*\/$/', $acceptable)) {
                    // Regex pattern
                    $pattern = $caseSensitive ? $acceptable : $acceptable . 'i';
                    if (preg_match($pattern, $userAnswer)) {
                        return $this->createGradeResult(true, false, $points, $points, true, 'Correct!');
                    }
                } else {
                    // Exact match
                    if ($caseSensitive) {
                        if ($userAnswer === $acceptable) {
                            return $this->createGradeResult(true, false, $points, $points, true, 'Correct!');
                        }
                    } else {
                        if (strtolower($userAnswer) === strtolower($acceptable)) {
                            return $this->createGradeResult(true, false, $points, $points, true, 'Correct!');
                        }
                    }
                }
            }
        }

        return $this->createGradeResult(false, false, 0, $points, true, 'Incorrect');
    }

    /**
     * Grade Matching
     */
    private function gradeMatching($answer, $options, $points, $partialCredit)
    {
        $matches = $answer['matches'] ?? [];
        
        if (empty($matches)) {
            return $this->createGradeResult(null, false, 0, $points, true, 'No answer provided');
        }

        $totalPairs = count($options);
        $correctPairs = 0;

        foreach ($options as $opt) {
            $optionId = $opt['id'];
            $correctMatch = $opt['match_text'];
            $userMatch = $matches[$optionId] ?? null;

            if ($userMatch !== null && strtolower(trim($userMatch)) === strtolower(trim($correctMatch))) {
                $correctPairs++;
            }
        }

        $isAllCorrect = $correctPairs == $totalPairs;

        if ($isAllCorrect) {
            return $this->createGradeResult(true, false, $points, $points, true, 'All pairs matched correctly!');
        }

        if ($partialCredit && $correctPairs > 0) {
            $pointsEarned = round($points * ($correctPairs / $totalPairs), 2);
            return $this->createGradeResult(false, true, $pointsEarned, $points, true, 
                "{$correctPairs} of {$totalPairs} pairs matched correctly");
        }

        return $this->createGradeResult(false, false, 0, $points, true, 'Incorrect');
    }

    /**
     * Grade Ordering
     */
    private function gradeOrdering($answer, $options, $points, $partialCredit)
    {
        $order = $answer['order'] ?? [];
        
        if (empty($order)) {
            return $this->createGradeResult(null, false, 0, $points, true, 'No answer provided');
        }

        // Build correct order
        $correctOrder = [];
        foreach ($options as $opt) {
            $correctOrder[$opt['correct_position']] = $opt['id'];
        }
        ksort($correctOrder);
        $correctOrder = array_values($correctOrder);

        $totalItems = count($correctOrder);
        $correctPositions = 0;

        for ($i = 0; $i < $totalItems && $i < count($order); $i++) {
            if ($order[$i] == $correctOrder[$i]) {
                $correctPositions++;
            }
        }

        $isAllCorrect = $correctPositions == $totalItems;

        if ($isAllCorrect) {
            return $this->createGradeResult(true, false, $points, $points, true, 'Correct order!');
        }

        if ($partialCredit && $correctPositions > 0) {
            $pointsEarned = round($points * ($correctPositions / $totalItems), 2);
            return $this->createGradeResult(false, true, $pointsEarned, $points, true, 
                "{$correctPositions} of {$totalItems} items in correct position");
        }

        return $this->createGradeResult(false, false, 0, $points, true, 'Incorrect order');
    }

    /**
     * Grade Hotspot
     */
    private function gradeHotspot($answer, $options, $question)
    {
        $coords = $answer['clicked_coords'] ?? null;
        $points = (float)$question['points'];
        
        if (!$coords || !isset($coords['x']) || !isset($coords['y'])) {
            return $this->createGradeResult(null, false, 0, $points, true, 'No answer provided');
        }

        $x = (float)$coords['x'];
        $y = (float)$coords['y'];
        $shape = $question['hotspot_shape'];

        foreach ($options as $opt) {
            if (!$opt['is_correct']) continue;
            
            $hotspotCoords = json_decode($opt['hotspot_coords'], true);
            if (!$hotspotCoords) continue;

            $isInside = false;

            switch ($shape) {
                case 'rectangle':
                    $isInside = $this->pointInRectangle($x, $y, $hotspotCoords);
                    break;
                case 'circle':
                    $isInside = $this->pointInCircle($x, $y, $hotspotCoords);
                    break;
                case 'polygon':
                    $isInside = $this->pointInPolygon($x, $y, $hotspotCoords['points'] ?? []);
                    break;
            }

            if ($isInside) {
                return $this->createGradeResult(true, false, $points, $points, true, 'Correct area clicked!');
            }
        }

        return $this->createGradeResult(false, false, 0, $points, true, 'Incorrect area');
    }

    /**
     * Grade Numeric
     */
    private function gradeNumeric($answer, $options, $question)
    {
        $points = (float)$question['points'];
        
        if (!isset($answer['value'])) {
            return $this->createGradeResult(null, false, 0, $points, true, 'No answer provided');
        }

        $userValue = (float)$answer['value'];
        $tolerance = (float)$question['numeric_tolerance'];

        foreach ($options as $opt) {
            if (!$opt['is_correct']) continue;
            
            $correctValue = (float)$opt['option_text'];
            
            if (abs($userValue - $correctValue) <= $tolerance) {
                return $this->createGradeResult(true, false, $points, $points, true, 'Correct!');
            }
        }

        return $this->createGradeResult(false, false, 0, $points, true, 'Incorrect');
    }

    // ==================== GEOMETRY HELPERS ====================

    private function pointInRectangle($x, $y, $coords)
    {
        return $x >= $coords['x1'] && $x <= $coords['x2'] 
            && $y >= $coords['y1'] && $y <= $coords['y2'];
    }

    private function pointInCircle($x, $y, $coords)
    {
        $dx = $x - $coords['cx'];
        $dy = $y - $coords['cy'];
        return ($dx * $dx + $dy * $dy) <= ($coords['radius'] * $coords['radius']);
    }

    private function pointInPolygon($x, $y, $polygon)
    {
        $inside = false;
        $n = count($polygon);
        
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $polygon[$i]['x'];
            $yi = $polygon[$i]['y'];
            $xj = $polygon[$j]['x'];
            $yj = $polygon[$j]['y'];
            
            if ((($yi > $y) != ($yj > $y)) && 
                ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi)) {
                $inside = !$inside;
            }
        }
        
        return $inside;
    }

    // ==================== MANUAL GRADING ====================

    /**
     * Get grading queue (pending manual grades)
     */
    public function getGradingQueue($tenantId, $filters = [])
    {
        $sql = "SELECT aa.*, att.user_id, att.assessment_id,
                       q.question_type, q.question_text, q.points,
                       u.first_name, u.last_name, u.email,
                       a.title as assessment_title
                FROM proc_attempt_answers aa
                INNER JOIN proc_assessment_attempts att ON aa.attempt_id = att.id
                INNER JOIN proc_questions q ON aa.question_id = q.id
                INNER JOIN users u ON att.user_id = u.id
                INNER JOIN proc_assessments a ON att.assessment_id = a.id
                WHERE att.tenant_id = :tenant_id
                AND aa.grading_status = 'pending'
                AND q.question_type IN ('long_answer', 'file_upload')";

        $params = ['tenant_id' => $tenantId];

        if (!empty($filters['assessment_id'])) {
            $sql .= " AND att.assessment_id = :assessment_id";
            $params['assessment_id'] = $filters['assessment_id'];
        }

        if (!empty($filters['question_type'])) {
            $sql .= " AND q.question_type = :question_type";
            $params['question_type'] = $filters['question_type'];
        }

        $sql .= " ORDER BY att.submitted_at ASC";

        if (isset($filters['limit'])) {
            $offset = (($filters['page'] ?? 1) - 1) * $filters['limit'];
            $sql .= " LIMIT {$filters['limit']} OFFSET {$offset}";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Manually grade an answer
     */
    public function gradeAnswerManually($answerId, $score, $feedback, $rubricScores, $graderId)
    {
        // Get answer
        $sql = "SELECT aa.*, q.points FROM proc_attempt_answers aa
                INNER JOIN proc_questions q ON aa.question_id = q.id
                WHERE aa.id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $answerId]);
        $answer = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$answer) {
            throw new \Exception('Answer not found');
        }

        $maxPoints = (float)$answer['points'];
        $score = min($maxPoints, max(0, (float)$score));

        // Determine correctness
        $isCorrect = $score >= $maxPoints;
        $isPartial = $score > 0 && $score < $maxPoints;

        // Update answer
        $sql = "UPDATE proc_attempt_answers SET
                    manual_score = :score,
                    points_earned = :points_earned,
                    is_correct = :is_correct,
                    is_partial = :is_partial,
                    grader_feedback = :feedback,
                    rubric_scores = :rubric_scores,
                    grading_status = 'manually_graded',
                    graded_by = :grader_id,
                    graded_at = NOW()
                WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $answerId,
            'score' => $score,
            'points_earned' => $score,
            'is_correct' => $isCorrect ? 1 : 0,
            'is_partial' => $isPartial ? 1 : 0,
            'feedback' => $feedback,
            'rubric_scores' => $rubricScores ? json_encode($rubricScores) : null,
            'grader_id' => $graderId
        ]);

        // Recalculate attempt scores
        $this->recalculateAttemptScore($answer['attempt_id']);

        return true;
    }

    /**
     * Recalculate attempt score after grading
     */
    public function recalculateAttemptScore($attemptId)
    {
        // Get all answers
        $sql = "SELECT SUM(points_earned) as total_earned, 
                       SUM(max_points) as total_possible,
                       COUNT(CASE WHEN is_correct = 1 THEN 1 END) as correct_count,
                       COUNT(CASE WHEN is_partial = 1 THEN 1 END) as partial_count,
                       COUNT(CASE WHEN is_correct = 0 AND is_partial = 0 AND answer_data IS NOT NULL THEN 1 END) as incorrect_count
                FROM proc_attempt_answers WHERE attempt_id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $attemptId]);
        $stats = $stmt->fetch(\PDO::FETCH_ASSOC);

        $percentage = $stats['total_possible'] > 0 
            ? ($stats['total_earned'] / $stats['total_possible']) * 100 
            : 0;

        // Get assessment for passing score
        $sql = "SELECT a.* FROM proc_assessments a 
                INNER JOIN proc_attempts att ON a.assessment_id = att.assessment_id
                WHERE att.attempt_id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $attemptId]);
        $assessment = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Determine pass/fail
        $passed = $assessment['passing_type'] === 'percentage'
            ? $percentage >= $assessment['passing_score']
            : $stats['total_earned'] >= $assessment['passing_score'];

        // Calculate grade
        $grade = null;
        if ($assessment['enable_grading'] && $assessment['grade_boundaries']) {
            $boundaries = json_decode($assessment['grade_boundaries'], true);
            foreach ($boundaries as $boundary) {
                if ($percentage >= $boundary['min']) {
                    $grade = $boundary['grade'];
                    break;
                }
            }
        }

        // Determine status
        $status = $stats['pending_count'] > 0 ? 'grading' : 'graded';

        // Update attempt
        $sql = "UPDATE proc_attempts SET
                    raw_score = :raw_score,
                    percentage_score = :percentage,
                    correct_answers = :correct,
                    partial_answers = :partial,
                    wrong_answers = :incorrect,
                    pending_grading = :pending,
                    passed = :passed,
                    letter_grade = :grade,
                    status = :status,
                    graded_at = CASE WHEN :status = 'graded' THEN NOW() ELSE graded_at END
                WHERE attempt_id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $attemptId,
            'raw_score' => $stats['total_earned'],
            'percentage' => round($percentage, 2),
            'correct' => $stats['cert.issue_date'],
            'partial' => $stats['partial_count'],
            'incorrect' => $stats['incorrect_count'],
            'pending' => $stats['pending_count'],
            'passed' => $passed ? 1 : 0,
            'grade' => $grade,
            'status' => $status
        ]);

        return true;
    }

    // ==================== HELPER ====================

    private function createGradeResult($isCorrect, $isPartial, $pointsEarned, $pointsPossible, $autoGraded, $feedback = null)
    {
        return [
            'is_correct' => $isCorrect,
            'is_partial' => $isPartial,
            'points_earned' => max(0, $pointsEarned),
            'points_possible' => $pointsPossible,
            'auto_graded' => $autoGraded,
            'feedback' => $feedback
        ];
    }
}
