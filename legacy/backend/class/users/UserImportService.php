<?php
/**
 * UserImportService — Bulk CSV/Excel import with validation, preview, and execution.
 */
class UserImportService
{
    private mysqli $conn;
    private int $tenant_id;
    private int $actor_id;
    private UserService $userService;

    /** Required columns in the import file */
    private const REQUIRED = ['first_name', 'email'];

    /** All recognized columns with their aliases */
    private const COLUMN_MAP = [
        'first_name' => ['first_name', 'first name', 'firstname', 'given name'],
        'last_name'  => ['last_name', 'last name', 'lastname', 'surname', 'family name'],
        'email'      => ['email', 'email address', 'e-mail'],
        'phone'      => ['phone', 'phone number', 'mobile', 'telephone'],
        'staffId'    => ['staffid', 'staff_id', 'staff id', 'employee_id', 'employee id', 'emp_id'],
        'department' => ['department', 'department_name', 'dept'],
        'team'       => ['team', 'team_name'],
        'branch'     => ['branch', 'branch_name', 'location', 'office'],
        'job_title'  => ['job_title', 'job title', 'title', 'position', 'designation'],
        'manager_email' => ['manager_email', 'manager email', 'reports_to', 'reports to'],
        'hire_date'  => ['hire_date', 'hire date', 'start_date', 'start date', 'date_hired'],
        'role'       => ['role', 'role_key', 'user_role', 'access_level'],
        'language'   => ['language', 'lang', 'locale'],
        'password'   => ['password', 'temp_password', 'initial_password'],
    ];

    public function __construct(mysqli $conn, UserService $userService)
    {
        $this->conn = $conn;
        $this->userService = $userService;
    }

    public function setContext(int $tenant_id, int $actor_id): self
    {
        $this->tenant_id = $tenant_id;
        $this->actor_id = $actor_id;
        return $this;
    }

    /**
     * Parse and validate an uploaded file. Returns preview data.
     * @param string $filePath Path to the uploaded CSV/XLSX file
     * @return array { columns, mapping, preview, stats, errors }
     */
    public function validateImport(string $filePath): array
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $rows = ($ext === 'csv') ? $this->parseCsv($filePath) : $this->parseXlsx($filePath);

        if (empty($rows)) return ['success' => false, 'error' => 'File is empty or unreadable'];

        // Auto-detect column mapping
        $headers = array_keys($rows[0]);
        $mapping = $this->autoMapColumns($headers);

        // Check required columns are mapped
        $missing = [];
        foreach (self::REQUIRED as $req) {
            if (!isset($mapping[$req])) $missing[] = $req;
        }
        if ($missing) {
            return ['success' => false, 'error' => 'Missing required columns: ' . implode(', ', $missing),
                    'headers' => $headers, 'mapping' => $mapping];
        }

        // Preload lookup tables for validation
        $departments = $this->loadLookup("SELECT department_id, department_name FROM departments WHERE tenant_id = ?", 'department_name');
        $teams = $this->loadLookup("SELECT team_id, team_name FROM teams WHERE tenant_id = ?", 'team_name');
        $branches = $this->loadLookup("SELECT branch_id, branch_name FROM branches WHERE tenant_id = ?", 'branch_name');
        $existingEmails = $this->loadEmails();
        $roles = $this->loadLookup("SELECT role_id, role_key FROM roles WHERE tenant_id = ?", 'role_key');

        // Validate each row
        $preview = [];
        $stats = ['total' => count($rows), 'valid' => 0, 'warnings' => 0, 'errors' => 0, 'duplicates' => 0];
        $seenEmails = [];

        foreach ($rows as $i => $row) {
            $mapped = $this->applyMapping($row, $mapping);
            $rowErrors = [];
            $rowWarnings = [];
            $rowStatus = 'valid'; // valid | warning | error

            // Required field checks
            if (empty($mapped['first_name'])) $rowErrors[] = 'First name is required';
            if (empty($mapped['email'])) $rowErrors[] = 'Email is required';
            elseif (!filter_var($mapped['email'], FILTER_VALIDATE_EMAIL)) $rowErrors[] = 'Invalid email format';

            // Duplicate check
            $email = strtolower($mapped['email'] ?? '');
            if ($email && isset($seenEmails[$email])) {
                $rowErrors[] = "Duplicate email in file (row {$seenEmails[$email]})";
            }
            $seenEmails[$email] = $i + 2; // +2 for 1-indexed + header row

            if ($email && isset($existingEmails[$email])) {
                $rowWarnings[] = 'User already exists — will be updated';
                $mapped['_existing_id'] = $existingEmails[$email];
                $stats['duplicates']++;
            }

            // Resolve department
            if (!empty($mapped['department'])) {
                $deptName = strtolower(trim($mapped['department']));
                if (isset($departments[$deptName])) $mapped['_department_id'] = $departments[$deptName];
                else $rowWarnings[] = "Department '{$mapped['department']}' not found";
            }

            // Resolve team
            if (!empty($mapped['team'])) {
                $teamName = strtolower(trim($mapped['team']));
                if (isset($teams[$teamName])) $mapped['_team_id'] = $teams[$teamName];
                else $rowWarnings[] = "Team '{$mapped['team']}' not found";
            }

            // Resolve branch
            if (!empty($mapped['branch'])) {
                $branchName = strtolower(trim($mapped['branch']));
                if (isset($branches[$branchName])) $mapped['_branch_id'] = $branches[$branchName];
                else $rowWarnings[] = "Branch '{$mapped['branch']}' not found";
            }

            // Resolve role
            if (!empty($mapped['role'])) {
                $roleKey = strtolower(trim($mapped['role']));
                if (isset($roles[$roleKey])) $mapped['_role_id'] = $roles[$roleKey];
                else $rowWarnings[] = "Role '{$mapped['role']}' not found";
            }

            // Status
            if (!empty($rowErrors)) { $rowStatus = 'error'; $stats['errors']++; }
            elseif (!empty($rowWarnings)) { $rowStatus = 'warning'; $stats['warnings']++; }
            else { $stats['valid']++; }

            $preview[] = [
                'row_number' => $i + 2,
                'data' => $mapped,
                'status' => $rowStatus,
                'errors' => $rowErrors,
                'warnings' => $rowWarnings
            ];
        }

        return [
            'success' => true,
            'headers' => $headers,
            'mapping' => $mapping,
            'preview' => array_slice($preview, 0, 100), // limit preview to 100 rows
            'stats' => $stats,
            'total_rows' => count($rows),
            '_full_data' => $preview // Store full data for execution (in session or temp file)
        ];
    }

    /**
     * Execute the import. Creates or updates users.
     */
    public function executeImport(array $validatedRows, array $options = []): array
    {
        $skipErrors = $options['skip_errors'] ?? true;
        $updateExisting = $options['update_existing'] ?? true;
        $sendWelcomeEmail = $options['send_welcome_email'] ?? false;

        $results = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        foreach ($validatedRows as $row) {
            if ($row['status'] === 'error') {
                if ($skipErrors) { $results['skipped']++; continue; }
                else { $results['failed']++; continue; }
            }

            $d = $row['data'];

            // Build user data
            $userData = [
                'first_name' => $d['first_name'],
                'last_name'  => $d['last_name'] ?? null,
                'email'      => $d['email'],
                'phone'      => $d['phone'] ?? null,
                'staffId'    => $d['staffId'] ?? null,
                'department_id' => $d['_department_id'] ?? null,
                'team_id'    => $d['_team_id'] ?? null,
                'branch_id'  => $d['_branch_id'] ?? null,
                'job_title'  => $d['job_title'] ?? null,
                'hire_date'  => $d['hire_date'] ?? null,
                'language'   => $d['language'] ?? 'en',
                'is_active'  => 1,
            ];

            if (!empty($d['password'])) $userData['password'] = $d['password'];
            if (!empty($d['_role_id'])) $userData['role_ids'] = [$d['_role_id']];

            // Existing user → update
            if (!empty($d['_existing_id']) && $updateExisting) {
                $res = $this->userService->updateUser((int)$d['_existing_id'], $userData);
                if ($res['success'] ?? false) $results['updated']++;
                else { $results['failed']++; $results['errors'][] = "Row {$row['row_number']}: " . ($res['error'] ?? 'Update failed'); }
                continue;
            }

            // New user → create
            $res = $this->userService->createUser($userData);
            if ($res['success'] ?? false) $results['created']++;
            else { $results['failed']++; $results['errors'][] = "Row {$row['row_number']}: " . ($res['error'] ?? 'Create failed'); }
        }

        return $results;
    }

    /** Generate a downloadable CSV template */
    public function getImportTemplate(): string
    {
        $headers = ['first_name', 'last_name', 'email', 'phone', 'staffId',
                     'department', 'team', 'branch', 'job_title', 'manager_email',
                     'hire_date', 'role', 'language'];
        $sample = ['John', 'Doe', 'john.doe@example.com', '+2341234567', 'EMP001',
                   'Information Technology', 'Backend Team', 'Lagos HQ', 'Software Developer', 'manager@example.com',
                   '2024-01-15', 'learner', 'en'];

        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers);
        fputcsv($output, $sample);
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        return $csv;
    }

    // ═══════════════════════════════════════════
    //  PRIVATE HELPERS
    // ═══════════════════════════════════════════

    private function parseCsv(string $path): array
    {
        $rows = [];
        if (($handle = fopen($path, 'r')) !== false) {
            $headers = fgetcsv($handle);
            if (!$headers) return [];
            $headers = array_map('trim', $headers);
            while (($line = fgetcsv($handle)) !== false) {
                if (count($line) === count($headers)) {
                    $rows[] = array_combine($headers, array_map('trim', $line));
                }
            }
            fclose($handle);
        }
        return $rows;
    }

    private function parseXlsx(string $path): array
    {
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory')) return $this->parseCsv($path); // fallback
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, true, false);

        if (empty($data)) return [];
        $headers = array_map('trim', array_shift($data));
        $rows = [];
        foreach ($data as $line) {
            if (count($line) === count($headers)) {
                $rows[] = array_combine($headers, array_map('trim', $line));
            }
        }
        return $rows;
    }

    private function autoMapColumns(array $headers): array
    {
        $mapping = [];
        foreach ($headers as $header) {
            $lower = strtolower(trim($header));
            foreach (self::COLUMN_MAP as $field => $aliases) {
                if (in_array($lower, $aliases, true)) {
                    $mapping[$field] = $header;
                    break;
                }
            }
        }
        return $mapping;
    }

    private function applyMapping(array $row, array $mapping): array
    {
        $mapped = [];
        foreach ($mapping as $field => $header) {
            $mapped[$field] = $row[$header] ?? null;
        }
        return $mapped;
    }

    private function loadLookup(string $sql, string $nameCol): array
    {
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $this->tenant_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $map = [];
        foreach ($rows as $r) {
            $map[strtolower($r[$nameCol])] = $r[array_keys($r)[0]]; // first col = ID
        }
        return $map;
    }

    private function loadEmails(): array
    {
        $stmt = $this->conn->prepare(
            "SELECT id, LOWER(email) as email FROM users WHERE tenant_id = ? AND deleted_at IS NULL"
        );
        $stmt->bind_param('i', $this->tenant_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $map = [];
        foreach ($rows as $r) $map[$r['email']] = $r['id'];
        return $map;
    }
}
