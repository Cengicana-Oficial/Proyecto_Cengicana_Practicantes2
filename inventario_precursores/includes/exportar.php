<?php
declare(strict_types=1);
// Exportacion de informes a Excel (.xlsx) y PDF. Usa PhpSpreadsheet y Dompdf de
// cengicursos/vendor, igual que los documentos PDF de CengiLabs.
//
// Un informe se describe una sola vez y sirve para ambos formatos:
//   'archivo'    nombre del archivo sin extension
//   'titulo'     titulo del documento;  'subtitulo' linea secundaria
//   'emisor'     nombre de quien lo emite
//   'meta'       [[etiqueta, valor], ...]
//   'bloques'    [['titulo' => ?string, 'vacio' => string,
//                  'columnas' => [[etiqueta, tipo, solo?], ...]   solo: true = solo Excel, 'pdf' = solo PDF
//                  'filas' => [[valor, ...], ...],
//                  'anchos_pdf' => [% del ancho por cada columna visible en el PDF], opcional
//                  'ancho_tabla_pdf' => % del ancho de la hoja, opcional], ...]
//                tipo: 't' texto, 'n' cantidad en milesimas (int), 'f' fecha Y-m-d,
//                      'nu' [milesimas, unidad] -> "0.75 L" (solo PDF)
//   'firmas'     [['rol','nombre','cargo','fecha','imagen'(data:image/png|null)], ...]
//   'compacto'   true: PDF con menos espacios, para que el informe quepa en una hoja

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsFecha;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

const INV_LOGO_INFORME = __DIR__ . '/../../login/assets/img/logo-cengicana.png';

/** Muestra las opciones de documento sin ocupar espacio en la cabecera. */
function inv_menu_documentos(array $consulta, bool $puedeExcel, bool $puedeImprimir = false): string
{
    $pdf = $consulta + ['export' => 'pdf'];
    $html = '<button class="btn" type="button" popovertarget="invDocumentos">Documentos <span class="material-symbols-outlined" aria-hidden="true">expand_more</span></button>'
        . '<div id="invDocumentos" class="inv-acc-grupo inv-documentos" popover>'
        . '<a class="btn sm" href="' . e(inv_url($pdf + ['preview' => '1'])) . '" target="_blank" rel="noopener">Ver PDF <small>(nueva pestaña)</small></a>'
        . '<a class="btn sm" href="' . e(inv_url($pdf)) . '">Descargar PDF</a>';
    if ($puedeExcel) {
        $html .= '<a class="btn sm" href="' . e(inv_url($consulta + ['export' => 'excel'])) . '">Descargar Excel</a>';
    }
    if ($puedeImprimir) {
        $html .= '<button class="btn sm" type="button" data-imprimir>Imprimir vale</button>';
    }
    return $html . '</div>';
}

/** Atiende ?export=excel|pdf de una vista de informe; no regresa si exporta. */
function inv_exportar_si_pide(array $informe, bool $puedeExcel): void
{
    $formato = (string) ($_GET['export'] ?? '');
    if ($formato === 'excel' && $puedeExcel) {
        inv_enviar_excel($informe);
    }
    if ($formato === 'pdf') {
        inv_enviar_pdf($informe);
    }
}

function inv_cargar_vendor(): void
{
    $autoload = __DIR__ . '/../../cengicursos/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('El generador de Excel/PDF no está disponible en este ambiente.');
    }
    require_once $autoload;
}

function inv_nombre_archivo(string $nombre, string $ext): string
{
    return (preg_replace('/[^A-Za-z0-9_.-]/', '_', $nombre) ?: 'informe') . '.' . $ext;
}

/** Descarta lo que la vista ya haya escrito y envia el archivo. */
function inv_enviar_archivo(string $bytes, string $archivo, string $tipo, bool $enLinea = false): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $tipo);
    $disposicion = $enLinea ? 'inline' : 'attachment';
    header('Content-Disposition: ' . $disposicion . '; filename="' . $archivo . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

/** Columnas visibles en el formato pedido (los indices se conservan para leer las filas). */
function inv_columnas_formato(array $bloque, bool $excel): array
{
    return array_filter($bloque['columnas'], static function (array $c) use ($excel): bool {
        $solo = $c[2] ?? null;
        return $solo === null || ($excel ? $solo !== 'pdf' : $solo === 'pdf');
    });
}

// ------------------------------------------------------------------ Excel

function inv_enviar_excel(array $informe): void
{
    inv_cargar_vendor();
    $verde = '73BC25';
    $verdeOscuro = '1F5632';
    $libro = new Spreadsheet();
    $libro->getProperties()->setCreator('CengiBodega · CENGICAÑA')->setTitle($informe['titulo']);
    $hoja = $libro->getActiveSheet();
    $hoja->setTitle(mb_substr(preg_replace('#[\\\\/?*\[\]:]#', ' ', $informe['hoja'] ?? 'Informe'), 0, 31));
    $hoja->setShowGridlines(false);
    $ancho = 1;
    foreach ($informe['bloques'] as $b) {
        $ancho = max($ancho, count(inv_columnas_formato($b, true)));
    }
    $ultima = static fn(int $n): string => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($n);
    $texto = static function (string $celda, $valor) use ($hoja): void {
        // Explicito como texto: un valor que empieza con "=" no se vuelve formula.
        $hoja->setCellValueExplicit($celda, (string) $valor, DataType::TYPE_STRING);
    };

    // Encabezado institucional
    if (is_file(INV_LOGO_INFORME)) {
        $logo = new Drawing();
        $logo->setPath(INV_LOGO_INFORME);
        $logo->setHeight(46);
        $logo->setCoordinates('A1');
        $logo->setOffsetX(4);
        $logo->setOffsetY(4);
        $logo->setWorksheet($hoja);
        $hoja->getRowDimension(1)->setRowHeight(42);
    }
    $fila = 2;
    $texto("A{$fila}", $informe['titulo']);
    $hoja->getStyle("A{$fila}")->getFont()->setBold(true)->setSize(14)->getColor()->setRGB($verdeOscuro);
    $fila++;
    if (!empty($informe['subtitulo'])) {
        $texto("A{$fila}", $informe['subtitulo']);
        $hoja->getStyle("A{$fila}")->getFont()->setItalic(true)->getColor()->setRGB('555555');
        $fila++;
    }
    $texto("A{$fila}", 'Emitido el ' . inv_fecha(inv_hoy()) . ' por ' . ($informe['emisor'] ?? ''));
    $hoja->getStyle("A{$fila}")->getFont()->setSize(9)->getColor()->setRGB('777777');
    $fila += 2;

    foreach ($informe['meta'] ?? [] as [$etiqueta, $valor]) {
        $texto("A{$fila}", $etiqueta);
        $texto("B{$fila}", $valor);
        $hoja->getStyle("A{$fila}")->getFont()->setBold(true);
        $fila++;
    }
    if (!empty($informe['meta'])) {
        $fila++;
    }

    $unBloque = count($informe['bloques']) === 1;
    foreach ($informe['bloques'] as $b) {
        $cols = inv_columnas_formato($b, true);
        $n = count($cols);
        if (!empty($b['titulo'])) {
            $texto("A{$fila}", $b['titulo']);
            $hoja->getStyle("A{$fila}")->getFont()->setBold(true)->setSize(12)->getColor()->setRGB($verdeOscuro);
            $fila++;
        }
        $filaEnc = $fila;
        $c = 1;
        foreach ($cols as $col) {
            $texto($ultima($c) . $fila, $col[0]);
            $c++;
        }
        $rangoEnc = "A{$fila}:" . $ultima($n) . $fila;
        $hoja->getStyle($rangoEnc)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $verde]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $hoja->getRowDimension($fila)->setRowHeight(30);
        $fila++;
        $primeraDato = $fila;
        foreach ($b['filas'] as $r) {
            $c = 1;
            foreach ($cols as $i => $col) {
                $celda = $ultima($c) . $fila;
                $v = $r[$i] ?? '';
                if ($col[1] === 'n') {
                    $hoja->setCellValueExplicit($celda, ((int) $v) / 1000, DataType::TYPE_NUMERIC);
                    $hoja->getStyle($celda)->getNumberFormat()->setFormatCode('#,##0.000');
                } elseif ($col[1] === 'f' && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $v)) {
                    $hoja->setCellValue($celda, XlsFecha::PHPToExcel(new DateTime(substr((string) $v, 0, 10))));
                    $hoja->getStyle($celda)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                } else {
                    $texto($celda, $v);
                }
                $c++;
            }
            if (($fila - $primeraDato) % 2 === 1) {
                $hoja->getStyle("A{$fila}:" . $ultima($n) . $fila)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F3F8EC');
            }
            $fila++;
        }
        if (!$b['filas']) {
            $texto("A{$fila}", $b['vacio'] ?? 'Sin datos');
            $hoja->mergeCells("A{$fila}:" . $ultima($n) . $fila);
            $hoja->getStyle("A{$fila}")->getFont()->setItalic(true)->getColor()->setRGB('777777');
            $fila++;
        }
        $hoja->getStyle("A{$filaEnc}:" . $ultima($n) . ($fila - 1))->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D5DCCF');
        if ($unBloque && $b['filas']) {
            $hoja->setAutoFilter($rangoEnc);
            $hoja->freezePane('A' . $primeraDato);
        }
        $fila++;
    }

    if (!empty($informe['firmas'])) {
        $texto("A{$fila}", 'Firmas');
        $hoja->getStyle("A{$fila}")->getFont()->setBold(true)->setSize(12)->getColor()->setRGB($verdeOscuro);
        $fila++;
        foreach (['Rol', 'Nombre', 'Cargo', 'Fecha de firma', 'Firma'] as $i => $t) {
            $texto($ultima($i + 1) . $fila, $t);
        }
        $hoja->getStyle("A{$fila}:E{$fila}")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $verde]],
        ]);
        $inicioFirmas = $fila;
        $fila++;
        foreach ($informe['firmas'] as $f) {
            $texto("A{$fila}", $f['rol']);
            $texto("B{$fila}", $f['nombre'] !== '' ? $f['nombre'] : 'Pendiente');
            $texto("C{$fila}", $f['cargo']);
            $texto("D{$fila}", $f['fecha'] !== '' ? inv_fecha($f['fecha']) : '');
            $hoja->getStyle("A{$fila}:E{$fila}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
            $img = inv_firma_png($f['imagen'] ?? null);
            if ($img !== null) {
                $dibujo = MemoryDrawing::fromString($img);
                $dibujo->setHeight(44);
                $dibujo->setCoordinates("E{$fila}");
                $dibujo->setOffsetX(4);
                $dibujo->setOffsetY(3);
                $dibujo->setWorksheet($hoja);
                $hoja->getRowDimension($fila)->setRowHeight(40);
            }
            $fila++;
        }
        $hoja->getStyle("A{$inicioFirmas}:E" . ($fila - 1))->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D5DCCF');
        $hoja->getColumnDimension('E')->setWidth(max(24, (float) $hoja->getColumnDimension('E')->getWidth()));
    }

    // Ancho de columnas segun el contenido de las tablas (no del titulo largo de la fila 2).
    $anchos = [];
    foreach ($informe['bloques'] as $b) {
        $c = 1;
        foreach (inv_columnas_formato($b, true) as $i => $col) {
            $max = mb_strlen($col[0]);
            foreach ($b['filas'] as $r) {
                $v = $r[$i] ?? '';
                $max = max($max, $col[1] === 'n' ? strlen(inv_fmt((int) $v)) + 3 : ($col[1] === 'f' ? 10 : mb_strlen((string) $v)));
            }
            $anchos[$c] = max($anchos[$c] ?? 0, $max);
            $c++;
        }
    }
    foreach ($anchos as $c => $w) {
        $hoja->getColumnDimension($ultima($c))->setWidth(min(48, max(10, $w + 2)));
    }
    if (!empty($informe['meta'])) {
        $hoja->getColumnDimension('A')->setWidth(max((float) $hoja->getColumnDimension('A')->getWidth(), 22));
    }

    $hoja->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_LETTER)
        ->setFitToWidth(1)->setFitToHeight(0);
    $hoja->getHeaderFooter()->setOddFooter('&L' . $informe['titulo'] . '&RPágina &P de &N');
    $hoja->setSelectedCell('A1');

    $tmp = fopen('php://temp', 'r+b');
    (new Xlsx($libro))->save($tmp);
    rewind($tmp);
    $bytes = (string) stream_get_contents($tmp);
    fclose($tmp);
    inv_enviar_archivo($bytes, inv_nombre_archivo($informe['archivo'], 'xlsx'), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}

/** Bytes PNG de una firma guardada (data:image/png;base64,...) o null. */
function inv_firma_png(?string $dataUri): ?string
{
    if ($dataUri === null || !preg_match('#^data:image/png;base64,([A-Za-z0-9+/]+={0,2})$#', $dataUri, $m)) {
        return null;
    }
    $png = base64_decode($m[1], true);
    return $png !== false && strncmp($png, "\x89PNG", 4) === 0 ? $png : null;
}

// ------------------------------------------------------------------ PDF

function inv_enviar_pdf(array $informe): void
{
    $pdf = inv_pdf_renderizar(inv_pdf_html($informe), false, $informe['titulo'] . ' · CengiBodega');
    inv_enviar_archivo($pdf, inv_nombre_archivo($informe['archivo'], 'pdf'), 'application/pdf', ($_GET['preview'] ?? '') === '1');
}

/** Convierte el HTML en PDF carta con pie "texto · Página X de Y". */
function inv_pdf_renderizar(string $html, bool $vertical, string $pie): string
{
    inv_cargar_vendor();
    $opciones = new Dompdf\Options();
    $opciones->set('isRemoteEnabled', false);
    $opciones->set('defaultFont', 'DejaVu Sans');
    $opciones->set('chroot', dirname(__DIR__));
    $dompdf = new Dompdf\Dompdf($opciones);
    $dompdf->setPaper('letter', $vertical ? 'portrait' : 'landscape');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();
    $lienzo = $dompdf->getCanvas();
    $fuente = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
    $lienzo->page_text(36, $lienzo->get_height() - 24, $pie, $fuente, 7, [0.45, 0.45, 0.45]);
    $lienzo->page_text($lienzo->get_width() - 110, $lienzo->get_height() - 24, 'Página {PAGE_NUM} de {PAGE_COUNT}', $fuente, 7, [0.45, 0.45, 0.45]);
    return (string) $dompdf->output();
}

function inv_pdf_html(array $informe): string
{
    $logo = is_file(INV_LOGO_INFORME) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents(INV_LOGO_INFORME)) : '';
    $h = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>'
        . '@page{margin:30px 36px 40px}'
        . 'body{font-family:"DejaVu Sans",sans-serif;font-size:8.5pt;color:#1d2a1f}'
        . '.enc{width:100%;border-collapse:collapse;border-bottom:3px solid #73BC25;margin-bottom:10px}'
        . '.enc td{vertical-align:middle;padding:0 0 8px}'
        . '.enc img{height:44px}'
        . '.tit{font-size:12.5pt;font-weight:bold;color:#1F5632}'
        . '.sub{font-size:8pt;color:#555;margin-top:2px}'
        . '.emi{text-align:right;font-size:7.5pt;color:#555;white-space:nowrap}'
        . '.emi b{display:block;color:#1F5632;font-size:9pt}'
        . '.meta{width:100%;border-collapse:collapse;margin-bottom:10px;background:#F3F8EC}'
        . '.meta td{padding:4px 8px;border:1px solid #D5DCCF}'
        . '.meta .k{font-weight:bold;color:#1F5632;width:16%}'
        . 'h3{font-size:10pt;color:#1F5632;margin:12px 0 5px}'
        . 'table.dat{width:100%;border-collapse:collapse}'
        . 'table.dat th{background:#73BC25;color:#fff;font-weight:bold;text-align:left;padding:5px 6px;font-size:7.8pt}'
        . 'table.dat td{padding:4px 6px;border-bottom:1px solid #E1E7DA}'
        . 'table.dat tr:nth-child(even) td{background:#F6FAF1}'
        . 'table.dat .n{text-align:right}table.dat td.n,table.dat td.c{white-space:nowrap}'
        . '.vacio{text-align:center;color:#777;font-style:italic;padding:12px}'
        . '.firmas{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:12px 0;margin-top:26px;page-break-inside:avoid}'
        . '.firmas td{text-align:center;vertical-align:bottom}'
        . '.firmas .rl{font-size:7.5pt;font-weight:bold;color:#1F5632;text-transform:uppercase;text-align:left}'
        . '.firmas .im{height:58px}.firmas .im img{max-height:56px;max-width:180px}'
        . '.firmas .pend{color:#999;font-style:italic;padding-top:36px}'
        . '.firmas .ln{border-top:1px solid #333;padding-top:4px}'
        . '.firmas .car{font-size:7.5pt;color:#555}'
        . (!empty($informe['compacto'])
            // Informe de una hoja: menos aire entre filas, encabezado y firmas mas bajos.
            ? '@page{margin:22px 30px 34px}body{font-size:7.8pt}.enc td{padding:0 0 5px}.enc img{height:36px}.tit{font-size:11.5pt}'
              . '.enc{margin-bottom:7px}.meta{margin-bottom:7px}.meta td{padding:3px 7px}'
              . 'table.dat th{padding:3px 6px;font-size:7.3pt}table.dat td{padding:2px 6px}'
              . '.firmas{margin-top:14px}.firmas .im{height:44px}.firmas .im img{max-height:42px}.firmas .pend{padding-top:24px}'
            : '')
        . '</style></head><body>';

    $h .= '<table class="enc"><tr>'
        . ($logo !== '' ? '<td style="width:120px"><img src="' . $logo . '" alt="CENGICAÑA"></td>' : '')
        . '<td><div class="tit">' . e($informe['titulo']) . '</div>'
        . (!empty($informe['subtitulo']) ? '<div class="sub">' . e($informe['subtitulo']) . '</div>' : '') . '</td>'
        . '<td class="emi">Emitido<b>' . e(inv_fecha(inv_hoy())) . '</b>' . e($informe['emisor'] ?? '') . '</td></tr></table>';

    if (!empty($informe['meta'])) {
        $h .= '<table class="meta">';
        foreach (array_chunk($informe['meta'], 3) as $par) {
            $h .= '<tr>';
            foreach ($par as [$k, $v]) {
                $h .= '<td class="k">' . e($k) . '</td><td>' . e($v) . '</td>';
            }
            $h .= count($par) < 3 ? '<td colspan="' . (2 * (3 - count($par))) . '" style="background:#fff;border:0"></td></tr>' : '</tr>';
        }
        $h .= '</table>';
    }

    foreach ($informe['bloques'] as $b) {
        $cols = inv_columnas_formato($b, false);
        if (!empty($b['titulo'])) {
            $h .= '<h3>' . e($b['titulo']) . '</h3>';
        }
        $h .= '<table class="dat"' . (isset($b['ancho_tabla_pdf']) ? ' style="width:' . (float) $b['ancho_tabla_pdf'] . '%"' : '') . '><thead><tr>';
        $k = 0; // anchos_pdf sigue el orden de las columnas visibles en el PDF
        foreach ($cols as $col) {
            $ancho = isset($b['anchos_pdf'][$k]) ? ' style="width:' . (float) $b['anchos_pdf'][$k] . '%"' : '';
            $h .= '<th' . (in_array($col[1], ['n', 'nu'], true) ? ' class="n"' : '') . $ancho . '>' . e($col[0]) . '</th>';
            $k++;
        }
        $h .= '</tr></thead><tbody>';
        foreach ($b['filas'] as $r) {
            $h .= '<tr>';
            foreach ($cols as $i => $col) {
                $v = $r[$i] ?? '';
                if ($col[1] === 'n') {
                    $h .= '<td class="n">' . e(inv_fmt((int) $v)) . '</td>';
                } elseif ($col[1] === 'nu') {
                    $h .= '<td class="n">' . e(inv_fmt((int) ($v[0] ?? 0)) . ' ' . ($v[1] ?? '')) . '</td>';
                } else {
                    $texto = $col[1] === 'f' ? inv_fecha($v) : (string) $v;
                    $h .= ($col[1] === 'f' || mb_strlen($texto) <= 14 ? '<td class="c">' : '<td>') . e($texto) . '</td>';
                }
            }
            $h .= '</tr>';
        }
        if (!$b['filas']) {
            $h .= '<tr><td class="vacio" colspan="' . count($cols) . '">' . e($b['vacio'] ?? 'Sin datos') . '</td></tr>';
        }
        $h .= '</tbody></table>';
    }

    if (!empty($informe['firmas'])) {
        $h .= '<table class="firmas"><tr>';
        $anchoFirma = round(100 / count($informe['firmas']), 2);
        foreach ($informe['firmas'] as $f) {
            $png = inv_firma_png($f['imagen'] ?? null);
            $h .= '<td style="width:' . $anchoFirma . '%"><div class="rl">' . e($f['rol']) . '</div><div class="im">'
                . ($png !== null ? '<img src="data:image/png;base64,' . base64_encode($png) . '" alt="">' : '<div class="pend">Pendiente</div>')
                . '</div><div class="ln"><b>' . ($f['nombre'] !== '' ? e($f['nombre']) : '&nbsp;') . '</b><br><span class="car">'
                . e($f['cargo']) . ($f['fecha'] !== '' ? ' · ' . e(inv_fecha($f['fecha'])) : '') . '</span></div></td>';
        }
        $h .= '</tr></table>';
    }

    return $h . '</body></html>';
}
