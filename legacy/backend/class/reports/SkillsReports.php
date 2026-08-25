<?php
require_once __DIR__ . '/BaseReport.php';

/**
 * Skills & Competency Reports — Phase 10
 * 6 reports critical for banking sector RFPs (FCMB).
 * Requires: skills, role_skills, user_skills, course_skills tables.
 */
class SkillsReports extends BaseReport
{
    private const PROF = ['awareness'=>1,'basic'=>2,'intermediate'=>3,'advanced'=>4,'expert'=>5];

    public function generate(): array { return $this->skillGapAnalysis(); }

    // ── Schema auto-detection ──
    private function uRoleCol(): string {
        static $c=null; if($c) return $c;
        foreach(['role_id','designation','role'] as $col){
            $r=$this->conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='{$col}'");
            if($r && $r->num_rows){$c=$col;return $c;}
        } return $c='role_id';
    }
    private function rTable(): string {
        static $c=null; if($c) return $c;
        foreach(['roles','designations','job_titles'] as $t){
            $r=$this->conn->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$t}'");
            if($r && $r->num_rows){$c=$t;return $c;}
        } return $c='roles';
    }
    private function rIdCol(): string {
        $t=$this->rTable();
        foreach(['id','role_id','designation_id'] as $col){
            $r=$this->conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$t}' AND COLUMN_NAME='{$col}'");
            if($r && $r->num_rows) return $col;
        } return 'id';
    }
    private function rNameCol(): string {
        $t=$this->rTable();
        foreach(['name','role_name','title','designation_name'] as $col){
            $r=$this->conn->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$t}' AND COLUMN_NAME='{$col}'");
            if($r && $r->num_rows) return $col;
        } return 'name';
    }
    private function profNum(string $l): int { return self::PROF[strtolower($l)] ?? 0; }
    private function profBadge(string $l): array {
        return match(strtolower($l)){
            'expert'=>['label'=>'Expert','color'=>'success','level'=>5],
            'advanced'=>['label'=>'Advanced','color'=>'primary','level'=>4],
            'intermediate'=>['label'=>'Intermediate','color'=>'info','level'=>3],
            'basic'=>['label'=>'Basic','color'=>'warning','level'=>2],
            'awareness'=>['label'=>'Awareness','color'=>'secondary','level'=>1],
            default=>['label'=>'None','color'=>'light','level'=>0]
        };
    }
    private function gapSt(int $req, int $act): array {
        if($act===0) return['status'=>'not_attempted','badge'=>'danger'];
        if($act>=$req) return['status'=>'met','badge'=>'success'];
        if($act>=$req-1) return['status'=>'near','badge'=>'warning'];
        return['status'=>'gap','badge'=>'danger'];
    }
    private function catFilter(): string {
        if(!empty($this->filters['skill_category'])){
            return "AND s.category='".$this->conn->real_escape_string($this->filters['skill_category'])."'";
        } return '';
    }
    /** FIELD()-based proficiency comparison expression */
    private function fieldGte(string $actual, string $required): string {
        return "FIELD({$actual},'awareness','basic','intermediate','advanced','expert') >= FIELD({$required},'awareness','basic','intermediate','advanced','expert')";
    }

    /* ═══════════════════════════════════════════════════
     * 1. SKILL GAP ANALYSIS
     * ═══════════════════════════════════════════════════ */
    public function skillGapAnalysis(): array
    {
        $orgF=$this->buildOrgFilter(); $catF=$this->catFilter();
        $rt=$this->rTable(); $ric=$this->rIdCol(); $rnc=$this->rNameCol(); $urc=$this->uRoleCol();

        $dim=$this->filters['breakdown']??'department';
        switch($dim){
            case 'role':  $sel="r.{$ric} AS gid, r.{$rnc} AS gname"; $grp="r.{$ric},r.{$rnc}"; break;
            case 'team':  $sel="t.id AS gid, t.team_name AS gname";   $grp="t.id,t.team_name";   break;
            default:      $sel="d.id AS gid, d.name AS gname";        $grp="d.id,d.name";         break;
        }
        $fGte = $this->fieldGte('us.proficiency_level','rs.proficiency_level');

        $q="SELECT {$sel}, s.skill_id, s.skill_name, s.category AS skill_category,
              rs.proficiency_level AS required_level,
              COUNT(DISTINCT u.id) AS total_emp,
              COUNT(DISTINCT us.user_id) AS with_skill,
              SUM(CASE WHEN {$fGte} THEN 1 ELSE 0 END) AS emp_met,
              ROUND(SUM(CASE WHEN {$fGte} THEN 1 ELSE 0 END)*100.0/NULLIF(COUNT(DISTINCT u.id),0),1) AS met_pct
            FROM users u
            INNER JOIN {$rt} r ON u.{$urc}=r.{$ric}
            INNER JOIN role_skills rs ON r.{$ric}=rs.role_id AND rs.tenant_id=u.tenant_id
            INNER JOIN skills s ON rs.skill_id=s.skill_id AND s.tenant_id=u.tenant_id AND s.is_active=1
            LEFT JOIN departments d ON u.department=d.id LEFT JOIN teams t ON u.team=t.id
            LEFT JOIN user_skills us ON u.id=us.user_id AND us.skill_id=s.skill_id AND us.tenant_id=u.tenant_id AND us.status='acquired'
            WHERE u.tenant_id=? AND u.is_active=1 {$catF} {$orgF}
            GROUP BY {$grp},s.skill_id,s.skill_name,s.category,rs.proficiency_level
            ORDER BY met_pct ASC LIMIT ? OFFSET ?";

        $stmt=$this->conn->prepare($q); $off=$this->getOffset();
        $stmt->bind_param("iii",$this->tenant_id,$this->per_page,$off);
        $stmt->execute(); $res=$stmt->get_result();

        $data=[];
        while($row=$res->fetch_assoc()){
            $gap=(int)$row['total_emp']-(int)$row['emp_met'];
            $data[]=[
                'group_id'=>(int)$row['gid'],'group_name'=>$row['gname']??'Unassigned','breakdown_type'=>$dim,
                'skill_id'=>(int)$row['skill_id'],'skill_name'=>$row['skill_name'],'skill_category'=>$row['skill_category'],
                'required_level'=>$row['required_level'],'required_badge'=>$this->profBadge($row['required_level']),
                'total_employees'=>(int)$row['total_emp'],'employees_with_skill'=>(int)$row['with_skill'],
                'employees_met'=>(int)$row['emp_met'],'gap_count'=>$gap,
                'met_pct'=>(float)($row['met_pct']??0),
                'severity'=>$gap>5?'critical':($gap>0?'moderate':'none')
            ];
        }
        return [
            'report_name'=>'Skill Gap Analysis','description'=>"Required vs acquired skills by {$dim}",
            'chart_type'=>'heatmap',
            'chart_config'=>['x_axis'=>'skill_name','y_axis'=>'group_name','value'=>'met_pct','color_scale'=>['#dc3545','#ffc107','#28a745']],
            'summary'=>['total_requirements'=>count($data),'total_gaps'=>array_sum(array_column($data,'gap_count')),
                'critical_gaps'=>count(array_filter($data,fn($d)=>$d['severity']==='critical')),
                'avg_met_pct'=>count($data)>0?round(array_sum(array_column($data,'met_pct'))/count($data),1):0],
            'data'=>$data,'meta'=>$this->buildMeta(count($data))
        ];
    }

    /* ═══════════════════════════════════════════════════
     * 2. COMPETENCY MATRIX
     * ═══════════════════════════════════════════════════ */
    public function competencyMatrix(): array
    {
        $orgF=$this->buildOrgFilter();
        $rt=$this->rTable(); $ric=$this->rIdCol(); $rnc=$this->rNameCol(); $urc=$this->uRoleCol();
        $roleFilter='';
        if(!empty($this->filters['role_id'])) $roleFilter="AND r.{$ric}=".(int)$this->filters['role_id'];

        // Required skills
        $sQ="SELECT DISTINCT s.skill_id,s.skill_name,s.category,rs.proficiency_level AS req_level
             FROM role_skills rs INNER JOIN skills s ON rs.skill_id=s.skill_id AND s.tenant_id=rs.tenant_id AND s.is_active=1
             INNER JOIN {$rt} r ON rs.role_id=r.{$ric}
             WHERE rs.tenant_id=? AND rs.is_required=1 {$roleFilter} ORDER BY s.category,s.skill_name";
        $ss=$this->conn->prepare($sQ); $ss->bind_param("i",$this->tenant_id); $ss->execute();
        $skills=[];
        while($r=$ss->get_result()->fetch_assoc()) $skills[$r['skill_id']]=['skill_id'=>(int)$r['skill_id'],'skill_name'=>$r['skill_name'],'category'=>$r['category'],'required_level'=>$r['req_level']];
        // Re-fetch if result already consumed
        if(empty($skills)){
            $ss->execute(); $sRes=$ss->get_result();
            while($r=$sRes->fetch_assoc()) $skills[$r['skill_id']]=['skill_id'=>(int)$r['skill_id'],'skill_name'=>$r['skill_name'],'category'=>$r['category'],'required_level'=>$r['req_level']];
        }

        if(empty($skills)) return['report_name'=>'Competency Matrix','description'=>'No required skills configured','data'=>[],'skills_legend'=>[],'meta'=>$this->buildMeta(0)];

        $sIds=implode(',',array_keys($skills));
        $q="SELECT u.id AS uid,CONCAT(u.first_name,' ',u.last_name) AS name,r.{$rnc} AS role_name,d.name AS dept,
              us.skill_id,us.proficiency_level AS actual
            FROM users u INNER JOIN {$rt} r ON u.{$urc}=r.{$ric}
            INNER JOIN role_skills rs ON r.{$ric}=rs.role_id AND rs.tenant_id=u.tenant_id AND rs.skill_id IN ({$sIds})
            LEFT JOIN departments d ON u.department=d.id
            LEFT JOIN user_skills us ON u.id=us.user_id AND us.skill_id=rs.skill_id AND us.tenant_id=u.tenant_id AND us.status='acquired'
            WHERE u.tenant_id=? AND u.is_active=1 {$roleFilter} {$orgF}
            ORDER BY u.last_name LIMIT ? OFFSET ?";
        $stmt=$this->conn->prepare($q); $off=$this->getOffset();
        $lim=min($this->per_page*count($skills),5000);
        $stmt->bind_param("iii",$this->tenant_id,$lim,$off); $stmt->execute(); $res=$stmt->get_result();

        $mx=[];
        while($row=$res->fetch_assoc()){
            $uid=(int)$row['uid'];
            if(!isset($mx[$uid])) $mx[$uid]=['user_id'=>$uid,'learner_name'=>$row['name'],'role_name'=>$row['role_name'],'department_name'=>$row['dept']??'Unassigned','skills'=>[],'met'=>0,'gaps'=>0,'total_required'=>count($skills)];
            $sid=(int)$row['skill_id'];
            if($sid && isset($skills[$sid])){
                $gs=$this->gapSt($this->profNum($skills[$sid]['required_level']),$row['actual']?$this->profNum($row['actual']):0);
                $mx[$uid]['skills'][$sid]=['skill_id'=>$sid,'required_level'=>$skills[$sid]['required_level'],'actual_level'=>$row['actual']??'none','status'=>$gs['status'],'badge'=>$gs['badge']];
                if($gs['status']==='met') $mx[$uid]['met']++; else $mx[$uid]['gaps']++;
            }
        }
        foreach($mx as &$u){
            foreach($skills as $sid=>$sk){
                if(!isset($u['skills'][$sid])){$u['skills'][$sid]=['skill_id'=>$sid,'required_level'=>$sk['required_level'],'actual_level'=>'none','status'=>'not_attempted','badge'=>'danger'];$u['gaps']++;}
            }
            $u['competency_pct']=$u['total_required']>0?round($u['met']*100.0/$u['total_required'],1):0;
            $u['skills']=array_values($u['skills']);
        } unset($u);

        $data=array_values($mx);
        return [
            'report_name'=>'Competency Matrix','description'=>'Employees × required competencies grid',
            'chart_type'=>'matrix',
            'chart_config'=>['cell_colors'=>['met'=>'#28a745','near'=>'#ffc107','gap'=>'#fd7e14','not_attempted'=>'#dc3545']],
            'skills_legend'=>array_values($skills),
            'summary'=>['total_employees'=>count($data),'total_skills'=>count($skills),
                'avg_competency_pct'=>count($data)>0?round(array_sum(array_column($data,'competency_pct'))/count($data),1):0],
            'data'=>$data,'meta'=>$this->buildMeta(count($data))
        ];
    }

    /* ═══════════════════════════════════════════════════
     * 3. SKILLS COVERAGE BY ROLE
     * ═══════════════════════════════════════════════════ */
    public function skillsCoverageByRole(): array
    {
        $rt=$this->rTable(); $ric=$this->rIdCol(); $rnc=$this->rNameCol(); $urc=$this->uRoleCol();
        $fGte=$this->fieldGte('cs.proficiency_taught','rs.proficiency_level');

        $q="SELECT r.{$ric} AS role_id, r.{$rnc} AS role_name,
              COUNT(DISTINCT rs.skill_id) AS total_req,
              COUNT(DISTINCT cs.skill_id) AS with_courses,
              COUNT(DISTINCT CASE WHEN cs.skill_id IS NOT NULL AND {$fGte} THEN rs.skill_id END) AS fully_cov,
              ROUND(COUNT(DISTINCT cs.skill_id)*100.0/NULLIF(COUNT(DISTINCT rs.skill_id),0),1) AS cov_pct,
              ROUND(COUNT(DISTINCT CASE WHEN cs.skill_id IS NOT NULL AND {$fGte} THEN rs.skill_id END)*100.0/NULLIF(COUNT(DISTINCT rs.skill_id),0),1) AS prof_cov_pct,
              (SELECT COUNT(*) FROM users u2 WHERE u2.{$urc}=r.{$ric} AND u2.tenant_id=r.tenant_id AND u2.is_active=1) AS emp_count
            FROM {$rt} r
            INNER JOIN role_skills rs ON r.{$ric}=rs.role_id AND rs.tenant_id=r.tenant_id
            INNER JOIN skills s ON rs.skill_id=s.skill_id AND s.is_active=1
            LEFT JOIN course_skills cs ON rs.skill_id=cs.skill_id AND cs.tenant_id=rs.tenant_id
            LEFT JOIN courses c ON cs.course_id=c.course_id AND c.tenant_id=cs.tenant_id AND c.is_active=1
            WHERE r.tenant_id=? GROUP BY r.{$ric},r.{$rnc} ORDER BY prof_cov_pct ASC LIMIT ? OFFSET ?";

        $stmt=$this->conn->prepare($q); $off=$this->getOffset();
        $stmt->bind_param("iii",$this->tenant_id,$this->per_page,$off); $stmt->execute(); $res=$stmt->get_result();

        $data=[];
        while($row=$res->fetch_assoc()){
            $uncov=(int)$row['total_req']-(int)$row['with_courses'];
            $data[]=['role_id'=>(int)$row['role_id'],'role_name'=>$row['role_name'],'employees_in_role'=>(int)$row['emp_count'],
                'total_required'=>(int)$row['total_req'],'skills_with_courses'=>(int)$row['with_courses'],
                'fully_covered'=>(int)$row['fully_cov'],'uncovered'=>$uncov,
                'course_coverage_pct'=>(float)($row['cov_pct']??0),'prof_coverage_pct'=>(float)($row['prof_cov_pct']??0),
                'risk_level'=>$uncov>3?'high':($uncov>0?'medium':'low')];
        }
        return [
            'report_name'=>'Skills Coverage by Role','description'=>'% of required skills covered by available courses per role',
            'chart_type'=>'horizontal_bar','chart_config'=>['x_axis'=>'prof_coverage_pct','y_axis'=>'role_name','color_by'=>'risk_level'],
            'summary'=>['total_roles'=>count($data),'fully_covered'=>count(array_filter($data,fn($d)=>$d['uncovered']===0)),
                'high_risk'=>count(array_filter($data,fn($d)=>$d['risk_level']==='high')),
                'avg_coverage'=>count($data)>0?round(array_sum(array_column($data,'prof_coverage_pct'))/count($data),1):0],
            'data'=>$data,'meta'=>$this->buildMeta(count($data))
        ];
    }

    /* ═══════════════════════════════════════════════════
     * 4. COMPETENCY DEVELOPMENT TREND
     * ═══════════════════════════════════════════════════ */
    public function competencyDevelopmentTrend(): array
    {
        $orgF=$this->buildOrgFilter(); $catF=$this->catFilter();
        $pExpr=($this->filters['period']??'quarter')==='month'
            ?"DATE_FORMAT(us.acquired_at,'%Y-%m')"
            :"CONCAT(YEAR(us.acquired_at),'-Q',QUARTER(us.acquired_at))";

        $q="SELECT {$pExpr} AS period,COUNT(*) AS acquired,COUNT(DISTINCT us.user_id) AS learners,
              COUNT(DISTINCT us.skill_id) AS skills,ROUND(AVG(us.assessment_score),1) AS avg_score,
              SUM(CASE WHEN us.proficiency_level IN('advanced','expert') THEN 1 ELSE 0 END) AS adv,
              SUM(CASE WHEN us.proficiency_level IN('awareness','basic') THEN 1 ELSE 0 END) AS bas
            FROM user_skills us INNER JOIN skills s ON us.skill_id=s.skill_id AND s.tenant_id=us.tenant_id
            LEFT JOIN users u ON us.user_id=u.id AND u.tenant_id=us.tenant_id
            WHERE us.tenant_id=? AND us.status='acquired' AND us.acquired_at>=DATE_SUB(CURDATE(),INTERVAL 24 MONTH)
            {$catF} {$orgF} GROUP BY period ORDER BY period ASC";

        $stmt=$this->conn->prepare($q); $stmt->bind_param("i",$this->tenant_id); $stmt->execute(); $res=$stmt->get_result();
        $data=[]; $cum=0;
        while($row=$res->fetch_assoc()){
            $a=(int)$row['acquired']; $cum+=$a;
            $data[]=['period'=>$row['period'],'skills_acquired'=>$a,'cumulative'=>$cum,
                'unique_learners'=>(int)$row['learners'],'unique_skills'=>(int)$row['skills'],
                'avg_score'=>(float)($row['avg_score']??0),'advanced_count'=>(int)$row['adv'],
                'basic_count'=>(int)$row['bas'],'growth_rate'=>0];
        }
        for($i=1;$i<count($data);$i++){$p=$data[$i-1]['skills_acquired'];$data[$i]['growth_rate']=$p>0?round(($data[$i]['skills_acquired']-$p)*100.0/$p,1):0;}

        return [
            'report_name'=>'Competency Development Trend','description'=>'Skill acquisition growth over time',
            'chart_type'=>'line','chart_config'=>['x_axis'=>'period','y_axes'=>['skills_acquired','cumulative'],'secondary_y'=>'cumulative'],
            'summary'=>['total_periods'=>count($data),'total_acquired'=>$cum,'latest_count'=>!empty($data)?end($data)['skills_acquired']:0,
                'avg_per_period'=>count($data)>0?round($cum/count($data),1):0],
            'data'=>$data,'meta'=>$this->buildMeta(count($data))
        ];
    }

    /* ═══════════════════════════════════════════════════
     * 5. ROLE REQUIREMENTS VS ACTUAL
     * For one role: every employee's status on each required skill.
     * ═══════════════════════════════════════════════════ */
    public function roleRequirementsVsActual(): array
    {
        $rt=$this->rTable(); $ric=$this->rIdCol(); $rnc=$this->rNameCol(); $urc=$this->uRoleCol();
        $orgF=$this->buildOrgFilter();

        $roleId=(int)($this->filters['role_id']??0);
        if($roleId===0){
            $dQ="SELECT u.{$urc} AS rid,COUNT(*) AS c FROM users u WHERE u.tenant_id=? AND u.is_active=1 AND u.{$urc} IS NOT NULL GROUP BY u.{$urc} ORDER BY c DESC LIMIT 1";
            $ds=$this->conn->prepare($dQ); $ds->bind_param("i",$this->tenant_id); $ds->execute();
            $dr=$ds->get_result()->fetch_assoc(); $roleId=(int)($dr['rid']??0);
        }

        // Role info
        $rs=$this->conn->prepare("SELECT {$ric} AS role_id,{$rnc} AS role_name FROM {$rt} WHERE {$ric}=? LIMIT 1");
        $rs->bind_param("i",$roleId); $rs->execute(); $roleInfo=$rs->get_result()->fetch_assoc();

        // Required skills
        $rq=$this->conn->prepare("SELECT rs.skill_id,s.name as skill_name, s.category_id as category,rs.proficiency_level,rs.is_required,rs.priority
            FROM role_skills rs INNER JOIN skills s ON rs.skill_id=s.skill_id AND s.is_active=1
            WHERE rs.role_id=? AND rs.tenant_id=? ORDER BY rs.priority DESC, skill_name");
        $rq->bind_param("ii",$roleId,$this->tenant_id); $rq->execute(); $rRes=$rq->get_result();
        $reqSkills=[]; while($r=$rRes->fetch_assoc()) $reqSkills[$r['skill_id']]=$r;

        if(empty($reqSkills)) return['report_name'=>'Role Requirements vs Actual','description'=>'No skills for this role',
            'role'=>$roleInfo,'required_skills'=>[],'data'=>[],'meta'=>$this->buildMeta(0)];

        $sIds=implode(',',array_keys($reqSkills));
        $q="SELECT u.id AS uid,CONCAT(u.first_name,' ',u.last_name) AS name,u.email,d.name AS dept,
              us.skill_id,us.proficiency_level AS actual,us.acquired_at,us.assessment_score
            FROM users u LEFT JOIN departments d ON u.department=d.id
            LEFT JOIN user_skills us ON u.id=us.user_id AND us.skill_id IN ({$sIds}) AND us.tenant_id=u.tenant_id AND us.status='acquired'
            WHERE u.tenant_id=? AND u.is_active=1 AND u.{$urc}=? {$orgF}
            ORDER BY u.last_name LIMIT ? OFFSET ?";
        $stmt=$this->conn->prepare($q); $off=$this->getOffset();
        $lim=min($this->per_page*count($reqSkills),5000);
        $stmt->bind_param("iiii",$this->tenant_id,$roleId,$lim,$off); $stmt->execute(); $res=$stmt->get_result();

        $emps=[];
        while($row=$res->fetch_assoc()){
            $uid=(int)$row['uid'];
            if(!isset($emps[$uid])) $emps[$uid]=['user_id'=>$uid,'learner_name'=>$row['name'],'email'=>$row['email'],
                'department_name'=>$row['dept']??'Unassigned','skills'=>[],'met'=>0,'gaps'=>0,'not_attempted'=>0];
            $sid=(int)$row['skill_id'];
            if($sid && isset($reqSkills[$sid])){
                $gs=$this->gapSt($this->profNum($reqSkills[$sid]['proficiency_level']),$row['actual']?$this->profNum($row['actual']):0);
                $emps[$uid]['skills'][]=['skill_id'=>$sid,'skill_name'=>$reqSkills[$sid]['skill_name'],
                    'required_level'=>$reqSkills[$sid]['proficiency_level'],'actual_level'=>$row['actual']??'none',
                    'status'=>$gs['status'],'score'=>$row['assessment_score']?(float)$row['assessment_score']:null,'acquired_at'=>$row['acquired_at']];
                if($gs['status']==='met') $emps[$uid]['met']++; else $emps[$uid]['gaps']++;
            }
        }
        // Fill missing
        foreach($emps as &$e){
            $covered=array_column($e['skills'],'skill_id');
            foreach($reqSkills as $sid=>$sk){
                if(!in_array($sid,$covered)){
                    $e['skills'][]=['skill_id'=>(int)$sid,'skill_name'=>$sk['skill_name'],'required_level'=>$sk['proficiency_level'],
                        'actual_level'=>'none','status'=>'not_attempted','score'=>null,'acquired_at'=>null];
                    $e['not_attempted']++; $e['gaps']++;
                }
            }
            $tot=count($reqSkills);
            $e['compliance_pct']=$tot>0?round($e['met']*100.0/$tot,1):0;
        } unset($e);

        $data=array_values($emps);
        return [
            'report_name'=>'Role Requirements vs Actual',
            'description'=>'Skill compliance for role: '.($roleInfo['role_name']??'Unknown'),
            'chart_type'=>'matrix','role'=>$roleInfo,'required_skills'=>array_values($reqSkills),
            'summary'=>['role_name'=>$roleInfo['role_name']??'Unknown','total_employees'=>count($data),
                'total_required_skills'=>count($reqSkills),
                'avg_compliance_pct'=>count($data)>0?round(array_sum(array_column($data,'compliance_pct'))/count($data),1):0,
                'fully_compliant'=>count(array_filter($data,fn($d)=>$d['gaps']===0))],
            'data'=>$data,'meta'=>$this->buildMeta(count($data))
        ];
    }

    /* ═══════════════════════════════════════════════════
     * 6. SKILLS INVENTORY
     * Complete catalog of all skills with holder counts.
     * ═══════════════════════════════════════════════════ */
    public function skillsInventory(): array
    {
        $orgF=$this->buildOrgFilter(); $catF=$this->catFilter(); $urc=$this->uRoleCol();
        $searchF='';
        if(!empty($this->filters['search'])){
            $s=$this->conn->real_escape_string($this->filters['search']);
            $searchF="AND (s.skill_name LIKE '%{$s}%' OR s.skill_code LIKE '%{$s}%')";
        }

        $q="SELECT s.skill_id,s.skill_name,s.skill_code,s.category,s.description,
              COUNT(DISTINCT us.user_id) AS holders,
              SUM(CASE WHEN us.proficiency_level='expert' THEN 1 ELSE 0 END) AS expert_ct,
              SUM(CASE WHEN us.proficiency_level='advanced' THEN 1 ELSE 0 END) AS adv_ct,
              SUM(CASE WHEN us.proficiency_level='intermediate' THEN 1 ELSE 0 END) AS int_ct,
              SUM(CASE WHEN us.proficiency_level='basic' THEN 1 ELSE 0 END) AS bas_ct,
              SUM(CASE WHEN us.proficiency_level='awareness' THEN 1 ELSE 0 END) AS awr_ct,
              COUNT(DISTINCT rs.role_id) AS roles_req,
              COUNT(DISTINCT cs.course_id) AS courses_teach,
              ROUND(AVG(us.assessment_score),1) AS avg_score,
              MAX(us.acquired_at) AS latest_acq,
              (SELECT COUNT(DISTINCT u2.id) FROM users u2
               INNER JOIN role_skills rs2 ON u2.{$urc}=rs2.role_id AND rs2.tenant_id=u2.tenant_id
               WHERE rs2.skill_id=s.skill_id AND u2.tenant_id=s.tenant_id AND u2.is_active=1) AS needing
            FROM skills s
            LEFT JOIN user_skills us ON s.skill_id=us.skill_id AND us.tenant_id=s.tenant_id AND us.status='acquired'
            LEFT JOIN users u ON us.user_id=u.id AND u.tenant_id=s.tenant_id
            LEFT JOIN role_skills rs ON s.skill_id=rs.skill_id AND rs.tenant_id=s.tenant_id
            LEFT JOIN course_skills cs ON s.skill_id=cs.skill_id AND cs.tenant_id=s.tenant_id
            WHERE s.tenant_id=? AND s.is_active=1 {$catF} {$searchF} {$orgF}
            GROUP BY s.skill_id,s.skill_name,s.skill_code,s.category,s.description
            ORDER BY holders DESC LIMIT ? OFFSET ?";

        $stmt=$this->conn->prepare($q); $off=$this->getOffset();
        $stmt->bind_param("iii",$this->tenant_id,$this->per_page,$off); $stmt->execute(); $res=$stmt->get_result();

        $data=[];
        while($row=$res->fetch_assoc()){
            $h=(int)$row['holders']; $n=(int)$row['needing'];
            $orgCov=$n>0?round($h*100.0/$n,1):($h>0?100.0:0);
            $data[]=['skill_id'=>(int)$row['skill_id'],'skill_name'=>$row['skill_name'],'skill_code'=>$row['skill_code'],
                'category'=>$row['category'],'description'=>$row['description'],'total_holders'=>$h,
                'proficiency_breakdown'=>['expert'=>(int)$row['expert_ct'],'advanced'=>(int)$row['adv_ct'],
                    'intermediate'=>(int)$row['int_ct'],'basic'=>(int)$row['bas_ct'],'awareness'=>(int)$row['awr_ct']],
                'roles_requiring'=>(int)$row['roles_req'],'courses_teaching'=>(int)$row['courses_teach'],
                'total_needing'=>$n,'org_coverage_pct'=>$orgCov,'avg_score'=>(float)($row['avg_score']??0),
                'latest_acquisition'=>$row['latest_acq'],
                'has_course_gap'=>(int)$row['courses_teach']===0&&(int)$row['roles_req']>0];
        }

        $cntQ="SELECT COUNT(*) AS total FROM skills WHERE tenant_id=? AND is_active=1 {$catF} {$searchF}";
        $total=$this->getTotalCount($cntQ,"i",[$this->tenant_id]);

        // Category summary
        $catQ=$this->conn->prepare("SELECT category_id as category,COUNT(*) AS cnt FROM skills WHERE tenant_id=? AND is_active=1 GROUP BY category ORDER BY cnt DESC");
        $catQ->bind_param("i",$this->tenant_id); $catQ->execute(); $catR=$catQ->get_result();
        $cats=[]; while($r=$catR->fetch_assoc()) $cats[]=['category'=>$r['category']??'Uncategorized','count'=>(int)$r['cnt']];

        return [
            'report_name'=>'Skills Inventory Report','description'=>'Complete skills catalog with acquisition statistics',
            'chart_type'=>'bar','chart_config'=>['x_axis'=>'skill_name','y_axes'=>['total_holders','total_needing']],
            'summary'=>['total_skills'=>$total,'categories'=>$cats,
                'skills_with_no_courses'=>count(array_filter($data,fn($d)=>$d['has_course_gap'])),
                'avg_org_coverage'=>count($data)>0?round(array_sum(array_column($data,'org_coverage_pct'))/count($data),1):0],
            'data'=>$data,'meta'=>$this->buildMeta($total)
        ];
    }
}
