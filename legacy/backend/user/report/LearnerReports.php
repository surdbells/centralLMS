<?php

require_once __DIR__ . '/BaseReport.php';

/**
 * Learner Reports (Self-Service)
 * Reports available to individual learners for their own data
 */
class LearnerReports extends BaseReport {

    private int $user_id;

    public function setUser(int $user_id): void {
        $this->user_id = $user_id;
    }

    public function generate(): array {
        return $this->myLearningSummary();
    }

    /**
     * My Learning Summary
     */
    public function myLearningSummary(): array {
        $query = "SELECT 
                    COUNT(*) as total_enrolled,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN status = 'not_started' THEN 1 ELSE 0 END) as not_started,
                    SUM(time_spent_minutes) as total_time,
                    AVG(CASE WHEN status = 'completed' THEN final_score END) as avg_score
                  FROM course_enrollments
                  WHERE user_id = ? AND tenant_id = ? AND is_active = 1";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();

        // Learning streak
        $streakQuery = "SELECT current_streak, longest_streak, last_activity_date 
                        FROM user_streaks WHERE user_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($streakQuery);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $streak = $stmt->get_result()->fetch_assoc() ?? ['current_streak' => 0, 'longest_streak' => 0];

        // Points and badges
        $gamificationQuery = "SELECT 
                                (SELECT COALESCE(SUM(points), 0) FROM user_points WHERE user_id = ? AND tenant_id = ?) as total_points,
                                (SELECT COUNT(*) FROM user_badges WHERE user_id = ? AND tenant_id = ?) as total_badges,
                                (SELECT COUNT(*) FROM certificates WHERE user_id = ? AND tenant_id = ?) as total_certificates";
        $stmt = $this->conn->prepare($gamificationQuery);
        $stmt->bind_param("iiiiii", $this->user_id, $this->tenant_id, $this->user_id, $this->tenant_id, 
                          $this->user_id, $this->tenant_id);
        $stmt->execute();
        $gamification = $stmt->get_result()->fetch_assoc();

        $enrolled = (int) ($stats['total_enrolled'] ?? 0);
        $completed = (int) ($stats['completed'] ?? 0);

        return [
            'report_name' => 'My Learning Summary',
            'courses' => [
                'total_enrolled' => $enrolled,
                'completed' => $completed,
                'in_progress' => (int) ($stats['in_progress'] ?? 0),
                'not_started' => (int) ($stats['not_started'] ?? 0),
                'completion_rate' => $enrolled > 0 ? round(($completed / $enrolled) * 100, 1) : 0
            ],
            'performance' => [
                'total_learning_time' => $this->formatDuration((int) ($stats['total_time'] ?? 0)),
                'avg_score' => round((float) ($stats['avg_score'] ?? 0), 1)
            ],
            'streak' => [
                'current' => (int) ($streak['current_streak'] ?? 0),
                'longest' => (int) ($streak['longest_streak'] ?? 0)
            ],
            'achievements' => [
                'total_points' => (int) $gamification['total_points'],
                'total_badges' => (int) $gamification['total_badges'],
                'total_certificates' => (int) $gamification['total_certificates']
            ]
        ];
    }

    /**
     * My Enrolled Courses
     */
    public function myEnrolledCourses(): array {
        $query = "SELECT ce.enrollment_id, c.course_id, c.title, c.description,
                    c.thumbnail, c.duration_hours, cat.name as category,
                    ce.status, ce.progress_percentage, ce.enrolled_at,
                    ce.started_at, ce.due_date, ce.is_mandatory,
                    ce.last_activity_at
                  FROM course_enrollments ce
                  JOIN courses c ON ce.course_id = c.course_id
                  LEFT JOIN categories cat ON c.category_id = cat.category_id
                  WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
                  ORDER BY ce.last_activity_at DESC NULLS LAST, ce.enrolled_at DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $this->user_id, $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'enrollment_id' => (int) $row['enrollment_id'],
                'course_id' => (int) $row['course_id'],
                'title' => $row['title'],
                'description' => $row['description'],
                'thumbnail' => $row['thumbnail'],
                'duration_hours' => (float) $row['duration_hours'],
                'category' => $row['category'] ?? 'General',
                'status' => $row['status'],
                'status_badge' => $this->getStatusBadge($row['status']),
                'progress' => (int) $row['progress_percentage'],
                'enrolled_at' => $row['enrolled_at'],
                'started_at' => $row['started_at'],
                'due_date' => $row['due_date'],
                'is_mandatory' => (bool) $row['is_mandatory'],
                'last_activity' => $row['last_activity_at']
            ];
        }

        return [
            'report_name' => 'My Enrolled Courses',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * My Completed Courses
     */
    public function myCompletedCourses(): array {
        $query = "SELECT ce.enrollment_id, c.course_id, c.title, c.thumbnail,
                    cat.name as category, c.duration_hours,
                    ce.completed_at, ce.final_score, ce.time_spent_minutes,
                    cert.certificate_id, cert.certificate_number
                  FROM course_enrollments ce
                  JOIN courses c ON ce.course_id = c.course_id
                  LEFT JOIN categories cat ON c.category_id = cat.category_id
                  LEFT JOIN certificates cert ON ce.user_id = cert.user_id AND ce.course_id = cert.course_id
                  WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
                  AND ce.status = 'completed'
                  ORDER BY ce.completed_at DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $this->user_id, $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'course_id' => (int) $row['course_id'],
                'title' => $row['title'],
                'thumbnail' => $row['thumbnail'],
                'category' => $row['category'] ?? 'General',
                'duration_hours' => (float) $row['duration_hours'],
                'completed_at' => $row['completed_at'],
                'final_score' => (int) $row['final_score'],
                'time_spent' => $this->formatDuration((int) $row['time_spent_minutes']),
                'has_certificate' => !empty($row['certificate_id']),
                'certificate_number' => $row['certificate_number']
            ];
        }

        return [
            'report_name' => 'My Completed Courses',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * My Assessment Results
     */
    public function myAssessmentResults(): array {
        $query = "SELECT aa.attempt_id, a.assessment_id, a.title, a.type,
                    c.title as course_title, a.passing_score,
                    aa.score, aa.passed, aa.attempt_number,
                    aa.started_at, aa.completed_at, aa.time_taken_minutes
                  FROM assessment_attempts aa
                  JOIN assessments a ON aa.assessment_id = a.assessment_id
                  JOIN courses c ON a.course_id = c.course_id
                  WHERE aa.user_id = ? AND a.tenant_id = ?
                  ORDER BY aa.completed_at DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $this->user_id, $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'attempt_id' => (int) $row['attempt_id'],
                'assessment_id' => (int) $row['assessment_id'],
                'title' => $row['title'],
                'type' => $row['type'],
                'course_title' => $row['course_title'],
                'passing_score' => (int) $row['passing_score'],
                'score' => (int) $row['score'],
                'passed' => (bool) $row['passed'],
                'status_badge' => $this->getStatusBadge($row['passed'] ? 'passed' : 'failed'),
                'attempt_number' => (int) $row['attempt_number'],
                'started_at' => $row['started_at'],
                'completed_at' => $row['completed_at'],
                'time_taken' => $this->formatDuration((int) $row['time_taken_minutes'])
            ];
        }

        return [
            'report_name' => 'My Assessment Results',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * My Certificates
     */
    public function myCertificates(): array {
        $query = "SELECT cert.certificate_id, cert.certificate_number,
                    c.course_id, c.title as course_title, c.thumbnail,
                    cert.issued_at, cert.expiry_date,
                    CASE 
                      WHEN cert.expiry_date IS NULL THEN 'valid'
                      WHEN cert.expiry_date < CURDATE() THEN 'expired'
                      WHEN cert.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'expiring_soon'
                      ELSE 'valid'
                    END as status
                  FROM certificates cert
                  JOIN courses c ON cert.course_id = c.course_id
                  WHERE cert.user_id = ? AND cert.tenant_id = ?
                  ORDER BY cert.issued_at DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $this->user_id, $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'certificate_id' => (int) $row['certificate_id'],
                'certificate_number' => $row['certificate_number'],
                'course_id' => (int) $row['course_id'],
                'course_title' => $row['course_title'],
                'thumbnail' => $row['thumbnail'],
                'issued_at' => $row['issued_at'],
                'expiry_date' => $row['expiry_date'],
                'status' => $row['status'],
                'status_badge' => $this->getStatusBadge($row['status'])
            ];
        }

        return [
            'report_name' => 'My Certificates',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * My Badges
     */
    public function myBadges(): array {
        $query = "SELECT ub.id, b.badge_id, b.badge_name, b.description, b.icon,
                    b.points_value, c.title as course_title, ub.earned_at
                  FROM user_badges ub
                  JOIN badges b ON ub.badge_id = b.badge_id
                  LEFT JOIN courses c ON b.course_id = c.course_id
                  WHERE ub.user_id = ? AND ub.tenant_id = ?
                  ORDER BY ub.earned_at DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $this->user_id, $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'id' => (int) $row['id'],
                'badge_id' => (int) $row['badge_id'],
                'badge_name' => $row['badge_name'],
                'description' => $row['description'],
                'icon' => $row['icon'],
                'points_value' => (int) $row['points_value'],
                'course_title' => $row['course_title'],
                'earned_at' => $row['earned_at']
            ];
        }

        return [
            'report_name' => 'My Badges',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * My Compliance Status
     */
    public function myComplianceStatus(): array {
        $query = "SELECT ce.enrollment_id, c.course_id, c.title,
                    ce.status, ce.progress_percentage, ce.due_date,
                    ce.completed_at,
                    CASE 
                      WHEN ce.status = 'completed' THEN 'compliant'
                      WHEN ce.due_date < CURDATE() THEN 'overdue'
                      WHEN ce.due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'due_soon'
                      ELSE 'pending'
                    END as compliance_status,
                    DATEDIFF(ce.due_date, CURDATE()) as days_until_due
                  FROM course_enrollments ce
                  JOIN courses c ON ce.course_id = c.course_id
                  WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
                  AND ce.is_mandatory = 1
                  ORDER BY CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END,
                           ce.due_date ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        $summary = ['total' => 0, 'compliant' => 0, 'pending' => 0, 'overdue' => 0];

        while ($row = $result->fetch_assoc()) {
            $status = $row['compliance_status'];
            $summary['total']++;
            if ($status === 'compliant') $summary['compliant']++;
            elseif ($status === 'overdue') $summary['overdue']++;
            else $summary['pending']++;

            $data[] = [
                'enrollment_id' => (int) $row['enrollment_id'],
                'course_id' => (int) $row['course_id'],
                'title' => $row['title'],
                'status' => $row['status'],
                'progress' => (int) $row['progress_percentage'],
                'due_date' => $row['due_date'],
                'completed_at' => $row['completed_at'],
                'compliance_status' => $status,
                'status_badge' => $this->getStatusBadge($status),
                'days_until_due' => $row['days_until_due'] !== null ? (int) $row['days_until_due'] : null
            ];
        }

        $summary['compliance_rate'] = $summary['total'] > 0 ? 
            round(($summary['compliant'] / $summary['total']) * 100, 1) : 100;

        return [
            'report_name' => 'My Compliance Status',
            'summary' => $summary,
            'data' => $data
        ];
    }

    /**
     * My Learning Time
     */
    public function myLearningTime(int $days = 30): array {
        $query = "SELECT DATE(started_at) as date,
                    COUNT(*) as sessions,
                    SUM(duration_minutes) as total_minutes
                  FROM learning_sessions
                  WHERE user_id = ? AND tenant_id = ?
                  AND started_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                  GROUP BY DATE(started_at)
                  ORDER BY date DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iii", $this->user_id, $this->tenant_id, $days);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        $totalMinutes = 0;
        $totalSessions = 0;
        $activeDays = 0;

        while ($row = $result->fetch_assoc()) {
            $minutes = (int) $row['total_minutes'];
            $totalMinutes += $minutes;
            $totalSessions += (int) $row['sessions'];
            $activeDays++;

            $data[] = [
                'date' => $row['date'],
                'sessions' => (int) $row['sessions'],
                'total_time' => $this->formatDuration($minutes),
                'hours' => round($minutes / 60, 1)
            ];
        }

        return [
            'report_name' => 'My Learning Time',
            'period_days' => $days,
            'summary' => [
                'total_time' => $this->formatDuration($totalMinutes),
                'total_hours' => round($totalMinutes / 60, 1),
                'total_sessions' => $totalSessions,
                'active_days' => $activeDays,
                'avg_daily_time' => $activeDays > 0 ? $this->formatDuration((int) ($totalMinutes / $activeDays)) : '0m'
            ],
            'daily_data' => $data
        ];
    }

    /**
     * My Leaderboard Position
     */
    public function myLeaderboardPosition(): array {
        // Get user's points
        $pointsQuery = "SELECT COALESCE(SUM(points), 0) as total_points FROM user_points 
                        WHERE user_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($pointsQuery);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $myPoints = (int) $stmt->get_result()->fetch_assoc()['total_points'];

        // Get rank
        $rankQuery = "SELECT COUNT(*) + 1 as rank FROM (
                        SELECT user_id, SUM(points) as total 
                        FROM user_points WHERE tenant_id = ? GROUP BY user_id
                        HAVING total > ?
                      ) ranked";
        $stmt = $this->conn->prepare($rankQuery);
        $stmt->bind_param("ii", $this->tenant_id, $myPoints);
        $stmt->execute();
        $myRank = (int) $stmt->get_result()->fetch_assoc()['rank'];

        // Get total participants
        $totalQuery = "SELECT COUNT(DISTINCT user_id) as total FROM user_points WHERE tenant_id = ?";
        $stmt = $this->conn->prepare($totalQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $totalParticipants = (int) $stmt->get_result()->fetch_assoc()['total'];

        // Get department rank
        $deptQuery = "SELECT d.department FROM users u 
                      JOIN departments d ON u.department = d.department
                      WHERE u.id = ? AND u.tenant_id = ?";
        $stmt = $this->conn->prepare($deptQuery);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $deptResult = $stmt->get_result()->fetch_assoc();
        $department = $deptResult['department'] ?? null;

        $deptRank = null;
        $deptTotal = null;
        if ($department) {
            $deptRankQuery = "SELECT COUNT(*) + 1 as rank FROM (
                                SELECT up.user_id, SUM(up.points) as total 
                                FROM user_points up
                                JOIN users u ON up.user_id = u.id
                                WHERE up.tenant_id = ? AND u.department = ?
                                GROUP BY up.user_id
                                HAVING total > ?
                              ) ranked";
            $stmt = $this->conn->prepare($deptRankQuery);
            $stmt->bind_param("isi", $this->tenant_id, $department, $myPoints);
            $stmt->execute();
            $deptRank = (int) $stmt->get_result()->fetch_assoc()['rank'];

            $deptTotalQuery = "SELECT COUNT(DISTINCT up.user_id) as total 
                              FROM user_points up
                              JOIN users u ON up.user_id = u.id
                              WHERE up.tenant_id = ? AND u.department = ?";
            $stmt = $this->conn->prepare($deptTotalQuery);
            $stmt->bind_param("is", $this->tenant_id, $department);
            $stmt->execute();
            $deptTotal = (int) $stmt->get_result()->fetch_assoc()['total'];
        }

        // Get nearby users
        $nearbyQuery = "SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) as name,
                          u.avatar_url, COALESCE(SUM(up.points), 0) as points
                        FROM users u
                        LEFT JOIN user_points up ON u.id = up.user_id AND up.tenant_id = ?
                        WHERE u.tenant_id = ? AND u.is_active = 1
                        GROUP BY u.id
                        ORDER BY points DESC
                        LIMIT 10";
        $stmt = $this->conn->prepare($nearbyQuery);
        $stmt->bind_param("ii", $this->tenant_id, $this->tenant_id);
        $stmt->execute();
        $topUsers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return [
            'report_name' => 'My Leaderboard Position',
            'my_stats' => [
                'total_points' => $myPoints,
                'overall_rank' => $myRank,
                'total_participants' => $totalParticipants,
                'percentile' => $totalParticipants > 0 ? round((($totalParticipants - $myRank + 1) / $totalParticipants) * 100, 1) : 0,
                'department_rank' => $deptRank,
                'department_total' => $deptTotal
            ],
            'top_users' => array_map(function($user, $index) {
                return [
                    'rank' => $index + 1,
                    'user_id' => (int) $user['id'],
                    'name' => $user['name'],
                    'avatar_url' => $user['avatar_url'],
                    'points' => (int) $user['points'],
                    'is_me' => (int) $user['id'] === $this->user_id
                ];
            }, $topUsers, array_keys($topUsers))
        ];
    }

    /**
     * My Training Transcript
     */
    public function myTrainingTranscript(): array {
        // Get all completed training with certificates
        $query = "SELECT c.title as course_title, c.duration_hours,
                    ce.completed_at, ce.final_score, ce.time_spent_minutes,
                    cert.certificate_number, cert.issued_at, cert.expiry_date
                  FROM course_enrollments ce
                  JOIN courses c ON ce.course_id = c.course_id
                  LEFT JOIN certificates cert ON ce.user_id = cert.user_id AND ce.course_id = cert.course_id
                  WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
                  AND ce.status = 'completed'
                  ORDER BY ce.completed_at DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        $courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // User info
        $userQuery = "SELECT CONCAT(first_name, ' ', last_name) as name, email, employee_id
                      FROM users WHERE id = ?";
        $stmt = $this->conn->prepare($userQuery);
        $stmt->bind_param("i", $this->user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        $totalHours = 0;
        $transcript = [];
        foreach ($courses as $course) {
            $totalHours += (int) $course['time_spent_minutes'];
            $transcript[] = [
                'course_title' => $course['course_title'],
                'completed_at' => $course['completed_at'],
                'score' => (int) $course['final_score'],
                'time_spent' => $this->formatDuration((int) $course['time_spent_minutes']),
                'certificate_number' => $course['certificate_number'],
                'certificate_expiry' => $course['expiry_date']
            ];
        }

        return [
            'report_name' => 'My Training Transcript',
            'generated_at' => date('Y-m-d H:i:s'),
            'learner' => [
                'name' => $user['name'],
                'email' => $user['email'],
                'employee_id' => $user['employee_id']
            ],
            'summary' => [
                'total_courses_completed' => count($courses),
                'total_learning_hours' => round($totalHours / 60, 1)
            ],
            'transcript' => $transcript
        ];
    }

    /**
     * My Upcoming Deadlines
     */
    public function myUpcomingDeadlines(int $days = 30): array {
        $query = "SELECT ce.enrollment_id, c.course_id, c.title, c.thumbnail,
                    ce.status, ce.progress_percentage, ce.due_date,
                    ce.is_mandatory,
                    DATEDIFF(ce.due_date, CURDATE()) as days_until_due
                  FROM course_enrollments ce
                  JOIN courses c ON ce.course_id = c.course_id
                  WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
                  AND ce.status != 'completed'
                  AND ce.due_date IS NOT NULL
                  AND ce.due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                  ORDER BY ce.due_date ASC";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iii", $this->user_id, $this->tenant_id, $days);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $daysUntil = (int) $row['days_until_due'];
            $urgency = 'normal';
            if ($daysUntil < 0) $urgency = 'overdue';
            elseif ($daysUntil <= 3) $urgency = 'critical';
            elseif ($daysUntil <= 7) $urgency = 'high';

            $data[] = [
                'enrollment_id' => (int) $row['enrollment_id'],
                'course_id' => (int) $row['course_id'],
                'title' => $row['title'],
                'thumbnail' => $row['thumbnail'],
                'status' => $row['status'],
                'progress' => (int) $row['progress_percentage'],
                'due_date' => $row['due_date'],
                'days_until_due' => $daysUntil,
                'is_mandatory' => (bool) $row['is_mandatory'],
                'urgency' => $urgency
            ];
        }

        return [
            'report_name' => 'My Upcoming Deadlines',
            'days_ahead' => $days,
            'data' => $data,
            'summary' => [
                'total' => count($data),
                'overdue' => count(array_filter($data, fn($d) => $d['urgency'] === 'overdue')),
                'critical' => count(array_filter($data, fn($d) => $d['urgency'] === 'critical')),
                'high' => count(array_filter($data, fn($d) => $d['urgency'] === 'high'))
            ]
        ];
    }

    public function getSummary(): array {
        return $this->myLearningSummary();
    }
}
