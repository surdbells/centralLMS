<?php

class Coupon{
    public ?int $id;
    public ?string $title;
    public ?int $store_id;
    public ?string $code;
    public ?string $start_date;
    public ?string $end_date;
    public ?int $quantity;
    public ?string $description;
    public ?bool $is_percentage;
    public ?bool $is_amount;
    public ?int $discount;
    public ?int $total_used;
    public ?int $product_quantity;
    public ?string $target;
    public ?string $min_order_price;
    public ?string $created;
    public ?string $updated_at;
    private mysqli $conn;

    public function __construct($db)
    {
        $this->conn = $db;
    }

    function get_vendor_coupons(): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT * FROM ec_discounts WHERE store_id = ?");
        $stmt->bind_param("i", $this->store_id);
        $stmt->execute();
        return $stmt->get_result();
    }
    function create_coupon(): bool{
        $stmt = $this->conn->prepare("INSERT INTO ec_discounts(
                         title, store_id, code, start_date, end_date,
                         quantity,description, is_percentage, is_amount, discount,product_quantity,min_order_price)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("sisssisiiiii", $this->title, $this->store_id, $this->code, $this->start_date, $this->end_date, $this->quantity, $this->description, $this->is_percentage, $this->is_amount, $this->discount, $this->product_quantity, $this->min_order_price);
        if ($stmt->execute()) {
            return true;
        }
        return false;
    }
    function update_coupon(): bool
    {
        $stmt = $this->conn->prepare("UPDATE ec_discounts SET 
                  title = ?, code = ?, start_date = ?, end_date = ?,
                  quantity = ?, description = ?, is_percentage = ?, is_amount = ?, discount = ?,
                  product_quantity = ?, min_order_price = ?, updated_at = ? WHERE id = ?");
        $stmt->bind_param("ssssisiiiiisi",
            $this->title, $this->code, $this->start_date, $this->end_date,
            $this->quantity, $this->description, $this->is_percentage, $this->is_amount, $this->discount,
            $this->product_quantity, $this->min_order_price, $this->updated_at, $this->id);
        if ($stmt->execute()) {
            return true;
        }
        return false;
    }
    function delete_coupon(): bool{
        $stmt = $this->conn->prepare("DELETE FROM ec_discounts WHERE id = ?");
        $stmt->bind_param("i", $this->id);
        if ($stmt->execute()) { return true; }
        return false;
    }
    function get_coupon_by_id(): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT * FROM ec_discounts  
            WHERE id = ?");
        $stmt->bind_param("i", $this->id);
        $stmt->execute();
        return $stmt->get_result();
    }

}