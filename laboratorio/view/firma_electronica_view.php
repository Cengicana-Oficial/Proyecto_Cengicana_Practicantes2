<?php
/**
 * Compatibilidad: la firma electrónica dejó de ser una sección aparte; el jefe de
 * laboratorio aprueba y firma dentro de Informes (prototipo). Los enlaces antiguos
 * (?id_documento=) abren el informe del lote correspondiente.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../models/documentos_model.php';

lab_require_permission('laboratorio.informes.ver');

$destino = 'informes_view.php';
$idDocumento = (int) ($_GET['id_documento'] ?? 0);
if ($idDocumento > 0) {
    $documento = lab_documento_obtener(Conexion::conectar(), $idDocumento);
    if ($documento && (int) $documento['id_lote'] > 0) {
        $destino .= '?lote=' . (int) $documento['id_lote'];
    }
}
header('Location: ' . $destino, true, 302);
exit;
