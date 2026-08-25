<?php
/**
 * Proctor Controller
 * Handles proctoring sessions, snapshots, violations, and recordings
 */

namespace App\Controllers\ProctoredAssessment;

class ProctorController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    // ==================== SESSION MANAGEMENT ====================

    public function startSession()
    {
        try {
            $data = $this->getJsonInput();
            $attemptId = $data['attempt_id'];

            // Get attempt and assessment
            $sql = "SELECT att.*, a.proctoring_level, a.title as assessment_title
                    FROM proc_assessment_attempts att
                    INNER JOIN proc_assessments a ON att.assessment_id = a.id
                    WHERE att.id = :id AND att.user_id = :user_id AND att.tenant_id = :tenant_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $attemptId, 'user_id' => $this->userId, 'tenant_id' => $this->tenantId]);
            $attempt = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$attempt) {
                return $this->error('Attempt not found');
            }

            // Get proctor settings
            $sql = "SELECT * FROM proc_proctor_settings WHERE assessment_id = :assessment_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['assessment_id' => $attempt['assessment_id']]);
            $settings = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Generate session token
            $sessionToken = bin2hex(random_bytes(32));

            // Create session
            $sql = "INSERT INTO proc_proctor_sessions (
                        tenant_id, attempt_id, session_token, status, trust_score,
                        device_info, browser_info, ip_address, started_at
                    ) VALUES (
                        :tenant_id, :attempt_id, :session_token, 'active', 100,
                        :device_info, :browser_info, :ip_address, NOW()
                    )";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'tenant_id' => $this->tenantId,
                'attempt_id' => $attemptId,
                'session_token' => $sessionToken,
                'device_info' => $data['device_info'] ?? null,
                'browser_info' => $data['browser_info'] ?? null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            $sessionId = $this->db->lastInsertId();

            return $this->success([
                'session_id' => $sessionId,
                'session_token' => $sessionToken,
                'settings' => $settings,
                'proctoring_level' => $attempt['proctoring_level']
            ], 'Proctoring session started');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function endSession($sessionId)
    {
        try {
            $data = $this->getJsonInput();

            $sql = "UPDATE proc_proctor_sessions SET 
                        status = 'completed',
                        ended_at = NOW(),
                        final_trust_score = :trust_score
                    WHERE id = :id AND tenant_id = :tenant_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'id' => $sessionId,
                'tenant_id' => $this->tenantId,
                'trust_score' => $data['trust_score'] ?? 100
            ]);

            // Update attempt with trust score
            $sql = "UPDATE proc_assessment_attempts att
                    SET trust_score = :trust_score
                    WHERE id = (SELECT attempt_id FROM proc_proctor_sessions WHERE id = :session_id)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['session_id' => $sessionId, 'trust_score' => $data['trust_score'] ?? 100]);

            return $this->success(null, 'Session ended successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function terminateSession($sessionId)
    {
        try {
            $data = $this->getJsonInput();
            $reason = $data['reason'] ?? 'Manual termination';

            $sql = "UPDATE proc_proctor_sessions SET 
                        status = 'terminated',
                        ended_at = NOW(),
                        termination_reason = :reason
                    WHERE id = :id AND tenant_id = :tenant_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'id' => $sessionId,
                'tenant_id' => $this->tenantId,
                'reason' => $reason
            ]);

            // Get attempt and terminate it
            $sql = "SELECT attempt_id FROM proc_proctor_sessions WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $sessionId]);
            $session = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($session) {
                $sql = "UPDATE proc_assessment_attempts SET 
                            status = 'terminated', 
                            submitted_at = NOW(),
                            termination_reason = :reason
                        WHERE id = :id";

                $stmt = $this->db->prepare($sql);
                $stmt->execute(['id' => $session['attempt_id'], 'reason' => $reason]);
            }

            return $this->success(null, 'Session terminated');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function getSessionDetails($sessionId)
    {
        try {
            $sql = "SELECT ps.*, 
                           att.attempt_number, att.status as attempt_status,
                           u.first_name, u.last_name, u.email,
                           a.title as assessment_title
                    FROM proc_proctor_sessions ps
                    INNER JOIN proc_assessment_attempts att ON ps.attempt_id = att.id
                    INNER JOIN users u ON att.user_id = u.id
                    INNER JOIN proc_assessments a ON att.assessment_id = a.id
                    WHERE ps.id = :id AND ps.tenant_id = :tenant_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $sessionId, 'tenant_id' => $this->tenantId]);
            $session = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$session) {
                return $this->notFound('Session not found');
            }

            // Get violation count
            $sql = "SELECT COUNT(*) as count, 
                           SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_count
                    FROM proc_proctor_violations WHERE session_id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $sessionId]);
            $violations = $stmt->fetch(\PDO::FETCH_ASSOC);

            $session['violation_count'] = $violations['count'];
            $session['critical_violations'] = $violations['critical_count'];

            // Get snapshot count
            $sql = "SELECT COUNT(*) FROM proc_proctor_snapshots WHERE session_id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $sessionId]);
            $session['snapshot_count'] = $stmt->fetchColumn();

            return $this->success($session);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== FACE REGISTRATION & ID VERIFICATION ====================

    public function registerFace()
    {
        try {
            $data = $this->getJsonInput();
            $sessionId = $data['session_id'];
            $faceEncoding = $data['face_encoding'];

            $sql = "UPDATE proc_proctor_sessions SET 
                        face_encoding = :encoding,
                        face_registered_at = NOW()
                    WHERE id = :id AND tenant_id = :tenant_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'id' => $sessionId,
                'tenant_id' => $this->tenantId,
                'encoding' => json_encode($faceEncoding)
            ]);

            return $this->success(null, 'Face registered successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function verifyId()
    {
        try {
            if (!isset($_FILES['id_image'])) {
                return $this->error('No ID image uploaded');
            }

            $data = $_POST;
            $sessionId = $data['session_id'];
            $file = $_FILES['id_image'];

            // Save ID image
            $filePath = $this->saveFile($file, "proctoring/{$sessionId}/id");

            $sql = "UPDATE proc_proctor_sessions SET 
                        id_verified = 1,
                        id_image_path = :path,
                        id_verified_at = NOW()
                    WHERE id = :id AND tenant_id = :tenant_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'id' => $sessionId,
                'tenant_id' => $this->tenantId,
                'path' => $filePath
            ]);

            return $this->success(['verified' => true], 'ID verified successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== SNAPSHOTS ====================

    public function uploadSnapshot()
    {
        try {
            $data = $this->getJsonInput();
            $sessionId = $data['session_id'];
            $imageData = $data['image_data']; // Base64
            $analysisResult = $data['analysis_result'] ?? null;

            // Decode and save image
            $imageContent = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $imageData));
            $fileName = uniqid('snapshot_') . '.jpg';
            $filePath = $this->saveContent($imageContent, "proctoring/{$sessionId}/snapshots", $fileName);

            // Determine flags
            $faceDetected = $analysisResult['face_detected'] ?? true;
            $multipleFaces = $analysisResult['multiple_faces'] ?? false;
            $lookingAway = $analysisResult['looking_away'] ?? false;
            $phoneDetected = $analysisResult['phone_detected'] ?? false;

            $sql = "INSERT INTO proc_proctor_snapshots (
                        session_id, image_path, face_detected, multiple_faces,
                        looking_away, phone_detected, ai_analysis, trust_impact, captured_at
                    ) VALUES (
                        :session_id, :image_path, :face_detected, :multiple_faces,
                        :looking_away, :phone_detected, :ai_analysis, :trust_impact, NOW()
                    )";

            $trustImpact = 0;
            if (!$faceDetected) $trustImpact -= 5;
            if ($multipleFaces) $trustImpact -= 10;
            if ($lookingAway) $trustImpact -= 2;
            if ($phoneDetected) $trustImpact -= 15;

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'session_id' => $sessionId,
                'image_path' => $filePath,
                'face_detected' => $faceDetected ? 1 : 0,
                'multiple_faces' => $multipleFaces ? 1 : 0,
                'looking_away' => $lookingAway ? 1 : 0,
                'phone_detected' => $phoneDetected ? 1 : 0,
                'ai_analysis' => json_encode($analysisResult),
                'trust_impact' => $trustImpact
            ]);

            $snapshotId = $this->db->lastInsertId();

            // Update session trust score
            if ($trustImpact < 0) {
                $this->updateTrustScore($sessionId, $trustImpact);
            }

            // Auto-create violations for detected issues
            if (!$faceDetected) {
                $this->createViolation($sessionId, 'face_not_detected', 'Face not detected in snapshot', 'medium', $snapshotId);
            }
            if ($multipleFaces) {
                $this->createViolation($sessionId, 'multiple_faces', 'Multiple faces detected', 'high', $snapshotId);
            }
            if ($phoneDetected) {
                $this->createViolation($sessionId, 'phone_detected', 'Phone detected in frame', 'high', $snapshotId);
            }

            return $this->success([
                'snapshot_id' => $snapshotId,
                'trust_impact' => $trustImpact,
                'flags' => [
                    'face_detected' => $faceDetected,
                    'multiple_faces' => $multipleFaces,
                    'looking_away' => $lookingAway,
                    'phone_detected' => $phoneDetected
                ]
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function getSessionSnapshots($sessionId)
    {
        try {
            $page = (int)($this->input('page') ?? 1);
            $limit = (int)($this->input('limit') ?? 50);
            $offset = ($page - 1) * $limit;
            $flaggedOnly = $this->input('flagged_only') === 'true';

            $where = "session_id = :session_id";
            $params = ['session_id' => $sessionId];

            if ($flaggedOnly) {
                $where .= " AND (face_detected = 0 OR multiple_faces = 1 OR phone_detected = 1)";
            }

            $sql = "SELECT * FROM proc_proctor_snapshots WHERE $where ORDER BY captured_at DESC LIMIT $limit OFFSET $offset";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            return $this->success($stmt->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== VIOLATIONS ====================

    public function recordViolation()
    {
        try {
            $data = $this->getJsonInput();

            $violationId = $this->createViolation(
                $data['session_id'],
                $data['violation_type'],
                $data['description'],
                $data['severity'] ?? 'low',
                $data['snapshot_id'] ?? null,
                $data['metadata'] ?? null
            );

            return $this->success(['violation_id' => $violationId], 'Violation recorded');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    private function createViolation($sessionId, $type, $description, $severity, $snapshotId = null, $metadata = null)
    {
        $trustImpact = match($severity) {
            'critical' => -20,
            'high' => -10,
            'medium' => -5,
            'low' => -2,
            default => -1
        };

        $sql = "INSERT INTO proc_proctor_violations (
                    session_id, violation_type, description, severity, 
                    snapshot_id, metadata, trust_impact, occurred_at
                ) VALUES (
                    :session_id, :type, :description, :severity,
                    :snapshot_id, :metadata, :trust_impact, NOW()
                )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'session_id' => $sessionId,
            'type' => $type,
            'description' => $description,
            'severity' => $severity,
            'snapshot_id' => $snapshotId,
            'metadata' => $metadata ? json_encode($metadata) : null,
            'trust_impact' => $trustImpact
        ]);

        $violationId = $this->db->lastInsertId();

        // Update trust score
        $this->updateTrustScore($sessionId, $trustImpact);

        // Update violation count on session
        $sql = "UPDATE proc_proctor_sessions SET 
                    violation_count = violation_count + 1,
                    warning_count = warning_count + 1
                WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $sessionId]);

        // Check for auto-termination
        $this->checkAutoTermination($sessionId);

        return $violationId;
    }

    public function getSessionViolations($sessionId)
    {
        try {
            $sql = "SELECT v.*, s.image_path as snapshot_path
                    FROM proc_proctor_violations v
                    LEFT JOIN proc_proctor_snapshots s ON v.snapshot_id = s.id
                    WHERE v.session_id = :session_id
                    ORDER BY v.occurred_at DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['session_id' => $sessionId]);

            return $this->success($stmt->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function reviewViolation($violationId)
    {
        try {
            $data = $this->getJsonInput();

            $sql = "UPDATE proc_proctor_violations SET 
                        reviewed = 1,
                        review_action = :action,
                        review_notes = :notes,
                        reviewed_by = :reviewed_by,
                        reviewed_at = NOW()
                    WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'id' => $violationId,
                'action' => $data['action'], // 'valid', 'dismissed', 'escalated'
                'notes' => $data['notes'] ?? null,
                'reviewed_by' => $this->userId
            ]);

            // If dismissed, restore trust impact
            if ($data['action'] === 'dismissed') {
                $sql = "SELECT session_id, trust_impact FROM proc_proctor_violations WHERE id = :id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute(['id' => $violationId]);
                $violation = $stmt->fetch(\PDO::FETCH_ASSOC);

                if ($violation) {
                    $this->updateTrustScore($violation['session_id'], abs($violation['trust_impact']));
                }
            }

            return $this->success(null, 'Violation reviewed');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== RECORDINGS ====================

    public function uploadRecordingChunk()
    {
        try {
            if (!isset($_FILES['chunk'])) {
                return $this->error('No chunk uploaded');
            }

            $sessionId = $_POST['session_id'];
            $recordingType = $_POST['recording_type']; // 'screen', 'webcam', 'audio'
            $chunkIndex = $_POST['chunk_index'];
            $totalChunks = $_POST['total_chunks'];

            $file = $_FILES['chunk'];
            $chunkPath = $this->saveFile($file, "proctoring/{$sessionId}/recordings/{$recordingType}/chunks");

            // Track chunk upload
            $sql = "INSERT INTO proc_recording_chunks (session_id, recording_type, chunk_index, chunk_path)
                    VALUES (:session_id, :type, :index, :path)
                    ON DUPLICATE KEY UPDATE chunk_path = :path";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'session_id' => $sessionId,
                'type' => $recordingType,
                'index' => $chunkIndex,
                'path' => $chunkPath
            ]);

            return $this->success([
                'chunk_index' => $chunkIndex,
                'total_chunks' => $totalChunks
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function finalizeRecording()
    {
        try {
            $data = $this->getJsonInput();
            $sessionId = $data['session_id'];
            $recordingType = $data['recording_type'];

            // In production, you would merge chunks here using FFmpeg
            // For now, just create the recording record

            $sql = "INSERT INTO proc_proctor_recordings (
                        session_id, recording_type, status, file_path, created_at
                    ) VALUES (
                        :session_id, :type, 'processing', :path, NOW()
                    )";

            $filePath = "proctoring/{$sessionId}/recordings/{$recordingType}/recording";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'session_id' => $sessionId,
                'type' => $recordingType,
                'path' => $filePath
            ]);

            return $this->success(['recording_id' => $this->db->lastInsertId()]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== SESSION REVIEW ====================

    public function reviewSession($sessionId)
    {
        try {
            $data = $this->getJsonInput();

            $sql = "UPDATE proc_proctor_sessions SET 
                        review_status = :status,
                        review_notes = :notes,
                        reviewed_by = :reviewed_by,
                        reviewed_at = NOW()
                    WHERE id = :id AND tenant_id = :tenant_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'id' => $sessionId,
                'tenant_id' => $this->tenantId,
                'status' => $data['status'], // 'approved', 'flagged', 'invalidated'
                'notes' => $data['notes'] ?? null,
                'reviewed_by' => $this->userId
            ]);

            // If invalidated, mark attempt as invalid
            if ($data['status'] === 'invalidated') {
                $sql = "UPDATE proc_assessment_attempts att
                        SET proctoring_status = 'invalid', proctoring_notes = :notes
                        WHERE id = (SELECT attempt_id FROM proc_proctor_sessions WHERE id = :session_id)";

                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    'session_id' => $sessionId,
                    'notes' => $data['notes'] ?? 'Session invalidated by reviewer'
                ]);
            }

            return $this->success(null, 'Session reviewed');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== ADMIN REVIEW QUEUE ====================

    public function getReviewQueue()
    {
        try {
            $status = $this->input('status') ?? 'pending';
            $page = (int)($this->input('page') ?? 1);
            $limit = (int)($this->input('limit') ?? 20);
            $offset = ($page - 1) * $limit;

            $where = ["ps.tenant_id = :tenant_id"];
            $params = ['tenant_id' => $this->tenantId];

            if ($status === 'pending') {
                $where[] = "ps.review_status IS NULL";
            } else {
                $where[] = "ps.review_status = :status";
                $params['status'] = $status;
            }

            $sql = "SELECT ps.*, 
                           att.attempt_number,
                           u.first_name, u.last_name, u.email,
                           a.title as assessment_title,
                           (SELECT COUNT(*) FROM proc_proctor_violations WHERE session_id = ps.id) as violation_count
                    FROM proc_proctor_sessions ps
                    INNER JOIN proc_assessment_attempts att ON ps.attempt_id = att.id
                    INNER JOIN users u ON att.user_id = u.id
                    INNER JOIN proc_assessments a ON att.assessment_id = a.id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY ps.ended_at DESC
                    LIMIT $limit OFFSET $offset";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            return $this->success($stmt->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function getFlaggedSessions()
    {
        try {
            $sql = "SELECT ps.*, 
                           att.attempt_number,
                           u.first_name, u.last_name,
                           a.title as assessment_title,
                           (SELECT COUNT(*) FROM proc_proctor_violations WHERE session_id = ps.id AND severity IN ('high', 'critical')) as critical_violations
                    FROM proc_proctor_sessions ps
                    INNER JOIN proc_assessment_attempts att ON ps.attempt_id = att.id
                    INNER JOIN users u ON att.user_id = u.id
                    INNER JOIN proc_assessments a ON att.assessment_id = a.id
                    WHERE ps.tenant_id = :tenant_id
                    AND (ps.trust_score < 70 OR ps.status = 'terminated')
                    AND ps.review_status IS NULL
                    ORDER BY ps.trust_score ASC
                    LIMIT 50";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['tenant_id' => $this->tenantId]);

            return $this->success($stmt->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== HELPER METHODS ====================

    private function updateTrustScore($sessionId, $delta)
    {
        $sql = "UPDATE proc_proctor_sessions SET 
                    trust_score = GREATEST(0, LEAST(100, trust_score + :delta))
                WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $sessionId, 'delta' => $delta]);
    }

    private function checkAutoTermination($sessionId)
    {
        $sql = "SELECT ps.*, pset.termination_threshold, pset.auto_terminate_on_critical
                FROM proc_proctor_sessions ps
                INNER JOIN proc_assessment_attempts att ON ps.attempt_id = att.id
                INNER JOIN proc_proctor_settings pset ON att.assessment_id = pset.assessment_id
                WHERE ps.id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $sessionId]);
        $session = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$session) return;

        // Check violation threshold
        if ($session['violation_count'] >= $session['termination_threshold']) {
            $this->terminateSession($sessionId);
            return;
        }

        // Check for critical violations
        if ($session['auto_terminate_on_critical']) {
            $sql = "SELECT COUNT(*) FROM proc_proctor_violations WHERE session_id = :id AND severity = 'critical'";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $sessionId]);
            $criticalCount = $stmt->fetchColumn();

            if ($criticalCount > 0) {
                $this->terminateSession($sessionId);
            }
        }
    }

    private function saveFile($file, $subfolder)
    {
        $basePath = defined('PROC_STORAGE_PATH') ? PROC_STORAGE_PATH : 'uploads';
        $targetDir = "{$basePath}/tenant_{$this->tenantId}/{$subfolder}";

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $fileName = uniqid() . '_' . basename($file['name']);
        $targetPath = "{$targetDir}/{$fileName}";

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new \Exception('Failed to save file');
        }

        return "tenant_{$this->tenantId}/{$subfolder}/{$fileName}";
    }

    private function saveContent($content, $subfolder, $fileName)
    {
        $basePath = defined('PROC_STORAGE_PATH') ? PROC_STORAGE_PATH : 'uploads';
        $targetDir = "{$basePath}/tenant_{$this->tenantId}/{$subfolder}";

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $targetPath = "{$targetDir}/{$fileName}";
        file_put_contents($targetPath, $content);

        return "tenant_{$this->tenantId}/{$subfolder}/{$fileName}";
    }
}
