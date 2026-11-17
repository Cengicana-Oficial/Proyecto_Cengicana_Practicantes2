<?php
require __DIR__ . '/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$transactionDb = null;
try {
    $action = $_GET['action'] ?? 'bootstrap';
    $u = require_user();
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') csrf();
    $input = $_POST;
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($input)) fail('Solicitud inválida.');
    $out = ['ok'=>true];
    if ($action === 'bootstrap') {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') fail('Método no permitido.',405);
        permit($u,0);
        $out += ['data'=>service_rows($u),'user'=>$u,'users'=>allowed($u,9) ? module_users() : [$u], 'areas'=>$u['rol'] === 'admin' ? module_areas() : [$u['area']], 'permissions'=>permissions(),'csrf'=>$_SESSION['tech_csrf']];
    } else {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Método no permitido.',405);
        if ($action === 'logout') {
            cengi_session_destroy();
            echo json_encode($out);
            exit;
        }
        if ($action === 'user_save' || $action === 'user_delete') fail('Las cuentas se administran desde Módulos.', 403);
        $transactionDb = storage();
        $transactionDb->beginTransaction();
        if ($action === 'user_access_save') {
            permit($u,9);
            $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
            if (!$id) fail('Selecciona un usuario válido.');
            $target = query(modules_db(),'SELECT id,es_superadmin FROM usuarios WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
            if (!$target) fail('Usuario no encontrado.',404);
            if ((int)$target['es_superadmin'] === 1 || $id === (int)$u['id']) fail('No puedes cambiar tu propio acceso ni el del superadmin.',403);
            $role = clean($input,'rol',20);
            if (!in_array($role,['','admin','profesional'],true)) fail('Selecciona Admin o Profesional.');
            $area = $role === 'profesional' ? clean($input,'area',100,true) : 'Todas';
            if ($role === 'profesional' && !in_array($area,module_areas(),true)) fail('Selecciona un área válida para el profesional.');
            if ($role === '') query(storage(),'DELETE FROM user_access WHERE user_id=?',[$id]);
            else query(storage(),'INSERT INTO user_access(user_id,rol,area) VALUES (?,?,?) ON CONFLICT(user_id) DO UPDATE SET rol=excluded.rol,area=excluded.area',[$id,$role,$area]);
        } elseif ($action === 'permission') {
            fail('Los permisos están definidos por los perfiles Admin y Profesional.',403);
        } elseif ($action === 'create' || $action === 'update') {
            permit($u,2); $id = clean($input,'id',20);
            if ($action === 'update') permit($u,10);
            if ($action === 'update') { $s = service($id); scope($u,$s); }
            $map = ['institucion'=>'Institucion','cliente'=>'Nombre_Cliente','area'=>'Area','asesor'=>'Asesor','tipo'=>'Tipo_Servicio','desc'=>'Descripcion_Actividad','lugar'=>'Lugar','email'=>'Email_Cliente','celular'=>'Celular_Cliente','emailProfesional'=>'Email_Profesional','medio'=>'Medio_Respuesta','estado'=>'Estado','lugarTransferencia'=>'Lugar_transferencia','descripcionTransferencia'=>'descripcion_transferencia'];
            $values = [];
            foreach ($map as $key=>$column) {
                $max = in_array($key,['area','asesor','tipo','medio']) ? 100 : ($key === 'desc' ? 10000 : ($key === 'descripcionTransferencia' || $key === 'lugarTransferencia' ? 255 : ($key === 'celular' ? 50 : 150)));
                $values[$column] = clean($input,$key,$max,in_array($key,['institucion','cliente','area','asesor','tipo','desc','estado']));
            }
            scope($u,$values);
            foreach (['Email_Cliente','Email_Profesional'] as $key) if ($values[$key] && !filter_var($values[$key],FILTER_VALIDATE_EMAIL)) fail('Correo electrónico inválido.');
            if (!in_array($values['Estado'],['Abierto','Finalizado'])) fail('Estado inválido.');
            $values['Fecha_Solicitud'] = date_value($input,'fechaISO') ?: date('Y-m-d');
            $values['Fecha_Cierre'] = $values['Estado'] === 'Finalizado' ? (date_value($input,'fechaCierre') ?: date('Y-m-d')) : null;
            if ($values['Fecha_Cierre'] && $values['Fecha_Cierre'] < $values['Fecha_Solicitud']) fail('El cierre no puede ser anterior a la solicitud.');
            $values['Numero_Participantes'] = filter_var($input['participantes'] ?? 0,FILTER_VALIDATE_INT);
            if ($values['Numero_Participantes'] === false || $values['Numero_Participantes'] < 0 || $values['Numero_Participantes'] > 1000000) fail('Número de participantes inválido.');
            $values['Envio_Encuesta'] = yes($input['envioEncuesta'] ?? '') ? 'Si':'No';
            $values['Transferencia'] = yes($input['transferencia'] ?? '') ? 'Si':'No';
            $values['Fecha2'] = $values['Transferencia'] === 'Si' ? date_value($input,'fechaTransferISO') : null;
            if ($values['Transferencia'] === 'No') { $values['Lugar_transferencia'] = ''; $values['descripcion_transferencia'] = ''; }
            $uploaded = null;
            if (isset($_FILES['evidencia']) && $_FILES['evidencia']['error'] !== UPLOAD_ERR_NO_FILE) {
                $f = $_FILES['evidencia'];
                if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 10*1024*1024) fail('La evidencia debe pesar menos de 10 MB.');
                $types = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'];
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']); if (!isset($types[$mime])) fail('Adjunta una imagen JPG, PNG, WebP o un PDF.');
                $name = bin2hex(random_bytes(16)) . '.' . $types[$mime];
                $uploaded = uploads_directory() . '/' . $name;
                if (!is_dir(uploads_directory())) mkdir(uploads_directory(),0755,true);
                if (!move_uploaded_file($f['tmp_name'],$uploaded)) fail('No se pudo guardar la evidencia.');
                $values['Evidencia'] = $name;
            }
            $db = primary(); $db->beginTransaction();
            try {
                if ($action === 'create') {
                    query($db,'INSERT INTO Encuestas (`' . implode('`,`',array_keys($values)) . '`) VALUES (' . implode(',',array_fill(0,count($values),'?')) . ')',array_values($values));
                    $internal = $db->lastInsertId();
                    $id = query($db,'SELECT ID_Servicio_Tecnico FROM Encuestas WHERE ID=?',[$internal])->fetchColumn();
                    if (!$id) { $id = 'ST-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4))); query($db,'UPDATE Encuestas SET ID_Servicio_Tecnico=? WHERE ID=?',[$id,$internal]); }
                } else query($db,'UPDATE Encuestas SET ' . implode(',',array_map(fn($k)=>'`'.$k.'`=?',array_keys($values))) . ' WHERE ID_Servicio_Tecnico=?',array_merge(array_values($values),[$id]));
                $m = meta($id); track($m,$action === 'create' ? 'Servicio registrado':'Servicio actualizado','Datos del servicio guardados.',$u['nombre']); save_meta($id,$m); $db->commit();
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); if ($uploaded && is_file($uploaded)) unlink($uploaded); throw $e; }
            $out['id'] = $id;
        } else {
            $id = clean($input,'id',20,true); $s = service($id); scope($u,$s); $m = meta($id);
            if ($action === 'link') {
                permit($u,3);
                if (empty($m['token'])) { $m['token'] = bin2hex(random_bytes(32)); track($m,'Enlace de encuesta generado','Enlace disponible para compartir.',$u['nombre']); }
                query(primary(),"UPDATE Encuestas SET Envio_Encuesta='Si' WHERE ID_Servicio_Tecnico=?",[$id]);
                $out['token'] = $m['token'];
            } elseif ($action === 'response') { permit($u,4); save_response($id,$input,$u['nombre']); $m = meta($id); }
            elseif ($action === 'followup') {
                permit($u,5); $state = clean($input,'estado'); if (!in_array($state,['pendiente','contactado','resuelto'])) fail('Estado de seguimiento inválido.');
                if (!query(primary(),'SELECT COUNT(*) FROM Respuestas WHERE ID_Servicio_Tecnico=?',[$id])->fetchColumn()) fail('Este servicio aún no tiene respuesta.');
                $note = clean($input,'nota',5000,true); $m['seguimiento']['estado'] = $state; $m['seguimiento']['notificado'] ??= false;
                $m['seguimiento']['notas'][] = ['fecha'=>date('d/m/Y'),'estado'=>$state,'texto'=>$note];
                track($m,'Seguimiento: ' . $state,$note,$u['nombre']);
            } elseif ($action === 'cargo') { permit($u,4); $m['cargo'] = clean($input,'cargo',150); track($m,'Cargo actualizado',$m['cargo'],$u['nombre']); }
            else fail('Acción desconocida.',404);
            save_meta($id,$m);
        }
        storage()->commit();
    }
    echo json_encode($out,JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    if ($transactionDb && $transactionDb->inTransaction()) $transactionDb->rollBack();
    if (!($e instanceof DomainException)) { http_response_code(500); error_log('Servicios técnicos: '.$e->getMessage()); }
    echo json_encode(['ok'=>false,'error'=>$e instanceof DomainException ? $e->getMessage() : 'No se pudo completar la operación. Revisa la conexión o los datos e intenta nuevamente.'],JSON_UNESCAPED_UNICODE);
}
