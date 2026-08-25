<?php

class Customer{
    public ?int $id;
    public string $token;
    public string $first_name;
    public string $name;
    public string $city;
    public string $area;
    public string $street;
    public string $last_name;
    public string $email;
    public string $password;
    public string $phone;
    public ?string $location;
    public string $last_login;
    public bool $is_2fa;
    public bool $is_customer;
    public bool $is_active;
    public string $updated;
    public string $m_name;
    public int $arm;
    public int $bust;
    public int $hip;
    public int $length;
    public int $neck;
    public int $waist;
    public int $label_id;
    public int $product_id;
    public string $label_name;

    private mysqli $conn;


    public function __construct($db){
        $this->conn = $db;
    }

    public function change_customer_password(): bool{
        $stmt = $this->conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $stmt->bind_param("si", $this->password, $this->id);
        if($stmt->execute()){ return true; } return false;
    }
    public function read_customer_user_basic(): false|mysqli_result{
        $this->is_customer = true;
        $stmt = $this->conn->prepare("SELECT user_id, token, first_name, last_name, email, phone FROM users WHERE user_id = ? and is_customer = ?");
        $stmt->bind_param("ii", $this->id, $this->is_customer);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function update_customer_user_basic(): bool{
        $stmt = $this->conn->prepare("UPDATE users SET first_name = ?, last_name = ?, phone = ? WHERE user_id = ?");
        $stmt->bind_param("sssi",$this->first_name, $this->last_name, $this->phone, $this->id);
        if($stmt->execute()){ return true; } return false;
    }

    public function update_wish_labels(string $name, int $id): bool{
        $stmt = $this->conn->prepare("UPDATE customer_wishlist_label SET label_name = ? WHERE wishll_id = ?");
        $stmt->bind_param("si",$name, $id);
        if($stmt->execute()){ return true; } return false;
    }

    public function update_profile($countryCode): bool{
        $stmt = $this->conn->prepare("UPDATE users SET first_name = ?, last_name = ?, location = ?, phone = ?, countryCode = ? WHERE user_id = ?");
        $stmt->bind_param("sssssi",$this->first_name,$this->last_name, $this->location, $this->phone, $countryCode, $this->id);
        if($stmt->execute()){ return true; } return false;
    }
    public function update_location(): bool{
        $stmt = $this->conn->prepare("UPDATE users SET location = ? WHERE user_id = ?");
        $stmt->bind_param("si",$this->location, $this->id);
        if($stmt->execute()){ return true; } return false;
    }
    public function update_measurement(): bool{
        $stmt = $this->conn->prepare("UPDATE users 
        SET arm = ? ,  bust = ?, hip = ?, length = ?, neck = ?, waist = ?
        WHERE user_id = ?");
        $stmt->bind_param("iiiiiii",
        $this->arm, $this->bust, $this->hip, $this->length, $this->neck,$this->waist,$this->id);
        if($stmt->execute()){ return true; } return false;
    }
    public function read_measurements(): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT m_id, m_name FROM measurements WHERE user_id = ?");
        $stmt->bind_param("i", $this->id);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function read_measurement_by_id(int $id): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT * FROM measurements WHERE m_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result();
    }


    public function create_billing(): bool{
        $stmt = $this->conn->prepare("INSERT INTO billing_addresses(user_id, name, phone, email, city, area, street)
        VALUES(?,?,?,?,?,?,?)");
        $stmt->bind_param("issssss",$this->id, $this->name, $this->phone, $this->email, $this->city, $this->area, $this->street);
        if($stmt->execute()){  return true; }
        return false;
    }
    public function update_billing($user): bool{
        $stmt = $this->conn->prepare("UPDATE users SET users.billing_name = ? ,  billing_phone = ?, billing_email = ?, billing_city = ?, billing_area = ?, billing_street = ? WHERE user_id = ?");
        $stmt->bind_param("ssssssi",
            $this->name, $this->phone, $this->email, $this->city, $this->area, $this->street, $user);
        if($stmt->execute()){ return true; } return false;
    }
    public function read_billings(): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT billing_name, billing_phone, billing_email, billing_country, billing_city, billing_area, billing_street FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $this->id);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function read_measurement(): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT arm, bust, hip, length, neck, waist FROM users WHERE user_id = ?");
        $stmt->bind_param("i", $this->id);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function read_reviews(): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT id, store_name, comment, star  FROM ec_reviews left join users u on ec_reviews.store_id = u.user_id WHERE ec_reviews.customer_id = ?");
        $stmt->bind_param("i", $this->id);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function read_profile(): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT user_id, first_name, last_name, phone, location, countryCode FROM users where user_id = ?");
        $stmt->bind_param("i", $this->id);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function read_billing_by_id(int $id): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT * FROM billing_addresses WHERE billing_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function update_avatar($avatar): bool{
        $stmt = $this->conn->prepare("UPDATE users SET avatar = ? WHERE user_id = ?");
        $stmt->bind_param("si",$avatar,$this->id);
        if($stmt->execute()){ return true; } return false;
    }

    public function best_sellers($status = "published"): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT * FROM products
         left join category c on products.category_id = c.category_id
         left join users u on products.store_id = u.user_id
         WHERE status = ? order by rand() limit 6");
        $stmt->bind_param("s", $status);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function explore($status = "published"): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT * FROM products
         left join category c on products.category_id = c.category_id
         left join users u on products.store_id = u.user_id
         WHERE status = ? order by rand() limit 6");
        $stmt->bind_param("s", $status);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function search_product(string $search_term, string $status = "published"): false|mysqli_result {
        $stmt = $this->conn->prepare("
        SELECT p.*, c.category_name, u.store_name AS store_name
        FROM products p
        LEFT JOIN category c ON p.category_id = c.category_id
        LEFT JOIN users u ON p.store_id = u.user_id
        WHERE p.status = ? 
          AND p.name LIKE ?
        ORDER BY RAND()
        LIMIT 10");

        // Add wildcards for partial search
        $like_term = "%" . $search_term . "%";
        $stmt->bind_param("ss", $status, $like_term);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function product_by_category($category, $status = "published"): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT * FROM products
         left join category c on products.category_id = c.category_id
         left join users u on products.store_id = u.user_id
         WHERE status = ? and products.category_id = ? order by rand() limit 10");
        $stmt->bind_param("si", $status, $category);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function random_store_featured_products(string $status = "published", bool $is_featured = true): false|mysqli_result {
        $stmt = $this->conn->prepare("
        SELECT p.*, c.category_name, u.store_name AS store_name
        FROM products p
        LEFT JOIN category c ON p.category_id = c.category_id
        LEFT JOIN users u ON p.store_id = u.user_id
        WHERE p.status = ? and p.is_featured = ? AND p.store_id = ( SELECT store_id FROM products WHERE status = ? ORDER BY RAND() LIMIT 1)
        ORDER BY RAND()
        LIMIT 10");
        $stmt->bind_param("sis", $status, $is_featured, $status);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function store_latest_product($store, string $status = "published"): false|mysqli_result {
        $stmt = $this->conn->prepare("SELECT * FROM products
         left join category c on products.category_id = c.category_id
         left join users u on products.store_id = u.user_id
         WHERE status = ? and products.store_id = ? order by rand() limit 10");
        $stmt->bind_param("si", $status, $store);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function store_label($store): false|mysqli_result {
        $stmt = $this->conn->prepare("SELECT * FROM vendor_custom_labels WHERE user_id = ?");
        $stmt->bind_param("i",$store);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function store_products_by_label($label_id, $status = "published"): false|mysqli_result {
        $stmt = $this->conn->prepare("SELECT * FROM products
         left join category c on products.category_id = c.category_id
         left join users u on products.store_id = u.user_id
         WHERE status = ? and label = ? order by rand()");
        $stmt->bind_param("si", $status, $label_id);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function filtered_products($category, $price_start, $price_end, $delivery, $status = "published"): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT * FROM products
         left join category c on products.category_id = c.category_id
         left join users u on products.store_id = u.user_id
         where products.category_id = ? and products.sale_price between ? and ? and products.delivery_time = ?
         and status = ? order by rand() limit 6");
        $stmt->bind_param("iiiss", $category, $price_start, $price_end, $delivery, $status);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function add_wishlist(): bool{
        $stmt = $this->conn->prepare("INSERT INTO wishlist(user_id, wishll_id, poduct_id)
        VALUES(?,?,?)");
        $stmt->bind_param("iii",$this->id,$this->label_id, $this->product_id);
        if($stmt->execute()){  return true; }
        return false;
    }
    public function wishlist_label(): false|mysqli_result {
        $stmt = $this->conn->prepare("SELECT * FROM customer_wishlist_label WHERE user_id = ?");
        $stmt->bind_param("i",$this->id);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function wishlist($label): false|mysqli_result {
        $stmt = $this->conn->prepare("SELECT * FROM wishlist WHERE user_id = ? and wishll_id = ?");
        $stmt->bind_param("ii",$this->id, $label);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function total_wishlist_label(int $label): int {
        $stmt = $this->conn->prepare('SELECT COUNT(*) FROM wishlist WHERE wishll_id = ?');
        $stmt->bind_param('i', $label); $stmt->execute(); $stmt->bind_result($n); $stmt->fetch(); $stmt->close();
        return (int)$n;
    }


    public function add_wishlist_label(): bool{
        $stmt = $this->conn->prepare("INSERT INTO customer_wishlist_label(user_id, label_name)
        VALUES(?,?)");
        $stmt->bind_param("is",$this->id,$this->label_name);
        if($stmt->execute()){  return true; }
        return false;
    }



}