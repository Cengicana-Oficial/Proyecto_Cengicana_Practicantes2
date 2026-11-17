<?php
declare(strict_types=1);
require_once __DIR__.'/directorio.php';
require_once __DIR__.'/directorio-exportar.php';
require_once __DIR__.'/agenda.php';
require_once __DIR__.'/agenda-informe.php';
require_once __DIR__.'/marca.php';
$agendaTipo=$view==='eventos'?'eventos':'reuniones';
$agendaRecurso=$agendaTipo;
if(can_view_resource($user,$agendaRecurso)) {
    agenda_preparar($pdo);$_SESSION['agenda_csrf'] ??= bin2hex(random_bytes(32));
}
if(isset($_GET['agenda_api']) || $_SERVER['REQUEST_METHOD']==='POST' || isset($_GET['plantilla'])) {
    try {
        if(!can_view_resource($user,$agendaRecurso)){http_response_code(403);throw new RuntimeException('No tienes acceso a esta sección.');}
        $accion=(string)($_POST['agenda_accion'] ?? (isset($_GET['plantilla'])?'plantilla':'datos'));
        $d=json_decode((string)($_POST['datos'] ?? '{}'),true,64,JSON_THROW_ON_ERROR);if(!is_array($d))throw new RuntimeException('Datos no válidos.');
        if($_SERVER['REQUEST_METHOD']==='POST' && !hash_equals($_SESSION['agenda_csrf'],(string)($_POST['csrf'] ?? ''))){http_response_code(403);throw new RuntimeException('El formulario expiró. Recarga la página.');}
        if($_SERVER['REQUEST_METHOD']!=='POST'&&!in_array($accion,['datos','plantilla'],true)){http_response_code(405);throw new RuntimeException('Método no permitido.');}
        if($accion==='plantilla') {
            $anio=(int)($_GET['anio'] ?? date('Y'));
            $filas=match($_GET['plantilla'] ?? 'plan'){
                'asistentes'=>[['Nombre completo','Correo','Teléfono','Empresa / ingenio','Cargo'],['Ing. Nombre Apellido','correo@empresa.com','5555-0000','Ingenio Ejemplo','Gerente Agrícola']],
                'encuesta'=>[['Calificación (1 a 5)','Comentario'],['5','Muy útil'],['4','']],
                default=>[['Comité','Mes','Fecha tentativa','Temas a revisar','Responsable'],['Comite de Riego','Octubre',$anio.'-10-14','Resultados de uniformidad',''],['Comite de Nutricion','11','','Resultados de ensayos','']]
            };
            header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="plantilla_'.(in_array($_GET['plantilla'] ?? '',['asistentes','encuesta'],true)?$_GET['plantilla']:'planificacion').'.csv"');
            $f=fopen('php://output','w');fwrite($f,"\xEF\xBB\xBF");foreach($filas as $row)fputcsv($f,$row);fclose($f);exit;
        }
        if(in_array($accion,['informe_html','informe_pdf'],true) && $agendaTipo==='eventos') {
            // Informe de evaluación de un evento (como el de eventos de Cengicursos).
            $evento=current(array_filter(agenda_datos($pdo,$user,'eventos')['registros'],static fn($r)=>$r['id']===(int)($d['id'] ?? 0)));
            if(!$evento)throw new RuntimeException('El evento ya no existe.');
            $html=agenda_informe_evento($evento,$accion==='informe_html');$archivo='informe_'.trim(preg_replace('/[^a-z0-9]+/','_',normalize_text($evento['nombre'])),'_');
            if($accion==='informe_pdf'){$pdf=directorio_pdf($html,'portrait');header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.$archivo.'.pdf"');echo $pdf;}
            else{header('Content-Type: text/html; charset=utf-8');echo $html;}
            exit;
        }
        if(in_array($accion,['preview','html','pdf','csv','enviar'],true)) {
            $datos=agenda_datos($pdo,$user,$agendaTipo);$anio=(int)($d['anio'] ?? date('Y'));$comite=(string)($d['comite'] ?? 'todos');
            $rows=array_values(array_filter($datos['registros'],static fn($r)=>substr($r['plan_mes'] ?: ($r['fecha'] ?? ''),0,4)===(string)$anio && ($agendaTipo==='eventos'||$comite==='todos'||$r['comite_id']===$comite)));
            $nombres=array_column($datos['comites'],'nombre','id');
            $filas=[$agendaTipo==='eventos'?['Mes','Evento','Fecha planificada','Fecha real','Lugar','Modalidad','Público','Estado']:['Comité','Mes','Fecha tentativa','Temas a revisar','Estado','Fecha de ejecución']];
            foreach($rows as $r)$filas[]=$agendaTipo==='eventos'?[$r['plan_mes'],$r['nombre'],$r['plan_fecha'],$r['realizada']?$r['fecha']:'',$r['lugar'],$r['modalidad'],$r['publico'],AGENDA_ESTADOS[$r['estado']]]:[$nombres[$r['comite_id']] ?? '',$r['plan_mes'] ?: 'No planificada',$r['plan_fecha'],$r['plan_temas'],AGENDA_ESTADOS[$r['estado']],$r['realizada']?$r['fecha']:''];
            if($accion==='csv'){
                header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="planificacion_'.$agendaTipo.'_'.$anio.'.csv"');
                $f=fopen('php://output','w');fwrite($f,"\xEF\xBB\xBF");foreach($filas as $row)fputcsv($f,array_map(static fn($v)=>preg_match('/^[=+@\-\t\r]/',(string)$v)?"'".$v:(string)$v,$row));fclose($f);exit;
            }
            $html=agenda_reporte($agendaTipo,$rows,$datos['comites'],$anio,$d);
            if($accion==='enviar') {
                if(!can_manage_resource($user,'correos')){http_response_code(403);throw new RuntimeException('No tienes permiso para enviar correos.');}
                directorio_enviar($pdo,$user,$d,$html,'Planificación de '.$agendaTipo);$mensaje='Planificación enviada.';
            }else{
                if($accion==='pdf'){$pdf=directorio_pdf($html);header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="planificacion_'.$agendaTipo.'.pdf"');echo $pdf;}
                else{header('Content-Type: text/html; charset=utf-8');if($accion==='html')header('Content-Disposition: attachment; filename="planificacion_'.$agendaTipo.'.html"');echo $html;}exit;
            }
        }elseif($accion!=='datos')$mensaje=agenda_mutar($pdo,$user,$agendaTipo,$accion,$d,$_FILES);
        header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'mensaje'=>$mensaje ?? '','datos'=>agenda_datos($pdo,$user,$agendaTipo)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }catch(Throwable $e){
        if($pdo->inTransaction())$pdo->rollBack();if(http_response_code()<400)http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');error_log('Agenda: '.$e->getMessage());
        echo json_encode(['ok'=>false,'error'=>$e instanceof PDOException?'No se pudo guardar el registro. Revisa los datos.':($e instanceof RuntimeException?$e->getMessage():'No se pudo procesar el archivo o la solicitud.')],JSON_UNESCAPED_UNICODE);
    }exit;
}

function agenda_reporte(string $tipo,array $rows,array $comites,int $anio,array $d): string
{
    $marca=marca_color($d['color'] ?? 'verde');
    $titulo=e((string)($d['titulo'] ?? ('Planificación de '.$tipo.' '.$anio)));$logo=(string)($d['logo'] ?? '');
    if(strlen($logo)>1500000 || !preg_match('~^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$~',$logo))$logo='';
    $estados='';foreach(MARCA_ESTADOS as $k=>[$fondo,$texto])$estados.=".{$k}{background:{$fondo};color:{$texto}}";
    $html='<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>'.$titulo.'</title><style>body{font:11px "DejaVu Sans",Arial;margin:22px}h1{font-size:22px;margin:0 0 4px}header{margin-bottom:16px}.cab{width:100%;border-collapse:collapse;margin-bottom:8px}.cab td{border:0;padding:0;vertical-align:top}.cab img{max-height:56px;max-width:170px}table.plan{width:100%;border-collapse:collapse;table-layout:fixed}table.plan th,table.plan td{padding:7px;border:1px solid '.MARCA_GRIS.';word-wrap:break-word;vertical-align:top}p{white-space:pre-wrap}tr{page-break-inside:avoid}table.plan td p{margin:0 0 3px;padding:3px 5px;border-radius:4px}.cancelada{text-decoration:line-through}'.$estados.marca_css($marca).'.cab td{border:0}</style></head><body><header><table class="cab"><tr><td><h1>'.$titulo.'</h1><p>'.e((string)($d['descripcion'] ?? '')).'</p><small>CENGICAÑA · '.$anio.' · '.count($rows).' registros</small></td><td style="text-align:right">'.($logo?'<img alt="Logo" src="'.e($logo).'">':marca_logo()).'</td></tr></table>'.marca_franja().'</header><table class="plan">';
    if($tipo==='reuniones') {
        $html.='<thead><tr><th style="width:140px">Comité</th>';foreach(AGENDA_MESES as $m)$html.='<th>'.mb_substr($m,0,3).'</th>';$html.='</tr></thead><tbody>';
        foreach($comites as $c){$rs=array_filter($rows,static fn($r)=>$r['comite_id']===$c['id']);if(!$rs)continue;$html.='<tr><td>'.e($c['nombre']).'</td>';
            for($i=1;$i<=12;$i++){$mes=$anio.'-'.str_pad((string)$i,2,'0',STR_PAD_LEFT);$html.='<td>';foreach($rs as $r){if(($r['plan_mes'] ?: substr((string)$r['fecha'],0,7))!==$mes)continue;$html.='<p class="'.e($r['estado']).'">'.e(AGENDA_ESTADOS[$r['estado']]).'<br>'.e($r['realizada']?$r['fecha']:($r['plan_fecha'] ?: 'Mes')).'</p>';}$html.='</td>';}$html.='</tr>';}
    }else{
        $html.='<thead><tr><th>Mes</th><th>Evento</th><th>Fecha</th><th>Lugar / modalidad</th><th>Público</th><th>Estado</th></tr></thead><tbody>';
        foreach($rows as $r)$html.='<tr class="'.e($r['estado']).'"><td>'.e(AGENDA_MESES[(int)substr($r['plan_mes'],5)-1]).'</td><td>'.e($r['nombre']).'</td><td>'.e($r['realizada']?$r['fecha']:($r['plan_fecha'] ?: 'Por definir')).'</td><td>'.e($r['lugar'].' · '.$r['modalidad']).'</td><td>'.e($r['publico']).'</td><td>'.e(AGENDA_ESTADOS[$r['estado']]).'</td></tr>';
    }
    return $html.'</tbody></table><p>'.e((string)($d['nota'] ?? 'Las fechas están sujetas a reprogramación.')).'</p></body></html>';
}
