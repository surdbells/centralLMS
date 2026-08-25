<?php
include_once '../../config/Database.php';
include_once '../../class/Branches.php';
include_once '../../class/User.php';
$database = new Database();
$db = $database->getConnection();
$branches = new Branches($db);
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id) && !empty($data->tenant_id)) {
    $token_check = $database->verify_user($data->token, $data->id);
    if ($token_check != 100 || !$user->is_admin($data->id)) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }
    // Pagination parameters
    $page = max(1, (int)($data->page ?? 1));
    $perPage = max(1, min(100, (int)($data->per_page ?? 20)));
    $offset = ($page - 1) * $perPage;

    // Sorting rules
    $allowedColumns = ['branch_id', 'branch_code', 'branch_name', 'address', 'city', 'state', 'country'];
    $sortBy = in_array($data->sort_by ?? '', $allowedColumns) ? $data->sort_by : 'branch_id';
    $sortDir = strtolower($data->sort_dir ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

    // Total count first
    $countQuery = "SELECT COUNT(*) AS total FROM branches";
    $countStmt = $db->query($countQuery);
    $total = (int)($countStmt->fetch_assoc()['total'] ?? 0);
    $totalPages = max(1, (int)ceil($total / $perPage));

    // Fetch paginated data
    $query = "SELECT * FROM branches ORDER BY $sortBy $sortDir LIMIT ? OFFSET ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param("ii", $perPage, $offset);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $rows = [];
    foreach ($result as $record) {
        $rows[] = [
            "branch_id" => (int)$record["branch_id"],
            "branch_code" => $record["branch_code"],
            "branch_name" => $record["branch_name"],
            "address" => $record["address"],
            "city" => $record["city"],
            "state" => $record["state"],
            "country" => $record["country"],
            "status" => (bool)$record["is_active"],
            "created_at" => $record["created_at"],
            "updated_at" => $record["updated_at"],
        ];
    }
    http_response_code(200);

    $database->send_response(200, "success", [
        "branch" => $rows,
        "meta" => [
            "total" => $total,
            "page" => $page,
            "per_page" => $perPage,
            "total_pages" => $totalPages,
            "sort_by" => $sortBy,
            "sort_dir" => $sortDir
        ],
        "links" => $user->buildPaginationLinks($page, $perPage, $totalPages)
    ], "Branches retrieved successfully");

} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request.");
}
