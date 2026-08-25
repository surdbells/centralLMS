<?php
include_once '../../config/Database.php';
include_once '../../class/Collections.php';
$database = new Database();
$db = $database->getConnection();
$collection_records = array();
$collection = new Collections($db);
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id)){
    $collection->id = $database->sanitize_input($data->collection);
    $collection->collection = $database->sanitize_input($data->name);
    $collection->is_active = $database->sanitize_input($data->is_active);
    $token_verification = $database->verify_user($data->token, $data->id);
    http_response_code(200);
    if ($token_verification == 100){
        if($collection->update_collections()){
            $database->send_response(200, "success", [], "Collection updated successfully");
        } else{
            $database->send_response(200, "failed", [], "Unable to update");
        }
    }else{
        $database->send_response(401, "failed", [], "Un-Authorized Request");
    }
}else{
    http_response_code(200);
    $database->send_response(400, "failed", [], "Invalid request");
}
