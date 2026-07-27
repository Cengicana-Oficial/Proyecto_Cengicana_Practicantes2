<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/xlsx_archive_helper.php';

const SUELOS_TEMPLATE_FILE = '001Nuevo formato de Informe para Suelos VF 015.xlsx';
const SUELOS_DATA_START_ROW = 20;
const SUELOS_FOOTER_START_ROW = 83;
const SUELOS_TEMPLATE_DATA_ROWS = 63;
const SUELOS_LAST_COLUMN = 'AA';

if (!defined('SUELOS_EXPORT_SKIP_RUN')) {
    suelos_handle_export_request();
}

function suelos_handle_export_request(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['id_lote'])) {
        http_response_code(405);
        echo 'Solicitud no permitida.';
        exit;
    }

    lab_require_module_access();
    require_once __DIR__ . '/../conexion.php';

    $idLote = (int) $_POST['id_lote'];
    $pdo = Conexion::conectar();
    $reporte = suelos_get_report_metadata($pdo, $idLote);

    if (!$reporte) {
        http_response_code(404);
        echo 'Lote no encontrado.';
        exit;
    }

    $registros = suelos_get_consolidated_data($pdo, $idLote, $reporte);

    if (($templatePath = suelos_find_template_path()) !== null) {
        suelos_export_xlsx_from_template($templatePath, $reporte, $registros);
    }

    suelos_export_xlsx_native($reporte, $registros);
}

function suelos_get_report_metadata(PDO $pdo, int $idLote): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            l.*,
            s.id_solicitud,
            s.codigo_muestreo,
            s.fecha_muestreo,
            s.numero_muestras,
            s.institucion,
            s.responsable_envio,
            s.ingresado_por,
            s.recibido_por,
            s.fecha_ingreso,
            s.fecha_estimada,
            s.observaciones,
            tm.nombre AS tipo_muestra
        FROM lote l
        LEFT JOIN solicitud s ON s.id_lote = l.id_lote
        LEFT JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo
        WHERE l.id_lote = ?
        ORDER BY
            CASE
                WHEN LOWER(COALESCE(tm.nombre, '')) IN ('suelos', 'suelo') THEN 0
                WHEN s.id_solicitud IS NULL THEN 2
                ELSE 1
            END,
            s.id_solicitud DESC
        LIMIT 1
    ");
    $stmt->execute([$idLote]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $codigoMuestreo = trim((string) ($row['codigo_muestreo'] ?? ''));
    if ($codigoMuestreo === '') {
        $codigoMuestreo = (string) ($row['codigo_lote'] ?? '');
    }

    return [
        'id_lote' => (int) $row['id_lote'],
        'codigo_lote' => (string) ($row['codigo_lote'] ?? ''),
        'codigo_muestreo' => $codigoMuestreo,
        'ingenio' => (string) ($row['institucion'] ?? ''),
        'fecha_ingreso' => suelos_format_date($row['fecha_ingreso'] ?? null),
        'fecha_entrega' => suelos_format_date($row['fecha_estimada'] ?? null),
        'finca' => (string) ($row['finca'] ?? $row['nombre_finca'] ?? ''),
    ];
}

function suelos_get_consolidated_data(PDO $pdo, int $idLote, array $reporte): array
{
    $numLabs = suelos_get_num_labs($pdo, $idLote);
    $registros = [];

    foreach ($numLabs as $numLab) {
        $row = [
            'finca' => $reporte['finca'] ?? '',
            'codigo' => $reporte['codigo_lote'] ?? '',
            'numero_laboratorio' => suelos_get_lab_code($pdo, $idLote, $numLab) ?: $numLab,
        ];

        $ce = suelos_fetch_latest_with_fallback($pdo, 'suelo_ce', $idLote, $numLab);
        $ph = suelos_fetch_latest_with_fallback($pdo, 'suelo_ph', $idLote, $numLab);
        $phAlt = suelos_fetch_latest_with_fallback($pdo, 'suelo_ph_agua', $idLote, $numLab);
        $nitrogeno = suelos_fetch_latest_with_fallback($pdo, 'suelo_nitrogeno', $idLote, $numLab);
        $fosforo = suelos_fetch_latest_with_fallback($pdo, 'suelo_fosforo', $idLote, $numLab);
        $macros = suelos_fetch_latest_with_fallback($pdo, 'suelo_macros', $idLote, $numLab);
        $azufre = suelos_fetch_latest_with_fallback($pdo, 'suelo_azufre', $idLote, $numLab);
        $micros = suelos_fetch_latest_with_fallback($pdo, 'suelo_micros', $idLote, $numLab);
        $boro = suelos_fetch_latest_with_fallback($pdo, 'suelo_boro', $idLote, $numLab);
        $materiaOrganica = suelos_fetch_latest_with_fallback($pdo, 'MO_Porcentaje', $idLote, $numLab);
        $materiaOrganicaAlt = suelos_fetch_latest_with_fallback($pdo, 'suelo_materia_organica', $idLote, $numLab);
        $cc = suelos_fetch_latest_with_fallback($pdo, 'suelo_cc', $idLote, $numLab);
        $pmp = suelos_fetch_latest_with_fallback($pdo, 'suelo_pmp', $idLote, $numLab);
        $dap = suelos_fetch_latest_with_fallback($pdo, 'suelo_dap', $idLote, $numLab);
        $humedad = suelos_fetch_latest_with_fallback($pdo, 'laboratorio_humedad', $idLote, $numLab);
        $humedadAlt = suelos_fetch_latest_with_fallback($pdo, 'suelo_humedad_gravimetrica', $idLote, $numLab);
        $humedadResidual = suelos_fetch_latest_with_fallback($pdo, 'suelo_humedad_residual', $idLote, $numLab);
        $humedadSimple = suelos_fetch_latest_with_fallback($pdo, 'suelo_humedad', $idLote, $numLab);
        $textura = suelos_fetch_latest_with_fallback($pdo, 'analisis_textura', $idLote, $numLab);
        $quimicos = suelos_fetch_latest_with_fallback($pdo, 'suelo_quimicos', $idLote, $numLab);

        $row += [
            'ce' => suelos_first_value($ce['ce'] ?? null),
            'ph' => suelos_first_value($ph['ph'] ?? null, $phAlt['ph'] ?? null),
            'porcentaje_n' => suelos_first_value($nitrogeno['porcentaje_n'] ?? null, $quimicos['nitrogeno'] ?? null),
            'ppm_suelo' => suelos_first_value($fosforo['ppm_suelo'] ?? null, $quimicos['fosforo'] ?? null),
            'ppm_k' => suelos_first_value($macros['ppm_k'] ?? null, $quimicos['potasio'] ?? null),
            'ppm_ca' => suelos_first_value($macros['ppm_ca'] ?? null, $quimicos['calcio'] ?? null),
            'ppm_mg' => suelos_first_value($macros['ppm_mg'] ?? null, $quimicos['magnesio'] ?? null),
            'ppm_na' => suelos_first_value($macros['ppm_na'] ?? null, $quimicos['sodio'] ?? null),
            'ppm_so4' => suelos_first_value($azufre['ppm_so4'] ?? null, $quimicos['azufre'] ?? null),
            'ppm_cu' => suelos_first_value($micros['ppm_cu'] ?? null),
            'ppm_zn' => suelos_first_value($micros['ppm_zn'] ?? null),
            'ppm_fe' => suelos_first_value($micros['ppm_fe'] ?? null),
            'ppm_mn' => suelos_first_value($micros['ppm_mn'] ?? null),
            'ppm_b' => suelos_first_value($boro['ppm_b'] ?? null),
            'materia_organica' => suelos_first_value(
                $materiaOrganica['porcentaje_materia_organica'] ?? null,
                $materiaOrganicaAlt['materia_organica'] ?? null
            ),
            'cic_meq' => suelos_first_value($macros['cic_meq'] ?? null),
            'arcilla' => suelos_first_value($textura['porcentaje_arcilla'] ?? null),
            'limo' => suelos_first_value($textura['porcentaje_limo'] ?? null),
            'arena' => suelos_first_value($textura['porcentaje_arena'] ?? null),
            'tipo_textura' => suelos_first_value($textura['textura'] ?? null),
            'porcentaje_pmp' => suelos_first_value($pmp['porcentaje_pmp'] ?? null),
            'porcentaje_cc' => suelos_first_value($cc['porcentaje_cc'] ?? null),
            'densidad' => suelos_first_value($dap['densidad'] ?? null),
            'humedad' => suelos_first_value(
                $humedad['PorHGrav'] ?? null,
                $humedadAlt['h_grav'] ?? null,
                $humedadResidual['humedad_residual'] ?? null,
                $humedadSimple['humedad'] ?? null
            ),
        ];

        $registros[] = $row;
    }

    return $registros;
}

function suelos_get_num_labs(PDO $pdo, int $idLote): array
{
    $tables = [
        'suelo_ph',
        'suelo_ph_agua',
        'suelo_ce',
        'suelo_nitrogeno',
        'suelo_fosforo',
        'suelo_macros',
        'suelo_azufre',
        'suelo_micros',
        'suelo_boro',
        'MO_Porcentaje',
        'suelo_materia_organica',
        'suelo_quimicos',
        'suelo_cc',
        'suelo_pmp',
        'suelo_dap',
        'suelo_humedad',
        'suelo_humedad_gravimetrica',
        'suelo_humedad_residual',
        'analisis_textura',
        'laboratorio_humedad',
    ];

    $parts = [];
    $params = [];
    $formularioIds = suelos_get_formulario_ids_for_lote($pdo, $idLote);
    $formPlaceholders = $formularioIds ? implode(', ', array_fill(0, count($formularioIds), '?')) : '';
    $sampleKeys = suelos_get_sample_keys_for_lote($pdo, $idLote);
    $samplePlaceholders = $sampleKeys ? implode(', ', array_fill(0, count($sampleKeys), '?')) : '';

    foreach ($tables as $table) {
        $columns = suelos_table_has_columns($pdo, $table, ['id_lote', 'numero_laboratorio']);
        if ($columns) {
            $parts[] = "SELECT DISTINCT numero_laboratorio FROM `$table` WHERE id_lote = ? AND numero_laboratorio IS NOT NULL";
            $params[] = $idLote;
        }

        if ($formularioIds && suelos_table_has_columns($pdo, $table, ['id_formulario', 'no_lab'])) {
            $parts[] = "SELECT DISTINCT no_lab AS numero_laboratorio FROM `$table` WHERE id_formulario IN ($formPlaceholders) AND no_lab IS NOT NULL AND no_lab <> ''";
            array_push($params, ...$formularioIds);
        }

        if ($sampleKeys && suelos_table_has_columns($pdo, $table, ['no_lab'])) {
            $parts[] = "SELECT DISTINCT no_lab AS numero_laboratorio FROM `$table` WHERE no_lab IN ($samplePlaceholders)";
            array_push($params, ...$sampleKeys);
        }
    }

    if (!$parts) {
        return [];
    }

    $stmt = $pdo->prepare(implode(' UNION ', $parts) . ' ORDER BY numero_laboratorio');
    $stmt->execute($params);

    return array_map(static fn($row) => $row['numero_laboratorio'], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function suelos_get_sample_keys_for_lote(PDO $pdo, int $idLote): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT CAST(m.numero_muestra AS CHAR) AS valor
              FROM muestra m
              INNER JOIN solicitud s ON s.id_solicitud = m.id_solicitud
             WHERE s.id_lote = ?
               AND m.numero_muestra IS NOT NULL
            UNION
            SELECT DISTINCT m.codigo_lab AS valor
              FROM muestra m
              INNER JOIN solicitud s ON s.id_solicitud = m.id_solicitud
             WHERE s.id_lote = ?
               AND m.codigo_lab IS NOT NULL
               AND m.codigo_lab <> ''
            ORDER BY valor
        ");
        $stmt->execute([$idLote, $idLote]);

        return array_values(array_filter(array_map(static function ($row) {
            return trim((string) ($row['valor'] ?? ''));
        }, $stmt->fetchAll(PDO::FETCH_ASSOC))));
    } catch (Throwable $e) {
        return [];
    }
}

function suelos_get_formulario_ids_for_lote(PDO $pdo, int $idLote): array
{
    try {
        $stmt = $pdo->prepare("
            SELECT DISTINCT f.id_formulario
              FROM formulario f
              INNER JOIN lote_rango lr ON lr.id_rango = f.id_rango
             WHERE lr.id_lote = ?
        ");
        $stmt->execute([$idLote]);

        return array_values(array_filter(array_map(static function ($row) {
            return (int) ($row['id_formulario'] ?? 0);
        }, $stmt->fetchAll(PDO::FETCH_ASSOC))));
    } catch (Throwable $e) {
        return [];
    }
}

function suelos_fetch_latest(PDO $pdo, string $table, int $idLote, $numLab): array
{
    if (!suelos_table_has_columns($pdo, $table, ['id_lote', 'numero_laboratorio'])) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE id_lote = ? AND numero_laboratorio = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$idLote, $numLab]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function suelos_fetch_latest_by_solicitud(PDO $pdo, string $table, int $idSolicitud): array
{
    if ($idSolicitud <= 0 || !suelos_table_has_columns($pdo, $table, ['id_solicitud'])) {
        return [];
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE id_solicitud = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$idSolicitud]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function suelos_fetch_latest_by_no_lab(PDO $pdo, string $table, int $idLote, $numLab): array
{
    if (!suelos_table_has_columns($pdo, $table, ['no_lab'])) {
        return [];
    }

    $formularioIds = suelos_table_has_columns($pdo, $table, ['id_formulario'])
        ? suelos_get_formulario_ids_for_lote($pdo, $idLote)
        : [];

    $identificadores = [];
    $numero = trim((string) $numLab);
    if ($numero !== '') {
        $identificadores[] = $numero;
    }

    $codigoLab = suelos_get_lab_code($pdo, $idLote, $numLab);
    if ($codigoLab !== '' && !in_array($codigoLab, $identificadores, true)) {
        $identificadores[] = $codigoLab;
    }

    if (!$identificadores) {
        return [];
    }

    $conds = [];
    $params = [];

    if ($formularioIds) {
        $placeholders = implode(', ', array_fill(0, count($formularioIds), '?'));
        $conds[] = "id_formulario IN ($placeholders)";
        array_push($params, ...$formularioIds);
    }

    foreach ($identificadores as $identificador) {
        $conds[] = 'no_lab = ?';
        $params[] = $identificador;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE " . implode(' OR ', $conds) . " ORDER BY id DESC LIMIT 1");
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

function suelos_get_solicitud_for_lab(PDO $pdo, int $idLote, $numLab): ?int
{
    try {
        $stmt = $pdo->prepare("
            SELECT s.id_solicitud
              FROM solicitud s
              INNER JOIN muestra m ON m.id_solicitud = s.id_solicitud
             WHERE s.id_lote = ?
               AND (m.numero_muestra = ? OR m.codigo_lab = ?)
             ORDER BY s.id_solicitud DESC
             LIMIT 1
        ");
        $stmt->execute([$idLote, $numLab, $numLab]);
        $idSolicitud = $stmt->fetchColumn();

        return $idSolicitud !== false ? (int) $idSolicitud : null;
    } catch (Throwable $e) {
        return null;
    }
}

function suelos_fetch_latest_with_fallback(PDO $pdo, string $table, int $idLote, $numLab): array
{
    $row = suelos_fetch_latest($pdo, $table, $idLote, $numLab);
    if ($row) {
        return $row;
    }

    $idSolicitud = suelos_get_solicitud_for_lab($pdo, $idLote, $numLab);
    if ($idSolicitud === null) {
        return suelos_fetch_latest_by_no_lab($pdo, $table, $idLote, $numLab);
    }

    $row = suelos_fetch_latest_by_solicitud($pdo, $table, $idSolicitud);
    if ($row) {
        return $row;
    }

    return suelos_fetch_latest_by_no_lab($pdo, $table, $idLote, $numLab);
}

function suelos_get_lab_code(PDO $pdo, int $idLote, $numLab): string
{
    try {
        $stmt = $pdo->prepare("
            SELECT m.codigo_lab
            FROM muestra m
            INNER JOIN solicitud s ON s.id_solicitud = m.id_solicitud
            WHERE s.id_lote = ?
              AND m.numero_muestra = ?
              AND m.codigo_lab IS NOT NULL
              AND m.codigo_lab <> ''
            ORDER BY s.id_solicitud DESC
            LIMIT 1
        ");
        $stmt->execute([$idLote, $numLab]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (string) ($row['codigo_lab'] ?? '');
    } catch (Throwable $e) {
        return '';
    }
}

function suelos_table_has_columns(PDO $pdo, string $table, array $requiredColumns): bool
{
    static $cache = [];

    if (!array_key_exists($table, $cache)) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
            $cache[$table] = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable $e) {
            $cache[$table] = [];
        }
    }

    foreach ($requiredColumns as $column) {
        if (!in_array($column, $cache[$table], true)) {
            return false;
        }
    }

    return true;
}

function suelos_first_value(...$values)
{
    foreach ($values as $value) {
        if ($value !== null && trim((string) $value) !== '') {
            return $value;
        }
    }

    return '';
}

function suelos_find_template_path(): ?string
{
    $paths = [
        __DIR__ . '/../templates/' . SUELOS_TEMPLATE_FILE,
        dirname(__DIR__, 2) . '/' . SUELOS_TEMPLATE_FILE,
        dirname(__DIR__, 3) . '/' . SUELOS_TEMPLATE_FILE,
    ];

    foreach ($paths as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function suelos_export_xlsx_from_template(string $templatePath, array $reporte, array $registros): void
{
    $tmpPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . 'reporte_suelos_' . uniqid('', true) . '.xlsx';

    $ok = lab_export_xlsx_template(
        $templatePath,
        $tmpPath,
        static function (string $sheetXml) use ($reporte, $registros): string {
            return suelos_build_sheet_xml($sheetXml, $reporte, $registros);
        }
    );

    if (!$ok) {
        suelos_export_xlsx_native($reporte, $registros);
    }

    $filename = 'Analisis_Suelos_' . suelos_safe_filename($reporte['codigo_lote'] ?? 'lote') . '_' . date('YmdHis') . '.xlsx';

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

function suelos_build_sheet_xml(string $sheetXml, array $reporte, array $registros): string
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = false;
    $dom->loadXML($sheetXml);

    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

    $dataRows = max(count($registros), 1);
    $extraRows = max(0, $dataRows - SUELOS_TEMPLATE_DATA_ROWS);

    if ($extraRows > 0) {
        suelos_shift_rows($xpath, SUELOS_FOOTER_START_ROW, $extraRows);
        suelos_shift_merge_cells($xpath, SUELOS_FOOTER_START_ROW, $extraRows);
    }

    suelos_set_cell_value($dom, $xpath, 'A12', 'Código del muestreo:  ' . ($reporte['codigo_muestreo'] ?? ''));
    suelos_set_cell_value($dom, $xpath, 'A13', 'Ingenio:  ' . ($reporte['ingenio'] ?? ''));
    suelos_set_cell_value($dom, $xpath, 'A14', 'Fecha de Ingreso:   ' . ($reporte['fecha_ingreso'] ?? ''));
    suelos_set_cell_value($dom, $xpath, 'A15', 'Fecha de Entrega:  ' . ($reporte['fecha_entrega'] ?? ''));

    $columns = suelos_report_columns();
    $styleMap = suelos_data_style_map($xpath);
    $totalRowsToClear = max(SUELOS_TEMPLATE_DATA_ROWS, $dataRows);

    for ($offset = 0; $offset < $totalRowsToClear; $offset++) {
        $rowNumber = SUELOS_DATA_START_ROW + $offset;
        $rowData = $registros[$offset] ?? null;

        foreach ($columns as $column => $field) {
            $value = $rowData[$field] ?? '';
            $style = $styleMap[$column] ?? '13';
            suelos_set_cell_value($dom, $xpath, $column . $rowNumber, $value, $style);
        }
    }

    suelos_update_dimension($xpath, 102 + $extraRows);
    suelos_sort_sheet_rows($xpath);

    return $dom->saveXML();
}

function suelos_report_columns(): array
{
    return [
        'A' => 'finca',
        'B' => 'codigo',
        'C' => 'numero_laboratorio',
        'D' => 'ce',
        'E' => 'ph',
        'F' => 'porcentaje_n',
        'G' => 'ppm_suelo',
        'H' => 'ppm_k',
        'I' => 'ppm_ca',
        'J' => 'ppm_mg',
        'K' => 'ppm_na',
        'L' => 'ppm_so4',
        'M' => 'ppm_cu',
        'N' => 'ppm_zn',
        'O' => 'ppm_fe',
        'P' => 'ppm_mn',
        'Q' => 'ppm_b',
        'R' => 'materia_organica',
        'S' => 'cic_meq',
        'T' => 'arcilla',
        'U' => 'limo',
        'V' => 'arena',
        'W' => 'tipo_textura',
        'X' => 'porcentaje_pmp',
        'Y' => 'porcentaje_cc',
        'Z' => 'densidad',
        'AA' => 'humedad',
    ];
}

function suelos_data_style_map(DOMXPath $xpath): array
{
    $map = [];

    foreach (array_keys(suelos_report_columns()) as $column) {
        $cell = suelos_find_cell($xpath, $column . SUELOS_DATA_START_ROW);
        if ($cell instanceof DOMElement && $cell->hasAttribute('s')) {
            $map[$column] = $cell->getAttribute('s');
        }
    }

    return $map + [
        'A' => '15',
        'B' => '14',
        'C' => '21',
        'D' => '13',
        'E' => '13',
        'F' => '13',
        'G' => '13',
        'H' => '13',
        'I' => '13',
        'J' => '13',
        'K' => '13',
        'L' => '13',
        'M' => '13',
        'N' => '13',
        'O' => '13',
        'P' => '13',
        'Q' => '13',
        'R' => '13',
        'S' => '13',
        'T' => '17',
        'U' => '17',
        'V' => '17',
        'W' => '17',
        'X' => '17',
        'Y' => '17',
        'Z' => '17',
        'AA' => '17',
    ];
}

function suelos_set_cell_value(DOMDocument $dom, DOMXPath $xpath, string $cellRef, $value, ?string $style = null): void
{
    $cell = suelos_find_cell($xpath, $cellRef);
    if (!$cell instanceof DOMElement) {
        $cell = suelos_create_cell($dom, $xpath, $cellRef);
    }

    if ($style !== null) {
        $cell->setAttribute('s', $style);
    }

    while ($cell->firstChild) {
        $cell->removeChild($cell->firstChild);
    }

    $value = suelos_clean_cell_value($value);

    if ($value === '') {
        $cell->removeAttribute('t');
        return;
    }

    if (is_numeric($value) && !preg_match('/^0\d+$/', (string) $value)) {
        $cell->removeAttribute('t');
        $v = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'v');
        $v->appendChild($dom->createTextNode((string) (float) $value));
        $cell->appendChild($v);
        return;
    }

    $cell->setAttribute('t', 'inlineStr');
    $is = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'is');
    $t = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 't');
    $t->appendChild($dom->createTextNode((string) $value));
    $is->appendChild($t);
    $cell->appendChild($is);
}

function suelos_find_cell(DOMXPath $xpath, string $cellRef): ?DOMElement
{
    $nodes = $xpath->query("//m:c[@r='{$cellRef}']");
    $cell = $nodes ? $nodes->item(0) : null;

    return $cell instanceof DOMElement ? $cell : null;
}

function suelos_create_cell(DOMDocument $dom, DOMXPath $xpath, string $cellRef): DOMElement
{
    [, $rowNumber] = suelos_split_cell_ref($cellRef);
    $row = suelos_find_or_create_row($dom, $xpath, $rowNumber);
    $cell = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'c');
    $cell->setAttribute('r', $cellRef);
    $row->appendChild($cell);

    suelos_sort_row_cells($row);

    return $cell;
}

function suelos_find_or_create_row(DOMDocument $dom, DOMXPath $xpath, int $rowNumber): DOMElement
{
    $nodes = $xpath->query("//m:row[@r='{$rowNumber}']");
    $row = $nodes ? $nodes->item(0) : null;

    if ($row instanceof DOMElement) {
        return $row;
    }

    $sheetData = $xpath->query('//m:sheetData')->item(0);
    $row = $dom->createElementNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'row');
    $row->setAttribute('r', (string) $rowNumber);
    $row->setAttribute('spans', '1:27');
    $sheetData->appendChild($row);

    return $row;
}

function suelos_shift_rows(DOMXPath $xpath, int $fromRow, int $offset): void
{
    foreach ($xpath->query('//m:row') as $row) {
        if (!$row instanceof DOMElement) {
            continue;
        }

        $rowNumber = (int) $row->getAttribute('r');
        if ($rowNumber < $fromRow) {
            continue;
        }

        $newRow = $rowNumber + $offset;
        $row->setAttribute('r', (string) $newRow);

        foreach ($xpath->query('m:c', $row) as $cell) {
            if (!$cell instanceof DOMElement || !$cell->hasAttribute('r')) {
                continue;
            }

            [$column] = suelos_split_cell_ref($cell->getAttribute('r'));
            $cell->setAttribute('r', $column . $newRow);
        }
    }
}

function suelos_shift_merge_cells(DOMXPath $xpath, int $fromRow, int $offset): void
{
    foreach ($xpath->query('//m:mergeCell') as $mergeCell) {
        if (!$mergeCell instanceof DOMElement) {
            continue;
        }

        $mergeCell->setAttribute('ref', suelos_shift_range_ref($mergeCell->getAttribute('ref'), $fromRow, $offset));
    }
}

function suelos_shift_range_ref(string $range, int $fromRow, int $offset): string
{
    $parts = explode(':', $range);
    $shifted = [];

    foreach ($parts as $part) {
        [$column, $row] = suelos_split_cell_ref($part);
        if ($row >= $fromRow) {
            $row += $offset;
        }

        $shifted[] = $column . $row;
    }

    return implode(':', $shifted);
}

function suelos_update_dimension(DOMXPath $xpath, int $lastRow): void
{
    $dimension = $xpath->query('//m:dimension')->item(0);
    if ($dimension instanceof DOMElement) {
        $dimension->setAttribute('ref', 'A1:AB' . $lastRow);
    }
}

function suelos_sort_sheet_rows(DOMXPath $xpath): void
{
    $sheetData = $xpath->query('//m:sheetData')->item(0);
    if (!$sheetData instanceof DOMElement) {
        return;
    }

    $rows = [];
    foreach ($xpath->query('m:row', $sheetData) as $row) {
        if ($row instanceof DOMElement) {
            suelos_sort_row_cells($row);
            $rows[] = $row;
        }
    }

    usort($rows, static fn(DOMElement $a, DOMElement $b) => (int) $a->getAttribute('r') <=> (int) $b->getAttribute('r'));

    foreach ($rows as $row) {
        $sheetData->appendChild($row);
    }
}

function suelos_sort_row_cells(DOMElement $row): void
{
    $cells = [];
    foreach ($row->childNodes as $child) {
        if ($child instanceof DOMElement && $child->localName === 'c') {
            $cells[] = $child;
        }
    }

    usort($cells, static function (DOMElement $a, DOMElement $b): int {
        [$colA] = suelos_split_cell_ref($a->getAttribute('r'));
        [$colB] = suelos_split_cell_ref($b->getAttribute('r'));

        return suelos_column_index($colA) <=> suelos_column_index($colB);
    });

    foreach ($cells as $cell) {
        $row->appendChild($cell);
    }
}

function suelos_split_cell_ref(string $cellRef): array
{
    if (!preg_match('/^([A-Z]+)(\d+)$/', $cellRef, $matches)) {
        return [$cellRef, 0];
    }

    return [$matches[1], (int) $matches[2]];
}

function suelos_column_index(string $column): int
{
    $index = 0;
    $letters = str_split($column);

    foreach ($letters as $letter) {
        $index = ($index * 26) + (ord($letter) - 64);
    }

    return $index;
}

function suelos_clean_cell_value($value)
{
    if ($value === null) {
        return '';
    }

    if (is_numeric($value)) {
        return $value;
    }

    return trim((string) $value);
}

function suelos_export_xlsx_native(array $reporte, array $registros): void
{
    $filename = 'Analisis_Suelos_' . suelos_safe_filename($reporte['codigo_lote'] ?? 'lote') . '_' . date('YmdHis') . '.xlsx';
    $xlsx = suelos_create_xlsx_package($reporte, $registros);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($xlsx));
    header('Cache-Control: max-age=0');

    echo $xlsx;
    exit;
}

function suelos_create_xlsx_package(array $reporte, array $registros): string
{
    $logoPath = suelos_find_logo_path();
    $hasLogo = $logoPath !== null;
    $entries = [
        '[Content_Types].xml' => suelos_xlsx_content_types($hasLogo),
        '_rels/.rels' => suelos_xlsx_root_rels(),
        'docProps/core.xml' => suelos_xlsx_core_props(),
        'docProps/app.xml' => suelos_xlsx_app_props(),
        'xl/workbook.xml' => suelos_xlsx_workbook(),
        'xl/_rels/workbook.xml.rels' => suelos_xlsx_workbook_rels(),
        'xl/styles.xml' => suelos_xlsx_styles(),
        'xl/worksheets/sheet1.xml' => suelos_xlsx_sheet($reporte, $registros, $hasLogo),
    ];

    if ($hasLogo) {
        $entries['xl/worksheets/_rels/sheet1.xml.rels'] = suelos_xlsx_sheet_rels();
        $entries['xl/drawings/drawing1.xml'] = suelos_xlsx_drawing();
        $entries['xl/drawings/_rels/drawing1.xml.rels'] = suelos_xlsx_drawing_rels();
        $entries['xl/media/image1.png'] = file_get_contents($logoPath);
    }

    return suelos_zip_create($entries);
}

function suelos_xlsx_sheet(array $reporte, array $registros, bool $hasLogo): string
{
    $recordCount = count($registros);
    $displayRows = max($recordCount, SUELOS_TEMPLATE_DATA_ROWS);
    $lastDataRow = SUELOS_DATA_START_ROW + $displayRows - 1;
    $footerRow = $lastDataRow + 1;
    $lastRow = $footerRow + 7;
    $rows = [];

    $rows[] = suelos_xlsx_row(1, [
        suelos_xlsx_cell('A1', 'Centro Guatemalteco de Investigación y Capacitación de la Caña de Azúcar', 1),
    ], 15);
    $rows[] = suelos_xlsx_row(2, [
        suelos_xlsx_cell('A2', 'Laboratorio Agroindustrial', 1),
    ], 15);
    $rows[] = suelos_xlsx_row(10, [
        suelos_xlsx_cell('A10', 'Reporte de Análisis Químico y Físico de Suelo', 2),
    ], 24);
    $rows[] = suelos_xlsx_row(12, [
        suelos_xlsx_cell('A12', 'Código del muestreo:  ' . ($reporte['codigo_muestreo'] ?? ''), 3),
    ], 21);
    $rows[] = suelos_xlsx_row(13, [
        suelos_xlsx_cell('A13', 'Ingenio:  ' . ($reporte['ingenio'] ?? ''), 3),
    ], 21);
    $rows[] = suelos_xlsx_row(14, [
        suelos_xlsx_cell('A14', 'Fecha de Ingreso:   ' . ($reporte['fecha_ingreso'] ?? ''), 3),
    ], 21);
    $rows[] = suelos_xlsx_row(15, [
        suelos_xlsx_cell('A15', 'Fecha de Entrega:  ' . ($reporte['fecha_entrega'] ?? ''), 3),
    ], 21);

    $headerTop = [
        'A' => 'IDENTIFICACIÓN',
        'C' => 'No. Lab',
        'D' => "Conductividad\nEléctrica",
        'E' => "pH en\nagua",
        'F' => "Nitrógeno\nTotal",
        'G' => 'Fósforo',
        'H' => 'Potasio',
        'I' => 'Calcio',
        'J' => 'Magnesio',
        'K' => 'Sodio',
        'L' => 'Azufre',
        'M' => 'Cobre',
        'N' => 'Cinc',
        'O' => 'Hierro',
        'P' => 'Manganeso',
        'Q' => 'Boro',
        'R' => "Materia\nOrganica",
        'S' => "Capacidad de\nIntercambio\nCatiónico",
        'T' => 'Arcilla',
        'U' => 'Limo',
        'V' => 'Arena',
        'W' => 'Tipo de Textura',
        'X' => "Punto de\nMarchitez\nPermanente",
        'Y' => "Capacidad de\nCampo",
        'Z' => "Densidad\nAparente",
        'AA' => "Humedad\nGravimetrica",
    ];
    $rows[] = suelos_xlsx_row(17, suelos_xlsx_cells_for_columns(17, $headerTop, 4), 45);
    $rows[] = suelos_xlsx_row(18, suelos_xlsx_cells_for_columns(18, [], 4), 30);

    $headerUnits = [
        'A' => 'Finca',
        'B' => 'CODIGO',
        'D' => 'dS/m',
        'F' => '%',
        'G' => 'ppm',
        'H' => 'Meq intercambiables/100 g suelo',
        'L' => 'ppm',
        'R' => '%',
        'S' => 'Meq intercambiables/100 g suelo',
        'T' => '%',
        'X' => '% H',
        'Z' => 'g/cm3',
        'AA' => '%',
    ];
    $rows[] = suelos_xlsx_row(19, suelos_xlsx_cells_for_columns(19, $headerUnits, 5), 36);

    $columns = suelos_report_columns();
    for ($i = 0; $i < $displayRows; $i++) {
        $rowNumber = SUELOS_DATA_START_ROW + $i;
        $registro = $registros[$i] ?? [];
        $cells = [];

        foreach ($columns as $column => $field) {
            $cells[] = suelos_xlsx_cell($column . $rowNumber, $registro[$field] ?? '', $field === 'tipo_textura' ? 7 : 6);
        }

        $rows[] = suelos_xlsx_row($rowNumber, $cells, 18);
    }

    $rows[] = suelos_xlsx_row($footerRow, [
        suelos_xlsx_cell('A' . $footerRow, 'Observaciones:', 8),
    ], 18);
    $rows[] = suelos_xlsx_row($footerRow + 2, [
        suelos_xlsx_cell('A' . ($footerRow + 2), 'Métodos de Análisis: Conductividad Eléctrica (CE), pH en agua, Materia Orgánica, Capacidad de Intercambio Catiónico, Bases Intercambiables, Micronutrientes, Fósforo, Textura, Retención de Humedad, Densidad Aparente, Humedad Gravimétrica y Nitrógeno Total.', 8),
    ], 45);
    $rows[] = suelos_xlsx_row($footerRow + 4, [
        suelos_xlsx_cell('A' . ($footerRow + 4), 'Los resultados de este informe son válidos únicamente para las muestras como fueron recibidas en el Laboratorio.', 8),
    ], 30);
    $rows[] = suelos_xlsx_row($footerRow + 6, [
        suelos_xlsx_cell('A' . ($footerRow + 6), 'Revisado por:', 8),
        suelos_xlsx_cell('N' . ($footerRow + 6), 'Aprobado por:', 8),
    ], 18);

    $mergeCells = [
        'A1:AA1',
        'A2:AA2',
        'A10:AA10',
        'A12:D12',
        'A13:B13',
        'A14:B14',
        'A15:B15',
        'A17:B18',
        'C17:C19',
        'D17:D18',
        'E17:E19',
        'F17:F18',
        'G17:G18',
        'H17:H18',
        'I17:I18',
        'J17:J18',
        'K17:K18',
        'L17:L18',
        'M17:M18',
        'N17:N18',
        'O17:O18',
        'P17:P18',
        'Q17:Q18',
        'R17:R18',
        'S17:S18',
        'T17:T18',
        'U17:U18',
        'V17:V18',
        'W17:W19',
        'X17:X18',
        'Y17:Y18',
        'Z17:Z18',
        'AA17:AA18',
        'H19:K19',
        'L19:Q19',
        'T19:V19',
        'X19:Y19',
        'A' . ($footerRow + 2) . ':AA' . ($footerRow + 3),
        'A' . ($footerRow + 4) . ':AA' . ($footerRow + 5),
    ];

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
    $xml .= '<dimension ref="A1:AA' . $lastRow . '"/>';
    $xml .= '<sheetViews><sheetView workbookViewId="0" zoomScale="60" zoomScaleNormal="60"/></sheetViews>';
    $xml .= '<sheetFormatPr defaultRowHeight="15"/>';
    $xml .= suelos_xlsx_columns();
    $xml .= '<sheetData>' . implode('', $rows) . '</sheetData>';
    $xml .= '<mergeCells count="' . count($mergeCells) . '">';
    foreach ($mergeCells as $mergeCell) {
        $xml .= '<mergeCell ref="' . $mergeCell . '"/>';
    }
    $xml .= '</mergeCells>';
    $xml .= '<pageMargins left="0.25" right="0.25" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>';
    $xml .= '<pageSetup orientation="landscape"/>';
    if ($hasLogo) {
        $xml .= '<drawing r:id="rId1"/>';
    }
    $xml .= '</worksheet>';

    return $xml;
}

function suelos_xlsx_cells_for_columns(int $rowNumber, array $values, int $style): array
{
    $cells = [];

    foreach (array_keys(suelos_report_columns()) as $column) {
        $cells[] = suelos_xlsx_cell($column . $rowNumber, $values[$column] ?? '', $style);
    }

    return $cells;
}

function suelos_xlsx_row(int $rowNumber, array $cells, ?int $height = null): string
{
    $attrs = ' r="' . $rowNumber . '" spans="1:27"';
    if ($height !== null) {
        $attrs .= ' ht="' . $height . '" customHeight="1"';
    }

    return '<row' . $attrs . '>' . implode('', $cells) . '</row>';
}

function suelos_xlsx_cell(string $ref, $value, int $style): string
{
    $value = suelos_clean_cell_value($value);
    $styleAttr = ' s="' . $style . '"';

    if ($value === '') {
        return '<c r="' . $ref . '"' . $styleAttr . '/>';
    }

    if (is_numeric($value) && !preg_match('/^0\d+$/', (string) $value)) {
        return '<c r="' . $ref . '"' . $styleAttr . '><v>' . suelos_xml((string) (float) $value) . '</v></c>';
    }

    return '<c r="' . $ref . '"' . $styleAttr . ' t="inlineStr"><is><t>' . suelos_xml((string) $value) . '</t></is></c>';
}

function suelos_xlsx_columns(): string
{
    $widths = [
        'A' => 18,
        'B' => 18,
        'C' => 16,
        'D' => 15,
        'E' => 10,
        'F' => 13,
        'G' => 13,
        'H' => 13,
        'I' => 13,
        'J' => 13,
        'K' => 13,
        'L' => 13,
        'M' => 11,
        'N' => 11,
        'O' => 11,
        'P' => 14,
        'Q' => 11,
        'R' => 15,
        'S' => 18,
        'T' => 11,
        'U' => 11,
        'V' => 11,
        'W' => 22,
        'X' => 18,
        'Y' => 16,
        'Z' => 15,
        'AA' => 15,
    ];

    $xml = '<cols>';
    foreach ($widths as $column => $width) {
        $index = suelos_column_index($column);
        $xml .= '<col min="' . $index . '" max="' . $index . '" width="' . $width . '" customWidth="1"/>';
    }
    $xml .= '</cols>';

    return $xml;
}

function suelos_xlsx_styles(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="4">'
        . '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="10"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><b/><sz val="14"/><name val="Calibri"/><family val="2"/></font>'
        . '<font><sz val="10"/><name val="Calibri"/><family val="2"/></font>'
        . '</fonts>'
        . '<fills count="3">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF92D050"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color rgb="FF000000"/></left><right style="thin"><color rgb="FF000000"/></right><top style="thin"><color rgb="FF000000"/></top><bottom style="thin"><color rgb="FF000000"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="9">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="center"/></xf>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
        . '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '<dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/>'
        . '</styleSheet>';
}

function suelos_xlsx_content_types(bool $hasLogo): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>';

    if ($hasLogo) {
        $xml .= '<Default Extension="png" ContentType="image/png"/>';
    }

    $xml .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';

    if ($hasLogo) {
        $xml .= '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
    }

    return $xml . '</Types>';
}

function suelos_xlsx_root_rels(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>';
}

function suelos_xlsx_workbook(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Suelos" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';
}

function suelos_xlsx_workbook_rels(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
}

function suelos_xlsx_core_props(): string
{
    $now = gmdate('Y-m-d\TH:i:s\Z');

    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>Reporte de Análisis Químico y Físico de Suelo</dc:title>'
        . '<dc:creator>Laboratorio Agroindustrial</dc:creator>'
        . '<cp:lastModifiedBy>Laboratorio Agroindustrial</cp:lastModifiedBy>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
        . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
        . '</cp:coreProperties>';
}

function suelos_xlsx_app_props(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        . '<Application>Microsoft Excel</Application>'
        . '</Properties>';
}

function suelos_xlsx_sheet_rels(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>'
        . '</Relationships>';
}

function suelos_xlsx_drawing(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
        . '<xdr:twoCellAnchor editAs="oneCell">'
        . '<xdr:from><xdr:col>0</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>1</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
        . '<xdr:to><xdr:col>3</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>8</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>'
        . '<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="2" name="Logo"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
        . '<xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
        . '<xdr:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic>'
        . '<xdr:clientData/></xdr:twoCellAnchor></xdr:wsDr>';
}

function suelos_xlsx_drawing_rels(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image1.png"/>'
        . '</Relationships>';
}

function suelos_find_logo_path(): ?string
{
    $paths = array_merge(
        glob(__DIR__ . '/../assets/cengica*.png') ?: [],
        [
            __DIR__ . '/../assets/logo.png',
            dirname(__DIR__, 2) . '/assets/Marca Cengicaña/SinFondo_logo_cengicana_Horizontal.png',
            dirname(__DIR__, 2) . '/assets/Marca Cengicaña/logo_cengicana_Horizontal.png',
        ]
    );

    foreach ($paths as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function suelos_zip_create(array $entries): string
{
    $localData = '';
    $centralDirectory = '';
    $offset = 0;
    [$dosTime, $dosDate] = suelos_zip_dos_datetime();

    foreach ($entries as $name => $data) {
        $data = (string) $data;
        $name = str_replace('\\', '/', $name);
        $crc = crc32($data);
        if ($crc < 0) {
            $crc += 4294967296;
        }

        $method = function_exists('gzdeflate') ? 8 : 0;
        $compressed = $method === 8 ? gzdeflate($data, 6) : $data;
        $nameLength = strlen($name);
        $compressedLength = strlen($compressed);
        $dataLength = strlen($data);

        $localHeader = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0,
            $method,
            $dosTime,
            $dosDate,
            $crc,
            $compressedLength,
            $dataLength,
            $nameLength,
            0
        );

        $localData .= $localHeader . $name . $compressed;

        $centralDirectory .= pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            20,
            20,
            0,
            $method,
            $dosTime,
            $dosDate,
            $crc,
            $compressedLength,
            $dataLength,
            $nameLength,
            0,
            0,
            0,
            0,
            32,
            $offset
        ) . $name;

        $offset += strlen($localHeader) + $nameLength + $compressedLength;
    }

    $count = count($entries);
    $end = pack(
        'VvvvvVVv',
        0x06054b50,
        0,
        0,
        $count,
        $count,
        strlen($centralDirectory),
        strlen($localData),
        0
    );

    return $localData . $centralDirectory . $end;
}

function suelos_zip_dos_datetime(): array
{
    $time = getdate();
    $dosTime = (($time['hours'] & 0x1F) << 11)
        | (($time['minutes'] & 0x3F) << 5)
        | ((int) ($time['seconds'] / 2) & 0x1F);
    $dosDate = ((($time['year'] - 1980) & 0x7F) << 9)
        | (($time['mon'] & 0x0F) << 5)
        | ($time['mday'] & 0x1F);

    return [$dosTime, $dosDate];
}

function suelos_xml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function suelos_export_csv(array $reporte, array $registros): void
{
    $filename = 'Analisis_Suelos_' . suelos_safe_filename($reporte['codigo_lote'] ?? 'lote') . '_' . date('YmdHis') . '.csv';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, array_values(suelos_csv_headers()));

    foreach ($registros as $registro) {
        $row = [];
        foreach (array_keys(suelos_csv_headers()) as $field) {
            $row[] = $registro[$field] ?? '';
        }
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

function suelos_csv_headers(): array
{
    return [
        'finca' => 'Finca',
        'codigo' => 'CODIGO',
        'numero_laboratorio' => 'No. Lab',
        'ce' => 'Conductividad Electrica',
        'ph' => 'pH',
        'porcentaje_n' => 'Nitrogeno Total',
        'ppm_suelo' => 'Fosforo',
        'ppm_k' => 'Potasio',
        'ppm_ca' => 'Calcio',
        'ppm_mg' => 'Magnesio',
        'ppm_na' => 'Sodio',
        'ppm_so4' => 'Azufre',
        'ppm_cu' => 'Cobre',
        'ppm_zn' => 'Cinc',
        'ppm_fe' => 'Hierro',
        'ppm_mn' => 'Manganeso',
        'ppm_b' => 'Boro',
        'materia_organica' => 'Materia Organica',
        'cic_meq' => 'CIC',
        'arcilla' => 'Arcilla',
        'limo' => 'Limo',
        'arena' => 'Arena',
        'tipo_textura' => 'Tipo de Textura',
        'porcentaje_pmp' => 'PMP',
        'porcentaje_cc' => 'Capacidad de Campo',
        'densidad' => 'Densidad Aparente',
        'humedad' => 'Humedad Gravimetrica',
    ];
}

function suelos_format_date($date): string
{
    if (empty($date)) {
        return '';
    }

    $timestamp = strtotime((string) $date);

    return $timestamp ? date('d/m/Y', $timestamp) : '';
}

function suelos_safe_filename(string $value): string
{
    $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $value);
    $safe = trim((string) $safe, '_');

    return $safe !== '' ? $safe : 'lote';
}
