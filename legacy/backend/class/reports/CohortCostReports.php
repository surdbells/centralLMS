<?php
require_once __DIR__ . '/BaseReport.php';

/**
 * Cohort & Cost Reports — Phase 13 (7 methods)
 * 3 cohort tracking + 4 cost analytics methods.
 * Cost reports use course.cost_per_learner if available, else 0.
 */
class CohortCostReports extends BaseReport
{
    public function generate(): array { return $this->cohortProgress(); }

    /* ═══════════════ COHORT REPORTS ═══════════════ */

    /* 1. COHORT PROGRESS — batch-level completion comparison */
    public function cohortProgress(): array
    {
        $orgF = $this->buildOrgFilter();
        $grp = ($this->filters['group_by'] ?? 'month') === 'quarter'
            ? "CONCAT(YEAR(ce.enrolled_at),'-Q',QUARTER(ce.enrolled_at))"
            : "DATE_FORMAT(ce.enrolled_at,'%Y-%m')";

        $q = "SELECT {$grp} AS cohort,
                COUNT(DISTINCT ce.user_id) AS learners,
                COUNT(DISTINCT ce.id) AS enrollments,
                SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END) AS completed,
                ROUND(SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END)*100.0/NULLIF(COUNT(DISTINCT ce.id),0),1) AS completion_rate,
                ROUND(AVG(TIMESTAMPDIFF(DAY, ce.enrolled_at, ce.completed_at)),1) AS avg_days
              FROM course_enrollments ce
              LEFT JOIN users u ON ce.user_id=u.id AND u.tenant_id=ce.tenant_id
              LEFT JOIN departments d ON u.department=d.id
              LEFT JOIN teams t ON u.team=t.id
              WHERE ce.tenant_id=? {$orgF}
              GROUP BY cohort ORDER BY cohort ASC
              LIMIT ? OFFSET ?";
        $stmt=$this->conn->prepare($q); $off=$this->getOffset();
        $stmt->bind_param("iii",$this->tenant_id,$this->per_page,$off);
        $stmt->execute(); $res=$stmt->get_result();

        $data=[];
        while($row=$res->fetch_assoc()){
            $data[]=[
                'cohort'=>$row['cohort'],'learners'=>(int)$row['learners'],
                'enrollments'=>(int)$row['enrollments'],'completed'=>(int)$row['completed'],
                'completion_rate'=>(float)$row['completion_rate'],
                'avg_completion_days'=>(float)($row['avg_days']??0)
            ];
        }
        return [
            'report_name'=>'Cohort Progress','description'=>'Enrollment cohort completion comparison',
            'chart_type'=>'line','chart_config'=>['x_axis'=>'cohort','y_axes'=>['completion_rate','learners']],
            'summary'=>['cohorts'=>count($data),'avg_rate'=>count($data)>0?round(array_sum(array_column($data,'completion_rate'))/count($data),1):0],
            'data'=>$data,'meta'=>$this->buildMeta(count($data))
        ];
    }

    /* 2. GROUP PERFORMANCE — defined group's collective progress */
    public function groupPerformance(): array
    {
        $orgF = $this->buildOrgFilter();

        $q = "SELECT
                d.name AS group_name, 'department' AS group_type,
                COUNT(DISTINCT u.id) AS members,
                COUNT(DISTINCT ce.id) AS total_enrollments,
                SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END) AS completed,
                ROUND(SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END)*100.0/NULLIF(COUNT(DISTINCT ce.id),0),1) AS completion_rate,
                ROUND(AVG(aa.score),1) AS avg_score,
                SUM(CASE WHEN ce.is_mandatory=1 AND ce.status='completed' THEN 1 ELSE 0 END) AS mandatory_completed,
                SUM(CASE WHEN ce.is_mandatory=1 THEN 1 ELSE 0 END) AS mandatory_total
              FROM users u
              INNER JOIN departments d ON u.department=d.id
              LEFT JOIN course_enrollments ce ON u.id=ce.user_id AND ce.tenant_id=u.tenant_id
              LEFT JOIN assessments a ON a.course_id=ce.course_id AND a.tenant_id=u.tenant_id
              LEFT JOIN assessment_attempts aa ON aa.assessment_id=a.id AND aa.user_id=u.id AND aa.status IN('completed','passed')
              LEFT JOIN teams t ON u.team=t.id
              WHERE u.tenant_id=? AND u.is_active=1 {$orgF}
              GROUP BY d.id, d.name
              ORDER BY completion_rate DESC
              LIMIT ? OFFSET ?";
        $stmt=$this->conn->prepare($q); $off=$this->getOffset();
        $stmt->bind_param("iii",$this->tenant_id,$this->per_page,$off);
        $stmt->execute(); $res=$stmt->get_result();

        $data=[];
        while($row=$res->fetch_assoc()){
            $mt=(int)$row['mandatory_total']; $mc=(int)$row['mandatory_completed'];
            $data[]=[
                'group_name'=>$row['group_name'],'group_type'=>$row['group_type'],
                'members'=>(int)$row['members'],'total_enrollments'=>(int)$row['total_enrollments'],
                'completed'=>(int)$row['completed'],'completion_rate'=>(float)$row['completion_rate'],
                'avg_score'=>(float)($row['avg_score']??0),
                'mandatory_compliance'=>$mt>0?round($mc*100.0/$mt,1):100
            ];
        }
        return [
            'report_name'=>'Group Performance','description'=>'Collective training progress by organizational group',
            'chart_type'=>'bar','chart_config'=>['x_axis'=>'group_name','y_axes'=>['completion_rate','mandatory_compliance']],
            'summary'=>['groups'=>count($data)],
            'data'=>$data,'meta'=>$this->buildMeta(count($data))
        ];
    }

    /* 3. PEER BENCHMARKING — individual vs peer group comparison */
    public function peerBenchmarking(): array
    {
        $orgF = $this->buildOrgFilter();

        $q = "SELECT
                u.id AS user_id, CONCAT(u.first_name,' ',u.last_name) AS learner_name,
                d.name AS department_name,
                COUNT(DISTINCT ce.id) AS enrolled,
                SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END) AS completed,
                ROUND(SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END)*100.0/NULLIF(COUNT(DISTINCT ce.id),0),1) AS completion_rate,
                ROUND(AVG(aa.score),1) AS avg_score,
                dept_avg.dept_cr, dept_avg.dept_score
              FROM users u
              LEFT JOIN departments d ON u.department=d.id
              LEFT JOIN course_enrollments ce ON u.id=ce.user_id AND ce.tenant_id=u.tenant_id
              LEFT JOIN assessments a ON a.course_id=ce.course_id AND a.tenant_id=u.tenant_id
              LEFT JOIN assessment_attempts aa ON aa.assessment_id=a.id AND aa.user_id=u.id AND aa.status IN('completed','passed')
              LEFT JOIN teams t ON u.team=t.id
              LEFT JOIN (
                  SELECT u2.department,
                    ROUND(AVG(CASE WHEN ce2.status='completed' THEN 1 ELSE 0 END)*100,1) AS dept_cr,
                    ROUND(AVG(aa2.score),1) AS dept_score
                  FROM users u2
                  LEFT JOIN course_enrollments ce2 ON u2.id=ce2.user_id AND ce2.tenant_id=u2.tenant_id
                  LEFT JOIN assessments a2 ON a2.course_id=ce2.course_id AND a2.tenant_id=u2.tenant_id
                  LEFT JOIN assessment_attempts aa2 ON aa2.assessment_id=a2.id AND aa2.user_id=u2.id AND aa2.status IN('completed','passed')
                  WHERE u2.tenant_id=? AND u2.is_active=1
                  GROUP BY u2.department
              ) dept_avg ON dept_avg.department=u.department
              WHERE u.tenant_id=? AND u.is_active=1 {$orgF}
              GROUP BY u.id, u.first_name, u.last_name, d.name, dept_avg.dept_cr, dept_avg.dept_score
              ORDER BY completion_rate DESC
              LIMIT ? OFFSET ?";
        $stmt=$this->conn->prepare($q); $off=$this->getOffset();
        $stmt->bind_param("iiii",$this->tenant_id,$this->tenant_id,$this->per_page,$off);
        $stmt->execute(); $res=$stmt->get_result();

        $data=[];
        while($row=$res->fetch_assoc()){
            $cr=(float)($row['completion_rate']??0); $dcr=(float)($row['dept_cr']??0);
            $data[]=[
                'user_id'=>(int)$row['user_id'],'learner_name'=>$row['learner_name'],
                'department_name'=>$row['department_name']??'Unassigned',
                'enrolled'=>(int)$row['enrolled'],'completed'=>(int)$row['completed'],
                'completion_rate'=>$cr,'avg_score'=>(float)($row['avg_score']??0),
                'peer_completion_rate'=>$dcr,'peer_avg_score'=>(float)($row['dept_score']??0),
                'vs_peers'=>round($cr-$dcr,1),
                'ranking'=>$cr>=$dcr?'above_average':'below_average'
            ];
        }
        return [
            'report_name'=>'Peer Benchmarking','description'=>'Individual learner performance vs department peers',
            'chart_type'=>'scatter','chart_config'=>['x_axis'=>'completion_rate','y_axis'=>'avg_score','color_by'=>'ranking'],
            'summary'=>['learners'=>count($data),
                'above_avg'=>count(array_filter($data,fn($d)=>$d['ranking']==='above_average'))],
            'data'=>$data,'meta'=>$this->buildMeta(count($data))
        ];
    }

    /* ═══════════════ COST REPORTS ═══════════════ */

    /** Helper: Check if column exists */
    private function columnExists(string $table, string $column): bool
    {
        $q = "SELECT COUNT(*) AS cnt FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?";
        $stmt=$this->conn->prepare($q); $stmt->bind_param("ss",$table,$column);
        $stmt->execute(); return (int)$stmt->get_result()->fetch_assoc()['cnt']>0;
    }

    private function costCol(): string {
        return $this->columnExists('courses','cost_per_learner') ? 'c.cost_per_learner' : '0';
    }

    /* 4. TRAINING COST PER EMPLOYEE */
    public function trainingCostPerEmployee(): array
    {
        $orgF=$this->buildOrgFilter(); $cc=$this->costCol();
        $q="SELECT u.id AS user_id, CONCAT(u.first_name,' ',u.last_name) AS learner_name,
              d.name AS department_name,
              COUNT(DISTINCT ce.id) AS courses_taken,
              SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END) AS courses_completed,
              ROUND(SUM({$cc}),2) AS total_cost,
              ROUND(SUM(CASE WHEN ce.status='completed' THEN {$cc} ELSE 0 END),2) AS effective_cost
            FROM users u LEFT JOIN departments d ON u.department=d.id LEFT JOIN teams t ON u.team=t.id
            LEFT JOIN course_enrollments ce ON u.id=ce.user_id AND ce.tenant_id=u.tenant_id
            LEFT JOIN courses c ON ce.course_id = c.course_id
            WHERE u.tenant_id=? AND u.is_active=1 {$orgF}
            GROUP BY u.id, u.first_name, u.last_name, d.name HAVING courses_taken>0
            ORDER BY total_cost DESC LIMIT ? OFFSET ?";
        $stmt=$this->conn->prepare($q); $off=$this->getOffset();
        $stmt->bind_param("iii",$this->tenant_id,$this->per_page,$off);
        $stmt->execute(); $res=$stmt->get_result();

        $data=[];
        while($row=$res->fetch_assoc()){
            $data[]=['user_id'=>(int)$row['user_id'],'learner_name'=>$row['learner_name'],
                'department_name'=>$row['department_name']??'Unassigned',
                'courses_taken'=>(int)$row['courses_taken'],'courses_completed'=>(int)$row['courses_completed'],
                'total_cost'=>(float)$row['total_cost'],'effective_cost'=>(float)$row['effective_cost']];
        }
        return ['report_name'=>'Training Cost per Employee','description'=>'Total training investment per employee',
            'chart_type'=>'bar','chart_config'=>['x_axis'=>'learner_name','y_axis'=>'total_cost'],
            'summary'=>['employees'=>count($data),'total_spend'=>array_sum(array_column($data,'total_cost'))],
            'data'=>$data,'meta'=>$this->buildMeta(count($data))];
    }

    /* 5. COST PER COMPLETION */
    public function costPerCompletion(): array
    {
        $orgF=$this->buildOrgFilter(); $cc=$this->costCol();
        $q="SELECT c.course_id AS course_id, c.title AS course_name, c.course_type,
              COUNT(DISTINCT ce.id) AS enrolled,
              SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END) AS completed,
              ROUND(SUM({$cc}),2) AS total_cost,
              ROUND(SUM({$cc})/NULLIF(SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END),0),2) AS cost_per_completion
            FROM courses c
            LEFT JOIN course_enrollments ce ON c.course_id=ce.course_id AND ce.tenant_id=c.tenant_id
            LEFT JOIN users u ON ce.user_id=u.id AND u.tenant_id=c.tenant_id
            LEFT JOIN departments d ON u.department=d.id LEFT JOIN teams t ON u.team=t.id
            WHERE c.tenant_id=? AND c.is_active=1 {$orgF}
            GROUP BY c.title, c.course_type HAVING enrolled>0
            ORDER BY cost_per_completion DESC LIMIT ? OFFSET ?";
        $stmt=$this->conn->prepare($q); $off=$this->getOffset();
        $stmt->bind_param("iii",$this->tenant_id,$this->per_page,$off);
        $stmt->execute(); $res=$stmt->get_result();

        $data=[];
        while($row=$res->fetch_assoc()){
            $data[]=['course_id'=>(int)$row['course_id'],'course_name'=>$row['course_name'],
                'course_type'=>$row['course_type'],'enrolled'=>(int)$row['enrolled'],
                'completed'=>(int)$row['completed'],'total_cost'=>(float)$row['total_cost'],
                'cost_per_completion'=>(float)($row['cost_per_completion']??0)];
        }
        return ['report_name'=>'Cost per Completion','description'=>'Training cost per course completion',
            'chart_type'=>'bar','chart_config'=>['x_axis'=>'course_name','y_axis'=>'cost_per_completion'],
            'summary'=>['courses'=>count($data),'total_spend'=>array_sum(array_column($data,'total_cost'))],
            'data'=>$data,'meta'=>$this->buildMeta(count($data))];
    }

    /* 6. DEPARTMENT TRAINING SPEND */
    public function departmentTrainingSpend(): array
    {
        $orgF=$this->buildOrgFilter(); $cc=$this->costCol();
        $q="SELECT d.name AS department_name,
              COUNT(DISTINCT u.id) AS employees, COUNT(DISTINCT ce.id) AS enrollments,
              SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END) AS completions,
              ROUND(SUM({$cc}),2) AS total_spend,
              ROUND(SUM({$cc})/NULLIF(COUNT(DISTINCT u.id),0),2) AS spend_per_employee
            FROM users u INNER JOIN departments d ON u.department=d.id
            LEFT JOIN course_enrollments ce ON u.id=ce.user_id AND ce.tenant_id=u.tenant_id
            LEFT JOIN courses c ON ce.course_id=c.course_id LEFT JOIN teams t ON u.team=t.id
            WHERE u.tenant_id=? AND u.is_active=1 {$orgF}
            GROUP BY d.id, d.name ORDER BY total_spend DESC LIMIT ? OFFSET ?";
        $stmt=$this->conn->prepare($q); $off=$this->getOffset();
        $stmt->bind_param("iii",$this->tenant_id,$this->per_page,$off);
        $stmt->execute(); $res=$stmt->get_result();

        $data=[];
        while($row=$res->fetch_assoc()){
            $data[]=['department_name'=>$row['department_name'],
                'employees'=>(int)$row['employees'],'enrollments'=>(int)$row['enrollments'],
                'completions'=>(int)$row['completions'],'total_spend'=>(float)$row['total_spend'],
                'spend_per_employee'=>(float)($row['spend_per_employee']??0)];
        }
        return ['report_name'=>'Department Training Spend','description'=>'Training budget allocation by department',
            'chart_type'=>'bar','chart_config'=>['x_axis'=>'department_name','y_axis'=>'total_spend'],
            'summary'=>['departments'=>count($data),'grand_total'=>array_sum(array_column($data,'total_spend'))],
            'data'=>$data,'meta'=>$this->buildMeta(count($data))];
    }

    /* 7. COST EFFICIENCY — online vs ILT cost comparison */
    public function costEfficiency(): array
    {
        $orgF=$this->buildOrgFilter(); $cc=$this->costCol();
        $q="SELECT c.course_type AS delivery_method,
              COUNT(DISTINCT ce.id) AS enrollments,
              SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END) AS completions,
              ROUND(SUM({$cc}),2) AS total_cost,
              ROUND(SUM({$cc})/NULLIF(SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END),0),2) AS cost_per_completion,
              ROUND(SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END)*100.0/NULLIF(COUNT(DISTINCT ce.id),0),1) AS completion_rate
            FROM courses c
            LEFT JOIN course_enrollments ce ON c.course_id=ce.course_id AND ce.tenant_id=c.tenant_id
            LEFT JOIN users u ON ce.user_id=u.id AND u.tenant_id=c.tenant_id
            LEFT JOIN departments d ON u.department=d.id LEFT JOIN teams t ON u.team=t.id
            WHERE c.tenant_id=? {$orgF} GROUP BY c.course_type ORDER BY c.course_type";
        $stmt=$this->conn->prepare($q); $stmt->bind_param("i",$this->tenant_id);
        $stmt->execute(); $res=$stmt->get_result();

        $data=[];
        while($row=$res->fetch_assoc()){
            $data[]=['delivery_method'=>$row['delivery_method'],
                'enrollments'=>(int)$row['enrollments'],'completions'=>(int)$row['completions'],
                'total_cost'=>(float)$row['total_cost'],
                'cost_per_completion'=>(float)($row['cost_per_completion']??0),
                'completion_rate'=>(float)$row['completion_rate']];
        }
        return ['report_name'=>'Cost Efficiency by Delivery Method','description'=>'Online vs ILT cost comparison',
            'chart_type'=>'grouped_bar','chart_config'=>['x_axis'=>'delivery_method','y_axes'=>['cost_per_completion','completion_rate']],
            'summary'=>['methods'=>count($data),'total_spend'=>array_sum(array_column($data,'total_cost'))],
            'data'=>$data,'meta'=>$this->buildMeta(count($data))];
    }
}
