<?php
/**
 * Plantilla Excel para la carga masiva del banco de preguntas del aula (Gestión del aula → Banco de preguntas).
 * Hoja "Preguntas": TIPO, MODULO, TEMA, ENUNCIADO, OPCION_A … OPCION_J, CORRECTA, CRITERIO.
 * TIPO y MODULO tienen lista desplegable; la hoja "Instrucciones" explica cada columna.
 * Lectura: aula_banco_filas() en aula_banco_helpers.php.
 */
require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/aula_helpers.php';
require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$db = conectar();
$cid = (int) ($_GET['curso'] ?? 0);
$curso = aula_teacher_course($db, $cid);
$modulos = aula_rows($db, 'SELECT id,nombre FROM curso_modulos WHERE curso_id=? ORDER BY orden,id', [$cid]);
$conModulos = $modulos && aula_banco_ready($db);

$letras = str_split(AULA_LETRAS);
$encabezados = array_merge(['TIPO', 'MODULO', 'TEMA', 'ENUNCIADO'], array_map(static function ($l) { return 'OPCION_' . $l; }, $letras), ['CORRECTA', 'CRITERIO', 'PUNTOS']);
$mod1 = $modulos[0]['nombre'] ?? ''; $mod2 = $modulos[1]['nombre'] ?? $mod1;
$ejemplos = [
    ['Una correcta', $mod1, 'Conceptos básicos', '¿Cuál es la capital de Guatemala? (ejemplo, borrar)', 'Quetzaltenango', 'Ciudad de Guatemala', 'Escuintla', '', '', '', '', '', '', '', 'B', '', '1'],
    ['Varias correctas', $mod2, 'Seguridad', 'Selecciona los equipos de protección personal (ejemplo, borrar)', 'Casco', 'Celular', 'Guantes', 'Audífonos de música', '', '', '', '', '', '', 'A,C', '', '2'],
    ['Abierta', $mod1, 'Aplicación', 'Describe un caso de tu trabajo donde aplicarías lo aprendido (ejemplo, borrar)', '', '', '', '', '', '', '', '', '', '', '', 'Relaciona el tema con un caso real y concreto.', '5'],
];
if (!$conModulos) foreach ($ejemplos as &$e) $e[1] = '';
unset($e);

$libro = new Spreadsheet();
$hoja = $libro->getActiveSheet();
$hoja->setTitle('Preguntas');
$hoja->fromArray($encabezados, null, 'A1');
$hoja->fromArray($ejemplos, null, 'A2');
$ultima = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($encabezados));
$hoja->getStyle('A1:' . $ultima . '1')->applyFromArray([
    'font' => ['bold' => true, 'color' => ['rgb' => '1F5632']],
    'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'EEF7E3']],
    'borders' => ['bottom' => ['borderStyle' => 'medium', 'color' => ['rgb' => '73BC25']]],
]);
$hoja->getStyle('A2:' . $ultima . '4')->getFont()->getColor()->setRGB('5B6459');
$hoja->getStyle('A1:' . $ultima . '500')->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
$hoja->getRowDimension(1)->setRowHeight(24);
$hoja->freezePane('E2');
$anchos = ['A' => 17, 'B' => 24, 'C' => 20, 'D' => 50];
foreach ($anchos as $c => $w) $hoja->getColumnDimension($c)->setWidth($w);
for ($i = 5; $i <= 4 + count($letras); $i++) $hoja->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i))->setWidth(20);
$colCorrecta = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(5 + count($letras));
$colCriterio = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(6 + count($letras));
$hoja->getColumnDimension($colCorrecta)->setWidth(12);
$hoja->getColumnDimension($colCriterio)->setWidth(40);
$hoja->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(7 + count($letras)))->setWidth(10);
$hoja->getStyle($colCorrecta . '2:' . $colCorrecta . '500')->getNumberFormat()->setFormatCode('@');

// Listas desplegables: tipo y módulos del curso (estos van en una hoja auxiliar).
$lista = static function ($hoja, $rango, $formula, $titulo) {
    $v = $hoja->getCell(explode(':', $rango)[0])->getDataValidation();
    $v->setType(DataValidation::TYPE_LIST)->setErrorStyle(DataValidation::STYLE_STOP)->setAllowBlank(true)
        ->setShowDropDown(true)->setShowErrorMessage(true)->setErrorTitle($titulo)->setError('Elige un valor de la lista.')->setFormula1($formula);
    $hoja->setDataValidation($rango, $v);
};
$lista($hoja, 'A2:A500', '"Una correcta,Varias correctas,Abierta,Tarea"', 'Tipo de pregunta');
if ($conModulos) {
    $aux = $libro->createSheet();
    $aux->setTitle('Modulos');
    $aux->setCellValue('A1', 'MÓDULOS DEL CURSO');
    foreach ($modulos as $i => $m) $aux->setCellValueExplicit('A' . ($i + 2), $m['nombre'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $aux->getColumnDimension('A')->setWidth(50);
    $aux->getStyle('A1')->getFont()->setBold(true);
    $lista($hoja, 'B2:B500', 'Modulos!$A$2:$A$' . (count($modulos) + 1), 'Módulo');
}

$inst = $libro->createSheet();
$inst->setTitle('Instrucciones');
$filas = [
    ['Carga masiva del banco de preguntas', ''],
    ['Curso', $curso['nombre_cursos']],
    ['', ''],
    ['Columna', 'Qué escribir'],
    ['TIPO', 'Una correcta · Varias correctas · Abierta · Tarea. Si se deja vacío: con opciones se toma como selección (una o varias según CORRECTA); sin opciones, como abierta.'],
    ['MODULO', $conModulos ? 'Nombre del módulo tal como aparece en la hoja Modulos (o su número de orden: 1, 2…). Vacío = pregunta de todo el curso.' : 'Este curso no tiene módulos: deja la columna vacía.'],
    ['TEMA', 'Opcional. Sirve para ordenar y buscar en el banco.'],
    ['ENUNCIADO', 'Obligatorio. Texto de la pregunta.'],
    ['OPCION_A … OPCION_J', 'Solo para preguntas de selección: de 2 a 10 opciones. Las celdas vacías se omiten.'],
    ['CORRECTA', 'Letra o número de la opción correcta (B o 2). Para «Varias correctas» sepáralas con coma: A,C (mínimo dos). Calificación parcial: si hay 2 correctas y el estudiante marca 1, obtiene la mitad del punto; cada opción incorrecta marcada resta.'],
    ['CRITERIO', 'Opcional. Guía de revisión para preguntas abiertas y tareas.'],
    ['PUNTOS', 'Opcional. Valor de la pregunta (0.01 a 1000; vacío = 1). La nota de la evaluación se calcula sobre 100 según el total de puntos. En cada evaluación se puede ajustar.'],
    ['', ''],
    ['Importante', 'Borra las filas de ejemplo antes de cargar. Si alguna fila tiene errores no se guarda ninguna y el sistema indica qué filas corregir. Máximo 1000 preguntas por archivo.'],
];
$inst->fromArray($filas, null, 'A1');
$inst->getColumnDimension('A')->setWidth(24);
$inst->getColumnDimension('B')->setWidth(100);
$inst->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB('1F5632');
$inst->getStyle('A4:B4')->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => '1F5632']], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'EEF7E3']]]);
$inst->getStyle('A5:A14')->getFont()->setBold(true);
$inst->getStyle('B1:B14')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
$libro->setActiveSheetIndex(0);

$nombre = 'banco_preguntas_' . preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $curso['nombre_cursos']) ?: 'curso');
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . trim(substr($nombre, 0, 80), '_') . '.xlsx"');
header('Cache-Control: no-store');
(new Xlsx($libro))->save('php://output');
$libro->disconnectWorksheets();
