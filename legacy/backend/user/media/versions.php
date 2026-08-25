<?php
/**
 * Media Version History API Endpoint
 * Get all versions, latest version, compare versions, or revert to a version
 *
 * POST /user/media/versions.php
 *
 * JSON Body:
 *   - token: Authentication token (required)
 *   - user_id: User ID (required)
 *   - tenant_id: Tenant ID (required)
 *   - media_id: Media ID (required)
 *   - action: "history" | "latest" | "compare" | "revert" (optional, default: history)
 *   - compare_with: Media ID to compare with (required for action=compare)
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

    if (empty($data->media_id)) {
        http_response_code(400);
        $database->send_response(400, "failed", [], "media_id is required");
        exit;
    }

    $media->tenant_id = intval($data->tenant_id);
    $media->media_id = intval($data->media_id);
    $action = $data->action ?? 'history';

    switch ($action) {
        case 'history':
            $versions = $media->getVersionHistory();

            if (empty($versions)) {
                http_response_code(404);
                $database->send_response(404, "failed", [], "Media not found");
                exit;
            }

            $formatted = formatVersionHistory($versions, $media);

            http_response_code(200);
            $database->send_response(200, "success", [
                "root_media_id" => $formatted['root_id'],
                "total_versions" => count($versions),
                "versions" => $formatted['versions']
            ], "Version history retrieved successfully");
            break;

        case 'latest':
            $latest = $media->getLatestVersion();

            if (!$latest) {
                http_response_code(404);
                $database->send_response(404, "failed", [], "Media not found");
                exit;
            }

            http_response_code(200);
            $database->send_response(200, "success", ["media" => $latest], "Latest version retrieved successfully");
            break;

        case 'compare':
            if (empty($data->compare_with)) {
                http_response_code(400);
                $database->send_response(400, "failed", [], "compare_with media_id is required for comparison");
                exit;
            }

            $compareWithId = intval($data->compare_with);
            $comparison = $media->compareVersions($compareWithId);

            if (!$comparison['success']) {
                http_response_code(400);
                $database->send_response(400, "failed", [], $comparison['error']);
                exit;
            }

            http_response_code(200);
            $database->send_response(200, "success", $comparison['data'], "Versions compared successfully");
            break;

        case 'revert':
            $result = $media->revertToVersion();

            if (!$result['success']) {
                http_response_code(400);
                $database->send_response(400, "failed", [], $result['error']);
                exit;
            }

            http_response_code(200);
            $database->send_response(200, "success", $result['data'], $result['message']);
            break;

        default:
            http_response_code(400);
            $database->send_response(400, "failed", [], "Invalid action. Use: history, latest, compare, revert");
            break;
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Invalid request. Token, user_id, and tenant_id are required");
}

/**
 * Format version history for response
 */
function formatVersionHistory(array $versions, Media $media): array
{
    $rootId = null;
    $formatted = [];

    foreach ($versions as $v) {
        if ($rootId === null) {
            $rootId = $v['root_parent_id'] ?? $v['media_id'];
        }

        $formatted[] = [
            'media_id' => (int) $v['media_id'],
            'title' => $v['title'],
            'version' => [
                'number' => (int) $v['version_number'],
                'label' => $v['version_label'],
                'notes' => $v['version_notes'],
                'is_latest' => (bool) $v['is_latest_version']
            ],
            'file' => [
                'size' => (int) $v['file_size'],
                'size_formatted' => $media->formatFileSize((int) $v['file_size']),
                'url' => $v['file_url']
            ],
            'uploaded_by' => [
                'user_id' => (int) $v['uploaded_by'],
                'name' => trim(($v['first_name'] ?? '') . ' ' . ($v['last_name'] ?? '')),
                'email' => $v['email'] ?? null
            ],
            'uploaded_at' => $v['created_at']
        ];
    }

    return [
        'root_id' => $rootId,
        'versions' => $formatted
    ];
}