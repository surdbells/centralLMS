<?php
include_once '../../config/Database.php';
include_once '../../class/Collections.php';
$database = new Database();
$db = $database->getConnection();
$collection_records = array();
$collection = new Collections($db);
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id)) {
    $collection->id = $data->collection;
    $result = $collection->all_collections();
    $token_verification = $database->verify_user($data->token, $data->id);
    if ($token_verification == 100){
        $count = $result->num_rows;
        if ($count > 0) {
            while($records = $result->fetch_assoc()) {
                $record = array(
                    "id" => $records["collections_id"],
                    "label" => $records["collection_name"],
                    "is_active" => (bool)$records["is_active"]
                );
                $collection_records[] = $record;
            }
            http_response_code(200);
            $database->send_response(200, "success", $collection_records,  "success");
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