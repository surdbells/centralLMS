<?php

class Discount{
    public ?int $id;
    public ?string $title;

    public ?int $store_id;
    public ?string $code;
    public ?string $start_date;
    public ?string $description;
    public ?int $store_id;
    public ?bool $is_percentage;
    public ?bool $is_amount;
    public ?int $quantity;
    public ?int ;
    private mysqli $conn;
    public function __construct($db){ $this->conn = $db;}

    function create_coupon(, , , , ,,, , , $discount,$product_quantity,$min_order_price): bool    {
        $stmt = $this->conn->prepare("INSERT INTO ec_discounts(title, store_id, code, start_date, end_date,quantity,description, is_percentage, is_amount, discount,product_quantity,min_order_price)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("sisssisiiiii",$title, $store_id, $code, $start_date, $end_date,$quantity,$description, $is_percentage, $is_amount, $discount,$product_quantity,$min_order_price);
        if($stmt->execute()){  return true; }
        return false;
    }
    function update_order(int $order, string $status): bool{
        $stmt = $this->conn->prepare("UPDATE ec_orders SET status = ? WHERE ec_orders_id = ?");
        $stmt->bind_param("si",
            $status, $order);
        if($stmt->execute()){ return true; } return false;
    }

    function get_vendor_orders(int $id): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT ec_orders_id, code, ci.cart_id, ec_orders.created_at, first_name,last_name, customer_email, ec_orders.status FROM ec_orders
         LEFT JOIN ec_cart_items ci ON ec_orders.store_id = ci.store_id
         LEFT JOIN users u ON ec_orders.user_id = u.user_id
         WHERE ci.store_id = ?");
        $stmt->bind_param("i",$id);
        $stmt->execute();
        return $stmt->get_result();
    }
    function get_vendor_returns(int $id): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT ec_orders_id, code, cancellation_reason, ci.cart_id, ec_orders.created_at, first_name,last_name, customer_email, ec_orders.status FROM ec_orders
         LEFT JOIN ec_cart_items ci ON ec_orders.store_id = ci.store_id
         LEFT JOIN users u ON ec_orders.user_id = u.user_id
         WHERE ci.store_id = ? and ec_orders.status = 'return_requested'");
        $stmt->bind_param("i",$id);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function get_vendor_orders_items($cart_id): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT ec_cart_items_id, name, item_quantity, item_size FROM ec_cart_items
         LEFT JOIN products p ON ec_cart_items.item_id = p.product_id
         WHERE ec_cart_items.cart_id = ?");
        $stmt->bind_param("i",$cart_id);
        $stmt->execute();
        return $stmt->get_result();
    }

}