<?php
declare(strict_types=1);
// PDF del vale de salida (carta vertical): formato de documento oficial con encabezado de
// marca, datos del solicitante, reactivos, las cuatro firmas y el historial del vale.
// Usa el renderizador y utilidades de includes/exportar.php.

/** Envia el PDF del vale y termina. */
function inv_vale_pdf(array $vale, array $items, array $asign, array $firmas, array $bitacora, array $user): void
{
    $html = inv_vale_pdf_html($vale, $items, $asign, $firmas, $bitacora, $user);
    $pdf = inv_pdf_renderizar($html, true, 'Vale ' . $vale['numero'] . ' · Generado el ' . inv_fecha(date('Y-m-d H:i')) . ' por ' . $user['nombre'] . ' · CengiBodega');
    inv_enviar_archivo($pdf, inv_nombre_archivo('vale_' . $vale['numero'], 'pdf'), 'application/pdf', ($_GET['preview'] ?? '') === '1');
}

function inv_vale_pdf_html(array $vale, array $items, array $asign, array $firmas, array $bitacora, array $user): string
{
    $estado = (string) $vale['estado'];
    $entregado = $estado === 'Entregado';
    [$estadoTexto, $estadoClase] = [
        'Pendiente' => ['Pendiente de visto bueno', 'e-pend'],
        'Autorizado' => ['Autorizado · por despachar', 'e-aut'],
        'Entregado' => ['Entregado', 'e-ok'],
        'Rechazado' => ['Rechazado', 'e-bad'],
        'Anulado' => ['Anulado', 'e-bad'],
    ][$estado] ?? [$estado, 'e-pend'];
    $logo = is_file(INV_LOGO_INFORME) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents(INV_LOGO_INFORME)) : '';

    $css = '@page{margin:28pt 30pt 39pt}'
        . 'body{font-family:"DejaVu Sans",sans-serif;margin:0;font-size:8.6pt;color:#28332A}'
        // Encabezado
        . '.enc{width:100%;border-collapse:collapse}'
        . '.enc td{vertical-align:middle}'
        . '.enc .lg{width:86px}.enc .lg img{width:72px}'
        . '.org{font-size:7.2pt;font-weight:bold;color:#5E9B1D;letter-spacing:1.2px;text-transform:uppercase}'
        . '.tit{font-size:15pt;font-weight:bold;color:#1F3B0A;margin-top:2px}'
        . '.sub{font-size:8.2pt;color:#636A6F;margin-top:1px}'
        . '.folio{width:145pt;text-align:left}'
        . '.caja{border-left:3pt solid #73BC25;background:#F1F7EC;padding:7pt 9pt}'
        . '.caja .l{font-size:6.8pt;color:#636A6F;letter-spacing:.8px;text-transform:uppercase}'
        . '.caja .n{font-size:12.5pt;font-weight:bold;color:#2F5A0C;margin:1px 0;white-space:nowrap}'
        . '.caja .f{font-size:7.4pt;color:#636A6F}'
        . '.est{display:inline-block;margin-top:6px;padding:3px 10px;border-radius:10px;font-size:7.4pt;font-weight:bold}'
        . '.e-ok{background:#73BC25;color:#fff}.e-aut{background:#F1F8E9;color:#3A6312;border:1px solid #A3D300}'
        . '.e-pend{background:#FFF8DB;color:#8A6E00;border:1px solid #FFCC00}.e-bad{background:#FFF0E6;color:#B34B00;border:1px solid #FF6B00}'
        . '.franja{border:0;border-top:1.5pt solid #73BC25;margin:9pt 0 0}'
        // Secciones
        . '.sec{width:100%;border-collapse:collapse;margin:9pt 0 4pt;border-bottom:1px solid #DDE3D6;page-break-after:avoid}.sec td{padding:0 0 4px;vertical-align:middle}'
        . '.sec .num{width:16px}.sec .num div{background:#73BC25;color:#fff;text-align:center;font-weight:bold;font-size:7.8pt;border-radius:4px;padding:1px 0}.sec .tx{padding-left:7px}'
        . '.sec b{font-size:9.6pt;color:#1F3B0A;letter-spacing:.2px}'
        // Datos
        . '.datos{width:100%;table-layout:fixed;border-collapse:collapse;background:#F6F8F4}'
        . '.datos td{vertical-align:top;padding:4pt 6pt;border:1px solid #DDE3D6}'
        . '.datos .l{font-size:7pt;color:#636A6F}'
        . '.datos .v{font-size:8.6pt;font-weight:bold;color:#1F2326;margin-top:1pt;overflow-wrap:break-word}'
        // Reactivos
        . 'table.it{width:100%;border-collapse:collapse}'
        . 'table.it th{background:#EAF3E4;color:#234519;font-size:7.6pt;font-weight:bold;text-align:left;padding:5px 6px;border-bottom:1px solid #A8BDA0}'
        . 'table.it td{padding:4px 6px;border-bottom:1px solid #DDE3D6;font-size:8.5pt;vertical-align:top;overflow-wrap:break-word}'
        . 'table.it tr:nth-child(even) td{background:#F8FBF4}'
        . 'table.it .c{text-align:center}table.it .r{text-align:right;white-space:nowrap}'
        . 'table.it .cod{white-space:nowrap;font-weight:bold;color:#2F5A0C}'
        . 'table.it .lo{font-size:7.6pt;color:#3A6312}'
        // Firmas
        . '.firmas{width:100%;table-layout:fixed;border-collapse:collapse;page-break-inside:avoid}'
        . '.firmas td{vertical-align:top;padding:0 4px}.firmas td.pr{padding-left:0}.firmas td.ul{padding-right:0}'
        . '.fb{border:1px solid #CFD8C6;border-radius:6px;text-align:center}'
        . '.fb.pend{border-style:dashed;background:#FAFBF9}'
        . '.fb .rl{background:#F1F8E9;color:#2F5A0C;font-size:6.9pt;font-weight:bold;letter-spacing:.6px;text-transform:uppercase;padding:5px 4px;border-bottom:1px solid #CFD8C6;border-radius:6px 6px 0 0}'
        . '.fb.pend .rl{background:#F3F4F5;color:#7A8186}'
        . '.fb .im{height:32pt;padding:3pt 4pt 0}.fb .im img{max-height:30pt;max-width:98pt}'
        . '.fb .pe{color:#636A6F;font-style:italic;font-size:7.6pt;padding-top:9pt}'
        . '.fb .ln{margin:0 10px;border-top:1px solid #30353A;padding:4px 0 7px}'
        . '.fb .nm{font-weight:bold;font-size:8.2pt;color:#1F2326}'
        . '.fb .cg{font-size:6.9pt;color:#636A6F}'
        . '.fb .fe{font-size:6.9pt;color:#3A6312;margin-top:1px}'
        // Otros
        . '.aviso{margin-top:12px;padding:7px 10px;background:#FFF0E6;color:#B34B00;border-left:3px solid #FF6B00;font-weight:bold}'
        . 'table.hist{width:100%;border-collapse:collapse}'
        . 'table.hist td{padding:3px 6px;border-bottom:1px solid #EEF1EB;font-size:7.6pt;vertical-align:top}'
        . 'table.hist .f{width:92px;color:#636A6F;white-space:nowrap}table.hist .u{width:105px;font-weight:bold}'
        . '.nota{margin-top:9pt;padding:5pt 7pt;page-break-inside:avoid;background:#F6F8F4;border-radius:4px;font-size:7.2pt;color:#636A6F;line-height:1.45}'
        . '.nota b{color:#30353A}';

    $h = '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>' . $css . '</style></head><body>';

    // Encabezado: logo, titulo, folio con estado y la franja con los colores de marca.
    $h .= '<table class="enc"><tr>'
        . ($logo !== '' ? '<td class="lg"><img src="' . $logo . '" alt="CENGICAÑA"></td>' : '')
        . '<td><div class="org">CENGICAÑA · Bodega Central de Precursores</div>'
        . '<div class="tit">Vale de solicitud y descarga</div>'
        . '<div class="sub">Reactivos precursores fiscalizados</div></td>'
        . '<td class="folio"><div class="caja"><div class="l">No. de vale</div><div class="n">' . e($vale['numero']) . '</div>'
        . '<div class="f">Fecha de solicitud: ' . e(inv_fecha($vale['fecha'])) . '</div>'
        . '<span class="est ' . $estadoClase . '">' . e($estadoTexto) . '</span></div></td></tr></table><hr class="franja">';

    // 1. Datos del solicitante y del despacho.
    $proyecto = (string) $vale['proyecto'];
    $proyectoEnFilaPropia = mb_strlen($proyecto) > 45;
    $h .= inv_vale_pdf_seccion(1, 'Laboratorio y solicitante') . '<table class="datos"><tr>'
        . inv_vale_pdf_dato('Laboratorio', $vale['lab_nombre'])
        . inv_vale_pdf_dato('Analista responsable', (string) $vale['solicitante']);
    if (!$proyectoEnFilaPropia) {
        $h .= inv_vale_pdf_dato('Proyecto / ensayo', $proyecto);
    }
    $h .= inv_vale_pdf_dato('Jefe / supervisor', (string) $vale['jefe'], $proyectoEnFilaPropia ? 2 : 1) . '</tr>';
    if ($proyectoEnFilaPropia) {
        $h .= '<tr>' . inv_vale_pdf_dato('Proyecto / ensayo', $proyecto, 4) . '</tr>';
    }
    if ($entregado) {
        $h .= '<tr>' . inv_vale_pdf_dato('Fecha de despacho', inv_fecha($vale['fecha_entrega']), 2)
            . inv_vale_pdf_dato('Recibido por', (string) ($vale['recibido_por'] ?? ''), 2) . '</tr>';
    }
    $h .= '</table>';

    // 2. Reactivos
    $h .= inv_vale_pdf_seccion(2, 'Detalle de reactivos precursores solicitados')
        . '<table class="it"><thead><tr><th class="c" style="width:5%">Ítem</th><th style="width:10%">Código</th><th style="width:25%">Precursor químico / concentración</th>'
        . '<th class="c" style="width:7%">Unidad</th><th class="r" style="width:10%">Cant.<br>solicitada</th><th class="r" style="width:10%">Cant.<br>entregada</th>'
        . '<th style="width:18%">Lote asignado</th><th style="width:15%">Observaciones / uso</th></tr></thead><tbody>';
    foreach ($items as $k => $i) {
        $lotes = [];
        foreach ($asign[(int) $i['id']] ?? [] as $a) {
            $lotes[] = e($a['lote']) . ' <span style="color:#636A6F">(' . e(inv_fmt(inv_milli($a['cantidad']))) . ')</span>';
        }
        $h .= '<tr><td class="c">' . ($k + 1) . '</td><td class="cod">' . e($i['codigo']) . '</td>'
            . '<td>' . e($i['nombre']) . ($i['concentracion'] ? ' <span style="color:#636A6F">(' . e($i['concentracion']) . ')</span>' : '') . '</td>'
            . '<td class="c">' . e($i['unidad']) . '</td><td class="r">' . e(inv_fmt(inv_milli($i['cantidad']))) . '</td>'
            . '<td class="r">' . ($entregado ? '<b>' . e(inv_fmt(inv_milli($i['entregado']))) . '</b>' : '—') . '</td>'
            . '<td class="lo">' . ($entregado && $lotes ? implode('<br>', $lotes) : '—') . '</td>'
            . '<td>' . e((string) $i['observaciones']) . '</td></tr>';
    }
    if (!$items) {
        $h .= '<tr><td colspan="8">Sin reactivos registrados</td></tr>';
    }
    $h .= '</tbody></table>';

    if (in_array($estado, ['Rechazado', 'Anulado'], true)) {
        $h .= '<div class="aviso">' . ($estado === 'Rechazado' ? 'Vale rechazado' : 'Vale anulado') . ': ' . e((string) $vale['motivo']) . '</div>';
    }

    // 3. Firmas
    $h .= inv_vale_pdf_seccion(3, 'Autorizaciones y conformidad de entrega') . '<table class="firmas"><tr>';
    foreach ([['sol', 'Solicitado por', 'Analista / solicitante'], ['aut', 'Visto bueno', 'Jefe inmediato de laboratorio'],
              ['ent', 'Entregado por', 'Encargado de Bodega Central'], ['rec', 'Recibido conforme', 'Persona que retira']] as $n => [$clave, $rol, $cargo]) {
        $f = $firmas[$clave] ?? null;
        $png = $f ? inv_firma_png((string) $f['imagen']) : null;
        $h .= '<td class="' . ($n === 0 ? 'pr' : ($n === 3 ? 'ul' : '')) . '"><div class="fb' . ($f ? '' : ' pend') . '"><div class="rl">' . e($rol) . '</div><div class="im">'
            . ($png !== null ? '<img src="data:image/png;base64,' . base64_encode($png) . '" alt="">' : '<div class="pe">Pendiente de firma</div>')
            . '</div><div class="ln"><div class="nm">' . ($f ? e($f['nombre']) : '&nbsp;') . '</div><div class="cg">' . e($cargo) . '</div>'
            . '<div class="fe">' . ($f ? e(inv_fecha($f['firmado_en'])) : '&nbsp;') . '</div></div></div></td>';
    }
    $h .= '</tr></table>';

    // 4. Historial
    if ($bitacora) {
        $h .= inv_vale_pdf_seccion(4, 'Historial del vale') . '<table class="hist">';
        foreach ($bitacora as $b) {
            $h .= '<tr><td class="f">' . e(inv_fecha($b['creado_en'])) . '</td><td class="u">' . e($b['usuario_nombre']) . '</td><td>' . e($b['accion']) . '</td></tr>';
        }
        $h .= '</table>';
    }

    $h .= '<div class="nota"><b>Nota:</b> este documento es un comprobante oficial auditable de descarga de sustancias fiscalizadas. '
        . 'La Bodega Central no entregará ningún precursor sin las firmas de autorización requeridas.</div>';

    return $h . '</body></html>';
}

function inv_vale_pdf_dato(string $etiqueta, string $valor, int $columnas = 1): string
{
    return '<td colspan="' . $columnas . '"><div class="l">' . e($etiqueta) . '</div><div class="v">'
        . e($valor !== '' ? $valor : '—') . '</div></td>';
}

function inv_vale_pdf_seccion(int $numero, string $titulo): string
{
    return '<table class="sec"><tr><td class="num"><div>' . $numero . '</div></td><td class="tx"><b>' . e($titulo) . '</b></td></tr></table>';
}
