<?php
require_once __DIR__ . '/BaseReport.php';

/**
 * ILT / Live Training Reports
 * Instructor-led training attendance, no-shows, session utilization, and instructor effectiveness
 */
class ILTReports extends BaseReport
{

    public function generate(): array
    {
        return $this->sessionAttendanceReport();
    }

    /**
     * Report 1: Session Attendance Report
     */
    public function sessionAttendanceReport(): array
    {
        $dateFilter = $this->buildDateFilter('ts.session_date');

        $query = "SELECT ts.session_id, c.title as course_title,
                    ts.session_date, ts.start_time, ts.end_time,
                    CONCAT(i.first_name, ' ', i.last_name) as instructor_name,
                    COALESCE(v.name, ts.virtual_link) as venue,
                    ts.capacity,
                    COUNT(tsa.attendance_id) as total_registered,
                    SUM(CASE WHEN tsa.status = 'present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN tsa.status = 'absent' THEN 1 ELSE 0 END) as absent,
                    SUM(CASE WHEN tsa.status = 'late' THEN 1 ELSE 0 END) as late,
                    ROUND(SUM(CASE WHEN tsa.status IN ('present','late') THEN 1 ELSE 0 END) / NULLIF(COUNT(tsa.attendance_id), 0) * 100, 1) as attendance_rate
                  FROM training_sessions ts
                  JOIN courses c ON ts.course_id = c.course_id
                  LEFT JOIN users i ON ts.instructor_id = i.user_id
                  LEFT JOIN venues v ON ts.venue_id = v.venue_id
                  LEFT JOIN training_session_attendance tsa ON ts.session_id = tsa.session_id
                  WHERE ts.tenant_id = ? {$dateFilter}
                  GROUP BY ts.session_id, c.title, ts.session_date, ts.start_time, ts.end_time,
                           instructor_name, venue, ts.capacity
                  ORDER BY ts.session_date DESC";

        $data = $this->executeQuery($query, [$this->tenant_id]);

        $totalPresent = array_sum(array_column($data, 'present'));
        $totalRegistered = array_sum(array_column($data, 'total_registered'));

        return [
            'report_name' => 'Session Attendance Report',
            'data' => $this->paginate($data),
            'meta' => $this->getMeta($data),
            'summary' => [
                'total_sessions' => count($data),
                'total_registered' => $totalRegistered,
                'total_present' => $totalPresent,
                'overall_attendance_rate' => $totalRegistered > 0 ? round(($totalPresent / $totalRegistered) * 100, 1) : 0,
                'total_absent' => array_sum(array_column($data, 'absent'))
            ]
        ];
    }

    /**
     * Report 2: No-Show Report
     */
    public function noShowReport(): array
    {
        $dateFilter = $this->buildDateFilter('ts.session_date');

        $query = "SELECT u.user_id, CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, d.name as department_name,
                    c.title as course_title, ts.session_date,
                    CONCAT(i.first_name, ' ', i.last_name) as instructor_name,
                    tsa.notes as absence_reason,
                    (SELECT COUNT(*) FROM training_session_attendance 
                     WHERE user_id = u.user_id AND status = 'absent' AND tenant_id = ?) as total_no_shows
                  FROM training_session_attendance tsa
                  JOIN users u ON tsa.user_id = u.user_id
                  JOIN training_sessions ts ON tsa.session_id = ts.session_id
                  JOIN courses c ON ts.course_id = c.course_id
                  LEFT JOIN users i ON ts.instructor_id = i.user_id
                  LEFT JOIN departments d ON u.department_id = d.department_id
                  WHERE tsa.status = 'absent' AND tsa.tenant_id = ? {$dateFilter}
                  ORDER BY ts.session_date DESC, learner_name";

        $data = $this->executeQuery($query, [$this->tenant_id, $this->tenant_id]);

        return [
            'report_name' => 'No-Show Report',
            'data' => $this->paginate($data),
            'meta' => $this->getMeta($data),
            'summary' => [
                'total_no_shows' => count($data),
                'unique_learners' => count(array_unique(array_column($data, 'user_id'))),
                'repeat_offenders' => count(array_filter($data, fn($r) => (int)$r['total_no_shows'] >= 3))
            ]
        ];
    }

    /**
     * Report 3: Session Utilization Report
     */
    public function sessionUtilizationReport(): array
    {
        $dateFilter = $this->buildDateFilter('ts.session_date');

        $query = "SELECT ts.session_id, c.title as course_title,
                    ts.session_date, ts.capacity,
                    COUNT(tsa.attendance_id) as registered,
                    SUM(CASE WHEN tsa.status IN ('present','late') THEN 1 ELSE 0 END) as attended,
                    ROUND(COUNT(tsa.attendance_id) / NULLIF(ts.capacity, 0) * 100, 1) as fill_rate,
                    ROUND(SUM(CASE WHEN tsa.status IN ('present','late') THEN 1 ELSE 0 END) / NULLIF(ts.capacity, 0) * 100, 1) as utilization_rate,
                    ts.capacity - COUNT(tsa.attendance_id) as available_spots,
                    COALESCE(v.name, 'Virtual') as venue
                  FROM training_sessions ts
                  JOIN courses c ON ts.course_id = c.course_id
                  LEFT JOIN venues v ON ts.venue_id = v.venue_id
                  LEFT JOIN training_session_attendance tsa ON ts.session_id = tsa.session_id
                  WHERE ts.tenant_id = ? {$dateFilter}
                  GROUP BY ts.session_id, c.title, ts.session_date, ts.capacity, venue
                  ORDER BY fill_rate ASC";

        $data = $this->executeQuery($query, [$this->tenant_id]);

        return [
            'report_name' => 'Session Utilization Report',
            'data' => $this->paginate($data),
            'meta' => $this->getMeta($data),
            'summary' => [
                'total_sessions' => count($data),
                'avg_fill_rate' => count($data) > 0 ? round(array_sum(array_column($data, 'fill_rate')) / count($data), 1) : 0,
                'avg_utilization_rate' => count($data) > 0 ? round(array_sum(array_column($data, 'utilization_rate')) / count($data), 1) : 0,
                'total_wasted_spots' => array_sum(array_column($data, 'available_spots'))
            ]
        ];
    }

    /**
     * Report 4: Instructor Effectiveness
     */
    public function instructorEffectiveness(): array
    {
        $dateFilter = $this->buildDateFilter('ts.session_date');

        $query = "SELECT i.user_id as instructor_id,
                    CONCAT(i.first_name, ' ', i.last_name) as instructor_name,
                    i.email,
                    COUNT(DISTINCT ts.session_id) as total_sessions,
                    COUNT(DISTINCT tsa.user_id) as total_learners,
                    ROUND(AVG(CASE WHEN tsa.status IN ('present','late') THEN 1 ELSE 0 END) * 100, 1) as avg_attendance_rate,
                    ROUND(AVG(sf.rating), 1) as avg_rating,
                    COUNT(sf.feedback_id) as total_feedback,
                    ROUND(AVG(aa.score), 1) as avg_learner_score
                  FROM training_sessions ts
                  JOIN users i ON ts.instructor_id = i.user_id
                  LEFT JOIN training_session_attendance tsa ON ts.session_id = tsa.session_id
                  LEFT JOIN session_feedback sf ON ts.session_id = sf.session_id
                  LEFT JOIN assessment_attempts aa ON aa.course_id = ts.course_id AND aa.user_id = tsa.user_id
                  WHERE ts.tenant_id = ? {$dateFilter}
                  GROUP BY i.user_id, i.first_name, i.last_name, i.email
                  ORDER BY avg_rating DESC";

        $data = $this->executeQuery($query, [$this->tenant_id]);

        return [
            'report_name' => 'Instructor Effectiveness',
            'data' => $this->paginate($data),
            'meta' => $this->getMeta($data),
            'summary' => [
                'total_instructors' => count($data),
                'avg_rating' => count($data) > 0 ? round(array_sum(array_column($data, 'avg_rating')) / count($data), 1) : 0,
                'avg_attendance_rate' => count($data) > 0 ? round(array_sum(array_column($data, 'avg_attendance_rate')) / count($data), 1) : 0
            ]
        ];
    }

    /**
     * Report 5: ILT Cost Report
     */
    public function iltCostReport(): array
    {
        $dateFilter = $this->buildDateFilter('ts.session_date');

        $query = "SELECT ts.session_id, c.title as course_title,
                    ts.session_date,
                    CONCAT(i.first_name, ' ', i.last_name) as instructor_name,
                    COALESCE(v.name, 'Virtual') as venue,
                    ts.instructor_cost, ts.venue_cost, ts.material_cost,
                    (ts.instructor_cost + ts.venue_cost + ts.material_cost) as total_cost,
                    COUNT(DISTINCT tsa.user_id) as attendees,
                    CASE WHEN COUNT(DISTINCT tsa.user_id) > 0
                        THEN ROUND((ts.instructor_cost + ts.venue_cost + ts.material_cost) / COUNT(DISTINCT tsa.user_id), 2)
                        ELSE 0 END as cost_per_learner
                  FROM training_sessions ts
                  JOIN courses c ON ts.course_id = c.course_id
                  LEFT JOIN users i ON ts.instructor_id = i.user_id
                  LEFT JOIN venues v ON ts.venue_id = v.venue_id
                  LEFT JOIN training_session_attendance tsa ON ts.session_id = tsa.session_id AND tsa.status IN ('present','late')
                  WHERE ts.tenant_id = ? {$dateFilter}
                  GROUP BY ts.session_id, c.title, ts.session_date, instructor_name, venue,
                           ts.instructor_cost, ts.venue_cost, ts.material_cost
                  ORDER BY total_cost DESC";

        $data = $this->executeQuery($query, [$this->tenant_id]);

        return [
            'report_name' => 'ILT Cost Report',
            'data' => $this->paginate($data),
            'meta' => $this->getMeta($data),
            'summary' => [
                'total_cost' => array_sum(array_column($data, 'total_cost')),
                'total_instructor_cost' => array_sum(array_column($data, 'instructor_cost')),
                'total_venue_cost' => array_sum(array_column($data, 'venue_cost')),
                'avg_cost_per_learner' => count($data) > 0 ? round(array_sum(array_column($data, 'cost_per_learner')) / count($data), 2) : 0,
                'total_sessions' => count($data)
            ]
        ];
    }

    /**
     * Report 6: Venue Utilization Report
     */
    public function venueUtilizationReport(): array
    {
        $dateFilter = $this->buildDateFilter('ts.session_date');

        $query = "SELECT v.venue_id, v.name as venue_name, v.location, v.capacity as max_capacity,
                    v.venue_type,
                    COUNT(ts.session_id) as total_sessions,
                    SUM(TIMESTAMPDIFF(HOUR, ts.start_time, ts.end_time)) as total_hours_used,
                    ROUND(AVG(sub.attendees / NULLIF(v.capacity, 0)) * 100, 1) as avg_utilization_rate,
                    MAX(ts.session_date) as last_used
                  FROM venues v
                  LEFT JOIN training_sessions ts ON v.venue_id = ts.venue_id AND ts.tenant_id = ? {$dateFilter}
                  LEFT JOIN (
                      SELECT session_id, COUNT(*) as attendees
                      FROM training_session_attendance WHERE status IN ('present','late')
                      GROUP BY session_id
                  ) sub ON ts.session_id = sub.session_id
                  WHERE v.tenant_id = ?
                  GROUP BY v.venue_id, v.name, v.location, v.capacity, v.venue_type
                  ORDER BY total_sessions DESC";

        $data = $this->executeQuery($query, [$this->tenant_id, $this->tenant_id]);

        return [
            'report_name' => 'Venue Utilization Report',
            'data' => $this->paginate($data),
            'meta' => $this->getMeta($data),
            'summary' => [
                'total_venues' => count($data),
                'total_sessions' => array_sum(array_column($data, 'total_sessions')),
                'avg_utilization_rate' => count($data) > 0 ? round(array_sum(array_column($data, 'avg_utilization_rate')) / count($data), 1) : 0
            ]
        ];
    }
}
