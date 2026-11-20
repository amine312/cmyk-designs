<?php
const STOCK_UNITS=['u','m²','m','kg','l','rouleau','feuille','panneau'];
const STOCK_FAMILIES=['Vinyle','Bâche','Papier','Plexi','MDF / Bois','Encres','Accessoires','Autre'];
const MOVEMENT_KINDS=['Entrée','Sortie','Correction +','Correction -'];
const EQUIPMENT_STATES=['Disponible','En maintenance','En panne','Hors service'];
const EVENT_TYPES=['Entretien','Réparation','Contrôle','Panne'];
function qty(string|int|float $value): int {return decimalInt((string)$value,3,11);}
function quantityText(int $milli): string {return rtrim(rtrim(decimalString($milli,3),'0'),'.');}
function stockBalance(int $id): array {
    $net=(string)scalar('SELECT COALESCE(SUM(quantity*direction),0) FROM stock_movements WHERE item_id=?',[$id]);
    if(str_starts_with($net,'-'))throw new RuntimeException('Stock incohérent : solde négatif.');
    $onhand=qty($net);$reserved=qty((string)scalar("SELECT COALESCE(SUM(quantity),0) FROM stock_reservations WHERE item_id=? AND state='Active'",[$id]));
    if($reserved>$onhand)throw new RuntimeException('Réservations supérieures au stock physique.');
    return ['onhand'=>$onhand,'reserved'=>$reserved,'available'=>$onhand-$reserved];
}
function requestKey(): string {$k=field('request_key',64,true);if(!preg_match('/^[a-f0-9]{64}$/D',$k))throw new DomainException('Rechargez le formulaire avant de réessayer.');return $k;}
function optionalOrderId(): ?int {$value=field('order_id',20);if($value==='')return null;if(!preg_match('/^[1-9]\d*$/D',$value))throw new DomainException('Commande invalide.');return (int)$value;}
function lockedStockItem(int $id,bool $active=true): array {$item=row('SELECT * FROM stock_items WHERE id=? FOR UPDATE',[$id]);if(!$item)throw new DomainException('Article introuvable.');if($active&&$item['archived'])throw new DomainException('Réactivez cet article avant une opération.');return $item;}
function lockActiveOrder(?int $id): void {if($id===null)return;$order=row('SELECT id,status FROM orders WHERE id=? FOR UPDATE',[$id]);if(!$order||in_array($order['status'],['Livrée','Annulée'],true))throw new DomainException('Choisissez une commande en cours (hors livrée ou annulée).');}
function stockDetailsFromPost(?array $old): array {
    $values=[];
    foreach(['entry_date','expiry_date'] as $key)$values[$key]=array_key_exists($key,$_POST)?dateField($key):($old[$key]??($key==='entry_date'&&!$old?date('Y-m-d'):null));
    if($values['entry_date']&&$values['entry_date']>date('Y-m-d'))throw new DomainException('La date d’entrée ne peut pas être future.');
    if($values['entry_date']&&$values['expiry_date']&&$values['expiry_date']<$values['entry_date'])throw new DomainException('La date d’expiration doit être égale ou postérieure à la date d’entrée.');
    foreach(['wholesale_price'=>[2,9],'retail_price'=>[2,9],'unit_weight_kg'=>[3,8]] as $key=>[$precision,$digits]){
        if(!array_key_exists($key,$_POST)){$values[$key]=$old[$key]??null;continue;}
        $raw=field($key,20);if($raw===''){$values[$key]=null;continue;}
        $amount=decimalInt($raw,$precision,$digits);if($key==='unit_weight_kg'&&$amount===0)throw new DomainException('Le poids unitaire doit être positif, ou laissé vide.');
        $values[$key]=decimalString($amount,$precision);
    }
    return $values;
}
function stockDate(?string $value): string {return $value?DateTimeImmutable::createFromFormat('!Y-m-d',$value)->format('d/m/Y'):'—';}
function stockExpiryLabel(?string $value): string {return !$value?'Non renseignée':($value<date('Y-m-d')?'Expiré':($value===date('Y-m-d')?'Expire aujourd’hui':'Valable'));}
function inventoryAction(string $action): bool {
    if($action==='stock_save'){
        $id=(int)($_POST['id']??0);$name=field('name',190,true);$code=mb_strtoupper(field('code',50));if($code!==''&&!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,49}$/D',$code))throw new DomainException('Référence : lettres sans accents, chiffres, point, tiret ou underscore.');
        $family=enumField('family',STOCK_FAMILIES);$unit=enumField('unit',STOCK_UNITS);$min=decimalInt(field('min_quantity',20,true),3,8);$cost=decimalInt(field('reference_cost',20,true),2,9);$supplier=field('supplier',190);$location=field('location',190);$notes=field('notes',5000);
        db()->beginTransaction();try{
            $old=$id?lockedStockItem($id,false):null;
            $details=stockDetailsFromPost($old);
            if($old&&$old['unit']!==$unit&&((int)scalar('SELECT COUNT(*) FROM stock_movements WHERE item_id=?',[$id])>0||(int)scalar('SELECT COUNT(*) FROM stock_reservations WHERE item_id=?',[$id])>0))throw new DomainException('L’unité est verrouillée dès qu’un historique existe. Créez un autre article pour une autre unité.');
            if($code!==''&&row('SELECT id FROM stock_items WHERE code=? AND id<>?',[$code,$id]))throw new DomainException('Cette référence article est déjà utilisée.');
            $values=[$code?:null,$name,$family,$unit,decimalString($min,3),decimalString($cost,2),$supplier,$location,$notes];
            if($id)query('UPDATE stock_items SET code=?,name=?,family=?,unit=?,min_quantity=?,reference_cost=?,supplier=?,location=?,notes=? WHERE id=?',[...$values,$id]);
            else{query('INSERT INTO stock_items(code,name,family,unit,min_quantity,reference_cost,supplier,location,notes) VALUES(?,?,?,?,?,?,?,?,?)',$values);$id=(int)db()->lastInsertId();}
            query('UPDATE stock_items SET entry_date=?,expiry_date=?,wholesale_price=?,retail_price=?,unit_weight_kg=? WHERE id=?',[...array_values($details),$id]);
            if($code==='')query("UPDATE stock_items SET code=? WHERE id=?",['MAT-'.str_pad((string)$id,5,'0',STR_PAD_LEFT),$id]);audit('Article enregistré','stock',$id);db()->commit();
        }catch(PDOException $ex){if(db()->inTransaction())db()->rollBack();if(($ex->errorInfo[1]??0)===1062)throw new DomainException('Cette référence article est déjà utilisée.');throw $ex;}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Article enregistré. Ajoutez une entrée pour saisir le stock initial.');go(url('stock_form',['id'=>$id]));
    }
    if($action==='stock_move'){
        $id=validId('item_id');$kind=enumField('kind',MOVEMENT_KINDS);$quantity=decimalInt(field('quantity',20,true),3,8);if($quantity<=0)throw new DomainException('La quantité doit être positive.');$orderId=optionalOrderId();$reason=field('reason',500,true);$key=requestKey();$direction=in_array($kind,['Entrée','Correction +'],true)?1:-1;
        db()->beginTransaction();try{
            lockActiveOrder($orderId);lockedStockItem($id);
            if(row('SELECT id FROM stock_movements WHERE request_key=?',[$key])){db()->rollBack();flash('Mouvement déjà enregistré.');go(url('stock_form',['id'=>$id]));}
            $s=stockBalance($id);if($direction<0&&$quantity>$s['available'])throw new DomainException('Stock disponible insuffisant. Les quantités réservées sont protégées.');
            if($direction>0&&$s['onhand']+$quantity>99999999999999)throw new DomainException('Quantité physique maximale dépassée.');
            query('INSERT INTO stock_movements(item_id,kind,quantity,direction,order_id,reason,request_key,user_id) VALUES(?,?,?,?,?,?,?,?)',[$id,$kind,decimalString($quantity,3),$direction,$orderId,$reason,$key,user()['id']]);audit($kind,'stock',$id);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Mouvement enregistré.');go(url('stock_form',['id'=>$id]));
    }
    if($action==='stock_reserve'){
        $id=validId('item_id');$orderId=validId('order_id');$quantity=decimalInt(field('quantity',20,true),3,8);if($quantity<=0)throw new DomainException('La quantité doit être positive.');$note=field('note',500);$key=requestKey();
        db()->beginTransaction();try{
            lockActiveOrder($orderId);lockedStockItem($id);
            if(row('SELECT id FROM stock_reservations WHERE request_key=?',[$key])){db()->rollBack();flash('Réservation déjà enregistrée.');go(url('stock_form',['id'=>$id]));}
            if($quantity>stockBalance($id)['available'])throw new DomainException('Stock disponible insuffisant pour réserver cette quantité.');
            query("INSERT INTO stock_reservations(item_id,order_id,quantity,state,note,request_key) VALUES(?,?,?,'Active',?,?)",[$id,$orderId,decimalString($quantity,3),$note,$key]);audit('Réservation','stock',$id);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Quantité réservée pour la commande. Le stock physique reste inchangé.');go(url('stock_form',['id'=>$id]));
    }
    if($action==='reservation_resolve'){
        $id=validId('reservation_id');$next=enumField('state',['Libérée','Consommée']);$r=row('SELECT * FROM stock_reservations WHERE id=?',[$id]);if(!$r)throw new DomainException('Réservation introuvable.');
        db()->beginTransaction();try{
            $order=row('SELECT id,status FROM orders WHERE id=? FOR UPDATE',[$r['order_id']]);lockedStockItem((int)$r['item_id'],false);$r=row('SELECT * FROM stock_reservations WHERE id=? FOR UPDATE',[$id]);
            if($r['state']!=='Active')throw new DomainException('Cette réservation a déjà été traitée.');
            if($next==='Consommée'){
                if(in_array($order['status'],['Livrée','Annulée'],true))throw new DomainException('La commande doit être en cours pour consommer une réservation.');
                if(qty($r['quantity'])>stockBalance((int)$r['item_id'])['onhand'])throw new DomainException('Stock physique insuffisant.');
                query("INSERT INTO stock_movements(item_id,kind,quantity,direction,order_id,reason,request_key,user_id) VALUES(?,'Sortie',?,-1,?,?,?,?)",[$r['item_id'],$r['quantity'],$r['order_id'],'Consommation réservation #'.$id,hash('sha256','reservation:'.$id),user()['id']]);
            }
            query('UPDATE stock_reservations SET state=?,resolved_at=UTC_TIMESTAMP() WHERE id=?',[$next,$id]);audit('Réservation '.$next,'stock',(int)$r['item_id']);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash($next==='Consommée'?'Réservation consommée : sortie de stock enregistrée une seule fois.':'Réservation libérée.');go(url('stock_form',['id'=>$r['item_id']]));
    }
    if($action==='stock_archive'){
        $id=validId();$archive=($_POST['archived']??'')==='1'?1:0;
        db()->beginTransaction();try{lockedStockItem($id,false);$s=stockBalance($id);if($archive&&($s['onhand']>0||$s['reserved']>0))throw new DomainException('Un article doit avoir un stock et des réservations à zéro pour être archivé.');query('UPDATE stock_items SET archived=? WHERE id=?',[$archive,$id]);audit($archive?'Article archivé':'Article réactivé','stock',$id);db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('État de l’article mis à jour.');go(url('stock'));
    }
    if($action==='equipment_save'){
        $id=(int)($_POST['id']??0);$name=field('name',190,true);$category=field('category',100,true);$model=field('model',190);$serial=field('serial_number',190);$location=field('location',190);$supplier=field('supplier',190);$state=enumField('state',EQUIPMENT_STATES);$purchase=dateField('purchase_date');$next=dateField('next_service');$cost=decimalInt(field('purchase_cost',20,true),2,9);$notes=field('notes',5000);
        db()->beginTransaction();try{if($id&&!row('SELECT id FROM equipment WHERE id=? FOR UPDATE',[$id]))throw new DomainException('Équipement introuvable.');$values=[$name,$category,$model,$serial,$location,$supplier,$state,$purchase,decimalString($cost,2),$next,$notes];
            if($id)query('UPDATE equipment SET name=?,category=?,model=?,serial_number=?,location=?,supplier=?,state=?,purchase_date=?,purchase_cost=?,next_service=?,notes=? WHERE id=?',[...$values,$id]);else{query('INSERT INTO equipment(name,category,model,serial_number,location,supplier,state,purchase_date,purchase_cost,next_service,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?)',$values);$id=(int)db()->lastInsertId();}audit('Équipement enregistré','matériel',$id);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Équipement enregistré.');go(url('equipment_form',['id'=>$id]));
    }
    if($action==='equipment_event'){
        $id=validId('equipment_id');$type=enumField('event_type',EVENT_TYPES);$date=dateField('event_date');if(!$date||$date>date('Y-m-d'))throw new DomainException('L’intervention réalisée doit avoir une date valide, au plus tard aujourd’hui.');$description=field('description',5000,true);$cost=decimalInt(field('cost',20,true),2,9);$state=enumField('state_after',EQUIPMENT_STATES);$next=dateField('next_service');if($next&&$next<$date)throw new DomainException('La prochaine échéance doit suivre la date d’intervention.');$key=requestKey();
        db()->beginTransaction();try{$eq=row('SELECT * FROM equipment WHERE id=? FOR UPDATE',[$id]);if(!$eq||$eq['archived'])throw new DomainException('Équipement introuvable ou archivé.');if(row('SELECT id FROM equipment_events WHERE request_key=?',[$key])){db()->rollBack();flash('Intervention déjà enregistrée.');go(url('equipment_form',['id'=>$id]));}
            query('INSERT INTO equipment_events(equipment_id,event_type,event_date,description,cost,state_after,next_service,request_key) VALUES(?,?,?,?,?,?,?,?)',[$id,$type,$date,$description,decimalString($cost,2),$state,$next,$key]);
            query('UPDATE equipment SET state=?,next_service=? WHERE id=?',[$state,$next,$id]);audit($type,'matériel',$id);db()->commit();
        }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('Intervention enregistrée. État et prochaine échéance actualisés.');go(url('equipment_form',['id'=>$id]));
    }
    if($action==='equipment_archive'){
        $id=validId();$archive=($_POST['archived']??'')==='1'?1:0;
        db()->beginTransaction();try{if(!row('SELECT id FROM equipment WHERE id=? FOR UPDATE',[$id]))throw new DomainException('Équipement introuvable.');query('UPDATE equipment SET archived=? WHERE id=?',[$archive,$id]);audit($archive?'Équipement archivé':'Équipement réactivé','matériel',$id);db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
        flash('État de l’équipement mis à jour.');go(url('equipment'));
    }
    if($action==='inventory_export'){
        $type=enumField('type',['movements','reservations','equipment','events','stock']);audit('Export CSV',$type);
        $data=match($type){
            'movements'=>query('SELECT m.id,i.code,i.name,i.unit,m.kind,m.quantity,m.direction,m.order_id,m.reason,m.created_at FROM stock_movements m JOIN stock_items i ON i.id=m.item_id ORDER BY m.id'),
            'reservations'=>query('SELECT r.id,i.code,i.name,i.unit,r.order_id,r.quantity,r.state,r.note,r.created_at,r.resolved_at FROM stock_reservations r JOIN stock_items i ON i.id=r.item_id ORDER BY r.id'),
            'events'=>query('SELECT e.id,q.name,e.event_type,e.event_date,e.description,e.cost,e.state_after,e.next_service,e.created_at FROM equipment_events e JOIN equipment q ON q.id=e.equipment_id ORDER BY e.id'),
            'equipment'=>query('SELECT * FROM equipment ORDER BY id'),
            'stock'=>query('SELECT i.*,(SELECT COALESCE(SUM(m.quantity*m.direction),0) FROM stock_movements m WHERE m.item_id=i.id) physical_quantity,(SELECT COALESCE(SUM(r.quantity),0) FROM stock_reservations r WHERE r.item_id=i.id AND r.state=\'Active\') reserved_quantity FROM stock_items i ORDER BY id')
        };
        header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="cmyk-'.$type.'-'.date('Y-m-d').'.csv"');$out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");$first=true;
        while($r=$data->fetch()){if($first){fputcsv($out,array_keys($r),';','"','');$first=false;}fputcsv($out,array_map(static function($v){$s=(string)($v??'');return preg_match('/^[\s]*[=+@\-]/u',$s)?"'".$s:$s;},$r),';','"','');}if($first)fputcsv($out,['Aucune donnée'],';','"','');fclose($out);exit;
    }
    return false;
}
