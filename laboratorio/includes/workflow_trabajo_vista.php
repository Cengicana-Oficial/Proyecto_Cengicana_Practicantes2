<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/../conexion.php';
require_once __DIR__.'/shell_sidebar.php';
require_once __DIR__.'/workflow_trabajo_helper.php';
require_once __DIR__.'/schema_safe_helper.php';
if($wfModo==='plan')lab_require_permission('laboratorio.kanban.ver');
else {lab_require_module_access();if(!lab_can('laboratorio.formularios_pendientes.ver')&&!lab_is_technician())lab_forbidden();}
lab_ensure_schema_safe(fn()=>wfSchema($conexion),'workflow_trabajo_v3');
$_SESSION['workflow_csrf']??=bin2hex(random_bytes(32));
$wfUser=lab_current_user();$wfManager=lab_can('laboratorio.kanban.gestionar');
$wfData=['tareas'=>wfTareas($conexion),'usuarios'=>wfUsuarios(),'user'=>$wfUser,'manager'=>$wfManager,'csrf'=>$_SESSION['workflow_csrf'],'modo'=>$wfModo,'hoja'=>$_GET['hoja']??''];
if($wfModo!=='plan'){
    $wfData+=wfResumenBandeja($conexion,$wfUser);
    // Campo "Equipo" de las hojas: equipos activos y equipo por defecto del Catálogo de análisis.
    require_once __DIR__.'/catalogo_control_helper.php';
    try{labCatalogoControlEsquema($conexion);$eq=labEquiposParaHojas($conexion);$wfData['equipos']=$eq['equipos'];$wfData['equiposHoja']=$eq['por_hoja'];}
    catch(Throwable $e){error_log('[laboratorio][bandeja] equipos: '.$e->getMessage());}
}
else{
    require_once __DIR__.'/identificacion_lote_helper.php';
    lab_ensure_schema_safe(fn()=>labMuestraIdentificacionEnsureSchema($conexion),'muestra_identificacion');
    lab_ensure_schema_safe(fn()=>labIdentificacionLoteSchema($conexion),'identificacion_lote_v3');
    $wfData['identificacion']=(object)wfIdentificacionResumen($conexion);
    $wfData['seguimiento']=(object)wfSeguimientoLotes($conexion);
}
$title=$wfModo==='plan'?'Planificación del trabajo':'Bandeja del analista';
// La bandeja usa el formato de hojas de trabajo del prototipo, compartido con Validación técnica;
// no carga workflow_trabajo.css porque sus reglas #wf-app pisarían ese formato.
$wfCss=$wfModo==='plan'
    ?['../css/lab_shell.css?v=20261006','../styles/workflow_trabajo.css?v='.filemtime(__DIR__.'/../styles/workflow_trabajo.css')]
    :['../css/lab_shell.css?v=20261006','../styles/validacion_tecnica.css?v='.filemtime(__DIR__.'/../styles/validacion_tecnica.css'),'../styles/bandeja_analista.css?v='.filemtime(__DIR__.'/../styles/bandeja_analista.css')];
lab_shell_head($title,$wfModo==='plan'?'Avance por lote y asignación por análisis':'Hojas de trabajo por análisis',$wfCss);
lab_shell_open($wfModo==='plan'?'kanban_view.php':'solicitudes_pendientes_tecnico.php',$title);
?>
<?php if($wfModo==='plan'): ?>
<section id="wf-app">
<p class="wf-intro">Resumen por tipo de muestra: cada fila es un lote y cada columna un análisis solicitado, con su avance (muestras aprobadas / solicitadas). Use <b>Ver muestras</b> para revisar qué muestras faltan, asignar analistas y definir la prioridad del lote.</p>
<p id="wf-status" role="status" aria-live="polite"></p>
<div id="wf-list-view">
<div id="wf-kpis" class="wf-kpis"></div>
<div class="wf-plan-tabs" role="tablist" aria-label="Lotes">
<button type="button" role="tab" aria-selected="true" data-plan-tab="curso">En curso <span id="wf-n-curso"></span></button>
<button type="button" role="tab" aria-selected="false" data-plan-tab="final">Finalizados <span id="wf-n-final"></span></button>
</div>
<div class="wf-toolbar">
<label>Buscar<input id="wf-search" type="search" placeholder="Lote, cliente, muestra o análisis"></label>
<label>Tipo<select id="wf-type"><option value="">Todos los tipos</option></select></label>
<label data-solo-curso>Etapa<select id="wf-state"><option value="">Todos en curso</option><option value="pendiente">Por iniciar</option><option value="proceso">En análisis</option><option value="validacion">En validación</option><option value="informe">Listos para informe</option></select></label>
<label data-solo-final hidden>Periodo<select id="wf-period"><option value="30">Últimos 30 días</option><option value="90">Últimos 90 días</option><option value="365">Último año</option><option value="">Todos</option></select></label>
<label data-solo-final hidden>Entrega<select id="wf-entrega"><option value="">Todas</option><option value="pendiente">Pendientes de envío</option><option value="enviado">Enviados por correo</option></select></label>
</div>
<p class="wf-legend wf-plan-legend" data-solo-curso><span><i class="pendiente"></i>Pendiente</span><span><i class="proceso"></i>En análisis</span><span><i class="validacion"></i>En validación</span><span><i class="validado"></i>Aprobado</span><span><i class="rechazado"></i>Reanálisis</span><span><b>—</b> No solicitado</span></p>
<p id="wf-count"></p><div id="wf-list"></div>
<div id="wf-modal" class="wf-modal" hidden>
<div class="wf-modal-card" role="dialog" aria-modal="true" aria-labelledby="wf-modal-title">
<header class="wf-modal-head"><div><small id="wf-modal-sub"></small><h2 id="wf-modal-title"></h2></div><button type="button" id="wf-modal-close" aria-label="Cerrar">×</button></header>
<div class="wf-plan-tabs wf-modal-tabs" role="tablist" aria-label="Detalle del lote">
<button type="button" role="tab" aria-selected="false" data-modal-tab="seguimiento">Seguimiento</button>
<button type="button" role="tab" aria-selected="true" data-modal-tab="muestras">Muestras y asignación</button>
</div>
<div id="wf-modal-body"></div>
</div>
</div>
</div>
<div id="wf-sheet-view" hidden>
<div class="wf-toolbar"><button type="button" id="wf-back">← Volver a mis colas</button><button type="button" id="wf-grid-mode">Vista grilla</button><button type="button" id="wf-tablet-mode">Vista tablet</button></div>
<div class="wf-card wf-sheet-head"><div><small>Hoja de trabajo</small><h2 id="wf-sheet-title"></h2><p id="wf-sheet-summary"></p></div><div class="wf-toolbar"><button type="button" id="wf-save">Guardar avance</button><button type="button" id="wf-send" class="wf-primary">Enviar a validación técnica</button></div></div>
<div id="wf-sheet-body"></div>
</div>
</section>
<?php else: ?>
<section id="wf-app" class="vt-wrap wf-bandeja">
<div id="wf-list-view">
<p class="vt-intro">Cada fila es una <b>hoja de trabajo</b> con el mismo formato que el laboratorio usa en papel: filas de <b>blanco y control</b>, campos de encabezado y, cuando aplica, <b>curva de calibración</b>. El analista solo captura lecturas; los cálculos los revisa el técnico en <b>Validación técnica</b>.<br><b>Orden:</b> primero los lotes de prioridad Alta, luego Media y Baja (la prioridad es del lote, para que todos sus análisis salgan juntos y se emita su informe). Como analista ves por defecto <b>tus análisis asignados</b>; cambia el filtro para ver los de todo el equipo.</p>
<div id="wf-kpis" class="bd-kpis"></div>
<div class="vt-toolbar bd-toolbar">
<input id="wf-search" type="search" aria-label="Buscar" placeholder="Buscar lote, cliente, muestra o análisis…">
<div class="bd-filters">
<select id="wf-analyst" aria-label="Filtrar por analista"><option value="">Todos los analistas</option><option value="sin">Sin asignar</option></select>
<select id="wf-type" aria-label="Filtrar por tipo de muestra"><option value="">Todo tipo de muestra</option></select>
</div>
</div>
<p id="wf-status" class="bd-status" role="status" aria-live="polite"></p>
<div class="bd-section-head"><h2 class="vt-section-title">Cola de captura</h2><span id="wf-count" class="small muted"></span></div>
<div id="wf-list"></div>
</div>
<div id="wf-sheet-view" hidden>
<div class="bd-sheet-top">
<button type="button" id="wf-back" class="vt-backlink bd-linkbtn"><span class="material-symbols-outlined" style="font-size:16px;">arrow_back</span>Volver a mis colas</button>
<div class="bd-seg" role="group" aria-label="Modo de captura"><button type="button" id="wf-grid-mode" class="active"><span class="material-symbols-outlined">grid_on</span>Vista grilla</button><button type="button" id="wf-tablet-mode"><span class="material-symbols-outlined">tablet_android</span>Vista tablet</button></div>
</div>
<div class="ws-card"><div class="ws-card-head">
<div><div class="ws-sec-title">Hoja de trabajo</div><h3 id="wf-sheet-title">—</h3><div class="small muted" id="wf-sheet-summary"></div></div>
<div class="ws-chips"><button type="button" id="wf-save" class="cengi-btn cengi-btn-ghost cengi-btn-sm">Guardar avance</button><button type="button" id="wf-send" class="cengi-btn cengi-btn-primary cengi-btn-sm">Enviar a validación técnica</button></div>
</div></div>
<div id="wf-sheet-body"></div>
</div>
</section>
<?php endif; ?>
<script type="application/json" id="wf-data"><?= json_encode($wfData,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS) ?></script>
<script src="../js/hojas_definiciones.js?v=<?= filemtime(__DIR__.'/../js/hojas_definiciones.js') ?>"></script>
<script src="../js/workflow_trabajo.js?v=<?= filemtime(__DIR__.'/../js/workflow_trabajo.js') ?>"></script>
<?php lab_shell_close(); ?>
