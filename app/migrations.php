<?php
function v2Defaults(): array {return ['company_address'=>'','company_phone'=>'','company_email'=>'','company_ice'=>'','company_if'=>'','company_rc'=>'','document_terms'=>'Règlement selon les conditions convenues.','default_tax_rate'=>'0.00'];}
function migrateToV2(): void {
    $lock=(int)scalar("SELECT GET_LOCK(CONCAT(DATABASE(), ':cmyk_migration'),10)");
    if($lock!==1)throw new DomainException('Une mise à jour est déjà en cours. Réessayez.');
    try {
        $version=(int)scalar("SELECT meta_value FROM app_meta WHERE meta_key='schema_version'");
        if($version===2)return;
        if($version!==1)throw new DomainException('Cette migration prend en charge uniquement le schéma 1.');
        foreach(['clients'=>['tax_id'=>"VARCHAR(30) NOT NULL DEFAULT ''"],'orders'=>['payment_method'=>"VARCHAR(30) NOT NULL DEFAULT 'Espèces'",'tax_rate'=>'DECIMAL(5,2) NOT NULL DEFAULT 0.00']] as $table=>$columns){
            foreach($columns as $column=>$definition){
                if(!(int)scalar('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',[$table,$column]))db()->exec("ALTER TABLE $table ADD COLUMN $column $definition");
            }
        }
        foreach(require __DIR__.'/schema_v2.php' as $sql)db()->exec($sql);
        foreach(v2Defaults() as $key=>$value)query('INSERT IGNORE INTO app_meta(meta_key,meta_value) VALUES (?,?)',[$key,$value]);
        db()->beginTransaction();
        try{query("UPDATE app_meta SET meta_value='2' WHERE meta_key='schema_version'");audit('Migration schéma 1 vers 2','application');db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
    }finally{query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':cmyk_migration'))");}
}
function migrateToLatest(): void {
    $version=(int)scalar("SELECT meta_value FROM app_meta WHERE meta_key='schema_version'");
    if($version===1){migrateToV2();$version=2;}
    if($version===3)return;
    if($version!==2)throw new DomainException('Version de base non prise en charge par cette migration.');
    if((int)scalar("SELECT GET_LOCK(CONCAT(DATABASE(), ':cmyk_migration'),10)")!==1)throw new DomainException('Une mise à jour est déjà en cours.');
    try{
        $current=(int)scalar("SELECT meta_value FROM app_meta WHERE meta_key='schema_version'");
        if($current===3)return;
        if($current!==2)throw new DomainException('La version de base a changé. Réessayez.');
        foreach(require __DIR__.'/schema_v3.php' as $sql)db()->exec($sql);
        db()->beginTransaction();try{query("UPDATE app_meta SET meta_value='3' WHERE meta_key='schema_version'");audit('Migration schéma 2 vers 3','application');db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
    }finally{query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':cmyk_migration'))");}
}
function migrateToV4(): void {
    $version=(int)scalar("SELECT meta_value FROM app_meta WHERE meta_key='schema_version'");if($version<3)migrateToLatest();
    if((int)scalar("SELECT GET_LOCK(CONCAT(DATABASE(), ':cmyk_migration'),10)")!==1)throw new DomainException('Une mise à jour est déjà en cours.');
    try{
        $version=(int)scalar("SELECT meta_value FROM app_meta WHERE meta_key='schema_version'");if($version===4)return;if($version!==3)throw new DomainException('Version de base non prise en charge.');
        foreach(require __DIR__.'/schema_v4.php' as $sql)db()->exec($sql);
        foreach(['role'=>"VARCHAR(20) NOT NULL DEFAULT 'admin'",'employee_id'=>'BIGINT UNSIGNED NULL','is_active'=>'TINYINT NOT NULL DEFAULT 1','session_version'=>'INT NOT NULL DEFAULT 0','activation_hash'=>'CHAR(64) NULL','activation_expires'=>'DATETIME NULL'] as $column=>$def){if(!(int)scalar('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',['users',$column]))db()->exec("ALTER TABLE users ADD COLUMN $column $def");}
        if(!(int)scalar("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='users' AND index_name='idx_account_employee'"))db()->exec('ALTER TABLE users ADD UNIQUE KEY idx_account_employee(employee_id)');
        db()->beginTransaction();try{query("UPDATE app_meta SET meta_value='4' WHERE meta_key='schema_version'");audit('Migration schéma 3 vers 4','application');db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
    }finally{query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':cmyk_migration'))");}
}
function migrateToV5(): void {
    $version=(int)scalar("SELECT meta_value FROM app_meta WHERE meta_key='schema_version'");if($version<4)migrateToV4();
    if((int)scalar("SELECT GET_LOCK(CONCAT(DATABASE(), ':cmyk_migration'),10)")!==1)throw new DomainException('Une mise à jour est déjà en cours.');
    try{
        $version=(int)scalar("SELECT meta_value FROM app_meta WHERE meta_key='schema_version'");if($version===5)return;if($version!==4)throw new DomainException('Version de base non prise en charge.');
        foreach(['lines_json'=>'LONGTEXT NULL','tax_amount'=>'DECIMAL(12,2) NULL'] as $column=>$def){if(!(int)scalar('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',['orders',$column]))db()->exec("ALTER TABLE orders ADD COLUMN $column $def");}
        // Old orders/documents keep their original amounts and snapshots. No backfill or rounding.
        db()->beginTransaction();try{query("UPDATE app_meta SET meta_value='5' WHERE meta_key='schema_version'");audit('Migration schéma 4 vers 5','application');db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
    }finally{query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':cmyk_migration'))");}
}
function migrateToV6(): void {
    $version=(int)scalar("SELECT meta_value FROM app_meta WHERE meta_key='schema_version'");if($version<5)migrateToV5();
    if((int)scalar("SELECT GET_LOCK(CONCAT(DATABASE(), ':cmyk_migration'),10)")!==1)throw new DomainException('Une mise à jour est déjà en cours.');
    try{
        $version=(int)scalar("SELECT meta_value FROM app_meta WHERE meta_key='schema_version'");if($version===6)return;if($version!==5)throw new DomainException('Version de base non prise en charge.');
        if(scalar("SELECT is_nullable FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='documents' AND column_name='order_id'")!=='YES')db()->exec('ALTER TABLE documents MODIFY COLUMN order_id BIGINT UNSIGNED NULL');
        foreach(['documents'=>['quote_state'=>"VARCHAR(20) NOT NULL DEFAULT 'Émis'",'valid_until'=>'DATE NULL','accepted_at'=>'DATETIME NULL','decision_note'=>"VARCHAR(1000) NOT NULL DEFAULT ''"],'orders'=>['source_quote_id'=>'BIGINT UNSIGNED NULL']] as $table=>$columns)foreach($columns as $column=>$def){if(!(int)scalar('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',[$table,$column]))db()->exec("ALTER TABLE $table ADD COLUMN $column $def");}
        if(!(int)scalar("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='orders' AND index_name='idx_source_quote'"))db()->exec('ALTER TABLE orders ADD UNIQUE KEY idx_source_quote(source_quote_id)');
        ensureSourceQuoteForeignKey();
        foreach(require __DIR__.'/schema_v6.php' as $sql)db()->exec($sql);
        db()->beginTransaction();try{query("UPDATE app_meta SET meta_value='6' WHERE meta_key='schema_version'");audit('Migration schéma 5 vers 6','application');db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
    }finally{query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':cmyk_migration'))");}
}
function migrateToV7(): void {
    $version=(int)scalar("SELECT meta_value FROM app_meta WHERE meta_key='schema_version'");if($version<6)migrateToV6();
    if((int)scalar("SELECT GET_LOCK(CONCAT(DATABASE(), ':cmyk_migration'),10)")!==1)throw new DomainException('Une mise à jour est déjà en cours.');
    try{
        $version=(int)scalar("SELECT meta_value FROM app_meta WHERE meta_key='schema_version'");if($version===7)return;if($version!==6)throw new DomainException('Version de base non prise en charge.');
        foreach(['source_invoice_id'=>'BIGINT UNSIGNED NULL','credit_request_key'=>'CHAR(64) NULL','credit_reason'=>"VARCHAR(1000) NOT NULL DEFAULT ''"] as $column=>$def)if(!(int)scalar('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',['documents',$column]))db()->exec("ALTER TABLE documents ADD COLUMN $column $def");
        foreach(['idx_credit_invoice'=>'KEY idx_credit_invoice(source_invoice_id,kind)','credit_request_key'=>'UNIQUE KEY credit_request_key(credit_request_key)'] as $index=>$def)if(!(int)scalar('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?',['documents',$index]))db()->exec('ALTER TABLE documents ADD '.$def);
        ensureCreditForeignKey();
        db()->beginTransaction();try{query("UPDATE app_meta SET meta_value='7' WHERE meta_key='schema_version'");audit('Migration schéma 6 vers 7','application');db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
    }finally{query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':cmyk_migration'))");}
}
function migrateToV8(): void {
    $version=(int)scalar("SELECT meta_value FROM app_meta WHERE meta_key='schema_version'");if($version<7)migrateToV7();
    if((int)scalar("SELECT GET_LOCK(CONCAT(DATABASE(), ':cmyk_migration'),10)")!==1)throw new DomainException('Une mise à jour est déjà en cours.');
    try{
        $version=(int)scalar("SELECT meta_value FROM app_meta WHERE meta_key='schema_version'");if($version===8)return;if($version!==7)throw new DomainException('Version de base non prise en charge.');
        foreach(['entry_date'=>'DATE NULL','expiry_date'=>'DATE NULL','wholesale_price'=>'DECIMAL(12,2) NULL','retail_price'=>'DECIMAL(12,2) NULL','unit_weight_kg'=>'DECIMAL(11,3) NULL'] as $column=>$definition)if(!(int)scalar('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',['stock_items',$column]))db()->exec("ALTER TABLE stock_items ADD COLUMN $column $definition");
        db()->beginTransaction();try{query("UPDATE app_meta SET meta_value='8' WHERE meta_key='schema_version'");audit('Migration schéma 7 vers 8','application');db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
    }finally{query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':cmyk_migration'))");}
}
function migrateToV9(): void {
    $version=(int)scalar("SELECT meta_value FROM app_meta WHERE meta_key='schema_version'");if($version<8)migrateToV8();
    if((int)scalar("SELECT GET_LOCK(CONCAT(DATABASE(), ':cmyk_migration'),10)")!==1)throw new DomainException('Une mise à jour est déjà en cours.');
    try{
        $version=(int)scalar("SELECT meta_value FROM app_meta WHERE meta_key='schema_version'");if($version===9)return;if($version!==8)throw new DomainException('Version de base non prise en charge.');
        foreach(['base_salary','default_bonus'] as $column)if(!(int)scalar('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',['employees',$column]))db()->exec("ALTER TABLE employees ADD COLUMN $column DECIMAL(12,2) NULL");
        foreach(require __DIR__.'/schema_v9.php' as $sql)db()->exec($sql);
        db()->beginTransaction();try{query("UPDATE app_meta SET meta_value='9' WHERE meta_key='schema_version'");audit('Migration schéma 8 vers 9','application');db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
    }finally{query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':cmyk_migration'))");}
}
function migrateToV10(): void {
    $version=(int)scalar("SELECT meta_value FROM app_meta WHERE meta_key='schema_version'");if($version<9)migrateToV9();
    if((int)scalar("SELECT GET_LOCK(CONCAT(DATABASE(), ':cmyk_migration'),10)")!==1)throw new DomainException('Une mise à jour est déjà en cours.');
    try{
        $version=(int)scalar("SELECT meta_value FROM app_meta WHERE meta_key='schema_version'");if($version===10)return;if($version!==9)throw new DomainException('Version de base non prise en charge.');
        if(!(int)scalar("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='clients' AND column_name='contact_position'"))db()->exec("ALTER TABLE clients ADD COLUMN contact_position VARCHAR(30) NOT NULL DEFAULT ''");
        db()->beginTransaction();try{query("UPDATE app_meta SET meta_value='10' WHERE meta_key='schema_version'");audit('Migration schéma 9 vers 10','application');db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
    }finally{query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':cmyk_migration'))");}
}
function migrateToV11(): void {
    $version=(int)scalar("SELECT meta_value FROM app_meta WHERE meta_key='schema_version'");if($version<10)migrateToV10();
    if((int)scalar("SELECT GET_LOCK(CONCAT(DATABASE(), ':cmyk_migration'),10)")!==1)throw new DomainException('Une mise à jour est déjà en cours.');
    try{
        $version=(int)scalar("SELECT meta_value FROM app_meta WHERE meta_key='schema_version'");if($version===11)return;if($version!==10)throw new DomainException('Version de base non prise en charge.');
        foreach(require __DIR__.'/schema_v11.php' as $sql)db()->exec($sql);
        foreach(['account_id'=>'BIGINT UNSIGNED NULL','financial_date'=>'DATE NULL'] as $column=>$def)if(!(int)scalar('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?',['order_payments',$column]))db()->exec("ALTER TABLE order_payments ADD COLUMN $column $def");
        ensureFinancePaymentForeignKey();
        db()->beginTransaction();try{query("UPDATE app_meta SET meta_value='11' WHERE meta_key='schema_version'");audit('Migration schéma 10 vers 11','application');db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
    }finally{query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':cmyk_migration'))");}
}
