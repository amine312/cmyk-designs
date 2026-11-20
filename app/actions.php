<?php
function handleAction(string $action): void {
    requireAction($action);
    if(teamAction($action))return;
    if(payrollAction($action))return;
    if(financeAction($action))return;
    if(quoteAction($action))return;
    if(creditAction($action))return;
    if($action==='logout'){$_SESSION=[];session_destroy();go('index.php');}
    if(commerceAction($action))return;
    if(inventoryAction($action))return;
    if($action==='client_save'){
        $id=(int)($_POST['id']??0);$company=field('company',190,true);$contact=field('contact',120);$phone=field('phone',40);$email=emailField('email');$ice=field('ice',15);
        if($ice!==''&&!preg_match('/^\d{15}$/D',$ice))throw new DomainException('L’ICE doit contenir exactement 15 chiffres, ou rester vide.');
        $taxId=field('tax_id',30);if($taxId!==''&&!preg_match('/^\d{1,30}$/D',$taxId))throw new DomainException('L’IF contient uniquement des chiffres.');
        $values=[$company,$contact,$phone,$email,$ice,$taxId,field('address',2000),field('notes',5000)];
        db()->beginTransaction();
        try {
            if($id){if(!row('SELECT id FROM clients WHERE id=? FOR UPDATE',[$id]))throw new DomainException('Client introuvable.');query('UPDATE clients SET company=?,contact=?,phone=?,email=?,ice=?,tax_id=?,address=?,notes=? WHERE id=?',[...$values,$id]);}
            else{query('INSERT INTO clients (company,contact,phone,email,ice,tax_id,address,notes) VALUES (?,?,?,?,?,?,?,?)',$values);$id=(int)db()->lastInsertId();}
            if(array_key_exists('contact_position',$_POST)){$position=field('contact_position',30);if($position!==''&&!in_array($position,CLIENT_POSITIONS,true))throw new DomainException('Poste du contact invalide.');query('UPDATE clients SET contact_position=? WHERE id=?',[$position,$id]);}
            audit('Enregistrement','client',$id);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Client enregistré.');go(url('clients'));
    }
    if($action==='client_archive'){
        $id=validId();$state=($_POST['archived']??'')==='1'?1:0;
        db()->beginTransaction();try{if(!row('SELECT id FROM clients WHERE id=? FOR UPDATE',[$id]))throw new DomainException('Client introuvable.');query('UPDATE clients SET archived=? WHERE id=?',[$state,$id]);audit($state?'Archivage':'Réactivation','client',$id);db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash($state?'Client archivé. Ses commandes sont conservées.':'Client réactivé.');go(url('clients',['archived'=>(string)$state]));
    }
    if($action==='order_save'){
        $id=(int)($_POST['id']??0);$clientId=validId('client_id');$title=field('title',190,true);$category=enumField('category',CATEGORIES);$description=field('description',6000);
        $lines=postedLineItems($category);$totals=calculateLineItems($lines);$first=$lines[0];$total=$totals['subtotal'];$newGross=$totals['total'];
        $method=enumField('payment_method',PAYMENT_METHODS);$status=enumField('status',STATUSES);$priority=enumField('priority',PRIORITIES);$due=dateField('due_date');
        db()->beginTransaction();
        try {
            $old=$id?row('SELECT * FROM orders WHERE id=? FOR UPDATE',[$id]):false;
            if($id&&!$old)throw new DomainException('Commande introuvable.');
            $client=row('SELECT id,archived FROM clients WHERE id=? FOR UPDATE',[$clientId]);
            if(!$client||($client['archived']&&(!$old||(int)$old['client_id']!==$clientId)))throw new DomainException('Choisissez un client actif.');
            if($old){
                if(!array_key_exists('lines',$_POST)&&!empty($old['lines_json']))throw new DomainException('Ce formulaire ancien ne contient pas les lignes actuelles. Rechargez la commande.');
                if(in_array($status,['Livrée','Annulée'],true)&&(int)scalar("SELECT COUNT(*) FROM stock_reservations WHERE order_id=? AND state='Active'",[$id])>0)throw new DomainException('Consommez ou libérez les réservations actives avant de livrer ou d’annuler cette commande.');
                $invoice=row("SELECT id FROM documents WHERE order_id=? AND kind='invoice'",[$id]);
                if($invoice&&((int)$old['client_id']!==$clientId||normalizeLineItems(orderLineItems($old))!==$lines||orderTotals($old)!==['subtotal'=>$total,'tax'=>$totals['tax'],'total'=>$newGross]))throw new DomainException('Facture déjà émise : le client, les désignations, quantités, remises et montants sont verrouillés.');
                if(!empty($old['source_quote_id'])&&((int)$old['client_id']!==$clientId||normalizeLineItems(orderLineItems($old))!==$lines||orderTotals($old)!==['subtotal'=>$total,'tax'=>$totals['tax'],'total'=>$newGross]))throw new DomainException('Devis accepté : le client et les lignes commerciales sont verrouillés. Préparez une nouvelle proposition pour changer les conditions.');
                $s=paymentSummary($id,$old);
                if($newGross<$s['paid']+$s['pending'])throw new DomainException('Le total ne peut être inférieur aux règlements enregistrés.');
                if((int)$old['client_id']!==$clientId&&($s['paid']+$s['pending']>0))throw new DomainException('Impossible de changer le client après un règlement.');
                if($status==='Annulée'&&($invoice||$s['paid']+$s['pending']>0))throw new DomainException('Annulez les règlements avant d’annuler une commande non facturée. Une facture émise reste conservée.');
            }
            // Retain first-line fields for compatibility; total is the sum of net HT lines.
            $values=[$clientId,$title,$category,$description,$first['quantity'],$first['unit'],$first['unit_price'],decimalString($total,2),$status,$priority,$due,$method,$first['tax_rate'],array_key_exists('lines',$_POST)?json_encode($lines,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):null,array_key_exists('lines',$_POST)?decimalString($totals['tax'],2):null];
            if($id)query('UPDATE orders SET client_id=?,title=?,category=?,description=?,quantity=?,unit=?,unit_price=?,total=?,status=?,priority=?,due_date=?,payment_method=?,tax_rate=?,lines_json=?,tax_amount=? WHERE id=?',[...$values,$id]);
            else{query('INSERT INTO orders (client_id,title,category,description,quantity,unit,unit_price,total,status,priority,due_date,payment_method,tax_rate,lines_json,tax_amount) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',$values);$id=(int)db()->lastInsertId();}
            audit('Enregistrement','commande',$id);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash(ref($id).' enregistrée.');go(url('orders'));
    }
    if($action==='settings_save'){
        $name=field('company_name',190,true);$timezone=enumField('timezone',['Africa/Casablanca','Europe/Madrid','Europe/Paris','UTC']);
        db()->beginTransaction();try{foreach(['company_name'=>$name,'timezone'=>$timezone]+companySettingsFromPost() as $k=>$v)query('UPDATE app_meta SET meta_value=? WHERE meta_key=?',[$v,$k]);audit('Paramètres modifiés','entreprise');db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Paramètres enregistrés.');go(url('settings'));
    }
    if($action==='password_save'){
        $current=(string)($_POST['current_password']??'');$new=(string)($_POST['new_password']??'');
        $hash=(string)scalar('SELECT password_hash FROM users WHERE id=?',[user()['id']]);
        if(!password_verify($current,$hash))throw new DomainException('Le mot de passe actuel est incorrect.');
        if(strlen($new)<12||strlen($new)>72)throw new DomainException('Le nouveau mot de passe doit contenir 12 à 72 caractères.');
        if($new!==(string)($_POST['confirm_password']??''))throw new DomainException('Les deux nouveaux mots de passe diffèrent.');
        db()->beginTransaction();try{query('UPDATE users SET password_hash=?,session_version=session_version+1 WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),user()['id']]);audit('Mot de passe modifié','compte',(int)user()['id']);db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        $_SESSION['auth_version']=(int)scalar('SELECT session_version FROM users WHERE id=?',[user()['id']]);session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));flash('Mot de passe mis à jour.');go(url(canPage('settings')?'settings':'profile'));
    }
    if($action==='export'){
        $type=enumField('type',['clients','orders','order_lines','documents']);
        // Export complet indépendant des filtres d'écran, protégé par authentification et CSRF.
        audit('Export CSV',$type);
        if($type==='order_lines'){
            header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="cmyk-lignes-commandes-'.date('Y-m-d').'.csv"');
            $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['commande','ligne','designation','quantite','unite','prix_unitaire_HT','remise_pct','TVA_pct','remise_DH','HT_net','TVA_DH','TTC'],';','"','');
            foreach(query('SELECT * FROM orders ORDER BY id') as $order){$calculated=calculateLineItems(orderLineItems($order));foreach($calculated['items'] as $index=>$line){
                $record=[ref((int)$order['id']),$index+1,$line['designation'],$line['quantity'],$line['unit'],$line['unit_price'],$line['discount_rate'],$line['tax_rate'],decimalString($line['discount'],2),decimalString($line['subtotal'],2),decimalString($line['tax'],2),decimalString($line['total'],2)];
                $safe=array_map(static function($v){$s=(string)$v;return preg_match('/^[\s]*[=+@\-]/u',$s)?"'".$s:$s;},$record);fputcsv($out,$safe,';','"','');
            }}fclose($out);exit;
        }
        $data=$type==='documents'?query("SELECT d.id,d.kind,d.number,d.order_id,d.client_id,d.source_invoice_id,src.number AS source_invoice_number,d.subtotal AS montant_HT,d.tax AS montant_TVA,d.total AS montant_TTC,CASE WHEN d.kind='credit' THEN 'A deduire' ELSE 'Document emis' END AS sens,d.credit_reason,d.created_at FROM documents d LEFT JOIN documents src ON src.id=d.source_invoice_id ORDER BY d.id"):($type==='clients'?query('SELECT id,company,contact,phone,email,ice,tax_id,address,notes,archived,created_at,updated_at,contact_position FROM clients ORDER BY id'):query('SELECT o.id,c.company AS client,o.title,o.category,o.description,o.quantity,o.unit,o.unit_price,o.total,o.status,o.priority,o.due_date,o.payment_method,o.tax_rate,o.created_at,o.updated_at,COALESCE(JSON_LENGTH(o.lines_json),1) AS line_count,COALESCE(o.tax_amount,ROUND(o.total*o.tax_rate/100,2)) AS tax_amount,o.total+COALESCE(o.tax_amount,ROUND(o.total*o.tax_rate/100,2)) AS gross_total,o.source_quote_id FROM orders o JOIN clients c ON c.id=o.client_id ORDER BY o.id'));
        header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="cmyk-'.$type.'-'.date('Y-m-d').'.csv"');
        $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");$first=true;
        while($record=$data->fetch()){
            if($first){fputcsv($out,array_keys($record),';','"','');$first=false;}
            $safe=array_map(static function($v){$s=(string)($v??'');return preg_match('/^[\s]*[=+@\-]/u',$s)?"'".$s:$s;},$record);
            fputcsv($out,$safe,';','"','');
        }
        if($first)fputcsv($out,[$type==='clients'?'Aucun client':($type==='documents'?'Aucun document':'Aucune commande')],';','"','');
        fclose($out);exit;
    }
    throw new DomainException('Opération inconnue.');
}
