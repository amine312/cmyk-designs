<?php
function documentData(int $id): array {
    $doc=row('SELECT * FROM documents WHERE id=?',[$id]);if(!$doc){http_response_code(404);throw new DomainException('Document introuvable.');}
    $snapshot=json_decode($doc['snapshot'],true,512,JSON_THROW_ON_ERROR);return [$doc,$snapshot];
}
function documentHtml(array $doc,array $s,array $summary): string {
    $i=$s['issuer'];$c=$s['client'];$o=$s['order'];$type=mb_strtoupper(documentType($doc));
    $lines=static function(array $values): string {return implode('<br>',array_map('e',array_values(array_filter($values,static fn($v)=>$v!==''&&$v!==null))));};
    $issuer=$lines([$i['company_address'],$i['company_phone'],$i['company_email'],'ICE : '.($i['company_ice']?:'—').' · IF : '.($i['company_if']?:'—'),'RC : '.($i['company_rc']?:'—')]);
    $buyer=$lines([$c['company'],$c['contact'],($c['contact_position']??''),$c['address'],$c['phone'],$c['email'],'ICE : '.($c['ice']?:'—').' · IF : '.($c['tax_id']?:'—')]);
    $paymentLines='';
    if($doc['kind']==='invoice')foreach(all("SELECT * FROM order_payments WHERE order_id=? ORDER BY id",[$doc['order_id']]) as $payment){
        $paymentLines.='<tr nobr="true"><td>'.e(localDate($payment['created_at'])).'</td><td>'.e($payment['method']).'<br>'.e($payment['reference']).'</td><td>'.e($payment['state']).'</td><td align="right">'.e(money($payment['amount'])).'</td></tr>';
    }
    $paymentHtml=$paymentLines?'<br><h2>Historique des règlements</h2><table cellpadding="4" class="body"><tr bgcolor="#edf7fb"><th>Date</th><th>Méthode / référence</th><th>État</th><th align="right">Montant</th></tr>'.$paymentLines.'</table>':'';
    $lineSummary=snapshotLineSummary($doc,$s);$itemRows='';$vatRows='';
    foreach($lineSummary['items'] as $item)$itemRows.='<tr class="body" nobr="true"><td width="34%">'.e($item['designation']).'</td><td width="14%" align="right">'.e($item['quantity'].' '.$item['unit']).'</td><td width="16%" align="right">'.e(money($item['unit_price'])).'</td><td width="10%" align="right">'.e($item['discount_rate']).'</td><td width="9%" align="right">'.e($item['tax_rate']).'</td><td width="17%" align="right">'.e(money(decimalString($item['subtotal'],2))).'</td></tr>';
    foreach($lineSummary['vat_groups'] as $group)$vatRows.='<tr nobr="true"><td width="30%">'.e($group['rate']).' %</td><td width="40%" align="right">'.e(money(decimalString($group['base'],2))).'</td><td width="30%" align="right">'.e(money(decimalString($group['tax'],2))).'</td></tr>';
    $created=localDate($doc['created_at']);$added=localDate($o['created_at']);$terms=nl2br(e($i['document_terms']));$status=$summary['balance']===0?'Soldée':($summary['paid']>0?'Partiellement réglée':'Non réglée');
    return '<style>h1{margin:0;font-size:23pt;color:#101e36}h2{margin:0;font-size:13pt;color:#101e36}.label{font-size:9pt;color:#687b92}.head{background-color:#101e36;color:white;font-size:9pt}.body{font-size:9.5pt;color:#24354f}.foot{font-size:8pt;color:#73869c}.total{background-color:#edf7fb;font-size:11pt}</style>'
    .'<table cellpadding="0"><tr><td width="60%"><h1>'.e($i['company_name']).'</h1><span class="label">Impression &amp; services publicitaires</span></td><td width="40%" align="right"><h2>'.$type.'</h2><b>'.e($doc['number']).'</b><br><span class="label">Émis le '.$created.'</span></td></tr></table><br>'
    .'<table cellpadding="8" class="body"><tr><td width="50%" bgcolor="#f4f7fb"><b>ÉMETTEUR</b><br>'.$issuer.'</td><td width="50%" bgcolor="#edf7fb"><b>CLIENT</b><br>'.$buyer.'</td></tr></table><br>'
    .'<span class="label">'.e(documentOriginLabel($doc,$s)).'</span><h2>'.e($o['title']).'</h2><p class="body">'.nl2br(e($o['description'])).'</p>'
    .(!empty($s['source_quote'])?'<p class="label">Selon devis accepté '.e($s['source_quote']['number']).'</p>':'')
    .($doc['kind']==='credit'?'<p class="body"><b>À déduire de la facture '.e($s['source_invoice']['number']).' du '.e(localDate($s['source_invoice']['created_at'])).'</b><br>Motif : '.nl2br(e($s['credit_reason'])).'<br>Aucun remboursement ni mouvement de stock automatique.</p>':'')
    .($doc['kind']==='quote'&&!empty($s['valid_until'])?'<p class="label">Valable jusqu’au '.e($s['valid_until']).'</p>':'')
    .'<table cellpadding="4" border="0"><thead><tr class="head"><th width="34%">Désignation</th><th width="14%" align="right">Quantité</th><th width="16%" align="right">P.U. HT</th><th width="10%" align="right">Rem. %</th><th width="9%" align="right">TVA %</th><th width="17%" align="right">HT net</th></tr></thead><tbody>'.$itemRows.'</tbody></table><br><h2>Détail de TVA</h2><table cellpadding="5" class="body"><thead><tr bgcolor="#edf7fb"><th width="30%">Taux</th><th width="40%" align="right">Base HT nette</th><th width="30%" align="right">TVA</th></tr></thead><tbody>'.$vatRows.'</tbody></table><br>'
    .'<table cellpadding="4" class="body" nobr="true"><tr><td width="55%"></td><td width="25%">HT avant remise</td><td width="20%" align="right">'.e(money(decimalString($lineSummary['gross_subtotal'],2))).'</td></tr><tr><td width="55%"></td><td width="25%">Remises</td><td width="20%" align="right">'.e(money(decimalString($lineSummary['discount'],2))).'</td></tr><tr><td width="55%"></td><td width="25%">HT net</td><td width="20%" align="right">'.e(money($doc['subtotal'])).'</td></tr><tr><td width="55%"></td><td width="25%">TVA totale</td><td width="20%" align="right">'.e(money($doc['tax'])).'</td></tr><tr class="total"><td width="55%"></td><td width="25%"><b>'.($doc['kind']==='credit'?'Total TTC à déduire':'Total TTC').'</b></td><td width="20%" align="right"><b>'.e(money($doc['total'])).'</b></td></tr></table><br>'
    .'<table cellpadding="6" class="body" nobr="true"><tr><td width="50%"><b>Règlement</b><br>Méthode prévue : '.e($o['payment_method']).'<br>Livraison : '.e($o['due_date']?:'À convenir').'</td><td width="50%">'.($doc['kind']==='invoice'?'<b>'.e($status).'</b><br>Avoirs déduits : '.e(money(decimalString($summary['credited'],2))).'<br>Net après avoirs : '.e(money(decimalString($summary['total'],2))).'<br>Solde en faveur du client : '.e(money(decimalString($summary['available'],2))).'<br>Réglé confirmé : '.e(money(decimalString($summary['paid'],2))).'<br>En attente : '.e(money(decimalString($summary['pending'],2))).'<br><b>Reste dû : '.e(money(decimalString($summary['balance'],2))).'</b>':($doc['kind']==='credit'?'<b>Avoir émis</b><br>Montants positifs à déduire. Aucun remboursement automatique.':'<b>Proposition commerciale</b><br>Les paiements sont suivis sur la commande.')).'</td></tr></table>'.$paymentHtml.'<span class="body">'.$terms.'</span><br><span class="foot">Devise : MAD · Coordonnées et montants conservés à la création du document. Les règlements de facture reflètent la situation au téléchargement.</span>';
}
function documentPdfBytes(int $id): string {
    [$doc,$snapshot]=documentData($id);$summary=$doc['order_id']?paymentSummary((int)$doc['order_id']):emptyPaymentSummary();
    if(!defined('K_TCPDF_EXTERNAL_CONFIG'))define('K_TCPDF_EXTERNAL_CONFIG',true);
    require_once __DIR__.'/vendor/tcpdf/tcpdf.php';
    $pdf=new TCPDF('P','mm','A4',true,'UTF-8',false);$pdf->setPrintHeader(false);$pdf->setPrintFooter(true);$pdf->SetCreator('CMYK Designs');$pdf->SetAuthor($snapshot['issuer']['company_name']);$pdf->SetTitle($doc['number']);$pdf->SetMargins(14,14,14);$pdf->SetAutoPageBreak(true,16);$pdf->setFooterFont(['dejavusans','',8]);$pdf->SetFooterMargin(10);$pdf->SetFont('dejavusans','',9.5);$pdf->AddPage();$pdf->writeHTML(documentHtml($doc,$snapshot,$summary),true,false,true,false,'');return $pdf->Output($doc['number'].'.pdf','S');
}
function downloadDocumentPdf(int $id): never {
    if(!row('SELECT id FROM documents WHERE id=?',[$id])){http_response_code(404);exit('Document introuvable.');}
    $bytes=documentPdfBytes($id);$number=(string)scalar('SELECT number FROM documents WHERE id=?',[$id]);
    header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.$number.'.pdf"');header('Content-Length: '.strlen($bytes));echo $bytes;exit;
}
