<?php
/**
 * CourseDuplicate Class
 * Handles duplication of courses with all related content
 * Copies: Course, Modules, Lessons, Assessments, Questions, Options
 */

class CourseDuplicate
{
    private $conn;
    private $tenant_id;
    private $user_id;

    // Mapping arrays to track old ID -> new ID relationships
    private $moduleMap = [];
    private $lessonMap = [];
    private $assessmentMap = [];
    private $questionMap = [];

    // Statistics
    private $stats = [
        'modules_copied' => 0,
        'lessons_copied' => 0,
        'assessments_copied' => 0,
        'questions_copied' => 0,
        'options_copied' => 0
    ];

    public function __construct($db)
    {
        $this->conn = $db;
    }

    /**
     * Set context for duplication
     */
    public function setContext(int $tenant_id, int $user_id): void
    {
        $this->tenant_id = $tenant_id;
        $this->user_id = $user_id;
    }

    /**
     * Duplicate a course with all its content
     *
     * @param int $source_course_id The course to duplicate
     * @param array $options Optional settings for duplication
     * @return array Result with new course data or error
     */
    public function duplicate(int $source_course_id, array $options = []): array
    {
        // Default options
        $defaults = [
            'new_title' => null,           // Custom title for duplicated course
            'title_suffix' => ' (Copy)',   // Suffix to add if no custom title
            'copy_modules' => true,
            'copy_lessons' => true,
            'copy_assessments' => true,
            'copy_questions' => true,
            'set_as_draft' => true,        // Set duplicated course as unpublished
            'created_by' => $this->user_id
        ];

        $options = array_merge($defaults, $options);

        // Reset mappings and stats
        $this->resetState();

        // Start transaction
        $this->conn->begin_transaction();

        try {
            // 1. Verify source course exists and belongs to tenant
            $sourceCourse = $this->getSourceCourse($source_course_id);
            if (!$sourceCourse) {
                throw new Exception('Source course not found or access denied');
            }

            // 2. Duplicate the course record
            $newCourseId = $this->duplicateCourseRecord($sourceCourse, $options);
            if (!$newCourseId) {
                throw new Exception('Failed to duplicate course record');
            }

            // 3. Duplicate modules if enabled
            if ($options['copy_modules']) {
                $this->duplicateModules($source_course_id, $newCourseId);
            }

            // 4. Duplicate lessons if enabled (requires modules)
            if ($options['copy_modules'] && $options['copy_lessons']) {
                $this->duplicateLessons($source_course_id);
            }

            // 5. Duplicate assessments if enabled
            if ($options['copy_assessments']) {
                $this->duplicateAssessments($source_course_id, $newCourseId);
            }

            // 6. Duplicate questions if enabled (requires assessments)
            if ($options['copy_assessments'] && $options['copy_questions']) {
                $this->duplicateQuestions();
            }

            // Commit transaction
            $this->conn->commit();

            // Get the new course details
            $newCourse = $this->getSourceCourse($newCourseId);

            return [
                'success' => true,
                'message' => 'Course duplicated successfully',
                'data' => [
                    'original_course_id' => $source_course_id,
                    'new_course_id' => $newCourseId,
                    'new_course' => $newCourse,
                    'statistics' => $this->stats,
                    'mappings' => [
                        'modules' => $this->moduleMap,
                        'lessons' => $this->lessonMap,
                        'assessments' => $this->assessmentMap,
                        'questions' => $this->questionMap
                    ]
                ]
            ];

        } catch (Exception $e) {
            // Rollback on error
            $this->conn->rollback();

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => null
            ];
        }
    }

    /**
     * Reset internal state for new duplication
     */
    private function resetState(): void
    {
        $this->moduleMap = [];
        $this->lessonMap = [];
        $this->assessmentMap = [];
        $this->questionMap = [];
        $this->stats = [
            'modules_copied' => 0,
            'lessons_copied' => 0,
            'assessments_copied' => 0,
            'questions_copied' => 0,
            'options_copied' => 0
        ];
    }

    /**
     * Get source course details
     */
    private function getSourceCourse(int $course_id): ?array
    {
        $query = "SELECT * FROM courses 
                  WHERE course_id = ? AND tenant_id = ? AND is_active = 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        return $result ?: null;
    }

    /**
     * Duplicate the main course record
     */
    private function duplicateCourseRecord(array $source, array $options): ?int
    {
        // Determine new title
        $newTitle = $options['new_title'] ?? ($source['title'] . $options['title_suffix']);

        // Ensure title is unique for this tenant
        $newTitle = $this->ensureUniqueTitle($newTitle);

        $query = "INSERT INTO courses (
                    tenant_id, title, description, objectives, summary, category_id, instructor_id, level,
                    duration_minutes, pass_percentage, certificate_template_id, is_active, created_at, updated_at
                  ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

        $isActive = $options['set_as_draft'] ? 0 : 1;

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param(
            "issssiisdiii",
            $this->tenant_id,
            $newTitle,
            $source['description'],
            $source['objectives'],
            $source['summary'],
            $source['category_id'],
            $source['instructor_id'],
            $source['level'],
            $source['duration_minutes'],
            $source['pass_percentage'],
            $source['certificate_template_id'],
            $isActive
        );
        if ($stmt->execute()) {
            return $this->conn->insert_id;
        }
        return null;
    }

    /**
     * Ensure course title is unique within tenant
     */
    private function ensureUniqueTitle(string $title): string
    {
        $originalTitle = $title;
        $counter = 1;
        while ($this->titleExists($title)) {
            $counter++;
            $title = $originalTitle . ' ' . $counter;
        }
        return $title;
    }

    /**
     * Check if title already exists for tenant
     */
    private function titleExists(string $title): bool
    {
        $query = "SELECT COUNT(*) as count FROM courses 
                  WHERE title = ? AND tenant_id = ? AND is_active = 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("si", $title, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        return $result['count'] > 0;
    }

    /**
     * Duplicate all modules for a course
     */
    private function duplicateModules(int $sourceCourseId, int $newCourseId): void
    {
        $query = "SELECT * FROM modules 
                  WHERE course_id = ? AND tenant_id = ? AND is_active = 1
                  ORDER BY module_id";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $sourceCourseId, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($module = $result->fetch_assoc()) {
            $oldModuleId = $module['module_id'];
            $newModuleId = $this->duplicateModuleRecord($module, $newCourseId);

            if ($newModuleId) {
                $this->moduleMap[$oldModuleId] = $newModuleId;
                $this->stats['modules_copied']++;
            }
        }
    }

    /**
     * Duplicate a single module record
     */
    private function duplicateModuleRecord(array $module, int $newCourseId): ?int
    {
        $query = "INSERT INTO modules (
                    tenant_id, course_id, title, description, 
                    is_active, created_at, updated_at
                  ) VALUES (?, ?, ?, ?, 1, NOW(), NOW())";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param(
            "iiss",
            $this->tenant_id,
            $newCourseId,
            $module['title'],
            $module['description']
        );

        if ($stmt->execute()) {
            return $this->conn->insert_id;
        }

        return null;
    }

    /**
     * Duplicate all lessons for modules
     */
    private function duplicateLessons(int $sourceCourseId): void
    {
        // Get all lessons from source course modules
        $query = "SELECT l.* FROM lessons l
                  JOIN modules m ON l.module_id = m.module_id
                  WHERE m.course_id = ? AND m.tenant_id = ? 
                  AND l.is_active = 1 AND m.is_active = 1
                  ORDER BY l.module_id, l.lesson_id";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $sourceCourseId, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($lesson = $result->fetch_assoc()) {
            $oldLessonId = $lesson['lesson_id'];
            $oldModuleId = $lesson['module_id'];

            // Skip if module wasn't copied
            if (!isset($this->moduleMap[$oldModuleId])) {
                continue;
            }

            $newModuleId = $this->moduleMap[$oldModuleId];
            $newLessonId = $this->duplicateLessonRecord($lesson, $newModuleId);

            if ($newLessonId) {
                $this->lessonMap[$oldLessonId] = $newLessonId;
                $this->stats['lessons_copied']++;
            }
        }
    }

    /**
     * Duplicate a single lesson record
     */
    private function duplicateLessonRecord(array $lesson, int $newModuleId): ?int{
        $query = "INSERT INTO lessons (
                    tenant_id, module_id, title, content_type, content_text,
                    content_url, duration_minutes, is_active, created_at, updated_at
                  ) VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param(
            "iissssi",
            $this->tenant_id,
            $newModuleId,
            $lesson['title'],
            $lesson['content_type'],
            $lesson['content_text'],
            $lesson['content_url'],
            $lesson['duration_minutes']
        );

        if ($stmt->execute()) {
            return $this->conn->insert_id;
        }
        return null;
    }
    /**
     * Duplicate all assessments for a course
     */
    private function duplicateAssessments(int $sourceCourseId, int $newCourseId): void
    {
        $query = "SELECT * FROM assessments 
                  WHERE course_id = ? AND tenant_id = ? AND is_active = 1
                  ORDER BY assessment_id";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $sourceCourseId, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($assessment = $result->fetch_assoc()) {
            $oldAssessmentId = $assessment['assessment_id'];

            // Map module_id if assessment is linked to a module
            $newModuleId = null;
            if (!empty($assessment['module_id']) && isset($this->moduleMap[$assessment['module_id']])) {
                $newModuleId = $this->moduleMap[$assessment['module_id']];
            }

            $newAssessmentId = $this->duplicateAssessmentRecord($assessment, $newCourseId, $newModuleId);

            if ($newAssessmentId) {
                $this->assessmentMap[$oldAssessmentId] = $newAssessmentId;
                $this->stats['assessments_copied']++;
            }
        }
    }

    /**
     * Duplicate a single assessment record
     */
    private function duplicateAssessmentRecord(array $assessment, int $newCourseId, ?int $newModuleId): ?int
    {
        $query = "INSERT INTO assessments (
                    tenant_id, course_id, module_id, title, description, type,
                    total_questions, passing_score, time_limit_minutes, 
                    max_attempts, shuffle_questions, show_correct_answers,
                    is_active, created_at, updated_at
                  ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param(
            "iiisssiiiiii",
            $this->tenant_id,
            $newCourseId,
            $newModuleId,
            $assessment['title'],
            $assessment['description'],
            $assessment['type'],
            $assessment['total_questions'],
            $assessment['passing_score'],
            $assessment['time_limit_minutes'],
            $assessment['max_attempts'],
            $assessment['shuffle_questions'],
            $assessment['show_correct_answers']
        );

        if ($stmt->execute()) {
            return $this->conn->insert_id;
        }

        return null;
    }

    /**
     * Duplicate all questions for assessments
     */
    private function duplicateQuestions(): void
    {
        foreach ($this->assessmentMap as $oldAssessmentId => $newAssessmentId) {
            $query = "SELECT * FROM questions 
                      WHERE assessment_id = ? AND is_active = 1
                      ORDER BY question_id";

            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("i", $oldAssessmentId);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($question = $result->fetch_assoc()) {
                $oldQuestionId = $question['question_id'];
                $newQuestionId = $this->duplicateQuestionRecord($question, $newAssessmentId);

                if ($newQuestionId) {
                    $this->questionMap[$oldQuestionId] = $newQuestionId;
                    $this->stats['questions_copied']++;

                    // Duplicate question options
                    $this->duplicateQuestionOptions($oldQuestionId, $newQuestionId);
                }
            }
        }
    }

    /**
     * Duplicate a single question record
     */
    private function duplicateQuestionRecord(array $question, int $newAssessmentId): ?int
    {
        $query = "INSERT INTO questions (tenant_id,
                    assessment_id, question_text, question_type, points,
                    explanation, is_active, created_at, updated_at
                  ) VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param(
            "iissis",
            $this->tenant_id,
            $newAssessmentId,
            $question['question_text'],
            $question['question_type'],
            $question['points'],
            $question['explanation']
        );

        if ($stmt->execute()) {
            return $this->conn->insert_id;
        }

        return null;
    }

    /**
     * Duplicate question options
     */
    private function duplicateQuestionOptions(int $oldQuestionId, int $newQuestionId): void
    {
        $query = "SELECT * FROM question_options 
                  WHERE question_id = ?
                  ORDER BY option_id";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $oldQuestionId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($option = $result->fetch_assoc()) {
            $this->duplicateOptionRecord($option, $newQuestionId);
        }
    }

    /**
     * Duplicate a single option record
     */
    private function duplicateOptionRecord(array $option, int $newQuestionId): void
    {
        $query = "INSERT INTO question_options (
                    question_id, option_text, is_correct, created_at
                  ) VALUES (?, ?, ?, NOW())";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param(
            "isi",
            $newQuestionId,
            $option['option_text'],
            $option['is_correct']
        );

        if ($stmt->execute()) {
            $this->stats['options_copied']++;
        }
    }

    /**
     * Get duplication preview (without actually duplicating)
     */
    public function preview(int $source_course_id): array
    {
        $course = $this->getSourceCourse($source_course_id);
        if (!$course) {
            return [
                'success' => false,
                'message' => 'Source course not found or access denied',
                'data' => null
            ];
        }

        // Count related items
        $modulesCount = $this->countModules($source_course_id);
        $lessonsCount = $this->countLessons($source_course_id);
        $assessmentsCount = $this->countAssessments($source_course_id);
        $questionsCount = $this->countQuestions($source_course_id);

        return [
            'success' => true,
            'message' => 'Preview generated',
            'data' => [
                'course' => [
                    'course_id' => $course['course_id'],
                    'title' => $course['title'],
                    'description' => $course['description']
                ],
                'will_copy' => [
                    'modules' => $modulesCount,
                    'lessons' => $lessonsCount,
                    'assessments' => $assessmentsCount,
                    'questions' => $questionsCount
                ],
                'suggested_title' => $course['title'] . ' (Copy)'
            ]
        ];
    }

    private function countModules(int $courseId): int
    {
        $query = "SELECT COUNT(*) as count FROM modules 
                  WHERE course_id = ? AND tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $courseId, $this->tenant_id);
        $stmt->execute();
        return (int) $stmt->get_result()->fetch_assoc()['count'];
    }

    private function countLessons(int $courseId): int
    {
        $query = "SELECT COUNT(*) as count FROM lessons l
                  JOIN modules m ON l.module_id = m.module_id
                  WHERE m.course_id = ? AND m.tenant_id = ? 
                  AND l.is_active = 1 AND m.is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $courseId, $this->tenant_id);
        $stmt->execute();
        return (int) $stmt->get_result()->fetch_assoc()['count'];
    }

    private function countAssessments(int $courseId): int
    {
        $query = "SELECT COUNT(*) as count FROM assessments 
                  WHERE course_id = ? AND tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $courseId, $this->tenant_id);
        $stmt->execute();
        return (int) $stmt->get_result()->fetch_assoc()['count'];
    }

    private function countQuestions(int $courseId): int
    {
        $query = "SELECT COUNT(*) as count FROM questions q
                  JOIN assessments a ON q.assessment_id = a.assessment_id
                  WHERE a.course_id = ? AND a.tenant_id = ? 
                  AND q.is_active = 1 AND a.is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $courseId, $this->tenant_id);
        $stmt->execute();
        return (int) $stmt->get_result()->fetch_assoc()['count'];
    }
}