<?php
/**
 * File Upload Handler
 * Handles file uploads for messaging attachments
 */

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/Database.php';

// Initialize database
$database = new Database();
$db = $database->getConnection();

try {
    // Get auth from POST
    $token = $_POST['token'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);
    $tenant_id = (int)($_POST['tenant_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $upload_type = $_POST['upload_type'] ?? 'message';

    // Validate auth
    if (empty($token) || $user_id === 0 || $tenant_id === 0) {
        throw new Exception('Unauthorized');
    }

    // TODO: Validate token against database
    // if (!validateToken($token, $user_id, $tenant_id)) {
    //     throw new Exception('Invalid token');
    // }

    // Configuration
    $upload_dir = __DIR__ . '/uploads/messages/';
    $allowed_types = [
        'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/zip', 'application/x-rar-compressed',
        'video/mp4', 'video/mpeg', 'video/quicktime',
        'audio/mpeg', 'audio/wav', 'audio/ogg'
    ];
    $max_file_size = 10485760; // 10MB

    // Create upload directory if not exists
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    // Handle different actions
    switch ($action) {
        case 'upload_message_attachment':
            // Single file upload
            if (!isset($_FILES['file'])) {
                throw new Exception('No file uploaded');
            }

            $result = uploadFile($_FILES['file'], $upload_dir, $allowed_types, $max_file_size, $tenant_id, $user_id);
            $database->send_response(200, 'success', $result, 'File uploaded successfully');
            break;

        case 'upload_message_attachments':
            // Multiple file upload
            if (!isset($_FILES['files'])) {
                throw new Exception('No files uploaded');
            }

            $results = [];
            $files = $_FILES['files'];
            $file_count = count($files['name']);

            for ($i = 0; $i < $file_count; $i++) {
                $file = [
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i]
                ];

                try {
                    $results[] = uploadFile($file, $upload_dir, $allowed_types, $max_file_size, $tenant_id, $user_id);
                } catch (Exception $e) {
                    error_log("File upload error for {$file['name']}: " . $e->getMessage());
                }
            }

            if (empty($results)) {
                throw new Exception('No files were uploaded successfully');
            }

            $database->send_response(200, 'success', $results, count($results) . ' file(s) uploaded successfully');
            break;

        default:
            throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    error_log('Upload Error: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'code' => 400,
        'status' => 'error',
        'data' => null,
        'message' => $e->getMessage()
    ]);
}

/**
 * Upload a single file
 */
function uploadFile($file, $upload_dir, $allowed_types, $max_file_size, $tenant_id, $user_id): array
{
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload error: ' . $file['error']);
    }

    // Validate file type
    if (!in_array($file['type'], $allowed_types)) {
        throw new Exception('File type not allowed: ' . $file['type']);
    }

    // Validate file size
    if ($file['size'] > $max_file_size) {
        throw new Exception('File size exceeds maximum allowed size');
    }

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $original_name = pathinfo($file['name'], PATHINFO_FILENAME);
    $safe_name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $original_name);
    $unique_name = $safe_name . '_' . time() . '_' . uniqid() . '.' . $extension;

    // Create tenant/user subdirectory
    $subdirectory = $upload_dir . $tenant_id . '/' . $user_id . '/';
    if (!file_exists($subdirectory)) {
        mkdir($subdirectory, 0777, true);
    }

    $file_path = $subdirectory . $unique_name;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $file_path)) {
        throw new Exception('Failed to move uploaded file');
    }

    // Create thumbnail for images
    $thumbnail_path = null;
    if (str_starts_with($file['type'], 'image/')) {
        $thumbnail_path = createThumbnail($file_path, $subdirectory, $unique_name, $file['type']);
    }

    // Return file information
    return [
        'success' => true,
        'file_name' => $file['name'],
        'file_path' => '/uploads/messages/' . $tenant_id . '/' . $user_id . '/' . $unique_name,
        'file_size' => $file['size'],
        'file_type' => $file['type'],
        'thumbnail_path' => $thumbnail_path
    ];
}

/**
 * Create thumbnail for images
 */
function createThumbnail($source_path, $dest_dir, $filename, $mime_type): ?string
{
    // Only create thumbnails for images
    if (!str_starts_with($mime_type, 'image/')) {
        return null;
    }

    try {
        // Maximum thumbnail dimensions
        $max_width = 200;
        $max_height = 200;

        // Get image dimensions
        list($width, $height) = getimagesize($source_path);

        // Calculate new dimensions
        $ratio = min($max_width / $width, $max_height / $height);
        $new_width = (int)($width * $ratio);
        $new_height = (int)($height * $ratio);

        // Create image resource
        switch ($mime_type) {
            case 'image/jpeg':
            case 'image/jpg':
                $source = imagecreatefromjpeg($source_path);
                break;
            case 'image/png':
                $source = imagecreatefrompng($source_path);
                break;
            case 'image/gif':
                $source = imagecreatefromgif($source_path);
                break;
            case 'image/webp':
                $source = imagecreatefromwebp($source_path);
                break;
            default:
                return null;
        }

        // Create thumbnail
        $thumbnail = imagecreatetruecolor($new_width, $new_height);

        // Preserve transparency for PNG and GIF
        if ($mime_type === 'image/png' || $mime_type === 'image/gif') {
            imagealphablending($thumbnail, false);
            imagesavealpha($thumbnail, true);
        }

        // Resize
        imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);

        // Save thumbnail
        $thumb_name = 'thumb_' . $filename;
        $thumb_path = $dest_dir . $thumb_name;

        switch ($mime_type) {
            case 'image/jpeg':
            case 'image/jpg':
                imagejpeg($thumbnail, $thumb_path, 85);
                break;
            case 'image/png':
                imagepng($thumbnail, $thumb_path, 8);
                break;
            case 'image/gif':
                imagegif($thumbnail, $thumb_path);
                break;
            case 'image/webp':
                imagewebp($thumbnail, $thumb_path, 85);
                break;
        }

        // Clean up
        imagedestroy($source);
        imagedestroy($thumbnail);

        return '/uploads/messages/' . basename(dirname($thumb_path, 2)) . '/' . basename(dirname($thumb_path)) . '/' . $thumb_name;

    } catch (Exception $e) {
        error_log('Thumbnail creation error: ' . $e->getMessage());
        return null;
    }
}