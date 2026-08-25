<?php

class Assessment {

    public ?int $id = null;
    private mysqli $conn;

    public string $title;
    public string $description;
    public float $total_marks;
    public int $duration_minutes;
    public int $attempts_allowed;
    public float $pass_percentage;
    public int $created_by;

    public function __construct($db){
        $this->conn = $db;
    }

    /* ===========================================================
        CREATE NEW ASSESSMENT
    ============================================================ */
    public function create(): bool {

        $sql = "INSERT INTO assessments 
                (title, description, total_marks, duration_minutes, attempts_allowed, pass_percentage, created_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            "ssdiidi",
            $this->title,
            $this->description,
            $this->total_marks,
            $this->duration_minutes,
            $this->attempts_allowed,
            $this->pass_percentage,
            $this->created_by
        );

        return $stmt->execute();
    }
    /* ===========================================================
        GET ALL ASSESSMENTS
    ============================================================ */
    public function all(): false|mysqli_result {
        $stmt = $this->conn->prepare("SELECT * FROM assessments ORDER BY id DESC");
        $stmt->execute();
        return $stmt->get_result();
    }

    /* ===========================================================
        GET SINGLE ASSESSMENT BY ID
    ============================================================ */
    public function find(): false|mysqli_result {
        $stmt = $this->conn->prepare("SELECT * FROM assessments WHERE id = ?");
        $stmt->bind_param("i", $this->id);
        $stmt->execute();
        return $stmt->get_result();
    }

    /* ===========================================================
        UPDATE ASSESSMENT
    ============================================================ */
    public function update(): bool {

        $sql = "UPDATE assessments SET 
                title = ?, 
                description = ?, 
                total_marks = ?, 
                duration_minutes = ?, 
                attempts_allowed = ?, 
                pass_percentage = ?
                WHERE id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            "ssdiidi",
            $this->title,
            $this->description,
            $this->total_marks,
            $this->duration_minutes,
            $this->attempts_allowed,
            $this->pass_percentage,
            $this->id
        );

        return $stmt->execute();
    }

    /* ===========================================================
        DELETE ASSESSMENT
    ============================================================ */
    public function delete(): bool {
        $stmt = $this->conn->prepare("DELETE FROM assessments WHERE id = ?");
        $stmt->bind_param("i", $this->id);
        return $stmt->execute();
    }

    /* ===========================================================
        SECTIONS
    ============================================================ */

    public function getSections(): false|mysqli_result {
        $stmt = $this->conn->prepare("SELECT * FROM assessment_sections WHERE assessment_id = ? ORDER BY sort_order ASC");
        $stmt->bind_param("i", $this->id);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function addSection(string $title, string $description, float $weight, int $sort): bool {

        $sql = "INSERT INTO assessment_sections (assessment_id, title, description, weight_percentage, sort_order)
                VALUES (?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("issdi", $this->id, $title, $description, $weight, $sort);

        return $stmt->execute();
    }

    public function deleteSection(int $section_id): bool {
        $stmt = $this->conn->prepare("DELETE FROM assessment_sections WHERE id = ? AND assessment_id = ?");
        $stmt->bind_param("ii", $section_id, $this->id);
        return $stmt->execute();
    }

    /* ===========================================================
        SECTION QUESTIONS
    ============================================================ */

    public function getSectionQuestions(int $section_id): false|mysqli_result {
        $stmt = $this->conn->prepare("
            SELECT q.*, asq.points 
            FROM assessment_section_questions asq
            JOIN questions q ON q.id = asq.question_id
            WHERE asq.section_id = ?
        ");
        $stmt->bind_param("i", $section_id);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function addQuestionToSection(int $section_id, int $question_id, float $points): bool {

        $sql = "INSERT INTO assessment_section_questions (section_id, question_id, points)
                VALUES (?, ?, ?)";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iid", $section_id, $question_id, $points);

        return $stmt->execute();
    }

    public function removeQuestionFromSection(int $section_id, int $question_id): bool {
        $stmt = $this->conn->prepare("DELETE FROM assessment_section_questions WHERE section_id = ? AND question_id = ?");
        $stmt->bind_param("ii", $section_id, $question_id);
        return $stmt->execute();
    }
}

