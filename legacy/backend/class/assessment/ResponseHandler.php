<?php
// ResponseHandler.php
class ResponseHandler {
    private mysqli $conn;

    public function __construct($db){
        $this->conn = $db;
    }

    /* Persist a response row (or update if exists) */
    public function saveResponse(int $attempt_id, int $question_id, ?string $response_text = null, ?string $response_file = null, int $time_spent_seconds = 0): bool {
        // If a response exists for attempt+question, update; else insert.
        $check = $this->conn->prepare("SELECT id FROM attempt_responses WHERE attempt_id = ? AND question_id = ? LIMIT 1");
        if (!$check) return false;
        $check->bind_param("ii", $attempt_id, $question_id);
        $check->execute();
        $res = $check->get_result();
        if ($res && $row = $res->fetch_assoc()) {
            $id = (int)$row['id'];
            $stmt = $this->conn->prepare("UPDATE attempt_responses SET response_text = ?, response_file = ?, time_spent_seconds = ? WHERE id = ?");
            if (!$stmt) return false;
            $stmt->bind_param("ssii", $response_text, $response_file, $time_spent_seconds, $id);
            return $stmt->execute();
        } else {
            $stmt = $this->conn->prepare("INSERT INTO attempt_responses (attempt_id, question_id, response_text, response_file, time_spent_seconds) VALUES (?, ?, ?, ?, ?)");
            if (!$stmt) return false;
            $stmt->bind_param("iissi", $attempt_id, $question_id, $response_text, $response_file, $time_spent_seconds);
            return $stmt->execute();
        }
    }

    public function getResponsesForAttempt(int $attempt_id): false|mysqli_result {
        $stmt = $this->conn->prepare("SELECT ar.*, q.question_type FROM attempt_responses ar JOIN questions q ON q.id = ar.question_id WHERE ar.attempt_id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("i", $attempt_id);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function saveSelectedOption(int $attempt_response_id, int $option_id): bool {
        // Remove duplicates not enforced here; application should manage multi-select semantics
        $stmt = $this->conn->prepare("INSERT INTO attempt_selected_options (attempt_response_id, option_id) VALUES (?, ?)");
        if (!$stmt) return false;
        $stmt->bind_param("ii", $attempt_response_id, $option_id);
        return $stmt->execute();
    }
}
