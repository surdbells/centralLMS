<?php
require_once __DIR__ . '/BaseReport.php';

/**
 * Manager Dashboard Reports — Phase 13 (4 methods)
 * Dedicated manager view with team status and action items.
 */
class ManagerReports extends BaseReport
{
    public function generate(): array { return $this->managerTeamDashboard(); }

    private function getManagerId(): int {
        return (int)($this->filters['manager_id'] ?? $this->user_id ?? 0);
    }

    /* 1. MANAGER TEAM DASHBOARD — all direct reports with training status */
    public function managerTeamDashboard(): array
    {
        $mid = $this->getManagerId();
        $q = "SELECT
                u.id AS user_id, CONCAT(u.first_name,' ',u.last_name) AS learner_name,
                u.email, d.department_name AS department_name,
                COUNT(DISTINCT ce.enrollment_id) AS total_enrolled,
                SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN ce.status IN('in_progress','enrolled') AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN ce.is_mandatory=1 AND ce.status!='completed' THEN 1 ELSE 0 END) AS mandatory_pending,
                ROUND(SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END)*100.0/NULLIF(COUNT(DISTINCT ce.enrollment_id),0),1) AS completion_rate,
                MAX(ce.completed_at) AS last_activity
              FROM users u
              LEFT JOIN departments d ON u.department=d.department_id
              LEFT JOIN course_enrollments ce ON u.id=ce.user_id AND ce.tenant_id=u.tenant_id
              WHERE u.tenant_id=? AND u.is_active=1 AND u.manager_id=?
              GROUP BY u.id, u.first_name, u.last_name, u.email, d.department_name
              ORDER BY overdue DESC, mandatory_pending DESC
              LIMIT ? OFFSET ?";
        $stmt=$this->conn->prepare($q); $off=$this->getOffset();
        $stmt->bind_param("iiii",$this->tenant_id,$mid,$this->per_page,$off);
        $stmt->execute(); $res=$stmt->get_result();

        $data=[];
        while($row=$res->fetch_assoc()){
            $ovd=(int)$row['overdue']; $mp=(int)$row['mandatory_pending'];
            $data[]=[
                'user_id'=>(int)$row['user_id'],'learner_name'=>$row['learner_name'],'email'=>$row['email'],
                'department_name'=>$row['department_name']??'Unassigned',
                'total_enrolled'=>(int)$row['total_enrolled'],'completed'=>(int)$row['completed'],
                'overdue'=>$ovd,'mandatory_pending'=>$mp,
                'completion_rate'=>(float)$row['completion_rate'],'last_activity'=>$row['last_activity'],
                'status'=>$ovd>0?'at_risk':($mp>0?'needs_attention':'on_track'),
                'status_badge'=>$ovd>0?'danger':($mp>0?'warning':'success')
            ];
        }
        return [
            'report_name'=>'Manager Team Dashboard','description'=>'Training status for all direct reports',
            'chart_type'=>'donut','chart_config'=>['field'=>'status'],
            'summary'=>['team_size'=>count($data),'at_risk'=>count(array_filter($data,fn($d)=>$d['status']==='at_risk')),
                'avg_completion'=>count($data)>0?round(array_sum(array_column($data,'completion_rate'))/count($data),1):0],
            'data'=>$data,'meta'=>$this->buildMeta(count($data))
        ];
    }

    /* 2. MANAGER COMPLIANCE SUMMARY — team compliance with action items */
    public function managerComplianceSummary(): array
    {
        $mid = $this->getManagerId();
        $q = "SELECT
                c.course_id AS course_id, c.title AS course_name,
                COUNT(DISTINCT ce.user_id) AS assigned,
                SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN ce.status!='completed' AND ce.due_date<CURDATE() THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN ce.status IN('in_progress','enrolled') AND (ce.due_date IS NULL OR ce.due_date>=CURDATE()) THEN 1 ELSE 0 END) AS in_progress,
                ROUND(SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END)*100.0/NULLIF(COUNT(DISTINCT ce.user_id),0),1) AS compliance_rate
              FROM course_enrollments ce
              INNER JOIN courses c ON ce.course_id=c.course_id
              INNER JOIN users u ON ce.user_id=u.id AND u.manager_id=?
              WHERE ce.tenant_id=? AND ce.is_mandatory=1
              GROUP BY c.course_id, c.title
              ORDER BY compliance_rate ASC
              LIMIT ? OFFSET ?";
        $stmt=$this->conn->prepare($q); $off=$this->getOffset();
        $stmt->bind_param("iiii",$mid,$this->tenant_id,$this->per_page,$off);
        $stmt->execute(); $res=$stmt->get_result();

        $data=[];
        while($row=$res->fetch_assoc()){
            $rate=(float)$row['compliance_rate'];
            $data[]=[
                'course_id'=>(int)$row['course_id'],'course_name'=>$row['course_name'],
                'assigned'=>(int)$row['assigned'],'completed'=>(int)$row['completed'],
                'overdue'=>(int)$row['overdue'],'in_progress'=>(int)$row['in_progress'],
                'compliance_rate'=>$rate,
                'urgency'=>$rate<50?'critical':($rate<80?'medium':'low')
            ];
        }
        return [
            'report_name'=>'Manager Compliance Summary','description'=>'Mandatory training compliance for team',
            'chart_type'=>'horizontal_bar','chart_config'=>['x_axis'=>'compliance_rate','y_axis'=>'course_name','color_by'=>'urgency'],
            'summary'=>['courses_tracked'=>count($data),'critical_count'=>count(array_filter($data,fn($d)=>$d['urgency']==='critical'))],
            'data'=>$data,'meta'=>$this->buildMeta(count($data))
        ];
    }

    /* 3. MANAGER ACTION REQUIRED — overdue, pending approvals, expiring certs */
    public function managerActionRequired(): array
    {
        $mid = $this->getManagerId();

        // Overdue training
        $q1 = "SELECT 'overdue_training' AS action_type, u.id AS user_id,
                 CONCAT(u.first_name,' ',u.last_name) AS learner_name,
                 c.title AS item_name, ce.due_date AS deadline,
                 DATEDIFF(CURDATE(), ce.due_date) AS days_overdue
               FROM course_enrollments ce
               INNER JOIN users u ON ce.user_id=u.id AND u.manager_id=?
               INNER JOIN courses c ON ce.course_id=c.course_id
               WHERE ce.tenant_id=? AND ce.status!='completed' AND ce.due_date<CURDATE()
               ORDER BY days_overdue DESC LIMIT 50";
        $s1=$this->conn->prepare($q1); $s1->bind_param("ii",$mid,$this->tenant_id);
        $s1->execute(); $r1=$s1->get_result();

        // Expiring certificates (next 30 days)
        $q2 = "SELECT 'expiring_cert' AS action_type, u.id AS user_id,
                 CONCAT(u.first_name,' ',u.last_name) AS learner_name,
                 ct.template_name  AS item_name, uc.expiry_date AS deadline,
                 DATEDIFF(uc.expiry_date, CURDATE()) AS days_remaining
               FROM certificates uc
               INNER JOIN users u ON uc.user_id=u.id AND u.manager_id=?
               INNER JOIN certificate_templates ct ON uc.template_id=ct.template_id
               WHERE uc.tenant_id=? AND uc.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
               ORDER BY days_remaining ASC LIMIT 50";
        $s2=$this->conn->prepare($q2); $s2->bind_param("ii",$mid,$this->tenant_id);
        $s2->execute(); $r2=$s2->get_result();

        $data = [];
        while($row=$r1->fetch_assoc()){
            $data[]=['action_type'=>'overdue_training','user_id'=>(int)$row['user_id'],
                'learner_name'=>$row['learner_name'],'item_name'=>$row['item_name'],
                'deadline'=>$row['deadline'],'urgency_value'=>(int)$row['days_overdue'],
                'urgency_label'=>$row['days_overdue'].' days overdue','priority'=>'high'];
        }
        while($row=$r2->fetch_assoc()){
            $data[]=['action_type'=>'expiring_cert','user_id'=>(int)$row['user_id'],
                'learner_name'=>$row['learner_name'],'item_name'=>$row['item_name'],
                'deadline'=>$row['deadline'],'urgency_value'=>(int)$row['days_remaining'],
                'urgency_label'=>$row['days_remaining'].' days remaining','priority'=>(int)$row['days_remaining']<=7?'high':'medium'];
        }

        return [
            'report_name'=>'Manager Action Required','description'=>'Items requiring manager attention: overdue training, expiring certs',
            'chart_type'=>'table',
            'summary'=>['total_actions'=>count($data),
                'overdue_count'=>count(array_filter($data,fn($d)=>$d['action_type']==='overdue_training')),
                'expiring_count'=>count(array_filter($data,fn($d)=>$d['action_type']==='expiring_cert')),
                'high_priority'=>count(array_filter($data,fn($d)=>$d['priority']==='high'))],
            'data'=>$data,'meta'=>$this->buildMeta(count($data))
        ];
    }

    /* 4. TEAM VS ORG BENCHMARK — team performance vs org average */
    public function teamVsOrgBenchmark(): array
    {
        $mid = $this->getManagerId();

        // Team metrics
        $qTeam = "SELECT
                    ROUND(AVG(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END)*100,1) AS team_completion_rate,
                    ROUND(AVG(aa.score),1) AS team_avg_score,
                    COUNT(DISTINCT u.id) AS team_size,
                    ROUND(AVG(CASE WHEN ce.is_mandatory=1 AND ce.status='completed' THEN 1 WHEN ce.is_mandatory=1 THEN 0 END)*100,1) AS team_compliance_rate
                  FROM users u
                  LEFT JOIN course_enrollments ce ON u.id=ce.user_id AND ce.tenant_id=u.tenant_id
                  LEFT JOIN assessments a ON a.course_id=ce.course_id AND a.tenant_id=u.tenant_id
                  LEFT JOIN assessment_attempts aa ON aa.assessment_id=a.assessment_id AND aa.user_id=u.id AND aa.status IN('completed','passed')
                  WHERE u.tenant_id=? AND u.is_active=1 AND u.manager_id=?";
        $st=$this->conn->prepare($qTeam); $st->bind_param("ii",$this->tenant_id,$mid);
        $st->execute(); $team=$st->get_result()->fetch_assoc();

        // Org metrics
        $qOrg = "SELECT
                   ROUND(AVG(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END)*100,1) AS org_completion_rate,
                   ROUND(AVG(aa.score),1) AS org_avg_score,
                   COUNT(DISTINCT u.id) AS org_size,
                   ROUND(AVG(CASE WHEN ce.is_mandatory=1 AND ce.status='completed' THEN 1 WHEN ce.is_mandatory=1 THEN 0 END)*100,1) AS org_compliance_rate
                 FROM users u
                 LEFT JOIN course_enrollments ce ON u.id=ce.user_id AND ce.tenant_id=u.tenant_id
                 LEFT JOIN assessments a ON a.course_id=ce.course_id AND a.tenant_id=u.tenant_id
                 LEFT JOIN assessment_attempts aa ON aa.assessment_id=a.assessment_id AND aa.user_id=u.id AND aa.status IN('completed','passed')
                 WHERE u.tenant_id=? AND u.is_active=1";
        $so=$this->conn->prepare($qOrg); $so->bind_param("i",$this->tenant_id);
        $so->execute(); $org=$so->get_result()->fetch_assoc();

        $metrics = ['completion_rate','avg_score','compliance_rate'];
        $data = [];
        foreach ($metrics as $m) {
            $tv = (float)($team["team_{$m}"] ?? 0);
            $ov = (float)($org["org_{$m}"] ?? 0);
            $diff = round($tv - $ov, 1);
            $data[] = [
                'metric'     => str_replace('_', ' ', ucfirst($m)),
                'team_value' => $tv,
                'org_value'  => $ov,
                'difference' => $diff,
                'status'     => $diff >= 0 ? 'above_average' : 'below_average',
                'badge'      => $diff >= 0 ? 'success' : 'danger'
            ];
        }

        return [
            'report_name'=>'Team vs Organization Benchmark','description'=>'Team performance compared to org-wide averages',
            'chart_type'=>'grouped_bar','chart_config'=>['x_axis'=>'metric','y_axes'=>['team_value','org_value']],
            'summary'=>['team_size'=>(int)($team['team_size']??0),'org_size'=>(int)($org['org_size']??0),
                'above_avg_count'=>count(array_filter($data,fn($d)=>$d['status']==='above_average'))],
            'data'=>$data,'meta'=>$this->buildMeta(count($data))
        ];
    }
}
