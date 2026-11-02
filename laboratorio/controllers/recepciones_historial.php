<?php
/**
 * Páginas de "Recepciones registradas" (Recepción): devuelve en JSON las filas de un tipo de
 * muestra y página, con la búsqueda aplicada en la base de datos, más los totales por pestaña.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/recepciones_historial_helper.php';

lab_require_module_access();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$permisosLista = ['laboratorio.solicitudes.crear', 'laboratorio.solicitudes.ver', 'laboratorio.solicitudes.editar',
    'laboratorio.solicitudes.editar.suelo', 'laboratorio.solicitudes.editar.foliar', 'laboratorio.solicitudes.editar.aguas', 'laboratorio.solicitudes.editar.cana'];
if (!array_filter($permisosLista, 'lab_can')) {
    http_response_code(403);
    echo json_encode(['error' => 'No tiene permiso para consultar las recepciones.']);
    exit;
}

$idTipo = max(0, (int) ($_GET['tipo'] ?? 0));
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);

try {
    $resultado = labRecepcionesPagina($conexion, $idTipo, $pagina, $q);
    $tabs = labRecepcionesTabs($conexion, $q);
    echo json_encode([
        'html' => implode('', array_map('labRecepcionFilaHtml', $resultado['filas'])),
        'total' => $resultado['total'],
        'muestras' => $resultado['muestras'],
        'pagina' => $resultado['pagina'],
        'paginas' => $resultado['paginas'],
        'por_pagina' => LAB_RECEPCIONES_POR_PAGINA,
        'tabs' => array_map(static fn($t) => ['id' => $t['id'], 'total' => $t['total']], $tabs),
        'todas' => array_sum(array_column($tabs, 'total')),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('[laboratorio][recepciones_historial] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'No se pudieron cargar las recepciones. Intente nuevamente.']);
}
