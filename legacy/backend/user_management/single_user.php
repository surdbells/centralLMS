<?php
include_once '../config/Database.php';
include_once '../class/User.php';
$database = new Database();
$db = $database->getConnection();
$user = new User($db);
$user_records = [];
$data = json_decode(file_get_contents("php://input"));
if (!empty($data->token) && !empty($data->id) && !empty($data->tenant_id)) {
    $token_verification = $database->verify_user($data->token, $data->id);
    if ($token_verification != 100) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unable to get data, Un-Authorized Request");
        exit;
    }
    $filters = [
        'id' => $data->userid ?? null,
        'department' => $data->department ?? null,
        'job_title' => $data->job_title ?? null,
        'level' => $data->level ?? null,
        'team' => $data->team ?? null,
        'branch' => $data->branch ?? null,
        'is_active' => $data->is_active ?? null,
        'is_admin' => $data->is_admin ?? null,
        'is_learner' => $data->is_learner ?? null,
        'is_content' => $data->is_content ?? null,
        'is_hr' => $data->is_hr ?? null,
        'is_instructor' => $data->is_instructor ?? null
    ];
    $user->page = $data->page ?? 1;
    $user->per_page = $data->per_page ?? 10;
    $user->sort_by = $data->sort_by ?? 'id';
    $user->sort_dir = $data->sort_dir ?? 'asc';
    $result = $user->getUsersByFilter($data->tenant_id, $filters);
    foreach ($result['data'] as $records) {
        $user_records[] = [
            "id" => $records["id"],
            "token" => $records["token"],
            "staffId" => $records["staffId"],
            "picture" => !empty($records["avatar"]) ? $records["avatar"] : "https://avatar.iran.liara.run/public/30",
            "first_name" => $records["first_name"],
            "last_name" => $records["last_name"],
            "email" => $records["email"],
            'department' => $user->getDepartmentName((int)$records['department']),
            'job_title' => $user->getJobTitleName((int)$records['job_title']),
            'level' => $user->getLevelName((int)$records['level']),
            'team' => $user->getTeamName((int)$records['team']),
            'branch' => $user->getBranchName((int)$records['branch']),
            "is_active" => (bool)$records["is_active"],
            "is_admin" => (bool)$records["is_admin"],
            "is_learner" => (bool)$records["is_learner"],
            "is_content" => (bool)$records["is_content"],
            "is_hr" => (bool)$records["is_hr"],
            "is_instructor" => (bool)$records["is_instructor"],
            "last_login" => $records["last_login"]
        ];
    }
    http_response_code(200);
    $database->send_response(200, "success", [
        "users" => $user_records,
        "meta" => $result['meta'],
        "links" => $result['links'],
        "statistics" => [
            "users" => $user->getUsersCount($data->tenant_id),
            "branch" => $user->getBranchesCount($data->tenant_id),
            "department" => $user->getDepartmentsCount($data->tenant_id),
            "team" => $user->getTeamsCount($data->tenant_id),
            "titles" => $user->getJobTitlesCount($data->tenant_id),
            "levels" => $user->getLevelsCount($data->tenant_id),
        ]
    ], "Users retrieved successfully");
} else {
    http_response_code(400);
    $database->send_response(400, "failed", [], "Unable to get data. Invalid request.");
}
