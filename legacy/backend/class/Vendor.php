<?php

class Vendor{
    public ?int $id;
    public string $token;
    public string $first_name;
    public string $last_name;
    public string $email;
    public string $phone;
    public string $last_login;
    public bool $is_2fa;
    public bool $is_active;
    public bool $is_admin;
    public bool $is_vendor;
    public string $is_customer;

    public string $updated;
    public string $created;
    public ?string $store_name;
    public string $store_slug;
    public ?string $store_email;
    public ?string $store_phone;
    public ?string $store_description;
    public ?string $store_logo;
    public ?string $store_cover;
    public ?string $vat_status;
    public ?string $store_legal_name;
    public ?string $trade_license_number;
    public ?string $licensing_authority;
    public ?string $tax_registration_number;
    public ?string $vat_registration_effective_date;
    public ?string $registered_tax_address;
    public ?string $tax_contact_email;
    public ?string $store_address;
    public ?string $store_bank_name;
    public ?string $store_account_name;
    public ?string $store_account_number;
    public bool $store_status;
    public bool $store_notify_order_received;
    public bool $store_notify_order_cancelled;
    public bool $store_notify_order_return_refund_initiated;
    public bool $store_notify_product_listing_approved_rejected;
    public bool $store_notify_payment_scheduled;
    public bool $store_notify_payment_completed;
    public bool $store_notify_negative_review;
    public bool $store_notify_monthly_performance;
    public bool $store_notify_weekly_performance;
    public bool $store_notify_low_engagement;
    public bool $store_notify_custom_order;
    private mysqli $conn;

    public function __construct($db){
        $this->conn = $db;
    }
    public function change_vendor_password(string $password, int $user_id): bool{
        $this->id = $user_id;
        $stmt = $this->conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $stmt->bind_param("si", $password, $this->id);
        if($stmt->execute()){ return true; } return false;
    }
    public function update_last_login(): void{
        $this->last_login = date("D M j, g:i a");
        $stmt = $this->conn->prepare("UPDATE users SET last_login = ? WHERE user_id = ?");
        $stmt->bind_param("si", $this->last_login, $this->id);
        $stmt->execute();
    }
    public function user_basic(int $user_id): false|mysqli_result{
            $this->id = $user_id;
            $stmt = $this->conn->prepare("SELECT user_id, token, first_name, last_name, email, phone, avatar FROM users WHERE user_id = ?");
            $stmt->bind_param("i", $this->id);
            $stmt->execute();
            return $stmt->get_result();
    }
    public function update_vendor_user_basic(string $first_name, string $last_name, string $email, string $phone, string $avatar, int $user_id): bool{
        $this->id = $user_id;
        $stmt = $this->conn->prepare("UPDATE users
			SET first_name = ?, 
			    last_name = ?, 
			    email = ? ,
			    phone = ?,
			    avatar = ?
			WHERE user_id = ?");
        $stmt->bind_param("sssssi",$first_name, $last_name, $email, $phone, $avatar, $this->id);
        if($stmt->execute()){
            return true;
        }
        return false;
    }
    public function read_vendor_store(int $user_id): false|mysqli_result{
        $this->id = $user_id;
        $this->is_vendor = true;
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE user_id = ? and is_vendor = ?");
        $stmt->bind_param("ii", $this->id, $this->is_vendor);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function update_vendor_store_basic(): bool{
        $stmt = $this->conn->prepare("UPDATE users SET store_name = ?,store_email = ?,store_phone = ?,store_description = ?, store_address = ?, store_logo = ?,store_cover = ? WHERE user_id = ?");
        $stmt->bind_param("sssssssi",
            $this->store_name, $this->store_email, $this->store_phone, $this->store_description, $this->store_address, $this->store_logo, $this->store_cover, $this->id);
        if($stmt->execute()){
            return true;
        }
        return false;
    }
    public function switch_vendor_store_status(int $status, int $user_id): bool{
        $stmt = $this->conn->prepare("UPDATE users SET store_status = ? WHERE user_id = ?");
        $stmt->bind_param("ii", $status, $user_id);
        if($stmt->execute()){ return true; }return false;
    }

    public function read_vendor_store_tax($user_id): false|mysqli_result{
        $this->id = $user_id;
        $this->is_vendor = true;
        $stmt = $this->conn->prepare("SELECT user_id, token, vat_status, store_legal_name, trade_license_number, licensing_authority,tax_registration_number,vat_registration_effective_date,registered_tax_address,tax_contact_email FROM users WHERE user_id = ? and is_vendor = ?");
        $stmt->bind_param("ii", $this->id, $this->is_vendor);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function update_vendor_store_tax(): bool{
        $stmt = $this->conn->prepare("UPDATE users
			SET vat_status = ?, 
			    store_legal_name = ?, 
			    trade_license_number = ?, 
			    licensing_authority = ?, 
			    tax_registration_number = ?, 
			    vat_registration_effective_date = ?, 
			    registered_tax_address = ?, 
			    tax_contact_email = ? 
			WHERE user_id = ?");
        $stmt->bind_param("sssssssss",
            $this->vat_status,
            $this->store_legal_name,
            $this->trade_license_number,
            $this->licensing_authority,
            $this->tax_registration_number,
            $this->vat_registration_effective_date,
            $this->registered_tax_address,
            $this->tax_contact_email,
            $this->id);
        if($stmt->execute()){
            return true;
        }
        return false;
    }
    public function update_compliance($front, $back): bool{
        $stmt = $this->conn->prepare("UPDATE users SET id_front = ?,  id_back = ? WHERE user_id = ?");
        $stmt->bind_param("ssi", $front,$back, $this->id);
        if($stmt->execute()){
            return true;
        }
        return false;
    }

    public function read_vendor_store_payment(int $user_id): false|mysqli_result{
        $this->id = $user_id;
        $this->is_vendor = true;
        $stmt = $this->conn->prepare("SELECT user_id, token, store_bank_name, store_account_name, store_account_number FROM users WHERE user_id = ? and is_vendor = ?");
        $stmt->bind_param("ii", $this->id, $this->is_vendor);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function update_vendor_store_payment(): bool{
        $stmt = $this->conn->prepare("UPDATE users SET store_bank_name = ?,  store_account_name = ?,  store_account_number = ?  WHERE user_id = ?");
        $stmt->bind_param("sssi",
            $this->store_bank_name, $this->store_account_name, $this->store_account_number,  $this->id);
        if($stmt->execute()){
            return true;
        }
        return false;
    }

    public function update_vendor_store_notifications(): bool{
        $stmt = $this->conn->prepare("UPDATE users SET 
                 store_notify_order_received = ?, 
                 store_notify_order_cancelled = ?, 
                 store_notify_order_return_refund_initiated = ?, 
                 store_notify_product_listing_approved_rejected = ?, 
                 store_notify_payment_scheduled = ?, 
                 store_notify_payment_completed = ?, 
                 store_notify_negative_review = ?, 
                 store_notify_monthly_performance = ?, 
                 store_notify_weekly_performance = ?,
                 store_notify_low_engagement = ?,
                 store_notify_custom_order = ?
             WHERE user_id = ?");
        $stmt->bind_param("iiiiiiiiiiii",
            $this->store_notify_order_received,
            $this->store_notify_order_cancelled,
            $this->store_notify_order_return_refund_initiated,
            $this->store_notify_product_listing_approved_rejected,
            $this->store_notify_payment_scheduled,
            $this->store_notify_payment_completed,
            $this->store_notify_negative_review,
            $this->store_notify_monthly_performance,
            $this->store_notify_weekly_performance,
            $this->store_notify_low_engagement,
            $this->store_notify_custom_order,
            $this->id
        );
        if($stmt->execute()){
            return true;
        }
        return false;

    }


    public function total_products(int $id): int {
        $status = "deleted";
        $stmt = $this->conn->prepare('SELECT COUNT(*) FROM products WHERE store_id=? and status != ?');
        $stmt->bind_param('is', $id, $status); $stmt->execute(); $stmt->bind_result($n); $stmt->fetch(); $stmt->close();
        return (int)$n;
    }

    public function total_orders(int $id): int{
        $stmt = $this->conn->prepare('SELECT COUNT(*) FROM ec_orders WHERE store_id=?');
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->bind_result($n); $stmt->fetch(); $stmt->close();
        return (int)$n;
    }

    public function pending_orders(string $status, int $id): int{
        $stmt = $this->conn->prepare('SELECT COUNT(*) FROM ec_orders WHERE status=? AND store_id=?');
        $stmt->bind_param('si', $status, $id); $stmt->execute(); $stmt->bind_result($n); $stmt->fetch(); $stmt->close();
        return (int)$n;
    }

    public function return_orders(string $status, int $id): int{
        $stmt = $this->conn->prepare('SELECT COUNT(*) FROM ec_orders WHERE status=? AND store_id=?');
        $stmt->bind_param('si', $status, $id); $stmt->execute(); $stmt->bind_result($n); $stmt->fetch(); $stmt->close();
        return (int)$n;
    }

    public function latest_orders(int $id): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT code, created_at, first_name, last_name, amount, status FROM ec_orders
                                             left join users u on ec_orders.user_id = u.user_id
                                             WHERE ec_orders.store_id = ? limit 5");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result();
    }

    function top_selling_products(int $store_id, string $status, int $limit = 4): false|mysqli_result{
        // keep limits sane
        $limit = max(1, min($limit, 50));

                $sql = "SELECT  p.*,  t.order_count
        FROM (SELECT eo.ec_orders_id, COUNT(*) AS order_count 
            FROM ec_orders eo
            WHERE eo.store_id = ? AND eo.status = ?
            GROUP BY eo.ec_orders_id
            ORDER BY order_count DESC
            LIMIT ?
        ) AS t
        INNER JOIN products p ON p.product_id = t.ec_orders_id 
        WHERE p.store_id = ?
        ORDER BY t.order_count DESC, p.product_id ASC
    ";

/*        $sql = "SELECT p.*, COALESCE(t.order_count, 0) AS order_count
        FROM products p
        LEFT JOIN ( SELECT eo.ec_orders_id, COUNT(*) AS order_count FROM ec_orders eo WHERE eo.store_id = ? AND eo.status = ? GROUP BY eo.ec_orders_id)
            AS t ON t.ec_orders_id = p.product_id WHERE p.store_id = ?
            ORDER BY COALESCE(t.order_count, 0) DESC, p.product_id ASC
            LIMIT ?";*/
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('isii', $store_id,$status, $store_id, $limit);
        $stmt->execute();
        return $stmt->get_result();
    }

    function monthlyInts(array $data): array {
        $order = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
        foreach ($data as $k=>$v) $norm[strtolower(substr($k,0,3))] = (int)$v;
        return array_map(fn($m)=>$norm[$m] ?? 0, $order);
    }
}