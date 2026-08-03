<?php
require_once "revisar_permisos.php";
cengi_require_admin();
require_once "conexion.php";
$mysqli = conectar();
//include menu
/** Incluye PHPExcel */
require_once dirname(__FILE__) . '/classes/PHPExcel.php';
require_once dirname(__FILE__) . '/classes/PHPExcel/Cell.php';
require_once dirname(__FILE__) . '/classes/PHPExcel/Calculation.php';
/*Extraer datos de MYSQL*/
$sql = "SELECT
	  i.nombre_ingenios,
	  i.id,
	  p.ingenio_id,
	  p.cui_participantes,
	  p.nombre_participantes,
	  p.puesto_participantes,
	  p.area_participantes,
	  p.estado_participantes,
	  u.ingenio_id,
	  u.nombre,
      GROUP_CONCAT(DISTINCT u.nombre ORDER BY u.nombre ASC)
	 FROM cengi_cursos.participantes p
	 INNER JOIN cengi_cursos.ingenios i ON (p.ingenio_id=i.id)
	 INNER JOIN cengi_cursos.users u ON (p.ingenio_id=u.ingenio_id)
	 WHERE p.estado_participantes = 1";
//GROUP_CONCAT(DISTINCT u.nombre ORDER BY u.nombre ASC)
//echo "$sql";
mysqli_query($mysqli, "SET GLOBAL sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''))");
$resultado = mysqli_query($mysqli, $sql) or die("Error en la selección de datos" . mysqli_error($mysqli));
//$row=$resultado->fetch_array(MYSQLI_ASSOC);

// Crear nuevo objeto PHPExcel
$objPHPExcel = new PHPExcel();
//propiedades del documento
$objPHPExcel->getProperties()->setCreator("Monica Galiego de Brán")
    ->setLastModifiedBy("Monica Galiego de Brán")
    ->setTitle("Office 2010 XLSX Reporte")
    ->setSubject("Office 2010 XLSX Reporte")
    ->setDescription("Reporte.")
    ->setKeywords("office 2010 openxml php")
    ->setCategory("Reporte");

// Combino las celdas desde A1 hasta F1
$objPHPExcel->setActiveSheetIndex(0)->mergeCells('A1:F1');

$objPHPExcel->setActiveSheetIndex(0)
    ->setCellValue('A1', 'Reporte Participantes por Ingenio')
    ->setCellValue('A2', 'Ingenio')
    ->setCellValue('B2', 'Participante')
    ->setCellValue('C2', 'CUI')
    ->setCellValue('D2', 'Puesto')
    ->setCellValue('E2', 'Area')
    ->setCellValue('F2', 'Delegado');

// Fuente de la primera fila en negrita
$boldArray = ['font' => ['bold' => true], 'alignment' => ['horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_CENTER]];

$objPHPExcel->getActiveSheet()->getStyle('A1:F2')->applyFromArray($boldArray);

//Ancho de las columnas
$objPHPExcel->getActiveSheet()->getColumnDimension('A')->setWidth(20);
$objPHPExcel->getActiveSheet()->getColumnDimension('B')->setWidth(40);
$objPHPExcel->getActiveSheet()->getColumnDimension('C')->setWidth(25);
$objPHPExcel->getActiveSheet()->getColumnDimension('D')->setWidth(25);
$objPHPExcel->getActiveSheet()->getColumnDimension('E')->setWidth(25);
$objPHPExcel->getActiveSheet()->getColumnDimension('F')->setWidth(40);

$cel = 3; //Numero de fila donde empezara a crear  el reporte
while ($row = mysqli_fetch_array($resultado)) {

    $ingenio      = $row['nombre_ingenios'];
    $cui          = $row['cui_participantes'];
    $participante = $row['nombre_participantes'];
    $puesto       = $row['puesto_participantes'];
    $area         = $row['area_participantes'];
    $delegado     = $row['nombre'];

    $a = "A" . $cel;
    $b = "B" . $cel;
    $c = "C" . $cel;
    $d = "D" . $cel;
    $e = "E" . $cel;
    $f = "F" . $cel;
    // Agregar datos
    $objPHPExcel->setActiveSheetIndex(0)
        ->setCellValue($a, $ingenio)
        ->setCellValue($b, $participante)
        ->setCellValue($c, $cui)
        ->setCellValue($d, $puesto)
        ->setCellValue($e, $area)
        ->setCellValue($f, $delegado);

    $cel += 1;
}
/*Fin extracion de datos MYSQL*/

//formato celdas
$rango      = "A2:F2";
$styleArray = ['font' => ['name' => 'Arial', 'size' => 10],
    'borders'             => ['allborders' => ['style' => PHPExcel_Style_Border::BORDER_THIN, 'color' => ['argb' => 'FFF']]],
];
$objPHPExcel->getActiveSheet()->getStyle($rango)->applyFromArray($styleArray);

// Cambiar el nombre de hoja de cálculo
$objPHPExcel->getActiveSheet()->setTitle('Reporte por Ingenio');

// Establecer índice de hoja activa a la primera hoja , por lo que Excel abre esto como la primera hoja
$objPHPExcel->setActiveSheetIndex(0);

// Redirigir la salida al navegador web de un cliente ( Excel5 )
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment;filename="reporte.xls"');
header('Cache-Control: max-age=0');
// Si usted está sirviendo a IE 9 , a continuación, puede ser necesaria la siguiente
header('Cache-Control: max-age=1');

// Si usted está sirviendo a IE a través de SSL , a continuación, puede ser necesaria la siguiente
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT'); // always modified
header('Cache-Control: cache, must-revalidate'); // HTTP/1.1
header('Pragma: public'); // HTTP/1.0

$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel5');
$objWriter->save('php://output');
exit;
