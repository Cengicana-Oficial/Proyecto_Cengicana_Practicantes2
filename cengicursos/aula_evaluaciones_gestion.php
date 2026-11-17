<?php
// Gestión del aula → Tareas y evaluaciones: banco de preguntas (alta, edición, eliminación, valor,
// carga masiva y filtro por módulo) y evaluaciones (preguntas con su valor, disponibilidad, tiempo,
// intentos, autocalificación y retroalimentación).
// Se incluye desde aula_gestion.php con $db, $cid, $bank, $evals, $sessions y $modules.
if (!isset($db, $cid, $bank, $evals, $sessions, $modules)) { http_response_code(403); exit; }
$bancoExt = aula_banco_ready($db);
$modNombres = array_column($modules, 'nombre', 'id');
$usos = ['p' => [], 'e' => [], 'v' => []];
foreach (aula_rows($db, 'SELECT ep.evaluacion_id, ep.pregunta_id' . ($bancoExt ? ', ep.puntos' : '') . ' FROM aula_evaluacion_preguntas ep JOIN aula_evaluaciones e ON e.id=ep.evaluacion_id WHERE e.curso_id=?', [$cid]) as $r) {
    $usos['p'][(int) $r['pregunta_id']][] = (int) $r['evaluacion_id'];
    $usos['e'][(int) $r['evaluacion_id']][] = (int) $r['pregunta_id'];
    $usos['v'][(int) $r['evaluacion_id']][(int) $r['pregunta_id']] = (float) ($r['puntos'] ?? 1);
}
$evTitulos = array_column($evals, 'titulo', 'id');
$fechaInput = static function ($v) { return $v ? str_replace(' ', 'T', substr($v, 0, 16)) : ''; };

/** Campos de una pregunta (alta y edición). */
$preguntaCampos = static function (array $p = []) use ($modules, $bancoExt) {
    $tipo = $p['tipo'] ?? 'seleccion';
    $opts = isset($p['opciones']) ? (json_decode((string) $p['opciones'], true) ?: []) : [];
    $corr = $p ? implode(', ', array_map(static function ($i) { return $i + 1; }, aula_correctas_de($p))) : '';
    ob_start(); ?>
    <div class="aula-courses">
        <label>Tipo<select name="tipo" data-tipo-pregunta><?php foreach (AULA_TIPOS_PREGUNTA as $k => $t): if ($k === 'multiple' && !$bancoExt) continue; ?><option value="<?= $k ?>" <?= $tipo === $k ? 'selected' : '' ?>><?= aula_h($t) ?></option><?php endforeach; ?></select></label>
        <?php if ($modules && $bancoExt): ?><label>Módulo<select name="curso_modulo_id"><option value="0">Sin módulo (todo el curso)</option><?php foreach ($modules as $m): ?><option value="<?= (int) $m['id'] ?>" <?= (int) ($p['curso_modulo_id'] ?? 0) === (int) $m['id'] ? 'selected' : '' ?>><?= aula_h($m['nombre']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
        <label>Tema (opcional)<input name="tema" maxlength="160" value="<?= aula_h($p['tema'] ?? '') ?>"></label>
        <?php if ($bancoExt): ?><label>Valor de la pregunta (puntos)<input type="number" name="puntos" min="0.01" max="1000" step="0.01" required value="<?= aula_h(aula_pts($p['puntos'] ?? 1)) ?>"><small>Valor sugerido; en cada evaluación se puede cambiar.</small></label><?php endif; ?>
    </div>
    <label>Enunciado<textarea name="enunciado" required maxlength="10000" rows="3"><?= aula_h($p['enunciado'] ?? '') ?></textarea></label>
    <div class="aula-courses" data-solo-opciones>
        <label>Opciones (una por línea)<textarea name="opciones" rows="4"><?= aula_h(implode("\n", $opts)) ?></textarea><small>Se omiten las líneas vacías. Máximo <?= AULA_MAX_OPCIONES ?> opciones.</small></label>
        <label><span data-label-correcta>Número de la opción correcta</span><input name="correcta" maxlength="40" value="<?= aula_h($corr) ?>" placeholder="Ej.: 2"><small data-ayuda-correcta>Escribe el número de la línea correcta (1 = primera opción).</small></label>
    </div>
    <label data-solo-criterio>Criterio de evaluación (preguntas abiertas y tareas)<textarea name="criterio" rows="2" maxlength="10000"><?= aula_h($p['criterio'] ?? '') ?></textarea></label>
    <?php return ob_get_clean();
};

/** Lista de preguntas para elegir, con su valor, filtro por módulo y "Seleccionar todas". */
$selectorPreguntas = static function (array $marcadas, array $valores = [], $moduloInicial = 0) use ($bank, $modules, $modNombres, $bancoExt) {
    ob_start(); ?>
    <fieldset class="aula-question aula-selector-preguntas" data-selector-preguntas>
        <legend>Preguntas del banco</legend>
        <?php if (!$bank): ?><p class="aula-muted">El banco está vacío. Agrega preguntas en “Banco de preguntas”.</p><?php else: ?>
        <div class="aula-banco-filtros">
            <?php if ($modules && $bancoExt): ?><label>Módulo<select data-filtro-modulo><option value="">Todos los módulos</option><option value="0">Sin módulo</option><?php foreach ($modules as $m): ?><option value="<?= (int) $m['id'] ?>" <?= (int) $moduloInicial === (int) $m['id'] ? 'selected' : '' ?>><?= aula_h($m['nombre']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
            <label class="aula-check-todas"><input type="checkbox" data-seleccionar-todas> Seleccionar todas las preguntas visibles</label>
            <span class="aula-muted" data-contador-seleccion></span>
        </div>
        <p class="aula-muted"><?= $bancoExt ? 'Indica el valor en puntos de cada pregunta; la nota se calcula sobre 100 según el total. ' : 'Todas las preguntas tienen el mismo peso. ' ?>El orden se mezcla al iniciar cada intento.</p>
        <div class="aula-selector-lista">
        <?php foreach ($bank as $p): $pid = (int) $p['id']; $mid = (int) ($p['curso_modulo_id'] ?? 0); $valor = $valores[$pid] ?? ($p['puntos'] ?? 1); ?>
            <div class="aula-option" data-modulo="<?= $mid ?>"><label><input type="checkbox" name="preguntas[]" value="<?= $pid ?>" <?= in_array($pid, $marcadas, true) ? 'checked' : '' ?>>
                <span><b>#<?= $pid ?></b> <?= aula_h(mb_strimwidth($p['enunciado'], 0, 220, '…')) ?>
                <small class="aula-muted"><?= aula_h(AULA_TIPOS_CORTOS[$p['tipo']] ?? $p['tipo']) ?><?= $mid && isset($modNombres[$mid]) ? ' · ' . aula_h($modNombres[$mid]) : '' ?><?= $p['tema'] !== '' ? ' · ' . aula_h($p['tema']) : '' ?></small></span></label>
                <?php if ($bancoExt): ?><label class="aula-valor-campo">Puntos<input type="number" name="valor[<?= $pid ?>]" min="0.01" max="1000" step="0.01" value="<?= aula_h(aula_pts($valor)) ?>" data-valor></label><?php endif; ?></div>
        <?php endforeach; ?>
        </div>
        <p class="aula-muted" data-selector-vacio hidden>No hay preguntas de este módulo.</p>
        <?php endif; ?>
    </fieldset>
    <?php return ob_get_clean();
};

/** Disponibilidad (desde/hasta), tiempo para realizarla, intentos y habilitación. */
$disponibilidad = static function (array $e = []) use ($bancoExt, $fechaInput) {
    ob_start(); ?>
    <fieldset class="aula-question aula-disponibilidad"><legend>Disponibilidad y tiempo</legend>
        <div class="aula-courses">
            <label>Disponible desde (Guatemala)<input type="datetime-local" name="abre" required value="<?= aula_h($fechaInput($e['abre'] ?? '')) ?>"><small><a href="#" data-ev-ahora>Ahora</a> · <a href="#" data-ev-manana>Mañana 07:00</a></small></label>
            <label>Disponible hasta (Guatemala)<input type="datetime-local" name="cierra" required value="<?= aula_h($fechaInput($e['cierra'] ?? '')) ?>"><small><a href="#" data-ev-extender="1">+1 día</a> · <a href="#" data-ev-extender="7">+1 semana</a></small></label>
            <?= aula_duracion_campo($e['minutos'] ?? 60) ?>
            <label>Intentos permitidos<input type="number" name="intentos" min="1" max="5" required value="<?= (int) ($e['intentos'] ?? 1) ?>" data-intentos></label>
            <?php if ($bancoExt): ?><span data-solo-varios-intentos <?= (int) ($e['intentos'] ?? 1) > 1 ? '' : 'hidden' ?>><?= aula_calificacion_campo($e['calificacion'] ?? 'mayor') ?></span><?php endif; ?>
        </div>
        <p class="aula-muted">El estudiante solo puede iniciarla dentro de este periodo. El tiempo corre desde que pulsa “Iniciar”, nunca pasa de la hora de cierre y al terminar se entrega sola.<?= $e ? ' Cambiar el cierre afecta a los intentos que se inicien desde ahora.' : '' ?></p>
        <label class="aula-habilitar"><input type="checkbox" name="habilitada" <?= !$e || $e['habilitada'] ? 'checked' : '' ?>> Habilitada (los estudiantes la ven en su aula)</label>
    </fieldset>
    <?php return ob_get_clean();
};
?>
<details class="aula-card" id="banco" open><summary>Banco de preguntas · <?= count($bank) ?></summary>
<?php if (!$bancoExt): ?><p class="alert alert-info">Para asignar módulos, valores y preguntas con varias respuestas correctas aplica la migración <code>20261111_aula_banco_preguntas.sql</code>.</p><?php endif; ?>
<div class="aula-banco-acciones">
    <details class="aula-eval-config" id="banco-nueva" <?= $bank ? '' : 'open' ?>><summary>＋ Agregar una pregunta</summary>
        <form method="post" class="aula-form" data-form-pregunta><?= aula_csrf() ?><input type="hidden" name="accion" value="pregunta"><?= $preguntaCampos() ?><button class="btn btn-primary">Agregar al banco</button></form>
    </details>
    <details class="aula-eval-config" id="banco-masiva"><summary>⇪ Carga masiva desde Excel</summary>
        <div class="aula-form">
            <ol class="aula-pasos">
                <li><a class="btn btn-default btn-sm" href="plantilla_banco_preguntas.php?curso=<?= (int) $cid ?>">Descargar plantilla Excel</a> <span class="aula-muted">Incluye ejemplos, instrucciones<?= $modules ? ' y la lista de módulos del curso' : '' ?>.</span></li>
                <li>Una pregunta por fila. <b>TIPO</b>: Una correcta, Varias correctas, Abierta o Tarea. Opciones en las columnas <b>OPCION_A</b> a <b>OPCION_J</b>, en <b>CORRECTA</b> la letra o el número (varias separadas por coma: <code>A,C</code>) y en <b>PUNTOS</b> el valor (vacío = 1).</li>
                <li>Sube el archivo. Si alguna fila tiene errores no se guarda nada y se indica qué corregir.</li>
            </ol>
            <form method="post" enctype="multipart/form-data" class="aula-inline"><?= aula_csrf() ?><input type="hidden" name="accion" value="banco_importar"><input type="file" name="archivo" accept=".xlsx,.xls,.csv" required><button class="btn btn-primary">Cargar preguntas</button></form>
        </div>
    </details>
</div>
<?php if ($bank): ?>
<div class="aula-banco-filtros" data-banco-filtros>
    <?php if ($modules && $bancoExt): ?><label>Módulo<select data-filtro-modulo><option value="">Todos los módulos</option><option value="0">Sin módulo</option><?php foreach ($modules as $m): ?><option value="<?= (int) $m['id'] ?>"><?= aula_h($m['nombre']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
    <label>Tipo<select data-filtro-tipo><option value="">Todos</option><?php foreach (AULA_TIPOS_CORTOS as $k => $t): ?><option value="<?= $k ?>"><?= aula_h($t) ?></option><?php endforeach; ?></select></label>
    <label class="aula-banco-buscar">Buscar<input type="search" data-filtro-texto placeholder="Texto del enunciado o tema"></label>
    <span class="aula-muted" data-banco-contador></span>
</div>
<div class="aula-banco-lista" data-banco-lista>
<?php foreach ($bank as $p): $pid = (int) $p['id']; $mid = (int) ($p['curso_modulo_id'] ?? 0); $opts = json_decode((string) $p['opciones'], true) ?: []; $ok = aula_correctas_de($p); $enEvals = $usos['p'][$pid] ?? []; ?>
<div class="aula-pregunta" id="preg-<?= $pid ?>" data-modulo="<?= $mid ?>" data-tipo="<?= aula_h($p['tipo']) ?>" data-texto="<?= aula_h(mb_strtolower($p['enunciado'] . ' ' . $p['tema'])) ?>">
    <div class="aula-pregunta-cab">
        <span class="aula-badge is-tipo">#<?= $pid ?></span>
        <span class="aula-badge"><?= aula_h(AULA_TIPOS_CORTOS[$p['tipo']] ?? $p['tipo']) ?></span>
        <?php if ($bancoExt): ?><span class="aula-badge is-complete"><?= aula_h(aula_pts($p['puntos'] ?? 1)) ?> pt<?= (float) ($p['puntos'] ?? 1) == 1 ? '' : 's' ?></span><?php endif; ?>
        <?php if ($mid && isset($modNombres[$mid])): ?><span class="aula-badge is-programado"><?= aula_h($modNombres[$mid]) ?></span><?php endif; ?>
        <?php if ($p['tema'] !== ''): ?><span class="aula-muted"><?= aula_h($p['tema']) ?></span><?php endif; ?>
        <?php if ($enEvals): ?><span class="aula-muted" title="<?= aula_h(implode(' · ', array_map(static function ($e) use ($evTitulos) { return $evTitulos[$e] ?? ''; }, $enEvals))) ?>">En <?= count($enEvals) ?> evaluación(es)</span><?php endif; ?>
    </div>
    <p class="aula-pregunta-texto"><?= aula_h($p['enunciado']) ?></p>
    <?php if ($opts): ?><ol class="aula-pregunta-opciones" type="A"><?php foreach ($opts as $i => $o): ?><li class="<?= in_array($i, $ok, true) ? 'is-correcta' : '' ?>"><?= aula_h($o) ?><?= in_array($i, $ok, true) ? ' <b>✓</b>' : '' ?></li><?php endforeach; ?></ol><?php endif; ?>
    <div class="aula-inline aula-pregunta-acciones">
        <details class="aula-eval-config"><summary>Editar</summary><form method="post" class="aula-form" data-form-pregunta><?= aula_csrf() ?><input type="hidden" name="accion" value="pregunta"><input type="hidden" name="pregunta_id" value="<?= $pid ?>"><?= $preguntaCampos($p) ?><p class="aula-muted">Los intentos ya iniciados conservan la versión anterior de la pregunta.</p><button class="btn btn-primary">Guardar cambios</button></form></details>
        <form method="post" data-confirmar="<?= aula_h('¿Eliminar la pregunta #' . $pid . '?' . ($enEvals ? ' Se retirará de ' . count($enEvals) . ' evaluación(es).' : '') . ' Las notas ya registradas no cambian.') ?>"><?= aula_csrf() ?><input type="hidden" name="accion" value="pregunta_eliminar"><input type="hidden" name="pregunta_id" value="<?= $pid ?>"><button class="btn btn-link aula-eliminar">Eliminar</button></form>
    </div>
</div>
<?php endforeach; ?>
</div>
<p class="aula-muted" data-banco-vacio hidden>No hay preguntas que coincidan con el filtro.</p>
<?php endif; ?>
</details>

<details class="aula-card" id="evaluaciones"><summary>Evaluaciones · <?= count($evals) ?></summary>
<details class="aula-eval-config" id="evaluacion-nueva" <?= $evals ? '' : 'open' ?>><summary>＋ Crear una evaluación</summary>
<form method="post" class="aula-form" data-form-evaluacion><?= aula_csrf() ?><input type="hidden" name="accion" value="evaluacion">
    <label>Título<input name="titulo" required maxlength="180"></label>
    <?= aula_binding_fields($sessions, $modules, ['sesion_id' => (int) ($_GET['sesion'] ?? 0)]) ?>
    <?= $disponibilidad() ?>
    <?php if ($bancoExt): ?><?= aula_resultado_campos() ?><?php endif; ?>
    <?= $selectorPreguntas([]) ?>
    <div class="aula-inline"><button class="btn btn-primary">Crear evaluación</button><span class="aula-muted" data-total-puntos></span></div>
</form>
</details>
<?php $evAhora = date('Y-m-d H:i:s'); foreach ($evals as $e): $eid = (int) $e['id']; $enEval = $usos['e'][$eid] ?? []; $totalEv = array_sum($usos['v'][$eid] ?? []);
    $evEstado = !$e['habilitada'] ? ['Deshabilitada', 'is-oculto'] : ($evAhora < $e['abre'] ? ['Abre ' . aula_fecha_larga($e['abre'], true) . ' ' . substr($e['abre'], 11, 5), 'is-programado'] : ($evAhora >= $e['cierra'] ? ['Cerrada el ' . aula_fecha_larga($e['cierra'], true) . ' ' . substr($e['cierra'], 11, 5), 'is-oculto'] : ['Abierta · cierra ' . aula_fecha_larga($e['cierra'], true) . ' ' . substr($e['cierra'], 11, 5), 'is-complete'])); ?>
<details class="aula-eval-config" id="ev-<?= $eid ?>"><summary><?= aula_h($e['titulo']) ?> · <?= count($enEval) ?> pregunta(s)<?= $bancoExt && $enEval ? ' · ' . aula_h(aula_pts($totalEv)) . ' pts' : '' ?> · <?= aula_h(aula_duracion_texto($e['minutos'])) ?> · <?= aula_h(aula_resultado_texto($e)) ?> <span class="aula-badge <?= $evEstado[1] ?>"><?= aula_h($evEstado[0]) ?></span></summary>
<form method="post" class="aula-form" data-form-evaluacion><?= aula_csrf() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="evaluacion" value="<?= $eid ?>"><input type="hidden" name="ancla" value="ev-<?= $eid ?>">
    <?= $disponibilidad($e) ?>
    <?php if ($bancoExt): ?><?= aula_resultado_campos($e) ?><?php endif; ?>
    <?= aula_binding_fields($sessions, $modules, $e) ?>
    <details class="aula-eval-preguntas"><summary>Preguntas y valores (<?= count($enEval) ?>) · cambiar</summary><input type="hidden" name="editar_preguntas" value="1" disabled data-editar-preguntas><?= $selectorPreguntas($enEval, $usos['v'][$eid] ?? []) ?><p class="aula-muted">Los intentos ya iniciados conservan sus preguntas y valores; los nuevos intentos usan esta lista.</p></details>
    <div class="aula-inline"><label data-solo-manual><input type="checkbox" name="publicar" <?= $e['publicar'] ? 'checked' : '' ?>> Publicar notas y alimentar seguimiento</label><button class="btn btn-primary">Guardar evaluación</button><span class="aula-muted" data-total-puntos></span></div>
</form></details>
<?php endforeach; ?>
</details>
<script src="js/aula_banco.js?v=<?= (int) @filemtime(__DIR__ . '/js/aula_banco.js') ?>"></script>
