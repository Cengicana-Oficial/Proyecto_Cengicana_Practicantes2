<?php
require_once __DIR__ . '/revisar_permisos.php';
require_once __DIR__ . '/instructor_cv_helpers.php';
require_once __DIR__ . '/planificacion_helpers.php';
require_once __DIR__ . '/vendor/autoload.php';
cengi_require_ver_instructores();
if (!cengi_puede_gestionar_instructores()) { http_response_code(403); exit('No tienes permiso para descargar esta plantilla.'); }

$db = conectar();
cengi_pp_asegurar_codigos_instructor($db);
$instructores = $db->query('SELECT id, nombre, correo FROM instructores ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$campos = [];
foreach (cengi_cv_campos() as $seccion => $grupo) foreach ($grupo as $clave => $meta) $campos[$clave] = [$seccion, $meta];
$libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$hoja = $libro->getActiveSheet();
$hoja->setTitle('CV digital');
$encabezados = array_merge(['CODIGO_INSTRUCTOR'], array_map('strtoupper', array_keys($campos)));
foreach ($encabezados as $i => $texto) {
    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
    $hoja->setCellValueExplicit($col . '1', $texto, 's');
    $hoja->getColumnDimension($col)->setWidth($i < 2 ? 26 : 32);
    $hoja->getStyle($col . '2:' . $col . '501')->getNumberFormat()->setFormatCode('@');
    $info = $i === 0 ? 'Código del directorio (ejemplo: INS-001). Consulta la hoja Instructores. Obligatorio para identificar el expediente.'
        : $campos[array_keys($campos)[$i-1]][1][0] . ($campos[array_keys($campos)[$i-1]][1][2] ? '. Obligatorio.' : '. Opcional.');
    $hoja->getComment($col . '1')->getText()->createTextRun($info);
}
$ultima = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($encabezados));
$hoja->freezePane('C2');
$hoja->setAutoFilter('A1:' . $ultima . '501');
$hoja->getStyle('A2:' . $ultima . '501')->getAlignment()->setWrapText(true);

$guia = $libro->createSheet(); $guia->setTitle('Instrucciones');
$instrucciones = [
    ['PLANTILLA DE CV DIGITAL', 'Una fila por instructor; hasta 500 filas preparadas.'],
    ['Identificación', 'Copia CODIGO_INSTRUCTOR de la hoja Instructores; no uses el número de fila ni inventes códigos.'],
    ['Campos obligatorios', 'CODIGO_INSTRUCTOR, NOMBRE, IDENTIDAD, CORREO, TELEFONO, TITULO, INSTITUCION_EGRESO y EXPERIENCIA.'],
    ['Documentos y teléfonos', 'Escribe como texto para conservar ceros iniciales y signos.'],
    ['Experiencia y listas', 'Enumera los elementos dentro de una misma celda. Usa Alt + Enter para separar líneas.'],
    ['Años de docencia', 'Número entre 0 y 80; admite decimales.'],
    ['LinkedIn', 'Opcional. Enlace del perfil, por ejemplo https://www.linkedin.com/in/usuario (también se acepta sin https://). Solo enlaces de linkedin.com. Las plantillas anteriores sin esta columna se siguen aceptando.'],
    ['Cursos a impartir', 'No se incluyen: la plataforma los obtiene de las asignaciones de cursos y módulos.'],
    ['Archivos adjuntos', 'El Excel contiene información del CV, no adjuntos PDF ni imágenes.'],
    ['Uso de la plantilla', 'En Instructores, abre Carga masiva de CV digital, sube el Excel, revisa y confirma. Los CV existentes se omiten. Los nuevos quedan pendientes de validación.'],
    ['Conservar estructura', 'No cambies los encabezados de CV digital. No repitas el mismo código en varias filas.'],
    ['CAMPO', 'DESCRIPCIÓN / REGLAS'],
];
foreach ($campos as $clave => [$seccion, $meta]) $instrucciones[] = [strtoupper($clave), $seccion . ': ' . $meta[0] . '. ' . ($meta[2] ? 'Obligatorio' : 'Opcional') . '; máximo ' . $meta[1] . ' caracteres.'];
$guia->fromArray($instrucciones, null, 'A1');
$guia->getColumnDimension('A')->setWidth(30);$guia->getColumnDimension('B')->setWidth(110);
$guia->getStyle('A1:B' . count($instrucciones))->getAlignment()->setWrapText(true);
for ($i=1;$i<=count($instrucciones);$i++) $guia->getRowDimension($i)->setRowHeight(32);
$catalogo = $libro->createSheet(); $catalogo->setTitle('Instructores');
$catalogo->fromArray([['CODIGO_INSTRUCTOR', 'NOMBRE', 'CORREO']], null, 'A1');
foreach ($instructores as $i => $inst) foreach ([cengi_pp_codigo_instructor((int)$inst['id']), $inst['nombre'], $inst['correo']] as $j => $valor) {
    $catalogo->setCellValueExplicit(chr(65+$j) . ($i+2), (string)$valor, 's');
}
$catalogo->getColumnDimension('A')->setWidth(25);$catalogo->getColumnDimension('B')->setWidth(45);$catalogo->getColumnDimension('C')->setWidth(45);
foreach ($libro->getAllSheets() as $sheet) {
    $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->applyFromArray(['font'=>['bold'=>true,'color'=>['rgb'=>'FFFFFF']], 'fill'=>['fillType'=>'solid','startColor'=>['rgb'=>'73BC25']]]);
    $sheet->getRowDimension(1)->setRowHeight(30);
    $sheet->freezePane($sheet === $hoja ? 'C2' : 'A2');
}
$libro->setActiveSheetIndex(0);
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="plantilla_cv_digital_instructores.xlsx"');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save('php://output');
$libro->disconnectWorksheets();
