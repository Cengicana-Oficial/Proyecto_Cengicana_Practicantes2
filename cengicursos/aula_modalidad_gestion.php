<?php
// Gestión del aula → Asistencia: modalidad de cada participante (sincrónica / asincrónica) y avance por grabaciones.
// Se incluye desde aula_gestion.php con $db, $cid. Esquema: migrations/20261112_aula_asincronica.sql.
if (!isset($db, $cid)) { http_response_code(403); exit; }
if (!aula_async_ready($db)): ?>
<details class="aula-card" id="modalidad"><summary>Modalidad sincrónica / asincrónica</summary><p class="alert alert-info">Para habilitar la modalidad asincrónica aplica la migración <code>20261112_aula_asincronica.sql</code>.</p></details>
<?php return; endif;
$modParticipantes = aula_rows($db, 'SELECT a.id, p.nombre_participantes, cc.asistencia FROM asignaciones a JOIN participantes p ON p.id=a.participantes_id
    LEFT JOIN control_cursos cc ON cc.asignacion_id=a.id WHERE a.cursos_id=? AND a.estado_asignaciones=1 AND p.estado_participantes=1 ORDER BY p.nombre_participantes', [$cid]);
$modMapa = aula_modalidades_curso($db, $cid);
$modVideos = aula_videos_sesiones($db, $cid);
$modVistas = [];
foreach (aula_rows($db, 'SELECT v.asignacion_id, COUNT(*) n FROM aula_video_avance v JOIN asignaciones a ON a.id=v.asignacion_id WHERE a.cursos_id=? AND v.visto_en IS NOT NULL GROUP BY v.asignacion_id', [$cid]) as $r) $modVistas[(int) $r['asignacion_id']] = (int) $r['n'];
$modAsync = count(array_filter($modMapa, static function ($m) { return $m['modalidad'] === 'asincronica'; }));
$modSinMedir = array_filter($modVideos, static function ($v) { return $v['fuente'] === 'otro' && !$v['duracion_min']; });
?>
<details class="aula-card" id="modalidad" <?= $modAsync ? 'open' : '' ?>><summary>Modalidad sincrónica / asincrónica · <?= $modAsync ?> asincrónico(s)</summary>
<p>El participante <b>asincrónico</b> usa esta misma aula con las grabaciones de las sesiones: al ver al menos el <?= AULA_VIDEO_MINIMO ?> % de cada grabación la marca como vista y, al completar las de una sesión, esa sesión cuenta como asistida. Sus evaluaciones se habilitan al terminar las grabaciones de su sesión o módulo, sin depender de las fechas del grupo en vivo. El diploma usa la misma regla de nota y asistencia.</p>
<p class="aula-muted">Grabaciones publicadas en las sesiones: <b><?= count($modVideos) ?></b>.<?php if ($modSinMedir): ?> <?= count($modSinMedir) ?> están en una plataforma que no informa el avance (Google Drive u otra): indica su duración al publicarlas para medir el <?= AULA_VIDEO_MINIMO ?> %; sin duración se exigen <?= AULA_VIDEO_SIN_DURACION / 60 ?> minutos de reproducción.<?php endif; ?></p>
<?php if (!$modParticipantes): ?><p class="aula-muted">El curso no tiene participantes activos.</p><?php else: ?>
<form method="post" class="aula-form" data-modalidad-form><?= aula_csrf() ?><input type="hidden" name="accion" value="modalidad">
    <div class="aula-banco-filtros">
        <label>Modalidad<select name="modalidad"><option value="asincronica">Asincrónica (grabaciones)</option><option value="sincronica">Sincrónica (en vivo)</option></select></label>
        <label>Fecha límite (opcional, solo asincrónica)<input type="date" name="plazo"></label>
        <button class="btn btn-primary">Aplicar a los marcados</button>
        <label class="aula-check-todas"><input type="checkbox" data-modalidad-todos> Marcar todos</label>
    </div>
    <div class="table-responsive"><table class="table aula-modalidad-tabla">
        <thead><tr><th></th><th>Participante</th><th>Modalidad</th><th>Grabaciones vistas</th><th>Asistencia</th></tr></thead>
        <tbody>
        <?php foreach ($modParticipantes as $mp): $aid = (int) $mp['id']; $m = $modMapa[$aid] ?? null; $esAsync = $m && $m['modalidad'] === 'asincronica'; $vistas = $modVistas[$aid] ?? 0; ?>
            <tr class="<?= $esAsync ? 'is-async' : '' ?>">
                <td><input type="checkbox" name="asignaciones[]" value="<?= $aid ?>" aria-label="Seleccionar a <?= aula_h($mp['nombre_participantes']) ?>"></td>
                <td><?= aula_h($mp['nombre_participantes']) ?></td>
                <td><?php if ($esAsync): ?><span class="aula-badge is-programado">Asincrónica</span><?= $m['plazo'] ? '<small class="aula-muted"> hasta ' . aula_h(date('d/m/Y', strtotime($m['plazo']))) . '</small>' : '' ?><?php else: ?><span class="aula-badge is-oculto">Sincrónica</span><?php endif; ?></td>
                <td><?php if ($esAsync): ?><b><?= $vistas ?></b> / <?= count($modVideos) ?><?php if ($modVideos): ?> <span class="aula-async-mini"><span style="width:<?= (int) round(100 * $vistas / count($modVideos)) ?>%"></span></span><?php endif; ?><?php else: ?><span class="aula-muted">—</span><?php endif; ?></td>
                <td><?= $mp['asistencia'] !== null && $mp['asistencia'] !== '' ? aula_h(rtrim(rtrim(number_format((float) $mp['asistencia'], 2, '.', ''), '0'), '.')) . ' %' : '<span class="aula-muted">—</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</form>
<script>
(function(){
    var f=document.querySelector('[data-modalidad-form]');if(!f)return;
    var todos=f.querySelector('[data-modalidad-todos]');
    todos.addEventListener('change',function(){f.querySelectorAll('input[name="asignaciones[]"]').forEach(function(c){c.checked=todos.checked;});});
    f.addEventListener('submit',function(e){if(!f.querySelector('input[name="asignaciones[]"]:checked')){e.preventDefault();alert('Marca al menos un participante.');}});
})();
</script>
<?php endif; ?>
</details>
