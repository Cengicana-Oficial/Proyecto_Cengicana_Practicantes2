<?php if(!isset($db,$cid,$sessions)){http_response_code(403);exit;}
// Gestion del aula -> Asistencia: pasar lista de una sesion con un clic y cuadricula de todo el curso.
// Los clics se guardan sin recargar (js/aula_asistencia.js -> accion asistencia_marcar en aula_gestion.php).
aula_csrf(); // asegura el token para las llamadas sin recargar
$asAlumnos=aula_rows($db,'SELECT a.id,p.nombre_participantes FROM asignaciones a JOIN participantes p ON p.id=a.participantes_id WHERE a.cursos_id=? AND a.estado_asignaciones=1 AND p.estado_participantes=1 ORDER BY p.nombre_participantes',[$cid]);
$asVentanas=array_column(aula_rows($db,'SELECT v.* FROM aula_asistencia_ventanas v JOIN curso_sesiones s ON s.id=v.sesion_id JOIN planificacion_cursos pc ON pc.id=s.planificacion_id WHERE pc.curso_id=?',[$cid]),null,'sesion_id');$asMapa=aula_asistencia_mapa($db,$cid);$asOficial=aula_asistencia_oficial($db,$cid);$asHoy=date('Y-m-d');
$asPasadas=array_values(array_filter($sessions,static function($s)use($asHoy){return $s['fecha']<=$asHoy;}));
// Sesion para pasar lista: la elegida, la de hoy o la ultima que ya paso.
$asSel=null;$asPedida=(int)($_GET['lista']??0);
foreach($sessions as $s){if((int)$s['id']===$asPedida)$asSel=$s;}
if(!$asSel)foreach($sessions as $s){if($s['fecha']===$asHoy){$asSel=$s;break;}}
if(!$asSel&&$asPasadas)$asSel=end($asPasadas);
if(!$asSel&&$sessions)$asSel=$sessions[0];
$asPresentes=static function($sid)use($asMapa){return count(array_filter($asMapa[(int)$sid]??[],static function($r){return (int)$r['presente']===1;}));};
$asSinRegistro=count(array_filter($asPasadas,static function($s)use($asMapa){return empty($asMapa[(int)$s['id']]);}));
$asPcts=array_values(array_filter(array_map(static function($a)use($asOficial){return isset($asOficial[(int)$a['id']])&&is_numeric($asOficial[(int)$a['id']]['asistencia'])?(float)$asOficial[(int)$a['id']]['asistencia']:null;},$asAlumnos),static function($v){return $v!==null;}));
$asBajos=count(array_filter($asPcts,static function($v){return $v<80;}));
?>
<section class="aula-card aula-asis" id="asistencia-rapida" data-asis-url="aula_gestion.php?curso=<?= $cid ?>" data-csrf="<?= aula_h($_SESSION['aula_csrf']??'') ?>">
<div class="aula-editor-cab"><div><h2><span class="glyphicon glyphicon-user" aria-hidden="true"></span> Asistencia</h2>
<p>Pasa lista con un clic: se guarda al instante, queda en el historial (por coordinación) y actualiza el porcentaje en Seguimiento.</p></div>
<div class="aula-editor-herr"><a class="btn btn-default btn-sm" href="#asistencia">Horario especial o código</a><a class="btn btn-default btn-sm" href="#correcciones">Corregir con fecha y motivo</a></div></div>

<div class="aula-asis-kpis">
    <div><b><?= count($asPasadas) ?> / <?= count($sessions) ?></b><span>sesiones ya realizadas</span></div>
    <div class="<?= $asSinRegistro?'is-alerta':'' ?>"><b><?= $asSinRegistro ?></b><span>sesiones pasadas sin ningún registro</span></div>
    <div><b><?= $asPcts?round(array_sum($asPcts)/count($asPcts)).'%':'—' ?></b><span>asistencia promedio</span></div>
    <div class="<?= $asBajos?'is-alerta':'' ?>"><b><?= $asBajos ?></b><span>participante(s) bajo 80%</span></div>
</div>

<?php if(!$asAlumnos): ?><p class="aula-muted">No hay participantes asignados a este curso.</p>
<?php elseif(!$sessions): ?><p class="aula-muted">El curso aún no tiene sesiones programadas.</p>
<?php else: $sid=(int)$asSel['id'];$futura=$asSel['fecha']>$asHoy; ?>
<div class="aula-asis-lista-cab">
    <form method="get" action="aula_gestion.php#asistencia-rapida" class="aula-asis-selector">
        <input type="hidden" name="curso" value="<?= $cid ?>">
        <label>Pasar lista de la sesión<select name="lista" onchange="this.form.submit()">
            <?php foreach($sessions as $i=>$s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id']===$sid?'selected':'' ?>><?= ($i+1).'. '.aula_h(date('d/m/Y',strtotime($s['fecha'])).' · '.$s['tema']).($s['fecha']===$asHoy?' (hoy)':'').(empty($asMapa[(int)$s['id']])&&$s['fecha']<=$asHoy?' · sin registro':'') ?></option><?php endforeach; ?>
        </select></label>
    </form>
    <span class="aula-asis-contador" data-asis-contador="<?= $sid ?>" data-total="<?= count($asAlumnos) ?>"><?= $asPresentes($sid) ?> de <?= count($asAlumnos) ?> presentes</span>
    <?php if(!$futura): ?>
        <button type="button" class="btn btn-primary btn-sm" data-asis-todos data-sesion="<?= $sid ?>" data-presente="1"><span class="glyphicon glyphicon-ok" aria-hidden="true"></span> Todos presentes</button>
        <button type="button" class="btn btn-default btn-sm" data-asis-todos data-sesion="<?= $sid ?>" data-presente="0">Todos ausentes</button>
    <?php endif; ?>
</div>
<?= aula_ventana_panel($asSel,$asVentanas[$sid]??null,'asistencia-rapida',$sid) ?>
<?php if($futura): ?><p class="aula-muted">Esta sesión es el <?= date('d/m/Y',strtotime($asSel['fecha'])) ?>: la asistencia se registra a partir de ese día.</p><?php endif; ?>
<div class="aula-asis-lista" data-asis-lista="<?= $sid ?>">
    <?php foreach($asAlumnos as $al): $reg=$asMapa[$sid][(int)$al['id']]??null;$pres=$reg&&(int)$reg['presente']===1;$aus=$reg&&!(int)$reg['presente']; ?>
    <div class="aula-asis-fila" data-sesion="<?= $sid ?>" data-asignacion="<?= (int)$al['id'] ?>">
        <span class="aula-asis-nombre"><strong><?= aula_h($al['nombre_participantes']) ?></strong>
            <small>Asistencia del curso: <span data-asis-pct="<?= (int)$al['id'] ?>"><?= isset($asOficial[(int)$al['id']])&&is_numeric($asOficial[(int)$al['id']]['asistencia'])?round((float)$asOficial[(int)$al['id']]['asistencia']).'%':'—' ?></span>
            <span class="aula-asis-detalle" data-asis-detalle><?= $reg?' · '.((int)$reg['presente']?'Presente':'Ausente').' '.date('d/m H:i',strtotime($reg['registrado'])).($reg['origen']==='coordinacion'?' (coordinación)':' (confirmó el estudiante)'):'' ?></span></small></span>
        <span class="aula-asis-opciones" role="group" aria-label="Asistencia de <?= aula_h($al['nombre_participantes']) ?>">
            <button type="button" class="aula-asis-op is-presente<?= $pres?' is-activo':'' ?>" data-asis-marcar data-presente="1" <?= $futura?'disabled':'' ?>>✓ Presente</button>
            <button type="button" class="aula-asis-op is-ausente<?= $aus?' is-activo':'' ?>" data-asis-marcar data-presente="0" <?= $futura?'disabled':'' ?>>✗ Ausente</button>
        </span>
    </div>
    <?php endforeach; ?>
</div>

<h3 class="aula-vista-sub">Cuadrícula del curso <small>· clic en una casilla para cambiarla (✓ presente, ✗ ausente, · sin registro)</small></h3>
<div class="aula-asis-grid-wrap">
<table class="aula-asis-grid">
    <thead><tr><th class="is-fijo">Participante</th><th class="is-fijo2">%</th>
        <?php foreach($sessions as $i=>$s): $pasada=$s['fecha']<=$asHoy; ?>
            <th class="<?= $s['fecha']===$asHoy?'is-hoy':'' ?><?= $pasada?'':' is-futura' ?>" title="<?= aula_h(($i+1).'. '.$s['tema'].' · '.date('d/m/Y',strtotime($s['fecha']))) ?>">
                <a href="aula_gestion.php?curso=<?= $cid ?>&amp;lista=<?= (int)$s['id'] ?>#asistencia-rapida"><?= $i+1 ?><small><?= date('d/m',strtotime($s['fecha'])) ?></small></a>
                <span class="aula-asis-colcont" data-asis-contador="<?= (int)$s['id'] ?>" data-total="<?= count($asAlumnos) ?>" data-corto="1"><?= $pasada?$asPresentes((int)$s['id']).'/'.count($asAlumnos):'' ?></span></th>
        <?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach($asAlumnos as $al): $aid=(int)$al['id'];$pct=isset($asOficial[$aid])&&is_numeric($asOficial[$aid]['asistencia'])?(float)$asOficial[$aid]['asistencia']:null; ?>
        <tr><th class="is-fijo"><?= aula_h($al['nombre_participantes']) ?></th>
            <td class="is-fijo2 <?= $pct!==null&&$pct<80?'is-bajo':'' ?>" data-asis-pct="<?= $aid ?>"><?= $pct!==null?round($pct).'%':'—' ?></td>
            <?php foreach($sessions as $s): ?><td><?= aula_asistencia_boton((int)$s['id'],$aid,$asMapa[(int)$s['id']][$aid]??null,$s['fecha']>$asHoy,true) ?></td><?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>
</section>
