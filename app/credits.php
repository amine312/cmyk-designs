<?php
function documentType(array $doc): string {return match($doc['kind']){'invoice'=>'Facture','credit'=>'Avoir',default=>'Devis'};}
function ensureCreditForeignKey(): void {
    if(!(int)scalar("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='documents' AND constraint_name='fk_credit_invoice'"))db()->exec('ALTER TABLE documents ADD CONSTRAINT fk_credit_invoice FOREIGN KEY(source_invoice_id) REFERENCES documents(id) ON DELETE RESTRICT');
}
// Round amount * part / whole without an overflowing intermediate product (64-bit PHP).
function proportionalCents(int $amount,int $part,int $whole): int {
    if($amount<0||$part<0||$whole<1||$part>$whole||$amount>999999999999||$whole>999999999)throw new DomainException('Répartition d’avoir invalide.');
    return intdiv($amount,$whole)*$part+intdiv(($amount%$whole)*$part+intdiv($whole,2),$whole);
}
function creditSummaryItems(array $items): array {
    $sum=['gross_subtotal'=>0,'discount'=>0,'subtotal'=>0,'tax'=>0,'total'=>0];$groups=[];
    foreach($items as $item){foreach($sum as $k=>$v)$sum[$k]+=$item[$k];$key=$item['tax_rate'];$groups[$key]??=['rate'=>$key,'base'=>0,'tax'=>0];$groups[$key]['base']+=$item['subtotal'];$groups[$key]['tax']+=$item['tax'];}
    uksort($groups,static fn($a,$b)=>decimalInt((string)$a,2,3)<=>decimalInt((string)$b,2,3));
    return $sum+['items'=>$items,'vat_groups'=>array_values($groups)];
}
function invoiceCreditPosition(array $invoice): array {
    if($invoice['kind']!=='invoice')throw new DomainException('Un avoir doit être lié à une facture émise.');
    $snapshot=json_decode($invoice['snapshot'],true,512,JSON_THROW_ON_ERROR);$items=snapshotLineSummary($invoice,$snapshot)['items'];$used=array_fill(0,count($items),0);
    // A locking current read also sees notes committed while this request waited for the order lock.
    $credits=all("SELECT * FROM documents WHERE source_invoice_id=? AND kind='credit' ORDER BY id".(db()->inTransaction()?' FOR UPDATE':''),[$invoice['id']]);
    foreach($credits as $credit){$s=json_decode($credit['snapshot'],true,512,JSON_THROW_ON_ERROR);foreach($s['credit_items'] as $item){$n=$item['source_line_index'];if(!isset($used[$n]))throw new DomainException('Historique d’avoirs incohérent.');$used[$n]+=(int)$item['quantity_milli'];}}
    $available=[];foreach($items as $n=>$item){$qty=decimalInt($item['quantity'],3,6);if($used[$n]>$qty)throw new DomainException('Historique de quantités incohérent.');$available[$n]=$qty-$used[$n];}
    return ['snapshot'=>$snapshot,'items'=>$items,'used'=>$used,'available'=>$available,'credits'=>$credits,'position_key'=>hash('sha256',json_encode(array_column($credits,'id'),JSON_THROW_ON_ERROR))];
}
function calculateCreditItems(array $position,mixed $quantities): array {
    if(!is_array($quantities)||count($quantities)>MAX_ORDER_LINES)throw new DomainException('Choisissez les quantités à créditer.');
    foreach($quantities as $n=>$raw)if(!array_key_exists($n,$position['items'])||!is_string($raw))throw new DomainException('Ligne d’avoir invalide.');
    $items=[];
    foreach($position['items'] as $n=>$source){
        $raw=trim($quantities[$n]??'0');$qty=decimalInt($raw===''?'0':$raw,3,6);if(!$qty)continue;
        if($qty>$position['available'][$n])throw new DomainException('La quantité dépasse le reliquat disponible. Rechargez la facture.');
        $whole=decimalInt($source['quantity'],3,6);$before=$position['used'][$n];$after=$before+$qty;$values=[];
        foreach(['discount','subtotal','tax'] as $k)$values[$k]=proportionalCents($source[$k],$after,$whole)-proportionalCents($source[$k],$before,$whole);
        $values['gross_subtotal']=$values['subtotal']+$values['discount'];$values['total']=$values['subtotal']+$values['tax'];
        $items[]=array_replace($source,$values,['quantity'=>decimalString($qty,3),'quantity_milli'=>$qty,'source_line_index'=>$n]);
    }
    $summary=creditSummaryItems($items);if($summary['total']<=0)throw new DomainException('Sélectionnez au moins une quantité avec un montant TTC positif.');
    return $summary;
}
function creditAction(string $action): bool {
    if($action!=='credit_issue')return false;
    $invoiceId=validId('invoice_id');$key=requestKey();$reason=field('reason',1000,true);
    if(($_POST['issue_confirmed']??'')!=='1')throw new DomainException('Confirmez l’émission définitive de l’avoir.');
    db()->beginTransaction();try{
        $candidate=row("SELECT order_id FROM documents WHERE id=? AND kind='invoice'",[$invoiceId]);if(!$candidate||!$candidate['order_id'])throw new DomainException('Facture introuvable.');
        row('SELECT id FROM orders WHERE id=? FOR UPDATE',[$candidate['order_id']]);
        $invoice=row("SELECT * FROM documents WHERE id=? AND kind='invoice' FOR UPDATE",[$invoiceId]);
        $existing=row('SELECT id,source_invoice_id FROM documents WHERE credit_request_key=? FOR UPDATE',[$key]);
        if($existing){if((int)$existing['source_invoice_id']!==$invoiceId)throw new DomainException('Référence de saisie déjà utilisée.');db()->rollBack();go(url('document',['id'=>$existing['id']]));}
        $position=invoiceCreditPosition($invoice);if(!hash_equals($position['position_key'],field('position_key',64,true)))throw new DomainException('Un autre avoir vient d’être émis. Rechargez la facture et vérifiez les montants.');
        $sum=calculateCreditItems($position,$_POST['credit_qty']??null);
        $snapshot=$position['snapshot'];unset($snapshot['lines']);$snapshot['calculation']='credit-allocation-v1';$snapshot['origin']='credit';$snapshot['credit_items']=$sum['items'];$snapshot['credit_reason']=$reason;
        $snapshot['source_invoice']=['id'=>(int)$invoice['id'],'number'=>$invoice['number'],'created_at'=>$invoice['created_at']];
        query('INSERT INTO documents(order_id,client_id,kind,number,snapshot,subtotal,tax_rate,tax,total,source_invoice_id,credit_request_key,credit_reason) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$invoice['order_id'],$invoice['client_id'],'credit',nextDocumentNumber('credit'),json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),decimalString($sum['subtotal'],2),$invoice['tax_rate'],decimalString($sum['tax'],2),decimalString($sum['total'],2),$invoiceId,$key,$reason]);
        $id=(int)db()->lastInsertId();audit('Émission avoir sur '.$invoice['number'],'document',$id);db()->commit();
    }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
    flash('Avoir émis. La facture originale est conservée. Aucun remboursement ni mouvement de stock automatique.');go(url('document',['id'=>$id]));
}
