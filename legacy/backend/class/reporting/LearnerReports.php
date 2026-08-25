<?php

/**
 * Learner Self-Service Reports (43 reports across 9 categories)
 * All reports scoped to the authenticated learner (user_id) — no admin org-unit filtering
 * Categories 15-23 from the catalog
 */

require_once __DIR__ . '/BaseReport.php';

class LearnerReports extends BaseReport
{
    // All learner reports use $this->user_id as the learner scope

    // ==========================================
    // CATEGORY 15: MY COURSES (7 reports)
    // ==========================================

    /** 15.1 My Learning Summary */
    public function myLearningSummary(): array
    {
        $uid = $this->user_id;
        $t = $this->tenant_id;

        $enrollSql = "SELECT
            COUNT(*) as total_enrolled,
            SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN status='not_started' THEN 1 ELSE 0 END) as not_started,
            SUM(CASE WHEN due_date < CURDATE() AND status != 'completed' THEN 1 ELSE 0 END) as overdue,
            AVG(progress_percentage) as avg_progress,
            AVG(CASE WHEN status='completed' THEN final_score ELSE NULL END) as avg_score,
            SUM(time_spent_minutes) as total_time
            FROM course_enrollments WHERE user_id = ? AND tenant_id = ? AND is_active = 1";
        $enroll = $this->executeOne($enrollSql, 'ii', [$uid, $t]);

        $certs = $this->executeOne("SELECT COUNT(*) as cnt FROM certificates WHERE user_id = ? AND tenant_id = ?", 'ii', [$uid, $t]);
        $points = $this->executeOne("SELECT COALESCE(SUM(points), 0) as total FROM user_points WHERE user_id = ? AND tenant_id = ?", 'ii', [$uid, $t]);
        $badges = $this->executeOne("SELECT COUNT(*) as cnt FROM user_badges WHERE user_id = ? AND tenant_id = ?", 'ii', [$uid, $t]);

        return [
            'total_enrolled' => (int) $enroll['total_enrolled'],
            'completed' => (int) $enroll['completed'],
            'in_progress' => (int) $enroll['in_progress'],
            'not_started' => (int) $enroll['not_started'],
            'overdue' => (int) $enroll['overdue'],
            'avg_progress' => round((float) $enroll['avg_progress'], 1),
            'avg_score' => round((float) $enroll['avg_score'], 1),
            'total_learning_hours' => round((int) $enroll['total_time'] / 60, 1),
            'certificates' => (int) $certs['cnt'],
            'points' => (int) $points['total'],
            'badges' => (int) $badges['cnt'],
        ];
    }

    /** 15.2 My Enrolled Courses */
    public function myEnrolledCourses(): array
    {
        $sql = "SELECT ce.enrollment_id, c.course_id, c.title, c.description,
            COALESCE(cat.name, '') as category, ce.status, ce.progress_percentage,
            ce.final_score, ce.time_spent_minutes, ce.enrolled_at, ce.started_at,
            ce.completed_at, ce.due_date, ce.is_mandatory
            FROM course_enrollments ce
            JOIN courses c ON c.course_id = ce.course_id
            LEFT JOIN categories cat ON cat.category_id = c.category_id
            WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
            ORDER BY ce.enrolled_at DESC";
        $data = $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
        foreach ($data as &$r) {
            $r['time_formatted'] = $this->formatMinutes((int) $r['time_spent_minutes']);
            $r['is_overdue'] = $r['due_date'] && $r['due_date'] < date('Y-m-d') && $r['status'] !== 'completed';
        }
        return $data;
    }

    /** 15.3 My Completed Courses */
    public function myCompletedCourses(): array
    {
        $sql = "SELECT c.title, COALESCE(cat.name, '') as category,
            ce.final_score, ce.time_spent_minutes, ce.completed_at,
            ce.enrolled_at, DATEDIFF(ce.completed_at, ce.enrolled_at) as days_to_complete,
            (SELECT cert.certificate_number FROM certificates cert WHERE cert.user_id = ce.user_id AND cert.course_id = ce.course_id LIMIT 1) as certificate_number
            FROM course_enrollments ce
            JOIN courses c ON c.course_id = ce.course_id
            LEFT JOIN categories cat ON cat.category_id = c.category_id
            WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1 AND ce.status = 'completed'
            ORDER BY ce.completed_at DESC";
        $data = $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
        foreach ($data as &$r) $r['time_formatted'] = $this->formatMinutes((int) $r['time_spent_minutes']);
        return $data;
    }

    /** 15.4 My In-Progress Courses */
    public function myInProgressCourses(): array
    {
        $sql = "SELECT c.title, ce.progress_percentage, ce.time_spent_minutes,
            ce.enrolled_at, ce.started_at, ce.due_date, ce.is_mandatory,
            DATEDIFF(COALESCE(ce.due_date, DATE_ADD(NOW(), INTERVAL 30 DAY)), NOW()) as days_remaining
            FROM course_enrollments ce JOIN courses c ON c.course_id = ce.course_id
            WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1 AND ce.status = 'in_progress'
            ORDER BY ce.due_date ASC, ce.progress_percentage DESC";
        return $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
    }

    /** 15.5 My Overdue Courses */
    public function myOverdueCourses(): array
    {
        $sql = "SELECT c.title, ce.status, ce.progress_percentage, ce.due_date,
            DATEDIFF(NOW(), ce.due_date) as days_overdue, ce.is_mandatory
            FROM course_enrollments ce JOIN courses c ON c.course_id = ce.course_id
            WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
            AND ce.due_date < CURDATE() AND ce.status != 'completed'
            ORDER BY days_overdue DESC";
        return $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
    }

    /** 15.6 My Recommended Courses */
    public function myRecommendedCourses(): array
    {
        // Recommend based on department peers + popular unfinished
        $sql = "SELECT c.course_id, c.title, COALESCE(cat.name, '') as category,
            c.difficulty_level, c.duration_hours,
            COUNT(DISTINCT ce2.user_id) as peers_enrolled,
            'Popular in your department' as reason
            FROM courses c
            LEFT JOIN categories cat ON cat.category_id = c.category_id
            JOIN course_enrollments ce2 ON ce2.course_id = c.course_id AND ce2.tenant_id = c.tenant_id AND ce2.is_active = 1
            JOIN users peer ON peer.id = ce2.user_id AND peer.department = (SELECT department FROM users WHERE id = ? AND tenant_id = ?)
            WHERE c.tenant_id = ? AND c.is_active = 1 AND c.status = 'published'
            AND c.course_id NOT IN (SELECT course_id FROM course_enrollments WHERE user_id = ? AND tenant_id = ? AND is_active = 1)
            GROUP BY c.course_id, c.title, cat.name, c.difficulty_level, c.duration_hours
            ORDER BY peers_enrolled DESC LIMIT 10";
        return $this->executeQuery($sql, 'iiiii', [$this->user_id, $this->tenant_id, $this->tenant_id, $this->user_id, $this->tenant_id]);
    }

    /** 15.7 My Course Ratings */
    public function myCourseRatings(): array
    {
        if (!$this->tableExists('course_ratings')) return [];
        $sql = "SELECT c.title, cr.rating, cr.review, cr.created_at
            FROM course_ratings cr JOIN courses c ON c.course_id = cr.course_id
            WHERE cr.user_id = ? AND cr.tenant_id = ? ORDER BY cr.created_at DESC";
        return $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
    }

    // ==========================================
    // CATEGORY 16: MY ASSESSMENTS (7 reports)
    // ==========================================

    /** 16.1 My Assessment Results */
    public function myAssessmentResults(): array
    {
        $sql = "SELECT a.title, at.score, at.max_score,
            CASE WHEN at.max_score > 0 THEN ROUND((at.score/at.max_score)*100, 1) ELSE 0 END as score_pct,
            at.passed, at.grade, at.status, at.attempt_number,
            at.started_at, at.completed_at, at.duration_seconds
            FROM proc_attempts at JOIN proc_assessments a ON a.assessment_id = at.assessment_id
            WHERE at.user_id = ? AND at.tenant_id = ? ORDER BY at.started_at DESC";
        $data = $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
        foreach ($data as &$r) $r['passed'] = (bool) $r['passed'];
        return $data;
    }

    /** 16.2 My Quiz History */
    public function myQuizHistory(): array
    {
        $sql = "SELECT a.title, a.assessment_type, at.score, at.max_score, at.passed, at.grade,
            at.attempt_number, at.completed_at
            FROM proc_attempts at JOIN proc_assessments a ON a.assessment_id = at.assessment_id
            WHERE at.user_id = ? AND at.tenant_id = ? AND a.assessment_type = 'quiz' AND at.status = 'completed'
            ORDER BY at.completed_at DESC";
        return $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
    }

    /** 16.3 My Exam Results */
    public function myExamResults(): array
    {
        $sql = "SELECT a.title, at.score, at.max_score, at.passed, at.grade, at.completed_at,
            (SELECT cert.certificate_number FROM certificates cert WHERE cert.user_id = at.user_id AND cert.course_id = a.course_id LIMIT 1) as certificate_number
            FROM proc_attempts at JOIN proc_assessments a ON a.assessment_id = at.assessment_id
            WHERE at.user_id = ? AND at.tenant_id = ? AND a.assessment_type IN ('exam', 'final_exam') AND at.status = 'completed'
            ORDER BY at.completed_at DESC";
        return $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
    }

    /** 16.4 My Assessment Attempts */
    public function myAssessmentAttempts(): array
    {
        $sql = "SELECT a.title, at.attempt_id, at.attempt_number, at.score, at.max_score,
            at.passed, at.grade, at.status, at.started_at, at.completed_at, at.duration_seconds,
            a.max_attempts,
            CASE WHEN a.max_attempts > 0 AND at.attempt_number >= a.max_attempts THEN 1 ELSE 0 END as max_reached
            FROM proc_attempts at JOIN proc_assessments a ON a.assessment_id = at.assessment_id
            WHERE at.user_id = ? AND at.tenant_id = ? ORDER BY at.started_at DESC";
        return $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
    }

    /** 16.5 My Strongest Topics */
    public function myStrongestTopics(): array
    {
        $sql = "SELECT COALESCE(cat.name, 'General') as topic,
            AVG(CASE WHEN at.max_score > 0 THEN (at.score/at.max_score)*100 ELSE NULL END) as avg_score,
            COUNT(*) as attempts, SUM(CASE WHEN at.passed=1 THEN 1 ELSE 0 END) as passed
            FROM proc_attempts at
            JOIN proc_assessments a ON a.assessment_id = at.assessment_id
            LEFT JOIN courses c ON c.course_id = a.course_id
            LEFT JOIN categories cat ON cat.category_id = c.category_id
            WHERE at.user_id = ? AND at.tenant_id = ? AND at.status = 'completed'
            GROUP BY cat.name HAVING avg_score >= 70 ORDER BY avg_score DESC";
        $rows = $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
        foreach ($rows as &$r) $r['avg_score'] = round((float) $r['avg_score'], 1);
        return $rows;
    }

    /** 16.6 My Weak Areas */
    public function myWeakAreas(): array
    {
        $sql = "SELECT COALESCE(cat.name, 'General') as topic,
            AVG(CASE WHEN at.max_score > 0 THEN (at.score/at.max_score)*100 ELSE NULL END) as avg_score,
            COUNT(*) as attempts, SUM(CASE WHEN at.passed=0 THEN 1 ELSE 0 END) as failed
            FROM proc_attempts at
            JOIN proc_assessments a ON a.assessment_id = at.assessment_id
            LEFT JOIN courses c ON c.course_id = a.course_id
            LEFT JOIN categories cat ON cat.category_id = c.category_id
            WHERE at.user_id = ? AND at.tenant_id = ? AND at.status = 'completed'
            GROUP BY cat.name HAVING avg_score < 70 ORDER BY avg_score ASC";
        $rows = $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
        foreach ($rows as &$r) $r['avg_score'] = round((float) $r['avg_score'], 1);
        return $rows;
    }

    /** 16.7 My Pending Assessments */
    public function myPendingAssessments(): array
    {
        $sql = "SELECT a.assessment_id, a.title, a.assessment_type, a.time_limit_minutes, a.max_attempts,
            aa.due_date,
            COALESCE((SELECT MAX(at.attempt_number) FROM proc_attempts at WHERE at.assessment_id = a.assessment_id AND at.user_id = ?), 0) as attempts_used,
            CASE WHEN aa.due_date < CURDATE() THEN 1 ELSE 0 END as is_overdue
            FROM proc_assessment_assignments aa
            JOIN proc_assessments a ON a.assessment_id = aa.assessment_id
            WHERE aa.user_id = ? AND aa.tenant_id = ? AND a.status = 'published'
            AND NOT EXISTS (SELECT 1 FROM proc_attempts at WHERE at.assessment_id = a.assessment_id AND at.user_id = ? AND at.passed = 1)
            ORDER BY aa.due_date ASC";
        return $this->executeQuery($sql, 'iiii', [$this->user_id, $this->user_id, $this->tenant_id, $this->user_id]);
    }

    // ==========================================
    // CATEGORY 17: MY CERTIFICATES & ACHIEVEMENTS (5 reports)
    // ==========================================

    /** 17.1 My Certificates */
    public function myCertificates(): array
    {
        $sql = "SELECT cert.certificate_id, cert.certificate_number, cert.issue_date, cert.expiry_date,
            c.title as course_title, DATEDIFF(cert.expiry_date, CURDATE()) as days_until_expiry,
            CASE WHEN cert.expiry_date IS NULL THEN 'permanent' WHEN cert.expiry_date < CURDATE() THEN 'expired' ELSE 'active' END as cert_status
            FROM certificates cert JOIN courses c ON c.course_id = cert.course_id
            WHERE cert.user_id = ? AND cert.tenant_id = ? ORDER BY cert.issue_date DESC";
        return $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
    }

    /** 17.2 My Expiring Certificates */
    public function myExpiringCertificates(): array
    {
        $days = (int) ($this->filters['days_ahead'] ?? 90);
        $sql = "SELECT cert.certificate_number, c.title as course_title,
            cert.issue_date, cert.expiry_date, DATEDIFF(cert.expiry_date, CURDATE()) as days_remaining
            FROM certificates cert JOIN courses c ON c.course_id = cert.course_id
            WHERE cert.user_id = ? AND cert.tenant_id = ? AND cert.expiry_date IS NOT NULL
            AND cert.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
            ORDER BY cert.expiry_date ASC";
        return $this->executeQuery($sql, 'iii', [$this->user_id, $this->tenant_id, $days]);
    }

    /** 17.3 My Badges */
    public function myBadges(): array
    {
        $sql = "SELECT b.badge_name, b.description, b.icon, ub.earned_at
            FROM user_badges ub JOIN badges b ON b.badge_id = ub.badge_id
            WHERE ub.user_id = ? AND ub.tenant_id = ? ORDER BY ub.earned_at DESC";
        return $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
    }

    /** 17.4 My Skills & Competencies */
    public function mySkills(): array
    {
        // Derive skills from completed course categories
        $sql = "SELECT COALESCE(cat.name, 'General') as skill_area,
            COUNT(*) as courses_completed,
            AVG(ce.final_score) as avg_score,
            SUM(ce.time_spent_minutes) as total_time
            FROM course_enrollments ce
            JOIN courses c ON c.course_id = ce.course_id
            LEFT JOIN categories cat ON cat.category_id = c.category_id
            WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1 AND ce.status = 'completed'
            GROUP BY cat.name ORDER BY courses_completed DESC";
        $rows = $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
        foreach ($rows as &$r) {
            $r['avg_score'] = round((float) $r['avg_score'], 1);
            $r['proficiency_level'] = $r['avg_score'] >= 90 ? 'Expert' : ($r['avg_score'] >= 70 ? 'Proficient' : 'Developing');
            $r['time_formatted'] = $this->formatMinutes((int) $r['total_time']);
        }
        return $rows;
    }

    /** 17.5 My Achievements Timeline */
    public function myAchievementsTimeline(): array
    {
        // Union of completions, certificates, badges
        $sql = "(SELECT 'course_completed' as type, c.title as name, ce.completed_at as achieved_at
            FROM course_enrollments ce JOIN courses c ON c.course_id = ce.course_id
            WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.status = 'completed' AND ce.completed_at IS NOT NULL)
            UNION ALL
            (SELECT 'certificate_earned' as type, c.title as name, cert.issue_date as achieved_at
            FROM certificates cert JOIN courses c ON c.course_id = cert.course_id
            WHERE cert.user_id = ? AND cert.tenant_id = ?)
            UNION ALL
            (SELECT 'badge_earned' as type, b.badge_name as name, ub.earned_at as achieved_at
            FROM user_badges ub JOIN badges b ON b.badge_id = ub.badge_id
            WHERE ub.user_id = ? AND ub.tenant_id = ?)
            ORDER BY achieved_at DESC LIMIT 50";
        return $this->executeQuery($sql, 'iiiiii', [$this->user_id, $this->tenant_id, $this->user_id, $this->tenant_id, $this->user_id, $this->tenant_id]);
    }

    // ==========================================
    // CATEGORY 18: MY GAMIFICATION (5 reports)
    // ==========================================

    /** 18.1 My Points Summary */
    public function myPointsSummary(): array
    {
        $sql = "SELECT COALESCE(activity_type, 'other') as activity_type,
            SUM(points) as total_points, COUNT(*) as transactions
            FROM user_points WHERE user_id = ? AND tenant_id = ?
            GROUP BY activity_type ORDER BY total_points DESC";
        $byType = $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
        $grand = array_sum(array_column($byType, 'total_points'));
        return ['total_points' => $grand, 'by_activity' => $byType];
    }

    /** 18.2 My Leaderboard Position */
    public function myLeaderboardPosition(): array
    {
        $sql = "SELECT user_id, total_points, rank_pos FROM (
            SELECT user_id, SUM(points) as total_points,
            RANK() OVER (ORDER BY SUM(points) DESC) as rank_pos
            FROM user_points WHERE tenant_id = ? GROUP BY user_id) ranked
            WHERE user_id = ?";
        $row = $this->executeOne($sql, 'ii', [$this->tenant_id, $this->user_id]);
        $totalUsers = $this->executeOne("SELECT COUNT(DISTINCT user_id) as cnt FROM user_points WHERE tenant_id = ?", 'i', [$this->tenant_id]);
        return [
            'rank' => (int) ($row['rank_pos'] ?? 0),
            'total_points' => (int) ($row['total_points'] ?? 0),
            'total_participants' => (int) ($totalUsers['cnt'] ?? 0),
            'percentile' => $totalUsers['cnt'] > 0 ? round((1 - ((int) ($row['rank_pos'] ?? 0) / (int) $totalUsers['cnt'])) * 100, 1) : 0,
        ];
    }

    /** 18.3 My Streak Stats */
    public function myStreakStats(): array
    {
        $row = $this->executeOne(
            "SELECT current_streak, longest_streak, last_activity_date FROM user_streaks WHERE user_id = ? AND tenant_id = ?",
            'ii', [$this->user_id, $this->tenant_id]
        );
        return $row ?: ['current_streak' => 0, 'longest_streak' => 0, 'last_activity_date' => null];
    }

    /** 18.4 My Milestones */
    public function myMilestones(): array
    {
        if (!$this->tableExists('achievement_milestones') || !$this->tableExists('user_achievements')) return [];
        $sql = "SELECT am.title, am.description, am.points_reward,
            CASE WHEN ua.id IS NOT NULL THEN 'achieved' ELSE 'pending' END as status,
            ua.achieved_at
            FROM achievement_milestones am
            LEFT JOIN user_achievements ua ON ua.milestone_id = am.milestone_id AND ua.user_id = ? AND ua.tenant_id = am.tenant_id
            WHERE am.tenant_id = ? ORDER BY ua.achieved_at DESC, am.title";
        return $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
    }

    /** 18.5 My Level Progress */
    public function myLevelProgress(): array
    {
        $totalPoints = $this->executeOne("SELECT COALESCE(SUM(points), 0) as total FROM user_points WHERE user_id = ? AND tenant_id = ?", 'ii', [$this->user_id, $this->tenant_id]);
        $pts = (int) $totalPoints['total'];
        // Simple level system: every 100 points = 1 level
        $level = intdiv($pts, 100) + 1;
        $ptsInLevel = $pts % 100;
        return ['total_points' => $pts, 'current_level' => $level, 'points_in_level' => $ptsInLevel, 'points_to_next' => 100 - $ptsInLevel];
    }

    // ==========================================
    // CATEGORY 19: MY COMPLIANCE (4 reports)
    // ==========================================

    /** 19.1 My Compliance Status */
    public function myComplianceStatus(): array
    {
        $sql = "SELECT
            COUNT(*) as total_mandatory,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN due_date < CURDATE() AND status != 'completed' THEN 1 ELSE 0 END) as overdue,
            SUM(CASE WHEN due_date >= CURDATE() AND status != 'completed' THEN 1 ELSE 0 END) as pending
            FROM course_enrollments WHERE user_id = ? AND tenant_id = ? AND is_active = 1 AND is_mandatory = 1";
        $row = $this->executeOne($sql, 'ii', [$this->user_id, $this->tenant_id]);
        $total = max((int) $row['total_mandatory'], 1);
        $row['compliance_rate'] = $this->safePercent((int) $row['completed'], $total);
        $row['status'] = (int) $row['overdue'] > 0 ? 'non_compliant' : ((int) $row['pending'] > 0 ? 'pending' : 'compliant');
        return $row;
    }

    /** 19.2 My Mandatory Training */
    public function myMandatoryTraining(): array
    {
        $sql = "SELECT c.title, ce.status, ce.progress_percentage, ce.due_date, ce.final_score,
            ce.enrolled_at, ce.completed_at,
            CASE WHEN ce.status = 'completed' THEN 'complete'
                 WHEN ce.due_date < CURDATE() THEN 'overdue'
                 ELSE 'pending' END as compliance_flag,
            CASE WHEN ce.due_date IS NOT NULL AND ce.status != 'completed' THEN DATEDIFF(ce.due_date, CURDATE()) ELSE NULL END as days_remaining
            FROM course_enrollments ce JOIN courses c ON c.course_id = ce.course_id
            WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1 AND ce.is_mandatory = 1
            ORDER BY ce.due_date ASC";
        return $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
    }

    /** 19.3 My Compliance Calendar */
    public function myComplianceCalendar(): array
    {
        $sql = "SELECT c.title, ce.due_date, ce.status, ce.is_mandatory,
            DATEDIFF(ce.due_date, CURDATE()) as days_until
            FROM course_enrollments ce JOIN courses c ON c.course_id = ce.course_id
            WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
            AND ce.due_date IS NOT NULL AND ce.status != 'completed'
            ORDER BY ce.due_date ASC";
        return $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
    }

    /** 19.4 My Compliance History */
    public function myComplianceHistory(): array
    {
        $sql = "SELECT YEAR(ce.completed_at) as year,
            COUNT(*) as mandatory_completed,
            AVG(ce.final_score) as avg_score
            FROM course_enrollments ce
            WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
            AND ce.is_mandatory = 1 AND ce.status = 'completed' AND ce.completed_at IS NOT NULL
            GROUP BY YEAR(ce.completed_at) ORDER BY year DESC";
        $rows = $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
        foreach ($rows as &$r) $r['avg_score'] = round((float) $r['avg_score'], 1);
        return $rows;
    }

    // ==========================================
    // CATEGORY 20: MY LEARNING PATHS (4 reports)
    // ==========================================

    /** 20.1 My Learning Paths */
    public function myLearningPaths(): array
    {
        $sql = "SELECT lp.title as path_name, lpe.status, lpe.progress_percentage,
            lpe.enrolled_at, lpe.completed_at,
            (SELECT COUNT(*) FROM learning_path_items lpi WHERE lpi.path_id = lp.path_id) as total_items,
            (SELECT COUNT(*) FROM learning_path_item_progress lpip WHERE lpip.enrollment_id = lpe.enrollment_id AND lpip.status = 'completed') as items_done
            FROM learning_path_enrollments lpe
            JOIN learning_paths lp ON lp.path_id = lpe.path_id
            WHERE lpe.user_id = ? AND lpe.tenant_id = ? ORDER BY lpe.enrolled_at DESC";
        return $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
    }

    /** 20.2 My Learning Path Progress */
    public function myLearningPathProgress(): array
    {
        $pathId = (int) ($this->filters['path_id'] ?? 0);
        if (!$pathId) return ['error' => 'path_id required'];
        $sql = "SELECT lpi.sort_order, lpi.item_type, COALESCE(c.title, CONCAT('Item ', lpi.sort_order)) as item_title,
            COALESCE(lpip.status, 'not_started') as status, COALESCE(lpip.progress_percentage, 0) as progress,
            lpip.completed_at
            FROM learning_path_items lpi
            LEFT JOIN courses c ON c.course_id = lpi.course_id
            LEFT JOIN learning_path_enrollments lpe ON lpe.path_id = lpi.path_id AND lpe.user_id = ? AND lpe.tenant_id = ?
            LEFT JOIN learning_path_item_progress lpip ON lpip.item_id = lpi.item_id AND lpip.enrollment_id = lpe.enrollment_id
            WHERE lpi.path_id = ? AND lpi.tenant_id = ?
            ORDER BY lpi.sort_order";
        return $this->executeQuery($sql, 'iiii', [$this->user_id, $this->tenant_id, $pathId, $this->tenant_id]);
    }

    /** 20.3 My Recommended Paths */
    public function myRecommendedPaths(): array
    {
        $sql = "SELECT lp.path_id, lp.title, lp.description,
            (SELECT COUNT(*) FROM learning_path_items lpi WHERE lpi.path_id = lp.path_id) as total_items,
            (SELECT COUNT(DISTINCT lpe2.user_id) FROM learning_path_enrollments lpe2 WHERE lpe2.path_id = lp.path_id) as total_enrolled
            FROM learning_paths lp
            WHERE lp.tenant_id = ? AND lp.is_active = 1
            AND lp.path_id NOT IN (SELECT path_id FROM learning_path_enrollments WHERE user_id = ? AND tenant_id = ?)
            ORDER BY total_enrolled DESC LIMIT 5";
        return $this->executeQuery($sql, 'iii', [$this->tenant_id, $this->user_id, $this->tenant_id]);
    }

    /** 20.4 My Skill Gap Analysis */
    public function mySkillGapAnalysis(): array
    {
        // Compare mandatory training required vs completed by category
        $sql = "SELECT COALESCE(cat.name, 'General') as skill_area,
            SUM(CASE WHEN ce.is_mandatory = 1 THEN 1 ELSE 0 END) as required_courses,
            SUM(CASE WHEN ce.is_mandatory = 1 AND ce.status = 'completed' THEN 1 ELSE 0 END) as completed_courses,
            SUM(CASE WHEN ce.is_mandatory = 1 AND ce.status != 'completed' THEN 1 ELSE 0 END) as gap
            FROM course_enrollments ce
            JOIN courses c ON c.course_id = ce.course_id
            LEFT JOIN categories cat ON cat.category_id = c.category_id
            WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
            GROUP BY cat.name HAVING required_courses > 0
            ORDER BY gap DESC";
        return $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
    }

    // ==========================================
    // CATEGORY 21: MY TIME & ACTIVITY (4 reports)
    // ==========================================

    /** 21.1 My Learning Time */
    public function myLearningTime(): array
    {
        $sql = "SELECT SUM(time_spent_minutes) as total, AVG(time_spent_minutes) as avg_per_course,
            COUNT(DISTINCT course_id) as courses_touched
            FROM course_enrollments WHERE user_id = ? AND tenant_id = ? AND is_active = 1 AND time_spent_minutes > 0";
        $row = $this->executeOne($sql, 'ii', [$this->user_id, $this->tenant_id]);
        return [
            'total_hours' => round((int) $row['total'] / 60, 1),
            'total_minutes' => (int) $row['total'],
            'avg_per_course_formatted' => $this->formatMinutes((int) $row['avg_per_course']),
            'courses_touched' => (int) $row['courses_touched'],
        ];
    }

    /** 21.2 My Weekly Activity */
    public function myWeeklyActivity(): array
    {
        $sql = "SELECT DATE(COALESCE(ce.started_at, ce.enrolled_at)) as activity_date,
            COUNT(*) as activities, SUM(time_spent_minutes) as minutes
            FROM course_enrollments ce
            WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
            AND COALESCE(ce.started_at, ce.enrolled_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY activity_date ORDER BY activity_date";
        return $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
    }

    /** 21.3 My Login History */
    public function myLoginHistory(): array
    {
        if (!$this->tableExists('user_login_history')) return [];
        $sql = "SELECT login_at, logout_at, ip_address, device_type, browser,
            TIMESTAMPDIFF(MINUTE, login_at, COALESCE(logout_at, NOW())) as session_minutes
            FROM user_login_history WHERE user_id = ? AND tenant_id = ? ORDER BY login_at DESC LIMIT 50";
        return $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
    }

    /** 21.4 My Engagement Score */
    public function myEngagementScore(): array
    {
        $enroll = $this->executeOne("SELECT COUNT(*) as total, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed,
            AVG(progress_percentage) as avg_prog, SUM(time_spent_minutes) as total_time
            FROM course_enrollments WHERE user_id = ? AND tenant_id = ? AND is_active = 1", 'ii', [$this->user_id, $this->tenant_id]);
        $user = $this->executeOne("SELECT last_login FROM users WHERE id = ? AND tenant_id = ?", 'ii', [$this->user_id, $this->tenant_id]);
        $assess = $this->executeOne("SELECT COUNT(*) as cnt FROM proc_attempts WHERE user_id = ? AND tenant_id = ?", 'ii', [$this->user_id, $this->tenant_id]);

        $enrolled = max((int) $enroll['total'], 1);
        $daysSinceLogin = $user['last_login'] ? (int) ((time() - strtotime($user['last_login'])) / 86400) : 30;

        $compScore = min(((int) $enroll['completed'] / $enrolled) * 30, 30);
        $progScore = min(((float) $enroll['avg_prog'] / 100) * 25, 25);
        $timeScore = min(((int) $enroll['total_time'] / 600) * 20, 20);
        $loginScore = max(0, 15 - ($daysSinceLogin * 0.5));
        $assessScore = min(((int) $assess['cnt'] / 5) * 10, 10);
        $total = round($compScore + $progScore + $timeScore + $loginScore + $assessScore, 1);

        return ['engagement_score' => $total, 'level' => $total >= 70 ? 'high' : ($total >= 40 ? 'medium' : 'low'),
            'breakdown' => ['completion' => round($compScore, 1), 'progress' => round($progScore, 1), 'time' => round($timeScore, 1), 'login' => round($loginScore, 1), 'assessments' => round($assessScore, 1)]];
    }

    // ==========================================
    // CATEGORY 22: MY PERFORMANCE & INSIGHTS (4 reports)
    // ==========================================

    /** 22.1 My Performance Trend */
    public function myPerformanceTrend(): array
    {
        $sql = "SELECT DATE_FORMAT(ce.completed_at, '%Y-%m') as month_key,
            DATE_FORMAT(ce.completed_at, '%b %Y') as label,
            AVG(ce.final_score) as avg_score, COUNT(*) as completions
            FROM course_enrollments ce
            WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1 AND ce.status = 'completed' AND ce.completed_at IS NOT NULL
            GROUP BY month_key, label ORDER BY month_key";
        $rows = $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
        foreach ($rows as &$r) $r['avg_score'] = round((float) $r['avg_score'], 1);
        return $rows;
    }

    /** 22.2 My vs Peer Comparison */
    public function myVsPeerComparison(): array
    {
        $dept = $this->executeOne("SELECT department FROM users WHERE id = ? AND tenant_id = ?", 'ii', [$this->user_id, $this->tenant_id]);
        $deptId = $dept['department'] ?? null;

        $mySql = "SELECT AVG(final_score) as my_score, AVG(progress_percentage) as my_progress,
            SUM(time_spent_minutes) as my_time, COUNT(CASE WHEN status='completed' THEN 1 END) as my_completed
            FROM course_enrollments WHERE user_id = ? AND tenant_id = ? AND is_active = 1";
        $me = $this->executeOne($mySql, 'ii', [$this->user_id, $this->tenant_id]);

        $peerSql = "SELECT AVG(ce.final_score) as peer_score, AVG(ce.progress_percentage) as peer_progress,
            AVG(ce.time_spent_minutes) as peer_time
            FROM course_enrollments ce JOIN users u ON u.id = ce.user_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1 AND u.department = ? AND u.id != ?";
        $peer = $deptId ? $this->executeOne($peerSql, 'iii', [$this->tenant_id, $deptId, $this->user_id]) : [];

        return [
            'my_avg_score' => round((float) ($me['my_score'] ?? 0), 1),
            'peer_avg_score' => round((float) ($peer['peer_score'] ?? 0), 1),
            'my_avg_progress' => round((float) ($me['my_progress'] ?? 0), 1),
            'peer_avg_progress' => round((float) ($peer['peer_progress'] ?? 0), 1),
            'my_total_hours' => round((int) ($me['my_time'] ?? 0) / 60, 1),
            'peer_avg_hours' => round((float) ($peer['peer_time'] ?? 0) / 60, 1),
            'my_completed' => (int) ($me['my_completed'] ?? 0),
        ];
    }

    /** 22.3 My Learning Velocity */
    public function myLearningVelocity(): array
    {
        $sql = "SELECT AVG(DATEDIFF(completed_at, enrolled_at)) as my_avg_days
            FROM course_enrollments WHERE user_id = ? AND tenant_id = ? AND is_active = 1 AND status = 'completed'";
        $me = $this->executeOne($sql, 'ii', [$this->user_id, $this->tenant_id]);

        $peerSql = "SELECT AVG(DATEDIFF(ce.completed_at, ce.enrolled_at)) as peer_avg_days
            FROM course_enrollments ce JOIN users u ON u.id = ce.user_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.status = 'completed'
            AND u.department = (SELECT department FROM users WHERE id = ? AND tenant_id = ?)";
        $peer = $this->executeOne($peerSql, 'iii', [$this->tenant_id, $this->user_id, $this->tenant_id]);

        return [
            'my_avg_days' => round((float) ($me['my_avg_days'] ?? 0), 1),
            'peer_avg_days' => round((float) ($peer['peer_avg_days'] ?? 0), 1),
            'faster_than_peers' => ((float) ($me['my_avg_days'] ?? 0)) < ((float) ($peer['peer_avg_days'] ?? 0)),
        ];
    }

    /** 22.4 My Improvement Areas */
    public function myImprovementAreas(): array
    {
        // Low-score categories + overdue
        $sql = "SELECT COALESCE(cat.name, 'General') as area,
            AVG(ce.final_score) as avg_score,
            COUNT(*) as courses,
            'Improve assessment scores' as suggestion
            FROM course_enrollments ce
            JOIN courses c ON c.course_id = ce.course_id
            LEFT JOIN categories cat ON cat.category_id = c.category_id
            WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1 AND ce.status = 'completed'
            GROUP BY cat.name HAVING avg_score < 70
            ORDER BY avg_score ASC";
        $low = $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);

        $overdue = $this->executeOne("SELECT COUNT(*) as cnt FROM course_enrollments
            WHERE user_id = ? AND tenant_id = ? AND is_active = 1 AND due_date < CURDATE() AND status != 'completed'",
            'ii', [$this->user_id, $this->tenant_id]);

        return ['low_score_areas' => $low, 'overdue_count' => (int) $overdue['cnt']];
    }

    // ==========================================
    // CATEGORY 23: MY EXPORTS & SHARING (3 reports)
    // ==========================================

    /** 23.1 My Training Transcript */
    public function myTrainingTranscript(): array
    {
        $learner = $this->executeOne("SELECT CONCAT(first_name,' ',last_name) as name, email, created_at FROM users WHERE id = ? AND tenant_id = ?", 'ii', [$this->user_id, $this->tenant_id]);
        $courses = $this->executeQuery(
            "SELECT c.title, ce.status, ce.final_score, ce.enrolled_at, ce.completed_at, ce.time_spent_minutes, ce.is_mandatory
            FROM course_enrollments ce JOIN courses c ON c.course_id = ce.course_id
            WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1 ORDER BY ce.enrolled_at",
            'ii', [$this->user_id, $this->tenant_id]);
        $certs = $this->executeQuery(
            "SELECT c.title, cert.certificate_number, cert.issue_date, cert.expiry_date
            FROM certificates cert JOIN courses c ON c.course_id = cert.course_id
            WHERE cert.user_id = ? AND cert.tenant_id = ? ORDER BY cert.issue_date",
            'ii', [$this->user_id, $this->tenant_id]);
        return ['learner' => $learner, 'courses' => $courses, 'certificates' => $certs, 'generated_at' => date('Y-m-d H:i:s')];
    }

    /** 23.2 My Learning Portfolio */
    public function myLearningPortfolio(): array
    {
        return [
            'summary' => $this->myLearningSummary(),
            'completed_courses' => $this->myCompletedCourses(),
            'certificates' => $this->myCertificates(),
            'badges' => $this->myBadges(),
            'skills' => $this->mySkills(),
        ];
    }

    /** 23.3 My Compliance Certificate Pack */
    public function myComplianceCertPack(): array
    {
        $sql = "SELECT cert.certificate_id, cert.certificate_number, cert.issue_date, cert.expiry_date,
            c.title as course_title
            FROM certificates cert
            JOIN courses c ON c.course_id = cert.course_id
            JOIN course_enrollments ce ON ce.course_id = cert.course_id AND ce.user_id = cert.user_id AND ce.tenant_id = cert.tenant_id
            WHERE cert.user_id = ? AND cert.tenant_id = ? AND ce.is_mandatory = 1
            ORDER BY cert.issue_date DESC";
        return $this->executeQuery($sql, 'ii', [$this->user_id, $this->tenant_id]);
    }

    public function getExportColumns(string $report): array
    {
        return match ($report) {
            'my_enrolled_courses', 'my_completed_courses' => [
                ['key' => 'title', 'label' => 'Course'], ['key' => 'status', 'label' => 'Status'],
                ['key' => 'progress_percentage', 'label' => 'Progress'], ['key' => 'final_score', 'label' => 'Score'],
                ['key' => 'enrolled_at', 'label' => 'Enrolled'], ['key' => 'completed_at', 'label' => 'Completed'],
            ],
            'my_training_transcript' => [
                ['key' => 'title', 'label' => 'Course'], ['key' => 'status', 'label' => 'Status'],
                ['key' => 'final_score', 'label' => 'Score'], ['key' => 'enrolled_at', 'label' => 'Enrolled'],
                ['key' => 'completed_at', 'label' => 'Completed'], ['key' => 'is_mandatory', 'label' => 'Mandatory'],
            ],
            default => []
        };
    }
}
