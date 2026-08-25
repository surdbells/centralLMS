<?php

/**
 * Skill Manager
 * Handles skills, skill categories, and course-skill mappings
 * For Multi-Tenant LMS - Admin & Learner Portals
 */

class Skill
{
    private mysqli $conn;
    private int $tenant_id;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function setTenant(int $tenant_id): void
    {
        $this->tenant_id = $tenant_id;
    }

    // ==========================================
    // SKILL CATEGORIES - CRUD
    // ==========================================

    /**
     * Create a skill category
     */
    public function createCategory(array $data): array
    {
        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $color = trim($data['color'] ?? '#6c757d');
        $is_active = isset($data['is_active']) ? (int) $data['is_active'] : 1;

        if (empty($name)) {
            return ['success' => false, 'message' => 'Category name is required'];
        }

        // Check duplicate
        $stmt = $this->conn->prepare("
            SELECT category_id FROM skill_categories 
            WHERE tenant_id = ? AND name = ?
        ");
        $stmt->bind_param("is", $this->tenant_id, $name);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            return ['success' => false, 'message' => 'Category name already exists'];
        }
        $stmt->close();

        $stmt = $this->conn->prepare("
            INSERT INTO skill_categories (tenant_id, name, description, color, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("isssi", $this->tenant_id, $name, $description, $color, $is_active);

        if ($stmt->execute()) {
            $category_id = $stmt->insert_id;
            $stmt->close();
            return [
                'success' => true,
                'message' => 'Category created successfully',
                'category_id' => $category_id
            ];
        }

        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to create category: ' . $error];
    }

    /**
     * Update a skill category
     */
    public function updateCategory(int $category_id, array $data): array
    {
        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $color = trim($data['color'] ?? '#6c757d');
        $is_active = isset($data['is_active']) ? (int) $data['is_active'] : 1;

        if (empty($name)) {
            return ['success' => false, 'message' => 'Category name is required'];
        }

        // Check duplicate (exclude current)
        $stmt = $this->conn->prepare("
            SELECT category_id FROM skill_categories 
            WHERE tenant_id = ? AND name = ? AND category_id != ?
        ");
        $stmt->bind_param("isi", $this->tenant_id, $name, $category_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            return ['success' => false, 'message' => 'Category name already exists'];
        }
        $stmt->close();

        $stmt = $this->conn->prepare("
            UPDATE skill_categories 
            SET name = ?, description = ?, color = ?, is_active = ?, updated_at = NOW()
            WHERE category_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("sssiii", $name, $description, $color, $is_active, $category_id, $this->tenant_id);

        if ($stmt->execute() && $stmt->affected_rows >= 0) {
            $stmt->close();
            return ['success' => true, 'message' => 'Category updated successfully'];
        }

        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to update category: ' . $error];
    }

    /**
     * Delete a skill category
     */
    public function deleteCategory(int $category_id): array
    {
        // Check if skills are linked
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count FROM skills 
            WHERE category_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("ii", $category_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ((int) $result['count'] > 0) {
            return [
                'success' => false,
                'message' => "Cannot delete: {$result['count']} skill(s) are linked to this category"
            ];
        }

        $stmt = $this->conn->prepare("
            DELETE FROM skill_categories 
            WHERE category_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("ii", $category_id, $this->tenant_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $stmt->close();
            return ['success' => true, 'message' => 'Category deleted successfully'];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Category not found or already deleted'];
    }

    /**
     * Get a single category
     */
    public function getCategory(int $category_id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                sc.*,
                (SELECT COUNT(*) FROM skills s WHERE s.category_id = sc.category_id AND s.is_active = 1) as skill_count
            FROM skill_categories sc
            WHERE sc.category_id = ? AND sc.tenant_id = ?
        ");
        $stmt->bind_param("ii", $category_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($result) {
            $result['is_active'] = (bool) $result['is_active'];
            $result['skill_count'] = (int) $result['skill_count'];
        }

        return $result;
    }

    /**
     * Get all categories with skill counts
     */
    public function getCategories(bool $activeOnly = false): array
    {
        $sql = "
            SELECT 
                sc.*,
                (SELECT COUNT(*) FROM skills s WHERE s.category_id = sc.category_id AND s.is_active = 1) as skill_count
            FROM skill_categories sc
            WHERE sc.tenant_id = ?
        ";

        if ($activeOnly) {
            $sql .= " AND sc.is_active = 1";
        }

        $sql .= " ORDER BY sc.name ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $categories = [];
        while ($row = $result->fetch_assoc()) {
            $row['is_active'] = (bool) $row['is_active'];
            $row['skill_count'] = (int) $row['skill_count'];
            $categories[] = $row;
        }
        $stmt->close();

        return $categories;
    }

    // ==========================================
    // SKILLS - CRUD
    // ==========================================

    /**
     * Create a skill
     */
    public function createSkill(array $data): array
    {
        $category_id = !empty($data['category_id']) ? (int) $data['category_id'] : null;
        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $level = $data['level'] ?? 'intermediate';
        $icon = trim($data['icon'] ?? '');
        $color = trim($data['color'] ?? '#c9a227');
        $is_active = isset($data['is_active']) ? (int) $data['is_active'] : 1;

        if (empty($name)) {
            return ['success' => false, 'message' => 'Skill name is required'];
        }

        // Validate level
        $validLevels = ['beginner', 'intermediate', 'advanced', 'expert'];
        if (!in_array($level, $validLevels)) {
            $level = 'intermediate';
        }

        // Check duplicate
        $stmt = $this->conn->prepare("
            SELECT skill_id FROM skills 
            WHERE tenant_id = ? AND name = ?
        ");
        $stmt->bind_param("is", $this->tenant_id, $name);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            return ['success' => false, 'message' => 'Skill name already exists'];
        }
        $stmt->close();

        $stmt = $this->conn->prepare("
            INSERT INTO skills (tenant_id, category_id, name, description, level, icon, color, is_active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("iisssssi",
            $this->tenant_id, $category_id, $name, $description,
            $level, $icon, $color, $is_active
        );

        if ($stmt->execute()) {
            $skill_id = $stmt->insert_id;
            $stmt->close();
            return [
                'success' => true,
                'message' => 'Skill created successfully',
                'skill_id' => $skill_id
            ];
        }

        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to create skill: ' . $error];
    }

    /**
     * Update a skill
     */
    public function updateSkill(int $skill_id, array $data): array
    {
        $category_id = !empty($data['category_id']) ? (int) $data['category_id'] : null;
        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $level = $data['level'] ?? 'intermediate';
        $icon = trim($data['icon'] ?? '');
        $color = trim($data['color'] ?? '#c9a227');
        $is_active = isset($data['is_active']) ? (int) $data['is_active'] : 1;

        if (empty($name)) {
            return ['success' => false, 'message' => 'Skill name is required'];
        }

        // Validate level
        $validLevels = ['beginner', 'intermediate', 'advanced', 'expert'];
        if (!in_array($level, $validLevels)) {
            $level = 'intermediate';
        }

        // Check duplicate (exclude current)
        $stmt = $this->conn->prepare("
            SELECT skill_id FROM skills 
            WHERE tenant_id = ? AND name = ? AND skill_id != ?
        ");
        $stmt->bind_param("isi", $this->tenant_id, $name, $skill_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            return ['success' => false, 'message' => 'Skill name already exists'];
        }
        $stmt->close();

        $stmt = $this->conn->prepare("
            UPDATE skills 
            SET category_id = ?, name = ?, description = ?, level = ?, 
                icon = ?, color = ?, is_active = ?, updated_at = NOW()
            WHERE skill_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("isssssiii",
            $category_id, $name, $description, $level,
            $icon, $color, $is_active, $skill_id, $this->tenant_id
        );

        if ($stmt->execute() && $stmt->affected_rows >= 0) {
            $stmt->close();
            return ['success' => true, 'message' => 'Skill updated successfully'];
        }

        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to update skill: ' . $error];
    }

    /**
     * Delete a skill
     */
    public function deleteSkill(int $skill_id): array
    {
        // Check if courses are linked
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count FROM course_skills WHERE skill_id = ?
        ");
        $stmt->bind_param("i", $skill_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ((int) $result['count'] > 0) {
            return [
                'success' => false,
                'message' => "Cannot delete: {$result['count']} course(s) are linked to this skill"
            ];
        }

        $stmt = $this->conn->prepare("
            DELETE FROM skills 
            WHERE skill_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("ii", $skill_id, $this->tenant_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $stmt->close();
            return ['success' => true, 'message' => 'Skill deleted successfully'];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Skill not found or already deleted'];
    }

    /**
     * Get a single skill with details
     */
    public function getSkill(int $skill_id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                s.*,
                sc.name as category_name,
                sc.color as category_color,
                (SELECT COUNT(*) FROM course_skills cs WHERE cs.skill_id = s.skill_id) as course_count,
                (SELECT COUNT(DISTINCT ce.user_id) 
                 FROM course_skills cs2 
                 INNER JOIN course_enrollments ce ON cs2.course_id = ce.course_id 
                    AND ce.tenant_id = ? AND ce.status = 'completed'
                 WHERE cs2.skill_id = s.skill_id) as learners_acquired
            FROM skills s
            LEFT JOIN skill_categories sc ON s.category_id = sc.category_id
            WHERE s.skill_id = ? AND s.tenant_id = ?
        ");
        $stmt->bind_param("iii", $this->tenant_id, $skill_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($result) {
            $result['is_active'] = (bool) $result['is_active'];
            $result['course_count'] = (int) $result['course_count'];
            $result['learners_acquired'] = (int) $result['learners_acquired'];
        }

        return $result;
    }

    /**
     * Get all skills with filters
     */
    public function getSkills(array $filters = []): array
    {
        $activeOnly = $filters['active_only'] ?? false;
        $category_id = $filters['category_id'] ?? null;
        $search = trim($filters['search'] ?? '');
        $level = $filters['level'] ?? null;

        $sql = "
            SELECT 
                s.*,
                sc.name as category_name,
                sc.color as category_color,
                (SELECT COUNT(*) FROM course_skills cs WHERE cs.skill_id = s.skill_id) as course_count,
                (SELECT COUNT(DISTINCT ce.user_id) 
                 FROM course_skills cs2 
                 INNER JOIN course_enrollments ce ON cs2.course_id = ce.course_id 
                    AND ce.tenant_id = ? AND ce.status = 'completed'
                 WHERE cs2.skill_id = s.skill_id) as learners_acquired
            FROM skills s
            LEFT JOIN skill_categories sc ON s.category_id = sc.category_id
            WHERE s.tenant_id = ?
        ";

        $params = [$this->tenant_id, $this->tenant_id];
        $types = "ii";

        if ($activeOnly) {
            $sql .= " AND s.is_active = 1";
        }

        if ($category_id) {
            $sql .= " AND s.category_id = ?";
            $params[] = $category_id;
            $types .= "i";
        }

        if ($level) {
            $sql .= " AND s.level = ?";
            $params[] = $level;
            $types .= "s";
        }

        if (!empty($search)) {
            $sql .= " AND (s.name LIKE ? OR s.description LIKE ?)";
            $searchParam = "%{$search}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $types .= "ss";
        }

        $sql .= " ORDER BY sc.name ASC, s.name ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $skills = [];
        while ($row = $result->fetch_assoc()) {
            $row['is_active'] = (bool) $row['is_active'];
            $row['course_count'] = (int) $row['course_count'];
            $row['learners_acquired'] = (int) $row['learners_acquired'];
            $skills[] = $row;
        }
        $stmt->close();

        return $skills;
    }

    // ==========================================
    // COURSE-SKILL MAPPING
    // ==========================================

    /**
     * Assign skills to a course
     */
    public function assignSkillsToCourse(int $course_id, array $skills): array
    {
        // First remove existing mappings
        $stmt = $this->conn->prepare("DELETE FROM course_skills WHERE course_id = ?");
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $stmt->close();

        if (empty($skills)) {
            return ['success' => true, 'message' => 'All skills removed from course'];
        }

        $stmt = $this->conn->prepare("
            INSERT INTO course_skills (course_id, skill_id, proficiency_level, is_primary, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");

        $added = 0;
        foreach ($skills as $skill) {
            $skill_id = (int) ($skill['skill_id'] ?? 0);
            $proficiency = $skill['proficiency_level'] ?? 'intermediate';
            $is_primary = (int) ($skill['is_primary'] ?? 0);

            if ($skill_id <= 0) continue;

            // Validate proficiency level
            $validLevels = ['beginner', 'intermediate', 'advanced', 'expert'];
            if (!in_array($proficiency, $validLevels)) {
                $proficiency = 'intermediate';
            }

            $stmt->bind_param("iisi", $course_id, $skill_id, $proficiency, $is_primary);
            if ($stmt->execute()) {
                $added++;
            }
        }
        $stmt->close();

        return [
            'success' => true,
            'message' => "{$added} skill(s) assigned to course",
            'assigned_count' => $added
        ];
    }

    /**
     * Get skills for a course
     */
    public function getCourseSkills(int $course_id): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                cs.*,
                s.name as skill_name,
                s.description as skill_description,
                s.level as skill_level,
                s.icon,
                s.color,
                sc.name as category_name,
                sc.color as category_color
            FROM course_skills cs
            INNER JOIN skills s ON cs.skill_id = s.skill_id AND s.is_active = 1
            LEFT JOIN skill_categories sc ON s.category_id = sc.category_id
            WHERE cs.course_id = ?
            ORDER BY cs.is_primary DESC, s.name ASC
        ");
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $skills = [];
        while ($row = $result->fetch_assoc()) {
            $row['is_primary'] = (bool) $row['is_primary'];
            $skills[] = $row;
        }
        $stmt->close();

        return $skills;
    }

    /**
     * Get courses that teach a specific skill
     */
    public function getSkillCourses(int $skill_id): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                c.course_id,
                c.title,
                c.description,
                c.duration_minutes,
                c.level as course_level,
                cs.proficiency_level,
                cs.is_primary,
                cat.name as category_name
            FROM course_skills cs
            INNER JOIN courses c ON cs.course_id = c.course_id AND c.is_active = 1 AND c.status = 'published'
            LEFT JOIN categories cat ON c.category_id = cat.category_id
            WHERE cs.skill_id = ? AND c.tenant_id = ?
            ORDER BY cs.is_primary DESC, c.title ASC
        ");
        $stmt->bind_param("ii", $skill_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $courses = [];
        while ($row = $result->fetch_assoc()) {
            $row['is_primary'] = (bool) $row['is_primary'];
            $courses[] = $row;
        }
        $stmt->close();

        return $courses;
    }

    // ==========================================
    // LEARNER SKILLS REPORT
    // ==========================================

    /**
     * Get skills acquired by a learner
     */
    public function getLearnerSkills(int $user_id): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                s.skill_id,
                s.name as skill_name,
                s.description,
                s.level as skill_level,
                s.icon,
                s.color,
                sc.name as category_name,
                sc.color as category_color,
                COUNT(DISTINCT ce.course_id) as courses_completed,
                MAX(ce.completed_at) as last_acquired,
                GROUP_CONCAT(DISTINCT cs.proficiency_level ORDER BY 
                    FIELD(cs.proficiency_level, 'expert', 'advanced', 'intermediate', 'beginner')
                ) as proficiency_levels,
                MAX(FIELD(cs.proficiency_level, 'beginner', 'intermediate', 'advanced', 'expert')) as highest_proficiency_rank
            FROM course_enrollments ce
            INNER JOIN course_skills cs ON ce.course_id = cs.course_id
            INNER JOIN skills s ON cs.skill_id = s.skill_id AND s.is_active = 1 AND s.tenant_id = ?
            LEFT JOIN skill_categories sc ON s.category_id = sc.category_id
            WHERE ce.tenant_id = ? AND ce.user_id = ? AND ce.status = 'completed'
            GROUP BY s.skill_id, s.name, s.description, s.level, s.icon, s.color, sc.name, sc.color
            ORDER BY highest_proficiency_rank DESC, courses_completed DESC, s.name ASC
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->tenant_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $skills = [];
        while ($row = $result->fetch_assoc()) {
            $row['courses_completed'] = (int) $row['courses_completed'];

            // Determine highest proficiency
            $proficiencies = explode(',', $row['proficiency_levels']);
            $levels = ['expert', 'advanced', 'intermediate', 'beginner'];
            $highest = 'beginner';
            foreach ($levels as $level) {
                if (in_array($level, $proficiencies)) {
                    $highest = $level;
                    break;
                }
            }
            $row['highest_proficiency'] = $highest;

            unset($row['highest_proficiency_rank']);
            $skills[] = $row;
        }
        $stmt->close();

        return $skills;
    }

    /**
     * Get learner skills grouped by category
     */
    public function getLearnerSkillsByCategory(int $user_id): array
    {
        $skills = $this->getLearnerSkills($user_id);

        $grouped = [];
        foreach ($skills as $skill) {
            $category = $skill['category_name'] ?? 'Uncategorized';
            if (!isset($grouped[$category])) {
                $grouped[$category] = [
                    'category_name' => $category,
                    'category_color' => $skill['category_color'] ?? '#6c757d',
                    'skills' => [],
                    'total_skills' => 0
                ];
            }
            $grouped[$category]['skills'][] = $skill;
            $grouped[$category]['total_skills']++;
        }

        return array_values($grouped);
    }

    /**
     * Get skill progress for a learner (skills in progress)
     */
    public function getLearnerSkillProgress(int $user_id): array
    {
        // Get all skills from enrolled courses
        $stmt = $this->conn->prepare("
            SELECT 
                s.skill_id,
                s.name as skill_name,
                s.description,
                s.level as skill_level,
                s.icon,
                s.color,
                sc.name as category_name,
                sc.color as category_color,
                cs.proficiency_level,
                cs.is_primary,
                c.course_id,
                c.title as course_title,
                ce.status as enrollment_status,
                ce.progress_percentage,
                ce.completed_at
            FROM course_enrollments ce
            INNER JOIN course_skills cs ON ce.course_id = cs.course_id
            INNER JOIN skills s ON cs.skill_id = s.skill_id AND s.is_active = 1 AND s.tenant_id = ?
            INNER JOIN courses c ON ce.course_id = c.course_id
            LEFT JOIN skill_categories sc ON s.category_id = sc.category_id
            WHERE ce.tenant_id = ? AND ce.user_id = ? AND ce.is_active = 1 AND ce.status != 'dropped'
            ORDER BY s.name, c.title
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->tenant_id, $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        // Group by skill
        $skillMap = [];
        while ($row = $result->fetch_assoc()) {
            $skill_id = $row['skill_id'];
            if (!isset($skillMap[$skill_id])) {
                $skillMap[$skill_id] = [
                    'skill_id' => (int) $row['skill_id'],
                    'skill_name' => $row['skill_name'],
                    'description' => $row['description'],
                    'skill_level' => $row['skill_level'],
                    'icon' => $row['icon'],
                    'color' => $row['color'],
                    'category_name' => $row['category_name'],
                    'category_color' => $row['category_color'],
                    'courses' => [],
                    'total_courses' => 0,
                    'completed_courses' => 0,
                    'in_progress_courses' => 0,
                    'is_acquired' => false
                ];
            }

            $skillMap[$skill_id]['courses'][] = [
                'course_id' => (int) $row['course_id'],
                'course_title' => $row['course_title'],
                'proficiency_level' => $row['proficiency_level'],
                'is_primary' => (bool) $row['is_primary'],
                'enrollment_status' => $row['enrollment_status'],
                'progress_percentage' => (float) $row['progress_percentage'],
                'completed_at' => $row['completed_at']
            ];

            $skillMap[$skill_id]['total_courses']++;

            if ($row['enrollment_status'] === 'completed') {
                $skillMap[$skill_id]['completed_courses']++;
                $skillMap[$skill_id]['is_acquired'] = true;
            } elseif ($row['enrollment_status'] === 'in_progress') {
                $skillMap[$skill_id]['in_progress_courses']++;
            }
        }
        $stmt->close();

        // Calculate progress percentage for each skill
        foreach ($skillMap as &$skill) {
            $skill['progress_percentage'] = $skill['total_courses'] > 0
                ? round(($skill['completed_courses'] / $skill['total_courses']) * 100, 1)
                : 0;
        }

        return array_values($skillMap);
    }

    // ==========================================
    // ADMIN ANALYTICS
    // ==========================================

    /**
     * Get skill adoption statistics (Admin)
     */
    public function getSkillStats(): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                s.skill_id,
                s.name,
                s.description,
                s.level,
                s.icon,
                s.color,
                s.is_active,
                sc.name as category_name,
                sc.color as category_color,
                (SELECT COUNT(*) FROM course_skills cs WHERE cs.skill_id = s.skill_id) as course_count,
                (SELECT COUNT(DISTINCT ce.user_id) 
                 FROM course_skills cs2 
                 INNER JOIN course_enrollments ce ON cs2.course_id = ce.course_id 
                    AND ce.tenant_id = ? AND ce.status = 'completed'
                 WHERE cs2.skill_id = s.skill_id) as learners_acquired,
                (SELECT COUNT(DISTINCT ce.user_id) 
                 FROM course_skills cs3 
                 INNER JOIN course_enrollments ce ON cs3.course_id = ce.course_id 
                    AND ce.tenant_id = ? AND ce.status = 'in_progress'
                 WHERE cs3.skill_id = s.skill_id) as learners_in_progress
            FROM skills s
            LEFT JOIN skill_categories sc ON s.category_id = sc.category_id
            WHERE s.tenant_id = ?
            ORDER BY learners_acquired DESC, s.name ASC
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->tenant_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $stats = [];
        while ($row = $result->fetch_assoc()) {
            $row['is_active'] = (bool) $row['is_active'];
            $row['course_count'] = (int) $row['course_count'];
            $row['learners_acquired'] = (int) $row['learners_acquired'];
            $row['learners_in_progress'] = (int) $row['learners_in_progress'];
            $stats[] = $row;
        }
        $stmt->close();

        return $stats;
    }

    /**
     * Get top skills by adoption (Admin)
     */
    public function getTopSkills(int $limit = 10): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                s.skill_id,
                s.name,
                s.icon,
                s.color,
                sc.name as category_name,
                COUNT(DISTINCT ce.user_id) as learners_acquired
            FROM skills s
            LEFT JOIN skill_categories sc ON s.category_id = sc.category_id
            INNER JOIN course_skills cs ON s.skill_id = cs.skill_id
            INNER JOIN course_enrollments ce ON cs.course_id = ce.course_id 
                AND ce.tenant_id = ? AND ce.status = 'completed'
            WHERE s.tenant_id = ? AND s.is_active = 1
            GROUP BY s.skill_id, s.name, s.icon, s.color, sc.name
            ORDER BY learners_acquired DESC
            LIMIT ?
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->tenant_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $skills = [];
        while ($row = $result->fetch_assoc()) {
            $row['learners_acquired'] = (int) $row['learners_acquired'];
            $skills[] = $row;
        }
        $stmt->close();

        return $skills;
    }

    /**
     * Get skills summary counts (Admin dashboard)
     */
    public function getSkillsSummary(): array
    {
        // Total skills
        $stmt = $this->conn->prepare("
            SELECT 
                COUNT(*) as total_skills,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_skills,
                SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive_skills
            FROM skills
            WHERE tenant_id = ?
        ");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $skillCounts = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Total categories
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as total_categories
            FROM skill_categories
            WHERE tenant_id = ? AND is_active = 1
        ");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $catCount = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Courses with skills
        $stmt = $this->conn->prepare("
            SELECT COUNT(DISTINCT cs.course_id) as courses_with_skills
            FROM course_skills cs
            INNER JOIN courses c ON cs.course_id = c.course_id AND c.tenant_id = ?
        ");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $coursesCount = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Learners with skills
        $stmt = $this->conn->prepare("
            SELECT COUNT(DISTINCT ce.user_id) as learners_with_skills
            FROM course_skills cs
            INNER JOIN course_enrollments ce ON cs.course_id = ce.course_id 
                AND ce.tenant_id = ? AND ce.status = 'completed'
        ");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $learnersCount = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return [
            'total_skills' => (int) $skillCounts['total_skills'],
            'active_skills' => (int) $skillCounts['active_skills'],
            'inactive_skills' => (int) $skillCounts['inactive_skills'],
            'total_categories' => (int) $catCount['total_categories'],
            'courses_with_skills' => (int) $coursesCount['courses_with_skills'],
            'learners_with_skills' => (int) $learnersCount['learners_with_skills']
        ];
    }

    /**
     * Get learners who have a specific skill (Admin)
     */
    public function getSkillLearners(int $skill_id, int $limit = 50): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                u.id,
                u.first_name,
                u.last_name,
                u.email,
                u.avatar,
                COUNT(DISTINCT ce.course_id) as courses_completed,
                MAX(ce.completed_at) as skill_acquired_at,
                GROUP_CONCAT(DISTINCT cs.proficiency_level ORDER BY 
                    FIELD(cs.proficiency_level, 'expert', 'advanced', 'intermediate', 'beginner')
                ) as proficiency_levels
            FROM course_skills cs
            INNER JOIN course_enrollments ce ON cs.course_id = ce.course_id 
                AND ce.tenant_id = ? AND ce.status = 'completed'
            INNER JOIN users u ON ce.user_id = u.id AND u.is_active = 1
            WHERE cs.skill_id = ?
            GROUP BY u.id, u.first_name, u.last_name, u.email, u.avatar
            ORDER BY skill_acquired_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("iii", $this->tenant_id, $skill_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $learners = [];
        while ($row = $result->fetch_assoc()) {
            $row['courses_completed'] = (int) $row['courses_completed'];
            $row['full_name'] = trim($row['first_name'] . ' ' . $row['last_name']);

            // Get highest proficiency
            $proficiencies = explode(',', $row['proficiency_levels']);
            $levels = ['expert', 'advanced', 'intermediate', 'beginner'];
            $highest = 'beginner';
            foreach ($levels as $level) {
                if (in_array($level, $proficiencies)) {
                    $highest = $level;
                    break;
                }
            }
            $row['highest_proficiency'] = $highest;

            $learners[] = $row;
        }
        $stmt->close();

        return $learners;
    }

    /**
     * Get skill gap analysis (skills with low adoption)
     */
    public function getSkillGaps(): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                s.skill_id,
                s.name,
                s.description,
                s.level,
                s.icon,
                s.color,
                sc.name as category_name,
                (SELECT COUNT(*) FROM course_skills cs WHERE cs.skill_id = s.skill_id) as course_count,
                (SELECT COUNT(DISTINCT ce.user_id) 
                 FROM course_skills cs2 
                 INNER JOIN course_enrollments ce ON cs2.course_id = ce.course_id 
                    AND ce.tenant_id = ? AND ce.status = 'completed'
                 WHERE cs2.skill_id = s.skill_id) as learners_acquired,
                (SELECT COUNT(*) FROM users u WHERE u.tenant_id = ? AND u.is_active = 1) as total_users
            FROM skills s
            LEFT JOIN skill_categories sc ON s.category_id = sc.category_id
            WHERE s.tenant_id = ? AND s.is_active = 1
            ORDER BY learners_acquired ASC, s.name ASC
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->tenant_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $gaps = [];
        while ($row = $result->fetch_assoc()) {
            $row['course_count'] = (int) $row['course_count'];
            $row['learners_acquired'] = (int) $row['learners_acquired'];
            $row['total_users'] = (int) $row['total_users'];
            $row['adoption_rate'] = $row['total_users'] > 0
                ? round(($row['learners_acquired'] / $row['total_users']) * 100, 1)
                : 0;
            $gaps[] = $row;
        }
        $stmt->close();

        return $gaps;
    }

    // ==========================================
    // HELPER METHODS FOR DROPDOWNS
    // ==========================================

    /**
     * Get users list for dropdown (Admin)
     */
    public function getUsers(string $search = '', int $limit = 100): array
    {
        $sql = "
            SELECT id,
                first_name,
                last_name,
                email,
                avatar
            FROM users
            WHERE tenant_id = ? AND is_active = 1
        ";

        $params = [$this->tenant_id];
        $types = "i";

        if (!empty($search)) {
            $sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
            $searchParam = "%{$search}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $params[] = $searchParam;
            $types .= "sss";
        }

        $sql .= " ORDER BY first_name ASC, last_name ASC LIMIT ?";
        $params[] = $limit;
        $types .= "i";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $users = [];
        while ($row = $result->fetch_assoc()) {
            $row['user_id'] = (int) $row['id'];
            $users[] = $row;
        }
        $stmt->close();

        return $users;
    }

    /**
     * Get courses list for dropdown (Admin)
     */
    public function getCourses(bool $activeOnly = true): array
    {
        $sql = "
            SELECT 
                c.course_id,
                c.title,
                cc.name as category_name,
                c.status
            FROM courses c
            LEFT JOIN categories cc ON c.category_id = cc.category_id
            WHERE c.tenant_id = ?
        ";

        $params = [$this->tenant_id];
        $types = "i";

        if ($activeOnly) {
            $sql .= " AND c.is_active = 1 AND c.status = 'published'";
        }

        $sql .= " ORDER BY c.title ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $courses = [];
        while ($row = $result->fetch_assoc()) {
            $row['course_id'] = (int) $row['course_id'];
            $courses[] = $row;
        }
        $stmt->close();

        return $courses;
    }
}