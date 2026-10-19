<?php
/**
 * Cobranza de inscripciones: seguimiento de pendientes de pago con fecha límite (el
 * inicio del curso), recordatorios por correo, enlace seguro para que el participante
 * envíe su comprobante (pago_comprobante.php), avisos de pago validado / recibido y
 * detección de boletas repetidas.
 *
 * Sin cambios de base de datos: los recordatorios quedan en "comunicaciones" con
 * referencia cobro:s:<id_solicitud> (participante) o cobro:g:<pagos_grupales.id>
 * (orden del ingenio); el secreto de los enlaces se guarda en eventos_config.
 */
require_once __DIR__ . '/proformas_helpers.php';

const CENGI_COB_DIAS_POR_VENCER = 7;
const CENGI_COB_CATEGORIA = 'Recordatorio de pago';
const CENGI_COB_SITUACIONES = [
    'sin-comprobante' => ['Sin comprobante', 'is-upcoming'],
    'comprobante-rechazado' => ['Comprobante rechazado', 'is-rejected'],
    'por-validar' => ['Comprobante por validar', 'is-pending'],
    'falta-registrar' => ['Validado · falta registrar el ingreso', 'is-pending'],
    'ingenio-por-validar' => ['Falta validación del ingenio', 'is-upcoming'],
    'orden-pendiente' => ['Orden del ingenio sin pagar', 'is-upcoming'],
    'orden-por-validar' => ['Comprobante de la orden por validar', 'is-pending'],
    'orden-rechazada' => ['Comprobante de la orden rechazado', 'is-rejected'],
    'proforma' => ['Proforma sin pagar', 'is-upcoming'],
];

// ---------------------------------------------------------------- enlaces seguros

function cengi_cob_url_base()
{
    $env = cengicursos_env();
    if (!empty($env['APP_URL'])) {
        return rtrim((string) $env['APP_URL'], '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/** Secreto para firmar los enlaces de comprobante; se crea la primera vez. */
function cengi_cob_secreto(PDO $db)
{
    $secreto = cengi_pf_config($db, 'pagos_enlace_secreto', '');
    if (!is_string($secreto) || strlen($secreto) < 32) {
        $secreto = bin2hex(random_bytes(32));
        $db->prepare('INSERT INTO eventos_config (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = valor')
            ->execute(['pagos_enlace_secreto', json_encode($secreto)]);
        $secreto = cengi_pf_config($db, 'pagos_enlace_secreto', $secreto);
    }
    return (string) $secreto;
}

/** Firma del enlace: depende de la inscripción y su correo (si cambia el correo, el enlace anterior deja de servir). */
function cengi_cob_token(PDO $db, array $s)
{
    return substr(hash_hmac('sha256', 'comprobante:' . (int) $s['id_solicitud'] . ':' . mb_strtolower(trim((string) $s['correo']), 'UTF-8'), cengi_cob_secreto($db)), 0, 32);
}

function cengi_cob_token_valido(PDO $db, array $s, $token)
{
    return is_string($token) && $token !== '' && hash_equals(cengi_cob_token($db, $s), $token);
}

function cengi_cob_url_comprobante(PDO $db, array $s)
{
    return cengi_cob_url_base() . '/cengicursos/pago_comprobante.php?' . http_build_query(['s' => (int) $s['id_solicitud'], 't' => cengi_cob_token($db, $s)]);
}

// ---------------------------------------------------------------- vencimiento y recordatorios

/** Fecha límite de pago = inicio del curso. ['dias' => días que faltan (negativo: vencido), 'clase', 'texto']. */
function cengi_cob_vencimiento($fechaInicio, $esProforma = false)
{
    $dias = cengi_pp_dias_entre(cengi_pp_hoy(), $fechaInicio);
    if ($dias < 0) {
        return ['dias' => $dias, 'clase' => 'vencido', 'texto' => ($esProforma ? 'Vigencia vencida hace ' : 'Vencido hace ') . abs($dias) . ' día(s)' . ($esProforma ? '' : ' (el curso ya inició)')];
    }
    if ($dias === 0) {
        return ['dias' => 0, 'clase' => 'por-vencer', 'texto' => $esProforma ? 'La vigencia vence hoy' : 'Vence hoy (inicia el curso)'];
    }
    return ['dias' => $dias, 'clase' => $dias <= CENGI_COB_DIAS_POR_VENCER ? 'por-vencer' : 'al-dia', 'texto' => 'Vence en ' . $dias . ' día(s)'];
}

/** [referencia => ['n' => recordatorios enviados, 'ultimo' => fecha]] */
function cengi_cob_recordatorios(PDO $db, array $referencias)
{
    $referencias = array_values(array_unique(array_filter($referencias)));
    if (!$referencias) {
        return [];
    }
    $stmt = $db->prepare('SELECT referencia, COUNT(*) AS n, MAX(COALESCE(enviado_en, creado)) AS ultimo FROM comunicaciones
        WHERE categoria = ? AND enviados > 0 AND referencia IN (' . implode(',', array_fill(0, count($referencias), '?')) . ') GROUP BY referencia');
    $stmt->execute(array_merge([CENGI_COB_CATEGORIA], $referencias));
    $resultado = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $resultado[$f['referencia']] = ['n' => (int) $f['n'], 'ultimo' => $f['ultimo']];
    }
    return $resultado;
}

/** Boleta normalizada para comparar: sin espacios, guiones ni puntos y en mayúsculas. */
function cengi_cob_boleta_normalizada($boleta)
{
    return strtoupper(preg_replace('/[\s.\-\/]+/', '', (string) $boleta));
}

/** Otras inscripciones con la misma boleta (posible comprobante usado dos veces). */
function cengi_cob_boletas_repetidas(PDO $db, $boleta, $excluirId)
{
    $normalizada = cengi_cob_boleta_normalizada($boleta);
    if (strlen($normalizada) < 3) {
        return [];
    }
    $stmt = $db->prepare("SELECT s.id_solicitud, s.nombre_participante, s.boleta, p.nombre AS plan_nombre FROM solicitudes_inscripcion s
        LEFT JOIN planificacion_cursos p ON p.id = s.planificacion_id
        WHERE s.id_solicitud <> ? AND s.boleta <> '' AND s.estado <> 'Rechazado'
          AND UPPER(REPLACE(REPLACE(REPLACE(REPLACE(s.boleta, ' ', ''), '-', ''), '.', ''), '/', '')) = ?");
    $stmt->execute([(int) $excluirId, $normalizada]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ---------------------------------------------------------------- pendientes

/**
 * Todo lo que falta cobrar en el año (y curso): participantes con pago propio, validaciones
 * de ingenio, órdenes grupales y proformas sin pagar. Cada fila:
 * tipo (s|g|pf), id, quien, detalle, curso, plan_id, fecha_inicio, monto_q, monto_txt,
 * situacion, venc, antiguedad (días desde la inscripción), recordatorio, correo.
 */
function cengi_cob_pendientes(PDO $db, $anio, $planId = 0, $limitado = null)
{
    $filtroPlan = $planId ? ' AND p.id = ' . (int) $planId : '';
    $filas = [];
    $mismoIngenio = static function ($nombre) use ($limitado) {
        return $limitado === null || cengi_texto_normalizado((string) $nombre) === cengi_texto_normalizado($limitado);
    };

    // Participantes (pago propio y validaciones de ingenio pendientes)
    $stmt = $db->prepare("SELECT s.*, i.nombre_ingenios, p.nombre AS plan_nombre, p.fecha_inicio, p.estado AS plan_estado
        FROM solicitudes_inscripcion s INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id LEFT JOIN ingenios i ON i.id = s.ingenio_id
        WHERE p.anio = ? AND p.estado <> 'cancelado' AND s.estado <> 'Rechazado'$filtroPlan
          AND ((s.tipo_pago = 'Propio' AND COALESCE(s.pago_estado, '') NOT IN ('pagado', 'reembolsado'))
            OR (s.tipo_pago = 'Ingenio' AND s.validacion_ingenio = 'pendiente'))");
    $stmt->execute([(int) $anio]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        if (!$mismoIngenio($s['nombre_ingenios'])) {
            continue;
        }
        if ($s['tipo_pago'] === 'Ingenio') {
            $situacion = 'ingenio-por-validar';
        } else {
            $situacion = ['en revisión' => 'por-validar', 'rechazado' => 'comprobante-rechazado', 'validado' => 'falta-registrar'][$s['pago_estado']] ?? 'sin-comprobante';
        }
        $filas[] = [
            'tipo' => 's', 'id' => (int) $s['id_solicitud'], 'quien' => $s['nombre_participante'],
            'detalle' => trim(($s['nombre_ingenios'] ?: $s['institucion_otra']) . ' · ' . $s['correo'] . ($s['telefono'] ? ' · Tel. ' . $s['telefono'] : ''), ' ·'),
            'curso' => $s['plan_nombre'], 'plan_id' => (int) $s['planificacion_id'], 'fecha_inicio' => $s['fecha_inicio'],
            'monto_q' => cengi_ins_en_quetzales($s['monto'], $s['moneda']), 'monto_txt' => cengi_ins_dinero($s['monto'], $s['moneda']),
            'situacion' => $situacion, 'venc' => cengi_cob_vencimiento($s['fecha_inicio']),
            'antiguedad' => $s['creado'] ? cengi_pp_dias_entre(substr($s['creado'], 0, 10), cengi_pp_hoy()) : null,
            'referencia' => 'cobro:s:' . (int) $s['id_solicitud'], 'correo' => $s['correo'], 'fila' => $s,
            'asignado' => $s['estado'] === 'Aprobado', 'pagador' => $s['tipo_pago'],
        ];
    }

    // Órdenes grupales del ingenio (sin proforma) con participantes y sin pagar
    $stmt = $db->prepare("SELECT g.*, i.nombre_ingenios, p.nombre AS plan_nombre, p.fecha_inicio,
            (SELECT COUNT(*) FROM solicitudes_inscripcion s WHERE s.grupo_pago_id = g.id AND s.estado <> 'Rechazado') AS miembros,
            (SELECT GROUP_CONCAT(s.nombre_participante ORDER BY s.nombre_participante SEPARATOR ', ') FROM solicitudes_inscripcion s WHERE s.grupo_pago_id = g.id AND s.estado <> 'Rechazado') AS nombres,
            (SELECT MIN(s.creado) FROM solicitudes_inscripcion s WHERE s.grupo_pago_id = g.id AND s.estado <> 'Rechazado') AS desde
        FROM pagos_grupales g INNER JOIN ingenios i ON i.id = g.ingenio_id INNER JOIN planificacion_cursos p ON p.id = g.planificacion_id
        WHERE p.anio = ? AND p.estado <> 'cancelado' AND g.proforma_id IS NULL AND g.estado <> 'pagado'$filtroPlan");
    $stmt->execute([(int) $anio]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $g) {
        if ((int) $g['miembros'] === 0 || !$mismoIngenio($g['nombre_ingenios'])) {
            continue;
        }
        $moneda = $g['moneda'] ?? 'GTQ';
        $filas[] = [
            'tipo' => 'g', 'id' => (int) $g['id'], 'quien' => $g['nombre_ingenios'],
            'detalle' => 'Orden ' . ($g['orden'] ?: 'sin número') . ' · ' . (int) $g['miembros'] . ' participante(s): ' . $g['nombres'],
            'curso' => $g['plan_nombre'], 'plan_id' => (int) $g['planificacion_id'], 'fecha_inicio' => $g['fecha_inicio'],
            'monto_q' => cengi_ins_en_quetzales($g['monto'], $moneda), 'monto_txt' => cengi_ins_dinero($g['monto'], $moneda),
            'situacion' => ['en revisión' => 'orden-por-validar', 'rechazado' => 'orden-rechazada'][$g['estado']] ?? 'orden-pendiente',
            'venc' => cengi_cob_vencimiento($g['fecha_inicio']),
            'antiguedad' => $g['desde'] ? cengi_pp_dias_entre(substr($g['desde'], 0, 10), cengi_pp_hoy()) : null,
            'referencia' => 'cobro:g:' . (int) $g['id'], 'correo' => '', 'fila' => $g, 'asignado' => false, 'pagador' => 'Ingenio',
        ];
    }

    // Proformas emitidas o enviadas sin pagar: su fecha límite es la vigencia de la proforma.
    $stmt = $db->prepare("SELECT pf.*, i.nombre_ingenios AS empresa, MIN(p.fecha_inicio) AS fecha_inicio,
            GROUP_CONCAT(DISTINCT l.descripcion ORDER BY l.id SEPARATOR ', ') AS cursos, MIN(p.id) AS plan_id
        FROM proformas pf INNER JOIN ingenios i ON i.id = pf.ingenio_id
        INNER JOIN proforma_lineas l ON l.proforma_id = pf.id INNER JOIN planificacion_cursos p ON p.id = l.planificacion_id
        WHERE pf.estado IN ('emitida', 'enviada') AND p.anio = ?$filtroPlan GROUP BY pf.id");
    $stmt->execute([(int) $anio]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $pf) {
        if (!$mismoIngenio($pf['empresa'])) {
            continue;
        }
        $limite = $pf['vence'];
        $filas[] = [
            'tipo' => 'pf', 'id' => (int) $pf['id'], 'quien' => $pf['empresa'],
            'detalle' => 'Proforma N.° ' . (int) $pf['numero'] . ' del ' . cengi_pp_fecha($pf['fecha']) . ($pf['dirigido'] ? ' · ' . $pf['dirigido'] : ''),
            'curso' => $pf['cursos'], 'plan_id' => (int) $pf['plan_id'], 'fecha_inicio' => $limite,
            'monto_q' => cengi_ins_en_quetzales($pf['total'], $pf['moneda']), 'monto_txt' => cengi_pf_dinero($pf['total'], $pf['moneda']),
            'situacion' => 'proforma', 'venc' => cengi_cob_vencimiento($limite, true),
            'antiguedad' => cengi_pp_dias_entre($pf['fecha'], cengi_pp_hoy()),
            'referencia' => '', 'correo' => '', 'fila' => $pf, 'asignado' => false, 'pagador' => 'Ingenio',
        ];
    }

    $recordatorios = cengi_cob_recordatorios($db, array_column($filas, 'referencia'));
    foreach ($filas as &$f) {
        $f['recordatorio'] = $recordatorios[$f['referencia']] ?? null;
    }
    unset($f);
    // Primero lo vencido y lo más próximo a vencer.
    usort($filas, static function ($a, $b) {
        return [$a['venc']['dias'], $a['quien']] <=> [$b['venc']['dias'], $b['quien']];
    });
    return $filas;
}

// ---------------------------------------------------------------- Excel

/**
 * Descarga un .xlsx con título, encabezado de marca, autofiltro y totales.
 * $columnas: [[título, ancho, tipo], ...] con tipo texto|dinero|fecha|entero.
 * $filas: arreglos con los valores en el mismo orden (fecha en AAAA-MM-DD).
 */
function cengi_cob_excel($titulo, $subtitulo, array $columnas, array $filas, $nombreArchivo)
{
    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/calendario_ics_helpers.php';
    $col = static function ($i) {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
    };
    $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $libro->getProperties()->setCreator('CENGICANA')->setTitle($titulo);
    $libro->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
    $hoja = $libro->getActiveSheet();
    $hoja->setTitle(mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $titulo), 0, 31, 'UTF-8'));
    $ultima = $col(count($columnas));
    $hoja->setCellValue('A1', $titulo);
    $hoja->mergeCells('A1:' . $ultima . '1');
    $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FF1F5632');
    $hoja->setCellValue('A2', $subtitulo . ' · generado el ' . date('d/m/Y H:i'));
    $hoja->mergeCells('A2:' . $ultima . '2');
    $hoja->getStyle('A2')->getFont()->setItalic(true)->getColor()->setARGB('FF55705F');
    foreach ($columnas as $i => [$texto, $ancho]) {
        $hoja->setCellValue($col($i + 1) . '4', $texto);
        $hoja->getColumnDimension($col($i + 1))->setWidth($ancho);
    }
    $hoja->getStyle('A4:' . $ultima . '4')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F5632']],
        'borders' => ['bottom' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF73BC25']]],
        'alignment' => ['wrapText' => true, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
    ]);
    $r = 5;
    foreach ($filas as $fila) {
        foreach (array_values($fila) as $i => $valor) {
            $celda = $col($i + 1) . $r;
            $tipo = $columnas[$i][2] ?? 'texto';
            if ($valor === null || $valor === '') {
                continue;
            }
            if ($tipo === 'fecha' && cengi_pp_es_fecha(substr((string) $valor, 0, 10))) {
                $hoja->setCellValue($celda, \PhpOffice\PhpSpreadsheet\Shared\Date::stringToExcel(substr((string) $valor, 0, 10)));
            } elseif (in_array($tipo, ['dinero', 'entero', 'decimal'], true) && is_numeric($valor)) {
                $hoja->setCellValue($celda, $tipo === 'entero' ? (int) $valor : round((float) $valor, $tipo === 'decimal' ? 1 : 2));
            } else {
                $hoja->setCellValueExplicit($celda, (string) $valor, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }
        $r++;
    }
    $ultimaFila = $r - 1;
    if ($ultimaFila >= 5) {
        foreach ($columnas as $i => $c) {
            $letra = $col($i + 1);
            if (($c[2] ?? '') === 'fecha') {
                $hoja->getStyle($letra . '5:' . $letra . $ultimaFila)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            } elseif (($c[2] ?? '') === 'decimal') {
                $hoja->getStyle($letra . '5:' . $letra . $ultimaFila)->getNumberFormat()->setFormatCode('0.0');
            } elseif (($c[2] ?? '') === 'dinero') {
                $hoja->getStyle($letra . '5:' . $letra . $r)->getNumberFormat()->setFormatCode('#,##0.00');
                $hoja->setCellValue($letra . $r, '=SUBTOTAL(9,' . $letra . '5:' . $letra . $ultimaFila . ')');
                $hoja->getStyle($letra . $r)->getFont()->setBold(true);
            }
        }
        $hoja->setCellValue('A' . $r, 'Total (filas visibles)');
        $hoja->getStyle('A' . $r)->getFont()->setBold(true);
        $hoja->getStyle('A4:' . $ultima . $ultimaFila)->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFCED2D5']]],
            'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP, 'wrapText' => true],
        ]);
        $hoja->setAutoFilter('A4:' . $ultima . $ultimaFila);
    }
    $hoja->freezePane('A5');
    $hoja->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)->setFitToWidth(1)->setFitToHeight(0);
    cengi_descarga_encabezados('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $nombreArchivo, 'xlsx');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save('php://output');
    exit;
}

// ---------------------------------------------------------------- correos

/** Datos de la cuenta bancaria para depositar, en texto. */
function cengi_cob_texto_cuenta(PDO $db, $moneda)
{
    $cuentas = cengi_pf_cuentas($db);
    $c = $cuentas[$moneda === 'USD' ? 'USD' : 'GTQ'];
    if (!cengi_pf_cuenta_completa($c)) {
        return '';
    }
    return "Datos para el depósito o transferencia:\n• " . $c['inst'] . "\n• Banco: " . $c['banco'] . ' · Cuenta ' . $c['tipo'] . ' N.° ' . $c['numero']
        . (!empty($c['swift']) ? "\n• SWIFT: " . $c['swift'] : '') . (!empty($c['correo']) ? "\n• Envía la constancia a: " . $c['correo'] : '');
}

/** Recordatorio de pago a un participante con pago propio (con enlace para enviar el comprobante). */
function cengi_cob_recordar_participante(PDO $db, array $s, $notaExtra = '')
{
    $venc = cengi_cob_vencimiento($s['fecha_inicio']);
    $monto = cengi_ins_dinero($s['monto'], $s['moneda']);
    if ($s['pago_estado'] === 'rechazado') {
        $situacion = 'Revisamos el comprobante que enviaste y no pudimos validarlo' . ($s['pago_nota'] ? ' (' . $s['pago_nota'] . ')' : '') . '. Por favor envía un comprobante válido.';
    } elseif ($s['pago_estado'] === 'en revisión') {
        $situacion = 'Ya recibimos tu comprobante y está en revisión; no necesitas hacer nada más por ahora.';
    } else {
        $situacion = 'Aún no hemos recibido el comprobante de tu pago.';
    }
    $mensaje = "Hola {nombre}:\n\nTe recordamos el pago de tu inscripción a " . $s['plan_nombre'] . ' (inicia el ' . cengi_pp_fecha($s['fecha_inicio']) . ').'
        . "\n\n• Monto a pagar: " . $monto . "\n• Fecha límite: antes del inicio del curso" . ($venc['dias'] >= 0 ? ' (' . mb_strtolower($venc['texto'], 'UTF-8') . ')' : '') . "\n\n" . $situacion
        . "\n\nAl registrar tu comprobante, Administración lo valida y te confirma tu lugar en el curso.";
    if ($s['pago_estado'] !== 'en revisión') {
        $cuenta = cengi_cob_texto_cuenta($db, $s['moneda']);
        $mensaje .= ($cuenta !== '' ? "\n\n" . $cuenta : '') . "\n\nEnvía tu comprobante (boleta o transferencia, PDF o foto) en este enlace:\n" . cengi_cob_url_comprobante($db, $s);
    }
    if (trim($notaExtra) !== '') {
        $mensaje .= "\n\n" . trim($notaExtra);
    }
    // La firma la pone la plantilla del correo (cengi_pp_correo_html).
    [, $ok] = cengi_pp_enviar_comunicacion($db, ['categoria' => CENGI_COB_CATEGORIA, 'origen' => 'sistema', 'referencia' => 'cobro:s:' . (int) $s['id_solicitud'],
        'planificacion_id' => (int) $s['planificacion_id'], 'asunto' => 'Recordatorio de pago: ' . $s['plan_nombre'], 'mensaje' => $mensaje],
        [['nombre' => $s['nombre_participante'], 'correo' => $s['correo']]]);
    return $ok > 0;
}

/** Recordatorio a los usuarios del ingenio por una orden grupal sin pagar (o por validar a sus inscritos). */
function cengi_cob_recordar_grupo(PDO $db, array $g, $notaExtra = '')
{
    $usuarios = cengi_ins_usuarios_ingenio($g['nombre_ingenios']);
    if (!$usuarios) {
        return null; // el ingenio no tiene usuarios con correo
    }
    $stmt = $db->prepare("SELECT nombre_participante, validacion_ingenio, monto, moneda FROM solicitudes_inscripcion WHERE grupo_pago_id = ? AND estado <> 'Rechazado' ORDER BY nombre_participante");
    $stmt->execute([(int) $g['id']]);
    $detalle = implode("\n", array_map(static function ($s) {
        return '• ' . $s['nombre_participante'] . ' — ' . cengi_ins_dinero($s['monto'], $s['moneda']) . ($s['validacion_ingenio'] === 'pendiente' ? ' (falta validar)' : '');
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
    $moneda = $g['moneda'] ?? 'GTQ';
    $cuenta = cengi_cob_texto_cuenta($db, $moneda);
    $mensaje = "Estimado(a) {nombre}:\n\nLe recordamos el pago de la orden de " . $g['nombre_ingenios'] . ' para ' . $g['plan_nombre'] . ' (inicia el ' . cengi_pp_fecha($g['fecha_inicio']) . ').'
        . "\n\n• Monto total: " . cengi_ins_dinero($g['monto'], $moneda) . ($g['orden'] ? "\n• Orden / factura: " . $g['orden'] : '') . "\n• Participantes:\n" . $detalle
        . ($g['estado'] === 'rechazado' && $g['nota'] ? "\n\nEl comprobante enviado no se pudo validar: " . $g['nota'] : '')
        . ($cuenta !== '' ? "\n\n" . $cuenta : '')
        . "\n\nSi ya realizó el pago, por favor responda este correo con el comprobante. Si falta validar participantes, ingrese a Cengicursos → Pagos de inscripción."
        . (trim($notaExtra) !== '' ? "\n\n" . trim($notaExtra) : '');
    [, $ok] = cengi_pp_enviar_comunicacion($db, ['categoria' => CENGI_COB_CATEGORIA, 'origen' => 'sistema', 'referencia' => 'cobro:g:' . (int) $g['id'],
        'planificacion_id' => (int) $g['planificacion_id'], 'asunto' => 'Recordatorio de pago — ' . $g['nombre_ingenios'] . ' · ' . $g['plan_nombre'], 'mensaje' => $mensaje],
        array_map(static function ($u) {
            return ['nombre' => $u['nombre'], 'correo' => $u['correo']];
        }, $usuarios));
    return $ok > 0;
}

/** Aviso al participante: comprobante validado (ya cuenta para el cupo) o pago recibido. */
function cengi_cob_avisar_pago(PDO $db, array $s, $tipo)
{
    try {
        if ($tipo === 'validado') {
            $asunto = 'Pago validado: ' . $s['plan_nombre'];
            $texto = 'Validamos el comprobante de pago de tu inscripción a ' . $s['plan_nombre'] . ' (' . cengi_ins_dinero($s['monto'], $s['moneda']) . ').'
                . "\n\nTu inscripción ya cuenta para el cupo del curso. Te avisaremos cuando quedes asignado(a) y recibirás la programación de las sesiones.";
        } else {
            $asunto = 'Pago recibido: ' . $s['plan_nombre'];
            $texto = 'Confirmamos la recepción de tu pago de ' . cengi_ins_dinero($s['monto'], $s['moneda']) . ' por la inscripción a ' . $s['plan_nombre']
                . ($s['pago_referencia'] ?? '' ? ' (referencia ' . $s['pago_referencia'] . ')' : '') . '. ¡Gracias!';
        }
        cengi_pp_enviar_comunicacion($db, ['categoria' => 'Pagos de inscripción', 'origen' => 'sistema', 'planificacion_id' => (int) $s['planificacion_id'],
            'asunto' => $asunto, 'mensaje' => "Hola {nombre}:\n\n" . $texto],
            [['nombre' => $s['nombre_participante'], 'correo' => $s['correo']]]);
    } catch (Throwable $e) {
        error_log('Cobranza: no se pudo avisar el pago a ' . $s['correo'] . ': ' . $e->getMessage());
    }
}
