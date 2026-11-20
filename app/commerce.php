<?php
const PAYMENT_METHODS=['Espèces','Virement','Carte','Chèque','Autre'];
function cents(string|int|float $value): int {return decimalInt((string)$value,2,10);}
function localDate(?string $date): string {return $date?(new DateTimeImmutable($date,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('d/m/Y H:i:s'):'—';}
function orderTotals(array $order): array {
    $subtotal=cents($order['total']);$rate=decimalInt((string)$order['tax_rate'],2,3);$tax=isset($order['tax_amount'])?cents($order['tax_amount']):intdiv($subtotal*$rate+5000,10000);return ['subtotal'=>$subtotal,'tax'=>$tax,'total'=>$subtotal+$tax];
}
function paymentSummary(int $orderId,?array $order=null): array {
    $order??=row('SELECT * FROM orders WHERE id=?',[$orderId]);if(!$order)throw new DomainException('Commande introuvable.');
    $invoice=row("SELECT id,total FROM documents WHERE order_id=? AND kind='invoice'",[$orderId]);
    $invoiced=$invoice?cents($invoice['total']):orderTotals($order)['total'];
    $credited=$invoice?cents((string)scalar("SELECT COALESCE(SUM(total),0) FROM documents WHERE source_invoice_id=? AND kind='credit'",[$invoice['id']])):0;
    $total=$invoiced-$credited;
    $paid=cents((string)scalar("SELECT COALESCE(SUM(amount),0) FROM order_payments WHERE order_id=? AND state='Confirmé'",[$orderId]));
    $pending=cents((string)scalar("SELECT COALESCE(SUM(amount),0) FROM order_payments WHERE order_id=? AND state='En attente'",[$orderId]));
    return ['total'=>$total,'invoiced'=>$invoiced,'credited'=>$credited,'paid'=>$paid,'pending'=>$pending,'balance'=>max(0,$total-$paid),'available'=>max(0,$paid-$total)];
}
function clientFinance(int $clientId): array {
    $ca=cents((string)scalar("SELECT COALESCE(SUM(subtotal),0) FROM documents WHERE client_id=? AND kind='invoice'",[$clientId]))-cents((string)scalar("SELECT COALESCE(SUM(subtotal),0) FROM documents WHERE client_id=? AND kind='credit'",[$clientId]));
    $invoices=all("SELECT order_id FROM documents WHERE client_id=? AND kind='invoice'",[$clientId]);$credit=0;$available=0;
    foreach($invoices as $inv){$s=paymentSummary((int)$inv['order_id']);$credit+=$s['balance'];$available+=$s['available'];}
    return ['ca'=>$ca,'credit'=>$credit,'available'=>$available];
}
function docSnapshot(array $order,array $client,array $meta): array {
    $issuer=[];foreach(['company_name','company_address','company_phone','company_email','company_ice','company_if','company_rc','document_terms'] as $k)$issuer[$k]=$meta[$k]??'';
    $snapshot=['issuer'=>$issuer,'client'=>array_intersect_key($client,array_flip(['company','contact','contact_position','phone','email','ice','tax_id','address'])),'order'=>array_intersect_key($order,array_flip(['id','title','category','description','quantity','unit','unit_price','payment_method','due_date','created_at'])),'timezone'=>date_default_timezone_get()];
    if(!empty($order['lines_json'])){$snapshot['lines']=orderLineItems($order);$snapshot['calculation']='line-cent-v1';}
    return $snapshot;
}
function commerceAction(string $action): bool {
    if($action==='payment_add'){
        $id=validId('order_id');$amount=decimalInt(field('amount',20,true),2,10);if($amount<=0||$amount>999999999999)throw new DomainException('Montant de règlement invalide.');
        $method=enumField('method',PAYMENT_METHODS);$state=$method==='Chèque'?'En attente':enumField('state',['Confirmé','En attente']);$reference=field('reference',190);$key=field('request_key',64,true);
        if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new DomainException('Référence de saisie invalide. Rechargez la page.');
        db()->beginTransaction();try{
            $order=row('SELECT * FROM orders WHERE id=? FOR UPDATE',[$id]);if(!$order)throw new DomainException('Commande introuvable.');
            if($order['status']==='Annulée')throw new DomainException('Réactivez la commande avant de saisir un paiement.');
            if(row('SELECT id FROM order_payments WHERE request_key=?',[$key])){db()->rollBack();flash('Ce règlement était déjà enregistré.');go(url('order_form',['id'=>$id]));}
            $s=paymentSummary($id,$order);if($amount>$s['balance']-$s['pending'])throw new DomainException('Le règlement dépasse le solde disponible (en tenant compte des montants en attente).');
            [$account,$financeDate]=paymentFinanceSelection($state);
            query('INSERT INTO order_payments(account_id,financial_date,order_id,amount,method,state,reference,request_key,confirmed_at) VALUES(?,?,?,?,?,?,?,?,?)',[$account,$financeDate,$id,decimalString($amount,2),$method,$state,$reference,$key,$state==='Confirmé'?gmdate('Y-m-d H:i:s'):null]);audit('Règlement ajouté','commande',$id);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash($method==='Chèque'?'Chèque enregistré en attente. Confirmez après encaissement.':'Règlement enregistré.');go(url('order_form',['id'=>$id]));
    }
    if($action==='payment_state'){
        $id=validId('payment_id');$payment=row('SELECT * FROM order_payments WHERE id=?',[$id]);if(!$payment)throw new DomainException('Règlement introuvable.');$next=enumField('state',['Confirmé','Annulé']);
        db()->beginTransaction();try{
            $order=row('SELECT * FROM orders WHERE id=? FOR UPDATE',[$payment['order_id']]);$payment=row('SELECT * FROM order_payments WHERE id=? FOR UPDATE',[$id]);
            if($payment['state']==='Annulé')throw new DomainException('Ce règlement est déjà annulé.');
            if($next==='Confirmé'){
                if($payment['state']!=='En attente')throw new DomainException('Ce règlement est déjà confirmé.');
                if($order['status']==='Annulée')throw new DomainException('Commande annulée : confirmation impossible.');
                if(cents($payment['amount'])>paymentSummary((int)$order['id'],$order)['balance'])throw new DomainException('Le montant dépasse le solde actuel.');
                $date=financeDate('financial_date',true);if($payment['account_id'])financeAccount((int)$payment['account_id'],$date);
                query("UPDATE order_payments SET state='Confirmé',confirmed_at=UTC_TIMESTAMP(),financial_date=? WHERE id=?",[$date,$id]);
            }else query("UPDATE order_payments SET state='Annulé',cancelled_at=UTC_TIMESTAMP() WHERE id=?",[$id]);
            audit('Règlement '.$next,'commande',(int)$order['id']);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('État du règlement mis à jour.');go(url('order_form',['id'=>$order['id']]));
    }
    if($action==='document_create'){
        $id=validId('order_id');$kind=enumField('kind',['quote','invoice']);
        db()->beginTransaction();try{
            $order=row('SELECT * FROM orders WHERE id=? FOR UPDATE',[$id]);if(!$order)throw new DomainException('Commande introuvable.');
            if($order['status']==='Annulée')throw new DomainException('Aucun document ne peut être créé pour une commande annulée.');
            $existing=$kind==='invoice'?row("SELECT id FROM documents WHERE order_id=? AND kind='invoice'",[$id]):false;
            if($existing){db()->rollBack();go(url('document',['id'=>$existing['id']]));}
            $client=row('SELECT * FROM clients WHERE id=?',[$order['client_id']]);$meta=array_column(all('SELECT meta_key,meta_value FROM app_meta'),'meta_value','meta_key');
            if(empty($order['source_quote_id'])&&trim($meta['company_address']??'')==='')throw new DomainException('Complétez l’adresse de votre entreprise dans Paramètres avant de créer un document.');
            if(empty($order['source_quote_id'])&&trim($client['address'])==='')throw new DomainException('Complétez l’adresse du client avant de créer un document.');
            $totals=orderTotals($order);if($totals['total']>999999999999)throw new DomainException('Montant TTC trop élevé.');
            $number=nextDocumentNumber($kind);
            query('INSERT INTO documents(order_id,client_id,kind,number,invoice_order_id,snapshot,subtotal,tax_rate,tax,total) VALUES(?,?,?,?,?,?,?,?,?,?)',[$id,$order['client_id'],$kind,$number,$kind==='invoice'?$id:null,json_encode(snapshotForOrderDocument($order,$client,$meta),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),decimalString($totals['subtotal'],2),$order['tax_rate'],decimalString($totals['tax'],2),decimalString($totals['total'],2)]);
            $docId=(int)db()->lastInsertId();audit('Création '.($kind==='invoice'?'facture':'devis'),'document',$docId);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Document créé. Les coordonnées et montants de cette version sont conservés.');go(url('document',['id'=>$docId]));
    }
    return false;
}
function companySettingsFromPost(): array {
    $values=[];foreach(['company_address'=>2000,'company_phone'=>40,'company_ice'=>15,'company_if'=>30,'company_rc'=>60,'document_terms'=>1500] as $key=>$max)$values[$key]=field($key,$max);
    $values['company_email']=emailField('company_email');
    if($values['company_ice']!==''&&!preg_match('/^\d{15}$/D',$values['company_ice']))throw new DomainException('L’ICE de votre entreprise doit contenir 15 chiffres.');
    if($values['company_if']!==''&&!preg_match('/^\d+$/D',$values['company_if']))throw new DomainException('L’IF de votre entreprise contient uniquement des chiffres.');
    $rate=decimalInt((string)($_POST['default_tax_rate']??'0'),2,3);if($rate>10000)throw new DomainException('Taux de TVA invalide.');$values['default_tax_rate']=decimalString($rate,2);return $values;
}
