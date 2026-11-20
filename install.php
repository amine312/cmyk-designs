<?php
declare(strict_types=1);
require __DIR__.'/app/bootstrap.php';
sessionInit();
$requirements=PHP_VERSION_ID>=80200 && PHP_INT_SIZE>=8 && extension_loaded('zlib') && extension_loaded('pdo_mysql') && extension_loaded('mbstring');
$error=''; $done=false;
if (!$requirements) {http_response_code(503);$error='Activez PHP 8.2 ou supérieur en 64 bits, pdo_mysql, mbstring et zlib dans cPanel.';}
if($requirements){
    $config=config();
    $configured=isset($config['setup_token'])&&strlen($config['setup_token'])>=32&&!str_contains($config['setup_token'],'CHANGE_ME');
    if(!$configured) $error='Personnalisez setup_token dans app/config.php (32 caractères aléatoires minimum).';
    if($configured){
        $lock=db()->query("SELECT GET_LOCK('cmyk_installation', 10)")->fetchColumn();
        if((int)$lock!==1){http_response_code(503);exit('Une installation est déjà en cours. Réessayez.');}
        try {
            $exists=(bool)scalar("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='app_meta'");
            if($exists && scalar("SELECT meta_value FROM app_meta WHERE meta_key='installed'")==='1'){http_response_code(403);exit('Installation déjà terminée. Ouvrez index.php.');}
            if($_SERVER['REQUEST_METHOD']==='POST'){
                checkCsrf();
                if(!hash_equals($config['setup_token'],(string)($_POST['setup_token']??''))){http_response_code(403);throw new DomainException('Jeton d’installation incorrect.');}
                $name=field('name',120,true);$email=emailField('email');
                if($email==='')throw new DomainException('Adresse e-mail obligatoire.');
                $password=(string)($_POST['password']??'');
                if(strlen($password)<12||strlen($password)>72)throw new DomainException('Choisissez un mot de passe de 12 à 72 caractères.');
                foreach(require __DIR__.'/app/schema.php' as $sql)db()->exec($sql);
                ensureSourceQuoteForeignKey();
                ensureCreditForeignKey();
                ensureFinancePaymentForeignKey();
                db()->beginTransaction();
                if((int)scalar('SELECT COUNT(*) FROM users')>0)throw new DomainException('Un administrateur existe déjà. Vérifiez l’installation avec votre hébergeur.');
                query('INSERT INTO users (name,email,password_hash) VALUES (?,?,?)',[$name,strtolower($email),password_hash($password,PASSWORD_DEFAULT)]);
                require_once __DIR__.'/app/migrations.php';
                foreach(['schema_version'=>(string)SCHEMA_VERSION,'installed'=>'1','company_name'=>'CMYK Designs','currency'=>'MAD','timezone'=>$config['timezone']??'Africa/Casablanca']+v2Defaults() as $k=>$v)query('INSERT INTO app_meta (meta_key,meta_value) VALUES (?,?) ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)',[$k,$v]);
                db()->commit(); $done=true;
            }
        }catch(DomainException $ex){if(db()->inTransaction())db()->rollBack();$error=$ex->getMessage();}
        finally {db()->query("SELECT RELEASE_LOCK('cmyk_installation')");}
    }
}
?><!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Installation · CMYK Designs</title><link rel="stylesheet" href="assets/style.css"></head><body class="auth-body"><main class="auth-card"><div class="brand-text">CMYK<span>designs.</span></div><p class="eyebrow">ESPACE DE GESTION · V<?=APP_VERSION?></p><h1><?= $done?'Votre atelier est prêt.':'Bienvenue chez CMYK.' ?></h1><p class="muted"><?= $done?'L’administrateur a été créé. Supprimez install.php dans cPanel après cette étape.':'Connectez votre base MySQL, puis créez votre compte administrateur. Aucune donnée de démonstration ne sera ajoutée.' ?></p><?php if($error):?><div class="notice error" role="alert"><?=e($error)?></div><?php endif ?><?php if($done):?><a class="btn primary" href="index.php">Ouvrir mon espace →</a><?php elseif($requirements&&$configured):?><form method="post" class="form-stack"><?=csrf()?><label>Jeton d’installation<input type="password" name="setup_token" required autocomplete="off"><small>La valeur setup_token de votre fichier app/config.php.</small></label><label>Votre nom<input name="name" maxlength="120" required autocomplete="name" value="<?=e($_POST['name']??'')?>"></label><label>E-mail de connexion<input type="email" name="email" maxlength="190" required autocomplete="username" value="<?=e($_POST['email']??'')?>"></label><label>Mot de passe<input type="password" name="password" minlength="12" maxlength="72" required autocomplete="new-password"><small>12 caractères minimum.</small></label><button class="btn primary">Créer l’espace CMYK Designs</button></form><?php endif?><p class="small muted">PHP + MySQL · Hébergement cPanel · Données sur votre serveur</p></main></body></html>
