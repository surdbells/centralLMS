<?php
require_once __DIR__ . '/BaseReport.php';

/**
 * Content & Resource Analytics — Phase 15 (3 reports)
 * Resource-level engagement tracking.
 * Uses course_resources/course_modules + resource_access_log tables.
 * Falls back to course-level data if resource tables don't exist.
 */
class ContentReports extends BaseReport
{
    public function generate(): array { return $this->resourceUtilization(); }

    private function getResTable(): string {
        return $this->tableExists('course_resources') ? 'course_resources' : ($this->tableExists('course_modules') ? 'course_modules' : '');
    }

    /* ═══════════════════════════════════════════════════
     * 1. RESOURCE UTILIZATION
     * Access frequency + time per individual resource.
     * ═══════════════════════════════════════════════════ */
    public function resourceUtilization(): array
    {
        $orgF = $this->buildOrgFilter();
        $resTable = $this->getResTable();
        $hasLog = $this->tableExists('resource_access_log');

        if ($resTable && $hasLog) {
            $q = "SELECT r.id AS resource_id, r.title AS resource_name,
                    r.resource_type, c.title AS course_name,
                    COUNT(ral.id) AS access_count,
                    COUNT(DISTINCT ral.user_id) AS unique_users,
                    ROUND(AVG(ral.duration_seconds)/60, 1) AS avg_time_mins,
                    ROUND(SUM(ral.duration_seconds)/3600, 1) AS total_hours,
                    MAX(ral.accessed_at) AS last_accessed
                  FROM {$resTable} r
                  INNER JOIN courses c ON r.course_id = c.course_id
                  LEFT JOIN resource_access_log ral ON ral.resource_id = r.id AND ral.tenant_id = r.tenant_id
                  LEFT JOIN users u ON ral.user_id = u.id AND u.tenant_id = r.tenant_id
                  LEFT JOIN departments d ON u.department = d.id
                  LEFT JOIN teams t ON u.team = t.id
                  WHERE r.tenant_id = ? {$orgF}
                  GROUP BY r.id, r.title, r.resource_type, c.title
                  ORDER BY access_count DESC
                  LIMIT ? OFFSET ?";
        } else {
            // Fallback: course-level utilization from enrollments
            $q = "SELECT c.course_id AS resource_id, c.title AS resource_name,
                    c.course_type AS resource_type, c.title AS course_name,
                    COUNT(DISTINCT ce.id) AS access_count,
                    COUNT(DISTINCT ce.user_id) AS unique_users,
                    ROUND(AVG(TIMESTAMPDIFF(MINUTE, ce.enrolled_at, COALESCE(ce.completed_at, NOW()))), 1) AS avg_time_mins,
                    ROUND(SUM(TIMESTAMPDIFF(MINUTE, ce.enrolled_at, COALESCE(ce.completed_at, NOW())))/60, 1) AS total_hours,
                    MAX(COALESCE(ce.completed_at, ce.enrolled_at)) AS last_accessed
                  FROM courses c
                  LEFT JOIN course_enrollments ce ON c.course_id = ce.course_id AND ce.tenant_id = c.tenant_id
                  LEFT JOIN users u ON ce.user_id = u.id AND u.tenant_id = c.tenant_id
                  LEFT JOIN departments d ON u.department = d.id
                  LEFT JOIN teams t ON u.team = t.id
                  WHERE c.tenant_id = ? AND c.is_active = 1 {$orgF}
                  GROUP BY c.title, c.course_type
                  ORDER BY access_count DESC
                  LIMIT ? OFFSET ?";
        }

        $stmt = $this->conn->prepare($q); $off = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $off);
        $stmt->execute(); $res = $stmt->get_result();

        $data = [];
        while ($row = $res->fetch_assoc()) {
            $data[] = [
                'resource_id'   => (int)$row['resource_id'],
                'resource_name' => $row['resource_name'],
                'resource_type' => $row['resource_type'] ?? 'unknown',
                'course_name'   => $row['course_name'],
                'access_count'  => (int)$row['access_count'],
                'unique_users'  => (int)$row['unique_users'],
                'avg_time_mins' => (float)($row['avg_time_mins'] ?? 0),
                'total_hours'   => (float)($row['total_hours'] ?? 0),
                'last_accessed' => $row['last_accessed'],
                'engagement'    => (int)$row['access_count'] > 50 ? 'high' : ((int)$row['access_count'] > 10 ? 'medium' : 'low')
            ];
        }

        return [
            'report_name' => 'Resource Utilization',
            'description' => 'Access frequency and time per individual resource',
            'chart_type'  => 'bar',
            'chart_config' => ['x_axis'=>'resource_name','y_axis'=>'access_count','color_by'=>'engagement'],
            'summary' => [
                'total_resources' => count($data),
                'data_level' => ($resTable && $hasLog) ? 'resource' : 'course_fallback',
                'high_engagement' => count(array_filter($data, fn($d) => $d['engagement'] === 'high'))
            ],
            'data' => $data, 'meta' => $this->buildMeta(count($data))
        ];
    }

    /* ═══════════════════════════════════════════════════
     * 2. CONTENT FRESHNESS
     * Flag stale content not updated in X months.
     * ═══════════════════════════════════════════════════ */
    public function contentFreshness(): array
    {
        $orgF = $this->buildOrgFilter();
        $staleMonths = (int)($this->filters['months'] ?? 6);

        $q = "SELECT c.course_id AS course_id, c.title AS course_name, c.course_type,
                c.created_at, c.updated_at,
                DATEDIFF(CURDATE(), COALESCE(c.updated_at, c.created_at)) AS days_since_update,
                COUNT(DISTINCT ce.enrollment_id) AS total_enrollments,
                SUM(CASE WHEN ce.enrolled_at >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH) THEN 1 ELSE 0 END) AS recent_enrollments
              FROM courses c
              LEFT JOIN course_enrollments ce ON c.course_id = ce.course_id AND ce.tenant_id = c.tenant_id
              WHERE c.tenant_id = ? AND c.is_active = 1
              GROUP BY c.course_id, c.title, c.course_type, c.created_at, c.updated_at
              ORDER BY days_since_update DESC
              LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($q); $off = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $off);
        $stmt->execute(); $res = $stmt->get_result();

        $data = [];
        $staleThreshold = $staleMonths * 30;
        while ($row = $res->fetch_assoc()) {
            $days = (int)$row['days_since_update'];
            $freshness = $days > $staleThreshold ? 'stale' : ($days > ($staleThreshold / 2) ? 'aging' : 'fresh');
            $data[] = [
                'course_id'        => (int)$row['course_id'],
                'course_name'      => $row['course_name'],
                'course_type'      => $row['course_type'],
                'created_at'       => $row['created_at'],
                'last_updated'     => $row['updated_at'] ?? $row['created_at'],
                'days_since_update'=> $days,
                'freshness'        => $freshness,
                'freshness_badge'  => $freshness === 'stale' ? 'danger' : ($freshness === 'aging' ? 'warning' : 'success'),
                'total_enrollments'=> (int)$row['total_enrollments'],
                'recent_enrollments'=> (int)$row['recent_enrollments'],
                'still_in_use'     => (int)$row['recent_enrollments'] > 0
            ];
        }

        return [
            'report_name' => 'Content Freshness',
            'description' => "Flag content not updated in {$staleMonths}+ months",
            'chart_type'  => 'bar',
            'chart_config' => ['x_axis'=>'course_name','y_axis'=>'days_since_update','color_by'=>'freshness'],
            'summary' => [
                'total_courses' => count($data),
                'stale_count'   => count(array_filter($data, fn($d) => $d['freshness'] === 'stale')),
                'aging_count'   => count(array_filter($data, fn($d) => $d['freshness'] === 'aging')),
                'fresh_count'   => count(array_filter($data, fn($d) => $d['freshness'] === 'fresh')),
                'stale_but_used'=> count(array_filter($data, fn($d) => $d['freshness'] === 'stale' && $d['still_in_use']))
            ],
            'data' => $data, 'meta' => $this->buildMeta(count($data))
        ];
    }

    /* ═══════════════════════════════════════════════════
     * 3. MEDIA TYPE ENGAGEMENT
     * Engagement comparison across video/PDF/SCORM/audio.
     * ═══════════════════════════════════════════════════ */
    public function mediaTypeEngagement(): array
    {
        $orgF = $this->buildOrgFilter();

        $q = "SELECT c.course_type AS media_type,
                COUNT(DISTINCT c.course_id) AS course_count,
                COUNT(DISTINCT ce.id) AS total_enrollments,
                COUNT(DISTINCT ce.user_id) AS unique_learners,
                SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END) AS completions,
                ROUND(SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END)*100.0/NULLIF(COUNT(DISTINCT ce.id),0),1) AS completion_rate,
                ROUND(AVG(TIMESTAMPDIFF(MINUTE, ce.enrolled_at, ce.completed_at)),1) AS avg_completion_mins,
                ROUND(AVG(aa.score),1) AS avg_assessment_score
              FROM courses c
              LEFT JOIN course_enrollments ce ON c.course_id = ce.course_id AND ce.tenant_id = c.tenant_id
              LEFT JOIN users u ON ce.user_id = u.id AND u.tenant_id = c.tenant_id
              LEFT JOIN departments d ON u.department = d.id
              LEFT JOIN teams t ON u.team = t.id
              LEFT JOIN assessments a ON a.course_id = c.course_id AND a.tenant_id = c.tenant_id
              LEFT JOIN assessment_attempts aa ON aa.assessment_id = a.id AND aa.user_id = ce.user_id AND aa.status IN ('completed','passed')
              WHERE c.tenant_id = ? AND c.is_active = 1 {$orgF}
              GROUP BY c.course_type
              ORDER BY total_enrollments DESC";

        $stmt = $this->conn->prepare($q);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute(); $res = $stmt->get_result();

        $data = [];
        while ($row = $res->fetch_assoc()) {
            $cr = (float)($row['completion_rate'] ?? 0);
            $data[] = [
                'media_type'         => $row['media_type'] ?? 'other',
                'course_count'       => (int)$row['course_count'],
                'total_enrollments'  => (int)$row['total_enrollments'],
                'unique_learners'    => (int)$row['unique_learners'],
                'completions'        => (int)$row['completions'],
                'completion_rate'    => $cr,
                'avg_completion_mins'=> (float)($row['avg_completion_mins'] ?? 0),
                'avg_assessment_score'=> (float)($row['avg_assessment_score'] ?? 0),
                'effectiveness'      => $cr >= 80 ? 'high' : ($cr >= 50 ? 'medium' : 'low')
            ];
        }

        return [
            'report_name' => 'Media Type Engagement',
            'description' => 'Engagement comparison across video, PDF, SCORM, ILT content types',
            'chart_type'  => 'grouped_bar',
            'chart_config' => ['x_axis'=>'media_type','y_axes'=>['completion_rate','avg_assessment_score']],
            'summary' => [
                'media_types' => count($data),
                'most_effective' => !empty($data) ? $data[0]['media_type'] : 'N/A',
                'total_learners' => array_sum(array_column($data, 'unique_learners'))
            ],
            'data' => $data, 'meta' => $this->buildMeta(count($data))
        ];
    }
}
