<?php
require dirname(__DIR__).'/app/bootstrap.php';
restore_exception_handler();
$row=['designation'=>'Affiche','quantity'=>'2.5','unit'=>'u','unit_price'=>'200','discount_rate'=>'10','tax_rate'=>'20'];
$sum=calculateLineItems(normalizeLineItems([$row]));
if([$sum['subtotal'],$sum['tax'],$sum['total']]!==[45000,9000,54000])throw new Exception('totals');
$p=['items'=>$sum['items'],'used'=>[0],'available'=>[2500]];
$credit=calculateCreditItems($p,['1']);
if($credit['total']!==21600)throw new Exception('credit');
$p['used']=[1000];$p['available']=[1500];
if(calculateCreditItems($p,['1.5'])['total']!==32400)throw new Exception('remainder');
foreach([['quantity','0'],['tax_rate','101']] as [$k,$v]){try{normalizeLineItems([array_replace($row,[$k=>$v])]);throw new Exception('unexpected success');}catch(DomainException){}}
try{calculateCreditItems($p,['2']);throw new Exception('excess credit');}catch(DomainException){}
echo "6 business checks passed\n";
