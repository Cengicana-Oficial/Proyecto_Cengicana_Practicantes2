<?php
/**
 * Carga masiva por Excel/CSV para Planificacion y programacion:
 *   tipo=plan    -> planes de un año (planificacion_cursos), quedan "planificados".
 *   tipo=ses     -> programa de sesiones de un curso planificado (curso_sesiones).
 *   tipo=tarifa  -> tarifa por sesion de cada instructor en un curso (tarifas_instructor_plan)
 *                   o su tarifa base (instructores.tarifa_sesion) si no se indica curso.
 *
 * Flujo en dos pasos: "analizar" lee el archivo, valida cada fila y guarda las filas en
 * la sesion PHP; "aplicar" vuelve a validarlas (los datos pudieron cambiar entretanto) e
 * importa solo las validas dentro de una transaccion.
 */
require_once __DIR__ . '/planificacion_helpers.php';
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

cengi_require_admin();

const CENGI_CARGA_MAX_BYTES = 5 * 1024 * 1024;
const CENGI_CARGA_MAX_FILAS = 2000;
const CENGI_CARGA_JORNADAS = ['Matutina', 'Vespertina', 'Fin de semana', 'Todo Completo']; // mismas del formulario de Cursos

/**
 * Columnas por tipo: clave => [encabezado, alias reconocidos, obligatoria, descripcion].
 * Los alias se comparan sin tildes, mayusculas ni signos; primero exactos y luego como prefijo.
 */
const CENGI_CARGA_TIPOS = [
    'plan' => [
        'titulo' => 'Carga masiva — Planificación de cursos',
        'destino' => 'Año destino',
        'volver' => 'planificacion_cursos.php',
        'nota' => 'Cada fila crea un plan en estado “Planificado” (luego se aprueba desde Planificación). Se rechazan filas sin nombre o fechas, con fecha final anterior a la inicial, con modalidad distinta de Presencial/Virtual/Híbrida o que ya existen en el año.',
        'cols' => [
            'curso' => ['Curso/Diplomado', ['curso diplomado', 'curso', 'diplomado', 'nombre'], true, 'Nombre del curso.'],
            'categoria' => ['Categoría', ['categoria'], false, 'Debe existir en Categorías. Es requerida para aprobar el plan.'],
            'area' => ['Área técnica', ['area tecnica', 'area'], false, 'Debe existir en Áreas técnicas.'],
            'modalidad' => ['Modalidad', ['modalidad'], true, 'Presencial, Virtual o Híbrida.'],
            'horario' => ['Horario', ['horario'], false, 'Ej. 8:00–12:00 (o usa Hora inicio / Hora fin). Si falta: 8:00–12:00.'],
            'hora_inicio' => ['Hora inicio', ['hora inicio', 'hora de inicio'], false, 'Alternativa a Horario.'],
            'hora_fin' => ['Hora fin', ['hora fin', 'hora final', 'hora de fin'], false, 'Alternativa a Horario.'],
            'fecha_inicio' => ['Fecha inicial', ['fecha inicial', 'fecha de inicio', 'fecha inicio', 'inicio'], true, 'AAAA-MM-DD o DD/MM/AAAA.'],
            'fecha_fin' => ['Fecha final', ['fecha final', 'fecha de fin', 'fecha fin', 'fin'], true, 'AAAA-MM-DD o DD/MM/AAAA.'],
            'sesiones' => ['N.° sesiones', ['n sesiones', 'no sesiones', 'numero de sesiones', 'sesiones'], true, 'Número entero, 1 o más.'],
            'dias' => ['Días de desarrollo', ['dias de desarrollo', 'dias'], false, 'Texto libre. Ej. Lunes a viernes.'],
            'cupo_minimo' => ['Cupo mínimo', ['cupo minimo', 'cupo'], false, 'Si falta: 1.'],
            'cupo_maximo' => ['Cupo máximo', ['cupo maximo'], false, 'Opcional.'],
            'costo_asociados' => ['Costo asociados', ['costo asociados', 'asociados'], false, 'Quetzales. Vacío = 0.'],
            'costo_externos' => ['Costo externos', ['costo externos', 'externos'], false, 'Quetzales. Vacío = 0.'],
            'costo_extranjero' => ['Costo extranjero (US$)', ['costo extranjero', 'extranjero'], false, 'Dólares. Vacío = 0.'],
            'instructor' => ['Instructor responsable', ['instructor responsable', 'instructor'], false, 'Nombre o código de catedrático de un instructor registrado.'],
            'modulos' => ['Módulos', ['modulos'], false, 'Separados por punto y coma (;).'],
            'dirigido_a' => ['Dirigido a', ['dirigido a', 'dirigido'], false, 'Si falta: Todos los ingenios.'],
            'observaciones' => ['Observaciones', ['observaciones', 'obs'], false, 'Texto libre.'],
        ],
    ],
    'ses' => [
        'titulo' => 'Carga masiva — Programa de sesiones',
        'destino' => 'Cursos destino',
        'volver' => 'programa_sesiones.php',
        'nota' => 'Sube el cronograma de un curso o de todos los cursos del año (en ese caso cada fila indica su curso con “ID curso” o “Curso/Diplomado”). El módulo debe coincidir con los del curso, el instructor debe existir y la fecha debe estar dentro del rango del curso. Los choques de horario se aceptan con aviso. Las sesiones con fecha pasada se importan como impartidas.',
        'cols' => [
            'plan_id' => ['ID curso', ['id curso', 'id plan', 'id'], false, 'Solo al cargar todos los cursos: el ID de la hoja “Cursos”. Tiene prioridad sobre el nombre.'],
            'curso' => ['Curso/Diplomado', ['curso diplomado', 'curso', 'diplomado'], false, 'Solo al cargar todos los cursos: nombre del curso planificado (si no se indica el ID).'],
            'modulo' => ['Módulo', ['modulo'], false, 'Uno de los módulos del curso. Si falta: el primero.'],
            'tema' => ['Tema', ['tema'], true, 'Tema de la sesión.'],
            'fecha' => ['Fecha', ['fecha'], true, 'AAAA-MM-DD o DD/MM/AAAA, dentro del rango del curso.'],
            'horario' => ['Horario', ['horario'], false, 'Ej. 15:00–17:00 (o usa Hora inicio / Hora fin).'],
            'hora_inicio' => ['Hora inicio', ['hora inicio', 'hora de inicio'], false, 'Alternativa a Horario.'],
            'hora_fin' => ['Hora fin', ['hora fin', 'hora final', 'hora de fin'], false, 'Alternativa a Horario.'],
            'instructor' => ['Instructor', ['instructor'], true, 'Nombre de un instructor registrado.'],
            'codigo' => ['Código catedrático', ['codigo catedratico', 'codigo', 'catedratico'], false, 'Se usa para identificar al instructor si el nombre no coincide.'],
            'modalidad' => ['Modalidad', ['modalidad'], false, 'Presencial, Virtual o Híbrida. Si falta: la del curso.'],
            'lugar' => ['Lugar de desarrollo', ['lugar de desarrollo', 'lugar', 'enlace'], false, 'Sede (presencial) o enlace de Teams (virtual). Si falta: el lugar por defecto de la modalidad.'],
            'soporte' => ['Soporte técnico', ['soporte tecnico', 'soporte'], false, 'Nombre o código (SOP-001) del soporte registrado.'],
        ],
    ],
    'curso' => [
        'titulo' => 'Carga masiva — Cursos en ejecución (sin planificación)',
        'destino' => null,
        'volver' => 'ver_cursos.php',
        'nota' => 'Cada fila crea directamente un curso en Cursos, sin pasar por la planificación (cursos ya en ejecución o históricos). Se rechazan filas sin nombre, categoría, modalidad, días, horario o fechas, con fecha final anterior a la inicial, o que ya existen (mismo nombre y fecha inicial).',
        'cols' => [
            'codigo' => ['Código', ['codigo del curso', 'codigo'], false, 'Ej. CEN-T-045 (3 a 30 letras, números, punto o guion). Vacío = se genera automáticamente.'],
            'curso' => ['Curso/Diplomado', ['curso diplomado', 'curso', 'diplomado', 'nombre'], true, 'Nombre del curso.'],
            'categoria' => ['Categoría', ['categoria'], true, 'Debe existir en Categorías.'],
            'actividad' => ['Tipo de actividad', ['tipo de actividad', 'actividad'], false, 'Formación académica o Evento técnico. Vacío = Formación académica.'],
            'area' => ['Área técnica', ['area tecnica', 'area'], false, 'Debe existir en Áreas técnicas.'],
            'modalidad' => ['Modalidad', ['modalidad'], true, 'Presencial, Virtual o Híbrida.'],
            'jornada' => ['Jornada', ['jornada'], false, 'Matutina, Vespertina, Fin de semana o Todo completo. Vacío = según el horario.'],
            'dias' => ['Días', ['dias de desarrollo', 'dias'], true, 'Texto libre. Ej. Lunes y miércoles.'],
            'horario' => ['Horario', ['horario'], false, 'Ej. 8:00–12:00 (o usa Hora inicio / Hora fin).'],
            'hora_inicio' => ['Hora inicio', ['hora inicio', 'hora de inicio'], false, 'Alternativa a Horario.'],
            'hora_fin' => ['Hora fin', ['hora fin', 'hora final', 'hora de fin'], false, 'Alternativa a Horario.'],
            'fecha_inicio' => ['Fecha inicial', ['fecha inicial', 'fecha de inicio', 'fecha inicio', 'inicio'], true, 'AAAA-MM-DD o DD/MM/AAAA.'],
            'fecha_fin' => ['Fecha final', ['fecha final', 'fecha de fin', 'fecha fin', 'fin'], true, 'AAAA-MM-DD o DD/MM/AAAA.'],
            'cupo' => ['Cupo', ['cupo maximo', 'cupo'], false, 'Número entero mayor que 0. Vacío = sin límite.'],
            'instructor' => ['Instructor responsable', ['instructor responsable', 'instructor'], false, 'Nombre o código de catedrático de un instructor registrado.'],
            'modulos' => ['Módulos (horas)', ['modulos horas', 'modulos'], false, 'Separados por punto y coma, con sus horas entre paréntesis. Ej. Suelos (10); Riego y drenaje (30). Llevan pre y pos-evaluación.'],
        ],
    ],
    'tarifa' => [
        'titulo' => 'Carga masiva — Tarifas de instructores',
        'destino' => 'Año de los cursos',
        'volver' => 'pago_instructores.php',
        'nota' => 'Indica el costo neto por sesión de cada instructor en cada curso: el número de sesiones se cuenta solo al liquidar. Si dejas vacío el curso, se actualiza la tarifa base del instructor. Las liquidaciones ya generadas conservan su tarifa.',
        'cols' => [
            'instructor' => ['Instructor', ['instructor'], true, 'Nombre o código de catedrático de un instructor registrado.'],
            'curso' => ['Curso/Diplomado', ['curso diplomado', 'curso', 'diplomado'], false, 'Curso planificado en el año elegido. Vacío = tarifa base.'],
            'costo' => ['Costo neto por sesión (Q)', ['costo neto por sesion', 'costo', 'tarifa'], true, 'Quetzales, 0 o más. Ej. 1150 o 1,150.00.'],
            'observaciones' => ['Observaciones', ['observaciones', 'obs'], false, 'Se precarga en la liquidación.'],
        ],
    ],
];

// ---------------------------------------------------------------- lectura y conversion de valores

function cengi_carga_norm($valor)
{
    return preg_replace('/[^a-z0-9]/', '', cengi_texto_normalizado(str_replace(['ü', 'Ü'], 'u', (string) $valor)));
}

/** Lee la primera hoja de un Excel/ODS o un CSV (detecta separador y codificacion). */
function cengi_carga_leer_archivo($ruta, $nombre)
{
    $extension = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    if (in_array($extension, ['xlsx', 'xls', 'ods'], true)) {
        $lector = IOFactory::createReaderForFile($ruta);
        $lector->setReadDataOnly(true);
        $filas = $lector->load($ruta)->getSheet(0)->toArray(null, true, false, false);
    } elseif (in_array($extension, ['csv', 'txt'], true)) {
        $contenido = (string) file_get_contents($ruta);
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
        if (!mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }
        $primera = strtok($contenido, "\n");
        $conteos = [',' => substr_count($primera, ','), ';' => substr_count($primera, ';'), "\t" => substr_count($primera, "\t")];
        arsort($conteos);
        $separador = key($conteos);
        $flujo = fopen('php://temp', 'r+');
        fwrite($flujo, $contenido);
        rewind($flujo);
        $filas = [];
        while (($fila = fgetcsv($flujo, 0, $separador, '"', '')) !== false) {
            $filas[] = $fila;
        }
        fclose($flujo);
    } else {
        throw new InvalidArgumentException('Formato no admitido. Sube un archivo .xlsx, .xls, .ods o .csv.');
    }
    return array_values(array_filter($filas, static function ($fila) {
        return is_array($fila) && trim(implode('', array_map('strval', $fila))) !== '';
    }));
}

/** Asigna cada columna esperada a un indice del encabezado (exacto primero, luego prefijo). */
function cengi_carga_mapear(array $encabezado, array $columnas)
{
    $normalizados = array_map('cengi_carga_norm', $encabezado);
    $mapa = [];
    $usados = [];
    foreach ([true, false] as $exacto) {
        foreach ($columnas as $clave => [, $alias]) {
            if (isset($mapa[$clave])) {
                continue;
            }
            foreach ($alias as $a) {
                $a = cengi_carga_norm($a);
                foreach ($normalizados as $i => $h) {
                    if (!isset($usados[$i]) && $h !== '' && ($exacto ? $h === $a : strpos($h, $a) === 0)) {
                        $mapa[$clave] = $i;
                        $usados[$i] = true;
                        break 2;
                    }
                }
            }
        }
    }
    return $mapa;
}

function cengi_carga_texto($valor)
{
    if (is_float($valor) && floor($valor) === $valor) {
        $valor = (int) $valor;
    }
    return trim(preg_replace('/\s+/u', ' ', (string) ($valor ?? '')));
}

/** Fecha en AAAA-MM-DD; null si esta vacia, false si no es valida. Acepta numero de serie de Excel. */
function cengi_carga_fecha($valor)
{
    if ($valor === null || (is_string($valor) && trim($valor) === '')) {
        return null;
    }
    if (is_numeric($valor) && (float) $valor > 1000) {
        return ExcelDate::excelToDateTimeObject((float) $valor)->format('Y-m-d');
    }
    $texto = trim((string) $valor);
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $texto, $m)) {
        [$a, $mes, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
    } elseif (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2}|\d{4})$/', $texto, $m)) {
        [$d, $mes, $a] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        $a += $a < 100 ? 2000 : 0;
    } else {
        return false;
    }
    return checkdate($mes, $d, $a) ? sprintf('%04d-%02d-%02d', $a, $mes, $d) : false;
}

/** Hora HH:MM; null si esta vacia, false si no es valida. Acepta fraccion de dia de Excel y am/pm. */
function cengi_carga_hora($valor)
{
    if ($valor === null || (is_string($valor) && trim($valor) === '')) {
        return null;
    }
    if (is_numeric($valor) && (float) $valor >= 0 && (float) $valor < 1) {
        $minutos = (int) round((float) $valor * 1440);
        return sprintf('%02d:%02d', intdiv($minutos, 60) % 24, $minutos % 60);
    }
    if (!preg_match('/^(\d{1,2})(?:[:.h](\d{2}))?\s*(a\.?\s*m\.?|p\.?\s*m\.?)?$/i', trim((string) $valor), $m)) {
        return false;
    }
    $h = (int) $m[1];
    $min = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
    $sufijo = strtolower(preg_replace('/[^ap]/i', '', $m[3] ?? ''));
    if ($sufijo === 'p' && $h < 12) {
        $h += 12;
    } elseif ($sufijo === 'a' && $h === 12) {
        $h = 0;
    }
    return $h < 24 && $min < 60 ? sprintf('%02d:%02d', $h, $min) : false;
}

/** [inicio, fin] desde "8:00–12:00" o columnas separadas; null si falta, false si no es valido. */
function cengi_carga_horario(array $fila)
{
    $horario = cengi_carga_texto($fila['horario'] ?? '');
    if ($horario !== '') {
        $partes = preg_split('/\s*(?:–|—|-|\ba\b|\bal\b|\bhasta\b)\s*/iu', $horario);
        if (count($partes) !== 2) {
            return false;
        }
        [$inicio, $fin] = [cengi_carga_hora($partes[0]), cengi_carga_hora($partes[1])];
    } else {
        [$inicio, $fin] = [cengi_carga_hora($fila['hora_inicio'] ?? null), cengi_carga_hora($fila['hora_fin'] ?? null)];
        if ($inicio === null && $fin === null) {
            return null;
        }
    }
    return $inicio && $fin && $fin > $inicio ? [$inicio, $fin] : false;
}

/** Numero desde "Q1,150.50", "1.150,5" o "1150"; null si vacio, false si no es numero. */
function cengi_carga_numero($valor)
{
    if ($valor === null || (is_string($valor) && trim($valor) === '')) {
        return null;
    }
    if (is_int($valor) || is_float($valor)) {
        return (float) $valor;
    }
    $texto = preg_replace('/[Q$\s]|US/i', '', (string) $valor);
    if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $texto)) {
        $texto = str_replace(',', '', $texto);
    } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $texto)) {
        $texto = str_replace(['.', ','], ['', '.'], $texto);
    } else {
        $texto = str_replace(',', '.', $texto);
    }
    return is_numeric($texto) ? (float) $texto : false;
}

/** Busca por nombre normalizado o por codigo. */
function cengi_carga_buscar(array $lista, $texto, $campoNombre, $campoCodigo = null)
{
    $n = cengi_carga_norm($texto);
    if ($n === '') {
        return null;
    }
    foreach ($lista as $item) {
        if (cengi_carga_norm($item[$campoNombre]) === $n || ($campoCodigo && $item[$campoCodigo] !== null && cengi_carga_norm($item[$campoCodigo]) === $n)) {
            return $item;
        }
    }
    return null;
}

/**
 * Destino de la carga de sesiones: un curso planificado (id) o todos los cursos de un año ("z:2026").
 * Devuelve ['todos' => bool, 'anio' => int, 'planes' => [id => plan]] sin los cancelados.
 */
function cengi_carga_ses_destino(PDO $db, $destino)
{
    if (preg_match('/^z:(\d{4})$/', (string) $destino, $m)) {
        $stmt = $db->prepare("
            SELECT p.*, cc.descripcion_categorias_cursos AS categoria_nombre, i.nombre AS instructor_nombre, c.codigo_curso
            FROM planificacion_cursos p
            LEFT JOIN categorias_cursos cc ON cc.id = p.categoria_curso_id
            LEFT JOIN instructores i ON i.id = p.instructor_id
            LEFT JOIN cursos c ON c.id = p.curso_id
            WHERE p.anio = ? AND p.estado <> 'cancelado'
            ORDER BY p.fecha_inicio, p.nombre
        ");
        $stmt->execute([(int) $m[1]]);
        $planes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $planes[(int) $p['id']] = $p;
        }
        if (!$planes) {
            throw new InvalidArgumentException('No hay cursos planificados en el año ' . (int) $m[1] . '.');
        }
        return ['todos' => true, 'anio' => (int) $m[1], 'planes' => $planes];
    }
    $plan = ctype_digit((string) $destino) ? cengi_pp_plan($db, (int) $destino) : null;
    if (!$plan || $plan['estado'] === 'cancelado') {
        throw new InvalidArgumentException('Elige un curso planificado vigente o “Todos los cursos” como destino.');
    }
    return ['todos' => false, 'anio' => (int) $plan['anio'], 'planes' => [(int) $plan['id'] => $plan]];
}

/** Plan de una fila al cargar todos los cursos: por ID o por nombre. [plan|null, error|null]. */
function cengi_carga_ses_plan_fila(array $planes, $anio, array $f)
{
    $id = cengi_carga_texto($f['plan_id'] ?? '');
    $nombre = cengi_carga_texto($f['curso'] ?? '');
    if ($id !== '') {
        return ctype_digit($id) && isset($planes[(int) $id])
            ? [$planes[(int) $id], null]
            : [null, 'ID curso ' . $id . ' no es un curso planificado del año ' . $anio];
    }
    if ($nombre === '') {
        return [null, 'Falta el curso (ID curso o Curso/Diplomado)'];
    }
    $coinciden = array_values(array_filter($planes, static function ($p) use ($nombre) {
        return cengi_carga_norm($p['nombre']) === cengi_carga_norm($nombre);
    }));
    if (count($coinciden) > 1) {
        return [null, 'Hay ' . count($coinciden) . ' cursos con ese nombre: indica el ID curso'];
    }
    return $coinciden ? [$coinciden[0], null] : [null, 'Curso no planificado en el año ' . $anio];
}

// ---------------------------------------------------------------- validacion por tipo

/**
 * Valida las filas (ya mapeadas a claves) y devuelve
 * [['n' => fila del archivo, 'error' => ?, 'avisos' => [], 'celdas' => [], 'obj' => datos a insertar], ...].
 */
function cengi_carga_validar(PDO $db, $tipo, $destino, array $filas)
{
    $resultado = [];
    $hoy = cengi_pp_hoy();
    $instructores = cengi_pp_instructores($db, false);

    if ($tipo === 'plan') {
        $anio = (int) $destino;
        $categorias = $db->query('SELECT id, descripcion_categorias_cursos AS nombre FROM categorias_cursos WHERE estado_categorias_cursos = 1')->fetchAll(PDO::FETCH_ASSOC);
        $areas = $db->query('SELECT nombre FROM areas_tecnicas WHERE estado = 1')->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $db->prepare("SELECT nombre, fecha_inicio FROM planificacion_cursos WHERE anio = ? AND estado <> 'cancelado'");
        $stmt->execute([$anio]);
        $existentes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $existentes[cengi_carga_norm($p['nombre']) . '|' . $p['fecha_inicio']] = true;
        }
        $vistos = [];
        foreach ($filas as $n => $f) {
            $error = null;
            $avisos = [];
            $curso = mb_substr(cengi_carga_texto($f['curso'] ?? ''), 0, 255, 'UTF-8');
            $modalidad = cengi_carga_buscar(array_map(static function ($m) {
                return ['nombre' => $m];
            }, CENGI_PP_MODALIDADES), $f['modalidad'] ?? '', 'nombre');
            $fi = cengi_carga_fecha($f['fecha_inicio'] ?? null);
            $ff = cengi_carga_fecha($f['fecha_fin'] ?? null);
            $sesiones = cengi_carga_numero($f['sesiones'] ?? null);
            $horario = cengi_carga_horario($f);
            $costos = [];
            foreach (['costo_asociados', 'costo_externos', 'costo_extranjero'] as $c) {
                $costos[$c] = cengi_carga_numero($f[$c] ?? null);
            }
            $cupoMin = cengi_carga_numero($f['cupo_minimo'] ?? null);
            $cupoMax = cengi_carga_numero($f['cupo_maximo'] ?? null);
            $clave = cengi_carga_norm($curso) . '|' . $fi;

            if (mb_strlen($curso, 'UTF-8') < 3) {
                $error = 'Falta el nombre del curso';
            } elseif ($fi === null) {
                $error = 'Falta la fecha inicial';
            } elseif ($fi === false) {
                $error = 'Fecha inicial no válida';
            } elseif ($ff === null) {
                $error = 'Falta la fecha final';
            } elseif ($ff === false) {
                $error = 'Fecha final no válida';
            } elseif ($ff < $fi) {
                $error = 'Fecha final anterior a la inicial';
            } elseif (!$modalidad) {
                $error = 'Modalidad “' . cengi_carga_texto($f['modalidad'] ?? '') . '” no válida';
            } elseif ($sesiones === null || $sesiones === false || $sesiones < 1 || $sesiones > 999 || floor($sesiones) != $sesiones) {
                $error = 'N.° de sesiones no válido';
            } elseif ($horario === false) {
                $error = 'Horario no válido (usa 8:00–12:00)';
            } elseif (in_array(false, $costos, true) || array_filter($costos, static function ($c) {
                return $c !== null && $c < 0;
            })) {
                $error = 'Costo no válido';
            } elseif ($cupoMin === false || ($cupoMin !== null && $cupoMin < 1) || $cupoMax === false || ($cupoMax !== null && $cupoMax < max(1, (int) $cupoMin))) {
                $error = 'Cupo no válido';
            } elseif (isset($existentes[$clave])) {
                $error = 'Ya existe en la planificación del año';
            } elseif (isset($vistos[$clave])) {
                $error = 'Fila duplicada en el archivo';
            }
            $vistos[$clave] = true;

            $obj = null;
            if (!$error) {
                $categoria = cengi_carga_buscar($categorias, $f['categoria'] ?? '', 'nombre');
                if (!$categoria) {
                    $avisos[] = cengi_carga_texto($f['categoria'] ?? '') !== '' ? 'categoría no existe: queda sin categoría' : 'sin categoría (requerida para aprobar)';
                }
                $area = cengi_carga_buscar($areas, $f['area'] ?? '', 'nombre');
                if (!$area && cengi_carga_texto($f['area'] ?? '') !== '') {
                    $avisos[] = 'área técnica no existe';
                }
                $instructor = cengi_carga_buscar($instructores, $f['instructor'] ?? '', 'nombre', 'codigo_catedratico');
                if (!$instructor && cengi_carga_texto($f['instructor'] ?? '') !== '') {
                    $avisos[] = 'instructor no registrado';
                }
                if ($horario === null) {
                    $horario = ['08:00', '12:00'];
                    $avisos[] = 'horario 8:00–12:00 por defecto';
                }
                if ($cupoMin === null) {
                    $avisos[] = 'cupo mínimo 1 por defecto';
                }
                if ((int) substr($fi, 0, 4) !== $anio) {
                    $avisos[] = 'fecha fuera del año ' . cengi_pp_anio_texto($anio);
                }
                if ($sesiones > cengi_pp_dias_entre($fi, $ff) + 1) {
                    $avisos[] = 'más sesiones que días';
                }
                $obj = [
                    'anio' => $anio, 'nombre' => $curso, 'categoria_curso_id' => $categoria['id'] ?? null, 'area_tecnica' => $area['nombre'] ?? '',
                    'actividad_tipo' => 'formacion_academica', 'modalidad' => $modalidad['nombre'],
                    'dirigido_a' => mb_substr(cengi_carga_texto($f['dirigido_a'] ?? ''), 0, 255, 'UTF-8') ?: 'Todos los ingenios',
                    'fecha_inicio' => $fi, 'fecha_fin' => $ff, 'hora_inicio' => $horario[0] . ':00', 'hora_fin' => $horario[1] . ':00',
                    'dias' => mb_substr(cengi_carga_texto($f['dias'] ?? ''), 0, 255, 'UTF-8'), 'sesiones_planificadas' => (int) $sesiones,
                    'cupo_minimo' => (int) ($cupoMin ?? 1), 'cupo_maximo' => $cupoMax !== null ? (int) $cupoMax : null,
                    'costo_asociados' => round((float) $costos['costo_asociados'], 2), 'costo_externos' => round((float) $costos['costo_externos'], 2),
                    'costo_extranjero' => round((float) $costos['costo_extranjero'], 2), 'instructor_id' => $instructor['id'] ?? null,
                    'modulos' => implode("\n", array_filter(array_map('trim', explode(';', cengi_carga_texto($f['modulos'] ?? ''))))),
                    'observaciones' => cengi_carga_texto($f['observaciones'] ?? ''),
                ];
            }
            $resultado[] = ['n' => $n, 'error' => $error, 'avisos' => $avisos, 'obj' => $obj, 'celdas' => [
                $curso ?: '—', cengi_carga_texto($f['modalidad'] ?? '') ?: '—',
                $fi ? cengi_pp_fecha($fi) : (cengi_carga_texto($f['fecha_inicio'] ?? '') ?: '—'),
                $ff ? cengi_pp_fecha($ff) : (cengi_carga_texto($f['fecha_fin'] ?? '') ?: '—'),
                cengi_carga_texto($f['sesiones'] ?? '') ?: '—',
            ]];
        }
        return $resultado;
    }

    if ($tipo === 'ses') {
        $info = cengi_carga_ses_destino($db, $destino);
        $soportes = cengi_pp_soportes($db, false);
        $stmt = $db->query("SELECT id, fecha, TIME_FORMAT(hora_inicio, '%H:%i') AS hi, TIME_FORMAT(hora_fin, '%H:%i') AS hf, instructor_id, tema
            FROM curso_sesiones WHERE estado <> 'cancelada'");
        $ocupadas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $activas = $db->prepare("SELECT COUNT(*) FROM curso_sesiones WHERE planificacion_id = ? AND estado <> 'cancelada'");
        $modulosPorPlan = [];
        $totalPorPlan = [];
        foreach ($filas as $n => $f) {
            $error = null;
            $avisos = [];
            if ($info['todos']) {
                [$plan, $error] = cengi_carga_ses_plan_fila($info['planes'], $info['anio'], $f);
            } else {
                $plan = reset($info['planes']);
            }
            $modulos = ['Sesión única'];
            if ($plan) {
                $pid = (int) $plan['id'];
                if (!isset($modulosPorPlan[$pid])) {
                    $modulosPorPlan[$pid] = cengi_pp_modulos_plan($db, $plan);
                    $activas->execute([$pid]);
                    $totalPorPlan[$pid] = (int) $activas->fetchColumn();
                }
                $modulos = $modulosPorPlan[$pid];
            }
            $tema = mb_substr(cengi_carga_texto($f['tema'] ?? ''), 0, 255, 'UTF-8');
            $textoModulo = cengi_carga_texto($f['modulo'] ?? '');
            $modulo = $textoModulo === '' ? $modulos[0] : (cengi_carga_buscar(array_map(static function ($m) {
                return ['nombre' => $m];
            }, $modulos), $textoModulo, 'nombre')['nombre'] ?? null);
            $fecha = cengi_carga_fecha($f['fecha'] ?? null);
            $horario = cengi_carga_horario($f);
            $instructor = cengi_carga_buscar($instructores, $f['instructor'] ?? '', 'nombre', 'codigo_catedratico')
                ?: cengi_carga_buscar($instructores, $f['codigo'] ?? '', 'codigo_catedratico');
            $textoSoporte = cengi_carga_texto($f['soporte'] ?? '');
            $soporte = $textoSoporte !== '' ? cengi_carga_buscar($soportes, $textoSoporte, 'nombre', 'codigo') : null;
            $textoModalidad = cengi_carga_texto($f['modalidad'] ?? '');
            $modalidad = $textoModalidad === '' ? ($plan['modalidad'] ?? 'Presencial') : (cengi_carga_buscar(array_map(static function ($m) {
                return ['nombre' => $m];
            }, CENGI_PP_MODALIDADES), $textoModalidad, 'nombre')['nombre'] ?? null);

            if ($error) {
                // Curso no identificado (solo al cargar todos los cursos).
            } elseif (mb_strlen($tema, 'UTF-8') < 3) {
                $error = 'Falta el tema';
            } elseif (!$modulo) {
                $error = 'Módulo no configurado en el curso (' . implode(', ', $modulos) . ')';
            } elseif (!$instructor) {
                $error = 'Instructor no registrado';
            } elseif (!$fecha) {
                $error = 'Fecha no válida';
            } elseif ($fecha < $plan['fecha_inicio'] || $fecha > $plan['fecha_fin']) {
                $error = 'Fuera del rango del curso (' . cengi_pp_fecha_corta($plan['fecha_inicio']) . ' – ' . cengi_pp_fecha_corta($plan['fecha_fin']) . ')';
            } elseif (!$horario) {
                $error = 'Horario no válido (usa 15:00–17:00)';
            } elseif (!$modalidad) {
                $error = 'Modalidad no válida';
            } elseif ($textoSoporte !== '' && !$soporte) {
                $error = 'Soporte técnico no registrado';
            }

            $obj = null;
            if (!$error) {
                foreach ($ocupadas as $o) {
                    if ((int) $o['instructor_id'] === (int) $instructor['id'] && $o['fecha'] === $fecha && $o['hi'] < $horario[1] && $horario[0] < $o['hf']) {
                        $avisos[] = 'choca con “' . $o['tema'] . '”';
                        break;
                    }
                }
                $codigo = cengi_carga_texto($f['codigo'] ?? '');
                if ($codigo !== '' && $instructor['codigo_catedratico'] && cengi_carga_norm($codigo) !== cengi_carga_norm($instructor['codigo_catedratico'])) {
                    $avisos[] = 'código registrado: ' . $instructor['codigo_catedratico'];
                }
                if ((int) $instructor['estado'] !== 1) {
                    $avisos[] = 'instructor inactivo';
                }
                if (!$soporte && $modalidad !== 'Presencial') {
                    $avisos[] = 'sin soporte técnico';
                }
                if (++$totalPorPlan[(int) $plan['id']] > $plan['sesiones_planificadas']) {
                    $avisos[] = 'supera las ' . $plan['sesiones_planificadas'] . ' sesiones del plan';
                }
                $obj = [
                    'planificacion_id' => (int) $plan['id'], 'modulo' => $modulo, 'tema' => $tema, 'fecha' => $fecha,
                    'hora_inicio' => $horario[0] . ':00', 'hora_fin' => $horario[1] . ':00', 'instructor_id' => (int) $instructor['id'],
                    'soporte_id' => $soporte ? (int) $soporte['id'] : null, 'modalidad' => $modalidad,
                    'lugar' => mb_substr(cengi_carga_texto($f['lugar'] ?? ''), 0, 255, 'UTF-8') ?: cengi_pp_lugar_por_defecto($modalidad),
                    'estado' => $fecha < $hoy ? 'impartida' : 'programada',
                ];
                // Las filas siguientes del archivo tambien chocan con esta.
                $ocupadas[] = ['fecha' => $fecha, 'hi' => $horario[0], 'hf' => $horario[1], 'instructor_id' => $instructor['id'], 'tema' => $tema];
            }
            $celdas = [
                $textoModulo ?: ($plan ? ($modulo ?? '—') : '—'), $tema ?: '—', $fecha ? cengi_pp_fecha($fecha) : (cengi_carga_texto($f['fecha'] ?? '') ?: '—'),
                $horario ? cengi_pp_horario($horario[0], $horario[1]) : (cengi_carga_texto($f['horario'] ?? '') ?: '—'),
                cengi_carga_texto($f['instructor'] ?? '') ?: '—',
            ];
            if ($info['todos']) {
                array_unshift($celdas, $plan ? $plan['nombre'] : (cengi_carga_texto($f['curso'] ?? '') ?: cengi_carga_texto($f['plan_id'] ?? '') ?: '—'));
            }
            $resultado[] = ['n' => $n, 'error' => $error, 'avisos' => $avisos, 'obj' => $obj, 'celdas' => $celdas];
        }
        return $resultado;
    }

    if ($tipo === 'curso') {
        $categorias = $db->query('SELECT id, descripcion_categorias_cursos AS nombre FROM categorias_cursos WHERE estado_categorias_cursos = 1')->fetchAll(PDO::FETCH_ASSOC);
        $areas = $db->query('SELECT nombre FROM areas_tecnicas WHERE estado = 1')->fetchAll(PDO::FETCH_ASSOC);
        $jornadas = array_map(static function ($j) {
            return ['nombre' => $j];
        }, CENGI_CARGA_JORNADAS);
        $existentes = [];
        foreach ($db->query('SELECT nombre_cursos, inicio FROM cursos')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $existentes[cengi_carga_norm($c['nombre_cursos']) . '|' . $c['inicio']] = true;
        }
        $codigos = array_fill_keys(array_map('strtoupper', array_filter($db->query('SELECT codigo_curso FROM cursos')->fetchAll(PDO::FETCH_COLUMN))), true);
        $vistos = [];
        foreach ($filas as $n => $f) {
            $error = null;
            $avisos = [];
            $curso = mb_substr(cengi_carga_texto($f['curso'] ?? ''), 0, 255, 'UTF-8');
            $codigo = strtoupper(cengi_carga_texto($f['codigo'] ?? ''));
            $categoria = cengi_carga_buscar($categorias, $f['categoria'] ?? '', 'nombre');
            $modalidad = cengi_carga_buscar(array_map(static function ($m) {
                return ['nombre' => $m];
            }, CENGI_PP_MODALIDADES), $f['modalidad'] ?? '', 'nombre');
            $textoActividad = cengi_carga_norm($f['actividad'] ?? '');
            $actividad = $textoActividad === '' || strpos($textoActividad, 'formacion') === 0 ? 'formacion_academica' : (strpos($textoActividad, 'evento') === 0 ? 'evento_tecnico' : null);
            $textoJornada = cengi_carga_texto($f['jornada'] ?? '');
            $jornada = $textoJornada === '' ? null : (cengi_carga_buscar($jornadas, $textoJornada, 'nombre')['nombre'] ?? false);
            $dias = mb_substr(cengi_carga_texto($f['dias'] ?? ''), 0, 255, 'UTF-8');
            $horario = cengi_carga_horario($f);
            $fi = cengi_carga_fecha($f['fecha_inicio'] ?? null);
            $ff = cengi_carga_fecha($f['fecha_fin'] ?? null);
            $cupo = cengi_carga_numero($f['cupo'] ?? null);
            $modulos = [];
            $modulosOk = true;
            foreach (array_filter(array_map('trim', explode(';', cengi_carga_texto($f['modulos'] ?? '')))) as $m) {
                if (preg_match('/^(.+?)\s*[\(:|]\s*([\d.,]+)\s*(?:h|horas?)?\s*\)?$/iu', $m, $p)) {
                    $horas = cengi_carga_numero($p[2]);
                    $modulosOk = $modulosOk && $horas !== false && $horas >= 0 && $horas <= 9999;
                    $modulos[] = ['id' => 0, 'nombre' => mb_substr(trim($p[1]), 0, 255, 'UTF-8'), 'horas' => (float) $horas, 'pre' => 1, 'post' => 1, 'instructores' => []];
                } else {
                    $modulos[] = ['id' => 0, 'nombre' => mb_substr($m, 0, 255, 'UTF-8'), 'horas' => 0, 'pre' => 1, 'post' => 1, 'instructores' => [], 'sin_horas' => true];
                }
            }
            $clave = cengi_carga_norm($curso) . '|' . $fi;

            if (mb_strlen($curso, 'UTF-8') < 3) {
                $error = 'Falta el nombre del curso';
            } elseif (!$categoria) {
                $error = cengi_carga_texto($f['categoria'] ?? '') === '' ? 'Falta la categoría' : 'Categoría “' . cengi_carga_texto($f['categoria']) . '” no existe';
            } elseif (!$modalidad) {
                $error = 'Modalidad “' . cengi_carga_texto($f['modalidad'] ?? '') . '” no válida';
            } elseif ($actividad === null) {
                $error = 'Tipo de actividad no válido (Formación académica o Evento técnico)';
            } elseif ($jornada === false) {
                $error = 'Jornada “' . $textoJornada . '” no válida';
            } elseif ($dias === '') {
                $error = 'Faltan los días';
            } elseif (!$horario) {
                $error = $horario === null ? 'Falta el horario' : 'Horario no válido (usa 8:00–12:00)';
            } elseif ($fi === null || $fi === false) {
                $error = $fi === null ? 'Falta la fecha inicial' : 'Fecha inicial no válida';
            } elseif ($ff === null || $ff === false) {
                $error = $ff === null ? 'Falta la fecha final' : 'Fecha final no válida';
            } elseif ($ff < $fi) {
                $error = 'Fecha final anterior a la inicial';
            } elseif ($cupo === false || ($cupo !== null && ($cupo < 1 || floor($cupo) != $cupo))) {
                $error = 'Cupo no válido';
            } elseif ($codigo !== '' && !preg_match('/^[A-Z0-9._-]{3,30}$/', $codigo)) {
                $error = 'Código no válido (3 a 30 letras, números, punto o guion)';
            } elseif ($codigo !== '' && isset($codigos[$codigo])) {
                $error = 'El código ' . $codigo . ' ya existe';
            } elseif (!$modulosOk) {
                $error = 'Horas de módulo no válidas';
            } elseif (isset($existentes[$clave])) {
                $error = 'Ya existe un curso con ese nombre y fecha inicial';
            } elseif (isset($vistos[$clave])) {
                $error = 'Fila duplicada en el archivo';
            }
            $vistos[$clave] = true;
            if ($codigo !== '') {
                $codigos[$codigo] = true;
            }

            $obj = null;
            if (!$error) {
                $area = cengi_carga_buscar($areas, $f['area'] ?? '', 'nombre');
                if (!$area && cengi_carga_texto($f['area'] ?? '') !== '') {
                    $avisos[] = 'área técnica no existe';
                }
                $instructor = cengi_carga_buscar($instructores, $f['instructor'] ?? '', 'nombre', 'codigo_catedratico');
                if (!$instructor && cengi_carga_texto($f['instructor'] ?? '') !== '') {
                    $avisos[] = 'instructor no registrado';
                }
                if ($jornada === null) {
                    $jornada = $horario[0] < '12:00' ? ($horario[1] > '13:00' ? 'Todo Completo' : 'Matutina') : 'Vespertina';
                    $avisos[] = 'jornada ' . mb_strtolower($jornada, 'UTF-8') . ' según el horario';
                }
                if ($codigo === '') {
                    $avisos[] = 'código automático';
                }
                if (array_filter($modulos, static function ($m) { return !empty($m['sin_horas']); })) {
                    $avisos[] = 'módulo(s) sin horas';
                }
                $obj = [
                    'codigo' => $codigo, 'categoria_id' => (int) $categoria['id'], 'instructor_id' => $instructor ? (int) $instructor['id'] : null,
                    'actividad' => $actividad, 'modalidad' => $modalidad['nombre'], 'nombre' => $curso, 'area' => $area['nombre'] ?? '',
                    'jornada' => $jornada, 'dias' => $dias, 'horario' => $horario[0] . ' - ' . $horario[1], 'cupo' => $cupo !== null ? (int) $cupo : null,
                    'inicio' => $fi, 'fin' => $ff, 'modulos' => array_map(static function ($m) {
                        unset($m['sin_horas']);
                        return $m;
                    }, $modulos),
                ];
            }
            $resultado[] = ['n' => $n, 'error' => $error, 'avisos' => $avisos, 'obj' => $obj, 'celdas' => [
                $curso ?: '—', $codigo ?: '(automático)', cengi_carga_texto($f['modalidad'] ?? '') ?: '—',
                $fi ? cengi_pp_fecha($fi) : (cengi_carga_texto($f['fecha_inicio'] ?? '') ?: '—'),
                $ff ? cengi_pp_fecha($ff) : (cengi_carga_texto($f['fecha_fin'] ?? '') ?: '—'),
                $modulos ? count($modulos) . ' módulo(s)' : '—',
            ]];
        }
        return $resultado;
    }

    // tarifas
    $anio = (int) $destino;
    $stmt = $db->prepare("SELECT id, nombre FROM planificacion_cursos WHERE anio = ? AND estado <> 'cancelado'");
    $stmt->execute([$anio]);
    $planes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $actuales = [];
    foreach ($db->query('SELECT instructor_id, planificacion_id, tarifa FROM tarifas_instructor_plan')->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $actuales[$t['instructor_id'] . ':' . $t['planificacion_id']] = (float) $t['tarifa'];
    }
    $conSesiones = [];
    foreach ($db->query("SELECT DISTINCT CONCAT(instructor_id, ':', planificacion_id) FROM curso_sesiones WHERE estado <> 'cancelada' AND instructor_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $k) {
        $conSesiones[$k] = true;
    }
    $vistos = [];
    foreach ($filas as $n => $f) {
        $error = null;
        $avisos = [];
        $instructor = cengi_carga_buscar($instructores, $f['instructor'] ?? '', 'nombre', 'codigo_catedratico');
        $textoCurso = cengi_carga_texto($f['curso'] ?? '');
        $plan = $textoCurso !== '' ? cengi_carga_buscar($planes, $textoCurso, 'nombre') : null;
        $costo = cengi_carga_numero($f['costo'] ?? null);
        if (!$instructor) {
            $error = 'Instructor no registrado';
        } elseif ($costo === null || $costo === false || $costo < 0) {
            $error = 'Costo no válido';
        } elseif ($textoCurso !== '' && !$plan) {
            $error = 'Curso no planificado en el año ' . cengi_pp_anio_texto($anio);
        }
        $clave = $instructor ? $instructor['id'] . ':' . ($plan['id'] ?? 'base') : null;
        if (!$error && isset($vistos[$clave])) {
            $error = 'Fila duplicada en el archivo';
        }
        $vistos[$clave] = true;
        $obj = null;
        if (!$error) {
            $actual = $plan ? ($actuales[$clave] ?? $instructor['tarifa_sesion']) : $instructor['tarifa_sesion'];
            if (!$plan) {
                $avisos[] = 'tarifa base del instructor';
            } elseif (!isset($conSesiones[$clave])) {
                $avisos[] = 'aún no tiene sesiones en ese curso';
            }
            if ($actual !== null && abs((float) $actual - $costo) < 0.005) {
                $avisos[] = 'misma tarifa';
            }
            $obj = ['instructor_id' => (int) $instructor['id'], 'planificacion_id' => $plan ? (int) $plan['id'] : null,
                'tarifa' => round($costo, 2), 'observaciones' => mb_substr(cengi_carga_texto($f['observaciones'] ?? ''), 0, 500, 'UTF-8')];
        }
        $resultado[] = ['n' => $n, 'error' => $error, 'avisos' => $avisos, 'obj' => $obj, 'celdas' => [
            cengi_carga_texto($f['instructor'] ?? '') ?: '—', $textoCurso ?: '(tarifa base)', $costo !== null && $costo !== false ? cengi_pp_q($costo, 2) : (cengi_carga_texto($f['costo'] ?? '') ?: '—'),
        ]];
    }
    return $resultado;
}

/** Inserta/actualiza las filas validas. Devuelve [cantidad, url de regreso]. */
function cengi_carga_aplicar(PDO $db, $tipo, $destino, array $validas, $archivo)
{
    $hoy = cengi_pp_hoy();
    if ($tipo === 'plan') {
        foreach ($validas as $r) {
            $obj = $r['obj'] + ['creado_por' => cengi_usuario_actual_id() ?: null, 'creado_por_nombre' => cengi_pp_usuario_nombre()];
            $columnas = array_keys($obj);
            $db->prepare('INSERT INTO planificacion_cursos (' . implode(', ', $columnas) . ') VALUES (' . implode(', ', array_fill(0, count($columnas), '?')) . ')')->execute(array_values($obj));
            cengi_pp_log($db, cengi_pp_plan($db, (int) $db->lastInsertId()), 'Creación', 'Importado por carga masiva (' . $archivo . ').');
        }
        return 'planificacion_cursos.php?' . http_build_query(['anio' => $destino, 'msg' => 'importado', 'n' => count($validas)]);
    }
    if ($tipo === 'ses') {
        $fechasPorPlan = [];
        foreach ($validas as $r) {
            $columnas = array_keys($r['obj']);
            $db->prepare('INSERT INTO curso_sesiones (' . implode(', ', $columnas) . ') VALUES (' . implode(', ', array_fill(0, count($columnas), '?')) . ')')->execute(array_values($r['obj']));
            $fechasPorPlan[$r['obj']['planificacion_id']][] = $r['obj']['fecha'];
        }
        // Una entrada de bitacora por curso (cada curso avisa a sus propios inscritos).
        foreach ($fechasPorPlan as $planId => $fechas) {
            $plan = cengi_pp_plan($db, (int) $planId);
            sort($fechas);
            cengi_pp_log($db, $plan, 'Sesiones agregadas', count($fechas) . ' sesión(es) importadas por carga masiva (' . $archivo . '): ' . implode(', ', array_map('cengi_pp_fecha_corta', $fechas)), [
                'afecta' => cengi_pp_convocado($plan) && end($fechas) >= $hoy,
            ]);
        }
        return 'programa_sesiones.php?' . http_build_query(['ver' => $destino, 'msg' => 'importado', 'n' => count($validas)]);
    }
    if ($tipo === 'curso') {
        // Mismo alta que el formulario "Crear curso" (guardar_cursos.php).
        $ingenioId = cengi_curso_ingenio_por_defecto($db);
        $insertar = $db->prepare('INSERT INTO cursos (codigo_curso, categoria_curso_id, ingenio_id, instructor_id, actividad_tipo, tipo, nombre_cursos,
            area_tecnica, jornada_cursos, dias, horario, cupo, inicio, fin, creado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $codigoAuto = $db->prepare('UPDATE cursos SET codigo_curso = ? WHERE id = ?');
        foreach ($validas as $r) {
            $o = $r['obj'];
            $insertar->execute([$o['codigo'] !== '' ? $o['codigo'] : null, $o['categoria_id'], $ingenioId, $o['instructor_id'], $o['actividad'], $o['modalidad'],
                $o['nombre'], $o['area'], $o['jornada'], $o['dias'], $o['horario'], $o['cupo'], $o['inicio'], $o['fin']]);
            $cursoId = (int) $db->lastInsertId();
            if ($o['codigo'] === '') {
                $codigoAuto->execute(['CEN-T-' . str_pad((string) $cursoId, 3, '0', STR_PAD_LEFT), $cursoId]);
            }
            $moduloIds = cengi_curso_guardar_modulos($db, $cursoId, $o['modulos'], false);
            cengi_curso_asegurar_enlaces_evaluacion($db, $cursoId, $o['instructor_id'], $o['modulos'], $moduloIds);
        }
        return 'ver_cursos.php?' . http_build_query(['anio' => 'todos', 'mensaje' => 'importado', 'n' => count($validas)]);
    }
    $base = $db->prepare('UPDATE instructores SET tarifa_sesion = ? WHERE id = ?');
    $porCurso = $db->prepare('INSERT INTO tarifas_instructor_plan (instructor_id, planificacion_id, tarifa, observaciones) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE tarifa = VALUES(tarifa), observaciones = VALUES(observaciones)');
    foreach ($validas as $r) {
        $o = $r['obj'];
        if ($o['planificacion_id']) {
            $porCurso->execute([$o['instructor_id'], $o['planificacion_id'], $o['tarifa'], $o['observaciones']]);
        } else {
            $base->execute([$o['tarifa'], $o['instructor_id']]);
        }
    }
    return 'pago_instructores.php?' . http_build_query(['msg' => 'importado', 'n' => count($validas)]);
}

/** Plantilla Excel con ejemplos y una hoja de instrucciones con los valores validos. */
function cengi_carga_plantilla(PDO $db, $tipo, $destino)
{
    if ($tipo === 'ses') {
        cengi_carga_plantilla_sesiones($db, $destino);
    }
    $config = CENGI_CARGA_TIPOS[$tipo];
    $libro = new Spreadsheet();
    $hoja = $libro->getActiveSheet();
    $hoja->setTitle('Plantilla');
    $columnas = array_filter($config['cols'], static function ($clave) {
        return !in_array($clave, ['hora_inicio', 'hora_fin'], true);
    }, ARRAY_FILTER_USE_KEY);
    $hoja->fromArray(array_column($columnas, 0), null, 'A1');
    $anio = (int) $destino ?: cengi_pp_anio_actual();
    $instructores = array_column(cengi_pp_instructores($db), 'nombre');
    $instr = $instructores[0] ?? 'Ing. Nombre Apellido';
    if ($tipo === 'plan') {
        $ejemplos = [
            ['Manejo integrado de plagas', 'Capacitacion general', '', 'Presencial', '8:00–17:00', ($anio) . '-02-02', ($anio) . '-02-06', 5, 'Lunes a viernes', 15, 30, 380, 520, 95, $instr, 'Identificación de plagas; Control biológico', 'Todos los ingenios', ''],
            ['Webinar: pronóstico climático', '', '', 'Virtual', '18:00–19:30', '24/03/' . $anio, '24/03/' . $anio, 1, 'Miércoles', 25, '', 0, 0, 0, '', '', '', 'Gratuito'],
        ];
    } elseif ($tipo === 'curso') {
        $categoria = (string) ($db->query('SELECT descripcion_categorias_cursos FROM categorias_cursos WHERE estado_categorias_cursos = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 'Capacitación general');
        $area = (string) ($db->query('SELECT nombre FROM areas_tecnicas WHERE estado = 1 ORDER BY nombre LIMIT 1')->fetchColumn() ?: '');
        $y = date('Y');
        $ejemplos = [
            ['', 'Diplomado de Ingeniería Cañera', $categoria, 'Formación académica', $area, 'Presencial', 'Todo completo', 'Viernes', '8:00–16:00', $y . '-02-06', $y . '-11-27', 30, $instr, 'Desarrollo varietal (16); Riegos (12); Nutrición vegetal (12)'],
            ['CEN-T-900', 'Excel intermedio', $categoria, 'Formación académica', '', 'Virtual', '', 'Martes y jueves', '18:00–20:00', '03/03/' . $y, '26/03/' . $y, 25, '', ''],
        ];
    } else {
        $stmt = $db->prepare("SELECT nombre FROM planificacion_cursos WHERE anio = ? AND estado <> 'cancelado' ORDER BY fecha_inicio LIMIT 1");
        $stmt->execute([$anio]);
        $ejemplos = [[$instr, (string) ($stmt->fetchColumn() ?: 'Nombre del curso planificado'), 950, 'Ajuste de tarifa'], [$instr, '', 900, 'Tarifa base']];
    }
    $hoja->fromArray($ejemplos, null, 'A2');
    $hoja->getStyle('A1:' . $hoja->getHighestColumn() . '1')->getFont()->setBold(true);
    $hoja->getStyle('A1:' . $hoja->getHighestColumn() . '1')->getFill()->setFillType('solid')->getStartColor()->setRGB('EAF6DD');
    foreach (range(1, count($columnas)) as $i) {
        $hoja->getColumnDimensionByColumn($i)->setWidth(22);
    }
    $hoja->freezePane('A2');

    $ayuda = $libro->createSheet();
    $ayuda->setTitle('Instrucciones');
    $filas = [[$config['titulo']], [$config['nota']], [], ['Columna', 'Obligatoria', 'Formato / valores']];
    foreach ($config['cols'] as [$etiqueta, , $obligatoria, $descripcion]) {
        $filas[] = [$etiqueta, $obligatoria ? 'Sí' : 'No', $descripcion];
    }
    $filas[] = [];
    $filas[] = ['Modalidades válidas', implode(', ', CENGI_PP_MODALIDADES)];
    if ($tipo === 'plan' || $tipo === 'curso') {
        $filas[] = ['Categorías', implode(', ', $db->query('SELECT descripcion_categorias_cursos FROM categorias_cursos WHERE estado_categorias_cursos = 1 ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN))];
        $filas[] = ['Áreas técnicas', implode(', ', $db->query('SELECT nombre FROM areas_tecnicas WHERE estado = 1 ORDER BY 1')->fetchAll(PDO::FETCH_COLUMN))];
        if ($tipo === 'curso') {
            $filas[] = ['Jornadas válidas', 'Matutina, Vespertina, Fin de semana, Todo completo'];
            $filas[] = ['Tipos de actividad', 'Formación académica, Evento técnico'];
        }
    }
    $filas[] = ['Instructores registrados', implode(', ', $instructores)];
    $ayuda->fromArray($filas, null, 'A1');
    $ayuda->getStyle('A1')->getFont()->setBold(true)->setSize(13);
    $ayuda->getStyle('A4:C4')->getFont()->setBold(true);
    $ayuda->getColumnDimension('A')->setWidth(28);
    $ayuda->getColumnDimension('B')->setWidth(14);
    $ayuda->getColumnDimension('C')->setWidth(90);
    $ayuda->getStyle('A2')->getAlignment()->setWrapText(true);
    $libro->setActiveSheetIndex(0);

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Plantilla_' . ['plan' => 'planificacion', 'ses' => 'programa_sesiones', 'curso' => 'cursos_ejecucion', 'tarifa' => 'tarifas_instructores'][$tipo] . '.xlsx"');
    (new Xlsx($libro))->save('php://output');
    exit;
}

/**
 * Plantilla del programa de sesiones, ya rellenada con los cursos reales del destino:
 *   - un curso: dos filas de ejemplo con sus módulos, fechas, horario e instructor responsable;
 *   - todos los cursos del año: una fila por curso (con ID y nombre) para copiar y completar.
 * Lleva listas desplegables (curso, módulo, instructor, modalidad y soporte) que se alimentan
 * de una hoja oculta "Listas", la hoja "Cursos" de referencia y la hoja "Instrucciones".
 */
function cengi_carga_plantilla_sesiones(PDO $db, $destino)
{
    $config = CENGI_CARGA_TIPOS['ses'];
    try {
        $info = cengi_carga_ses_destino($db, $destino);
    } catch (InvalidArgumentException $e) {
        $info = ['todos' => true, 'anio' => cengi_pp_anio_actual(), 'planes' => []];
    }
    $planes = $info['planes'];
    $instructores = array_column(cengi_pp_instructores($db), 'nombre');
    $soportes = array_column(cengi_pp_soportes($db), 'nombre');
    $conteo = $db->prepare("SELECT COUNT(*) FROM curso_sesiones WHERE planificacion_id = ? AND estado <> 'cancelada'");

    // Columnas: las de curso solo cuando el archivo es para todos los cursos.
    $claves = array_values(array_filter(array_keys($config['cols']), static function ($clave) use ($info) {
        return !in_array($clave, ['hora_inicio', 'hora_fin'], true) && ($info['todos'] || !in_array($clave, ['plan_id', 'curso'], true));
    }));
    $letra = [];
    foreach ($claves as $i => $clave) {
        $letra[$clave] = Coordinate::stringFromColumnIndex($i + 1);
    }

    $libro = new Spreadsheet();
    $libro->getProperties()->setCreator('CENGICAÑA — Cengicursos')->setLastModifiedBy('Cengicursos')->setTitle('Plantilla — Programa de sesiones');
    $hoja = $libro->getActiveSheet();
    $hoja->setTitle('Plantilla');
    $hoja->fromArray(array_map(static function ($clave) use ($config) {
        return $config['cols'][$clave][0];
    }, $claves), null, 'A1');

    // Filas de ejemplo con datos reales del curso o cursos.
    $filas = [];
    $fila = static function (array $plan, $modulo, $tema, $fecha, $horario) use ($instructores) {
        return [
            'plan_id' => (int) $plan['id'], 'curso' => $plan['nombre'], 'modulo' => $modulo, 'tema' => $tema,
            'fecha' => $fecha, 'horario' => $horario, 'instructor' => $plan['instructor_nombre'] ?: ($instructores[0] ?? ''),
            'codigo' => '', 'modalidad' => $plan['modalidad'], 'lugar' => '', 'soporte' => '',
        ];
    };
    $modulosPorPlan = [];
    foreach ($planes as $plan) {
        $modulosPorPlan[(int) $plan['id']] = cengi_pp_modulos_plan($db, $plan);
    }
    if (!$info['todos'] && $planes) {
        $plan = reset($planes);
        $modulos = $modulosPorPlan[(int) $plan['id']];
        $horario = cengi_pp_horario($plan['hora_inicio'], $plan['hora_fin']);
        $segunda = min(cengi_pp_sumar_dias($plan['fecha_inicio'], 7), $plan['fecha_fin']);
        $filas[] = $fila($plan, $modulos[0], 'Tema de la primera sesión', $plan['fecha_inicio'], $horario);
        $filas[] = $fila($plan, $modulos[min(1, count($modulos) - 1)], 'Tema de la segunda sesión', $segunda, $horario);
    } else {
        foreach ($planes as $plan) {
            $filas[] = $fila($plan, $modulosPorPlan[(int) $plan['id']][0], 'Tema de la primera sesión', $plan['fecha_inicio'], cengi_pp_horario($plan['hora_inicio'], $plan['hora_fin']));
        }
    }
    foreach ($filas as $i => $datos) {
        foreach ($claves as $clave) {
            $celda = $letra[$clave] . ($i + 2);
            if ($clave === 'fecha') {
                $hoja->setCellValue($celda, ExcelDate::PHPToExcel(new DateTime($datos['fecha'])));
            } else {
                $hoja->setCellValueExplicit($celda, (string) $datos[$clave], $clave === 'plan_id' ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
        }
    }

    // Formato: encabezado, fechas dd/mm/aaaa y el resto como texto (Excel no convierte "8:00–12:00").
    $ultima = $letra[end($claves)];
    $hasta = 500;
    $hoja->getStyle('A1:' . $ultima . '1')->getFont()->setBold(true);
    $hoja->getStyle('A1:' . $ultima . '1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EAF6DD');
    foreach ($claves as $clave) {
        $columna = $letra[$clave];
        $hoja->getColumnDimension($columna)->setWidth(['curso' => 44, 'tema' => 36, 'lugar' => 30, 'plan_id' => 10, 'fecha' => 13][$clave] ?? 20);
        $hoja->getStyle($columna . '2:' . $columna . $hasta)->getNumberFormat()->setFormatCode($clave === 'fecha' ? 'dd/mm/yyyy' : ($clave === 'plan_id' ? '0' : NumberFormat::FORMAT_TEXT));
    }
    foreach ($claves as $clave) {
        if ($config['cols'][$clave][2]) {
            $hoja->getComment($letra[$clave] . '1')->getText()->createTextRun('Obligatoria. ' . $config['cols'][$clave][3]);
        }
    }
    $hoja->freezePane('A2');

    // Hoja oculta con las listas de las validaciones.
    $listas = $libro->createSheet();
    $listas->setTitle('Listas');
    $columnasLista = [
        'A' => ['Instructores', $instructores],
        'B' => ['Soporte técnico', $soportes],
        'C' => ['Cursos', array_column($planes, 'nombre')],
        'D' => ['Módulos', $info['todos'] ? array_values(array_unique(array_merge(...array_values($modulosPorPlan ?: [['Sesión única']])))) : ($modulosPorPlan ? reset($modulosPorPlan) : ['Sesión única'])],
        'E' => ['Modalidades', CENGI_PP_MODALIDADES],
    ];
    foreach ($columnasLista as $col => [$titulo, $valores]) {
        $listas->setCellValue($col . '1', $titulo);
        foreach (array_values($valores) as $i => $valor) {
            $listas->setCellValueExplicit($col . ($i + 2), (string) $valor, DataType::TYPE_STRING);
        }
    }
    $listas->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
    $validar = static function ($clave, $col, array $valores) use ($hoja, $letra, $hasta) {
        if (!isset($letra[$clave]) || !$valores) {
            return;
        }
        $v = new DataValidation();
        $v->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)
            // Solo sugiere: se puede escribir otro valor (p. ej. el código del instructor) y lo valida el sistema al subir.
            ->setShowErrorMessage(false)
            ->setFormula1("'Listas'!\$" . $col . '$2:$' . $col . '$' . (count($valores) + 1))
            // Una sola regla para todo el rango (setDataValidation con rango la copia celda por celda).
            ->setSqref($letra[$clave] . '2:' . $letra[$clave] . $hasta);
        $hoja->setDataValidation($letra[$clave] . '2', $v);
    };
    foreach (['instructor' => 'A', 'soporte' => 'B', 'curso' => 'C', 'modulo' => 'D', 'modalidad' => 'E'] as $clave => $col) {
        $validar($clave, $col, $columnasLista[$col][1]);
    }

    // Hoja de referencia con los cursos del destino.
    $cursos = $libro->createSheet();
    $cursos->setTitle('Cursos');
    $ref = [['ID curso', 'Curso/Diplomado', 'Inicio', 'Fin', 'Modalidad', 'Horario', 'Sesiones planificadas', 'Sesiones ya programadas', 'Módulos', 'Instructor responsable']];
    foreach ($planes as $plan) {
        $conteo->execute([(int) $plan['id']]);
        $ref[] = [(int) $plan['id'], $plan['nombre'], cengi_pp_fecha($plan['fecha_inicio']), cengi_pp_fecha($plan['fecha_fin']), $plan['modalidad'],
            cengi_pp_horario($plan['hora_inicio'], $plan['hora_fin']), (int) $plan['sesiones_planificadas'], (int) $conteo->fetchColumn(),
            implode('; ', $modulosPorPlan[(int) $plan['id']]), $plan['instructor_nombre'] ?: '—'];
    }
    if (count($ref) === 1) {
        $ref[] = ['', 'No hay cursos planificados para este destino.'];
    }
    $cursos->fromArray($ref, null, 'A1', true);
    $cursos->getStyle('A1:J1')->getFont()->setBold(true);
    $cursos->getStyle('A1:J1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EAF6DD');
    foreach (['A' => 10, 'B' => 48, 'C' => 12, 'D' => 12, 'E' => 12, 'F' => 14, 'G' => 12, 'H' => 12, 'I' => 50, 'J' => 30] as $col => $ancho) {
        $cursos->getColumnDimension($col)->setWidth($ancho);
    }
    $cursos->freezePane('A2');

    // Instrucciones.
    $ayuda = $libro->createSheet();
    $ayuda->setTitle('Instrucciones');
    $destinoTexto = $info['todos'] ? 'Todos los cursos del año ' . $info['anio'] : ($planes ? reset($planes)['nombre'] : '');
    $texto = [[$config['titulo']], ['Destino: ' . $destinoTexto], [$config['nota']], [],
        [$info['todos']
            ? 'Hay una fila de ejemplo por curso: cópiala tantas veces como sesiones tenga el curso y cambia tema, fecha y horario. Borra las filas de los cursos que no vas a cargar.'
            : 'Hay dos filas de ejemplo con los datos del curso: cámbialas y agrega una fila por sesión.'],
        ['Las celdas con lista desplegable sugieren valores; también puedes escribir el código del instructor o del soporte.'], [],
        ['Columna', 'Obligatoria', 'Formato / valores']];
    foreach ($claves as $clave) {
        [$etiqueta, , $obligatoria, $descripcion] = $config['cols'][$clave];
        $texto[] = [$etiqueta, $obligatoria || ($info['todos'] && $clave === 'curso') ? 'Sí' : 'No', $descripcion];
    }
    $texto[] = ['Hora inicio / Hora fin', 'No', 'Alternativa a Horario: puedes reemplazar la columna Horario por dos columnas con esos encabezados.'];
    $ayuda->fromArray($texto, null, 'A1');
    $ayuda->getStyle('A1')->getFont()->setBold(true)->setSize(13);
    $ayuda->getStyle('A8:C8')->getFont()->setBold(true);
    $ayuda->getColumnDimension('A')->setWidth(28);
    $ayuda->getColumnDimension('B')->setWidth(14);
    $ayuda->getColumnDimension('C')->setWidth(90);
    $libro->setActiveSheetIndex(0);

    $sufijo = $info['todos'] ? 'todos_' . $info['anio'] : 'curso_' . (int) key($planes);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Plantilla_programa_sesiones_' . $sufijo . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new Xlsx($libro))->save('php://output');
    exit;
}

// ---------------------------------------------------------------- controlador

$db = conectar();
$tipo = array_key_exists($_REQUEST['tipo'] ?? '', CENGI_CARGA_TIPOS) ? $_REQUEST['tipo'] : 'plan';
$config = CENGI_CARGA_TIPOS[$tipo];
$destino = (string) ($_REQUEST['destino'] ?? '');
if ($tipo === 'ses') {
    // Sesiones: un curso (id) o todos los cursos de un año ("z:2026"); por defecto, todos los del año actual.
    if (!ctype_digit($destino) && !preg_match('/^z:\d{4}$/', $destino)) {
        $destino = 'z:' . cengi_pp_anio_actual();
    }
} elseif (!ctype_digit($destino)) {
    $destino = (string) cengi_pp_anio_actual();
}
$sesTodos = $tipo === 'ses' && strpos($destino, 'z:') === 0;
$error = '';
$resultado = null;
$carga = $_SESSION['cengi_carga_planificacion'] ?? null;

if (!empty($_GET['plantilla'])) {
    cengi_carga_plantilla($db, $tipo, $destino);
}
if (!empty($_GET['errores']) && $carga && hash_equals($carga['token'], (string) $_GET['errores'])) {
    $filas = [array_merge(array_column(CENGI_CARGA_TIPOS[$carga['tipo']]['cols'], 0), ['Fila', 'Error'])];
    foreach (cengi_carga_validar($db, $carga['tipo'], $carga['destino'], $carga['filas']) as $r) {
        if ($r['error']) {
            $fila = [];
            foreach (array_keys(CENGI_CARGA_TIPOS[$carga['tipo']]['cols']) as $clave) {
                $fila[] = cengi_carga_texto($carga['filas'][$r['n']][$clave] ?? '');
            }
            $filas[] = array_merge($fila, [$r['n'], $r['error']]);
        }
    }
    cengi_pp_csv('Filas_con_error', $filas);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    try {
        if ($accion === 'analizar') {
            $archivo = $_FILES['archivo'] ?? null;
            if (!$archivo || $archivo['error'] === UPLOAD_ERR_NO_FILE) {
                throw new InvalidArgumentException('Elige un archivo.');
            }
            if ($archivo['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($archivo['tmp_name'])) {
                throw new InvalidArgumentException('No se pudo recibir el archivo (código ' . (int) $archivo['error'] . ').');
            }
            if ($archivo['size'] > CENGI_CARGA_MAX_BYTES) {
                throw new InvalidArgumentException('El archivo supera los 5 MB.');
            }
            try {
                $filas = cengi_carga_leer_archivo($archivo['tmp_name'], $archivo['name']);
            } catch (InvalidArgumentException $e) {
                throw $e;
            } catch (Throwable $e) {
                error_log('Carga masiva: no se pudo leer ' . $archivo['name'] . ': ' . $e->getMessage());
                throw new InvalidArgumentException('No se pudo leer el archivo. Verifica que sea un Excel o CSV válido.');
            }
            if (count($filas) < 2) {
                throw new InvalidArgumentException('El archivo no tiene filas de datos (la primera fila debe ser el encabezado).');
            }
            if (count($filas) - 1 > CENGI_CARGA_MAX_FILAS) {
                throw new InvalidArgumentException('El archivo tiene más de ' . CENGI_CARGA_MAX_FILAS . ' filas.');
            }
            $mapa = cengi_carga_mapear(array_map('cengi_carga_texto', $filas[0]), $config['cols']);
            $faltan = [];
            foreach ($config['cols'] as $clave => [$etiqueta, , $obligatoria]) {
                if ($obligatoria && !isset($mapa[$clave])) {
                    $faltan[] = $etiqueta;
                }
            }
            if (!isset($mapa['horario']) && !(isset($mapa['hora_inicio'], $mapa['hora_fin'])) && in_array($tipo, ['ses', 'curso'], true)) {
                $faltan[] = 'Horario (o Hora inicio y Hora fin)';
            }
            if ($sesTodos && !isset($mapa['plan_id']) && !isset($mapa['curso'])) {
                $faltan[] = 'ID curso o Curso/Diplomado (se cargan todos los cursos del año)';
            }
            if ($faltan) {
                throw new InvalidArgumentException('Faltan columnas obligatorias: ' . implode(', ', $faltan) . '. Descarga la plantilla para ver los encabezados esperados.');
            }
            $datos = [];
            foreach (array_slice($filas, 1, null, true) as $i => $fila) {
                $registro = [];
                foreach ($mapa as $clave => $indice) {
                    $registro[$clave] = $fila[$indice] ?? null;
                }
                $datos[$i + 1] = $registro; // numero de fila en el archivo (1 = encabezado)
            }
            $carga = ['token' => bin2hex(random_bytes(16)), 'tipo' => $tipo, 'destino' => $destino, 'archivo' => mb_substr(basename($archivo['name']), 0, 120, 'UTF-8'),
                'filas' => $datos];
            $_SESSION['cengi_carga_planificacion'] = $carga;
            $resultado = cengi_carga_validar($db, $tipo, $destino, $datos);
        } elseif ($accion === 'aplicar') {
            if (!$carga || !hash_equals($carga['token'], (string) ($_POST['token'] ?? ''))) {
                throw new InvalidArgumentException('La vista previa expiró. Vuelve a subir el archivo.');
            }
            $db->beginTransaction();
            $validas = array_values(array_filter(cengi_carga_validar($db, $carga['tipo'], $carga['destino'], $carga['filas']), static function ($r) {
                return !$r['error'];
            }));
            if (!$validas) {
                throw new InvalidArgumentException('No hay filas válidas para importar.');
            }
            $regreso = cengi_carga_aplicar($db, $carga['tipo'], $carga['destino'], $validas, $carga['archivo']);
            $db->commit();
            unset($_SESSION['cengi_carga_planificacion']);
            header('Location: ' . $regreso);
            exit;
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($e instanceof InvalidArgumentException) {
            $error = $e->getMessage();
        } else {
            error_log('Carga masiva de planificacion: ' . $e->getMessage());
            $error = 'No fue posible procesar la carga.';
        }
    }
}

$validas = $resultado ? count(array_filter($resultado, static function ($r) {
    return !$r['error'];
})) : 0;
$conAviso = $resultado ? count(array_filter($resultado, static function ($r) {
    return !$r['error'] && $r['avisos'];
})) : 0;
$conError = $resultado ? count($resultado) - $validas : 0;

if ($tipo === 'curso') {
    $opcionesDestino = '';
    $textoDestino = 'Cursos (ejecución directa)';
} elseif ($tipo === 'ses') {
    $gruposPlanes = cengi_pp_planes_opciones($db);
    $opcionesDestino = cengi_pp_options_planes($gruposPlanes, $destino, 'Todos los cursos');
    $planDestino = ctype_digit($destino) ? cengi_pp_plan($db, (int) $destino) : null;
    $textoDestino = $sesTodos
        ? 'Todos los cursos — año ' . substr($destino, 2)
        : ($planDestino ? $planDestino['nombre'] . ' (' . cengi_pp_fecha_corta($planDestino['fecha_inicio']) . ' – ' . cengi_pp_fecha_corta($planDestino['fecha_fin']) . ')' : '');
} else {
    $opcionesDestino = '';
    foreach (cengi_pp_anios_disponibles($db, (int) $destino) as $z) {
        $opcionesDestino .= '<option value="' . $z . '"' . ((string) $z === $destino ? ' selected' : '') . '>Año ' . cengi_pp_anio_texto($z) . '</option>';
    }
    $textoDestino = 'Año ' . cengi_pp_anio_texto((int) $destino);
}
$cabeceras = ['plan' => ['Curso', 'Modalidad', 'Fecha inicial', 'Fecha final', 'Sesiones'], 'ses' => ['Módulo', 'Tema', 'Fecha', 'Horario', 'Instructor'],
    'curso' => ['Curso', 'Código', 'Modalidad', 'Fecha inicial', 'Fecha final', 'Módulos'], 'tarifa' => ['Instructor', 'Curso / diplomado', 'Costo / sesión']][$tipo];
if ($sesTodos) {
    array_unshift($cabeceras, 'Curso');
}
$volver = $config['volver'] . ($tipo === 'plan' ? '?anio=' . urlencode($destino) : ($tipo === 'ses' ? '?ver=' . urlencode($destino) : ''));
?>
<!DOCTYPE html>
<html lang="es">
<head><?php cengi_pp_head($config['titulo']); ?></head>
<body class="cengi-canvas">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page">
    <?php if ($tipo !== 'curso') { cengi_pp_subnav($config['volver']); } ?>
    <?php cengi_pp_feedback(cengi_pp_h($error), 'error'); ?>

    <section class="pp-card">
        <div class="pp-card-head">
            <div><h3><?php echo cengi_pp_h($config['titulo']); ?></h3><span class="pp-sub"><?php echo cengi_pp_h($config['nota']); ?></span></div>
            <div class="pp-toolbar-group">
                <?php foreach (['plan' => 'Planes', 'ses' => 'Sesiones', 'tarifa' => 'Tarifas', 'curso' => 'Cursos en ejecución'] as $t => $texto): ?>
                    <a class="btn btn-sm <?php echo $t === $tipo ? 'btn-primary' : 'btn-default'; ?>" href="carga_masiva_planificacion.php?tipo=<?php echo $t; ?>"><?php echo $texto; ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($resultado === null): ?>
            <form class="pp-card-body" method="post" enctype="multipart/form-data" id="carga-form">
                <input type="hidden" name="accion" value="analizar">
                <input type="hidden" name="tipo" value="<?php echo $tipo; ?>">
                <div class="cengi-course-modal-grid">
                    <?php if ($config['destino'] !== null): ?>
                    <div class="form-group">
                        <label for="carga-destino"><?php echo cengi_pp_h($config['destino']); ?></label>
                        <select class="form-control" id="carga-destino" name="destino" required>
                            <?php if ($tipo === 'ses' && $opcionesDestino === ''): ?><option value="">No hay cursos planificados</option><?php endif; ?>
                            <?php echo $opcionesDestino; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label for="carga-archivo">Archivo Excel o CSV</label>
                        <input type="file" class="form-control" id="carga-archivo" name="archivo" accept=".xlsx,.xls,.ods,.csv" required>
                        <p class="help-block">Hasta 5 MB y <?php echo number_format(CENGI_CARGA_MAX_FILAS); ?> filas. Se lee la primera hoja; la primera fila es el encabezado.</p>
                    </div>
                </div>
                <p class="pp-sub" style="margin-top:12px;"><b>Columnas:</b>
                    <?php echo implode(', ', array_map(static function ($c) {
                        return cengi_pp_h($c[0]) . ($c[2] ? ' <b>*</b>' : '');
                    }, $config['cols'])); ?>. <b>*</b> obligatoria. Fechas AAAA-MM-DD o DD/MM/AAAA; los encabezados se reconocen aunque no lleven tildes.</p>
                <div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;margin-top:14px;">
                    <a class="btn btn-default" href="<?php echo cengi_pp_h($volver); ?>">Volver</a>
                    <a class="btn btn-default" id="carga-plantilla" href="carga_masiva_planificacion.php?tipo=<?php echo $tipo; ?>&amp;plantilla=1&amp;destino=<?php echo cengi_pp_h(urlencode($destino)); ?>"><span class="glyphicon glyphicon-download-alt"></span> Descargar plantilla</a>
                    <button type="submit" class="btn btn-primary" id="carga-btn"><span class="glyphicon glyphicon-upload"></span> Revisar archivo</button>
                </div>
            </form>
        <?php else: ?>
            <div class="pp-card-body">
                <div class="pp-strip">
                    <div class="pp-mini-stat"><span>Filas leídas</span><strong><?php echo count($resultado); ?></strong></div>
                    <div class="pp-mini-stat"><span>Válidas</span><strong style="color:#3E7A12;"><?php echo $validas; ?></strong></div>
                    <div class="pp-mini-stat"><span>Con aviso</span><strong<?php echo $conAviso ? ' style="color:#B34E00;"' : ''; ?>><?php echo $conAviso; ?></strong></div>
                    <div class="pp-mini-stat"><span>Con errores</span><strong<?php echo $conError ? ' style="color:#B23223;"' : ''; ?>><?php echo $conError; ?></strong></div>
                    <div class="pp-mini-stat" style="grid-column:span 2;"><span><?php echo cengi_pp_h($carga['archivo']); ?> → destino</span><strong style="font-size:13px;"><?php echo cengi_pp_h($textoDestino); ?></strong></div>
                </div>
                <div class="pp-table-wrap">
                    <table class="cengi-org-table" style="min-width:720px;">
                        <thead><tr><th>Fila</th><?php foreach ($cabeceras as $c): ?><th><?php echo $c; ?></th><?php endforeach; ?><th>Validación</th></tr></thead>
                        <tbody>
                            <?php foreach ($resultado as $r): ?>
                                <tr class="<?php echo $r['error'] ? 'pp-muted-row' : ''; ?>">
                                    <td class="pp-num"><?php echo (int) $r['n']; ?></td>
                                    <?php foreach ($r['celdas'] as $i => $celda): ?><td<?php echo $i === 0 ? ' style="font-weight:600;"' : ''; ?>><?php echo cengi_pp_h($celda); ?></td><?php endforeach; ?>
                                    <td style="min-width:200px;">
                                        <?php if ($r['error']): ?><span class="pp-chip is-danger"><?php echo cengi_pp_h($r['error']); ?></span>
                                        <?php elseif ($r['avisos']): ?><span class="pp-chip is-yellow">Válida · <?php echo cengi_pp_h(implode(' · ', $r['avisos'])); ?></span>
                                        <?php else: ?><span class="pp-chip is-ok">Válida</span><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <form method="post" style="display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;margin-top:14px;">
                    <input type="hidden" name="accion" value="aplicar">
                    <input type="hidden" name="tipo" value="<?php echo $tipo; ?>">
                    <input type="hidden" name="token" value="<?php echo cengi_pp_h($carga['token']); ?>">
                    <a class="btn btn-default" href="carga_masiva_planificacion.php?tipo=<?php echo $tipo; ?>&amp;destino=<?php echo cengi_pp_h(urlencode($destino)); ?>">Subir otro archivo</a>
                    <?php if ($conError): ?><a class="btn btn-default" href="carga_masiva_planificacion.php?tipo=<?php echo $tipo; ?>&amp;errores=<?php echo cengi_pp_h($carga['token']); ?>"><span class="glyphicon glyphicon-download-alt"></span> Descargar filas con error</a><?php endif; ?>
                    <button type="submit" class="btn btn-primary" <?php echo $validas ? '' : 'disabled'; ?> onclick="this.disabled=true;this.form.submit();">
                        <?php echo $validas ? 'Confirmar e importar ' . $validas . ' fila' . ($validas > 1 ? 's' : '') . ' válida' . ($validas > 1 ? 's' : '') : 'No hay filas válidas'; ?>
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </section>
</main>
<script>
(function () {
    var destino = document.getElementById('carga-destino'), plantilla = document.getElementById('carga-plantilla'), form = document.getElementById('carga-form');
    if (destino && plantilla) destino.addEventListener('change', function () { plantilla.href = plantilla.href.replace(/destino=[^&]*/, 'destino=' + encodeURIComponent(this.value)); });
    if (form) form.addEventListener('submit', function () { var b = document.getElementById('carga-btn'); setTimeout(function () { b.disabled = true; b.textContent = 'Revisando…'; }, 0); });
})();
</script>
</body>
</html>
