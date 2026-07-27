<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/xlsx_archive_helper.php';

// Prevent suelos_export_excel_controller from executing directly when included
define('SUELOS_EXPORT_SKIP_RUN', true);
require_once __DIR__ . '/suelos_export_excel_controller.php';
require_once __DIR__ . '/../conexion.php';

general_handle_export_request();

function general_handle_export_request(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id_lote']) || empty($_POST['tipo_reporte'])) {
        http_response_code(405);
        echo 'Solicitud no permitida.';
        exit;
    }

    lab_require_module_access();

    $idLote = (int) $_POST['id_lote'];
    $tipoReporte = trim((string) $_POST['tipo_reporte']); // suelos, aguas, foliares, cana

    $pdo = Conexion::conectar();
    $reporte = suelos_get_report_metadata($pdo, $idLote);

    if (!$reporte) {
        http_response_code(404);
        echo 'Lote no encontrado.';
        exit;
    }

    // Determine template configuration based on report type
    $config = general_get_report_config($tipoReporte);
    if (!$config) {
        http_response_code(400);
        echo 'Tipo de reporte no soportado.';
        exit;
    }

    // Retrieve consolidated data records
    $registros = [];
    if ($tipoReporte === 'suelos') {
        $registros = suelos_get_consolidated_data($pdo, $idLote, $reporte);
    } elseif ($tipoReporte === 'aguas') {
        $registros = aguas_get_consolidated_data($pdo, $idLote, $reporte);
    } elseif ($tipoReporte === 'foliares') {
        $registros = foliares_get_consolidated_data($pdo, $idLote, $reporte);
    } elseif ($tipoReporte === 'cana') {
        $registros = cana_get_consolidated_data($pdo, $idLote, $reporte);
    }

    $templatePath = general_find_template_path($config['template_file']);
    if ($templatePath === null) {
        http_response_code(500);
        echo 'Error: No se encontró la plantilla del reporte.';
        exit;
    }

    general_export_xlsx_from_template($templatePath, $reporte, $registros, $config, $tipoReporte);
}

function general_get_report_config(string $tipoReporte): ?array
{
    switch ($tipoReporte) {
        case 'suelos':
            return [
                'template_file' => '001Nuevo formato de Informe para Suelos VF 015.xlsx',
                'data_start_row' => 20,
                'footer_start_row' => 83,
                'template_data_rows' => 63,
                'last_column' => 'AA',
                'columns' => [
                    'A' => 'finca', 'B' => 'codigo', 'C' => 'numero_laboratorio',
                    'D' => 'ce', 'E' => 'ph', 'F' => 'porcentaje_n', 'G' => 'ppm_suelo',
                    'H' => 'ppm_k', 'I' => 'ppm_ca', 'J' => 'ppm_mg', 'K' => 'ppm_na',
                    'L' => 'ppm_so4', 'M' => 'ppm_cu', 'N' => 'ppm_zn', 'O' => 'ppm_fe',
                    'P' => 'ppm_mn', 'Q' => 'ppm_b', 'R' => 'materia_organica', 'S' => 'cic_meq',
                    'T' => 'arcilla', 'U' => 'limo', 'V' => 'arena', 'W' => 'tipo_textura',
                    'X' => 'porcentaje_pmp', 'Y' => 'porcentaje_cc', 'Z' => 'densidad',
                    'AA' => 'humedad'
                ]
            ];
        case 'aguas':
            return [
                'template_file' => '2. Formato Reporte de Aguas.xlsx',
                'data_start_row' => 20,
                'footer_start_row' => 40,
                'template_data_rows' => 20,
                'last_column' => 'Y',
                'columns' => [
                    'A' => 'finca',
                    'C' => 'codigo_lote',
                    'D' => 'numero_laboratorio',
                    'E' => 'ce',
                    'F' => 'ph',
                    'G' => 'tds',
                    'H' => 'cloruros',
                    'I' => 'dureza',
                    'J' => 'alcalinidad',
                    'K' => 'carbonatos',
                    'L' => 'bicarbonatos',
                    'M' => 'ras',
                    'N' => 'resistividad',
                    'O' => 'salinidad',
                    'P' => 'fosforo',
                    'Q' => 'boro',
                    'R' => 'calcio',
                    'S' => 'magnesio',
                    'T' => 'potasio',
                    'U' => 'sodio',
                    'V' => 'cobre',
                    'W' => 'zinc',
                    'X' => 'hierro',
                    'Y' => 'manganeso'
                ]
            ];
        case 'foliares':
            return [
                'template_file' => 'Formato de informes de Foliares VF_015.xlsx',
                'data_start_row' => 17,
                'footer_start_row' => 41,
                'template_data_rows' => 24,
                'last_column' => 'P',
                'columns' => [
                    'A' => 'identificacion',
                    'B' => 'finca',
                    'C' => 'codigo_lote',
                    'D' => 'pante',
                    'E' => 'numero_laboratorio',
                    'F' => 'nitrogeno',
                    'G' => 'calcio',
                    'H' => 'magnesio',
                    'I' => 'potasio',
                    'J' => 'fosforo',
                    'K' => 'boro',
                    'L' => 'cobre',
                    'M' => 'cinc',
                    'N' => 'hierro',
                    'O' => 'manganeso',
                    'P' => 'humedad'
                ]
            ];
        case 'cana':
            return [
                'template_file' => 'SAL-LAG-FOR-004 Reporte de análisis de caña.xlsx',
                'data_start_row' => 16,
                'footer_start_row' => 44,
                'template_data_rows' => 28,
                'last_column' => 'K',
                'columns' => [
                    'A' => 'humedad',
                    'B' => 'numero_laboratorio',
                    'C' => 'brix',
                    'D' => 'fibra',
                    'E' => 'pureza_jugo',
                    'F' => 'porcentaje_jugo',
                    'G' => 'porcentaje_pol_cana',
                    'H' => 'rendimiento_comercial_lbs',
                    'I' => 'rendimiento_comercial_kg',
                    'J' => 'rendimiento_real_lbs',
                    'K' => 'rendimiento_real_kg'
                ]
            ];
        default:
            return null;
    }
}

function general_find_template_path(string $templateFile): ?string
{
    $paths = [
        __DIR__ . '/../templates/' . $templateFile,
        dirname(__DIR__, 2) . '/' . $templateFile,
        dirname(__DIR__, 3) . '/' . $templateFile,
        'c:/xampp/htdocs/login/' . $templateFile,
    ];

    foreach ($paths as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

// Data consolidators for Waters (Aguas)
function aguas_get_num_labs(PDO $pdo, int $idLote): array
{
    $tables = [
        'agua_conductividad', 'agua_ph', 'agua_tds', 'agua_cloruros', 'agua_dureza', 
        'agua_alcalinidad', 'agua_carbonatos', 'agua_bicarbonatos', 'agua_ras', 
        'agua_resistividad', 'agua_salinidad', 'agua_fosforo', 'agua_boro', 
        'agua_macros', 'agua_micros'
    ];
    $parts = [];
    $params = [];

    foreach ($tables as $table) {
        if (!suelos_table_has_columns($pdo, $table, ['id_lote', 'numero_laboratorio'])) {
            continue;
        }
        $parts[] = "SELECT DISTINCT numero_laboratorio FROM `$table` WHERE id_lote = ? AND numero_laboratorio IS NOT NULL";
        $params[] = $idLote;
    }

    if (!$parts) {
        return [];
    }

    $stmt = $pdo->prepare(implode(' UNION ', $parts) . ' ORDER BY numero_laboratorio');
    $stmt->execute($params);

    return array_map(static fn($row) => $row['numero_laboratorio'], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function aguas_get_consolidated_data(PDO $pdo, int $idLote, array $reporte): array
{
    $numLabs = aguas_get_num_labs($pdo, $idLote);
    $registros = [];

    foreach ($numLabs as $numLab) {
        $row = [
            'finca' => $reporte['finca'] ?? '',
            'codigo_lote' => $reporte['codigo_lote'] ?? '',
            'numero_laboratorio' => suelos_get_lab_code($pdo, $idLote, $numLab) ?: $numLab,
        ];

        $ce = suelos_fetch_latest_with_fallback($pdo, 'agua_conductividad', $idLote, $numLab);
        $ph = suelos_fetch_latest_with_fallback($pdo, 'agua_ph', $idLote, $numLab);
        $tds = suelos_fetch_latest_with_fallback($pdo, 'agua_tds', $idLote, $numLab);
        $cloruros = suelos_fetch_latest_with_fallback($pdo, 'agua_cloruros', $idLote, $numLab);
        $dureza = suelos_fetch_latest_with_fallback($pdo, 'agua_dureza', $idLote, $numLab);
        $alcalinidad = suelos_fetch_latest_with_fallback($pdo, 'agua_alcalinidad', $idLote, $numLab);
        $carbonatos = suelos_fetch_latest_with_fallback($pdo, 'agua_carbonatos', $idLote, $numLab);
        $bicarbonatos = suelos_fetch_latest_with_fallback($pdo, 'agua_bicarbonatos', $idLote, $numLab);
        $ras = suelos_fetch_latest_with_fallback($pdo, 'agua_ras', $idLote, $numLab);
        $resistividad = suelos_fetch_latest_with_fallback($pdo, 'agua_resistividad', $idLote, $numLab);
        $salinidad = suelos_fetch_latest_with_fallback($pdo, 'agua_salinidad', $idLote, $numLab);
        $fosforo = suelos_fetch_latest_with_fallback($pdo, 'agua_fosforo', $idLote, $numLab);
        $boro = suelos_fetch_latest_with_fallback($pdo, 'agua_boro', $idLote, $numLab);
        $macros = suelos_fetch_latest_with_fallback($pdo, 'agua_macros', $idLote, $numLab);
        $micros = suelos_fetch_latest_with_fallback($pdo, 'agua_micros', $idLote, $numLab);

        $row += [
            'ce' => suelos_first_value($ce['ce'] ?? null),
            'ph' => suelos_first_value($ph['ph'] ?? null),
            'tds' => suelos_first_value($tds['tds_mgl'] ?? null),
            'cloruros' => suelos_first_value($cloruros['cloruros_mgl'] ?? null),
            'dureza' => suelos_first_value($dureza['dureza'] ?? null),
            'alcalinidad' => suelos_first_value($alcalinidad['alcalinidad_mgl'] ?? null),
            'carbonatos' => suelos_first_value($carbonatos['carbonatos'] ?? null),
            'bicarbonatos' => suelos_first_value($bicarbonatos['bicarbonatos_mgl'] ?? null),
            'ras' => suelos_first_value($ras['ras'] ?? null),
            'resistividad' => suelos_first_value($resistividad['lectura_resistividad'] ?? null),
            'salinidad' => suelos_first_value($salinidad['lectura_psu'] ?? null),
            'fosforo' => suelos_first_value($fosforo['ppm_p'] ?? null),
            'boro' => suelos_first_value($boro['boro'] ?? null),
            'calcio' => suelos_first_value($macros['ca_mgl'] ?? null),
            'magnesio' => suelos_first_value($macros['mg_mgl'] ?? null),
            'potasio' => suelos_first_value($macros['k_mgl'] ?? null),
            'sodio' => suelos_first_value($macros['na_mgl'] ?? null),
            'cobre' => suelos_first_value($micros['cu_mgl'] ?? null),
            'zinc' => suelos_first_value($micros['zn_mgl'] ?? null),
            'hierro' => suelos_first_value($micros['fe_mgl'] ?? null),
            'manganeso' => suelos_first_value($micros['mn_mgl'] ?? null),
        ];

        $registros[] = $row;
    }

    return $registros;
}

// Data consolidators for Foliars (Foliares)
function foliares_get_num_labs(PDO $pdo, int $idLote): array
{
    $tables = ['foliar_nitrogeno', 'foliar_macros', 'foliar_fosforo', 'foliar_boro', 'foliar_micros', 'foliar_humedad', 'foliar_quimicos'];
    $parts = [];
    $params = [];

    foreach ($tables as $table) {
        if (!suelos_table_has_columns($pdo, $table, ['id_lote', 'numero_laboratorio'])) {
            continue;
        }
        $parts[] = "SELECT DISTINCT numero_laboratorio FROM `$table` WHERE id_lote = ? AND numero_laboratorio IS NOT NULL";
        $params[] = $idLote;
    }

    if (!$parts) {
        return [];
    }

    $stmt = $pdo->prepare(implode(' UNION ', $parts) . ' ORDER BY numero_laboratorio');
    $stmt->execute($params);

    return array_map(static fn($row) => $row['numero_laboratorio'], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function foliares_get_consolidated_data(PDO $pdo, int $idLote, array $reporte): array
{
    $numLabs = foliares_get_num_labs($pdo, $idLote);
    $registros = [];

    foreach ($numLabs as $numLab) {
        $row = [
            'identificacion' => $reporte['codigo_muestreo'] ?? '',
            'finca' => $reporte['finca'] ?? '',
            'codigo_lote' => $reporte['codigo_lote'] ?? '',
            'pante' => '',
            'numero_laboratorio' => suelos_get_lab_code($pdo, $idLote, $numLab) ?: $numLab,
        ];

        $nitrogeno = suelos_fetch_latest($pdo, 'foliar_nitrogeno', $idLote, $numLab);
        $macros = suelos_fetch_latest($pdo, 'foliar_macros', $idLote, $numLab);
        $fosforo = suelos_fetch_latest($pdo, 'foliar_fosforo', $idLote, $numLab);
        $boro = suelos_fetch_latest($pdo, 'foliar_boro', $idLote, $numLab);
        $micros = suelos_fetch_latest($pdo, 'foliar_micros', $idLote, $numLab);
        $humedad = suelos_fetch_latest($pdo, 'foliar_humedad', $idLote, $numLab);

        $row += [
            'nitrogeno' => suelos_first_value($nitrogeno['resultado'] ?? null),
            'calcio' => suelos_first_value($macros['calcio'] ?? null),
            'magnesio' => suelos_first_value($macros['magnesio'] ?? null),
            'potasio' => suelos_first_value($macros['potasio'] ?? null),
            'fosforo' => suelos_first_value($fosforo['porcentaje_p'] ?? null),
            'boro' => suelos_first_value($boro['resultado'] ?? null),
            'cobre' => suelos_first_value($micros['ppm_cu'] ?? null),
            'cinc' => suelos_first_value($micros['ppm_zn'] ?? null),
            'hierro' => suelos_first_value($micros['ppm_fe'] ?? null),
            'manganeso' => suelos_first_value($micros['ppm_mn'] ?? null),
            'humedad' => suelos_first_value($humedad['humedad'] ?? null),
        ];

        $registros[] = $row;
    }

    return $registros;
}

// Data consolidators for Sugar Cane (Caña)
function cana_get_num_labs(PDO $pdo, int $idLote): array
{
    $tables = ['cana_brixpol', 'cana_humedad', 'cana_fibra', 'cana_peso_seco', 'cana_ph'];
    $parts = [];
    $params = [];

    foreach ($tables as $table) {
        if (!suelos_table_has_columns($pdo, $table, ['id_lote', 'numero_laboratorio'])) {
            continue;
        }
        $parts[] = "SELECT DISTINCT numero_laboratorio FROM `$table` WHERE id_lote = ? AND numero_laboratorio IS NOT NULL";
        $params[] = $idLote;
    }

    if (!$parts) {
        return [];
    }

    $stmt = $pdo->prepare(implode(' UNION ', $parts) . ' ORDER BY numero_laboratorio');
    $stmt->execute($params);

    return array_map(static fn($row) => $row['numero_laboratorio'], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function cana_get_consolidated_data(PDO $pdo, int $idLote, array $reporte): array
{
    $numLabs = cana_get_num_labs($pdo, $idLote);
    $registros = [];

    foreach ($numLabs as $numLab) {
        $row = [
            'numero_laboratorio' => suelos_get_lab_code($pdo, $idLote, $numLab) ?: $numLab,
        ];

        $brixpol = suelos_fetch_latest($pdo, 'cana_brixpol', $idLote, $numLab);
        $humedad = suelos_fetch_latest($pdo, 'cana_humedad', $idLote, $numLab);
        $fibra = suelos_fetch_latest($pdo, 'cana_fibra', $idLote, $numLab);

        $row += [
            'humedad' => suelos_first_value($humedad['porcentaje_humedad'] ?? null),
            'brix' => suelos_first_value($brixpol['brix'] ?? null),
            'fibra' => suelos_first_value($fibra['fibra'] ?? null),
            'pureza_jugo' => suelos_first_value($brixpol['pureza_jugo'] ?? null),
            'porcentaje_jugo' => suelos_first_value($brixpol['porcentaje_jugo'] ?? null),
            'porcentaje_pol_cana' => suelos_first_value($brixpol['porcentaje_pol_cana'] ?? null),
            'rendimiento_comercial_lbs' => suelos_first_value($brixpol['rendimiento_comercial_lbs'] ?? null),
            'rendimiento_comercial_kg' => suelos_first_value($brixpol['rendimiento_comercial_kg'] ?? null),
            'rendimiento_real_lbs' => suelos_first_value($brixpol['rendimiento_real_lbs'] ?? null),
            'rendimiento_real_kg' => suelos_first_value($brixpol['rendimiento_real_kg'] ?? null),
        ];

        $registros[] = $row;
    }

    return $registros;
}

function general_export_xlsx_from_template(string $templatePath, array $reporte, array $registros, array $config, string $tipoReporte): void
{
    $tmpPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'reporte_general_' . uniqid('', true) . '.xlsx';

    $ok = lab_export_xlsx_template(
        $templatePath,
        $tmpPath,
        static function (string $sheetXml) use ($reporte, $registros, $config, $tipoReporte): string {
            return general_build_sheet_xml($sheetXml, $reporte, $registros, $config, $tipoReporte);
        }
    );

    if (!$ok) {
        @unlink($tmpPath);
        http_response_code(500);
        echo 'Error al generar el archivo Excel del reporte.';
        exit;
    }

    $prefixMap = [
        'suelos' => 'Analisis_Suelos_',
        'aguas' => 'Reporte_Aguas_',
        'foliares' => 'Analisis_Foliares_',
        'cana' => 'Reporte_Cana_'
    ];
    $prefix = $prefixMap[$tipoReporte] ?? 'Reporte_';
    $filename = $prefix . suelos_safe_filename($reporte['codigo_lote'] ?? 'lote') . '_' . date('YmdHis') . '.xlsx';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmpPath));
    header('Cache-Control: max-age=0');

    readfile($tmpPath);
    @unlink($tmpPath);
    exit;
}

function general_build_sheet_xml(string $sheetXml, array $reporte, array $registros, array $config, string $tipoReporte): string
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = false;
    $dom->loadXML($sheetXml);

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $dataRows = max(count($registros), 1);
    $extraRows = max(0, $dataRows - $config['template_data_rows']);

    // Shift rows and merge cell references if we have more rows than the template pre-allocated space
    if ($extraRows > 0) {
        suelos_shift_rows($xpath, $config['footer_start_row'], $extraRows);
        suelos_shift_merge_cells($xpath, $config['footer_start_row'], $extraRows);
    }

    // Populate metadata at top of sheet
    if ($tipoReporte === 'suelos') {
        suelos_set_cell_value($dom, $xpath, 'A12', 'Código del muestreo:  ' . ($reporte['codigo_muestreo'] ?? ''));
        suelos_set_cell_value($dom, $xpath, 'A13', 'Ingenio:  ' . ($reporte['ingenio'] ?? ''));
        suelos_set_cell_value($dom, $xpath, 'A14', 'Fecha de Ingreso:   ' . ($reporte['fecha_ingreso'] ?? ''));
        suelos_set_cell_value($dom, $xpath, 'A15', 'Fecha de Entrega:  ' . ($reporte['fecha_entrega'] ?? ''));
    } elseif ($tipoReporte === 'aguas') {
        suelos_set_cell_value($dom, $xpath, 'A12', 'Código del muestreo:  ' . ($reporte['codigo_muestreo'] ?? ''));
        suelos_set_cell_value($dom, $xpath, 'A13', 'Ingenio:   ' . ($reporte['ingenio'] ?? ''));
        suelos_set_cell_value($dom, $xpath, 'A14', 'Fecha de Ingreso:   ' . ($reporte['fecha_ingreso'] ?? ''));
        suelos_set_cell_value($dom, $xpath, 'A15', 'Fecha de Entrega:  ' . ($reporte['fecha_entrega'] ?? ''));
    } elseif ($tipoReporte === 'foliares') {
        suelos_set_cell_value($dom, $xpath, 'A11', 'Ingenio:  ' . ($reporte['ingenio'] ?? ''));
        suelos_set_cell_value($dom, $xpath, 'A12', 'Fecha de Ingreso:  ' . ($reporte['fecha_ingreso'] ?? ''));
        suelos_set_cell_value($dom, $xpath, 'A13', 'Fecha de Entrega:  ' . ($reporte['fecha_entrega'] ?? ''));
    } elseif ($tipoReporte === 'cana') {
        suelos_set_cell_value($dom, $xpath, 'B1', 'FINCA:  ' . ($reporte['finca'] ?? ''));
        suelos_set_cell_value($dom, $xpath, 'O2', 'LOTE: ' . ($reporte['codigo_muestreo'] ?? ''));
        suelos_set_cell_value($dom, $xpath, 'A11', 'FECHA DE INGRESO: ' . ($reporte['fecha_ingreso'] ?? ''));
        suelos_set_cell_value($dom, $xpath, 'J11', 'FECHA DE REPORTE: ' . date('d/m/Y'));
    }

    // Populate data grid rows
    $columns = $config['columns'];
    $styleMap = general_data_style_map($xpath, $config);
    $totalRowsToClear = max($config['template_data_rows'], $dataRows);

    for ($offset = 0; $offset < $totalRowsToClear; $offset++) {
        $rowNumber = $config['data_start_row'] + $offset;
        $rowData = $registros[$offset] ?? null;

        foreach ($columns as $column => $field) {
            $value = $rowData[$field] ?? '';
            $style = $styleMap[$column] ?? '15';
            suelos_set_cell_value($dom, $xpath, $column . $rowNumber, $value, $style);
        }
    }

    // Update sheet dimensions
    general_update_dimension($xpath, $config['last_column'], $config['footer_start_row'] + $extraRows + 30);
    suelos_sort_sheet_rows($xpath);

    return $dom->saveXML();
}

function general_data_style_map(DOMXPath $xpath, array $config): array
{
    $map = [];
    foreach (array_keys($config['columns']) as $column) {
        $cell = suelos_find_cell($xpath, $column . $config['data_start_row']);
        if ($cell instanceof DOMElement && $cell->hasAttribute('s')) {
            $map[$column] = $cell->getAttribute('s');
        }
    }
    return $map;
}

function general_update_dimension(DOMXPath $xpath, string $lastColumn, int $lastRow): void
{
    $dimension = $xpath->query('//m:dimension')->item(0);
    if ($dimension instanceof DOMElement) {
        $dimension->setAttribute('ref', 'A1:' . $lastColumn . $lastRow);
    }
}
