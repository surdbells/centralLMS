<?php
/**
 * Base Report Class
 * Common functionality for all LMS reports
 *
 * Phase 1 additions:
 *   - Org-unit filter properties (course_id, department_id, team_id, branch_id, learner_id)
 *   - Setter methods for each filter
 *   - buildOrgFilter()  — WHERE clause fragments
 *   - buildOrgJoins()   — LEFT JOIN clauses for departments, teams, branches
 *   - buildOrgSelectColumns() — SELECT fragments for org-unit names
 *   - hasOrgFilter()    — check if any org filter is active
 *   - Updated buildMeta() to include active filter info in response
 *
 * Column mapping (from users table schema):
 *   users.department  → departments.department_id
 *   users.team        → teams.team_id
 *   users.branch      → branches.branch_id
 *   users.id          → user PK
 *   users.manager_id  → references users.id
 */
abstract class BaseReport
{
    protected mysqli $conn;
    protected int $tenant_id;
    protected ?string $date_from = null;
    protected ?string $date_to = null;
    protected int $page = 1;
    protected int $per_page = 25;
    protected string $sort_by = 'created_at';
    protected string $sort_dir = 'DESC';
    protected array $filters = [];

    // Org-Unit Filter Properties
    protected ?int $course_id = null;
    protected ?int $department_id = null;
    protected ?int $team_id = null;
    protected ?int $branch_id = null;
    protected ?int $learner_id = null;
    protected ?int $manager_id = null;

    // Learner scope (for LearnerReports)
    protected ?int $user_id = null;

    public function __construct(mysqli $db)
    {
        $this->conn = $db;
    }

    public function setContext(int $tenant_id): void
    {
        $this->tenant_id = $tenant_id;
    }

    public function setDateRange(?string $from, ?string $to): void
    {
        $this->date_from = $from;
        $this->date_to = $to;
    }

    public function setPagination(int $page, int $per_page): void
    {
        $this->page = max(1, $page);
        $this->per_page = max(1, min(100, $per_page));
    }

    public function setSorting(string $sort_by, string $sort_dir): void
    {
        $this->sort_by = $sort_by;
        $this->sort_dir = strtoupper($sort_dir) === 'ASC' ? 'ASC' : 'DESC';
    }

    public function setFilters(array $filters): void
    {
        $this->filters = $filters;
    }

    // ========================================
    // Org-Unit Filter Setters
    // ========================================

    public function setCourseFilter(?int $course_id): void
    {
        $this->course_id = $course_id;
    }

    public function setDepartmentFilter(?int $department_id): void
    {
        $this->department_id = $department_id;
    }

    public function setTeamFilter(?int $team_id): void
    {
        $this->team_id = $team_id;
    }

    public function setBranchFilter(?int $branch_id): void
    {
        $this->branch_id = $branch_id;
    }

    public function setLearnerFilter(?int $learner_id): void
    {
        $this->learner_id = $learner_id;
    }

    public function setManagerFilter(?int $manager_id): void
    {
        $this->manager_id = $manager_id;
    }

    /** Set the authenticated learner (used by LearnerReports) */
    public function setUser(int $user_id): self
    {
        $this->user_id = $user_id;
        return $this;
    }

    // ========================================
    // Org-Unit Filter Builders
    // ========================================

    /**
     * Build WHERE clause fragments for org-unit filtering.
     *
     * Returns SQL like: " AND u.department = 5 AND u.branch = 2"
     * Returns empty string if no filters are active.
     *
     * @param string $userAlias   Alias for the users table (default 'u')
     * @param string $courseAlias  Alias for course_enrollments table (default 'ce')
     * @return string  SQL WHERE fragment (each part starts with " AND ...")
     */
    protected function buildOrgFilter(string $userAlias = 'u', string $courseAlias = 'ce'): string
    {
        $filter = "";

        if ($this->department_id !== null) {
            $filter .= " AND {$userAlias}.department = " . (int)$this->department_id;
        }
        if ($this->team_id !== null) {
            $filter .= " AND {$userAlias}.team = " . (int)$this->team_id;
        }
        if ($this->branch_id !== null) {
            $filter .= " AND {$userAlias}.branch = " . (int)$this->branch_id;
        }
        if ($this->learner_id !== null) {
            $filter .= " AND {$userAlias}.id = " . (int)$this->learner_id;
        }
        if ($this->course_id !== null) {
            $filter .= " AND {$courseAlias}.course_id = " . (int)$this->course_id;
        }

        return $filter;
    }

    /**
     * Build LEFT JOIN clauses for department, team, and branch tables.
     *
     * @param string $userAlias  Alias for the users table (default 'u')
     * @param bool   $forceAll   If true, always include all 3 joins
     *                           (useful for reports that always display org-unit names)
     * @return string  SQL JOIN fragment
     */
    protected function buildOrgJoins(string $userAlias = 'u', bool $forceAll = false): string
    {
        $joins = "";

        // Always include department join (it was already used in many existing reports)
        $joins .= " LEFT JOIN departments d ON {$userAlias}.department = d.department_id";

        if ($forceAll || $this->team_id !== null) {
            $joins .= " LEFT JOIN teams t ON {$userAlias}.team = t.team_id";
        }
        if ($forceAll || $this->branch_id !== null) {
            $joins .= " LEFT JOIN branches b ON {$userAlias}.branch = b.branch_id";
        }

        return $joins;
    }

    /**
     * Build full org-unit JOINs (always includes all three).
     * Convenience method for reports that always need all org names.
     *
     * @param string $userAlias  Alias for the users table
     * @return string  SQL JOIN fragment with all 3 org tables
     */
    protected function buildAllOrgJoins(string $userAlias = 'u'): string
    {
        return " LEFT JOIN departments d ON {$userAlias}.department = d.department_id"
            . " LEFT JOIN teams t ON {$userAlias}.team = t.team_id"
            . " LEFT JOIN branches b ON {$userAlias}.branch = b.branch_id";
    }

    /**
     * Build SELECT columns for org-unit names.
     * Returns fragment like: ", d.department_name, t.team_name, b.branch_name"
     *
     * @return string  SQL SELECT fragment (with leading comma)
     */
    protected function buildOrgSelectColumns(): string
    {
        return ", d.department_name, t.team_name, b.branch_name";
    }

    /**
     * Check if any org-unit filter is active.
     *
     * @return bool
     */
    protected function hasOrgFilter(): bool
    {
        return $this->department_id !== null
            || $this->team_id !== null
            || $this->branch_id !== null
            || $this->learner_id !== null
            || $this->course_id !== null;
    }

    // ========================================
    // Existing Helper Methods
    // ========================================

    protected function getOffset(): int
    {
        return ($this->page - 1) * $this->per_page;
    }

    protected function buildDateFilter(string $column): string
    {
        $filter = "";
        if ($this->date_from) {
            $filter .= " AND $column >= '{$this->date_from}'";
        }
        if ($this->date_to) {
            $filter .= " AND $column <= '{$this->date_to} 23:59:59'";
        }
        return $filter;
    }

    protected function getTotalCount(string $query, string $types = '', array $params = []): int
    {
        $stmt = $this->conn->prepare($query);
        if ($types && $params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return (int)$stmt->get_result()->fetch_assoc()['total'];
    }

    /**
     * Build metadata for paginated responses.
     * Now includes active org-unit filters in output.
     */
    protected function buildMeta(int $total): array
    {
        $meta = [
            'total' => $total,
            'page' => $this->page,
            'per_page' => $this->per_page,
            'total_pages' => max(1, ceil($total / $this->per_page)),
            'sort_by' => $this->sort_by,
            'sort_dir' => $this->sort_dir,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to
        ];

        // Include active filters so the frontend knows what's applied
        $activeFilters = array_filter([
            'course_id' => $this->course_id,
            'department_id' => $this->department_id,
            'team_id' => $this->team_id,
            'branch_id' => $this->branch_id,
            'learner_id' => $this->learner_id
        ], function ($v) {
            return $v !== null;
        });

        if (!empty($activeFilters)) {
            $meta['applied_filters'] = $activeFilters;
        }

        return $meta;
    }

    protected function formatDuration(int $minutes): string
    {
        if ($minutes < 60) return $minutes . 'm';
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return $hours . 'h ' . ($mins > 0 ? $mins . 'm' : '');
    }

    protected function formatPercentage(float $value): float
    {
        return round($value, 1);
    }

    protected function getStatusBadge(string $status): array
    {
        $badges = [
            'completed' => ['class' => 'success', 'label' => 'Completed'],
            'in_progress' => ['class' => 'primary', 'label' => 'In Progress'],
            'not_started' => ['class' => 'secondary', 'label' => 'Not Started'],
            'overdue' => ['class' => 'danger', 'label' => 'Overdue'],
            'passed' => ['class' => 'success', 'label' => 'Passed'],
            'failed' => ['class' => 'danger', 'label' => 'Failed'],
            'active' => ['class' => 'success', 'label' => 'Active'],
            'inactive' => ['class' => 'secondary', 'label' => 'Inactive'],
            'expiring_soon' => ['class' => 'warning', 'label' => 'Expiring Soon'],
            'expired' => ['class' => 'danger', 'label' => 'Expired'],
            'valid' => ['class' => 'success', 'label' => 'Valid'],
            'pending' => ['class' => 'warning', 'label' => 'Pending'],
            'compliant' => ['class' => 'success', 'label' => 'Compliant'],
            'non_compliant' => ['class' => 'danger', 'label' => 'Non-Compliant']
        ];
        return $badges[$status] ?? ['class' => 'secondary', 'label' => ucfirst($status)];
    }

    // Abstract method - each report must implement
    abstract public function generate(): array;

    // Optional summary for dashboard widgets
    public function getSummary(): array
    {
        return [];
    }

    // Export to CSV format
    public function exportCSV(): string
    {
        $data = $this->generate();
        if (empty($data['data'])) return '';

        $output = fopen('php://temp', 'r+');

        // Headers
        fputcsv($output, array_keys($data['data'][0]));

        // Data rows
        foreach ($data['data'] as $row) {
            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    // ========================================
    // Query Helpers (Phase 6)
    // ========================================

    /** Execute a prepared SELECT and return all rows as assoc array */
    protected function executeQuery(string $sql, string $types = '', array $params = []): array
    {
        $stmt = $this->conn->prepare($sql);
        if ($types && $params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }

    /** Execute a prepared SELECT and return the first row (or null) */
    protected function executeOne(string $sql, string $types = '', array $params = []): ?array
    {
        $stmt = $this->conn->prepare($sql);
        if ($types && $params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row;
    }

    /** Check whether a table exists in the current database */
    protected function tableExists(string $table): bool
    {
        $clean = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $result = $this->conn->query("SHOW TABLES LIKE '{$clean}'");
        return $result && $result->num_rows > 0;
    }

    /** Safe percentage calculation avoiding divide-by-zero */
    protected function safePercent(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 1) : 0;
    }

    /** Format minutes into human-readable string */
    protected function formatMinutes(int $minutes): string
    {
        if ($minutes < 60) return $minutes . 'm';
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
    }
}