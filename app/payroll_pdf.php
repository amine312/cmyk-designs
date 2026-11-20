<?php
function attestationText(array $s): string {
    $e=$s['employee'];$text='Nous soussignés, '.$s['signatory'].', agissant pour '.$s['issuer']['company_name'].', attestons que '.$e['full_name'].' fait partie de nos effectifs au poste de '.$e['job_title'].' depuis le '.$e['hire_date'].'.';
    if($s['kind']==='salary')$text.="\n\nSon salaire de base mensuel brut est de ".money($e['base_salary']).', hors primes et avant retenues.';
    return $text."\n\nCette attestation est établie à la demande de l’intéressé(e).";
}
function hrPdfData(string $page,int $id): array {
    $table=$page==='payroll_form'?'payroll_records':'employee_documents';$r=row("SELECT * FROM $table WHERE id=?",[$id]);if(!$r||($page==='payroll_form'&&$r['state']!=='Émis'))throw new DomainException('Document RH émis introuvable.');
    return [$r,json_decode($r['snapshot'],true,512,JSON_THROW_ON_ERROR)];
}
function hrPdfHtml(string $page,array $r,array $s): string {
    $i=$s['issuer'];$e=$s['employee'];$isPay=$page==='payroll_form';$title=$isPay?'FICHE DE PAIE':($r['kind']==='work'?'ATTESTATION DE TRAVAIL':'ATTESTATION DE SALAIRE DE BASE');
    $html='<style>h1{font-size:22pt;color:#101e36}h2{font-size:13pt;color:#101e36}.body{font-size:10pt;color:#24354f}.small{font-size:8.5pt;color:#687b92}</style><h1>'.e($i['company_name']).'</h1><p class="body">'.nl2br(e($i['company_address'])).'<br>'.e(($i['company_phone']??'').' · '.($i['company_email']??'')).'<br>ICE : '.e($i['company_ice']??'').' · IF : '.e($i['company_if']??'').' · RC : '.e($i['company_rc']??'').'</p><h2>'.$title.'</h2><p class="small">'.e($r['number']).' · Émis le '.e(localDate($isPay?$r['issued_at']:$r['created_at'])).'</p>';
    if(!$isPay)return $html.'<p class="body">'.nl2br(e(attestationText($s))).'</p><br><p class="body">Fait à '.e($s['place']).', le '.e($s['issued_date']).'<br>'.e($s['signatory']).'<br>Signature et cachet</p>';
    $p=$s['payroll'];$html.='<table cellpadding="7" class="body"><tr bgcolor="#edf7fb"><td><b>'.e($e['full_name']).'</b><br>EMP-'.str_pad((string)$e['id'],5,'0',STR_PAD_LEFT).'<br>'.e($e['job_title']).' · '.e($e['department']).'<br>Entrée : '.e($e['hire_date']).'</td><td><b>Période : '.e($p['period']).'</b><br>Devise : MAD<br>Montants saisis et validés</td></tr></table><br><table cellpadding="7" class="body"><thead><tr bgcolor="#101e36" color="#ffffff"><th width="65%">RUBRIQUE</th><th width="35%" align="right">MONTANT</th></tr></thead><tbody>';
    foreach(PAYROLL_FIELDS as $k=>$label)$html.='<tr nobr="true"><td width="65%">'.e($label).'</td><td width="35%" align="right">'.e(money($p[$k])).'</td></tr>';
    foreach(['gross'=>'Total brut','deductions'=>'Total retenues','net'=>'Net à payer'] as $k=>$label)$html.='<tr bgcolor="#edf7fb" nobr="true"><td width="65%"><b>'.e($label).'</b></td><td width="35%" align="right"><b>'.e(money(decimalString($s['totals'][$k],2))).'</b></td></tr>';
    return $html.'</tbody></table><p class="body">'.nl2br(e($p['note'])).'</p><p class="small">Présences renseignées pour la période : '.(int)$s['activity']['counts']['Présent'].' jour(s) · absences renseignées : '.(int)$s['activity']['counts']['Absent'].' · congés : '.(int)$s['activity']['counts']['Congé'].'. Les jours non saisis restent inconnus.</p><p class="small">Cotisations et impôt renseignés manuellement. Aucune retenue automatique pour absence. Ce document ne constitue pas une preuve de versement.</p><br><p class="body">Validation, signature et cachet</p>';
}
function hrPdfBytes(string $page,int $id): string {
    [$record,$snapshot]=hrPdfData($page,$id);if(!defined('K_TCPDF_EXTERNAL_CONFIG'))define('K_TCPDF_EXTERNAL_CONFIG',true);require_once __DIR__.'/vendor/tcpdf/tcpdf.php';
    $pdf=new TCPDF('P','mm','A4',true,'UTF-8',false);$pdf->setPrintHeader(false);$pdf->setPrintFooter(true);$pdf->SetCreator('CMYK Designs');$pdf->SetAuthor($snapshot['issuer']['company_name']);$pdf->SetTitle($record['number']);$pdf->SetMargins(18,18,18);$pdf->SetAutoPageBreak(true,18);$pdf->setFooterFont(['dejavusans','',8]);$pdf->SetFooterMargin(10);$pdf->SetFont('dejavusans','',10);$pdf->AddPage();$pdf->writeHTML(hrPdfHtml($page,$record,$snapshot),true,false,true,false,'');return $pdf->Output($record['number'].'.pdf','S');
}
function downloadHrPdf(string $page,int $id): never {
    try{[$r]=hrPdfData($page,$id);}catch(DomainException){http_response_code(404);exit('Document RH émis introuvable.');}$bytes=hrPdfBytes($page,$id);header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.$r['number'].'.pdf"');header('Content-Length: '.strlen($bytes));echo $bytes;exit;
}
