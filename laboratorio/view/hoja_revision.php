<?php
require_once __DIR__.'/../includes/auth.php';
require_once __DIR__.'/../conexion.php';
require_once __DIR__.'/../includes/shell_sidebar.php';
require_once __DIR__.'/../models/validacion_tecnica_model.php';
lab_require_permission('laboratorio.validacion_tecnica.ver');

$id=(int)($_GET['id_formulario']??0);
labCatalogoAnalisisAsegurarEsquema($conexion);
$q=$conexion->prepare("
    SELECT h.datos, f.id_formulario, f.fecha, f.analista, ef.nombre AS estado,
           l.codigo_lote, lr.inicio, lr.fin, COALESCE(tm.nombre,'Sin tipo') AS tipo_muestra,
           ta.unidad, ta.limite_min, ta.limite_max
    FROM lab_hoja_formulario h
    JOIN formulario f ON f.id_formulario=h.id_formulario
    JOIN lote_rango lr ON lr.id_rango=f.id_rango
    JOIN lote l ON l.id_lote=lr.id_lote
    LEFT JOIN estado_formulario ef ON ef.id_estado=f.id_estado
    LEFT JOIN tipo_analisis ta ON ta.id_tipo=f.id_tipo_analisis
    LEFT JOIN tipo_muestra tm ON tm.id_tipo=ta.id_tipo_muestra
    WHERE h.id_formulario=?");
$q->execute([$id]);
$row=$q->fetch(PDO::FETCH_ASSOC);
if(!$row){http_response_code(404);exit('Hoja no encontrada.');}
$snapshot=json_decode($row['datos'],true,512,JSON_THROW_ON_ERROR);

$esRevision=lab_validacion_estado_es_revision($row['estado']??'');
$estado=mb_strtolower((string)$row['estado']);
$codigos=array_values(array_filter(array_map(static fn($m)=>(string)($m['codigo_muestra']??''),$snapshot['muestras']??[])));
$rango=$codigos?($codigos[0]===end($codigos)?$codigos[0]:$codigos[0].' – '.end($codigos)):($row['inicio']===$row['fin']?$row['inicio']:$row['inicio'].' – '.$row['fin']);

$notas=[];
$h=$conexion->prepare("SELECT accion, estado_nuevo, usuario, fecha, comentario FROM historial_formulario WHERE id_formulario=? AND TRIM(COALESCE(comentario,''))<>'' ORDER BY fecha, id_historial");
$h->execute([$id]);
foreach($h->fetchAll(PDO::FETCH_ASSOC) as $n){
    $ts=strtotime((string)$n['fecha']);
    $notas[]=['quien'=>$n['usuario']?:'Sistema','fh'=>$ts?date('d/m/Y H:i',$ts):(string)$n['fecha'],'texto'=>$n['comentario'],
        'tipo'=>preg_match('/rechaz/i',(string)$n['estado_nuevo'])||in_array($n['accion'],['reanalisis','devuelto_informe'],true)?'alerta':'info'];
}

$payload=[
    'modo'=>$esRevision&&lab_can('laboratorio.validacion_tecnica.aprobar')?'revisor':'lectura',
    'endpoint'=>'../controllers/validacion_tecnica_controller.php',
    'motivos'=>LAB_MOTIVOS_RECHAZO,
    'volver'=>'validacion_tecnica_view.php',
    'cap'=>$snapshot,
    'notas'=>$notas,
    'formulario'=>[
        'id'=>$id,'estado'=>$row['estado']?:'Sin estado',
        'estado_clase'=>strpos($estado,'aprob')!==false?'is-approved':($esRevision?'is-review':''),
        'lote'=>$row['codigo_lote'],'rango'=>$rango,'tipo'=>$row['tipo_muestra'],
        'analista'=>$row['analista'],'fecha'=>$row['fecha'],'unidad'=>$row['unidad'],
        'limite_min'=>$row['limite_min']!==null?(float)$row['limite_min']:null,
        'limite_max'=>$row['limite_max']!==null?(float)$row['limite_max']:null,
    ],
];
lab_shell_head('Validación técnica','Formulario #'.$id.' · datos capturados, cálculos y curva',['../css/lab_shell.css?v=20261006','../styles/validacion_tecnica.css?v='.filemtime(__DIR__.'/../styles/validacion_tecnica.css')]);
lab_shell_open('validacion_tecnica_view.php');
?>
<section class="vt-wrap">
    <a class="vt-backlink" href="validacion_tecnica_view.php"><span class="material-symbols-outlined" style="font-size:16px;">arrow_back</span>Volver a validación técnica</a>
    <div id="wf-review"></div>
</section>
<script type="application/json" id="wf-review-data"><?= json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS) ?></script>
<script src="../js/hojas_definiciones.js?v=<?= filemtime(__DIR__.'/../js/hojas_definiciones.js') ?>"></script>
<script src="../js/validacion_tecnica.js?v=<?= filemtime(__DIR__.'/../js/validacion_tecnica.js') ?>"></script>
<script src="../js/hoja_revision.js?v=<?= filemtime(__DIR__.'/../js/hoja_revision.js') ?>"></script>
<?php lab_shell_close(); ?>
