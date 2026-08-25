<?php
/**
 * Question Controller
 * Handles question banks, categories, tags, and questions
 */

namespace App\Controllers\ProctoredAssessment;

use App\Models\ProctoredAssessment\Question;

class QuestionController extends BaseController
{
    private $questionModel;

    public function __construct()
    {
        parent::__construct();
        $this->questionModel = new Question($this->db);
    }

    // ==================== QUESTION BANKS ====================

    public function getQuestionBanks()
    {
        try {
            $status = $this->input('status');
            $where = ["tenant_id = :tenant_id"];
            $params = ['tenant_id' => $this->tenantId];

            if ($status) {
                $where[] = "status = :status";
                $params['status'] = $status;
            }

            $sql = "SELECT qb.*, 
                           (SELECT COUNT(*) FROM proc_questions WHERE question_bank_id = qb.id AND status != 'archived') as question_count,
                           u.first_name as creator_first_name, u.last_name as creator_last_name
                    FROM proc_question_banks qb
                    LEFT JOIN users u ON qb.created_by = u.id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY qb.name";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            return $this->success($stmt->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function getQuestionBank($id)
    {
        try {
            $sql = "SELECT qb.*, 
                           (SELECT COUNT(*) FROM proc_questions WHERE question_bank_id = qb.id AND status != 'archived') as question_count
                    FROM proc_question_banks qb
                    WHERE qb.id = :id AND qb.tenant_id = :tenant_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id, 'tenant_id' => $this->tenantId]);
            $bank = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$bank) {
                return $this->notFound('Question bank not found');
            }

            // Get categories
            $bank['categories'] = $this->getCategoriesForBank($id);

            return $this->success($bank);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function createQuestionBank()
    {
        try {
            $data = $this->getJsonInput();

            if (empty($data['name'])) {
                return $this->error('Name is required');
            }

            $sql = "INSERT INTO proc_question_banks (tenant_id, name, description, status, created_by)
                    VALUES (:tenant_id, :name, :description, :status, :created_by)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'tenant_id' => $this->tenantId,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'active',
                'created_by' => $this->userId
            ]);

            $bankId = $this->db->lastInsertId();
            $this->logAction('create', 'question_bank', $bankId);

            return $this->success(['id' => $bankId], 'Question bank created successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function updateQuestionBank($id)
    {
        try {
            $data = $this->getJsonInput();

            $sql = "UPDATE proc_question_banks SET
                        name = :name,
                        description = :description,
                        status = :status,
                        updated_at = NOW()
                    WHERE id = :id AND tenant_id = :tenant_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'id' => $id,
                'tenant_id' => $this->tenantId,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? 'active'
            ]);

            return $this->success(null, 'Question bank updated successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function deleteQuestionBank($id)
    {
        try {
            // Check if bank has questions
            $sql = "SELECT COUNT(*) FROM proc_questions WHERE question_bank_id = :id AND status != 'archived'";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id]);
            $count = $stmt->fetchColumn();

            if ($count > 0) {
                return $this->error('Cannot delete question bank with existing questions. Archive questions first.');
            }

            $sql = "UPDATE proc_question_banks SET status = 'archived' WHERE id = :id AND tenant_id = :tenant_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id, 'tenant_id' => $this->tenantId]);

            return $this->success(null, 'Question bank deleted successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== CATEGORIES ====================

    public function getCategories($bankId)
    {
        try {
            $categories = $this->getCategoriesForBank($bankId);
            return $this->success($categories);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    private function getCategoriesForBank($bankId)
    {
        $sql = "SELECT c.*, 
                       (SELECT COUNT(*) FROM proc_questions WHERE category_id = c.category_id AND status != 'archived') as question_count
                FROM proc_question_categories c
                WHERE c.bank_id = :bank_id
                ORDER BY c.sort_order, c.name";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['bank_id' => $bankId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function createCategory($bankId)
    {
        try {
            $data = $this->getJsonInput();

            $sql = "INSERT INTO proc_question_bank_categories (question_bank_id, parent_id, name, description, sort_order)
                    VALUES (:bank_id, :parent_id, :name, :description, :sort_order)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'bank_id' => $bankId,
                'parent_id' => $data['parent_id'] ?? null,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0
            ]);

            return $this->success(['id' => $this->db->lastInsertId()], 'Category created successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function updateCategory($id)
    {
        try {
            $data = $this->getJsonInput();

            $sql = "UPDATE proc_question_bank_categories SET
                        name = :name,
                        description = :description,
                        parent_id = :parent_id,
                        sort_order = :sort_order
                    WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'id' => $id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'parent_id' => $data['parent_id'] ?? null,
                'sort_order' => $data['sort_order'] ?? 0
            ]);

            return $this->success(null, 'Category updated successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function deleteCategory($id)
    {
        try {
            // Move questions to uncategorized
            $sql = "UPDATE proc_questions SET category_id = NULL WHERE category_id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id]);

            // Delete category
            $sql = "DELETE FROM proc_question_bank_categories WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id]);

            return $this->success(null, 'Category deleted successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== TAGS ====================

    public function getTags()
    {
        try {
            $sql = "SELECT * FROM proc_question_tags WHERE tenant_id = :tenant_id ORDER BY name";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['tenant_id' => $this->tenantId]);
            
            return $this->success($stmt->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function createTag()
    {
        try {
            $data = $this->getJsonInput();

            $sql = "INSERT INTO proc_question_tags (tenant_id, name, color) VALUES (:tenant_id, :name, :color)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'tenant_id' => $this->tenantId,
                'name' => $data['name'],
                'color' => $data['color'] ?? '#6c757d'
            ]);

            return $this->success(['id' => $this->db->lastInsertId()], 'Tag created successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function updateTag($id)
    {
        try {
            $data = $this->getJsonInput();

            $sql = "UPDATE proc_question_tags SET name = :name, color = :color WHERE id = :id AND tenant_id = :tenant_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'id' => $id,
                'tenant_id' => $this->tenantId,
                'name' => $data['name'],
                'color' => $data['color'] ?? '#6c757d'
            ]);

            return $this->success(null, 'Tag updated successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function deleteTag($id)
    {
        try {
            // Remove tag associations
            $sql = "DELETE FROM proc_question_tag_map WHERE tag_id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id]);

            // Delete tag
            $sql = "DELETE FROM proc_question_tags WHERE id = :id AND tenant_id = :tenant_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id, 'tenant_id' => $this->tenantId]);

            return $this->success(null, 'Tag deleted successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== QUESTIONS ====================

    public function index()
    {
        try {
            $filters = [
                'question_bank_id' => $this->input('question_bank_id'),
                'category_id' => $this->input('category_id'),
                'question_type' => $this->input('question_type'),
                'difficulty' => $this->input('difficulty'),
                'status' => $this->input('status'),
                'search' => $this->input('search')
            ];

            $page = (int)($this->input('page') ?? 1);
            $limit = (int)($this->input('limit') ?? 20);

            $result = $this->questionModel->getAll($this->tenantId, $filters, $page, $limit);
            
            return $this->json($result);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function show($id)
    {
        try {
            $question = $this->questionModel->getById($id, $this->tenantId);

            if (!$question) {
                return $this->notFound('Question not found');
            }

            return $this->success($question);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function store()
    {
        try {
            $data = $this->getJsonInput();
            $this->validateQuestion($data);

            $data['tenant_id'] = $this->tenantId;
            $data['created_by'] = $this->userId;

            $questionId = $this->questionModel->create($data);

            $this->logAction('create', 'question', $questionId);

            return $this->success(['id' => $questionId], 'Question created successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function update($id)
    {
        try {
            $question = $this->questionModel->getById($id, $this->tenantId);
            if (!$question) {
                return $this->notFound('Question not found');
            }

            $data = $this->getJsonInput();
            $data['tenant_id'] = $this->tenantId;
            $data['updated_by'] = $this->userId;

            $this->questionModel->update($id, $data);

            $this->logAction('update', 'question', $id);

            return $this->success(null, 'Question updated successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function destroy($id)
    {
        try {
            $this->questionModel->delete($id, $this->tenantId);
            $this->logAction('delete', 'question', $id);

            return $this->success(null, 'Question deleted successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function clone($id)
    {
        try {
            $question = $this->questionModel->getById($id, $this->tenantId);
            if (!$question) {
                return $this->notFound('Question not found');
            }

            $question['tenant_id'] = $this->tenantId;
            $question['created_by'] = $this->userId;
            $question['question_text'] = $question['question_text'] . ' (Copy)';
            $question['status'] = 'draft';
            unset($question['id'], $question['created_at'], $question['updated_at'], $question['version']);

            $newId = $this->questionModel->create($question);

            return $this->success(['id' => $newId], 'Question cloned successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function preview($id)
    {
        try {
            $question = $this->questionModel->getById($id, $this->tenantId);
            if (!$question) {
                return $this->notFound('Question not found');
            }

            // Shuffle options for preview
            if (!empty($question['options'])) {
                shuffle($question['options']);
                foreach ($question['options'] as &$opt) {
                    unset($opt['is_correct'], $opt['acceptable_answers'], $opt['match_text'], $opt['correct_position']);
                }
            }

            return $this->success($question);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function statistics($id)
    {
        try {
            $sql = "SELECT 
                        COUNT(*) as times_used,
                        AVG(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) * 100 as correct_rate,
                        AVG(time_spent_seconds) as avg_time_spent,
                        AVG(points_earned / points_possible) as avg_score_rate
                    FROM proc_attempt_answers aa
                    INNER JOIN proc_assessment_attempts att ON aa.attempt_id = att.id
                    WHERE aa.question_id = :id AND att.status = 'graded'";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id]);
            $stats = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Option distribution for MCQ
            $sql = "SELECT 
                        COALESCE(JSON_UNQUOTE(JSON_EXTRACT(answer_data, '$.selected_option_id')), 'no_answer') as selected,
                        COUNT(*) as count
                    FROM proc_attempt_answers aa
                    INNER JOIN proc_assessment_attempts att ON aa.attempt_id = att.id
                    WHERE aa.question_id = :id AND att.status = 'graded'
                    GROUP BY selected";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id]);
            $stats['option_distribution'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            return $this->success($stats);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function getVersions($id)
    {
        try {
            $sql = "SELECT qv.*, u.first_name, u.last_name
                    FROM proc_question_versions qv
                    LEFT JOIN users u ON qv.changed_by = u.id
                    WHERE qv.question_id = :id
                    ORDER BY qv.version DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id]);

            return $this->success($stmt->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function restoreVersion($id)
    {
        try {
            $data = $this->getJsonInput();
            $version = $data['version'];

            $sql = "SELECT question_data FROM proc_question_versions WHERE question_id = :id AND version = :version";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id, 'version' => $version]);
            $versionData = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$versionData) {
                return $this->notFound('Version not found');
            }

            $questionData = json_decode($versionData['question_data'], true);
            $questionData['tenant_id'] = $this->tenantId;
            $questionData['updated_by'] = $this->userId;

            $this->questionModel->update($id, $questionData);

            return $this->success(null, 'Question restored to version ' . $version);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== BULK OPERATIONS ====================

    public function bulkDelete()
    {
        try {
            $data = $this->getJsonInput();
            $ids = $data['question_ids'] ?? [];

            if (empty($ids)) {
                return $this->error('No questions selected');
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE proc_questions SET status = 'archived' WHERE id IN ($placeholders) AND tenant_id = ?";
            
            $stmt = $this->db->prepare($sql);
            $params = array_merge($ids, [$this->tenantId]);
            $stmt->execute($params);

            return $this->success(['deleted_count' => count($ids)], 'Questions deleted successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function bulkMove()
    {
        try {
            $data = $this->getJsonInput();
            $ids = $data['question_ids'] ?? [];
            $targetBankId = $data['question_bank_id'];
            $targetCategoryId = $data['category_id'] ?? null;

            if (empty($ids)) {
                return $this->error('No questions selected');
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE proc_questions SET question_bank_id = ?, category_id = ? WHERE id IN ($placeholders) AND tenant_id = ?";
            
            $stmt = $this->db->prepare($sql);
            $params = array_merge([$targetBankId, $targetCategoryId], $ids, [$this->tenantId]);
            $stmt->execute($params);

            return $this->success(['moved_count' => count($ids)], 'Questions moved successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function bulkUpdateDifficulty()
    {
        try {
            $data = $this->getJsonInput();
            $ids = $data['question_ids'] ?? [];
            $difficulty = $data['difficulty'];

            if (empty($ids)) {
                return $this->error('No questions selected');
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE proc_questions SET difficulty = ? WHERE id IN ($placeholders) AND tenant_id = ?";
            
            $stmt = $this->db->prepare($sql);
            $params = array_merge([$difficulty], $ids, [$this->tenantId]);
            $stmt->execute($params);

            return $this->success(null, 'Difficulty updated successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function bulkUpdateStatus()
    {
        try {
            $data = $this->getJsonInput();
            $ids = $data['question_ids'] ?? [];
            $status = $data['status'];

            if (empty($ids)) {
                return $this->error('No questions selected');
            }

            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE proc_questions SET status = ? WHERE id IN ($placeholders) AND tenant_id = ?";
            
            $stmt = $this->db->prepare($sql);
            $params = array_merge([$status], $ids, [$this->tenantId]);
            $stmt->execute($params);

            return $this->success(null, 'Status updated successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== IMPORT/EXPORT ====================

    public function importQuestions()
    {
        try {
            if (!isset($_FILES['file'])) {
                return $this->error('No file uploaded');
            }

            $data = $this->getJsonInput();
            $bankId = $data['question_bank_id'];
            $file = $_FILES['file'];

            // Create import job
            $sql = "INSERT INTO proc_import_jobs (tenant_id, type, file_name, file_path, status, started_by)
                    VALUES (:tenant_id, 'questions', :file_name, :file_path, 'pending', :started_by)";

            $filePath = $this->saveUploadedFile($file, 'imports');

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'tenant_id' => $this->tenantId,
                'file_name' => $file['name'],
                'file_path' => $filePath,
                'started_by' => $this->userId
            ]);

            $jobId = $this->db->lastInsertId();

            // Process import (in production, this would be queued)
            $this->processImport($jobId, $bankId);

            return $this->success(['job_id' => $jobId], 'Import started');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function getImportStatus($jobId)
    {
        try {
            $sql = "SELECT * FROM proc_import_jobs WHERE id = :id AND tenant_id = :tenant_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $jobId, 'tenant_id' => $this->tenantId]);
            $job = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$job) {
                return $this->notFound('Import job not found');
            }

            if ($job['error_log']) {
                $job['error_log'] = json_decode($job['error_log'], true);
            }

            return $this->success($job);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function downloadImportTemplate()
    {
        $headers = [
            'question_type', 'question_text', 'explanation', 'hint', 'points', 'difficulty',
            'option_1', 'option_1_correct', 'option_2', 'option_2_correct',
            'option_3', 'option_3_correct', 'option_4', 'option_4_correct',
            'category', 'tags'
        ];

        $sample = [
            'multiple_choice_single', 'What is 2+2?', 'Basic math', 'Think carefully', '1', 'easy',
            '3', '0', '4', '1', '5', '0', '6', '0', 'Mathematics', 'math,arithmetic'
        ];

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="question_import_template.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, $headers);
        fputcsv($output, $sample);
        fclose($output);
        exit;
    }

    public function exportBank($bankId)
    {
        try {
            $format = $this->input('format') ?? 'csv';

            $questions = $this->questionModel->getAll($this->tenantId, ['question_bank_id' => $bankId], 1, 10000);

            if ($format === 'json') {
                header('Content-Type: application/json');
                header('Content-Disposition: attachment; filename="question_bank_export.json"');
                echo json_encode($questions['data'], JSON_PRETTY_PRINT);
                exit;
            }

            // CSV export
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="question_bank_export.csv"');

            $output = fopen('php://output', 'w');
            
            // Headers
            fputcsv($output, ['ID', 'Type', 'Question', 'Explanation', 'Points', 'Difficulty', 'Status']);

            foreach ($questions['data'] as $q) {
                fputcsv($output, [
                    $q['id'],
                    $q['question_type'],
                    $q['question_text'],
                    $q['explanation'],
                    $q['points'],
                    $q['difficulty'],
                    $q['status']
                ]);
            }

            fclose($output);
            exit;
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== MEDIA ====================

    public function uploadMedia($id)
    {
        try {
            if (!isset($_FILES['file'])) {
                return $this->error('No file uploaded');
            }

            $file = $_FILES['file'];
            $type = $_POST['type'] ?? 'image';

            $filePath = $this->saveUploadedFile($file, "questions/{$id}");

            $sql = "INSERT INTO proc_question_media (question_id, media_type, file_name, file_path, file_size, mime_type)
                    VALUES (:question_id, :media_type, :file_name, :file_path, :file_size, :mime_type)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'question_id' => $id,
                'media_type' => $type,
                'file_name' => $file['name'],
                'file_path' => $filePath,
                'file_size' => $file['size'],
                'mime_type' => $file['type']
            ]);

            return $this->success([
                'id' => $this->db->lastInsertId(),
                'path' => $filePath
            ], 'Media uploaded successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    public function deleteMedia($id, $mediaId)
    {
        try {
            $sql = "SELECT file_path FROM proc_question_media WHERE id = :id AND question_id = :question_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $mediaId, 'question_id' => $id]);
            $media = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($media) {
                // Delete file
                $fullPath = $this->getStoragePath() . '/' . $media['file_path'];
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                }

                // Delete record
                $sql = "DELETE FROM proc_question_media WHERE id = :id";
                $stmt = $this->db->prepare($sql);
                $stmt->execute(['id' => $mediaId]);
            }

            return $this->success(null, 'Media deleted successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== GRADING TEST ====================

    public function gradeAnswer($id)
    {
        try {
            $data = $this->getJsonInput();
            $answerData = $data['answer_data'];

            $result = $this->questionModel->gradeAnswer($id, $answerData, $this->tenantId);

            return $this->success($result);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== HELPERS ====================

    private function validateQuestion($data)
    {
        if (empty($data['question_bank_id'])) {
            throw new \Exception('Question bank is required');
        }
        if (empty($data['question_type'])) {
            throw new \Exception('Question type is required');
        }
        if (empty($data['question_text'])) {
            throw new \Exception('Question text is required');
        }

        $validTypes = [
            'multiple_choice_single', 'multiple_choice_multiple', 'true_false',
            'fill_in_blanks', 'short_answer', 'long_answer', 'matching',
            'ordering', 'hotspot', 'numeric', 'file_upload'
        ];

        if (!in_array($data['question_type'], $validTypes)) {
            throw new \Exception('Invalid question type');
        }
    }

    private function processImport($jobId, $bankId)
    {
        // Update job status
        $sql = "UPDATE proc_import_jobs SET status = 'processing', started_at = NOW() WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $jobId]);

        // Get job details
        $sql = "SELECT * FROM proc_import_jobs WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $jobId]);
        $job = $stmt->fetch(\PDO::FETCH_ASSOC);

        $filePath = $this->getStoragePath() . '/' . $job['file_path'];
        
        $imported = 0;
        $failed = 0;
        $errors = [];

        if (($handle = fopen($filePath, 'r')) !== false) {
            $headers = fgetcsv($handle);
            $lineNum = 1;

            while (($row = fgetcsv($handle)) !== false) {
                $lineNum++;
                try {
                    $data = array_combine($headers, $row);
                    
                    $questionData = [
                        'tenant_id' => $this->tenantId,
                        'question_bank_id' => $bankId,
                        'question_type' => $data['question_type'],
                        'question_text' => $data['question_text'],
                        'explanation' => $data['explanation'] ?? null,
                        'hint' => $data['hint'] ?? null,
                        'points' => $data['points'] ?? 1,
                        'difficulty' => $data['difficulty'] ?? 'medium',
                        'created_by' => $job['started_by'],
                        'options' => []
                    ];

                    // Parse options
                    for ($i = 1; $i <= 10; $i++) {
                        if (!empty($data["option_{$i}"])) {
                            $questionData['options'][] = [
                                'option_text' => $data["option_{$i}"],
                                'is_correct' => $data["option_{$i}_correct"] ?? 0,
                                'sort_order' => $i - 1
                            ];
                        }
                    }

                    $this->questionModel->create($questionData);
                    $imported++;
                } catch (\Exception $e) {
                    $failed++;
                    $errors[] = "Line {$lineNum}: " . $e->getMessage();
                }
            }

            fclose($handle);
        }

        // Update job completion
        $sql = "UPDATE proc_import_jobs SET 
                    status = 'completed',
                    completed_at = NOW(),
                    total_rows = :total,
                    success_count = :success,
                    error_count = :errors,
                    error_log = :error_log
                WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $jobId,
            'total' => $imported + $failed,
            'success' => $imported,
            'errors' => $failed,
            'error_log' => json_encode($errors)
        ]);
    }

    private function saveUploadedFile($file, $subfolder)
    {
        $basePath = $this->getStoragePath();
        $targetDir = "{$basePath}/tenant_{$this->tenantId}/assessment/{$subfolder}";

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $fileName = uniqid() . '_' . basename($file['name']);
        $targetPath = "{$targetDir}/{$fileName}";

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new \Exception('Failed to save uploaded file');
        }

        return "tenant_{$this->tenantId}/assessment/{$subfolder}/{$fileName}";
    }

    private function getStoragePath()
    {
        return defined('PROC_STORAGE_PATH') ? PROC_STORAGE_PATH : 'uploads';
    }
}
