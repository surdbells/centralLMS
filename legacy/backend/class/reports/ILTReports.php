<?php
require_once __DIR__ . '/BaseReport.php';

/**
 * ILT (Instructor-Led Training) Reports
 * Reports for tracking session attendance, no-shows, utilisation, instructor
 * effectiveness, costs, and venue usage.
 *
 * PHASE 5 — Requires: training_sessions, training_session_attendance, venues tables
 *
 * Methods:
 *  1. sessionAttendanceReport()   – Attendance tracking for all ILT sessions
 *  2. noShowReport()              – Learners who enrolled but didn't attend
 *  3. sessionUtilizationReport()  – Session capacity utilisation
 *  4. instructorEffectiveness()   – Instructor performance metrics
 *  5. iltCostReport()             – Cost tracking per session
 *  6. venueUtilizationReport()    – Venue usage and availability
 */
class ILTReports extends BaseReport
{
    public function generate(): array
    {
        return $this->sessionAttendanceReport();
    }

    /* ──────────────────────────────────────────────
     * 1. Session Attendance Report
     * ────────────────────────────────────────────── */
    public function sessionAttendanceReport(): array
    {
        $dateFilter = $this->buildDateFilter('ts.start_datetime');
        $orgFilter  = $this->buildOrgFilter();

        $sessionFilter    = '';
        $instructorFilter = '';
        $types  = "i";
        $params = [$this->tenant_id];

        if (!empty($this->filters['session_id'])) {
            $sessionFilter = " AND ts.session_id = ?";
            $types .= "i";
            $params[] = (int)$this->filters['session_id'];
        }
        if (!empty($this->filters['instructor_id'])) {
            $instructorFilter = " AND ts.instructor_id = ?";
            $types .= "i";
            $params[] = (int)$this->filters['instructor_id'];
        }

        $query = "SELECT ts.session_id, ts.session_name,
                    c.title AS course_title,
                    CONCAT(instr.firstname, ' ', instr.lastname) AS instructor_name,
                    COALESCE(v.venue_name, 'Virtual') AS venue,
                    ts.start_datetime, ts.end_datetime, ts.capacity,
                    COUNT(CASE WHEN tsa.enrollment_status = 'enrolled' THEN 1 END) AS enrolled,
                    COUNT(CASE WHEN tsa.attendance_status = 'attended' THEN 1 END) AS attended,
                    COUNT(CASE WHEN tsa.attendance_status = 'no_show'  THEN 1 END) AS no_show,
                    COUNT(CASE WHEN tsa.attendance_status = 'excused'  THEN 1 END) AS excused
                  FROM training_sessions ts
                  JOIN courses c ON ts.course_id = c.course_id
                  LEFT JOIN users instr ON ts.instructor_id = instr.id
                  LEFT JOIN venues v ON ts.venue_id = v.venue_id
                  LEFT JOIN training_session_attendance tsa ON ts.session_id = tsa.session_id
                  LEFT JOIN users u ON tsa.user_id = u.id
                  WHERE ts.tenant_id = ? AND ts.is_active = 1
                  $sessionFilter $instructorFilter $dateFilter
                  {$orgFilter}
                  GROUP BY ts.session_id, ts.session_name, c.title,
                           instructor_name, venue, ts.start_datetime, ts.end_datetime, ts.capacity
                  ORDER BY ts.start_datetime DESC
                  LIMIT ? OFFSET ?";

        $offset = $this->getOffset();
        $types .= "ii";
        $params[] = $this->per_page;
        $params[] = $offset;

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $enrolled = (int)$row['enrolled'];
            $attended = (int)$row['attended'];
            $data[] = [
                'session_id'      => (int)$row['session_id'],
                'session_name'    => $row['session_name'],
                'course_title'    => $row['course_title'],
                'instructor_name' => $row['instructor_name'] ?? 'Unassigned',
                'venue'           => $row['venue'],
                'start_datetime'  => $row['start_datetime'],
                'end_datetime'    => $row['end_datetime'],
                'capacity'        => (int)$row['capacity'],
                'enrolled'        => $enrolled,
                'attended'        => $attended,
                'no_show'         => (int)$row['no_show'],
                'excused'         => (int)$row['excused'],
                'attendance_rate' => $enrolled > 0 ? round(($attended / $enrolled) * 100, 1) : 0
            ];
        }

        $countQuery = "SELECT COUNT(DISTINCT ts.session_id) AS total
                       FROM training_sessions ts
                       WHERE ts.tenant_id = ? AND ts.is_active = 1 $sessionFilter $instructorFilter";
        $countTypes  = "i";
        $countParams = [$this->tenant_id];
        if (!empty($this->filters['session_id'])) {
            $countTypes  .= "i";
            $countParams[] = (int)$this->filters['session_id'];
        }
        if (!empty($this->filters['instructor_id'])) {
            $countTypes  .= "i";
            $countParams[] = (int)$this->filters['instructor_id'];
        }
        $total = $this->getTotalCount($countQuery, $countTypes, $countParams);

        return [
            'report_name' => 'Session Attendance Report',
            'data' => $data,
            'meta' => $this->buildMeta($total)
        ];
    }

    /* ──────────────────────────────────────────────
     * 2. No-Show Report
     * ────────────────────────────────────────────── */
    public function noShowReport(): array
    {
        $dateFilter = $this->buildDateFilter('ts.start_datetime');
        $orgFilter  = $this->buildOrgFilter();

        $query = "SELECT u.id AS user_id,
                    CONCAT(u.firstname, ' ', u.lastname) AS learner_name,
                    u.email,
                    d.name AS department,
                    ts.session_name, c.title AS course_title,
                    ts.start_datetime AS scheduled_date,
                    tsa.enrolled_at AS enrollment_date,
                    -- total no-shows for this learner across all sessions
                    (SELECT COUNT(*) FROM training_session_attendance tsa2
                     WHERE tsa2.user_id = u.id AND tsa2.attendance_status = 'no_show'
                       AND tsa2.tenant_id = ts.tenant_id) AS no_show_count_total
                  FROM training_session_attendance tsa
                  JOIN training_sessions ts ON tsa.session_id = ts.session_id
                  JOIN courses c ON ts.course_id = c.course_id
                  JOIN users u ON tsa.user_id = u.id
                  LEFT JOIN departments d ON u.department_id = d.department_id
                  WHERE tsa.attendance_status = 'no_show'
                    AND tsa.tenant_id = ? AND ts.is_active = 1 $dateFilter
                  {$orgFilter}
                  ORDER BY no_show_count_total DESC, ts.start_datetime DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'user_id'              => (int)$row['user_id'],
                'learner_name'         => $row['learner_name'],
                'email'                => $row['email'],
                'department'           => $row['department'] ?? 'Unassigned',
                'session_name'         => $row['session_name'],
                'course_title'         => $row['course_title'],
                'scheduled_date'       => $row['scheduled_date'],
                'enrollment_date'      => $row['enrollment_date'],
                'no_show_count_total'  => (int)$row['no_show_count_total']
            ];
        }

        $countQuery = "SELECT COUNT(*) AS total
                       FROM live_training_attendance tsa
                       JOIN live_training_sessions ts ON tsa.session_id = ts.session_id
                       WHERE tsa.status = 'absent'
                         AND tsa.tenant_id = ? AND ts.is_active = 1";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'No-Show Report',
            'data' => $data,
            'meta' => $this->buildMeta($total)
        ];
    }

    /* ──────────────────────────────────────────────
     * 3. Session Utilization Report
     * ────────────────────────────────────────────── */
    public function sessionUtilizationReport(): array
    {
        $dateFilter  = $this->buildDateFilter('ts.start_datetime');
        $venueFilter = '';
        $types  = "i";
        $params = [$this->tenant_id];

        if (!empty($this->filters['venue_id'])) {
            $venueFilter = " AND ts.venue_id = ?";
            $types .= "i";
            $params[] = (int)$this->filters['venue_id'];
        }

        $query = "SELECT ts.session_id, ts.session_name,
                    c.title AS course_title,
                    COALESCE(v.venue_name, 'Virtual') AS venue,
                    ts.capacity,
                    ts.start_datetime, ts.end_datetime,
                    COUNT(CASE WHEN tsa.enrollment_status = 'enrolled' THEN 1 END) AS enrolled,
                    COUNT(CASE WHEN tsa.enrollment_status = 'waitlist' THEN 1 END) AS waitlist
                  FROM training_sessions ts
                  JOIN courses c ON ts.course_id = c.course_id
                  LEFT JOIN venues v ON ts.venue_id = v.venue_id
                  LEFT JOIN training_session_attendance tsa ON ts.session_id = tsa.session_id
                  WHERE ts.tenant_id = ? AND ts.is_active = 1
                  $venueFilter $dateFilter
                  GROUP BY ts.session_id, ts.session_name, c.title, venue,
                           ts.capacity, ts.start_datetime, ts.end_datetime
                  ORDER BY ts.start_datetime DESC
                  LIMIT ? OFFSET ?";

        $offset = $this->getOffset();
        $types .= "ii";
        $params[] = $this->per_page;
        $params[] = $offset;

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $capacity = (int)$row['capacity'];
            $enrolled = (int)$row['enrolled'];
            $data[] = [
                'session_id'       => (int)$row['session_id'],
                'session_name'     => $row['session_name'],
                'course_title'     => $row['course_title'],
                'venue'            => $row['venue'],
                'start_datetime'   => $row['start_datetime'],
                'end_datetime'     => $row['end_datetime'],
                'capacity'         => $capacity,
                'enrolled'         => $enrolled,
                'waitlist'         => (int)$row['waitlist'],
                'utilization_rate' => $capacity > 0 ? round(($enrolled / $capacity) * 100, 1) : 0,
                'available_seats'  => max(0, $capacity - $enrolled)
            ];
        }

        $countQuery = "SELECT COUNT(DISTINCT ts.session_id) AS total
                       FROM training_sessions ts
                       WHERE ts.tenant_id = ? AND ts.is_active = 1 $venueFilter";
        $countTypes  = "i";
        $countParams = [$this->tenant_id];
        if (!empty($this->filters['venue_id'])) {
            $countTypes  .= "i";
            $countParams[] = (int)$this->filters['venue_id'];
        }
        $total = $this->getTotalCount($countQuery, $countTypes, $countParams);

        return [
            'report_name' => 'Session Utilization Report',
            'data' => $data,
            'meta' => $this->buildMeta($total)
        ];
    }

    /* ──────────────────────────────────────────────
     * 4. Instructor Effectiveness
     * ────────────────────────────────────────────── */
    public function instructorEffectiveness(): array
    {
        $dateFilter       = $this->buildDateFilter('ts.start_datetime');
        $instructorFilter = '';
        $types  = "i";
        $params = [$this->tenant_id];

        if (!empty($this->filters['instructor_id'])) {
            $instructorFilter = " AND ts.instructor_id = ?";
            $types .= "i";
            $params[] = (int)$this->filters['instructor_id'];
        }

        $query = "SELECT ts.instructor_id,
                    CONCAT(instr.firstname, ' ', instr.lastname) AS instructor_name,
                    instr.email AS instructor_email,
                    COUNT(DISTINCT ts.session_id) AS sessions_delivered,
                    COUNT(CASE WHEN tsa.enrollment_status = 'enrolled' THEN 1 END) AS total_learners,
                    COUNT(CASE WHEN tsa.attendance_status = 'attended' THEN 1 END) AS total_attended,
                    COUNT(CASE WHEN tsa.attendance_status = 'no_show'  THEN 1 END) AS total_no_shows,
                    -- avg hours per session
                    ROUND(AVG(TIMESTAMPDIFF(MINUTE, ts.start_datetime, ts.end_datetime)) / 60, 1) AS avg_session_hours
                  FROM training_sessions ts
                  JOIN users instr ON ts.instructor_id = instr.id
                  LEFT JOIN training_session_attendance tsa ON ts.session_id = tsa.session_id
                  WHERE ts.tenant_id = ? AND ts.is_active = 1 AND ts.instructor_id IS NOT NULL
                  $instructorFilter $dateFilter
                  GROUP BY ts.instructor_id, instructor_name, instructor_email
                  ORDER BY sessions_delivered DESC
                  LIMIT ? OFFSET ?";

        $offset = $this->getOffset();
        $types .= "ii";
        $params[] = $this->per_page;
        $params[] = $offset;

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $totalLearners = (int)$row['total_learners'];
            $totalAttended = (int)$row['total_attended'];
            $data[] = [
                'instructor_id'    => (int)$row['instructor_id'],
                'instructor_name'  => $row['instructor_name'],
                'instructor_email' => $row['instructor_email'],
                'sessions_delivered'=> (int)$row['sessions_delivered'],
                'total_learners'   => $totalLearners,
                'total_attended'   => $totalAttended,
                'total_no_shows'   => (int)$row['total_no_shows'],
                'avg_attendance_rate' => $totalLearners > 0
                    ? round(($totalAttended / $totalLearners) * 100, 1) : 0,
                'avg_session_hours'=> (float)($row['avg_session_hours'] ?? 0)
            ];
        }

        $countQuery = "SELECT COUNT(DISTINCT ts.instructor_id) AS total
                       FROM training_sessions ts
                       WHERE ts.tenant_id = ? AND ts.is_active = 1
                         AND ts.instructor_id IS NOT NULL $instructorFilter";
        $countTypes  = "i";
        $countParams = [$this->tenant_id];
        if (!empty($this->filters['instructor_id'])) {
            $countTypes  .= "i";
            $countParams[] = (int)$this->filters['instructor_id'];
        }
        $total = $this->getTotalCount($countQuery, $countTypes, $countParams);

        return [
            'report_name' => 'Instructor Effectiveness Report',
            'data' => $data,
            'meta' => $this->buildMeta($total)
        ];
    }

    /* ──────────────────────────────────────────────
     * 5. ILT Cost Report
     * ────────────────────────────────────────────── */
    public function iltCostReport(): array
    {
        $dateFilter = $this->buildDateFilter('ts.start_datetime');

        $query = "SELECT ts.session_id, ts.session_name,
                    c.title AS course_title,
                    CONCAT(instr.firstname, ' ', instr.lastname) AS instructor_name,
                    COALESCE(v.venue_name, 'Virtual') AS venue,
                    ts.start_datetime,
                    ts.venue_cost, ts.instructor_cost, ts.materials_cost,
                    (ts.venue_cost + ts.instructor_cost + ts.materials_cost) AS total_cost,
                    COUNT(CASE WHEN tsa.attendance_status = 'attended' THEN 1 END) AS attended_count
                  FROM training_sessions ts
                  JOIN courses c ON ts.course_id = c.course_id
                  LEFT JOIN users instr ON ts.instructor_id = instr.id
                  LEFT JOIN venues v ON ts.venue_id = v.venue_id
                  LEFT JOIN training_session_attendance tsa ON ts.session_id = tsa.session_id
                  WHERE ts.tenant_id = ? AND ts.is_active = 1 $dateFilter
                  GROUP BY ts.session_id, ts.session_name, c.title,
                           instructor_name, venue, ts.start_datetime,
                           ts.venue_cost, ts.instructor_cost, ts.materials_cost
                  ORDER BY total_cost DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $totalCost = (float)$row['total_cost'];
            $attended  = (int)$row['attended_count'];
            $data[] = [
                'session_id'       => (int)$row['session_id'],
                'session_name'     => $row['session_name'],
                'course_title'     => $row['course_title'],
                'instructor_name'  => $row['instructor_name'] ?? 'Unassigned',
                'venue'            => $row['venue'],
                'start_datetime'   => $row['start_datetime'],
                'venue_cost'       => (float)$row['venue_cost'],
                'instructor_cost'  => (float)$row['instructor_cost'],
                'materials_cost'   => (float)$row['materials_cost'],
                'total_cost'       => $totalCost,
                'attended_count'   => $attended,
                'cost_per_learner' => $attended > 0 ? round($totalCost / $attended, 2) : 0
            ];
        }

        $countQuery = "SELECT COUNT(DISTINCT ts.session_id) AS total
                       FROM live_training_sessions ts
                       WHERE ts.tenant_id = ? AND ts.is_active = 1";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        // Summary totals
        $summaryQuery = "SELECT
                    SUM(ts.venue_cost) AS total_venue_cost,
                    SUM(ts.instructor_cost) AS total_instructor_cost,
                    SUM(ts.materials_cost) AS total_materials_cost,
                    SUM(ts.venue_cost + ts.instructor_cost + ts.materials_cost) AS grand_total
                  FROM live_training_sessions ts
                  WHERE ts.tenant_id = ? AND ts.is_active = 1";
        $sumStmt = $this->conn->prepare($summaryQuery);
        $sumStmt->bind_param("i", $this->tenant_id);
        $sumStmt->execute();
        $summary = $sumStmt->get_result()->fetch_assoc();

        return [
            'report_name' => 'ILT Cost Report',
            'data' => $data,
            'meta' => $this->buildMeta($total),
            'summary' => [
                'total_venue_cost'      => (float)($summary['total_venue_cost'] ?? 0),
                'total_instructor_cost' => (float)($summary['total_instructor_cost'] ?? 0),
                'total_materials_cost'  => (float)($summary['total_materials_cost'] ?? 0),
                'grand_total'           => (float)($summary['grand_total'] ?? 0)
            ]
        ];
    }

    /* ──────────────────────────────────────────────
     * 6. Venue Utilization Report
     * ────────────────────────────────────────────── */
    public function venueUtilizationReport(): array
    {
        $dateFilter = $this->buildDateFilter('ts.start_datetime');

        $query = "SELECT v.venue_id, v.venue_name, v.location, v.capacity AS venue_capacity,
                    COUNT(DISTINCT ts.session_id) AS sessions_held,
                    SUM(CASE WHEN tsa.attendance_status = 'attended' THEN 1 ELSE 0 END) AS total_learners,
                    ROUND(AVG(
                        CASE WHEN v.capacity > 0 THEN
                            (SELECT COUNT(*) FROM training_session_attendance tsa2
                             WHERE tsa2.session_id = ts.session_id
                               AND tsa2.enrollment_status = 'enrolled') / v.capacity * 100
                        END
                    ), 1) AS avg_utilization_rate,
                    MIN(ts.start_datetime) AS first_session,
                    MAX(ts.start_datetime) AS last_session,
                    -- total hours used
                    ROUND(SUM(TIMESTAMPDIFF(MINUTE, ts.start_datetime, ts.end_datetime)) / 60, 1) AS total_hours_used
                  FROM venues v
                  LEFT JOIN training_sessions ts
                       ON v.venue_id = ts.venue_id AND ts.is_active = 1 AND ts.tenant_id = v.tenant_id
                  LEFT JOIN training_session_attendance tsa ON ts.session_id = tsa.session_id
                  WHERE v.tenant_id = ? AND v.is_active = 1 $dateFilter
                  GROUP BY v.venue_id, v.venue_name, v.location, v.capacity
                  ORDER BY sessions_held DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'venue_id'             => (int)$row['venue_id'],
                'venue_name'           => $row['venue_name'],
                'location'             => $row['location'] ?? '',
                'venue_capacity'       => (int)$row['venue_capacity'],
                'sessions_held'        => (int)$row['sessions_held'],
                'total_learners'       => (int)$row['total_learners'],
                'avg_utilization_rate' => (float)($row['avg_utilization_rate'] ?? 0),
                'total_hours_used'     => (float)($row['total_hours_used'] ?? 0),
                'first_session'        => $row['first_session'],
                'last_session'         => $row['last_session']
            ];
        }

        $countQuery = "SELECT COUNT(*) AS total FROM venues v
                       WHERE v.tenant_id = ? AND v.is_active = 1";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Venue Utilization Report',
            'data' => $data,
            'meta' => $this->buildMeta($total)
        ];
    }
}
