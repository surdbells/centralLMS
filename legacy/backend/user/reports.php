<?php

/**
 * Reports API Endpoint
 * Uses existing Database class pattern with token verification
 */

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../class/reports/ReportService.php';

$db = new Database();
$conn = $db->getConnection();

// Get request parameters
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';



// Get authentication from request
$token = $_GET['token'] ?? $_POST['token'] ?? $_SERVER['HTTP_X_TOKEN'] ?? null;
$user_id = $_GET['user_id'] ?? $_POST['user_id'] ?? $_SERVER['HTTP_X_USER_ID'] ?? null;

// Handle JSON body
$input = [];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!$token && isset($input['token'])) $token = $input['token'];
    if (!$user_id && isset($input['user_id'])) $user_id = $input['user_id'];
    if (!$action && isset($input['action'])) $action = $input['action'];

}

// Verify token
if (!$token || !$user_id) {
    $db->send_response(401, 'error', null, 'Token and user_id required');
    exit;
}

$verification = $db->verify_user($token, $user_id);
if ($verification < 100) {
    $db->send_response(401, 'error', null, 'Invalid token');
    exit;
}

// Get user context from request (these come from the client)
$tenant_id = (int)($_GET['tenant_id'] ?? $_POST['tenant_id'] ?? $input['tenant_id'] ?? 0);
$user_role = $_GET['role'] ?? $_POST['role'] ?? $input['role'] ?? 'superadmin';
$user_id = (int)$user_id;

// Validate tenant_id
if (!$tenant_id) {
    $db->send_response(400, 'error', null, 'tenant_id required');
    exit;
}

// Initialize report service
$reportService = new ReportService($conn);
$reportService->setContext($tenant_id, $user_id);

$service = new ReportService($conn);
$service->setContext($tenant_id, $user_id);

try {
    switch ($action) {

        case 'onboarding-progress-by-cohort':
            $params = getReportParams($input);
            $params['group_by'] = $_GET['group_by'] ?? 'month';
            $params['new_hire_window_days'] = $_GET['window_days'] ?? 90;
            $result = $reportService->runReport('onboarding_progress_by_cohort', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'time-to-competency':
            $params = getReportParams($input);
            $params['new_hire_window_days'] = $_GET['window_days'] ?? 90;
            $result = $reportService->runReport('time_to_competency', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'onboarding-completion-by-org':
            $params = getReportParams($input);
            $params['breakdown'] = $_GET['breakdown'] ?? 'department';
            $result = $reportService->runReport('onboarding_completion_by_org', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'new-hire-training-dashboard':
            $params = getReportParams($input);
            $params['onboarding_status'] = $_GET['status'] ?? 'all';
            $params['new_hire_window_days'] = $_GET['window_days'] ?? 90;
            $result = $reportService->runReport('new_hire_training_dashboard', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'onboarding-drop-off':
            $params = getReportParams($input);
            $params['new_hire_window_days'] = $_GET['window_days'] ?? 90;
            $result = $reportService->runReport('onboarding_drop_off', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'probation-training-compliance':
            $params = getReportParams($input);
            $params['probation_days'] = $_GET['probation_days'] ?? 180;
            $params['new_hire_window_days'] = $_GET['window_days'] ?? 365;
            $result = $reportService->runReport('probation_training_compliance', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'skill-gap-analysis':
            $params = getReportParams($input);
            $params['breakdown'] = $_GET['breakdown'] ?? 'department';
            if (isset($_GET['skill_category'])) $params['skill_category'] = $_GET['skill_category'];
            $result = $reportService->runReport('skill_gap_analysis', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'competency-matrix':
            $params = getReportParams($input);
            if (isset($_GET['role_id'])) $params['role_id'] = (int)$_GET['role_id'];
            $result = $reportService->runReport('competency_matrix', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'skills-coverage-by-role':
            $params = getReportParams($input);
            $result = $reportService->runReport('skills_coverage_by_role', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'competency-development-trend':
            $params = getReportParams($input);
            $params['period'] = $_GET['period'] ?? 'quarter';
            if (isset($_GET['skill_category'])) $params['skill_category'] = $_GET['skill_category'];
            $result = $reportService->runReport('competency_development_trend', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'role-requirements-vs-actual':
            $params = getReportParams($input);
            if (isset($_GET['role_id'])) $params['role_id'] = (int)$_GET['role_id'];
            $result = $reportService->runReport('role_requirements_vs_actual', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'skills-inventory':
            $params = getReportParams($input);
            if (isset($_GET['skill_category'])) $params['skill_category'] = $_GET['skill_category'];
            if (isset($_GET['search'])) $params['search'] = $_GET['search'];
            $result = $reportService->runReport('skills_inventory', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        // ==========================================
        // GET REPORT CATALOG
        // ==========================================
        case 'catalog':
            $category = $_GET['category'] ?? null;
            $audience = determineAudience($user_role);
            $reports = $reportService->getReportCatalog($category, $audience);
            $db->send_response(200, 'success', $reports, 'Report catalog retrieved');
            break;

        // ==========================================
        // GET CATEGORIES
        // ==========================================
        case 'categories':
            $categories = $reportService->getCategories();
            $db->send_response(200, 'success', $categories, 'Categories retrieved');
            break;

        // ==========================================
        // RUN REPORT
        // ==========================================
        case 'run':
            $reportKey = $_GET['report'] ?? $input['report'] ?? null;
            if (!$reportKey) {
                $db->send_response(400, 'error', null, 'Report key required');
                exit;
            }

            // Check access
            if (!canAccessReport($reportKey, $user_role, $reportService)) {
                $db->send_response(403, 'error', null, 'Access denied to this report');
                exit;
            }

            // Gather parameters
            $params = getReportParams($input);
            $result = $reportService->runReport($reportKey, $params);

            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        // ==========================================
        // EXPORT REPORT (CSV/Excel/PDF)
        // ==========================================
        case 'export':
            $reportKey = $_GET['report'] ?? null;
            $format = $_GET['format'] ?? 'csv';

            if (!$reportKey) {
                $db->send_response(400, 'error', null, 'Report key required');
                exit;
            }

            $params = getReportParams($input);
            $result = $reportService->exportReport($reportKey, $params, $format);

            if ($result['success']) {
                // If library wasn't available and we fell back to CSV,
                // use CSV content-type regardless of requested format
                $actualFormat = (!empty($result['fallback'])) ? 'csv' : $format;

                $contentTypes = [
                    'csv'  => 'text/csv',
                    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'pdf'  => 'application/pdf'
                ];

                // Fix the filename extension too
                if (!empty($result['fallback'])) {
                    $result['filename'] = preg_replace('/\.(xlsx|pdf)$/', '.csv', $result['filename']);
                }

                header('Content-Type: ' . ($contentTypes[$actualFormat] ?? 'text/csv'));
                header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
                header('Content-Length: ' . strlen($result['content']));
                echo $result['content'];
                exit;
            } else {
                $db->send_response(400, 'error', null, $result['error'] ?? 'Export failed');
            }
            break;

        // ==========================================
        // DASHBOARD SUMMARY (Admin/HR)
        // ==========================================
        case 'dashboard':
            if (!in_array($user_role, ['admin', 'superadmin', 'hr', 'hr_manager'])) {
                $db->send_response(403, 'error', null, 'Access denied');
                exit;
            }
            $summary = $reportService->getDashboardSummary();
            $db->send_response(200, 'success', $summary, 'Dashboard summary retrieved');
            break;

        // ==========================================
        // LEARNER DASHBOARD (Own data)
        // ==========================================
        case 'my-dashboard':
        case 'learner-dashboard':
            $result = $reportService->runReport('my_learning_summary');
            $db->send_response(200, 'success', $result, 'Learner dashboard retrieved');
            break;

        // ==========================================
        // SCHEDULE REPORT
        // ==========================================
        case 'schedule':
            if ($method !== 'POST') {
                $db->send_response(405, 'error', null, 'POST required');
                exit;
            }

            $reportKey = $input['report'] ?? null;
            $schedule = $input['schedule'] ?? null;

            if (!$reportKey || !$schedule) {
                $db->send_response(400, 'error', null, 'Report and schedule required');
                exit;
            }

            if (!canAccessReport($reportKey, $user_role, $reportService)) {
                $db->send_response(403, 'error', null, 'Access denied');
                exit;
            }

            $result = scheduleReport($conn, $user_id, $tenant_id, $reportKey, $schedule, $input['params'] ?? []);
            $db->send_response(200, 'success', $result, 'Report scheduled');
            break;

        // ==========================================
        // GET SAVED REPORTS
        // ==========================================
        case 'saved':
            $saved = getSavedReports($conn, $user_id, $tenant_id);
            $db->send_response(200, 'success', $saved, 'Saved reports retrieved');
            break;

        // ==========================================
        // SAVE REPORT CONFIGURATION
        // ==========================================
        case 'save':
            if ($method !== 'POST') {
                $db->send_response(405, 'error', null, 'POST required');
                exit;
            }

            $reportKey = $input['report_key'] ?? null;
            $name = $input['name'] ?? null;

            if (!$reportKey || !$name) {
                $db->send_response(400, 'error', null, 'report_key and name required');
                exit;
            }

            $result = saveReportConfig($conn, $user_id, $tenant_id, $input);

            if ($result['success']) {
                $db->send_response(200, 'success', ['id' => $result['id']], 'Report saved');
            } else {
                $db->send_response(500, 'error', null, $result['message']);
            }
            break;

        // ==========================================
        // DELETE SAVED REPORT
        // ==========================================
        case 'delete':
            if ($method !== 'POST' && $method !== 'DELETE') {
                $db->send_response(405, 'error', null, 'POST or DELETE required');
                exit;
            }

            $saved_id = (int)($_GET['id'] ?? $input['id'] ?? 0);
            if (!$saved_id) {
                $db->send_response(400, 'error', null, 'Saved report ID required');
                exit;
            }

            $result = deleteSavedReport($conn, $saved_id, $user_id, $tenant_id);

            if ($result) {
                $db->send_response(200, 'success', null, 'Report deleted');
            } else {
                $db->send_response(404, 'error', null, 'Report not found or access denied');
            }
            break;

        // ==========================================
        // UPDATE SCHEDULE
        // ==========================================
        case 'update-schedule':
            if ($method !== 'POST') {
                $db->send_response(405, 'error', null, 'POST required');
                exit;
            }

            $saved_id = (int)($input['id'] ?? 0);
            $schedule = $input['schedule'] ?? null;

            if (!$saved_id || !$schedule) {
                $db->send_response(400, 'error', null, 'ID and schedule required');
                exit;
            }

            $result = updateReportSchedule($conn, $saved_id, $user_id, $tenant_id, $schedule);

            if ($result) {
                $db->send_response(200, 'success', null, 'Schedule updated');
            } else {
                $db->send_response(404, 'error', null, 'Report not found or access denied');
            }
            break;

        // ==========================================
        // ORG-UNIT DATA (for filter dropdowns)
        // ==========================================
        /*case 'org-units':
            $orgData = getOrgUnits($conn, $tenant_id);
            $db->send_response(200, 'success', $orgData, 'Org units retrieved');
            break;*/

        // ==========================================
        // COURSES LIST (for filter dropdowns)
        // ==========================================
        /*        case 'courses-list':
                    $coursesList = getCoursesList($conn, $tenant_id);
                    $db->send_response(200, 'success', $coursesList, 'Courses list retrieved');
                    break;*/

        // ==========================================
        // LEARNERS LIST (for filter dropdowns)
        // ==========================================
        case 'learners-list':
            $search = $_GET['search'] ?? '';
            $deptFilter = isset($_GET['department_id']) ? (int)$_GET['department_id'] : null;
            $teamFilter = isset($_GET['team_id']) ? (int)$_GET['team_id'] : null;
            $branchFilter = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : null;
            $learnersList = getLearnersList($conn, $tenant_id, $search, $deptFilter, $teamFilter, $branchFilter);
            $db->send_response(200, 'success', $learnersList, 'Learners list retrieved');
         break;
        // =========================================================================
        // Phase 7: Custom Report Builder Endpoints
        // =========================================================================

        case 'custom-sources':
            // List available data sources
            echo json_encode(['success' => true, 'sources' => $service->getCustomSources()]);
            break;

        case 'custom-columns':
            // List columns for a given data source
            $source = $_GET['source'] ?? '';
            echo json_encode([
                'success' => true,
                'source'  => $source,
                'columns' => $service->getCustomColumns($source),
                'filters' => $service->getCustomFilters($source),
                'groupings' => $service->getCustomGroupings($source),
            ]);
            break;

        case 'custom-build':
            // Build and execute a custom report (POST)
            $body = json_decode(file_get_contents('php://input'), true);
            if (!$body || !isset($body['source'])) {
                echo json_encode(['error' => 'Missing source in request body']);
                break;
            }
            $result = $service->buildCustomReport($body);
            echo json_encode(array_merge(['success' => !isset($result['error'])], $result));
            break;

        case 'custom-save':
            // Save a custom report configuration (POST)
            $body = json_decode(file_get_contents('php://input'), true);
            if (!$body || !isset($body['name'], $body['config'])) {
                echo json_encode(['error' => 'Missing name or config in request body']);
                break;
            }
            $result = $service->saveCustomReport(
                $body['name'],
                $body['description'] ?? '',
                $body['config']
            );
            echo json_encode($result);
            break;

        case 'custom-update':
            // Update a saved custom report (POST)
            $body = json_decode(file_get_contents('php://input'), true);
            if (!$body || !isset($body['report_id'], $body['name'], $body['config'])) {
                echo json_encode(['error' => 'Missing report_id, name, or config']);
                break;
            }
            $result = $service->updateCustomReport(
                (int)$body['report_id'],
                $body['name'],
                $body['description'] ?? '',
                $body['config']
            );
            echo json_encode($result);
            break;

        case 'custom-delete':
            // Soft-delete a saved custom report
            $reportId = (int)($_GET['id'] ?? $_POST['report_id'] ?? 0);
            if ($reportId <= 0) {
                echo json_encode(['error' => 'Missing report_id']);
                break;
            }
            echo json_encode($service->deleteCustomReport($reportId));
            break;

        case 'custom-list':
            // List saved custom reports (optional: ?user_id=X for personal only)
            $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
            echo json_encode(['success' => true, 'reports' => $service->listSavedReports($userId)]);
            break;

        case 'custom-run':
            // Run a saved custom report by ID (with optional overrides)
            $reportId = (int)($_GET['id'] ?? 0);
            if ($reportId <= 0) {
                echo json_encode(['error' => 'Missing report id']);
                break;
            }
            $overrides = [];
            if (isset($_GET['page'])) $overrides['page'] = (int)$_GET['page'];
            if (isset($_GET['per_page'])) $overrides['per_page'] = (int)$_GET['per_page'];
            if (isset($_GET['date_from'])) $overrides['date_from'] = $_GET['date_from'];
            if (isset($_GET['date_to'])) $overrides['date_to'] = $_GET['date_to'];
            echo json_encode($service->runSavedReport($reportId, $overrides));
            break;

        case 'custom-duplicate':
            // Duplicate a saved custom report
            $reportId = (int)($_GET['id'] ?? 0);
            if ($reportId <= 0) {
                echo json_encode(['error' => 'Missing report id']);
                break;
            }
            echo json_encode($service->duplicateCustomReport($reportId));
            break;

        case 'custom-export':
            // Export a saved or ad-hoc custom report (CSV/XLSX/PDF)
            $format = $_GET['format'] ?? 'csv';
            $reportId = (int)($_GET['id'] ?? 0);

            if ($reportId > 0) {
                // Run saved report first, then export
                $result = $service->runSavedReport($reportId);
            } else {
                // Ad-hoc build from POST body
                $body = json_decode(file_get_contents('php://input'), true);
                $result = $body ? $service->buildCustomReport($body) : ['error' => 'No config provided'];
            }

            if (isset($result['error'])) {
                echo json_encode($result);
                break;
            }

            // Use existing export infrastructure
            // The custom report returns data in the same format as regular reports
            $exportResult = exportCustomReportData($result, $format);
            if ($exportResult['type'] === 'download') {
                header('Content-Type: ' . $exportResult['content_type']);
                header('Content-Disposition: attachment; filename="' . $exportResult['filename'] . '"');
                echo $exportResult['content'];
            } else {
                echo json_encode($exportResult);
            }
            break;
        case 'dropdown-meta':
            // GET /api/reports?action=dropdown-meta
            // Returns: list of all available dropdown endpoints with descriptions
            $meta = $reportService->getDropdownMeta();
            echo json_encode([
                'success'   => true,
                'dropdowns' => $meta,
                'count'     => count($meta),
            ]);
            break;


        case 'org-units':
            // GET /api/reports?action=org-units
            // GET /api/reports?action=org-units&department_id=5        (teams in dept 5)
            // GET /api/reports?action=org-units&branch_id=3            (depts in branch 3)
            // GET /api/reports?action=org-units&state=Lagos            (branches in Lagos)
            $params = collectDropdownParams();
            $data = $reportService->getOrgUnits($params);
            echo json_encode([
                'success' => true,
                'data'    => $data,
            ]);
            break;


        case 'courses-list':
            // GET /api/reports?action=courses-list
            // GET /api/reports?action=courses-list&search=AML
            // GET /api/reports?action=courses-list&category_id=2&page=1&per_page=50
            // GET /api/reports?action=courses-list&is_mandatory=1
            $params = collectDropdownParams();
            $result = $reportService->getCoursesList($params);
            echo json_encode([
                'success'  => true,
                'data'     => $result['data'],
                'total'    => $result['total'],
                'page'     => $result['page'],
                'per_page' => $result['per_page'],
                'pages'    => $result['pages'],
            ]);
            break;


        /*case 'learners-list':
            // GET /api/reports?action=learners-list
            // GET /api/reports?action=learners-list&search=john
            // GET /api/reports?action=learners-list&department_id=5&team_id=3
            // GET /api/reports?action=learners-list&branch_id=2&page=2&per_page=50
            $params = collectDropdownParams();
            $result = $reportService->getLearnersList($params);
            echo json_encode([
                'success'  => true,
                'data'     => $result['data'],
                'total'    => $result['total'],
                'page'     => $result['page'],
                'per_page' => $result['per_page'],
                'pages'    => $result['pages'],
            ]);
            break;*/


        case 'managers-list':
            // GET /api/reports?action=managers-list
            // GET /api/reports?action=managers-list&search=smith
            // GET /api/reports?action=managers-list&department_id=5
            $params = collectDropdownParams();
            $data = $reportService->getManagersList($params);
            echo json_encode([
                'success' => true,
                'data'    => $data,
                'count'   => count($data),
            ]);
            break;


        case 'assessments-list':
            // GET /api/reports?action=assessments-list
            // GET /api/reports?action=assessments-list&search=KYC
            // GET /api/reports?action=assessments-list&assessment_type=exam
            // GET /api/reports?action=assessments-list&course_id=12
            $params = collectDropdownParams();
            $data = $reportService->getAssessmentsList($params);
            echo json_encode([
                'success' => true,
                'data'    => $data,
                'count'   => count($data),
            ]);
            break;


        case 'paths-list':
            // GET /api/reports?action=paths-list
            // GET /api/reports?action=paths-list&search=onboarding
            $params = collectDropdownParams();
            $data = $reportService->getLearningPathsList($params);
            echo json_encode([
                'success' => true,
                'data'    => $data,
                'count'   => count($data),
            ]);
            break;


        case 'sessions-list':
            // GET /api/reports?action=sessions-list
            // GET /api/reports?action=sessions-list&search=fire+safety
            // GET /api/reports?action=sessions-list&instructor_id=8&upcoming_only=1
            // GET /api/reports?action=sessions-list&venue_id=3
            $params = collectDropdownParams();
            $data = $reportService->getSessionsList($params);
            echo json_encode([
                'success' => true,
                'data'    => $data,
                'count'   => count($data),
            ]);
            break;


        case 'instructors-list':
            // GET /api/reports?action=instructors-list
            // GET /api/reports?action=instructors-list&search=ade
            $params = collectDropdownParams();
            $data = $reportService->getInstructorsList($params);
            echo json_encode([
                'success' => true,
                'data'    => $data,
                'count'   => count($data),
            ]);
            break;


        case 'venues-list':
            // GET /api/reports?action=venues-list
            // GET /api/reports?action=venues-list&search=conference
            $params = collectDropdownParams();
            $data = $reportService->getVenuesList($params);
            echo json_encode([
                'success' => true,
                'data'    => $data,
                'count'   => count($data),
            ]);
            break;


        case 'categories-list':
            // GET /api/reports?action=categories-list
            $data = $reportService->getCategoriesList();
            echo json_encode([
                'success' => true,
                'data'    => $data,
                'count'   => count($data),
            ]);
            break;


        case 'branch-states':
            // GET /api/reports?action=branch-states
            $data = $reportService->getBranchStates();
            echo json_encode([
                'success' => true,
                'data'    => $data,
                'count'   => count($data),
            ]);
            break;


        case 'all-filters':
            // GET /api/reports?action=all-filters
            // Returns a combined payload for the report filter panel.
            // This loads org-units, categories, and branch states in one call.
            // Learners/courses/assessments use their own paginated endpoints.
            $orgUnits   = $reportService->getOrgUnits();
            $categories = $reportService->getCategoriesList();
            $states     = $reportService->getBranchStates();
            $managers   = $reportService->getManagersList();

            echo json_encode([
                'success'     => true,
                'departments' => $orgUnits['departments'],
                'teams'       => $orgUnits['teams'],
                'branches'    => $orgUnits['branches'],
                'categories'  => $categories,
                'states'      => $states,
                'managers'    => $managers,
                'note'        => 'Use courses-list, learners-list, assessments-list, paths-list, sessions-list for searchable/paginated dropdowns.',
            ]);
            break;
        // ═══════ Phase 11: Survey & Feedback (6 reports) ═══════

        case 'learner-satisfaction-nps':
            $params = getReportParams($input);
            $result = $reportService->runReport('learner_satisfaction_nps', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'course-feedback-summary':
            $params = getReportParams($input);
            $result = $reportService->runReport('course_feedback_summary', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'instructor-evaluation':
            $params = getReportParams($input);
            $result = $reportService->runReport('instructor_evaluation', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'training-effectiveness-survey':
            $params = getReportParams($input);
            $result = $reportService->runReport('training_effectiveness_survey', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'feedback-trends':
            $params = getReportParams($input);
            $params['period'] = $_GET['period'] ?? 'month';
            $result = $reportService->runReport('feedback_trends', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'learner-sentiment':
            $params = getReportParams($input);
            $result = $reportService->runReport('learner_sentiment', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        // ═══════ Phase 12: Knowledge Retention & Impact (9 reports) ═══════

        case 'pre-post-comparison':
            $params = getReportParams($input);
            $result = $reportService->runReport('pre_post_comparison', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'knowledge-retention':
            $params = getReportParams($input);
            $result = $reportService->runReport('knowledge_retention', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'knowledge-decay':
            $params = getReportParams($input);
            $result = $reportService->runReport('knowledge_decay', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'repeat-assessment-trend':
            $params = getReportParams($input);
            $result = $reportService->runReport('repeat_assessment_trend', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'kirkpatrick-summary':
            $params = getReportParams($input);
            $result = $reportService->runReport('kirkpatrick_summary', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'training-impact':
            $params = getReportParams($input);
            $result = $reportService->runReport('training_impact', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'training-effectiveness-index':
            $params = getReportParams($input);
            $result = $reportService->runReport('training_effectiveness_index', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'time-to-proficiency':
            $params = getReportParams($input);
            $result = $reportService->runReport('time_to_proficiency', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'post-training-performance':
            $params = getReportParams($input);
            $result = $reportService->runReport('post_training_performance', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        // ═══════ Phase 13: Manager + Cohort + Cost (11 reports) ═══════

        case 'manager-team-dashboard':
            $params = getReportParams($input);
            if (isset($_GET['manager_id'])) $params['manager_id'] = (int)$_GET['manager_id'];
            $result = $reportService->runReport('manager_team_dashboard', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'manager-compliance-summary':
            $params = getReportParams($input);
            if (isset($_GET['manager_id'])) $params['manager_id'] = (int)$_GET['manager_id'];
            $result = $reportService->runReport('manager_compliance_summary', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'manager-action-required':
            $params = getReportParams($input);
            if (isset($_GET['manager_id'])) $params['manager_id'] = (int)$_GET['manager_id'];
            $result = $reportService->runReport('manager_action_required', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'team-vs-org-benchmark':
            $params = getReportParams($input);
            if (isset($_GET['manager_id'])) $params['manager_id'] = (int)$_GET['manager_id'];
            $result = $reportService->runReport('team_vs_org_benchmark', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'cohort-progress':
            $params = getReportParams($input);
            $result = $reportService->runReport('cohort_progress', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'group-performance':
            $params = getReportParams($input);
            $params['breakdown'] = $_GET['breakdown'] ?? 'department';
            $result = $reportService->runReport('group_performance', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'peer-benchmarking':
            $params = getReportParams($input);
            $result = $reportService->runReport('peer_benchmarking', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'training-cost-per-employee':
            $params = getReportParams($input);
            $result = $reportService->runReport('training_cost_per_employee', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'cost-per-completion':
            $params = getReportParams($input);
            $result = $reportService->runReport('cost_per_completion', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'department-training-spend':
            $params = getReportParams($input);
            $result = $reportService->runReport('department_training_spend', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'cost-efficiency':
            $params = getReportParams($input);
            $result = $reportService->runReport('cost_efficiency', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        // ═══════ Phase 14: Scheduled Reports (5 endpoints) ═══════

        case 'schedule-report':
            if ($method !== 'POST') { $db->send_response(405, 'error', null, 'POST required'); exit; }
            $scheduler = new ReportScheduler($conn, $tenant_id);
            $input['user_id'] = $user_id;
            $result = $scheduler->scheduleReport($input);
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', null, $result);
            break;

        case 'scheduled-reports':
            $scheduler = new ReportScheduler($conn, $tenant_id);
            $forUser = isset($_GET['all']) && in_array($user_role, ['admin','superadmin']) ? 0 : $user_id;
            $schedules = $scheduler->getScheduledReports((int)$forUser);
            $db->send_response(200, 'success', $schedules, 'Scheduled reports retrieved');
            break;

        case 'delete-schedule':
            if ($method !== 'POST' && $method !== 'DELETE') { $db->send_response(405, 'error', null, 'POST/DELETE required'); exit; }
            $scheduleId = (int)($input['id'] ?? $_GET['id'] ?? 0);
            $scheduler = new ReportScheduler($conn, $tenant_id);
            $deleted = $scheduler->deleteSchedule($scheduleId, (int)$user_id);
            $db->send_response($deleted ? 200 : 404, $deleted ? 'success' : 'error', null,
                $deleted ? 'Schedule deleted' : 'Not found or access denied');
            break;

        case 'compliance-alerts':
            if (!in_array($user_role, ['admin','superadmin','hr','hr_manager'])) {
                $db->send_response(403, 'error', null, 'Access denied'); exit;
            }
            $scheduler = new ReportScheduler($conn, $tenant_id);
            $result = $scheduler->complianceAlertNotifications();
            $db->send_response(200, 'success', $result, 'Compliance alerts sent');
            break;

        case 'executive-digest':
            if (!in_array($user_role, ['admin','superadmin','executive'])) {
                $db->send_response(403, 'error', null, 'Access denied'); exit;
            }
            $period = $_GET['period'] ?? 'weekly';
            $scheduler = new ReportScheduler($conn, $tenant_id);
            $result = $scheduler->executiveDigest($period);
            $db->send_response(200, 'success', $result, 'Executive digest sent');
            break;

        // ═══════ Phase 15: Content & Resource Analytics (3 reports) ═══════

        case 'resource-utilization':
            $params = getReportParams($input);
            $result = $reportService->runReport('resource_utilization', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'content-freshness':
            $params = getReportParams($input);
            if (isset($_GET['months'])) $params['months'] = (int)$_GET['months'];
            $result = $reportService->runReport('content_freshness', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;

        case 'media-type-engagement':
            $params = getReportParams($input);
            $result = $reportService->runReport('media_type_engagement', $params);
            $db->send_response(200, 'success', $result, 'Report generated');
            break;


        default:
            $db->send_response(400, 'error', null, 'Invalid action. Use: catalog, categories, run, export, dashboard, my-dashboard, schedule, saved, save, delete, update-schedule, org-units, courses-list, learners-list');
    }
} catch (Exception $e) {
    error_log('Report API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, 'Server error: ' . $e->getMessage());
}


/**
 * Helper Functions
 */

function determineAudience(string $role): string {
    return match($role) {
        'superadmin', 'admin' => 'admin',
        'hr', 'hr_manager' => 'hr',
        'manager' => 'manager',
        'executive' => 'executive',
        default => 'learner'
    };
}

function canAccessReport(string $reportKey, string $role, ReportService $service): bool {
    $catalog = $service->getReportCatalog();

    foreach ($catalog as $report) {
        if ($report['report_key'] === $reportKey) {
            // Admin can access all
            if ($role === 'admin' || $role === 'superadmin') {
                return true;
            }

            // Learner reports for everyone
            if ($report['category'] === 'learner') {
                return true;
            }

            // Check if role's audience is allowed
            $audience = determineAudience($role);
            return in_array($audience, $report['audience']);
        }
    }

    return false;
}

function getReportParams(array $input = []): array {
    $params = [];

    // Date filters
    if (isset($_GET['date_from'])) $params['date_from'] = $_GET['date_from'];
    if (isset($_GET['date_to'])) $params['date_to'] = $_GET['date_to'];

    // Pagination
    if (isset($_GET['page'])) $params['page'] = (int)$_GET['page'];
    if (isset($_GET['per_page'])) $params['per_page'] = (int)$_GET['per_page'];

    // Sorting
    if (isset($_GET['sort_by'])) $params['sort_by'] = $_GET['sort_by'];
    if (isset($_GET['sort_dir'])) $params['sort_dir'] = $_GET['sort_dir'];

    // Report-specific params
    $specificParams = [
        // Phase 1: Core params
        'user_id', 'course_id', 'assessment_id', 'manager_id',
        'department', 'department_id', 'team_id', 'branch_id', 'learner_id',
        'days', 'days_ahead', 'months', 'month',
        'period', 'status', 'category', 'limit', 'group_by',
        'format', 'path_id', 'session_id', 'instructor_id', 'venue_id',
        // Phase 7: Custom Report Builder
        'data_source', 'report_name',
        // Phase 9: Onboarding
        'window_days', 'probation_days', 'onboarding_status', 'breakdown',
        // Phase 10: Skills
        'role_id', 'skill_category', 'search'
    ];

    foreach ($specificParams as $param) {
        if (isset($_GET[$param])) {
            $params[$param] = is_numeric($_GET[$param]) ? (int)$_GET[$param] : $_GET[$param];
        }
    }

    // Merge POST body params (lower priority than GET)
    if (!empty($input)) {
        foreach ($input as $key => $value) {
            if (!isset($params[$key]) && !in_array($key, ['token', 'user_id', 'tenant_id', 'role', 'report', 'report_key', 'name', 'schedule'])) {
                $params[$key] = $value;
            }
        }
    }

    return $params;
}

function getSavedReports(mysqli $conn, int $user_id, int $tenant_id): array {
    $query = "SELECT id, report_key, name, params, schedule, created_at, updated_at 
              FROM saved_reports 
              WHERE user_id = ? AND tenant_id = ? AND is_active = 1 
              ORDER BY created_at DESC";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $user_id, $tenant_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $reports = [];
    while ($row = $result->fetch_assoc()) {
        $row['params'] = json_decode($row['params'], true) ?? [];
        $row['schedule'] = json_decode($row['schedule'], true);
        $reports[] = $row;
    }

    $stmt->close();
    return $reports;
}

function saveReportConfig(mysqli $conn, int $user_id, int $tenant_id, array $data): array {
    $query = "INSERT INTO saved_reports (user_id, tenant_id, report_key, name, params, schedule, is_active, created_at)
              VALUES (?, ?, ?, ?, ?, ?, 1, NOW())";

    $stmt = $conn->prepare($query);

    $report_key = $data['report_key'];
    $name = $data['name'];
    $params = json_encode($data['params'] ?? []);
    $schedule = json_encode($data['schedule'] ?? null);

    $stmt->bind_param("iissss", $user_id, $tenant_id, $report_key, $name, $params, $schedule);

    if ($stmt->execute()) {
        $id = $conn->insert_id;
        $stmt->close();
        return ['success' => true, 'id' => $id];
    }

    $stmt->close();
    return ['success' => false, 'message' => 'Failed to save report'];
}

function deleteSavedReport(mysqli $conn, int $saved_id, int $user_id, int $tenant_id): bool {
    // Soft delete - only own reports
    $query = "UPDATE saved_reports SET is_active = 0 WHERE id = ? AND user_id = ? AND tenant_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iii", $saved_id, $user_id, $tenant_id);
    $stmt->execute();
    $affected = $stmt->affected_rows > 0;
    $stmt->close();
    return $affected;
}

function scheduleReport(mysqli $conn, int $user_id, int $tenant_id, string $reportKey, array $schedule, array $params = []): array {
    // Check if already exists
    $query = "SELECT id FROM saved_reports WHERE user_id = ? AND tenant_id = ? AND report_key = ? AND is_active = 1";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iis", $user_id, $tenant_id, $reportKey);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        // Update existing
        return updateReportSchedule($conn, $existing['id'], $user_id, $tenant_id, $schedule)
            ? ['id' => $existing['id'], 'updated' => true]
            : ['error' => 'Failed to update schedule'];
    }

    // Create new
    $result = saveReportConfig($conn, $user_id, $tenant_id, [
        'report_key' => $reportKey,
        'name' => 'Scheduled: ' . $reportKey,
        'params' => $params,
        'schedule' => $schedule
    ]);

    return $result['success'] ? ['id' => $result['id'], 'created' => true] : ['error' => 'Failed to create schedule'];
}

function updateReportSchedule(mysqli $conn, int $saved_id, int $user_id, int $tenant_id, array $schedule): bool {
    $query = "UPDATE saved_reports SET schedule = ?, updated_at = NOW() WHERE id = ? AND user_id = ? AND tenant_id = ?";
    $stmt = $conn->prepare($query);
    $scheduleJson = json_encode($schedule);
    $stmt->bind_param("siii", $scheduleJson, $saved_id, $user_id, $tenant_id);
    $stmt->execute();
    $affected = $stmt->affected_rows > 0;
    $stmt->close();
    return $affected;
}
// ============================================================
// ORG-UNIT DATA HELPERS (Phase 1)
// ============================================================

/**
 * Get all org-unit options for filter dropdowns.
 * Returns departments, teams, and branches for the tenant.
 */
function getOrgUnits(mysqli $conn, int $tenant_id): array
{
    $result = [
        'departments' => [],
        'teams' => [],
        'branches' => []
    ];

    // Departments
    $stmt = $conn->prepare("SELECT department_id, department_name, department_code FROM departments WHERE tenant_id = ? AND is_active = 1 ORDER BY department_name");
    $stmt->bind_param('i', $tenant_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $result['departments'][] = [
            'id' => (int)$row['department_id'],
            'name' => $row['department_name'],
            'code' => $row['department_code']
        ];
    }
    $stmt->close();

    // Teams
    $stmt = $conn->prepare("SELECT team_id, team_name, team_code FROM teams WHERE tenant_id = ? AND is_active = 1 ORDER BY team_name");
    $stmt->bind_param('i', $tenant_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $result['teams'][] = [
            'id' => (int)$row['team_id'],
            'name' => $row['team_name'],
            'code' => $row['team_code']
        ];
    }
    $stmt->close();

    // Branches
    $stmt = $conn->prepare("SELECT branch_id, branch_name, branch_code, city, state FROM branches WHERE tenant_id = ? AND is_active = 1 ORDER BY branch_name");
    $stmt->bind_param('i', $tenant_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $result['branches'][] = [
            'id' => (int)$row['branch_id'],
            'name' => $row['branch_name'],
            'code' => $row['branch_code'],
            'location' => trim(($row['city'] ?? '') . ', ' . ($row['state'] ?? ''), ', ')
        ];
    }
    $stmt->close();

    return $result;
}

/**
 * Get courses list for filter dropdown.
 */
function getCoursesList(mysqli $conn, int $tenant_id): array
{
    $courses = [];
    $stmt = $conn->prepare("SELECT course_id, title, course_type FROM courses WHERE tenant_id = ? AND is_active = 1 ORDER BY title");
    $stmt->bind_param('i', $tenant_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $courses[] = [
            'id' => (int)$row['course_id'],
            'title' => $row['title'],
            'type' => $row['course_type']
        ];
    }
    $stmt->close();
    return $courses;
}

/**
 * Get learners list for filter dropdown (with optional search and org-unit pre-filter).
 * Returns max 50 results to keep dropdown fast.
 */
function getLearnersList(mysqli $conn, int $tenant_id, string $search = '', ?int $dept = null, ?int $team = null, ?int $branch = null): array
{
    $learners = [];
    $where = "u.tenant_id = ? AND u.is_active = 1 AND u.is_learner = 1";
    $types = 'i';
    $bindValues = [$tenant_id];

    if ($search !== '') {
        $where .= " AND (CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.email LIKE ? OR u.staffId LIKE ?)";
        $types .= 'sss';
        $searchTerm = "%{$search}%";
        $bindValues[] = $searchTerm;
        $bindValues[] = $searchTerm;
        $bindValues[] = $searchTerm;
    }
    if ($dept !== null) {
        $where .= " AND u.department = ?";
        $types .= 'i';
        $bindValues[] = $dept;
    }
    if ($team !== null) {
        $where .= " AND u.team = ?";
        $types .= 'i';
        $bindValues[] = $team;
    }
    if ($branch !== null) {
        $where .= " AND u.branch = ?";
        $types .= 'i';
        $bindValues[] = $branch;
    }

    $query = "SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) AS full_name, u.email, u.staffId
              FROM users u
              WHERE {$where}
              ORDER BY u.first_name, u.last_name
              LIMIT 50";

    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$bindValues);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $learners[] = [
            'id' => (int)$row['id'],
            'name' => $row['full_name'],
            'email' => $row['email'],
            'staff_id' => $row['staffId']
        ];
    }
    $stmt->close();
    return $learners;
}

/**
 * Export custom report result to CSV/XLSX/PDF.
 * Add this function outside the switch block.
 */
function exportCustomReportData(array $result, string $format): array
{
    $data    = $result['data'] ?? [];
    $columns = $result['columns'] ?? [];
    $meta    = $result['meta'] ?? [];

    if (empty($data)) {
        return ['error' => 'No data to export'];
    }

    // Column headers
    $headers = array_map(fn($c) => $c['label'] ?? $c['key'], $columns);
    $keys    = array_map(fn($c) => $c['key'], $columns);

    if ($format === 'csv') {
        ob_start();
        $fp = fopen('php://output', 'w');
        fputcsv($fp, $headers);
        foreach ($data as $row) {
            $line = [];
            foreach ($keys as $key) {
                $line[] = $row[$key] ?? '';
            }
            fputcsv($fp, $line);
        }
        fclose($fp);
        $content = ob_get_clean();

        $source = $meta['source'] ?? 'custom';
        return [
            'type'         => 'download',
            'content_type' => 'text/csv',
            'filename'     => "custom_report_{$source}_" . date('Y-m-d') . '.csv',
            'content'      => $content,
        ];
    }

    // For XLSX/PDF, fall back to CSV if libraries not available
    // (or integrate with Phase 3c's exportReport infrastructure)
    return [
        'type'         => 'download',
        'content_type' => 'text/csv',
        'filename'     => "custom_report_" . date('Y-m-d') . '.csv',
        'content'      => '',  // Generate CSV as fallback
    ];
}

// ─── Dropdown helper: collect common filter params from GET ───
function collectDropdownParams(): array
{
    $params = [];
    $keys = [
        'search', 'department_id', 'team_id', 'branch_id', 'state',
        'category_id', 'course_id', 'assessment_type', 'is_mandatory',
        'instructor_id', 'venue_id', 'upcoming_only', 'role',
        'page', 'per_page'
    ];
    foreach ($keys as $k) {
        if (isset($_GET[$k]) && $_GET[$k] !== '') {
            $params[$k] = $_GET[$k];
        }
    }
    return $params;
}
