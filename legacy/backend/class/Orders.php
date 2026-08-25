<?php

class Orders{
    public ?int $order_code;
    public ?int $user_id;
    public ?int $order_id;
    public ?int $store_id;
    public ?int $cart_id;
    public ?int $order_date;
    public ?string $customer_name;
    public ?string $order_status;
    public ?string $order_amount;
    private mysqli $conn;
    public function __construct($db){ $this->conn = $db;}

    function create_order(): bool{
        $stmt = $this->conn->prepare("INSERT INTO ec_orders(code, user_id, cart_id, store_id, amount)
        VALUES(?,?,?,?,?)");
        $stmt->bind_param("siiid",$this->order_code,$this->user_id,$this->cart_id, $this->store_id, $this->order_amount);
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