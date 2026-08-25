<?php
include_once '../../config/Database.php';
include_once '../../class/assessment/Assessment.php';
$database = new Database();
$db = $database->getConnection();
$assessment = new Assessment($db);
$questions = $assessment->list_questions();
$database->send_response(200, "success", $questions, "Question list loaded");
