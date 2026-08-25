<?php
require_once __DIR__ . '/ReportService.php';

class ReportScheduler
{
    private $conn;
    private int $tenant_id;

    public function __construct($conn, int $tenant_id) { $this->conn = $conn; $this->tenant_id = $tenant_id; }

    public function scheduleReport(array $data): array
    {
        $userId=(int)($data['user_id']??0); $reportKey=$data['report_key']??'';
        $frequency=$data['frequency']??'weekly'; $recipients=$data['recipients']??'';
        $params=json_encode($data['params']??[]); $format=$data['format']??'csv';
        $dow=(int)($data['day_of_week']??1); $dom=(int)($data['day_of_month']??1);
        $tod=$data['time_of_day']??'08:00';
        if(!$reportKey||!$userId) return ['success'=>false,'message'=>'report_key and user_id required'];

        $ck=$this->conn->prepare("SELECT id FROM scheduled_reports WHERE user_id=? AND tenant_id=? AND report_key=? AND is_active=1");
        $ck->bind_param("iis",$userId,$this->tenant_id,$reportKey); $ck->execute();
        $ex=$ck->get_result()->fetch_assoc(); $ck->close();

        if($ex){
            $u=$this->conn->prepare("UPDATE scheduled_reports SET frequency=?,recipients=?,params=?,export_format=?,day_of_week=?,day_of_month=?,time_of_day=?,updated_at=NOW() WHERE id=?");
            $u->bind_param("ssssiisi",$frequency,$recipients,$params,$format,$dow,$dom,$tod,$ex['id']);
            $u->execute(); $u->close();
            return ['success'=>true,'id'=>(int)$ex['id'],'action'=>'updated'];
        }

        $i=$this->conn->prepare("INSERT INTO scheduled_reports (user_id,tenant_id,report_key,frequency,recipients,params,export_format,day_of_week,day_of_month,time_of_day,is_active,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,1,NOW())");
        $i->bind_param("iisssssiss",$userId,$this->tenant_id,$reportKey,$frequency,$recipients,$params,$format,$dow,$dom,$tod);
        $i->execute(); $id=$this->conn->insert_id; $i->close();
        return ['success'=>true,'id'=>$id,'action'=>'created'];
    }

    public function getScheduledReports(int $userId=0): array
    {
        $w="tenant_id=? AND is_active=1"; $t="i"; $b=[$this->tenant_id];
        if($userId>0){$w.=" AND user_id=?";$t.="i";$b[]=$userId;}
        $s=$this->conn->prepare("SELECT id,user_id,report_key,frequency,recipients,params,export_format,day_of_week,day_of_month,time_of_day,last_run_at,next_run_at,last_run_status,created_at FROM scheduled_reports WHERE {$w} ORDER BY created_at DESC");
        $s->bind_param($t,...$b); $s->execute(); $r=$s->get_result();
        $out=[];
        while($row=$r->fetch_assoc()){$row['params']=json_decode($row['params'],true)??[];$row['id']=(int)$row['id'];$out[]=$row;}
        return $out;
    }

    public function runScheduledReports(): array
    {
        $results=[];
        $s=$this->conn->prepare("SELECT * FROM scheduled_reports WHERE tenant_id=? AND is_active=1 AND (next_run_at IS NULL OR next_run_at<=NOW())");
        $s->bind_param("i",$this->tenant_id); $s->execute(); $r=$s->get_result();
        $svc=new ReportService($this->conn); $svc->setContext($this->tenant_id,0);

        while($sch=$r->fetch_assoc()){
            $sid=(int)$sch['id']; $p=json_decode($sch['params'],true)??[];
            $recip=array_filter(array_map('trim',explode(',',$sch['recipients'])));
            try{
                $res=$svc->runReport($sch['report_key'],$p);
                if(!$res||isset($res['error'])){$this->updStatus($sid,'error',$sch['frequency']);$results[]=['id'=>$sid,'status'=>'error'];continue;}
                if(!empty($recip)){
                    $exp=$svc->exportReport($sch['report_key'],$p,$sch['export_format']??'csv');
                    if($exp&&$exp['success']) $this->mailReport($recip,$res['report_name']??$sch['report_key'],$exp['content'],$exp['filename'],$sch['export_format']??'csv');
                }
                $this->updStatus($sid,'success',$sch['frequency']);
                $results[]=['id'=>$sid,'status'=>'success','sent_to'=>count($recip)];
            }catch(\Exception $e){
                error_log("Sched #{$sid}: ".$e->getMessage());
                $this->updStatus($sid,'error',$sch['frequency']);
                $results[]=['id'=>$sid,'status'=>'error','msg'=>$e->getMessage()];
            }
        }
        return ['executed'=>count($results),'results'=>$results,'run_at'=>date('Y-m-d H:i:s')];
    }

    public function complianceAlertNotifications(): array
    {
        $s=$this->conn->prepare("SELECT mgr.id AS mid,mgr.email AS me,CONCAT(mgr.first_name,' ',mgr.last_name) AS mn,
            CONCAT(u.first_name,' ',u.last_name) AS ln,c.title AS cn,ce.due_date,DATEDIFF(CURDATE(),ce.due_date) AS dov
            FROM course_enrollments ce INNER JOIN users u ON ce.user_id=u.id AND u.tenant_id=ce.tenant_id
            INNER JOIN users mgr ON u.manager_id=mgr.id AND mgr.tenant_id=ce.tenant_id
            INNER JOIN courses c ON ce.course_id = c.course_id
            WHERE ce.tenant_id=? AND ce.is_mandatory=1 AND ce.status!='completed' AND ce.due_date<CURDATE() ORDER BY mgr.id,dov DESC");
        $s->bind_param("i",$this->tenant_id); $s->execute(); $r=$s->get_result();

        $alerts=[];
        while($row=$r->fetch_assoc()){
            $m=(int)$row['mid'];
            if(!isset($alerts[$m]))$alerts[$m]=['manager_id'=>$m,'email'=>$row['me'],'name'=>$row['mn'],'items'=>[]];
            $alerts[$m]['items'][]=['learner'=>$row['ln'],'course'=>$row['cn'],'due_date'=>$row['due_date'],'days_overdue'=>(int)$row['dov']];
        }
        $sent=0;
        foreach($alerts as $a){
            $n=count($a['items']); $subj="Compliance Alert: {$n} overdue";
            $body="Dear {$a['name']},\n\nOverdue mandatory training:\n\n";
            foreach($a['items'] as $i) $body.="- {$i['learner']}: {$i['course']} (due {$i['due_date']}, {$i['days_overdue']}d overdue)\n";
            $this->mail($a['email'],$subj,$body); $sent++;
        }
        return ['managers_notified'=>$sent,'total_overdue'=>array_sum(array_map(fn($a)=>count($a['items']),$alerts)),'alerts'=>array_values($alerts)];
    }

    public function executiveDigest(string $period='weekly'): array
    {
        $days=$period==='monthly'?30:7; $label=$period==='monthly'?'Monthly':'Weekly'; $kpis=[];
        $qs=['new_enrollments'=>"SELECT COUNT(*) v FROM course_enrollments WHERE tenant_id=? AND enrolled_at>=DATE_SUB(CURDATE(),INTERVAL {$days} DAY)",
            'completions'=>"SELECT COUNT(*) v FROM course_enrollments WHERE tenant_id=? AND status='completed' AND completed_at>=DATE_SUB(CURDATE(),INTERVAL {$days} DAY)",
            'compliance_rate'=>"SELECT ROUND(SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END)*100.0/NULLIF(COUNT(*),0),1) v FROM course_enrollments WHERE tenant_id=? AND is_mandatory=1",
            'overdue'=>"SELECT COUNT(*) v FROM course_enrollments WHERE tenant_id=? AND is_mandatory=1 AND status!='completed' AND due_date<CURDATE()",
            'active_learners'=>"SELECT COUNT(DISTINCT user_id) v FROM course_enrollments WHERE tenant_id=? AND updated_at>=DATE_SUB(CURDATE(),INTERVAL {$days} DAY)",
            'avg_score'=>"SELECT ROUND(AVG(score),1) v FROM assessment_attempts WHERE tenant_id=? AND completed_at>=DATE_SUB(CURDATE(),INTERVAL {$days} DAY) AND status IN('completed','passed')"];
        foreach($qs as $k=>$sql){$st=$this->conn->prepare($sql);$st->bind_param("i",$this->tenant_id);$st->execute();$kpis[$k]=$st->get_result()->fetch_assoc()['v']??0;if(is_numeric($kpis[$k]))$kpis[$k]=$kpis[$k]+0;}

        $emails=$this->getExecEmails();
        $subj="LMS {$label} Digest - ".date('M j, Y');
        $body="LMS {$label} Performance Summary\n".str_repeat('=',40)."\n\n";
        foreach($kpis as $k=>$v) $body.=str_pad(str_replace('_',' ',ucfirst($k)),25).": {$v}\n";
        $sent=0; foreach($emails as $e){$this->mail($e,$subj,$body);$sent++;}
        return ['period'=>$period,'kpis'=>$kpis,'recipients_sent'=>$sent,'generated_at'=>date('Y-m-d H:i:s')];
    }

    public function deleteSchedule(int $id, int $uid): bool {
        $s=$this->conn->prepare("UPDATE scheduled_reports SET is_active=0 WHERE id=? AND user_id=? AND tenant_id=?");
        $s->bind_param("iii",$id,$uid,$this->tenant_id); $s->execute(); return $s->affected_rows>0;
    }

    public function toggleSchedule(int $id, int $uid, bool $active): bool {
        $v=$active?1:0;$s=$this->conn->prepare("UPDATE scheduled_reports SET is_active=? WHERE id=? AND user_id=? AND tenant_id=?");
        $s->bind_param("iiii",$v,$id,$uid,$this->tenant_id); $s->execute(); return $s->affected_rows>0;
    }

    private function updStatus(int $id, string $st, string $freq): void {
        $next=match($freq){'daily'=>date('Y-m-d H:i:s',strtotime('+1 day')),'weekly'=>date('Y-m-d H:i:s',strtotime('+1 week')),'monthly'=>date('Y-m-d H:i:s',strtotime('+1 month')),default=>date('Y-m-d H:i:s',strtotime('+1 week'))};
        $s=$this->conn->prepare("UPDATE scheduled_reports SET last_run_at=NOW(),last_run_status=?,next_run_at=? WHERE id=?");
        $s->bind_param("ssi",$st,$next,$id); $s->execute();
    }
    private function getExecEmails(): array {
        $s=$this->conn->prepare("SELECT email FROM users WHERE tenant_id=? AND is_active=1 AND role IN('admin','superadmin','executive','hr_manager') LIMIT 20");
        $s->bind_param("i",$this->tenant_id); $s->execute(); $r=$s->get_result(); $e=[];
        while($row=$r->fetch_assoc()) $e[]=$row['email']; return $e;
    }
    private function mailReport(array $to,string $name,string $c,string $f,string $fmt): void {
        $subj="Scheduled Report: {$name} - ".date('M j'); $body="Attached: {$name}\nFormat: ".strtoupper($fmt);
        foreach($to as $e) $this->mail($e,$subj,$body);
    }
    private function mail(string $to, string $subj, string $body): bool {
        error_log("[ReportScheduler] Email to: {$to} | Subject: {$subj}");
        return @mail($to,$subj,$body,"From: noreply@lms.local\r\nContent-Type: text/plain; charset=UTF-8\r\n");
    }
}
