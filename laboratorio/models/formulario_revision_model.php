<?php

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/../includes/formulario_revision_helper.php';

function obtenerResumenRevisionRango(int $idRango): ?array
{
    $pdo = Conexion::conectar();
    $stmt = $pdo->prepare("
        SELECT lr.id_rango,
               lr.inicio,
               lr.fin,
               l.codigo_lote,
               s.id_solicitud,
               s.fecha_ingreso,
               s.numero_muestras,
               tm.nombre AS tipo_muestra
          FROM lote_rango lr
          LEFT JOIN lote l ON l.id_lote = lr.id_lote
          LEFT JOIN solicitud s ON s.id_lote = l.id_lote
          LEFT JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo
         WHERE lr.id_rango = ?
         LIMIT 1
    ");
    $stmt->execute([$idRango]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function obtenerFormulariosRevisionRango(int $idRango): array
{
    labFormularioEnsureSchema();
    $pdo = Conexion::conectar();
    $stmt = $pdo->prepare("
        SELECT f.*,
               ef.nombre AS estado_nombre,
               ta.nombre AS analisis_nombre
          FROM formulario f
          LEFT JOIN estado_formulario ef ON ef.id_estado = f.id_estado
          LEFT JOIN tipo_analisis ta ON ta.id_tipo = f.id_tipo_analisis
         WHERE f.id_rango = ?
         ORDER BY f.id_formulario ASC
    ");
    $stmt->execute([$idRango]);
    $formularios = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($formularios as &$formulario) {
        $idFormulario = (int) $formulario['id_formulario'];
        labFormularioEnsureVersionInicial($idFormulario, (string) ($formulario['analista'] ?? 'Sistema'));
        $datos = labFormularioDatosActuales($idFormulario);
        $formulario['tablas'] = $datos['tablas'];
        $formulario['versiones'] = labFormularioVersiones($idFormulario);
    }
    unset($formulario);

    return $formularios;
}

function labFormularioRevisionValorPrimerCoincidente(array $fila, array $nombres, $default = '-'): string
{
    foreach ($nombres as $nombre) {
        if (array_key_exists($nombre, $fila) && $fila[$nombre] !== null && $fila[$nombre] !== '') {
            return (string) $fila[$nombre];
        }
    }

    return (string) $default;
}

function labFormularioRevisionNumeroLaboratorio(array $formulario): string
{
    foreach (($formulario['tablas'] ?? []) as $tabla) {
        foreach (($tabla['filas'] ?? []) as $fila) {
            $valor = labFormularioRevisionValorPrimerCoincidente($fila, ['numero_laboratorio', 'no_lab', 'numero_muestra'], '');
            if ($valor !== '') {
                return $valor;
            }
        }
    }

    return '-';
}

function labFormularioRevisionPrincipalCampoOrden(string $nombre): int
{
    $nombre = strtolower($nombre);
    $prioridades = [
        'resultado' => 0,
        'resultado_final' => 0,
        'valor_final' => 0,
        'promedio' => 1,
        'media' => 1,
        'ppm' => 2,
        'ph' => 2,
        'brix' => 2,
        'pol' => 2,
        'porcentaje' => 2,
        'conductividad' => 2,
        'ce' => 2,
        'densidad' => 2,
        'cloruros' => 2,
        'calcio' => 2,
        'magnesio' => 2,
        'sodio' => 2,
        'potasio' => 2,
        'fosforo' => 2,
        'nitrógeno' => 2,
        'nitrogeno' => 2,
        'boro' => 2,
        'ras' => 2,
        'tds' => 2,
        'salinidad' => 2,
    ];

    foreach ($prioridades as $patron => $peso) {
        if (strpos($nombre, $patron) !== false) {
            return $peso;
        }
    }

    return 9;
}

function labFormularioRevisionEtiquetaCampo(string $nombre): string
{
    $nombre = str_replace('_', ' ', (string) $nombre);
    return ucwords($nombre);
}

function labFormularioRevisionResultadoPrincipal(array $formulario): string
{
    $candidatos = [];

    foreach (($formulario['tablas'] ?? []) as $tabla) {
        $pk = $tabla['primary_key'] ?? null;
        $ocultas = [
            $pk,
            'id_formulario',
            'id_encabezado',
            'numero_laboratorio',
            'numero_muestra',
            'no_lab',
            'lote',
            'codigo_lote',
        ];

        $columnas = [];
        foreach (($tabla['columnas'] ?? []) as $columna) {
            $nombre = (string) ($columna['Field'] ?? '');
            if ($nombre === '' || in_array($nombre, $ocultas, true)) {
                continue;
            }
            $columnas[] = $nombre;
        }

        foreach (($tabla['filas'] ?? []) as $fila) {
            foreach ($columnas as $columna) {
                $valor = $fila[$columna] ?? null;
                if ($valor === null || $valor === '') {
                    continue;
                }

                $candidatos[] = [
                    'peso' => labFormularioRevisionPrincipalCampoOrden((string) $columna),
                    'columna' => (string) $columna,
                    'valor' => is_scalar($valor) ? trim((string) $valor) : '',
                ];
            }
        }
    }

    if (!$candidatos) {
        return '-';
    }

    usort($candidatos, static function (array $a, array $b): int {
        if ($a['peso'] === $b['peso']) {
            return strcmp($a['columna'], $b['columna']);
        }

        return $a['peso'] <=> $b['peso'];
    });

    foreach ($candidatos as $candidato) {
        if ($candidato['valor'] !== '') {
            return labFormularioRevisionEtiquetaCampo($candidato['columna']) . ': ' . $candidato['valor'];
        }
    }

    return '-';
}

function labFormularioRevisionEstadoGrupo(array $formularios): array
{
    $estados = [];

    foreach ($formularios as $formulario) {
        $estado = trim((string) ($formulario['estado_nombre'] ?? ''));
        if ($estado !== '') {
            $estados[$estado] = true;
        }
    }

    $nombres = array_keys($estados);
    sort($nombres, SORT_NATURAL | SORT_FLAG_CASE);

    if (!$nombres) {
        return [
            'texto' => 'Revisar',
            'clase' => 'estado-revision',
        ];
    }

    if (count($nombres) === 1) {
        $estado = $nombres[0];
        $estadoNormalizado = strtolower(trim($estado));
        $estadoNormalizado = strtr($estadoNormalizado, [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'ñ' => 'n',
        ]);

        $clase = 'is-neutral';
        if (str_contains($estadoNormalizado, 'aprob')) {
            $clase = 'estado-aprobado';
        } elseif (str_contains($estadoNormalizado, 'error')) {
            $clase = 'estado-con-errores';
        } elseif (str_contains($estadoNormalizado, 'revision') || str_contains($estadoNormalizado, 'revisar')) {
            $clase = 'estado-revision';
        }

        return [
            'texto' => $estado,
            'clase' => $clase,
        ];
    }

    return [
        'texto' => 'Mixto',
        'clase' => 'is-neutral',
    ];
}

function labFormularioRevisionAgruparRango(array $formularios): array
{
    $grupos = [];

    foreach ($formularios as $formulario) {
        $numeroLaboratorio = labFormularioRevisionNumeroLaboratorio($formulario);
        $formulario['numero_laboratorio'] = $numeroLaboratorio;
        $formulario['resultado_principal'] = labFormularioRevisionResultadoPrincipal($formulario);

        if (!isset($grupos[$numeroLaboratorio])) {
            $grupos[$numeroLaboratorio] = [
                'numero_laboratorio' => $numeroLaboratorio,
                'formularios' => [],
            ];
        }

        $grupos[$numeroLaboratorio]['formularios'][] = $formulario;
    }

    $resultado = [];
    foreach ($grupos as $grupo) {
        $formulariosGrupo = $grupo['formularios'];
        $resultados = [];
        foreach ($formulariosGrupo as $formulario) {
            $resultadoPrincipal = trim((string) ($formulario['resultado_principal'] ?? ''));
            if ($resultadoPrincipal !== '') {
                $resultados[] = $resultadoPrincipal;
            }
        }
        $resultados = array_values(array_unique($resultados));

        $estado = labFormularioRevisionEstadoGrupo($formulariosGrupo);
        $resultadoResumen = '-';
        if ($resultados) {
            $resultadoResumen = $resultados[0];
            if (count($resultados) > 1) {
                $resultadoResumen .= ' +' . (count($resultados) - 1);
            }
        }

        $resultado[] = [
            'numero_laboratorio' => (string) ($grupo['numero_laboratorio'] ?? '-'),
            'formularios' => $formulariosGrupo,
            'formularios_total' => count($formulariosGrupo),
            'resultado_resumen' => $resultadoResumen,
            'estado_resumen' => $estado,
        ];
    }

    usort($resultado, static function (array $left, array $right): int {
        $leftNumero = trim((string) ($left['numero_laboratorio'] ?? ''));
        $rightNumero = trim((string) ($right['numero_laboratorio'] ?? ''));

        $leftEsNumero = is_numeric($leftNumero);
        $rightEsNumero = is_numeric($rightNumero);

        if ($leftEsNumero && $rightEsNumero) {
            return (int) $leftNumero <=> (int) $rightNumero;
        }

        if ($leftEsNumero) {
            return -1;
        }

        if ($rightEsNumero) {
            return 1;
        }

        return strnatcasecmp($leftNumero, $rightNumero);
    });

    return $resultado;
}

function guardarRevisionFormularios(array $formulariosBase, array $datosTablas, string $usuario, string $comentario = ''): void
{
    labFormularioEnsureSchema();
    $pdo = Conexion::conectar();
    $useTransaction = !$pdo->inTransaction();

    if ($useTransaction) {
        $pdo->beginTransaction();
    }

    try {
        foreach ($formulariosBase as $idFormulario => $datosBase) {
            $idFormulario = (int) $idFormulario;
            if ($idFormulario <= 0) {
                continue;
            }

            labFormularioEnsureVersionInicial($idFormulario, $usuario);
            labFormularioGuardarVersionConErrores($idFormulario, $usuario, $comentario);
            labFormularioActualizarBase($idFormulario, is_array($datosBase) ? $datosBase : []);
            labFormularioActualizarDatos($idFormulario, $datosTablas[$idFormulario] ?? []);
            labFormularioGuardarVersion($idFormulario, 'corregida', $usuario, $comentario ?: 'Version corregida desde revision.');
            labFormularioRegistrarHistorial($idFormulario, 'Formulario corregido', 'Revisar', 'Revisar', $usuario, $comentario);
        }

        if ($useTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($useTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function marcarFormulariosRangoConErrores(int $idRango, string $usuario, string $comentario = ''): void
{
    labFormularioEnsureSchema();
    $pdo = Conexion::conectar();
    $useTransaction = !$pdo->inTransaction();

    if ($useTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare("
            SELECT id_formulario
              FROM formulario
             WHERE id_rango = ?
             ORDER BY id_formulario ASC
        ");
        $stmt->execute([$idRango]);
        $formularios = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($formularios as $formulario) {
            $idFormulario = (int) $formulario['id_formulario'];
            labFormularioEnsureVersionInicial($idFormulario, $usuario);
            labFormularioGuardarVersionConErrores($idFormulario, $usuario, $comentario);
            labFormularioRegistrarHistorial($idFormulario, 'Formulario marcado con errores', 'Revisar', 'Revisar', $usuario, $comentario);
        }

        if ($useTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($useTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function aprobarFormulariosRango(int $idRango, string $usuario, string $comentario = ''): void
{
    $formularios = obtenerFormulariosRevisionRango($idRango);

    foreach ($formularios as $formulario) {
        $idFormulario = (int) $formulario['id_formulario'];
        labFormularioEnsureVersionInicial($idFormulario, $usuario);
        labFormularioAprobar($idFormulario, $usuario, $comentario);
    }
}

?>
