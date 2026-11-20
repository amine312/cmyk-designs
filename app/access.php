<?php
const ROLES=['admin'=>'Administrateur','manager'=>'Responsable production','employee'=>'Employé'];
function schemaNumber(): int {static $n;return $n??=(int)scalar("SELECT meta_value FROM app_meta WHERE meta_key='schema_version'");}
function user(): array {
    static $resolved=false,$u=[];if($resolved)return $u;$resolved=true;
    $id=(int)($_SESSION['user_id']??0);if(!$id)return [];
    if(schemaNumber()<4){$old=row('SELECT id,name,email FROM users WHERE id=?',[$id]);return $u=$old?($old+['role'=>'admin','employee_id'=>null,'is_active'=>1,'session_version'=>0]):[];}
    $account=row('SELECT u.id,u.name,u.email,u.role,u.employee_id,u.is_active,u.session_version,u.activation_hash,e.archived,e.employment_state FROM users u LEFT JOIN employees e ON e.id=u.employee_id WHERE u.id=?',[$id]);
    if(!$account||!isset(ROLES[$account['role']])||($account['role']==='employee'&&!$account['employee_id'])||!$account['is_active']||$account['activation_hash']!==null||($account['employee_id']&&($account['archived']||$account['employment_state']!=='Actif'))||(int)($_SESSION['auth_version']??0)!==(int)$account['session_version']){unset($_SESSION['user_id'],$_SESSION['auth_version']);return [];}
    return $u=$account;
}
function canPage(string $page): bool {
    $u=user();if(!$u)return false;if($u['role']==='admin')return true;
    $allowed=['tasks','task_view','profile','roadmap'];
    if($u['role']==='manager')$allowed=[...$allowed,'task_form','production'];
    return in_array($page,$allowed,true);
}
function requirePage(string $page): void {if(!canPage($page)){http_response_code(403);exit('<!doctype html><meta charset="utf-8"><title>Accès limité</title><h1>Accès non autorisé</h1><p>Votre rôle ne permet pas de consulter cette page.</p><a href="index.php">Retour à mon espace</a>');}}
function homePage(): string {return (user()['role']??'')==='admin'?'dashboard':((user()['role']??'')==='manager'?'production':'tasks');}
function requireAction(string $action): void {
    if(!user()){http_response_code(401);exit('Connexion requise.');}
    if(in_array($action,['logout','password_save'],true))return;
    if(user()['role']==='admin')return;
    if($action==='task_state')return;
    if($action==='task_save'&&user()['role']==='manager')return;
    http_response_code(403);exit('Votre rôle ne permet pas cette opération.');
}
function activeEmployee(int $id,bool $lock=false): array {$e=row('SELECT id,full_name,archived,employment_state FROM employees WHERE id=?'.($lock?' FOR UPDATE':''),[$id]);if(!$e||$e['archived']||$e['employment_state']!=='Actif')throw new DomainException('Choisissez un employé actif.');return $e;}
function lockAdministrators(): array {return all("SELECT id,employee_id FROM users WHERE role='admin' AND is_active=1 AND activation_hash IS NULL ORDER BY id FOR UPDATE");}
function userCanTask(array $task): bool {return user()['role']!=='employee'||(int)user()['employee_id']===(int)$task['employee_id'];}
function taskRecord(int $id,bool $lock=false): array|false {
    $sql='SELECT t.*,e.full_name,o.title AS order_title FROM team_tasks t JOIN employees e ON e.id=t.employee_id LEFT JOIN orders o ON o.id=t.order_id WHERE t.id=?';$args=[$id];
    if(user()['role']==='employee'){$sql.=' AND t.employee_id=?';$args[]=user()['employee_id'];}
    return row($sql.($lock?' FOR UPDATE':''),$args);
}
