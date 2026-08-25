<?php
/**
 * Assessment Controller
 * Handles all assessment-related API endpoints
 */

namespace App\Controllers\ProctoredAssessment;

use App\Models\ProctoredAssessment\Assessment;
use App\Models\ProctoredAssessment\Question;

class AssessmentController extends BaseController
{
    private $assessmentModel;
    private $questionModel;

    public function __construct()
    {
        parent::__construct();
        $this->assessmentModel = new Assessment($this->db);
        $this->questionModel = new Question($this->db);
    }

    // ==================== CRUD OPERATIONS ====================

    /**
     * GET /api/proc/assessments
     * List all assessments with filters
     */
    public function index()
    {
        try {
            $filters = [
                'status' => $this->input('status'),
                'course_id' => $this->input('course_id'),
                'live_training_id' => $this->input('live_training_id'),
                'is_standalone' => $this->input('is_standalone'),
                'proctoring_level' => $this->input('proctoring_level'),
                'search' => $this->input('search'),
                'created_by' => $this->input('created_by')
            ];
            
            $page = (int)($this->input('page') ?? 1);
            $limit = (int)($this->input('limit') ?? 20);

            $result = $this->assessmentModel->getAll($this->tenantId, $filters, $page, $limit);
            
            return $this->json($result);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * GET /api/proc/assessments/{id}
     * Get single assessment details
     */
    public function show($id)
    {
        try {
            $assessment = $this->assessmentModel->getById($id, $this->tenantId);
            
            if (!$assessment) {
                return $this->notFound('Assessment not found');
            }
            
            return $this->success($assessment);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * POST /api/proc/assessments
     * Create new assessment
     */
    public function store()
    {
        try {
            $data = $this->getJsonInput();
            $this->validateAssessment($data);
            
            $data['tenant_id'] = $this->tenantId;
            $data['created_by'] = $this->userId;
            
            $assessmentId = $this->assessmentModel->create($data);
            
            $this->logAction('create', 'assessment', $assessmentId, null, $data);
            
            return $this->success(['id' => $assessmentId], 'Assessment created successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * PUT /api/proc/assessments/{id}
     * Update assessment
     */
    public function update($id)
    {
        try {
            $assessment = $this->assessmentModel->getById($id, $this->tenantId, false);
            if (!$assessment) {
                return $this->notFound('Assessment not found');
            }
            
            // Don't allow editing published assessments with attempts
            if ($assessment['status'] === 'published' && $assessment['total_attempts'] > 0) {
                return $this->error('Cannot edit assessment with existing attempts');
            }
            
            $data = $this->getJsonInput();
            $data['tenant_id'] = $this->tenantId;
            $data['updated_by'] = $this->userId;
            
            $this->assessmentModel->update($id, $data);
            
            $this->logAction('update', 'assessment', $id, $assessment, $data);
            
            return $this->success(null, 'Assessment updated successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * DELETE /api/proc/assessments/{id}
     * Delete assessment (soft delete)
     */
    public function destroy($id)
    {
        try {
            $assessment = $this->assessmentModel->getById($id, $this->tenantId, false);
            if (!$assessment) {
                return $this->notFound('Assessment not found');
            }
            
            $this->assessmentModel->delete($id, $this->tenantId);
            
            $this->logAction('delete', 'assessment', $id);
            
            return $this->success(null, 'Assessment deleted successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * POST /api/proc/assessments/{id}/clone
     * Clone assessment
     */
    public function clone($id)
    {
        try {
            $newId = $this->assessmentModel->clone($id, $this->tenantId, $this->userId);
            
            if (!$newId) {
                return $this->error('Failed to clone assessment');
            }
            
            return $this->success(['id' => $newId], 'Assessment cloned successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * POST /api/proc/assessments/{id}/publish
     * Publish assessment
     */
    public function publish($id)
    {
        try {
            $this->assessmentModel->publish($id, $this->tenantId);
            
            $this->logAction('publish', 'assessment', $id);
            
            return $this->success(null, 'Assessment published successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * POST /api/proc/assessments/{id}/close
     * Close assessment
     */
    public function close($id)
    {
        try {
            $sql = "UPDATE proc_assessments SET status = 'closed', closed_at = NOW() 
                    WHERE id = :id AND tenant_id = :tenant_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id, 'tenant_id' => $this->tenantId]);
            
            $this->logAction('archive', 'assessment', $id);
            
            return $this->success(null, 'Assessment closed successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== SECTIONS ====================

    /**
     * GET /api/proc/assessments/{id}/sections
     */
    public function getSections($id)
    {
        try {
            $sections = $this->assessmentModel->getSections($id);
            return $this->success($sections);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * POST /api/proc/assessments/{id}/sections
     */
    public function createSection($id)
    {
        try {
            $data = $this->getJsonInput();
            $data['assessment_id'] = $id;
            
            $sectionId = $this->assessmentModel->createSection($data);
            
            return $this->success(['id' => $sectionId], 'Section created successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * PUT /api/proc/assessments/{id}/sections/{sectionId}
     */
    public function updateSection($id, $sectionId)
    {
        try {
            $data = $this->getJsonInput();
            
            $sql = "UPDATE proc_assessment_sections SET 
                        title = :title,
                        description = :description,
                        instructions = :instructions,
                        time_limit_minutes = :time_limit_minutes,
                        selection_type = :selection_type,
                        random_count = :random_count,
                        updated_at = NOW()
                    WHERE id = :id AND assessment_id = :assessment_id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'id' => $sectionId,
                'assessment_id' => $id,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'instructions' => $data['instructions'] ?? null,
                'time_limit_minutes' => $data['time_limit_minutes'] ?? null,
                'selection_type' => $data['selection_type'] ?? 'all',
                'random_count' => $data['random_count'] ?? null
            ]);
            
            return $this->success(null, 'Section updated successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * DELETE /api/proc/assessments/{id}/sections/{sectionId}
     */
    public function deleteSection($id, $sectionId)
    {
        try {
            $sql = "DELETE FROM proc_assessment_sections WHERE id = :id AND assessment_id = :assessment_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $sectionId, 'assessment_id' => $id]);
            
            return $this->success(null, 'Section deleted successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== QUESTIONS ====================

    /**
     * GET /api/proc/assessments/{id}/questions
     */
    public function getQuestions($id)
    {
        try {
            $questions = $this->assessmentModel->getQuestions($id);
            return $this->success($questions);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * POST /api/proc/assessments/{id}/questions
     * Add questions to assessment
     */
    public function addQuestions($id)
    {
        try {
            $data = $this->getJsonInput();
            $questions = $data['questions'] ?? [];
            
            if (empty($questions)) {
                return $this->error('No questions provided');
            }
            
            $this->assessmentModel->addQuestions($id, $questions);
            
            return $this->success(null, 'Questions added successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * PUT /api/proc/assessments/{id}/questions/{questionId}
     */
    public function updateAssessmentQuestion($id, $questionId)
    {
        try {
            $data = $this->getJsonInput();
            
            $sql = "UPDATE proc_assessment_questions SET 
                        section_id = :section_id,
                        points_override = :points_override,
                        time_limit_override = :time_limit_override,
                        required = :required
                    WHERE assessment_id = :assessment_id AND question_id = :question_id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'assessment_id' => $id,
                'question_id' => $questionId,
                'section_id' => $data['section_id'] ?? null,
                'points_override' => $data['points_override'] ?? null,
                'time_limit_override' => $data['time_limit_override'] ?? null,
                'required' => $data['required'] ?? 0
            ]);
            
            return $this->success(null, 'Question updated successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * DELETE /api/proc/assessments/{id}/questions/{questionId}
     */
    public function removeQuestion($id, $questionId)
    {
        try {
            $this->assessmentModel->removeQuestion($id, $questionId);
            return $this->success(null, 'Question removed successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * PUT /api/proc/assessments/{id}/questions/reorder
     */
    public function reorderQuestions($id)
    {
        try {
            $data = $this->getJsonInput();
            $questionIds = $data['question_ids'] ?? [];
            
            $this->assessmentModel->reorderQuestions($id, $questionIds);
            
            return $this->success(null, 'Questions reordered successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== RANDOM RULES ====================

    /**
     * GET /api/proc/assessments/{id}/random-rules
     */
    public function getRandomRules($id)
    {
        try {
            $rules = $this->assessmentModel->getRandomRules($id);
            return $this->success($rules);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * POST /api/proc/assessments/{id}/random-rules
     */
    public function createRandomRule($id)
    {
        try {
            $data = $this->getJsonInput();
            $data['assessment_id'] = $id;
            
            $ruleId = $this->assessmentModel->createRandomRule($data);
            
            return $this->success(['id' => $ruleId], 'Random rule created successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * DELETE /api/proc/assessments/{id}/random-rules/{ruleId}
     */
    public function deleteRandomRule($id, $ruleId)
    {
        try {
            $sql = "DELETE FROM proc_assessment_random_rules WHERE id = :id AND assessment_id = :assessment_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $ruleId, 'assessment_id' => $id]);
            
            return $this->success(null, 'Random rule deleted successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== ASSIGNMENTS ====================

    /**
     * GET /api/proc/assessments/{id}/assignments
     */
    public function getAssignments($id)
    {
        try {
            $sql = "SELECT aa.*, 
                           u.first_name, u.last_name, u.email,
                           t.team_name as group_name,
                           d.department_name as department_name
                    FROM proc_assessment_assignments aa
                    LEFT JOIN users u ON aa.user_id = u.id
                    LEFT JOIN teams t ON aa.group_id = t.team_id
                    LEFT JOIN departments d ON aa.department_id = d.department_id
                    WHERE aa.assessment_id = :assessment_id AND aa.tenant_id = :tenant_id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['assessment_id' => $id, 'tenant_id' => $this->tenantId]);
            
            return $this->success($stmt->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * POST /api/proc/assessments/{id}/assign
     */
    public function assign($id)
    {
        try {
            $data = $this->getJsonInput();
            $data['tenant_id'] = $this->tenantId;
            $data['assigned_by'] = $this->userId;
            
            $count = $this->assessmentModel->assign($id, $data);
            
            return $this->success(['assigned_count' => $count], 'Assignment created successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * DELETE /api/proc/assessments/{id}/assignments/{assignmentId}
     */
    public function unassign($id, $assignmentId)
    {
        try {
            $sql = "DELETE FROM proc_assessment_assignments 
                    WHERE id = :id AND assessment_id = :assessment_id AND tenant_id = :tenant_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'id' => $assignmentId, 
                'assessment_id' => $id, 
                'tenant_id' => $this->tenantId
            ]);
            
            return $this->success(null, 'Assignment removed successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== PROCTOR SETTINGS ====================

    /**
     * GET /api/proc/assessments/{id}/proctor-settings
     */
    public function getProctorSettings($id)
    {
        try {
            $settings = $this->assessmentModel->getProctorSettings($id);
            return $this->success($settings);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * PUT /api/proc/assessments/{id}/proctor-settings
     */
    public function updateProctorSettings($id)
    {
        try {
            $data = $this->getJsonInput();
            
            // Get assessment to get proctoring level
            $assessment = $this->assessmentModel->getById($id, $this->tenantId, false);
            if (!$assessment) {
                return $this->notFound('Assessment not found');
            }
            
            $this->assessmentModel->updateProctorSettings($id, $assessment['proctoring_level'], $data);
            
            return $this->success(null, 'Proctor settings updated successfully');
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== STATISTICS ====================

    /**
     * GET /api/proc/assessments/{id}/statistics
     */
    public function getStatistics($id)
    {
        try {
            // Get basic stats
            $sql = "SELECT 
                        COUNT(*) as total_attempts,
                        SUM(CASE WHEN status = 'graded' THEN 1 ELSE 0 END) as completions,
                        AVG(CASE WHEN status = 'graded' THEN percentage_score ELSE NULL END) as avg_score,
                        AVG(CASE WHEN status = 'graded' THEN time_spent_seconds ELSE NULL END) as avg_time,
                        SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) as passed_count,
                        MIN(CASE WHEN status = 'graded' THEN percentage_score ELSE NULL END) as min_score,
                        MAX(CASE WHEN status = 'graded' THEN percentage_score ELSE NULL END) as max_score
                    FROM proc_assessment_attempts
                    WHERE assessment_id = :id AND tenant_id = :tenant_id
                    AND status IN ('submitted', 'grading', 'graded')";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id, 'tenant_id' => $this->tenantId]);
            $stats = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            // Calculate pass rate
            $stats['pass_rate'] = $stats['completions'] > 0 
                ? ($stats['passed_count'] / $stats['completions']) * 100 
                : 0;
            
            // Score distribution
            $sql = "SELECT 
                    score_range,
                    COUNT(*) AS total
                FROM (
                    SELECT
                        CASE 
                            WHEN percentage_score >= 90 THEN '90-100'
                            WHEN percentage_score >= 80 THEN '80-89'
                            WHEN percentage_score >= 70 THEN '70-79'
                            WHEN percentage_score >= 60 THEN '60-69'
                            WHEN percentage_score >= 50 THEN '50-59'
                            ELSE 'Below 50'
                        END AS score_range
                    FROM proc_assessment_attempts
                    WHERE assessment_id = :id
                      AND tenant_id = :tenant_id
                      AND status = 'graded'
                ) t
                GROUP BY score_range
                ORDER BY
                    CASE score_range
                        WHEN '90-100' THEN 6
                        WHEN '80-89' THEN 5
                        WHEN '70-79' THEN 4
                        WHEN '60-69' THEN 3
                        WHEN '50-59' THEN 2
                        ELSE 1
                    END DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id, 'tenant_id' => $this->tenantId]);
            $stats['score_distribution'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Question stats
            $sql = "SELECT 
                        aa.question_id,
                        AVG(CASE WHEN aa.is_correct = 1 THEN 1 ELSE 0 END) * 100 as correct_rate,
                        AVG(aa.time_spent_seconds) as avg_time
                    FROM proc_attempt_answers aa
                    INNER JOIN proc_assessment_attempts att ON aa.attempt_id = att.id
                    WHERE att.assessment_id = :id AND att.tenant_id = :tenant_id AND att.status = 'graded'
                    GROUP BY aa.question_id";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id, 'tenant_id' => $this->tenantId]);
            $stats['question_stats'] = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            return $this->success($stats);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== EXPORT ====================

    /**
     * GET /api/proc/assessments/{id}/export-results
     */
    public function exportResults($id)
    {
        try {
            $format = $this->input('format') ?? 'excel';
            
            // Get all attempts with details
            $sql = "SELECT 
                        att.*,
                        u.first_name, u.last_name, u.email,
                        a.title as assessment_title
                    FROM proc_assessment_attempts att
                    INNER JOIN users u ON att.user_id = u.id
                    INNER JOIN proc_assessments a ON att.assessment_id = a.id
                    WHERE att.assessment_id = :id AND att.tenant_id = :tenant_id
                    AND att.status IN ('submitted', 'grading', 'graded')
                    ORDER BY att.submitted_at DESC";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id, 'tenant_id' => $this->tenantId]);
            $attempts = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            if ($format === 'csv') {
                return $this->exportCsv($attempts);
            } else {
                return $this->exportExcel($attempts);
            }
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== HELPER METHODS ====================

    private function validateAssessment($data)
    {
        if (empty($data['title'])) {
            throw new \Exception('Title is required');
        }
        
        if (isset($data['passing_score']) && ($data['passing_score'] < 0 || $data['passing_score'] > 100)) {
            throw new \Exception('Passing score must be between 0 and 100');
        }
        
        if (isset($data['max_attempts']) && $data['max_attempts'] < 1) {
            throw new \Exception('Maximum attempts must be at least 1');
        }
    }

    private function exportCsv($data)
    {
        $filename = 'assessment_results_' . date('Y-m-d') . '.csv';
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        // Header row
        fputcsv($output, [
            'Learner Name', 'Email', 'Attempt #', 'Status', 
            'Score', 'Percentage', 'Passed', 'Time Spent', 
            'Started', 'Submitted', 'Trust Score', 'Violations'
        ]);
        
        foreach ($data as $row) {
            fputcsv($output, [
                $row['first_name'] . ' ' . $row['last_name'],
                $row['email'],
                $row['attempt_number'],
                $row['status'],
                $row['raw_score'] . '/' . $row['total_possible'],
                $row['percentage_score'] . '%',
                $row['passed'] ? 'Yes' : 'No',
                gmdate('H:i:s', $row['time_spent_seconds']),
                $row['started_at'],
                $row['submitted_at'],
                $row['trust_score'],
                $row['violation_count']
            ]);
        }
        
        fclose($output);
        exit;
    }

    private function exportExcel($data)
    {
        // Would use PhpSpreadsheet library
        // For now, return CSV
        return $this->exportCsv($data);
    }
}
