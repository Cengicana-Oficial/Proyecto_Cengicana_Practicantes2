<?php
/**
 * Proformas de pago para ingenios (Pagos de inscripcion -> Proformas y proforma.php).
 * Esquema: cengicursos/migrations/20261014_proformas_pago.sql.
 *
 * Una proforma tiene una linea por curso. Cada linea es tambien la orden grupal del
 * ingenio para ese curso (pagos_grupales.proforma_id, con monto fijo = total de la linea
 * y "cupos" = cantidad). Los inscritos seleccionados se amarran a esa orden con el precio
 * de la proforma (descuento incluido); los cupos sin nombre se llenan solos cuando el
 * ingenio inscribe a mas personas (cengi_pf_auto_amarrar desde guardar_inscripcion.php).
 * Al registrar el pago de la proforma, la orden queda pagada y todos los amarrados quedan
 * validados (cuentan para el cupo del curso). Al anularla, vuelven a la orden normal del
 * ingenio con la cuota regular.
 */

require_once __DIR__ . '/inscripcion_helpers.php';

const CENGI_PF_ESTADOS = [
    'emitida' => ['Emitida', 'is-upcoming'],
    'enviada' => ['Enviada al cliente', 'is-pending'],
    'pagada' => ['Pagada', 'is-active'],
    'anulada' => ['Anulada', 'is-neutral'],
    'vencida' => ['Vencida', 'is-rejected'],
];
const CENGI_PF_TIPOS = ['asociado' => 'Ingenio asociado', 'externo' => 'Externo', 'extranjero' => 'Extranjero'];
const CENGI_PF_VIGENCIA_DIAS = 30;
const CENGI_PF_TEXTOS = [
    'descripcion' => 'Por medio de la presente se emite la proforma correspondiente a los servicios de capacitación solicitados. Este documento tiene como finalidad respaldar el proceso administrativo y de gestión de pago para la participación en las actividades de formación incluidas en el detalle siguiente.',
    'condiciones' => 'El monto corresponde a una DONACIÓN, dado que somos una organización sin fines de lucro.',
    'vigencia' => '30 días calendario a partir de la fecha de emisión.',
    'obs1' => 'El documento para girar por parte de CENGICAÑA es un Recibo Contable por Donación, autorizado por la SAT.',
    'obsExt' => 'Si el pago es en el extranjero: el monto para cancelar es en dólares, quedando libre de pago de impuesto (la tarifa de impuesto es aplicada al porcentaje establecido de su país, este costo deberá ser cancelado de manera adicional al monto total a cancelar).',
    'cupos' => 'Los cupos sin nombre se asignarán a los participantes que el ingenio inscriba para cada curso.',
];
const CENGI_PF_INSTITUCION = 'Centro Guatemalteco de Investigación y Capacitación de la Caña de Azúcar - CENGICAÑA';

// ---------------------------------------------------------------- configuracion

function cengi_pf_config(PDO $db, $clave, $defecto)
{
    $stmt = $db->prepare('SELECT valor FROM eventos_config WHERE clave = ?');
    $stmt->execute([$clave]);
    $valor = $stmt->fetchColumn();
    if ($valor === false) {
        return $defecto;
    }
    $json = json_decode((string) $valor, true);
    return $json === null ? $defecto : $json;
}

function cengi_pf_guardar_config(PDO $db, $clave, $valor)
{
    $db->prepare('INSERT INTO eventos_config (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')
        ->execute([$clave, json_encode($valor, JSON_UNESCAPED_UNICODE)]);
}

/** ['GTQ' => cuenta nacional, 'USD' => cuenta del extranjero, 'cargo' => US$ por transferencia internacional] */
function cengi_pf_cuentas(PDO $db)
{
    $base = ['inst' => CENGI_PF_INSTITUCION, 'banco' => '', 'tipo' => '', 'numero' => '', 'correo' => ''];
    return [
        'GTQ' => array_merge($base, ['tipo' => 'Monetaria'], (array) cengi_pf_config($db, 'pf_cuenta_gtq', [])),
        'USD' => array_merge($base, ['tipo' => 'Monetaria en dólares', 'swift' => '', 'intermediario' => ''], (array) cengi_pf_config($db, 'pf_cuenta_usd', [])),
        'cargo' => (float) cengi_pf_config($db, 'pf_cargo_usd', 35),
    ];
}

function cengi_pf_cuenta_completa(array $cuenta)
{
    return trim($cuenta['banco'] ?? '') !== '' && trim($cuenta['numero'] ?? '') !== '';
}

// ---------------------------------------------------------------- consultas

function cengi_pf_estado(array $pf)
{
    return in_array($pf['estado'], ['emitida', 'enviada'], true) && $pf['vence'] < cengi_pp_hoy() ? 'vencida' : $pf['estado'];
}

function cengi_pf_dinero($monto, $moneda)
{
    return ($moneda === 'USD' ? 'US$' : 'Q') . number_format((float) $monto, 2);
}

function cengi_pf_log(PDO $db, $proformaId, $texto, $detalle = '')
{
    $db->prepare('INSERT INTO proforma_historial (proforma_id, texto, detalle, por) VALUES (?, ?, ?, ?)')
        ->execute([(int) $proformaId, $texto, mb_substr((string) $detalle, 0, 500, 'UTF-8'), cengi_pp_usuario_nombre()]);
}

/** Proforma con ingenio, lineas (con sus inscritos amarrados) e historial; null si no existe. */
function cengi_pf_cargar(PDO $db, $id, $bloquear = false)
{
    $stmt = $db->prepare('SELECT pf.*, i.nombre_ingenios AS empresa FROM proformas pf INNER JOIN ingenios i ON i.id = pf.ingenio_id WHERE pf.id = ?' . ($bloquear ? ' FOR UPDATE' : ''));
    $stmt->execute([(int) $id]);
    $pf = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pf) {
        return null;
    }
    $stmt = $db->prepare('SELECT l.*, p.nombre AS plan_nombre, p.fecha_inicio, g.estado AS grupo_estado FROM proforma_lineas l
        INNER JOIN planificacion_cursos p ON p.id = l.planificacion_id LEFT JOIN pagos_grupales g ON g.id = l.grupo_pago_id
        WHERE l.proforma_id = ? ORDER BY p.fecha_inicio, l.id');
    $stmt->execute([(int) $id]);
    $pf['lineas'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmtIns = $db->prepare("SELECT id_solicitud, nombre_participante, cui_participante, validacion_ingenio, estado FROM solicitudes_inscripcion
        WHERE grupo_pago_id = ? AND estado <> 'Rechazado' ORDER BY nombre_participante");
    foreach ($pf['lineas'] as &$l) {
        $l['inscritos'] = [];
        if ($l['grupo_pago_id']) {
            $stmtIns->execute([(int) $l['grupo_pago_id']]);
            $l['inscritos'] = $stmtIns->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    unset($l);
    $stmt = $db->prepare('SELECT * FROM proforma_historial WHERE proforma_id = ? ORDER BY creado, id');
    $stmt->execute([(int) $id]);
    $pf['historial'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pf['estado_vista'] = cengi_pf_estado($pf);
    return $pf;
}

/** Cursos a los que se les puede emitir proforma: convocados, sin terminar y con costo. */
function cengi_pf_cursos(PDO $db)
{
    return $db->query("SELECT * FROM planificacion_cursos p WHERE estado IN ('aprobado', 'reprogramado') AND (fecha_fin >= CURDATE() OR EXISTS(SELECT 1 FROM solicitudes_inscripcion s LEFT JOIN pagos_grupales g ON g.id=s.grupo_pago_id WHERE s.planificacion_id=p.id AND s.tipo_pago='Ingenio' AND s.estado<>'Rechazado' AND COALESCE(g.estado,'pendiente')<>'pagado'))
        AND (costo_asociados > 0 OR costo_externos > 0 OR costo_extranjero > 0) ORDER BY fecha_inicio, nombre")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Inscritos que el ingenio paga y que pueden ir en una proforma: pago del ingenio sin
 * cerrar y que no esten ya en otra proforma vigente. [planificacion_id => [solicitudes]].
 * Incluye 'en_proforma' (numero) para mostrar los que ya estan en otra.
 */
function cengi_pf_candidatos(PDO $db, $ingenioId, $planId = 0)
{
    $stmt = $db->prepare("SELECT s.id_solicitud, s.planificacion_id, s.nombre_participante, s.cui_participante, s.correo, s.validacion_ingenio,
            s.grupo_pago_id, g.estado AS grupo_estado, pf.numero AS en_proforma
        FROM solicitudes_inscripcion s
        LEFT JOIN pagos_grupales g ON g.id = s.grupo_pago_id
        LEFT JOIN proformas pf ON pf.id = g.proforma_id AND pf.estado <> 'anulada'
        WHERE s.ingenio_id = ? AND s.tipo_pago = 'Ingenio' AND s.estado <> 'Rechazado' AND s.planificacion_id IS NOT NULL
          AND COALESCE(g.estado, 'pendiente') <> 'pagado' AND COALESCE(s.pago_estado,'pendiente') <> 'pagado'" . ($planId ? ' AND s.planificacion_id = ' . (int) $planId : '') . '
        ORDER BY s.nombre_participante');
    $stmt->execute([(int) $ingenioId]);
    $porPlan = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $porPlan[(int) $s['planificacion_id']][] = $s;
    }
    return $porPlan;
}

/** Linea de una proforma vigente del ingenio para el curso que aun tiene cupos sin nombre. */
function cengi_pf_linea_libre(PDO $db, $planId, $ingenioId)
{
    $stmt = $db->prepare("SELECT l.*, pf.numero, pf.estado AS pf_estado, pf.vence, pf.tipo_cliente, pf.moneda,
            (SELECT COUNT(*) FROM solicitudes_inscripcion s WHERE s.grupo_pago_id = l.grupo_pago_id AND s.estado <> 'Rechazado') AS ocupados
        FROM proforma_lineas l INNER JOIN proformas pf ON pf.id = l.proforma_id
        WHERE l.planificacion_id = ? AND pf.ingenio_id = ? AND l.grupo_pago_id IS NOT NULL
          AND (pf.estado = 'pagada' OR (pf.estado IN ('emitida', 'enviada') AND pf.vence >= CURDATE()))
        ORDER BY pf.numero");
    $stmt->execute([(int) $planId, (int) $ingenioId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $l) {
        if ((int) $l['ocupados'] < (int) $l['cantidad']) {
            return $l;
        }
    }
    return null;
}

// ---------------------------------------------------------------- operaciones (dentro de una transaccion)

/**
 * Amarra una inscripcion a la linea: pasa a su orden con el precio de la proforma.
 * $linea necesita grupo_pago_id, precio, desc_unit; $pf: numero, estado, tipo_cliente,
 * moneda y empresa (para la validacion automatica si ya esta pagada).
 */
function cengi_pf_amarrar(PDO $db, array $pf, array $linea, $solicitudId)
{
    $stmt = $db->prepare('SELECT grupo_pago_id FROM solicitudes_inscripcion WHERE id_solicitud = ? FOR UPDATE');
    $stmt->execute([(int) $solicitudId]);
    $grupoAnterior = (int) $stmt->fetchColumn();
    $db->prepare('UPDATE solicitudes_inscripcion SET grupo_pago_id = ?, cuota = ?, monto = ?, moneda = ?, descuento = ? WHERE id_solicitud = ?')
        ->execute([(int) $linea['grupo_pago_id'], $pf['tipo_cliente'], round((float) $linea['precio'] - (float) $linea['desc_unit'], 2), $pf['moneda'],
            (float) $linea['desc_unit'] > 0 ? (float) $linea['desc_unit'] : null, (int) $solicitudId]);
    if ($grupoAnterior && $grupoAnterior !== (int) $linea['grupo_pago_id']) {
        cengi_ins_recalcular_grupo($db, $grupoAnterior);
        // La orden normal que quedo vacia y sin movimiento se elimina.
        $db->prepare("DELETE FROM pagos_grupales WHERE id = ? AND proforma_id IS NULL AND estado = 'pendiente' AND comprobante_ruta IS NULL
            AND NOT EXISTS (SELECT 1 FROM solicitudes_inscripcion s WHERE s.grupo_pago_id = pagos_grupales.id)")->execute([$grupoAnterior]);
    }
}

/** Al inscribirse con pago del ingenio: si hay una proforma con cupos libres para ese curso, se amarra sola. */
function cengi_pf_auto_amarrar(PDO $db, $solicitudId, $planId, $ingenioId)
{
    $linea = cengi_pf_linea_libre($db, $planId, $ingenioId);
    if (!$linea) {
        return null;
    }
    $pf = ['numero' => $linea['numero'], 'estado' => $linea['pf_estado'], 'tipo_cliente' => $linea['tipo_cliente'], 'moneda' => $linea['moneda']];
    cengi_pf_amarrar($db, $pf, $linea, $solicitudId);
    cengi_pf_log($db, $linea['proforma_id'], 'Participante amarrado al inscribirse', 'Cupo de ' . $linea['descripcion']);
    return (int) $linea['numero'];
}

/**
 * Emite la proforma. $datos: ingenio_id, cliente (dirigido, contacto, correo, nit, pais),
 * tipo_cliente, fecha, obs, cargo (bool), cargo_monto, lineas: [[plan, inscritos => ids,
 * cupos, desc_tipo, desc_valor]]. Devuelve el id.
 */
function cengi_pf_emitir(PDO $db, array $datos)
{
    $tipo = $datos['tipo_cliente'];
    $moneda = $tipo === 'extranjero' ? 'USD' : 'GTQ';
    $numero = (int) $db->query('SELECT COALESCE(MAX(numero), 0) + 1 FROM proformas FOR UPDATE')->fetchColumn();
    $numero = max($numero, (int) cengi_pf_config($db, 'pf_numero_inicial', 1));
    $fecha = $datos['fecha'];
    $cargo = !empty($datos['cargo']) ? round(max(0, (float) $datos['cargo_monto']), 2) : 0;
    $c = $datos['cliente'];
    $db->prepare('INSERT INTO proformas (numero, fecha, vence, ingenio_id, dirigido, contacto, correo, nit, pais, tipo_cliente, moneda, cargo_monto, obs, creado_por_nombre)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$numero, $fecha, cengi_pp_sumar_dias($fecha, CENGI_PF_VIGENCIA_DIAS), (int) $datos['ingenio_id'], $c['dirigido'], $c['contacto'], $c['correo'],
            $c['nit'], $c['pais'], $tipo, $moneda, $cargo, $datos['obs'], cengi_pp_usuario_nombre()]);
    $pfId = (int) $db->lastInsertId();
    $pf = ['numero' => $numero, 'estado' => 'emitida', 'tipo_cliente' => $tipo, 'moneda' => $moneda];

    $total = $cargo;
    $cupos = 0;
    foreach ($datos['lineas'] as $l) {
        $plan = $l['plan'];
        $cantidad = count($l['inscritos']) + (int) $l['cupos'];
        $precio = cengi_ins_monto($plan, $tipo)[0];
        $descUnit = cengi_pf_desc_unit($precio, $l['desc_tipo'], $l['desc_valor']);
        $totalLinea = round($cantidad * ($precio - $descUnit), 2);
        $db->prepare("INSERT INTO pagos_grupales (planificacion_id, ingenio_id, proforma_id, cupos, orden, monto, moneda) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([(int) $plan['id'], (int) $datos['ingenio_id'], $pfId, $cantidad, 'Proforma N.° ' . $numero, $totalLinea, $moneda]);
        $grupoId = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO proforma_lineas (proforma_id, planificacion_id, descripcion, cantidad, precio, desc_tipo, desc_valor, desc_unit, total, grupo_pago_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$pfId, (int) $plan['id'], $plan['nombre'], $cantidad, $precio, $l['desc_tipo'], (float) $l['desc_valor'], $descUnit, $totalLinea, $grupoId]);
        $linea = ['grupo_pago_id' => $grupoId, 'precio' => $precio, 'desc_unit' => $descUnit];
        foreach ($l['inscritos'] as $sid) {
            cengi_pf_amarrar($db, $pf, $linea, $sid);
        }
        $total += $totalLinea;
        $cupos += (int) $l['cupos'];
    }
    $db->prepare('UPDATE proformas SET total = ? WHERE id = ?')->execute([round($total, 2), $pfId]);
    cengi_pf_log($db, $pfId, 'Emitida', $cupos ? $cupos . ' cupo(s) para personas aún no inscritas' : '');

    // Se recuerdan los datos del cliente para la siguiente proforma del ingenio.
    $clientes = (array) cengi_pf_config($db, 'pf_clientes', []);
    $clientes[(string) (int) $datos['ingenio_id']] = $c + ['tipo' => $tipo];
    cengi_pf_guardar_config($db, 'pf_clientes', $clientes);
    $db->prepare('INSERT INTO proforma_versiones(proforma_id,revision,motivo,por,documento) VALUES(?,1,?,?,?)')
        ->execute([$pfId,'Emisión original',cengi_pp_usuario_nombre(),json_encode(['proforma'=>cengi_pf_cargar($db,$pfId),'cuentas'=>cengi_pf_cuentas($db)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
    return $pfId;
}

function cengi_pf_desc_unit($precio, $tipo, $valor)
{
    $valor = max(0, (float) $valor);
    return round(min((float) $precio, $tipo === 'pct' ? (float) $precio * min(100, $valor) / 100 : $valor), 2);
}

/** Registra el pago: proforma y ordenes pagadas, amarrados validados. Devuelve los planes afectados. */
function cengi_pf_registrar_pago(PDO $db, array $pf, $forma, $ref, $fecha)
{
    $db->prepare("UPDATE proformas SET estado = 'pagada', pago_forma = ?, pago_referencia = ?, pago_fecha = ? WHERE id = ?")
        ->execute([$forma, $ref, $fecha, (int) $pf['id']]);
    $planes = [];
    $validados = 0;
    foreach ($pf['lineas'] as $l) {
        if (!$l['grupo_pago_id']) {
            continue;
        }
        $db->prepare("UPDATE pagos_grupales SET estado = 'pagado', pago_forma = ?, pago_referencia = ?, pago_fecha = ?, verificado_por = ?, verificado_en = NOW() WHERE id = ?")
            ->execute([$forma, $ref, $fecha, cengi_pp_usuario_nombre(), (int) $l['grupo_pago_id']]);
        $planes[(int) $l['planificacion_id']] = true;
    }
    cengi_pf_log($db, $pf['id'], 'Pagada', trim($forma . ' ' . $ref) . ($validados ? ' · ' . $validados . ' participante(s) validados automáticamente' : ''));
    return array_keys($planes);
}

/** Anula: los amarrados vuelven a la orden normal del ingenio con la cuota regular, sin descuento. */
function cengi_pf_anular(PDO $db, array $pf, $motivo)
{
    foreach ($pf['lineas'] as $l) {
        if (!$l['grupo_pago_id'] || $l['grupo_estado'] === 'pagado') {
            continue;
        }
        if ($l['inscritos']) {
            $plan = cengi_pp_plan($db, $l['planificacion_id']);
            $destino = cengi_ins_grupo_abierto($db, $l['planificacion_id'], $pf['ingenio_id']);
            $stmt = $db->prepare('SELECT id_solicitud, cuota FROM solicitudes_inscripcion WHERE grupo_pago_id = ?');
            $stmt->execute([(int) $l['grupo_pago_id']]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
                [$monto, $moneda] = cengi_ins_monto($plan, $s['cuota'] ?: 'asociado');
                $db->prepare('UPDATE solicitudes_inscripcion SET grupo_pago_id = ?, monto = ?, moneda = ?, descuento = NULL WHERE id_solicitud = ?')
                    ->execute([$destino, $monto, $moneda, (int) $s['id_solicitud']]);
            }
            cengi_ins_recalcular_grupo($db, $destino);
        }
        $db->prepare('DELETE FROM pagos_grupales WHERE id = ?')->execute([(int) $l['grupo_pago_id']]);
    }
    $db->prepare("UPDATE proformas SET estado = 'anulada' WHERE id = ?")->execute([(int) $pf['id']]);
    cengi_pf_log($db, $pf['id'], 'Anulada', $motivo);
}

// ---------------------------------------------------------------- documento

/** PDF de la proforma (Dompdf) para adjuntarlo al correo o descargarlo. */
function cengi_pf_pdf(array $pf, array $cuentas)
{
    require_once __DIR__ . '/vendor/autoload.php';
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('chroot', __DIR__);
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml('<!DOCTYPE html><html><head><meta charset="utf-8"><style>@page{margin:24px;} body{font-family:"DejaVu Sans",Arial,sans-serif;}</style></head><body>'
        . cengi_pf_html($pf, $cuentas, true) . '</body></html>', 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();
    return $dompdf->output();
}

/**
 * HTML del documento (mismo formato de la proforma del prototipo). Maquetado con tablas
 * para que Dompdf lo reproduzca igual; $pdf usa la ruta local del logo.
 */
function cengi_pf_html(array $pf, array $cuentas, $pdf = false)
{
    $h = 'cengi_pp_h';
    $ext = $pf['moneda'] === 'USD';
    $mon = $ext ? 'US$' : 'Q';
    $cta = $cuentas[$pf['moneda']];
    $num = static function ($v) {
        return number_format((float) $v, 2);
    };
    $V = '#2F6B12';
    $L = '#D9DED4';
    $sec = static function ($t) use ($V) {
        return '<div style="border-bottom:1.5px solid ' . $V . ';color:' . $V . ';font-weight:700;font-size:10.5px;letter-spacing:.6px;padding:12px 0 4px;margin-bottom:6px;text-transform:uppercase;">' . $t . '</div>';
    };
    $fila = static function ($k, $v) use ($h) {
        return '<tr><td style="padding:3px 0;color:#5B6459;width:38%;">' . $k . '</td><td style="padding:3px 0;font-weight:600;">' . $h($v !== '' && $v !== null ? $v : '—') . '</td></tr>';
    };
    $lineas = '';
    $cupos = false;
    foreach ($pf['lineas'] as $l) {
        $cupos = $cupos || count($l['inscritos']) < (int) $l['cantidad'];
        $lineas .= '<tr><td style="text-align:center;padding:6px 4px;border-bottom:1px solid ' . $L . ';">' . (int) $l['cantidad'] . '</td>'
            . '<td style="padding:6px 4px;border-bottom:1px solid ' . $L . ';">' . $h($l['descripcion'])
            . ((float) $l['desc_unit'] > 0 ? '<div style="font-size:9.5px;color:#5B6459;">Incluye descuento de ' . $mon . $num($l['desc_unit']) . ' por participante (precio regular ' . $mon . $num($l['precio']) . ')</div>' : '') . '</td>'
            . '<td style="text-align:right;padding:6px 4px;border-bottom:1px solid ' . $L . ';">' . $num($l['precio'] - $l['desc_unit']) . '</td>'
            . '<td style="text-align:right;padding:6px 4px;border-bottom:1px solid ' . $L . ';">' . $num($l['total']) . '</td></tr>';
    }
    if ((float) $pf['cargo_monto'] > 0) {
        $lineas .= '<tr><td style="text-align:center;padding:6px 4px;border-bottom:1px solid ' . $L . ';">1</td><td style="padding:6px 4px;border-bottom:1px solid ' . $L . ';">Cargo por transferencia internacional</td>'
            . '<td style="text-align:right;padding:6px 4px;border-bottom:1px solid ' . $L . ';">' . $num($pf['cargo_monto']) . '</td><td style="text-align:right;padding:6px 4px;border-bottom:1px solid ' . $L . ';">' . $num($pf['cargo_monto']) . '</td></tr>';
    }
    $obs = $h(CENGI_PF_TEXTOS['obs1']) . ($ext || (float) $pf['cargo_monto'] > 0 ? '<br>' . $h(CENGI_PF_TEXTOS['obsExt']) : '')
        . ($cupos ? '<br>' . $h(CENGI_PF_TEXTOS['cupos']) : '') . ($pf['obs'] !== '' ? '<br>' . $h($pf['obs']) : '');
    $th = 'padding:5px 4px;border-bottom:1px solid #1C2517;font-size:10px;';
    // Version de 320 px del logo: Dompdf no procesa el original (6667 px).
    $logo = ($pdf ? __DIR__ . '/' : '') . 'img/logo-comite-capacitacion-pdf.png';
    return '<div class="pf-doc" style="font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#1C2517;max-width:720px;margin:0 auto;background:#fff;padding:' . ($pdf ? '0' : '28px 32px;border:1px solid ' . $L) . ';">'
        . '<table style="width:100%;border-collapse:collapse;border-bottom:3px solid ' . $V . ';"><tr>'
        . '<td style="vertical-align:top;padding-bottom:10px;"><img src="' . $h($logo) . '" alt="" style="height:60px;"><div style="font-size:9.5px;color:#5B6459;margin-top:4px;">' . $h(CENGI_PF_INSTITUCION) . '</div></td>'
        . '<td style="vertical-align:top;text-align:right;padding-bottom:10px;"><div style="font-size:18px;font-weight:bold;letter-spacing:.4px;">PROFORMA DE PAGO</div><div style="font-size:10px;color:#5B6459;">Servicios de capacitación</div>'
        . '<div style="margin-top:6px;font-size:10.5px;">No. <b>' . (int) $pf['numero'] . '</b> · Emisión <b>' . cengi_pp_fecha($pf['fecha']) . '</b> · Vence <b>' . cengi_pp_fecha($pf['vence']) . '</b></div></td></tr></table>'
        . $sec('Datos del cliente')
        . '<table style="width:100%;border-collapse:collapse;"><tr><td style="width:58%;vertical-align:top;padding-right:24px;"><table style="width:100%;border-collapse:collapse;">'
        . $fila('Dirigido a', $pf['dirigido']) . $fila('Empresa', $pf['empresa']) . $fila('Contacto', $pf['contacto']) . $fila('Correo electrónico', $pf['correo']) . '</table></td>'
        . '<td style="width:42%;vertical-align:top;"><table style="width:100%;border-collapse:collapse;">' . $fila('NIT', $pf['nit']) . $fila('País', $pf['pais'])
        . $fila('Tipo de cliente', CENGI_PF_TIPOS[$pf['tipo_cliente']] ?? '') . $fila('Moneda', $ext ? 'Dólares (US$)' : 'Quetzales (Q)') . '</table></td></tr></table>'
        . $sec('Descripción general de la solicitud') . '<div style="line-height:1.5;color:#3A4236;">' . $h(CENGI_PF_TEXTOS['descripcion']) . '</div>'
        . $sec('Detalle de servicios')
        . '<table style="width:100%;border-collapse:collapse;"><thead><tr><th style="' . $th . 'width:64px;">Cantidad</th><th style="' . $th . 'text-align:left;">Descripción del servicio</th>'
        . '<th style="' . $th . 'text-align:right;">Precio unitario (' . $mon . ')</th><th style="' . $th . 'text-align:right;">Total (' . $mon . ')</th></tr></thead><tbody>' . $lineas . '</tbody></table>'
        . '<table style="width:100%;border-collapse:collapse;margin-top:8px;"><tr><td></td><td style="width:240px;border-top:2px solid ' . $V . ';padding:6px 4px;font-size:13px;font-weight:bold;">Total</td>'
        . '<td style="width:120px;border-top:2px solid ' . $V . ';padding:6px 4px;font-size:13px;font-weight:bold;text-align:right;">' . $mon . $num($pf['total']) . '</td></tr></table>'
        . $sec('Observaciones')
        . '<table style="width:100%;border-collapse:collapse;">'
        . '<tr><td style="padding:3px 0;color:#5B6459;width:30%;vertical-align:top;">Condiciones de pago</td><td style="padding:3px 0;">' . $h(CENGI_PF_TEXTOS['condiciones']) . '</td></tr>'
        . '<tr><td style="padding:3px 0;color:#5B6459;vertical-align:top;">Vigencia de la proforma</td><td style="padding:3px 0;">' . $h(CENGI_PF_TEXTOS['vigencia']) . '</td></tr>'
        . '<tr><td style="padding:3px 0;color:#5B6459;vertical-align:top;">Observaciones adicionales</td><td style="padding:3px 0;">' . $obs . '</td></tr></table>'
        . $sec($ext ? 'Datos para pago — cuenta para pagos desde el extranjero (US$)' : 'Datos para pago — cuenta para depósitos en Guatemala (Q)')
        . '<table style="width:100%;border-collapse:collapse;">' . $fila('Nombre de la institución', $cta['inst']) . $fila('Banco', $cta['banco'] ?: 'Por configurar')
        . $fila('Tipo de cuenta', $cta['tipo']) . $fila('Número de cuenta', $cta['numero'] ?: 'Por configurar')
        . ($ext ? $fila('Código SWIFT', $cta['swift'] ?? '') . (($cta['intermediario'] ?? '') !== '' ? $fila('Banco intermediario', $cta['intermediario']) : '') : '')
        . $fila('Correo para enviar el comprobante', $cta['correo']) . '</table></div>';
}

/** Modal "Cuentas bancarias" (se usa en proforma.php y en la pestaña Proformas de pagos_inscripcion.php). */
function cengi_pf_modal_cuentas(array $cuentas, $volver)
{
    ?>
<div class="modal fade cengi-course-form-modal pp-modal is-wide" id="cuentas-modal" tabindex="-1" role="dialog" aria-labelledby="cuentas-title">
    <div class="modal-dialog" role="document"><div class="modal-content"><form method="post" action="proforma.php">
        <input type="hidden" name="accion" value="cuentas"><input type="hidden" name="volver" value="<?php echo cengi_pp_h($volver); ?>">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><h4 class="modal-title" id="cuentas-title">Cuentas bancarias para proformas</h4>
            <p>La proforma en quetzales muestra la cuenta nacional; la proforma en dólares, la cuenta para pagos del extranjero.</p></div>
        <div class="modal-body">
            <h5 style="font-weight:700;">Cuenta nacional (quetzales)</h5>
            <div class="cengi-course-modal-grid">
                <?php foreach (['inst' => 'Nombre de la institución', 'banco' => 'Banco', 'tipo' => 'Tipo de cuenta', 'numero' => 'Número de cuenta', 'correo' => 'Correo para el comprobante'] as $k => $t): ?>
                    <div class="form-group"><label for="q_<?php echo $k; ?>"><?php echo $t; ?></label><input class="form-control" id="q_<?php echo $k; ?>" name="q_<?php echo $k; ?>" value="<?php echo cengi_pp_h($cuentas['GTQ'][$k] ?? ''); ?>"></div>
                <?php endforeach; ?>
            </div>
            <h5 style="font-weight:700;margin-top:14px;">Cuenta para pagos desde el extranjero (dólares)</h5>
            <div class="cengi-course-modal-grid">
                <?php foreach (['inst' => 'Beneficiario', 'banco' => 'Banco', 'tipo' => 'Tipo de cuenta', 'numero' => 'Número de cuenta', 'swift' => 'Código SWIFT', 'intermediario' => 'Banco intermediario (si aplica)', 'correo' => 'Correo para el comprobante'] as $k => $t): ?>
                    <div class="form-group"><label for="u_<?php echo $k; ?>"><?php echo $t; ?></label><input class="form-control" id="u_<?php echo $k; ?>" name="u_<?php echo $k; ?>" value="<?php echo cengi_pp_h($cuentas['USD'][$k] ?? ''); ?>"></div>
                <?php endforeach; ?>
                <div class="form-group"><label for="cargo_usd">Cargo por transferencia internacional (US$)</label><input type="number" min="0" step="0.01" class="form-control" id="cargo_usd" name="cargo_usd" value="<?php echo cengi_pp_h($cuentas['cargo']); ?>"></div>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-primary">Guardar cuentas</button></div>
    </form></div></div>
</div>
    <?php
}
