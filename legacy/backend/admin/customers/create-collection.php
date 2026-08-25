<?php
include_once '../../config/Database.php';
include_once '../../class/Collections.php';
$database = new Database();
$db = $database->getConnection();
$collection_records = array();
$collection = new Collections($db);
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id) && !empty($data->collection)){
    $collection->collection = $database->sanitize_input($data->collection);
    $token_verification = $database->verify_user($data->token, $data->id);
    http_response_code(200);
    if ($token_verification == 100){
        if($collection->create_collection()){
            $database->send_response(200, "success", [], "Collection created successfully");
        } else{
            $database->send_response(200, "failed", [], "Unable to collection label.");
        }
    }else{
        $database->send_response(401, "failed", [], "Unable to create collection, Un-Authorized Request");
    }
}else{
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to create collection. invalid request");
}
