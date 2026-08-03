<?php
require_once("revisar_permisos.php");
cengi_require_ver_participantes();
require_once("conexion.php");
$db = conectar();
$cursoId = (int) ($_GET['curso_id'] ?? 0);
//include menu
/** Incluye PHPExcel */
require_once dirname(__FILE__) . '/classes/PHPExcel.php';
require_once dirname(__FILE__) . '/classes/PHPExcel/Cell.php';
require_once dirname(__FILE__) . '/classes/PHPExcel/Calculation.php';
/*Extraer datos de MYSQL*/
$condiciones = [];
$params = [];
if ($cursoId > 0) {
    $condiciones[] = 'a.cursos_id = ?';
    $params[] = $cursoId;
}
if (!cengi_ve_todo_por_rol_o_ingenio()) {
    $condiciones[] = 'p.ingenio_id = ?';
    $params[] = cengi_ingenio_id_actual();
}
$where = $condiciones ? 'WHERE ' . implode(' AND ', $condiciones) : '';

	$sql = "
SELECT
    c.nombre_cursos,
    c.jornada_cursos,
    c.dias,
    c.horario,
    p.nombre_participantes,
    i.nombre_ingenios
FROM asignaciones a
INNER JOIN cursos c ON a.cursos_id = c.id
INNER JOIN participantes p ON a.participantes_id = p.id
INNER JOIN ingenios i ON p.ingenio_id = i.id
$where
ORDER BY i.id, c.id
";

$stmt = $db->prepare($sql);
$stmt->execute($params);

// Crear nuevo objeto PHPExcel
$objPHPExcel = new PHPExcel();
//propiedades del documento
$objPHPExcel->getProperties()->setCreator("Monica Galiego de Brán")
							 ->setLastModifiedBy("Monica Galiego de Brán")
							 ->setTitle("Office 2010 XLSX Documento de prueba")
							 ->setSubject("Office 2010 XLSX Documento de prueba")
							 ->setDescription("Reporte")
							 ->setKeywords("office 2010 openxml php")
							 ->setCategory("Reporte");

// Combino las celdas desde A1 hasta E1
$objPHPExcel->setActiveSheetIndex(0)->mergeCells('A1:E1');
 
$objPHPExcel->setActiveSheetIndex(0)
            ->setCellValue('A1', 'Reporte Participantes por Ingenio')
            ->setCellValue('A2', 'Curso')
            ->setCellValue('B2', 'dias')
            ->setCellValue('C2', 'Horario')
			->setCellValue('D2', 'Participante')
			->setCellValue('E2', 'Ingenio');
			
// Fuente de la primera fila en negrita
$boldArray = array('font' => array('bold' => true,),'alignment' => array('horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_CENTER));
 
$objPHPExcel->getActiveSheet()->getStyle('A1:E2')->applyFromArray($boldArray);

//Ancho de las columnas
$objPHPExcel->getActiveSheet()->getColumnDimension('A')->setWidth(20);	
$objPHPExcel->getActiveSheet()->getColumnDimension('B')->setWidth(40);	
$objPHPExcel->getActiveSheet()->getColumnDimension('C')->setWidth(25);	
$objPHPExcel->getActiveSheet()->getColumnDimension('D')->setWidth(25);	
$objPHPExcel->getActiveSheet()->getColumnDimension('E')->setWidth(25);



	$cel=3;//Numero de fila donde empezara a crear  el reporte

	while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
		
		$cursos=$row['nombre_cursos'];
		$dias=$row['dias'];
		$horario=$row['horario'];
		$participante=$row['nombre_participantes'];
		$ingenio=$row['nombre_ingenios'];
		
		
			$a="A".$cel;
			$b="B".$cel;
			$c="C".$cel;
			$d="D".$cel;
			$e="E".$cel;
			// Agregar datos
			$objPHPExcel->setActiveSheetIndex(0)
           	->setCellValue($a, $cursos)
            ->setCellValue($b, $dias)
            ->setCellValue($c, $horario)
            ->setCellValue($d, $participante)
			->setCellValue($e, $ingenio);
			
	$cel+=1;
	}
 
/*Fin extracion de datos MYSQL*/

//formato celdas
$rango="A2:$e";
$styleArray = array('font' => array( 'name' => 'Arial','size' => 10),
'borders'=>array('allborders'=>array('style'=> PHPExcel_Style_Border::BORDER_THIN,'color'=>array('argb' => 'FFF')))
);
$objPHPExcel->getActiveSheet()->getStyle($rango)->applyFromArray($styleArray);

// Cambiar el nombre de hoja de cálculo
$objPHPExcel->getActiveSheet()->setTitle('Participantes por Cursos');
 
 
// Establecer índice de hoja activa a la primera hoja , por lo que Excel abre esto como la primera hoja
$objPHPExcel->setActiveSheetIndex(0);

// Redirigir la salida al navegador web de un cliente ( Excel5 )
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment;filename="participantes_curso.xls"');
header('Cache-Control: max-age=0');
// Si usted está sirviendo a IE 9 , a continuación, puede ser necesaria la siguiente
header('Cache-Control: max-age=1');
 
// Si usted está sirviendo a IE a través de SSL , a continuación, puede ser necesaria la siguiente
header ('Expires: Mon, 26 Jul 1997 05:00:00 GMT'); // Date in the past
header ('Last-Modified: '.gmdate('D, d M Y H:i:s').' GMT'); // always modified
header ('Cache-Control: cache, must-revalidate'); // HTTP/1.1
header ('Pragma: public'); // HTTP/1.0
 
$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel5');
$objWriter->save('php://output');
exit;
?>
