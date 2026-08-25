<?php

class UserLevelProgress {
    public ?int $progress_id = null;
    public ?int $tenant_id;
    public ?int $user_id;
    public ?int $current_level_id;
    public int $total_points = 0;
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $stmt = $this->conn->prepare("INSERT INTO user_level_progress (tenant_id, user_id, current_level_id, total_points) VALUES (?,?,?,?)");
        $stmt->bind_param("iiii", $this->tenant_id, $this->user_id, $this->current_level_id, $this->total_points);
        return $stmt->execute();
    }

    public function update(): bool {
        $stmt = $this->conn->prepare("UPDATE user_level_progress SET current_level_id=?, total_points=? WHERE user_id=? AND tenant_id=?");
        $stmt->bind_param("iiii", $this->current_level_id, $this->total_points, $this->user_id, $this->tenant_id);
        return $stmt->execute();
    }

    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT ulp.*, ul.level_number, ul.level_name, ul.level_icon_url, ul.required_points, CONCAT(u.first_name, ' ', u.last_name) as learner_name FROM user_level_progress ulp LEFT JOIN user_levels ul ON ulp.current_level_id = ul.level_id LEFT JOIN users u ON ulp.user_id = u.id WHERE ulp.user_id=? AND ulp.tenant_id=?");
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "User level progress not found";
    }

    public function update_user_level(int $user_id, int $points_to_add): bool {
        $stmt1 = $this->conn->prepare("SELECT * FROM user_level_progress WHERE user_id=? AND tenant_id=?");
        $stmt1->bind_param("ii", $user_id, $this->tenant_id);
        $stmt1->execute();
        $progress = $stmt1->get_result()->fetch_assoc();

        $new_total_points = ($progress['total_points'] ?? 0) + $points_to_add;

        $stmt2 = $this->conn->prepare("SELECT level_id FROM user_levels WHERE tenant_id=? AND required_points <= ? AND is_active=1 ORDER BY required_points DESC LIMIT 1");
        $stmt2->bind_param("ii", $this->tenant_id, $new_total_points);
        $stmt2->execute();
        $level = $stmt2->get_result()->fetch_assoc();
        $new_level_id = $level['level_id'] ?? null;

        if (!$progress) {
            $this->user_id = $user_id;
            $this->current_level_id = $new_level_id;
            $this->total_points = $new_total_points;
            return $this->create();
        } else {
            $this->user_id = $user_id;
            $this->current_level_id = $new_level_id;
            $this->total_points = $new_total_points;
            return $this->update();
        }
    }

    public function get_progress_percentage(): float {
        $stmt = $this->conn->prepare("SELECT ulp.total_points, ul.required_points, (SELECT required_points FROM user_levels WHERE tenant_id=? AND level_number > ul.level_number AND is_active=1 ORDER BY level_number ASC LIMIT 1) as next_level_points FROM user_level_progress ulp LEFT JOIN user_levels ul ON ulp.current_level_id = ul.level_id WHERE ulp.user_id=? AND ulp.tenant_id=?");
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if (!$result || !$result['next_level_points']) {
            return 100.0;
        }

        $current_level_points = $result['required_points'];
        $next_level_points = $result['next_level_points'];
        $total_points = $result['total_points'];

        $points_in_current_level = $total_points - $current_level_points;
        $points_needed_for_next = $next_level_points - $current_level_points;

        return min(100, ($points_in_current_level / $points_needed_for_next) * 100);
    }
}