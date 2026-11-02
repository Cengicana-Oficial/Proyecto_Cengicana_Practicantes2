<?php
/**
 * informe_excel.php — descarga el informe de un lote en Excel (.xlsx) con el formato del
 * laboratorio. Informe firmado: valores aprobados guardados con la firma. Borrador: valores
 * calculados en la pantalla (POST 'resultados'), marcado como borrador.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../models/informes_model.php';

lab_require_permission('laboratorio.informes.ver');

$idLote = (int) ($_POST['id_lote'] ?? $_GET['lote'] ?? 0);
$pdo = Conexion::conectar();
lab_informes_esquema($pdo);
$detalle = $idLote > 0 ? lab_informe_detalle($pdo, $idLote) : null;
if (!$detalle) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('El lote no tiene análisis registrados.');
}

$est = lab_informe_estructura($detalle);
$firmado = $detalle['lote']['estado'] === 'FIRMADO';
if ($firmado) {
    $valores = json_decode((string) ($detalle['lote']['informe']['resultados_json'] ?? ''), true) ?: [];
} else {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_SESSION['informes_csrf']) || !hash_equals((string) $_SESSION['informes_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        exit('La sesión venció. Recargue la página del informe e intente de nuevo.');
    }
    $valores = lab_informe_valores_limpios($est, json_decode((string) ($_POST['resultados'] ?? ''), true) ?: []);
}
$meta = lab_informe_meta($pdo, $detalle, !$firmado);
if (!$firmado && isset($_POST['observaciones'])) $meta['observaciones'] = mb_substr(trim((string) $_POST['observaciones']), 0, 2000);
if (!$firmado) $meta['codigo'] = $meta['codigo'] !== '' ? $meta['codigo'] . ' (borrador)' : 'Borrador';

lab_informe_xlsx($est, $detalle, $valores, $meta);
