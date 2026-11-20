<?php
const PAYROLL_FIELDS=['base_salary'=>'Salaire de base brut','bonus'=>'Primes brutes','social_deductions'=>'Cotisations salariales','income_tax'=>'Impôt sur le revenu','other_deductions'=>'Autres retenues'];
function payrollMonth(string $value): string {
    if(!preg_match('/^(19|20|21)\d{2}-(0[1-9]|1[0-2])$/D',$value))throw new DomainException('Période invalide : choisissez un mois.');return $value;
}
function employeeSalaryFromPost(?array $old): array {
    $out=[];foreach(['base_salary','default_bonus'] as $key){if(!array_key_exists($key,$_POST)){$out[$key]=$old[$key]??null;continue;}$raw=field($key,20);$out[$key]=$raw===''?null:decimalString(decimalInt($raw,2,9),2);}return $out;
}
function payrollTotals(array $record): array {
    $v=[];foreach(PAYROLL_FIELDS as $k=>$label)$v[$k]=cents($record[$k]);$gross=$v['base_salary']+$v['bonus'];$deductions=$v['social_deductions']+$v['income_tax']+$v['other_deductions'];
    if($gross>999999999999||$deductions>$gross)throw new DomainException('Les retenues ne peuvent pas dépasser le brut.');
    return ['gross'=>$gross,'deductions'=>$deductions,'net'=>$gross-$deductions];
}
function employeeActivity(int $id,string $month): array {
    payrollMonth($month);$start=$month.'-01';$end=(new DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
    $days=all('SELECT attendance_date,state,hours,note FROM attendance WHERE employee_id=? AND attendance_date>=? AND attendance_date<? ORDER BY attendance_date',[$id,$start,$end]);$counts=['Présent'=>0,'Absent'=>0,'Congé'=>0];$hours=0;
    foreach($days as $d){$counts[$d['state']]++;if($d['state']==='Présent')$hours+=decimalInt($d['hours'],2,4);}
    $tasks=all("SELECT t.id,t.state,t.due_date,t.estimated_hours,MAX(CASE WHEN ev.state='Terminée' THEN ev.created_at END) completed_at FROM team_tasks t LEFT JOIN task_events ev ON ev.task_id=t.id WHERE t.employee_id=? GROUP BY t.id,t.state,t.due_date,t.estimated_hours",[$id]);$open=0;$done=0;$late=0;
    foreach($tasks as $t){if(!in_array($t['state'],['Terminée','Annulée'],true)){$open++;if($t['due_date']&&$t['due_date']<date('Y-m-d'))$late++;}if($t['state']==='Terminée'&&$t['completed_at']){$day=(new DateTimeImmutable($t['completed_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d');if($day>=$start&&$day<$end)$done++;}}
    return ['days'=>$days,'counts'=>$counts,'hours'=>$hours,'done'=>$done,'open'=>$open,'late'=>$late];
}
function hrSnapshot(array $employee): array {
    $meta=array_column(all('SELECT meta_key,meta_value FROM app_meta'),'meta_value','meta_key');
    if(trim($meta['company_address']??'')==='')throw new DomainException('Complétez l’adresse de l’entreprise dans Paramètres.');
    if(!$employee['hire_date']||trim($employee['job_title'])==='')throw new DomainException('Complétez le poste et la date d’entrée de l’employé.');
    return ['issuer'=>array_intersect_key($meta,array_flip(['company_name','company_address','company_phone','company_email','company_ice','company_if','company_rc'])),'employee'=>array_intersect_key($employee,array_flip(['id','full_name','job_title','department','hire_date','base_salary','default_bonus'])),'timezone'=>date_default_timezone_get()];
}
function payrollAction(string $action): bool {
    if($action==='payroll_save'){
        $id=(int)($_POST['id']??0);$employeeId=validId('employee_id');$period=payrollMonth(field('period',7,true));$key=$id?'':requestKey();$values=[];foreach(PAYROLL_FIELDS as $k=>$label)$values[$k]=decimalString(decimalInt(field($k,20,true),2,9),2);payrollTotals($values);$note=field('note',1500);
        db()->beginTransaction();try{
            if(!row('SELECT id FROM employees WHERE id=? FOR UPDATE',[$employeeId]))throw new DomainException('Employé introuvable.');
            $old=$id?row('SELECT * FROM payroll_records WHERE id=? FOR UPDATE',[$id]):false;
            if($id&&(!$old||(int)$old['employee_id']!==$employeeId))throw new DomainException('Bulletin introuvable.');
            if($old&&($old['state']!=='Brouillon'||$old['period']!==$period))throw new DomainException('Une fiche émise ou sa période ne peut plus être modifiée.');
            if($old&&(int)($_POST['revision']??0)!==(int)$old['revision'])throw new DomainException('Le brouillon a changé. Rechargez la fiche.');
            if(!$id&&($existing=row('SELECT id,employee_id,period FROM payroll_records WHERE request_key=? FOR UPDATE',[$key]))){if((int)$existing['employee_id']!==$employeeId||$existing['period']!==$period)throw new DomainException('Référence de saisie déjà utilisée.');db()->rollBack();go(url('payroll_form',['id'=>$existing['id']]));}
            if(!$id&&row('SELECT id FROM payroll_records WHERE employee_id=? AND period=?',[$employeeId,$period]))throw new DomainException('Une fiche existe déjà pour ce mois. Ouvrez-la depuis l’employé.');
            if($id)query('UPDATE payroll_records SET base_salary=?,bonus=?,social_deductions=?,income_tax=?,other_deductions=?,note=?,revision=revision+1 WHERE id=?',[...array_values($values),$note,$id]);
            else{query('INSERT INTO payroll_records(employee_id,period,base_salary,bonus,social_deductions,income_tax,other_deductions,note,request_key) VALUES(?,?,?,?,?,?,?,?,?)',[$employeeId,$period,...array_values($values),$note,$key]);$id=(int)db()->lastInsertId();}
            audit('Brouillon de paie enregistré','paie',$id);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Brouillon enregistré. Vérifiez les montants avant émission.');go(url('payroll_form',['id'=>$id]));
    }
    if($action==='payroll_issue'){
        $id=validId();$candidate=row('SELECT employee_id FROM payroll_records WHERE id=?',[$id]);if(!$candidate)throw new DomainException('Bulletin introuvable.');
        if(($_POST['issue_confirmed']??'')!=='1')throw new DomainException('Confirmez la validation des montants et des retenues.');
        db()->beginTransaction();try{
            $emp=row('SELECT * FROM employees WHERE id=? FOR UPDATE',[$candidate['employee_id']]);$record=row('SELECT * FROM payroll_records WHERE id=? FOR UPDATE',[$id]);
            if($record['state']==='Émis'){db()->rollBack();go(url('payroll_form',['id'=>$id]));}
            if((int)($_POST['revision']??0)!==(int)$record['revision'])throw new DomainException('Le brouillon a changé. Rechargez la fiche.');
            if($record['period']>date('Y-m'))throw new DomainException('Une fiche d’un mois futur ne peut pas être émise.');
            $snapshot=hrSnapshot($emp);if(substr($emp['hire_date'],0,7)>$record['period'])throw new DomainException('La période précède l’entrée de l’employé.');
            $snapshot['payroll']=$record;$snapshot['totals']=payrollTotals($record);$snapshot['activity']=employeeActivity((int)$emp['id'],$record['period']);unset($snapshot['activity']['days']);
            query("UPDATE payroll_records SET state='Émis',number=?,snapshot=?,issued_at=UTC_TIMESTAMP(),revision=revision+1 WHERE id=?",[nextDocumentNumber('payroll'),json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$id]);audit('Fiche de paie émise','paie',$id);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Fiche de paie émise et conservée. Aucun paiement effectué.');go(url('payroll_form',['id'=>$id]));
    }
    if($action==='attestation_issue'){
        $id=validId('employee_id');$kind=enumField('kind',['work','salary']);$signatory=field('signatory',120,true);$place=field('place',120,true);$key=requestKey();
        if(($_POST['facts_confirmed']??'')!=='1')throw new DomainException('Confirmez l’exactitude des informations de l’attestation.');
        db()->beginTransaction();try{
            $emp=row('SELECT * FROM employees WHERE id=? FOR UPDATE',[$id]);if(!$emp)throw new DomainException('Employé introuvable.');
            $existing=row('SELECT id,employee_id,kind FROM employee_documents WHERE request_key=? FOR UPDATE',[$key]);if($existing){if((int)$existing['employee_id']!==$id||$existing['kind']!==$kind)throw new DomainException('Référence de saisie déjà utilisée.');db()->rollBack();go(url('hr_document',['id'=>$existing['id']]));}
            if($emp['archived']||$emp['employment_state']!=='Actif'||($emp['hire_date']&&$emp['hire_date']>date('Y-m-d')))throw new DomainException('Une attestation de situation actuelle exige un employé actif, déjà entré.');
            if($kind==='salary'&&$emp['base_salary']===null)throw new DomainException('Renseignez le salaire de base avant l’attestation.');
            $snapshot=hrSnapshot($emp)+['kind'=>$kind,'signatory'=>$signatory,'place'=>$place,'issued_date'=>date('Y-m-d')];
            query('INSERT INTO employee_documents(employee_id,kind,number,snapshot,request_key) VALUES(?,?,?,?,?)',[$id,$kind,nextDocumentNumber($kind),json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$key]);$docId=(int)db()->lastInsertId();audit('Attestation émise','document RH',$docId);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Attestation conservée. Vérifiez, signez et apposez le cachet avant utilisation.');go(url('hr_document',['id'=>$docId]));
    }
    return false;
}
