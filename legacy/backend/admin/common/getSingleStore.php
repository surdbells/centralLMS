<?php
include_once '../../config/Database.php';
include_once '../../class/Admin.php';
$database = new Database();
$db = $database->getConnection();
$admin = new Admin($db);
$record = array();
$data = json_decode(file_get_contents("php://input"));
if(!empty($data->token) && !empty($data->id) && !empty($data->store)) {
    $result = $admin->singleStore($data->store);
    $token_verification = $database->verify_user($data->token, $data->id);
    if ($token_verification == 100){
        $count = $result->num_rows;
        while($records = $result->fetch_assoc()) {
            $record = array(
                "id" => $records["user_id"],
                "token" => $records["token"],
                "first_name" => $records["first_name"],
                "last_name" => $records["last_name"],
                "email" => $records["email"],
                "phone" => $records["countryCode"] . $records["phone"],
                "avatar" => $records['avatar'] ??= 'assets/img/placeholder-1.png',
                "id_front" => $records['id_front'] ??= 'assets/img/placeholder-1.png',
                "id_back" => $records['id_back'] ??= 'assets/img/placeholder-1.png',
                "is_active" => (bool)$records["is_active"],
                "store_name" => $records["store_name"],
                "store_status" => (bool)$records["store_status"],
                "approved" => (bool)$records["store_approved"],
                "store_email" => $records["store_email"],
                "store_phone" => $records["store_phone"],
                "store_description" => $records["store_description"],
                "store_logo" => $records['store_logo'] ??= 'assets/img/placeholder-1.png',
                "store_cover" => $records['store_cover'] ??= 'assets/img/placeholder-1.png',
                "vat_status" => $records["vat_status"],
                "store_legal_name" => $records["store_legal_name"],
                "trade_license_number" => $records["trade_license_number"],
                "licensing_authority" => $records["licensing_authority"],
                "tax_registration_number" => $records["tax_registration_number"],
                "vat_registration_effective_date" => $records["vat_registration_effective_date"],
                "registered_tax_address" => $records["registered_tax_address"],
                "tax_contact_email" => $records["tax_contact_email"],
                "store_address" => $records["store_address"],
                "store_bank_name" => $records["store_bank_name"],
                "store_account_name" => $records["store_account_name"],
                "store_account_number" => $records["store_account_number"],
                "last_login" => $records["last_login"]
            );
        }
        http_response_code(200);
        $database->send_response(
            200, "success", $record, 'success');
    }else{
        http_response_code(200);
        $database->send_response(401, "failed", [], "Unable to get data, Un-Authorized Request");
    }

} else {
    http_response_code(200);
    $database->send_response(400, "failed", [], "Unable to get data. Invalid request.");
}
