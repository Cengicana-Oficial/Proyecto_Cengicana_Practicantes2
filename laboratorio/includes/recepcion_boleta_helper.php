<?php
require_once __DIR__ . '/solicitud_formulario_helpers.php';
require_once __DIR__ . '/recepcion_catalogo_helper.php';

/** Obtiene datos guardados; el controlador valida el permiso antes de exponerlos. */
function labRecepcionObtenerBoleta(PDO $conexion, int $id): ?array
{
    $stmt = $conexion->prepare('SELECT s.*, l.codigo_lote, tm.nombre AS tipo_nombre, tm.prefijo
        FROM solicitud s LEFT JOIN lote l ON l.id_lote = s.id_lote
        LEFT JOIN tipo_muestra tm ON tm.id_tipo = s.id_tipo WHERE s.id_solicitud = ?');
    $stmt->execute([$id]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$s) return null;
    $tipo = labCatalogoAnalisisClaveModulo($s['tipo_nombre'], $s['prefijo']);
    $analisis = $conexion->prepare('SELECT ta.nombre FROM solicitud_analisis sa JOIN tipo_analisis ta ON ta.id_tipo = sa.id_tipo_analisis WHERE sa.id_solicitud = ? ORDER BY ta.id_tipo');
    $analisis->execute([$id]);
    $lista = array_map(static fn($row) => [
        'nombre' => $row['nombre'],
        'tipo' => labCatalogoAnalisisEtiquetaModuloPlural($tipo),
    ], $analisis->fetchAll(PDO::FETCH_ASSOC));
    $muestras = $conexion->prepare("SELECT codigo_lab FROM muestra WHERE id_solicitud = ? AND codigo_lab IS NOT NULL AND codigo_lab <> '' ORDER BY numero_muestra, id_muestra");
    $muestras->execute([$id]);
    $codigos = $muestras->fetchAll(PDO::FETCH_COLUMN);
    return [
        'idSolicitud' => $id, 'tipo' => labCatalogoAnalisisEtiquetaModuloPlural($tipo),
        'institucion' => $s['institucion'], 'responsableEnvio' => $s['responsable_envio'],
        'lote' => $s['codigo_lote'], 'codigoMuestreo' => $s['codigo_muestreo'],
        'fechaMuestreo' => $s['fecha_muestreo'], 'fechaIngreso' => $s['fecha_ingreso'], 'fechaEstimada' => $s['fecha_estimada'],
        'numeroMuestras' => $s['numero_muestras'],
        'numeroLaboratorioInicio' => $codigos ? reset($codigos) : '', 'numeroLaboratorioFin' => $codigos ? end($codigos) : '',
        'ingresadoPor' => $s['ingresado_por'], 'correoIngresadoPor' => $s['correo_ingresado'],
        'recibidoPor' => $s['recibido_por'], 'correoRecibidoPor' => $s['correo_recibido'],
        'firmaIngreso' => normalizarFirmaSolicitud($s['firma_ingreso'] ?? ''),
        'firmaRecibe' => normalizarFirmaSolicitud($s['firma_recibe'] ?? ''),
        'observaciones' => $s['observaciones'], 'analisis' => $lista,
    ];
}
