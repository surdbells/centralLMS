<?php
/**
 * CourseService — Course information CRUD.
 * Handles listing, get, create, update (info only — not modules/lessons/content).
 *
 * Column mapping (courses table):
 *   c.category_id  → categories.category_id (categories.name)
 *   c.instructor_id → users.id
 *   c.created_by   → users.id
 *   c.certificate_template_id → certificate_templates.template_id
 */
class CourseService
{
    private mysqli $conn;
    private int $tenant_id;
    private int $actor_id;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function setContext(int $tenant_id, int $actor_id): self
    {
        $this->tenant_id = $tenant_id;
        $this->actor_id  = $actor_id;
        return $this;
    }

    // ═══════════════════════════════════════════
    //  LIST COURSES
    // ═══════════════════════════════════════════

    public function listCourses(array $params = []): array
    {
        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = min(100, max(1, (int)($params['per_page'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        $sortWhitelist = [
            'title' => 'c.title', 'created_at' => 'c.created_at', 'updated_at' => 'c.updated_at',
            'status' => 'c.status', 'category' => 'cat.name', 'rating' => 'c.average_rating',
            'duration' => 'c.duration_minutes',
        ];
        $sortCol = $sortWhitelist[$params['sort_by'] ?? 'title'] ?? 'c.title';
        $sortDir = strtoupper($params['sort_dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        $where = ["c.tenant_id = ?"];
        $types = 'i';
        $binds = [$this->tenant_id];

        if (!empty($params['search'])) {
            $term = '%' . $params['search'] . '%';
            $where[] = "(c.title LIKE ? OR c.description LIKE ?)";
            $types .= 'ss';
            array_push($binds, $term, $term);
        }
        if (!empty($params['status'])) {
            $where[] = "c.status = ?"; $types .= 's'; $binds[] = $params['status'];
        }
        if (!empty($params['category_id'])) {
            $where[] = "c.category_id = ?"; $types .= 'i'; $binds[] = (int)$params['category_id'];
        }
        if (isset($params['is_mandatory']) && $params['is_mandatory'] !== '') {
            $where[] = "c.is_mandatory = ?"; $types .= 'i'; $binds[] = (int)$params['is_mandatory'];
        }
        if (isset($params['is_active']) && $params['is_active'] !== '') {
            $where[] = "c.is_active = ?"; $types .= 'i'; $binds[] = (int)$params['is_active'];
        }
        if (!empty($params['instructor_id'])) {
            $where[] = "c.instructor_id = ?"; $types .= 'i'; $binds[] = (int)$params['instructor_id'];
        }
        if (!empty($params['level'])) {
            $where[] = "c.level = ?"; $types .= 's'; $binds[] = $params['level'];
        }
        if (!empty($params['course_type'])) {
            $where[] = "c.course_type = ?"; $types .= 's'; $binds[] = $params['course_type'];
        }

        $whereSQL = implode(' AND ', $where);

        // Count
        $cStmt = $this->conn->prepare("SELECT COUNT(*) as total FROM courses c WHERE $whereSQL");
        $cStmt->bind_param($types, ...$binds);
        $cStmt->execute();
        $total = (int)$cStmt->get_result()->fetch_assoc()['total'];

        // Data
        $sql = "SELECT c.course_id, c.title, c.course_type, c.description, c.summary,
                       c.thumbnail_url, c.category_id, c.pass_mark, c.instructor_id,
                       c.certificate_template_id, c.is_mandatory, c.status, c.level,
                       c.pass_percentage, c.duration_minutes, c.is_featured, c.is_active,
                       c.is_onboarding, c.enrollment_limit, c.allow_self_enrollment,
                       c.average_rating, c.rating_count, c.start_date, c.end_date,
                       c.created_at, c.updated_at, c.version,
                       cat.name as category_name,
                       CONCAT(i.first_name, ' ', i.last_name) as instructor_name,
                       CONCAT(cr.first_name, ' ', cr.last_name) as created_by_name,
                       (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.course_id AND ce.is_active = 1) as enrollment_count
                FROM courses c
                LEFT JOIN categories cat ON c.category_id = cat.category_id
                LEFT JOIN users i ON c.instructor_id = i.id
                LEFT JOIN users cr ON c.created_by = cr.id
                WHERE $whereSQL
                ORDER BY $sortCol $sortDir
                LIMIT $perPage OFFSET $offset";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        $stmt->execute();
        $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return [
            'data' => $courses,
            'meta' => [
                'total' => $total, 'page' => $page, 'per_page' => $perPage,
                'total_pages' => max(1, ceil($total / $perPage))
            ]
        ];
    }

    // ═══════════════════════════════════════════
    //  GET SINGLE COURSE
    // ═══════════════════════════════════════════

    public function getCourse(int $courseId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT c.*,
                    cat.name as category_name,
                    CONCAT(i.first_name, ' ', i.last_name) as instructor_name, i.email as instructor_email,
                    CONCAT(cr.first_name, ' ', cr.last_name) as created_by_name,
                    ct.template_name as certificate_template_name,
                    (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.course_id AND ce.is_active = 1) as enrollment_count
             FROM courses c
             LEFT JOIN categories cat ON c.category_id = cat.category_id
             LEFT JOIN users i ON c.instructor_id = i.id
             LEFT JOIN users cr ON c.created_by = cr.id
             LEFT JOIN certificate_templates ct ON c.certificate_template_id = ct.template_id
             WHERE c.course_id = ? AND c.tenant_id = ?"
        );
        $stmt->bind_param('ii', $courseId, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    // ═══════════════════════════════════════════
    //  CREATE COURSE
    // ═══════════════════════════════════════════

    public function createCourse(array $data): array
    {
        if (empty($data['title'])) return ['success' => false, 'error' => 'Title is required'];
        if (empty($data['instructor_id'])) return ['success' => false, 'error' => 'Instructor is required'];

        $stmt = $this->conn->prepare(
            "INSERT INTO courses (tenant_id, title, course_type, description, objectives, summary,
                                  thumbnail_url, category_id, pass_mark, instructor_id,
                                  certificate_template_id, is_mandatory, status, level,
                                  pass_percentage, duration_minutes, is_featured, is_active,
                                  is_onboarding, enrollment_limit, allow_self_enrollment,
                                  start_date, end_date, version, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $title          = $data['title'];
        $courseType      = $data['course_type'] ?? 'Regular';
        $description     = $data['description'] ?? null;
        $objectives      = $data['objectives'] ?? null;
        $summary         = $data['summary'] ?? null;
        $thumbnail       = $data['thumbnail_url'] ?? null;
        $categoryId      = !empty($data['category_id']) ? (int)$data['category_id'] : null;
        $passMark        = (int)($data['pass_mark'] ?? 70);
        $instructorId    = (int)$data['instructor_id'];
        $certTemplateId  = !empty($data['certificate_template_id']) ? (int)$data['certificate_template_id'] : null;
        $isMandatory     = (int)($data['is_mandatory'] ?? 0);
        $status          = $data['status'] ?? 'draft';
        $level           = $data['level'] ?? null;
        $passPercentage  = (int)($data['pass_percentage'] ?? 70);
        $duration        = !empty($data['duration_minutes']) ? (float)$data['duration_minutes'] : null;
        $isFeatured      = (int)($data['is_featured'] ?? 0);
        $isActive        = (int)($data['is_active'] ?? 1);
        $isOnboarding    = (int)($data['is_onboarding'] ?? 0);
        $enrollLimit     = (int)($data['enrollment_limit'] ?? 0);
        $allowSelfEnroll = (int)($data['allow_self_enrollment'] ?? 1);
        $startDate       = !empty($data['start_date']) ? $data['start_date'] : null;
        $endDate         = !empty($data['end_date']) ? $data['end_date'] : null;
        $version         = $data['version'] ?? '1.0';

        $stmt->bind_param('issssssiiiiissidiiiiisss' . 'i',
            $this->tenant_id, $title, $courseType, $description, $objectives, $summary,
            $thumbnail, $categoryId, $passMark, $instructorId,
            $certTemplateId, $isMandatory, $status, $level,
            $passPercentage, $duration, $isFeatured, $isActive,
            $isOnboarding, $enrollLimit, $allowSelfEnroll,
            $startDate, $endDate, $version, $this->actor_id
        );

        if (!$stmt->execute()) {
            return ['success' => false, 'error' => 'Failed to create course: ' . $this->conn->error];
        }

        return ['success' => true, 'course_id' => $this->conn->insert_id];
    }

    // ═══════════════════════════════════════════
    //  UPDATE COURSE
    // ═══════════════════════════════════════════

    public function updateCourse(int $courseId, array $data): array
    {
        // Verify course belongs to tenant
        $check = $this->conn->prepare("SELECT course_id FROM courses WHERE course_id = ? AND tenant_id = ?");
        $check->bind_param('ii', $courseId, $this->tenant_id);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) return ['success' => false, 'error' => 'Course not found'];

        $fieldMap = [
            'title'                   => 's', 'course_type'              => 's',
            'description'             => 's', 'objectives'               => 's',
            'summary'                 => 's', 'thumbnail_url'            => 's',
            'category_id'             => 'i', 'pass_mark'                => 'i',
            'instructor_id'           => 'i', 'certificate_template_id'  => 'i',
            'is_mandatory'            => 'i', 'status'                   => 's',
            'level'                   => 's', 'pass_percentage'          => 'i',
            'duration_minutes'        => 'd', 'is_featured'              => 'i',
            'is_active'               => 'i', 'is_onboarding'            => 'i',
            'enrollment_limit'        => 'i', 'allow_self_enrollment'    => 'i',
            'start_date'              => 's', 'end_date'                 => 's',
            'version'                 => 's',
        ];

        $sets = [];
        $types = '';
        $binds = [];

        foreach ($fieldMap as $field => $type) {
            if (array_key_exists($field, $data)) {
                $sets[] = "$field = ?";
                $types .= $type;
                $val = $data[$field];
                if ($type === 'i' && $val !== null) $val = (int)$val;
                elseif ($type === 'd' && $val !== null) $val = (float)$val;
                $binds[] = $val;
            }
        }

        if (empty($sets)) return ['success' => true, 'message' => 'No changes'];

        $sql = "UPDATE courses SET " . implode(', ', $sets) . " WHERE course_id = ? AND tenant_id = ?";
        $types .= 'ii';
        $binds[] = $courseId;
        $binds[] = $this->tenant_id;

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        $stmt->execute();

        return ['success' => true, 'affected' => $stmt->affected_rows];
    }

    // ═══════════════════════════════════════════
    //  DELETE (soft: set status = 'archived')
    // ═══════════════════════════════════════════

    public function deleteCourse(int $courseId): array
    {
        $stmt = $this->conn->prepare(
            "UPDATE courses SET status = 'archived', is_active = 0 WHERE course_id = ? AND tenant_id = ?"
        );
        $stmt->bind_param('ii', $courseId, $this->tenant_id);
        $stmt->execute();
        if ($stmt->affected_rows === 0) return ['success' => false, 'error' => 'Course not found'];
        return ['success' => true];
    }

    // ═══════════════════════════════════════════
    //  DUPLICATE
    // ═══════════════════════════════════════════

    public function duplicateCourse(int $courseId): array
    {
        $orig = $this->getCourse($courseId);
        if (!$orig) return ['success' => false, 'error' => 'Course not found'];

        $data = $orig;
        $data['title'] = $orig['title'] . ' (Copy)';
        $data['status'] = 'draft';
        $data['is_active'] = 0;
        $data['average_rating'] = 0;
        $data['rating_count'] = 0;
        unset($data['course_id'], $data['created_at'], $data['updated_at'],
              $data['category_name'], $data['instructor_name'], $data['created_by_name'],
              $data['certificate_template_name'], $data['enrollment_count'],
              $data['instructor_email'], $data['total_ratings'], $data['selfEnroll'], $data['column_name']);

        return $this->createCourse($data);
    }

    // ═══════════════════════════════════════════
    //  DROPDOWNS (categories, instructors, templates)
    // ═══════════════════════════════════════════

    public function getDropdowns(): array
    {
        // Categories
        $stmt = $this->conn->prepare(
            "SELECT category_id, name FROM categories WHERE tenant_id = ? ORDER BY name"
        );
        $stmt->bind_param('i', $this->tenant_id);
        $stmt->execute();
        $categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Instructors (users with instructor role or is_instructor flag)
        $stmt = $this->conn->prepare(
            "SELECT DISTINCT u.id, CONCAT(u.first_name, ' ', u.last_name) as name, u.email
             FROM users u
             LEFT JOIN user_roles ur ON u.id = ur.user_id
             LEFT JOIN roles r ON ur.role_id = r.role_id AND r.tenant_id = u.tenant_id
             WHERE u.tenant_id = ? AND u.deleted_at IS NULL AND u.is_active = 1
               AND (u.is_instructor = 1 OR u.is_admin = 1 OR r.role_key IN ('instructor', 'admin', 'superadmin'))
             ORDER BY u.first_name"
        );
        $stmt->bind_param('i', $this->tenant_id);
        $stmt->execute();
        $instructors = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Certificate templates
        $stmt = $this->conn->prepare(
            "SELECT template_id, template_name FROM certificate_templates WHERE tenant_id = ? ORDER BY template_name"
        );
        $stmt->bind_param('i', $this->tenant_id);
        $stmt->execute();
        $templates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return [
            'categories' => $categories,
            'instructors' => $instructors,
            'certificate_templates' => $templates
        ];
    }

    // ═══════════════════════════════════════════
    //  STATS
    // ═══════════════════════════════════════════

    public function getStats(): array
    {
        $stmt = $this->conn->prepare(
            "SELECT
                COUNT(*) as total,
                SUM(status = 'published') as published,
                SUM(status = 'draft') as draft,
                SUM(status = 'archived') as archived,
                SUM(is_mandatory = 1) as mandatory,
                SUM(is_featured = 1) as featured,
                SUM(is_onboarding = 1) as onboarding,
                AVG(NULLIF(average_rating, 0)) as avg_rating
             FROM courses WHERE tenant_id = ?"
        );
        $stmt->bind_param('i', $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}
