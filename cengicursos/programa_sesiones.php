<?php
if(($_POST['accion']??'')==='guardar_tabla')ob_start();
require_once __DIR__ . '/planificacion_helpers.php';
require_once __DIR__ . '/calendario_ics_helpers.php';
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/sesiones_tabla_helpers.php';

cengi_require_admin();

$db = conectar();
$hoy = cengi_pp_hoy();
if(isset($_GET['edicion']))$_SESSION['ses_modo_edicion']=(bool)(int)$_GET['edicion'];
$modoEdicion=!empty($_SESSION['ses_modo_edicion']);
$mensaje = '';
$mensajeTipo = 'success';
$reabrir = null; // datos para volver a abrir un modal tras error o avisos
if(empty($_SESSION['ses_tabla_csrf']))$_SESSION['ses_tabla_csrf']=bin2hex(random_bytes(32));

function cengi_ses_revision_tabla(array $s): string
{
    $datos=[];
    foreach(['id','planificacion_id','modulo','tema','fecha','hora_inicio','hora_fin','instructor_id','soporte_id','modalidad','lugar','estado','liquidacion_id','liquidacion_soporte_id'] as $campo)$datos[$campo]=(string)($s[$campo]??'');
    return hash('sha256',json_encode($datos));
}

const CENGI_SES_MENSAJES = [
    'guardada' => 'Sesión guardada.',
    'generadas' => 'Sesiones creadas.',
    'impartida' => 'Sesión marcada como impartida: ya cuenta para el pago.',
    'cancelada' => 'Sesión cancelada.',
    'programada' => 'Sesión restaurada como programada.',
    'eliminada' => 'Sesión eliminada.',
    'soporte' => 'Soporte técnico guardado.',
    'invitaciones' => 'Invitaciones de calendario revisadas.',
    'programacion' => 'Programación enviada.',
];

function cengi_ses_obtener(PDO $db, $id)
{
    $stmt = $db->prepare('SELECT s.*, i.nombre AS instructor_nombre, st.nombre AS soporte_nombre FROM curso_sesiones s
        LEFT JOIN instructores i ON i.id = s.instructor_id LEFT JOIN soporte_tecnico st ON st.id = s.soporte_id WHERE s.id = ?');
    $stmt->execute([(int) $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function cengi_ses_nombre(PDO $db, $tabla, $id)
{
    if (!$id) {
        return '';
    }
    $stmt = $db->prepare("SELECT nombre FROM $tabla WHERE id = ?");
    $stmt->execute([(int) $id]);
    return (string) $stmt->fetchColumn();
}

/** Sesiones no canceladas del plan (sin contar $excluirId). */
function cengi_ses_activas(PDO $db, $planId, $excluirId = 0)
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM curso_sesiones WHERE planificacion_id = ? AND estado <> 'cancelada' AND id <> ?");
    $stmt->execute([(int) $planId, (int) $excluirId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Cada curso habilita tantas sesiones como indica su plan: no se agrega (ni se restaura)
 * una sesión si ya están todas programadas.
 */
function cengi_ses_validar_cupo(PDO $db, array $plan, $nuevas = 1, $excluirId = 0)
{
    $planificadas = (int) $plan['sesiones_planificadas'];
    $libres = $planificadas - cengi_ses_activas($db, $plan['id'], $excluirId);
    if ($nuevas > $libres) {
        throw new InvalidArgumentException($libres <= 0
            ? 'El curso tiene ' . $planificadas . ' sesión(es) planificada(s) y ya están todas programadas. Para agregar otra, aumenta el número de sesiones en Planificación de cursos o cancela una sesión.'
            : 'Solo quedan ' . $libres . ' de ' . $planificadas . ' sesión(es) por programar en este curso.');
    }
}

/**
 * Corre las sesiones posteriores a $sesion (según su fecha anterior) las mismas clases que
 * se movió ella: de miércoles 21 a viernes 23 = 1 clase, y cada sesión siguiente avanza una
 * clase (conserva el patrón de días y las semanas sin clase). Solo mueve sesiones programadas
 * y sin liquidar. Si la última pasa de la fecha final, extiende el curso. Devuelve
 * [[fecha anterior, fecha nueva], ...] de las sesiones siguientes que se movieron.
 */
function cengi_ses_correr_siguientes(PDO $db, array $plan, array $sesion, $nuevaFecha, $motivo = '')
{
    $dias = cengi_pp_dias_clase($db, $plan);
    $k = cengi_pp_clases_entre($sesion['fecha'], $nuevaFecha, $dias);
    if ($k === 0) {
        return [];
    }
    $stmt = $db->prepare("SELECT id, fecha FROM curso_sesiones WHERE planificacion_id = ? AND id <> ? AND estado = 'programada' AND liquidacion_id IS NULL
        AND (fecha > ? OR (fecha = ? AND hora_inicio > ?)) ORDER BY fecha, hora_inicio, id");
    $stmt->execute([(int) $plan['id'], (int) $sesion['id'], $sesion['fecha'], $sesion['fecha'], $sesion['hora_inicio']]);
    $mover = $db->prepare('UPDATE curso_sesiones SET fecha = ? WHERE id = ?');
    $corridas = [];
    $ultima = $nuevaFecha;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $fecha = cengi_pp_mover_clases($s['fecha'], $k, $dias);
        $mover->execute([$fecha, $s['id']]);
        $corridas[] = [$s['fecha'], $fecha, (int) $s['id']];
        $ultima = max($ultima, $fecha);
    }
    $afecta = cengi_pp_convocado($plan);
    if ($corridas) {
        $lista = static function ($i) use ($corridas) {
            return implode(', ', array_map(static function ($c) use ($i) {
                return cengi_pp_fecha_corta($c[$i]);
            }, $corridas));
        };
        cengi_pp_log($db, $plan, 'Cambio de fecha de sesión', count($corridas) . ' sesión(es) siguiente(s) corrida(s) ' . sprintf('%+d', $k) . ' clase(s)', [
            'antes' => $lista(0), 'despues' => $lista(1), 'afecta' => $afecta, 'motivo' => $motivo,
        ]);
    }
    if ($ultima > $plan['fecha_fin']) {
        $db->prepare("UPDATE planificacion_cursos SET fecha_fin = ?, estado = IF(estado IN ('aprobado', 'reprogramado'), 'reprogramado', estado) WHERE id = ?")
            ->execute([$ultima, (int) $plan['id']]);
        cengi_pp_log($db, $plan, 'Reprogramación', 'Fecha final extendida al correr sesiones', [
            'antes' => cengi_pp_fecha($plan['fecha_inicio']) . ' – ' . cengi_pp_fecha($plan['fecha_fin']),
            'despues' => cengi_pp_fecha($plan['fecha_inicio']) . ' – ' . cengi_pp_fecha($ultima), 'afecta' => $afecta, 'motivo' => $motivo,
        ]);
        cengi_pp_sincronizar_fechas_curso($db, $plan['id']);
    }
    return $corridas;
}

/** Fechas que cumplen el patron del generador dentro del rango del plan. */
function cengi_ses_fechas_generador(array $plan, $desde, $cantidad, array $dias, $frecuencia)
{
    $fechas = [];
    if (!cengi_pp_es_fecha($desde) || $cantidad < 1 || !$dias) {
        return $fechas;
    }
    $lunes = cengi_pp_sumar_dias($desde, -(((int) date('w', strtotime($desde)) + 6) % 7));
    for ($d = max($desde, $plan['fecha_inicio']); $d <= $plan['fecha_fin'] && count($fechas) < $cantidad; $d = cengi_pp_sumar_dias($d, 1)) {
        $semana = intdiv(cengi_pp_dias_entre($lunes, $d), 7);
        if (in_array((int) date('w', strtotime($d)), $dias, true) && $semana % $frecuencia === 0) {
            $fechas[] = $d;
        }
    }
    return $fechas;
}

/**
 * Descarga las sesiones (ya filtradas) como Excel .xlsx: fechas y horas como valores reales
 * (se pueden ordenar, filtrar y sumar), total de horas y una hoja de resumen por instructor.
 */
function cengi_ses_exportar_excel(array $sesiones, $titulo, array $filtros, $nombreArchivo)
{
    require_once __DIR__ . '/vendor/autoload.php';
    $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $libro->getProperties()->setCreator('CENGICANA')->setTitle('Programa de sesiones')->setSubject($titulo);
    $libro->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);
    $col = static function ($i) {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
    };
    $encabezado = [
        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F5632']],
        'borders' => ['bottom' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF73BC25']]],
        'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ];
    $bordes = ['borders' => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFCED2D5']]]];
    $horas = static function (array $s) {
        return max(0, strtotime('2000-01-01 ' . $s['hora_fin']) - strtotime('2000-01-01 ' . $s['hora_inicio'])) / 3600;
    };
    $fraccionDia = static function ($hora) {
        [$h, $m] = array_map('intval', explode(':', substr((string) $hora, 0, 5)));
        return ($h * 60 + $m) / 1440;
    };

    // ---- Hoja 1: sesiones
    $hoja = $libro->getActiveSheet();
    $hoja->setTitle('Sesiones');
    $columnas = [['N.°', 6], ['Fecha', 12], ['Día', 7], ['Inicio', 8], ['Fin', 8], ['Horas', 7], ['Curso / Diplomado', 34], ['Módulo', 22], ['Tema', 34],
        ['Instructor', 26], ['Código de instructor', 13], ['Soporte técnico', 20], ['Modalidad', 11], ['Lugar o enlace', 32], ['Estado', 12]];
    $ultima = $col(count($columnas));
    $hoja->setCellValue('A1', 'Programa de sesiones — ' . $titulo);
    $hoja->mergeCells('A1:' . $ultima . '1');
    $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FF1F5632');
    $hoja->setCellValue('A2', implode(' · ', array_merge($filtros, [count($sesiones) . ' sesión(es)', 'generado el ' . date('d/m/Y H:i')])));
    $hoja->mergeCells('A2:' . $ultima . '2');
    $hoja->getStyle('A2')->getFont()->setItalic(true)->getColor()->setARGB('FF55705F');
    foreach ($columnas as $i => [$texto, $ancho]) {
        $hoja->setCellValue($col($i + 1) . '4', $texto);
        $hoja->getColumnDimension($col($i + 1))->setWidth($ancho);
    }
    $hoja->getStyle('A4:' . $ultima . '4')->applyFromArray($encabezado);
    $hoja->getRowDimension(4)->setRowHeight(22);

    $colorEstado = ['programada' => 'FFFFF7D6', 'impartida' => 'FFEEF7E3', 'cancelada' => 'FFF1F3F4'];
    $resumen = [];
    $fila = 5;
    foreach (array_values($sesiones) as $n => $s) {
        $hoja->setCellValue('A' . $fila, $n + 1);
        $hoja->setCellValue('B' . $fila, \PhpOffice\PhpSpreadsheet\Shared\Date::stringToExcel($s['fecha']));
        $hoja->setCellValue('C' . $fila, ucfirst(CENGI_PP_DIAS_CORTOS[(int) date('w', strtotime($s['fecha']))]));
        $hoja->setCellValue('D' . $fila, $fraccionDia($s['hora_inicio']));
        $hoja->setCellValue('E' . $fila, $fraccionDia($s['hora_fin']));
        $hoja->setCellValue('F' . $fila, round($horas($s), 2));
        foreach (['G' => $s['plan_nombre'], 'H' => $s['modulo'], 'I' => $s['tema'], 'J' => $s['instructor_nombre'] ?: 'Sin instructor', 'K' => (string) $s['codigo_catedratico'],
            'L' => (string) $s['soporte_nombre'], 'M' => $s['modalidad'], 'N' => $s['lugar'], 'O' => CENGI_PP_ESTADOS_SESION[$s['estado']][0] ?? $s['estado']] as $c => $valor) {
            $hoja->setCellValueExplicit($c . $fila, (string) $valor, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        $hoja->getStyle('O' . $fila)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB($colorEstado[$s['estado']] ?? 'FFFFFFFF');
        if ($s['estado'] === 'cancelada') {
            $hoja->getStyle('A' . $fila . ':' . $ultima . $fila)->getFont()->getColor()->setARGB('FF8A958C');
        }

        $clave = $s['instructor_nombre'] ?: 'Sin instructor';
        $resumen[$clave] = $resumen[$clave] ?? ['programada' => 0, 'impartida' => 0, 'cancelada' => 0, 'horas' => 0.0, 'desde' => $s['fecha'], 'hasta' => $s['fecha']];
        $resumen[$clave][$s['estado']] = ($resumen[$clave][$s['estado']] ?? 0) + 1;
        if ($s['estado'] !== 'cancelada') {
            $resumen[$clave]['horas'] += $horas($s);
        }
        $resumen[$clave]['desde'] = min($resumen[$clave]['desde'], $s['fecha']);
        $resumen[$clave]['hasta'] = max($resumen[$clave]['hasta'], $s['fecha']);
        $fila++;
    }
    $ultimaFila = $fila - 1;
    if ($ultimaFila >= 5) {
        $hoja->getStyle('B5:B' . $ultimaFila)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $hoja->getStyle('D5:E' . $ultimaFila)->getNumberFormat()->setFormatCode('hh:mm');
        $hoja->getStyle('F5:F' . $fila)->getNumberFormat()->setFormatCode('0.0#');
        $hoja->getStyle('A4:' . $ultima . $ultimaFila)->applyFromArray($bordes + [
            'alignment' => ['vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP, 'wrapText' => true],
        ]);
        $hoja->getStyle('A5:F' . $ultimaFila)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        // Total de horas sin las sesiones canceladas.
        $hoja->setCellValue('E' . $fila, 'Total');
        $hoja->setCellValue('F' . $fila, '=SUMIFS(F5:F' . $ultimaFila . ',O5:O' . $ultimaFila . ',"<>Cancelada")');
        $hoja->setCellValue('G' . $fila, 'horas (sin canceladas)');
        $hoja->getStyle('E' . $fila . ':G' . $fila)->getFont()->setBold(true);
        $hoja->getStyle('E' . $fila . ':F' . $fila)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        $hoja->setAutoFilter('A4:' . $ultima . $ultimaFila);
    }
    $hoja->freezePane('A5');
    $hoja->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
        ->setFitToWidth(1)->setFitToHeight(0)->setRowsToRepeatAtTopByStartAndEnd(4, 4);

    // ---- Hoja 2: resumen por instructor
    $hoja2 = $libro->createSheet();
    $hoja2->setTitle('Resumen por instructor');
    $hoja2->setCellValue('A1', 'Resumen por instructor — ' . $titulo);
    $hoja2->mergeCells('A1:G1');
    $hoja2->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setARGB('FF1F5632');
    foreach ([['Instructor', 30], ['Programadas', 13], ['Impartidas', 12], ['Canceladas', 12], ['Horas', 10], ['Primera sesión', 14], ['Última sesión', 14]] as $i => [$texto, $ancho]) {
        $hoja2->setCellValue($col($i + 1) . '3', $texto);
        $hoja2->getColumnDimension($col($i + 1))->setWidth($ancho);
    }
    $hoja2->getStyle('A3:G3')->applyFromArray($encabezado);
    ksort($resumen);
    $r = 4;
    foreach ($resumen as $nombre => $d) {
        $hoja2->setCellValueExplicit('A' . $r, $nombre, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $hoja2->setCellValue('B' . $r, $d['programada']);
        $hoja2->setCellValue('C' . $r, $d['impartida']);
        $hoja2->setCellValue('D' . $r, $d['cancelada']);
        $hoja2->setCellValue('E' . $r, round($d['horas'], 2));
        $hoja2->setCellValue('F' . $r, \PhpOffice\PhpSpreadsheet\Shared\Date::stringToExcel($d['desde']));
        $hoja2->setCellValue('G' . $r, \PhpOffice\PhpSpreadsheet\Shared\Date::stringToExcel($d['hasta']));
        $r++;
    }
    if ($r > 4) {
        $hoja2->setCellValue('A' . $r, 'Total');
        foreach (['B', 'C', 'D', 'E'] as $c) {
            $hoja2->setCellValue($c . $r, '=SUM(' . $c . '4:' . $c . ($r - 1) . ')');
        }
        $hoja2->getStyle('A' . $r . ':G' . $r)->getFont()->setBold(true);
        $hoja2->getStyle('A' . $r . ':G' . $r)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFEEF7E3');
        $hoja2->getStyle('A3:G' . $r)->applyFromArray($bordes);
        $hoja2->getStyle('E4:E' . $r)->getNumberFormat()->setFormatCode('0.0#');
        $hoja2->getStyle('F4:G' . ($r - 1))->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $hoja2->getStyle('B4:G' . $r)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    }
    $libro->setActiveSheetIndex(0);

    cengi_descarga_encabezados('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $nombreArchivo, 'xlsx');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save('php://output');
    exit;
}

if(($_SERVER['REQUEST_METHOD']??'')==='POST'&&($_POST['accion']??'')==='guardar_tabla'){
    try{
        if(!hash_equals($_SESSION['ses_tabla_csrf'],(string)($_POST['csrf']??'')))throw new InvalidArgumentException('Actualiza la página antes de guardar.');
        $filas=json_decode((string)($_POST['filas']??''),true,32,JSON_THROW_ON_ERROR);
        if(!is_array($filas))throw new InvalidArgumentException('Selección inválida.');
        $db->beginTransaction();$r=cengi_ses_tabla_guardar($db,$filas,!empty($_POST['confirmar']));
        if(!empty($r['confirmar']))$db->rollBack();else{$db->commit();try{cengi_ics_sincronizar($db,$r['ics'],$r['ids'],[],[]);}catch(Throwable $e){error_log('Calendario tras edición de tabla: '.$e->getMessage());$r['aviso']='Cambios guardados; revisa la sincronización del calendario.';}}
        if(empty($r['confirmar'])){
            $r['filas']=[];
            foreach($filas as $f){$s=cengi_ses_obtener($db,(int)$f['id']);if($s)$r['filas'][]=['id'=>(int)$s['id'],'revision'=>cengi_ses_revision_tabla($s),'datos'=>json_decode(cengi_ses_datos_fila($s),true)];}
        }
        unset($r['ics'],$r['ids']);$r['ok']=empty($r['confirmar']);
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$r=['ok'=>false,'mensaje'=>$e instanceof InvalidArgumentException?$e->getMessage():'No fue posible guardar las filas. No se aplicaron cambios.'];error_log('Edición conjunta de sesiones: '.$e->getMessage());}
    if(ob_get_level())ob_clean();header('Content-Type: application/json; charset=UTF-8');echo json_encode($r,JSON_UNESCAPED_UNICODE);exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $codigoMensaje = '';
    $extra = [];
    // Invitaciones de Outlook: estado previo de las sesiones, sesiones nuevas y a quién invitar.
    $icsAntes = [];
    $icsIds = [];
    $icsInvitar = [];
    $icsSolo = [];
    $invitarCalendario = !empty($_POST['invitar_calendario']);
    $programacionPendiente = null;
    try {
        $db->beginTransaction();

        if ($accion === 'guardar_sesion') {
            $id = (int) ($_POST['id'] ?? 0);
            $anterior = $id ? cengi_ses_obtener($db, $id) : null;
            if ($id && !$anterior) {
                throw new InvalidArgumentException('La sesión ya no existe.');
            }
            $edicionTabla=!empty($_POST['edicion_tabla']);
            if($edicionTabla){
                if(!$anterior||!hash_equals($_SESSION['ses_tabla_csrf'],(string)($_POST['csrf']??'')))throw new InvalidArgumentException('Actualiza la página antes de editar la tabla.');
                $lock=$db->prepare('SELECT id FROM curso_sesiones WHERE id=? FOR UPDATE');$lock->execute([$id]);
                $anterior=cengi_ses_obtener($db,$id);
                if(!$anterior||!hash_equals(cengi_ses_revision_tabla($anterior),(string)($_POST['revision']??'')))throw new InvalidArgumentException('Esta sesión cambió desde que abriste la tabla. Recarga la página para consultar sus datos actuales.');
                // La edición de columnas no mueve la sesión de curso ni cambia su estado o contenido interno.
                foreach(['planificacion_id','tema','estado'] as $campo)$_POST[$campo]=$anterior[$campo];
            }
            $v = [
                'planificacion_id' => (int) ($_POST['planificacion_id'] ?? 0),
                'modulo' => mb_substr(trim((string) ($_POST['modulo'] ?? '')), 0, 255, 'UTF-8') ?: 'Sesión única',
                'tema' => mb_substr(trim((string) ($_POST['tema'] ?? '')), 0, 255, 'UTF-8'),
                'fecha' => (string) ($_POST['fecha'] ?? ''),
                'hora_inicio' => (string) ($_POST['hora_inicio'] ?? ''),
                'hora_fin' => (string) ($_POST['hora_fin'] ?? ''),
                'instructor_id' => (int) ($_POST['instructor_id'] ?? 0) ?: null,
                'soporte_id' => (int) ($_POST['soporte_id'] ?? 0) ?: null,
                'modalidad' => in_array($_POST['modalidad'] ?? '', CENGI_PP_MODALIDADES, true) ? $_POST['modalidad'] : 'Presencial',
                'lugar' => mb_substr(trim((string) ($_POST['lugar'] ?? '')), 0, 255, 'UTF-8'),
                'estado' => array_key_exists($_POST['estado'] ?? '', CENGI_PP_ESTADOS_SESION) ? $_POST['estado'] : 'programada',
            ];
            $plan = cengi_pp_plan($db, $v['planificacion_id']);
            $errores = [];
            if($edicionTabla&&$v['modulo']!==$anterior['modulo']&&!in_array($v['modulo'],cengi_pp_modulos_plan($db,$plan),true))$errores[]='Selecciona un módulo configurado para este curso.';
            if (!$plan) {
                throw new InvalidArgumentException('Selecciona el curso.');
            }
            // "Correr esta y las siguientes": las sesiones programadas posteriores se mueven las
            // mismas clases; si la última pasa de la fecha final, el curso se extiende.
            $correr = $anterior && !empty($_POST['correr']) && $v['estado'] === 'programada'
                && $anterior['fecha'] !== $v['fecha'] && (int) $anterior['planificacion_id'] === $v['planificacion_id'];
            // Con módulos automáticos el módulo no se elige: lo asigna cengi_pp_asignar_modulos() al guardar.
            $estructura = cengi_pp_estructura_modulos($db, $plan);
            if (cengi_pp_modulos_automaticos($estructura)) {
                $v['modulo'] = $anterior && (int) $anterior['planificacion_id'] === $v['planificacion_id'] ? $anterior['modulo'] : $estructura[0]['nombre'];
            }
            if (mb_strlen($v['tema'], 'UTF-8') < 3) {
                $errores[] = 'Escribe el tema de la sesión.';
            }
            if (!cengi_pp_es_fecha($v['fecha'])) {
                $errores[] = 'Indica la fecha.';
            } elseif ((!$edicionTabla || $v['fecha']!==$anterior['fecha']) && ($v['fecha'] < $plan['fecha_inicio'] || (!$correr && $v['fecha'] > $plan['fecha_fin']))) {
                $errores[] = 'La fecha debe estar dentro del rango del curso (' . cengi_pp_fecha_corta($plan['fecha_inicio']) . ' – ' . cengi_pp_fecha_corta($plan['fecha_fin']) . '). Para pasar de la fecha final, usa “Correr esta y las siguientes” (extiende el curso) o reprograma el curso.';
            }
            if (!cengi_pp_es_hora($v['hora_inicio']) || !cengi_pp_es_hora($v['hora_fin']) || $v['hora_fin'] <= $v['hora_inicio']) {
                $errores[] = 'La hora de fin debe ser mayor que la de inicio.';
            }
            if ($v['lugar'] === '') {
                $errores[] = 'Indica el lugar de desarrollo o el enlace virtual.';
            }
            if ($v['estado'] === 'impartida' && $v['fecha'] > $hoy) {
                $errores[] = 'Una sesión futura no puede quedar como impartida.';
            }
            if ($anterior && $anterior['liquidacion_id'] && ($anterior['estado'] !== $v['estado'] || (int) $anterior['instructor_id'] !== (int) $v['instructor_id']
                || $anterior['fecha'] !== $v['fecha'] || (int) $anterior['planificacion_id'] !== $v['planificacion_id'])) {
                $errores[] = 'La sesión ya está en una liquidación de pago: no se puede cambiar su estado, fecha, curso ni instructor. Solo tema, lugar u horario.';
            }
            if ($anterior && $anterior['liquidacion_soporte_id'] && (int) $anterior['soporte_id'] !== (int) $v['soporte_id']) {
                $errores[] = 'El pago del soporte técnico de esta sesión ya está liquidado: no se puede cambiar el soporte.';
            }
            if ($errores) {
                throw new InvalidArgumentException(implode('<br>', array_map('cengi_pp_h', $errores)));
            }
            $v['hora_inicio'] = substr($v['hora_inicio'], 0, 5) . ':00';
            $v['hora_fin'] = substr($v['hora_fin'], 0, 5) . ':00';

            // Ocupa un espacio nuevo si es una sesión nueva, una cancelada que se restaura o una que cambia de curso.
            $ocupaEspacio = $v['estado'] !== 'cancelada'
                && (!$anterior || $anterior['estado'] === 'cancelada' || (int) $anterior['planificacion_id'] !== $v['planificacion_id']);
            if ($ocupaEspacio) {
                cengi_ses_validar_cupo($db, $plan, 1, $id);
            }

            $revisarAvisos=!$edicionTabla || $v['fecha']!==$anterior['fecha'] || substr($v['hora_inicio'],0,5)!==substr($anterior['hora_inicio'],0,5)
                || substr($v['hora_fin'],0,5)!==substr($anterior['hora_fin'],0,5) || (int)$v['instructor_id']!==(int)$anterior['instructor_id']
                || (int)$v['soporte_id']!==(int)$anterior['soporte_id'] || $v['modalidad']!==$anterior['modalidad'];
            if ($v['estado'] !== 'cancelada' && empty($_POST['confirmar']) && $revisarAvisos) {
                $avisos = cengi_pp_avisos_sesion($db, $v, $id);
                if (!$v['soporte_id'] && $v['modalidad'] !== 'Presencial') {
                    $avisos[] = 'Es una sesión ' . mb_strtolower($v['modalidad'], 'UTF-8') . ' y no tiene soporte técnico asignado.';
                }
                if ($v['instructor_id'] && ($docInstr = cengi_pp_documentacion($db, $v['instructor_id']))) {
                    if (!$docInstr['activo']) {
                        $avisos[] = 'El instructor está marcado como inactivo.';
                    } elseif ($docInstr['faltan']) {
                        $avisos[] = 'El instructor tiene la documentación incompleta (falta ' . cengi_pp_h(implode(', ', $docInstr['faltan'])) . ').';
                    }
                }
                if ($avisos) {
                    $db->rollBack();
                    $reabrir = ['modal' => 'sesion', 'datos' => $_POST + ['id' => $id], 'avisos' => $avisos];
                    throw new DomainException('avisos');
                }
            }

            // Todo el plan: correr sesiones o reasignar módulos puede cambiar otras sesiones e instructores.
            $icsAntes = cengi_ics_instantanea($db, [], $v['planificacion_id'])
                + ($anterior ? cengi_ics_instantanea($db, [], $anterior['planificacion_id']) : []);
            $convocado = cengi_pp_convocado($plan);
            if ($anterior) {
                $columnas = implode(', ', array_map(static function ($c) {
                    return $c . ' = ?';
                }, array_keys($v)));
                $db->prepare("UPDATE curso_sesiones SET $columnas WHERE id = ?")->execute(array_merge(array_values($v), [$id]));
                $afectaFutura = $convocado && ($anterior['fecha'] >= $hoy || $v['fecha'] >= $hoy) && $v['estado'] !== 'cancelada';
                $etiqueta = 'Sesión del ' . cengi_pp_fecha($anterior['fecha']) . ' — ' . $anterior['tema'];
                $registrar = static function ($tipo, $antes, $despues) use ($db, $plan, $etiqueta, $id, $afectaFutura) {
                    if ((string) $antes !== (string) $despues) {
                        cengi_pp_log($db, $plan, $tipo, $etiqueta, ['antes' => $antes, 'despues' => $despues, 'sesion_id' => $id,
                            'afecta' => $afectaFutura && in_array($tipo, CENGI_PP_TIPOS_AFECTAN, true)]);
                    }
                };
                $registrar('Cambio de fecha de sesión', cengi_pp_fecha($anterior['fecha']), cengi_pp_fecha($v['fecha']));
                $registrar('Cambio de horario', cengi_pp_horario($anterior['hora_inicio'], $anterior['hora_fin']), cengi_pp_horario($v['hora_inicio'], $v['hora_fin']));
                $registrar('Cambio de lugar', $anterior['lugar'], $v['lugar']);
                $registrar('Cambio de instructor', $anterior['instructor_nombre'] ?: 'Sin instructor', cengi_ses_nombre($db, 'instructores', $v['instructor_id']) ?: 'Sin instructor');
                $registrar('Cambio de soporte técnico', $anterior['soporte_nombre'] ?: 'Sin soporte', cengi_ses_nombre($db, 'soporte_tecnico', $v['soporte_id']) ?: 'Sin soporte');
                $registrar('Cambio de modalidad', $anterior['modalidad'], $v['modalidad']);
                $registrar('Ajuste de contenido', $anterior['modulo'] . ' — ' . $anterior['tema'], $v['modulo'] . ' — ' . $v['tema']);
                if ($anterior['estado'] !== $v['estado']) {
                    $tipo = $v['estado'] === 'cancelada' ? 'Sesión cancelada' : ($anterior['estado'] === 'cancelada' ? 'Sesión restaurada' : 'Sesión impartida');
                    cengi_pp_log($db, $plan, $tipo, $etiqueta, ['antes' => $anterior['estado'], 'despues' => $v['estado'], 'sesion_id' => $id,
                        'afecta' => $tipo !== 'Sesión impartida' && $convocado && $v['fecha'] >= $hoy]);
                }
                if ($correr) {
                    $corridas = cengi_ses_correr_siguientes($db, $plan, $anterior, $v['fecha']);
                    if ($corridas) {
                        $extra['corridas'] = count($corridas);
                    }
                }
                if ((int) $anterior['planificacion_id'] !== $v['planificacion_id']) {
                    cengi_pp_asignar_modulos($db, $anterior['planificacion_id']);
                }
            } else {
                $columnas = array_keys($v);
                $db->prepare('INSERT INTO curso_sesiones (' . implode(', ', $columnas) . ') VALUES (' . implode(', ', array_fill(0, count($columnas), '?')) . ')')->execute(array_values($v));
                $nuevoId = (int) $db->lastInsertId();
                $icsIds[] = $nuevoId;
                cengi_pp_log($db, $plan, 'Sesión agregada', cengi_pp_fecha($v['fecha']) . ' ' . cengi_pp_horario($v['hora_inicio'], $v['hora_fin']) . ' — ' . $v['tema'], [
                    'despues' => $v['lugar'] . ' · ' . (cengi_ses_nombre($db, 'instructores', $v['instructor_id']) ?: 'Sin instructor'),
                    'sesion_id' => $nuevoId, 'afecta' => $convocado && $v['fecha'] >= $hoy,
                ]);
            }
            cengi_pp_asignar_modulos($db, $v['planificacion_id']);
            if ($invitarCalendario) {
                $icsInvitar[] = $id ?: $nuevoId;
            }
            $extra['ver'] = $v['planificacion_id'];
            $codigoMensaje = isset($extra['corridas']) ? 'corridas' : 'guardada';
        } elseif ($accion === 'estado_sesion') {
            $sesion = cengi_ses_obtener($db, (int) ($_POST['id'] ?? 0));
            $estado = (string) ($_POST['estado'] ?? '');
            if (!$sesion || !array_key_exists($estado, CENGI_PP_ESTADOS_SESION)) {
                throw new InvalidArgumentException('La sesión no es válida.');
            }
            if ($sesion['liquidacion_id'] || $sesion['liquidacion_soporte_id']) {
                throw new InvalidArgumentException('Esta sesión está en una liquidación de pago. Anula la liquidación para cambiarla.');
            }
            if ($estado === 'impartida' && $sesion['fecha'] > $hoy) {
                throw new InvalidArgumentException('No se puede marcar como impartida una sesión futura (' . cengi_pp_fecha_corta($sesion['fecha']) . ').');
            }
            $plan = cengi_pp_plan($db, $sesion['planificacion_id']);
            $icsAntes = cengi_ics_instantanea($db, [], $sesion['planificacion_id']);
            if ($sesion['estado'] === 'cancelada' && $estado !== 'cancelada') {
                cengi_ses_validar_cupo($db, $plan, 1, $sesion['id']);
            }
            $db->prepare('UPDATE curso_sesiones SET estado = ? WHERE id = ?')->execute([$estado, $sesion['id']]);
            $tipo = $estado === 'cancelada' ? 'Sesión cancelada' : ($sesion['estado'] === 'cancelada' ? 'Sesión restaurada' : 'Sesión impartida');
            cengi_pp_log($db, $plan, $tipo, 'Sesión del ' . cengi_pp_fecha($sesion['fecha']) . ' — ' . $sesion['tema'], [
                'antes' => $sesion['estado'], 'despues' => $estado, 'sesion_id' => (int) $sesion['id'],
                'afecta' => $tipo !== 'Sesión impartida' && cengi_pp_convocado($plan) && $sesion['fecha'] >= $hoy,
            ]);
            cengi_pp_asignar_modulos($db, $sesion['planificacion_id']);
            $codigoMensaje = $estado;
        } elseif ($accion === 'eliminar_sesion') {
            $sesion = cengi_ses_obtener($db, (int) ($_POST['id'] ?? 0));
            if (!$sesion) {
                throw new InvalidArgumentException('La sesión ya no existe.');
            }
            if ($sesion['liquidacion_id'] || $sesion['liquidacion_soporte_id']) {
                throw new InvalidArgumentException('No se puede eliminar: la sesión está en una liquidación de pago.');
            }
            $plan = cengi_pp_plan($db, $sesion['planificacion_id']);
            $icsAntes = cengi_ics_instantanea($db, [], $sesion['planificacion_id']);
            if (aula_session_used($db, $sesion['id'])) {
                throw new InvalidArgumentException('La sesión tiene asistencia, recursos o actividades del aula. Cancélala para conservar su historial.');
            }
            $db->prepare('DELETE FROM curso_sesiones WHERE id = ?')->execute([$sesion['id']]);
            cengi_pp_log($db, $plan, 'Sesión eliminada', 'Sesión del ' . cengi_pp_fecha($sesion['fecha']) . ' — ' . $sesion['tema'], [
                'antes' => cengi_pp_fecha($sesion['fecha']) . ' ' . cengi_pp_horario($sesion['hora_inicio'], $sesion['hora_fin']), 'despues' => 'Eliminada',
                'afecta' => cengi_pp_convocado($plan) && $sesion['estado'] === 'programada' && $sesion['fecha'] >= $hoy,
            ]);
            cengi_pp_asignar_modulos($db, $sesion['planificacion_id']);
            $codigoMensaje = 'eliminada';
        } elseif ($accion === 'generar') {
            $plan = cengi_pp_plan($db, (int) ($_POST['planificacion_id'] ?? 0));
            if (!$plan || $plan['estado'] === 'cancelado') {
                throw new InvalidArgumentException('Selecciona un curso vigente.');
            }
            $dias = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['dias'] ?? [])), static function ($d) {
                return $d >= 0 && $d <= 6;
            })));
            $hi = (string) ($_POST['hora_inicio'] ?? '');
            $hf = (string) ($_POST['hora_fin'] ?? '');
            if (!cengi_pp_es_hora($hi) || !cengi_pp_es_hora($hf) || $hf <= $hi) {
                throw new InvalidArgumentException('La hora de fin debe ser mayor que la de inicio.');
            }
            $fechas = cengi_ses_fechas_generador($plan, (string) ($_POST['desde'] ?? ''), min(200, (int) ($_POST['cantidad'] ?? 0)), $dias, max(1, min(4, (int) ($_POST['frecuencia'] ?? 1))));
            if (!$fechas) {
                throw new InvalidArgumentException('No hay fechas para crear con ese patrón. Revisa los días, la cantidad y el rango del curso.');
            }
            cengi_ses_validar_cupo($db, $plan, count($fechas));
            $modulo = mb_substr(trim((string) ($_POST['modulo'] ?? '')), 0, 255, 'UTF-8') ?: 'Sesión única';
            // Tema: "Sesión N" con el número de la sesión en el curso (y el texto base, si se indica).
            $base = mb_substr(trim((string) ($_POST['tema'] ?? '')), 0, 220, 'UTF-8');
            $previas = cengi_ses_activas($db, $plan['id']);
            $modalidad = in_array($_POST['modalidad'] ?? '', CENGI_PP_MODALIDADES, true) ? $_POST['modalidad'] : $plan['modalidad'];
            $lugar = mb_substr(trim((string) ($_POST['lugar'] ?? '')), 0, 255, 'UTF-8') ?: cengi_pp_lugar_por_defecto($modalidad);
            $instructorId = (int) ($_POST['instructor_id'] ?? 0) ?: null;
            $soporteId = (int) ($_POST['soporte_id'] ?? 0) ?: null;
            $icsAntes = cengi_ics_instantanea($db, [], $plan['id']);
            $stmt = $db->prepare('INSERT INTO curso_sesiones (planificacion_id, modulo, tema, fecha, hora_inicio, hora_fin, instructor_id, soporte_id, modalidad, lugar, estado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            foreach ($fechas as $n => $fecha) {
                $stmt->execute([$plan['id'], $modulo, ($base !== '' ? $base . ' — ' : '') . 'Sesión ' . ($previas + $n + 1), $fecha,
                    substr($hi, 0, 5) . ':00', substr($hf, 0, 5) . ':00', $instructorId, $soporteId, $modalidad, $lugar, $fecha < $hoy ? 'impartida' : 'programada']);
                $icsIds[] = (int) $db->lastInsertId();
            }
            if ($invitarCalendario) {
                $icsInvitar = $icsIds;
            }
            if (!empty($_POST['avisar_programacion'])) {
                // Nota con la programación generada para instructor y soporte (tras asignar módulos e instructores).
                $programacionPendiente = ['ids' => $icsIds, 'nota' => CENGI_ICS_NOTA_PROGRAMACION, 'ics' => !$invitarCalendario, 'plan' => (int) $plan['id']];
            }
            cengi_pp_log($db, $plan, 'Sesiones agregadas', count($fechas) . ' sesión(es) generadas: ' . implode(', ', array_map('cengi_pp_fecha_corta', $fechas)), [
                'despues' => cengi_pp_horario($hi, $hf) . ' · ' . (cengi_ses_nombre($db, 'instructores', $instructorId) ?: 'Sin instructor'),
                'afecta' => cengi_pp_convocado($plan) && end($fechas) >= $hoy,
            ]);
            $extra['ver'] = $plan['id'];
            cengi_pp_asignar_modulos($db, $plan['id']);
            $codigoMensaje = 'generadas';
        } elseif ($accion === 'reprogramar_sesion') {
            // Mueve una sesión (y opcionalmente las siguientes, conservando los días de clase del curso).
            $sesion = cengi_ses_obtener($db, (int) ($_POST['id'] ?? 0));
            if (!$sesion) {
                throw new InvalidArgumentException('La sesión ya no existe.');
            }
            if ($sesion['estado'] !== 'programada') {
                throw new InvalidArgumentException('Solo se reprograma una sesión programada (esta está ' . cengi_pp_h($sesion['estado']) . ').');
            }
            if ($sesion['liquidacion_id'] || $sesion['liquidacion_soporte_id']) {
                throw new InvalidArgumentException('La sesión está en una liquidación de pago: no se puede mover.');
            }
            $plan = cengi_pp_plan($db, $sesion['planificacion_id']);
            if (!$plan || $plan['estado'] === 'cancelado') {
                throw new InvalidArgumentException('El curso está cancelado.');
            }
            $nuevaFecha = (string) ($_POST['fecha'] ?? '');
            $hi = (string) ($_POST['hora_inicio'] ?? '');
            $hf = (string) ($_POST['hora_fin'] ?? '');
            $correr = ($_POST['alcance'] ?? '') === 'siguientes';
            $motivo = mb_substr(trim((string) ($_POST['motivo'] ?? '')), 0, 300, 'UTF-8');
            if (!cengi_pp_es_fecha($nuevaFecha)) {
                throw new InvalidArgumentException('Indica la nueva fecha.');
            }
            if (!cengi_pp_es_hora($hi) || !cengi_pp_es_hora($hf) || $hf <= $hi) {
                throw new InvalidArgumentException('La hora de fin debe ser mayor que la de inicio.');
            }
            $hi = substr($hi, 0, 5) . ':00';
            $hf = substr($hf, 0, 5) . ':00';
            if ($nuevaFecha === $sesion['fecha'] && $hi === $sesion['hora_inicio'] && $hf === $sesion['hora_fin']) {
                throw new InvalidArgumentException('La fecha y el horario son los mismos: no hay nada que reprogramar.');
            }
            if ($nuevaFecha < $plan['fecha_inicio']) {
                throw new InvalidArgumentException('La nueva fecha es anterior al inicio del curso (' . cengi_pp_fecha_corta($plan['fecha_inicio']) . ').');
            }
            if ($nuevaFecha < $hoy) {
                throw new InvalidArgumentException('La nueva fecha ya pasó. Para registrar una sesión pasada, edítala y márcala como impartida.');
            }
            if (!$correr && $nuevaFecha > $plan['fecha_fin']) {
                throw new InvalidArgumentException('La fecha pasa del final del curso (' . cengi_pp_fecha_corta($plan['fecha_fin']) . '). Elige “Esta y las siguientes” para extender el curso o reprograma el curso en Planificación.');
            }
            if (mb_strlen($motivo, 'UTF-8') < 3) {
                throw new InvalidArgumentException('Escribe el motivo de la reprogramación: queda en la bitácora.');
            }

            $icsAntes = cengi_ics_instantanea($db, [], $plan['id']);
            $db->prepare('UPDATE curso_sesiones SET fecha = ?, hora_inicio = ?, hora_fin = ? WHERE id = ?')->execute([$nuevaFecha, $hi, $hf, $sesion['id']]);
            $afecta = cengi_pp_convocado($plan);
            cengi_pp_log($db, $plan, $nuevaFecha !== $sesion['fecha'] ? 'Cambio de fecha de sesión' : 'Cambio de horario', 'Sesión del ' . cengi_pp_fecha($sesion['fecha']) . ' — ' . $sesion['tema'], [
                'antes' => cengi_pp_fecha($sesion['fecha']) . ' ' . cengi_pp_horario($sesion['hora_inicio'], $sesion['hora_fin']),
                'despues' => cengi_pp_fecha($nuevaFecha) . ' ' . cengi_pp_horario($hi, $hf),
                'motivo' => $motivo, 'sesion_id' => (int) $sesion['id'], 'afecta' => $afecta,
            ]);
            $corridas = $correr ? cengi_ses_correr_siguientes($db, $plan, $sesion, $nuevaFecha, $motivo) : [];
            cengi_pp_asignar_modulos($db, $plan['id']);
            $movidas = array_merge([(int) $sesion['id']], array_column($corridas, 2));

            // Conflictos de instructor o soporte con la programación ya corrida (no antes).
            if (empty($_POST['confirmar'])) {
                $stmt = $db->prepare("SELECT a.fecha, a.tema, b.tema AS otro_tema, p2.nombre AS otro_plan,
                        IF(a.instructor_id IS NOT NULL AND b.instructor_id = a.instructor_id, 'del mismo instructor', 'del mismo soporte técnico') AS quien
                    FROM curso_sesiones a
                    INNER JOIN curso_sesiones b ON b.id <> a.id AND b.fecha = a.fecha AND a.hora_inicio < b.hora_fin AND b.hora_inicio < a.hora_fin
                        AND ((a.instructor_id IS NOT NULL AND b.instructor_id = a.instructor_id) OR (a.soporte_id IS NOT NULL AND b.soporte_id = a.soporte_id))
                    INNER JOIN planificacion_cursos p2 ON p2.id = b.planificacion_id
                    WHERE a.id IN (" . implode(',', array_map('intval', $movidas)) . ") AND a.estado <> 'cancelada' AND b.estado <> 'cancelada' AND p2.estado <> 'cancelado'
                    ORDER BY a.fecha");
                $stmt->execute();
                $choques = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if ($choques) {
                    $db->rollBack();
                    $avisos = array_map(static function ($c) {
                        return cengi_pp_h(cengi_pp_fecha_corta($c['fecha']) . ': «' . $c['tema'] . '» choca con «' . $c['otro_tema'] . '» (' . $c['otro_plan'] . ') ' . $c['quien'] . '.');
                    }, array_slice($choques, 0, 5));
                    if (count($choques) > 5) {
                        $avisos[] = 'Y ' . (count($choques) - 5) . ' choque(s) más.';
                    }
                    $reabrir = ['modal' => 'reprogramar', 'datos' => $_POST, 'avisos' => $avisos];
                    throw new DomainException('avisos');
                }
            }
            if (!empty($_POST['avisar'])) {
                $programacionPendiente = ['ids' => $movidas, 'plan' => (int) $plan['id'], 'ics' => false,
                    'nota' => "Hola {nombre}:\n\nSe reprogramaron tus sesiones como {rol} de {curso}.\nMotivo: " . $motivo . "\n\nEstas son las fechas actualizadas:",
                    'asunto' => 'Reprogramación de sesiones — ' . $plan['nombre'],
                    'cierre' => 'Si aceptaste las invitaciones de Outlook, tu calendario se actualiza solo con estas fechas.'];
            }
            $extra['ver'] = $plan['id'];
            $extra['n'] = count($movidas);
            $codigoMensaje = 'reprogramada';
        } elseif ($accion === 'invitar_plan') {
            // Invita por Outlook a quien aún no tenga la invitación de las sesiones futuras del curso
            // (solo las del instructor filtrado, si lo hay).
            $plan = cengi_pp_plan($db, (int) ($_POST['planificacion_id'] ?? 0));
            if (!$plan || $plan['estado'] === 'cancelado') {
                throw new InvalidArgumentException('Selecciona un curso vigente.');
            }
            $instructorPost = (int) ($_POST['instructor'] ?? 0);
            $proximas = cengi_ics_proximas($db, $plan['id'], 0, $instructorPost ?: '');
            if (!$proximas) {
                throw new InvalidArgumentException('No hay sesiones programadas a partir de hoy' . ($instructorPost ? ' para ese instructor' : '') . ' en este curso.');
            }
            $icsInvitar = array_keys($proximas);
            if ($instructorPost) {
                $persona = cengi_ics_personas_programacion($proximas, $instructorPost);
                $icsSolo = array_filter(array_column($persona, 'correo'));
                if (!$icsSolo) {
                    throw new InvalidArgumentException('El instructor no tiene un correo registrado en su ficha.');
                }
            }
            $extra['ver'] = $plan['id'];
            $codigoMensaje = 'invitaciones';
        } elseif ($accion === 'enviar_programacion') {
            // Nota con la programación de cada persona (se envía después de confirmar la transacción).
            $planIdPost = (int) ($_POST['planificacion_id'] ?? 0);
            $plan = $planIdPost ? cengi_pp_plan($db, $planIdPost) : null;
            $instructorPost = ($_POST['instructor'] ?? '') === 'sin' ? 'sin' : ((int) ($_POST['instructor'] ?? 0) ?: '');
            if ($planIdPost && (!$plan || $plan['estado'] === 'cancelado')) {
                throw new InvalidArgumentException('Selecciona un curso vigente.');
            }
            if (!$plan && ($instructorPost === '' || $instructorPost === 'sin')) {
                throw new InvalidArgumentException('Selecciona un curso o un instructor para enviar su programación.');
            }
            $personas = cengi_ics_personas_programacion(cengi_ics_proximas($db, $planIdPost, (int) ($_POST['anio'] ?? 0), $instructorPost), $instructorPost !== 'sin' ? (int) $instructorPost : 0);
            $elegidas = array_intersect_key($personas, array_flip((array) ($_POST['destinatarios'] ?? [])));
            $elegidas = array_filter($elegidas, static function ($p) {
                return $p['correo'] !== '';
            });
            if (!$elegidas) {
                throw new InvalidArgumentException('Marca al menos una persona con correo registrado.');
            }
            $nota = mb_substr(trim((string) ($_POST['nota'] ?? '')), 0, 3000, 'UTF-8') ?: CENGI_ICS_NOTA_PROGRAMACION;
            $conInvitaciones = ($_POST['modo'] ?? '') === 'invitaciones';
            $programacionPendiente = ['personas' => $elegidas, 'nota' => $nota, 'ics' => !$conInvitaciones, 'plan' => $planIdPost ?: null];
            if ($conInvitaciones) {
                foreach ($elegidas as $p) {
                    $icsInvitar = array_merge($icsInvitar, array_map('intval', array_column($p['sesiones'], 'id')));
                }
                $icsSolo = array_column($elegidas, 'correo');
            }
            $extra += array_filter(['ver' => $planIdPost ?: '', 'instructor' => $instructorPost]);
            $codigoMensaje = 'programacion';
        } elseif ($accion === 'eliminar_programacion') {
            $plan = cengi_pp_plan($db, (int) ($_POST['planificacion_id'] ?? 0));
            if (!$plan) {
                throw new InvalidArgumentException('El curso ya no existe.');
            }
            if (mb_strtoupper(trim((string) ($_POST['confirmacion'] ?? '')), 'UTF-8') !== 'ELIMINAR') {
                throw new InvalidArgumentException('Para eliminar la programación escribe ELIMINAR en la confirmación.');
            }
            $todas = ($_POST['alcance'] ?? '') === 'todas';
            $icsAntes = cengi_ics_instantanea($db, [], $plan['id']);
            $futuras = $db->prepare("SELECT COUNT(*) FROM curso_sesiones WHERE planificacion_id = ? AND estado = 'programada' AND fecha >= ?");
            $futuras->execute([$plan['id'], $hoy]);
            $habiaFuturas = (int) $futuras->fetchColumn() > 0;
            // Nunca se eliminan sesiones incluidas en una liquidación de pago.
            $stmt = $db->prepare('DELETE FROM curso_sesiones WHERE planificacion_id = ? AND liquidacion_id IS NULL AND liquidacion_soporte_id IS NULL'
                . ($todas ? '' : " AND estado IN ('programada', 'cancelada')")
                . (aula_integrada($db) ? ' AND NOT EXISTS(SELECT 1 FROM aula_recursos r WHERE r.sesion_id=curso_sesiones.id) AND NOT EXISTS(SELECT 1 FROM aula_evaluaciones e WHERE e.sesion_id=curso_sesiones.id) AND NOT EXISTS(SELECT 1 FROM aula_asistencias a WHERE a.sesion_id=curso_sesiones.id)' : ''));
            $stmt->execute([$plan['id']]);
            $eliminadas = $stmt->rowCount();
            if (!$eliminadas) {
                throw new InvalidArgumentException('No había sesiones que eliminar con esa opción (las liquidadas se conservan).');
            }
            cengi_pp_log($db, $plan, 'Sesiones eliminadas', 'Se eliminó la programación del curso: ' . $eliminadas . ' sesión(es) '
                . ($todas ? '(todas las no liquidadas)' : '(programadas y canceladas; se conservan las impartidas)'), [
                'antes' => $eliminadas . ' sesión(es)', 'despues' => 'Eliminadas', 'afecta' => cengi_pp_convocado($plan) && $habiaFuturas,
            ]);
            cengi_pp_asignar_modulos($db, $plan['id']);
            $extra['ver'] = $plan['id'];
            $extra['n'] = $eliminadas;
            $codigoMensaje = 'programacion_eliminada';
        } elseif ($accion === 'guardar_soporte') {
            $id = (int) ($_POST['id'] ?? 0);
            $v = [
                'nombre' => mb_substr(trim((string) ($_POST['nombre'] ?? '')), 0, 255, 'UTF-8'),
                'tipo' => ($_POST['tipo'] ?? '') === 'Externo' ? 'Externo' : 'Interno',
                'correo' => trim((string) ($_POST['correo'] ?? '')),
                'telefono' => mb_substr(trim((string) ($_POST['telefono'] ?? '')), 0, 50, 'UTF-8'),
                'tarifa' => (float) ($_POST['tarifa'] ?? -1),
                'estado' => !empty($_POST['estado']) ? 1 : 0,
                'notas' => mb_substr(trim((string) ($_POST['notas'] ?? '')), 0, 500, 'UTF-8'),
            ];
            if (mb_strlen($v['nombre'], 'UTF-8') < 4 || ($v['correo'] !== '' && !filter_var($v['correo'], FILTER_VALIDATE_EMAIL)) || $v['tarifa'] < 0) {
                $reabrir = ['modal' => 'soporte', 'datos' => $_POST];
                throw new InvalidArgumentException('Revisa el nombre, el correo y la tarifa por sesión (0 o mayor).');
            }
            if ($id) {
                $columnas = implode(', ', array_map(static function ($c) {
                    return $c . ' = ?';
                }, array_keys($v)));
                $db->prepare("UPDATE soporte_tecnico SET $columnas WHERE id = ?")->execute(array_merge(array_values($v), [$id]));
            } else {
                $siguiente = (int) $db->query("SELECT COALESCE(MAX(CAST(SUBSTRING(codigo, 5) AS UNSIGNED)), 0) + 1 FROM soporte_tecnico WHERE codigo LIKE 'SOP-%'")->fetchColumn();
                $v['codigo'] = 'SOP-' . str_pad((string) $siguiente, 3, '0', STR_PAD_LEFT);
                $columnas = array_keys($v);
                $db->prepare('INSERT INTO soporte_tecnico (' . implode(', ', $columnas) . ') VALUES (' . implode(', ', array_fill(0, count($columnas), '?')) . ')')->execute(array_values($v));
            }
            $extra['soporte'] = 1;
            $codigoMensaje = 'soporte';
        } else {
            throw new InvalidArgumentException('Acción no válida.');
        }

        $db->commit();
        // Ya guardado: nota de programación y luego invitaciones, actualizaciones y cancelaciones de calendario.
        if ($programacionPendiente) {
            $personasNota = $programacionPendiente['personas'] ?? null;
            if ($personasNota === null) {
                $proximasNota = array_filter(cengi_ics_sesiones($db, $programacionPendiente['ids']), 'cengi_ics_vigente');
                $personasNota = cengi_ics_personas_programacion($proximasNota);
            }
            $prog = ['prog' => 0, 'progf' => 0, 'progs' => 0];
            foreach ($personasNota as $persona) {
                if ($persona['correo'] === '') {
                    $prog['progs']++;
                } elseif (cengi_ics_enviar_programacion($db, $persona, $programacionPendiente['nota'], $programacionPendiente['ics'], $programacionPendiente['plan'],
                    $programacionPendiente['asunto'] ?? null, $programacionPendiente['cierre'] ?? null)) {
                    $prog['prog']++;
                } else {
                    $prog['progf']++;
                }
            }
            $extra += array_filter($prog);
        }
        $extra += cengi_ics_parametros(cengi_ics_sincronizar($db, $icsAntes, $icsIds, $icsInvitar, $icsSolo));
        $limpio = array_diff_key($_GET, array_flip(['editar', 'generar', 'soporte', 'msg', 'n', 'inv', 'invc', 'invs', 'invf', 'prog', 'progf', 'progs', 'exportar']));
        $_GET = $limpio;
        cengi_pp_redirigir('programa_sesiones.php', $extra + ['msg' => $codigoMensaje]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($e instanceof DomainException) {
            $mensajeTipo = 'warning';
            $mensaje = '';
        } else {
            $mensajeTipo = 'error';
            if ($e instanceof InvalidArgumentException) {
                $mensaje = $e->getMessage();
            } elseif ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                $mensaje = 'Ya existe un registro con ese nombre o código.';
            } else {
                error_log('Programa de sesiones: ' . $e->getMessage());
                $mensaje = 'No fue posible guardar los cambios.';
            }
            if ($accion === 'guardar_sesion' && !$reabrir) {
                $reabrir = ['modal' => 'sesion', 'datos' => $_POST, 'error' => $mensaje];
                $mensaje = '';
            }
            if ($accion === 'generar') {
                $reabrir = ['modal' => 'generar', 'datos' => $_POST, 'error' => $mensaje];
                $mensaje = '';
            }
            if ($accion === 'reprogramar_sesion' && !$reabrir) {
                $reabrir = ['modal' => 'reprogramar', 'datos' => $_POST, 'error' => $mensaje];
                $mensaje = '';
            }
            if ($reabrir && $reabrir['modal'] === 'soporte') {
                $reabrir['error'] = $mensaje;
                $mensaje = '';
            }
        }
    }
}

if ($mensaje === '' && ($_GET['msg'] ?? '') === 'corridas') {
    $mensaje = cengi_pp_h('Sesión movida: las ' . (int) ($_GET['corridas'] ?? 0) . ' sesión(es) siguientes se corrieron las mismas clases y los módulos se reasignaron. Si el curso tiene inscritos, revisa la bitácora para avisarles.');
} elseif ($mensaje === '' && ($_GET['msg'] ?? '') === 'reprogramada') {
    $n = (int) ($_GET['n'] ?? 1);
    $mensaje = cengi_pp_h($n > 1 ? 'Reprogramación aplicada: se movieron ' . $n . ' sesiones (esta y las siguientes) y los módulos se reasignaron.' : 'Sesión reprogramada.')
        . ' Si el curso tiene inscritos, puedes avisarles desde <a href="comunicaciones.php?tab=avisar">Comunicaciones → Por avisar</a>.';
} elseif ($mensaje === '' && ($_GET['msg'] ?? '') === 'programacion_eliminada') {
    $mensaje = cengi_pp_h('Programación eliminada: ' . (int) ($_GET['n'] ?? 0) . ' sesión(es). Las invitaciones de Outlook enviadas se cancelaron. Ya puedes volver a programar el curso.');
} elseif ($mensaje === '' && ($_GET['msg'] ?? '') === 'importado') {
    $mensaje = cengi_pp_h('Carga masiva aplicada: ' . (int) ($_GET['n'] ?? 0) . ' sesión(es) importada(s). Si el curso tiene inscritos, avísales desde Comunicaciones → Por avisar.');
} elseif ($mensaje === '' && isset(CENGI_SES_MENSAJES[$_GET['msg'] ?? ''])) {
    $mensaje = cengi_pp_h(CENGI_SES_MENSAJES[$_GET['msg']]);
}
if ($mensaje !== '' && $mensajeTipo === 'success') {
    $resumenIcs = trim(implode(' ', array_filter([
        (int) ($_GET['prog'] ?? 0) ? 'Nota de programación enviada a ' . (int) $_GET['prog'] . ' persona(s).' : '',
        (int) ($_GET['progs'] ?? 0) ? (int) $_GET['progs'] . ' persona(s) sin correo registrado no recibieron la nota.' : '',
        (int) ($_GET['progf'] ?? 0) ? (int) $_GET['progf'] . ' nota(s) no se pudieron enviar (revisa Comunicaciones).' : '',
        cengi_ics_resumen_envio(cengi_ics_desde_parametros($_GET)),
    ])));
    if ($resumenIcs === '' && ($_GET['msg'] ?? '') === 'invitaciones') {
        $resumenIcs = 'Todas las personas ya tenían su invitación.';
    }
    $mensaje .= $resumenIcs !== '' ? ' ' . cengi_pp_h($resumenIcs) : '';
}

// ---------------------------------------------------------------- datos
$gruposPlanes = cengi_pp_planes_opciones($db, true);
$ver = (string) ($_GET['ver'] ?? $_GET['plan'] ?? '');
$sesionEditar = (int) ($_GET['editar'] ?? 0) ? cengi_ses_obtener($db, (int) $_GET['editar']) : null;
$sesionReprogramar = (int) ($_GET['reprogramar'] ?? 0) ? cengi_ses_obtener($db, (int) $_GET['reprogramar']) : null;
if (($sesionEditar || $sesionReprogramar) && $ver === '') {
    $ver = (string) ($sesionEditar ?: $sesionReprogramar)['planificacion_id'];
}
if ($ver === '' || ($ver[0] !== 'z' && !ctype_digit($ver))) {
    $ver = 'z:' . cengi_pp_anio_actual();
}
$planSel = ctype_digit($ver) ? cengi_pp_plan($db, (int) $ver) : null;
if (!$planSel && ctype_digit($ver)) {
    $ver = 'z:' . cengi_pp_anio_actual();
}
$anioSel = $planSel ? (int) $planSel['anio'] : (int) substr($ver, 2);
$modalidadFiltro = in_array($_GET['modalidad'] ?? '', CENGI_PP_MODALIDADES, true) ? $_GET['modalidad'] : '';
$estadoFiltro = array_key_exists($_GET['estado'] ?? '', CENGI_PP_ESTADOS_SESION) ? $_GET['estado'] : '';
$busqueda = trim((string) ($_GET['q'] ?? ''));
// Instructor: id, 'sin' (sesiones sin instructor) o '' (todos).
$instructorFiltro = ($_GET['instructor'] ?? '') === 'sin' ? 'sin' : ((int) ($_GET['instructor'] ?? 0) ?: '');

$sql = "
    SELECT s.*, p.nombre AS plan_nombre, p.fecha_inicio AS plan_fi, p.fecha_fin AS plan_ff, p.estado AS plan_estado, p.curso_id AS plan_curso_id,
           p.sesiones_planificadas AS plan_planificadas, p.modalidad AS plan_modalidad,
           i.nombre AS instructor_nombre, i.codigo_catedratico, i.estado AS instructor_estado,
           st.nombre AS soporte_nombre, l.numero AS liq_numero, ls.numero AS liq_soporte_numero
    FROM curso_sesiones s
    INNER JOIN planificacion_cursos p ON p.id = s.planificacion_id
    LEFT JOIN instructores i ON i.id = s.instructor_id
    LEFT JOIN soporte_tecnico st ON st.id = s.soporte_id
    LEFT JOIN liquidaciones l ON l.id = s.liquidacion_id
    LEFT JOIN liquidaciones ls ON ls.id = s.liquidacion_soporte_id
    WHERE " . ($planSel ? 's.planificacion_id = ?' : 'p.anio = ?') . "
    ORDER BY s.fecha, s.hora_inicio";
$stmt = $db->prepare($sql);
$stmt->execute([$planSel ? $planSel['id'] : $anioSel]);
$base = $stmt->fetchAll(PDO::FETCH_ASSOC);
$conflictos = cengi_pp_conflictos($db);
$documentacion = cengi_pp_documentacion($db);

$sesiones = array_values(array_filter($base, static function ($s) use ($modalidadFiltro, $estadoFiltro, $busqueda, $instructorFiltro) {
    if (($modalidadFiltro !== '' && $s['modalidad'] !== $modalidadFiltro) || ($estadoFiltro !== '' && $s['estado'] !== $estadoFiltro)) {
        return false;
    }
    if (($instructorFiltro === 'sin' && $s['instructor_id']) || ($instructorFiltro !== '' && $instructorFiltro !== 'sin' && (int) $s['instructor_id'] !== $instructorFiltro)) {
        return false;
    }
    return $busqueda === '' || strpos(cengi_texto_normalizado($s['tema'] . ' ' . $s['instructor_nombre'] . ' ' . $s['modulo'] . ' ' . $s['lugar'] . ' ' . $s['plan_nombre']), cengi_texto_normalizado($busqueda)) !== false;
}));
// Instructores presentes en el curso o año (para el filtro).
$instructoresVista = [];
foreach ($base as $s) {
    if ($s['instructor_id']) {
        $instructoresVista[(int) $s['instructor_id']] = $s['instructor_nombre'];
    }
}
asort($instructoresVista);
$instructorFiltroNombre = $instructorFiltro === 'sin' ? 'Sin instructor' : ($instructoresVista[$instructorFiltro] ?? '');

$exportar = (string) ($_GET['exportar'] ?? '');
// "Programa de sesiones - Curso" o "... - Año 2026", con el instructor si está filtrado.
$tituloExportacion = ($planSel ? $planSel['nombre'] : 'Año ' . cengi_pp_anio_texto($anioSel)) . ($instructorFiltroNombre !== '' ? ' - ' . $instructorFiltroNombre : '');
if ($exportar === 'excel' || $exportar === 'csv') {
    cengi_ses_exportar_excel($sesiones, $planSel ? $planSel['nombre'] : 'Año ' . cengi_pp_anio_texto($anioSel), array_filter([
        $instructorFiltroNombre !== '' ? 'Instructor: ' . $instructorFiltroNombre : '', $modalidadFiltro !== '' ? 'Modalidad: ' . $modalidadFiltro : '',
        $estadoFiltro !== '' ? 'Estado: ' . CENGI_PP_ESTADOS_SESION[$estadoFiltro][0] : '', $busqueda !== '' ? 'Búsqueda: ' . $busqueda : '',
    ]), 'Programa de sesiones - ' . $tituloExportacion);
} elseif ($exportar === 'ics') {
    $idsIcs = array_column(array_filter($sesiones, static function ($s) {
        return $s['estado'] !== 'cancelada';
    }), 'id');
    if ($idsIcs) {
        $contenidoIcs = cengi_ics_calendario(cengi_ics_sesiones($db, $idsIcs), 'PUBLISH', [], $tituloExportacion);
        cengi_descarga_encabezados('text/calendar; charset=utf-8', 'Sesiones - ' . $tituloExportacion, 'ics');
        header('Content-Length: ' . strlen($contenidoIcs));
        echo $contenidoIcs;
        exit;
    }
    $mensajeTipo = 'warning';
    $mensaje = 'No hay sesiones para descargar con los filtros actuales (las canceladas no se incluyen).';
}

$cuenta = static function ($estado) use ($base) {
    return count(array_filter($base, static function ($s) use ($estado) {
        return $s['estado'] === $estado;
    }));
};
$nConflictos = count(array_filter($base, static function ($s) use ($conflictos) {
    return isset($conflictos['instructor'][$s['id']]);
}));
if ($planSel) {
    $activas = count($base) - $cuenta('cancelada');
    $faltan = max(0, $planSel['sesiones_planificadas'] - $activas);
    $resumen = [['Planificadas', (int) $planSel['sesiones_planificadas'], ''], ['Programadas', $activas, ''], ['Impartidas', $cuenta('impartida'), '#3E7A12'],
        ['Por programar', $faltan, $faltan ? '#8A6600' : ''], ['Conflictos', $nConflictos, $nConflictos ? '#B23223' : ''],
        ['Rango del curso', '<small>' . cengi_pp_fecha_corta($planSel['fecha_inicio']) . ' – ' . cengi_pp_fecha_corta($planSel['fecha_fin']) . '</small>', '']];
} else {
    $resumen = [['Sesiones en el año', count($base), ''], ['Programadas', $cuenta('programada'), ''], ['Impartidas', $cuenta('impartida'), '#3E7A12'],
        ['Canceladas', $cuenta('cancelada'), ''], ['Conflictos', $nConflictos, $nConflictos ? '#B23223' : '']];
}

// Curso seleccionado: sesiones numeradas ("2 de 5") y espacios por programar hasta completar las planificadas.
$numeroSesion = [];
$espacios = [];
$cupoLleno = false;
if ($planSel) {
    foreach ($base as $s) {
        if ($s['estado'] !== 'cancelada') {
            $numeroSesion[$s['id']] = count($numeroSesion) + 1;
        }
    }
    $cupoLleno = $planSel['estado'] === 'cancelado' || $faltan === 0;
    if (!$cupoLleno && $modalidadFiltro === '' && $estadoFiltro === '' && $busqueda === '' && $instructorFiltro === '') {
        $espacios = range(count($numeroSesion) + 1, (int) $planSel['sesiones_planificadas']);
    }
}
$nColumnas = $planSel ? 8 : 7;

// Módulos del curso seleccionado: rango de sesiones de cada uno y avisos si la estructura está incompleta.
$estructuraSel = $planSel ? cengi_pp_estructura_modulos($db, $planSel) : [];
$automaticoSel = cengi_pp_modulos_automaticos($estructuraSel);
$sumaModulos = array_sum(array_column($estructuraSel, 'sesiones'));
$dondeModulos = $planSel && $planSel['curso_id']
    ? [cengi_pp_url_editar_curso($planSel), 'Gestión de cursos (editar el curso)']
    : ($planSel ? ['planificacion_cursos.php?' . http_build_query(['anio' => (int) $planSel['anio'], 'q' => $planSel['nombre']]), 'Planificación de cursos (editar el plan)'] : null);

// Datos para los modales (JS)
$planesJs = [];
$stmtPlanes = $db->query("
    SELECT p.*, COALESCE(SUM(s.estado <> 'cancelada'), 0) AS activas, MAX(IF(s.estado <> 'cancelada', s.fecha, NULL)) AS ultima
    FROM planificacion_cursos p LEFT JOIN curso_sesiones s ON s.planificacion_id = p.id
    GROUP BY p.id ORDER BY p.anio DESC, p.fecha_inicio
");
foreach ($stmtPlanes->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $estructuraP = cengi_pp_estructura_modulos($db, $p);
    $planesJs[(int) $p['id']] = [
        'automatico' => cengi_pp_modulos_automaticos($estructuraP),
        'estructura' => array_map(static function ($m) {
            return [$m['nombre'], $m['sesiones']];
        }, $estructuraP),
        'nombre' => $p['nombre'], 'estado' => $p['estado'], 'fi' => $p['fecha_inicio'], 'ff' => $p['fecha_fin'],
        'hi' => substr($p['hora_inicio'], 0, 5), 'hf' => substr($p['hora_fin'], 0, 5), 'modalidad' => $p['modalidad'],
        'instructor' => $p['instructor_id'] ? (int) $p['instructor_id'] : '', 'sesiones' => (int) $p['sesiones_planificadas'],
        'activas' => (int) $p['activas'], 'ultima' => $p['ultima'], 'modulos' => cengi_pp_modulos_plan($db, $p),
        'dias' => cengi_pp_dias_plan($p['dias']),
    ];
}
$ocupadas = $db->query("SELECT id, fecha, TIME_FORMAT(hora_inicio, '%H:%i') AS hi, TIME_FORMAT(hora_fin, '%H:%i') AS hf, instructor_id, soporte_id, tema
    FROM curso_sesiones WHERE estado <> 'cancelada' AND fecha >= CURDATE() - INTERVAL 60 DAY")->fetchAll(PDO::FETCH_ASSOC);
$instructores = cengi_pp_instructores($db, false);
$soportes = cengi_pp_soportes($db, false);
$soportesUso = $db->query("SELECT soporte_id, SUM(estado = 'impartida') AS impartidas, SUM(estado = 'programada') AS programadas FROM curso_sesiones
    WHERE soporte_id IS NOT NULL AND YEAR(fecha) = YEAR(CURDATE()) GROUP BY soporte_id")->fetchAll(PDO::FETCH_UNIQUE);

if ($sesionEditar && !$reabrir) {
    $reabrir = ['modal' => 'sesion', 'datos' => [
        'id' => $sesionEditar['id'], 'planificacion_id' => $sesionEditar['planificacion_id'], 'modulo' => $sesionEditar['modulo'], 'tema' => $sesionEditar['tema'],
        'fecha' => $sesionEditar['fecha'], 'hora_inicio' => substr($sesionEditar['hora_inicio'], 0, 5), 'hora_fin' => substr($sesionEditar['hora_fin'], 0, 5),
        'instructor_id' => $sesionEditar['instructor_id'], 'soporte_id' => $sesionEditar['soporte_id'], 'modalidad' => $sesionEditar['modalidad'],
        'lugar' => $sesionEditar['lugar'], 'estado' => $sesionEditar['estado'],
    ]];
} elseif (!$reabrir && (int) ($_GET['reprogramar'] ?? 0)) {
    $reabrir = ['modal' => 'reprogramar', 'datos' => ['id' => (int) $_GET['reprogramar']]];
} elseif (!$reabrir && !empty($_GET['generar'])) {
    $reabrir = ['modal' => 'generar', 'datos' => ['planificacion_id' => $planSel['id'] ?? '']];
} elseif (!$reabrir && !empty($_GET['soporte'])) {
    $reabrir = ['modal' => 'soporte', 'datos' => []];
}
// Sesiones del curso o año para la vista previa de "Reprogramar" (JS).
$sesionesJs = array_map(static function ($s) use ($numeroSesion) {
    return ['id' => (int) $s['id'], 'plan' => (int) $s['planificacion_id'], 'fecha' => $s['fecha'], 'hi' => substr($s['hora_inicio'], 0, 5), 'hf' => substr($s['hora_fin'], 0, 5),
        'estado' => $s['estado'], 'liq' => (bool) ($s['liquidacion_id'] || $s['liquidacion_soporte_id']), 'tema' => $s['tema'],
        'instr' => $s['instructor_id'] ? (int) $s['instructor_id'] : null, 'sop' => $s['soporte_id'] ? (int) $s['soporte_id'] : null,
        'num' => $numeroSesion[$s['id']] ?? null, 'curso' => $s['plan_nombre']];
}, $base);
// "Reprogramar" desde otra página (calendario) sobre una sesión de otro curso: se muestra ese curso.
if ($reabrir && $reabrir['modal'] === 'reprogramar' && !in_array((int) ($reabrir['datos']['id'] ?? 0), array_column($sesionesJs, 'id'), true)) {
    $reabrir = null;
}

$filtrosActivos = array_filter(['ver' => $ver, 'instructor' => $instructorFiltro, 'modalidad' => $modalidadFiltro, 'estado' => $estadoFiltro, 'q' => $busqueda], static function ($x) {
    return $x !== '';
});

// Programación por enviar: sesiones programadas desde hoy del curso (o del año con un instructor filtrado).
$puedeEnviarProgramacion = $planSel ? $planSel['estado'] !== 'cancelado' : ($instructorFiltro !== '' && $instructorFiltro !== 'sin');
$personasProgramacion = $puedeEnviarProgramacion
    ? cengi_ics_personas_programacion(cengi_ics_proximas($db, $planSel['id'] ?? 0, $anioSel, $instructorFiltro), $instructorFiltro !== 'sin' ? (int) $instructorFiltro : 0)
    : [];
$sesionesFuturas = $planSel ? count(array_filter($base, static function ($s) use ($hoy) {
    return $s['estado'] === 'programada' && $s['fecha'] >= $hoy;
})) : 0;
$eliminables = $planSel ? [
    'pendientes' => count(array_filter($base, static function ($s) {
        return in_array($s['estado'], ['programada', 'cancelada'], true) && !$s['liquidacion_id'] && !$s['liquidacion_soporte_id'];
    })),
    'todas' => count(array_filter($base, static function ($s) {
        return !$s['liquidacion_id'] && !$s['liquidacion_soporte_id'];
    })),
] : [];

function cengi_ses_datos_fila(array $s)
{
    return json_encode([
        'id' => (int) $s['id'], 'planificacion_id' => (int) $s['planificacion_id'], 'modulo' => $s['modulo'], 'tema' => $s['tema'], 'fecha' => $s['fecha'],
        'hora_inicio' => substr($s['hora_inicio'], 0, 5), 'hora_fin' => substr($s['hora_fin'], 0, 5), 'instructor_id' => $s['instructor_id'],
        'soporte_id' => $s['soporte_id'], 'modalidad' => $s['modalidad'], 'lugar' => $s['lugar'], 'estado' => $s['estado'],
        'liquidada' => (bool) ($s['liquidacion_id'] || $s['liquidacion_soporte_id']),
    ], JSON_UNESCAPED_UNICODE);
}
?>
<!DOCTYPE html>
<html lang="es">
<head><?php cengi_pp_head('Programa de sesiones'); ?><link rel="stylesheet" href="css/sesiones_edicion_tabla.css?v=<?=filemtime(__DIR__.'/css/sesiones_edicion_tabla.css')?>"></head>
<body class="cengi-canvas cg-tema">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page">
    <?php cengi_banner(); ?>
    <?php cengi_pp_subnav('programa_sesiones.php'); ?>
    <?php cengi_pp_feedback($mensaje, $mensajeTipo); ?>

    <?php
    $tituloLleno = $cupoLleno ? ($planSel['estado'] === 'cancelado' ? 'El curso está cancelado.' : 'Ya están programadas las ' . (int) $planSel['sesiones_planificadas'] . ' sesiones del curso.') : '';
    $urlExportar = static function ($tipo) use ($filtrosActivos) {
        return 'programa_sesiones.php?' . http_build_query($filtrosActivos + ['exportar' => $tipo]);
    };
    $hayFiltros = $instructorFiltro !== '' || $modalidadFiltro !== '' || $estadoFiltro !== '' || $busqueda !== '';
    $conCorreo = count(array_filter($personasProgramacion, static function ($p) {
        return $p['correo'] !== '';
    }));
    ?>
    <section class="pp-card ses-filtros">
        <form class="ses-filtros-form" method="get">
            <label class="ses-campo is-curso"><span>Curso o año</span>
                <select name="ver" onchange="this.form.submit()"><?php echo cengi_pp_options_planes($gruposPlanes ?: [cengi_pp_anio_actual() => []], $ver, 'Todos los cursos'); ?></select></label>
            <label class="ses-campo"><span>Instructor</span>
                <select name="instructor" onchange="this.form.submit()">
                    <option value="">Todos los instructores</option>
                    <?php foreach ($instructoresVista as $idInstr => $nombreInstr): ?><option value="<?php echo $idInstr; ?>" <?php echo $instructorFiltro === $idInstr ? 'selected' : ''; ?>><?php echo cengi_pp_h($nombreInstr); ?></option><?php endforeach; ?>
                    <option value="sin" <?php echo $instructorFiltro === 'sin' ? 'selected' : ''; ?>>Sin instructor asignado</option>
                </select></label>
            <label class="ses-campo"><span>Modalidad</span>
                <select name="modalidad" onchange="this.form.submit()">
                    <option value="">Todas</option>
                    <?php foreach (CENGI_PP_MODALIDADES as $m): ?><option <?php echo $modalidadFiltro === $m ? 'selected' : ''; ?>><?php echo $m; ?></option><?php endforeach; ?>
                </select></label>
            <label class="ses-campo"><span>Estado</span>
                <select name="estado" onchange="this.form.submit()">
                    <option value="">Todos</option>
                    <?php foreach (CENGI_PP_ESTADOS_SESION as $clave => [$texto]): ?><option value="<?php echo $clave; ?>" <?php echo $estadoFiltro === $clave ? 'selected' : ''; ?>><?php echo $texto; ?></option><?php endforeach; ?>
                </select></label>
            <label class="ses-campo is-buscar"><span>Buscar</span>
                <span class="ses-buscar"><span class="glyphicon glyphicon-search" aria-hidden="true"></span><input type="search" name="q" value="<?php echo cengi_pp_h($busqueda); ?>" placeholder="Tema, módulo, lugar o instructor..."></span></label>
            <div class="ses-filtros-fin">
                <span class="ses-total"><b><?php echo count($sesiones); ?></b> sesión(es)</span>
                <?php if ($hayFiltros): ?><a class="ses-limpiar" href="programa_sesiones.php?ver=<?php echo cengi_pp_h(urlencode($ver)); ?>"><span class="glyphicon glyphicon-remove"></span>Quitar filtros</a><?php endif; ?>
                <button type="submit" class="sr-only">Filtrar</button>
            </div>
        </form>
        <?php if ($instructorFiltroNombre !== ''): ?>
            <div class="ses-filtro-aviso"><span class="glyphicon glyphicon-user"></span>
                Mostrando solo las sesiones de <b><?php echo cengi_pp_h($instructorFiltroNombre); ?></b>. Exportar, descargar el calendario, invitar por Outlook y enviar la programación usan solo estas sesiones.</div>
        <?php endif; ?>
    </section>

    <section class="ses-curso<?php echo $planSel ? '' : ' is-anio'; ?>">
        <div class="ses-curso-info">
            <?php if ($planSel):
                $activasSel = count($numeroSesion);
                $pctProg = min(100, (int) round($activasSel * 100 / max(1, (int) $planSel['sesiones_planificadas'])));
            ?>
                <a class="ses-volver" href="programa_sesiones.php?<?php echo cengi_pp_h(http_build_query(array_filter(['ver' => 'z:' . (int) $planSel['anio'], 'instructor' => $instructorFiltro, 'modalidad' => $modalidadFiltro, 'estado' => $estadoFiltro, 'q' => $busqueda], 'strlen'))); ?>"><span class="glyphicon glyphicon-arrow-left" aria-hidden="true"></span> Volver a todos los cursos de <?php echo (int) $planSel['anio']; ?></a>
                <span class="ses-curso-eyebrow"><?php echo cengi_pp_h(implode(' · ', array_filter([$planSel['codigo_curso'] ?? '', $planSel['modalidad'], $planSel['dias']]))); ?> <?php echo cengi_pp_badge(CENGI_PP_ESTADOS_PLAN, $planSel['estado']); ?></span>
                <h2><?php echo cengi_pp_h($planSel['nombre']); ?></h2>
                <div class="ses-curso-meta">
                    <span><span class="glyphicon glyphicon-calendar"></span><?php echo cengi_pp_fecha_corta($planSel['fecha_inicio']) . ' – ' . cengi_pp_fecha_corta($planSel['fecha_fin']) . ' ' . substr($planSel['fecha_fin'], 0, 4); ?></span>
                    <span><span class="glyphicon glyphicon-time"></span><?php echo cengi_pp_horario($planSel['hora_inicio'], $planSel['hora_fin']); ?></span>
                    <span><span class="glyphicon glyphicon-user"></span><?php echo count($instructoresVista); ?> instructor(es)</span>
                    <?php if ($sesionesFuturas): ?><span><span class="glyphicon glyphicon-forward"></span><?php echo $sesionesFuturas; ?> por impartir</span><?php endif; ?>
                </div>
                <div class="ses-curso-prog">
                    <span class="ses-curso-bar<?php echo $activasSel > (int) $planSel['sesiones_planificadas'] ? ' is-over' : ($activasSel < (int) $planSel['sesiones_planificadas'] ? ' is-incompleto' : ''); ?>"><span style="width:<?php echo $pctProg; ?>%"></span></span>
                    <span><b><?php echo $activasSel; ?></b> de <?php echo (int) $planSel['sesiones_planificadas']; ?> sesiones programadas · <?php echo $cuenta('impartida'); ?> impartidas</span>
                </div>
            <?php else: ?>
                <span class="ses-curso-eyebrow">Vista general</span>
                <h2>Todas las sesiones del año <?php echo cengi_pp_anio_texto($anioSel); ?></h2>
                <p class="ses-curso-ayuda">Elige un curso para ver su avance, enviar la programación a los instructores o eliminar su programación. Con un instructor filtrado puedes enviarle todas sus sesiones del año.</p>
            <?php endif; ?>
        </div>
        <div class="ses-curso-acciones">
            <div class="ses-acciones-fila">
                <button type="button" class="btn btn-default" data-toggle="modal" data-target="#generar-modal"<?php echo $cupoLleno ? ' disabled title="' . cengi_pp_h($tituloLleno) . '"' : ''; ?>><span class="glyphicon glyphicon-calendar"></span> Generar sesiones</button>
                <button type="button" class="btn btn-primary" data-toggle="modal" data-target="#sesion-modal"<?php echo $cupoLleno ? ' disabled title="' . cengi_pp_h($tituloLleno) . '"' : ''; ?>><span class="glyphicon glyphicon-plus"></span> Nueva sesión</button>
            </div>
            <div class="ses-acciones-fila">
                <button type="button" class="ses-accion is-programacion" data-toggle="modal" data-target="#programacion-modal"<?php echo $puedeEnviarProgramacion ? '' : ' disabled title="Elige un curso o filtra por un instructor"'; ?>>
                    <span class="glyphicon glyphicon-envelope"></span> Enviar programación<?php echo $conCorreo ? ' <b>' . $conCorreo . '</b>' : ''; ?></button>
                <div class="dropdown ses-dropdown">
                    <button type="button" class="ses-accion dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><span class="glyphicon glyphicon-download-alt"></span> Exportar <span class="caret"></span></button>
                    <ul class="dropdown-menu dropdown-menu-right">
                        <li class="dropdown-header"><?php echo count($sesiones); ?> sesión(es) con los filtros actuales</li>
                        <li><a href="<?php echo cengi_pp_h($urlExportar('excel')); ?>"><span class="glyphicon glyphicon-list-alt"></span> Excel (.xlsx)</a></li>
                        <li><a href="<?php echo cengi_pp_h($urlExportar('ics')); ?>"><span class="glyphicon glyphicon-calendar"></span> Calendario para Outlook (.ics)</a></li>
                    </ul>
                </div>
                <div class="dropdown ses-dropdown">
                    <button type="button" class="ses-accion dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><span class="glyphicon glyphicon-option-horizontal"></span> Más <span class="caret"></span></button>
                    <ul class="dropdown-menu dropdown-menu-right">
                        <?php if ($planSel && $planSel['estado'] !== 'cancelado'): ?>
                            <li><form method="post" onsubmit="return confirm('¿Enviar la invitación de Outlook de cada sesión futura<?php echo $instructorFiltroNombre !== '' && $instructorFiltro !== 'sin' ? ' a ' . cengi_pp_h(addslashes($instructorFiltroNombre)) : ' a los instructores y al soporte técnico'; ?> que aún no la tenga?');">
                                <input type="hidden" name="accion" value="invitar_plan"><input type="hidden" name="planificacion_id" value="<?php echo (int) $planSel['id']; ?>">
                                <?php if ($instructorFiltro !== '' && $instructorFiltro !== 'sin'): ?><input type="hidden" name="instructor" value="<?php echo (int) $instructorFiltro; ?>"><?php endif; ?>
                                <button type="submit"><span class="glyphicon glyphicon-send"></span> Solo invitaciones de Outlook</button></form></li>
                        <?php endif; ?>
                        <li><a href="carga_masiva_planificacion.php?tipo=ses&amp;destino=<?php echo $planSel ? (int) $planSel['id'] : cengi_pp_h(urlencode($ver)); ?>"><span class="glyphicon glyphicon-upload"></span> Cargar sesiones desde Excel/CSV</a></li>
                        <li><a href="#" data-toggle="modal" data-target="#soporte-modal"><span class="glyphicon glyphicon-blackboard"></span> Personal de soporte técnico</a></li>
                        <?php if ($planSel && $planSel['curso_id']): ?><li><a href="aula_gestion.php?curso=<?php echo (int) $planSel['curso_id']; ?>#sesiones"><span class="glyphicon glyphicon-blackboard"></span> Aula del curso: materiales, tareas y asistencia</a></li><?php endif; ?>
                        <?php if ($planSel): ?><li><a href="bitacora_cambios.php?plan=<?php echo (int) $planSel['id']; ?>"><span class="glyphicon glyphicon-time"></span> Bitácora de cambios del curso</a></li><?php endif; ?>
                        <?php if ($planSel): ?>
                            <li role="separator" class="divider"></li>
                            <li><a href="#" class="is-danger" data-toggle="modal" data-target="#eliminar-modal"><span class="glyphicon glyphicon-trash"></span> Eliminar programación del curso</a></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <div class="pp-strip">
        <?php foreach ($resumen as [$etiqueta, $valor, $color]): ?>
            <div class="pp-mini-stat"><span><?php echo $etiqueta; ?></span><strong<?php echo $color ? ' style="color:' . $color . '"' : ''; ?>><?php echo $valor; ?></strong></div>
        <?php endforeach; ?>
    </div>

    <?php if ($planSel && $planSel['estado'] !== 'cancelado'): ?>
        <div class="pp-ses-modulos">
            <span class="pp-ses-modulos-titulo">Módulos</span>
            <?php if ($automaticoSel):
                $desde = 1;
                foreach ($estructuraSel as $m):
                    $hasta = $desde + $m['sesiones'] - 1; ?>
                    <span class="pp-chip is-ok" title="<?php echo $m['sesiones']; ?> sesión(es)"><?php echo cengi_pp_h($m['nombre']); ?> · <?php echo $desde === $hasta ? 'sesión ' . $desde : 'sesiones ' . $desde . '–' . $hasta; ?></span>
                <?php $desde = $hasta + 1; endforeach; ?>
                <?php if ($sumaModulos !== (int) $planSel['sesiones_planificadas']): ?>
                    <span class="pp-chip is-warn">Los módulos suman <?php echo $sumaModulos; ?> sesiones y el curso tiene <?php echo (int) $planSel['sesiones_planificadas']; ?></span>
                <?php endif; ?>
                <span class="pp-sub">Se asignan solos según el orden de las sesiones por fecha.</span>
            <?php else: ?>
                <span class="pp-sub"><?php echo $estructuraSel ? 'Falta indicar cuántas sesiones tiene cada módulo' : 'El curso no tiene módulos definidos'; ?>: el módulo de cada sesión se elige a mano.
                    Defínelo en <a href="<?php echo cengi_pp_h($dondeModulos[0]); ?>"><?php echo $dondeModulos[1]; ?></a> para que se asignen solos por número de sesión.</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($planSel && $espacios): ?>
        <div class="pp-ses-aviso">
            <span class="glyphicon glyphicon-tasks" aria-hidden="true"></span>
            <div><b><?php echo count($numeroSesion); ?> de <?php echo (int) $planSel['sesiones_planificadas']; ?> sesiones programadas.</b>
                Faltan <?php echo count($espacios); ?>: se habilitan abajo como “Por programar”<?php echo $planSel['dias'] !== '' ? ' (días del plan: ' . cengi_pp_h($planSel['dias']) . ')' : ''; ?>.</div>
            <?php if (count($espacios) > 1): ?>
                <button type="button" class="btn btn-default btn-sm" data-toggle="modal" data-target="#generar-modal"><span class="glyphicon glyphicon-calendar"></span> Programar las <?php echo count($espacios); ?> restantes</button>
            <?php endif; ?>
        </div>
    <?php elseif ($planSel && $planSel['estado'] !== 'cancelado' && $faltan === 0): ?>
        <div class="pp-ses-aviso is-ok">
            <span class="glyphicon glyphicon-ok-circle" aria-hidden="true"></span>
            <div><b>Las <?php echo (int) $planSel['sesiones_planificadas']; ?> sesiones del curso están programadas.</b> Para agregar otra, aumenta el número de sesiones en <a href="planificacion_cursos.php?<?php echo cengi_pp_h(http_build_query(['anio' => (int) $planSel['anio'], 'q' => $planSel['nombre']])); ?>">Planificación de cursos</a> o cancela una sesión.</div>
        </div>
    <?php endif; ?>

    <?php
    // Encabezado y fila de una sesión: se usan en la tabla del curso y en el detalle de
    // cada curso de la vista del año. El tema no se muestra (se edita en "Editar").
    $encabezadoSesiones = static function ($conNumero) use($modoEdicion) {
        ?>
        <thead><tr>
            <?php if ($conNumero): ?><th class="pp-ses-num">N.°</th><?php endif; ?>
            <th>Fecha y horario</th><th>Módulo</th><th>Instructor</th><th>Soporte técnico</th><th>Modalidad y lugar</th><th>Estado</th>
            <th class="is-actions"><?php if($modoEdicion):?>Guardar<?php else:?><span class="sr-only">Acciones</span><?php endif;?></th>
        </tr></thead>
        <?php
    };
    $filaSesion = static function (array $s, $conNumero) use ($planSel, $numeroSesion, $conflictos, $documentacion, $hoy, $modoEdicion) {
        $fuera = $s['fecha'] < $s['plan_fi'] || $s['fecha'] > $s['plan_ff'];
        $liquidada = $s['liquidacion_id'] || $s['liquidacion_soporte_id'];
        $docS = $s['instructor_id'] ? ($documentacion[(int) $s['instructor_id']] ?? null) : null;
        ?>
        <tr class="<?php echo $s['estado'] === 'cancelada' ? 'pp-muted-row' : ''; ?>"<?php if($modoEdicion):?> data-inline-numero="<?=$conNumero?1:0?>" data-inline-session="<?=cengi_pp_h(cengi_ses_datos_fila($s))?>" data-inline-revision="<?=cengi_ses_revision_tabla($s)?>" data-inline-liquidacion="<?=(int)(bool)$s['liquidacion_id']?>" data-inline-soporte="<?=(int)(bool)$s['liquidacion_soporte_id']?>"<?php endif;?><?php echo (string) $s['tema'] !== '' ? ' title="Tema: ' . cengi_pp_h($s['tema']) . '"' : ''; ?>>
            <?php if ($conNumero): ?>
                <td class="pp-ses-num"><?php echo isset($numeroSesion[$s['id']]) ? '<b>' . $numeroSesion[$s['id']] . '</b><span class="pp-sub">de ' . (int) $planSel['sesiones_planificadas'] . '</span>' : '<span class="pp-sub" title="Cancelada: no ocupa una de las sesiones del curso">—</span>'; ?></td>
            <?php endif; ?>
            <td class="ses-fecha">
                <b><?php echo cengi_pp_fecha($s['fecha']); ?></b> <span class="ses-dia"><?php echo CENGI_PP_DIAS_CORTOS[(int) date('w', strtotime($s['fecha']))]; ?></span>
                <span class="pp-sub"><?php echo cengi_pp_horario($s['hora_inicio'], $s['hora_fin']); ?></span>
                <?php if (isset($conflictos['instructor'][$s['id']])): ?><span class="pp-chip is-danger">Choca con otra sesión del instructor</span><?php endif; ?>
                <?php if ($fuera && $s['estado'] !== 'cancelada'): ?><span class="pp-chip is-warn">Fuera del rango del curso</span><?php endif; ?>
            </td>
            <td><?php echo cengi_pp_h($s['modulo']) ?: '<span class="pp-sub">—</span>'; ?></td>
            <td><?php echo cengi_pp_h($s['instructor_nombre'] ?: '—'); ?>
                <?php if ($s['instructor_nombre']): ?><span class="pp-sub pp-mono"><?php echo cengi_pp_h(cengi_pp_codigo_instructor($s['instructor_id'] ?? 0, $s['codigo_catedratico'])); ?></span><?php endif; ?>
                <?php if ($docS && $s['estado'] === 'programada' && !$docS['completa']): ?><a class="pp-chip is-yellow" href="ficha_instructor.php?id=<?php echo (int) $s['instructor_id']; ?>&amp;tab=documentos" title="<?php echo cengi_pp_h($docS['activo'] ? 'Falta: ' . implode(', ', $docS['faltan']) : 'Instructor inactivo'); ?>"><?php echo $docS['activo'] ? 'Doc. incompleta' : 'Inactivo'; ?></a><?php endif; ?>
            </td>
            <td>
                <?php if ($s['soporte_nombre']): ?>
                    <?php echo cengi_pp_h($s['soporte_nombre']); ?>
                    <?php if (isset($conflictos['soporte'][$s['id']])): ?><span class="pp-chip is-danger">Soporte con otra sesión</span><?php endif; ?>
                <?php elseif ($s['modalidad'] !== 'Presencial' && $s['estado'] === 'programada'): ?>
                    <span class="pp-chip is-yellow">Sin soporte</span>
                <?php else: ?><span class="pp-sub">—</span><?php endif; ?>
            </td>
            <td><?php echo cengi_pp_h($s['modalidad']); ?><?php if ($s['lugar'] !== ''): ?><span class="pp-sub"><?php echo cengi_pp_h($s['lugar']); ?></span><?php endif; ?></td>
            <td><?php echo cengi_pp_badge(CENGI_PP_ESTADOS_SESION, $s['estado']); ?>
                <?php if ($s['liq_numero']): ?><span class="pp-sub pp-mono" title="Incluida en liquidación"><?php echo cengi_pp_h($s['liq_numero']); ?></span><?php endif; ?>
            </td>
            <td class="is-actions">
                <div class="cengi-row-actions" data-actions-title="Gestionar sesión">
                    <?php if ($s['plan_curso_id'] && $s['estado'] !== 'cancelada'): ?>
                        <a class="cengi-action-btn is-aula" href="aula_gestion.php?curso=<?php echo (int) $s['plan_curso_id']; ?>#ses-<?php echo (int) $s['id']; ?>" data-tooltip="Aula: grabación, material, tarea y asistencia" aria-label="Abrir la sesión en el aula"><span class="glyphicon glyphicon-blackboard"></span></a>
                    <?php endif; ?>
                    <?php if ($s['estado'] === 'programada' && !$liquidada && $s['plan_estado'] !== 'cancelado'): ?>
                        <button type="button" class="cengi-action-btn is-reprogramar" data-reprogramar="<?php echo (int) $s['id']; ?>" data-tooltip="Reprogramar (mover fecha u horario)" aria-label="Reprogramar"><span class="glyphicon glyphicon-share-alt"></span></button>
                    <?php endif; ?>
                    <button type="button" class="cengi-action-btn is-edit" data-toggle="modal" data-target="#sesion-modal" data-sesion="<?php echo cengi_pp_h(cengi_ses_datos_fila($s)); ?>" data-tooltip="Editar (tema, fecha, instructor…)" aria-label="Editar"><span class="glyphicon glyphicon-pencil"></span></button>
                    <?php if (!$liquidada): ?>
                        <?php foreach ($s['estado'] === 'programada' ? ['impartida' => ['ok-circle', 'Marcar como impartida', 'is-view'], 'cancelada' => ['ban-circle', 'Cancelar sesión', 'is-delete']]
                            : ($s['estado'] === 'impartida' ? ['cancelada' => ['ban-circle', 'Cancelar sesión', 'is-delete']] : ['programada' => ['repeat', 'Restaurar como programada', 'is-view']]) as $nuevo => [$icono, $titulo, $clase]):
                            if ($nuevo === 'impartida' && $s['fecha'] > $hoy) { continue; } ?>
                            <form method="post" class="cengi-inline-form"><input type="hidden" name="accion" value="estado_sesion"><input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>"><input type="hidden" name="estado" value="<?php echo $nuevo; ?>">
                                <button type="submit" class="cengi-action-btn <?php echo $clase; ?>" data-tooltip="<?php echo $titulo; ?>" aria-label="<?php echo $titulo; ?>"><span class="glyphicon glyphicon-<?php echo $icono; ?>"></span></button></form>
                        <?php endforeach; ?>
                        <form method="post" class="cengi-inline-form" onsubmit="return confirm('¿Eliminar esta sesión?');"><input type="hidden" name="accion" value="eliminar_sesion"><input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
                            <button type="submit" class="cengi-action-btn is-delete" data-tooltip="Eliminar" aria-label="Eliminar"><span class="glyphicon glyphicon-trash"></span></button></form>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php
    };
    ?>

        <div class="ses-inline-toolbar">
        <?php $urlTabla='programa_sesiones.php?'.http_build_query(array_merge($_GET,['edicion'=>!$modoEdicion?1:0])); ?>
        <a class="btn <?=!$modoEdicion?'btn-primary':'btn-default'?>" href="<?=cengi_pp_h($urlTabla)?>"><?=!$modoEdicion?'Activar modo edición':'Salir del modo edición'?></a>
        <?php if($modoEdicion):?><span>Despliega un curso, edita sus columnas y guarda las filas seleccionadas juntas. Los módulos automáticos y los datos vinculados a pagos liquidados permanecen protegidos.</span><?php endif;?>
        <?php if($modoEdicion):?><label><input type="checkbox" id="ses-inline-select-all"> Seleccionar filas modificadas</label><button type="button" class="btn btn-primary" id="ses-inline-save-selected" disabled>Guardar seleccionadas (0)</button><span id="ses-inline-status" role="status" aria-live="polite"></span><?php endif;?>
        </div>
    <?php if ($planSel): ?>
        <section class="cengi-courses-section ses-tabla">
            <div class="pp-table-wrap">
                <table class="cengi-org-table">
                    <?php $encabezadoSesiones(true); ?>
                    <tbody>
                        <?php if (!$sesiones && !$espacios): ?>
                            <tr><td colspan="<?php echo $nColumnas; ?>" class="pp-empty"><?php echo $base ? 'Ninguna sesión coincide con los filtros.' : 'Aún no hay sesiones. Usa “Generar sesiones” o “Nueva sesión”.'; ?></td></tr>
                        <?php endif; ?>
                        <?php foreach ($sesiones as $s) { $filaSesion($s, true); } ?>
                        <?php foreach ($espacios as $k): ?>
                            <tr class="pp-ses-espacio">
                                <td class="pp-ses-num"><b><?php echo $k; ?></b><span class="pp-sub">de <?php echo (int) $planSel['sesiones_planificadas']; ?></span></td>
                                <td colspan="<?php echo $nColumnas - 3; ?>">
                                    <div class="pp-ses-pendiente">
                                        <button type="button" class="btn btn-default btn-sm pp-ses-programar" data-toggle="modal" data-target="#sesion-modal" data-sugerir="1"
                                            data-sesion="<?php echo cengi_pp_h(json_encode(['planificacion_id' => (int) $planSel['id'], 'tema' => 'Sesión ' . $k, 'modulo_sugerido' => $automaticoSel ? cengi_pp_modulo_de_sesion($estructuraSel, $k)['nombre'] : null], JSON_UNESCAPED_UNICODE)); ?>"><span class="glyphicon glyphicon-plus"></span> Programar sesión <?php echo $k; ?></button>
                                        <span><?php echo $automaticoSel ? 'Módulo: <b>' . cengi_pp_h(cengi_pp_modulo_de_sesion($estructuraSel, $k)['nombre']) . '</b> · ' : ''; ?>Fecha, horario, instructor y lugar por definir</span>
                                    </div>
                                </td>
                                <td><span class="cengi-status-badge is-neutral"><i></i>Por programar</span></td>
                                <td class="is-actions"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    <?php else:
        // Vista del año: un renglón por curso con su avance; "Ver sesiones" despliega el detalle.
        $porCurso = [];
        foreach ($sesiones as $s) {
            $porCurso[(int) $s['planificacion_id']][] = $s;
        }
        $abrirTodos = $hayFiltros && count($porCurso) <= 3;
    ?>
        <section class="ses-cursos">
            <?php if (!$porCurso): ?>
                <div class="pp-card ses-vacio"><?php echo $base ? 'Ninguna sesión coincide con los filtros.' : 'Aún no hay sesiones en ' . cengi_pp_anio_texto($anioSel) . '. Elige un curso y usa “Generar sesiones” o “Nueva sesión”.'; ?></div>
            <?php endif; ?>
            <?php foreach ($porCurso as $planId => $lista):
                $p = $lista[0];
                $cuentaCurso = ['programada' => 0, 'impartida' => 0, 'cancelada' => 0];
                $proxima = null;
                $alertas = 0;
                foreach ($lista as $s) {
                    $cuentaCurso[$s['estado']] = ($cuentaCurso[$s['estado']] ?? 0) + 1;
                    if ($s['estado'] === 'programada' && $s['fecha'] >= $hoy && ($proxima === null || $s['fecha'] < $proxima['fecha'])) {
                        $proxima = $s;
                    }
                    $alertas += isset($conflictos['instructor'][$s['id']]) || isset($conflictos['soporte'][$s['id']]) ? 1 : 0;
                }
                $activasCurso = $cuentaCurso['programada'] + $cuentaCurso['impartida'];
                $planificadas = max(1, (int) $p['plan_planificadas']);
                $pct = min(100, (int) round($cuentaCurso['impartida'] * 100 / $planificadas));
            ?>
                <details class="ses-curso-item<?php echo $p['plan_estado'] === 'cancelado' ? ' is-cancelado' : ''; ?>"<?php echo $abrirTodos ? ' open' : ''; ?>>
                    <summary>
                        <span class="ses-ci-nombre">
                            <b><?php echo cengi_pp_h($p['plan_nombre']); ?></b>
                            <span class="pp-sub"><?php echo cengi_pp_fecha_corta($p['plan_fi']) . ' – ' . cengi_pp_fecha_corta($p['plan_ff']); ?><?php echo $p['plan_modalidad'] ? ' · ' . cengi_pp_h($p['plan_modalidad']) : ''; ?></span>
                        </span>
                        <span class="ses-ci-avance" title="<?php echo $cuentaCurso['impartida']; ?> impartidas de <?php echo (int) $p['plan_planificadas']; ?> planificadas">
                            <span class="ses-curso-bar"><span style="width:<?php echo $pct; ?>%"></span></span>
                            <span class="pp-sub"><b><?php echo $cuentaCurso['impartida']; ?></b> de <?php echo (int) $p['plan_planificadas']; ?> impartidas · <?php echo $activasCurso; ?> programadas</span>
                        </span>
                        <span class="ses-ci-proxima">
                            <?php if ($proxima): ?><span class="pp-sub">Próxima</span><b><?php echo cengi_pp_fecha_corta($proxima['fecha']); ?></b><?php else: ?><span class="pp-sub">Sin próximas</span><?php endif; ?>
                        </span>
                        <span class="ses-ci-chips">
                            <?php if ($alertas): ?><span class="pp-chip is-danger"><?php echo $alertas; ?> conflicto(s)</span><?php endif; ?>
                            <?php if ($cuentaCurso['cancelada']): ?><span class="pp-chip"><?php echo $cuentaCurso['cancelada']; ?> cancelada(s)</span><?php endif; ?>
                        </span>
                        <span class="ses-ci-botones">
                            <span class="ses-ci-ver"><span class="glyphicon glyphicon-chevron-down" aria-hidden="true"></span> <span class="ses-ci-ver-txt">Ver sesiones</span> <b><?php echo count($lista); ?></b></span>
                            <a class="ses-ci-gestionar" href="programa_sesiones.php?ver=<?php echo (int) $planId; ?>" title="Avance, módulos, sesiones por programar y envío de la programación"><span class="glyphicon glyphicon-cog" aria-hidden="true"></span> Gestionar curso</a>
                        </span>
                    </summary>
                    <div class="ses-ci-detalle pp-table-wrap">
                        <table class="cengi-org-table">
                            <?php $encabezadoSesiones(false); ?>
                            <tbody><?php foreach ($lista as $s) { $filaSesion($s, false); } ?></tbody>
                        </table>
                    </div>
                </details>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</main>

<?php
$opcionesInstructores = '<option value="">Sin instructor</option>';
foreach ($instructores as $i) {
    // El código de instructor viene de la ficha del instructor (no se captura aquí).
    $opcionesInstructores .= '<option value="' . (int) $i['id'] . '">' . cengi_pp_h($i['nombre'] . ' · ' . cengi_pp_codigo_instructor($i['id'], $i['codigo_catedratico'])
        . ((int) $i['estado'] !== 1 ? ' (inactivo)' : (empty($documentacion[(int) $i['id']]['faltan']) ? '' : ' — doc. incompleta'))) . '</option>';
}
$opcionesSoporte = '<option value="">No requiere soporte técnico</option>';
foreach ($soportes as $sp) {
    $opcionesSoporte .= '<option value="' . (int) $sp['id'] . '">' . cengi_pp_h($sp['nombre'] . ' · ' . $sp['tipo'] . ' · ' . cengi_pp_q($sp['tarifa']) . '/sesión' . ((int) $sp['estado'] !== 1 ? ' (inactivo)' : '')) . '</option>';
}
$opcionesModalidad = '';
foreach (CENGI_PP_MODALIDADES as $m) {
    $opcionesModalidad .= '<option>' . $m . '</option>';
}
$opcionesPlanes = cengi_pp_options_planes(array_map(static function ($g) {
    return array_values(array_filter($g, static function ($p) {
        return $p['estado'] !== 'cancelado';
    }));
}, $gruposPlanes), $planSel['id'] ?? '');
?>

<div class="modal fade cengi-course-form-modal pp-modal" id="sesion-modal" tabindex="-1" role="dialog" aria-labelledby="sesion-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post" id="sesion-form" autocomplete="off">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
                <span class="cengi-course-modal-kicker">Programa de sesiones</span>
                <h4 class="modal-title" id="sesion-title">Nueva sesión</h4>
                <p id="sf-rango"></p>
            </div>
            <div class="modal-body">
                <input type="hidden" name="accion" value="guardar_sesion">
                <input type="hidden" name="id" id="sf-id">
                <input type="hidden" name="confirmar" id="sf-confirmar" value="">
                <input type="hidden" name="fecha_original" id="sf-fecha-original" value="">
                <div class="pp-form-msg" id="sf-msg"></div>
                <div class="cengi-course-modal-grid">
                    <div class="form-group is-full"><label for="sf-plan">Curso / diplomado</label><select class="form-control" id="sf-plan" name="planificacion_id" required><?php echo $opcionesPlanes; ?></select></div>
                    <div class="form-group"><label for="sf-modulo">Módulo</label><select class="form-control" id="sf-modulo" name="modulo"></select><small class="help-block" id="sf-modulo-ayuda" hidden>Se asigna solo según el número de la sesión.</small></div>
                    <div class="form-group"><label for="sf-estado">Estado</label><select class="form-control" id="sf-estado" name="estado"><?php foreach (CENGI_PP_ESTADOS_SESION as $clave => [$texto]): ?><option value="<?php echo $clave; ?>"><?php echo $texto; ?></option><?php endforeach; ?></select></div>
                    <div class="form-group is-full"><label for="sf-tema">Tema</label><input type="text" class="form-control" id="sf-tema" name="tema" maxlength="255" required></div>
                    <div class="form-group"><label for="sf-fecha">Fecha</label><input type="date" class="form-control" id="sf-fecha" name="fecha" required></div>
                    <div class="form-group"><label>Horario</label><div style="display:flex;gap:6px;"><input type="time" class="form-control" id="sf-hi" name="hora_inicio" required aria-label="Hora de inicio"><input type="time" class="form-control" id="sf-hf" name="hora_fin" required aria-label="Hora de fin"></div></div>
                    <div class="form-group is-full rp-hint" id="sf-correr-wrap" hidden>
                        <span class="glyphicon glyphicon-info-sign" aria-hidden="true"></span>
                        <span>Al guardar <b>solo se mueve esta sesión</b>. Para correr también las siguientes y ver cómo quedan antes de aplicarlo, usa <b>Reprogramar</b>.</span>
                        <button type="button" class="btn btn-default btn-sm" id="sf-ir-reprogramar"><span class="glyphicon glyphicon-share-alt"></span> Reprogramar…</button>
                    </div>
                    <div class="form-group"><label for="sf-instr">Instructor</label><select class="form-control" id="sf-instr" name="instructor_id"><?php echo $opcionesInstructores; ?></select></div>
                    <div class="form-group"><label for="sf-soporte">Soporte técnico</label><select class="form-control" id="sf-soporte" name="soporte_id"><?php echo $opcionesSoporte; ?></select></div>
                    <div class="form-group"><label for="sf-modalidad">Modalidad</label><select class="form-control" id="sf-modalidad" name="modalidad"><?php echo $opcionesModalidad; ?></select></div>
                    <div class="form-group"><label for="sf-lugar">Lugar o enlace</label><input type="text" class="form-control" id="sf-lugar" name="lugar" maxlength="255" required></div>
                    <div class="form-group is-full pp-ics-check"><label><input type="checkbox" name="invitar_calendario" value="1" id="sf-invitar" checked>
                        <span><b>Enviar invitación de Outlook</b> al instructor y al soporte técnico <small>Quien ya la recibió obtiene los cambios automáticamente; si la sesión se cancela, se quita de su calendario.</small></span></label></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="sf-btn">Guardar sesión</button>
            </div>
        </form>
    </div></div>
</div>

<div class="modal fade cengi-course-form-modal pp-modal" id="generar-modal" tabindex="-1" role="dialog" aria-labelledby="generar-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post" id="generar-form" autocomplete="off">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
                <span class="cengi-course-modal-kicker">Programa de sesiones</span>
                <h4 class="modal-title" id="generar-title">Generar sesiones</h4>
                <p id="gn-rango"></p>
            </div>
            <div class="modal-body">
                <input type="hidden" name="accion" value="generar">
                <div class="pp-form-msg" id="gn-msg"></div>
                <div class="cengi-course-modal-grid">
                    <div class="form-group is-full"><label for="gn-plan">Curso / diplomado</label><select class="form-control" id="gn-plan" name="planificacion_id"><?php echo $opcionesPlanes; ?></select></div>
                    <div class="form-group"><label for="gn-modulo">Módulo</label><select class="form-control" id="gn-modulo" name="modulo"></select><small class="help-block" id="gn-modulo-ayuda" hidden>Cada sesión toma su módulo según su número.</small></div>
                    <div class="form-group"><label for="gn-tema">Tema <small>(opcional)</small></label><input type="text" class="form-control" id="gn-tema" name="tema" maxlength="220" placeholder="Se agrega “Sesión N” a cada una"></div>
                    <div class="form-group"><label for="gn-desde">Desde</label><input type="date" class="form-control" id="gn-desde" name="desde"></div>
                    <div class="form-group"><label for="gn-cant">Cantidad</label><input type="number" class="form-control" id="gn-cant" name="cantidad" min="1" max="200"></div>
                    <div class="form-group is-full"><label>Días de la semana</label>
                        <div class="pp-days" id="gn-dias"><?php foreach ([1, 2, 3, 4, 5, 6, 0] as $d): ?><label><input type="checkbox" name="dias[]" value="<?php echo $d; ?>"> <?php echo ucfirst(CENGI_PP_DIAS_CORTOS[$d]); ?></label><?php endforeach; ?></div>
                    </div>
                    <div class="form-group"><label for="gn-frec">Frecuencia</label><select class="form-control" id="gn-frec" name="frecuencia"><option value="1">Cada semana</option><option value="2">Cada 2 semanas</option><option value="4">Cada 4 semanas</option></select></div>
                    <div class="form-group"><label>Horario</label><div style="display:flex;gap:6px;"><input type="time" class="form-control" id="gn-hi" name="hora_inicio" aria-label="Hora de inicio"><input type="time" class="form-control" id="gn-hf" name="hora_fin" aria-label="Hora de fin"></div></div>
                    <div class="form-group"><label for="gn-instr">Instructor</label><select class="form-control" id="gn-instr" name="instructor_id"><?php echo $opcionesInstructores; ?></select></div>
                    <div class="form-group"><label for="gn-soporte">Soporte técnico</label><select class="form-control" id="gn-soporte" name="soporte_id"><?php echo $opcionesSoporte; ?></select></div>
                    <div class="form-group"><label for="gn-modalidad">Modalidad</label><select class="form-control" id="gn-modalidad" name="modalidad"><?php echo $opcionesModalidad; ?></select></div>
                    <div class="form-group"><label for="gn-lugar">Lugar o enlace</label><input type="text" class="form-control" id="gn-lugar" name="lugar" maxlength="255"></div>
                    <div class="form-group is-full pp-ics-check"><label><input type="checkbox" name="avisar_programacion" value="1" id="gn-avisar" checked>
                        <span><b>Enviar nota con la programación</b> al instructor y al soporte técnico <small>Un solo correo por persona con la tabla de sus sesiones (fechas, horarios, tema y lugar).</small></span></label></div>
                    <div class="form-group is-full pp-ics-check"><label><input type="checkbox" name="invitar_calendario" value="1" id="gn-invitar" checked>
                        <span><b>Enviar invitación de Outlook</b> de cada sesión <small>Un correo por sesión que se agrega a su calendario y se actualiza solo si algo cambia. Si lo desmarcas, la nota lleva un archivo .ics para importarlas.</small></span></label></div>
                    <div class="form-group is-full"><label>Vista previa</label><div class="pp-preview" id="gn-preview"></div></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="gn-btn">Crear sesiones</button>
            </div>
        </form>
    </div></div>
</div>

<div class="modal fade cengi-course-form-modal pp-modal is-wide" id="reprogramar-modal" tabindex="-1" role="dialog" aria-labelledby="reprogramar-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post" id="reprogramar-form" autocomplete="off">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
                <span class="cengi-course-modal-kicker" id="rp-curso">Programa de sesiones</span>
                <h4 class="modal-title" id="reprogramar-title">Reprogramar sesión</h4>
                <p id="rp-actual"></p>
            </div>
            <div class="modal-body">
                <input type="hidden" name="accion" value="reprogramar_sesion">
                <input type="hidden" name="id" id="rp-id">
                <input type="hidden" name="confirmar" id="rp-confirmar" value="">
                <div class="pp-form-msg" id="rp-msg"></div>
                <div class="rp-grid">
                    <div class="form-group"><label for="rp-fecha">Nueva fecha</label><input type="date" class="form-control" id="rp-fecha" name="fecha" required></div>
                    <div class="form-group"><label>Horario</label><div class="rp-horas"><input type="time" class="form-control" id="rp-hi" name="hora_inicio" required aria-label="Hora de inicio"><span>a</span><input type="time" class="form-control" id="rp-hf" name="hora_fin" required aria-label="Hora de fin"></div></div>
                </div>
                <label class="ses-paso">¿Qué sesiones se mueven?</label>
                <div class="ses-modos rp-modos">
                    <label><input type="radio" name="alcance" value="siguientes" checked><span><b>Esta y las siguientes</b> <small>Todas las sesiones programadas posteriores se corren las mismas clases, respetando los días de clase. Si la última pasa de la fecha final, el curso se extiende. Úsalo para feriados o semanas sin clase.</small></span></label>
                    <label><input type="radio" name="alcance" value="solo"><span><b>Solo esta sesión</b> <small>Las demás conservan su fecha. Úsalo para cambiar un día puntual.</small></span></label>
                </div>
                <small class="help-block" id="rp-dias"></small>
                <div class="rp-preview-head"><label class="ses-paso">Vista previa</label><span class="rp-resumen" id="rp-resumen"></span></div>
                <div class="rp-preview" id="rp-preview"></div>
                <div class="form-group rp-motivo"><label for="rp-motivo">Motivo <small>(queda en la bitácora del curso)</small></label>
                    <input type="text" class="form-control" id="rp-motivo" name="motivo" maxlength="300" required placeholder="Ej.: feriado, instructor no disponible, cambio de sede"></div>
                <div class="pp-ics-check"><label><input type="checkbox" name="avisar" value="1" id="rp-avisar" checked>
                    <span><b>Avisar por correo al instructor y al soporte</b> con las nuevas fechas <small>Quien tiene la invitación de Outlook recibe además la actualización y su calendario se ajusta solo.</small></span></label></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" id="rp-btn"><span class="glyphicon glyphicon-share-alt"></span> <span id="rp-btn-texto">Reprogramar</span></button>
            </div>
        </form>
    </div></div>
</div>

<div class="modal fade cengi-course-form-modal pp-modal is-wide" id="programacion-modal" tabindex="-1" role="dialog" aria-labelledby="programacion-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post" id="programacion-form" autocomplete="off">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
                <span class="cengi-course-modal-kicker">Programa de sesiones</span>
                <h4 class="modal-title" id="programacion-title">Enviar programación</h4>
                <p><?php echo $planSel ? cengi_pp_h($planSel['nombre']) : ($instructorFiltroNombre !== '' ? 'Sesiones del año ' . cengi_pp_anio_texto($anioSel) . ' de ' . cengi_pp_h($instructorFiltroNombre) : ''); ?> · solo sesiones programadas a partir de hoy<?php echo $instructorFiltroNombre !== '' ? ' · instructor: ' . cengi_pp_h($instructorFiltroNombre) : ''; ?></p>
            </div>
            <div class="modal-body">
                <input type="hidden" name="accion" value="enviar_programacion">
                <input type="hidden" name="planificacion_id" value="<?php echo (int) ($planSel['id'] ?? 0); ?>">
                <input type="hidden" name="anio" value="<?php echo (int) $anioSel; ?>">
                <input type="hidden" name="instructor" value="<?php echo cengi_pp_h((string) $instructorFiltro); ?>">
                <?php if (!$personasProgramacion): ?>
                    <div class="ses-vacio"><span class="glyphicon glyphicon-calendar"></span><b>No hay sesiones por enviar</b><span>No hay sesiones programadas a partir de hoy con instructor o soporte asignado<?php echo $instructorFiltroNombre !== '' ? ' para este instructor' : ''; ?>.</span></div>
                <?php else: ?>
                    <label class="ses-paso">1. ¿A quién se envía?</label>
                    <div class="ses-destinatarios">
                        <?php foreach ($personasProgramacion as $clave => $p):
                            $primera = $p['sesiones'][0]['fecha'];
                            $ultimaFecha = end($p['sesiones'])['fecha'];
                        ?>
                            <label class="ses-destinatario<?php echo $p['correo'] === '' ? ' is-sin-correo' : ''; ?>">
                                <input type="checkbox" name="destinatarios[]" value="<?php echo cengi_pp_h($clave); ?>" <?php echo $p['correo'] !== '' ? 'checked' : 'disabled'; ?>>
                                <span class="ses-destinatario-info">
                                    <b><?php echo cengi_pp_h($p['nombre']); ?></b> <span class="ses-rol"><?php echo cengi_pp_h($p['rol']); ?></span>
                                    <span class="pp-sub"><?php echo $p['correo'] !== '' ? cengi_pp_h($p['correo']) : 'Sin correo registrado: agrégalo en su ficha para poder enviarle'; ?></span>
                                </span>
                                <span class="ses-destinatario-n"><b><?php echo count($p['sesiones']); ?></b> sesión(es)<span class="pp-sub"><?php echo cengi_pp_fecha_corta($primera) . ($primera !== $ultimaFecha ? ' – ' . cengi_pp_fecha_corta($ultimaFecha) : ''); ?></span></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <label class="ses-paso" for="pr-nota">2. Nota (se agrega automáticamente la tabla con sus sesiones)</label>
                    <textarea class="form-control" id="pr-nota" name="nota" rows="5" maxlength="3000"><?php echo cengi_pp_h(CENGI_ICS_NOTA_PROGRAMACION); ?></textarea>
                    <small class="help-block">Puedes usar {nombre}, {rol} y {curso}; se reemplazan para cada persona.</small>
                    <label class="ses-paso">3. ¿Cómo se agrega a su calendario?</label>
                    <div class="ses-modos">
                        <label><input type="radio" name="modo" value="invitaciones" checked><span><b>Invitación de Outlook por cada sesión</b> <small>Recomendado: se agregan a su calendario y se actualizan solas si cambias fecha, horario o lugar. Llega la nota y un correo por sesión.</small></span></label>
                        <label><input type="radio" name="modo" value="ics"><span><b>Solo la nota con un archivo .ics adjunto</b> <small>Un único correo; la persona abre el archivo para importar todas sus sesiones. Los cambios posteriores no se actualizan solos.</small></span></label>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <?php if ($personasProgramacion): ?><button type="submit" class="btn btn-primary" id="pr-btn"><span class="glyphicon glyphicon-envelope"></span> Enviar programación</button><?php endif; ?>
            </div>
        </form>
    </div></div>
</div>

<?php if ($planSel): ?>
<div class="modal fade cengi-course-form-modal pp-modal" id="eliminar-modal" tabindex="-1" role="dialog" aria-labelledby="eliminar-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <form method="post" id="eliminar-form" autocomplete="off">
            <div class="modal-header ses-peligro">
                <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
                <span class="cengi-course-modal-kicker">Programa de sesiones</span>
                <h4 class="modal-title" id="eliminar-title">Eliminar programación del curso</h4>
                <p><?php echo cengi_pp_h($planSel['nombre']); ?></p>
            </div>
            <div class="modal-body">
                <input type="hidden" name="accion" value="eliminar_programacion">
                <input type="hidden" name="planificacion_id" value="<?php echo (int) $planSel['id']; ?>">
                <p class="ses-peligro-texto">Úsalo si la programación quedó confusa y quieres volver a empezar. Las sesiones incluidas en una liquidación de pago nunca se eliminan.</p>
                <div class="ses-modos">
                    <label><input type="radio" name="alcance" value="pendientes" checked><span><b>Programadas y canceladas</b> (<?php echo (int) $eliminables['pendientes']; ?>) <small>Recomendado: conserva las sesiones ya impartidas, que cuentan para la asistencia y el pago.</small></span></label>
                    <label><input type="radio" name="alcance" value="todas"><span><b>Todas, incluidas las impartidas</b> (<?php echo (int) $eliminables['todas']; ?>) <small>Borra también el registro de las sesiones impartidas que aún no se liquidan.</small></span></label>
                </div>
                <ul class="ses-consecuencias">
                    <li>Quienes tenían la invitación de Outlook reciben la cancelación y la sesión se quita de su calendario.</li>
                    <li>Queda registrado en la bitácora<?php echo cengi_pp_convocado($planSel) ? '; el aviso a los inscritos queda en Comunicaciones → Por avisar' : ''; ?>.</li>
                </ul>
                <label class="ses-paso" for="el-confirmacion">Para confirmar escribe <b>ELIMINAR</b></label>
                <input type="text" class="form-control" id="el-confirmacion" name="confirmacion" autocomplete="off" placeholder="ELIMINAR">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger" id="el-btn" disabled><span class="glyphicon glyphicon-trash"></span> Eliminar sesiones</button>
            </div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<div class="modal fade cengi-course-form-modal pp-modal is-wide" id="soporte-modal" tabindex="-1" role="dialog" aria-labelledby="soporte-title">
    <div class="modal-dialog" role="document"><div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button>
            <span class="cengi-course-modal-kicker">Programa de sesiones</span>
            <h4 class="modal-title" id="soporte-title">Soporte técnico</h4>
            <p>Personal que atiende las sesiones virtuales e híbridas (o el audio en campo). Su pago por sesión impartida se liquida junto con el de los instructores.</p>
        </div>
        <div class="modal-body">
            <div class="pp-table-wrap" style="margin-bottom:16px;">
                <table class="cengi-org-table" style="min-width:640px;">
                    <thead><tr><th>Código</th><th>Nombre</th><th>Tipo</th><th>Contacto</th><th>Tarifa / sesión</th><th>Sesiones <?php echo date('Y'); ?></th><th></th></tr></thead>
                    <tbody>
                        <?php if (!$soportes): ?><tr><td colspan="7" class="pp-empty">Aún no hay personal de soporte técnico.</td></tr><?php endif; ?>
                        <?php foreach ($soportes as $sp): $uso = $soportesUso[$sp['id']] ?? ['impartidas' => 0, 'programadas' => 0]; ?>
                            <tr class="<?php echo (int) $sp['estado'] !== 1 ? 'pp-muted-row' : ''; ?>">
                                <td class="pp-mono"><?php echo cengi_pp_h($sp['codigo']); ?></td>
                                <td><strong><?php echo cengi_pp_h($sp['nombre']); ?></strong><span class="pp-sub"><?php echo cengi_pp_h($sp['notas']); ?></span></td>
                                <td><?php echo cengi_pp_h($sp['tipo']); ?></td>
                                <td><?php echo cengi_pp_h($sp['correo']); ?><span class="pp-sub"><?php echo cengi_pp_h($sp['telefono']); ?></span></td>
                                <td class="pp-num"><?php echo cengi_pp_q($sp['tarifa'], 2); ?></td>
                                <td class="pp-num"><?php echo (int) $uso['impartidas']; ?> impartidas<span class="pp-sub"><?php echo (int) $uso['programadas']; ?> programadas</span></td>
                                <td><button type="button" class="cengi-action-btn is-edit" data-soporte="<?php echo cengi_pp_h(json_encode($sp, JSON_UNESCAPED_UNICODE)); ?>" aria-label="Editar"><span class="glyphicon glyphicon-pencil"></span></button></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <form method="post" id="soporte-form" autocomplete="off">
                <input type="hidden" name="accion" value="guardar_soporte">
                <input type="hidden" name="id" id="sp-id">
                <h5 id="sp-titulo" style="font-weight:700;">Agregar persona de soporte técnico</h5>
                <div class="pp-form-msg" id="sp-msg"></div>
                <div class="cengi-course-modal-grid">
                    <div class="form-group"><label for="sp-nombre">Nombre</label><input type="text" class="form-control" id="sp-nombre" name="nombre" maxlength="255" required></div>
                    <div class="form-group"><label for="sp-tipo">Tipo</label><select class="form-control" id="sp-tipo" name="tipo"><option>Interno</option><option>Externo</option></select></div>
                    <div class="form-group"><label for="sp-correo">Correo</label><input type="email" class="form-control" id="sp-correo" name="correo" maxlength="255"></div>
                    <div class="form-group"><label for="sp-tel">Teléfono</label><input type="text" class="form-control" id="sp-tel" name="telefono" maxlength="50"></div>
                    <div class="form-group"><label for="sp-tarifa">Tarifa por sesión (Q)</label><input type="number" class="form-control" id="sp-tarifa" name="tarifa" min="0" step="0.01" value="250" required></div>
                    <div class="form-group"><label for="sp-estado">Estado</label><select class="form-control" id="sp-estado" name="estado"><option value="1">Activo</option><option value="0">Inactivo</option></select></div>
                    <div class="form-group is-full"><label for="sp-notas">Notas</label><input type="text" class="form-control" id="sp-notas" name="notas" maxlength="500" placeholder="Ej. Zoom, Teams y sonido del auditorio"></div>
                </div>
                <div style="margin-top:12px;text-align:right;">
                    <button type="button" class="btn btn-default" id="sp-nuevo">Nuevo</button>
                    <button type="submit" class="btn btn-primary" id="sp-btn">Agregar</button>
                </div>
            </form>
        </div>
    </div></div>
</div>

<script>
(function () {
    'use strict';
    var PLANES = <?php echo json_encode($planesJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var OCUPADAS = <?php echo json_encode($ocupadas, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var REABRIR = <?php echo json_encode($reabrir, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var HOY = <?php echo json_encode($hoy); ?>;
    var DIAS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
    function $id(id) { return document.getElementById(id); }
    function set(id, v) { $id(id).value = v === null || v === undefined ? '' : String(v); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function addDays(iso, n) { var d = new Date(iso + 'T12:00:00'); d.setDate(d.getDate() + n); return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
    function dow(iso) { return new Date(iso + 'T12:00:00').getDay(); }
    function diffDays(a, b) { return Math.round((new Date(b + 'T12:00:00') - new Date(a + 'T12:00:00')) / 86400000); }
    function corta(iso) { var p = iso.split('-'); return p[2] + '/' + p[1]; }
    function lugarDefecto(m) { return m === 'Virtual' ? 'Enlace virtual — Teams' : m === 'Híbrida' ? 'Auditorio CENGICAÑA / Zoom' : 'Auditorio CENGICAÑA'; }
    function msg(id, tipo, html) { var m = $id(id); m.className = 'pp-form-msg' + (html ? ' is-visible is-' + tipo : ''); m.innerHTML = html || ''; }
    function modulos(selectId, planId, valor) {
        var p = PLANES[planId], s = $id(selectId); s.innerHTML = '';
        ((p && p.modulos) || ['Sesión única']).forEach(function (m) { s.add(new Option(m, m)); });
        if (valor && ![].some.call(s.options, function (o) { return o.value === valor; })) s.add(new Option(valor, valor));
        if (valor) s.value = valor;
    }

    // Cada curso habilita tantas sesiones como indica el plan (el servidor también lo valida).
    function libres(p) { return Math.max(0, p.sesiones - p.activas); }
    function diasPlan(p) { return p.dias && p.dias.length ? p.dias : null; }
    // Próxima fecha en los días del plan, después de la última sesión programada (y desde hoy si el curso ya empezó).
    function siguienteFecha(p) {
        var desde = p.ultima && p.ultima >= p.fi ? addDays(p.ultima, 1) : p.fi, dias = diasPlan(p);
        if (desde < HOY && HOY <= p.ff) desde = HOY;
        for (var d = desde; d <= p.ff; d = addDays(d, 1)) {
            if (!dias || dias.indexOf(dow(d)) >= 0) return d;
        }
        return '';
    }

    // ---------- sesion
    function sesionPlan(keepModulo) {
        var p = PLANES[$id('sf-plan').value]; if (!p) return;
        modulos('sf-modulo', $id('sf-plan').value, keepModulo);
        $id('sf-rango').textContent = 'Rango del curso: ' + corta(p.fi) + ' – ' + corta(p.ff) + ' · ' + p.activas + ' de ' + p.sesiones + ' sesiones programadas · ' + p.modalidad;
        $id('sf-fecha').min = p.fi; $id('sf-fecha').max = p.ff;
        // Módulos automáticos: el módulo sale del número de sesión (el servidor lo asigna al guardar).
        $id('sf-modulo').disabled = !!p.automatico;
        $id('sf-modulo-ayuda').hidden = !p.automatico;
        revisarCorrer();
    }
    // "Correr esta y las siguientes" solo aplica al cambiar la fecha de una sesión ya guardada.
    function revisarCorrer() {
        var original = $id('sf-fecha').getAttribute('data-original') || '', fecha = $id('sf-fecha').value, p = PLANES[$id('sf-plan').value];
        var visible = !!$id('sf-id').value && !!original && !!fecha && fecha !== original && $id('sf-estado').value === 'programada';
        // Al cambiar la fecha de una sesión guardada se sugiere "Reprogramar" (corre las siguientes con vista previa).
        $id('sf-correr-wrap').hidden = !visible;
        $id('sf-fecha').max = p ? p.ff : '';
    }
    // Una sesión nueva solo se puede guardar si el curso tiene espacios por programar.
    function revisarCupoSesion() {
        var p = PLANES[$id('sf-plan').value], lleno = !!p && !$id('sf-id').value && libres(p) === 0;
        $id('sf-btn').disabled = lleno;
        if (lleno) msg('sf-msg', 'error', 'Este curso tiene ' + p.sesiones + ' sesión(es) planificada(s) y ya están todas programadas. Para agregar otra, aumenta el número de sesiones en Planificación de cursos o cancela una sesión.');
        return lleno;
    }
    function abrirSesion(d, avisos, error) {
        d = d || {};
        var nuevo = !d.id, planId = d.planificacion_id || $id('sf-plan').value;
        $id('sesion-form').reset();
        if (planId && ![].some.call($id('sf-plan').options, function (o) { return o.value == planId; }) && PLANES[planId]) $id('sf-plan').add(new Option(PLANES[planId].nombre, planId));
        set('sf-id', d.id); set('sf-plan', planId); set('sf-confirmar', '');
        $id('sf-fecha').setAttribute('data-original', d.fecha_original || (d.id ? d.fecha : '') || '');
        set('sf-fecha-original', $id('sf-fecha').getAttribute('data-original'));
        sesionPlan(d.modulo);
        var p = PLANES[$id('sf-plan').value] || {};
        set('sf-tema', d.tema); set('sf-fecha', d.fecha);
        set('sf-hi', d.hora_inicio || p.hi); set('sf-hf', d.hora_fin || p.hf);
        set('sf-instr', d.instructor_id !== undefined ? d.instructor_id : p.instructor);
        set('sf-soporte', d.soporte_id); set('sf-modalidad', d.modalidad || p.modalidad || 'Presencial');
        set('sf-lugar', d.lugar || lugarDefecto(d.modalidad || p.modalidad)); set('sf-estado', d.estado || 'programada');
        if (d.modulo_sugerido && p.automatico) set('sf-modulo', d.modulo_sugerido);
        // Al reabrir tras un aviso se respeta lo que se había marcado; si no, invitar queda activo.
        $id('sf-invitar').checked = d.accion ? !!d.invitar_calendario : true;
        revisarCorrer();
        $id('sesion-title').textContent = nuevo ? 'Nueva sesión' : 'Editar sesión';
        $id('sf-btn').textContent = 'Guardar sesión';
        msg('sf-msg', '', '');
        revisarCupoSesion();
        if (d.liquidada) msg('sf-msg', 'warn', 'Esta sesión ya está en una liquidación de pago: solo puedes cambiar tema, lugar u horario.');
        if (error) msg('sf-msg', 'error', error);
        if (avisos && avisos.length) {
            msg('sf-msg', 'warn', '<b>Antes de guardar:</b><br>' + avisos.join('<br>') + '<br>Pulsa “Guardar de todos modos” para confirmar, o ajusta los datos.');
            set('sf-confirmar', '1'); $id('sf-btn').textContent = 'Guardar de todos modos';
        }
    }
    $('#sesion-modal').on('show.bs.modal', function (e) {
        if (!e.relatedTarget) return; // abierto por REABRIR
        var d = {}; try { d = JSON.parse(e.relatedTarget.getAttribute('data-sesion') || '{}'); } catch (x) {}
        abrirSesion(d);
        // "Programar" en un espacio "Por programar": sugiere la próxima fecha según los días del plan.
        var p = PLANES[$id('sf-plan').value];
        if (e.relatedTarget.hasAttribute('data-sugerir') && p) set('sf-fecha', siguienteFecha(p));
    });
    $id('sf-plan').addEventListener('change', function () { sesionPlan(); msg('sf-msg', '', ''); revisarCupoSesion(); var p = PLANES[this.value]; if (p) { set('sf-hi', p.hi); set('sf-hf', p.hf); set('sf-modalidad', p.modalidad); set('sf-lugar', lugarDefecto(p.modalidad)); set('sf-instr', p.instructor); } });
    $id('sf-modalidad').addEventListener('change', function () { set('sf-lugar', lugarDefecto(this.value)); });
    ['sf-fecha', 'sf-estado'].forEach(function (id) { $id(id).addEventListener('change', revisarCorrer); });
    // Desde "Editar sesión": pasa a Reprogramar con la fecha y el horario que ya se escribieron.
    $id('sf-ir-reprogramar').addEventListener('click', function () {
        var datos = { id: $id('sf-id').value, fecha: $id('sf-fecha').value, hora_inicio: $id('sf-hi').value, hora_fin: $id('sf-hf').value, alcance: 'siguientes' };
        $('#sesion-modal').one('hidden.bs.modal', function () { abrirReprogramar(datos); }).modal('hide');
    });

    // ---------- reprogramar: misma regla que el servidor (cengi_pp_clases_entre / cengi_pp_mover_clases)
    var SESIONES = <?php echo json_encode($sesionesJs, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var DIAS_NOMBRE = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    var rpSesion = null;
    function sesionPorId(id) { return SESIONES.filter(function (s) { return s.id === +id; })[0] || null; }
    function diasClase(planId) {
        var p = PLANES[planId];
        if (p && p.dias && p.dias.length) return p.dias.slice().sort();
        var vistos = {};
        SESIONES.forEach(function (s) { if (s.plan === +planId && s.estado !== 'cancelada') vistos[dow(s.fecha)] = true; });
        return Object.keys(vistos).map(Number).sort();
    }
    function clasesEntre(desde, hasta, dias) {
        if (!dias.length) return diffDays(desde, hasta);
        var signo = hasta >= desde ? 1 : -1, n = 0;
        for (var d = desde; d !== hasta;) { d = addDays(d, signo); if (dias.indexOf(dow(d)) >= 0) n += signo; }
        return n;
    }
    function moverClases(fecha, k, dias) {
        if (!dias.length) return addDays(fecha, k);
        var signo = k >= 0 ? 1 : -1;
        for (var i = 0; i < Math.abs(k); i++) { do { fecha = addDays(fecha, signo); } while (dias.indexOf(dow(fecha)) < 0); }
        return fecha;
    }
    function fechaLarga(iso) { var p = iso.split('-'); return DIAS[dow(iso)] + ' ' + (+p[2]) + '/' + p[1]; }
    function abrirReprogramar(datos, avisos, error) {
        datos = datos || {};
        var s = sesionPorId(datos.id);
        if (!s) return;
        rpSesion = s;
        var p = PLANES[s.plan] || {};
        $id('reprogramar-form').reset();
        set('rp-id', s.id); set('rp-confirmar', '');
        $id('rp-actual').textContent = (s.num ? 'Sesión ' + s.num + (p.sesiones ? ' de ' + p.sesiones : '') + ' · ' : '') + s.tema + ' · ' + fechaLarga(s.fecha) + ' · ' + s.hi + '–' + s.hf;
        $id('rp-curso').textContent = s.curso;
        set('rp-fecha', datos.fecha || s.fecha); set('rp-hi', datos.hora_inicio || s.hi); set('rp-hf', datos.hora_fin || s.hf);
        $id('rp-fecha').min = p.fi && p.fi > HOY ? p.fi : HOY;
        document.querySelector('#reprogramar-form input[name="alcance"][value="' + (datos.alcance === 'solo' ? 'solo' : 'siguientes') + '"]').checked = true;
        set('rp-motivo', datos.motivo || '');
        $id('rp-avisar').checked = datos.id && datos.accion ? !!datos.avisar : true;
        $id('rp-btn-texto').textContent = 'Reprogramar';
        previewReprogramar();
        if (error) msg('rp-msg', 'error', error);
        if (avisos && avisos.length) {
            msg('rp-msg', 'warn', '<b>Hay choques de horario con la programación resultante:</b><br>' + avisos.join('<br>') + '<br>Ajusta la fecha u horario, o pulsa “Reprogramar de todos modos”.');
            set('rp-confirmar', '1'); $id('rp-btn-texto').textContent = 'Reprogramar de todos modos';
        }
        $('#reprogramar-modal').modal('show');
    }
    function previewReprogramar() {
        var s = rpSesion; if (!s) return;
        var p = PLANES[s.plan] || {}, dias = diasClase(s.plan);
        var fecha = $id('rp-fecha').value, hi = $id('rp-hi').value, hf = $id('rp-hf').value;
        var siguientes = document.querySelector('#reprogramar-form input[name="alcance"]:checked').value === 'siguientes';
        var nombresDias = dias.map(function (d) { return DIAS_NOMBRE[d]; }).join(', ');
        $id('rp-dias').textContent = dias.length ? 'Días de clase del curso: ' + nombresDias + '.' : 'El curso no tiene días fijos: se mueve por días naturales.';
        msg('rp-msg', '', '');
        var errores = [], avisos = [], cambios = [];
        if (!fecha || !hi || !hf) errores.push('Indica la nueva fecha y el horario.');
        else if (hf <= hi) errores.push('La hora de fin debe ser mayor que la de inicio.');
        if (fecha && fecha < HOY) errores.push('La nueva fecha ya pasó.');
        if (fecha && p.fi && fecha < p.fi) errores.push('La nueva fecha es anterior al inicio del curso (' + corta(p.fi) + ').');
        if (fecha && !siguientes && p.ff && fecha > p.ff) errores.push('La fecha pasa del final del curso (' + corta(p.ff) + '). Elige “Esta y las siguientes” para extender el curso.');
        if (fecha && dias.length && dias.indexOf(dow(fecha)) < 0) avisos.push('El ' + DIAS_NOMBRE[dow(fecha)] + ' no es día de clase del curso (' + nombresDias + ').');

        if (fecha && !errores.length) {
            cambios.push({ s: s, nueva: fecha, hi: hi, hf: hf, principal: true });
            var k = fecha !== s.fecha ? clasesEntre(s.fecha, fecha, dias) : 0;
            if (siguientes) {
                SESIONES.filter(function (o) {
                    return o.plan === s.plan && o.id !== s.id && o.estado === 'programada' && !o.liq && (o.fecha > s.fecha || (o.fecha === s.fecha && o.hi > s.hi));
                }).sort(function (a, b) { return (a.fecha + a.hi).localeCompare(b.fecha + b.hi); }).forEach(function (o) {
                    cambios.push({ s: o, nueva: k ? moverClases(o.fecha, k, dias) : o.fecha, hi: o.hi, hf: o.hf });
                });
                if (fecha !== s.fecha && k === 0) avisos.push('No hay días de clase entre ambas fechas: las siguientes conservan su fecha.');
            }
            var ultima = cambios.reduce(function (m, c) { return c.nueva > m ? c.nueva : m; }, '');
            if (siguientes && p.ff && ultima > p.ff) avisos.push('El curso se extenderá: termina el ' + corta(ultima) + ' (hoy termina el ' + corta(p.ff) + ').');
        }
        var movidas = cambios.filter(function (c) { return c.nueva !== c.s.fecha || c.hi !== c.s.hi || c.hf !== c.s.hf; });
        var ids = movidas.map(function (c) { return c.s.id; });
        // Choques con sesiones que no se mueven (mismo instructor o soporte, misma fecha y horario traslapado).
        var choques = 0;
        $id('rp-preview').innerHTML = movidas.length ? '<table class="rp-tabla"><thead><tr><th>N.°</th><th>Tema</th><th>Antes</th><th></th><th>Después</th></tr></thead><tbody>' +
            movidas.slice(0, 40).map(function (c) {
                var choque = OCUPADAS.filter(function (o) {
                    return ids.indexOf(+o.id) < 0 && o.fecha === c.nueva && c.hi < o.hf && o.hi < c.hf && ((c.s.instr && o.instructor_id == c.s.instr) || (c.s.sop && o.soporte_id == c.s.sop));
                })[0];
                if (choque) choques++;
                return '<tr class="' + (c.principal ? 'is-principal' : '') + (choque ? ' is-choque' : '') + '"><td>' + (c.s.num || '—') + '</td><td>' + esc(c.s.tema) + '</td>' +
                    '<td class="rp-antes">' + fechaLarga(c.s.fecha) + ' · ' + c.s.hi + '</td><td class="rp-flecha">→</td>' +
                    '<td class="rp-despues"><b>' + fechaLarga(c.nueva) + ' · ' + c.hi + '</b>' + (choque ? '<span class="pp-chip is-danger">Choca con ' + esc(choque.tema) + '</span>' : '') + '</td></tr>';
            }).join('') + '</tbody></table>' + (movidas.length > 40 ? '<span class="pp-sub">Y ' + (movidas.length - 40) + ' sesión(es) más.</span>' : '')
            : '<span class="pp-sub">Elige la nueva fecha u horario para ver cómo queda la programación.</span>';
        if (choques) avisos.push(choques + ' sesión(es) chocarían con otra del mismo instructor o soporte (marcadas en rojo).');

        if (errores.length) msg('rp-msg', 'error', errores.join('<br>'));
        else if (avisos.length) msg('rp-msg', 'warn', avisos.join('<br>'));
        $id('rp-resumen').textContent = movidas.length ? movidas.length + ' sesión(es) cambian de fecha u horario' : '';
        $id('rp-btn').disabled = !!errores.length || !movidas.length;
        if (!$id('rp-confirmar').value) $id('rp-btn-texto').textContent = movidas.length > 1 ? 'Reprogramar ' + movidas.length + ' sesiones' : 'Reprogramar sesión';
    }
    document.querySelectorAll('[data-reprogramar]').forEach(function (b) { b.addEventListener('click', function () { abrirReprogramar({ id: b.getAttribute('data-reprogramar') }); }); });
    ['rp-fecha', 'rp-hi', 'rp-hf'].forEach(function (id) { ['input', 'change'].forEach(function (ev) { $id(id).addEventListener(ev, function () { set('rp-confirmar', ''); previewReprogramar(); }); }); });
    document.querySelectorAll('#reprogramar-form input[name="alcance"]').forEach(function (r) { r.addEventListener('change', function () { set('rp-confirmar', ''); previewReprogramar(); }); });
    ['sf-tema', 'sf-fecha', 'sf-hi', 'sf-hf', 'sf-instr', 'sf-soporte', 'sf-lugar', 'sf-modalidad', 'sf-estado'].forEach(function (id) {
        $id(id).addEventListener('input', function () { if ($id('sf-confirmar').value) { set('sf-confirmar', ''); $id('sf-btn').textContent = 'Guardar sesión'; msg('sf-msg', '', ''); } });
    });

    // ---------- generador
    function fechasGenerador() {
        var p = PLANES[$id('gn-plan').value], desde = $id('gn-desde').value, cant = +$id('gn-cant').value, frec = +$id('gn-frec').value;
        var dias = [].map.call(document.querySelectorAll('#gn-dias input:checked'), function (c) { return +c.value; });
        var fechas = [];
        if (!p || !desde || !(cant >= 1) || !dias.length) return { fechas: fechas, p: p, cant: cant, dias: dias };
        var lunes = addDays(desde, -((dow(desde) + 6) % 7));
        for (var d = desde < p.fi ? p.fi : desde; d <= p.ff && fechas.length < cant; d = addDays(d, 1)) {
            if (dias.indexOf(dow(d)) >= 0 && Math.floor(diffDays(lunes, d) / 7) % frec === 0) fechas.push(d);
        }
        return { fechas: fechas, p: p, cant: cant, dias: dias };
    }
    function previewGenerador() {
        var r = fechasGenerador(), hi = $id('gn-hi').value, hf = $id('gn-hf').value, instr = $id('gn-instr').value, sop = $id('gn-soporte').value;
        msg('gn-msg', '', '');
        if (!r.dias.length) { $id('gn-preview').innerHTML = '<span class="pp-sub">Elige al menos un día de la semana.</span>'; return; }
        $id('gn-preview').innerHTML = r.fechas.length ? r.fechas.map(function (f, i) {
            var choque = OCUPADAS.filter(function (o) { return o.fecha === f && hi < o.hf && o.hi < hf && ((instr && o.instructor_id == instr) || (sop && o.soporte_id == sop)); })[0];
            return '<div><span><b>' + (i + 1) + '.</b> ' + corta(f) + ' · ' + DIAS[dow(f)] + ' · ' + hi + '–' + hf + '</span>' +
                (choque ? '<span class="pp-chip is-danger">Choca con ' + esc(choque.tema) + '</span>' : '<span class="pp-chip is-ok">Libre</span>') + '</div>';
        }).join('') : '<span class="pp-sub">No hay fechas disponibles con ese patrón dentro del rango del curso.</span>';
        if (r.p && r.fechas.length && r.fechas.length < r.cant) msg('gn-msg', 'warn', 'Solo caben ' + r.fechas.length + ' de ' + r.cant + ' sesiones antes de la fecha final del curso (' + corta(r.p.ff) + '). Cambia la frecuencia, los días o reprograma el curso.');
        var excede = r.p && r.fechas.length > libres(r.p);
        if (r.p && excede) msg('gn-msg', 'error', libres(r.p) ? 'Solo quedan ' + libres(r.p) + ' de ' + r.p.sesiones + ' sesiones por programar en este curso.' : 'Las ' + r.p.sesiones + ' sesiones del curso ya están programadas.');
        $id('gn-btn').textContent = r.fechas.length ? 'Crear ' + r.fechas.length + ' sesión(es)' : 'Crear sesiones';
        $id('gn-btn').disabled = !r.fechas.length || excede;
    }
    function generadorPlan() {
        var p = PLANES[$id('gn-plan').value]; if (!p) return;
        modulos('gn-modulo', $id('gn-plan').value);
        $id('gn-modulo').disabled = !!p.automatico;
        $id('gn-modulo-ayuda').hidden = !p.automatico;
        var desde = p.ultima && p.ultima >= p.fi ? addDays(p.ultima, 1) : p.fi;
        if (desde > p.ff) desde = p.fi;
        set('gn-desde', desde); $id('gn-desde').min = p.fi; $id('gn-desde').max = p.ff;
        set('gn-cant', Math.max(1, libres(p))); $id('gn-cant').max = Math.max(1, libres(p)); set('gn-hi', p.hi); set('gn-hf', p.hf);
        // Con módulos automáticos el instructor queda vacío: cada sesión toma el de su módulo.
        set('gn-instr', p.automatico ? '' : p.instructor); set('gn-modalidad', p.modalidad); set('gn-lugar', lugarDefecto(p.modalidad)); set('gn-tema', '');
        // Días: los del plan ("Miércoles y viernes"); si no los indica, el día de inicio (o lunes a viernes en cursos cortos).
        var dia = dow(p.fi), corto = diffDays(p.fi, p.ff) < 7 && p.sesiones > 1, dias = diasPlan(p);
        document.querySelectorAll('#gn-dias input').forEach(function (c) { c.checked = dias ? dias.indexOf(+c.value) >= 0 : (corto ? (+c.value >= 1 && +c.value <= 5) : +c.value === dia); });
        set('gn-frec', diffDays(p.fi, p.ff) > 120 && p.sesiones < 30 ? '2' : '1');
        $id('gn-rango').textContent = 'Rango: ' + corta(p.fi) + ' – ' + corta(p.ff) + ' · ' + p.activas + ' de ' + p.sesiones + ' sesiones programadas' + (p.ultima ? ' · última: ' + corta(p.ultima) : '');
        previewGenerador();
    }
    $('#generar-modal').on('show.bs.modal', function (e) { if (e.relatedTarget) generadorPlan(); });
    $id('gn-plan').addEventListener('change', generadorPlan);
    $id('gn-modalidad').addEventListener('change', function () { set('gn-lugar', lugarDefecto(this.value)); });
    ['gn-desde', 'gn-cant', 'gn-frec', 'gn-hi', 'gn-hf', 'gn-instr', 'gn-soporte'].forEach(function (id) { $id(id).addEventListener('input', previewGenerador); $id(id).addEventListener('change', previewGenerador); });
    document.querySelectorAll('#gn-dias input').forEach(function (c) { c.addEventListener('change', previewGenerador); });

    // ---------- soporte tecnico
    function editarSoporte(sp) {
        sp = sp || {};
        $id('soporte-form').reset();
        set('sp-id', sp.id); set('sp-nombre', sp.nombre); set('sp-tipo', sp.tipo || 'Interno'); set('sp-correo', sp.correo);
        set('sp-tel', sp.telefono); set('sp-tarifa', sp.tarifa !== undefined ? sp.tarifa : 250); set('sp-estado', sp.estado !== undefined ? +sp.estado : 1); set('sp-notas', sp.notas);
        $id('sp-titulo').textContent = sp.id ? 'Editar ' + sp.nombre : 'Agregar persona de soporte técnico';
        $id('sp-btn').textContent = sp.id ? 'Guardar cambios' : 'Agregar';
        msg('sp-msg', '', '');
    }
    document.querySelectorAll('[data-soporte]').forEach(function (b) { b.addEventListener('click', function () { editarSoporte(JSON.parse(b.getAttribute('data-soporte'))); $id('sp-nombre').focus(); }); });
    $id('sp-nuevo').addEventListener('click', function () { editarSoporte(); });
    $('#soporte-modal').on('show.bs.modal', function (e) { if (e.relatedTarget) editarSoporte(); });

    ['sesion-form', 'generar-form', 'soporte-form', 'programacion-form', 'eliminar-form', 'reprogramar-form'].forEach(function (id) {
        if (!$id(id)) return;
        $id(id).addEventListener('submit', function () { var b = this.querySelector('button[type="submit"]'); if (b) setTimeout(function () { b.disabled = true; }, 0); });
    });

    // ---------- enviar programación: el botón muestra a cuántas personas se envía
    if ($id('pr-btn')) {
        var contarDestinatarios = function () {
            var n = document.querySelectorAll('#programacion-form input[name="destinatarios[]"]:checked').length;
            $id('pr-btn').disabled = n === 0;
            $id('pr-btn').innerHTML = '<span class="glyphicon glyphicon-envelope"></span> ' + (n ? 'Enviar a ' + n + ' persona(s)' : 'Marca al menos una persona');
        };
        document.querySelectorAll('#programacion-form input[name="destinatarios[]"]').forEach(function (c) { c.addEventListener('change', contarDestinatarios); });
        contarDestinatarios();
    }
    // ---------- eliminar programación: solo con la palabra de confirmación
    if ($id('el-confirmacion')) {
        $id('el-confirmacion').addEventListener('input', function () { $id('el-btn').disabled = this.value.trim().toUpperCase() !== 'ELIMINAR'; });
        $('#eliminar-modal').on('show.bs.modal', function () { $id('el-confirmacion').value = ''; $id('el-btn').disabled = true; });
    }

    if (REABRIR) {
        if (REABRIR.modal === 'sesion') { abrirSesion(REABRIR.datos, REABRIR.avisos, REABRIR.error); $('#sesion-modal').modal('show'); }
        if (REABRIR.modal === 'reprogramar') { abrirReprogramar(REABRIR.datos, REABRIR.avisos, REABRIR.error); }
        if (REABRIR.modal === 'generar') {
            if (REABRIR.datos.planificacion_id) set('gn-plan', REABRIR.datos.planificacion_id);
            generadorPlan();
            if (REABRIR.error) msg('gn-msg', 'error', REABRIR.error);
            $('#generar-modal').modal('show');
        }
        if (REABRIR.modal === 'soporte') {
            var d = REABRIR.datos || {};
            editarSoporte(d.nombre !== undefined ? { id: d.id, nombre: d.nombre, tipo: d.tipo, correo: d.correo, telefono: d.telefono, tarifa: d.tarifa, estado: d.estado, notas: d.notas } : null);
            if (REABRIR.error) msg('sp-msg', 'error', REABRIR.error);
            $('#soporte-modal').modal('show');
        }
    }
})();
</script>
<?php if($modoEdicion):?>
<script type="application/json" id="ses-inline-config"><?=json_encode(['csrf'=>$_SESSION['ses_tabla_csrf'],'planes'=>$planesJs],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?></script>
<script src="js/sesiones_edicion_tabla.js?v=<?=filemtime(__DIR__.'/js/sesiones_edicion_tabla.js')?>"></script>
<?php endif;?>
</body>
</html>
