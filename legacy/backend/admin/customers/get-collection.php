<?php
include_once '../../config/Database.php';
include_once '../../class/Collections.php';
$database = new Database();
$db = $database->getConnection();
$record = array();
$collection = new Collections($db);
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id)) {
    $collection->id = $data->collection;
    $result = $collection->single_collections();
    $token_verification = $database->verify_user($data->token, $data->id);
    if ($token_verification == 100){
        $count = $result->num_rows;
        if ($count > 0) {
            while($records = $result->fetch_assoc()) {
                $record = array(
                    "id" => $data->id,
                    "token" => $data->token,
                    "is_active" => (bool)$records["is_active"],
                    "collection" => $records["collections_id"],
                    "name" => $records["collection_name"]
                );
            }
            http_response_code(200);
            $database->send_response(200, "success", $record,  "success");
        } else {
            http_response_code(200);
            $database->send_response(503, "failed", [], "No collection found.");
        }
    }else{
        http_response_code(200);
        $database->send_response(401, "failed", [], "Unable to get collection, Un-Authorized Request");
    }

} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to get collection. Invalid request.");
}
?>