<?php
include_once '../config/Database.php';
include_once '../class/Branches.php';
include_once '../class/User.php';
$database = new Database();
$db = $database->getConnection();
$user = new User($db);
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id) && !empty($data->tenant_id) && !empty($data->user_id)) {
    $token_check = $database->verify_user($data->token, $data->id);
    if ($token_check != 100 || !$user->is_admin($data->id)) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }
    $user->id = $data->user_id;
    $completed = $user->getUserCourseCompleted($data->tenant_id, $data->user_id);
    $started = $user->getUserCourseStarted($data->tenant_id, $data->user_id);
    $activity_count = $user->getUserActivityCount($data->tenant_id, $data->user_id);
    $course_count = $user->getUserCourseCount($data->tenant_id, $data->user_id);
    $completion_rate = $user->calculateCompletionRate($started, $completed);
    $record = $user->read_single();
    if (is_array($record)) {
        $list = array(
            "id" =>$data->id,
            "token" => $data->token,
            "tenant" => $data->tenant_id,
            "user_id" => $record["id"],
            "staffId" => $record["staffId"],
            "first_name" => $record["first_name"],
            "last_name" => $record["last_name"],
            "email" => $record["email"],
            "phone" => $record["phone"],
            "department" => $record["department"],
            "job_title" => $record["job_title"],
            "level" => $record["level"],
            "team" => $record["team"],
            "branch" => $record["branch"],
            'course_count' => $course_count,
            'activity_count' => $activity_count,
            'course_started' => $started,
            'course_completed' => $completed,
            'completion_rate' => $completion_rate,
            'departmentName' => $user->getDepartmentName((int) $record['department']),
            'job_titleName' => $user->getJobTitleName((int)$record['job_title']),
            'levelName' => $user->getLevelName((int)$record['level']),
            'teamName' => $user->getTeamName((int)$record['team']),
            'branchName' => $user->getBranchName((int)$record['branch']),
            "picture" => !empty($record["avatar"]) ? $record["avatar"] : "/assets/img/placeholder.png",
            "days_since_last_login" => $user->formatLastActivityHuman($record["last_login"]),
            "last_login" => $record["last_login"] ?? null,
            "is_admin" => (bool)$record["is_admin"],
            "is_learner" => (bool)$record["is_learner"],
            "is_content" => (bool)$record["is_content"],
            "is_hr" => (bool)$record["is_hr"],
            "is_instructor" => (bool)$record["is_instructor"],
            "is_active" => isset($record["is_active"]) ? (bool)$record["is_active"] : null
        );
        http_response_code(200);
        $database->send_response(200, "success", $list, "Record retrieved successfully.");
    } else {
        http_response_code(404);
        $database->send_response(404, "failed", [], $record); // "User not found"
    }
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Unable to get records. Invalid request.");
}

