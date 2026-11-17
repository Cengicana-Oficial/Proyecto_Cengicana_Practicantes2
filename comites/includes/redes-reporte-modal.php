<?php
// Dialogo "Generar reporte" de Redes sociales (se abre con &reporte=1 en cualquier pestana).
$periodoRep = estadisticas_periodo($_GET);
$cerrarRep = 'index.php?' . http_build_query(array_diff_key($_GET, ['reporte' => 1]));
$conDatos = fetch_placeholder_rows($pdo, "SELECT DISTINCT plataforma FROM publicaciones WHERE estado = 'Publicada' AND plataforma IS NOT NULL ORDER BY plataforma");
?>
<link rel="stylesheet" href="assets/css/estadisticas.css?v=<?= @filemtime(__DIR__ . '/../assets/css/estadisticas.css') ?>">
<div class="modal-backdrop is-open" data-close-href="<?= e($cerrarRep) ?>">
    <div class="modal-box modal-wide rep-modal" onclick="event.stopPropagation()">
        <div class="modal-head">
            <h3><i class="ti ti-file-analytics"></i> Generar informe de redes sociales</h3>
            <a class="modal-close" href="<?= e($cerrarRep) ?>"><i class="ti ti-x"></i></a>
        </div>
        <form method="get" class="modal-body" id="rep-form">
            <input type="hidden" name="view" value="publicaciones">
            <div class="rep-paso">
                <span class="rep-num">1</span>
                <div>
                    <b>Periodo</b>
                    <div class="rep-fila">
                        <select class="form-control" name="periodo" id="rep-periodo">
                            <?php foreach (ESTADISTICAS_PERIODOS as $clave => $etiqueta): ?>
                                <option value="<?= e($clave) ?>" <?= $periodoRep['clave'] === $clave ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span id="rep-rango" class="rep-fila" <?= $periodoRep['clave'] === 'rango' ? '' : 'hidden' ?>>
                            <input class="form-control" type="month" name="desde" value="<?= e($periodoRep['desde_mes']) ?>" aria-label="Desde">
                            <input class="form-control" type="month" name="hasta" value="<?= e($periodoRep['hasta_mes']) ?>" aria-label="Hasta">
                        </span>
                    </div>
                    <small class="text-muted">Los indicadores se comparan con el periodo anterior de la misma duración.</small>
                </div>
            </div>
            <div class="rep-paso">
                <span class="rep-num">2</span>
                <div>
                    <b>Plataformas</b> <small class="text-muted">(sin marcar = todas)</small>
                    <div class="rep-opciones">
                        <?php foreach ($conDatos as $fila): $p = (string) $fila['plataforma']; $cfg = redes_plataformas()[$p] ?? redes_plataformas()['Otro']; ?>
                            <label class="rep-chip"><input type="checkbox" name="plataformas[]" value="<?= e($p) ?>"><span><i class="ti <?= e($cfg['icon']) ?>" style="color:<?= e($cfg['color']) ?>"></i> <?= e($p) ?></span></label>
                        <?php endforeach; ?>
                        <?php if (!$conDatos): ?><small class="text-muted">Aún no hay publicaciones publicadas.</small><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="rep-paso">
                <span class="rep-num">3</span>
                <div>
                    <b>Secciones del informe</b>
                    <div class="rep-opciones rep-secciones">
                        <?php foreach (REDES_REPORTE_SECCIONES as $clave => $etiqueta): ?>
                            <label class="rep-chip"><input type="checkbox" name="secciones[]" value="<?= e($clave) ?>" checked><span><?= e($etiqueta) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="rep-paso">
                <span class="rep-num">4</span>
                <div>
                    <b>Conclusiones</b> <small class="text-muted">(opcional, aparece en la sección «Conclusiones»)</small>
                    <textarea class="form-control rep-conclusiones" name="conclusiones" rows="3" maxlength="3000" placeholder="Ej. El diplomado fue el contenido con mayor alcance; en julio se reforzarán los videos cortos con técnicos de campo..."></textarea>
                    <small class="text-muted">El informe agrega además las acciones sugeridas por el análisis.</small>
                </div>
            </div>
            <div class="rep-paso">
                <span class="rep-num">5</span>
                <div>
                    <b>Formato</b>
                    <div class="rep-formatos">
                        <button type="submit" name="generar" value="pdf" class="rep-formato"><i class="ti ti-file-type-pdf"></i><b>PDF</b><small>Para enviar o archivar</small></button>
                        <button type="submit" name="generar" value="html" formtarget="_blank" class="rep-formato"><i class="ti ti-printer"></i><b>Ver e imprimir</b><small>Se abre en otra pestaña</small></button>
                        <button type="submit" name="generar" value="xlsx" class="rep-formato"><i class="ti ti-file-spreadsheet"></i><b>Excel</b><small>Datos para analizar</small></button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var sel = document.getElementById('rep-periodo');
    sel.addEventListener('change', function () { document.getElementById('rep-rango').hidden = sel.value !== 'rango'; });
})();
</script>
