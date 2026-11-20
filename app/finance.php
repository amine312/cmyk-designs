<?php
const EXPENSE_CATEGORIES=['Achats de matières','Loyer','Électricité / eau','Transport','Maintenance','Salaires','Primes','Services / abonnements','Autre'];
function signedMoney(int $value): string {return ($value<0?'−':'').money(decimalString(abs($value),2));}
function signedCents(string $value): int {$raw=str_replace(',','.',trim($value));$negative=str_starts_with($raw,'-');$n=decimalInt($negative?substr($raw,1):$raw,2,10);if($n>999999999999)throw new DomainException('Montant trop élevé.');return $negative?-$n:$n;}
function financeDate(string $key,bool $default=false): string {$d=dateField($key);if(!$d&&$default)$d=date('Y-m-d');if(!$d||$d>date('Y-m-d'))throw new DomainException('Date de trésorerie requise, au plus tard aujourd’hui.');return $d;}
function financeAmount(string $key='amount'): int {$v=decimalInt(field($key,20,true),2,9);if($v<=0)throw new DomainException('Montant strictement positif requis.');return $v;}
function optionalAccountId(): ?int {if(field('account_id',20)==='')return null;return validId('account_id');}
function financeAccount(int $id,string $date,bool $lock=false): array {$a=row('SELECT * FROM finance_accounts WHERE id=?'.($lock?' FOR UPDATE':''),[$id]);if(!$a)throw new DomainException('Compte de trésorerie introuvable.');if($date<$a['opening_date'])throw new DomainException('La date précède l’ouverture du compte.');return $a;}
function paymentFinanceDate(array $p): string {return $p['financial_date']?:((new DateTimeImmutable($p['confirmed_at']?:$p['created_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d'));}
function paymentFinanceSelection(string $state): array {
    $account=optionalAccountId();$date=$state==='Confirmé'?financeDate('financial_date',true):null;
    if($account)financeAccount($account,$date??date('Y-m-d'));
    return [$account,$date];
}
function ensureFinancePaymentForeignKey(): void {if(!(int)scalar("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='order_payments' AND constraint_name='fk_payment_account'"))db()->exec('ALTER TABLE order_payments ADD CONSTRAINT fk_payment_account FOREIGN KEY(account_id) REFERENCES finance_accounts(id) ON DELETE RESTRICT');}
function financeSnapshot(): array {
    $owns=!db()->inTransaction();if($owns)db()->beginTransaction();try{
        $accounts=all('SELECT * FROM finance_accounts ORDER BY id');$entries=[];$names=[];$balances=[];$unassigned=0;
        foreach($accounts as $a){$id=(int)$a['id'];$names[$id]=$a['name'];$balances[$id]=signedCents($a['opening_balance']);$entries[]=['date'=>$a['opening_date'],'account_id'=>$id,'source'=>'Ouverture','ref'=>'CPT-'.$id,'description'=>$a['name'],'signed'=>$balances[$id]];}
        foreach(all("SELECT * FROM order_payments WHERE state='Confirmé' ORDER BY id") as $p){$amount=cents($p['amount']);$id=$p['account_id']?(int)$p['account_id']:null;$entries[]=['date'=>paymentFinanceDate($p),'account_id'=>$id,'source'=>'Encaissement client','ref'=>'REG-'.$p['id'],'description'=>ref((int)$p['order_id']).' · '.$p['method'].' · '.$p['reference'],'signed'=>$amount];if($id)$balances[$id]+=$amount;else $unassigned+=$amount;}
        foreach(all("SELECT * FROM finance_expenses WHERE state='Payée' ORDER BY id") as $x){$id=(int)$x['account_id'];$amount=cents($x['amount']);$entries[]=['date'=>$x['paid_date'],'account_id'=>$id,'source'=>'Charge payée','ref'=>'CHG-'.$x['id'],'description'=>$x['title'].' · '.$x['category'].' · '.$x['payee'],'signed'=>-$amount];$balances[$id]-=$amount;}
        foreach(all('SELECT * FROM finance_transfers ORDER BY id') as $t)foreach(['from_account_id'=>-1,'to_account_id'=>1] as $key=>$dir){$id=(int)$t[$key];$amount=cents($t['amount'])*$dir;$entries[]=['date'=>$t['transfer_date'],'account_id'=>$id,'source'=>'Transfert interne','ref'=>'TRF-'.$t['id'],'description'=>$t['reference'],'signed'=>$amount];$balances[$id]+=$amount;}
        usort($entries,static fn($a,$b)=>strcmp($b['date'],$a['date'])?:strcmp($b['ref'],$a['ref']));
        if($owns)db()->commit();return ['accounts'=>$accounts,'names'=>$names,'balances'=>$balances,'entries'=>$entries,'unassigned'=>$unassigned];
    }catch(Throwable $ex){if($owns&&db()->inTransaction())db()->rollBack();throw $ex;}
}
function financeAction(string $action): bool {
    if($action==='finance_account_add'){
        $name=field('name',120,true);$kind=enumField('kind',['Caisse','Banque']);$date=financeDate('opening_date');$amount=signedCents(field('opening_balance',20,true));$key=requestKey();
        db()->beginTransaction();try{if(row('SELECT id FROM finance_accounts WHERE request_key=? FOR UPDATE',[$key])){db()->rollBack();go(url('finance'));}if(row('SELECT id FROM finance_accounts WHERE name=?',[$name]))throw new DomainException('Ce nom de compte existe déjà.');query('INSERT INTO finance_accounts(name,kind,opening_date,opening_balance,request_key) VALUES(?,?,?,?,?)',[$name,$kind,$date,($amount<0?'-':'').decimalString(abs($amount),2),$key]);audit('Compte de trésorerie créé','finance',(int)db()->lastInsertId());db()->commit();}catch(PDOException $ex){if(db()->inTransaction())db()->rollBack();if(($ex->errorInfo[1]??0)===1062)throw new DomainException('Compte ou envoi déjà enregistré.');throw $ex;}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Compte créé. Le solde initial et sa date sont conservés.');go(url('finance'));
    }
    if($action==='expense_add'){
        $title=field('title',190,true);$category=enumField('category',EXPENSE_CATEGORIES);$payee=field('payee',190);$date=dateField('expense_date');if(!$date)throw new DomainException('Date de charge obligatoire.');$amount=financeAmount();$method=enumField('method',PAYMENT_METHODS);$state=enumField('state',['Prévue','Payée']);$note=field('note',1500);$key=requestKey();$account=$state==='Payée'?validId('account_id'):optionalAccountId();$paid=$state==='Payée'?financeDate('paid_date'):null;
        if($method==='Chèque'&&$state==='Payée')throw new DomainException('Un chèque doit être enregistré en charge prévue, puis payé après débit effectif.');
        if($paid&&$paid<$date)throw new DomainException('Le paiement ne peut pas précéder la date de la charge.');
        db()->beginTransaction();try{if(row('SELECT id FROM finance_expenses WHERE request_key=? FOR UPDATE',[$key])){db()->rollBack();go(url('expenses'));}if($account)financeAccount($account,$paid??max(date('Y-m-d'),$date));query('INSERT INTO finance_expenses(title,category,payee,expense_date,amount,method,state,account_id,paid_date,note,request_key) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$title,$category,$payee,$date,decimalString($amount,2),$method,$state,$account,$paid,$note,$key]);audit('Charge enregistrée','charge',(int)db()->lastInsertId());db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Charge enregistrée. Seules les charges payées diminuent la trésorerie.');go(url('expenses'));
    }
    if($action==='expense_paid'||$action==='expense_cancel'){
        $id=validId();db()->beginTransaction();try{$x=row('SELECT * FROM finance_expenses WHERE id=? FOR UPDATE',[$id]);if(!$x)throw new DomainException('Charge introuvable.');
            if($action==='expense_paid'){
                if($x['state']==='Payée'){db()->rollBack();go(url('expenses'));}if($x['state']==='Annulée')throw new DomainException('Charge annulée.');$account=validId('account_id');$date=financeDate('paid_date');if($date<$x['expense_date'])throw new DomainException('Le paiement ne peut pas précéder la date de la charge.');financeAccount($account,$date);query("UPDATE finance_expenses SET state='Payée',account_id=?,paid_date=? WHERE id=?",[$account,$date,$id]);audit('Paiement de charge constaté','charge',$id);
            }else{$reason=field('cancel_reason',1000,true);if($x['state']==='Annulée'){db()->rollBack();go(url('expenses'));}query("UPDATE finance_expenses SET state='Annulée',cancel_reason=?,cancelled_at=UTC_TIMESTAMP() WHERE id=?",[$reason,$id]);audit('Annulation saisie charge','charge',$id);}
            db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash($action==='expense_cancel'?'Saisie annulée et conservée. Aucun remboursement effectué.':'Paiement constaté une seule fois.');go(url('expenses'));
    }
    if($action==='finance_transfer'){
        $from=validId('from_account_id');$to=validId('to_account_id');if($from===$to)throw new DomainException('Choisissez deux comptes différents.');$date=financeDate('transfer_date');$amount=financeAmount();$reference=field('reference',190,true);$key=requestKey();
        db()->beginTransaction();try{$ids=[$from,$to];sort($ids);foreach($ids as $id)financeAccount($id,$date,true);if(row('SELECT id FROM finance_transfers WHERE request_key=? FOR UPDATE',[$key])){db()->rollBack();go(url('finance'));}query('INSERT INTO finance_transfers(from_account_id,to_account_id,transfer_date,amount,reference,request_key) VALUES(?,?,?,?,?,?)',[$from,$to,$date,decimalString($amount,2),$reference,$key]);audit('Transfert interne constaté','finance',(int)db()->lastInsertId());db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Transfert enregistré dans le suivi. Aucun ordre envoyé à une banque.');go(url('finance'));
    }
    if($action==='payment_account'){
        $id=validId('payment_id');$candidate=row('SELECT order_id FROM order_payments WHERE id=?',[$id]);if(!$candidate)throw new DomainException('Règlement introuvable.');$account=validId('account_id');$date=financeDate('financial_date');
        db()->beginTransaction();try{row('SELECT id FROM orders WHERE id=? FOR UPDATE',[$candidate['order_id']]);$p=row('SELECT * FROM order_payments WHERE id=? FOR UPDATE',[$id]);if($p['state']!=='Confirmé')throw new DomainException('Affectez uniquement un règlement confirmé.');if($p['account_id'])throw new DomainException('Ce règlement est déjà affecté.');financeAccount($account,$date);query('UPDATE order_payments SET account_id=?,financial_date=? WHERE id=?',[$account,$date,$id]);audit('Règlement affecté au compte','règlement',$id);db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Règlement existant affecté, sans créer un second encaissement.');go(url('finance'));
    }
    if($action==='finance_export'){
        $s=financeSnapshot();$out=fopen('php://output','wb');audit('Export journal trésorerie','finance');header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="cmyk-tresorerie-'.date('Y-m-d').'.csv"');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['date','compte','source','reference','description','entree_DH','sortie_DH'],';','"','');foreach($s['entries'] as $r){$record=[$r['date'],$s['names'][$r['account_id']]??'Non affecté',$r['source'],$r['ref'],$r['description'],$r['signed']>=0?decimalString($r['signed'],2):'0.00',$r['signed']<0?decimalString(-$r['signed'],2):'0.00'];fputcsv($out,array_map(static fn($v)=>preg_match('/^[\s]*[=+@-]/u',(string)$v)?"'".$v:$v,$record),';','"','');}fclose($out);exit;
    }
    return false;
}
