<?php

/**
 * Course Management API Endpoint
 * Handles course versions and waitlist functionality
 * Uses existing Database class pattern
 */

require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../class/CourseVersion.php';
require_once __DIR__ . '/../../class/CourseWaitlist.php';

$db = new Database();
$conn = $db->getConnection();

// Authenticate
$auth = authenticate();
if (!$auth['success']) {
    $db->send_response(401, 'error', null, $auth['message']);
    exit;
}

$user = $auth['user'];
$tenant_id = $user['tenant_id'];
$user_id = $user['id'];
$user_role = $user['role'];

// Get request parameters
$resource = $_GET['resource'] ?? $_POST['resource'] ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = getJsonInput();

try {
    switch ($resource) {
        // ==========================================
        // COURSE VERSIONS
        // ==========================================
        case 'versions':
            $versionManager = new CourseVersion($conn);
            $versionManager->setTenant($tenant_id);

            switch ($action) {
                case 'list':
                    $course_id = (int) $_GET['course_id'];
                    if (!$course_id) {
                        $db->send_response(400, 'error', null, 'course_id required');
                        exit;
                    }
                    $versions = $versionManager->getCourseVersions($course_id);
                    $db->send_response(200, 'success', $versions, 'Versions retrieved');
                    break;

                case 'get':
                    $version_id = (int) $_GET['version_id'];
                    if (!$version_id) {
                        $db->send_response(400, 'error', null, 'version_id required');
                        exit;
                    }
                    $version = $versionManager->getVersion($version_id);
                    if ($version) {
                        $version['changelog'] = $versionManager->getVersionChangelog($version_id);
                    }
                    $db->send_response(200, 'success', $version, 'Version retrieved');
                    break;

                case 'current':
                    $course_id = (int) $_GET['course_id'];
                    if (!$course_id) {
                        $db->send_response(400, 'error', null, 'course_id required');
                        exit;
                    }
                    $version = $versionManager->getCurrentVersion($course_id);
                    $db->send_response(200, 'success', $version, 'Current version retrieved');
                    break;

                case 'create':
                    if (!isAdmin($user)) {
                        $db->send_response(403, 'error', null, 'Admin access required');
                        exit;
                    }
                    if (empty($input['course_id'])) {
                        $db->send_response(400, 'error', null, 'course_id required');
                        exit;
                    }
                    $result = $versionManager->createVersion($input['course_id'], $input, $user_id);
                    $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                        $result['success'] ? 'Version created' : $result['message']);
                    break;

                case 'publish':
                    if (!isAdmin($user)) {
                        $db->send_response(403, 'error', null, 'Admin access required');
                        exit;
                    }
                    $result = $versionManager->publishVersion($input['version_id'], $user_id);
                    $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                        $result['success'] ? 'Version published' : $result['message']);
                    break;

                case 'compare':
                    $v1 = (int) $_GET['version_id_1'];
                    $v2 = (int) $_GET['version_id_2'];
                    if (!$v1 || !$v2) {
                        $db->send_response(400, 'error', null, 'Both version IDs required');
                        exit;
                    }
                    $comparison = $versionManager->compareVersions($v1, $v2);
                    $db->send_response(200, $comparison['success'] ? 'success' : 'error', $comparison,
                        $comparison['success'] ? 'Comparison retrieved' : $comparison['message']);
                    break;

                case 'changelog':
                    $version_id = (int) $_GET['version_id'];
                    if (!$version_id) {
                        $db->send_response(400, 'error', null, 'version_id required');
                        exit;
                    }
                    $changelog = $versionManager->getVersionChangelog($version_id);
                    $db->send_response(200, 'success', $changelog, 'Changelog retrieved');
                    break;

                case 'add-changelog':
                    if (!isAdmin($user)) {
                        $db->send_response(403, 'error', null, 'Admin access required');
                        exit;
                    }
                    $id = $versionManager->addChangelogEntry($input['version_id'], $input, $user_id);
                    $db->send_response(200, 'success', ['changelog_id' => $id], 'Changelog entry added');
                    break;

                case 'history-report':
                    if (!isHR($user)) {
                        $db->send_response(403, 'error', null, 'HR access required');
                        exit;
                    }
                    $report = $versionManager->getVersionHistoryReport(
                        $_GET['date_from'] ?? null,
                        $_GET['date_to'] ?? null
                    );
                    $db->send_response(200, 'success', $report, 'Version history report retrieved');
                    break;

                default:
                    $db->send_response(400, 'error', null, 'Invalid versions action');
            }
            break;

        // ==========================================
        // COURSE WAITLIST
        // ==========================================
        case 'waitlist':
            $waitlistManager = new CourseWaitlist($conn);
            $waitlistManager->setTenant($tenant_id);

            switch ($action) {
                // User actions
                case 'join':
                    if (empty($input['course_id'])) {
                        $db->send_response(400, 'error', null, 'course_id required');
                        exit;
                    }
                    $result = $waitlistManager->addToWaitlist($user_id, $input['course_id'], $input);
                    $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                        $result['success'] ? 'Added to waitlist' : $result['message']);
                    break;

                case 'leave':
                    $result = $waitlistManager->removeFromWaitlist($input['waitlist_id'], $user_id, 'cancelled');
                    $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                        $result['success'] ? 'Removed from waitlist' : $result['message']);
                    break;

                case 'my-waitlist':
                    $entries = $waitlistManager->getUserWaitlist($user_id);
                    $db->send_response(200, 'success', $entries, 'Waitlist entries retrieved');
                    break;

                case 'position':
                    $waitlist_id = (int) $_GET['waitlist_id'];
                    $entry = $waitlistManager->getWaitlistEntry($waitlist_id);
                    if ($entry && $entry['user_id'] == $user_id) {
                        $db->send_response(200, 'success', [
                            'position' => $entry['position'],
                            'status' => $entry['status']
                        ], 'Position retrieved');
                    } else {
                        $db->send_response(404, 'error', null, 'Entry not found');
                    }
                    break;

                // Admin actions
                case 'course-waitlist':
                    if (!isManager($user)) {
                        $db->send_response(403, 'error', null, 'Manager access required');
                        exit;
                    }
                    $course_id = (int) $_GET['course_id'];
                    if (!$course_id) {
                        $db->send_response(400, 'error', null, 'course_id required');
                        exit;
                    }
                    $status = $_GET['status'] ?? null;
                    $waitlist = $waitlistManager->getCourseWaitlist($course_id, $status);
                    $db->send_response(200, 'success', $waitlist, 'Course waitlist retrieved');
                    break;

                case 'enroll':
                    if (!isHR($user)) {
                        $db->send_response(403, 'error', null, 'HR access required');
                        exit;
                    }
                    $result = $waitlistManager->enrollFromWaitlist($input['waitlist_id'], $user_id);
                    $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                        $result['success'] ? 'User enrolled from waitlist' : $result['message']);
                    break;

                case 'remove':
                    if (!isHR($user)) {
                        $db->send_response(403, 'error', null, 'HR access required');
                        exit;
                    }
                    $reason = $input['reason'] ?? 'cancelled';
                    $result = $waitlistManager->removeFromWaitlist($input['waitlist_id'], $user_id, $reason);
                    $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                        $result['success'] ? 'Removed from waitlist' : $result['message']);
                    break;

                case 'notify-next':
                    if (!isHR($user)) {
                        $db->send_response(403, 'error', null, 'HR access required');
                        exit;
                    }
                    $count = $input['count'] ?? 1;
                    $result = $waitlistManager->notifyNextInLine($input['course_id'], $count);
                    $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                        $result['success'] ? 'Notifications sent' : 'Failed to send notifications');
                    break;

                case 'update-priority':
                    if (!isHR($user)) {
                        $db->send_response(403, 'error', null, 'HR access required');
                        exit;
                    }
                    $result = $waitlistManager->updatePriority($input['waitlist_id'], $input['priority'], $user_id);
                    $db->send_response(200, $result ? 'success' : 'error', null,
                        $result ? 'Priority updated' : 'Failed to update priority');
                    break;

                case 'process-expired':
                    if (!isAdmin($user)) {
                        $db->send_response(403, 'error', null, 'Admin access required');
                        exit;
                    }
                    $result = $waitlistManager->processExpiredNotifications();
                    $db->send_response(200, 'success', $result, 'Expired notifications processed');
                    break;

                // Reports
                case 'summary':
                    if (!isHR($user)) {
                        $db->send_response(403, 'error', null, 'HR access required');
                        exit;
                    }
                    $summary = $waitlistManager->getWaitlistSummary();
                    $db->send_response(200, 'success', $summary, 'Waitlist summary retrieved');
                    break;

                case 'report':
                    if (!isHR($user)) {
                        $db->send_response(403, 'error', null, 'HR access required');
                        exit;
                    }
                    $report = $waitlistManager->getWaitlistReport(
                        $_GET['date_from'] ?? null,
                        $_GET['date_to'] ?? null
                    );
                    $db->send_response(200, 'success', $report, 'Waitlist report retrieved');
                    break;

                // Manager actions - add users to waitlist
                case 'add-user':
                    if (!isManager($user)) {
                        $db->send_response(403, 'error', null, 'Manager access required');
                        exit;
                    }
                    $options = array_merge($input, ['requested_by' => $user_id]);
                    $result = $waitlistManager->addToWaitlist($input['user_id'], $input['course_id'], $options);
                    $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                        $result['success'] ? 'User added to waitlist' : $result['message']);
                    break;

                default:
                    $db->send_response(400, 'error', null, 'Invalid waitlist action');
            }
            break;

        default:
            $db->send_response(400, 'error', null, 'Invalid resource. Use: versions, waitlist');
    }
} catch (Exception $e) {
    error_log('Course Management API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, 'Server error: ' . $e->getMessage());
}