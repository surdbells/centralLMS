<?php

/**
 * Learning Path Model
 * Multi-Tenant LMS
 *
 * Handles all learning path operations including CRUD, publishing, and statistics.
 */

class LearningPath
{
    // Database connection
    private mysqli $conn;

    // Properties
    public ?int $path_id = null;
    public int $tenant_id;
    public string $title = '';
    public string $slug = '';
    public string $short_description = '';
    public string $description = '';
    public ?string $thumbnail_url = null;
    public ?string $banner_url = null;
    public ?int $category_id = null;
    public string $skill_level = 'all_levels';
    public string $path_type = 'sequential';
    public bool $is_mandatory = false;
    public bool $enforce_prerequisites = true;
    public bool $allow_skip = false;
    public ?float $estimated_duration_hours = null;
    public ?int $estimated_weeks = null;
    public ?float $weekly_commitment_hours = null;
    public string $completion_type = 'all_items';
    public float $completion_threshold = 100.00;
    public ?float $passing_score = null;
    public bool $certificate_enabled = false;
    public ?int $certificate_template_id = null;
    public ?int $certificate_validity_months = null;
    public int $total_points = 0;
    public ?int $badge_id = null;
    public string $status = 'draft';
    public string $visibility = 'public';
    public ?string $publish_date = null;
    public ?string $unpublish_date = null;
    public string $enrollment_type = 'open';
    public ?int $max_enrollments = null;
    public ?string $enrollment_start_date = null;
    public ?string $enrollment_end_date = null;
    public bool $is_free = true;
    public float $price = 0.00;
    public string $currency = 'USD';
    public ?array $tags = null;
    public ?array $target_audience = null;
    public ?array $learning_objectives = null;
    public string $prerequisites_description = '';
    public ?int $created_by = null;
    public ?int $updated_by = null;
    public bool $is_active = true;

    // Pagination & Sorting
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'created_at';
    public string $sort_dir = 'desc';

    // Search & Filter
    public string $search = '';

    /**
     * Constructor
     */
    public function __construct(mysqli $db)
    {
        $this->conn = $db;
    }

    /**
     * Generate URL-friendly slug
     */
    private function generateSlug(string $title): string
    {
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');

        // Check for uniqueness
        $baseSlug = $slug;
        $counter = 1;

        while ($this->slugExists($slug)) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Check if slug exists
     */
    private function slugExists(string $slug): bool
    {
        $sql = "SELECT path_id FROM learning_paths WHERE tenant_id = ? AND slug = ? AND path_id != ? AND is_active = 1";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $pathId = $this->path_id ?? 0;
        $stmt->bind_param("isi", $this->tenant_id, $slug, $pathId);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->num_rows > 0;
    }

    /**
     * Create new learning path
     *
     * INSERT has 40 columns (excluding created_at which uses NOW())
     * Type string must have exactly 40 characters matching 40 bind parameters
     */
    public function create(): array
    {
        // Generate slug if not provided
        if (empty($this->slug)) {
            $this->slug = $this->generateSlug($this->title);
        }

        $sql = "INSERT INTO learning_paths (
            tenant_id, title, slug, short_description, description, thumbnail_url, banner_url,
            category_id, skill_level, path_type, is_mandatory, enforce_prerequisites, allow_skip,
            estimated_duration_hours, estimated_weeks, weekly_commitment_hours,
            completion_type, completion_threshold, passing_score,
            certificate_enabled, certificate_template_id, certificate_validity_months,
            total_points, badge_id, status, visibility, publish_date, unpublish_date,
            enrollment_type, max_enrollments, enrollment_start_date, enrollment_end_date,
            is_free, price, currency, tags, target_audience, learning_objectives,
            prerequisites_description, created_by, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmt = $this->conn->prepare($sql);

        if (!$stmt) {
            return ['success' => false, 'error' => 'Prepare failed: ' . $this->conn->error];
        }

        // Prepare JSON values
        $tags_json = $this->tags ? json_encode($this->tags) : null;
        $target_audience_json = $this->target_audience ? json_encode($this->target_audience) : null;
        $learning_objectives_json = $this->learning_objectives ? json_encode($this->learning_objectives) : null;

        // Prepare boolean values as integers
        $is_mandatory = $this->is_mandatory ? 1 : 0;
        $enforce_prerequisites = $this->enforce_prerequisites ? 1 : 0;
        $allow_skip = $this->allow_skip ? 1 : 0;
        $certificate_enabled = $this->certificate_enabled ? 1 : 0;
        $is_free = $this->is_free ? 1 : 0;

        /**
         * Type string breakdown (40 parameters):
         * 1.  tenant_id: i (int)
         * 2.  title: s (string)
         * 3.  slug: s (string)
         * 4.  short_description: s (string)
         * 5.  description: s (string)
         * 6.  thumbnail_url: s (string/null)
         * 7.  banner_url: s (string/null)
         * 8.  category_id: i (int/null)
         * 9.  skill_level: s (string)
         * 10. path_type: s (string)
         * 11. is_mandatory: i (int - boolean)
         * 12. enforce_prerequisites: i (int - boolean)
         * 13. allow_skip: i (int - boolean)
         * 14. estimated_duration_hours: d (double/float)
         * 15. estimated_weeks: i (int/null)
         * 16. weekly_commitment_hours: d (double/float)
         * 17. completion_type: s (string)
         * 18. completion_threshold: d (double/float)
         * 19. passing_score: d (double/float)
         * 20. certificate_enabled: i (int - boolean)
         * 21. certificate_template_id: i (int/null)
         * 22. certificate_validity_months: i (int/null)
         * 23. total_points: i (int)
         * 24. badge_id: i (int/null)
         * 25. status: s (string)
         * 26. visibility: s (string)
         * 27. publish_date: s (string/null - datetime)
         * 28. unpublish_date: s (string/null - datetime)
         * 29. enrollment_type: s (string)
         * 30. max_enrollments: i (int/null)
         * 31. enrollment_start_date: s (string/null - datetime)
         * 32. enrollment_end_date: s (string/null - datetime)
         * 33. is_free: i (int - boolean)
         * 34. price: d (double/float)
         * 35. currency: s (string)
         * 36. tags: s (string - JSON)
         * 37. target_audience: s (string - JSON)
         * 38. learning_objectives: s (string - JSON)
         * 39. prerequisites_description: s (string)
         * 40. created_by: i (int)
         *
         * Type string: issssssississiiididsddiiiisssssissidssssi
         * Count:       1234567890123456789012345678901234567890 = 40 characters
         */
        $types = "issssssisssiiididsddiiiiisssssissidssssi";

        $stmt->bind_param(
            $types,
            $this->tenant_id,              // 1. i
            $this->title,                  // 2. s
            $this->slug,                   // 3. s
            $this->short_description,      // 4. s
            $this->description,            // 5. s
            $this->thumbnail_url,          // 6. s
            $this->banner_url,             // 7. s
            $this->category_id,            // 8. i
            $this->skill_level,            // 9. s
            $this->path_type,              // 10. s
            $is_mandatory,                 // 11. i
            $enforce_prerequisites,        // 12. i
            $allow_skip,                   // 13. i
            $this->estimated_duration_hours,   // 14. d
            $this->estimated_weeks,        // 15. i
            $this->weekly_commitment_hours,    // 16. d
            $this->completion_type,        // 17. s
            $this->completion_threshold,   // 18. d
            $this->passing_score,          // 19. d
            $certificate_enabled,          // 20. i
            $this->certificate_template_id,    // 21. i
            $this->certificate_validity_months,// 22. i
            $this->total_points,           // 23. i
            $this->badge_id,               // 24. i
            $this->status,                 // 25. s
            $this->visibility,             // 26. s
            $this->publish_date,           // 27. s
            $this->unpublish_date,         // 28. s
            $this->enrollment_type,        // 29. s
            $this->max_enrollments,        // 30. i
            $this->enrollment_start_date,  // 31. s
            $this->enrollment_end_date,    // 32. s
            $is_free,                      // 33. i
            $this->price,                  // 34. d
            $this->currency,               // 35. s
            $tags_json,                    // 36. s
            $target_audience_json,         // 37. s
            $learning_objectives_json,     // 38. s
            $this->prerequisites_description,  // 39. s
            $this->created_by              // 40. i
        );

        if ($stmt->execute()) {
            $this->path_id = $this->conn->insert_id;
            $this->logActivity('path_created');
            return [
                'success' => true,
                'data' => [
                    'path_id' => $this->path_id,
                    'slug' => $this->slug
                ]
            ];
        }

        return ['success' => false, 'error' => $stmt->error];
    }

    /**
     * Update learning path
     *
     * UPDATE has 36 SET columns + 2 WHERE conditions = 38 parameters
     * Type string must have exactly 38 characters
     */
    public function update(): array
    {
        // First load current values to preserve unchanged fields
        $current = $this->getById($this->path_id);
        if (!$current) {
            return ['success' => false, 'error' => 'Learning path not found'];
        }

        // Merge current values with any new values (for fields that weren't explicitly set)
        if (empty($this->title)) {
            $this->title = $current['title'];
        }
        if (empty($this->short_description)) {
            $this->short_description = $current['short_description'] ?? '';
        }
        if (empty($this->description)) {
            $this->description = $current['description'] ?? '';
        }

        // Regenerate slug if title changed
        if ($this->title !== $current['title']) {
            $this->slug = $this->generateSlug($this->title);
        } else {
            $this->slug = $current['slug'];
        }

        $sql = "UPDATE learning_paths SET 
            title = ?, slug = ?, short_description = ?, description = ?,
            thumbnail_url = ?, banner_url = ?, category_id = ?, skill_level = ?,
            path_type = ?, is_mandatory = ?, enforce_prerequisites = ?, allow_skip = ?,
            estimated_duration_hours = ?, estimated_weeks = ?, weekly_commitment_hours = ?,
            completion_type = ?, completion_threshold = ?, passing_score = ?,
            certificate_enabled = ?, certificate_template_id = ?, certificate_validity_months = ?,
            total_points = ?, badge_id = ?, visibility = ?,
            enrollment_type = ?, max_enrollments = ?, enrollment_start_date = ?, enrollment_end_date = ?,
            is_free = ?, price = ?, currency = ?, tags = ?, target_audience = ?, learning_objectives = ?,
            prerequisites_description = ?, updated_by = ?, updated_at = NOW()
            WHERE path_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);

        if (!$stmt) {
            return ['success' => false, 'error' => 'Prepare failed: ' . $this->conn->error];
        }

        // Prepare JSON values
        $tags_json = $this->tags ? json_encode($this->tags) : null;
        $target_audience_json = $this->target_audience ? json_encode($this->target_audience) : null;
        $learning_objectives_json = $this->learning_objectives ? json_encode($this->learning_objectives) : null;

        // Prepare boolean values as integers
        $is_mandatory = $this->is_mandatory ? 1 : 0;
        $enforce_prerequisites = $this->enforce_prerequisites ? 1 : 0;
        $allow_skip = $this->allow_skip ? 1 : 0;
        $certificate_enabled = $this->certificate_enabled ? 1 : 0;
        $is_free = $this->is_free ? 1 : 0;

        /**
         * Type string breakdown (38 parameters):
         * SET clause (36 parameters):
         * 1.  title: s
         * 2.  slug: s
         * 3.  short_description: s
         * 4.  description: s
         * 5.  thumbnail_url: s
         * 6.  banner_url: s
         * 7.  category_id: i
         * 8.  skill_level: s
         * 9.  path_type: s
         * 10. is_mandatory: i
         * 11. enforce_prerequisites: i
         * 12. allow_skip: i
         * 13. estimated_duration_hours: d
         * 14. estimated_weeks: i
         * 15. weekly_commitment_hours: d
         * 16. completion_type: s
         * 17. completion_threshold: d
         * 18. passing_score: d
         * 19. certificate_enabled: i
         * 20. certificate_template_id: i
         * 21. certificate_validity_months: i
         * 22. total_points: i
         * 23. badge_id: i
         * 24. visibility: s
         * 25. enrollment_type: s
         * 26. max_enrollments: i
         * 27. enrollment_start_date: s
         * 28. enrollment_end_date: s
         * 29. is_free: i
         * 30. price: d
         * 31. currency: s
         * 32. tags: s
         * 33. target_audience: s
         * 34. learning_objectives: s
         * 35. prerequisites_description: s
         * 36. updated_by: i
         * WHERE clause (2 parameters):
         * 37. path_id: i
         * 38. tenant_id: i
         *
         * Type string: ssssssississiiididsddiiiississidssssiiii
         * Count:       12345678901234567890123456789012345678 = 38 characters
         */
        $types = "ssssssissiiididsddiiiiississidsssssiii";

        $stmt->bind_param(
            $types,
            $this->title,                  // 1. s
            $this->slug,                   // 2. s
            $this->short_description,      // 3. s
            $this->description,            // 4. s
            $this->thumbnail_url,          // 5. s
            $this->banner_url,             // 6. s
            $this->category_id,            // 7. i
            $this->skill_level,            // 8. s
            $this->path_type,              // 9. s
            $is_mandatory,                 // 10. i
            $enforce_prerequisites,        // 11. i
            $allow_skip,                   // 12. i
            $this->estimated_duration_hours,   // 13. d
            $this->estimated_weeks,        // 14. i
            $this->weekly_commitment_hours,    // 15. d
            $this->completion_type,        // 16. s
            $this->completion_threshold,   // 17. d
            $this->passing_score,          // 18. d
            $certificate_enabled,          // 19. i
            $this->certificate_template_id,    // 20. i
            $this->certificate_validity_months,// 21. i
            $this->total_points,           // 22. i
            $this->badge_id,               // 23. i
            $this->visibility,             // 24. s
            $this->enrollment_type,        // 25. s
            $this->max_enrollments,        // 26. i
            $this->enrollment_start_date,  // 27. s
            $this->enrollment_end_date,    // 28. s
            $is_free,                      // 29. i
            $this->price,                  // 30. d
            $this->currency,               // 31. s
            $tags_json,                    // 32. s
            $target_audience_json,         // 33. s
            $learning_objectives_json,     // 34. s
            $this->prerequisites_description,  // 35. s
            $this->updated_by,             // 36. i
            $this->path_id,                // 37. i (WHERE)
            $this->tenant_id               // 38. i (WHERE)
        );

        if ($stmt->execute()) {
            $this->logActivity('path_updated');
            return ['success' => true];
        }

        return ['success' => false, 'error' => $stmt->error];
    }

    /**
     * Get learning path by ID
     */
    public function getById(int $path_id): ?array
    {
        $sql = "SELECT lp.*, 
                c.name as category_name,
                u.first_name as created_by_first_name, 
                u.last_name as created_by_last_name,
                (SELECT COUNT(*) FROM learning_path_items WHERE path_id = lp.path_id AND is_active = 1) as items_count,
                (SELECT COUNT(*) FROM learning_path_sections WHERE path_id = lp.path_id AND is_active = 1) as sections_count,
                (SELECT COUNT(*) FROM learning_path_enrollments WHERE path_id = lp.path_id AND is_active = 1) as total_enrollments
                FROM learning_paths lp
                LEFT JOIN categories c ON lp.category_id = c.category_id
                LEFT JOIN users u ON lp.created_by = u.id
                WHERE lp.path_id = ? AND lp.tenant_id = ? AND lp.is_active = 1";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("ii", $path_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if ($result) {
            // Cast numeric fields
            $result['path_id'] = (int) $result['path_id'];
            $result['tenant_id'] = (int) $result['tenant_id'];
            $result['category_id'] = $result['category_id'] ? (int) $result['category_id'] : null;
            $result['total_points'] = (int) $result['total_points'];
            $result['items_count'] = (int) $result['items_count'];
            $result['sections_count'] = (int) $result['sections_count'];
            $result['total_enrollments'] = (int) $result['total_enrollments'];

            // Cast boolean fields
            $result['is_mandatory'] = (bool) $result['is_mandatory'];
            $result['enforce_prerequisites'] = (bool) $result['enforce_prerequisites'];
            $result['allow_skip'] = (bool) $result['allow_skip'];
            $result['certificate_enabled'] = (bool) $result['certificate_enabled'];
            $result['is_free'] = (bool) $result['is_free'];
            $result['is_active'] = (bool) $result['is_active'];

            // Parse JSON fields
            $result['tags'] = $result['tags'] ? json_decode($result['tags'], true) : [];
            $result['target_audience'] = $result['target_audience'] ? json_decode($result['target_audience'], true) : [];
            $result['learning_objectives'] = $result['learning_objectives'] ? json_decode($result['learning_objectives'], true) : [];
        }

        return $result;
    }

    /**
     * Get learning path by slug
     */
    public function getBySlug(string $slug): ?array
    {
        $sql = "SELECT path_id FROM learning_paths WHERE slug = ? AND tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("si", $slug, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if ($result) {
            return $this->getById($result['path_id']);
        }

        return null;
    }

    /**
     * Get all learning paths with filters and pagination
     */
    public function getAll(array $filters = []): array
    {
        $where = ["lp.tenant_id = ?", "lp.is_active = 1"];
        $params = [$this->tenant_id];
        $types = "i";

        // Apply filters
        if (!empty($filters['status'])) {
            $where[] = "lp.status = ?";
            $params[] = $filters['status'];
            $types .= "s";
        }

        if (!empty($filters['skill_level'])) {
            $where[] = "lp.skill_level = ?";
            $params[] = $filters['skill_level'];
            $types .= "s";
        }

        if (!empty($filters['category_id'])) {
            $where[] = "lp.category_id = ?";
            $params[] = (int) $filters['category_id'];
            $types .= "i";
        }

        if (!empty($filters['visibility'])) {
            $where[] = "lp.visibility = ?";
            $params[] = $filters['visibility'];
            $types .= "s";
        }

        if (isset($filters['is_mandatory'])) {
            $where[] = "lp.is_mandatory = ?";
            $params[] = (int) $filters['is_mandatory'];
            $types .= "i";
        }

        if (!empty($filters['search'])) {
            $where[] = "(lp.title LIKE ? OR lp.short_description LIKE ? OR lp.description LIKE ?)";
            $searchTerm = "%" . $filters['search'] . "%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= "sss";
        }

        $whereClause = implode(" AND ", $where);

        // Count total
        $countSql = "SELECT COUNT(*) as total FROM learning_paths lp WHERE $whereClause";
        $stmt = $this->conn->prepare($countSql);
        if (!$stmt) {
            return ['data' => [], 'pagination' => ['total' => 0, 'page' => 1, 'per_page' => $this->per_page, 'total_pages' => 0]];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];

        // Get data with pagination
        $offset = ($this->page - 1) * $this->per_page;
        $orderBy = $this->validateSortColumn($this->sort_by);
        $orderDir = strtoupper($this->sort_dir) === 'ASC' ? 'ASC' : 'DESC';

        $sql = "SELECT lp.*, 
                c.name as category_name,
                (SELECT COUNT(*) FROM learning_path_items WHERE path_id = lp.path_id AND is_active = 1) as items_count,
                (SELECT COUNT(*) FROM learning_path_enrollments WHERE path_id = lp.path_id AND is_active = 1) as enrollments_count
                FROM learning_paths lp
                LEFT JOIN categories c ON lp.category_id = c.category_id
                WHERE $whereClause
                ORDER BY lp.$orderBy $orderDir
                LIMIT ? OFFSET ?";

        $params[] = $this->per_page;
        $params[] = $offset;
        $types .= "ii";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return ['data' => [], 'pagination' => ['total' => 0, 'page' => 1, 'per_page' => $this->per_page, 'total_pages' => 0]];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $paths = [];
        while ($row = $result->fetch_assoc()) {
            $row['path_id'] = (int) $row['path_id'];
            $row['items_count'] = (int) $row['items_count'];
            $row['enrollments_count'] = (int) $row['enrollments_count'];
            $row['is_mandatory'] = (bool) $row['is_mandatory'];
            $row['is_free'] = (bool) $row['is_free'];
            $row['tags'] = $row['tags'] ? json_decode($row['tags'], true) : [];
            $paths[] = $row;
        }

        return [
            'data' => $paths,
            'pagination' => [
                'total' => (int) $total,
                'page' => $this->page,
                'per_page' => $this->per_page,
                'total_pages' => (int) ceil($total / $this->per_page)
            ]
        ];
    }

    /**
     * Validate sort column to prevent SQL injection
     */
    private function validateSortColumn(string $column): string
    {
        $allowed = ['created_at', 'updated_at', 'title', 'status', 'skill_level', 'total_enrollments', 'avg_rating'];
        return in_array($column, $allowed) ? $column : 'created_at';
    }

    /**
     * Publish learning path
     */
    public function publish(): array
    {
        $validation = $this->validateForPublish();
        if (!$validation['valid']) {
            return ['success' => false, 'errors' => $validation['errors']];
        }

        $sql = "UPDATE learning_paths SET 
                status = 'published', 
                publish_date = NOW(), 
                updated_by = ?, 
                updated_at = NOW() 
                WHERE path_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return ['success' => false, 'error' => 'Prepare failed: ' . $this->conn->error];
        }
        $stmt->bind_param("iii", $this->updated_by, $this->path_id, $this->tenant_id);

        if ($stmt->execute()) {
            $this->logActivity('path_published');
            return ['success' => true];
        }

        return ['success' => false, 'error' => $stmt->error];
    }

    /**
     * Validate learning path for publishing
     */
    private function validateForPublish(): array
    {
        $errors = [];

        // Check if path has items
        $sql = "SELECT COUNT(*) as count FROM learning_path_items WHERE path_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $errors[] = "Database error";
            return ['valid' => false, 'errors' => $errors];
        }
        $stmt->bind_param("i", $this->path_id);
        $stmt->execute();
        $itemCount = $stmt->get_result()->fetch_assoc()['count'];

        if ($itemCount === 0) {
            $errors[] = "Learning path must have at least one item";
        }

        // Check required fields
        $path = $this->getById($this->path_id);
        if ($path) {
            if (empty($path['title'])) $errors[] = "Title is required";
            if (empty($path['short_description'])) $errors[] = "Short description is required";
        } else {
            $errors[] = "Learning path not found";
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Archive learning path
     */
    public function archive(): bool
    {
        $sql = "UPDATE learning_paths SET status = 'archived', updated_by = ?, updated_at = NOW() WHERE path_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("iii", $this->updated_by, $this->path_id, $this->tenant_id);

        if ($stmt->execute()) {
            $this->logActivity('path_archived');
            return true;
        }
        return false;
    }

    /**
     * Soft delete learning path
     */
    public function delete(): bool
    {
        $sql = "UPDATE learning_paths SET is_active = 0, deleted_at = NOW(), updated_by = ? WHERE path_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("iii", $this->updated_by, $this->path_id, $this->tenant_id);

        if ($stmt->execute()) {
            $this->logActivity('path_deleted');
            return true;
        }
        return false;
    }

    /**
     * Duplicate learning path
     */
    public function duplicate(?string $newTitle = null): array
    {
        $path = $this->getById($this->path_id);
        if (!$path) {
            return ['success' => false, 'error' => 'Original path not found'];
        }

        // Create new path with copied properties
        $this->title = $newTitle ?? $path['title'] . ' (Copy)';
        $this->slug = ''; // Will be auto-generated
        $this->short_description = $path['short_description'] ?? '';
        $this->description = $path['description'] ?? '';
        $this->thumbnail_url = $path['thumbnail_url'];
        $this->banner_url = $path['banner_url'];
        $this->category_id = $path['category_id'];
        $this->skill_level = $path['skill_level'] ?? 'all_levels';
        $this->path_type = $path['path_type'] ?? 'sequential';
        $this->is_mandatory = (bool) $path['is_mandatory'];
        $this->enforce_prerequisites = (bool) $path['enforce_prerequisites'];
        $this->allow_skip = (bool) $path['allow_skip'];
        $this->estimated_duration_hours = $path['estimated_duration_hours'];
        $this->estimated_weeks = $path['estimated_weeks'];
        $this->weekly_commitment_hours = $path['weekly_commitment_hours'];
        $this->completion_type = $path['completion_type'] ?? 'all_items';
        $this->completion_threshold = $path['completion_threshold'] ?? 100;
        $this->passing_score = $path['passing_score'];
        $this->certificate_enabled = (bool) $path['certificate_enabled'];
        $this->certificate_template_id = $path['certificate_template_id'];
        $this->certificate_validity_months = $path['certificate_validity_months'];
        $this->total_points = $path['total_points'] ?? 0;
        $this->badge_id = $path['badge_id'];
        $this->status = 'draft'; // Always start as draft
        $this->visibility = $path['visibility'] ?? 'public';
        $this->enrollment_type = $path['enrollment_type'] ?? 'open';
        $this->max_enrollments = $path['max_enrollments'];
        $this->is_free = (bool) $path['is_free'];
        $this->price = $path['price'] ?? 0;
        $this->currency = $path['currency'] ?? 'USD';
        $this->tags = $path['tags'] ?? [];
        $this->target_audience = $path['target_audience'] ?? [];
        $this->learning_objectives = $path['learning_objectives'] ?? [];
        $this->prerequisites_description = $path['prerequisites_description'] ?? '';

        $originalPathId = $this->path_id;
        $this->path_id = null; // Reset for new creation

        $result = $this->create();
        if (!$result['success']) {
            return $result;
        }

        $newPathId = $result['data']['path_id'];

        // Copy sections
        $this->duplicateSections($originalPathId, $newPathId);

        // Copy items
        $this->duplicateItems($originalPathId, $newPathId);

        return [
            'success' => true,
            'data' => [
                'path_id' => $newPathId,
                'slug' => $result['data']['slug']
            ]
        ];
    }

    /**
     * Duplicate sections to new path
     */
    private function duplicateSections(int $sourcePathId, int $targetPathId): void
    {
        $sql = "INSERT INTO learning_path_sections (path_id, tenant_id, title, description, sort_order, is_milestone, unlock_type, completion_required, min_items_to_complete, created_at)
                SELECT ?, tenant_id, title, description, sort_order, is_milestone, unlock_type, completion_required, min_items_to_complete, NOW()
                FROM learning_path_sections WHERE path_id = ? AND is_active = 1";

        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ii", $targetPathId, $sourcePathId);
            $stmt->execute();
        }
    }

    /**
     * Duplicate items to new path
     */
    private function duplicateItems(int $sourcePathId, int $targetPathId): void
    {
        $sql = "INSERT INTO learning_path_items (path_id, tenant_id, item_type, course_id, assessment_id, live_session_id, media_id, external_url, external_title, external_description, sort_order, is_required, unlock_type, completion_type, points_value, estimated_duration_minutes, instructor_notes, learner_notes, created_at)
                SELECT ?, tenant_id, item_type, course_id, assessment_id, live_session_id, media_id, external_url, external_title, external_description, sort_order, is_required, unlock_type, completion_type, points_value, estimated_duration_minutes, instructor_notes, learner_notes, NOW()
                FROM learning_path_items WHERE path_id = ? AND is_active = 1";

        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ii", $targetPathId, $sourcePathId);
            $stmt->execute();
        }
    }

    /**
     * Get learning path statistics
     */
    public function getStatistics(): array
    {
        $sql = "SELECT 
                COUNT(DISTINCT CASE WHEN status = 'active' THEN enrollment_id END) as active_enrollments,
                COUNT(DISTINCT CASE WHEN status = 'completed' THEN enrollment_id END) as completed_enrollments,
                COUNT(DISTINCT CASE WHEN status = 'withdrawn' THEN enrollment_id END) as withdrawn_enrollments,
                AVG(progress_percentage) as avg_progress,
                AVG(CASE WHEN status = 'completed' THEN DATEDIFF(completed_at, enrolled_at) END) as avg_completion_days,
                AVG(total_time_spent_minutes) as avg_time_spent,
                SUM(total_points_earned) as total_points_awarded
                FROM learning_path_enrollments
                WHERE path_id = ? AND is_active = 1";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [
                'active_enrollments' => 0,
                'completed_enrollments' => 0,
                'withdrawn_enrollments' => 0,
                'avg_progress' => 0,
                'avg_completion_days' => 0,
                'avg_time_spent' => 0,
                'total_points_awarded' => 0,
                'avg_rating' => 0,
                'total_reviews' => 0
            ];
        }
        $stmt->bind_param("i", $this->path_id);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();

        // Get rating stats
        $sql = "SELECT AVG(rating) as avg_rating, COUNT(*) as total_reviews
                FROM learning_path_reviews
                WHERE path_id = ? AND status = 'approved' AND is_active = 1";

        $stmt = $this->conn->prepare($sql);
        $ratings = ['avg_rating' => null, 'total_reviews' => 0];
        if ($stmt) {
            $stmt->bind_param("i", $this->path_id);
            $stmt->execute();
            $ratings = $stmt->get_result()->fetch_assoc();
        }

        return [
            'active_enrollments' => (int) ($stats['active_enrollments'] ?? 0),
            'completed_enrollments' => (int) ($stats['completed_enrollments'] ?? 0),
            'withdrawn_enrollments' => (int) ($stats['withdrawn_enrollments'] ?? 0),
            'avg_progress' => round((float) ($stats['avg_progress'] ?? 0), 2),
            'avg_completion_days' => round((float) ($stats['avg_completion_days'] ?? 0), 1),
            'avg_time_spent' => round((float) ($stats['avg_time_spent'] ?? 0), 1),
            'total_points_awarded' => (int) ($stats['total_points_awarded'] ?? 0),
            'avg_rating' => round((float) ($ratings['avg_rating'] ?? 0), 2),
            'total_reviews' => (int) ($ratings['total_reviews'] ?? 0)
        ];
    }

    /**
     * Get all published paths for catalog
     */
    public function getCatalog(): array
    {
        $offset = ($this->page - 1) * $this->per_page;

        // Use subqueries instead of relying on potentially non-existent columns
        $sql = "SELECT lp.path_id, lp.title, lp.slug, lp.short_description, lp.thumbnail_url,
                lp.skill_level, lp.estimated_duration_hours, lp.is_free, lp.price, lp.currency,
                lp.path_type, lp.total_points,
                (SELECT COUNT(*) FROM learning_path_items WHERE path_id = lp.path_id AND is_active = 1) as total_items,
                (SELECT COUNT(*) FROM learning_path_enrollments WHERE path_id = lp.path_id AND is_active = 1) as total_enrollments,
                (SELECT AVG(rating) FROM learning_path_reviews WHERE path_id = lp.path_id AND status = 'approved' AND is_active = 1) as avg_rating,
                (SELECT COUNT(*) FROM learning_path_reviews WHERE path_id = lp.path_id AND status = 'approved' AND is_active = 1) as total_ratings,
                c.name as category_name
                FROM learning_paths lp
                LEFT JOIN categories c ON lp.category_id = c.category_id
                WHERE lp.tenant_id = ? AND lp.status = 'published' AND lp.is_active = 1
                AND (lp.visibility = 'public' OR lp.visibility = 'enrolled_only')
                ORDER BY total_enrollments DESC, lp.created_at DESC
                LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return ['data' => [], 'pagination' => ['total' => 0, 'page' => 1, 'per_page' => $this->per_page, 'total_pages' => 0]];
        }
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $paths = [];
        while ($row = $result->fetch_assoc()) {
            $row['path_id'] = (int) $row['path_id'];
            $row['total_items'] = (int) $row['total_items'];
            $row['total_enrollments'] = (int) $row['total_enrollments'];
            $row['total_ratings'] = (int) $row['total_ratings'];
            $row['avg_rating'] = round((float) ($row['avg_rating'] ?? 0), 2);
            $row['is_free'] = (bool) $row['is_free'];
            $paths[] = $row;
        }

        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM learning_paths 
                     WHERE tenant_id = ? AND status = 'published' AND is_active = 1
                     AND (visibility = 'public' OR visibility = 'enrolled_only')";
        $stmt = $this->conn->prepare($countSql);
        $total = 0;
        if ($stmt) {
            $stmt->bind_param("i", $this->tenant_id);
            $stmt->execute();
            $total = $stmt->get_result()->fetch_assoc()['total'];
        }

        return [
            'data' => $paths,
            'pagination' => [
                'total' => (int) $total,
                'page' => $this->page,
                'per_page' => $this->per_page,
                'total_pages' => (int) ceil($total / $this->per_page)
            ]
        ];
    }

    /**
     * Log activity
     */
    private function logActivity(string $activityType, array $data = []): void
    {
        // Check if activity log table exists before inserting
        $checkTable = $this->conn->query("SHOW TABLES LIKE 'learning_path_activity_log'");
        if (!$checkTable || $checkTable->num_rows === 0) {
            return; // Table doesn't exist, skip logging
        }

        $sql = "INSERT INTO learning_path_activity_log (path_id, user_id, tenant_id, activity_type, activity_data, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return; // Skip if prepare fails
        }

        $userId = $this->updated_by ?? $this->created_by;
        $activityData = !empty($data) ? json_encode($data) : null;

        $stmt->bind_param("iiiss", $this->path_id, $userId, $this->tenant_id, $activityType, $activityData);
        $stmt->execute();
    }

    /**
     * Update item and section counts on the learning path
     */
    public function updateCounts(): void
    {
        $sql = "UPDATE learning_paths lp 
                SET total_items = (SELECT COUNT(*) FROM learning_path_items WHERE path_id = lp.path_id AND is_active = 1),
                    total_points = (SELECT COALESCE(SUM(points_value), 0) FROM learning_path_items WHERE path_id = lp.path_id AND is_active = 1)
                WHERE path_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ii", $this->path_id, $this->tenant_id);
            $stmt->execute();
        }
    }
}