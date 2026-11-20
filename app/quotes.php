<?php
function nextDocumentNumber(string $kind): string {
    $year=(int)date('Y');query('INSERT IGNORE INTO document_counters(kind,fiscal_year,last_number) VALUES(?,?,0)',[$kind,$year]);
    $counter=row('SELECT last_number FROM document_counters WHERE kind=? AND fiscal_year=? FOR UPDATE',[$kind,$year]);$seq=(int)$counter['last_number']+1;
    query('UPDATE document_counters SET last_number=? WHERE kind=? AND fiscal_year=?',[$seq,$kind,$year]);
    return (match($kind){'invoice'=>'FAC','credit'=>'AVR','payroll'=>'PAY','work'=>'ATT-TRV','salary'=>'ATT-SAL',default=>'DEV'}).'-'.$year.'-'.str_pad((string)$seq,5,'0',STR_PAD_LEFT);
}
function quoteLabel(array $doc): string {return $doc['quote_state']==='Émis'&&$doc['valid_until']&&$doc['valid_until']<date('Y-m-d')?'Expiré':$doc['quote_state'];}
function emptyPaymentSummary(): array {return ['total'=>0,'paid'=>0,'pending'=>0,'balance'=>0];}
function requireActiveQuoteClient(int $id,bool $lock=false): array {
    $c=row('SELECT * FROM clients WHERE id=?'.($lock?' FOR UPDATE':''),[$id]);if(!$c||$c['archived'])throw new DomainException('Réactivez le client avant de poursuivre ce devis.');return $c;
}
function quoteMatchesOrder(array $doc,array $order): bool {
    $snap=json_decode($doc['snapshot'],true,512,JSON_THROW_ON_ERROR);$items=snapshotLineSummary($doc,$snap)['items'];
    return (int)$doc['client_id']===(int)$order['client_id']&&normalizeLineItems($items)===normalizeLineItems(orderLineItems($order))&&orderTotals($order)===['subtotal'=>cents($doc['subtotal']),'tax'=>cents($doc['tax']),'total'=>cents($doc['total'])];
}
function lockedQuote(int $id): array {
    // Existing parents always lock before their document; a newly inserted order has no competing owner.
    $candidate=row("SELECT order_id FROM documents WHERE id=? AND kind='quote'",[$id]);if(!$candidate)throw new DomainException('Devis introuvable.');
    $parent=$candidate['order_id']?row('SELECT * FROM orders WHERE id=? FOR UPDATE',[$candidate['order_id']]):false;
    $doc=row("SELECT * FROM documents WHERE id=? AND kind='quote' FOR UPDATE",[$id]);if(!$doc)throw new DomainException('Devis introuvable.');
    if($doc['order_id']&&(!$parent||(int)$parent['id']!==(int)$doc['order_id'])&&$doc['quote_state']!=='Converti')throw new DomainException('Ce devis vient de changer. Rechargez la page.');
    return [$doc,$parent];
}
function quoteAction(string $action): bool {
    if($action==='quote_draft_save'){
        $id=(int)($_POST['id']??0);$clientId=validId('client_id');$title=field('title',190,true);$category=enumField('category',CATEGORIES);$description=field('description',6000);$lines=normalizeLineItems($_POST['lines']??null);calculateLineItems($lines);
        $method=enumField('payment_method',PAYMENT_METHODS);$due=dateField('due_date');$valid=dateField('valid_until');$key=$id?'':requestKey();
        db()->beginTransaction();try{
            $old=$id?row('SELECT * FROM quote_drafts WHERE id=? FOR UPDATE',[$id]):false;
            if($id&&!$old)throw new DomainException('Brouillon introuvable.');
            if($old&&($old['archived']||$old['issued_document_id']))throw new DomainException('Un brouillon archivé ou émis ne peut plus être modifié. Préparez une nouvelle version.');
            if($old&&(int)($_POST['revision']??0)!==(int)$old['revision'])throw new DomainException('Une autre modification a été enregistrée. Rechargez le brouillon.');
            requireActiveQuoteClient($clientId,true);
            if(!$id&&($existing=row('SELECT id FROM quote_drafts WHERE request_key=?',[$key]))){db()->rollBack();go(url('quote_form',['id'=>$existing['id']]));}
            $values=[$clientId,$title,$category,$description,json_encode($lines,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$method,$due,$valid];
            if($id)query('UPDATE quote_drafts SET client_id=?,title=?,category=?,description=?,lines_json=?,payment_method=?,due_date=?,valid_until=?,revision=revision+1 WHERE id=?',[...$values,$id]);
            else{query('INSERT INTO quote_drafts(client_id,title,category,description,lines_json,payment_method,due_date,valid_until,request_key) VALUES(?,?,?,?,?,?,?,?,?)',[...$values,$key]);$id=(int)db()->lastInsertId();}
            audit('Brouillon de devis enregistré','devis',$id);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Brouillon enregistré. Émettez le devis pour figer cette proposition.');go(url('quote_form',['id'=>$id]));
    }
    if($action==='quote_draft_archive'){
        $id=validId();$archived=($_POST['archived']??'')==='1'?1:0;db()->beginTransaction();try{$draft=row('SELECT * FROM quote_drafts WHERE id=? FOR UPDATE',[$id]);if(!$draft||$draft['issued_document_id'])throw new DomainException('Seul un brouillon non émis peut être archivé.');query('UPDATE quote_drafts SET archived=?,revision=revision+1 WHERE id=?',[$archived,$id]);audit($archived?'Brouillon archivé':'Brouillon réactivé','devis',$id);db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}flash('État du brouillon modifié.');go(url('quotes'));
    }
    if($action==='quote_draft_issue'){
        $id=validId();db()->beginTransaction();try{
            $draft=row('SELECT * FROM quote_drafts WHERE id=? FOR UPDATE',[$id]);if(!$draft)throw new DomainException('Brouillon introuvable.');
            if($draft['issued_document_id']){db()->rollBack();go(url('document',['id'=>$draft['issued_document_id']]));}
            if($draft['archived'])throw new DomainException('Réactivez le brouillon avant émission.');
            if((int)($_POST['revision']??0)!==(int)$draft['revision'])throw new DomainException('Le brouillon a changé. Rechargez avant de l’émettre.');
            if($draft['valid_until']&&$draft['valid_until']<date('Y-m-d'))throw new DomainException('La date de validité est déjà dépassée.');
            $client=requireActiveQuoteClient((int)$draft['client_id'],true);$meta=array_column(all('SELECT meta_key,meta_value FROM app_meta'),'meta_value','meta_key');
            if(trim($client['address'])===''||trim($meta['company_address']??'')==='')throw new DomainException('Complétez les adresses du client et de votre entreprise avant émission.');
            $lines=normalizeLineItems(json_decode($draft['lines_json'],true,64,JSON_THROW_ON_ERROR));$totals=calculateLineItems($lines);$first=$lines[0];
            $payload=$draft+['quantity'=>$first['quantity'],'unit'=>$first['unit'],'unit_price'=>$first['unit_price']];$payload['id']=0;
            $snapshot=docSnapshot($payload,$client,$meta);$snapshot['origin']='proposal';$snapshot['valid_until']=$draft['valid_until'];
            if($draft['copied_from_id'])$snapshot['previous_quote']=(string)scalar('SELECT number FROM documents WHERE id=?',[$draft['copied_from_id']]);
            $number=nextDocumentNumber('quote');
            query("INSERT INTO documents(order_id,client_id,kind,number,invoice_order_id,snapshot,subtotal,tax_rate,tax,total,valid_until) VALUES(NULL,?,'quote',?,NULL,?,?,?,?,?,?)",[$client['id'],$number,json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),decimalString($totals['subtotal'],2),$first['tax_rate'],decimalString($totals['tax'],2),decimalString($totals['total'],2),$draft['valid_until']]);
            $docId=(int)db()->lastInsertId();query('UPDATE quote_drafts SET issued_document_id=?,revision=revision+1 WHERE id=?',[$docId,$id]);audit('Devis émis sans commande','document',$docId);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Devis émis. Aucune commande ni écriture de chiffre d’affaires n’a été créée.');go(url('document',['id'=>$docId]));
    }
    if($action==='quote_decision'){
        $id=validId();$next=enumField('state',['Accepté','Refusé']);$note=field('decision_note',1000,true);
        if($next==='Accepté'&&($_POST['agreement_confirmed']??'')!=='1')throw new DomainException('Confirmez que vous avez obtenu l’accord du client.');
        db()->beginTransaction();try{
            [$doc,$order]=lockedQuote($id);
            if($next==='Accepté'&&in_array($doc['quote_state'],['Accepté','Converti'],true)){db()->rollBack();go(url('document',['id'=>$id]));}
            if($doc['quote_state']!=='Émis')throw new DomainException('Une décision est déjà enregistrée. Préparez une nouvelle proposition.');
            if($next==='Accepté'){
                if($doc['valid_until']&&$doc['valid_until']<date('Y-m-d'))throw new DomainException('Ce devis est expiré. Préparez une nouvelle version.');
                requireActiveQuoteClient((int)$doc['client_id'],true);
                if($order){
                    if($order['status']==='Annulée'||row("SELECT id FROM documents WHERE order_id=? AND kind='invoice'",[$order['id']]))throw new DomainException('Cette commande est annulée ou déjà facturée.');
                    if($order['source_quote_id']&&(int)$order['source_quote_id']!==$id)throw new DomainException('Un autre devis est déjà accepté pour cette commande.');
                    if(!quoteMatchesOrder($doc,$order))throw new DomainException('La commande ne correspond plus à ce devis. Créez un devis actualisé avant acceptation.');
                    query('UPDATE orders SET source_quote_id=? WHERE id=?',[$id,$order['id']]);
                }
            }
            query('UPDATE documents SET quote_state=?,accepted_at=?,decision_note=? WHERE id=?',[$next,$next==='Accepté'?gmdate('Y-m-d H:i:s'):null,$note,$id]);audit('Devis '.$next,'document',$id);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash($next==='Accepté'?'Accord enregistré. Vous pouvez créer ou ouvrir la commande.':'Refus enregistré. Le devis reste conservé.');go(url('document',['id'=>$id]));
    }
    if($action==='quote_convert'){
        $id=validId();db()->beginTransaction();try{
            [$doc,$order]=lockedQuote($id);
            if($doc['quote_state']==='Converti'&&$doc['order_id']){db()->rollBack();go(url('order_form',['id'=>$doc['order_id']]));}
            if($doc['quote_state']!=='Accepté')throw new DomainException('Enregistrez d’abord l’accord du client sur ce devis.');
            requireActiveQuoteClient((int)$doc['client_id'],true);
            if($order){if(!quoteMatchesOrder($doc,$order)||($order['source_quote_id']&&(int)$order['source_quote_id']!==$id))throw new DomainException('La commande liée ne correspond pas au devis accepté.');$orderId=(int)$order['id'];query('UPDATE orders SET source_quote_id=? WHERE id=?',[$id,$orderId]);}
            else{
                $s=json_decode($doc['snapshot'],true,512,JSON_THROW_ON_ERROR);$o=$s['order'];$lines=normalizeLineItems(snapshotLineSummary($doc,$s)['items']);$calc=calculateLineItems($lines);
                if($calc['subtotal']!==cents($doc['subtotal'])||$calc['tax']!==cents($doc['tax'])||$calc['total']!==cents($doc['total']))throw new DomainException('Les montants du devis ne correspondent pas aux lignes. Aucune commande créée.');
                $first=$lines[0];query("INSERT INTO orders(client_id,title,category,description,quantity,unit,unit_price,total,status,priority,due_date,payment_method,tax_rate,lines_json,tax_amount,source_quote_id) VALUES(?,?,?,?,?,?,?,?,'À préparer','Normale',?,?,?,?,?,?)",[$doc['client_id'],$o['title'],$o['category'],$o['description'],$first['quantity'],$first['unit'],$first['unit_price'],$doc['subtotal'],$o['due_date'],$o['payment_method'],$first['tax_rate'],json_encode($lines,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$doc['tax'],$id]);$orderId=(int)db()->lastInsertId();
            }
            query("UPDATE documents SET order_id=?,quote_state='Converti' WHERE id=?",[$orderId,$id]);audit('Devis converti en '.ref($orderId),'document',$id);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Commande liée au devis accepté. Les lignes commerciales sont verrouillées ; stock, tâches et paiements restent à gérer.');go(url('order_form',['id'=>$orderId]));
    }
    if($action==='quote_copy'){
        $id=validId();$key=requestKey();db()->beginTransaction();try{
            $doc=row("SELECT * FROM documents WHERE id=? AND kind='quote' FOR UPDATE",[$id]);if(!$doc)throw new DomainException('Devis introuvable.');
            $client=requireActiveQuoteClient((int)$doc['client_id'],true);
            if($existing=row('SELECT id FROM quote_drafts WHERE request_key=?',[$key])){db()->rollBack();go(url('quote_form',['id'=>$existing['id']]));}
            $s=json_decode($doc['snapshot'],true,512,JSON_THROW_ON_ERROR);$o=$s['order'];$lines=normalizeLineItems(snapshotLineSummary($doc,$s)['items']);
            query('INSERT INTO quote_drafts(client_id,title,category,description,lines_json,payment_method,due_date,valid_until,copied_from_id,request_key) VALUES(?,?,?,?,?,?,?,NULL,?,?)',[$client['id'],$o['title'],$o['category'],$o['description'],json_encode($lines,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$o['payment_method'],$o['due_date'],$id,$key]);$draftId=(int)db()->lastInsertId();audit('Nouvelle proposition depuis '.$doc['number'],'devis',$draftId);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Nouvelle proposition préparée. Vérifiez les dates et conditions ; le devis précédent est conservé.');go(url('quote_form',['id'=>$draftId]));
    }
    return false;
}
function snapshotForOrderDocument(array $order,array $client,array $meta): array {
    if(empty($order['source_quote_id']))return docSnapshot($order,$client,$meta);
    $quote=row("SELECT * FROM documents WHERE id=? AND kind='quote'",[$order['source_quote_id']]);
    if(!$quote||!in_array($quote['quote_state'],['Accepté','Converti'],true)||!quoteMatchesOrder($quote,$order))throw new DomainException('La commande ne correspond pas à son devis accepté.');
    $snapshot=json_decode($quote['snapshot'],true,512,JSON_THROW_ON_ERROR);$snapshot['origin']='converted';$snapshot['source_quote']=['number'=>$quote['number'],'accepted_at'=>$quote['accepted_at']];$snapshot['order']['id']=(int)$order['id'];$snapshot['order']['created_at']=$order['created_at'];return $snapshot;
}
function documentOriginLabel(array $doc,array $snapshot): string {
    if(($snapshot['origin']??'')==='proposal')return 'PROPOSITION · Créée le '.localDate($snapshot['order']['created_at']);
    return 'COMMANDE '.ref((int)$doc['order_id']).' · Ajoutée le '.localDate($snapshot['order']['created_at']);
}

function ensureSourceQuoteForeignKey(): void {
    if(!(int)scalar("SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='orders' AND constraint_name='fk_order_source_quote'"))db()->exec('ALTER TABLE orders ADD CONSTRAINT fk_order_source_quote FOREIGN KEY(source_quote_id) REFERENCES documents(id) ON DELETE RESTRICT');
}
