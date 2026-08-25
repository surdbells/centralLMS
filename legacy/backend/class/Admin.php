<?php

class Admin{
    private mysqli $conn;

    public function __construct($db){
        $this->conn = $db;
    }

    public function total_products(): int {
        $stmt = $this->conn->prepare('SELECT COUNT(*) FROM products');
        $stmt->execute(); $stmt->bind_result($n); $stmt->fetch(); $stmt->close();
        return (int)$n;
    }

    public function total_orders(): int{
        $stmt = $this->conn->prepare('SELECT COUNT(*) FROM ec_orders');
        $stmt->execute(); $stmt->bind_result($n); $stmt->fetch(); $stmt->close();
        return (int)$n;
    }
    public function total_customer_orders($id): int{
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM ec_orders where user_id = '$id' and is_confirmed = true");
        $stmt->execute(); $stmt->bind_result($n); $stmt->fetch(); $stmt->close();
        return (int)$n;
    }
    public function total_vendor_orders($id): int{
        $stmt = $this->conn->prepare("SELECT COUNT(*) FROM ec_orders where store_id = '$id' and is_confirmed = true");
        $stmt->execute(); $stmt->bind_result($n); $stmt->fetch(); $stmt->close();
        return (int)$n;
    }
    function customer_status($status, $user): bool{
        $stmt = $this->conn->prepare("UPDATE users SET is_active = ? WHERE user_id = ?");
        $stmt->bind_param("ii",$status, $user);
        if($stmt->execute()){ return true; } return false;
    }
    function store_status($status, $user): bool{
        $stmt = $this->conn->prepare("UPDATE users SET store_approved = ? WHERE user_id = ?");
        $stmt->bind_param("ii",$status, $user);
        if($stmt->execute()){ return true; } return false;
    }
    public function pending_orders(string $status): int{
        $stmt = $this->conn->prepare('SELECT COUNT(*) FROM ec_orders WHERE status=?');
        $stmt->bind_param('s', $status); $stmt->execute(); $stmt->bind_result($n); $stmt->fetch(); $stmt->close();
        return (int)$n;
    }

    public function return_orders(string $status): int{
        $stmt = $this->conn->prepare('SELECT COUNT(*) FROM ec_orders WHERE status=?');
        $stmt->bind_param('s', $status); $stmt->execute(); $stmt->bind_result($n); $stmt->fetch(); $stmt->close();
        return (int)$n;
    }

    public function latest_orders(): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT code, created_at, first_name, last_name, amount, status FROM ec_orders left join users u on ec_orders.user_id = u.user_id limit 5");
        $stmt->execute();
        return $stmt->get_result();
    }

    function top_selling_products(string $status, int $limit = 4): false|mysqli_result{
        $limit = max(1, min($limit, 50));
        $sql = "SELECT  p.*,  t.order_count
        FROM (SELECT eo.ec_orders_id, COUNT(*) AS order_count 
            FROM ec_orders eo
            WHERE eo.status = ?
            GROUP BY eo.ec_orders_id
            ORDER BY order_count DESC
            LIMIT ?
        ) AS t
        INNER JOIN products p ON p.product_id = t.ec_orders_id 
        ORDER BY t.order_count DESC, p.product_id ASC";

/*        $sql = "SELECT p.*, COALESCE(t.order_count, 0) AS order_count
        FROM products p
        LEFT JOIN ( SELECT eo.ec_orders_id, COUNT(*) AS order_count FROM ec_orders eo WHERE eo.status = ? GROUP BY eo.ec_orders_id)
            AS t ON t.ec_orders_id = p.product_id ORDER BY COALESCE(t.order_count, 0) DESC, p.product_id ASC
            LIMIT ?";*/
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('si', $status,  $limit);
        $stmt->execute();
        return $stmt->get_result();
    }
    function customers(): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT * FROM users where is_customer = true");
        $stmt->execute();
        return $stmt->get_result();
    }
    function stores(): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT * FROM users where is_vendor = true");
        $stmt->execute();
        return $stmt->get_result();
    }
    function product_sales($id): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT code, product_name, customer_email, customer_name, amount, payment_fee, shipping_amount, payment_ref, status FROM ec_orders where product_id = '$id'");
        $stmt->execute();
        return $stmt->get_result();
    }
    function product_returns(): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT code, product_name, customer_email, customer_name, amount, payment_fee, shipping_amount, payment_ref, status FROM ec_orders where status = 'returned'");
        $stmt->execute();
        return $stmt->get_result();
    }
    function singleStore($id): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT * FROM users where is_vendor = true AND user_id = '$id'");
        $stmt->execute();
        return $stmt->get_result();
    }

    function monthlyInts(array $data): array {
        $order = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
        foreach ($data as $k=>$v) $norm[strtolower(substr($k,0,3))] = (int)$v;
        return array_map(fn($m)=>$norm[$m] ?? 0, $order);
    }
    function countProductsByMonth(string $monthName, ?int $year = null): int{
        $year = $year ?? (int)date('Y');
        $normalized = ucfirst(strtolower(trim($monthName)));
        $dt = DateTimeImmutable::createFromFormat('!F Y', "$normalized $year")
            ?: DateTimeImmutable::createFromFormat('!M Y', substr($normalized, 0, 3) . " $year");
        if (!$dt) {
            throw new InvalidArgumentException("Invalid month name: $monthName");
        }
        $start = $dt->setTime(0, 0, 0);                   // first second of the month
        $end   = $start->modify('first day of next month'); // exclusive upper bound
        $sql = "SELECT COUNT(*)  FROM products  WHERE created_at >= ? AND created_at < ?";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException("Prepare failed: " . $this->conn->error);
        }
        $startStr = $start->format('Y-m-d H:i:s');
        $endStr   = $end->format('Y-m-d H:i:s');
        $stmt->bind_param('ss', $startStr, $endStr);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        return (int)$count;
    }
    function countOrdersByMonth(string $monthName, string $status, ?int $year = null): int{
        $year = $year ?? (int)date('Y');
        $normalized = ucfirst(strtolower(trim($monthName)));
        $dt = DateTimeImmutable::createFromFormat('!F Y', "$normalized $year")
            ?: DateTimeImmutable::createFromFormat('!M Y', substr($normalized, 0, 3) . " $year");
        if (!$dt) {
            throw new InvalidArgumentException("Invalid month name: $monthName");
        }
        $start = $dt->setTime(0, 0, 0);                   // first second of the month
        $end   = $start->modify('first day of next month'); // exclusive upper bound
        $sql = "SELECT COUNT(*)  FROM ec_orders  WHERE created_at >= ? AND created_at < ? AND status = ?";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException("Prepare failed: " . $this->conn->error);
        }
        $startStr = $start->format('Y-m-d H:i:s');
        $endStr   = $end->format('Y-m-d H:i:s');
        $stmt->bind_param('sss', $startStr, $endStr, $status);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        return (int)$count;
    }

}