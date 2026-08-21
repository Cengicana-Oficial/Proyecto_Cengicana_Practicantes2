<?php
/**
 * Helpers compartidos para las exportaciones de cengicursos (PDF y Excel).
 *
 * Centraliza aqui lo que antes estaba duplicado casi al byte entre
 * exportarparticipantes.php (prefijo cengi_pdf_*) y
 * exportardashboardingenio.php (prefijo cengi_dbi_pdf_*): la codificacion de
 * texto para el PDF armado a mano, el recorte de celdas y el ensamblado final
 * del archivo PDF; y agrega un helper nuevo para generar un libro de Excel
 * real (via la libreria PHPExcel ya vendorizada en classes/PHPExcel.php,
 * el mismo motor que usan exportarcursos.php y exportaringenios.php) en vez
 * de un CSV de texto plano.
 */

/**
 * Convierte un texto UTF-8 a Windows-1252 (WinAnsiEncoding) y escapa los
 * caracteres especiales de la sintaxis de cadenas de PDF: \ ( )
 */
function cengi_export_pdf_codificar($texto)
{
    $texto = (string) $texto;
    if (function_exists('mb_convert_encoding')) {
        $texto = mb_convert_encoding($texto, 'Windows-1252', 'UTF-8');
    } elseif (function_exists('iconv')) {
        $convertido = iconv('UTF-8', 'Windows-1252//TRANSLIT', $texto);
        $texto = $convertido === false ? $texto : $convertido;
    }
    $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $texto);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto);
}

/**
 * Recorta un texto UTF-8 a un maximo de caracteres visibles, agregando "..."
 * cuando se recorta. Usa mb_* / iconv_* segun lo que este disponible.
 */
function cengi_export_pdf_recortar($texto, $maximo)
{
    $texto = trim((string) $texto);
    if (function_exists('mb_strlen')) {
        $longitud = mb_strlen($texto, 'UTF-8');
        $recorte = function ($valor, $limite) {
            return mb_substr($valor, 0, $limite, 'UTF-8');
        };
    } elseif (function_exists('iconv_strlen')) {
        $longitud = iconv_strlen($texto, 'UTF-8');
        $recorte = function ($valor, $limite) {
            return iconv_substr($valor, 0, $limite, 'UTF-8');
        };
    } else {
        $longitud = strlen($texto);
        $recorte = function ($valor, $limite) {
            return substr($valor, 0, $limite);
        };
    }
    return $longitud <= $maximo ? $texto : rtrim($recorte($texto, $maximo - 1)) . '...';
}

/**
 * Genera el operador de texto (BT ... Tj ET) para una pagina de PDF armada a
 * mano. $negrita = true usa la fuente F2 (Helvetica-Bold), false usa F1
 * (Helvetica); ambas registradas por cengi_export_pdf_crear().
 */
function cengi_export_pdf_texto($x, $y, $texto, $tamano = 8, $negrita = false)
{
    return sprintf("BT /F%d %.2F Tf %.2F %.2F Td (%s) Tj ET\n",
        $negrita ? 2 : 1, $tamano, $x, $y, cengi_export_pdf_codificar($texto));
}

/**
 * Ensambla un PDF (1.4) valido a partir de los content streams de cada
 * pagina (ya generados con cengi_export_pdf_texto() y operadores de dibujo
 * planos). Todas las paginas comparten las mismas dos fuentes estandar
 * (Helvetica / Helvetica-Bold), por lo que no se embebe ningun font file.
 *
 * Nota: cada content stream debe terminar con un salto de linea antes de
 * "endstream" (segun la seccion 7.3.8 del PDF spec, aunque la mayoria de
 * lectores lo toleran igual); esta funcion se encarga de agregarlo si falta.
 */
function cengi_export_pdf_crear(array $contenidos)
{
    $objetos = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
    ];
    $referencias = [];
    foreach ($contenidos as $i => $contenido) {
        if (substr($contenido, -1) !== "\n") {
            $contenido .= "\n";
        }
        $pagina = 5 + $i * 2;
        $flujo = $pagina + 1;
        $referencias[] = $pagina . ' 0 R';
        $objetos[$pagina] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 841.89 595.28] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $flujo . ' 0 R >>';
        $objetos[$flujo] = '<< /Length ' . strlen($contenido) . " >>\nstream\n" . $contenido . "endstream";
    }
    $objetos[2] = '<< /Type /Pages /Kids [' . implode(' ', $referencias) . '] /Count ' . count($referencias) . ' >>';
    ksort($objetos);

    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [0];
    foreach ($objetos as $numero => $objeto) {
        $offsets[$numero] = strlen($pdf);
        $pdf .= $numero . " 0 obj\n" . $objeto . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $cantidad = max(array_keys($objetos)) + 1;
    $pdf .= "xref\n0 $cantidad\n0000000000 65535 f \n";
    for ($i = 1; $i < $cantidad; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    return $pdf . "trailer\n<< /Size $cantidad /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
}

/**
 * Envia un PDF ya generado (bytes crudos) al navegador con los headers
 * correctos y termina la ejecucion. Se asegura de limpiar cualquier buffer
 * de salida pendiente antes de mandar los headers, para que un warning/notice
 * accidental de PHP no rompa el binario ni el Content-Length.
 */
function cengi_export_enviar_pdf($pdfBytes, $nombreArchivo, $adjunto = true)
{
    $pdfBytes = (string) $pdfBytes;
    if (strncmp($pdfBytes, '%PDF-', 5) !== 0) {
        throw new RuntimeException('No se puede descargar un contenido que no sea un PDF valido.');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    // Evita que una configuracion global de PHP/Apache comprima, recodifique o
    // conserve un Content-Type anterior. Cualquiera de esos casos puede hacer
    // que el navegador muestre los objetos internos (%PDF, xref, stream, etc.)
    // como texto en vez de tratar la respuesta como un archivo descargable.
    if (function_exists('header_remove')) {
        header_remove('Content-Type');
        header_remove('Content-Disposition');
        header_remove('Content-Length');
        header_remove('Content-Encoding');
    }
    @ini_set('zlib.output_compression', '0');

    $nombreArchivo = cengi_export_nombre_archivo($nombreArchivo, 'reporte.pdf');
    $nombreAscii = function_exists('iconv')
        ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombreArchivo)
        : $nombreArchivo;
    $nombreAscii = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $nombreAscii);
    if ($nombreAscii === '') {
        $nombreAscii = 'reporte.pdf';
    }

    http_response_code(200);
    header('Content-Type: application/pdf', true);
    $disposicion = $adjunto ? 'attachment' : 'inline';
    header("Content-Disposition: {$disposicion}; filename=\"{$nombreAscii}\"; filename*=UTF-8''" . rawurlencode($nombreArchivo), true);
    header('Content-Transfer-Encoding: binary', true);
    header('Content-Length: ' . strlen($pdfBytes));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo $pdfBytes;
    exit;
}

/**
 * Normaliza un nombre usado en Content-Disposition y bloquea saltos de linea
 * o separadores de ruta que podrian romper los headers de descarga.
 */
function cengi_export_nombre_archivo($nombreArchivo, $predeterminado)
{
    $nombreArchivo = str_replace(["\r", "\n", '\\', '/'], ['', '', '_', '_'], trim((string) $nombreArchivo));
    $nombreArchivo = trim($nombreArchivo, " .\t\n\r\0\x0B");
    return $nombreArchivo !== '' ? $nombreArchivo : $predeterminado;
}

/**
 * Genera un libro de Excel real (formato Excel5 / .xls, el mismo que usan
 * exportarcursos.php y exportaringenios.php via PHPExcel) a partir de un
 * arreglo de encabezados y filas, y lo envia al navegador. Reemplaza el
 * antiguo "CSV" (texto separado por comas via fputcsv) que el navegador
 * mostraba como texto plano en vez de descargar un archivo bien formateado.
 *
 * @param string[] $encabezados Titulos de columna (fila 1, en negrita).
 * @param array<int, array<int, scalar>> $filas Cada fila es un arreglo indexado 0..n alineado con $encabezados.
 * @param string $tituloHoja Nombre de la hoja de calculo (Excel limita a 31 caracteres).
 * @param string $nombreArchivoBase Nombre de archivo sin extension.
 * @param int[] $anchosColumnas Ancho opcional por columna (indice 0..n); si falta, se usa un ancho por defecto.
 */
function cengi_export_enviar_excel(array $encabezados, array $filas, $tituloHoja, $nombreArchivoBase, array $anchosColumnas = [])
{
    // La libreria PHPExcel vendorizada es de 2014 y, aunque ya no tiene errores
    // de sintaxis bajo PHP 8 (ver classes/PHPExcel/*), sigue generando muchos
    // avisos "Deprecated" (creacion de propiedades dinamicas, conversion
    // implicita float->int, etc.) bajo PHP 8.1+. Con display_errors=On (el
    // default de esta imagen de Docker) esos avisos se imprimen como texto
    // plano ANTES de los header(), lo que rompe el Content-Type y hace que el
    // navegador muestre el archivo binario como si fuera texto (el mismo bug
    // reportado originalmente). Se baja error_reporting solo durante la
    // generacion del archivo (sin ocultar errores fatales) y ademas se
    // envuelve todo en un buffer de salida que se descarta antes de mandar
    // los headers, como doble seguro.
    error_reporting(E_ERROR | E_PARSE | E_COMPILE_ERROR | E_CORE_ERROR);
    set_error_handler(static function () {
        return true;
    }, E_DEPRECATED | E_USER_DEPRECATED);
    ob_start();

    require_once __DIR__ . '/PHPExcel.php';
    require_once __DIR__ . '/PHPExcel/Cell.php';
    require_once __DIR__ . '/PHPExcel/Calculation.php';

    $objPHPExcel = new PHPExcel();
    $objPHPExcel->getProperties()
        ->setCreator('CENGICANA')
        ->setLastModifiedBy('CENGICANA')
        ->setTitle($tituloHoja)
        ->setSubject($tituloHoja)
        ->setDescription('Reporte generado por cengicursos')
        ->setCategory('Reporte');

    $hoja = $objPHPExcel->setActiveSheetIndex(0);
    $ultimaColumna = PHPExcel_Cell::stringFromColumnIndex(max(0, count($encabezados) - 1));

    foreach ($encabezados as $indice => $titulo) {
        $columna = PHPExcel_Cell::stringFromColumnIndex($indice);
        $hoja->setCellValue($columna . '1', $titulo);
        $hoja->getColumnDimension($columna)->setWidth($anchosColumnas[$indice] ?? 22);
    }

    $boldArray = ['font' => ['bold' => true], 'alignment' => ['horizontal' => PHPExcel_Style_Alignment::HORIZONTAL_CENTER]];
    $hoja->getStyle('A1:' . $ultimaColumna . '1')->applyFromArray($boldArray);
    $hoja->freezePane('A2');

    $fila = 2;
    foreach ($filas as $datosFila) {
        foreach (array_values($datosFila) as $indice => $valor) {
            $columna = PHPExcel_Cell::stringFromColumnIndex($indice);
            $hoja->setCellValueExplicit($columna . $fila, (string) $valor, PHPExcel_Cell_DataType::TYPE_STRING);
        }
        $fila++;
    }

    if ($fila > 2) {
        $styleArray = [
            'font' => ['name' => 'Arial', 'size' => 10],
            'borders' => ['allborders' => ['style' => PHPExcel_Style_Border::BORDER_THIN, 'color' => ['argb' => 'FFCCCCCC']]],
        ];
        $hoja->getStyle('A1:' . $ultimaColumna . ($fila - 1))->applyFromArray($styleArray);
    }

    $hoja->setTitle(mb_substr($tituloHoja, 0, 31, 'UTF-8'));
    $objPHPExcel->setActiveSheetIndex(0);

    $objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel5');
    $objWriter->save('php://output');
    $excelBytes = ob_get_clean();

    // La funcion siempre termina la peticion con exit. No se restaura aqui el
    // handler porque PHPExcel conserva objetos estaticos y sus destructores se
    // ejecutan durante el shutdown; en PHP 8.2 esos destructores vuelven a
    // emitir avisos Deprecated despues del binario y corrompen el .xls.

    if (!is_string($excelBytes)
        || strncmp($excelBytes, "\xD0\xCF\x11\xE0", 4) !== 0
        || strlen($excelBytes) % 512 !== 0) {
        throw new RuntimeException('No se pudo generar un archivo de Excel valido.');
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (function_exists('header_remove')) {
        header_remove('Content-Type');
        header_remove('Content-Disposition');
        header_remove('Content-Length');
        header_remove('Content-Encoding');
    }
    @ini_set('zlib.output_compression', '0');

    $nombreArchivo = cengi_export_nombre_archivo($nombreArchivoBase . '.xls', 'reporte.xls');
    $nombreAscii = function_exists('iconv')
        ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombreArchivo)
        : $nombreArchivo;
    $nombreAscii = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string) $nombreAscii);
    if ($nombreAscii === '') {
        $nombreAscii = 'reporte.xls';
    }

    http_response_code(200);
    header('Content-Type: application/vnd.ms-excel', true);
    header("Content-Disposition: attachment; filename=\"{$nombreAscii}\"; filename*=UTF-8''" . rawurlencode($nombreArchivo), true);
    header('Content-Transfer-Encoding: binary', true);
    header('Content-Length: ' . strlen($excelBytes), true);
    header('Cache-Control: no-store, no-cache, must-revalidate', true);
    header('Pragma: no-cache', true);
    header('X-Content-Type-Options: nosniff', true);
    echo $excelBytes;
    exit;
}
