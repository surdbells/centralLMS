<?php
/**
 * Media Read API Endpoint
 * Get list of media files with pagination and filters
 *
 * POST /user/media/read.php
 *
 * JSON Body:
 *   - token: Authentication token (required)
 *   - user_id: User ID (required)
 *   - tenant_id: Tenant ID (required)
 *   - page: Page number (optional, default: 1)
 *   - per_page: Items per page (optional, default: 10, max: 50)
 *   - media_type: Filter by type - VIDEO, AUDIO, PDF, SCORM (optional)
 *   - latest_only: Show only latest versions (optional, default: true)
 *   - search: Search in title (optional)
 *   - sort_by: Sort field - created_at, title, file_size (optional, default: created_at)
 *   - sort_order: ASC or DESC (optional, default: DESC)
 */

include_once '../../config/Database.php';
include_once '../../class/Media.php';

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'code' => 405,
        'status' => 'failed',
        'data' => [],
        'message' => 'Method not allowed'
    ]);
    exit;
}

$database = new Database();
$db = $database->getConnection();
$media = new Media($db);

$data = json_decode(file_get_contents("php://input"));

if (!empty($data->token) && !empty($data->user_id) && !empty($data->tenant_id)) {
    $token_check = $database->verify_user($data->token, $data->user_id);
    if ($token_check != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }

    $media->tenant_id = intval($data->tenant_id);

    // Pagination
    $page = isset($data->page) ? max(1, intval($data->page)) : 1;
    $per_page = isset($data->per_page) ? min(50, max(1, intval($data->per_page))) : 10;

    // Filters
    $media_type = $data->media_type ?? null;
    $latest_only = !isset($data->latest_only) || $data->latest_only;
    $search = $data->search ?? null;
    $sort_by = $data->sort_by ?? 'created_at';
    $sort_order = strtoupper($data->sort_order ?? 'DESC');

    // Validate sort options
    $allowed_sort = ['created_at', 'title', 'file_size', 'updated_at'];
    if (!in_array($sort_by, $allowed_sort)) {
        $sort_by = 'created_at';
    }
    if (!in_array($sort_order, ['ASC', 'DESC'])) {
        $sort_order = 'DESC';
    }

    $result = $media->read($page, $per_page, $media_type, $latest_only, $search, $sort_by, $sort_order);

    http_response_code(200);
    $database->send_response(200, "success", [
        "media" => $result['data'],
        "meta" => $result['meta']
    ], "Media retrieved successfully");

} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request. Token, user_id, and tenant_id are required");
}