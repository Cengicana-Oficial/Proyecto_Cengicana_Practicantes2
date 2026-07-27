<?php
require_once __DIR__ . '/../models/analisis_generico_model.php';

if (!function_exists('labLegacyConfigFromScript')) {
    function labLegacyConfigFromScript(): ?array
    {
        global $labAnalysisLegacyConfig;

        if (isset($labAnalysisLegacyConfig) && is_array($labAnalysisLegacyConfig)) {
            return $labAnalysisLegacyConfig;
        }

        $script = strtolower(str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '')));
        $mapa = [
            'suelos/cc_controller.php' => ['tipos' => ['suelos', 'suelo'], 'analisis' => ['Capacidad de Campo', 'Capacidad campo']],
            'suelos/pmp_controller.php' => ['tipos' => ['suelos', 'suelo'], 'analisis' => ['Punto de Marchitez Permanente', 'Marchitez Permanente', 'PMP']],
            'suelos/macroscic_controller.php' => ['tipos' => ['suelos', 'suelo'], 'analisis' => ['Macronutrientes y CIC', 'Macronutrientes', 'CIC']],
            'suelos/micros_controller.php' => ['tipos' => ['suelos', 'suelo'], 'analisis' => ['Micro Nutrientes', 'Micronutrientes', 'Cu, Zn, Fe, Mn, K']],
            'suelos/nitrogeno_controller.php' => ['tipos' => ['suelos', 'suelo'], 'analisis' => ['Nitrogeno', 'Nitrógeno', 'Nitrogeno total', 'Nitrógeno total']],
            'suelos/boro_controller.php' => ['tipos' => ['suelos', 'suelo'], 'analisis' => ['Boro']],
            'suelos/azufre_controller.php' => ['tipos' => ['suelos', 'suelo'], 'analisis' => ['Azufre', 'SO4']],
            'suelos/fosforo_controller.php' => ['tipos' => ['suelos', 'suelo'], 'analisis' => ['Fosforo', 'Fósforo', 'Fosforo disponible', 'Fósforo disponible']],
            'foliares/micros_controller.php' => ['tipos' => ['foliares', 'foliar'], 'analisis' => ['Micro Nutrientes', 'Micronutrientes', 'Cu, Zn, Fe, Mn, K']],
            'foliares/fosforo_controller.php' => ['tipos' => ['foliares', 'foliar'], 'analisis' => ['Fosforo', 'Fósforo', 'Fosforo foliar', 'Fósforo foliar']],
            'aguas/micros_controller.php' => ['tipos' => ['agua', 'aguas'], 'analisis' => ['Micro Nutrientes', 'Micronutrientes', 'Micro Nutrientes (Cu, Zn, Fe, Mn)', 'Micronutrientes (Cu, Zn, Fe, Mn)', 'Micro Nutrientes de Aguas', 'Micronutrientes de Aguas', 'Cu, Zn, Fe, Mn']],
            'aguas/fosforo_controller.php' => ['tipos' => ['agua', 'aguas'], 'analisis' => ['Fosforo', 'Fósforo']],
            'aguas/conductividad_controller.php' => ['tipos' => ['agua', 'aguas'], 'analisis' => ['Conductividad Electrica', 'Conductividad Eléctrica', 'CE']],
            'aguas/tds_controller.php' => ['tipos' => ['agua', 'aguas'], 'analisis' => ['TDS', 'STD', 'Solidos totales disueltos', 'Sólidos totales disueltos']],
            'aguas/resistividad_controller.php' => ['tipos' => ['agua', 'aguas'], 'analisis' => ['Resistividad']],
            'aguas/cloruros_controller.php' => ['tipos' => ['agua', 'aguas'], 'analisis' => ['Cloruros']],
            'aguas/alcanilidad_controller.php' => ['tipos' => ['agua', 'aguas'], 'analisis' => ['Alcalinidad', 'Alcanilidad']],
            'aguas/bicarbonato_controller.php' => ['tipos' => ['agua', 'aguas'], 'analisis' => ['Bicarbonatos', 'Bicarbonato']],
            'cana/humedad_controller.php' => ['tipos' => ['cañas', 'caña', 'canas', 'cana'], 'analisis' => ['% de Humedad', 'Humedad']],
            'cana/brixpol_controller.php' => ['tipos' => ['cañas', 'caña', 'canas', 'cana'], 'analisis' => ['Determinacion de Brix y Pol', 'Determinación de Brix y Pol', 'Brix', 'Pol']],
        ];

        foreach ($mapa as $needle => $config) {
            if (strpos($script, $needle) !== false) {
                return $config;
            }
        }

        return null;
    }
}

if (!function_exists('labLegacyUniquePostedLotes')) {
    function labLegacyUniquePostedLotes(): array
    {
        $rawLotes = $_POST['lote'] ?? [];
        $lotes = is_array($rawLotes) ? $rawLotes : [$rawLotes];
        $vistos = [];
        $resultado = [];

        foreach ($lotes as $lote) {
            $codigoLote = trim((string) $lote);
            if ($codigoLote === '' || isset($vistos[$codigoLote])) {
                continue;
            }

            $vistos[$codigoLote] = true;
            $resultado[] = $codigoLote;
        }

        return $resultado;
    }
}

if (!function_exists('labLegacyPrepareMetadataByLote')) {
    function labLegacyPrepareMetadataByLote(array $config, string $fecha, string $analista): array
    {
        $metadataByLote = [];
        $errores = [];
        $lotes = labLegacyUniquePostedLotes();

        if ($fecha === '' || $analista === '') {
            $errores[] = 'Complete fecha y analista para guardar el formulario.';
            return ['metadata' => $metadataByLote, 'errores' => $errores];
        }

        if (!$lotes) {
            return ['metadata' => $metadataByLote, 'errores' => $errores];
        }

        foreach ($lotes as $codigoLote) {
            try {
                $destino = labGenericDestino($config, $codigoLote, '');
                if (empty($destino['id_lote'])) {
                    $errores[] = 'No se encontro un destino valido para el lote ' . $codigoLote . '.';
                    continue;
                }

                $idFormulario = labGenericCrearFormulario($destino, $fecha, $analista);
                labFormularioGuardarVersion($idFormulario, 'inicial', $analista, 'Version enviada desde el formulario de analisis.');

                $metadataByLote[$codigoLote] = [
                    'id_solicitud' => isset($destino['id_solicitud']) && $destino['id_solicitud'] !== null ? (int) $destino['id_solicitud'] : null,
                    'id_lote' => isset($destino['id_lote']) && $destino['id_lote'] !== null ? (int) $destino['id_lote'] : null,
                    'id_formulario' => $idFormulario,
                    'lote' => $codigoLote,
                    'codigo_lote' => $codigoLote,
                ];
            } catch (Throwable $e) {
                $errores[] = 'No se pudo preparar el formulario para el lote ' . $codigoLote . ': ' . $e->getMessage();
            }
        }

        return ['metadata' => $metadataByLote, 'errores' => $errores];
    }
}

if (!function_exists('labLegacyMetadataForRow')) {
    function labLegacyExtractNumeroLaboratorio(string $numeroLaboratorio): ?int
    {
        $numeroLaboratorio = trim($numeroLaboratorio);
        if ($numeroLaboratorio === '') {
            return null;
        }

        if (is_numeric($numeroLaboratorio)) {
            return (int) $numeroLaboratorio;
        }

        if (preg_match('/^[A-Za-z]+-(\d+)-\d{2}-\d{2}$/', $numeroLaboratorio, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/(\d+)/', $numeroLaboratorio, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}

if (!function_exists('labLegacyMetadataForRow')) {
    function labLegacyMetadataForRow(array $metadataByLote, string $codigoLote, string $numeroLaboratorio): ?array
    {
        if ($codigoLote === '' || !isset($metadataByLote[$codigoLote])) {
            return null;
        }

        $numeroNormalizado = labLegacyExtractNumeroLaboratorio($numeroLaboratorio);

        return array_merge($metadataByLote[$codigoLote], [
            'numero_laboratorio' => $numeroNormalizado,
            'numero_muestra' => $numeroNormalizado,
            'no_lab' => $numeroLaboratorio !== '' ? $numeroLaboratorio : null,
        ]);
    }
}

if (!function_exists('labLegacyPostDataRowIndices')) {
    function labLegacyPostDataRowIndices(): array
    {
        $excluir = ['lote', 'numero_laboratorio', 'fecha', 'analista', 'observaciones'];
        $max = 0;

        foreach ($_POST as $valor) {
            if (is_array($valor)) {
                $max = max($max, count($valor));
            }
        }

        $indices = [];
        for ($index = 0; $index < $max; $index++) {
            foreach ($_POST as $nombre => $valor) {
                if (
                    !is_array($valor)
                    || in_array((string) $nombre, $excluir, true)
                    || strpos((string) $nombre, 'curva') !== false
                ) {
                    continue;
                }

                if (trim((string) ($valor[$index] ?? '')) !== '') {
                    $indices[] = $index;
                    break;
                }
            }
        }

        return $indices;
    }
}

if (!function_exists('labLegacyAutoMetadataForInsert')) {
    function labLegacyAutoMetadataForInsert(): array
    {
        static $estado = null;
        global $labSkipFooterBaseSave;

        if ($estado === null) {
            $config = labLegacyConfigFromScript();
            $fecha = trim((string) ($_POST['fecha'] ?? ''));
            $analista = trim((string) ($_POST['analista'] ?? $_POST['tecnico'] ?? ''));
            $preparado = $config ? labLegacyPrepareMetadataByLote($config, $fecha, $analista) : ['metadata' => [], 'errores' => []];

            if ($preparado['metadata']) {
                $labSkipFooterBaseSave = true;
            }

            $estado = [
                'metadata' => $preparado['metadata'],
                'indices' => labLegacyPostDataRowIndices(),
                'cursor' => 0,
            ];
        }

        $postIndex = $estado['indices'][$estado['cursor']] ?? $estado['cursor'];
        $estado['cursor']++;
        $loteRaw = $_POST['lote'] ?? '';
        $numeroRaw = $_POST['numero_laboratorio'] ?? '';
        $codigoLote = trim((string) (is_array($loteRaw) ? ($loteRaw[$postIndex] ?? '') : $loteRaw));
        $numeroLaboratorio = trim((string) (is_array($numeroRaw) ? ($numeroRaw[$postIndex] ?? '') : $numeroRaw));

        return labLegacyMetadataForRow($estado['metadata'], $codigoLote, $numeroLaboratorio) ?? [];
    }
}
