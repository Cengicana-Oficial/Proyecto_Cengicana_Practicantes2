<?php if(!isset($db,$cid,$sessions)){http_response_code(403);exit;}
// Editor por sesion (Gestion del aula): grabaciones y materiales, tareas/evaluaciones y asistencia de cada
// sesion, con vista previa y edicion en el mismo lugar. Las acciones estan en aula_gestion.php.
$resources=aula_resources($db,$cid);$instructorLinks=aula_instructor_links($db,$cid);$edVista=aula_vista_ready($db);
$edAlumnos=aula_rows($db,'SELECT a.id,p.nombre_participantes FROM asignaciones a JOIN participantes p ON p.id=a.participantes_id WHERE a.cursos_id=? AND a.estado_asignaciones=1 AND p.estado_participantes=1 ORDER BY p.nombre_participantes',[$cid]);
$edVentanas=array_column(aula_rows($db,'SELECT v.* FROM aula_asistencia_ventanas v JOIN curso_sesiones s ON s.id=v.sesion_id JOIN planificacion_cursos pc ON pc.id=s.planificacion_id WHERE pc.curso_id=?',[$cid]),null,'sesion_id');
$edAsistencia=[];
foreach(aula_rows($db,'SELECT r.* FROM aula_asistencias r JOIN asignaciones a ON a.id=r.asignacion_id WHERE a.cursos_id=?',[$cid]) as $r)$edAsistencia[(int)$r['sesion_id']][(int)$r['asignacion_id']]=$r;
$edEntregas=[];
foreach(aula_rows($db,'SELECT t.evaluacion_id,COUNT(*) AS entregados,SUM(t.nota IS NULL) AS por_revisar,MIN(CASE WHEN t.nota IS NULL THEN t.id END) AS primero_pendiente,COUNT(DISTINCT t.asignacion_id) AS personas FROM aula_intentos t JOIN aula_evaluaciones e ON e.id=t.evaluacion_id WHERE e.curso_id=? AND t.entregado IS NOT NULL GROUP BY t.evaluacion_id',[$cid]) as $r)$edEntregas[(int)$r['evaluacion_id']]=$r;
$edAhora=date('Y-m-d H:i:s');$edHoy=date('Y-m-d');
$edVer=$edAlumnos?(int)$edAlumnos[0]['id']:0;
// Evaluacion de cada instructor y anuncios por sesion.
$edSurveys=[];foreach(aula_instructor_surveys($db,$cid) as $sv)$edSurveys[(int)$sv['instructor_id']]=$sv;
$edResp=aula_respuestas_instructor($db,$cid);
$edAnuncios=[];foreach(aula_anuncios($db,$cid) as $an)if($an['sesion_id'])$edAnuncios[(int)$an['sesion_id']][]=$an;
$edTeamsCurso=aula_teams_url($db,$cid)?:'';
$edDt=static function($v){return $v?aula_h(str_replace(' ','T',substr($v,0,16))):'';};
// Un recurso: vista previa, estado y formularios de edicion / eliminacion.
$edRecurso=static function(array $r,$ancla) use($cid,$sessions,$edVista,$edDt){
    [$estado,$tono]=$edVista?aula_recurso_estado($r):['Visible','is-complete'];
    $embedInfo=$r['url']?aula_video_embed($r['url']):null;$esVideo=$r['tipo']==='video';
    $reproductor=aula_video_html($r['titulo'],$r['url'],$r['archivo_id'],$r['mime']??'');
    ob_start(); ?>
    <article class="aula-ed-recurso">
        <div class="aula-ed-recurso-cab">
            <span class="aula-ed-icono <?= $esVideo?'is-video':'' ?>"><span class="glyphicon glyphicon-<?= $esVideo?'film':'file' ?>" aria-hidden="true"></span></span>
            <span class="aula-ed-recurso-txt"><strong><?= aula_h($r['titulo']) ?></strong>
                <small><?= $esVideo?'Grabación':'Material' ?> · <?= $r['archivo_id']?aula_h(($r['mime']==='video/mp4'?'MP4 ':'PDF ').'· '.$r['nombre']):($embedInfo?$embedInfo['fuente']:'Enlace externo') ?><?= (int)$r['orden']?' · orden '.(int)$r['orden']:'' ?></small></span>
            <span class="aula-badge <?= $tono ?>"><?= aula_h($estado) ?></span>
        </div>
        <?php if($reproductor): ?><div class="aula-ed-video"><?= $reproductor ?></div><?php else: ?>
        <details class="aula-ed-previa"><summary>Ver contenido</summary>
            <p><?php if($r['archivo_id']): ?><a href="aula_archivo.php?id=<?= (int)$r['archivo_id'] ?>" target="_blank" rel="noopener">Abrir <?= $r['mime']==='video/mp4'?'video':'documento' ?> ↗</a><?php elseif(aula_url($r['url'])): ?><a href="<?= aula_h($r['url']) ?>" target="_blank" rel="noopener noreferrer"><?= aula_h($r['url']) ?> ↗</a><?php endif; ?></p>
            <?php if($esVideo&&$r['url']): ?><p class="aula-falta">Este enlace no se puede mostrar como video dentro del aula: usa un enlace de YouTube, Vimeo, Google Drive o Loom, o sube un MP4.</p><?php endif; ?>
        </details>
        <?php endif; ?>
        <?php if($edVista): ?>
        <details class="aula-ed-editar"><summary>Editar</summary>
            <form method="post" enctype="multipart/form-data" class="aula-form">
                <?= aula_csrf() ?><input type="hidden" name="accion" value="recurso_editar"><input type="hidden" name="recurso" value="<?= (int)$r['id'] ?>"><input type="hidden" name="ancla" value="<?= aula_h($ancla) ?>">
                <div class="aula-courses">
                    <label>Título<input name="titulo" maxlength="180" required value="<?= aula_h($r['titulo']) ?>"></label>
                    <label>Sesión<select name="sesion_id"><option value="0">Material general del curso</option><?php foreach($sessions as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$r['sesion_id']===(int)$s['id']?'selected':'' ?>><?= aula_h(date('d/m',strtotime($s['fecha'])).' · '.$s['tema']) ?></option><?php endforeach; ?></select></label>
                    <?php if($r['contenido_id']): ?><label class="is-ancho">Enlace<?= $esVideo?' del video (YouTube, Vimeo, Google Drive o Loom)':'' ?><input name="url" maxlength="3000" required value="<?= aula_h($r['url']) ?>"></label>
                    <?php elseif($r['archivo_id']): ?><label>Reemplazar archivo (opcional)<input type="file" name="material" accept="<?= $esVideo?'video/mp4':'application/pdf' ?>"><small><?= $esVideo?'MP4 hasta 15 MB':'PDF hasta 5 MB' ?>. Vacío = conservar el actual.</small></label><?php endif; ?>
                    <label>Orden<input type="number" name="orden" value="<?= (int)$r['orden'] ?>"></label>
                    <label>Disponible desde<input type="datetime-local" name="disponible_desde" value="<?= $edDt($r['disponible_desde']??'') ?>"><small>Vacío = de inmediato.</small></label>
                    <label class="aula-ed-check"><input type="checkbox" name="visible" <?= (int)($r['visible']??1)?'checked':'' ?>> Visible para los estudiantes</label>
                </div>
                <div class="aula-ed-acciones"><button class="btn btn-primary btn-sm">Guardar cambios</button></div>
            </form>
            <form method="post" class="aula-ed-borrar" onsubmit="return confirm('¿Eliminar «<?= aula_h(addslashes($r['titulo'])) ?>»? El estudiante dejará de verlo y el archivo se borra.');">
                <?= aula_csrf() ?><input type="hidden" name="accion" value="recurso_eliminar"><input type="hidden" name="recurso" value="<?= (int)$r['id'] ?>"><input type="hidden" name="ancla" value="<?= aula_h($ancla) ?>">
                <button class="btn btn-link btn-sm aula-ed-peligro"><span class="glyphicon glyphicon-trash" aria-hidden="true"></span> Eliminar recurso</button>
            </form>
        </details>
        <?php endif; ?>
    </article>
    <?php return ob_get_clean();
};
// Formulario corto para publicar un recurso directamente en una sesion (o como material general).
$edNuevo=static function($sid,$ancla){
    ob_start(); ?>
    <details class="aula-ed-nuevo"><summary><span class="glyphicon glyphicon-plus" aria-hidden="true"></span> Agregar grabación o material</summary>
        <form method="post" enctype="multipart/form-data" class="aula-form">
            <?= aula_csrf() ?><input type="hidden" name="accion" value="material"><input type="hidden" name="sesion_id" value="<?= (int)$sid ?>"><input type="hidden" name="ancla" value="<?= aula_h($ancla) ?>">
            <div class="aula-courses">
                <label>Título<input name="titulo" maxlength="180" required></label>
                <label>Tipo<select name="tipo_recurso"><option value="video">Grabación (YouTube, Vimeo, Drive o MP4)</option><option value="material">Material (PDF o enlace)</option></select></label>
                <label class="is-ancho">Enlace del video o recurso<input name="url" maxlength="3000" placeholder="https://youtube.com/watch?v=... · https://vimeo.com/... · https://..."></label>
                <label>O subir archivo<input type="file" name="material" accept="application/pdf,video/mp4"><small>PDF 5 MB · MP4 15 MB</small></label>
                <label>Disponible desde (opcional)<input type="datetime-local" name="disponible_desde"></label>
                <label class="aula-ed-check"><input type="checkbox" name="visible" checked> Visible para los estudiantes</label>
                <input type="hidden" name="orden" value="0">
            </div>
            <div class="aula-ed-acciones"><button class="btn btn-primary btn-sm">Publicar</button></div>
        </form>
    </details>
    <?php return ob_get_clean();
};
$edGenerales=array_values(array_filter($resources,static function($r){return empty($r['sesion_id']);}));
// $edSoloSesion (aula_gestion.php?ed_sesion=<id>): devuelve solo el contenido de esa sesion; la pagina carga
// cada sesion al abrirla, asi no se envian los formularios de todas las sesiones de una vez.
$edSolo=isset($edSoloSesion)?(int)$edSoloSesion:null;
if($edSolo===null): ?>
<section class="aula-card aula-editor" id="sesiones">
<div class="aula-editor-cab">
    <div><h2>Editor del curso por sesión</h2>
    <p>Todo lo que ve el estudiante, organizado como en su aula: abre una sesión para revisar, editar o eliminar sus grabaciones y materiales, ajustar sus tareas y marcar la asistencia.</p></div>
    <div class="aula-editor-herr">
        <button type="button" class="btn btn-default btn-sm" data-ed-todas="1">Expandir todas</button>
        <button type="button" class="btn btn-default btn-sm" data-ed-todas="0">Contraer</button>
        <?php if($edVer): ?><a class="btn btn-default btn-sm" href="mi_aula.php?curso=<?= $cid ?>&amp;seccion=sesiones&amp;vista_previa=<?= $edVer ?>" target="_blank" rel="noopener"><span class="glyphicon glyphicon-eye-open" aria-hidden="true"></span> Ver como estudiante</a><?php endif; ?>
    </div>
</div>

<details class="aula-ed-sesion is-general" id="ses-general" <?= $edGenerales?'':'' ?>>
    <summary><span class="aula-session-number"><span class="glyphicon glyphicon-folder-open" aria-hidden="true"></span></span>
        <span class="aula-ed-sum-txt"><strong>Material general del curso</strong><small>Recursos que no pertenecen a una sesión (sección “Material y videos” del estudiante)</small></span>
        <span class="aula-ed-chips"><span class="aula-ed-chip"><span class="glyphicon glyphicon-file" aria-hidden="true"></span> <?= count($edGenerales) ?></span></span></summary>
    <div class="aula-ed-cuerpo">
        <?php foreach($edGenerales as $r)echo $edRecurso($r,'ses-general'); ?>
        <?php if(!$edGenerales): ?><p class="aula-muted">No hay material general publicado.</p><?php endif; ?>
        <?= $edNuevo(0,'ses-general') ?>
        <?php $evGen=array_filter($evals,static function($e){return empty($e['sesion_id']);}); if($evGen): ?>
            <h4 class="aula-ed-titulo"><span class="glyphicon glyphicon-check" aria-hidden="true"></span> Actividades del curso completo</h4>
            <?php foreach($evGen as $e): $ent=$edEntregas[(int)$e['id']]??null; ?>
                <div class="aula-ed-linea aula-ed-actividad"><strong><?= aula_h($e['titulo']) ?></strong>
                    <span class="aula-badge <?= $e['habilitada']?'is-complete':'is-oculto' ?>"><?= $e['habilitada']?'Habilitada':'Deshabilitada' ?></span>
                    <span class="aula-muted"><?= $ent?(int)$ent['personas'].' entregaron'.((int)$ent['por_revisar']?' · '.(int)$ent['por_revisar'].' por revisar':''):'Sin entregas' ?></span>
                    <a href="#evaluaciones">Configurar →</a></div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</details>

<div class="aula-session-list">
<?php endif; ?>
<?php foreach($sessions as $index=>$s):
    $sid=(int)$s['id'];$ancla='ses-'.$sid;
    $recSes=array_values(array_filter($resources,static function($r)use($sid){return (int)$r['sesion_id']===$sid;}));
    $evSes=array_values(array_filter($evals,static function($e)use($sid){return (int)$e['sesion_id']===$sid;}));
    $asis=$edAsistencia[$sid]??[];$presentes=count(array_filter($asis,static function($r){return (int)$r['presente']===1;}));
    $futura=$s['fecha']>$edHoy;$esHoy=$s['fecha']===$edHoy;
    $teamsSes=aula_teams_url($db,$cid,$s);
    $linksSes=array_filter($instructorLinks,static function($l)use($sid){return (int)$l['sesion_id']===$sid;});
    $docentes=array_unique(array_map(static function($l){return $l['instructor'];},$linksSes));
    $docIds=array_values(array_unique(array_map(static function($l){return (int)$l['instructor_id'];},$linksSes)));
    $anSes=$edAnuncios[$sid]??[];
    if($edSolo===null):
?>
<details class="aula-ed-sesion<?= $esHoy?' is-hoy':'' ?>" id="<?= $ancla ?>">
    <summary>
        <span class="aula-session-number"><?= $index+1 ?></span>
        <span class="aula-ed-sum-txt"><strong><?= aula_h($s['tema']) ?></strong>
            <small><?= aula_h(date('d/m/Y',strtotime($s['fecha'])).' · '.substr($s['hora_inicio'],0,5).'–'.substr($s['hora_fin'],0,5).($s['modulo']?' · '.$s['modulo']:'').($docentes?' · '.implode(', ',$docentes):'')) ?></small></span>
        <span class="aula-ed-chips">
            <?php if($esHoy): ?><span class="aula-ed-chip is-hoy">Hoy</span><?php endif; ?>
            <span class="aula-ed-chip <?= $s['estado']==='impartida'?'is-ok':(!$futura&&!$esHoy?'is-pend':'') ?>" title="Estado en Programa de sesiones"><?= $s['estado']==='impartida'?'✓ Impartida':'Programada' ?></span>
            <?php if($anSes): ?><span class="aula-ed-chip" title="Anuncios"><span class="glyphicon glyphicon-bullhorn" aria-hidden="true"></span> <?= count($anSes) ?></span><?php endif; ?>
            <span class="aula-ed-chip" title="Grabaciones y materiales"><span class="glyphicon glyphicon-film" aria-hidden="true"></span> <?= count($recSes) ?></span>
            <span class="aula-ed-chip" title="Tareas y evaluaciones"><span class="glyphicon glyphicon-check" aria-hidden="true"></span> <?= count($evSes) ?></span>
            <span class="aula-ed-chip <?= !$futura&&$edAlumnos&&$presentes<count($edAlumnos)?'is-pend':'' ?>" title="Asistencia"><span class="glyphicon glyphicon-user" aria-hidden="true"></span> <?= $futura?'—':$presentes.'/'.count($edAlumnos) ?></span>
        </span>
    </summary>
    <div class="aula-ed-cuerpo" data-ed-cargar="aula_gestion.php?curso=<?= $cid ?>&amp;ed_sesion=<?= $sid ?>"><p class="aula-muted">Cargando la sesión…</p></div>
</details>
<?php continue; endif; if($sid!==$edSolo)continue; ?>
        <div class="aula-ed-linea">
            <?php if($teamsSes): ?><a class="btn btn-default btn-xs" href="<?= aula_h($teamsSes) ?>" target="_blank" rel="noopener noreferrer"><span class="glyphicon glyphicon-facetime-video" aria-hidden="true"></span> Teams de la sesión ↗</a><?php else: ?><span class="aula-muted">Sin enlace de Teams.</span><?php endif; ?>
            <a href="#conexion">Cambiar enlace</a>
            <?php if($edVer): ?><a href="mi_aula.php?curso=<?= $cid ?>&amp;seccion=sesiones&amp;vista_previa=<?= $edVer ?>" target="_blank" rel="noopener">Ver como estudiante ↗</a><?php endif; ?>
            <a href="programa_sesiones.php?reprogramar=<?= $sid ?>">Reprogramar</a>
            <a href="programa_sesiones.php?ver=<?= (int)$s['planificacion_id'] ?>">Programa de sesiones</a>
        </div>

        <div class="aula-ed-registro">
            <span class="glyphicon glyphicon-<?= $s['estado']==='impartida'?'ok-circle':'time' ?>" aria-hidden="true"></span>
            <div><b><?= $s['estado']==='impartida'?'Sesión registrada como impartida':($futura?'Sesión programada':'Sesión pendiente de registrar') ?></b>
                <small><?= $s['estado']==='impartida'?'Cuenta para el pago del instructor y el avance del curso.':($futura?'Se podrá registrar a partir del '.date('d/m/Y',strtotime($s['fecha'])).'.':'Ya pasó la fecha: regístrala como impartida para que cuente en el pago del instructor.') ?></small></div>
            <?php if($s['estado']==='programada'&&!$futura): ?>
                <form method="post" onsubmit="return confirm('¿Registrar «<?= aula_h(addslashes($s['tema'])) ?>» como impartida?');"><?= aula_csrf() ?><input type="hidden" name="accion" value="sesion_impartida"><input type="hidden" name="sesion_id" value="<?= $sid ?>"><input type="hidden" name="ancla" value="<?= $ancla ?>">
                    <button class="btn btn-primary btn-sm"><span class="glyphicon glyphicon-ok" aria-hidden="true"></span> Registrar como impartida</button></form>
            <?php endif; ?>
        </div>

        <div class="aula-ed-subtabs" role="tablist" aria-label="Contenido de la sesión">
            <button type="button" class="is-activo" data-ed-tab="contenido"><span class="glyphicon glyphicon-film" aria-hidden="true"></span> Grabaciones y material <b><?= count($recSes) ?></b></button>
            <button type="button" data-ed-tab="tareas"><span class="glyphicon glyphicon-check" aria-hidden="true"></span> Tareas <b><?= count($evSes) ?></b></button>
            <button type="button" data-ed-tab="asistencia"><span class="glyphicon glyphicon-user" aria-hidden="true"></span> Asistencia <b data-asis-contador="<?= $sid ?>" data-total="<?= count($edAlumnos) ?>" data-corto="1"><?= $futura?'—':$presentes.'/'.count($edAlumnos) ?></b></button>
            <button type="button" data-ed-tab="anuncios"><span class="glyphicon glyphicon-bullhorn" aria-hidden="true"></span> Anuncios <b><?= count($anSes) ?></b></button>
            <?php if($docIds): ?><button type="button" data-ed-tab="docente"><span class="glyphicon glyphicon-education" aria-hidden="true"></span> Docente</button><?php endif; ?>
        </div>
        <div class="aula-ed-panel" data-ed-panel="contenido">
        <?php foreach($recSes as $r)echo $edRecurso($r,$ancla); ?>
        <?php if(!$recSes): ?><p class="aula-muted">Todavía no hay grabaciones ni materiales en esta sesión.</p><?php endif; ?>
        <?= $edNuevo($sid,$ancla) ?>
        </div>
        <div class="aula-ed-panel" data-ed-panel="tareas" hidden>
        <?php foreach($evSes as $e): $ent=$edEntregas[(int)$e['id']]??null;
            $abierta=$e['habilitada']&&$e['abre']<=$edAhora&&$e['cierra']>$edAhora; ?>
            <article class="aula-ed-actividad">
                <div class="aula-ed-recurso-cab">
                    <span class="aula-ed-icono is-tarea"><span class="glyphicon glyphicon-<?= $e['tipo_actividad']==='evaluacion'?'list-alt':'paperclip' ?>" aria-hidden="true"></span></span>
                    <span class="aula-ed-recurso-txt"><strong><?= aula_h($e['titulo']) ?></strong>
                        <small><?= aula_h(['evaluacion'=>'Evaluación','tarea'=>'Tarea','actividad'=>'Actividad'][$e['tipo_actividad']]??$e['tipo_actividad']) ?> · <?= date('d/m H:i',strtotime($e['abre'])) ?> → <?= date('d/m H:i',strtotime($e['cierra'])) ?> · <?= (int)$e['intentos'] ?> intento(s)</small></span>
                    <span class="aula-badge <?= $abierta?'is-complete':($e['habilitada']?'is-programado':'is-oculto') ?>"><?= $abierta?'Abierta':($e['habilitada']?($e['cierra']<=$edAhora?'Cerrada':'Programada'):'Deshabilitada') ?></span>
                    <?php if($e['publicar']): ?><span class="aula-badge is-complete">Notas publicadas</span><?php endif; ?>
                </div>
                <div class="aula-ed-linea">
                    <span><?= $ent?(int)$ent['personas'].' de '.count($edAlumnos).' entregaron':'Sin entregas' ?><?= $ent&&(int)$ent['por_revisar']?' · <b>'.(int)$ent['por_revisar'].' por revisar</b>':'' ?></span>
                    <?php if($ent&&$ent['primero_pendiente']): ?><a href="aula_gestion.php?curso=<?= $cid ?>&amp;revision=<?= (int)$ent['primero_pendiente'] ?>#revision">Revisar entregas →</a><?php endif; ?>
                    <a href="#evaluaciones">Preguntas, peso y destino de la nota</a>
                </div>
                <details class="aula-ed-editar"><summary>Editar disponibilidad</summary>
                    <form method="post" class="aula-form">
                        <?= aula_csrf() ?><input type="hidden" name="accion" value="evaluacion_rapida"><input type="hidden" name="evaluacion" value="<?= (int)$e['id'] ?>"><input type="hidden" name="ancla" value="<?= $ancla ?>">
                        <div class="aula-courses">
                            <label>Apertura<input type="datetime-local" name="abre" required value="<?= $edDt($e['abre']) ?>"></label>
                            <label>Cierre<input type="datetime-local" name="cierra" required value="<?= $edDt($e['cierra']) ?>"></label>
                            <label>Duración (minutos)<input type="number" name="minutos" min="1" max="10080" required value="<?= (int)$e['minutos'] ?>"></label>
                            <label>Intentos<input type="number" name="intentos" min="1" max="5" required value="<?= (int)$e['intentos'] ?>"></label>
                            <label class="aula-ed-check"><input type="checkbox" name="habilitada" <?= $e['habilitada']?'checked':'' ?>> Habilitada para los estudiantes</label>
                            <label class="aula-ed-check"><input type="checkbox" name="publicar" <?= $e['publicar']?'checked':'' ?>> Publicar notas (alimenta Seguimiento si está configurado)</label>
                        </div>
                        <div class="aula-ed-acciones"><button class="btn btn-primary btn-sm">Guardar</button></div>
                    </form>
                </details>
            </article>
        <?php endforeach; ?>
        <?php if(!$evSes): ?><p class="aula-muted">Sin tareas ni evaluaciones en esta sesión.</p><?php endif; ?>
        <details class="aula-ed-nuevo"><summary><span class="glyphicon glyphicon-plus" aria-hidden="true"></span> Crear tarea en esta sesión</summary>
            <form method="post" class="aula-form">
                <?= aula_csrf() ?><input type="hidden" name="accion" value="tarea_rapida"><input type="hidden" name="sesion_id" value="<?= $sid ?>"><input type="hidden" name="ancla" value="<?= $ancla ?>">
                <div class="aula-courses">
                    <label class="is-ancho">Título<input name="titulo" maxlength="180" required placeholder="Ej. Tarea 1: análisis de variedades"></label>
                    <label class="is-ancho">Instrucciones para el estudiante<textarea name="enunciado" rows="3" maxlength="10000" required placeholder="Qué debe entregar. Podrá responder con texto o subir un PDF."></textarea></label>
                    <label class="is-ancho">Criterio de calificación (solo docentes)<textarea name="criterio" rows="2" maxlength="10000" placeholder="Opcional: lo que se revisa al calificar"></textarea></label>
                    <label>Apertura<input type="datetime-local" name="abre" required value="<?= aula_h($s['fecha'].'T'.substr($s['hora_inicio'],0,5)) ?>"></label>
                    <label>Entrega hasta<input type="datetime-local" name="cierra" required value="<?= aula_h(date('Y-m-d',strtotime($s['fecha'].' +7 days')).'T23:00') ?>"></label>
                    <label>Intentos<input type="number" name="intentos" min="1" max="5" value="1" required></label>
                    <label class="aula-ed-check"><input type="checkbox" name="habilitada" checked> Habilitada para los estudiantes</label>
                </div>
                <div class="aula-ed-acciones"><button class="btn btn-primary btn-sm">Crear tarea</button></div>
            </form>
            <p class="aula-muted">Se califica de 0 a 100 en “Revisión docente”. Para una evaluación con preguntas de selección múltiple usa <a href="aula_gestion.php?curso=<?= $cid ?>&amp;sesion=<?= $sid ?>#evaluaciones">Evaluaciones</a> (banco de preguntas).</p>
        </details>
        </div>
        <div class="aula-ed-panel" data-ed-panel="asistencia" hidden>
        <?= aula_ventana_panel($s,$edVentanas[$sid]??null,$ancla) ?>
        <?php if(!$edAlumnos): ?><p class="aula-muted">No hay participantes asignados.</p>
        <?php else: ?>
            <div class="aula-ed-linea">
                <?php if($futura): ?><span class="aula-muted">Se registra a partir del <?= date('d/m/Y',strtotime($s['fecha'])) ?>.</span>
                <?php else: ?>
                    <button type="button" class="btn btn-primary btn-sm" data-asis-todos data-sesion="<?= $sid ?>" data-presente="1"><span class="glyphicon glyphicon-ok" aria-hidden="true"></span> Todos presentes</button>
                    <button type="button" class="btn btn-default btn-sm" data-asis-todos data-sesion="<?= $sid ?>" data-presente="0">Todos ausentes</button>
                <?php endif; ?>
                <a href="aula_gestion.php?curso=<?= $cid ?>&amp;lista=<?= $sid ?>#asistencia-rapida">Cuadrícula del curso</a>
                <a href="#asistencia">Horario especial o código</a>
            </div>
            <div class="aula-asis-lista">
            <?php foreach($edAlumnos as $al): $reg=$asis[(int)$al['id']]??null;$pres=$reg&&(int)$reg['presente']===1;$aus=$reg&&!(int)$reg['presente']; ?>
                <div class="aula-asis-fila aula-ed-asis-fila" data-sesion="<?= $sid ?>" data-asignacion="<?= (int)$al['id'] ?>">
                    <span class="aula-asis-nombre"><strong><?= aula_h($al['nombre_participantes']) ?></strong>
                        <small data-asis-detalle><?= $reg?((int)$reg['presente']?'Presente':'Ausente').' '.date('d/m H:i',strtotime($reg['registrado'])).($reg['origen']==='coordinacion'?' (coordinación)':' (confirmó el estudiante)'):($futura?'':'Sin registro') ?></small></span>
                    <span class="aula-asis-opciones">
                        <button type="button" class="aula-asis-op is-presente<?= $pres?' is-activo':'' ?>" data-asis-marcar data-presente="1" <?= $futura?'disabled':'' ?>>✓ Presente</button>
                        <button type="button" class="aula-asis-op is-ausente<?= $aus?' is-activo':'' ?>" data-asis-marcar data-presente="0" <?= $futura?'disabled':'' ?>>✗ Ausente</button>
                    </span>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
        <div class="aula-ed-panel" data-ed-panel="anuncios" hidden>
        <?php foreach($anSes as $a)echo function_exists('aula_anuncio_item')?aula_anuncio_item($a,$sessions,$edTeamsCurso,$ancla):''; ?>
        <?php if(function_exists('aula_anuncio_form')): ?>
        <details class="aula-ed-nuevo"><summary><span class="glyphicon glyphicon-plus" aria-hidden="true"></span> Publicar anuncio en esta sesión</summary>
            <?= aula_anuncio_form($sessions,$edTeamsCurso,[],$ancla,$sid) ?>
        </details>
        <?php endif; ?>
        </div>
        <?php if($docIds): ?>
        <div class="aula-ed-panel" data-ed-panel="docente" hidden>
        <?php foreach($docIds as $iid): $sv=$edSurveys[$iid]??null; if(!$sv)continue;
            $svAbre=$sv['abre'];$svEstado=!$sv['habilitada']?['Deshabilitada','is-oculto']:(!$svAbre?['Sin apertura','is-programado']:($edAhora<$svAbre?['Abre el '.date('d/m/Y H:i',strtotime($svAbre)),'is-programado']:($sv['cierra']&&$edAhora>=$sv['cierra']?['Cerrada','is-oculto']:['Abierta para los estudiantes','is-complete']))); ?>
            <div class="aula-ed-linea aula-ed-docente"><strong><?= aula_h($sv['instructor']) ?></strong>
                <span class="aula-badge <?= $svEstado[1] ?>">Evaluación: <?= aula_h($svEstado[0]) ?></span>
                <span class="aula-muted"><?= $edResp[$iid]??0 ?> respuesta(s)</span>
                <?php if($sv['token']): ?><a href="evaluacion.php?token=<?= rawurlencode($sv['token']) ?>" target="_blank" rel="noopener">Ver formulario ↗</a><?php endif; ?>
                <a href="#encuestas">Configurar apertura</a></div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
<?php endforeach; ?>
<?php if($edSolo===null): ?>
</div>
<?php if(!$sessions): ?><p>Primero programa las sesiones de este curso en <a href="programa_sesiones.php">Programa de sesiones</a>.</p><?php endif; ?>
</section>
<script>
(function(){
    function cargar(d){
        var c=d.querySelector('[data-ed-cargar]');if(!c||c.getAttribute('data-cargado'))return;
        c.setAttribute('data-cargado','1');
        fetch(c.getAttribute('data-ed-cargar'),{credentials:'same-origin'}).then(function(r){if(!r.ok)throw new Error(r.status);return r.text();})
            .then(function(h){c.innerHTML=h;var t=null;try{t=sessionStorage.getItem('aulaEdTab-'+d.id);}catch(e){}if(t)mostrarTab(c,t);})
            .catch(function(){c.removeAttribute('data-cargado');c.innerHTML='<p class="aula-falta">No se pudo cargar la sesión. Ciérrala y vuelve a abrirla.</p>';});
    }
    // Sub-pestanas de la sesion (Grabaciones, Tareas, Asistencia, Anuncios, Docente); se recuerda la ultima.
    function mostrarTab(cuerpo,tab){
        if(!cuerpo.querySelector('[data-ed-panel="'+tab+'"]'))return;
        cuerpo.querySelectorAll('[data-ed-tab]').forEach(function(b){b.classList.toggle('is-activo',b.getAttribute('data-ed-tab')===tab);});
        cuerpo.querySelectorAll('[data-ed-panel]').forEach(function(p){p.hidden=p.getAttribute('data-ed-panel')!==tab;});
    }
    document.addEventListener('click',function(e){
        var b=e.target.closest('[data-ed-tab]');if(!b)return;
        var cuerpo=b.closest('.aula-ed-cuerpo'),ses=b.closest('.aula-ed-sesion');
        mostrarTab(cuerpo,b.getAttribute('data-ed-tab'));
        if(ses)try{sessionStorage.setItem('aulaEdTab-'+ses.id,b.getAttribute('data-ed-tab'));}catch(err){}
    });
    document.querySelectorAll('.aula-ed-sesion').forEach(function(d){d.addEventListener('toggle',function(){if(d.open)cargar(d);});if(d.open)cargar(d);});
    document.querySelectorAll('[data-ed-todas]').forEach(function(b){b.addEventListener('click',function(){var abrir=b.getAttribute('data-ed-todas')==='1';document.querySelectorAll('.aula-ed-sesion').forEach(function(d){d.open=abrir;});});});
    document.addEventListener('change',function(e){var t=e.target;if(t.hasAttribute&&t.hasAttribute('data-ed-todos'))t.closest('form').querySelectorAll('input[name="asignaciones[]"]').forEach(function(c){c.checked=t.checked;});});
    document.addEventListener('submit',function(e){var f=e.target;if(f.classList&&f.classList.contains('aula-ed-asistencia')&&!f.querySelector('input[name="asignaciones[]"]:checked')){e.preventDefault();alert('Marca al menos un participante.');}},true);
})();
</script>
<?php endif; ?>
