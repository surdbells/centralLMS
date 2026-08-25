<?php
/**
 * Base Controller
 * Common functionality for all Proctored Assessment controllers
 */

namespace App\Controllers\ProctoredAssessment;

abstract class BaseController
{
    protected $db;
    protected $tenantId;
    protected $userId;
    protected $userRole;

    public function __construct()
    {
        global $database, $currentUser;
        
        $this->db = $database->getConnection();
        $this->tenantId = $currentUser['tenant_id'] ?? null;
        $this->userId = $currentUser['id'] ?? null;
        $this->userRole = $currentUser['role'] ?? 'learner';
    }

    // ==================== REQUEST HELPERS ====================

    protected function input($key, $default = null)
    {
        return $_GET[$key] ?? $_POST[$key] ?? $default;
    }

    protected function getJsonInput()
    {
        $input = file_get_contents('php://input');
        return json_decode($input, true) ?? [];
    }

    protected function getFile($key)
    {
        return $_FILES[$key] ?? null;
    }

    // ==================== RESPONSE HELPERS ====================

    protected function json($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }

    protected function success($data = null, $message = null)
    {
        return $this->json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
    }

    protected function error($message, $statusCode = 400)
    {
        return $this->json([
            'success' => false,
            'message' => $message,
            'data' => null
        ], $statusCode);
    }

    protected function notFound($message = 'Resource not found')
    {
        return $this->error($message, 404);
    }

    protected function forbidden($message = 'Access denied')
    {
        return $this->error($message, 403);
    }

    protected function unauthorized($message = 'Authentication required')
    {
        return $this->error($message, 401);
    }

    protected function validationError($errors)
    {
        return $this->json([
            'success' => false,
            'message' => 'Validation failed',
            'errors' => $errors
        ], 422);
    }

    // ==================== FILE HELPERS ====================

    protected function getStoragePath($filename, $type = 'general')
    {
        $basePath = "uploads/tenant_{$this->tenantId}/assessment";
        
        switch ($type) {
            case 'question_media':
                return "$basePath/questions/$filename";
            case 'snapshot':
                return "$basePath/proctoring/snapshots/$filename";
            case 'recording':
                return "$basePath/proctoring/recordings/$filename";
            case 'certificate':
                return "$basePath/certificates/$filename";
            case 'import':
                return "$basePath/imports/$filename";
            case 'export':
                return "$basePath/exports/$filename";
            default:
                return "$basePath/$filename";
        }
    }

    protected function uploadFile($file, $type, $allowedExtensions = [])
    {
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \Exception('File upload failed');
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!empty($allowedExtensions) && !in_array($extension, $allowedExtensions)) {
            throw new \Exception('File type not allowed');
        }

        $filename = uniqid() . '_' . time() . '.' . $extension;
        $path = $this->getStoragePath($filename, $type);
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . '/' . $path;

        // Create directory if not exists
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
            throw new \Exception('Failed to save file');
        }

        return $path;
    }

    protected function deleteFile($path)
    {
        $fullPath = $_SERVER['DOCUMENT_ROOT'] . '/' . $path;
        if (file_exists($fullPath)) {
            unlink($fullPath);
        }
    }

    // ==================== AUDIT LOGGING ====================

    protected function logAction($action, $entityType, $entityId, $oldData = null, $newData = null)
    {
        $sql = "INSERT INTO proc_audit_log (
                    tenant_id, user_id, action, entity_type, entity_id,
                    old_values, new_values, ip_address, user_agent
                ) VALUES (
                    :tenant_id, :user_id, :action, :entity_type, :entity_id,
                    :old_values, :new_values, :ip_address, :user_agent
                )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldData ? json_encode($oldData) : null,
            'new_values' => $newData ? json_encode($newData) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }

    // ==================== AUTHORIZATION ====================

    protected function requireAdmin()
    {
        if (!in_array($this->userRole, ['admin', 'super_admin', 'instructor'])) {
            $this->forbidden('Admin access required');
        }
    }

    protected function requireInstructor()
    {
        if (!in_array($this->userRole, ['admin', 'super_admin', 'instructor', 'trainer'])) {
            $this->forbidden('Instructor access required');
        }
    }

    protected function canManageAssessment($assessmentId)
    {
        if (in_array($this->userRole, ['admin', 'super_admin'])) {
            return true;
        }

        $sql = "SELECT created_by FROM proc_assessments WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $assessmentId, 'tenant_id' => $this->tenantId]);
        $assessment = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $assessment && $assessment['created_by'] == $this->userId;
    }

    // ==================== PAGINATION ====================

    protected function paginate($query, $params, $page = 1, $limit = 20)
    {
        $offset = ($page - 1) * $limit;

        // Count total
        $countQuery = preg_replace('/SELECT .+ FROM/i', 'SELECT COUNT(*) FROM', $query);
        $countQuery = preg_replace('/ORDER BY .+$/i', '', $countQuery);
        $countQuery = preg_replace('/LIMIT .+$/i', '', $countQuery);

        $stmt = $this->db->prepare($countQuery);
        $stmt->execute($params);
        $total = $stmt->fetchColumn();

        // Get data
        $query .= " LIMIT $limit OFFSET $offset";
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        $data = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'data' => $data,
            'pagination' => [
                'total' => (int)$total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil($total / $limit)
            ]
        ];
    }

    // ==================== VALIDATION ====================

    protected function validate($data, $rules)
    {
        $errors = [];

        foreach ($rules as $field => $ruleSet) {
            $value = $data[$field] ?? null;
            $ruleList = is_array($ruleSet) ? $ruleSet : explode('|', $ruleSet);

            foreach ($ruleList as $rule) {
                $params = [];
                if (strpos($rule, ':') !== false) {
                    list($rule, $paramStr) = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                }

                $error = $this->validateRule($field, $value, $rule, $params);
                if ($error) {
                    $errors[$field] = $error;
                    break;
                }
            }
        }

        if (!empty($errors)) {
            $this->validationError($errors);
        }

        return true;
    }

    private function validateRule($field, $value, $rule, $params)
    {
        $fieldName = ucfirst(str_replace('_', ' ', $field));

        switch ($rule) {
            case 'required':
                if ($value === null || $value === '') {
                    return "$fieldName is required";
                }
                break;

            case 'string':
                if ($value !== null && !is_string($value)) {
                    return "$fieldName must be a string";
                }
                break;

            case 'integer':
                if ($value !== null && !is_numeric($value)) {
                    return "$fieldName must be an integer";
                }
                break;

            case 'email':
                if ($value !== null && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return "$fieldName must be a valid email";
                }
                break;

            case 'min':
                if ($value !== null && strlen($value) < $params[0]) {
                    return "$fieldName must be at least {$params[0]} characters";
                }
                break;

            case 'max':
                if ($value !== null && strlen($value) > $params[0]) {
                    return "$fieldName must not exceed {$params[0]} characters";
                }
                break;

            case 'in':
                if ($value !== null && !in_array($value, $params)) {
                    return "$fieldName must be one of: " . implode(', ', $params);
                }
                break;

            case 'numeric_min':
                if ($value !== null && $value < $params[0]) {
                    return "$fieldName must be at least {$params[0]}";
                }
                break;

            case 'numeric_max':
                if ($value !== null && $value > $params[0]) {
                    return "$fieldName must not exceed {$params[0]}";
                }
                break;
        }

        return null;
    }

    // ==================== EMAIL HELPERS ====================

    protected function sendEmail($to, $subject, $template, $data = [])
    {
        // Use your LMS email service
        global $emailService;
        
        if ($emailService) {
            return $emailService->send($to, $subject, $template, $data);
        }

        return false;
    }

    // ==================== DATE/TIME HELPERS ====================

    protected function now()
    {
        return date('Y-m-d H:i:s');
    }

    protected function parseDate($date)
    {
        return $date ? date('Y-m-d H:i:s', strtotime($date)) : null;
    }

    protected function formatDuration($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }
        return sprintf('%d:%02d', $minutes, $secs);
    }
}
