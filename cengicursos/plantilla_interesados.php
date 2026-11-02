<?php
require_once __DIR__.'/revisar_permisos.php';require_once __DIR__.'/carga_interesados_helpers.php';
cengi_require_carga_participantes('inscripciones.php?vista=interesados');
$headers=array_map(static function($n){return $n[0];},cengi_ci_columnas());
if(($_GET['formato']??'')==='csv'){
    header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="plantilla-interesados-flexible.csv"');$out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,array_values($headers),';');fclose($out);exit;
}
require_once __DIR__.'/vendor/autoload.php';
$db=conectar();$book=new \PhpOffice\PhpSpreadsheet\Spreadsheet();$sheet=$book->getActiveSheet();$sheet->setTitle('Interesados');$sheet->fromArray(array_values($headers),null,'A1');
$book->getProperties()->setCreator('CENGICAÑA')->setTitle('Carga flexible de interesados');
$sheet->getStyle('A1:L1')->getFont()->setBold(true)->getColor()->setRGB('17351B');$sheet->getStyle('A1:L1')->getFill()->setFillType('solid')->getStartColor()->setRGB('A3D300');$sheet->getStyle('A1:L1')->getAlignment()->setWrapText(true);$sheet->getRowDimension(1)->setRowHeight(32);$sheet->freezePane('A2');$sheet->setAutoFilter('A1:L1');
foreach(['A'=>12,'B'=>42,'C'=>26,'D'=>20,'E'=>32,'F'=>24,'G'=>24,'H'=>34,'I'=>24,'J'=>18,'K'=>18,'L'=>18] as $col=>$width){$sheet->getColumnDimension($col)->setWidth($width);$sheet->getStyle($col.'2:'.$col.'501')->getNumberFormat()->setFormatCode('@');}
foreach(['K'=>'"Propio,Ingenio"','L'=>'"Asociado,Externo,Extranjero"'] as $col=>$list){$v=new \PhpOffice\PhpSpreadsheet\Cell\DataValidation();$v->setType('list')->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(true)->setErrorTitle('Valor no válido')->setError('Selecciona una opción de la lista.')->setFormula1($list)->setSqref($col.'2:'.$col.'501');$sheet->setDataValidation($col.'2',$v);}
$courses=$book->createSheet();$courses->setTitle('Cursos disponibles');$courses->fromArray([['ID_CURSO','CURSO','CODIGO_CURSO','INICIO']],null,'A1');$r=2;
foreach($db->query('SELECT id,nombre_cursos,codigo_curso,inicio FROM cursos ORDER BY inicio DESC,nombre_cursos')->fetchAll(PDO::FETCH_NUM) as $row){foreach($row as $i=>$value)$courses->setCellValueExplicit(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i+1).$r,(string)$value,\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);$r++;}
foreach(['A'=>14,'B'=>65,'C'=>25,'D'=>16] as $col=>$width)$courses->getColumnDimension($col)->setWidth($width);$courses->getStyle('A1:D1')->getFont()->setBold(true);$courses->freezePane('A2');
$help=$book->createSheet();$help->setTitle('Instrucciones');$help->fromArray([
 ['CARGA FLEXIBLE DE INTERESADOS'],['Una fila por persona y curso. Puedes mezclar cursos, pagadores y cuotas.'],
 ['ID_CURSO o CURSO','Copia el ID de Cursos disponibles, o usa el nombre exacto/código del curso. Si llenas ambos, deben coincidir.'],
 ['QUIEN_PAGA','Propio o Ingenio. En blanco utiliza el valor común elegido en pantalla.'],
 ['TIPO_CUOTA','Asociado, Externo o Extranjero. En blanco utiliza el valor común elegido en pantalla.'],
 ['Un solo curso','Puedes dejar ID_CURSO y CURSO vacíos y seleccionar el curso común en pantalla.'],
 ['Participantes','Ingenio registrado, nombre, puesto, área, correo válido, grado académico y teléfono son obligatorios.'],
 ['CUI','Opcional. Conservar como texto, igual que el teléfono.'],
 ['Confirmación','Analiza el archivo y revisa errores y duplicados antes de confirmar. Máximo 500 filas y 5 MB.'],
 ['Sin duplicados','La misma persona puede aparecer en cursos diferentes. Se omiten solicitudes existentes y personas ya asignadas al mismo curso.']
 ],null,'A1');$help->getColumnDimension('A')->setWidth(26);$help->getColumnDimension('B')->setWidth(95);$help->getStyle('A1:B10')->getAlignment()->setWrapText(true);$help->getStyle('A1')->getFont()->setBold(true)->setSize(16);for($i=2;$i<=10;$i++)$help->getRowDimension($i)->setRowHeight(32);
$book->setActiveSheetIndex(0);header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="plantilla-interesados-flexible.xlsx"');(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save('php://output');exit;
