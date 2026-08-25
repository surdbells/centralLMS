<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
include_once '../config/Database.php';
include_once '../class/Channel.php';

    $database = new Database();
    $db = $database->getConnection();
    $channel = new Channel($db);
    $result = $channel->read_all();
    $channel_records = array();
    while($records = $result->fetch_assoc()) {
        $channel_record = array(
            "id" => $records["channel_id"],
            "name" => $records["channel_name"]
        );
        $channel_records[] = $channel_record;
    }
    http_response_code(200);
$database->send_response(200, "success", $channel_records, "Channels retrieved successfully.");
?>