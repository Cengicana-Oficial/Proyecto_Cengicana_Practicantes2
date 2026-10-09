<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Ejecutar en el contenedor: php laboratorio/tests/identificacion_lote_test.php
// Las escrituras de prueba se revierten. Las tablas se preparan antes de la transacción.
require_once __DIR__ . '/../conexion.php';
require_once __DIR__ . '/../includes/identificacion_lote_helper.php';
function comprobar($valor, string $mensaje): void { if (!$valor) throw new RuntimeException($mensaje); }
function rechazar(callable $fn, string $mensaje, ?int $codigo = null): void {
    try { $fn(); } catch (InvalidArgumentException | RuntimeException $e) {
        if ($codigo !== null) comprobar($e->getCode() === $codigo, $mensaje . ': código incorrecto');
        return;
    }
    throw new RuntimeException($mensaje);
}
labMuestraIdentificacionEnsureSchema($conexion);
labIdentificacionLoteSchema($conexion);
$lista = labIdentificacionLotes($conexion);
$base = array_values(array_filter($lista, static fn($l) => count($l['muestras']) >= 2))[0] ?? null;
comprobar($base !== null, 'Se necesitan al menos dos muestras en un lote para la prueba.');
$original = json_encode($base);
$conexion->beginTransaction();
try {
    $rows = array_map(static fn($m) => [
        'id_muestra' => $m['id_muestra'],
        'etiqueta' => ['info'=>'', 'campos'=>['finca'=>'Finca de prueba', 'parcela'=>'Parcela '.$m['id_muestra']], 'origen'=>'pegado'],
    ], $base['muestras']);
    $payload = ['id_solicitud'=>$base['id_solicitud'],'version'=>$base['version'],'metodo'=>'tabular','motivo'=>'Prueba transaccional de identificación','muestras'=>$rows];
    $payload['plantilla'] = 'Agua';
    $guardado = labIdentificacionGuardarLote($conexion,$payload,'Prueba automatizada');
    comprobar($guardado['plantilla'] === 'Agua', 'No se conserva el formato del lote.');
    comprobar($guardado['version'] === $base['version'] + 1, 'La versión no avanzó.');
    comprobar(count($guardado['historial']) === count($base['historial']) + 1, 'No se creó el historial.');
    comprobar(array_column($guardado['muestras'],'id_muestra') === array_column($base['muestras'],'id_muestra'), 'Se alteraron los IDs de muestra.');
    comprobar(array_column($guardado['muestras'],'codigo_lab') === array_column($base['muestras'],'codigo_lab'), 'Se alteraron los códigos.');
    comprobar(count(array_filter($guardado['muestras'], static fn($m) => $m['estado'] === 'ok')) === count($rows), 'No se guardaron todas las etiquetas.');
    rechazar(fn()=>labIdentificacionGuardarLote($conexion,$payload,'Prueba'), 'Se permitió sobrescribir una versión anterior.',409);

    $payload['version'] = $guardado['version'];
    $payload['motivo'] = '';
    rechazar(fn()=>labIdentificacionGuardarLote($conexion,$payload,'Prueba'), 'Se permitió corregir sin motivo.');
    $payload['motivo'] = 'Corrección de prueba';
    $invalid = $payload;
    $invalid['muestras'][0]['id_muestra'] = 2147483647;
    rechazar(fn()=>labIdentificacionGuardarLote($conexion,$invalid,'Prueba'), 'Se permitió una muestra ajena.');
    $invalid = $payload;
    $invalid['muestras'][1]['id_muestra'] = $invalid['muestras'][0]['id_muestra'];
    rechazar(fn()=>labIdentificacionGuardarLote($conexion,$invalid,'Prueba'), 'Se permitieron muestras duplicadas.');
    $invalid = $payload;
    array_pop($invalid['muestras']);
    rechazar(fn()=>labIdentificacionGuardarLote($conexion,$invalid,'Prueba'), 'Se permitió omitir una muestra.');
    $invalid = $payload;
    $invalid['muestras'][0]['etiqueta']['campos']['finca'] = str_repeat('x',1001);
    rechazar(fn()=>labIdentificacionGuardarLote($conexion,$invalid,'Prueba'), 'Se permitió una celda demasiado larga.');

    $invalid = $payload;
    $invalid['plantilla'] = 'Formato inexistente';
    rechazar(fn()=>labIdentificacionGuardarLote($conexion,$invalid,'Prueba'), 'Se permitió un formato inválido.');

    // No debe generar una versión nueva si no cambió el contenido normalizado.
    $igual = labIdentificacionGuardarLote($conexion,$payload,'Prueba');
    comprobar($igual['version'] === $guardado['version'], 'Un guardado sin cambios creó una versión.');

    $payload['metodo'] = 'qr';
    foreach ($payload['muestras'] as &$m) $m['etiqueta'] = ['info'=>'', 'campos'=>[], 'origen'=>'qr'];
    unset($m);
    $payload['muestras'][0]['etiqueta']['info'] = 'SOL-TEST-123';
    rechazar(fn()=>labIdentificacionGuardarLote($conexion,$payload,'Prueba'), 'Se permitió un guardado parcial sin confirmación.');
    $payload['confirmar_pendientes'] = true;
    $parcial = labIdentificacionGuardarLote($conexion,$payload,'Prueba');
    comprobar($parcial['version'] === $guardado['version'] + 1, 'No se versionó la corrección.');
    comprobar($parcial['muestras'][0]['estado'] === 'solo_id', 'No se detectó solo ID.');
    comprobar($parcial['muestras'][1]['estado'] === 'pendiente', 'No se preservó el pendiente.');
    $payload['version'] = $parcial['version'];
    $payload['muestras'][0]['etiqueta']['info'] = "Cliente: Prueba\nFinca: El ejemplo\n".str_repeat('Etiqueta completa; ',50);
    $completa = labIdentificacionGuardarLote($conexion,$payload,'Prueba');
    comprobar($completa['muestras'][0]['estado'] === 'ok', 'No se detectó información completa.');
    comprobar($completa['muestras'][0]['etiqueta']['info'] === trim($payload['muestras'][0]['etiqueta']['info']), 'La lectura completa se truncó.');
    $payload['version'] = $completa['version'];
    $payload['plantilla'] = 'Todos los campos';
    $formato = labIdentificacionGuardarLote($conexion,$payload,'Prueba');
    comprobar($formato['version'] === $completa['version'] + 1 && $formato['plantilla'] === 'Todos los campos', 'El cambio de formato no se versionó.');
    $snapshot = $conexion->prepare('SELECT etiquetas_json FROM lab_identificacion_historial WHERE id_solicitud=? AND version=?');
    $snapshot->execute([$base['id_solicitud'],$formato['version']]);
    comprobar(json_decode($snapshot->fetchColumn(),true)['_plantilla'] === 'Todos los campos', 'El historial no conserva el formato.');
} finally {
    $conexion->rollBack();
}
comprobar(json_encode(labIdentificacionLotes($conexion,$base['id_solicitud'])[0]) === $original, 'La prueba dejó cambios persistentes.');
echo "OK: guardado por lote, historial, conflictos, validaciones, pendientes, lecturas completas e integridad de muestras. Prueba revertida.\n";
