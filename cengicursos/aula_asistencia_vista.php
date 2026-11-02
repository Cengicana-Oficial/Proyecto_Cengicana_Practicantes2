<?php if(!isset($db,$courseId,$assignment)){http_response_code(403);exit;}
// Mi asistencia: arriba las sesiones con registro abierto (con su boton), abajo una lista compacta filtrable.
$records=$ready?array_column(aula_rows($db,'SELECT * FROM aula_asistencias WHERE asignacion_id=?',[$assignment['id']]),null,'sesion_id'):[];
$impartidas=aula_sesiones_impartidas($db,$courseId,$assignment['id']);
$sinRegistro=0;
?>
<div class="aula-overview is-4">
    <div><strong><?= is_numeric($control['asistencia']??null)?aula_h(round((float)$control['asistencia'])).'%':'—' ?></strong><span>Mi asistencia (sobre las sesiones impartidas)</span></div>
    <div><strong><?= (int)($control['sesiones_asistidas']??count($registered)) ?> / <?= $impartidas ?></strong><span>Sesiones asistidas de las impartidas</span></div>
    <div><strong><?= max(0,count($sessions)-$impartidas) ?></strong><span>Sesiones por realizar</span></div>
    <div><strong><?= count($abiertas) ?></strong><span>Registro abierto ahora</span></div>
</div>

<?php foreach($abiertas as $s): $w=$windows[$s['id']]??null;$n=array_search($s,$sessions,true); ?>
<section class="aula-card aula-foco is-abierta"><span class="aula-foco-etiqueta">Registro abierto</span>
<h2><span class="aula-session-number"><?= $n!==false?$n+1:'' ?></span> <?= aula_h($s['tema']) ?></h2>
<p class="aula-foco-cuando"><?= aula_h(aula_fecha_larga($s['fecha']).' · '.substr($s['hora_inicio'],0,5).'–'.substr($s['hora_fin'],0,5)) ?><?= $w&&!empty($w['horario_manual'])?' · Habilitado hasta '.aula_h(substr($w['cierra'],11,5)):'' ?></p>
<form method="post" class="aula-foco-form"><?= aula_csrf() ?><input type="hidden" name="accion" value="asistencia"><input type="hidden" name="sesion" value="<?= (int)$s['id'] ?>"><?php if($w&&$w['codigo_hash']!==''): ?><label>Código de la sesión<input name="codigo" maxlength="30" required autocomplete="off"></label><?php endif; ?><button class="btn btn-primary"><span class="glyphicon glyphicon-ok" aria-hidden="true"></span> Registrar mi asistencia</button></form>
</section>
<?php endforeach; ?>

<section class="aula-card"><h2>Mi asistencia por sesión</h2><p>Confirma durante el horario de cada sesión. Tu porcentaje se calcula sobre las sesiones ya impartidas: si asististe a todas, tienes 100%. Si olvidaste registrarte, coordinación puede revisar y corregir tu registro.</p>
<?php if($sessions): ?>
<div class="aula-filtros" data-filtros=".aula-mi-asis tbody tr">
    <span class="aula-filtros-chips" role="group" aria-label="Filtrar sesiones">
        <button type="button" class="is-activo" data-filtro="todas">Todas <b><?= count($sessions) ?></b></button>
        <button type="button" data-filtro="registrada">Registradas <b data-filtro-cuenta="registrada"></b></button>
        <button type="button" data-filtro="sin">Sin registro <b data-filtro-cuenta="sin"></b></button>
        <button type="button" data-filtro="proxima">Próximas <b data-filtro-cuenta="proxima"></b></button>
    </span>
</div>
<div class="aula-mi-asis-wrap"><table class="aula-mi-asis">
<thead><tr><th>#</th><th>Fecha</th><th>Sesión</th><th>Estado</th></tr></thead><tbody>
<?php foreach($sessions as $n=>$s): $sid=(int)$s['id'];$w=$windows[$sid]??null;$r=$records[$sid]??null;$present=isset($registered[$sid]);$absent=$r&&isset($r['presente'])&&!$r['presente'];$abierta=$ready&&!$present&&aula_session_window($s,$w);$futura=$s['fecha']>$hoy;
    $tag=$present?'registrada':($abierta?'abierta sin':($futura?'proxima':'sin'));
    [$texto,$clase]=$present?['✓ Registrada','is-complete']:($absent?['Ausencia confirmada','is-ausente']:($abierta?['Registro abierto','is-pending']:($futura?['Programada','']:['Sin registro','is-sin']))); ?>
<tr data-tags="<?= $tag ?>" class="<?= $s['fecha']===$hoy?'is-hoy':'' ?>"><td><?= $n+1 ?></td><td class="aula-mi-asis-fecha"><?= aula_h(aula_fecha_larga($s['fecha'],true)) ?><small><?= aula_h(substr($s['hora_inicio'],0,5).'–'.substr($s['hora_fin'],0,5)) ?></small></td>
<td><?= aula_h($s['tema']) ?><?php if($present): ?><small><?= ($r['origen']??'')==='coordinacion'?'Validada por coordinación':'Registro personal' ?> · <?= aula_h(date('d/m H:i',strtotime($registered[$sid]))) ?></small><?php elseif($absent&&!empty($r['motivo'])): ?><small><?= aula_h($r['motivo']) ?></small><?php endif; ?></td>
<td><span class="aula-badge <?= $clase ?>"><?= $texto ?></span></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<p class="aula-muted" data-filtro-vacio hidden>No hay sesiones en este filtro.</p>
<?php else: ?><p>Todavía no hay sesiones programadas.</p><?php endif; ?>
</section>
