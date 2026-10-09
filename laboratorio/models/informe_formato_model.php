<?php
/**
 * informe_formato_model.php — arma el informe de resultados de un lote con el formato del
 * laboratorio según el tipo de muestra (config/informes_formatos.php, igual que el prototipo y
 * los PDF de referencia). Un solo renderizador para la vista previa, el PDF firmado y el Excel.
 *
 * Columnas: solo los análisis solicitados en Recepción (solicitud_analisis), en el orden del
 * formato; los solicitados que el formato no contempla van al final marcados con *.
 * Identificación: campos de la etiqueta registrada en Identificación de muestra.
 * Valores: se calculan en el navegador con las fórmulas de las hojas (hojas_definiciones.js) a
 * partir de los resultados VALIDADOS en Validación técnica; aquí solo se reciben como texto.
 */

if (!function_exists('lab_informe_formato_config')) {
    function lab_informe_formato_config(): array
    {
        static $config;
        return $config ??= require __DIR__ . '/../config/informes_formatos.php';
    }
}

if (!function_exists('lab_informe_tipo')) {
    /** Tipo de formato a partir del tipo de muestra del sistema (suelos → Suelo, cañas → Caña…). */
    function lab_informe_tipo(string $tipoMuestra): string
    {
        $n = mb_strtolower(trim($tipoMuestra));
        $n = strtr($n, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']);
        foreach (['suelo' => 'Suelo', 'agua' => 'Agua', 'foliar' => 'Foliar', 'cana' => 'Caña'] as $clave => $tipo) {
            if (strpos($n, $clave) !== false) return $tipo;
        }
        return '_';
    }
}

if (!function_exists('lab_informe_etiqueta_campos')) {
    /**
     * Campos de la etiqueta de una muestra: los tabulados en Identificación y, si se leyó por QR /
     * código de barras con el formato "CAMPO: valor", los que se pueden separar del texto.
     */
    function lab_informe_etiqueta_campos(array $etiqueta): array
    {
        static $alias = ['fecha' => 'fecha', 'labor' => 'labor', 'ensayo' => 'ensayo', 'descripcion' => 'descripcion', 'estrato' => 'estrato',
            'finca' => 'finca', 'seccion' => 'seccion', 'lote' => 'loteCampo', 'pante' => 'pante', 'parcela' => 'parcela', 'bloque' => 'bloque',
            'profundidad' => 'profundidad', 'tratamiento' => 'tratamiento', 'repeticion' => 'repeticion', 'variedad' => 'variedad',
            'requerimiento' => 'requerimiento', 'muestra' => 'descripcion', 'punto' => 'descripcion'];
        $campos = [];
        foreach ((array) ($etiqueta['campos'] ?? []) as $k => $v) {
            if (trim((string) $v) !== '') $campos[$k] = trim((string) $v);
        }
        $info = trim((string) ($etiqueta['info'] ?? ''));
        if ($info !== '' && ($etiqueta['contenido'] ?? '') !== 'solo_id') {
            foreach (preg_split('/\n|\||;/', $info) as $parte) {
                if (!preg_match('/^\s*([^:=]{2,30})[:=]\s*(.+?)\s*$/u', $parte, $m)) continue;
                $clave = preg_replace('/[^a-z]/', '', strtr(mb_strtolower($m[1]), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n']));
                if (isset($alias[$clave]) && !isset($campos[$alias[$clave]])) $campos[$alias[$clave]] = $m[2];
            }
        }
        return $campos;
    }
}

if (!function_exists('lab_informe_estructura')) {
    /**
     * Columnas de identificación y de resultados del informe y, por muestra y columna, la celda
     * (formulario que contiene la lectura y su estado). $detalle viene de lab_informe_detalle().
     */
    function lab_informe_estructura(array $detalle): array
    {
        $cfg = lab_informe_formato_config();
        $tipo = lab_informe_tipo((string) ($detalle['solicitudes'][0]['tipo_muestra'] ?? $detalle['lote']['tipo_muestra']));
        $formato = $cfg['tipos'][$tipo] ?? $cfg['tipos']['_'];
        if ($tipo === '_') $formato['titulo'] = 'Reporte de Análisis de ' . ($detalle['lote']['tipo_muestra'] ?? '');

        // Análisis solicitados en Recepción (por nombre de la hoja de trabajo).
        $solicitados = [];
        foreach ($detalle['analisis'] as $a) {
            $solicitados[$a['analisis_hoja']] = $a;
        }
        $columnas = [];
        $cubiertos = [];
        foreach ($formato['columnas'] as $col) {
            if (!isset($solicitados[$col['an']])) continue;
            $col['key'] = $col['an'] . '|' . ($col['k'] ?? $col['h']);
            $columnas[] = $col;
            $cubiertos[$col['an']] = true;
        }
        foreach ($solicitados as $an => $a) {
            if (isset($cubiertos[$an]) || in_array($an, $formato['no_reportar'] ?? [], true)) continue;
            $columnas[] = ['h' => $a['nombre'], 'u' => $a['unidad'] ?? '', 'an' => $an, 'k' => null, 'd' => 2,
                'extra' => $tipo !== '_', 'key' => $an . '|' . $a['nombre']];
        }

        // Identificación desde la etiqueta.
        $muestras = [];
        foreach ($detalle['muestras'] as $m) {
            $etiqueta = (array) ($m['etiqueta_completa'] ?? []);
            $muestras[] = $m + ['campos' => lab_informe_etiqueta_campos($etiqueta), 'info' => trim((string) ($etiqueta['info'] ?? '')),
                'solo_id' => ($etiqueta['contenido'] ?? '') === 'solo_id'];
        }
        $identificacion = $formato['identificacion'];
        if (!$identificacion) {
            $camposCfg = json_decode((string) file_get_contents(__DIR__ . '/../config/identificacion_v3.json'), true)['campos'] ?? [];
            $cols = [];
            foreach ($camposCfg as $c) {
                if ($c['key'] === 'obs') continue;
                foreach ($muestras as $m) {
                    if (!empty($m['campos'][$c['key']])) { $cols[] = ['k' => $c['key'], 'h' => $c['label']]; break; }
                }
            }
            if (!$cols) $cols = [['k' => array_filter(array_column($muestras, 'info')) ? '__info' : '__vacio', 'h' => 'Identificación de la etiqueta']];
            $identificacion = ['grupo' => null, 'cols' => $cols];
        }

        // Celda de cada muestra por columna: tarea (muestra × análisis de la hoja) del lote.
        $celdas = [];
        foreach ($muestras as $m) {
            foreach ($columnas as $i => $col) {
                $c = $detalle['celdas_hoja'][$m['id_muestra'] . '|' . $col['an']] ?? null;
                if ($c) $celdas[$m['id_muestra']][$i] = $c;
            }
        }

        return ['tipo' => $tipo, 'formato' => $formato, 'columnas' => $columnas, 'identificacion' => $identificacion,
            'muestras' => $muestras, 'celdas' => $celdas];
    }
}

if (!function_exists('lab_informe_celda')) {
    /** Texto y estado de una celda: ok (validado), pend (sin validar) o ns (no solicitado). */
    function lab_informe_celda(array $est, array $valores, int $idMuestra, int $i): array
    {
        $c = $est['celdas'][$idMuestra][$i] ?? null;
        if (!$c) return ['txt' => '—', 'estado' => 'ns'];
        if ($c['estado'] !== 'validado') return ['txt' => 'Pend.', 'estado' => 'pend'];
        $v = trim((string) ($valores[$idMuestra][$est['columnas'][$i]['key']] ?? ''));
        return ['txt' => $v !== '' ? $v : '—', 'estado' => 'ok'];
    }
}

if (!function_exists('lab_informe_datos_encabezado')) {
    /** Pares etiqueta/valor del bloque de datos del informe según el formato. */
    function lab_informe_datos_encabezado(array $est, array $detalle, array $meta): array
    {
        $s = $detalle['solicitudes'][0] ?? [];
        $lote = $detalle['lote'];
        $f = static fn($v) => $v && strtotime((string) $v) ? date('d/m/Y', strtotime((string) $v)) : '—';
        $clientes = implode(', ', array_unique(array_filter(array_column($detalle['solicitudes'], 'institucion')))) ?: $lote['cliente'];
        $uno = static function (string $k) use ($est): string {
            $v = array_values(array_unique(array_filter(array_map(static fn($m) => $m['campos'][$k] ?? '', $est['muestras']))));
            return $v ? implode(', ', $v) : '—';
        };
        $entrega = $meta['fecha_firma'] ? $f($meta['fecha_firma']) : $f($s['fecha_estimada'] ?? null) . ' (estimada)';
        $codigoMuestreo = trim((string) ($s['codigo_muestreo'] ?? '')) ?: $lote['codigo_lote'];
        $mapa = [
            'codigo_muestreo' => ['Código del muestreo:', $codigoMuestreo . ($est['tipo'] === 'Suelo' ? ' (lote ' . $lote['codigo_lote'] . ')' : '')],
            'institucion' => ['Ingenio/ Institución:', $clientes],
            'ingenio' => ['Ingenio:', $clientes],
            'fecha_ingreso' => ['Fecha de Ingreso:', $f($s['fecha_ingreso'] ?? null)],
            'fecha_entrega' => ['Fecha de Entrega:', $entrega],
            'ensayo' => ['ENSAYO:', $uno('ensayo')],
            'fecha_ingreso_mayus' => ['FECHA DE INGRESO:', $f($s['fecha_ingreso'] ?? null)],
            'ingenio_mayus' => ['INGENIO:', $clientes],
            'fecha_reporte' => ['FECHA DE REPORTE:', $entrega],
            'finca_mayus' => ['FINCA:', $uno('finca')],
            'lote_mayus' => ['LOTE:', $lote['codigo_lote']],
        ];
        return array_map(static fn($k) => $mapa[$k], $est['formato']['datos']);
    }
}

if (!function_exists('lab_informe_reporte_html')) {
    /**
     * Informe completo (HTML con tablas, apto para Dompdf, carta horizontal).
     * $meta: codigo, observaciones, revisores[], firmante, firma_png, fecha_firma, fecha_firma_txt, preview.
     */
    function lab_informe_reporte_html(array $est, array $detalle, array $valores, array $meta): string
    {
        $cfg = lab_informe_formato_config();
        $F = $est['formato'];
        $e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $cols = $est['columnas'];
        $idCols = $est['identificacion']['cols'];
        $idGrupo = $est['identificacion']['grupo'];
        $lote = $detalle['lote'];

        // Encabezado de columnas: títulos (agrupados por hg) y unidades (agrupadas por g).
        $titulos = '';
        for ($i = 0; $i < count($cols);) {
            if (!empty($cols[$i]['hg'])) {
                $n = 0; $hg = $cols[$i]['hg'];
                while ($i < count($cols) && ($cols[$i]['hg'] ?? null) === $hg) { $n++; $i++; }
                $titulos .= '<th colspan="' . $n . '">' . $e($hg) . '</th>';
            } else {
                $titulos .= '<th>' . $e($cols[$i]['h']) . (!empty($cols[$i]['extra']) ? ' *' : '') . '</th>';
                $i++;
            }
        }
        $unidades = '';
        for ($i = 0; $i < count($cols);) {
            if (!empty($cols[$i]['g'])) {
                $n = 0; $g = $cols[$i]['g'];
                while ($i < count($cols) && ($cols[$i]['g'] ?? null) === $g) { $n++; $i++; }
                $unidades .= '<td colspan="' . $n . '">' . $e($g) . '</td>';
            } else {
                $unidades .= '<td>' . $e($cols[$i]['u'] ?? '') . '</td>';
                $i++;
            }
        }
        $cabeceraId = $idGrupo
            ? '<th colspan="' . count($idCols) . '" class="et">' . $e($idGrupo) . '</th>'
            : implode('', array_map(static fn($c) => '<th rowspan="2" class="et">' . $e($c['h']) . '</th>', $idCols));
        $subId = $idGrupo ? implode('', array_map(static fn($c) => '<td class="et idsub">' . $e($c['h']) . '</td>', $idCols)) : '';

        $filas = '';
        foreach ($est['muestras'] as $m) {
            $hayCampos = false;
            foreach ($idCols as $c) {
                if ($c['k'][0] !== '_' && !empty($m['campos'][$c['k']])) { $hayCampos = true; break; }
            }
            $sid = $m['solo_id'] ? ' <span class="sid">solo ID</span>' : '';
            if ($m['info'] !== '' && !$hayCampos) {
                $idCeldas = '<td class="et info" colspan="' . count($idCols) . '">' . $e($m['info']) . $sid . '</td>';
            } else {
                $idCeldas = '';
                foreach ($idCols as $c) {
                    $txt = match ($c['k']) {
                        '__info' => $m['info'] ?: '—',
                        '__vacio' => '—',
                        '__ident' => $m['campos']['descripcion'] ?? ($m['info'] ?: ''),
                        default => $m['campos'][$c['k']] ?? '',
                    };
                    $idCeldas .= '<td class="et' . ($c['k'] === '__info' ? ' info' : '') . '">' . $e($txt) . ($c['k'] === '__info' ? $sid : '') . '</td>';
                }
            }
            $filas .= '<tr>' . $idCeldas . '<td class="lab">' . $e($m['codigo']) . '</td>';
            foreach ($cols as $i => $col) {
                $v = lab_informe_celda($est, $valores, (int) $m['id_muestra'], $i);
                $filas .= '<td class="v ' . $v['estado'] . '">' . $e($v['txt']) . '</td>';
            }
            $filas .= '</tr>';
        }
        $totalCols = count($idCols) + 1 + count($cols);
        // Formatos anchos (p. ej. Suelos con todos los análisis, ~31 columnas) caben en una hoja carta horizontal.
        $densidad = $totalCols > 26 ? ' mini' : ($totalCols > 18 ? ' compacta' : '');

        $datos = lab_informe_datos_encabezado($est, $detalle, $meta);
        $filasDatos = '';
        foreach (array_chunk($datos, 2) as $par) {
            $filasDatos .= '<tr>';
            foreach ($par as [$k, $v]) $filasDatos .= '<td class="dt">' . $e($k) . '</td><td class="dd">' . $e($v) . '</td>';
            if (count($par) === 1) $filasDatos .= '<td></td><td></td>';
            $filasDatos .= '</tr>';
        }

        if (!empty($F['metodos_por_analisis'])) {
            $met = '';
            foreach (array_unique(array_column($cols, 'an')) as $an) {
                if (!isset($F['metodos_por_analisis'][$an])) continue;
                $met .= '<tr><td class="mk">' . $e($F['metodos_etiqueta'][$an] ?? $an) . '</td><td>' . $e($F['metodos_por_analisis'][$an]) . '</td></tr>';
            }
            $metodos = $met ? '<div class="metodos"><b>Metodología:</b><table class="met">' . $met . '</table></div>' : '';
        } else {
            $metodos = !empty($F['metodos']) ? '<p class="metodos"><b>Métodos de Análisis:</b> ' . $e($F['metodos']) . '</p>' : '';
        }

        $validados = 0; $total = 0;
        foreach ($est['celdas'] as $porCol) foreach ($porCol as $c) { $total++; if ($c['estado'] === 'validado') $validados++; }
        $aviso = !empty($meta['preview'])
            ? '<div class="aviso">Borrador — ' . $validados . ' de ' . $total . ' resultados validados por el técnico. "Pend." = aún sin validar. Sin firma del jefe de laboratorio.</div>' : '';
        $codigoCaja = !empty($F['lote_arriba'])
            ? '<tr><td>' . $e($F['codigo']) . '</td></tr><tr><td>Lote ' . $e($lote['codigo_lote']) . '</td></tr>'
            : '<tr><td>' . $e($F['codigo']) . '</td></tr><tr><td>' . $e($F['version'] ?? '') . '</td></tr><tr><td>' . $e($F['fecha'] ?? '') . '</td></tr>';
        $firmado = !empty($meta['firma_png']);
        $firmante = trim((string) ($meta['firmante'] ?? ''));

        return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>' . $e(($meta['codigo'] ?? 'Informe') . ' · Lote ' . $lote['codigo_lote']) . '</title><style>
@page{size:letter landscape;margin:9mm}
body{font-family:DejaVu Sans,sans-serif;color:#1F2429;font-size:9px;margin:0}
table{border-collapse:collapse}
.head{width:100%;border:1px solid #9AA3AD}.head td{border:1px solid #9AA3AD;text-align:center;vertical-align:middle}
.logo{width:13%;font-weight:bold;font-size:11px}.srv{font-size:8.5px;color:#495057;padding:3px}.ht{font-weight:bold;font-size:11px;padding:6px}
.cod{width:100%}.cod td{border:none;border-bottom:1px solid #9AA3AD;font-size:8.5px;font-weight:bold;padding:2px}.cod tr:last-child td{border-bottom:none}
.labt{text-align:center;font-weight:bold;font-size:11.5px;margin:9px 0 2px}.titulo{text-align:center;font-weight:bold;font-size:12px;margin:3px 0 9px}
.datos{width:100%;margin-bottom:8px;font-size:9.5px}.datos td{padding:1.5px 4px}.dt{font-weight:bold;width:15%;white-space:nowrap}.dd{width:35%}
.aviso{border:1px dashed #E0B84F;background:#FFF8E1;color:#7A5A00;text-align:center;padding:4px;margin:4px 0 8px;font-size:8.5px}
.res{width:100%;font-size:8px}.res th,.res td{border:1px solid #9AA3AD;padding:2.5px 3px;text-align:center;vertical-align:middle}
.res thead th{background:#EEF1F4;font-weight:bold;font-size:7.5px}.res .uni td{background:#F7F8FA;font-weight:bold;font-size:7.5px;color:#495057}
.res .et{text-align:left}.res td.idsub{background:#EEF1F4;font-weight:bold;color:#1F2429}.res .info{font-size:7.5px}
.res .lab{font-weight:bold;white-space:nowrap}.res .v{white-space:nowrap}.res .pend{color:#C08A00;font-size:7px}.res .ns{color:#B6BDC5}
.sid{font-size:6.5px;font-weight:bold;color:#946200;border:1px solid #E0B84F;padding:0 2px}
.res.compacta{font-size:7px}.res.compacta th,.res.compacta td{padding:2px 1.5px}.res.compacta thead th,.res.compacta .uni td{font-size:6.5px}
.res.mini{font-size:6px}.res.mini th,.res.mini td{padding:1.5px 1px}.res.mini thead th,.res.mini .uni td{font-size:5.6px}.res.mini .info,.res.mini .sid{font-size:5.4px}
.ultima td{border:none !important;color:#6C757D;font-size:8px;padding-top:4px}
.nota{font-size:8px;color:#6C757D;margin-top:3px}.metodos{font-size:8.5px;margin:9px 0 5px;text-align:justify}
.met{width:100%;margin-top:3px;font-size:8px}.met td{padding:1.5px 4px;border-bottom:1px solid #EEF1F4;vertical-align:top}.met .mk{font-weight:bold;white-space:nowrap;width:1%}
.obs{font-size:9px;margin:4px 0 8px}
.firmas{width:100%;margin:6px 0 8px}.firmas td{vertical-align:bottom;text-align:center;width:28%;font-size:9px}
.flbl{text-align:left;font-size:8px;color:#6C757D;font-weight:bold}.farea{height:44px;vertical-align:bottom}.farea img{height:42px}
.fval{font-size:8px;color:#6C757D;font-style:italic}.fpend{font-size:8px;color:#B6BDC5;font-style:italic}.flinea{border-top:1px solid #1F2429;padding-top:2px}
.fsmall{font-size:7.5px;color:#6C757D}
.pie{border-top:1px solid #DDE1E5;padding-top:5px;font-size:7.5px;color:#495057}.pie p{margin:0 0 2px}
.pief{width:100%;margin-top:4px;color:#6C757D;font-size:7.5px}
</style></head><body>
<table class="head"><tr><td class="logo">CENGICAÑA</td><td style="padding:0"><table style="width:100%"><tr><td class="srv" style="border:none;border-bottom:1px solid #9AA3AD">' . $e($F['servicio']) . '</td></tr><tr><td class="ht" style="border:none">' . $e($F['encabezado']) . '</td></tr></table></td><td style="width:13%;padding:0"><table class="cod">' . $codigoCaja . '</table></td></tr></table>
<div class="labt">Laboratorio Agroindustrial</div>
' . $aviso . '
<div class="titulo">' . $e($F['titulo']) . '</div>
<table class="datos">' . $filasDatos . '</table>
<table class="res' . $densidad . '"><thead><tr>' . $cabeceraId . '<th rowspan="2">No. Lab</th>' . $titulos . '</tr><tr class="uni">' . $subId . $unidades . '</tr></thead>
<tbody>' . $filas . '<tr class="ultima"><td colspan="' . $totalCols . '">---- última línea ----</td></tr></tbody></table>
' . (array_filter(array_column($cols, 'extra')) ? '<div class="nota">' . $e($cfg['nota_extra']) . '</div>' : '') . '
' . $metodos . '
<div class="obs"><b>Observaciones:</b> ' . nl2br($e($meta['observaciones'] ?? '')) . '</div>
<table class="firmas"><tr><td style="width:44%"></td>
<td><div class="flbl">Revisado por:</div><div class="farea"><span class="fval">Validado electrónicamente</span></div><div class="flinea"><b>' . $e(implode(', ', $meta['revisores'] ?? []) ?: '—') . '</b></div><div class="fsmall">' . $e($F['revisado_cargo']) . '</div></td>
<td><div class="flbl">Aprobado por:</div><div class="farea">' . ($firmado ? '<img src="' . $e($meta['firma_png']) . '" alt="Firma">' : '<span class="fpend">Pendiente de firma</span>') . '</div><div class="flinea"><b>' . $e(($firmante !== '' ? $firmante . ', ' : '') . $cfg['firmante_cargo']) . '</b></div><div class="fsmall">' . ($firmado ? 'Firmado electrónicamente el ' . $e($meta['fecha_firma_txt'] ?? '') : 'Pendiente de aprobación y firma') . '</div></td>
</tr></table>
<div class="pie">' . implode('', array_map(static fn($p) => '<p>' . $e($p) . '</p>', $cfg['pie'])) . (!empty($F['nota']) ? '<p>' . $e($F['nota']) . '</p>' : '') . '
<table class="pief"><tr><td>página 1/1' . (!empty($meta['codigo']) ? ' · ' . $e($meta['codigo']) : '') . '</td><td style="text-align:right">' . $e($cfg['direccion']) . '</td></tr></table></div>
</body></html>';
    }
}

if (!function_exists('lab_informe_xlsx')) {
    /** Descarga el informe en Excel (.xlsx) con el mismo formato de columnas (PhpSpreadsheet). */
    function lab_informe_xlsx(array $est, array $detalle, array $valores, array $meta): void
    {
        $autoload = __DIR__ . '/../../cengicursos/vendor/autoload.php';
        if (!is_file($autoload)) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=UTF-8');
            exit('El generador de Excel no está disponible en este ambiente.');
        }
        require_once $autoload;
        $cfg = lab_informe_formato_config();
        $F = $est['formato'];
        $cols = $est['columnas'];
        $idCols = $est['identificacion']['cols'];
        $nId = count($idCols);
        $nTotal = $nId + 1 + count($cols);
        $L = static fn(int $i) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
        $ultima = $L($nTotal);

        $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $libro->getProperties()->setCreator('CENGICAÑA · Laboratorio Agroindustrial')->setTitle(($meta['codigo'] ?? 'Informe') . ' · Lote ' . $detalle['lote']['codigo_lote']);
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle(mb_substr('Informe ' . $detalle['lote']['codigo_lote'], 0, 31));
        $hoja->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_LETTER)->setFitToWidth(1)->setFitToHeight(0);

        $fila = 1;
        $poner = static function (int $col, int $fila, $valor) use ($hoja, $L) {
            $hoja->setCellValueExplicit($L($col) . $fila, (string) $valor, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        };
        // Encabezado del documento.
        $poner(1, $fila, 'CENGICAÑA');
        $poner(2, $fila, $F['servicio'] . ' · ' . $F['encabezado']);
        $poner($nTotal, $fila, trim($F['codigo'] . ' ' . (!empty($F['lote_arriba']) ? 'Lote ' . $detalle['lote']['codigo_lote'] : (($F['version'] ?? '') . ' ' . ($F['fecha'] ?? '')))));
        if ($nTotal > 3) $hoja->mergeCells($L(2) . $fila . ':' . $L($nTotal - 1) . $fila);
        $hoja->getStyle('A1:' . $ultima . '1')->getFont()->setBold(true);
        $fila += 2;
        $poner(1, $fila, 'Laboratorio Agroindustrial');
        $hoja->mergeCells('A' . $fila . ':' . $ultima . $fila);
        $fila++;
        $poner(1, $fila, $F['titulo']);
        $hoja->mergeCells('A' . $fila . ':' . $ultima . $fila);
        $hoja->getStyle('A' . ($fila - 1) . ':A' . $fila)->getFont()->setBold(true)->setSize(12);
        $hoja->getStyle('A' . ($fila - 1) . ':A' . $fila)->getAlignment()->setHorizontal('center');
        $fila += 2;
        foreach (lab_informe_datos_encabezado($est, $detalle, $meta) as [$k, $v]) {
            $poner(1, $fila, $k);
            $poner(3, $fila, $v);
            $hoja->getStyle('A' . $fila)->getFont()->setBold(true);
            $fila++;
        }
        $fila++;

        // Tabla de resultados: dos filas de encabezado (títulos / unidades) como el PDF.
        $h1 = $fila; $h2 = $fila + 1;
        if ($est['identificacion']['grupo']) {
            $poner(1, $h1, $est['identificacion']['grupo']);
            if ($nId > 1) $hoja->mergeCells('A' . $h1 . ':' . $L($nId) . $h1);
            foreach ($idCols as $i => $c) $poner($i + 1, $h2, $c['h']);
        } else {
            foreach ($idCols as $i => $c) { $poner($i + 1, $h1, $c['h']); $hoja->mergeCells($L($i + 1) . $h1 . ':' . $L($i + 1) . $h2); }
        }
        $poner($nId + 1, $h1, 'No. Lab');
        $hoja->mergeCells($L($nId + 1) . $h1 . ':' . $L($nId + 1) . $h2);
        for ($i = 0; $i < count($cols);) {
            $c0 = $nId + 2 + $i;
            if (!empty($cols[$i]['hg'])) {
                $n = 0; $hg = $cols[$i]['hg'];
                while ($i + $n < count($cols) && ($cols[$i + $n]['hg'] ?? null) === $hg) $n++;
                $poner($c0, $h1, $hg);
                if ($n > 1) $hoja->mergeCells($L($c0) . $h1 . ':' . $L($c0 + $n - 1) . $h1);
                $i += $n;
            } else {
                $poner($c0, $h1, $cols[$i]['h'] . (!empty($cols[$i]['extra']) ? ' *' : ''));
                $i++;
            }
        }
        for ($i = 0; $i < count($cols);) {
            $c0 = $nId + 2 + $i;
            if (!empty($cols[$i]['g'])) {
                $n = 0; $g = $cols[$i]['g'];
                while ($i + $n < count($cols) && ($cols[$i + $n]['g'] ?? null) === $g) $n++;
                $poner($c0, $h2, $g);
                if ($n > 1) $hoja->mergeCells($L($c0) . $h2 . ':' . $L($c0 + $n - 1) . $h2);
                $i += $n;
            } else {
                $poner($c0, $h2, $cols[$i]['u'] ?? '');
                $i++;
            }
        }
        $rangoCab = 'A' . $h1 . ':' . $ultima . $h2;
        $hoja->getStyle($rangoCab)->getFont()->setBold(true)->setSize(9);
        $hoja->getStyle($rangoCab)->getAlignment()->setHorizontal('center')->setVertical('center')->setWrapText(true);
        $hoja->getStyle($rangoCab)->getFill()->setFillType('solid')->getStartColor()->setARGB('FFEEF1F4');
        $fila = $h2 + 1;
        $inicioDatos = $fila;
        foreach ($est['muestras'] as $m) {
            foreach ($idCols as $i => $c) {
                $txt = match ($c['k']) {
                    '__info', '__ident' => $c['k'] === '__ident' ? ($m['campos']['descripcion'] ?? $m['info']) : $m['info'],
                    '__vacio' => '',
                    default => $m['campos'][$c['k']] ?? '',
                };
                $poner($i + 1, $fila, $txt);
            }
            $poner($nId + 1, $fila, $m['codigo']);
            foreach ($cols as $i => $col) {
                $v = lab_informe_celda($est, $valores, (int) $m['id_muestra'], $i);
                $celda = $L($nId + 2 + $i) . $fila;
                // Los resultados numéricos se guardan como número (se pueden usar en fórmulas de Excel).
                if ($v['estado'] === 'ok' && is_numeric($v['txt'])) {
                    $hoja->setCellValue($celda, (float) $v['txt']);
                    $dec = (int) ($col['d'] ?? 2);
                    $hoja->getStyle($celda)->getNumberFormat()->setFormatCode($dec > 0 ? '0.' . str_repeat('0', $dec) : '0');
                } else {
                    $poner($nId + 2 + $i, $fila, $v['txt']);
                }
            }
            $fila++;
        }
        $rangoTabla = 'A' . $h1 . ':' . $ultima . ($fila - 1);
        $hoja->getStyle($rangoTabla)->getBorders()->getAllBorders()->setBorderStyle('thin')->getColor()->setARGB('FF9AA3AD');
        if ($fila > $inicioDatos) {
            $hoja->getStyle($L($nId + 1) . $inicioDatos . ':' . $ultima . ($fila - 1))->getAlignment()->setHorizontal('center');
        }
        $poner(1, $fila, '---- última línea ----');
        $hoja->mergeCells('A' . $fila . ':' . $ultima . $fila);
        $hoja->getStyle('A' . $fila)->getAlignment()->setHorizontal('center');
        $fila += 2;
        if (array_filter(array_column($cols, 'extra'))) { $poner(1, $fila++, $cfg['nota_extra']); }
        if (!empty($F['metodos_por_analisis'])) {
            $poner(1, $fila++, 'Metodología:');
            foreach (array_unique(array_column($cols, 'an')) as $an) {
                if (!isset($F['metodos_por_analisis'][$an])) continue;
                $poner(1, $fila, $F['metodos_etiqueta'][$an] ?? $an);
                $poner(3, $fila++, $F['metodos_por_analisis'][$an]);
            }
        } elseif (!empty($F['metodos'])) {
            $poner(1, $fila, 'Métodos de Análisis: ' . $F['metodos']);
            $hoja->mergeCells('A' . $fila . ':' . $ultima . $fila);
            $hoja->getStyle('A' . $fila)->getAlignment()->setWrapText(true);
            $hoja->getRowDimension($fila)->setRowHeight(42);
            $fila++;
        }
        $poner(1, $fila++, 'Observaciones: ' . ($meta['observaciones'] ?? ''));
        $fila++;
        $poner(1, $fila++, 'Revisado por: ' . (implode(', ', $meta['revisores'] ?? []) ?: '—') . ' · ' . $F['revisado_cargo'] . ' · Validado electrónicamente');
        $poner(1, $fila++, 'Aprobado por: ' . trim(($meta['firmante'] ?? '') . ', ' . $cfg['firmante_cargo'], ', ')
            . (!empty($meta['firma_png']) ? ' · Firmado electrónicamente el ' . ($meta['fecha_firma_txt'] ?? '') : ' · Pendiente de aprobación y firma'));
        $fila++;
        foreach ($cfg['pie'] as $p) $poner(1, $fila++, $p);
        if (!empty($F['nota'])) $poner(1, $fila++, $F['nota']);
        $poner(1, $fila, ($meta['codigo'] ?? '') . ' · ' . $cfg['direccion']);

        foreach (range(1, $nTotal) as $i) {
            $hoja->getColumnDimension($L($i))->setWidth($i <= $nId ? 16 : ($i === $nId + 1 ? 14 : 11));
        }
        $hoja->freezePane('A' . ($h2 + 1));

        $nombre = preg_replace('/[^A-Za-z0-9_-]+/', '_', 'Informe_' . ($meta['codigo'] ?: 'borrador') . '_Lote_' . $detalle['lote']['codigo_lote']) . '.xlsx';
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nombre . '"');
        header('Cache-Control: no-store');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save('php://output');
        exit;
    }
}
