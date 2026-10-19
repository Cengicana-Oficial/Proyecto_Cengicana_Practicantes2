<?php
require_once __DIR__.'/menu.php';require_once __DIR__.'/proformas_helpers.php';
if(!cengi_ins_puede_ver_pagos()){http_response_code(403);exit;}
$db=conectar();$pf=cengi_pf_cargar($db,(int)($_GET['id']??0));$limite=cengi_ins_ingenio_limitado();
if(!$pf||($limite!==null&&cengi_texto_normalizado($limite)!==cengi_texto_normalizado($pf['empresa']))){http_response_code(403);exit;}
$q=$db->prepare('SELECT documento FROM proforma_versiones WHERE proforma_id=? AND revision=?');$q->execute([$pf['id'],(int)($_GET['revision']??0)]);$json=$q->fetchColumn();
if(!$json){http_response_code(404);exit;}$snapshot=json_decode($json,true,512,JSON_THROW_ON_ERROR);
$pdf=cengi_pf_pdf($snapshot['proforma'],$snapshot['cuentas']);
header('Content-Type: application/pdf');header('Cache-Control: private,no-store');header('Content-Disposition: inline; filename="Proforma_'.$pf['numero'].'_revision_'.(int)$_GET['revision'].'.pdf"');echo $pdf;
