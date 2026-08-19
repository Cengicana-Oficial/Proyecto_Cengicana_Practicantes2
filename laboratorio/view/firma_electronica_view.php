<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/shell_sidebar.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../models/documentos_model.php';

lab_require_permission('laboratorio.firma.gestionar');

$pdo = Conexion::conectar();

$tablaDisponible = lab_documentos_tabla_existe($pdo, 'lab_firma_documento');
$firmas = lab_firmas_listar($pdo);
$pendientes = lab_firmas_documentos_pendientes($pdo);

function firma_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function firma_fecha($fecha): string
{
    $fecha = trim((string) $fecha);
    if ($fecha === '') {
        return '-';
    }
    $ts = strtotime($fecha);
    return $ts ? date('d/m/Y H:i', $ts) : $fecha;
}

lab_shell_head('Firma electronica', 'Firmas registradas e informes pendientes de firmar', [
    '../css/lab_shell.css?v=1',
]);
lab_shell_open('firma_electronica_view.php');
?>

<div class="cengi-kpi-grid">
    <article class="cengi-kpi">
        <span class="cengi-kpi-icon"><span class="material-symbols-outlined" style="font-size:16px;">draw</span></span>
        <div class="cengi-kpi-val"><?= count($firmas) ?></div>
        <div class="cengi-kpi-label">Firmas registradas</div>
        <div class="cengi-kpi-trend is-flat">Tabla <code>lab_firma_documento</code></div>
        <span class="cengi-kpi-bar"></span>
    </article>
    <article class="cengi-kpi">
        <span class="cengi-kpi-icon"><span class="material-symbols-outlined" style="font-size:16px;">pending_actions</span></span>
        <div class="cengi-kpi-val"><?= count($pendientes) ?></div>
        <div class="cengi-kpi-label">Informes vigentes sin firma</div>
        <div class="cengi-kpi-trend is-flat">Documentos tipo "informe"</div>
        <span class="cengi-kpi-bar"></span>
    </article>
</div>

<?php if (!$tablaDisponible): ?>
    <div class="cengi-empty" style="margin-bottom:18px;">
        La tabla <code>lab_firma_documento</code> todavia no existe en esta base de datos.
        Aplique <code>laboratorio/database/002_lab_flujo_generico.sql</code> para habilitar el
        registro de firmas electronicas.
    </div>
<?php endif; ?>

<div class="cengi-card" style="margin-bottom:18px;">
    <h3>Informes pendientes de firma</h3>
    <div class="cengi-card-sub">
        Documentos vigentes de tipo "informe" (<code>lab_documento</code>) que todavia no tienen
        ninguna fila en <code>lab_firma_documento</code>.
    </div>

    <?php if (empty($pendientes)): ?>
        <div class="cengi-empty">
            No hay informes generados pendientes de firma. Este listado se llenara automaticamente
            en cuanto el flujo de generacion de informes empiece a escribir en <code>lab_documento</code>.
        </div>
    <?php else: ?>
        <div class="cengi-table-wrap">
            <table>
                <thead>
                    <tr><th>Lote</th><th>Titulo</th><th>Version</th><th>Generado en</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($pendientes as $doc): ?>
                        <tr>
                            <td>Lote <?= firma_e($doc['codigo_lote'] ?? '-') ?></td>
                            <td><?= firma_e($doc['titulo'] ?: '-') ?></td>
                            <td>v<?= (int) $doc['version'] ?></td>
                            <td><?= firma_e(firma_fecha($doc['generado_en'] ?? null)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="cengi-card">
    <h3>Firmas registradas</h3>
    <div class="cengi-card-sub">
        Historial de firmas ya capturadas (patron identico al usado para las firmas de
        ingreso/recibo en <code>view/solicitud_formulario.php</code>: imagen PNG en base64).
    </div>

    <?php if (empty($firmas)): ?>
        <div class="cengi-empty">Todavia no se ha registrado ninguna firma electronica.</div>
    <?php else: ?>
        <div class="cengi-table-wrap">
            <table>
                <thead>
                    <tr><th>Documento</th><th>Lote</th><th>Rol</th><th>Firmante</th><th>Firma</th><th>Fecha</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($firmas as $f): ?>
                        <tr>
                            <td><?= firma_e($f['documento_titulo'] ?: ('Documento #' . (int) $f['id_documento'])) ?></td>
                            <td>Lote <?= firma_e($f['codigo_lote'] ?? '-') ?></td>
                            <td><?= firma_e($f['rol_firma']) ?></td>
                            <td><?= firma_e($f['firmante_nombre'] ?: '-') ?></td>
                            <td>
                                <?php if (!empty($f['tiene_imagen'])): ?>
                                    <span class="cengi-status-badge is-approved">Con imagen</span>
                                <?php else: ?>
                                    <span class="cengi-status-badge is-neutral">Sin imagen</span>
                                <?php endif; ?>
                            </td>
                            <td><?= firma_e(firma_fecha($f['fecha_firma'] ?? null)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php lab_shell_close(); ?>
