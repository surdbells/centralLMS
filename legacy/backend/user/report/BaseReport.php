<?php

/**
 * Base Report Class
 * Common functionality for all LMS reports
 */
abstract class BaseReport {
    protected mysqli $conn;
    protected int $tenant_id;
    protected ?string $date_from = null;
    protected ?string $date_to = null;
    protected int $page = 1;
    protected int $per_page = 25;
    protected string $sort_by = 'created_at';
    protected string $sort_dir = 'DESC';
    protected array $filters = [];

    public function __construct(mysqli $db) {
        $this->conn = $db;
    }

    public function setContext(int $tenant_id): void {
        $this->tenant_id = $tenant_id;
    }

    public function setDateRange(?string $from, ?string $to): void {
        $this->date_from = $from;
        $this->date_to = $to;
    }

    public function setPagination(int $page, int $per_page): void {
        $this->page = max(1, $page);
        $this->per_page = max(1, min(100, $per_page));
    }

    public function setSorting(string $sort_by, string $sort_dir): void {
        $this->sort_by = $sort_by;
        $this->sort_dir = strtoupper($sort_dir) === 'ASC' ? 'ASC' : 'DESC';
    }

    public function setFilters(array $filters): void {
        $this->filters = $filters;
    }

    protected function getOffset(): int {
        return ($this->page - 1) * $this->per_page;
    }

    protected function buildDateFilter(string $column): string {
        $filter = "";
        if ($this->date_from) {
            $filter .= " AND $column >= '{$this->date_from}'";
        }
        if ($this->date_to) {
            $filter .= " AND $column <= '{$this->date_to} 23:59:59'";
        }
        return $filter;
    }

    protected function getTotalCount(string $query, string $types = '', array $params = []): int {
        $stmt = $this->conn->prepare($query);
        if ($types && $params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        return (int) $stmt->get_result()->fetch_assoc()['total'];
    }

    protected function buildMeta(int $total): array {
        return [
            'total' => $total,
            'page' => $this->page,
            'per_page' => $this->per_page,
            'total_pages' => max(1, ceil($total / $this->per_page)),
            'sort_by' => $this->sort_by,
            'sort_dir' => $this->sort_dir,
            'date_from' => $this->date_from,
            'date_to' => $this->date_to
        ];
    }

    protected function formatDuration(int $minutes): string {
        if ($minutes < 60) return $minutes . 'm';
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        return $hours . 'h ' . ($mins > 0 ? $mins . 'm' : '');
    }

    protected function formatPercentage(float $value): float {
        return round($value, 1);
    }

    protected function getStatusBadge(string $status): array {
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
    public function getSummary(): array {
        return [];
    }

    // Export to CSV format
    public function exportCSV(): string {
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
}