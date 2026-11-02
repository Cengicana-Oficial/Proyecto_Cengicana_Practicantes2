<?php
declare(strict_types=1);
const DIRECTORIO_COLUMNAS = ['nombre'=>'Nombre completo','cargo'=>'Cargo','empresa'=>'Empresa / ingenio','area'=>'Área','nivel'=>'Nivel','email'=>'Correo','telefono'=>'Teléfono','comites'=>'Comités','estado'=>'Estado','origen'=>'Origen','origen_detalle'=>'Detalle del origen','origen_fecha'=>'Fecha de alta','ultima'=>'Última interacción'];

function directorio_valor(array $c,string $campo,array $comites): string
{
    if ($campo==='comites') return implode('; ',array_map(static fn($id)=>$comites[$id] ?? $id,$c['comites']));
    if ($campo==='origen') return DIRECTORIO_ORIGENES[$c['origen_tipo']] ?? 'Base existente';
    if ($campo==='ultima') return $c['ultima'] ? $c['ultima']['fecha'].' · '.$c['ultima']['titulo'] : '';
    return (string)($c[$campo] ?? '');
}

function directorio_seleccionar(array $datos,array $d): array
{
    $ids=array_unique(array_map('intval',(array)($d['ids'] ?? [])));
    return array_values(array_filter($datos['contactos'],static fn($c)=>in_array($c['id'],$ids,true)));
}

function directorio_reporte(array $lista,array $d,array $comites): string
{
    $cols=array_values(array_intersect(array_keys(DIRECTORIO_COLUMNAS),(array)($d['cols'] ?? ['nombre','cargo','empresa','area','email','telefono'])));
    if (!in_array('nombre',$cols,true)) array_unshift($cols,'nombre');
    $colores=['verde'=>'#047857','azul'=>'#1d4ed8','gris'=>'#334155','violeta'=>'#6d28d9'];
    $color=$colores[$d['color'] ?? 'verde'] ?? $colores['verde'];
    $titulo=e(mb_substr((string)($d['titulo'] ?? 'Directorio de contactos'),0,180));
    $logo=(string)($d['logo'] ?? '');
    if (!preg_match('~^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$~',$logo) || strlen($logo)>1500000) $logo='';
    $grupos=[]; $agrupar=(string)($d['agrupar'] ?? '');
    foreach ($lista as $c) {
        $grupo=in_array($agrupar,['area','nivel','empresa','comites'],true)?directorio_valor($c,$agrupar,$comites):'';
        $grupos[$grupo][]=$c;
    }
    if ($agrupar!=='') ksort($grupos,SORT_NATURAL|SORT_FLAG_CASE);
    $html='<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>'.$titulo.'</title><style>body{font:12px "DejaVu Sans",Arial,sans-serif;color:#334155;margin:28px}h1{font-size:24px;color:'.$color.';margin:0}header{border-bottom:4px solid '.$color.';padding-bottom:16px;margin-bottom:20px}header img{max-height:55px;max-width:150px;float:right}p{white-space:pre-wrap}table{border-collapse:collapse;width:100%;margin:12px 0 24px;table-layout:fixed}th,td{text-align:left;padding:8px;border-bottom:1px solid #e2e8f0;word-wrap:break-word}th{background:'.$color.';color:white;font-size:11px}tr:nth-child(even){background:#f8fafc}h2{font-size:15px;color:'.$color.'}footer{font-size:10px;color:#64748b}@media print{body{margin:10mm}thead{display:table-header-group}tr{page-break-inside:avoid}}</style></head><body><header>'.($logo?'<img alt="Logo" src="'.e($logo).'">':'').'<h1>'.$titulo.'</h1><p>CENGICAÑA · '.count($lista).' contactos · '.date('d/m/Y').'</p><p>'.e(mb_substr((string)($d['descripcion'] ?? ''),0,3000)).'</p></header>';
    foreach ($grupos as $grupo=>$contactos) {
        if ($agrupar!=='') $html.='<h2>'.e($grupo ?: 'Sin clasificar').'</h2>';
        $html.='<table><thead><tr>';
        foreach ($cols as $col) $html.='<th>'.e(DIRECTORIO_COLUMNAS[$col]).'</th>';
        $html.='</tr></thead><tbody>';
        foreach ($contactos as $c) { $html.='<tr>'; foreach ($cols as $col) $html.='<td>'.e(directorio_valor($c,$col,$comites)).'</td>'; $html.='</tr>'; }
        $html.='</tbody></table>';
    }
    return $html.'<footer>Directorio de contactos · CENGICAÑA</footer></body></html>';
}

function directorio_excel(array $hojas): string
{
    directorio_dependencias();
    $book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    foreach ($hojas as $i=>$hoja) {
        $sheet=$i===0?$book->getActiveSheet():$book->createSheet(); $sheet->setTitle($hoja['nombre']);
        foreach ($hoja['filas'] as $r=>$fila) foreach (array_values($fila) as $c=>$valor) {
            // Texto explícito: los valores de usuarios nunca se ejecutan como fórmulas.
            $sheet->setCellValueExplicit(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c+1).($r+1),(string)$valor,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        $sheet->freezePane('A2');
        $sheet->getStyle('1:1')->getFont()->setBold(true);
        for ($c=1;$c<=count($hoja['filas'][0] ?? []);$c++) $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c))->setWidth(25);
    }
    $tmp=tempnam(sys_get_temp_dir(),'directorio-');
    try { (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($tmp); return file_get_contents($tmp); }
    finally { $book->disconnectWorksheets(); unlink($tmp); }
}

function directorio_pdf(string $html): string
{
    directorio_dependencias();
    $options=new \Dompdf\Options(); $options->set('isRemoteEnabled',false); $options->set('isPhpEnabled',false);
    $pdf=new \Dompdf\Dompdf($options); $pdf->loadHtml($html,'UTF-8'); $pdf->setPaper('A4','landscape'); $pdf->render(); return $pdf->output();
}

function directorio_enviar(PDO $pdo,array $user,array $d,string $html,string $contexto='Directorio de contactos'): void
{
    $para=array_values(array_unique(array_filter(array_map('trim',preg_split('/[;,\s]+/',(string)($d['para'] ?? ''))))));
    if (!$para || count($para)>50) throw new RuntimeException('Indica entre 1 y 50 destinatarios.');
    foreach ($para as $email) if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Destinatario no válido: '.$email);
    $asunto=trim((string)($d['asunto'] ?? '')); $cuerpo=trim((string)($d['cuerpo'] ?? ''));
    if ($asunto==='' || mb_strlen($asunto)>220 || mb_strlen($cuerpo)>15000) throw new RuntimeException('Revisa el asunto y el mensaje.');
    foreach (['MAIL_HOST','MAIL_USERNAME','MAIL_PASSWORD','MAIL_FROM_ADDRESS'] as $key) {
        if (in_array(app_env_value($key),['','change-me'],true)) throw new RuntimeException('Configura las credenciales SMTP en deploy/env/login.env para enviar correos. La lista aún no se ha enviado.');
    }
    directorio_dependencias();
    $mail=new \PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP(); $mail->Host=app_env_value('MAIL_HOST'); $mail->Port=(int)app_env_value('MAIL_PORT','2525');
    $mail->SMTPAuth=true; $mail->Username=app_env_value('MAIL_USERNAME'); $mail->Password=app_env_value('MAIL_PASSWORD');
    $mail->SMTPSecure=$mail->Port===465?'ssl':'tls'; $mail->CharSet='UTF-8'; $mail->Timeout=20;
    $mail->setFrom(app_env_value('MAIL_FROM_ADDRESS'),app_env_value('MAIL_FROM_NAME','CENGICAÑA'));
    foreach ($para as $email) $mail->addBCC($email);
    $mail->Subject=$asunto; $mail->Body=$cuerpo;
    $mail->addStringAttachment(directorio_pdf($html),$contexto==='Directorio de contactos'?'directorio_contactos.pdf':'planificacion.pdf','base64','application/pdf');
    try { $mail->send(); } catch (\PHPMailer\PHPMailer\Exception $e) { error_log('Directorio SMTP: '.$e->getMessage()); throw new RuntimeException('El servidor SMTP no confirmó el envío. Revisa la configuración antes de reintentar.'); }
    $pdo->prepare('INSERT INTO correos_enviados(asunto,cuerpo,destinatarios,grupo_destino,enviado_por) VALUES (?,?,?,?,?)')->execute([$asunto,$cuerpo,json_encode($para),$contexto,$user['nombre']]);
}
