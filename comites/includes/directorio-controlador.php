<?php
declare(strict_types=1);
require_once __DIR__.'/directorio.php';
require_once __DIR__.'/directorio-exportar.php';
if (can_view_resource($user,'contactos')) {
    directorio_preparar($pdo);
    $_SESSION['directorio_csrf'] ??= bin2hex(random_bytes(32));
}
$esApi=isset($_GET['directorio_api']) || $_SERVER['REQUEST_METHOD']==='POST' || ($_GET['accion'] ?? '')==='plantilla';
if ($esApi) {
    try {
        if (!can_view_resource($user,'contactos')) { http_response_code(403); throw new RuntimeException('No tienes acceso al directorio.'); }
        $accion=(string)($_POST['dir_accion'] ?? ($_GET['accion'] ?? 'datos'));
        $d=json_decode((string)($_POST['datos'] ?? '{}'),true,64,JSON_THROW_ON_ERROR);
        if (!is_array($d)) throw new RuntimeException('Datos no válidos.');
        if ($_SERVER['REQUEST_METHOD']==='POST' && !hash_equals($_SESSION['directorio_csrf'],(string)($_POST['csrf'] ?? ''))) { http_response_code(403); throw new RuntimeException('El formulario expiró. Recarga la página.'); }
        if ($_SERVER['REQUEST_METHOD']!=='POST' && !in_array($accion,['datos','plantilla'],true)) { http_response_code(405); throw new RuntimeException('Método no permitido.'); }
        if ($accion==='plantilla') {
            $comites=$pdo->query('SELECT nombre FROM comites WHERE activo=1 ORDER BY nombre')->fetchAll(PDO::FETCH_COLUMN);
            $valores=[['Área','Nivel','Comités (nombre exacto)']];
            for ($i=0;$i<max(count(DIRECTORIO_AREAS),count($comites));$i++) $valores[]=[DIRECTORIO_AREAS[$i] ?? '',DIRECTORIO_NIVELES[$i] ?? '',$comites[$i] ?? ''];
            $contenido=directorio_excel([['nombre'=>'Contactos','filas'=>[['Nombre completo','Cargo','Empresa / ingenio','Área','Nivel','Teléfono','Correo','Comités','Estado'],['Ing. Nombre Apellido','Jefe de Riego','Ingenio Ejemplo','Agrícola','Jefatura','5555-0000','correo@empresa.com',implode('; ',array_slice($comites,0,2)),'Activo']]],['nombre'=>'Valores permitidos','filas'=>$valores]]);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="plantilla_directorio_contactos.xlsx"'); echo $contenido; exit;
        }
        if (in_array($accion,['exportar','preview','html','pdf','enviar'],true)) {
            $datos=directorio_datos($pdo,$user); $lista=directorio_seleccionar($datos,$d); $comites=array_column($datos['comites'],'nombre','id');
            if (!$lista) throw new RuntimeException('Selecciona al menos un contacto.');
            if ($accion==='exportar') {
                $cols=array_values(array_intersect(array_keys(DIRECTORIO_COLUMNAS),(array)($d['cols'] ?? array_keys(DIRECTORIO_COLUMNAS))));
                if (!$cols) throw new RuntimeException('Selecciona columnas para exportar.');
                $filas=[array_map(static fn($c)=>DIRECTORIO_COLUMNAS[$c],$cols)];
                foreach ($lista as $c) $filas[]=array_map(static fn($col)=>directorio_valor($c,$col,$comites),$cols);
                $contenido=directorio_excel([['nombre'=>'Directorio','filas'=>$filas]]);
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="directorio_contactos.xlsx"'); echo $contenido; exit;
            }
            $html=directorio_reporte($lista,$d,$comites);
            if ($accion==='enviar') {
                if (!can_manage_resource($user,'correos')) { http_response_code(403); throw new RuntimeException('No tienes permiso para enviar correos.'); }
                directorio_enviar($pdo,$user,$d,$html); $mensaje='Lista enviada correctamente.';
            } else {
                if ($accion==='pdf') { $contenido=directorio_pdf($html); header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="directorio_contactos.pdf"'); echo $contenido; }
                else { header('Content-Type: text/html; charset=utf-8'); if ($accion==='html') header('Content-Disposition: attachment; filename="directorio_contactos.html"'); echo $html; }
                exit;
            }
        } elseif ($accion!=='datos') {
            if (!can_manage_resource($user,'contactos') && !in_array($accion,['segmento_guardar','segmento_eliminar'],true)) { http_response_code(403); throw new RuntimeException('No tienes permiso para gestionar contactos.'); }
            $mensaje=directorio_mutar($pdo,$user,$accion,$d,$_FILES);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>true,'mensaje'=>$mensaje ?? '', 'datos'=>directorio_datos($pdo,$user)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (http_response_code()<400) http_response_code(422);
        header('Content-Type: application/json; charset=utf-8');
        $mensaje=$error instanceof PDOException?'No se pudo guardar el cambio. Revisa los datos.':($error instanceof RuntimeException?$error->getMessage():'No se pudo procesar la solicitud. Revisa el archivo o los datos.');
        error_log('Directorio: '.$error->getMessage());
        echo json_encode(['ok'=>false,'error'=>$mensaje],JSON_UNESCAPED_UNICODE);
    }
    exit;
}
