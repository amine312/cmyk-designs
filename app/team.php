<?php
const TASK_STATES=['À faire','En cours','Bloquée','Terminée','Annulée'];
const EMPLOYMENT_STATES=['Actif','Suspendu','Sorti'];
function loginAllowed(array $account): bool {
    if(schemaNumber()<4)return true;
    if(!isset(ROLES[$account['role']])||($account['role']==='employee'&&!$account['employee_id'])||!$account['is_active']||$account['activation_hash']!==null)return false;
    if($account['employee_id'])return (bool)row("SELECT id FROM employees WHERE id=? AND archived=0 AND employment_state='Actif'",[$account['employee_id']]);return true;
}
function accountToken(int $id,string $email): void {
    $code=bin2hex(random_bytes(32));query('UPDATE users SET activation_hash=?,activation_expires=UTC_TIMESTAMP()+INTERVAL 1 DAY,session_version=session_version+1 WHERE id=?',[hash('sha256',$code),$id]);
    $_SESSION['activation_notice']=['email'=>$email,'code'=>$code];
}
function teamAction(string $action): bool {
    if($action==='employee_save'){
        $id=(int)($_POST['id']??0);$name=field('full_name',120,true);$job=field('job_title',120);$department=field('department',100);$phone=field('phone',40);$email=emailField('email');$hire=dateField('hire_date');$state=enumField('employment_state',EMPLOYMENT_STATES);$notes=field('notes',5000);
        db()->beginTransaction();try{
            $admins=lockAdministrators();$old=$id?row('SELECT * FROM employees WHERE id=? FOR UPDATE',[$id]):null;if($id&&!$old)throw new DomainException('Employé introuvable.');
            if($id&&$state!=='Actif'&&(int)scalar("SELECT COUNT(*) FROM team_tasks WHERE employee_id=? AND state NOT IN ('Terminée','Annulée')",[$id])>0)throw new DomainException('Réaffectez ou clôturez les tâches ouvertes avant de suspendre ou sortir cet employé.');
            $account=$id?row('SELECT id,role FROM users WHERE employee_id=? FOR UPDATE',[$id]):false;
            if($state!=='Actif'&&$account&&$account['role']==='admin'&&count($admins)===1&&(int)$admins[0]['id']===(int)$account['id'])throw new DomainException('Le dernier administrateur actif doit être conservé.');
            $salary=employeeSalaryFromPost($old);
            $values=[$name,$job,$department,$phone,$email,$hire,$state,$notes];
            if($id)query('UPDATE employees SET full_name=?,job_title=?,department=?,phone=?,email=?,hire_date=?,employment_state=?,notes=? WHERE id=?',[...$values,$id]);else{query('INSERT INTO employees(full_name,job_title,department,phone,email,hire_date,employment_state,notes) VALUES(?,?,?,?,?,?,?,?)',$values);$id=(int)db()->lastInsertId();}
            query('UPDATE employees SET base_salary=?,default_bonus=? WHERE id=?',[$salary['base_salary'],$salary['default_bonus'],$id]);
            if($account){query('UPDATE users SET name=? WHERE id=?',[$name,$account['id']]);if($state!=='Actif')query('UPDATE users SET is_active=0,session_version=session_version+1 WHERE id=?',[$account['id']]);}
            audit('Employé enregistré','équipe',$id);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Employé enregistré.');go(url('employee_form',['id'=>$id]));
    }
    if($action==='employee_archive'){
        $id=validId();$state=($_POST['archived']??'')==='1'?1:0;
        db()->beginTransaction();try{$admins=lockAdministrators();$employee=row('SELECT * FROM employees WHERE id=? FOR UPDATE',[$id]);if(!$employee)throw new DomainException('Employé introuvable.');$account=row('SELECT id,role FROM users WHERE employee_id=? FOR UPDATE',[$id]);
            if($state&&(int)scalar("SELECT COUNT(*) FROM team_tasks WHERE employee_id=? AND state NOT IN ('Terminée','Annulée')",[$id])>0)throw new DomainException('Réaffectez ou clôturez les tâches ouvertes avant archivage.');
            if($state&&$account&&$account['role']==='admin'&&count($admins)===1&&(int)$admins[0]['id']===(int)$account['id'])throw new DomainException('Le dernier administrateur actif doit être conservé.');
            query('UPDATE employees SET archived=? WHERE id=?',[$state,$id]);if($state&&$account)query('UPDATE users SET is_active=0,session_version=session_version+1 WHERE id=?',[$account['id']]);audit($state?'Employé archivé':'Employé réactivé','équipe',$id);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash($state?'Employé archivé et accès suspendu.':'Employé réactivé. Réactivez son compte séparément si nécessaire.');go(url('employees'));
    }
    if($action==='account_save'){
        $id=(int)($_POST['id']??0);$employeeId=max(0,(int)($_POST['employee_id']??0));$email=strtolower(emailField('email'));if($email==='')throw new DomainException('E-mail de connexion obligatoire.');$role=enumField('role',array_keys(ROLES));$enabled=($_POST['is_active']??'')==='1'?1:0;
        db()->beginTransaction();try{
            $admins=lockAdministrators();$employee=$employeeId?activeEmployee($employeeId,true):null;$old=$id?row('SELECT * FROM users WHERE id=? FOR UPDATE',[$id]):false;
            if($id&&!$old)throw new DomainException('Compte introuvable.');if(!$id&&!$employee)throw new DomainException('Choisissez un employé actif.');
            if($old&&(int)$old['employee_id']!==$employeeId)throw new DomainException('Le rattachement du compte à l’employé ne peut pas être changé.');
            if($old&&$old['role']==='admin'&&($role!=='admin'||!$enabled)&&count($admins)===1&&(int)$admins[0]['id']===$id)throw new DomainException('Le dernier administrateur actif doit être conservé.');
            if($role==='employee'&&!$employeeId)throw new DomainException('Le rôle employé exige une fiche employé liée.');
            if(row('SELECT id FROM users WHERE email=? AND id<>?',[$email,$id]))throw new DomainException('Cet e-mail de connexion est déjà utilisé.');
            if($employeeId&&row('SELECT id FROM users WHERE employee_id=? AND id<>?',[$employeeId,$id]))throw new DomainException('Un compte est déjà lié à cet employé.');
            $name=$employee?$employee['full_name']:($old['name']??'');
            if($id){$changed=$old['role']!==$role||(int)$old['is_active']!==$enabled||$old['email']!==$email;query('UPDATE users SET name=?,email=?,role=?,is_active=?,session_version=session_version+? WHERE id=?',[$name,$email,$role,$enabled,$changed?1:0,$id]);}
            else{query('INSERT INTO users(name,email,password_hash,employee_id,role,is_active) VALUES(?,?,?,?,?,?)',[$name,$email,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT),$employeeId,$role,$enabled]);$id=(int)db()->lastInsertId();accountToken($id,$email);}
            audit('Compte enregistré','compte',$id);db()->commit();
        }catch(PDOException $ex){if(db()->inTransaction())db()->rollBack();if(($ex->errorInfo[1]??0)===1062)throw new DomainException('E-mail ou employé déjà lié à un compte.');throw $ex;}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Compte enregistré. Les changements d’accès invalident ses anciennes sessions.');go(url('accounts'));
    }
    if($action==='account_invite'){
        $id=validId();db()->beginTransaction();try{
            $admins=lockAdministrators();$u=row('SELECT * FROM users WHERE id=? FOR UPDATE',[$id]);if(!$u||!$u['employee_id'])throw new DomainException('Ce compte initial utilise son mot de passe personnel.');
            if($u['role']==='admin'&&count($admins)===1&&(int)$admins[0]['id']===$id)throw new DomainException('Le dernier administrateur actif ne peut pas être mis en attente.');
            if(!$u['is_active'])throw new DomainException('Réactivez le compte avant de générer une activation.');accountToken($id,$u['email']);audit('Activation renouvelée','compte',$id);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Ancien code invalidé. Le nouveau code expire dans 24 heures.');go(url('accounts'));
    }
    if($action==='task_save'){
        $id=(int)($_POST['id']??0);$employeeId=validId('employee_id');$orderId=optionalOrderId();$title=field('title',190,true);$description=field('description',5000);$state=enumField('state',TASK_STATES);$priority=enumField('priority',PRIORITIES);$due=dateField('due_date');$hours=decimalInt(field('estimated_hours',12,true),2,4);if($hours>999999)throw new DomainException('Estimation trop élevée.');
        db()->beginTransaction();try{activeEmployee($employeeId,true);$old=$id?row('SELECT * FROM team_tasks WHERE id=? FOR UPDATE',[$id]):false;if($id&&!$old)throw new DomainException('Tâche introuvable.');if($orderId&&!row('SELECT id FROM orders WHERE id=?',[$orderId]))throw new DomainException('Commande introuvable.');
            $values=[$employeeId,$orderId,$title,$description,$state,$priority,$due,decimalString($hours,2)];
            if($id)query('UPDATE team_tasks SET employee_id=?,order_id=?,title=?,description=?,state=?,priority=?,due_date=?,estimated_hours=? WHERE id=?',[...$values,$id]);else{query('INSERT INTO team_tasks(employee_id,order_id,title,description,state,priority,due_date,estimated_hours,created_by) VALUES(?,?,?,?,?,?,?,?,?)',[...$values,user()['id']]);$id=(int)db()->lastInsertId();}
            query('INSERT INTO task_events(task_id,user_id,state,note) VALUES(?,?,?,?)',[$id,user()['id'],$state,$old?'Fiche mise à jour':'Tâche créée']);audit('Tâche enregistrée','tâche',$id);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Tâche enregistrée.');go(url('task_view',['id'=>$id]));
    }
    if($action==='task_state'){
        $id=validId('task_id');$state=enumField('state',TASK_STATES);$note=field('note',1000);
        if(user()['role']==='employee'&&$state==='Annulée')throw new DomainException('L’annulation est réservée au responsable.');
        db()->beginTransaction();try{$candidate=taskRecord($id);if(!$candidate){db()->rollBack();http_response_code(404);exit('Tâche introuvable.');}$assignee=row('SELECT id,archived,employment_state FROM employees WHERE id=? FOR UPDATE',[$candidate['employee_id']]);$task=taskRecord($id,true);if(!$task){db()->rollBack();http_response_code(404);exit('Tâche introuvable.');}if((int)$task['employee_id']!==(int)$candidate['employee_id'])throw new DomainException('L’affectation vient de changer. Rechargez la tâche.');if(!in_array($state,['Terminée','Annulée'],true)&&(!$assignee||$assignee['archived']||$assignee['employment_state']!=='Actif'))throw new DomainException('Réaffectez la tâche à un employé actif avant de la rouvrir.');query('UPDATE team_tasks SET state=? WHERE id=?',[$state,$id]);query('INSERT INTO task_events(task_id,user_id,state,note) VALUES(?,?,?,?)',[$id,user()['id'],$state,$note]);audit('Tâche '.$state,'tâche',$id);db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Avancement enregistré.');go(url('task_view',['id'=>$id]));
    }
    if($action==='attendance_save'){
        $id=validId('employee_id');$date=dateField('attendance_date');if(!$date)throw new DomainException('Date obligatoire.');$state=enumField('state',['Présent','Absent','Congé']);$hours=decimalInt(field('hours',8,true),2,2);if($hours>2400||($state!=='Présent'&&$hours!==0))throw new DomainException('Heures : de 0 à 24 pour une présence ; zéro pour une absence ou un congé.');if($state==='Présent'&&$date>date('Y-m-d'))throw new DomainException('Une présence ne peut pas être enregistrée dans le futur.');$note=field('note',1000);
        db()->beginTransaction();try{activeEmployee($id,true);query('INSERT INTO attendance(employee_id,attendance_date,state,hours,note,recorded_by) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE state=VALUES(state),hours=VALUES(hours),note=VALUES(note),recorded_by=VALUES(recorded_by)',[$id,$date,$state,decimalString($hours,2),$note,user()['id']]);audit('Présence / absence enregistrée','équipe',$id);db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Journée enregistrée. Une seule ligne par employé et par date.');if(($_POST['return_employee']??'')==='1')go(url('employee_form',['id'=>$id,'month'=>substr($date,0,7)]));go(url('attendance',['date'=>$date]));
    }
    if($action==='review_add'){
        $id=validId('employee_id');$date=dateField('review_date');$score=filter_var($_POST['score']??null,FILTER_VALIDATE_INT);if(!$date||$date>date('Y-m-d')||$score<1||$score>5)throw new DomainException('Date passée ou actuelle et note entière de 1 à 5 requises.');$strengths=field('strengths',5000);$improvements=field('improvements',5000);$objectives=field('objectives',5000,true);$key=requestKey();
        db()->beginTransaction();try{activeEmployee($id,true);if(!row('SELECT id FROM employee_reviews WHERE request_key=?',[$key])){query('INSERT INTO employee_reviews(employee_id,review_date,score,strengths,improvements,objectives,reviewer_id,request_key) VALUES(?,?,?,?,?,?,?,?)',[$id,$date,$score,$strengths,$improvements,$objectives,user()['id'],$key]);audit('Évaluation enregistrée','équipe',$id);}db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Évaluation enregistrée.');go(url('reviews',['employee_id'=>$id]));
    }
    return false;
}
