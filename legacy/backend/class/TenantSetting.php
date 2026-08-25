<?php

class TenantSetting {
    public ?int $setting_id = null;
    public ?int $tenant_id;
    public string $setting_key = '';
    public string $setting_value = '';
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $stmt = $this->conn->prepare("INSERT INTO tenant_settings (tenant_id, setting_key, setting_value) VALUES (?,?,?)");
        $stmt->bind_param("iss", $this->tenant_id, $this->setting_key, $this->setting_value);
        return $stmt->execute();
    }

    public function update(): bool {
        $stmt = $this->conn->prepare("UPDATE tenant_settings SET setting_value=? WHERE tenant_id=? AND setting_key=?");
        $stmt->bind_param("sis", $this->setting_value, $this->tenant_id, $this->setting_key);
        return $stmt->execute();
    }

    public function delete(): bool {
        $stmt = $this->conn->prepare("DELETE FROM tenant_settings WHERE setting_id=? AND tenant_id=?");
        $stmt->bind_param("ii", $this->setting_id, $this->tenant_id);
        return $stmt->execute();
    }

    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT * FROM tenant_settings WHERE setting_key=? AND tenant_id=?");
        $stmt->bind_param("si", $this->setting_key, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "Setting not found";
    }

    public function get_value(string $key, $default = null): mixed {
        $this->setting_key = $key;
        $result = $this->read_single();
        return is_array($result) ? $result['setting_value'] : $default;
    }

    public function set_value(string $key, string $value): bool {
        $this->setting_key = $key;
        $existing = $this->read_single();

        if (is_array($existing)) {
            $this->setting_value = $value;
            return $this->update();
        } else {
            $this->setting_value = $value;
            return $this->create();
        }
    }

    public function get_all_settings(): array {
        $stmt = $this->conn->prepare("SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id=?");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $settings = [];
        foreach ($result as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }
}