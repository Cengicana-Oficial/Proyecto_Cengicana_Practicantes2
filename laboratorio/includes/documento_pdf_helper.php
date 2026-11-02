<?php
/**
 * documento_pdf_helper.php
 *
 * Genera el PDF (Dompdf) de un documento del repositorio (lab_documento). Lo usan
 * la vista/descarga (controllers/documento_pdf.php) y el envío por correo de
 * Reportes (controllers/enviar_reporte_correo.php).
 */

if (!function_exists('lab_documento_pdf_e')) {
    function lab_documento_pdf_e($valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('lab_documento_pdf_html')) {
    /** HTML imprimible del documento; si no tiene contenido guardado, una ficha con sus metadatos. */
    function lab_documento_pdf_html(array $documento): string
    {
        $e = 'lab_documento_pdf_e';
        $contenido = trim((string) ($documento['contenido_html'] ?? ''));
        if ($contenido === '') {
            $tipo = ($documento['tipo_documento'] ?? '') === 'boleta'
                ? 'Boleta de recepción'
                : 'Informe de resultados';
            $titulo = trim((string) ($documento['titulo'] ?? ''));
            if ($titulo === '') {
                $titulo = $tipo . ' #' . (int) $documento['id_documento'];
            }
            $fecha = trim((string) ($documento['generado_en'] ?? ''));
            $fechaFormateada = $fecha !== '' && strtotime($fecha)
                ? date('d/m/Y H:i', strtotime($fecha))
                : '—';

            return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>
                @page{margin:32px 38px}body{font-family:DejaVu Sans,sans-serif;color:#20242a;font-size:12px}
                .head{border-bottom:3px solid #73bc25;padding-bottom:14px;margin-bottom:24px}
                h1{font-size:21px;margin:0 0 5px}.sub{color:#6f777d}.grid{width:100%;border-collapse:collapse}
                .grid td{padding:10px 12px;border:1px solid #dfe3e5}.grid td:first-child{width:34%;font-weight:bold;background:#f5f7f4}
                .note{margin-top:24px;padding:13px;border:1px solid #dfe3e5;background:#f7f8f8;color:#6f777d}
            </style></head><body>
                <div class="head"><h1>' . $e($titulo) . '</h1><div class="sub">CENGILABS · Laboratorio Agroindustrial</div></div>
                <table class="grid">
                    <tr><td>Documento</td><td>DOC-' . str_pad((string) (int) $documento['id_documento'], 4, '0', STR_PAD_LEFT) . '</td></tr>
                    <tr><td>Tipo</td><td>' . $e($tipo) . '</td></tr>
                    <tr><td>Lote</td><td>' . $e($documento['codigo_lote'] ?? '—') . '</td></tr>
                    <tr><td>Cliente</td><td>' . $e($documento['cliente'] ?? 'Sin institución') . '</td></tr>
                    <tr><td>Versión</td><td>v' . (int) ($documento['version'] ?? 1) . '</td></tr>
                    <tr><td>Generado por</td><td>' . $e($documento['generado_por'] ?: '—') . '</td></tr>
                    <tr><td>Fecha</td><td>' . $e($fechaFormateada) . '</td></tr>
                </table>
                <div class="note">Este registro todavía no contiene una representación HTML detallada. El PDF muestra los metadatos versionados disponibles.</div>
            </body></html>';
        }
        if (stripos($contenido, '<html') === false) {
            return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>
                @page{margin:30px}body{font-family:DejaVu Sans,sans-serif;color:#20242a;font-size:11px}
                table{width:100%;border-collapse:collapse}th,td{padding:7px;border:1px solid #dfe3e5}
            </style></head><body>' . $contenido . '</body></html>';
        }

        return $contenido;
    }
}

if (!function_exists('lab_documento_pdf_nombre')) {
    /** Nombre de archivo seguro: título del documento + versión. */
    function lab_documento_pdf_nombre(array $documento): string
    {
        $titulo = trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($documento['titulo'] ?? '')), '-');
        if ($titulo === '') {
            $titulo = 'documento-' . (int) $documento['id_documento'];
        }

        return $titulo . '-v' . (int) ($documento['version'] ?? 1) . '.pdf';
    }
}

if (!function_exists('lab_documento_pdf_cargar_dompdf')) {
    function lab_documento_pdf_cargar_dompdf(): void
    {
        $autoload = __DIR__ . '/../../cengicursos/vendor/autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('El generador de PDF no está disponible en este ambiente.');
        }
        require_once $autoload;
    }
}

if (!function_exists('lab_documento_pdf_binario')) {
    /** PDF del documento (bytes). El tamaño de página lo fija el @page del HTML (informes: carta horizontal). */
    function lab_documento_pdf_binario(array $documento): string
    {
        lab_documento_pdf_cargar_dompdf();
        $opciones = new Dompdf\Options();
        $opciones->set('isRemoteEnabled', false);
        $opciones->set('defaultFont', 'DejaVu Sans');
        $opciones->set('chroot', dirname(__DIR__));
        $dompdf = new Dompdf\Dompdf($opciones);
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->loadHtml(lab_documento_pdf_html($documento), 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
