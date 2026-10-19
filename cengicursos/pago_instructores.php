<?php
require_once __DIR__ . '/planificacion_helpers.php';
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/pago_recibos_helpers.php';

// Administración gestiona todo; el Jefe de Transferencia de Tecnología (permiso
// firmar_liquidaciones_cengi) entra a revisar, firmar e imprimir recibos.
$esAdmin = cengi_es_admin();
// Solo quien tiene el permiso firma (el superadministrador lo tiene todo); el administrador configura y aprueba.
$esFirmante = cengi_tiene_permiso('firmar_liquidaciones_cengi');
$puedeFirmar = $esAdmin || $esFirmante;
if (!$puedeFirmar) {
    header('Location: index.php');
    exit;
}

$db = conectar();
$hoy = cengi_pp_hoy();

/**
 * Tablas y columnas del módulo (migrations/20261016_liquidacion_versiones.sql y
 * 20261017_liquidacion_comprobante_firma.sql). Se agregan aquí si faltan, fuera de cualquier
 * transacción (el DDL de MySQL hace commit implícito).
 */
function cengi_pago_asegurar_esquema(PDO $db)
{
    $db->exec("CREATE TABLE IF NOT EXISTS liquidacion_versiones (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT, liquidacion_id INT UNSIGNED NOT NULL, version SMALLINT UNSIGNED NOT NULL,
      motivo VARCHAR(500) NOT NULL DEFAULT '', cambios TEXT NULL, datos LONGTEXT NOT NULL,
      total_antes DECIMAL(12,2) NOT NULL DEFAULT 0, total_despues DECIMAL(12,2) NOT NULL DEFAULT 0,
      usuario VARCHAR(255) NOT NULL DEFAULT '', creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id), UNIQUE KEY uq_liquidacion_version (liquidacion_id, version),
      CONSTRAINT fk_liquidacion_versiones_liq FOREIGN KEY (liquidacion_id) REFERENCES liquidaciones (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $columnas = [
        'instructores' => ['dpi' => "VARCHAR(20) NOT NULL DEFAULT ''", 'comprobante' => "VARCHAR(10) NOT NULL DEFAULT 'factura'"],
        'soporte_tecnico' => ['dpi' => "VARCHAR(20) NOT NULL DEFAULT ''", 'comprobante' => "VARCHAR(10) NOT NULL DEFAULT 'factura'"],
        'liquidacion_detalle' => ['comprobante' => "VARCHAR(10) NOT NULL DEFAULT 'factura'", 'impuesto_pct' => 'DECIMAL(5,2) NOT NULL DEFAULT 5.00',
            'persona_dpi' => "VARCHAR(20) NOT NULL DEFAULT ''", 'documento_numero' => "VARCHAR(40) NOT NULL DEFAULT ''"],
        'liquidaciones' => ['firmada_en' => 'DATETIME NULL', 'firmada_por' => "VARCHAR(255) NOT NULL DEFAULT ''", 'firma_codigo' => "VARCHAR(20) NOT NULL DEFAULT ''",
            'firma_imagen' => 'MEDIUMTEXT NULL', 'firma_nombre' => "VARCHAR(255) NOT NULL DEFAULT ''", 'firma_cargo' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'observacion_jefe' => "VARCHAR(500) NOT NULL DEFAULT ''"],
    ];
    $existentes = [];
    foreach ($db->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME IN ('instructores', 'soporte_tecnico', 'liquidacion_detalle', 'liquidaciones')")->fetchAll(PDO::FETCH_NUM) as [$t, $c]) {
        $existentes[$t][$c] = true;
    }
    foreach ($columnas as $tabla => $defs) {
        foreach ($defs as $columna => $definicion) {
            if (empty($existentes[$tabla][$columna])) {
                $db->exec("ALTER TABLE $tabla ADD COLUMN $columna $definicion");
            }
        }
    }
    // Permiso del Jefe de Transferencia en la base de usuarios (matriz de Roles y permisos).
    try {
        conectar_usuarios_menu_pdo()->prepare('INSERT IGNORE INTO permisos (nombre_permiso, descripcion) VALUES (?, ?)')
            ->execute(['firmar_liquidaciones_cengi', 'Permite validar y firmar digitalmente las liquidaciones de pago a instructores (Jefe de Transferencia de Tecnologia)']);
    } catch (Throwable $e) {
        error_log('Pago a instructores: no se pudo registrar el permiso de firma: ' . $e->getMessage());
    }
}
cengi_pago_asegurar_esquema($db);
cengi_pr_asegurar($db);
$cfgPago = cengi_pp_pago_config($db);

/** Guarda la configuración del pago a instructores (eventos_config, clave pi_config). */
function cengi_pago_guardar_config(PDO $db, array $cfg)
{
    $db->prepare("INSERT INTO eventos_config (clave, valor) VALUES ('pi_config', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)")
        ->execute([json_encode($cfg, JSON_UNESCAPED_UNICODE)]);
}

/** Siguiente correlativo de recibo del año (R-2026-0001), dentro de la transacción en curso. */
function cengi_pago_siguiente_recibo(PDO $db)
{
    $db->exec("INSERT IGNORE INTO eventos_config (clave, valor) VALUES ('pi_recibo_seq', '{}')");
    $valor = $db->query("SELECT valor FROM eventos_config WHERE clave = 'pi_recibo_seq' FOR UPDATE")->fetchColumn();
    $seq = json_decode((string) $valor, true) ?: [];
    $anio = date('Y');
    $seq[$anio] = (int) ($seq[$anio] ?? 0) + 1;
    $db->prepare("UPDATE eventos_config SET valor = ? WHERE clave = 'pi_recibo_seq'")->execute([json_encode($seq)]);
    return 'R-' . $anio . '-' . str_pad((string) $seq[$anio], 4, '0', STR_PAD_LEFT);
}

// cengi_pago_secreto(), cengi_pago_codigo_firma() y cengi_pago_concepto(): pago_recibos_helpers.php (también los usa recibo_firmado.php).

$mensaje = '';
$mensajeTipo = 'success';

const CENGI_PAGO_MENSAJES = [
    'generada' => 'Liquidación generada y enviada al Jefe de Transferencia para su aprobación.',
    'solicitada' => 'Liquidación enviada al Jefe de Transferencia para su aprobación. Cuando la apruebe se habilita el envío a Administración.',
    'devuelta' => 'Liquidación devuelta a Capacitación con tu observación.',
    'borrador_reaprobar' => 'Liquidación actualizada y de vuelta en borrador: envíala de nuevo al Jefe de Transferencia para su aprobación.',
    'borrador' => 'Liquidación guardada como borrador. Revísala, edítala si hace falta y envíala a Administración cuando esté lista.',
    'borrador_editado' => 'Borrador actualizado. Aún no se ha enviado a Administración.',
    'borrador_eliminado' => 'Borrador eliminado: sus sesiones vuelven a “Por liquidar”.',
    'liq_enviada' => 'Liquidación enviada a Administración. Desde ahora, cualquier cambio queda como una nueva versión con su comentario.',
    'factura_registrada' => 'Factura registrada. Queda en el expediente de la persona.',
    'factura_req' => 'Requerimientos de factura enviados.',
    'aprobada' => 'Liquidación aprobada: lista para pago.',
    'pagada' => 'Pago registrado.',
    'anulada' => 'Liquidación anulada: sus sesiones vuelven a quedar por liquidar.',
    'tarifas' => 'Tarifas actualizadas.',
    'impartidas' => 'Sesiones marcadas como impartidas: ya aparecen en “Por liquidar”.',
    'firmada' => 'Liquidación aprobada y firmada por el Jefe de Transferencia. Ya se puede enviar a Administración.',
    'config' => 'Configuración de pagos guardada.',
    'recibo_env' => 'Recibos enviados por correo para firmar.',
    'recibo_firmado' => 'Recibo firmado guardado. Si había uno anterior, queda en el expediente del recibo.',
    'recibo_validado' => 'Recibo(s) validado(s) por el Jefe de Transferencia.',
    'corregida' => 'Nueva versión guardada con tu comentario. La versión anterior quedó en el historial de versiones.',
    'corregida_reaprobar' => 'Liquidación corregida y devuelta a “Enviada”: Administración debe aprobarla de nuevo. La versión anterior quedó guardada en el historial.',
];
const CENGI_PAGO_MAX_DIAS = 366;
// Estados antes de enviarse a Administración: se editan sin crear versión y no generan recibos ni facturas.
const CENGI_PG_SIN_ENVIAR = ['borrador', 'revision', 'autorizada'];
// Tipo de liquidación: se genera por separado para instructores (sesiones impartidas) y para soporte técnico.
const CENGI_PAGO_CLASES = [
    'instructor' => ['Instructores', 'Sesiones impartidas', 'INS', 'record'],
    'soporte' => ['Soporte técnico', 'Soporte de sesiones', 'SOP', 'wrench'],
];

/** Nota que acompaña a cada persona según el comprobante con que cobra. */
function cengi_pago_nota_comprobante($comprobante, $pctFactura)
{
    $pct = rtrim(rtrim(number_format((float) $pctFactura, 2, '.', ''), '0'), '.');
    return $comprobante === 'recibo'
        ? 'Se emite recibo para su firma; después lo valida el Jefe. Se paga el neto (sin impuesto).'
        : 'Presenta factura de pequeño contribuyente: se suma el ' . $pct . '%.';
}

/** Expediente de un recibo: pasos con fecha, quién y el archivo de evidencia (PDF emitido o firmado). */
/**
 * Avance del trámite de pago de una persona (fila de la liquidación):
 * recibo: enviado → firmado → validado por el Jefe → pagado (4 pasos);
 * factura: requerimiento enviado → factura registrada → pagado (3 pasos).
 * Devuelve [%, texto del paso, clase, pasos hechos, pasos totales].
 */
function cengi_pg_avance(array $d, $er, $requerido, $liqEstado)
{
    $esRecibo = ($d['comprobante'] ?? '') === 'recibo';
    $pasos = $esRecibo ? 4 : 3;
    if ($liqEstado === 'pagada') {
        return [100, 'Pagado', 'is-pagado', $pasos, $pasos];
    }
    if (in_array($liqEstado, CENGI_PG_SIN_ENVIAR, true)) {
        return [0, $esRecibo ? 'Recibo: se emite al enviar la liquidación' : 'Factura: se solicita al enviar la liquidación', '', 0, $pasos];
    }
    if ($liqEstado === 'anulada') {
        return [0, 'Anulada', '', 0, $pasos];
    }
    if ($esRecibo) {
        $e = cengi_pr_estado($d, $er);
        $n = ['sin_enviar' => 0, 'monto_cambio' => 0, 'enviado' => 1, 'firmado' => 2, 'validado' => 3][$e] ?? 0;
        $txt = [
            'sin_enviar' => 'Recibo por enviar',
            'monto_cambio' => 'Recibo por reenviar (cambió el monto)',
            'enviado' => 'Esperando la firma de la persona',
            'firmado' => 'Firmado · falta la validación del Jefe',
            'validado' => 'Recibo validado · listo para pago',
        ][$e] ?? '';
        return [(int) round($n / 4 * 100), $txt, $n === 3 ? 'is-listo' : '', $n, 4];
    }
    $hFac = trim((string) ($d['documento_numero'] ?? '')) !== '';
    $n = $hFac ? 2 : ($requerido ? 1 : 0);
    return [(int) round($n / 3 * 100), $hFac ? 'Factura ' . $d['documento_numero'] . ' registrada · lista para pago' : ($requerido ? 'Esperando la factura' : 'Requerimiento de factura por enviar'), $hFac ? 'is-listo' : '', $n, 3];
}

/** % de la liquidación (ponderado por monto), comprobantes listos y montos por forma de pago. */
function cengi_pg_avance_resumen(array $filas, $liqEstado)
{
    $total = 0.0;
    $ponderado = 0.0;
    $listos = 0;
    $forma = ['recibo' => [0, 0.0], 'factura' => [0, 0.0]];
    foreach ($filas as $f) {
        $total += (float) $f['total'];
        $ponderado += (float) $f['total'] * (int) $f['pct'];
        $listos += in_array($f['paso_cls'], ['is-listo', 'is-pagado'], true) ? 1 : 0;
        $c = $f['comprobante'] === 'recibo' ? 'recibo' : 'factura';
        $forma[$c][0]++;
        $forma[$c][1] += (float) $f['total'];
    }
    $pct = $liqEstado === 'pagada' ? 100 : ($total > 0 ? (int) floor($ponderado / $total) : 0);
    return ['pct' => $pct, 'listos' => $listos, 'n' => count($filas), 'forma' => $forma];
}

/** Anillo de avance compacto (% en el centro). */
function cengi_pg_anillo($pct, $clase = '')
{
    $pct = max(0, min(100, (int) $pct));
    return '<span class="pg-anillo' . ($pct >= 100 ? ' is-completo' : '') . ($clase !== '' ? ' ' . $clase : '') . '" style="--p:' . $pct . '" role="img" aria-label="Avance ' . $pct . ' %"><b>' . $pct . '<i>%</i></b></span>';
}

/** Pasos del comprobante como segmentos (hechos en verde, el siguiente en amarillo). */
function cengi_pg_puntos($hechos, $pasos)
{
    $html = '<span class="pg-puntos" title="Paso ' . min((int) $hechos + 1, (int) $pasos) . ' de ' . (int) $pasos . '">';
    for ($k = 0; $k < (int) $pasos; $k++) {
        $html .= '<i class="' . ($k < (int) $hechos ? 'is-hecho' : ($k === (int) $hechos ? 'is-actual' : '')) . '"></i>';
    }
    return $html . '</span>';
}

function cengi_pg_expediente(array $pasos)
{
    if (!$pasos) {
        return '';
    }
    $html = '<details class="pg-expediente"><summary><span class="glyphicon glyphicon-folder-open"></span> Expediente <b>' . count($pasos) . '</b></summary><ol>';
    foreach ($pasos as $p) {
        [$texto, $icono] = CENGI_PR_ACCIONES[$p['accion']] ?? [ucfirst($p['accion']), 'record'];
        $html .= '<li class="is-' . cengi_pp_h($p['accion']) . '"><span class="glyphicon glyphicon-' . $icono . '"></span><div><b>' . cengi_pp_h($texto) . '</b>'
            . '<small>' . date('d/m/Y H:i', strtotime($p['creado'])) . ($p['usuario'] !== '' ? ' · ' . cengi_pp_h($p['usuario']) : '') . ($p['monto'] !== null ? ' · ' . cengi_pp_q($p['monto'], 2) : '')
            . ($p['detalle'] !== '' ? ' · ' . cengi_pp_h($p['detalle']) : '') . '</small></div>'
            . ($p['archivo_path'] !== '' ? '<a class="btn btn-default btn-xs" href="pago_instructores.php?recibo_archivo=' . (int) $p['id'] . '" target="_blank" rel="noopener"><span class="glyphicon glyphicon-paperclip"></span> ' . ($p['accion'] === 'enviado' ? 'PDF emitido' : 'Archivo') . '</a>' : '')
            . '</li>';
    }
    return $html . '</ol></details>';
}

/** Periodo elegido: por mes (YYYY-MM) o por rango. */
function cengi_pago_periodo(array $fuente)
{
    if (($fuente['tipo'] ?? 'mes') === 'rango') {
        $desde = (string) ($fuente['desde'] ?? '');
        $hasta = (string) ($fuente['hasta'] ?? '');
        if (cengi_pp_es_fecha($desde) && cengi_pp_es_fecha($hasta) && $hasta >= $desde) {
            return ['tipo' => 'rango', 'desde' => $desde, 'hasta' => $hasta, 'mes' => substr($desde, 0, 7),
                'label' => cengi_pp_fecha($desde) . ' – ' . cengi_pp_fecha($hasta)];
        }
    }
    $mes = preg_match('/^\d{4}-\d{2}$/', (string) ($fuente['mes'] ?? '')) ? $fuente['mes'] : date('Y-m');
    $desde = $mes . '-01';
    return ['tipo' => 'mes', 'desde' => $desde, 'hasta' => date('Y-m-t', strtotime($desde)), 'mes' => $mes,
        'label' => CENGI_PP_MESES[(int) substr($mes, 5, 2)] . ' ' . substr($mes, 0, 4)];
}

/**
 * Sesiones impartidas del periodo que aun no estan en una liquidacion, agrupadas por
 * persona (instructor o soporte) y curso. Clave: i:<instructor>:<plan> / s:<soporte>:<plan>.
 */
function cengi_pago_por_liquidar(PDO $db, array $periodo, $persona = '')
{
    $grupos = [];
    $consultas = [
        'i' => "SELECT s.id, s.fecha, s.planificacion_id, p.nombre AS curso, i.id AS persona_id, i.nombre AS persona, COALESCE(i.codigo_catedratico, '') AS codigo,
                       COALESCE(t.tarifa, i.tarifa_sesion) AS tarifa, COALESCE(t.observaciones, '') AS obs, i.comprobante, i.dpi
                FROM curso_sesiones s INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id INNER JOIN instructores i ON i.id = s.instructor_id
                LEFT JOIN tarifas_instructor_plan t ON t.instructor_id = i.id AND t.planificacion_id = s.planificacion_id
                WHERE s.estado = 'impartida' AND s.liquidacion_id IS NULL AND s.fecha BETWEEN ? AND ?",
        's' => "SELECT s.id, s.fecha, s.planificacion_id, p.nombre AS curso, st.id AS persona_id, st.nombre AS persona, st.codigo, st.tarifa, '' AS obs, st.comprobante, st.dpi
                FROM curso_sesiones s INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id INNER JOIN soporte_tecnico st ON st.id = s.soporte_id
                WHERE s.estado = 'impartida' AND s.liquidacion_soporte_id IS NULL AND s.fecha BETWEEN ? AND ?",
    ];
    foreach ($consultas as $tipo => $sql) {
        $stmt = $db->prepare($sql . ' ORDER BY s.fecha');
        $stmt->execute([$periodo['desde'], $periodo['hasta']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            if ($persona !== '' && $persona !== $tipo . ':' . $fila['persona_id']) {
                continue;
            }
            $clave = $tipo . ':' . $fila['persona_id'] . ':' . $fila['planificacion_id'];
            $grupos[$clave] ??= [
                'clave' => $clave, 'tipo' => $tipo === 'i' ? 'instructor' : 'soporte', 'persona_id' => (int) $fila['persona_id'],
                'persona' => $fila['persona'], 'codigo' => $fila['codigo'], 'plan_id' => (int) $fila['planificacion_id'], 'curso' => $fila['curso'],
                'tarifa' => $fila['tarifa'] !== null ? (float) $fila['tarifa'] : null, 'obs' => $fila['obs'], 'sesiones' => [], 'fechas' => [],
                'comprobante' => $fila['comprobante'] === 'recibo' ? 'recibo' : 'factura', 'dpi' => (string) $fila['dpi'],
            ];
            $grupos[$clave]['sesiones'][] = (int) $fila['id'];
            $grupos[$clave]['fechas'][] = $fila['fecha'];
        }
    }
    uasort($grupos, static function ($a, $b) {
        return [$a['tipo'] !== 'instructor', $a['persona'], $a['curso']] <=> [$b['tipo'] !== 'instructor', $b['persona'], $b['curso']];
    });
    return $grupos;
}

/** Copia completa (encabezado + detalle) de una liquidación, para guardarla como versión. */
function cengi_pago_snapshot(PDO $db, $liqId)
{
    $stmt = $db->prepare('SELECT id, numero, desde, hasta, destino, nota, estado, total, aprobada_en, aprobada_por, firmada_en, firmada_por, firma_codigo FROM liquidaciones WHERE id = ?');
    $stmt->execute([(int) $liqId]);
    $liq = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $db->prepare("SELECT id, tipo, persona_id, persona_nombre, persona_codigo, planificacion_id, curso_nombre, sesiones, fechas, tarifa, neto, impuesto, total, observaciones,
            comprobante, impuesto_pct, persona_dpi, documento_numero
        FROM liquidacion_detalle WHERE liquidacion_id = ? ORDER BY tipo = 'soporte', persona_nombre, curso_nombre");
    $stmt->execute([(int) $liqId]);
    return ['liquidacion' => $liq, 'detalle' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
}

/** Libera las sesiones de una fila del detalle (vuelven a "Por liquidar"). */
function cengi_pago_liberar_fila(PDO $db, $liqId, array $d)
{
    [$colLiq, $colPersona] = $d['tipo'] === 'soporte' ? ['liquidacion_soporte_id', 'soporte_id'] : ['liquidacion_id', 'instructor_id'];
    $db->prepare("UPDATE curso_sesiones SET $colLiq = NULL WHERE $colLiq = ? AND $colPersona = ? AND planificacion_id <=> ?")
        ->execute([(int) $liqId, (int) $d['persona_id'], $d['planificacion_id'] !== null ? (int) $d['planificacion_id'] : null]);
}

/**
 * Corrige una liquidación enviada o aprobada y guarda la versión anterior. $datos: numero,
 * destino, nota, motivo, lineas[detalle_id] = [tarifa, obs, quitar], agregar (bool).
 * Devuelve true si la liquidación estaba aprobada y vuelve a "Enviada".
 */
function cengi_pago_corregir(PDO $db, array $liq, array $datos, $usuario)
{
    $id = (int) $liq['id'];
    if (!in_array($liq['estado'], ['borrador', 'revision', 'autorizada', 'enviada', 'aprobada'], true)) {
        throw new InvalidArgumentException('Solo se corrigen liquidaciones enviadas o aprobadas. Una liquidación pagada no se modifica.');
    }
    // Borrador: aún no se envió, se edita directamente (sin comentario ni versión).
    $esBorrador = in_array($liq['estado'], CENGI_PG_SIN_ENVIAR, true);
    $motivo = mb_substr(trim((string) ($datos['motivo'] ?? '')), 0, 500, 'UTF-8');
    if ($motivo === '' && !$esBorrador) {
        throw new InvalidArgumentException('Escribe el comentario de la nueva versión: queda registrado en el historial de versiones.');
    }
    $antes = cengi_pago_snapshot($db, $id);
    $cambios = [];

    // Encabezado
    $numero = mb_substr(trim((string) ($datos['numero'] ?? $liq['numero'])), 0, 40, 'UTF-8');
    $destino = mb_substr(trim((string) ($datos['destino'] ?? $liq['destino'])), 0, 255, 'UTF-8') ?: 'Administración';
    $nota = trim((string) ($datos['nota'] ?? $liq['nota']));
    if ($numero === '') {
        throw new InvalidArgumentException('El número de liquidación no puede quedar vacío.');
    }
    if ($numero !== $liq['numero']) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM liquidaciones WHERE numero = ? AND estado <> 'anulada' AND id <> ?");
        $stmt->execute([$numero, $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException('Ya existe otra liquidación con el número ' . cengi_pp_h($numero) . '.');
        }
        $cambios[] = 'Número: ' . $liq['numero'] . ' → ' . $numero;
    }
    if ($destino !== $liq['destino']) {
        $cambios[] = 'Dirigida a: ' . $liq['destino'] . ' → ' . $destino;
    }
    if ($nota !== trim((string) $liq['nota'])) {
        $cambios[] = 'Nota para Administración actualizada';
    }

    // Filas del detalle: tarifa, observaciones o quitar
    $lineas = (array) ($datos['lineas'] ?? []);
    $actualizar = $db->prepare('UPDATE liquidacion_detalle SET tarifa = ?, neto = ?, impuesto = ?, total = ?, observaciones = ?, comprobante = ?, impuesto_pct = ?, documento_numero = ? WHERE id = ? AND liquidacion_id = ?');
    $quedan = [];
    foreach ($antes['detalle'] as $d) {
        $l = $lineas[$d['id']] ?? [];
        $quien = $d['persona_nombre'] . ' (' . $d['curso_nombre'] . ')';
        if (!empty($l['quitar'])) {
            cengi_pago_liberar_fila($db, $id, $d);
            $db->prepare('DELETE FROM liquidacion_detalle WHERE id = ? AND liquidacion_id = ?')->execute([(int) $d['id'], $id]);
            $cambios[] = 'Se quitó a ' . $quien . ': ' . (int) $d['sesiones'] . ' sesión(es), ' . cengi_pp_q($d['total'], 2) . ' (las sesiones vuelven a “Por liquidar”)';
            continue;
        }
        $tarifaTexto = str_replace(',', '', trim((string) ($l['tarifa'] ?? $d['tarifa'])));
        if (!is_numeric($tarifaTexto) || (float) $tarifaTexto < 0) {
            throw new InvalidArgumentException('Revisa la tarifa de ' . cengi_pp_h($quien) . '.');
        }
        $tarifa = round((float) $tarifaTexto, 2);
        $obs = mb_substr(trim((string) ($l['obs'] ?? $d['observaciones'])), 0, 500, 'UTF-8');
        if (abs($tarifa - (float) $d['tarifa']) >= 0.005) {
            $cambios[] = 'Tarifa de ' . $quien . ': ' . cengi_pp_q($d['tarifa'], 2) . ' → ' . cengi_pp_q($tarifa, 2);
        }
        if ($obs !== $d['observaciones']) {
            $cambios[] = 'Observación de ' . $quien . ($obs === '' ? ' eliminada' : ': “' . $obs . '”');
        }
        // Comprobante: si cambia, se aplica el % vigente del nuevo tipo; si no, se conserva el % con que se liquidó.
        $comprobante = ($l['comprobante'] ?? $d['comprobante']) === 'recibo' ? 'recibo' : 'factura';
        $pct = (float) $d['impuesto_pct'];
        $documento = (string) $d['documento_numero'];
        if ($comprobante !== $d['comprobante']) {
            $pct = cengi_pago_pct($db, $comprobante);
            $documento = $comprobante === 'recibo' && !$esBorrador ? cengi_pago_siguiente_recibo($db) : '';
            $cambios[] = 'Comprobante de ' . $quien . ': ' . CENGI_PP_COMPROBANTES[$d['comprobante']] . ' → ' . CENGI_PP_COMPROBANTES[$comprobante] . ' (impuesto ' . (float) $d['impuesto_pct'] . '% → ' . $pct . '%)';
        }
        if ($comprobante === 'recibo' && $pct > 0) {
            $cambios[] = 'Recibo de ' . $quien . ': se paga el neto (impuesto ' . $pct . '% → 0%)';
            $pct = 0.0;
        }
        [$neto, $impuesto, $total] = cengi_pago_calcular(['sesiones' => array_fill(0, (int) $d['sesiones'], 0)], $tarifa, $pct / 100);
        $actualizar->execute([$tarifa, $neto, $impuesto, $total, $obs, $comprobante, $pct, $documento, (int) $d['id'], $id]);
        // El recibo lleva el DPI: si la fila no lo tiene, se toma el registrado actualmente.
        if ($comprobante === 'recibo' && trim((string) $d['persona_dpi']) === '') {
            $stmtDpi = $db->prepare('SELECT dpi FROM ' . ($d['tipo'] === 'soporte' ? 'soporte_tecnico' : 'instructores') . ' WHERE id = ?');
            $stmtDpi->execute([(int) $d['persona_id']]);
            $db->prepare('UPDATE liquidacion_detalle SET persona_dpi = ? WHERE id = ?')->execute([(string) $stmtDpi->fetchColumn(), (int) $d['id']]);
        }
        $quedan[$d['tipo'] . ':' . $d['persona_id'] . ':' . $d['planificacion_id']] = $d + ['tarifa_nueva' => $tarifa, 'pct_nuevo' => $pct];
    }

    // Agregar sesiones impartidas pendientes del mismo período
    if (!empty($datos['agregar'])) {
        $grupos = cengi_pago_por_liquidar($db, ['desde' => $liq['desde'], 'hasta' => $liq['hasta']]);
        $insertar = $db->prepare('INSERT INTO liquidacion_detalle (liquidacion_id, tipo, persona_id, persona_nombre, persona_codigo, planificacion_id, curso_nombre, sesiones, fechas, tarifa, neto, impuesto, total, observaciones,
                comprobante, impuesto_pct, persona_dpi, documento_numero)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $sinTarifa = [];
        foreach ($grupos as $g) {
            $clave = ($g['tipo'] === 'instructor' ? 'instructor' : 'soporte') . ':' . $g['persona_id'] . ':' . $g['plan_id'];
            $existente = $quedan[$clave] ?? null;
            $tarifa = $existente ? (float) $existente['tarifa_nueva'] : $g['tarifa'];
            if ($tarifa === null) {
                $sinTarifa[] = $g['persona'];
                continue;
            }
            $columna = $g['tipo'] === 'instructor' ? 'liquidacion_id' : 'liquidacion_soporte_id';
            $marcar = $db->prepare("UPDATE curso_sesiones SET $columna = ? WHERE $columna IS NULL AND estado = 'impartida' AND id IN (" . implode(',', array_fill(0, count($g['sesiones']), '?')) . ')');
            $marcar->execute(array_merge([$id], $g['sesiones']));
            if ($marcar->rowCount() !== count($g['sesiones'])) {
                throw new InvalidArgumentException('Algunas sesiones cambiaron mientras corregías la liquidación. Vuelve a intentarlo.');
            }
            if ($existente) {
                $fechas = array_merge(array_filter(explode(',', (string) $existente['fechas'])), $g['fechas']);
                sort($fechas);
                $n = (int) $existente['sesiones'] + count($g['sesiones']);
                [$neto, $impuesto, $total] = cengi_pago_calcular(['sesiones' => array_fill(0, $n, 0)], $tarifa, (float) $existente['pct_nuevo'] / 100);
                $db->prepare('UPDATE liquidacion_detalle SET sesiones = ?, fechas = ?, neto = ?, impuesto = ?, total = ? WHERE id = ?')
                    ->execute([$n, implode(',', $fechas), $neto, $impuesto, $total, (int) $existente['id']]);
            } else {
                $pct = cengi_pago_pct($db, $g['comprobante']);
                [$neto, $impuesto, $total] = cengi_pago_calcular($g, $tarifa, $pct / 100);
                $insertar->execute([$id, $g['tipo'], $g['persona_id'], $g['persona'], $g['codigo'], $g['plan_id'], $g['curso'], count($g['sesiones']),
                    implode(',', $g['fechas']), $tarifa, $neto, $impuesto, $total, mb_substr((string) $g['obs'], 0, 500, 'UTF-8'),
                    $g['comprobante'], $pct, $g['dpi'], $g['comprobante'] === 'recibo' && !$esBorrador ? cengi_pago_siguiente_recibo($db) : '']);
            }
            $cambios[] = 'Se agregaron ' . count($g['sesiones']) . ' sesión(es) de ' . $g['persona'] . ' (' . $g['curso'] . ')';
        }
        if ($sinTarifa) {
            throw new InvalidArgumentException('Sin tarifa registrada: ' . cengi_pp_h(implode(', ', array_unique($sinTarifa))) . '. Regístrala en “Tarifas” antes de agregar sus sesiones.');
        }
    }

    $stmt = $db->prepare('SELECT COUNT(*), COALESCE(SUM(total), 0) FROM liquidacion_detalle WHERE liquidacion_id = ?');
    $stmt->execute([$id]);
    [$filas, $totalNuevo] = $stmt->fetch(PDO::FETCH_NUM);
    if ((int) $filas === 0) {
        throw new InvalidArgumentException('La liquidación quedaría sin filas. Si ya no aplica, anúlala.');
    }
    if (!$cambios) {
        throw new InvalidArgumentException('No hiciste cambios en la liquidación.');
    }
    if (abs((float) $totalNuevo - (float) $liq['total']) >= 0.005) {
        $cambios[] = 'Total: ' . cengi_pp_q($liq['total'], 2) . ' → ' . cengi_pp_q($totalNuevo, 2);
    }
    $reaprobar = $liq['estado'] === 'aprobada';
    if ($reaprobar) {
        $cambios[] = 'Vuelve a “Enviada”: requiere nueva aprobación de Administración';
    }
    if (!empty($liq['firmada_en'])) {
        $cambios[] = 'Se retiró la firma de ' . ($liq['firma_nombre'] ?: $liq['firmada_por']) . ': requiere nueva validación';
        $db->prepare("UPDATE liquidaciones SET firmada_en = NULL, firmada_por = '', firma_codigo = '', firma_imagen = NULL WHERE id = ?")->execute([$id]);
    }
    $db->prepare('UPDATE liquidaciones SET numero = ?, destino = ?, nota = ?, total = ?' . ($reaprobar ? ", estado = 'enviada', aprobada_en = NULL, aprobada_por = ''" : '') . ' WHERE id = ?')
        ->execute([$numero, $destino, $nota, (float) $totalNuevo, $id]);

    if ($esBorrador) {
        if ($liq['estado'] !== 'borrador') {
            // Editada en revisión o ya aprobada por el Jefe: vuelve a borrador (la aprobación se retiró arriba).
            $db->prepare("UPDATE liquidaciones SET estado = 'borrador' WHERE id = ?")->execute([$id]);
        }
        return false;
    }
    $stmt = $db->prepare('SELECT COALESCE(MAX(version), 0) + 1 FROM liquidacion_versiones WHERE liquidacion_id = ?');
    $stmt->execute([$id]);
    $db->prepare('INSERT INTO liquidacion_versiones (liquidacion_id, version, motivo, cambios, datos, total_antes, total_despues, usuario) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$id, (int) $stmt->fetchColumn(), $motivo, implode("\n", $cambios), json_encode($antes, JSON_UNESCAPED_UNICODE), (float) $liq['total'], (float) $totalNuevo, $usuario]);
    return $reaprobar;
}

/** Días que abarca el período (para rechazar rangos mal escritos, p. ej. 2026 → 2028). */
function cengi_pago_dias(array $periodo)
{
    return (int) ((strtotime($periodo['hasta']) - strtotime($periodo['desde'])) / 86400) + 1;
}

/**
 * Aviso por correo a cada instructor / soporte de una liquidación pagada, con el detalle de
 * sus sesiones. Queda en Comunicaciones (categoría "Pago a instructores", referencia liq:<id>).
 * Devuelve [enviados, sin correo (nombres)].
 */
function cengi_pago_avisar(PDO $db, $liqId)
{
    $stmt = $db->prepare('SELECT * FROM liquidaciones WHERE id = ?');
    $stmt->execute([(int) $liqId]);
    $liq = $stmt->fetch(PDO::FETCH_ASSOC);
    $stmt = $db->prepare("SELECT d.*, CASE WHEN d.tipo = 'soporte' THEN st.correo ELSE i.correo END AS correo
        FROM liquidacion_detalle d
        LEFT JOIN instructores i ON d.tipo = 'instructor' AND i.id = d.persona_id
        LEFT JOIN soporte_tecnico st ON d.tipo = 'soporte' AND st.id = d.persona_id
        WHERE d.liquidacion_id = ? ORDER BY d.persona_nombre, d.curso_nombre");
    $stmt->execute([(int) $liqId]);
    $personas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $personas[$d['tipo'] . ':' . $d['persona_id']][] = $d;
    }
    $enviados = 0;
    $sinCorreo = [];
    foreach ($personas as $filas) {
        $p = $filas[0];
        if (!filter_var((string) $p['correo'], FILTER_VALIDATE_EMAIL)) {
            $sinCorreo[] = $p['persona_nombre'];
            continue;
        }
        $lineas = array_map(static function ($d) {
            return '• ' . $d['curso_nombre'] . ': ' . (int) $d['sesiones'] . ' sesión(es) × ' . cengi_pp_q($d['tarifa'], 2) . ' = ' . cengi_pp_q($d['neto'], 2)
                . "\n  Fechas: " . implode(', ', array_map('cengi_pp_fecha_corta', array_filter(explode(',', (string) $d['fechas']))));
        }, $filas);
        $neto = array_sum(array_column($filas, 'neto'));
        $impuesto = array_sum(array_column($filas, 'impuesto'));
        $pcts = implode(' / ', array_unique(array_map(static function ($d) {
            return rtrim(rtrim(number_format((float) $d['impuesto_pct'], 2, '.', ''), '0'), '.') . '% (' . mb_strtolower(CENGI_PP_COMPROBANTES[$d['comprobante']] ?? 'Factura', 'UTF-8') . ')';
        }, $filas)));
        $documentos = array_filter(array_map(static function ($d) {
            return $d['documento_numero'] !== '' ? (CENGI_PP_COMPROBANTES[$d['comprobante']] ?? 'Documento') . ' ' . $d['documento_numero'] : '';
        }, $filas));
        $mensaje = "Hola {nombre}:\n\nTe informamos que se registró el pago de tus sesiones impartidas"
            . ' (liquidación ' . $liq['numero'] . ', período del ' . cengi_pp_fecha($liq['desde']) . ' al ' . cengi_pp_fecha($liq['hasta']) . ").\n\n"
            . implode("\n", $lineas) . "\n\n• Total neto: " . cengi_pp_q($neto, 2) . "\n• Impuesto " . $pcts . ': ' . cengi_pp_q($impuesto, 2)
            . "\n• Total: " . cengi_pp_q($neto + $impuesto, 2) . ($documentos ? "\n• " . implode(', ', array_unique($documentos)) : '')
            . "\n\nForma de pago: " . $liq['pago_forma'] . ($liq['pago_referencia'] !== '' ? ' · Referencia ' . $liq['pago_referencia'] : '') . ' · Fecha ' . cengi_pp_fecha($liq['pago_fecha'])
            . "\n\nSi tienes alguna consulta sobre este pago, responde este correo.";
        [, $ok] = cengi_pp_enviar_comunicacion($db, [
            'categoria' => 'Pago a instructores', 'origen' => 'sistema', 'referencia' => 'liq:' . (int) $liqId,
            'planificacion_id' => (int) $p['planificacion_id'] ?: null,
            'asunto' => 'Pago de sesiones impartidas · ' . $liq['numero'], 'mensaje' => $mensaje,
        ], [['nombre' => $p['persona_nombre'], 'correo' => $p['correo']]]);
        $enviados += $ok > 0 ? 1 : 0;
    }
    return [$enviados, $sinCorreo];
}

/** [neto, impuesto, total]; $pct en fracción (0.05 = 5%), según el comprobante de la fila. */
function cengi_pago_calcular(array $grupo, $tarifa, $pct = CENGI_PP_IMPUESTO)
{
    $neto = round($tarifa * count($grupo['sesiones']), 2);
    $impuesto = round($neto * $pct, 2);
    return [$neto, $impuesto, $neto + $impuesto];
}

/** % (número, p. ej. 5.00) que se guarda en la fila según el comprobante. */
function cengi_pago_pct(PDO $db, $comprobante)
{
    return round(cengi_pp_impuesto($db, $comprobante) * 100, 2);
}

function cengi_pago_numero_libre(PDO $db, $base)
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM liquidaciones WHERE numero = ? AND estado <> 'anulada'");
    $numero = $base;
    for ($k = 2; ; $k++) {
        $stmt->execute([$numero]);
        if ((int) $stmt->fetchColumn() === 0) {
            return $numero;
        }
        $numero = $base . '-' . $k;
    }
}

// ---------------------------------------------------------------- recibos: enviar por correo y registrar el firmado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['accion'] ?? '', ['recibo_enviar', 'recibo_subir', 'recibo_enviar_todos', 'recibo_validar', 'factura_requerir', 'factura_requerir_todos'], true)) {
    $liqId = (int) ($_POST['id'] ?? 0);
    $fila = (int) ($_POST['fila'] ?? 0);
    // Desde el repositorio de recibos se regresa al repositorio (con su filtro); desde la liquidación, a la liquidación.
    $volver = ($_POST['volver'] ?? '') === 'recibos' ? array_filter(['tab' => 'recibos', 'rec' => (string) ($_POST['rec'] ?? '')])
        : (($_POST['volver'] ?? '') === 'facturas' ? array_filter(['tab' => 'facturas', 'fac' => (string) ($_POST['fac'] ?? '')]) : ['tab' => 'liquidaciones', 'ver' => $liqId]);
    try {
        if ($_POST['accion'] === 'recibo_validar') {
            // El Jefe de Transferencia valida cada recibo firmado (las facturas no pasan por él).
            if (!$esFirmante) {
                throw new InvalidArgumentException('Tu usuario no tiene el permiso “Firmar liquidaciones” para validar recibos. Asígnalo en Roles y permisos.');
            }
            if (trim((string) $cfgPago['firma']) === '') {
                throw new InvalidArgumentException('Primero carga la firma en “Configuración”.');
            }
            if (empty($_POST['revisado'])) {
                throw new InvalidArgumentException('Confirma que revisaste los recibos firmados.');
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['recibos'] ?? [])))));
            if (!$ids) {
                throw new InvalidArgumentException('Marca al menos un recibo firmado para validar.');
            }
            $stmt = conectar_usuarios_menu_pdo()->prepare('SELECT contrasena FROM usuarios WHERE id = ?');
            $stmt->execute([(int) cengi_usuario_actual_id()]);
            if (!password_verify((string) ($_POST['contrasena'] ?? ''), (string) $stmt->fetchColumn())) {
                throw new InvalidArgumentException('La contraseña no es correcta: no se validó ningún recibo.');
            }
            $stmt = $db->prepare("SELECT d.*, l.estado AS liq_estado FROM liquidacion_detalle d INNER JOIN liquidaciones l ON l.id = d.liquidacion_id
                WHERE d.comprobante = 'recibo' AND d.id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')');
            $stmt->execute($ids);
            $estadosV = cengi_pr_estados($db, $ids);
            $usuarioV = cengi_pp_usuario_nombre();
            $validados = 0;
            $omitidos = [];
            $db->beginTransaction();
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
                $er = $estadosV[(int) $d['id']] ?? null;
                if (in_array($d['liq_estado'], ['anulada', 'pagada'], true) || cengi_pr_estado($d, $er) !== 'firmado') {
                    $omitidos[] = $d['persona_nombre'];
                    continue;
                }
                cengi_pr_validar($db, $d, $er, $cfgPago, $usuarioV);
                $validados++;
            }
            $db->commit();
            cengi_pp_redirigir('pago_instructores.php', $volver + ['msg' => 'recibo_validado', 'n' => $validados, 'err' => implode(', ', array_unique($omitidos))]);
        }
        if (!$esAdmin) {
            throw new InvalidArgumentException('Solo Administración envía y registra los recibos.');
        }
        if (in_array($_POST['accion'], ['factura_requerir', 'factura_requerir_todos'], true)) {
            // Requerimiento de factura: a una persona, o a todas las que aún no lo recibieron.
            $sqlReq = "SELECT d.* FROM liquidacion_detalle d INNER JOIN liquidaciones l ON l.id = d.liquidacion_id
                WHERE d.comprobante = 'factura' AND l.estado IN ('enviada', 'aprobada') AND d.documento_numero = ''"
                . ($_POST['accion'] === 'factura_requerir' ? ' AND d.id = ' . (int) $fila
                    : " AND NOT EXISTS (SELECT 1 FROM liquidacion_recibo_historial h WHERE h.detalle_id = d.id AND h.accion = 'requerimiento')");
            $enviadosF = 0;
            $erroresF = [];
            $liqCacheF = [];
            foreach ($db->query($sqlReq)->fetchAll(PDO::FETCH_ASSOC) as $dF) {
                $lidF = (int) $dF['liquidacion_id'];
                if (!isset($liqCacheF[$lidF])) {
                    $stmt = $db->prepare('SELECT * FROM liquidaciones WHERE id = ?');
                    $stmt->execute([$lidF]);
                    $liqCacheF[$lidF] = $stmt->fetch(PDO::FETCH_ASSOC);
                }
                try {
                    $motivoF = cengi_pr_enviar_requerimiento($db, $dF, $liqCacheF[$lidF], $cfgPago);
                } catch (Throwable $e) {
                    error_log('Requerimiento de factura ' . $dF['id'] . ': ' . $e->getMessage());
                    $motivoF = 'error al enviar';
                }
                if ($motivoF === '') {
                    $enviadosF++;
                } else {
                    $erroresF[] = $dF['persona_nombre'] . ' (' . $motivoF . ')';
                }
            }
            cengi_pp_redirigir('pago_instructores.php', $volver + ['msg' => 'factura_req', 'env' => $enviadosF, 'err' => implode('; ', array_unique($erroresF))]);
        }
        if ($_POST['accion'] === 'recibo_enviar_todos') {
            // Todos los recibos sin enviar (o con monto cambiado) de las liquidaciones vigentes.
            $pend = $db->query("SELECT d.*, r.enviado_en AS r_enviado, r.firmado_en AS r_firmado, r.monto AS r_monto FROM liquidacion_detalle d
                INNER JOIN liquidaciones l ON l.id = d.liquidacion_id LEFT JOIN liquidacion_recibos r ON r.detalle_id = d.id
                WHERE d.comprobante = 'recibo' AND l.estado IN ('enviada', 'aprobada', 'pagada') ORDER BY d.liquidacion_id")->fetchAll(PDO::FETCH_ASSOC);
            $enviados = 0;
            $errores = [];
            $liqCache = [];
            foreach ($pend as $d) {
                $er = $d['r_enviado'] || $d['r_firmado'] ? ['enviado_en' => $d['r_enviado'], 'firmado_en' => $d['r_firmado'], 'monto' => $d['r_monto']] : null;
                if (!in_array(cengi_pr_estado($d, $er), ['sin_enviar', 'monto_cambio'], true)) {
                    continue;
                }
                $lid = (int) $d['liquidacion_id'];
                if (!isset($liqCache[$lid])) {
                    $stmt = $db->prepare('SELECT * FROM liquidaciones WHERE id = ?');
                    $stmt->execute([$lid]);
                    $l = $stmt->fetch(PDO::FETCH_ASSOC);
                    $stmt = $db->prepare('SELECT * FROM liquidacion_detalle WHERE liquidacion_id = ?');
                    $stmt->execute([$lid]);
                    $liqCache[$lid] = [$l, !empty($l['firmada_en']) && hash_equals((string) $l['firma_codigo'], cengi_pago_codigo_firma($db, $l, $stmt->fetchAll(PDO::FETCH_ASSOC), $l['firmada_en'], $l['firmada_por']))];
                }
                [$l, $fv] = $liqCache[$lid];
                try {
                    $motivo = cengi_pr_enviar($db, $d, $l, $cfgPago, $fv, $l['pago_fecha'] ?: $hoy);
                } catch (Throwable $e) {
                    error_log('Recibo ' . $d['documento_numero'] . ': ' . $e->getMessage());
                    $motivo = 'error al enviar';
                }
                if ($motivo === '') {
                    $enviados++;
                } else {
                    $errores[] = $d['persona_nombre'] . ' (' . $motivo . ')';
                }
            }
            cengi_pp_redirigir('pago_instructores.php', $volver + ['msg' => 'recibo_env', 'env' => $enviados, 'err' => implode('; ', array_unique($errores))]);
        }
        $stmt = $db->prepare('SELECT * FROM liquidaciones WHERE id = ?');
        $stmt->execute([$liqId]);
        $liqR = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$liqR || in_array($liqR['estado'], ['anulada', 'borrador', 'revision', 'autorizada'], true)) {
            throw new InvalidArgumentException('La liquidación no existe, está anulada o aún es un borrador sin enviar.');
        }
        $stmt = $db->prepare("SELECT * FROM liquidacion_detalle WHERE liquidacion_id = ? ORDER BY tipo = 'soporte', persona_nombre, curso_nombre");
        $stmt->execute([$liqId]);
        $detR = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $firmaR = !empty($liqR['firmada_en']) && hash_equals((string) $liqR['firma_codigo'], cengi_pago_codigo_firma($db, $liqR, $detR, $liqR['firmada_en'], $liqR['firmada_por']));
        $filasR = array_values(array_filter($detR, static function ($d) use ($fila) {
            return $d['comprobante'] === 'recibo' && (!$fila || (int) $d['id'] === $fila);
        }));
        if (!$filasR) {
            throw new InvalidArgumentException('No hay recibos en esa liquidación.');
        }
        if ($_POST['accion'] === 'recibo_subir') {
            cengi_pr_guardar_firmado($db, $filasR[0], $_FILES['firmado'] ?? [], 'administracion');
            cengi_pp_redirigir('pago_instructores.php', $volver + ['msg' => 'recibo_firmado']);
        }
        $enviados = 0;
        $errores = [];
        // Envío de todos: se omiten los que ya regresaron firmados por el mismo monto.
        $estadosR = $fila ? [] : cengi_pr_estados($db, array_column($filasR, 'id'));
        foreach ($filasR as $d) {
            $er = $estadosR[(int) $d['id']] ?? null;
            if ($er && $er['firmado_en'] && abs((float) $er['monto'] - (float) $d['total']) < 0.005) {
                continue;
            }
            try {
                $motivo = cengi_pr_enviar($db, $d, $liqR, $cfgPago, $firmaR, $liqR['pago_fecha'] ?: $hoy);
            } catch (Throwable $e) {
                error_log('Recibo ' . $d['documento_numero'] . ': ' . $e->getMessage());
                $motivo = 'error al enviar';
            }
            if ($motivo === '') {
                $enviados++;
            } else {
                $errores[] = $d['persona_nombre'] . ' (' . $motivo . ')';
            }
        }
        cengi_pp_redirigir('pago_instructores.php', $volver + ['msg' => 'recibo_env', 'env' => $enviados, 'err' => implode('; ', array_unique($errores))]);
    } catch (InvalidArgumentException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $mensaje = cengi_pp_h($e->getMessage());
        $mensajeTipo = 'error';
        $_GET['ver'] = $liqId;
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Pago a instructores (recibos): ' . $e->getMessage());
        $mensaje = 'No fue posible completar la acción con los recibos.';
        $mensajeTipo = 'error';
        $_GET['ver'] = $liqId;
    }
}
// Archivo del expediente (PDF emitido o firmado anterior): solo Administración y el Jefe (llevan DPI).
if (isset($_GET['recibo_archivo'])) {
    $stmt = $db->prepare('SELECT h.*, d.documento_numero FROM liquidacion_recibo_historial h INNER JOIN liquidacion_detalle d ON d.id = h.detalle_id WHERE h.id = ?');
    $stmt->execute([(int) $_GET['recibo_archivo']]);
    $ha = $stmt->fetch(PDO::FETCH_ASSOC);
    $archivoHa = $ha && $puedeFirmar ? cengi_pr_archivo_firmado($ha['archivo_path']) : null;
    if (!$archivoHa) {
        http_response_code(404);
        exit('El archivo no está disponible.');
    }
    $ext = strtolower(pathinfo($archivoHa, PATHINFO_EXTENSION));
    header('Content-Type: ' . (array_search($ext, CENGI_PR_TIPOS, true) ?: 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . ($ha['accion'] === 'enviado' ? 'Recibo_emitido_' : 'Recibo_firmado_') . preg_replace('/[^A-Za-z0-9_-]+/', '_', $ha['documento_numero']) . '_' . (int) $ha['id'] . '.' . $ext . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($archivoHa);
    exit;
}
// Recibo firmado vigente: solo Administración y el firmante lo abren (lleva DPI).
if (isset($_GET['recibo_firmado'])) {
    $stmt = $db->prepare('SELECT r.*, d.documento_numero, d.persona_nombre FROM liquidacion_recibos r INNER JOIN liquidacion_detalle d ON d.id = r.detalle_id WHERE r.detalle_id = ?');
    $stmt->execute([(int) $_GET['recibo_firmado']]);
    $rf = $stmt->fetch(PDO::FETCH_ASSOC);
    $archivoRf = $rf ? cengi_pr_archivo_firmado($rf['firmado_path']) : null;
    if (!$archivoRf) {
        http_response_code(404);
        exit('El recibo firmado no está disponible.');
    }
    $ext = strtolower(pathinfo($archivoRf, PATHINFO_EXTENSION));
    header('Content-Type: ' . (array_search($ext, CENGI_PR_TIPOS, true) ?: 'application/octet-stream'));
    header('Content-Disposition: inline; filename="Recibo_firmado_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $rf['documento_numero']) . '.' . $ext . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($archivoRf);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($_POST['accion'] ?? '', ['recibo_enviar', 'recibo_subir', 'recibo_enviar_todos', 'recibo_validar', 'factura_requerir', 'factura_requerir_todos'], true)) {
    $accion = (string) ($_POST['accion'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    $usuario = cengi_pp_usuario_nombre();
    $extra = [];
    $avisarLiq = 0;
    $enviarRecibosLiq = 0;
    $enviarReqLiq = 0;
    try {
        // El Jefe de Transferencia (sin rol de administrador) solo firma y actualiza su firma.
        if (!$esAdmin && !in_array($accion, ['firmar', 'devolver_jefe', 'config'], true)) {
            throw new InvalidArgumentException('Tu usuario solo puede validar y firmar liquidaciones.');
        }
        $db->beginTransaction();
        $liq = null;
        if ($id) {
            $stmt = $db->prepare('SELECT * FROM liquidaciones WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $liq = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$liq) {
                throw new InvalidArgumentException('La liquidación ya no existe.');
            }
        }

        if ($accion === 'generar') {
            $periodo = cengi_pago_periodo($_POST);
            if (cengi_pago_dias($periodo) > CENGI_PAGO_MAX_DIAS) {
                throw new InvalidArgumentException('El rango de fechas supera un año (' . cengi_pp_h($periodo['label']) . '). Revisa las fechas.');
            }
            $grupos = cengi_pago_por_liquidar($db, $periodo, (string) ($_POST['persona'] ?? ''));
            $claseGen = ($_POST['clase'] ?? '') === 'soporte' ? 'soporte' : 'instructor';
            $seleccion = array_filter(array_intersect_key($grupos, array_flip((array) ($_POST['sel'] ?? []))), static function ($g) use ($claseGen) {
                return $g['tipo'] === $claseGen;
            });
            if (!$seleccion) {
                throw new InvalidArgumentException('Marca al menos una fila para liquidar.');
            }
            $numero = mb_substr(trim((string) ($_POST['numero'] ?? '')), 0, 40, 'UTF-8');
            if ($numero === '') {
                throw new InvalidArgumentException('Escribe el número de liquidación.');
            }
            $duplicado = $db->prepare("SELECT COUNT(*) FROM liquidaciones WHERE numero = ? AND estado <> 'anulada'");
            $duplicado->execute([$numero]);
            if ((int) $duplicado->fetchColumn() > 0) {
                throw new InvalidArgumentException('Ya existe una liquidación con el número ' . cengi_pp_h($numero) . '.');
            }
            $enviarAhora = ($_POST['modo'] ?? '') === 'enviar'; // si no, queda como borrador editable
            $db->prepare('INSERT INTO liquidaciones (numero, desde, hasta, destino, nota, estado, creado_por, creado_por_nombre) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
                $numero, $periodo['desde'], $periodo['hasta'], mb_substr(trim((string) ($_POST['destino'] ?? '')), 0, 255, 'UTF-8') ?: 'Administración',
                trim((string) ($_POST['nota'] ?? '')), $enviarAhora ? 'revision' : 'borrador', cengi_usuario_actual_id() ?: null, $usuario,
            ]);
            $liqId = (int) $db->lastInsertId();
            $stmtDet = $db->prepare('INSERT INTO liquidacion_detalle (liquidacion_id, tipo, persona_id, persona_nombre, persona_codigo, planificacion_id, curso_nombre, sesiones, fechas, tarifa, neto, impuesto, total, observaciones,
                    comprobante, impuesto_pct, persona_dpi, documento_numero)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $total = 0;
            foreach ($seleccion as $clave => $g) {
                $tarifaTexto = str_replace(',', '', (string) ($_POST['tarifa'][$clave] ?? $g['tarifa']));
                if (!is_numeric($tarifaTexto) || (float) $tarifaTexto < 0) {
                    throw new InvalidArgumentException('Revisa la tarifa de ' . cengi_pp_h($g['persona']) . '.');
                }
                $tarifa = round((float) $tarifaTexto, 2);
                $comprobante = ($_POST['comp'][$clave] ?? $g['comprobante']) === 'recibo' ? 'recibo' : 'factura';
                $pct = cengi_pago_pct($db, $comprobante);
                [$neto, $impuesto, $totalFila] = cengi_pago_calcular($g, $tarifa, $pct / 100);
                $stmtDet->execute([$liqId, $g['tipo'], $g['persona_id'], $g['persona'], $g['codigo'], $g['plan_id'], $g['curso'], count($g['sesiones']),
                    implode(',', $g['fechas']), $tarifa, $neto, $impuesto, $totalFila, mb_substr(trim((string) ($_POST['obs'][$clave] ?? '')), 0, 500, 'UTF-8'),
                    $comprobante, $pct, $g['dpi'], '']); // el n.° de recibo se asigna cuando el Jefe aprueba
                $columna = $g['tipo'] === 'instructor' ? 'liquidacion_id' : 'liquidacion_soporte_id';
                $marcar = $db->prepare("UPDATE curso_sesiones SET $columna = ? WHERE $columna IS NULL AND estado = 'impartida' AND id IN (" . implode(',', array_fill(0, count($g['sesiones']), '?')) . ')');
                $marcar->execute(array_merge([$liqId], $g['sesiones']));
                if ($marcar->rowCount() !== count($g['sesiones'])) {
                    throw new InvalidArgumentException('Algunas sesiones cambiaron mientras generabas la liquidación. Vuelve a intentarlo.');
                }
                $total += $totalFila;
            }
            $db->prepare('UPDATE liquidaciones SET total = ? WHERE id = ?')->execute([$total, $liqId]);
            $extra = ['tab' => 'liquidaciones', 'ver' => $liqId, 'msg' => $enviarAhora ? 'generada' : 'borrador'];
        } elseif ($accion === 'factura_registrar') {
            $filaF = (int) ($_POST['fila'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM liquidacion_detalle WHERE id = ? AND liquidacion_id = ? AND comprobante = 'factura'");
            $stmt->execute([$filaF, $id]);
            $dF = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$dF || in_array($liq['estado'], ['anulada'], true)) {
                throw new InvalidArgumentException('La fila no existe o la liquidación está anulada.');
            }
            $numFactura = mb_substr(trim((string) ($_POST['numero_factura'] ?? '')), 0, 40, 'UTF-8');
            if ($numFactura === '') {
                throw new InvalidArgumentException('Escribe la serie y el número de la factura.');
            }
            $rutaF = '';
            $nombreF = '';
            $archivoF = $_FILES['archivo_factura'] ?? null;
            if ($archivoF && ($archivoF['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $mimeF = $archivoF['error'] === UPLOAD_ERR_OK ? (new finfo(FILEINFO_MIME_TYPE))->file($archivoF['tmp_name']) : '';
                if (!isset(CENGI_PR_TIPOS[$mimeF]) || $archivoF['size'] > CENGI_PR_MAX) {
                    throw new InvalidArgumentException('La factura debe ser PDF, JPG o PNG de hasta 10 MB.');
                }
                $rutaF = cengi_guardar_archivo_subido($archivoF['tmp_name'], 'facturas', bin2hex(random_bytes(16)) . '.' . CENGI_PR_TIPOS[$mimeF]) ?: '';
                if ($rutaF === '') {
                    throw new InvalidArgumentException('No se pudo guardar el archivo de la factura.');
                }
                $nombreF = basename((string) $archivoF['name']);
            }
            $db->prepare('UPDATE liquidacion_detalle SET documento_numero = ? WHERE id = ?')->execute([$numFactura, $filaF]);
            cengi_pr_log($db, $filaF, 'factura', 'Factura ' . $numFactura . ($dF['documento_numero'] !== '' && $dF['documento_numero'] !== $numFactura ? ' (antes ' . $dF['documento_numero'] . ')' : ''), $rutaF, $nombreF, $dF['total'], $usuario);
            $extra = ($_POST['volver'] ?? '') === 'facturas' ? array_filter(['tab' => 'facturas', 'fac' => (string) ($_POST['fac'] ?? ''), 'msg' => 'factura_registrada']) : ['tab' => 'liquidaciones', 'ver' => $id, 'msg' => 'factura_registrada'];
        } elseif ($accion === 'neto_recibos') {
            $reaprobar = cengi_pago_corregir($db, $liq, ['motivo' => 'Recibos: se paga el valor neto, sin impuesto'], $usuario);
            $extra = ['tab' => 'liquidaciones', 'ver' => $id, 'msg' => $liq['estado'] === 'borrador' ? 'borrador_editado' : (in_array($liq['estado'], CENGI_PG_SIN_ENVIAR, true) ? 'borrador_reaprobar' : ($reaprobar ? 'corregida_reaprobar' : 'corregida'))];
        } elseif ($accion === 'aprobar') {
            if ($liq['estado'] !== 'enviada') {
                throw new InvalidArgumentException('Solo se aprueban liquidaciones enviadas.');
            }
            if (empty($liq['firmada_en'])) {
                throw new InvalidArgumentException('Falta la aprobación del Jefe de Transferencia para esta versión de la liquidación.');
            }
            $stmt = $db->prepare('SELECT * FROM liquidacion_detalle WHERE liquidacion_id = ?');
            $stmt->execute([$id]);
            $filasAprobar = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (array_filter($filasAprobar, static function ($d) { return $d['comprobante'] === 'recibo' && (float) $d['impuesto'] > 0; })) {
                throw new InvalidArgumentException('No se puede aprobar: hay recibos liquidados con impuesto. Con recibo se paga el neto: usa “Aplicar pago neto a los recibos”.');
            }
            $recResumen = cengi_pr_resumen($db, $filasAprobar, true);
            if ($recResumen['faltan']) {
                throw new InvalidArgumentException('No se puede aprobar: faltan recibos firmados (' . $recResumen['firmados'] . ' de ' . $recResumen['total'] . '): ' . cengi_pp_h(implode(', ', $recResumen['faltan'])) . '.');
            }
            if (!$recResumen['listos']) {
                throw new InvalidArgumentException('No se puede aprobar: el ' . cengi_pp_h($cfgPago['jefe_cargo']) . ' aún no valida los recibos de ' . cengi_pp_h(implode(', ', $recResumen['por_validar'])) . '.');
            }
            $db->prepare("UPDATE liquidaciones SET estado = 'aprobada', aprobada_en = NOW(), aprobada_por = ? WHERE id = ?")->execute([$usuario, $id]);
            $extra = ['tab' => 'liquidaciones', 'ver' => $id, 'msg' => 'aprobada'];
        } elseif ($accion === 'pagar') {
            $forma = mb_substr(trim((string) ($_POST['pago_forma'] ?? '')), 0, 60, 'UTF-8');
            $referencia = mb_substr(trim((string) ($_POST['pago_referencia'] ?? '')), 0, 120, 'UTF-8');
            $fecha = (string) ($_POST['pago_fecha'] ?? '');
            if ($liq['estado'] !== 'aprobada') {
                throw new InvalidArgumentException('Registra el pago cuando Administración haya aprobado la liquidación.');
            }
            if ($forma === '' || !cengi_pp_es_fecha($fecha)) {
                throw new InvalidArgumentException('Indica la forma y la fecha de pago.');
            }
            if ($fecha > $hoy) {
                throw new InvalidArgumentException('La fecha de pago no puede ser futura.');
            }
            $stmtFacPend = $db->prepare("SELECT id, persona_nombre, documento_numero FROM liquidacion_detalle WHERE liquidacion_id = ? AND comprobante = 'factura'");
            $stmtFacPend->execute([$id]);
            $sinFactura = [];
            foreach ($stmtFacPend->fetchAll(PDO::FETCH_ASSOC) as $fp) {
                if (trim((string) ($_POST['factura'][$fp['id']] ?? $fp['documento_numero'])) === '') {
                    $sinFactura[] = $fp['persona_nombre'];
                }
            }
            if ($sinFactura) {
                throw new InvalidArgumentException('Falta registrar la factura de: ' . cengi_pp_h(implode(', ', array_unique($sinFactura))) . '.');
            }
            $db->prepare("UPDATE liquidaciones SET estado = 'pagada', pago_forma = ?, pago_referencia = ?, pago_fecha = ?, pagada_en = NOW(), pagada_por = ? WHERE id = ?")
                ->execute([$forma, $referencia, $fecha, $usuario, $id]);
            $stmtRecPago = $db->prepare('SELECT id, total FROM liquidacion_detalle WHERE liquidacion_id = ?');
            $stmtRecPago->execute([$id]);
            foreach ($stmtRecPago->fetchAll(PDO::FETCH_ASSOC) as $rp) {
                cengi_pr_log($db, $rp['id'], 'pagado', trim($forma . ' ' . $referencia) . ' · ' . cengi_pp_fecha($fecha), '', '', $rp['total'], $usuario);
            }
            // N.° de factura de quienes cobran con factura (los recibos ya tienen su correlativo).
            $stmtFac = $db->prepare("UPDATE liquidacion_detalle SET documento_numero = ? WHERE id = ? AND liquidacion_id = ? AND comprobante = 'factura'");
            foreach ((array) ($_POST['factura'] ?? []) as $detId => $numFactura) {
                $stmtFac->execute([mb_substr(trim((string) $numFactura), 0, 40, 'UTF-8'), (int) $detId, $id]);
            }
            $extra = ['tab' => 'liquidaciones', 'ver' => $id, 'msg' => 'pagada'];
            $avisarLiq = !empty($_POST['avisar']) ? $id : 0;
        } elseif ($accion === 'marcar_impartidas') {
            // Sesiones pasadas que siguen "programadas" (mismas reglas que el calendario).
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['sesiones'] ?? [])))));
            if (!$ids) {
                throw new InvalidArgumentException('Marca al menos una sesión.');
            }
            $stmt = $db->prepare('SELECT * FROM curso_sesiones WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
            $stmt->execute($ids);
            $marcadas = 0;
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $sesion) {
                $plan = cengi_pp_plan($db, $sesion['planificacion_id']);
                if ($sesion['estado'] !== 'programada' || $sesion['fecha'] > $hoy || !$plan || $plan['estado'] === 'cancelado') {
                    continue;
                }
                $db->prepare("UPDATE curso_sesiones SET estado = 'impartida' WHERE id = ?")->execute([$sesion['id']]);
                cengi_pp_log($db, $plan, 'Sesión impartida', 'Sesión del ' . cengi_pp_fecha($sesion['fecha']) . ' — ' . $sesion['tema'], [
                    'antes' => 'programada', 'despues' => 'impartida', 'sesion_id' => (int) $sesion['id'], 'afecta' => false,
                ]);
                cengi_pp_asignar_modulos($db, $sesion['planificacion_id']);
                $marcadas++;
            }
            if (!$marcadas) {
                throw new InvalidArgumentException('Ninguna de las sesiones se pudo marcar (deben estar programadas, con fecha pasada y en un curso activo).');
            }
            $extra = ['msg' => 'impartidas'];
        } elseif ($accion === 'corregir') {
            $reaprobar = cengi_pago_corregir($db, $liq, [
                'numero' => $_POST['numero'] ?? $liq['numero'], 'destino' => $_POST['destino'] ?? $liq['destino'], 'nota' => $_POST['nota'] ?? $liq['nota'],
                'motivo' => $_POST['motivo'] ?? '', 'lineas' => (array) ($_POST['lineas'] ?? []), 'agregar' => !empty($_POST['agregar']),
            ], $usuario);
            $extra = ['tab' => 'liquidaciones', 'ver' => $id, 'msg' => $liq['estado'] === 'borrador' ? 'borrador_editado' : (in_array($liq['estado'], CENGI_PG_SIN_ENVIAR, true) ? 'borrador_reaprobar' : ($reaprobar ? 'corregida_reaprobar' : 'corregida'))];
        } elseif ($accion === 'solicitar_jefe') {
            // Capacitación pide la aprobación del Jefe de Transferencia.
            if ($liq['estado'] !== 'borrador') {
                throw new InvalidArgumentException('Solo se envía al Jefe una liquidación en borrador.');
            }
            $db->prepare("UPDATE liquidaciones SET estado = 'revision' WHERE id = ?")->execute([$id]);
            $extra = ['tab' => 'liquidaciones', 'ver' => $id, 'msg' => 'solicitada'];
        } elseif ($accion === 'devolver_jefe') {
            if (!$esFirmante) {
                throw new InvalidArgumentException('Tu usuario no tiene el permiso “Firmar liquidaciones”.');
            }
            if ($liq['estado'] !== 'revision') {
                throw new InvalidArgumentException('Solo se devuelve una liquidación pendiente de tu aprobación.');
            }
            $obs = mb_substr(trim((string) ($_POST['observacion'] ?? '')), 0, 500, 'UTF-8');
            if ($obs === '') {
                throw new InvalidArgumentException('Escribe la observación: qué debe corregir Capacitación.');
            }
            $db->prepare("UPDATE liquidaciones SET estado = 'borrador', observacion_jefe = ? WHERE id = ?")->execute([$obs . ' — ' . $usuario . ', ' . date('d/m/Y H:i'), $id]);
            $extra = ['tab' => 'liquidaciones', 'ver' => $id, 'msg' => 'devuelta'];
        } elseif ($accion === 'enviar_liq') {
            // El borrador pasa a Administración: los recibos se numeran ahora y, desde aquí, cada cambio es una nueva versión.
            if ($liq['estado'] !== 'autorizada') {
                throw new InvalidArgumentException($liq['estado'] === 'enviada' ? 'Esta liquidación ya fue enviada a Administración.' : 'Primero debe aprobarla el Jefe de Transferencia; después se habilita el envío a Administración.');
            }
            $stmt = $db->prepare("SELECT id FROM liquidacion_detalle WHERE liquidacion_id = ? AND comprobante = 'recibo' AND documento_numero = '' ORDER BY tipo = 'soporte', persona_nombre, curso_nombre");
            $stmt->execute([$id]);
            $numerar = $db->prepare('UPDATE liquidacion_detalle SET documento_numero = ? WHERE id = ?');
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $filaId) {
                $numerar->execute([cengi_pago_siguiente_recibo($db), $filaId]);
            }
            $db->prepare("UPDATE liquidaciones SET estado = 'enviada', creado = NOW() WHERE id = ?")->execute([$id]);
            $extra = ['tab' => 'liquidaciones', 'ver' => $id, 'msg' => 'liq_enviada'];
            $enviarRecibosLiq = !empty($_POST['enviar_recibos']) ? $id : 0;
            $enviarReqLiq = !empty($_POST['enviar_requerimientos']) ? $id : 0;
        } elseif ($accion === 'eliminar_borrador') {
            if (!in_array($liq['estado'], CENGI_PG_SIN_ENVIAR, true)) {
                throw new InvalidArgumentException('Solo se elimina una liquidación que aún no se ha enviado. Si ya se envió, anúlala.');
            }
            $db->prepare('UPDATE curso_sesiones SET liquidacion_id = NULL WHERE liquidacion_id = ?')->execute([$id]);
            $db->prepare('UPDATE curso_sesiones SET liquidacion_soporte_id = NULL WHERE liquidacion_soporte_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM liquidacion_detalle WHERE liquidacion_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM liquidaciones WHERE id = ?')->execute([$id]);
            $extra = ['tab' => 'liquidaciones', 'msg' => 'borrador_eliminado'];
        } elseif ($accion === 'anular') {
            $motivo = mb_substr(trim((string) ($_POST['motivo'] ?? '')), 0, 500, 'UTF-8');
            if (!in_array($liq['estado'], ['enviada', 'aprobada'], true)) {
                throw new InvalidArgumentException('Solo se anulan liquidaciones enviadas o aprobadas.');
            }
            if ($motivo === '') {
                throw new InvalidArgumentException('Escribe el motivo de la anulación.');
            }
            $db->prepare('UPDATE curso_sesiones SET liquidacion_id = NULL WHERE liquidacion_id = ?')->execute([$id]);
            $db->prepare('UPDATE curso_sesiones SET liquidacion_soporte_id = NULL WHERE liquidacion_soporte_id = ?')->execute([$id]);
            $db->prepare("UPDATE liquidaciones SET estado = 'anulada', anulada_en = NOW(), anulada_por = ?, motivo_anulacion = ? WHERE id = ?")->execute([$usuario, $motivo, $id]);
            $extra = ['tab' => 'liquidaciones', 'ver' => $id, 'msg' => 'anulada'];
        } elseif ($accion === 'firmar') {
            // Validación del Jefe de Transferencia de Tecnología: con su contraseña.
            if (!$esFirmante) {
                throw new InvalidArgumentException('Tu usuario no tiene el permiso “Firmar liquidaciones”. Asígnalo en Roles y permisos.');
            }
            if (!in_array($liq['estado'], ['revision', 'enviada'], true)) {
                throw new InvalidArgumentException('Solo se aprueban liquidaciones enviadas al Jefe (o corregidas después de enviarse a Administración).');
            }
            if (!empty($liq['firmada_en'])) {
                throw new InvalidArgumentException('Esta liquidación ya está aprobada por el Jefe.');
            }
            if (trim((string) $cfgPago['firma']) === '') {
                throw new InvalidArgumentException('Primero carga la firma en “Configuración” (Firma del ' . cengi_pp_h($cfgPago['jefe_cargo']) . ').');
            }
            if (empty($_POST['revisado'])) {
                throw new InvalidArgumentException('Confirma que revisaste el detalle de la liquidación.');
            }
            $stmt = conectar_usuarios_menu_pdo()->prepare('SELECT contrasena FROM usuarios WHERE id = ?');
            $stmt->execute([(int) cengi_usuario_actual_id()]);
            if (!password_verify((string) ($_POST['contrasena'] ?? ''), (string) $stmt->fetchColumn())) {
                throw new InvalidArgumentException('La contraseña no es correcta: la liquidación no se aprobó.');
            }
            // Los recibos se numeran al aprobarse: el número forma parte del código de la firma.
            $stmt = $db->prepare("SELECT id FROM liquidacion_detalle WHERE liquidacion_id = ? AND comprobante = 'recibo' AND documento_numero = '' ORDER BY tipo = 'soporte', persona_nombre, curso_nombre");
            $stmt->execute([$id]);
            $numerar = $db->prepare('UPDATE liquidacion_detalle SET documento_numero = ? WHERE id = ?');
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $filaId) {
                $numerar->execute([cengi_pago_siguiente_recibo($db), $filaId]);
            }
            $firmadaEn = date('Y-m-d H:i:s');
            $stmt = $db->prepare('SELECT * FROM liquidacion_detalle WHERE liquidacion_id = ?');
            $stmt->execute([$id]);
            $codigo = cengi_pago_codigo_firma($db, $liq, $stmt->fetchAll(PDO::FETCH_ASSOC), $firmadaEn, $usuario);
            $db->prepare('UPDATE liquidaciones SET firmada_en = ?, firmada_por = ?, firma_codigo = ?, firma_imagen = ?, firma_nombre = ?, firma_cargo = ? WHERE id = ?')
                ->execute([$firmadaEn, $usuario, $codigo, $cfgPago['firma'], $cfgPago['jefe_nombre'] ?: $usuario, $cfgPago['jefe_cargo'], $id]);
            if ($liq['estado'] === 'revision') {
                $db->prepare("UPDATE liquidaciones SET estado = 'autorizada', observacion_jefe = '' WHERE id = ?")->execute([$id]);
            }
            $extra = ['tab' => 'liquidaciones', 'ver' => $id, 'msg' => 'firmada'];
        } elseif ($accion === 'config') {
            $nuevo = $cfgPago;
            if ($esAdmin) {
                $_POST['pct_recibo'] = 0; // con recibo se paga el neto
                foreach (['pct_factura', 'pct_recibo'] as $campo) {
                    $valor = str_replace(',', '.', trim((string) ($_POST[$campo] ?? '')));
                    if (!is_numeric($valor) || (float) $valor < 0 || (float) $valor > 100) {
                        throw new InvalidArgumentException('El porcentaje de impuesto debe estar entre 0 y 100.');
                    }
                    $nuevo[$campo] = round((float) $valor, 2);
                }
                $nuevo['recibi_de'] = mb_substr(trim((string) ($_POST['recibi_de'] ?? '')), 0, 120, 'UTF-8') ?: 'CENGICAÑA';
                $nuevo['lugar'] = mb_substr(trim((string) ($_POST['lugar'] ?? '')), 0, 120, 'UTF-8');
                $nuevo['requiere_firma'] = !empty($_POST['requiere_firma']);
            }
            $nuevo['jefe_nombre'] = mb_substr(trim((string) ($_POST['jefe_nombre'] ?? '')), 0, 160, 'UTF-8');
            $nuevo['jefe_cargo'] = mb_substr(trim((string) ($_POST['jefe_cargo'] ?? '')), 0, 160, 'UTF-8') ?: 'Jefe de Transferencia de Tecnología';
            // Firma: dibujada en pantalla (PNG en base64) o subida como imagen; se guarda en la configuración, no en una carpeta pública.
            $firmaNueva = trim((string) ($_POST['firma_data'] ?? ''));
            if (!empty($_FILES['firma_archivo']['tmp_name']) && is_uploaded_file($_FILES['firma_archivo']['tmp_name'])) {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['firma_archivo']['tmp_name']);
                if (!in_array($mime, ['image/png', 'image/jpeg'], true) || $_FILES['firma_archivo']['size'] > 600 * 1024) {
                    throw new InvalidArgumentException('La firma debe ser una imagen PNG o JPG de hasta 600 KB.');
                }
                $firmaNueva = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($_FILES['firma_archivo']['tmp_name']));
            }
            if ($firmaNueva !== '') {
                if (!preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $firmaNueva) || strlen($firmaNueva) > 900 * 1024) {
                    throw new InvalidArgumentException('La imagen de la firma no es válida.');
                }
                $nuevo['firma'] = $firmaNueva;
            } elseif (!empty($_POST['quitar_firma'])) {
                $nuevo['firma'] = '';
            }
            cengi_pago_guardar_config($db, $nuevo);
            $extra = ['msg' => 'config'];
        } elseif ($accion === 'tarifas') {
            // El código de instructor se edita solo en su ficha; aquí, tarifa, DPI y comprobante.
            $stmt = $db->prepare('UPDATE instructores SET tarifa_sesion = ?, dpi = ?, comprobante = ? WHERE id = ?');
            $stmtSop = $db->prepare('UPDATE soporte_tecnico SET tarifa = ?, dpi = ?, comprobante = ? WHERE id = ?');
            foreach (['instr' => $stmt, 'sop' => $stmtSop] as $grupo => $sentencia) {
                foreach ((array) ($_POST[$grupo] ?? []) as $personaId => $datos) {
                    $tarifa = str_replace(',', '', trim((string) ($datos['tarifa'] ?? '')));
                    if ($tarifa !== '' && (!is_numeric($tarifa) || (float) $tarifa < 0)) {
                        throw new InvalidArgumentException('Las tarifas deben ser 0 o mayores.');
                    }
                    $dpi = preg_replace('/\D/', '', (string) ($datos['dpi'] ?? ''));
                    if ($dpi !== '' && strlen($dpi) !== 13) {
                        throw new InvalidArgumentException('El DPI debe tener 13 dígitos (' . cengi_pp_h($datos['dpi']) . ').');
                    }
                    $sentencia->execute([$tarifa !== '' ? round((float) $tarifa, 2) : ($grupo === 'sop' ? 0 : null), $dpi,
                        ($datos['comprobante'] ?? '') === 'recibo' ? 'recibo' : 'factura', (int) $personaId]);
                }
            }
            $extra = ['msg' => 'tarifas'];
        } else {
            throw new InvalidArgumentException('Acción no válida.');
        }
        $db->commit();
        if ($enviarRecibosLiq) {
            // Recibos de la liquidación recién generada: cada persona recibe su PDF y el enlace para devolverlo firmado.
            $enviadosR = 0;
            $erroresR = [];
            $stmt = $db->prepare('SELECT * FROM liquidaciones WHERE id = ?');
            $stmt->execute([$enviarRecibosLiq]);
            $liqR = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt = $db->prepare("SELECT * FROM liquidacion_detalle WHERE liquidacion_id = ? AND comprobante = 'recibo'");
            $stmt->execute([$enviarRecibosLiq]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $dR) {
                try {
                    $motivoR = cengi_pr_enviar($db, $dR, $liqR, $cfgPago, false, $hoy);
                } catch (Throwable $e) {
                    error_log('Recibo ' . $dR['documento_numero'] . ': ' . $e->getMessage());
                    $motivoR = 'error al enviar';
                }
                if ($motivoR === '') {
                    $enviadosR++;
                } else {
                    $erroresR[] = $dR['persona_nombre'] . ' (' . $motivoR . ')';
                }
            }
            $extra['env'] = $enviadosR;
            $extra['err'] = implode('; ', array_unique($erroresR));
        }
        if ($enviarReqLiq) {
            $fenv = 0;
            $stmt = $db->prepare('SELECT * FROM liquidaciones WHERE id = ?');
            $stmt->execute([$enviarReqLiq]);
            $liqF = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt = $db->prepare("SELECT * FROM liquidacion_detalle WHERE liquidacion_id = ? AND comprobante = 'factura'");
            $stmt->execute([$enviarReqLiq]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $dF) {
                try {
                    $fenv += cengi_pr_enviar_requerimiento($db, $dF, $liqF, $cfgPago) === '' ? 1 : 0;
                } catch (Throwable $e) {
                    error_log('Requerimiento de factura ' . $dF['id'] . ': ' . $e->getMessage());
                }
            }
            $extra['fenv'] = $fenv;
        }
        if ($avisarLiq) {
            // Después del commit: un fallo de correo no deshace el pago (queda en Comunicaciones).
            try {
                [$enviados, $sinCorreo] = cengi_pago_avisar($db, $avisarLiq);
                $extra['av'] = $enviados;
                $extra['sc'] = count($sinCorreo);
            } catch (Throwable $e) {
                error_log('Pago a instructores: aviso de pago: ' . $e->getMessage());
                $extra['av'] = 0;
            }
        }
        $_GET = array_diff_key($_GET, array_flip(['ver', 'msg', 'tab', 'av', 'sc']));
        cengi_pp_redirigir('pago_instructores.php', $extra);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $mensajeTipo = 'error';
        if ($e instanceof InvalidArgumentException) {
            $mensaje = $e->getMessage();
        } else {
            error_log('Pago a instructores: ' . $e->getMessage());
            $mensaje = 'No fue posible guardar los cambios.';
        }
    }
}

if ($mensaje === '' && ($_GET['msg'] ?? '') === 'importado') {
    $mensaje = cengi_pp_h('Carga masiva aplicada: ' . (int) ($_GET['n'] ?? 0) . ' tarifa(s) actualizada(s). Se usan como valor por defecto al liquidar.');
} elseif ($mensaje === '' && isset(CENGI_PAGO_MENSAJES[$_GET['msg'] ?? ''])) {
    $mensaje = cengi_pp_h(CENGI_PAGO_MENSAJES[$_GET['msg']]);
    if ($_GET['msg'] === 'factura_req') {
        $mensaje = 'Requerimiento(s) de factura enviado(s): <b>' . (int) ($_GET['env'] ?? 0) . '</b>. Cada persona recibe el monto a facturar.'
            . (trim((string) ($_GET['err'] ?? '')) !== '' ? ' <br>No enviados: ' . cengi_pp_h(mb_substr((string) $_GET['err'], 0, 600, 'UTF-8')) . '.' : '');
        $mensajeTipo = (int) ($_GET['env'] ?? 0) ? 'success' : 'warning';
    }
    if (in_array($_GET['msg'], ['generada', 'liq_enviada'], true) && (isset($_GET['env']) || isset($_GET['fenv']))) {
        $mensaje .= isset($_GET['fenv']) ? ' Requerimientos de factura enviados: <b>' . (int) $_GET['fenv'] . '</b>.' : '';
    }
    if (in_array($_GET['msg'], ['generada', 'liq_enviada'], true) && isset($_GET['env'])) {
        $mensaje .= ' Recibos enviados por correo para firmar: <b>' . (int) $_GET['env'] . '</b>.'
            . (trim((string) ($_GET['err'] ?? '')) !== '' ? ' No enviados: ' . cengi_pp_h(mb_substr((string) $_GET['err'], 0, 400, 'UTF-8')) . ' (puedes enviarlos desde el bloque de recibos).' : '');
    }
    if ($_GET['msg'] === 'recibo_validado') {
        $mensaje = 'Recibo(s) validado(s): <b>' . (int) ($_GET['n'] ?? 0) . '</b>. Cada uno lleva tu firma y su código de verificación.'
            . (trim((string) ($_GET['err'] ?? '')) !== '' ? ' <br>Sin validar (no estaban firmados o la liquidación ya no lo permite): ' . cengi_pp_h(mb_substr((string) $_GET['err'], 0, 400, 'UTF-8')) . '.' : '');
        $mensajeTipo = (int) ($_GET['n'] ?? 0) ? 'success' : 'warning';
    }
    if ($_GET['msg'] === 'recibo_env') {
        $mensaje = 'Recibo(s) enviado(s) por correo: <b>' . (int) ($_GET['env'] ?? 0) . '</b>. Cada persona recibe el PDF y un enlace para devolverlo firmado.'
            . (trim((string) ($_GET['err'] ?? '')) !== '' ? ' <br>No enviados: ' . cengi_pp_h(mb_substr((string) $_GET['err'], 0, 600, 'UTF-8')) . '.' : '');
        $mensajeTipo = (int) ($_GET['env'] ?? 0) ? 'success' : 'warning';
    }
    if ($_GET['msg'] === 'pagada' && isset($_GET['av'])) {
        $mensaje .= ' Aviso por correo enviado a ' . (int) $_GET['av'] . ' persona(s)' . ((int) ($_GET['sc'] ?? 0) ? '; ' . (int) $_GET['sc'] . ' sin correo registrado' : '')
            . '. <a class="pp-link" href="comunicaciones.php?categoria=' . rawurlencode('Pago a instructores') . '">Ver en Comunicaciones</a>';
    }
}

// ---------------------------------------------------------------- liquidacion: descarga de planilla
$verId = (int) ($_GET['ver'] ?? 0);
$liqVer = null;
$liqDetalle = [];
if ($verId) {
    $stmt = $db->prepare('SELECT * FROM liquidaciones WHERE id = ?');
    $stmt->execute([$verId]);
    $liqVer = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($liqVer) {
        $stmt = $db->prepare("SELECT * FROM liquidacion_detalle WHERE liquidacion_id = ? ORDER BY tipo = 'soporte', persona_nombre, curso_nombre");
        $stmt->execute([$verId]);
        $liqDetalle = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
$liqVersiones = [];
$versionVigente = 1;
if ($liqVer) {
    $stmt = $db->prepare('SELECT * FROM liquidacion_versiones WHERE liquidacion_id = ? ORDER BY version DESC');
    $stmt->execute([$verId]);
    $liqVersiones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $versionVigente = $liqVersiones ? (int) $liqVersiones[0]['version'] + 1 : 1;
}
// Sesiones impartidas pendientes dentro del período de la liquidación (se pueden agregar al corregirla).
$pendientesLiq = ['sesiones' => 0, 'personas' => []];
if ($liqVer && in_array($liqVer['estado'], ['enviada', 'aprobada'], true)) {
    foreach (cengi_pago_por_liquidar($db, ['desde' => $liqVer['desde'], 'hasta' => $liqVer['hasta']]) as $g) {
        $pendientesLiq['sesiones'] += count($g['sesiones']);
        $pendientesLiq['personas'][$g['persona']] = true;
    }
}
$exportarVersion = (int) ($_GET['version'] ?? 0);
if ($liqVer && $exportarVersion > 0 && $exportarVersion < $versionVigente && in_array($_GET['exportar'] ?? '', ['excel', 'csv'], true)) {
    // Planilla de una versión anterior, tal como estaba antes de la corrección.
    foreach ($liqVersiones as $v) {
        if ((int) $v['version'] === $exportarVersion) {
            $copia = json_decode($v['datos'], true) ?: [];
            $liqVer = array_merge($liqVer, (array) ($copia['liquidacion'] ?? []));
            $liqVer['numero'] .= ' v' . $exportarVersion;
            $liqVer['pago_fecha'] = null;
            $liqDetalle = (array) ($copia['detalle'] ?? []);
            break;
        }
    }
}
if ($liqVer && in_array($_GET['exportar'] ?? '', ['excel', 'csv'], true)) {
    // Planilla para Administración: Excel con montos numéricos y total (SUBTOTAL) al pie.
    require_once __DIR__ . '/cobranza_helpers.php';
    if (!$exportarVersion && $versionVigente > 1) {
        $liqVer['numero'] .= ' v' . $versionVigente;
    }
    $filas = [];
    foreach ($liqDetalle as $d) {
        $filas[] = [$d['tipo'] === 'soporte' ? 'Soporte técnico' : 'Instructor', $d['persona_nombre'], $d['persona_codigo'], $d['persona_dpi'] ?? '', $d['curso_nombre'],
            implode(', ', array_map('cengi_pp_fecha_corta', array_filter(explode(',', (string) $d['fechas'])))), $d['sesiones'], $d['tarifa'], $d['neto'],
            CENGI_PP_COMPROBANTES[$d['comprobante'] ?? 'factura'] ?? 'Factura', (float) ($d['impuesto_pct'] ?? 5), $d['impuesto'], $d['total'], $d['documento_numero'] ?? '', $d['observaciones']];
    }
    $firmaTexto = !empty($liqVer['firmada_en']) ? ' · Validada por ' . ($liqVer['firma_nombre'] ?: $liqVer['firmada_por']) . ' el ' . cengi_pp_fecha($liqVer['firmada_en']) . ' (código ' . $liqVer['firma_codigo'] . ')' : '';
    cengi_cob_excel('Liquidación ' . $liqVer['numero'],
        'Período ' . cengi_pp_fecha($liqVer['desde']) . ' – ' . cengi_pp_fecha($liqVer['hasta']) . ' · Dirigida a ' . $liqVer['destino'] . ' · ' . (CENGI_PP_ESTADOS_LIQ[$liqVer['estado']][0] ?? $liqVer['estado'])
            . $firmaTexto . ($liqVer['pago_fecha'] ? ' · Pagada el ' . cengi_pp_fecha($liqVer['pago_fecha']) . ' (' . trim($liqVer['pago_forma'] . ' ' . $liqVer['pago_referencia']) . ')' : ''), [
        ['Tipo', 14], ['Nombre', 28], ['Código', 12], ['DPI', 16], ['Curso / Diplomado', 32], ['Fechas', 30], ['Sesiones', 9, 'entero'], ['Costo neto por sesión', 13, 'dinero'],
        ['Total neto', 13, 'dinero'], ['Comprobante', 11], ['% impuesto', 10, 'decimal'], ['Impuesto', 12, 'dinero'], ['Total a pagar', 14, 'dinero'], ['N.° recibo / factura', 16], ['Observaciones', 30],
    ], $filas, 'Liquidacion_' . trim(preg_replace('/[^A-Za-z0-9_-]+/', '_', $liqVer['numero']), '_'));
}

// Firma: el código se recalcula con el contenido actual; si no coincide, la liquidación cambió después de firmarse.
$firmaValida = null;
if ($liqVer && !empty($liqVer['firmada_en'])) {
    $firmaValida = hash_equals((string) $liqVer['firma_codigo'], cengi_pago_codigo_firma($db, $liqVer, $liqDetalle, $liqVer['firmada_en'], $liqVer['firmada_por']));
}

// ---------------------------------------------------------------- recibos imprimibles
if ($liqVer && isset($_GET['recibos'])) {
    $fila = (int) ($_GET['fila'] ?? 0);
    if ($fila && isset($_GET['pdf'])) {
        foreach ($liqDetalle as $dPdf) {
            if ((int) $dPdf['id'] === $fila && $dPdf['comprobante'] === 'recibo') {
                $vPdf = cengi_pr_estados($db, [$fila])[$fila] ?? null;
                $pdfRec = cengi_pr_pdf($dPdf, $liqVer, $cfgPago, $vPdf ? cengi_pr_validacion_valida($db, $dPdf, $vPdf) : false, $liqVer['pago_fecha'] ?: $hoy, $vPdf);
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . str_replace('.pdf', cengi_pr_estado($dPdf, $vPdf) === 'validado' ? '_validado.pdf' : '.pdf', cengi_pr_nombre_archivo($dPdf)) . '"');
                header('Cache-Control: private, no-store');
                echo $pdfRec;
                exit;
            }
        }
        http_response_code(404);
        exit('Recibo no encontrado.');
    }
    $recibos = array_values(array_filter($liqDetalle, static function ($d) use ($fila) {
        return $d['comprobante'] === 'recibo' && (!$fila || (int) $d['id'] === $fila);
    }));
    $fechaRecibo = $liqVer['pago_fecha'] ?: $hoy;
    header('Content-Type: text/html; charset=UTF-8');
    ?><!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><title>Recibos · <?php echo cengi_pp_h($liqVer['numero']); ?></title>
<link rel="stylesheet" href="css/pago_instructores.css?v=<?php echo (int) (@filemtime(__DIR__ . '/css/pago_instructores.css') ?: 1); ?>"></head>
<body class="pg-recibos-pagina">
<div class="pg-recibos-barra"><span><b><?php echo count($recibos); ?></b> recibo(s) · Liquidación <?php echo cengi_pp_h($liqVer['numero']); ?></span>
    <?php $estImp = cengi_pr_estados($db, array_column($recibos, 'id')); $sinValidar = count(array_filter($recibos, static function ($d) use ($estImp) { return cengi_pr_estado($d, $estImp[(int) $d['id']] ?? null) !== 'validado'; })); ?>
    <?php if ($sinValidar): ?><span class="pg-recibos-aviso"><?php echo $sinValidar; ?> sin validación del <?php echo cengi_pp_h($cfgPago['jefe_cargo']); ?></span><?php endif; ?>
    <button type="button" onclick="window.print()">Imprimir</button></div>
<?php if (!$recibos): ?><p class="pg-recibos-vacio">Esta liquidación no tiene filas con comprobante “Recibo”. Quienes cobran con factura presentan su factura.</p><?php endif; ?>
<?php foreach ($recibos as $d) { $v = $estImp[(int) $d['id']] ?? null; echo cengi_pr_html($d, $liqVer, $cfgPago, $v ? cengi_pr_validacion_valida($db, $d, $v) : false, $fechaRecibo, $v); } ?>
</body></html>
<?php
    exit;
}

// ---------------------------------------------------------------- datos
// El Jefe de Transferencia (sin rol de administrador) solo ve las liquidaciones; abre en las que esperan su firma.
$tab = in_array($_GET['tab'] ?? '', ['liquidaciones', 'recibos', 'facturas'], true) ? $_GET['tab'] : ($esAdmin ? 'liquidar' : 'recibos');
$periodo = cengi_pago_periodo($_GET);
$persona = preg_match('/^[is]:\d+$/', (string) ($_GET['persona'] ?? '')) ? $_GET['persona'] : '';
$estadoLiq = array_key_exists($_GET['estado'] ?? '', CENGI_PP_ESTADOS_LIQ) || in_array($_GET['estado'] ?? '', ['por_firmar', 'esperando_recibos'], true) ? $_GET['estado'] : (!$esAdmin && !isset($_GET['estado']) ? 'por_firmar' : '');
$pctFactura = cengi_pago_pct($db, 'factura');
$pctRecibo = cengi_pago_pct($db, 'recibo');
$fmtPct = static function ($pct) {
    return rtrim(rtrim(number_format((float) $pct, 2, '.', ''), '0'), '.') . '%';
};

$clase = ($_GET['clase'] ?? '') === 'soporte' || strpos($persona, 's:') === 0 ? 'soporte' : 'instructor';
$porLiquidarTodos = cengi_pago_por_liquidar($db, $periodo, $persona);
$conteoClase = ['instructor' => ['filas' => 0, 'sesiones' => 0], 'soporte' => ['filas' => 0, 'sesiones' => 0]];
foreach ($porLiquidarTodos as $g) {
    $conteoClase[$g['tipo']]['filas']++;
    $conteoClase[$g['tipo']]['sesiones'] += count($g['sesiones']);
}
// Si el tipo elegido no tiene pendientes y el otro sí, se muestra el otro.
if (!$conteoClase[$clase]['filas'] && $conteoClase[$clase === 'soporte' ? 'instructor' : 'soporte']['filas'] && !isset($_GET['clase'])) {
    $clase = $clase === 'soporte' ? 'instructor' : 'soporte';
}
$porLiquidar = array_filter($porLiquidarTodos, static function ($g) use ($clase) {
    return $g['tipo'] === $clase;
});
$totalPorLiquidar = 0;
foreach ($porLiquidar as &$g) {
    $g['pct'] = $g['comprobante'] === 'recibo' ? $pctRecibo : $pctFactura;
    [$g['neto'], $g['impuesto'], $g['total']] = cengi_pago_calcular($g, (float) $g['tarifa'], $g['pct'] / 100);
    $totalPorLiquidar += $g['total'];
}
unset($g);

// Sesiones del periodo que ya pasaron y siguen "programadas": no entran al pago hasta marcarlas.
$stmt = $db->prepare("SELECT s.id, s.fecha, s.tema, p.nombre AS curso, i.nombre AS instructor FROM curso_sesiones s
    INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id LEFT JOIN instructores i ON i.id = s.instructor_id
    WHERE s.estado = 'programada' AND s.fecha BETWEEN ? AND ? AND s.fecha <= ? ORDER BY s.fecha");
$stmt->execute([$periodo['desde'], $periodo['hasta'], $hoy]);
$sinMarcar = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $db->prepare("SELECT COUNT(*) FROM curso_sesiones WHERE estado = 'impartida' AND instructor_id IS NULL AND fecha BETWEEN ? AND ?");
$stmt->execute([$periodo['desde'], $periodo['hasta']]);
$sinInstructor = (int) $stmt->fetchColumn();
$sinTarifa = array_filter($porLiquidar, static function ($g) {
    return $g['tarifa'] === null;
});
$rangoExcesivo = cengi_pago_dias($periodo) > CENGI_PAGO_MAX_DIAS;
// Sesiones impartidas sin liquidar en cualquier mes (las de otros meses no se ven en el período elegido).
$pendientesMes = $db->query("SELECT DATE_FORMAT(fecha, '%Y-%m') AS mes, COUNT(*) AS sesiones,
        COUNT(DISTINCT planificacion_id) AS cursos
    FROM curso_sesiones WHERE estado = 'impartida'
      AND ((instructor_id IS NOT NULL AND liquidacion_id IS NULL) OR (soporte_id IS NOT NULL AND liquidacion_soporte_id IS NULL))
    GROUP BY mes ORDER BY mes")->fetchAll(PDO::FETCH_ASSOC);
$pendientesTotal = array_sum(array_column($pendientesMes, 'sesiones'));

$resumenLiq = $db->query("SELECT estado, COUNT(*) AS n, COALESCE(SUM(total), 0) AS total FROM liquidaciones GROUP BY estado")->fetchAll(PDO::FETCH_UNIQUE);
$pagadoAnio = (float) $db->query("SELECT COALESCE(SUM(total), 0) FROM liquidaciones WHERE estado = 'pagada' AND YEAR(pago_fecha) = YEAR(CURDATE())")->fetchColumn();

// Recibos de cada liquidación y cuántos volvieron firmados por el monto vigente.
$sqlRecibos = "(SELECT COUNT(*) FROM liquidacion_detalle dr WHERE dr.liquidacion_id = l.id AND dr.comprobante = 'recibo')";
// Recibo listo: firmado por el monto vigente y, si se exige, validado por el Jefe por ese mismo monto.
$sqlRecibosOk = "(SELECT COUNT(*) FROM liquidacion_detalle dr INNER JOIN liquidacion_recibos rr ON rr.detalle_id = dr.id
    WHERE dr.liquidacion_id = l.id AND dr.comprobante = 'recibo' AND rr.firmado_en IS NOT NULL AND ABS(rr.monto - dr.total) < 0.005"
    . ' AND rr.validado_en IS NOT NULL AND ABS(rr.validado_monto - dr.total) < 0.005)';
if ($estadoLiq === 'por_firmar') {
    $estadoLiq = 'esperando_recibos'; // enlaces anteriores: la firma ahora es por recibo
}
$condPorFirmar = "l.estado = 'enviada' AND $sqlRecibos = $sqlRecibosOk";
$condEsperaRecibos = "l.estado = 'enviada' AND $sqlRecibos > $sqlRecibosOk";
$sqlLiq = "SELECT l.*, COUNT(DISTINCT CONCAT(d.tipo, d.persona_id)) AS personas, COALESCE(SUM(d.sesiones), 0) AS sesiones,
        (SELECT COALESCE(MAX(v.version), 0) + 1 FROM liquidacion_versiones v WHERE v.liquidacion_id = l.id) AS version_vigente,
        $sqlRecibos AS recibos, $sqlRecibosOk AS recibos_ok, GROUP_CONCAT(DISTINCT d.tipo) AS tipos
    FROM liquidaciones l LEFT JOIN liquidacion_detalle d ON d.liquidacion_id = l.id"
    . ($estadoLiq === 'por_firmar' ? " WHERE $condPorFirmar" : ($estadoLiq === 'esperando_recibos' ? " WHERE $condEsperaRecibos" : ($estadoLiq !== '' ? ' WHERE l.estado = ?' : ''))) . ' GROUP BY l.id ORDER BY l.creado DESC';
$stmt = $db->prepare($sqlLiq);
$stmt->execute($estadoLiq !== '' && !in_array($estadoLiq, ['por_firmar', 'esperando_recibos'], true) ? [$estadoLiq] : []);
$liquidaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Detalle de cada liquidación de la lista: forma de pago, avance por persona y motivo de la última versión.
$liqFilas = [];
$liqUltVersion = [];
if ($liquidaciones) {
    $inL = implode(',', array_map('intval', array_column($liquidaciones, 'id')));
    $filasTodas = $db->query("SELECT d.*, EXISTS (SELECT 1 FROM liquidacion_recibo_historial h WHERE h.detalle_id = d.id AND h.accion = 'requerimiento') AS requerido
        FROM liquidacion_detalle d WHERE d.liquidacion_id IN ($inL) ORDER BY d.tipo = 'soporte', d.persona_nombre, d.curso_nombre")->fetchAll(PDO::FETCH_ASSOC);
    $estFilas = cengi_pr_estados($db, array_column($filasTodas, 'id'));
    $estadoPorLiq = array_column($liquidaciones, 'estado', 'id');
    foreach ($filasTodas as $fl) {
        [$fl['pct'], $fl['paso'], $fl['paso_cls'], $fl['pasos_hechos'], $fl['pasos_total']] = cengi_pg_avance($fl, $estFilas[(int) $fl['id']] ?? null, (bool) $fl['requerido'], $estadoPorLiq[$fl['liquidacion_id']] ?? '');
        $liqFilas[(int) $fl['liquidacion_id']][] = $fl;
    }
    foreach ($db->query("SELECT v.liquidacion_id, v.version, v.motivo, v.creado, v.usuario FROM liquidacion_versiones v
        INNER JOIN (SELECT liquidacion_id, MAX(version) AS mx FROM liquidacion_versiones WHERE liquidacion_id IN ($inL) GROUP BY liquidacion_id) u
            ON u.liquidacion_id = v.liquidacion_id AND u.mx = v.version")->fetchAll(PDO::FETCH_ASSOC) as $uv) {
        $liqUltVersion[(int) $uv['liquidacion_id']] = $uv;
    }
}
$porFirmar = (int) $db->query("SELECT COUNT(*) FROM liquidaciones l WHERE $condPorFirmar")->fetchColumn();
$esperandoRecibos = (int) $db->query("SELECT COUNT(*) FROM liquidaciones l WHERE $condEsperaRecibos")->fetchColumn();

// Repositorio de recibos: todos los recibos de liquidaciones vigentes, con su etapa en el trámite.
const CENGI_PG_ETAPAS_RECIBO = [
    'sin_enviar' => ['Por enviar', 'is-sin_enviar'],
    'enviado' => ['Esperando firma', 'is-enviado'],
    'firmado' => ['Por validar · Jefe', 'is-firmado'],
    'validado' => ['Validado · por pagar', 'is-validado'],
    'pagado' => ['Pagado', 'is-pagado'],
];
$recibosRepo = [];
$conteoRec = array_fill_keys(array_keys(CENGI_PG_ETAPAS_RECIBO), 0);
foreach ($db->query("SELECT d.*, l.numero AS liq_numero, l.estado AS liq_estado, l.firmada_en AS liq_firmada_en, l.desde AS liq_desde, l.hasta AS liq_hasta,
        r.enviado_en, r.enviado_a, r.envios, r.firmado_en, r.firmado_por, r.firmado_path, r.monto AS rec_monto,
        r.validado_en, r.validado_por, r.validado_nombre, r.validado_codigo, r.validado_monto
    FROM liquidacion_detalle d INNER JOIN liquidaciones l ON l.id = d.liquidacion_id LEFT JOIN liquidacion_recibos r ON r.detalle_id = d.id
    WHERE d.comprobante = 'recibo' AND l.estado NOT IN ('anulada', 'borrador', 'revision', 'autorizada') ORDER BY l.creado DESC, d.persona_nombre")->fetchAll(PDO::FETCH_ASSOC) as $rr) {
    $er = $rr['enviado_en'] || $rr['firmado_en'] ? ['enviado_en' => $rr['enviado_en'], 'firmado_en' => $rr['firmado_en'], 'monto' => $rr['rec_monto'],
        'validado_en' => $rr['validado_en'], 'validado_monto' => $rr['validado_monto']] : null;
    $rr['estado_recibo'] = cengi_pr_estado($rr, $er);
    $rr['etapa'] = $rr['liq_estado'] === 'pagada' ? 'pagado'
        : (in_array($rr['estado_recibo'], ['firmado', 'validado'], true) ? $rr['estado_recibo'] : ($rr['estado_recibo'] === 'enviado' ? 'enviado' : 'sin_enviar'));
    $conteoRec[$rr['etapa']]++;
    $recibosRepo[] = $rr;
}
$filtroRec = array_key_exists($_GET['rec'] ?? '', CENGI_PG_ETAPAS_RECIBO) || ($_GET['rec'] ?? '') === 'todos' ? $_GET['rec'] : ($esAdmin ? 'pendientes' : 'firmado');
$buscarRec = trim((string) ($_GET['q'] ?? ''));
$recibosVista = array_values(array_filter($recibosRepo, static function ($rr) use ($filtroRec, $buscarRec) {
    if ($filtroRec === 'pendientes' && !in_array($rr['etapa'], ['sin_enviar', 'enviado', 'firmado'], true)) {
        return false;
    }
    if (!in_array($filtroRec, ['pendientes', 'todos'], true) && $rr['etapa'] !== $filtroRec) {
        return false;
    }
    return $buscarRec === '' || stripos($rr['persona_nombre'] . ' ' . $rr['documento_numero'] . ' ' . $rr['liq_numero'] . ' ' . $rr['curso_nombre'], $buscarRec) !== false;
}));
$recPendientes = $conteoRec['sin_enviar'] + $conteoRec['enviado'];
$historialRepo = $tab === 'recibos' ? cengi_pr_historial($db, array_column($recibosVista, 'id')) : [];
// Validación del Jefe desde el repositorio (filtro "Por validar").
$puedeValidarRepo = $esFirmante && trim((string) $cfgPago['firma']) !== '' && $conteoRec['firmado'] > 0;
$puedeFirmarRec = $esFirmante && trim((string) $cfgPago['firma']) !== '';

// Repositorio de facturas: seguimiento de Administración a quien cobra con factura.
const CENGI_PG_ETAPAS_FACTURA = [
    'por_requerir' => ['Por requerir', 'is-sin_enviar'],
    'esperando' => ['Esperando factura', 'is-enviado'],
    'recibida' => ['Factura recibida · por pagar', 'is-firmado'],
    'pagada' => ['Pagada', 'is-pagado'],
];
$facturasRepo = [];
$conteoFac = array_fill_keys(array_keys(CENGI_PG_ETAPAS_FACTURA), 0);
foreach ($db->query("SELECT d.*, l.numero AS liq_numero, l.estado AS liq_estado, l.pago_fecha,
        (SELECT MAX(h.creado) FROM liquidacion_recibo_historial h WHERE h.detalle_id = d.id AND h.accion = 'requerimiento') AS requerido_en,
        (SELECT COUNT(*) FROM liquidacion_recibo_historial h WHERE h.detalle_id = d.id AND h.accion = 'requerimiento') AS requerimientos,
        (SELECT MAX(h.id) FROM liquidacion_recibo_historial h WHERE h.detalle_id = d.id AND h.accion = 'factura' AND h.archivo_path <> '') AS factura_hist,
        (SELECT MAX(h.creado) FROM liquidacion_recibo_historial h WHERE h.detalle_id = d.id AND h.accion = 'factura') AS factura_en
    FROM liquidacion_detalle d INNER JOIN liquidaciones l ON l.id = d.liquidacion_id
    WHERE d.comprobante = 'factura' AND l.estado NOT IN ('anulada', 'borrador', 'revision', 'autorizada') ORDER BY l.creado DESC, d.persona_nombre")->fetchAll(PDO::FETCH_ASSOC) as $ff) {
    $ff['etapa'] = $ff['liq_estado'] === 'pagada' ? 'pagada' : (trim((string) $ff['documento_numero']) !== '' ? 'recibida' : ($ff['requerido_en'] ? 'esperando' : 'por_requerir'));
    $conteoFac[$ff['etapa']]++;
    $facturasRepo[] = $ff;
}
$filtroFac = array_key_exists($_GET['fac'] ?? '', CENGI_PG_ETAPAS_FACTURA) || ($_GET['fac'] ?? '') === 'todos' ? $_GET['fac'] : 'pendientes';
$facturasVista = array_values(array_filter($facturasRepo, static function ($ff) use ($filtroFac, $buscarRec) {
    if ($filtroFac === 'pendientes' && $ff['etapa'] === 'pagada') {
        return false;
    }
    if (!in_array($filtroFac, ['pendientes', 'todos'], true) && $ff['etapa'] !== $filtroFac) {
        return false;
    }
    return $buscarRec === '' || stripos($ff['persona_nombre'] . ' ' . $ff['documento_numero'] . ' ' . $ff['liq_numero'] . ' ' . $ff['curso_nombre'], $buscarRec) !== false;
}));
$facPendientes = $conteoFac['por_requerir'] + $conteoFac['esperando'];
$historialFac = $tab === 'facturas' ? cengi_pr_historial($db, array_column($facturasVista, 'id')) : [];

// Con DPI y comprobante (columnas de esta pantalla, ver cengi_pago_asegurar_esquema).
cengi_pp_asegurar_codigos_instructor($db);
$instructores = $db->query("SELECT id, nombre, correo, codigo_catedratico, tarifa_sesion, estado, dpi, comprobante FROM instructores ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$soportes = cengi_pp_soportes($db, false);
$meses = [];
for ($k = -12; $k <= 2; $k++) {
    $m = date('Y-m', strtotime(date('Y-m-01') . " $k months"));
    $meses[$m] = CENGI_PP_MESES[(int) substr($m, 5, 2)] . ' ' . substr($m, 0, 4);
}
if (!isset($meses[$periodo['mes']])) {
    $meses[$periodo['mes']] = CENGI_PP_MESES[(int) substr($periodo['mes'], 5, 2)] . ' ' . substr($periodo['mes'], 0, 4);
}
krsort($meses);
$numeroSugerido = cengi_pago_numero_libre($db, 'LIQ-' . CENGI_PAGO_CLASES[$clase][2] . '-' . ($periodo['tipo'] === 'mes' ? $periodo['mes'] : date('ymd', strtotime($periodo['desde'])) . '-' . date('ymd', strtotime($periodo['hasta']))));
$filtrosPeriodo = array_filter(['clase' => $clase === 'soporte' ? 'soporte' : '', 'tipo' => $periodo['tipo'], 'mes' => $periodo['tipo'] === 'mes' ? $periodo['mes'] : '', 'desde' => $periodo['tipo'] === 'rango' ? $periodo['desde'] : '',
    'hasta' => $periodo['tipo'] === 'rango' ? $periodo['hasta'] : '', 'persona' => $persona]);
?>
<!DOCTYPE html>
<html lang="es">
<head><?php cengi_pp_head('Pago a instructores'); ?>
    <link rel="stylesheet" href="css/pago_instructores.css?v=<?php echo (int) (@filemtime(__DIR__ . '/css/pago_instructores.css') ?: 1); ?>">
</head>
<body class="cengi-canvas cg-tema">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page pg-page">
    <header class="ins-process-header"><small>ADMINISTRACIÓN · PAGOS A DOCENTES</small><h1>Liquidaciones de instructores</h1><p>Reúne las sesiones impartidas, revisa tarifas y gestiona firma, aprobación y pago de cada liquidación.</p></header>
    <?php cengi_pp_feedback($mensaje, $mensajeTipo); ?>

    <?php
    $sesionesPeriodo = array_sum(array_map(static function ($g) {
        return count($g['sesiones']);
    }, $porLiquidar));
    $urlLiq = static function ($estado) use ($esAdmin) {
        // Sin estado, el Jefe de Transferencia abre en "por firmar"; "todas" muestra el listado completo.
        return 'pago_instructores.php?' . http_build_query(array_filter(['tab' => 'liquidaciones', 'estado' => $estado ?: ($esAdmin ? '' : 'todas')]));
    };
    $cargoJefe = $cfgPago['jefe_cargo'] ?: 'Jefe de Transferencia de Tecnología';
    $pasos = array_values(array_filter([
        $esAdmin ? ['pago_instructores.php?' . http_build_query($filtrosPeriodo), 'time', 'is-amarillo', 'Por liquidar', cengi_pp_q($totalPorLiquidar, 2), $periodo['label'] . ' · ' . $sesionesPeriodo . ' sesión(es)', $tab === 'liquidar'] : null,
        ['pago_instructores.php?tab=recibos', 'file', 'is-amarillo', 'Recibos por firmar', (string) $recPendientes, $conteoRec['sin_enviar'] . ' por enviar · ' . $conteoRec['enviado'] . ' esperando firma', $tab === 'recibos' && $filtroRec !== 'firmado'],
        ['pago_instructores.php?tab=recibos&rec=firmado', 'pencil', 'is-naranja', 'Recibos por validar', (string) $conteoRec['firmado'], $cargoJefe, $tab === 'recibos' && $filtroRec === 'firmado'],
        [$urlLiq('enviada'), 'send', 'is-verde2', 'Enviadas por aprobar', (string) (int) ($resumenLiq['enviada']['n'] ?? 0), cengi_pp_q($resumenLiq['enviada']['total'] ?? 0, 2), $tab === 'liquidaciones' && $estadoLiq === 'enviada'],
        [$urlLiq('aprobada'), 'check', 'is-amarillo', 'Aprobadas por pagar', (string) (int) ($resumenLiq['aprobada']['n'] ?? 0), cengi_pp_q($resumenLiq['aprobada']['total'] ?? 0, 2), $tab === 'liquidaciones' && $estadoLiq === 'aprobada'],
        [$urlLiq('pagada'), 'usd', 'is-verde', 'Pagado en ' . date('Y'), cengi_pp_q($pagadoAnio, 2), (int) ($resumenLiq['pagada']['n'] ?? 0) . ' liquidación(es)', $tab === 'liquidaciones' && $estadoLiq === 'pagada'],
    ]));
    ?>
    <nav class="pg-flujo is-<?php echo count($pasos); ?>" style="--n:<?php echo count($pasos); ?>" aria-label="Flujo del pago">
        <?php foreach ($pasos as $n => [$href, $icono, $tono, $titulo, $valor, $detalle, $activo]): ?>
            <a href="<?php echo cengi_pp_h($href); ?>" class="pg-paso <?php echo $tono . ($activo ? ' is-activo' : ''); ?>">
                <span class="pg-paso-icono"><span class="glyphicon glyphicon-<?php echo $icono; ?>"></span></span>
                <span class="pg-paso-txt"><small><?php echo ($n + 1) . '. ' . cengi_pp_h($titulo); ?></small><b><?php echo cengi_pp_h($valor); ?></b><em><?php echo cengi_pp_h($detalle); ?></em></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <details class="pg-proceso"<?php echo $tab === 'liquidar' && !$liquidaciones ? ' open' : ''; ?>>
        <summary><span class="glyphicon glyphicon-info-sign"></span> <b>Cómo funciona el pago</b> · generar liquidación → <b>factura</b> (+ <?php echo $fmtPct($pctFactura); ?>) o <b>recibo</b> (neto) → aprobar y pagar
            <?php if ($esAdmin || $puedeFirmar): ?><button type="button" class="pg-link" data-toggle="modal" data-target="#config-modal">Configurar</button><?php endif; ?></summary>
        <div class="pg-proceso-cuerpo">
            <div class="pg-proceso-inicio"><span>1</span><div><b>Generar la liquidación</b><small>Por separado: <b>instructores</b> (sesiones impartidas) y <b>soporte técnico</b>. Cada persona lleva su nota: presenta factura o recibo. Solo entran sesiones impartidas.</small></div></div>
            <div class="pg-carriles">
                <div class="pg-carril is-factura"><b>Factura · se suma el <?php echo $fmtPct($pctFactura); ?></b>
                    <ol><li>La liquidación va a Administración con el valor + <?php echo $fmtPct($pctFactura); ?> (pequeño contribuyente).</li>
                        <li>Administración se comunica con la persona y <b>registra su factura</b>.</li>
                        <li>Administración aprueba y paga.</li></ol></div>
                <div class="pg-carril is-recibo"><b>Recibo · se paga el neto</b>
                    <ol><li>El sistema <b>emite el recibo</b> y lo envía a la persona.</li>
                        <li>La persona lo <b>firma</b> y lo devuelve.</li>
                        <li>El <?php echo cengi_pp_h($cargoJefe); ?> lo <b>valida</b>.</li>
                        <li>Administración aprueba y paga.</li></ol></div>
            </div>
        </div>
    </details>
    <?php if ($puedeFirmar && trim((string) $cfgPago['firma']) === ''): ?>
        <div class="pg-aviso pg-aviso-suelto"><span class="glyphicon glyphicon-pencil"></span><div><b>Falta cargar la firma del <?php echo cengi_pp_h($cargoJefe); ?>.</b> Sin ella no se pueden validar los recibos. <button type="button" class="pg-link" data-toggle="modal" data-target="#config-modal">Cargar o dibujar la firma</button></div></div>
    <?php endif; ?>

    <section class="pp-card pg-panel">
        <div class="pg-cabecera">
            <nav class="pp-tabs">
                <?php if ($esAdmin): ?><a href="pago_instructores.php?<?php echo cengi_pp_h(http_build_query($filtrosPeriodo)); ?>" class="<?php echo $tab === 'liquidar' ? 'is-active' : ''; ?>">Por liquidar <?php if ($pendientesTotal): ?><span class="pp-chip is-yellow"><?php echo $pendientesTotal; ?></span><?php endif; ?></a><?php endif; ?>
                <a href="pago_instructores.php?<?php echo cengi_pp_h(http_build_query(['tab' => 'liquidaciones', 'estado' => $esAdmin ? null : 'todas'])); ?>" class="<?php echo $tab === 'liquidaciones' ? 'is-active' : ''; ?>">Liquidaciones <span class="pp-chip"><?php echo array_sum(array_map(static function ($r) {
                    return (int) $r['n'];
                }, $resumenLiq)); ?></span></a>
                <a href="pago_instructores.php?tab=recibos" class="<?php echo $tab === 'recibos' ? 'is-active' : ''; ?>">Recibos <?php if ($recPendientes): ?><span class="pp-chip is-yellow"><?php echo $recPendientes; ?></span><?php endif; ?></a>
                <a href="pago_instructores.php?tab=facturas" class="<?php echo $tab === 'facturas' ? 'is-active' : ''; ?>">Facturas <?php if ($facPendientes): ?><span class="pp-chip is-yellow"><?php echo $facPendientes; ?></span><?php endif; ?></a>
            </nav>
            <div class="pg-herramientas">
                <?php if ($esAdmin): ?>
                    <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#tarifas-modal"><span class="glyphicon glyphicon-usd"></span> Tarifas, DPI y comprobante</button>
                    <a class="btn btn-default btn-sm" href="carga_masiva_planificacion.php?tipo=tarifa"><span class="glyphicon glyphicon-upload"></span> Cargar tarifas</a>
                <?php endif; ?>
                <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#config-modal"><span class="glyphicon glyphicon-cog"></span> Configuración</button>
            </div>
        </div>

        <?php if ($tab === 'liquidar'): ?>
            <nav class="pg-clases" aria-label="Tipo de liquidación">
                <?php foreach (CENGI_PAGO_CLASES as $claveClase => [$tituloClase, $subClase, , $iconoClase]): ?>
                    <a href="pago_instructores.php?<?php echo cengi_pp_h(http_build_query(array_filter(['clase' => $claveClase, 'tipo' => $periodo['tipo'], 'mes' => $periodo['tipo'] === 'mes' ? $periodo['mes'] : '', 'desde' => $periodo['tipo'] === 'rango' ? $periodo['desde'] : '', 'hasta' => $periodo['tipo'] === 'rango' ? $periodo['hasta'] : '']))); ?>" class="<?php echo $clase === $claveClase ? 'is-activo' : ''; ?>">
                        <span class="glyphicon glyphicon-<?php echo $iconoClase; ?>"></span>
                        <span><b><?php echo $tituloClase; ?></b><small><?php echo $subClase; ?> · <?php echo $conteoClase[$claveClase]['sesiones']; ?> sesión(es) por liquidar</small></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <form class="ses-filtros-form" method="get">
                <input type="hidden" name="clase" value="<?php echo $clase; ?>">
                <label class="ses-campo"><span>Período</span>
                    <select name="tipo" id="pago-tipo">
                        <option value="mes" <?php echo $periodo['tipo'] === 'mes' ? 'selected' : ''; ?>>Por mes</option>
                        <option value="rango" <?php echo $periodo['tipo'] === 'rango' ? 'selected' : ''; ?>>Por rango de fechas</option>
                    </select></label>
                <label class="ses-campo" id="pago-mes-campo" <?php echo $periodo['tipo'] === 'rango' ? 'hidden' : ''; ?>><span>Mes</span>
                    <select name="mes" id="pago-mes" onchange="this.form.submit()">
                        <?php foreach ($meses as $valor => $texto): ?><option value="<?php echo $valor; ?>" <?php echo $valor === $periodo['mes'] ? 'selected' : ''; ?>><?php echo $texto; ?></option><?php endforeach; ?>
                    </select></label>
                <span class="pg-rango" id="pago-rango" <?php echo $periodo['tipo'] === 'mes' ? 'hidden' : ''; ?>>
                    <label class="ses-campo"><span>Desde</span><input type="date" name="desde" value="<?php echo cengi_pp_h($periodo['desde']); ?>"></label>
                    <label class="ses-campo"><span>Hasta</span><input type="date" name="hasta" value="<?php echo cengi_pp_h($periodo['hasta']); ?>"></label>
                </span>
                <label class="ses-campo"><span>Persona</span>
                    <select name="persona" onchange="this.form.submit()">
                        <option value=""><?php echo $clase === 'soporte' ? 'Todo el soporte técnico' : 'Todos los instructores'; ?></option>
                        <?php if ($clase === 'instructor'): ?><?php foreach ($instructores as $i): ?><option value="i:<?php echo (int) $i['id']; ?>" <?php echo $persona === 'i:' . $i['id'] ? 'selected' : ''; ?>><?php echo cengi_pp_h($i['nombre']); ?></option><?php endforeach; ?>
                        <?php else: ?><?php foreach ($soportes as $sp): ?><option value="s:<?php echo (int) $sp['id']; ?>" <?php echo $persona === 's:' . $sp['id'] ? 'selected' : ''; ?>><?php echo cengi_pp_h($sp['nombre']); ?></option><?php endforeach; ?><?php endif; ?>
                    </select></label>
                <div class="ses-filtros-fin">
                    <span class="ses-total"><b><?php echo count($porLiquidar); ?></b> fila(s) · <?php echo $sesionesPeriodo; ?> sesión(es)</span>
                    <button type="submit" class="btn btn-default btn-sm">Ver</button>
                </div>
            </form>

            <?php if ($pendientesMes): ?>
                <div class="pg-meses">
                    <span class="pg-meses-t"><span class="glyphicon glyphicon-calendar"></span><?php echo $pendientesTotal; ?> sesión(es) impartida(s) sin liquidar:</span>
                    <?php foreach ($pendientesMes as $pm):
                        $etiqueta = CENGI_PP_MESES_CORTOS[(int) substr($pm['mes'], 5, 2)] . ' ' . substr($pm['mes'], 0, 4);
                        $esActual = $periodo['tipo'] === 'mes' && $periodo['mes'] === $pm['mes']; ?>
                        <a class="pg-mes<?php echo $esActual ? ' is-activo' : ''; ?>" href="pago_instructores.php?<?php echo cengi_pp_h(http_build_query(array_filter(['tipo' => 'mes', 'mes' => $pm['mes'], 'persona' => $persona]))); ?>"><?php echo cengi_pp_h($etiqueta); ?> <b><?php echo (int) $pm['sesiones']; ?></b></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($rangoExcesivo): ?>
                <div class="pg-aviso is-error"><span class="glyphicon glyphicon-warning-sign"></span><div>El rango elegido abarca <?php echo cengi_pago_dias($periodo); ?> días (<?php echo cengi_pp_h($periodo['label']); ?>). Revisa las fechas: no se puede liquidar un período mayor a un año.</div></div>
            <?php endif; ?>
            <?php if ($sinInstructor || $sinTarifa): ?>
                <div class="pg-aviso"><span class="glyphicon glyphicon-info-sign"></span><div>
                    <?php if ($sinInstructor): ?><?php echo $sinInstructor; ?> sesión(es) impartida(s) del período no tienen instructor asignado y no se pueden liquidar. <a class="pp-link" href="programa_sesiones.php">Asignar en Programa de sesiones</a><br><?php endif; ?>
                    <?php if ($sinTarifa): ?><?php echo count($sinTarifa); ?> fila(s) sin tarifa registrada: escríbela en la tabla o en “Tarifas”.<?php endif; ?>
                </div></div>
            <?php endif; ?>
            <?php if ($sinMarcar): ?>
                <form method="post" class="pg-sin-marcar" onsubmit="return this.querySelector('input[name=&quot;sesiones[]&quot;]:checked') ? confirm('¿Marcar las sesiones seleccionadas como impartidas? Entrarán al pago del instructor.') : (alert('Marca al menos una sesión.'), false);">
                    <input type="hidden" name="accion" value="marcar_impartidas">
                    <div class="pg-sin-marcar-cab">
                        <span class="glyphicon glyphicon-warning-sign"></span>
                        <div><b><?php echo count($sinMarcar); ?> sesión(es) del período ya pasaron y siguen “programadas”</b><small>No entran al pago hasta marcarlas como impartidas. Si alguna no se dio, reprográmala o cancélala.</small></div>
                        <button type="submit" class="btn btn-primary btn-sm"><span class="glyphicon glyphicon-ok"></span> Marcar impartidas</button>
                    </div>
                    <ul>
                        <?php foreach ($sinMarcar as $s): ?>
                            <li><label><input type="checkbox" name="sesiones[]" value="<?php echo (int) $s['id']; ?>" checked>
                                <span class="pp-mono"><?php echo cengi_pp_fecha_corta($s['fecha']); ?></span> <?php echo cengi_pp_h($s['curso'] . ' — ' . $s['tema']); ?><?php echo $s['instructor'] ? ' <small>(' . cengi_pp_h($s['instructor']) . ')</small>' : ' <small class="pg-falta">sin instructor</small>'; ?></label>
                                <a class="pp-link" href="programa_sesiones.php?reprogramar=<?php echo (int) $s['id']; ?>">Reprogramar</a></li>
                        <?php endforeach; ?>
                    </ul>
                </form>
            <?php endif; ?>

            <form method="post" id="liquidar-form">
                <input type="hidden" name="accion" value="generar"><input type="hidden" name="clase" value="<?php echo $clase; ?>">
                <?php foreach (['tipo' => $periodo['tipo'], 'mes' => $periodo['mes'], 'desde' => $periodo['desde'], 'hasta' => $periodo['hasta'], 'persona' => $persona] as $campo => $valor): ?>
                    <input type="hidden" name="<?php echo $campo; ?>" value="<?php echo cengi_pp_h($valor); ?>">
                <?php endforeach; ?>
                <div class="pp-table-wrap">
                    <table class="cengi-org-table pg-tabla">
                        <thead><tr>
                            <th class="pg-col-check"><input type="checkbox" id="pago-todos" checked aria-label="Seleccionar todo"></th>
                            <th><?php echo $clase === 'soporte' ? 'Soporte técnico' : 'Instructor'; ?></th><th>Curso / Diplomado</th><th class="pg-num">Sesiones</th><th class="pg-num">Tarifa / sesión</th>
                            <th>¿Factura o recibo?</th><th class="pg-num">Neto + impuesto</th><th class="pg-num">Total</th><th>Observaciones</th>
                        </tr></thead>
                        <tbody>
                            <?php if (!$porLiquidar): ?>
                                <tr><td colspan="9"><div class="pg-vacio"><span class="glyphicon glyphicon-ok-circle"></span><b>No hay <?php echo $clase === 'soporte' ? 'soporte técnico' : 'sesiones impartidas de instructores'; ?> pendiente de liquidar en <?php echo cengi_pp_h($periodo['label']); ?></b>
                                    <span><?php echo $pendientesTotal ? 'Revisa los meses con sesiones pendientes indicados arriba.' : 'Todo lo impartido ya está liquidado.'; ?></span></div></td></tr>
                            <?php endif; ?>
                            <?php foreach ($porLiquidar as $clave => $g):
                                $iniciales = implode('', array_map(static function ($x) {
                                    return mb_strtoupper(mb_substr($x, 0, 1, 'UTF-8'), 'UTF-8');
                                }, array_slice(preg_split('/\s+/', trim($g['persona'])), 0, 2))); ?>
                                <tr data-sesiones="<?php echo count($g['sesiones']); ?>" class="<?php echo $g['tarifa'] === null ? 'is-sin-tarifa' : ''; ?>">
                                    <td class="pg-col-check"><input type="checkbox" name="sel[]" value="<?php echo cengi_pp_h($clave); ?>" checked aria-label="Incluir"></td>
                                    <td><div class="pg-persona">
                                        <span class="pg-avatar<?php echo $g['tipo'] === 'soporte' ? ' is-soporte' : ''; ?>"><?php echo cengi_pp_h($iniciales); ?></span>
                                        <span><?php if ($g['tipo'] === 'instructor'): ?><a href="ficha_instructor.php?id=<?php echo (int) $g['persona_id']; ?>" data-persona><?php echo cengi_pp_h($g['persona']); ?></a><?php else: ?><b data-persona><?php echo cengi_pp_h($g['persona']); ?></b><?php endif; ?>
                                            <small><?php echo $g['tipo'] === 'soporte' ? 'Soporte técnico · ' : ''; ?><span class="pp-mono"><?php echo cengi_pp_h($g['codigo'] ?: 'Sin código'); ?></span></small></span>
                                    </div></td>
                                    <td><a class="pg-curso" href="programa_sesiones.php?ver=<?php echo (int) $g['plan_id']; ?>"><?php echo cengi_pp_h($g['curso']); ?></a></td>
                                    <td class="pg-num"><span class="pg-ses"><?php echo count($g['sesiones']); ?></span>
                                        <small class="pg-fechas" title="<?php echo cengi_pp_h(implode(', ', array_map('cengi_pp_fecha_corta', $g['fechas']))); ?>"><?php echo cengi_pp_h(implode(', ', array_map('cengi_pp_fecha_corta', $g['fechas']))); ?></small></td>
                                    <td class="pg-num"><span class="pg-q"><span>Q</span><input type="number" class="pp-input-inline" name="tarifa[<?php echo cengi_pp_h($clave); ?>]" min="0" step="0.01" value="<?php echo $g['tarifa'] !== null ? number_format($g['tarifa'], 2, '.', '') : ''; ?>" placeholder="0.00" aria-label="Tarifa por sesión"></span></td>
                                    <td><select class="pg-comp" name="comp[<?php echo cengi_pp_h($clave); ?>]" aria-label="Comprobante">
                                        <option value="factura" data-pct="<?php echo $pctFactura; ?>" data-nota="<?php echo cengi_pp_h(cengi_pago_nota_comprobante('factura', $pctFactura)); ?>" <?php echo $g['comprobante'] === 'factura' ? 'selected' : ''; ?>>Factura · + <?php echo $fmtPct($pctFactura); ?></option>
                                        <option value="recibo" data-pct="0" data-nota="<?php echo cengi_pp_h(cengi_pago_nota_comprobante('recibo', $pctFactura)); ?>" <?php echo $g['comprobante'] === 'recibo' ? 'selected' : ''; ?>>Recibo · pago neto</option>
                                    </select><small class="pg-comp-nota" data-comp-nota><?php echo cengi_pp_h(cengi_pago_nota_comprobante($g['comprobante'], $pctFactura)); ?></small><?php if ($g['comprobante'] === 'recibo' && $g['dpi'] === ''): ?><small class="pg-falta">Sin DPI para el recibo</small><?php endif; ?></td>
                                    <td class="pg-num"><span data-neto><?php echo cengi_pp_q($g['neto'], 2); ?></span><small>+ <span data-imp><?php echo cengi_pp_q($g['impuesto'], 2); ?></span></small></td>
                                    <td class="pg-num pg-total" data-total><?php echo cengi_pp_q($g['total'], 2); ?></td>
                                    <td><input type="text" class="pp-input-obs" name="obs[<?php echo cengi_pp_h($clave); ?>]" maxlength="500" value="<?php echo cengi_pp_h($g['obs']); ?>" placeholder="Opcional" aria-label="Observaciones"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($porLiquidar): ?>
                    <div class="pg-barra">
                        <div class="pg-barra-datos">
                            <span><b id="pago-filas">0</b> fila(s) · <b id="pago-personas">0</b> persona(s)</span>
                            <span>Neto <b id="pago-neto">Q0.00</b></span>
                            <span>Impuesto <b id="pago-imp">Q0.00</b></span>
                            <span class="pg-barra-total">Total <b id="pago-total">Q0.00</b></span>
                        </div>
                        <button type="button" class="btn btn-primary" id="pago-generar" data-toggle="modal" data-target="#generar-liq-modal" <?php echo $rangoExcesivo ? 'disabled' : ''; ?>><span class="glyphicon glyphicon-send"></span> Generar liquidación</button>
                    </div>
                <?php endif; ?>

                <div class="modal fade cengi-course-form-modal pp-modal" id="generar-liq-modal" tabindex="-1" role="dialog" aria-labelledby="generar-liq-title">
                    <div class="modal-dialog" role="document"><div class="modal-content">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
                            <h4 class="modal-title" id="generar-liq-title">Generar liquidación de <?php echo mb_strtolower(CENGI_PAGO_CLASES[$clase][0], 'UTF-8'); ?></h4>
                            <p>Período <?php echo cengi_pp_h($periodo['label']); ?> · <span id="gl-resumen"></span></p>
                        </div>
                        <div class="modal-body">
                            <div class="cengi-course-modal-grid">
                                <div class="form-group"><label for="gl-numero">N.° de liquidación</label><input type="text" class="form-control" id="gl-numero" name="numero" maxlength="40" value="<?php echo cengi_pp_h($numeroSugerido); ?>" required></div>
                                <div class="form-group"><label for="gl-destino">Dirigida a</label><input type="text" class="form-control" id="gl-destino" name="destino" maxlength="255" value="Administración"></div>
                                <div class="form-group is-full"><label for="gl-nota">Nota para Administración <small>(opcional)</small></label><textarea class="form-control" id="gl-nota" name="nota" rows="3"><?php echo cengi_pp_h(CENGI_PAGO_CLASES[$clase][0] . ' · ' . mb_strtolower(CENGI_PAGO_CLASES[$clase][1], 'UTF-8') . ' de ' . $periodo['label'] . '. Cada persona indica si presenta factura (se suma el ' . $fmtPct($pctFactura) . ') o recibo (pago neto, firma y validación del Jefe).'); ?></textarea></div>
                            </div>
                            <p class="pg-nota-modal"><b>Guardar borrador</b>: la revisas y la editas antes de continuar. <b>Generar y enviar al Jefe</b>: pasa a la aprobación del Jefe de Transferencia; cuando la apruebe se habilita el envío a Administración, donde se distribuye a recibos y facturas.</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-default" name="modo" value="borrador"><span class="glyphicon glyphicon-edit"></span> Guardar borrador</button>
                            <button type="submit" class="btn btn-primary" name="modo" value="enviar"><span class="glyphicon glyphicon-send"></span> Generar y enviar al Jefe</button>
                        </div>
                    </div></div>
                </div>
            </form>
        <?php elseif ($tab === 'liquidaciones'): ?>
            <nav class="pg-estados" aria-label="Estado">
                <a href="<?php echo cengi_pp_h($urlLiq('')); ?>" class="<?php echo $estadoLiq === '' ? 'is-activo' : ''; ?>">Todas <b><?php echo array_sum(array_map(static function ($r) {
                    return (int) $r['n'];
                }, $resumenLiq)); ?></b></a>
                <?php if ($esperandoRecibos): ?><a href="<?php echo cengi_pp_h($urlLiq('esperando_recibos')); ?>" class="is-esperando<?php echo $estadoLiq === 'esperando_recibos' ? ' is-activo' : ''; ?>">Esperando recibos <b><?php echo $esperandoRecibos; ?></b></a><?php endif; ?>
                <?php foreach (CENGI_PP_ESTADOS_LIQ as $clave => [$texto]): if (!(int) ($resumenLiq[$clave]['n'] ?? 0) && $estadoLiq !== $clave) continue; // sin liquidaciones en ese estado: no se muestra ?>
                    <a href="<?php echo cengi_pp_h($urlLiq($clave)); ?>" class="is-<?php echo $clave; ?><?php echo $estadoLiq === $clave ? ' is-activo' : ''; ?>"><?php echo cengi_pp_h($texto); ?> <b><?php echo (int) ($resumenLiq[$clave]['n'] ?? 0); ?></b></a>
                <?php endforeach; ?>
            </nav>
            <div class="pp-table-wrap">
                <table class="cengi-org-table pg-tabla pg-tabla-liq" data-sin-menu>
                    <thead><tr><th>Liquidación</th><th>Período</th><th>Se distribuye en</th><th class="pg-num">Total</th><th>Avance del pago</th><th></th></tr></thead>
                    <tbody>
                        <?php if (!$liquidaciones): ?><tr><td colspan="6"><div class="pg-vacio"><span class="glyphicon glyphicon-list-alt"></span><b><?php echo $estadoLiq === 'esperando_recibos' ? 'Ninguna liquidación espera recibos' : ($estadoLiq ? 'No hay liquidaciones en este estado' : 'Aún no hay liquidaciones'); ?></b><span><?php echo $esAdmin ? 'Genera la primera desde “Por liquidar”.' : 'Cuando Capacitación te envíe una, aparecerá aquí para tu aprobación.'; ?></span></div></td></tr><?php endif; ?>
                        <?php foreach ($liquidaciones as $l):
                            if (!$esAdmin && $l['estado'] === 'borrador') continue; // el borrador es interno de Capacitación
                            $filasL = $liqFilas[(int) $l['id']] ?? [];
                            $av = cengi_pg_avance_resumen($filasL, $l['estado']);
                            $ultV = $liqUltVersion[(int) $l['id']] ?? null;
                            $sinEnviarL = in_array($l['estado'], CENGI_PG_SIN_ENVIAR, true);
                            $aprobJefeL = !empty($l['firmada_en']);
                            $esperaRecibos = $l['estado'] === 'enviada' && (int) $l['recibos'] > (int) $l['recibos_ok'];
                            $diasRango = (int) ((strtotime($l['hasta']) - strtotime($l['desde'])) / 86400) + 1;
                            $urlVerL = 'pago_instructores.php?' . http_build_query(['tab' => 'liquidaciones', 'estado' => $estadoLiq ?: null, 'ver' => $l['id']]);
                            $editableL = $esAdmin && in_array($l['estado'], ['borrador', 'revision', 'autorizada', 'enviada', 'aprobada'], true);
                            $jefeDebeL = $esFirmante && ($l['estado'] === 'revision' || ($l['estado'] === 'enviada' && !$aprobJefeL));
                            $estadoTxt = [
                                'borrador' => (trim((string) $l['observacion_jefe']) !== '' ? 'Devuelta por el Jefe · por corregir' : 'Borrador · sin enviar'),
                                'revision' => 'Por aprobar · Jefe de Transferencia',
                                'autorizada' => 'Aprobada por el Jefe · lista para enviar',
                                'enviada' => !$aprobJefeL ? 'Falta la aprobación del Jefe' : ($esperaRecibos ? 'Esperando recibos (' . (int) $l['recibos_ok'] . ' de ' . (int) $l['recibos'] . ' validados)' : 'Por aprobar · Administración'),
                                'aprobada' => 'Aprobada por Administración · por pagar',
                                'pagada' => 'Pagada ' . cengi_pp_fecha($l['pago_fecha']),
                                'anulada' => 'Anulada',
                            ][$l['estado']] ?? '';
                            // Pasos del trámite de la liquidación (para el menú de acciones)
                            $hechosL = [$aprobJefeL, !$sinEnviarL && $l['estado'] !== 'anulada', $l['estado'] === 'pagada' || ($av['n'] && $av['listos'] === $av['n'] && !$sinEnviarL), $l['estado'] === 'pagada'];
                            $actualL = $l['estado'] === 'anulada' ? false : array_search(false, $hechosL, true);
                            $clsL = static function ($k) use ($hechosL, $actualL) {
                                return $hechosL[$k] ? 'is-hecho' : ($k === $actualL ? 'is-actual' : 'is-espera');
                            };
                            $pendienteMio = ($esAdmin && (in_array($l['estado'], ['borrador', 'autorizada', 'aprobada'], true) || ($l['estado'] === 'enviada' && $aprobJefeL && !$esperaRecibos))) || $jefeDebeL;
                            $repoL = static function ($tab, $clave) use ($l) {
                                return 'pago_instructores.php?' . http_build_query(['tab' => $tab, $clave => 'todos', 'q' => $l['numero']]);
                            }; ?>
                            <tr class="pg-liq-fila<?php echo $l['estado'] === 'anulada' ? ' pp-muted-row' : ($l['estado'] === 'borrador' ? ' pg-fila-borrador' : ''); ?>">
                                <td><a class="pg-liq-num" href="<?php echo cengi_pp_h($urlVerL); ?>"><?php echo cengi_pp_h($l['numero']); ?></a> <span class="pg-version<?php echo $ultV ? '' : ' is-original'; ?>" title="<?php echo $ultV ? 'Corregida ' . (int) $ultV['version'] . ' vez/veces' : 'Sin correcciones'; ?>">v<?php echo (int) $l['version_vigente']; ?></span>
                                    <small class="pg-sub"><?php $tiposL = array_filter(explode(',', (string) $l['tipos'])); echo '<span class="pg-clase-chip is-' . (count($tiposL) > 1 ? 'mixta' : cengi_pp_h($tiposL[0] ?? '')) . '">' . (count($tiposL) > 1 ? 'Instructores y soporte' : (CENGI_PAGO_CLASES[$tiposL[0] ?? '']['0'] ?? '—')) . '</span> ' . cengi_pp_fecha($l['creado']) . ' · ' . cengi_pp_h($l['creado_por_nombre']); ?></small>
                                    <?php if ($ultV): ?><small class="pg-liq-motivo" title="<?php echo cengi_pp_h($ultV['motivo'] . ' · ' . cengi_pp_fecha($ultV['creado']) . ' · ' . $ultV['usuario']); ?>"><b>Motivo v<?php echo (int) $ultV['version'] + 1; ?>:</b> <?php echo cengi_pp_h(mb_strimwidth((string) $ultV['motivo'], 0, 90, '…', 'UTF-8')); ?></small><?php endif; ?>
                                    <?php if ($l['estado'] === 'borrador' && trim((string) $l['observacion_jefe']) !== ''): ?><small class="pg-liq-motivo is-anulada"><b>Observación del Jefe:</b> <?php echo cengi_pp_h(mb_strimwidth((string) $l['observacion_jefe'], 0, 90, '…', 'UTF-8')); ?></small><?php endif; ?>
                                    <?php if ($l['estado'] === 'anulada' && trim((string) $l['motivo_anulacion']) !== ''): ?><small class="pg-liq-motivo is-anulada"><b>Anulada:</b> <?php echo cengi_pp_h(mb_strimwidth((string) $l['motivo_anulacion'], 0, 90, '…', 'UTF-8')); ?></small><?php endif; ?>
                                    <?php if ($filasL): ?><button type="button" class="pg-liq-toggle" aria-expanded="false" aria-controls="liq-det-<?php echo (int) $l['id']; ?>"><span class="glyphicon glyphicon-menu-down"></span> Detalle de <?php echo $av['n']; ?> persona(s) · <?php echo (int) $l['sesiones']; ?> sesión(es)</button><?php endif; ?></td>
                                <td class="pp-num"><?php echo cengi_pp_fecha($l['desde']) . ' – ' . cengi_pp_fecha($l['hasta']); ?>
                                    <?php if ($diasRango > CENGI_PAGO_MAX_DIAS): ?><small class="pg-falta" title="El período supera un año: revisa si la fecha final es correcta">Revisar fechas</small><?php endif; ?></td>
                                <td class="pg-forma">
                                    <?php foreach (['recibo' => ['Recibos · neto', 'recibos', 'rec'], 'factura' => ['Facturas · + ' . $fmtPct($pctFactura), 'facturas', 'fac']] as $compL => [$compTxt, $tabRepo, $claveRepo]): if (!$av['forma'][$compL][0]) continue; ?>
                                        <?php if ($sinEnviarL || $l['estado'] === 'anulada'): ?>
                                            <span class="pg-forma-item"><span class="pg-comp-chip is-<?php echo $compL; ?>"><?php echo $compTxt; ?></span><small><?php echo $av['forma'][$compL][0]; ?> persona(s) · <?php echo cengi_pp_q($av['forma'][$compL][1], 2); ?></small></span>
                                        <?php else: ?>
                                            <a class="pg-forma-item is-link" href="<?php echo cengi_pp_h($repoL($tabRepo, $claveRepo)); ?>" title="Ver en el repositorio de <?php echo $tabRepo; ?>"><span class="pg-comp-chip is-<?php echo $compL; ?>"><?php echo $compTxt; ?> →</span><small><?php echo $av['forma'][$compL][0]; ?> persona(s) · <?php echo cengi_pp_q($av['forma'][$compL][1], 2); ?></small></a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                    <?php if ($sinEnviarL): ?><small class="pg-forma-nota">Se distribuye al enviarla a Administración</small><?php endif; ?></td>
                                <td class="pg-num pg-total"><?php echo cengi_pp_q($l['total'], 2); ?></td>
                                <td><div class="pg-av<?php echo $l['estado'] === 'anulada' ? ' is-anulada' : ''; ?>">
                                    <?php echo cengi_pg_anillo($l['estado'] === 'anulada' ? 0 : $av['pct']); ?>
                                    <span class="pg-av-txt"><b><?php echo cengi_pp_h($estadoTxt); ?></b><?php if (in_array($l['estado'], ['enviada', 'aprobada'], true) && $av['n'] && !$esperaRecibos): ?><small><?php echo $av['listos']; ?> de <?php echo $av['n']; ?> comprobante(s) listos</small><?php elseif ($l['estado'] === 'pagada' && trim($l['pago_forma'] . ' ' . $l['pago_referencia']) !== ''): ?><small><?php echo cengi_pp_h(trim($l['pago_forma'] . ' ' . $l['pago_referencia'])); ?></small><?php endif; ?></span>
                                </div></td>
                                <td class="pg-acc-celda">
                                    <details class="pg-acciones-rec pg-acciones-liq">
                                        <summary class="btn btn-<?php echo $pendienteMio ? 'primary' : 'default'; ?> btn-sm"><span class="glyphicon glyphicon-list"></span> Acciones <span class="caret"></span></summary>
                                        <div class="pg-acciones-menu">
                                            <div class="pg-acc-rapidas">
                                                <a class="btn btn-default btn-xs" href="<?php echo cengi_pp_h($urlVerL); ?>"><span class="glyphicon glyphicon-eye-open"></span> Ver liquidación</a>
                                                <?php if ($editableL): ?><a class="btn btn-default btn-xs" href="<?php echo cengi_pp_h($urlVerL . '&editar=1'); ?>" title="<?php echo $sinEnviarL ? 'Aún no se envía: se edita sin crear versión' . ($l['estado'] !== 'borrador' ? ' (vuelve a borrador y se retira la aprobación del Jefe)' : '') : 'Corrección: queda como versión ' . ((int) $l['version_vigente'] + 1) . ' con su motivo'; ?>"><span class="glyphicon glyphicon-pencil"></span> <?php echo $sinEnviarL ? 'Editar' : 'Corregir'; ?></a><?php endif; ?>
                                                <a class="btn btn-default btn-xs" href="pago_instructores.php?<?php echo cengi_pp_h(http_build_query(['ver' => $l['id'], 'exportar' => 'excel'])); ?>"><span class="glyphicon glyphicon-download-alt"></span> Descargar</a>
                                            </div>
                                            <ol class="pg-tramite is-menu">
                                                <li class="<?php echo $clsL(0); ?>"><span class="pg-tramite-n"><?php echo $hechosL[0] ? '✓' : '1'; ?></span>
                                                    <div><b>Aprobación del <?php echo cengi_pp_h($cargoJefe); ?></b>
                                                        <small><?php echo $aprobJefeL ? 'Aprobada ' . cengi_pp_fecha($l['firmada_en']) . ' · ' . cengi_pp_h($l['firma_nombre'] ?: $l['firmada_por']) : ($l['estado'] === 'revision' ? 'Enviada al Jefe: pendiente de su aprobación' : ($l['estado'] === 'borrador' ? 'Revisa la liquidación y envíala al Jefe' : 'Pendiente')); ?></small>
                                                        <?php if ($esAdmin && $l['estado'] === 'borrador'): ?>
                                                            <form method="post" class="pg-rec-form" onsubmit="return confirm('¿Enviar la liquidación <?php echo cengi_pp_h(addslashes($l['numero'])); ?> al Jefe de Transferencia para su aprobación?');"><input type="hidden" name="accion" value="solicitar_jefe"><input type="hidden" name="id" value="<?php echo (int) $l['id']; ?>">
                                                                <button class="btn btn-primary btn-xs"><span class="glyphicon glyphicon-send"></span> Enviar al Jefe</button></form>
                                                        <?php elseif ($jefeDebeL): ?>
                                                            <a class="btn btn-primary btn-xs" href="<?php echo cengi_pp_h($urlVerL . '&aprobar=1'); ?>"><span class="glyphicon glyphicon-check"></span> Revisar y aprobar</a>
                                                        <?php endif; ?></div></li>
                                                <li class="<?php echo $clsL(1); ?>"><span class="pg-tramite-n"><?php echo $hechosL[1] ? '✓' : '2'; ?></span>
                                                    <div><b>Enviar a Administración</b>
                                                        <small><?php echo $hechosL[1] ? 'Enviada ' . cengi_pp_fecha($l['creado']) : ($l['estado'] === 'autorizada' ? 'Habilitado: el Jefe ya la aprobó' : 'Se habilita cuando el Jefe la apruebe'); ?></small>
                                                        <?php if ($esAdmin && $l['estado'] === 'autorizada'): ?>
                                                            <form method="post" class="pg-rec-form" onsubmit="return confirm('Se enviará a Administración y se distribuirá: los recibos salen por correo para firma y los requerimientos de factura a quienes facturan. ¿Continuar?');"><input type="hidden" name="accion" value="enviar_liq"><input type="hidden" name="id" value="<?php echo (int) $l['id']; ?>"><input type="hidden" name="enviar_recibos" value="1"><input type="hidden" name="enviar_requerimientos" value="1">
                                                                <button class="btn btn-primary btn-xs"><span class="glyphicon glyphicon-send"></span> Enviar a Administración</button></form>
                                                        <?php endif; ?></div></li>
                                                <li class="<?php echo $clsL(2); ?>"><span class="pg-tramite-n"><?php echo $hechosL[2] ? '✓' : '3'; ?></span>
                                                    <div><b>Recibos y facturas</b>
                                                        <small><?php echo $sinEnviarL ? 'Al enviarla, cada persona pasa a su repositorio' : $av['listos'] . ' de ' . $av['n'] . ' comprobante(s) listos para pago'; ?></small>
                                                        <?php if (!$sinEnviarL && $l['estado'] !== 'anulada'): ?>
                                                            <?php if ($av['forma']['recibo'][0]): ?><a class="btn btn-default btn-xs" href="<?php echo cengi_pp_h($repoL('recibos', 'rec')); ?>"><span class="pg-comp-chip is-recibo">Recibos <?php echo $av['forma']['recibo'][0]; ?></span> →</a><?php endif; ?>
                                                            <?php if ($av['forma']['factura'][0]): ?><a class="btn btn-default btn-xs" href="<?php echo cengi_pp_h($repoL('facturas', 'fac')); ?>"><span class="pg-comp-chip is-factura">Facturas <?php echo $av['forma']['factura'][0]; ?></span> →</a><?php endif; ?>
                                                        <?php endif; ?></div></li>
                                                <li class="<?php echo $clsL(3); ?>"><span class="pg-tramite-n"><?php echo $hechosL[3] ? '✓' : '4'; ?></span>
                                                    <div><b>Administración aprueba y paga</b>
                                                        <small><?php echo $l['estado'] === 'pagada' ? 'Pagada ' . cengi_pp_fecha($l['pago_fecha']) : ($l['estado'] === 'aprobada' ? 'Aprobada ' . cengi_pp_fecha($l['aprobada_en']) . ': falta registrar el pago' : ($l['estado'] === 'enviada' && $aprobJefeL && !$esperaRecibos ? 'Lista para la aprobación de Administración' : 'Cuando el Jefe la apruebe y los recibos estén validados')); ?></small>
                                                        <?php if ($esAdmin && (($l['estado'] === 'enviada' && $aprobJefeL && !$esperaRecibos) || $l['estado'] === 'aprobada')): ?><a class="btn btn-primary btn-xs" href="<?php echo cengi_pp_h($urlVerL); ?>"><span class="glyphicon glyphicon-<?php echo $l['estado'] === 'aprobada' ? 'usd' : 'check'; ?>"></span> <?php echo $l['estado'] === 'aprobada' ? 'Registrar pago' : 'Aprobar'; ?></a><?php endif; ?></div></li>
                                            </ol>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                            <?php if ($filasL): ?>
                                <tr class="pg-liq-detalle" id="liq-det-<?php echo (int) $l['id']; ?>" hidden><td colspan="6">
                                    <ul class="pg-liq-personas">
                                        <?php foreach ($filasL as $fl): ?>
                                            <li>
                                                <span class="pg-lp-persona"><b><?php echo cengi_pp_h($fl['persona_nombre']); ?></b><small><?php echo $fl['tipo'] === 'soporte' ? 'Soporte técnico' : 'Instructor'; ?> · <?php echo cengi_pp_h($fl['curso_nombre']); ?> · <?php echo (int) $fl['sesiones']; ?> × <?php echo cengi_pp_q($fl['tarifa'], 2); ?></small></span>
                                                <span class="pg-lp-forma"><span class="pg-comp-chip is-<?php echo cengi_pp_h($fl['comprobante']); ?>"><?php echo $fl['comprobante'] === 'recibo' ? 'Recibo' : 'Factura · + ' . $fmtPct($fl['impuesto_pct']); ?></span><?php if ($fl['comprobante'] === 'recibo' && trim((string) $fl['documento_numero']) !== '' && $l['estado'] !== 'anulada'): ?> <a class="pg-recibo-pdf" href="pago_instructores.php?<?php echo cengi_pp_h(http_build_query(['ver' => $l['id'], 'recibos' => 1, 'fila' => $fl['id'], 'pdf' => 1])); ?>" target="_blank" rel="noopener" title="Abrir el recibo en PDF"><span class="glyphicon glyphicon-file"></span> <?php echo cengi_pp_h($fl['documento_numero']); ?></a><?php endif; ?></span>
                                                <span class="pg-lp-total"><b><?php echo cengi_pp_q($fl['total'], 2); ?></b><?php if ((float) $fl['impuesto'] > 0): ?><small><?php echo cengi_pp_q($fl['neto'], 2); ?> + <?php echo cengi_pp_q($fl['impuesto'], 2); ?></small><?php endif; ?></span>
                                                <span class="pg-lp-avance <?php echo $fl['paso_cls']; ?>"><?php echo cengi_pg_puntos($fl['pasos_hechos'], $fl['pasos_total']); ?><small><?php echo cengi_pp_h($fl['paso']); ?></small></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </td></tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <script>
            document.querySelectorAll('.pg-liq-toggle').forEach(function (b) {
                b.addEventListener('click', function () {
                    var fila = document.getElementById(b.getAttribute('aria-controls'));
                    fila.hidden = !fila.hidden;
                    b.setAttribute('aria-expanded', String(!fila.hidden));
                });
            });
            </script>
        <?php elseif ($tab === 'recibos'): ?>
            <?php /* Repositorio de recibos: el seguimiento de cada recibo, de cualquier liquidación. */ ?>
            <div class="pg-repo-cab">
                <div><b>Repositorio de recibos</b><small>Quienes cobran sin factura firman su recibo. El trámite: <b>emitir y enviar</b> → la persona lo <b>firma</b> y lo devuelve → el <?php echo cengi_pp_h($cargoJefe); ?> <b>valida</b> cada recibo → Administración <b>aprueba y paga</b>. Cada paso queda en su <b>expediente</b> (con el PDF emitido y cada firmado).</small></div>
                <?php if ($esAdmin && ($conteoRec['sin_enviar'] > 0)): ?>
                    <form method="post" class="pg-rec-form" onsubmit="return confirm('Se enviará por correo cada recibo pendiente (PDF) con el enlace para devolverlo firmado. ¿Continuar?');">
                        <input type="hidden" name="accion" value="recibo_enviar_todos"><input type="hidden" name="volver" value="recibos"><input type="hidden" name="rec" value="<?php echo cengi_pp_h($filtroRec); ?>">
                        <button class="btn btn-primary btn-sm"><span class="glyphicon glyphicon-envelope"></span> Enviar los <?php echo $conteoRec['sin_enviar']; ?> pendientes</button>
                    </form>
                <?php endif; ?>
            </div>
            <nav class="pg-estados" aria-label="Etapa del recibo">
                <a href="pago_instructores.php?tab=recibos" class="<?php echo $filtroRec === 'pendientes' ? 'is-activo' : ''; ?>">Pendientes <b><?php echo $conteoRec['sin_enviar'] + $conteoRec['enviado'] + $conteoRec['firmado']; ?></b></a>
                <?php foreach (CENGI_PG_ETAPAS_RECIBO as $clave => [$texto, $clase]): ?>
                    <a href="pago_instructores.php?<?php echo cengi_pp_h(http_build_query(['tab' => 'recibos', 'rec' => $clave])); ?>" class="<?php echo $clase . ($filtroRec === $clave ? ' is-activo' : ''); ?>"><?php echo $texto; ?> <b><?php echo $conteoRec[$clave]; ?></b></a>
                <?php endforeach; ?>
                <a href="pago_instructores.php?tab=recibos&amp;rec=todos" class="<?php echo $filtroRec === 'todos' ? 'is-activo' : ''; ?>">Todos <b><?php echo count($recibosRepo); ?></b></a>
                <form method="get" class="pg-repo-buscar"><input type="hidden" name="tab" value="recibos"><input type="hidden" name="rec" value="<?php echo cengi_pp_h($filtroRec); ?>">
                    <input type="search" name="q" value="<?php echo cengi_pp_h($buscarRec); ?>" placeholder="Persona, N.° de recibo o liquidación" aria-label="Buscar recibo"></form>
            </nav>
            <?php if (!$recibosVista): ?>
                <div class="pg-vacio"><span class="glyphicon glyphicon-file"></span><b><?php echo $recibosRepo ? 'No hay recibos en esta etapa' : 'Aún no hay recibos'; ?></b><span><?php echo $recibosRepo ? 'Prueba con otra etapa o “Todos”.' : 'Aparecen al generar una liquidación con personas que cobran con recibo.'; ?></span></div>
            <?php else: ?>
                <?php if ($puedeValidarRepo && $filtroRec === 'firmado'): ?>
                    <form method="post" class="pg-validar-recibos is-repo" id="validar-repo-form">
                        <input type="hidden" name="accion" value="recibo_validar"><input type="hidden" name="volver" value="recibos"><input type="hidden" name="rec" value="firmado">
                        <img src="<?php echo cengi_pp_h($cfgPago['firma']); ?>" alt="Firma que se aplicará">
                        <div><b>Validar como <?php echo cengi_pp_h($cargoJefe); ?> los recibos marcados</b>
                            <label class="pg-check"><input type="checkbox" name="revisado" value="1" required> Revisé cada recibo firmado (persona, monto, DPI y firma)</label>
                            <div class="pg-validar-fila"><input type="password" class="form-control input-sm" name="contrasena" autocomplete="current-password" placeholder="Tu contraseña" required aria-label="Tu contraseña">
                                <button type="submit" class="btn btn-primary btn-sm"><span class="glyphicon glyphicon-check"></span> Validar recibos</button></div></div>
                    </form>
                <?php endif; ?>
                <ul class="pg-repo-lista">
                    <?php foreach ($recibosVista as $rr):
                        [$etapaTxt, $etapaCls] = CENGI_PG_ETAPAS_RECIBO[$rr['etapa']];
                        $verRecibo = 'pago_instructores.php?' . http_build_query(['ver' => $rr['liquidacion_id'], 'recibos' => 1, 'fila' => $rr['id']]); ?>
                        <?php
                        // Pasos del trámite del recibo, en orden: cada uno con su botón cuando le toca.
                        $hEnv = $rr['enviado_en'] && $rr['estado_recibo'] !== 'monto_cambio';
                        $hFirm = in_array($rr['estado_recibo'], ['firmado', 'validado'], true);
                        $hVal = $rr['estado_recibo'] === 'validado';
                        $hPag = $rr['etapa'] === 'pagado';
                        $pasosRec = [$hGen = true, $hEnv || $hFirm, $hFirm, $hVal, $hPag];
                        $actual = array_search(false, $pasosRec, true);
                        $abierta = !in_array($rr['liq_estado'], ['anulada', 'pagada'], true);
                        $volverRec = '<input type="hidden" name="volver" value="recibos"><input type="hidden" name="rec" value="' . cengi_pp_h($filtroRec) . '">';
                        $clsPaso = static function ($k) use ($pasosRec, $actual) {
                            return $pasosRec[$k] ? 'is-hecho' : ($k === $actual ? 'is-actual' : 'is-espera');
                        };
                        ?>
                        <li class="pg-repo-fila <?php echo $etapaCls; ?>" id="rec-<?php echo (int) $rr['id']; ?>">
                            <div class="pg-repo-cab">
                                <?php if ($puedeValidarRepo && $filtroRec === 'firmado' && $rr['etapa'] === 'firmado'): ?><label class="pg-rec-check" title="Marcar para firmar en grupo"><input type="checkbox" name="recibos[]" value="<?php echo (int) $rr['id']; ?>" form="validar-repo-form" checked></label><?php endif; ?>
                                <div class="pg-repo-num"><span class="pp-mono"><?php echo cengi_pp_h($rr['documento_numero'] ?: '—'); ?></span><b><?php echo cengi_pp_q($rr['total'], 2); ?></b></div>
                                <div class="pg-repo-persona"><b><?php echo cengi_pp_h($rr['persona_nombre']); ?></b>
                                    <small><?php echo $rr['tipo'] === 'soporte' ? 'Soporte técnico' : 'Instructor'; ?> · <?php echo cengi_pp_h($rr['curso_nombre']); ?> · <?php echo (int) $rr['sesiones']; ?> sesión(es)</small>
                                    <small><a href="pago_instructores.php?<?php echo cengi_pp_h(http_build_query(['tab' => 'liquidaciones', 'ver' => $rr['liquidacion_id']])); ?>">Liquidación <?php echo cengi_pp_h($rr['liq_numero']); ?></a> · <?php echo $rr['persona_dpi'] !== '' ? 'DPI ✓' : '<span class="pg-falta">Sin DPI</span>'; ?></small></div>
                                <div class="pg-repo-progreso" title="Paso <?php echo $actual === false ? 5 : $actual + 1; ?> de 5">
                                    <span class="pg-puntos"><?php foreach ($pasosRec as $k => $hecho): ?><i class="<?php echo $hecho ? 'is-hecho' : ($k === $actual ? 'is-actual' : ''); ?>"></i><?php endforeach; ?></span>
                                    <span class="pg-rec-chip <?php echo $etapaCls; ?>"><?php echo $etapaTxt; ?></span>
                                    <small><?php echo $actual === false ? 'Trámite completo' : 'Paso ' . ($actual + 1) . ' de 5 · ' . ['Generar recibo', $rr['tipo'] === 'soporte' ? 'Enviar a soporte técnico' : 'Enviar al instructor', 'Cargar el firmado', 'Firma del Jefe', 'Descargar y pagar'][$actual]; ?></small>
                                </div>
                                <details class="pg-acciones-rec">
                                    <summary class="btn btn-default btn-sm"><span class="glyphicon glyphicon-list"></span> Acciones <span class="caret"></span></summary>
                                    <div class="pg-acciones-menu">
                            <ol class="pg-tramite is-menu">
                                <li class="<?php echo $clsPaso(0); ?>">
                                    <span class="pg-tramite-n"><?php echo $pasosRec[0] ? '✓' : '1'; ?></span>
                                    <div><b>Generar recibo</b><small>Con la liquidación <?php echo cengi_pp_h($rr['liq_numero']); ?></small>
                                        <a class="btn btn-default btn-xs" href="<?php echo cengi_pp_h($verRecibo); ?>" target="_blank" rel="noopener"><span class="glyphicon glyphicon-eye-open"></span> Ver recibo</a></div>
                                </li>
                                <li class="<?php echo $clsPaso(1); ?>">
                                    <span class="pg-tramite-n"><?php echo $pasosRec[1] ? '✓' : '2'; ?></span>
                                    <div><b>Enviar <?php echo $rr['tipo'] === 'soporte' ? 'a soporte técnico' : 'al instructor'; ?></b>
                                        <small><?php echo $rr['estado_recibo'] === 'monto_cambio' ? '<span class="pg-falta">El monto cambió: envíalo de nuevo</span>'
                                            : ($rr['enviado_en'] ? 'Enviado ' . cengi_pp_fecha($rr['enviado_en']) . ((int) $rr['envios'] > 1 ? ' (' . (int) $rr['envios'] . '×)' : '') : ($hFirm ? 'Entregado impreso' : 'Por correo, o entrégalo impreso')); ?></small>
                                        <?php if ($esAdmin && $abierta && !$hFirm): ?>
                                            <form method="post" class="pg-rec-form"><input type="hidden" name="accion" value="recibo_enviar"><input type="hidden" name="id" value="<?php echo (int) $rr['liquidacion_id']; ?>"><input type="hidden" name="fila" value="<?php echo (int) $rr['id']; ?>"><?php echo $volverRec; ?>
                                                <button class="btn btn-<?php echo $actual === 1 ? 'primary' : 'default'; ?> btn-xs"><span class="glyphicon glyphicon-envelope"></span> <?php echo $rr['enviado_en'] && $rr['estado_recibo'] !== 'monto_cambio' ? 'Reenviar' : 'Enviar'; ?></button></form>
                                        <?php endif; ?></div>
                                </li>
                                <li class="<?php echo $clsPaso(2); ?>">
                                    <span class="pg-tramite-n"><?php echo $pasosRec[2] ? '✓' : '3'; ?></span>
                                    <div><b>Recibir y cargar firmado</b>
                                        <small><?php echo $hFirm ? 'Recibido ' . cengi_pp_fecha($rr['firmado_en']) . ($rr['firmado_por'] === 'persona' ? ' · por su enlace' : ' · cargado por Administración') : 'Firmado por la persona'; ?></small>
                                        <?php if ($rr['firmado_en']): ?><a class="btn btn-default btn-xs" href="pago_instructores.php?recibo_firmado=<?php echo (int) $rr['id']; ?>" target="_blank" rel="noopener"><span class="glyphicon glyphicon-paperclip"></span> Ver firmado</a><?php endif; ?>
                                        <?php if ($esAdmin && $abierta): ?>
                                            <details class="pg-rec-subir"><summary class="<?php echo $actual === 2 ? 'is-principal' : ''; ?>"><span class="glyphicon glyphicon-upload"></span> <?php echo $rr['firmado_en'] ? 'Cargar otro' : 'Cargar firmado'; ?></summary>
                                                <form method="post" enctype="multipart/form-data"><input type="hidden" name="accion" value="recibo_subir"><input type="hidden" name="id" value="<?php echo (int) $rr['liquidacion_id']; ?>"><input type="hidden" name="fila" value="<?php echo (int) $rr['id']; ?>"><?php echo $volverRec; ?><input type="file" name="firmado" accept="application/pdf,image/jpeg,image/png" required><button class="btn btn-primary btn-xs">Guardar</button></form></details>
                                        <?php endif; ?></div>
                                </li>
                                <li class="<?php echo $clsPaso(3); ?>">
                                    <span class="pg-tramite-n"><?php echo $pasosRec[3] ? '✓' : '4'; ?></span>
                                    <div><b>Firma del <?php echo cengi_pp_h($cargoJefe); ?></b>
                                        <small><?php echo $hVal ? 'Firmado ' . cengi_pp_fecha($rr['validado_en']) . ' · ' . cengi_pp_h($rr['validado_nombre']) . ' · ' . cengi_pp_h($rr['validado_codigo']) : ($hFirm ? 'Le toca firmar' : 'Después de la firma de la persona'); ?></small>
                                        <?php if ($puedeFirmarRec && $abierta && $rr['estado_recibo'] === 'firmado'): ?>
                                            <button type="button" class="btn btn-primary btn-xs" data-toggle="modal" data-target="#firmar-recibo-modal" data-recibo="<?php echo (int) $rr['id']; ?>" data-nombre="<?php echo cengi_pp_h($rr['documento_numero'] . ' · ' . $rr['persona_nombre'] . ' · ' . cengi_pp_q($rr['total'], 2)); ?>" data-firmado="pago_instructores.php?recibo_firmado=<?php echo (int) $rr['id']; ?>"><span class="glyphicon glyphicon-pencil"></span> Firmar</button>
                                        <?php endif; ?></div>
                                </li>
                                <li class="<?php echo $clsPaso(4); ?>">
                                    <span class="pg-tramite-n"><?php echo $pasosRec[4] ? '✓' : '5'; ?></span>
                                    <div><b>Administración: descargar y pagar</b>
                                        <small><?php echo $hPag ? 'Pagado' : ($hVal ? (['enviada' => 'Aprobar la liquidación y pagar', 'aprobada' => 'Liquidación aprobada: registrar el pago'][$rr['liq_estado']] ?? '') : 'Cuando el Jefe firme'); ?></small>
                                        <?php if ($hVal): ?><a class="btn btn-<?php echo $actual === 4 ? 'primary' : 'default'; ?> btn-xs" href="<?php echo cengi_pp_h($verRecibo . '&pdf=1'); ?>"><span class="glyphicon glyphicon-download-alt"></span> Descargar recibo</a><?php endif; ?>
                                        <?php if ($hVal && !$hPag && $esAdmin): ?><a class="btn btn-default btn-xs" href="pago_instructores.php?<?php echo cengi_pp_h(http_build_query(['tab' => 'liquidaciones', 'ver' => $rr['liquidacion_id']])); ?>"><span class="glyphicon glyphicon-usd"></span> Ir al pago</a><?php endif; ?></div>
                                </li>
                            </ol>
                            <div class="pg-repo-exp"><?php echo cengi_pg_expediente($historialRepo[(int) $rr['id']] ?? []); ?></div>
                                    </div>
                                </details>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <script>
// Menú "Acciones" de cada recibo: uno abierto a la vez; se cierra al hacer clic fuera.
document.addEventListener('click', function (e) {
    document.querySelectorAll('.pg-acciones-rec[open]').forEach(function (d) { if (!d.contains(e.target)) d.open = false; });
});
document.querySelectorAll('.pg-acciones-rec').forEach(function (d) {
    d.addEventListener('toggle', function () { if (d.open) document.querySelectorAll('.pg-acciones-rec[open]').forEach(function (o) { if (o !== d) o.open = false; }); });
});
</script>
            <?php endif; ?>
        <?php elseif ($tab === 'facturas'): ?>
            <?php /* Repositorio de facturas: Administración da seguimiento a quien cobra con factura (no pasa por el Jefe). */ ?>
            <div class="pg-repo-cab-tab">
                <div><b>Repositorio de facturas</b><small>Quienes cobran con factura reciben el valor + <?php echo $fmtPct($pctFactura); ?> (pequeño contribuyente). El trámite: <b>enviar el requerimiento</b> de factura → <b>recibir y registrar</b> la factura → Administración <b>confirma el pago</b>. No pasa por el <?php echo cengi_pp_h($cargoJefe); ?>.</small></div>
                <?php if ($esAdmin && $conteoFac['por_requerir'] > 0): ?>
                    <form method="post" class="pg-rec-form" onsubmit="return confirm('Se enviará por correo el requerimiento de factura a cada persona pendiente. ¿Continuar?');">
                        <input type="hidden" name="accion" value="factura_requerir_todos"><input type="hidden" name="volver" value="facturas"><input type="hidden" name="fac" value="<?php echo cengi_pp_h($filtroFac); ?>">
                        <button class="btn btn-primary btn-sm"><span class="glyphicon glyphicon-envelope"></span> Enviar los <?php echo $conteoFac['por_requerir']; ?> requerimientos pendientes</button>
                    </form>
                <?php endif; ?>
            </div>
            <nav class="pg-estados" aria-label="Etapa de la factura">
                <a href="pago_instructores.php?tab=facturas" class="<?php echo $filtroFac === 'pendientes' ? 'is-activo' : ''; ?>">Pendientes <b><?php echo $conteoFac['por_requerir'] + $conteoFac['esperando'] + $conteoFac['recibida']; ?></b></a>
                <?php foreach (CENGI_PG_ETAPAS_FACTURA as $clave => [$texto, $clase]): ?>
                    <a href="pago_instructores.php?<?php echo cengi_pp_h(http_build_query(['tab' => 'facturas', 'fac' => $clave])); ?>" class="<?php echo $clase . ($filtroFac === $clave ? ' is-activo' : ''); ?>"><?php echo $texto; ?> <b><?php echo $conteoFac[$clave]; ?></b></a>
                <?php endforeach; ?>
                <a href="pago_instructores.php?tab=facturas&amp;fac=todos" class="<?php echo $filtroFac === 'todos' ? 'is-activo' : ''; ?>">Todas <b><?php echo count($facturasRepo); ?></b></a>
                <form method="get" class="pg-repo-buscar"><input type="hidden" name="tab" value="facturas"><input type="hidden" name="fac" value="<?php echo cengi_pp_h($filtroFac); ?>">
                    <input type="search" name="q" value="<?php echo cengi_pp_h($buscarRec); ?>" placeholder="Persona, N.° de factura o liquidación" aria-label="Buscar factura"></form>
            </nav>
            <?php if (!$facturasVista): ?>
                <div class="pg-vacio"><span class="glyphicon glyphicon-list-alt"></span><b><?php echo $facturasRepo ? 'No hay facturas en esta etapa' : 'Aún no hay facturas'; ?></b><span><?php echo $facturasRepo ? 'Prueba con otra etapa o “Todas”.' : 'Aparecen al generar una liquidación con personas que presentan factura.'; ?></span></div>
            <?php else: ?>
                <ul class="pg-repo-lista">
                    <?php foreach ($facturasVista as $ff):
                        [$etapaTxt, $etapaCls] = CENGI_PG_ETAPAS_FACTURA[$ff['etapa']];
                        $hReq = (bool) $ff['requerido_en'];
                        $hFac = trim((string) $ff['documento_numero']) !== '';
                        $hPagF = $ff['liq_estado'] === 'pagada';
                        $pasosFac = [true, $hReq || $hFac, $hFac, $hPagF];
                        $actualF = array_search(false, $pasosFac, true);
                        $abiertaF = !in_array($ff['liq_estado'], ['anulada', 'pagada'], true);
                        $clsF = static function ($k) use ($pasosFac, $actualF) {
                            return $pasosFac[$k] ? 'is-hecho' : ($k === $actualF ? 'is-actual' : 'is-espera');
                        };
                        $urlLiqF = 'pago_instructores.php?' . http_build_query(['tab' => 'liquidaciones', 'ver' => $ff['liquidacion_id']]);
                        $volverFac = '<input type="hidden" name="volver" value="facturas"><input type="hidden" name="fac" value="' . cengi_pp_h($filtroFac) . '">'; ?>
                        <li class="pg-repo-fila <?php echo $etapaCls; ?>" id="fac-<?php echo (int) $ff['id']; ?>">
                            <div class="pg-repo-cab">
                                <div class="pg-repo-num"><span class="pp-mono"><?php echo $hFac ? cengi_pp_h($ff['documento_numero']) : 'Factura —'; ?></span><b><?php echo cengi_pp_q($ff['total'], 2); ?></b><small><?php echo cengi_pp_q($ff['neto'], 2); ?> + <?php echo cengi_pp_q($ff['impuesto'], 2); ?></small></div>
                                <div class="pg-repo-persona"><b><?php echo cengi_pp_h($ff['persona_nombre']); ?></b>
                                    <small><?php echo $ff['tipo'] === 'soporte' ? 'Soporte técnico' : 'Instructor'; ?> · <?php echo cengi_pp_h($ff['curso_nombre']); ?> · <?php echo (int) $ff['sesiones']; ?> sesión(es)</small>
                                    <small><a href="<?php echo cengi_pp_h($urlLiqF); ?>">Liquidación <?php echo cengi_pp_h($ff['liq_numero']); ?></a></small></div>
                                <div class="pg-repo-progreso" title="Paso <?php echo $actualF === false ? 4 : $actualF + 1; ?> de 4">
                                    <span class="pg-puntos"><?php foreach ($pasosFac as $k => $hecho): ?><i class="<?php echo $hecho ? 'is-hecho' : ($k === $actualF ? 'is-actual' : ''); ?>"></i><?php endforeach; ?></span>
                                    <span class="pg-rec-chip <?php echo $etapaCls; ?>"><?php echo $etapaTxt; ?></span>
                                    <small><?php echo $actualF === false ? 'Pago confirmado' : 'Paso ' . ($actualF + 1) . ' de 4 · ' . ['Liquidación generada', 'Enviar requerimiento de factura', 'Recibir y registrar la factura', 'Administración confirma el pago'][$actualF]; ?></small>
                                </div>
                                <details class="pg-acciones-rec">
                                    <summary class="btn btn-default btn-sm"><span class="glyphicon glyphicon-list"></span> Acciones <span class="caret"></span></summary>
                                    <div class="pg-acciones-menu">
                                        <ol class="pg-tramite is-menu">
                                            <li class="<?php echo $clsF(0); ?>"><span class="pg-tramite-n">✓</span>
                                                <div><b>Liquidación generada · valor + <?php echo $fmtPct($ff['impuesto_pct']); ?></b><small><?php echo cengi_pp_q($ff['neto'], 2); ?> + <?php echo cengi_pp_q($ff['impuesto'], 2); ?> = <?php echo cengi_pp_q($ff['total'], 2); ?></small>
                                                    <a class="btn btn-default btn-xs" href="<?php echo cengi_pp_h($urlLiqF); ?>"><span class="glyphicon glyphicon-eye-open"></span> Ver liquidación</a></div></li>
                                            <li class="<?php echo $clsF(1); ?>"><span class="pg-tramite-n"><?php echo $pasosFac[1] ? '✓' : '2'; ?></span>
                                                <div><b>Enviar requerimiento de factura <?php echo $ff['tipo'] === 'soporte' ? 'a soporte técnico' : 'al instructor'; ?></b>
                                                    <small><?php echo $hReq ? 'Enviado ' . cengi_pp_fecha($ff['requerido_en']) . ((int) $ff['requerimientos'] > 1 ? ' (' . (int) $ff['requerimientos'] . '×)' : '') : 'Por correo, con el monto a facturar'; ?></small>
                                                    <?php if ($esAdmin && $abiertaF && !$hFac): ?>
                                                        <form method="post" class="pg-rec-form"><input type="hidden" name="accion" value="factura_requerir"><input type="hidden" name="id" value="<?php echo (int) $ff['liquidacion_id']; ?>"><input type="hidden" name="fila" value="<?php echo (int) $ff['id']; ?>"><?php echo $volverFac; ?>
                                                            <button class="btn btn-<?php echo $actualF === 1 ? 'primary' : 'default'; ?> btn-xs"><span class="glyphicon glyphicon-envelope"></span> <?php echo $hReq ? 'Reenviar' : 'Enviar requerimiento'; ?></button></form>
                                                    <?php endif; ?></div></li>
                                            <li class="<?php echo $clsF(2); ?>"><span class="pg-tramite-n"><?php echo $pasosFac[2] ? '✓' : '3'; ?></span>
                                                <div><b>Recibir y registrar la factura</b>
                                                    <small><?php echo $hFac ? 'Factura ' . cengi_pp_h($ff['documento_numero']) . ($ff['factura_en'] ? ' · ' . cengi_pp_fecha($ff['factura_en']) : '') : 'Serie, número y archivo (opcional)'; ?></small>
                                                    <?php if ($ff['factura_hist']): ?><a class="btn btn-default btn-xs" href="pago_instructores.php?recibo_archivo=<?php echo (int) $ff['factura_hist']; ?>" target="_blank" rel="noopener"><span class="glyphicon glyphicon-paperclip"></span> Ver factura</a><?php endif; ?>
                                                    <?php if ($esAdmin && $abiertaF): ?>
                                                        <details class="pg-rec-subir"><summary class="<?php echo $actualF === 2 ? 'is-principal' : ''; ?>"><span class="glyphicon glyphicon-upload"></span> <?php echo $hFac ? 'Cambiar factura' : 'Registrar factura'; ?></summary>
                                                            <form method="post" enctype="multipart/form-data"><input type="hidden" name="accion" value="factura_registrar"><input type="hidden" name="id" value="<?php echo (int) $ff['liquidacion_id']; ?>"><input type="hidden" name="fila" value="<?php echo (int) $ff['id']; ?>"><?php echo $volverFac; ?>
                                                                <input type="text" class="form-control input-sm" name="numero_factura" maxlength="40" placeholder="Serie y número" value="<?php echo cengi_pp_h($ff['documento_numero']); ?>" required>
                                                                <input type="file" name="archivo_factura" accept="application/pdf,image/jpeg,image/png" title="Archivo de la factura (opcional)">
                                                                <button class="btn btn-primary btn-xs">Guardar</button></form></details>
                                                    <?php endif; ?></div></li>
                                            <li class="<?php echo $clsF(3); ?>"><span class="pg-tramite-n"><?php echo $pasosFac[3] ? '✓' : '4'; ?></span>
                                                <div><b>Administración confirma el pago</b>
                                                    <small><?php echo $hPagF ? 'Pagado ' . cengi_pp_fecha($ff['pago_fecha']) : (!$hFac ? 'Cuando se registre la factura' : (['enviada' => 'Falta aprobar la liquidación', 'aprobada' => 'Liquidación aprobada: confirmar el pago'][$ff['liq_estado']] ?? '')); ?></small>
                                                    <?php if ($esAdmin && $hFac && !$hPagF): ?><a class="btn btn-<?php echo $actualF === 3 ? 'primary' : 'default'; ?> btn-xs" href="<?php echo cengi_pp_h($urlLiqF); ?>"><span class="glyphicon glyphicon-usd"></span> <?php echo $ff['liq_estado'] === 'aprobada' ? 'Confirmar pago' : 'Ir a la liquidación'; ?></a><?php endif; ?></div></li>
                                        </ol>
                                        <div class="pg-repo-exp"><?php echo cengi_pg_expediente($historialFac[(int) $ff['id']] ?? []); ?></div>
                                    </div>
                                </details>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <script>
document.addEventListener('click', function (e) {
    document.querySelectorAll('.pg-acciones-rec[open]').forEach(function (d) { if (!d.contains(e.target)) d.open = false; });
});
</script>
        <?php endif; ?>
    </section>
</main>

<?php if ($tab === 'recibos' && $puedeFirmarRec): ?>
<div class="modal fade cengi-course-form-modal pp-modal" id="firmar-recibo-modal" tabindex="-1" role="dialog" aria-labelledby="firmar-recibo-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post">
            <input type="hidden" name="accion" value="recibo_validar"><input type="hidden" name="volver" value="recibos"><input type="hidden" name="rec" value="<?php echo cengi_pp_h($filtroRec); ?>">
            <input type="hidden" name="recibos[]" id="fr-recibo">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
                <span class="cengi-course-modal-kicker">Paso 4 · Firma del <?php echo cengi_pp_h($cargoJefe); ?></span>
                <h4 class="modal-title" id="firmar-recibo-title">Firmar recibo</h4>
                <p id="fr-nombre"></p>
            </div>
            <div class="modal-body">
                <div class="pg-validar-recibos is-repo" style="margin:0;">
                    <img src="<?php echo cengi_pp_h($cfgPago['firma']); ?>" alt="Firma que se aplicará">
                    <div><b>Se aplicará tu firma y un código de verificación</b>
                        <a class="pg-link" id="fr-ver-firmado" href="#" target="_blank" rel="noopener">Ver el recibo firmado por la persona</a>
                        <label class="pg-check"><input type="checkbox" name="revisado" value="1" required> Revisé el recibo firmado (persona, monto, DPI y firma)</label>
                        <div class="pg-validar-fila"><input type="password" class="form-control input-sm" name="contrasena" autocomplete="current-password" placeholder="Tu contraseña" required aria-label="Tu contraseña"></div></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary"><span class="glyphicon glyphicon-pencil"></span> Firmar recibo</button>
            </div>
        </form>
    </div></div>
</div>
<script>
$('#firmar-recibo-modal').on('show.bs.modal', function (e) {
    var b = e.relatedTarget;
    if (!b) return;
    document.getElementById('fr-recibo').value = b.getAttribute('data-recibo');
    document.getElementById('fr-nombre').textContent = b.getAttribute('data-nombre');
    document.getElementById('fr-ver-firmado').href = b.getAttribute('data-firmado');
});
</script>
<?php endif; ?>

<?php if ($esAdmin): ?>
<div class="modal fade cengi-course-form-modal pp-modal is-wide" id="tarifas-modal" tabindex="-1" role="dialog" aria-labelledby="tarifas-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post">
            <input type="hidden" name="accion" value="tarifas">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
                <h4 class="modal-title" id="tarifas-title">Tarifas, DPI y comprobante</h4>
                <p>Valores por defecto al liquidar; las liquidaciones ya generadas conservan los suyos. El <b>comprobante</b> indica si la persona cobra con factura (<?php echo $fmtPct($pctFactura); ?>) o con recibo (<?php echo $fmtPct($pctRecibo); ?>); el DPI aparece en el recibo. El código de catedrático se edita en la ficha del instructor.</p>
            </div>
            <div class="modal-body">
                <div class="pp-table-wrap"><table class="cengi-org-table pg-tabla pg-tabla-tarifas">
                    <thead><tr><th>Persona</th><th>Código</th><th class="pg-num">Tarifa por sesión</th><th>Comprobante</th><th>DPI (13 dígitos)</th></tr></thead>
                    <tbody>
                        <tr class="pg-grupo"><td colspan="5">Instructores</td></tr>
                        <?php foreach ($instructores as $i): ?>
                            <tr class="<?php echo (int) $i['estado'] !== 1 ? 'pp-muted-row' : ''; ?><?php echo $i['tarifa_sesion'] === null ? ' is-sin-tarifa' : ''; ?>">
                                <td><a href="ficha_instructor.php?id=<?php echo (int) $i['id']; ?>"><?php echo cengi_pp_h($i['nombre']); ?></a><?php echo (int) $i['estado'] !== 1 ? ' <span class="pp-chip">Inactivo</span>' : ''; ?></td>
                                <td class="pp-mono"><?php echo cengi_pp_h(cengi_pp_codigo_instructor($i['id'], $i['codigo_catedratico'])); ?></td>
                                <td class="pg-num"><span class="pg-q"><span>Q</span><input type="number" class="pp-input-inline" name="instr[<?php echo (int) $i['id']; ?>][tarifa]" min="0" step="0.01" value="<?php echo cengi_pp_h($i['tarifa_sesion']); ?>" placeholder="Sin tarifa" aria-label="Tarifa de <?php echo cengi_pp_h($i['nombre']); ?>"></span></td>
                                <td><select name="instr[<?php echo (int) $i['id']; ?>][comprobante]" aria-label="Comprobante"><?php foreach (CENGI_PP_COMPROBANTES as $c => $t): ?><option value="<?php echo $c; ?>" <?php echo ($i['comprobante'] ?? 'factura') === $c ? 'selected' : ''; ?>><?php echo $t; ?></option><?php endforeach; ?></select></td>
                                <td><input type="text" class="pg-dpi" name="instr[<?php echo (int) $i['id']; ?>][dpi]" inputmode="numeric" maxlength="15" pattern="[0-9 ]{13,15}" value="<?php echo cengi_pp_h($i['dpi'] ?? ''); ?>" placeholder="0000 00000 0000" aria-label="DPI"></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($soportes): ?><tr class="pg-grupo"><td colspan="5">Soporte técnico</td></tr><?php endif; ?>
                        <?php foreach ($soportes as $sp): ?>
                            <tr class="<?php echo (int) $sp['estado'] !== 1 ? 'pp-muted-row' : ''; ?>">
                                <td><?php echo cengi_pp_h($sp['nombre']); ?><?php echo (int) $sp['estado'] !== 1 ? ' <span class="pp-chip">Inactivo</span>' : ''; ?></td>
                                <td class="pp-mono"><?php echo cengi_pp_h($sp['codigo']); ?></td>
                                <td class="pg-num"><span class="pg-q"><span>Q</span><input type="number" class="pp-input-inline" name="sop[<?php echo (int) $sp['id']; ?>][tarifa]" min="0" step="0.01" value="<?php echo cengi_pp_h($sp['tarifa']); ?>" aria-label="Tarifa de <?php echo cengi_pp_h($sp['nombre']); ?>"></span></td>
                                <td><select name="sop[<?php echo (int) $sp['id']; ?>][comprobante]" aria-label="Comprobante"><?php foreach (CENGI_PP_COMPROBANTES as $c => $t): ?><option value="<?php echo $c; ?>" <?php echo ($sp['comprobante'] ?? 'factura') === $c ? 'selected' : ''; ?>><?php echo $t; ?></option><?php endforeach; ?></select></td>
                                <td><input type="text" class="pg-dpi" name="sop[<?php echo (int) $sp['id']; ?>][dpi]" inputmode="numeric" maxlength="15" pattern="[0-9 ]{13,15}" value="<?php echo cengi_pp_h($sp['dpi'] ?? ''); ?>" placeholder="0000 00000 0000" aria-label="DPI"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar</button>
            </div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<div class="modal fade cengi-course-form-modal pp-modal" id="config-modal" tabindex="-1" role="dialog" aria-labelledby="config-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post" enctype="multipart/form-data" id="config-form">
            <input type="hidden" name="accion" value="config">
            <input type="hidden" name="firma_data" id="cf-firma-data">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
                <h4 class="modal-title" id="config-title">Configuración del pago a instructores</h4>
                <p>Impuesto por comprobante, datos del recibo y firma del <?php echo cengi_pp_h($cfgPago['jefe_cargo'] ?: 'Jefe de Transferencia de Tecnología'); ?>.</p>
            </div>
            <div class="modal-body">
                <?php if ($esAdmin): ?>
                    <h5 class="pg-subtitulo">Impuesto según el comprobante</h5>
                    <div class="cengi-course-modal-grid">
                        <div class="form-group"><label for="cf-pct-factura">Factura de pequeño contribuyente (%)</label><input type="number" class="form-control" id="cf-pct-factura" name="pct_factura" min="0" max="100" step="0.01" value="<?php echo cengi_pp_h($pctFactura); ?>" required></div>
                        <div class="form-group"><label>Recibo</label><p class="form-control-static">Sin impuesto: se paga el valor neto.</p></div>
                    </div>
                    <p class="pg-nota-modal">Se aplica a las liquidaciones que se generen desde ahora. Las ya generadas conservan su porcentaje; si debe cambiar, usa “Corregir”.</p>
                    <h5 class="pg-subtitulo">Datos del recibo</h5>
                    <div class="cengi-course-modal-grid">
                        <div class="form-group"><label for="cf-recibi">“Recibí de”</label><input type="text" class="form-control" id="cf-recibi" name="recibi_de" maxlength="120" value="<?php echo cengi_pp_h($cfgPago['recibi_de']); ?>"></div>
                        <div class="form-group"><label for="cf-lugar">Lugar</label><input type="text" class="form-control" id="cf-lugar" name="lugar" maxlength="120" value="<?php echo cengi_pp_h($cfgPago['lugar']); ?>"></div>
                    </div>
                    <p class="pg-nota-modal">Los <b>recibos</b> siempre los valida el Jefe después de la firma de la persona; las <b>facturas</b> no pasan por su validación.</p>
                <?php endif; ?>
                <h5 class="pg-subtitulo">Quien valida y firma</h5>
                <div class="cengi-course-modal-grid">
                    <div class="form-group"><label for="cf-jefe">Nombre</label><input type="text" class="form-control" id="cf-jefe" name="jefe_nombre" maxlength="160" value="<?php echo cengi_pp_h($cfgPago['jefe_nombre']); ?>" placeholder="Nombre completo"></div>
                    <div class="form-group"><label for="cf-cargo">Cargo</label><input type="text" class="form-control" id="cf-cargo" name="jefe_cargo" maxlength="160" value="<?php echo cengi_pp_h($cfgPago['jefe_cargo']); ?>"></div>
                </div>
                <div class="pg-firma-config">
                    <div class="pg-firma-actual">
                        <span>Firma registrada</span>
                        <?php if (trim((string) $cfgPago['firma']) !== ''): ?><img src="<?php echo cengi_pp_h($cfgPago['firma']); ?>" alt="Firma registrada">
                            <label class="pg-check"><input type="checkbox" name="quitar_firma" value="1"> Quitar firma</label>
                        <?php else: ?><em>Sin firma</em><?php endif; ?>
                    </div>
                    <div class="pg-firma-nueva">
                        <span>Dibuja la firma aquí (con el mouse, lápiz o el dedo)</span>
                        <canvas id="cf-firma-lienzo" width="520" height="170" aria-label="Área para dibujar la firma"></canvas>
                        <div class="pg-firma-acciones"><button type="button" class="btn btn-default btn-xs" id="cf-firma-limpiar">Limpiar</button>
                            <label class="btn btn-default btn-xs">O subir imagen (PNG/JPG)<input type="file" name="firma_archivo" accept="image/png,image/jpeg" hidden></label></div>
                    </div>
                </div>
                <p class="pg-nota-modal">La firma se guarda en el sistema (no en una carpeta pública). Solo los usuarios con el permiso “Firmar liquidaciones” la aplican, confirmando con su contraseña; cada liquidación firmada guarda una copia y un código de verificación.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary">Guardar configuración</button>
            </div>
        </form>
    </div></div>
</div>

<?php if ($liqVer):
    $porPersona = [];
    foreach ($liqDetalle as $d) {
        $clavePersona = $d['tipo'] . ':' . $d['persona_id'];
        $porPersona[$clavePersona] ??= ['nombre' => $d['persona_nombre'], 'tipo' => $d['tipo'], 'id' => (int) $d['persona_id'], 'total' => 0, 'sesiones' => 0];
        $porPersona[$clavePersona]['total'] += $d['total'];
        $porPersona[$clavePersona]['sesiones'] += (int) $d['sesiones'];
    }
    $volver = cengi_pp_h(http_build_query(['tab' => 'liquidaciones', 'estado' => $estadoLiq]));
    $ordenVer = ['enviada' => 1, 'aprobada' => 3, 'pagada' => 4][$liqVer['estado']] ?? 0;
    if ($liqVer['estado'] === 'enviada' && !empty($liqVer['firmada_en'])) $ordenVer = 2;
    $recibosLiq = count(array_filter($liqDetalle, static function ($d) { return $d['comprobante'] === 'recibo'; }));
    $estadosRecibo = $recibosLiq ? cengi_pr_estados($db, array_column($liqDetalle, 'id')) : [];
    $recibosFirmados = count(array_filter($estadosRecibo, static function ($r) { return $r['firmado_en']; }));
    $facturasLiq = array_filter($liqDetalle, static function ($d) { return $d['comprobante'] === 'factura'; });
    // Avance de cada persona (según su recibo o factura) y de la liquidación completa, ponderado por monto.
    $estAvance = cengi_pr_estados($db, array_column($liqDetalle, 'id'));
    $histAvance = cengi_pr_historial($db, array_column($liqDetalle, 'id'));
    foreach ($liqDetalle as &$dAv) {
        $reqAv = (bool) array_filter($histAvance[(int) ($dAv['id'] ?? 0)] ?? [], static function ($h) { return $h['accion'] === 'requerimiento'; });
        [$dAv['pct'], $dAv['paso'], $dAv['paso_cls'], $dAv['pasos_hechos'], $dAv['pasos_total']] = cengi_pg_avance($dAv, $estAvance[(int) ($dAv['id'] ?? 0)] ?? null, $reqAv, $liqVer['estado']);
    }
    unset($dAv);
    $avLiq = cengi_pg_avance_resumen($liqDetalle, $liqVer['estado']);
    $requiereFirma = true; // la validación del Jefe en cada recibo es obligatoria (las facturas no pasan por él)
    $faltaFirma = $liqVer['estado'] === 'enviada' && empty($liqVer['firmada_en']);
    // Pasos del trámite: los recibos van antes de la validación del Jefe.
    $resRec = cengi_pr_resumen($db, $liqDetalle, $requiereFirma);
    $recibosListos = $resRec['listos'];
    $historialRec = cengi_pr_historial($db, array_column($liqDetalle, 'id'));
    $puedeValidarRecibos = $esFirmante && trim((string) $cfgPago['firma']) !== '' && in_array($liqVer['estado'], ['enviada', 'aprobada'], true);
    $esBorrador = in_array($liqVer['estado'], CENGI_PG_SIN_ENVIAR, true); // aún no se envía a Administración
    $puedeAprobarJefe = $esFirmante && ($liqVer['estado'] === 'revision' || ($liqVer['estado'] === 'enviada' && empty($liqVer['firmada_en'])));
    $pasosLiq = [['Aprobada por el Jefe', $liqVer['firmada_en'], $liqVer['firma_nombre'] ?: $liqVer['firmada_por'], !empty($liqVer['firmada_en'])]];
    $pasosLiq[] = ['Enviada a Administración', $esBorrador ? null : $liqVer['creado'], $esBorrador ? '' : $liqVer['creado_por_nombre'], !$esBorrador];
    if ($recibosLiq) {
        $pasosLiq[] = [$requiereFirma ? 'Recibos firmados y validados' : 'Recibos firmados', $recibosListos ? $resRec['ultima'] : null, $resRec['firmados'] . ' firmados · ' . $resRec['validados'] . ' validados de ' . $resRec['total'], $recibosListos];
    }
    $pasosLiq[] = ['Aprobada por Administración', $liqVer['aprobada_en'], $liqVer['aprobada_por'], in_array($liqVer['estado'], ['aprobada', 'pagada'], true)];
    $pasosLiq[] = ['Pagada', $liqVer['pago_fecha'], trim($liqVer['pago_forma'] . ' ' . $liqVer['pago_referencia']), $liqVer['estado'] === 'pagada'];
    $pasoActual = null;
    foreach ($pasosLiq as $k => $paso) {
        if (!$paso[3]) {
            $pasoActual = $k;
            break;
        }
    }
?>
<div class="modal fade cengi-course-form-modal pp-modal is-wide pg-liq-modal" id="liq-modal" tabindex="-1" role="dialog" aria-labelledby="liq-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <div class="modal-header">
            <a class="close" href="pago_instructores.php?<?php echo $volver; ?>" aria-label="Cerrar"><span>&times;</span></a>
            <span class="cengi-course-modal-kicker">Liquidación · <?php echo cengi_pp_h($liqVer['destino']); ?></span>
            <h4 class="modal-title" id="liq-title"><?php echo cengi_pp_h($liqVer['numero']); ?><?php if ($versionVigente > 1): ?> <span class="pg-version" title="Corregida <?php echo $versionVigente - 1; ?> vez/veces">versión <?php echo $versionVigente; ?></span><?php endif; ?></h4>
            <p>Período <?php echo cengi_pp_fecha($liqVer['desde']) . ' – ' . cengi_pp_fecha($liqVer['hasta']); ?> · <?php echo cengi_pp_badge(CENGI_PP_ESTADOS_LIQ, $liqVer['estado']); ?></p>
        </div>
        <div class="modal-body">
            <?php if ($esBorrador): $obsJefe = trim((string) ($liqVer['observacion_jefe'] ?? '')); ?><div class="pg-aviso is-borrador"><span class="glyphicon glyphicon-<?php echo ['borrador' => 'edit', 'revision' => 'time', 'autorizada' => 'ok-circle'][$liqVer['estado']]; ?>"></span><div><?php echo [
                'borrador' => '<b>Borrador · aún no se ha enviado.</b> Revisa el detalle y edítalo si hace falta (no se crea versión). Después envíalo al Jefe de Transferencia para su aprobación.',
                'revision' => '<b>En revisión del Jefe de Transferencia.</b> Cuando la apruebe se habilita el envío a Administración. Si se edita, vuelve a borrador.',
                'autorizada' => '<b>Aprobada por el Jefe de Transferencia.</b> Ya se puede enviar a Administración: en ese momento se distribuye a recibos y facturas.',
            ][$liqVer['estado']]; ?><?php if ($obsJefe !== '' && $liqVer['estado'] === 'borrador'): ?><span class="pg-obs-jefe"><b>Observación del Jefe:</b> <?php echo cengi_pp_h($obsJefe); ?></span><?php endif; ?></div></div>
            <?php elseif ($liqVer['estado'] === 'enviada' && empty($liqVer['firmada_en'])): ?><div class="pg-aviso"><span class="glyphicon glyphicon-time"></span><div><b>Falta la aprobación del Jefe de Transferencia</b> para esta versión. Administración podrá aprobarla cuando el Jefe la apruebe.</div></div><?php endif; ?>
            <?php if ($liqVer['estado'] !== 'anulada'): ?>
                <ol class="pg-pasos-liq" style="--n:<?php echo count($pasosLiq); ?>">
                    <?php foreach ($pasosLiq as $k => [$txt, $cuando, $quien, $hecho]): ?>
                        <li class="<?php echo $hecho ? 'is-ok' : ($k === $pasoActual ? 'is-actual' : ''); ?>"><span><?php echo $hecho ? '✓' : $k + 1; ?></span><div><b><?php echo $txt; ?></b><small><?php echo $cuando ? cengi_pp_fecha($cuando) . ($quien ? ' · ' . cengi_pp_h($quien) : '') : (strpos($txt, 'Recibos') === 0 ? cengi_pp_h($quien) : 'Pendiente'); ?></small></div></li>
                    <?php endforeach; ?>
                </ol>
            <?php else: ?>
                <div class="pg-aviso is-error"><span class="glyphicon glyphicon-ban-circle"></span><div><b>Anulada</b> el <?php echo cengi_pp_fecha($liqVer['anulada_en']) . ' por ' . cengi_pp_h($liqVer['anulada_por']); ?>: <?php echo cengi_pp_h($liqVer['motivo_anulacion']); ?></div></div>
            <?php endif; ?>
            <?php if ($liqVersiones): $uvM = $liqVersiones[0]; ?>
                <a class="pg-version-aviso" href="#pg-historial"><span class="pg-version">versión <?php echo $versionVigente; ?></span>
                    <span><b>Motivo de la corrección:</b> <?php echo cengi_pp_h($uvM['motivo']); ?><small><?php echo cengi_pp_fecha($uvM['creado']) . ' ' . date('H:i', strtotime($uvM['creado'])) . ' · ' . cengi_pp_h($uvM['usuario']); ?> · corrige la versión <?php echo (int) $uvM['version']; ?></small></span>
                    <span class="pg-version-aviso-ver">Historial (<?php echo count($liqVersiones); ?>) <span class="glyphicon glyphicon-menu-down"></span></span></a>
            <?php elseif (!$esBorrador && $liqVer['estado'] !== 'anulada'): ?>
                <p class="pg-version-orig"><span class="pg-version is-original">versión 1</span> Original, sin correcciones.<?php if ($esAdmin && in_array($liqVer['estado'], ['enviada', 'aprobada'], true)): ?> Si hay que corregirla, usa <b>Corregir</b>: quedará como versión 2 con su motivo.<?php endif; ?></p>
            <?php endif; ?>
            <div class="pp-strip">
                <div class="pp-mini-stat"><span>Personas</span><strong><?php echo count($porPersona); ?></strong></div>
                <div class="pp-mini-stat"><span>Sesiones</span><strong><?php echo array_sum(array_column($liqDetalle, 'sesiones')); ?></strong></div>
                <div class="pp-mini-stat"><span>Total neto</span><strong><?php echo cengi_pp_q(array_sum(array_column($liqDetalle, 'neto')), 2); ?></strong></div>
                <div class="pp-mini-stat"><span>Impuesto</span><strong><?php echo cengi_pp_q(array_sum(array_column($liqDetalle, 'impuesto')), 2); ?></strong></div>
                <div class="pp-mini-stat"><span>Total a pagar</span><strong><?php echo cengi_pp_q($liqVer['total'], 2); ?></strong></div>
            </div>
            <?php if (!in_array($liqVer['estado'], ['anulada', 'borrador', 'revision', 'autorizada'], true)): ?><div class="pg-avance-liq"><span>Avance del pago</span><?php echo cengi_pg_anillo($avLiq['pct'], 'is-sm'); ?><small><?php echo $liqVer['estado'] === 'pagada' ? 'Liquidación pagada' : $avLiq['listos'] . ' de ' . $avLiq['n'] . ' comprobante(s) listos para pago'; ?></small></div><?php endif; ?>
            <?php if (trim((string) $liqVer['nota']) !== ''): ?><div class="pp-msgbox" style="margin-bottom:14px;"><?php echo cengi_pp_h($liqVer['nota']); ?></div><?php endif; ?>
            <div class="pp-table-wrap"><table class="cengi-org-table pg-tabla">
                <thead><tr><th>Instructor / soporte</th><th>Curso</th><th>Fechas</th><th class="pg-num">Sesiones</th><th class="pg-num">Tarifa</th><th>Factura o recibo</th><th class="pg-num">Neto + imp.</th><th class="pg-num">Total</th></tr></thead>
                <tbody>
                    <?php foreach ($liqDetalle as $d): ?>
                        <tr>
                            <td><b><?php echo cengi_pp_h($d['persona_nombre']); ?></b><small class="pg-sub"><?php echo $d['tipo'] === 'soporte' ? 'Soporte técnico · ' : ''; ?><span class="pp-mono"><?php echo cengi_pp_h($d['persona_codigo']); ?></span></small>
                                <?php if ($d['observaciones'] !== ''): ?><small class="pg-sub"><i><?php echo cengi_pp_h($d['observaciones']); ?></i></small><?php endif; ?></td>
                            <td><?php echo cengi_pp_h($d['curso_nombre']); ?></td>
                            <td class="pg-fechas-celda"><?php echo cengi_pp_h(implode(', ', array_map('cengi_pp_fecha_corta', array_filter(explode(',', (string) $d['fechas']))))); ?></td>
                            <td class="pg-num"><?php echo (int) $d['sesiones']; ?></td>
                            <td class="pg-num"><?php echo cengi_pp_q($d['tarifa'], 2); ?></td>
                            <td><span class="pg-comp-chip is-<?php echo cengi_pp_h($d['comprobante']); ?>"><?php echo $d['comprobante'] === 'recibo' ? 'Recibo · pago neto' : 'Factura · + ' . $fmtPct($d['impuesto_pct']); ?></span><?php if ($d['comprobante'] === 'recibo' && trim((string) $d['documento_numero']) !== '' && !empty($d['id']) && $liqVer['estado'] !== 'anulada'): ?><a class="pg-recibo-pdf" href="pago_instructores.php?<?php echo cengi_pp_h(http_build_query(['ver' => $liqVer['id'], 'recibos' => 1, 'fila' => $d['id'], 'pdf' => 1])); ?>" target="_blank" rel="noopener" title="Abrir el recibo en PDF"><span class="glyphicon glyphicon-file"></span> <?php echo cengi_pp_h($d['documento_numero']); ?> · PDF</a><?php endif; ?><?php if ($d['comprobante'] === 'recibo' && (float) $d['impuesto_pct'] > 0): ?><small class="pg-falta">Liquidado con <?php echo $fmtPct($d['impuesto_pct']); ?>: debe ser el neto</small><?php endif; ?>
                                <small class="pg-comp-nota"><?php echo cengi_pp_h(cengi_pago_nota_comprobante($d['comprobante'], $d['comprobante'] === 'factura' ? $d['impuesto_pct'] : $pctFactura)); ?></small>
                                <?php if (!in_array($liqVer['estado'], ['anulada', 'borrador', 'revision', 'autorizada'], true) && isset($d['pct'])): ?><span class="pg-lp-avance is-celda <?php echo cengi_pp_h($d['paso_cls']); ?>"><?php echo cengi_pg_puntos($d['pasos_hechos'], $d['pasos_total']); ?><small><?php echo cengi_pp_h($d['paso']); ?></small></span><?php endif; ?></td>
                            <td class="pg-num"><?php echo cengi_pp_q($d['neto'], 2); ?><small>+ <?php echo cengi_pp_q($d['impuesto'], 2); ?></small></td>
                            <td class="pg-num pg-total"><?php echo cengi_pp_q($d['total'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td colspan="7">Total</td><td class="pg-num"><?php echo cengi_pp_q($liqVer['total'], 2); ?></td></tr></tfoot>
            </table></div>
            <?php if (count($porPersona) > 1): ?>
                <h5 class="pg-subtitulo">Total por persona</h5>
                <div class="pg-personas">
                    <?php foreach ($porPersona as $pp): ?>
                        <div><span><?php echo $pp['tipo'] === 'instructor' ? '<a href="ficha_instructor.php?id=' . $pp['id'] . '">' . cengi_pp_h($pp['nombre']) . '</a>' : cengi_pp_h($pp['nombre']); ?><small><?php echo $pp['sesiones']; ?> sesión(es)</small></span><b><?php echo cengi_pp_q($pp['total'], 2); ?></b></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php $recibosConImpuesto = array_filter($liqDetalle, static function ($d) { return $d['comprobante'] === 'recibo' && (float) $d['impuesto'] > 0; }); ?>
            <?php if ($recibosConImpuesto && $esAdmin && in_array($liqVer['estado'], ['enviada', 'aprobada'], true)): ?>
                <div class="pg-aviso is-error"><span class="glyphicon glyphicon-warning-sign"></span><div><b><?php echo count($recibosConImpuesto); ?> recibo(s) se liquidaron con impuesto</b> (<?php echo cengi_pp_h(implode(', ', array_unique(array_column($recibosConImpuesto, 'persona_nombre')))); ?>). Con recibo se paga el <b>neto</b>. Al aplicarlo se guarda una nueva versión de la liquidación y el recibo debe enviarse y firmarse de nuevo por el monto correcto (lo anterior queda en el expediente).
                    <form method="post" class="cengi-inline-form" onsubmit="return confirm('¿Dejar los recibos de esta liquidación en el valor neto (sin impuesto)?');"><input type="hidden" name="accion" value="neto_recibos"><input type="hidden" name="id" value="<?php echo (int) $liqVer['id']; ?>"> <button class="btn btn-primary btn-xs">Aplicar pago neto a los recibos</button></form></div></div>
            <?php endif; ?>
            <?php
            $facListas = count(array_filter($facturasLiq, static function ($d) { return trim((string) $d['documento_numero']) !== ''; }));
            if (($recibosLiq || $facturasLiq) && !$esBorrador): ?>
                <div class="pg-seguimiento">
                    <span class="glyphicon glyphicon-tasks"></span><b>Seguimiento del pago</b>
                    <?php if ($recibosLiq): ?><a href="pago_instructores.php?<?php echo cengi_pp_h(http_build_query(['tab' => 'recibos', 'rec' => 'todos', 'q' => $liqVer['numero']])); ?>"><span class="pg-comp-chip is-recibo">Recibos</span> <?php echo $resRec['validados'] . ' de ' . $resRec['total']; ?> firmados por el Jefe →</a><?php endif; ?>
                    <?php if ($facturasLiq): ?><a href="pago_instructores.php?<?php echo cengi_pp_h(http_build_query(['tab' => 'facturas', 'fac' => 'todos', 'q' => $liqVer['numero']])); ?>"><span class="pg-comp-chip is-factura">Facturas</span> <?php echo $facListas . ' de ' . count($facturasLiq); ?> registradas →</a><?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($liqVer['firmada_en'])): /* Firma de la liquidación completa: así se trabajaba antes; se conserva como registro. */ ?>
                <details class="pg-firma-anterior" open><summary>Aprobación del Jefe de Transferencia · <?php echo cengi_pp_h($liqVer['firma_nombre']); ?> · <?php echo cengi_pp_fecha($liqVer['firmada_en']); ?></summary>
                    <div class="pg-firma-bloque<?php echo $firmaValida ? '' : ' is-invalida'; ?>">
                        <img src="<?php echo cengi_pp_h($liqVer['firma_imagen']); ?>" alt="Firma">
                        <div><b><?php echo cengi_pp_h($liqVer['firma_nombre']); ?></b><span><?php echo cengi_pp_h($liqVer['firma_cargo']); ?></span>
                            <small>Aprobada y firmada electrónicamente el <?php echo date('d/m/Y H:i', strtotime($liqVer['firmada_en'])); ?> por el usuario <?php echo cengi_pp_h($liqVer['firmada_por']); ?></small>
                            <small class="pg-firma-codigo">Código de verificación <b><?php echo cengi_pp_h($liqVer['firma_codigo']); ?></b> · <?php echo $firmaValida ? '<span class="is-ok">✓ Contenido íntegro</span>' : '<span class="is-mal">✗ La liquidación cambió después de aprobarse</span>'; ?></small>
                            </div>
                    </div>
                </details>
            <?php endif; ?>

            <?php if ($puedeAprobarJefe): ?>
                <form method="post" id="aprobar-jefe-form" class="pg-form-accion is-enviar" hidden>
                    <input type="hidden" name="accion" value="firmar"><input type="hidden" name="id" value="<?php echo (int) $liqVer['id']; ?>">
                    <h5 class="pg-subtitulo">Aprobar liquidación <small>· como <?php echo cengi_pp_h($cfgPago['jefe_cargo'] ?: 'Jefe de Transferencia'); ?>, con tu firma</small></h5>
                    <?php if (trim((string) $cfgPago['firma']) === ''): ?><p class="pg-falta">Primero carga tu firma en “Configuración”.</p><?php endif; ?>
                    <label class="pg-check"><input type="checkbox" name="revisado" value="1" required> Revisé el detalle, quién cobra con recibo y quién con factura, y los montos.</label>
                    <div class="form-group" style="max-width:320px;"><label for="aj-pass">Tu contraseña <small>(confirma la aprobación)</small></label><input type="password" class="form-control" id="aj-pass" name="contrasena" required autocomplete="current-password"></div>
                    <div style="text-align:right;"><button type="button" class="btn btn-default" onclick="document.getElementById('aprobar-jefe-form').hidden=true;">Cancelar</button> <button type="submit" class="btn btn-primary"><span class="glyphicon glyphicon-check"></span> Aprobar y firmar</button></div>
                </form>
                <?php if ($liqVer['estado'] === 'revision'): ?>
                <form method="post" id="devolver-form" class="pg-form-accion is-corregir" hidden>
                    <input type="hidden" name="accion" value="devolver_jefe"><input type="hidden" name="id" value="<?php echo (int) $liqVer['id']; ?>">
                    <h5 class="pg-subtitulo">Devolver a Capacitación <small>· vuelve a borrador para que la corrijan</small></h5>
                    <div class="form-group"><label for="dv-obs">Observación <small>(qué se debe corregir)</small></label><textarea class="form-control" id="dv-obs" name="observacion" rows="2" maxlength="450" required></textarea></div>
                    <div style="text-align:right;"><button type="button" class="btn btn-default" onclick="document.getElementById('devolver-form').hidden=true;">Cancelar</button> <button type="submit" class="btn btn-primary">Devolver</button></div>
                </form>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($esAdmin && $liqVer['estado'] === 'autorizada'): ?>
                <form method="post" id="enviar-form" class="pg-form-accion is-enviar" hidden>
                    <input type="hidden" name="accion" value="enviar_liq"><input type="hidden" name="id" value="<?php echo (int) $liqVer['id']; ?>">
                    <h5 class="pg-subtitulo">Enviar a Administración <small>· se distribuye a recibos y facturas; después, los cambios quedan como nueva versión</small></h5>
                    <?php if ($recibosLiq): ?><label class="pg-check"><input type="checkbox" name="enviar_recibos" value="1" checked> Enviar por correo los <b><?php echo (int) $recibosLiq; ?> recibo(s)</b> para que cada persona los firme</label><?php endif; ?>
                    <?php if ($facturasLiq): ?><label class="pg-check"><input type="checkbox" name="enviar_requerimientos" value="1" checked> Enviar el <b>requerimiento de factura</b> a <?php echo count($facturasLiq); ?> persona(s), con el monto a facturar</label><?php endif; ?>
                    <div style="text-align:right;"><button type="button" class="btn btn-default" onclick="document.getElementById('enviar-form').hidden=true;">Cancelar</button> <button type="submit" class="btn btn-primary"><span class="glyphicon glyphicon-send"></span> Enviar liquidación</button></div>
                </form>
            <?php endif; ?>
            <?php if ($esAdmin && in_array($liqVer['estado'], ['borrador', 'revision', 'autorizada', 'enviada', 'aprobada'], true)): ?>
                <form method="post" id="corregir-form" class="pg-form-accion is-corregir" hidden>
                    <input type="hidden" name="accion" value="corregir"><input type="hidden" name="id" value="<?php echo (int) $liqVer['id']; ?>">
                    <?php if ($esBorrador): ?><h5 class="pg-subtitulo">Editar liquidación <small>· aún no se envía a Administración: no se crea versión<?php echo $liqVer['estado'] !== 'borrador' ? '. Al guardar vuelve a borrador y se retira la aprobación del Jefe' : ''; ?></small></h5><?php else: ?>
                    <h5 class="pg-subtitulo">Corregir liquidación <small>· quedará como versión <?php echo $versionVigente + 1; ?> con su motivo</small></h5>
                    <p class="pg-nota-modal">La versión actual (v<?php echo $versionVigente; ?>) queda guardada en el historial tal como está.<?php echo !empty($liqVer['firmada_en']) ? ' <b>Se retirará la firma del Jefe: deberá validarla de nuevo.</b>' : ''; ?><?php echo $liqVer['estado'] === 'aprobada' ? ' <b>Como ya está aprobada, al corregirla vuelve a “Enviada” y Administración debe aprobarla de nuevo.</b>' : ''; ?></p>
                    <div class="form-group pg-comentario-version"><label for="cg-motivo">Motivo de la corrección <small>(obligatorio: se muestra como motivo de la versión <?php echo $versionVigente + 1; ?>)</small></label><textarea class="form-control" id="cg-motivo" name="motivo" maxlength="500" rows="2" required placeholder="Ej. Se corrige la tarifa a Q450 por sesión según contrato"></textarea></div>
                    <?php endif; ?>
                    <div class="cengi-course-modal-grid">
                        <div class="form-group"><label for="cg-numero">N.° de liquidación</label><input type="text" class="form-control" id="cg-numero" name="numero" maxlength="40" value="<?php echo cengi_pp_h($liqVer['numero']); ?>" required></div>
                        <div class="form-group"><label for="cg-destino">Dirigida a</label><input type="text" class="form-control" id="cg-destino" name="destino" maxlength="255" value="<?php echo cengi_pp_h($liqVer['destino']); ?>"></div>
                        <div class="form-group is-full"><label for="cg-nota">Nota para Administración</label><textarea class="form-control" id="cg-nota" name="nota" rows="2"><?php echo cengi_pp_h($liqVer['nota']); ?></textarea></div>
                    </div>
                    <div class="pp-table-wrap"><table class="cengi-org-table pg-tabla" id="cg-lineas">
                        <thead><tr><th>Instructor / soporte · curso</th><th class="pg-num">Sesiones</th><th class="pg-num">Tarifa / sesión</th><th>Comprobante</th><th class="pg-num">Total</th><th>Observaciones</th><th class="pg-num">Quitar</th></tr></thead>
                        <tbody>
                            <?php foreach ($liqDetalle as $d): ?>
                                <tr data-sesiones="<?php echo (int) $d['sesiones']; ?>" data-original="<?php echo cengi_pp_h(number_format((float) $d['tarifa'], 2, '.', '')); ?>">
                                    <td><b><?php echo cengi_pp_h($d['persona_nombre']); ?></b><small class="pg-sub"><?php echo cengi_pp_h($d['curso_nombre']); ?></small></td>
                                    <td class="pg-num"><span class="pg-ses"><?php echo (int) $d['sesiones']; ?></span></td>
                                    <td class="pg-num"><span class="pg-q"><span>Q</span><input type="number" class="pp-input-inline" name="lineas[<?php echo (int) $d['id']; ?>][tarifa]" min="0" step="0.01" value="<?php echo cengi_pp_h(number_format((float) $d['tarifa'], 2, '.', '')); ?>" aria-label="Tarifa"></span></td>
                                    <td><select class="pg-comp" name="lineas[<?php echo (int) $d['id']; ?>][comprobante]" aria-label="Comprobante">
                                        <option value="factura" data-pct="<?php echo $d['comprobante'] === 'factura' ? (float) $d['impuesto_pct'] : $pctFactura; ?>" <?php echo $d['comprobante'] === 'factura' ? 'selected' : ''; ?>>Factura · <?php echo $fmtPct($d['comprobante'] === 'factura' ? $d['impuesto_pct'] : $pctFactura); ?></option>
                                        <option value="recibo" data-pct="<?php echo $d['comprobante'] === 'recibo' ? (float) $d['impuesto_pct'] : $pctRecibo; ?>" <?php echo $d['comprobante'] === 'recibo' ? 'selected' : ''; ?>>Recibo · <?php echo $fmtPct($d['comprobante'] === 'recibo' ? $d['impuesto_pct'] : $pctRecibo); ?></option>
                                    </select></td>
                                    <td class="pg-num pg-total" data-total><?php echo cengi_pp_q($d['total'], 2); ?></td>
                                    <td><input type="text" class="pp-input-obs" name="lineas[<?php echo (int) $d['id']; ?>][obs]" maxlength="500" value="<?php echo cengi_pp_h($d['observaciones']); ?>" placeholder="Opcional" aria-label="Observaciones"></td>
                                    <td class="pg-num"><input type="checkbox" name="lineas[<?php echo (int) $d['id']; ?>][quitar]" value="1" class="pg-quitar" aria-label="Quitar fila" title="Quitar: sus sesiones vuelven a “Por liquidar”"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot><tr><td colspan="4">Total corregido <small id="cg-diferencia"></small></td><td class="pg-num" id="cg-total"><?php echo cengi_pp_q($liqVer['total'], 2); ?></td><td colspan="2"></td></tr></tfoot>
                    </table></div>
                    <?php if ($pendientesLiq['sesiones']): ?>
                        <label class="pg-check"><input type="checkbox" name="agregar" value="1"> Agregar las <b><?php echo $pendientesLiq['sesiones']; ?> sesión(es) impartida(s) pendientes</b> del período (<?php echo cengi_pp_h(implode(', ', array_keys($pendientesLiq['personas']))); ?>) <small>(con la tarifa de cada fila o la tarifa registrada)</small></label>
                    <?php endif; ?>
                    <div style="text-align:right;"><button type="button" class="btn btn-default" onclick="document.getElementById('corregir-form').hidden=true;">Cancelar</button> <button type="submit" class="btn btn-primary"><span class="glyphicon glyphicon-floppy-disk"></span> <?php echo $esBorrador ? 'Guardar cambios' : 'Guardar nueva versión'; ?></button></div>
                </form>
            <?php endif; ?>

            <?php if ($liqVersiones): ?>
                <h5 class="pg-subtitulo" id="pg-historial">Historial de versiones <small>· vigente: versión <?php echo $versionVigente; ?></small></h5>
                <div class="pg-versiones">
                    <?php foreach ($liqVersiones as $v):
                        $copia = json_decode($v['datos'], true) ?: [];
                        $diferencia = (float) $v['total_despues'] - (float) $v['total_antes']; ?>
                        <details class="pg-version-item">
                            <summary>
                                <span class="pg-version-num">v<?php echo (int) $v['version'] + 1; ?></span>
                                <span class="pg-version-txt"><b><?php echo cengi_pp_h($v['motivo']); ?></b>
                                    <small><?php echo cengi_pp_fecha($v['creado']) . ' ' . date('H:i', strtotime($v['creado'])) . ' · ' . cengi_pp_h($v['usuario']); ?> · corrige la versión <?php echo (int) $v['version']; ?></small></span>
                                <span class="pg-version-total"><?php echo cengi_pp_q($v['total_antes'], 2); ?> → <b><?php echo cengi_pp_q($v['total_despues'], 2); ?></b>
                                    <?php if (abs($diferencia) >= 0.005): ?><small class="<?php echo $diferencia > 0 ? 'is-sube' : 'is-baja'; ?>"><?php echo ($diferencia > 0 ? '+' : '−') . cengi_pp_q(abs($diferencia), 2); ?></small><?php endif; ?></span>
                            </summary>
                            <ul class="pg-version-cambios"><?php foreach (array_filter(explode("\n", (string) $v['cambios'])) as $cambio): ?><li><?php echo cengi_pp_h($cambio); ?></li><?php endforeach; ?></ul>
                            <div class="pg-version-copia">
                                <div class="pg-version-copia-cab"><span>Así estaba la <b>versión <?php echo (int) $v['version']; ?></b> (<?php echo cengi_pp_h($copia['liquidacion']['numero'] ?? ''); ?> · <?php echo cengi_pp_h(CENGI_PP_ESTADOS_LIQ[$copia['liquidacion']['estado'] ?? '']['0'] ?? ''); ?>)</span>
                                    <a class="pp-link" href="pago_instructores.php?<?php echo cengi_pp_h(http_build_query(['ver' => $liqVer['id'], 'exportar' => 'excel', 'version' => $v['version']])); ?>"><span class="glyphicon glyphicon-download-alt"></span> Planilla de esta versión</a></div>
                                <table class="pg-version-tabla">
                                    <?php foreach ((array) ($copia['detalle'] ?? []) as $cd): ?>
                                        <tr><td><?php echo cengi_pp_h($cd['persona_nombre']); ?> <small><?php echo cengi_pp_h($cd['curso_nombre']); ?></small></td><td class="pg-num"><?php echo (int) $cd['sesiones']; ?> × <?php echo cengi_pp_q($cd['tarifa'], 2); ?></td><td class="pg-num"><b><?php echo cengi_pp_q($cd['total'], 2); ?></b></td></tr>
                                    <?php endforeach; ?>
                                </table>
                            </div>
                        </details>
                    <?php endforeach; ?>
                    <div class="pg-version-item is-origen"><span class="pg-version-num">v1</span><span class="pg-version-txt"><b>Liquidación generada</b><small><?php echo cengi_pp_fecha($liqVer['creado']) . ' · ' . cengi_pp_h($liqVer['creado_por_nombre']); ?></small></span></div>
                </div>
            <?php endif; ?>

            <?php if ($liqVer['estado'] === 'aprobada'): ?>
                <form method="post" id="pagar-form" class="pg-form-accion" hidden>
                    <input type="hidden" name="accion" value="pagar"><input type="hidden" name="id" value="<?php echo (int) $liqVer['id']; ?>">
                    <h5 class="pg-subtitulo">Registrar pago</h5>
                    <div class="cengi-course-modal-grid">
                        <div class="form-group"><label for="pg-forma">Forma de pago</label><select class="form-control" id="pg-forma" name="pago_forma"><option>Transferencia</option><option>Cheque</option><option>Depósito</option><option>Efectivo</option></select></div>
                        <div class="form-group"><label for="pg-ref">Referencia</label><input type="text" class="form-control" id="pg-ref" name="pago_referencia" maxlength="120" placeholder="N.° de transferencia o cheque"></div>
                        <div class="form-group"><label for="pg-fecha">Fecha de pago</label><input type="date" class="form-control" id="pg-fecha" name="pago_fecha" value="<?php echo $hoy; ?>" max="<?php echo $hoy; ?>" required></div>
                    </div>
                    <?php if ($facturasLiq): ?>
                        <div class="pg-facturas"><span>N.° de factura <small>(obligatorio para pagar a quienes cobran con factura)</small></span>
                            <?php foreach ($facturasLiq as $fd): ?><label><?php echo cengi_pp_h($fd['persona_nombre'] . ' · ' . $fd['curso_nombre']); ?><input type="text" class="form-control input-sm" name="factura[<?php echo (int) $fd['id']; ?>]" maxlength="40" value="<?php echo cengi_pp_h($fd['documento_numero']); ?>" placeholder="Serie y número" required></label><?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <label class="pg-check"><input type="checkbox" name="avisar" value="1" checked> Avisar por correo a cada instructor y soporte con el detalle de su pago <small>(queda en Comunicaciones)</small></label>
                    <div style="text-align:right;margin-top:10px;"><button type="submit" class="btn btn-primary">Guardar pago</button></div>
                </form>
            <?php endif; ?>
            <?php if (in_array($liqVer['estado'], ['enviada', 'aprobada'], true)): ?>
                <form method="post" id="anular-form" class="pg-form-accion is-peligro" hidden>
                    <input type="hidden" name="accion" value="anular"><input type="hidden" name="id" value="<?php echo (int) $liqVer['id']; ?>">
                    <div class="form-group"><label for="an-motivo">Motivo de la anulación <small>(las sesiones vuelven a “Por liquidar”)</small></label><input type="text" class="form-control" id="an-motivo" name="motivo" maxlength="500" required></div>
                    <div style="text-align:right;"><button type="submit" class="btn btn-danger">Anular liquidación</button></div>
                </form>
            <?php endif; ?>
        </div>
        <script>
        // Muestra un formulario de acción del modal (y oculta los demás).
        function pgMostrar(id, foco) {
            ['corregir-form', 'anular-form', 'pagar-form', 'enviar-form', 'aprobar-jefe-form', 'devolver-form'].forEach(function (i) { var f = document.getElementById(i); if (f) f.hidden = i !== id; });
            var e = document.getElementById(id);
            if (!e) return;
            e.scrollIntoView({ behavior: 'smooth', block: 'start' });
            if (foco) setTimeout(function () { var x = document.getElementById(foco); if (x) x.focus({ preventScroll: true }); }, 300);
        }
        <?php if (!empty($_GET['aprobar']) && $puedeAprobarJefe): ?>setTimeout(function () { pgMostrar('aprobar-jefe-form', 'aj-pass'); }, 450);<?php endif; ?>
        </script>
        <div class="modal-footer">
            <?php if ($esAdmin && in_array($liqVer['estado'], ['enviada', 'aprobada'], true)): ?><button type="button" class="btn btn-link pg-anular" onclick="pgMostrar('anular-form', 'an-motivo');">Anular</button><?php endif; ?>
            <?php if ($esAdmin && $esBorrador): ?><form method="post" class="cengi-inline-form" onsubmit="return confirm('¿Eliminar la liquidación <?php echo cengi_pp_h(addslashes($liqVer['numero'])); ?>? Aún no se ha enviado; sus sesiones vuelven a “Por liquidar”.');"><input type="hidden" name="accion" value="eliminar_borrador"><input type="hidden" name="id" value="<?php echo (int) $liqVer['id']; ?>"><button type="submit" class="btn btn-link pg-anular">Eliminar</button></form><?php endif; ?>
            <?php if ($puedeAprobarJefe && $liqVer['estado'] === 'revision'): ?><button type="button" class="btn btn-link pg-anular" onclick="pgMostrar('devolver-form', 'dv-obs');">Devolver con observación</button><?php endif; ?>
            <?php if ($esAdmin && in_array($liqVer['estado'], ['borrador', 'revision', 'autorizada', 'enviada', 'aprobada'], true)): ?><button type="button" class="btn btn-default" id="cg-abrir" title="<?php echo $esBorrador ? 'Aún no se envía: se edita sin crear versión' . ($liqVer['estado'] !== 'borrador' ? '. Vuelve a borrador y se retira la aprobación del Jefe' : '') : 'Ya se envió: el cambio queda como nueva versión con su motivo'; ?>"><span class="glyphicon glyphicon-pencil"></span> <?php echo $esBorrador ? 'Editar' : 'Corregir'; ?></button><?php endif; ?>
            <button type="button" class="btn btn-default" onclick="window.print();"><span class="glyphicon glyphicon-print"></span> Imprimir</button>
            <a class="btn btn-default" href="pago_instructores.php?<?php echo cengi_pp_h(http_build_query(['ver' => $liqVer['id'], 'exportar' => 'excel'])); ?>"><span class="glyphicon glyphicon-download-alt"></span> Descargar</a>
            <?php if ($esAdmin && $liqVer['estado'] === 'borrador'): ?>
                <form method="post" class="cengi-inline-form" onsubmit="return confirm('¿Enviar la liquidación <?php echo cengi_pp_h(addslashes($liqVer['numero'])); ?> al <?php echo cengi_pp_h(addslashes($cfgPago['jefe_cargo'] ?: 'Jefe de Transferencia')); ?> para su aprobación?');"><input type="hidden" name="accion" value="solicitar_jefe"><input type="hidden" name="id" value="<?php echo (int) $liqVer['id']; ?>"><button type="submit" class="btn btn-primary"><span class="glyphicon glyphicon-send"></span> Enviar al Jefe para aprobación</button></form>
            <?php elseif ($puedeAprobarJefe): ?>
                <button type="button" class="btn btn-primary" onclick="pgMostrar('aprobar-jefe-form', 'aj-pass');"><span class="glyphicon glyphicon-check"></span> Aprobar liquidación</button>
            <?php elseif ($liqVer['estado'] === 'revision'): ?>
                <button type="button" class="btn btn-primary" disabled><span class="glyphicon glyphicon-time"></span> Esperando aprobación del Jefe</button>
            <?php elseif ($esAdmin && $liqVer['estado'] === 'autorizada'): ?>
                <button type="button" class="btn btn-primary" onclick="pgMostrar('enviar-form');"><span class="glyphicon glyphicon-send"></span> Enviar a Administración</button>
            <?php elseif ($esAdmin && $liqVer['estado'] === 'enviada' && empty($liqVer['firmada_en'])): ?>
                <button type="button" class="btn btn-primary" disabled title="El Jefe de Transferencia debe aprobar esta versión"><span class="glyphicon glyphicon-time"></span> Falta la aprobación del Jefe</button>
            <?php elseif ($esAdmin && $liqVer['estado'] === 'enviada' && !$recibosListos): ?>
                <button type="button" class="btn btn-primary" disabled title="Faltan recibos firmados o validados"><span class="glyphicon glyphicon-time"></span> <?php echo $resRec['faltan'] ? 'Esperando recibos firmados' : 'Esperando validación de recibos'; ?></button>
            <?php elseif ($esAdmin && $liqVer['estado'] === 'enviada'): ?>
                <form method="post" class="cengi-inline-form" onsubmit="return confirm('¿Confirmas que Administración aprobó la liquidación <?php echo cengi_pp_h(addslashes($liqVer['numero'])); ?>?');"><input type="hidden" name="accion" value="aprobar"><input type="hidden" name="id" value="<?php echo (int) $liqVer['id']; ?>"><button type="submit" class="btn btn-primary"><span class="glyphicon glyphicon-check"></span> Aprobada por Administración</button></form>
            <?php elseif ($esAdmin && $liqVer['estado'] === 'aprobada'): ?>
                <button type="button" class="btn btn-primary" onclick="pgMostrar('pagar-form', 'pg-ref');"><span class="glyphicon glyphicon-usd"></span> Registrar pago</button>
            <?php else: ?>
                <a class="btn btn-primary" href="pago_instructores.php?<?php echo $volver; ?>">Cerrar</a>
            <?php endif; ?>
        </div>
    </div></div>
</div>
<?php endif; ?>

<script>
(function () {
    'use strict';
    function q(n) { return 'Q' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    // % de impuesto de la fila según su comprobante (factura o recibo), en fracción.
    function pctFila(tr) {
        var sel = tr.querySelector('select.pg-comp');
        return sel ? (parseFloat(sel.options[sel.selectedIndex].getAttribute('data-pct')) || 0) / 100 : 0;
    }
    var tipo = document.getElementById('pago-tipo');
    if (tipo) tipo.addEventListener('change', function () {
        document.getElementById('pago-mes-campo').hidden = this.value === 'rango';
        document.getElementById('pago-rango').hidden = this.value !== 'rango';
        if (this.value === 'mes') this.form.submit();
    });
    var form = document.getElementById('liquidar-form');
    document.querySelectorAll('select.pg-comp').forEach(function (sel) {
        sel.addEventListener('change', function () {
            var nota = sel.parentNode.querySelector('[data-comp-nota]');
            if (nota) nota.textContent = sel.options[sel.selectedIndex].getAttribute('data-nota') || '';
        });
    });
    var bloqueado = <?php echo $rangoExcesivo ? 'true' : 'false'; ?>;
    function recalcular() {
        if (!form) return;
        var neto = 0, imp = 0, filas = 0, personas = {};
        form.querySelectorAll('tbody tr[data-sesiones]').forEach(function (tr) {
            var t = parseFloat(tr.querySelector('input[type="number"]').value) || 0, n = +tr.getAttribute('data-sesiones');
            var fn = Math.round(t * n * 100) / 100, fi = Math.round(fn * pctFila(tr) * 100) / 100;
            var marcada = tr.querySelector('input[type="checkbox"]').checked;
            tr.querySelector('[data-neto]').textContent = q(fn); tr.querySelector('[data-imp]').textContent = q(fi); tr.querySelector('[data-total]').textContent = q(fn + fi);
            tr.classList.toggle('is-excluida', !marcada);
            tr.classList.toggle('is-sin-tarifa', !(t > 0));
            if (marcada) { neto += fn; imp += fi; filas++; personas[tr.querySelector('[data-persona]').textContent] = 1; }
        });
        var set = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = v; };
        set('pago-neto', q(neto)); set('pago-imp', q(imp)); set('pago-total', q(neto + imp));
        set('pago-filas', filas); set('pago-personas', Object.keys(personas).length);
        var b = document.getElementById('pago-generar'); if (b) b.disabled = !filas || bloqueado;
        set('gl-resumen', Object.keys(personas).length + ' persona(s) · ' + filas + ' fila(s) · ' + q(neto + imp) + ' con impuesto');
    }
    if (form) {
        form.addEventListener('input', recalcular); form.addEventListener('change', recalcular);
        var todos = document.getElementById('pago-todos');
        if (todos) todos.addEventListener('change', function () { form.querySelectorAll('input[name="sel[]"]').forEach(function (c) { c.checked = todos.checked; }); recalcular(); });
        recalcular();
    }
    // Corregir liquidación: total en vivo y filas marcadas para quitar.
    var corregir = document.getElementById('corregir-form');
    function recalcularCorreccion() {
        if (!corregir) return;
        var total = 0, original = <?php echo $liqVer ? (float) $liqVer['total'] : 0; ?>;
        corregir.querySelectorAll('#cg-lineas tbody tr').forEach(function (tr) {
            var t = parseFloat(tr.querySelector('input[type="number"]').value) || 0, n = +tr.getAttribute('data-sesiones');
            var neto = Math.round(t * n * 100) / 100, fila = neto + Math.round(neto * pctFila(tr) * 100) / 100;
            var quitar = tr.querySelector('.pg-quitar').checked, comp = tr.querySelector('select.pg-comp');
            tr.querySelector('[data-total]').textContent = q(fila);
            tr.classList.toggle('is-excluida', quitar);
            tr.classList.toggle('is-cambiada', !quitar && (Math.abs(t - parseFloat(tr.getAttribute('data-original'))) >= 0.005 || (comp && comp.value !== tr.getAttribute('data-comp-original'))));
            if (!quitar) total += fila;
        });
        document.getElementById('cg-total').textContent = q(total);
        var dif = Math.round((total - original) * 100) / 100;
        document.getElementById('cg-diferencia').textContent = dif ? '(' + (dif > 0 ? '+' : '−') + q(Math.abs(dif)) + ' vs. versión actual)' : '';
    }
    if (corregir) {
        corregir.addEventListener('input', recalcularCorreccion);
        corregir.addEventListener('change', recalcularCorreccion);
        document.getElementById('cg-abrir').addEventListener('click', function () {
            ['pagar-form', 'anular-form', 'enviar-form'].forEach(function (id) { var f = document.getElementById(id); if (f) f.hidden = true; });
            corregir.hidden = false;
            corregir.scrollIntoView({ behavior: 'smooth', block: 'start' });
            var motivo = document.getElementById('cg-motivo');
            if (motivo) setTimeout(function () { motivo.focus({ preventScroll: true }); }, 300);
        });
        <?php if (!empty($_GET['editar'])): ?>setTimeout(function () { document.getElementById('cg-abrir').click(); }, 450);<?php endif; ?>
        corregir.addEventListener('submit', function (e) {
            var filas = corregir.querySelectorAll('#cg-lineas tbody tr').length, quitadas = corregir.querySelectorAll('.pg-quitar:checked').length;
            var agregar = corregir.querySelector('[name="agregar"]');
            if (filas && quitadas === filas && !(agregar && agregar.checked)) { e.preventDefault(); alert('No puedes quitar todas las filas. Si la liquidación ya no aplica, anúlala.'); return; }
            if (quitadas && !confirm('Se quitarán ' + quitadas + ' fila(s): sus sesiones vuelven a “Por liquidar”. ¿Continuar?')) e.preventDefault();
        });
        recalcularCorreccion();
    }
    // Firma dibujada en el lienzo de Configuración (mouse, lápiz o dedo) -> PNG en firma_data.
    var lienzo = document.getElementById('cf-firma-lienzo');
    if (lienzo) {
        var ctx = lienzo.getContext('2d'), dibujando = false, hayTrazo = false;
        ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#1b2a44';
        var punto = function (e) {
            var r = lienzo.getBoundingClientRect();
            return { x: (e.clientX - r.left) * lienzo.width / r.width, y: (e.clientY - r.top) * lienzo.height / r.height };
        };
        lienzo.addEventListener('pointerdown', function (e) { dibujando = true; hayTrazo = true; lienzo.setPointerCapture(e.pointerId); var p = punto(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); });
        lienzo.addEventListener('pointermove', function (e) { if (!dibujando) return; var p = punto(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); });
        ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) { lienzo.addEventListener(ev, function () { dibujando = false; }); });
        document.getElementById('cf-firma-limpiar').addEventListener('click', function () { ctx.clearRect(0, 0, lienzo.width, lienzo.height); hayTrazo = false; });
        document.getElementById('config-form').addEventListener('submit', function () {
            document.getElementById('cf-firma-data').value = hayTrazo ? lienzo.toDataURL('image/png') : '';
        });
    }
    // El DPI se guarda solo con dígitos: se agrupa 4-5-4 al escribir.
    document.querySelectorAll('.pg-dpi').forEach(function (inp) {
        inp.addEventListener('input', function () {
            var d = inp.value.replace(/\D/g, '').slice(0, 13);
            inp.value = [d.slice(0, 4), d.slice(4, 9), d.slice(9)].filter(Boolean).join(' ');
        });
    });
    if (document.getElementById('liq-modal')) {
        $('#liq-modal').modal({ backdrop: 'static', show: true });
    }
})();
</script>
</body>
</html>
