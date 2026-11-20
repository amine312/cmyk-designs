<?php
const LINE_UNITS=['u','m²','m','lot','h'];
const MAX_ORDER_LINES=50;
function normalizeLineItems(mixed $raw): array {
    if(!is_array($raw)||count($raw)<1||count($raw)>MAX_ORDER_LINES)throw new DomainException('Une commande doit contenir de 1 à 50 lignes.');
    $result=[];
    foreach(array_values($raw) as $i=>$line){
        if(!is_array($line))throw new DomainException('Ligne invalide.');
        foreach(['designation','quantity','unit','unit_price','discount_rate','tax_rate'] as $key)if(!isset($line[$key])||!is_string($line[$key]))throw new DomainException('Complétez tous les champs de chaque ligne.');
        $label=trim($line['designation']);if($label===''||mb_strlen($label)>190)throw new DomainException('Désignation obligatoire, 190 caractères maximum par ligne.');
        if(!in_array($line['unit'],LINE_UNITS,true))throw new DomainException('Unité invalide.');
        $qty=decimalInt($line['quantity'],3,6);if($qty<1)throw new DomainException('La quantité doit être supérieure à zéro.');
        $price=decimalInt($line['unit_price'],2,7);$discount=decimalInt($line['discount_rate'],2,3);$rate=decimalInt($line['tax_rate'],2,3);
        if($discount>10000||$rate>10000)throw new DomainException('Remise et TVA doivent être comprises entre 0 et 100 %.');
        $result[]=['designation'=>$label,'quantity'=>decimalString($qty,3),'unit'=>$line['unit'],'unit_price'=>decimalString($price,2),'discount_rate'=>decimalString($discount,2),'tax_rate'=>decimalString($rate,2)];
    }
    return $result;
}
function calculateLineItems(array $lines): array {
    $totals=['gross_subtotal'=>0,'discount'=>0,'subtotal'=>0,'tax'=>0,'total'=>0];$items=[];$groups=[];
    foreach($lines as $line){
        $qty=decimalInt($line['quantity'],3,6);$price=decimalInt($line['unit_price'],2,7);$discount=decimalInt($line['discount_rate'],2,3);$rate=decimalInt($line['tax_rate'],2,3);
        $gross=intdiv($qty*$price+500,1000);if($gross>999999999999)throw new DomainException('Montant de ligne trop élevé.');
        $off=intdiv($gross*$discount+5000,10000);$net=$gross-$off;$vat=intdiv($net*$rate+5000,10000);
        $amounts=['gross_subtotal'=>$gross,'discount'=>$off,'subtotal'=>$net,'tax'=>$vat,'total'=>$net+$vat];
        foreach($amounts as $key=>$value){$totals[$key]+=$value;if($totals[$key]>999999999999)throw new DomainException('Montant total trop élevé.');}
        $items[]=$line+$amounts;
        $key=$line['tax_rate'];$groups[$key]??=['rate'=>$key,'base'=>0,'tax'=>0];$groups[$key]['base']+=$net;$groups[$key]['tax']+=$vat;
    }
    uksort($groups,static fn($a,$b)=>decimalInt((string)$a,2,3)<=>decimalInt((string)$b,2,3));
    return $totals+['items'=>$items,'vat_groups'=>array_values($groups)];
}
function orderLineItems(array $order): array {
    if(!empty($order['lines_json']))return json_decode($order['lines_json'],true,64,JSON_THROW_ON_ERROR);
    return [['designation'=>$order['category'],'quantity'=>$order['quantity'],'unit'=>$order['unit'],'unit_price'=>$order['unit_price'],'discount_rate'=>'0.00','tax_rate'=>$order['tax_rate']]];
}
function postedLineItems(string $category): array {
    if(array_key_exists('lines',$_POST))return normalizeLineItems($_POST['lines']);
    // Compatibility with a pre-upgrade single-line form. Existing multi-line orders reject this path.
    return normalizeLineItems([['designation'=>$category,'quantity'=>$_POST['quantity']??'','unit'=>$_POST['unit']??'','unit_price'=>$_POST['unit_price']??'','discount_rate'=>'0','tax_rate'=>$_POST['tax_rate']??'0']]);
}
function snapshotLineSummary(array $doc,array $snapshot): array {
    if(($snapshot['calculation']??'')==='credit-allocation-v1')return creditSummaryItems($snapshot['credit_items']);
    if(isset($snapshot['lines']))return calculateLineItems($snapshot['lines']);
    // Historical snapshots retain their issued amounts, without recalculating old documents.
    $o=$snapshot['order'];$net=cents($doc['subtotal']);$vat=cents($doc['tax']);
    return ['gross_subtotal'=>$net,'discount'=>0,'subtotal'=>$net,'tax'=>$vat,'total'=>cents($doc['total']),'items'=>[['designation'=>$o['category'],'quantity'=>$o['quantity'],'unit'=>$o['unit'],'unit_price'=>$o['unit_price'],'discount_rate'=>'0.00','tax_rate'=>$doc['tax_rate'],'gross_subtotal'=>$net,'discount'=>0,'subtotal'=>$net,'tax'=>$vat,'total'=>cents($doc['total'])]],'vat_groups'=>[['rate'=>$doc['tax_rate'],'base'=>$net,'tax'=>$vat]]];
}
