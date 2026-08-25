<?php

class AssessmentAttempt {
    public ?int $id;
    private mysqli $conn;

    public function __construct($db){
        $this->conn = $db;
    }

    /**
     * Get all responses for an attempt
     */
    public function getAttemptResponses(): false|mysqli_result {
        $stmt = $this->conn->prepare("
            SELECT ar.id AS response_id, ar.question_id, ar.section_id, ar.user_answer,
                   ar.auto_score, ar.final_score, ar.feedback, q.question_text
            FROM attempt_responses ar
            JOIN questions q ON q.id = ar.question_id
            WHERE ar.attempt_id = ?
        ");
        $stmt->bind_param("i", $this->id);
        $stmt->execute();
        return $stmt->get_result();
    }

    /**
     * Set instructor override score and feedback for a response
     */
    public function setFinalScore(int $response_id, float $score, string $feedback = ""): bool {
        $stmt = $this->conn->prepare("
            UPDATE attempt_responses
            SET final_score = ?, feedback = ?
            WHERE id = ?
        ");

        $stmt->bind_param("dsi", $score, $feedback, $response_id);
        return $stmt->execute();
    }

    /**
     * Grade entire attempt: sum final_score (or auto_score if final_score is NULL)
     */
    public function gradeAttempt(int $instructor_id): bool {

        // Sum all scores
        $stmt = $this->conn->prepare("
            SELECT SUM(COALESCE(final_score, auto_score)) AS total
            FROM attempt_responses
            WHERE attempt_id = ?
        ");
        $stmt->bind_param("i", $this->id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $total = $result['total'] ?? 0;

        // Update attempt record (graded_by + graded_at are stored here)
        $update = $this->conn->prepare("
            UPDATE assessment_attempts
            SET total_score = ?, status = 'graded', graded_by = ?, graded_at = NOW()
            WHERE id = ?
        ");
        $update->bind_param("dii", $total, $instructor_id, $this->id);
        $update->execute();

        // Optionally mark queue as completed
        $q = $this->conn->prepare("
            UPDATE instructor_marking_queue
            SET status = 'completed'
            WHERE attempt_id = ?
        ");
        $q->bind_param("i", $this->id);
        $q->execute();

        return true;
    }

    /**
     * Get attempts pending grading for an instructor
     */
    public function getPendingGrading(int $instructor_id): false|mysqli_result {
        $stmt = $this->conn->prepare("
            SELECT amq.id AS queue_id, aa.id AS attempt_id, aa.user_id, aa.assessment_id, aa.started_at, aa.ended_at
            FROM instructor_marking_queue amq
            JOIN assessment_attempts aa ON aa.id = amq.attempt_id
            WHERE amq.instructor_id = ? AND amq.status = 'pending'
        ");
        $stmt->bind_param("i", $instructor_id);
        $stmt->execute();
        return $stmt->get_result();
    }
}
