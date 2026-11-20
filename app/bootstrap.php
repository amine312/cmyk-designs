<?php
declare(strict_types=1);
const APP_VERSION = '0.8.0';
const SCHEMA_VERSION = 11;
if(PHP_INT_SIZE<8){http_response_code(503);exit('CMYK Designs nécessite PHP en 64 bits pour calculer les montants. Activez PHP 8.2+ en 64 bits dans votre hébergement.');}
const STATUSES = ['À préparer','En conception','Bon à tirer','En production','Prête','Livrée','Annulée'];
const CATEGORIES = ['Impression numérique','Grand format','Signalétique','Enseigne / Néon','Découpe CNC / Laser','Design graphique','Autre'];
const CLIENT_POSITIONS = ['Gérant','Admin','Directeur','Responsable'];
const PRIORITIES = ['Normale','Haute','Urgente'];
function e(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $page='dashboard', array $params=[]): string { return 'index.php?'.http_build_query(['page'=>$page]+$params); }
function go(string $location): never { header('Location: '.$location, true, 303); exit; }
function money(int|float|string $value): string { return number_format((float)$value, 2, ',', ' ').' DH'; }
function ref(int $id): string { return 'CMD-'.str_pad((string)$id, 5, '0', STR_PAD_LEFT); }
function flash(string $message): void { $_SESSION['flash']=$message; }
function csrf(): string { return '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
function checkCsrf(): void { if (!hash_equals($_SESSION['csrf'],(string)($_POST['csrf']??''))) { http_response_code(419); exit('Session expirée. Revenez à la page précédente, actualisez puis réessayez.'); } }
function config(): array {
    static $config;
    if (!$config) {
        $path=__DIR__.'/config.php';
        if (!is_file($path)) { http_response_code(503); exit('<!doctype html><meta charset="utf-8"><title>Installation CMYK</title><h1>CMYK Designs</h1><p>Créez app/config.php à partir de app/config.example.php. Consultez le guide README.html puis ouvrez install.php.</p>'); }
        $config=require $path;
    }
    return $config;
}
function db(): PDO {
    static $db;
    if (!$db) {
        $c=config();
        $db=new PDO('mysql:host='.$c['db_host'].';port='.($c['db_port']??3306).';dbname='.$c['db_name'].';charset=utf8mb4', $c['db_user'], $c['db_password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
        $db->exec("SET time_zone = '+00:00'");
    }
    return $db;
}
function query(string $sql, array $args=[]): PDOStatement { $q=db()->prepare($sql);$q->execute($args);return $q; }
function row(string $sql,array $args=[]): array|false { return query($sql,$args)->fetch(); }
function all(string $sql,array $args=[]): array { return query($sql,$args)->fetchAll(); }
function scalar(string $sql,array $args=[]): mixed { return query($sql,$args)->fetchColumn(); }
function audit(string $action,string $entity,int $id=0): void { query('INSERT INTO audit_events (user_id,action,entity,entity_id) VALUES (?,?,?,?)', [$_SESSION['user_id']??null,$action,$entity,$id]); }

function field(string $name, int $max=255, bool $required=false): string {
    $value=trim((string)($_POST[$name]??''));
    if ($required && $value==='') throw new DomainException('Merci de remplir tous les champs obligatoires.');
    if (mb_strlen($value)>$max) throw new DomainException('Le champ « '.$name.' » est trop long.');
    return $value;
}
function emailField(string $name): string { $email=field($name,190);if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new DomainException('Adresse e-mail invalide.');return $email; }
function dateField(string $name): ?string { $v=field($name,10);if($v==='')return null;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v)throw new DomainException('Date invalide.');return $v; }
function enumField(string $name,array $values): string { $v=field($name);if(!in_array($v,$values,true))throw new DomainException('Choix invalide.');return $v; }
function decimalInt(string $value,int $precision,int $maxWhole): int {
    $v=str_replace(',','.',trim($value));
    if(!preg_match('/^\d{1,'.$maxWhole.'}(?:\.\d{1,'.$precision.'})?$/D',$v))throw new DomainException('Nombre invalide : valeur positive et '.$precision.' décimales maximum.');
    $p=explode('.',$v);return ((int)$p[0])*(10**$precision)+(int)str_pad($p[1]??'',$precision,'0');
}
function decimalString(int $value,int $precision): string { $scale=10**$precision;return intdiv($value,$scale).'.'.str_pad((string)($value%$scale),$precision,'0',STR_PAD_LEFT); }
function validId(string $key='id'): int { $id=filter_var($_POST[$key]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$id)throw new DomainException('Référence invalide.');return $id; }
function orderClass(string $status): string {return match($status){'Livrée'=>'green','Annulée'=>'gray','En production'=>'cyan','Prête'=>'green','Bon à tirer'=>'yellow',default=>'purple'};}
function sessionInit(): void {
    ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');
    session_name('cmyk_session');
    session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','samesite'=>'Lax','path'=>rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'])),'/').'/']);
    session_start(); $_SESSION['csrf']??=bin2hex(random_bytes(32));
    if(isset($_SESSION['last_active'])&&time()-$_SESSION['last_active']>7200){unset($_SESSION['user_id']);}
    $_SESSION['last_active']=time();
    header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin');header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    header('Cache-Control: no-store');
}
set_exception_handler(function(Throwable $ex){error_log('CMYK: '.$ex->getMessage());http_response_code(500);echo '<!doctype html><meta charset="utf-8"><title>CMYK Designs</title><h1>CMYK Designs</h1><p>Une erreur technique empêche cette opération. Vos données existantes sont conservées. Vérifiez la configuration et les journaux PHP de votre hébergement.</p><p><a href="index.php">Retour</a></p>';});

require_once __DIR__.'/commerce.php';
require_once __DIR__.'/line_items.php';
require_once __DIR__.'/quotes.php';
require_once __DIR__.'/credits.php';

require_once __DIR__.'/inventory.php';

require_once __DIR__.'/access.php';
require_once __DIR__.'/team.php';

require_once __DIR__.'/payroll.php';

require_once __DIR__.'/finance.php';
