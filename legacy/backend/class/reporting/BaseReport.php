<?php

/**
 * BaseReport - Abstract base for all LMS report classes
 * Provides common filtering, pagination, sorting, org-unit scoping, and CSV export
 *
 * Multi-Tenant LMS Report Module - Phase 1
 */

abstract class BaseReport
{
    protected mysqli $conn;
    protected int $tenant_id;
    protected int $user_id;

    // Pagination
    protected int $page = 1;
    protected int $per_page = 25;

    // Sorting
    protected string $sort_by = '';
    protected string $sort_dir = 'DESC';

    // Date range
    protected ?string $date_from = null;
    protected ?string $date_to = null;

    // Scope filters
    protected ?int $course_id = null;
    protected ?int $department_id = null;
    protected ?int $team_id = null;
    protected ?int $branch_id = null;
    protected ?int $learner_id = null;

    // Additional filters
    protected array $filters = [];

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function setTenant(int $tenant_id): self
    {
        $this->tenant_id = $tenant_id;
        return $this;
    }

    public function setUser(int $user_id): self
    {
        $this->user_id = $user_id;
        return $this;
    }

    public function setPagination(int $page = 1, int $per_page = 25): self
    {
        $this->page = max(1, $page);
        $this->per_page = min(max(1, $per_page), 500);
        return $this;
    }

    public function setSorting(string $sort_by, string $sort_dir = 'DESC'): self
    {
        $this->sort_by = $sort_by;
        $this->sort_dir = strtoupper($sort_dir) === 'ASC' ? 'ASC' : 'DESC';
        return $this;
    }

    public function setDateRange(?string $date_from, ?string $date_to): self
    {
        $this->date_from = $date_from;
        $this->date_to = $date_to;
        return $this;
    }

    public function setScope(array $scope): self
    {
        $this->course_id = !empty($scope['course_id']) ? (int) $scope['course_id'] : null;
        $this->department_id = !empty($scope['department_id']) ? (int) $scope['department_id'] : null;
        $this->team_id = !empty($scope['team_id']) ? (int) $scope['team_id'] : null;
        $this->branch_id = !empty($scope['branch_id']) ? (int) $scope['branch_id'] : null;
        $this->learner_id = !empty($scope['learner_id']) ? (int) $scope['learner_id'] : null;
        return $this;
    }

    public function setFilters(array $filters): self
    {
        $this->filters = $filters;
        return $this;
    }

    // ==========================================
    // SCOPE HELPERS
    // ==========================================

    /**
     * Build WHERE clauses for org-unit scoping on user table alias
     * @param string $userAlias e.g. 'u'
     * @return array ['where' => string, 'types' => string, 'params' => array]
     */
    protected function buildOrgScopeWhere(string $userAlias = 'u'): array
    {
        $where = '';
        $types = '';
        $params = [];

        if ($this->department_id) {
            $where .= " AND {$userAlias}.department = ?";
            $types .= 'i';
            $params[] = $this->department_id;
        }
        if ($this->team_id) {
            $where .= " AND {$userAlias}.team = ?";
            $types .= 'i';
            $params[] = $this->team_id;
        }
        if ($this->branch_id) {
            $where .= " AND {$userAlias}.branch = ?";
            $types .= 'i';
            $params[] = $this->branch_id;
        }
        if ($this->learner_id) {
            $where .= " AND {$userAlias}.id = ?";
            $types .= 'i';
            $params[] = $this->learner_id;
        }

        return ['where' => $where, 'types' => $types, 'params' => $params];
    }

    /**
     * Build WHERE clause for course scoping on enrollment alias
     * @param string $enrollAlias e.g. 'ce'
     */
    protected function buildCourseScopeWhere(string $enrollAlias = 'ce'): array
    {
        $where = '';
        $types = '';
        $params = [];

        if ($this->course_id) {
            $where .= " AND {$enrollAlias}.course_id = ?";
            $types .= 'i';
            $params[] = $this->course_id;
        }

        return ['where' => $where, 'types' => $types, 'params' => $params];
    }

    /**
     * Build WHERE clause for date range on a given column
     */
    protected function buildDateRangeWhere(string $column): array
    {
        $where = '';
        $types = '';
        $params = [];

        if ($this->date_from) {
            $where .= " AND {$column} >= ?";
            $types .= 's';
            $params[] = $this->date_from . ' 00:00:00';
        }
        if ($this->date_to) {
            $where .= " AND {$column} <= ?";
            $types .= 's';
            $params[] = $this->date_to . ' 23:59:59';
        }

        return ['where' => $where, 'types' => $types, 'params' => $params];
    }

    /**
     * Merge multiple where clause arrays
     */
    protected function mergeWhereClauses(array ...$clauses): array
    {
        $where = '';
        $types = '';
        $params = [];
        foreach ($clauses as $c) {
            $where .= $c['where'];
            $types .= $c['types'];
            $params = array_merge($params, $c['params']);
        }
        return ['where' => $where, 'types' => $types, 'params' => $params];
    }

    // ==========================================
    // PAGINATION HELPERS
    // ==========================================

    protected function getOffset(): int
    {
        return ($this->page - 1) * $this->per_page;
    }

    protected function getLimitClause(): string
    {
        return " LIMIT {$this->per_page} OFFSET {$this->getOffset()}";
    }

    /**
     * Get total count for a query (for pagination meta)
     */
    protected function getCount(string $sql, string $types, array $params): int
    {
        $countSql = "SELECT COUNT(*) as total FROM ({$sql}) as count_query";
        $stmt = $this->conn->prepare($countSql);
        if ($types && $params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $count = (int) $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        return $count;
    }

    /**
     * Build paginated response wrapper
     */
    protected function paginatedResponse(array $data, int $total): array
    {
        return [
            'data' => $data,
            'pagination' => [
                'page' => $this->page,
                'per_page' => $this->per_page,
                'total' => $total,
                'total_pages' => (int) ceil($total / $this->per_page),
                'has_next' => ($this->page * $this->per_page) < $total,
                'has_prev' => $this->page > 1
            ]
        ];
    }

    // ==========================================
    // QUERY EXECUTION
    // ==========================================

    /**
     * Execute a query with params and return all rows
     */
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

    /**
     * Execute a query and return single row
     */
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

    // ==========================================
    // CSV EXPORT
    // ==========================================

    /**
     * Export report data as CSV string
     */
    public function exportCsv(array $data, array $columns): string
    {
        $csv = '';
        // Header row
        $csv .= implode(',', array_map(fn($c) => '"' . str_replace('"', '""', $c['label']) . '"', $columns)) . "\n";
        // Data rows
        foreach ($data as $row) {
            $values = [];
            foreach ($columns as $col) {
                $key = $col['key'];
                $val = $row[$key] ?? '';
                $values[] = '"' . str_replace('"', '""', (string) $val) . '"';
            }
            $csv .= implode(',', $values) . "\n";
        }
        return $csv;
    }

    // ==========================================
    // STANDARD JOINS
    // ==========================================

    protected function userJoin(string $alias = 'u', string $onField = 'user_id', string $srcAlias = 'ce'): string
    {
        return " JOIN users {$alias} ON {$alias}.id = {$srcAlias}.{$onField}";
    }

    protected function orgJoins(string $userAlias = 'u'): string
    {
        return " LEFT JOIN departments d ON d.department_id = {$userAlias}.department"
            . " LEFT JOIN teams t ON t.team_id = {$userAlias}.team"
            . " LEFT JOIN branches b ON b.branch_id = {$userAlias}.branch";
    }

    protected function courseJoin(string $alias = 'c', string $onField = 'course_id', string $srcAlias = 'ce'): string
    {
        return " JOIN courses {$alias} ON {$alias}.course_id = {$srcAlias}.{$onField} AND {$alias}.tenant_id = {$srcAlias}.tenant_id";
    }

    protected function categoryJoin(string $courseAlias = 'c', string $catAlias = 'cat'): string
    {
        return " LEFT JOIN categories {$catAlias} ON {$catAlias}.category_id = {$courseAlias}.category_id";
    }

    // ==========================================
    // UTILITY
    // ==========================================

    protected function formatMinutes(int $minutes): string
    {
        if ($minutes < 60) return $minutes . 'm';
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
    }

    protected function safePercent(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 1) : 0;
    }

    /**
     * Check if a table exists (for optional tables like proc_*)
     */
    protected function tableExists(string $table): bool
    {
        $result = $this->conn->query("SHOW TABLES LIKE '{$table}'");
        return $result && $result->num_rows > 0;
    }
}
