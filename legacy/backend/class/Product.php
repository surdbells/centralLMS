<?php

use Random\RandomException;

class Product{
    public ?int $product_id;
    public ?int $store_id;
    public ?int $category_id;
    public ?string $name;
    public ?string $description;
    public ?string $status;
    public ?string $image_1;
    public ?string $images;
    public ?string $collection;

    public ?int $quantity;
    public ?int $label;
    public ?bool $allow_checkout_when_out_of_stock;
    public ?bool $with_storehouse_management;
    public ?string $stock_status;
    public ?bool $is_featured;
    public ?float $price;
    public ?float $sale_price;
    public ?int $minimum_order_quantity;
    public ?int $maximum_order_quantity;
    public ?float $height;
    public ?float $weight;
    public ?float $wide;
    public ?float $length;
    public ?float $cost_per_item;

    public ?string $delivery_time;
    public ?string $custom_delivery_time;
    public ?bool $size_xs;
    public ?bool $size_s;
    public ?bool $size_m;
    public ?bool $size_l;
    public ?bool $size_xl;
    public ?bool $size_xxl;
    public ?bool $size_50;
    public ?bool $size_52;
    public ?bool $size_54;
    public ?bool $size_56;
    public ?bool $size_58;
    public ?bool $size_60;
    public ?bool $size_62;
    public ?bool $require_extra_msmt;

    public ?bool $size_custom;
    public ?bool $is_hot;
    public ?bool $is_new;
    public ?bool $is_sale;
    public ?string $delivery_note;
    public ?string $extra_msmt;
    public ?string $colors;
    public ?string $created_at;
    public ?string $updated_at;

    private mysqli $conn;

    public function __construct($db){
        $this->conn = $db;
    }

    function create_product(): bool{
        $stmt = $this->conn->prepare("INSERT INTO products(
            store_id, category_id, name, description, 
            image_1, images, 
            quantity, allow_checkout_when_out_of_stock, with_storehouse_management, 
            stock_status, price, sale_price, minimum_order_quantity, maximum_order_quantity, 
             height, weight, wide, length, created_at, cost_per_item, delivery_time,
             custom_delivery_time,size_xs, size_s, size_m, size_l, size_xl,size_xxl,size_custom,
                     size_50, size_52, size_54, size_56, size_58,size_60,size_62,require_extra_msmt, extra_msmt,
             is_hot, is_new, is_sale,delivery_note,colors,status,label, collection, is_featured)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param(
            "iissssiiisddiiddddsdssiiiiiiiiiiiiiiisiiisssisi",
            $this->store_id,$this->category_id,$this->name,$this->description,
            $this->image_1, $this->images, $this->quantity, $this->allow_checkout_when_out_of_stock, $this->with_storehouse_management,
            $this->stock_status,$this->price,$this->sale_price, $this->minimum_order_quantity,$this->maximum_order_quantity,
            $this->height,$this->weight,$this->wide,$this->length,$this->created_at, $this->cost_per_item,
            $this->delivery_time,$this->custom_delivery_time,$this->size_xs,$this->size_s,$this->size_m,$this->size_l,$this->size_xl,$this->size_xxl, $this->size_custom,

            $this->size_50,$this->size_52,$this->size_54,$this->size_56,$this->size_58,$this->size_60,$this->size_62,$this->require_extra_msmt, $this->extra_msmt,
            $this->is_hot,$this->is_new,$this->is_sale,$this->delivery_note, $this->colors,$this->status,$this->label,$this->collection, $this->is_featured);
        if($stmt->execute()){  return true; }
        return false;
    }
    function update_product(): bool{
        $stmt = $this->conn->prepare("UPDATE products SET 
                  category_id = ?, name = ?, description = ?, 
            image_1 = ?, images = ?,quantity = ?, allow_checkout_when_out_of_stock = ?, with_storehouse_management = ?, 
            stock_status = ?, price = ?, sale_price = ?, minimum_order_quantity = ?, maximum_order_quantity = ?, 
             height = ?, weight = ?, wide = ?, length = ?, created_at = ?, cost_per_item = ?, delivery_time = ?,
             custom_delivery_time = ?,size_xs = ?, size_s = ?, size_m = ?, size_l = ?, size_xl = ?,size_xxl = ?,size_custom = ?,
             
             size_50 = ?, size_52 = ?, size_54 = ?, size_56 = ?, size_58 = ?, size_60 = ?, size_62 = ?, require_extra_msmt = ?, extra_msmt = ?,
             
             is_hot = ?, is_new = ?, is_sale = ?,delivery_note = ?,colors = ?, label = ?, collection = ?, updated_at = ?, is_featured = ? WHERE product_id = ?");
        $stmt->bind_param("issssiiisddiiddddsdssiiiiiiiiiiiiiiisiiississii",
            $this->category_id,$this->name,$this->description,
            $this->image_1, $this->images,$this->quantity, $this->allow_checkout_when_out_of_stock, $this->with_storehouse_management,
            $this->stock_status,$this->price,$this->sale_price, $this->minimum_order_quantity,$this->maximum_order_quantity,
            $this->height,$this->weight,$this->wide,$this->length,$this->created_at, $this->cost_per_item,
            $this->delivery_time,$this->custom_delivery_time,$this->size_xs,$this->size_s,$this->size_m,$this->size_l,$this->size_xl,$this->size_xxl, $this->size_custom,
            $this->size_50,$this->size_52,$this->size_54,$this->size_56,$this->size_58,$this->size_60,$this->size_62,$this->require_extra_msmt, $this->extra_msmt,
            $this->is_hot,$this->is_new,$this->is_sale,$this->delivery_note, $this->colors,$this->label,$this->collection,$this->updated_at,$this->is_featured, $this->product_id);
        if($stmt->execute()){ return true; } return false;
    }
    function delete_product(): bool{
        $stmt = $this->conn->prepare("UPDATE products SET status = ? WHERE product_id = ?");
        $stmt->bind_param("si",$this->status, $this->product_id);
        if($stmt->execute()){ return true; } return false;
    }
    function feature_product(): bool{
        $stmt = $this->conn->prepare("UPDATE products SET 
                  is_featured = ? WHERE product_id = ?");
        $stmt->bind_param("ii",
            $this->is_featured, $this->product_id);
        if($stmt->execute()){ return true; } return false;
    }
    function product_store_house_management(): bool{
        $stmt = $this->conn->prepare("UPDATE products SET 
                  with_storehouse_management = ? WHERE product_id = ?");
        $stmt->bind_param("ii",
            $this->with_storehouse_management, $this->product_id);
        if($stmt->execute()){ return true; } return false;
    }
    function get_product_by_id(): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT * FROM products  
            left join category c on products.category_id = c.category_id
            WHERE products.product_id = ?");
        $stmt->bind_param("i", $this->product_id);
        $stmt->execute();
        return $stmt->get_result();
    }
    function get_vendor_products(): false|mysqli_result{
            $stmt = $this->conn->prepare("SELECT * FROM products  
            left join category c on products.category_id = c.category_id
            WHERE products.store_id = ? and status != 'deleted'");
            $stmt->bind_param("i", $this->store_id);
            $stmt->execute();
            return $stmt->get_result();
    }
    function get_products(): false|mysqli_result{
            $stmt = $this->conn->prepare("SELECT * FROM products  
            left join category c on products.category_id = c.category_id
            left join users u on products.store_id = u.user_id
            WHERE status != 'deleted'");
            $stmt->execute();
            return $stmt->get_result();
    }
    function get_vendor_products_reviews(int $store_id): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT * FROM ec_reviews  
            left join products p on ec_reviews.product_id = p.product_id
            WHERE ec_reviews.store_id = ? and ec_reviews.status != 'published'");
        $stmt->bind_param("i", $store_id);
        $stmt->execute();
        return $stmt->get_result();
    }


    function store_published_products(): false|mysqli_result{
        if($this->store_id) {
            $stmt = $this->conn->prepare("SELECT * FROM products  
            left join category c on products.category_id = c.category_id
            WHERE products.store_id = ? and products.status = ?");
            $stmt->bind_param("is", $this->store_id, $this->status);
            $stmt->execute();
            return $stmt->get_result();
        }
        return false;
    }
    function generate_coupon(int $length = 12): string{
        $code = '';
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $maxIndex = strlen($alphabet) - 1;
        for ($i = 0; $i < $length; $i++) {
            try { $code .= $alphabet[random_int(0, $maxIndex)];
            } catch (RandomException $e) {
            }
        }
        return $code;
    }
    function create_coupon($title, $store_id, $code, $start_date, $end_date,$quantity,$description, $is_percentage, $is_amount, $discount,$product_quantity,$min_order_price): bool    {
        $stmt = $this->conn->prepare("INSERT INTO ec_discounts(title, store_id, code, start_date, end_date,quantity,description, is_percentage, is_amount, discount,product_quantity,min_order_price)
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("sisssisiiiii",$title, $store_id, $code, $start_date, $end_date,$quantity,$description, $is_percentage, $is_amount, $discount,$product_quantity,$min_order_price);
        if($stmt->execute()){  return true; }
        return false;
    }

}