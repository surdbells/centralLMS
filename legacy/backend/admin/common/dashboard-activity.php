<?php
include_once '../../config/Database.php';
include_once '../../class/Admin.php';
include_once '../../class/Generic.php';
$database = new Database();
$db = $database->getConnection();
$admin = new Admin($db);
$generic = new Admin($db);

$total_products = 0;
$total_orders = 0;
$pending_orders = 0;
$return_orders = 0;

$total_products_stats = 0;
$total_orders_stats = 0;
$pending_orders_stats = 0;
$return_orders_stats = 0;

$recent_orders_records = array();
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id)) {

    $order_pending = "pending";
    $order_returned = "returned";
    $order_delivered = "delivered";
    $result = $admin->latest_orders();

    $total_products = $admin->total_products();
    $total_orders = $admin->total_orders();
    $pending_orders = $admin->pending_orders($order_pending);
    $return_orders = $admin->return_orders($order_returned);

    $total_products_array = [
        'January'=> $generic->countProductsByMonth("January"),
        'February'=> $generic->countProductsByMonth("February"),
        'March'=> $generic->countProductsByMonth("March"),
        'April'=> $generic->countProductsByMonth("April"),
        'May'=> $generic->countProductsByMonth("May"),
        'June'=> $generic->countProductsByMonth("June"),
        'July'=> $generic->countProductsByMonth("July"),
        'August'=> $generic->countProductsByMonth("August"),
        'September'=> $generic->countProductsByMonth("September"),
        'October'=> $generic->countProductsByMonth("October"),
        'November'=> $generic->countProductsByMonth("November"),
        'December'=> $generic->countProductsByMonth("December"),
    ];
    $total_products_stats = $admin->monthlyInts($total_products_array);

    $total_orders_array = [
        'January'=> $generic->countOrdersByMonth("January", $order_delivered),
        'February'=> $generic->countOrdersByMonth("February", $order_delivered),
        'March'=> $generic->countOrdersByMonth("March", $order_delivered),
        'April'=> $generic->countOrdersByMonth("April", $order_delivered),
        'May'=> $generic->countOrdersByMonth("May", $order_delivered),
        'June'=> $generic->countOrdersByMonth("June", $order_delivered),
        'July'=> $generic->countOrdersByMonth("July", $order_delivered),
        'August'=> $generic->countOrdersByMonth("August", $order_delivered),
        'September'=> $generic->countOrdersByMonth("September", $order_delivered),
        'October'=> $generic->countOrdersByMonth("October", $order_delivered),
        'November'=> $generic->countOrdersByMonth("November", $order_delivered),
        'December'=> $generic->countOrdersByMonth("December", $order_delivered)
    ];
    $total_orders_stats = $admin->monthlyInts($total_orders_array);

    $pending_orders_array = [
        'January'=> $generic->countOrdersByMonth("January", $order_pending),
        'February'=> $generic->countOrdersByMonth("February", $order_pending),
        'March'=> $generic->countOrdersByMonth("March", $order_pending),
        'April'=> $generic->countOrdersByMonth("April", $order_pending),
        'May'=> $generic->countOrdersByMonth("May", $order_pending),
        'June'=> $generic->countOrdersByMonth("June", $order_pending),
        'July'=> $generic->countOrdersByMonth("July", $order_pending),
        'August'=> $generic->countOrdersByMonth("August", $order_pending),
        'September'=> $generic->countOrdersByMonth("September", $order_pending),
        'October'=> $generic->countOrdersByMonth("October", $order_pending),
        'November'=> $generic->countOrdersByMonth("November", $order_pending),
        'December'=> $generic->countOrdersByMonth("December", $order_pending)
    ];
    $pending_orders_stats = $admin->monthlyInts($pending_orders_array);

    $return_orders_array = [
        'January'=> $generic->countOrdersByMonth("January", $order_returned),
        'February'=> $generic->countOrdersByMonth("February", $order_returned),
        'March'=> $generic->countOrdersByMonth("March", $order_returned),
        'April'=> $generic->countOrdersByMonth("April", $order_returned),
        'May'=> $generic->countOrdersByMonth("May", $order_returned),
        'June'=> $generic->countOrdersByMonth("June", $order_returned),
        'July'=> $generic->countOrdersByMonth("July", $order_returned),
        'August'=> $generic->countOrdersByMonth("August", $order_returned),
        'September'=> $generic->countOrdersByMonth("September", $order_returned),
        'October'=> $generic->countOrdersByMonth("October", $order_returned),
        'November'=> $generic->countOrdersByMonth("November", $order_returned),
        'December'=> $generic->countOrdersByMonth("December", $order_returned)
    ];
    $return_orders_stats = $admin->monthlyInts($return_orders_array);

    $token_verification = $database->verify_user($data->token, $data->id);
    if ($token_verification == 100){
        $count = $result->num_rows;
        while($records = $result->fetch_assoc()) {
            $record = array(
                "code" => $records["code"],
                "created_at" => $records["created_at"],
                "customer" => $records["first_name"] . " " .  $records["last_name"],
                "amount" => $records["amount"],
                "status" => $records["status"],
            );
            $recent_orders_records[] = $record;
        }
        http_response_code(200);
        $database->dashboard_stat_response(
            200, "success", $total_products, $total_orders, $pending_orders,  $return_orders,
            $total_products_stats, $total_orders_stats, $pending_orders_stats,
            $return_orders_stats, $recent_orders_records,
            $count);
    }else{
        http_response_code(200);
        $database->send_response(401, "failed", [], "Unable to get data, Un-Authorized Request");
    }

} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to get data. Invalid request.");
}
