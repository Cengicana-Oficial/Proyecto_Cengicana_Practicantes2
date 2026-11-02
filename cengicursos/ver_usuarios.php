<?php
/**
 * Usuarios de Cursos, en dos pestañas:
 *  - Equipo: coordinación, jefaturas, gestores e instructores. Su rol define qué cursos ve
 *    (todos, los de su ingenio o los que imparte).
 *  - Estudiantes: su acceso es POR CURSO. En el aula y en "Mis cursos" solo ven los cursos donde
 *    tienen una inscripción activa vinculada a su cuenta (asignaciones.usuarios_id o
 *    participantes.usuarios_id, ver aula_helpers.php). Para darle o quitarle un curso se inscribe o
 *    retira en Seguimiento por curso; aquí se ve ese acceso y se corrigen los vínculos de cuenta.
 * Las contraseñas de estudiantes y sus accesos se gestionan aquí; las cuentas nuevas se crean en login/.
 */
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/accesos_helpers.php';
require_once __DIR__ . '/inscripcion_registro.php';
require_once __DIR__ . '/estudiantes_acceso_helpers.php';

cengi_require_ver_usuarios();

$m = conectar_usuarios_menu_pdo();
$db = conectar();
$moduloId = cengi_rm_modulo_id($m, 'cursos');
$esSuper = cengi_es_superadmin();
$puedeGestionar = cengi_puede_gestionar_usuarios();
$veTodo = cengi_ve_todo_por_rol_o_ingenio();
$miIngenio = cengi_ingenio_id_actual();
$miId = (int) ($_SESSION['id_usuario'] ?? 0);
$hoy = date('Y-m-d');
if (empty($_SESSION['cengi_usuarios_csrf'])) {
    $_SESSION['cengi_usuarios_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['cengi_usuarios_csrf'];
$h = static function ($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};

$roles = cengi_acc_roles_cursos($m);
$rolesPorId = array_column($roles, null, 'id');
$rolesAsignables = array_values(array_filter($roles, 'cengi_acc_puede_asignar_rol'));
$ingenios = $m->query('SELECT id, nombre_ingenio FROM ingenios ORDER BY nombre_ingenio')->fetchAll(PDO::FETCH_KEY_PAIR);

/** Usuario con acceso a Cursos que el usuario actual puede modificar (o null). */
$objetivo = static function ($usuarioId) use ($m, $moduloId, $esSuper, $veTodo, $miIngenio, $miId, $rolesPorId) {
    $stmt = $m->prepare('SELECT u.*, um.rol_id AS rol_cursos FROM usuarios u
        INNER JOIN usuario_modulo um ON um.usuario_id = u.id AND um.modulo_id = ? WHERE u.id = ?');
    $stmt->execute([$moduloId, (int) $usuarioId]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u || (int) $u['id'] === $miId || ((int) $u['es_superadmin'] === 1 && !$esSuper)) {
        return null;
    }
    if (!$veTodo && (int) $u['ingenio_id'] !== $miIngenio) {
        return null;
    }
    if (cengi_es_gestor_ingenio() && !cengi_acc_es_estudiante($rolesPorId[(int) $u['rol_cursos']]['nombre_rol'] ?? '')) return null;
    return $u;
};
/** Ficha de participante (base de cursos) dentro del alcance del usuario actual. */
$ficha = static function ($participanteId) use ($db, $veTodo, $miIngenio) {
    $stmt = $db->prepare('SELECT * FROM participantes WHERE id = ?');
    $stmt->execute([(int) $participanteId]);
    $p = $stmt->fetch(PDO::FETCH_ASSOC);
    return $p && ($veTodo || (int) $p['ingenio_id'] === $miIngenio) ? $p : null;
};

// ---------------------------------------------------------------- acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $volver = array_filter(['tab' => $_POST['tab'] ?? '', 'q' => $_POST['q'] ?? '', 'rol' => $_POST['f_rol'] ?? '', 'ingenio' => $_POST['f_ingenio'] ?? '', 'pagina' => $_POST['pagina'] ?? '', 'por_pagina' => $_POST['por_pagina'] ?? '', 'anio' => $_POST['anio'] ?? ''], 'strlen');
    $msg = 'sin_permiso';
    $extra = [];
    if ($puedeGestionar && hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
      try {
        if ($accion === 'editar_estudiante') {
            $u = $objetivo($_POST['usuario_id'] ?? 0);
            if ($u && (int) $u['es_superadmin'] !== 1 && cengi_acc_es_estudiante($rolesPorId[(int) $u['rol_cursos']]['nombre_rol'] ?? '')) {
                cengi_acc_actualizar_estudiante($m, $db, $u, $_POST['nombre'] ?? '', $_POST['correo'] ?? '', $veTodo, $miIngenio);
                $msg = 'datos_guardados';
                $extra = ['ver' => $u['id'], 'tab' => 'estudiantes'];
            }
        } elseif ($accion === 'suspender_aula' || $accion === 'habilitar_aula') {
            $u = $objetivo($_POST['usuario_id'] ?? 0);
            $cursoId = (int) ($_POST['curso_id'] ?? 0);
            if ($u && (int) $u['es_superadmin'] !== 1 && cengi_acc_es_estudiante($rolesPorId[(int) $u['rol_cursos']]['nombre_rol'] ?? '')) {
                $q = $db->prepare('SELECT p.ingenio_id FROM asignaciones a JOIN participantes p ON p.id = a.participantes_id WHERE a.cursos_id = ? AND a.estado_asignaciones = 1 AND p.estado_participantes = 1 AND (a.usuarios_id = ? OR p.usuarios_id = ?)');
                $q->execute([$cursoId, $u['id'], $u['id']]);
                $instituciones = $q->fetchAll(PDO::FETCH_COLUMN);
                if ($instituciones && ($veTodo || !array_filter($instituciones, static function ($id) use ($miIngenio) { return (int) $id !== $miIngenio; }))) {
                    if ($accion === 'suspender_aula') $db->prepare('INSERT INTO estudiante_aula_bloqueos (usuario_id, curso_id, actualizado_por) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE actualizado_por = VALUES(actualizado_por), actualizado = NOW()')->execute([$u['id'], $cursoId, $miId]);
                    else $db->prepare('DELETE FROM estudiante_aula_bloqueos WHERE usuario_id = ? AND curso_id = ?')->execute([$u['id'], $cursoId]);
                    $msg = $accion === 'suspender_aula' ? 'aula_suspendida' : 'aula_habilitada';
                    $extra = ['ver' => $u['id'], 'tab' => 'estudiantes'];
                }
            }
        } elseif ($accion === 'cambiar_rol') {
            $u = $objetivo($_POST['usuario_id'] ?? 0);
            $rol = $rolesPorId[(int) ($_POST['rol_id'] ?? 0)] ?? null;
            if ($u && (int) $u['es_superadmin'] !== 1 && $rol && cengi_acc_puede_asignar_rol($rol)) {
                cengi_rm_asignar($m, $u['id'], $moduloId, $rol['id']);
                $msg = 'rol';
                $extra = ['ver' => $u['id'], 'tab' => cengi_acc_es_estudiante($rol['nombre_rol']) ? 'estudiantes' : 'equipo'];
            }
        } elseif ($accion === 'resetear_password') {
            $u = $objetivo($_POST['usuario_id'] ?? 0);
            $password = (string) ($_POST['password'] ?? '');
            if ($u && (int) $u['es_superadmin'] !== 1 && cengi_acc_es_estudiante($rolesPorId[(int) $u['rol_cursos']]['nombre_rol'] ?? '')) {
                $extra = ['ver' => $u['id'], 'tab' => 'estudiantes'];
                if ($password !== (string) ($_POST['password_confirmacion'] ?? '') || strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
                    $msg = 'password_invalida';
                } else {
                    cengi_acc_reset_password($m, $u['id'], $password, (string) ($_POST['password_confirmacion'] ?? ''));
                    $msg = 'password';
                }
            }
        } elseif ($accion === 'agregar_curso' || $accion === 'quitar_curso') {
            $u = $objetivo($_POST['usuario_id'] ?? 0);
            $p = $ficha($_POST['participante_id'] ?? 0);
            $cursoId = (int) ($_POST['curso_id'] ?? 0);
            if ($u && $p && (int) $p['usuarios_id'] === (int) $u['id'] && cengi_acc_es_estudiante($rolesPorId[(int) $u['rol_cursos']]['nombre_rol'] ?? '')) {
                $db->beginTransaction();
                if ($accion === 'quitar_curso') {
                    $db->prepare('UPDATE asignaciones SET estado_asignaciones = 0, actualizado = NOW() WHERE participantes_id = ? AND cursos_id = ?')->execute([$p['id'], $cursoId]);
                    $q = $db->prepare("SELECT id_solicitud FROM solicitudes_inscripcion WHERE curso_id = ? AND cui_participante = ? AND estado = 'Aprobado'");
                    $q->execute([$cursoId, $p['cui_participantes']]);
                    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $sid) cengi_ins_quitar_asignacion($db, cengi_ins_solicitud($db, $sid, true));
                    $msg = 'curso_quitado';
                } else {
                    $sid = cengi_ins_registrar_persona($db, $p['id'], $cursoId);
                    $sol = cengi_ins_solicitud($db, $sid, true);
                    if ((int) $sol['ingenio_id'] !== (int) $p['ingenio_id'] || strcasecmp(trim((string) $sol['correo']), trim((string) $u['correo'])) !== 0) {
                        throw new InvalidArgumentException('La inscripción no corresponde a la cuenta y al ingenio del estudiante.');
                    }
                    if (cengi_ins_admitida($sol)) {
                        cengi_ins_asignar($db, $sol, $_SESSION['nombre'] ?? 'Gestión de estudiantes');
                        $msg = 'curso_agregado';
                    } else $msg = 'curso_pendiente';
                }
                $db->commit();
                $extra = ['ver' => $u['id'], 'tab' => 'estudiantes'];
            }
        } elseif ($accion === 'cambiar_ingenio') {
            $u = $objetivo($_POST['usuario_id'] ?? 0);
            $ingenioId = (int) ($_POST['ingenio_id'] ?? 0);
            if ($u && $veTodo && isset($ingenios[$ingenioId])) {
                $m->prepare('UPDATE usuarios SET ingenio_id = ? WHERE id = ?')->execute([$ingenioId, $u['id']]);
                $msg = 'ingenio';
                $extra = ['ver' => $u['id']];
            }
        } elseif ($accion === 'quitar_acceso') {
            $u = $objetivo($_POST['usuario_id'] ?? 0);
            if ($u && (int) $u['es_superadmin'] !== 1) {
                $m->prepare('DELETE FROM usuario_modulo WHERE usuario_id = ? AND modulo_id = ?')->execute([$u['id'], $moduloId]);
                $msg = 'quitado';
            }
        } elseif ($accion === 'vincular_ficha') {
            // La cuenta del estudiante se une a su ficha de participante (mismo correo): desde ahí
            // ve en el aula todos los cursos donde esa ficha tenga una inscripción activa.
            $u = $objetivo($_POST['usuario_id'] ?? 0);
            $p = $ficha($_POST['participante_id'] ?? 0);
            if ($u && $p && empty($p['usuarios_id']) && strcasecmp(trim((string) $p['correo_participantes']), trim((string) $u['correo'])) === 0) {
                $db->prepare('UPDATE participantes SET usuarios_id = ?, actualizado = NOW() WHERE id = ? AND usuarios_id IS NULL')->execute([$u['id'], $p['id']]);
                $msg = 'vinculado';
                $extra = ['ver' => $u['id'], 'tab' => 'estudiantes'];
            }
        } elseif ($accion === 'corregir_vinculo') {
            // Inscripciones de su ficha que quedaron ligadas a OTRA cuenta: pasan a esta (la otra
            // cuenta deja de ver esos cursos como estudiante).
            $u = $objetivo($_POST['usuario_id'] ?? 0);
            $p = $ficha($_POST['participante_id'] ?? 0);
            if ($u && $p && (int) $p['usuarios_id'] === (int) $u['id']) {
                $db->prepare('UPDATE asignaciones SET usuarios_id = ?, actualizado = NOW() WHERE participantes_id = ? AND usuarios_id IS NOT NULL AND usuarios_id <> ?')
                    ->execute([$u['id'], $p['id'], $u['id']]);
                $msg = 'corregido';
                $extra = ['ver' => $u['id'], 'tab' => 'estudiantes'];
            }
        } elseif ($accion === 'dar_acceso') {
            $correo = trim((string) ($_POST['correo'] ?? ''));
            $rol = $rolesPorId[(int) ($_POST['rol_id'] ?? 0)] ?? null;
            $stmt = $m->prepare('SELECT * FROM usuarios WHERE LOWER(correo) = LOWER(?) LIMIT 1');
            $stmt->execute([$correo]);
            $u = $correo !== '' ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            if (!$u) {
                $msg = 'no_existe';
                $extra = ['correo' => $correo];
            } elseif (!$veTodo && (int) $u['ingenio_id'] !== $miIngenio) {
                $msg = 'sin_permiso';
            } elseif (!$rol || !cengi_acc_puede_asignar_rol($rol) || ((int) $u['es_superadmin'] === 1 && !$esSuper)) {
                $msg = 'sin_permiso';
            } else {
                $ya = $m->prepare('SELECT COUNT(*) FROM usuario_modulo WHERE usuario_id = ? AND modulo_id = ?');
                $ya->execute([$u['id'], $moduloId]);
                if ((int) $ya->fetchColumn() > 0) {
                    $msg = 'ya_tiene';
                } else {
                    $ingenioId = $veTodo ? (int) ($_POST['ingenio_id'] ?? 0) : $miIngenio;
                    if ($ingenioId > 0 && isset($ingenios[$ingenioId]) && (int) $u['ingenio_id'] !== $ingenioId && (int) $u['es_superadmin'] !== 1) {
                        $m->prepare('UPDATE usuarios SET ingenio_id = ? WHERE id = ?')->execute([$ingenioId, $u['id']]);
                    }
                    cengi_rm_asignar($m, $u['id'], $moduloId, $rol['id']);
                    $msg = 'agregado';
                }
                $extra = ['ver' => $u['id'], 'tab' => cengi_acc_es_estudiante($rol['nombre_rol']) ? 'estudiantes' : 'equipo'];
            }
        }
      } catch (Throwable $e) {
        if ($m->inTransaction()) $m->rollBack();
        if ($db->inTransaction()) $db->rollBack();
        error_log('Gestión de usuarios Cursos: ' . $e->getMessage());
        $extra = ['ver' => (int) ($_POST['usuario_id'] ?? 0), 'tab' => $_POST['tab'] ?? 'estudiantes'];
        $msg = in_array($e->getMessage(), ['datos_invalidos', 'correo_ocupado', 'sin_permiso', 'password_invalida'], true) ? $e->getMessage() : 'error_guardar';
      }
    }
    header('Location: ver_usuarios.php?' . http_build_query(array_merge($volver, ['msg' => $msg], $extra)) . (isset($extra['ver']) ? '#u-' . (int) $extra['ver'] : ''));
    exit;
}

// ---------------------------------------------------------------- usuarios de Cursos
$sql = "SELECT u.id, u.nombre, u.correo, u.es_superadmin, u.ingenio_id, i.nombre_ingenio,
        CASE WHEN u.es_superadmin = 1 THEN u.rol_id ELSE um.rol_id END AS rol_id
    FROM usuarios u
    INNER JOIN usuario_modulo um ON um.usuario_id = u.id AND um.modulo_id = ?
    LEFT JOIN ingenios i ON i.id = u.ingenio_id
    WHERE 1 = 1";
$params = [$moduloId];
if (!$veTodo) {
    $sql .= ' AND u.ingenio_id = ?';
    $params[] = $miIngenio;
}
$stmt = $m->prepare($sql . ' ORDER BY u.nombre');
$stmt->execute($params);
$todos = $stmt->fetchAll(PDO::FETCH_ASSOC);
$nombresUsuario = array_column($m->query('SELECT id, nombre FROM usuarios')->fetchAll(PDO::FETCH_ASSOC), 'nombre', 'id');

foreach ($todos as &$u) {
    $u['nombre_rol'] = $rolesPorId[(int) $u['rol_id']]['nombre_rol'] ?? '';
    $u['es_estudiante'] = cengi_acc_es_estudiante($u['nombre_rol']) && (int) $u['es_superadmin'] !== 1;
}
unset($u);

// ---------------------------------------------------------------- acceso por curso (estudiantes)
$estado = static function ($inicio, $fin) use ($hoy) {
    if ($fin && $fin < $hoy) {
        return ['Finalizado', 'is-fin'];
    }
    return $inicio && $inicio > $hoy ? ['Próximo', 'is-proximo'] : ['En curso', 'is-curso'];
};
$idsEst = array_map('intval', array_column(array_filter($todos, static function ($u) { return $u['es_estudiante']; }), 'id'));
$acceso = [];   // uid => [curso_id => curso]
$fichas = [];   // uid => [participante_id => ficha]
$ajenas = [];   // uid => [participante_id => [cursos ligados a otra cuenta]]
$candidatas = []; // uid => fichas sin cuenta con el mismo correo
if ($idsEst) {
    $in = implode(',', $idsEst);
    $filas = $db->query("SELECT a.id AS aid, a.usuarios_id AS ua, a.estado_asignaciones, p.id AS pid, p.usuarios_id AS up, p.estado_participantes,
            p.nombre_participantes, p.cui_participantes, p.ingenio_id, c.id AS cid, c.nombre_cursos, c.inicio, c.fin
        FROM asignaciones a INNER JOIN participantes p ON p.id = a.participantes_id INNER JOIN cursos c ON c.id = a.cursos_id
        WHERE a.usuarios_id IN ($in) OR p.usuarios_id IN ($in) ORDER BY c.inicio DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($filas as $f) {
        foreach (array_unique(array_filter([(int) $f['ua'], (int) $f['up']])) as $uid) {
            if (!in_array($uid, $idsEst, true)) {
                continue;
            }
            if ((int) $f['up'] === $uid) {
                $fichas[$uid][(int) $f['pid']] = $f;
            }
            if ((int) $f['estado_asignaciones'] === 1 && (int) $f['estado_participantes'] === 1) {
                $acceso[$uid][(int) $f['cid']] = $f;
            }
            if ((int) $f['up'] === $uid && $f['ua'] && (int) $f['ua'] !== $uid) {
                $ajenas[$uid][(int) $f['pid']][] = $f;
            }
        }
    }
    $qFichas = $db->query("SELECT id AS pid, nombre_participantes, cui_participantes, ingenio_id, usuarios_id AS up FROM participantes WHERE usuarios_id IN ($in)");
    foreach ($qFichas->fetchAll(PDO::FETCH_ASSOC) as $f) $fichas[(int) $f['up']][(int) $f['pid']] = $f;
    // Una consulta para las fichas candidatas, incluso con cientos de estudiantes.
    $sinFichaPorCorreo = [];
    foreach ($todos as $u) {
        if ($u['es_estudiante'] && empty($fichas[(int) $u['id']]) && trim((string) $u['correo']) !== '') {
            $sinFichaPorCorreo[strtolower(trim($u['correo']))][] = (int) $u['id'];
        }
    }
    if ($sinFichaPorCorreo) {
        $marcas = implode(',', array_fill(0, count($sinFichaPorCorreo), '?'));
        $scopeFicha = $veTodo ? '' : ' AND p.ingenio_id = ' . (int) $miIngenio;
        $stmtC = $db->prepare("SELECT p.id, p.nombre_participantes, p.cui_participantes, p.ingenio_id, LOWER(TRIM(p.correo_participantes)) AS correo
            FROM participantes p WHERE p.usuarios_id IS NULL AND LOWER(TRIM(p.correo_participantes)) IN ($marcas) $scopeFicha");
        $stmtC->execute(array_keys($sinFichaPorCorreo));
        foreach ($stmtC->fetchAll(PDO::FETCH_ASSOC) as $p) {
            foreach ($sinFichaPorCorreo[$p['correo']] ?? [] as $uid) $candidatas[$uid][] = $p;
        }
    }
}
$sqlSinCuenta = 'SELECT COUNT(DISTINCT p.id) FROM asignaciones a INNER JOIN participantes p ON p.id = a.participantes_id
    WHERE a.estado_asignaciones = 1 AND p.estado_participantes = 1 AND a.usuarios_id IS NULL AND p.usuarios_id IS NULL' . ($veTodo ? '' : ' AND p.ingenio_id = ' . (int) $miIngenio);
$inscritosSinCuenta = (int) $db->query($sqlSinCuenta)->fetchColumn();

// ---------------------------------------------------------------- cursos que ve el equipo
$cursosPorIngenio = array_map('intval', $db->query('SELECT p.ingenio_id, COUNT(DISTINCT a.cursos_id) FROM asignaciones a
    INNER JOIN participantes p ON p.id = a.participantes_id WHERE a.estado_asignaciones = 1 GROUP BY p.ingenio_id')->fetchAll(PDO::FETCH_KEY_PAIR));
$totalCursos = (int) $db->query('SELECT COUNT(*) FROM cursos')->fetchColumn();
$imparte = [];
foreach ($db->query("SELECT LOWER(i.correo) AS correo, COUNT(DISTINCT c.id) AS n FROM instructores i
    INNER JOIN cursos c ON c.instructor_id = i.id OR c.id IN (SELECT cm.curso_id FROM curso_modulos cm INNER JOIN curso_modulo_instructores cmi ON cmi.curso_modulo_id = cm.id WHERE cmi.instructor_id = i.id)
    WHERE i.correo <> '' GROUP BY LOWER(i.correo)")->fetchAll(PDO::FETCH_ASSOC) as $fi) {
    $imparte[$fi['correo']] = (int) $fi['n'];
}
$permisosRol = [];
$alcance = static function (array $u) use ($m, &$permisosRol, $cursosPorIngenio, $totalCursos, $imparte) {
    $rolId = (int) $u['rol_id'];
    if (!isset($permisosRol[$rolId])) {
        $permisosRol[$rolId] = $rolId ? obtener_permisos_rol($m, $rolId) : [];
    }
    $n = cengi_rm_norm($u['nombre_rol']);
    if ((int) $u['es_superadmin'] === 1 || in_array($n, ['admin', 'administrador', 'superadmin'], true) || in_array('administrar_cursos_cengi', $permisosRol[$rolId], true)) {
        return ['Todos los cursos', $totalCursos . ' curso(s) · todos los ingenios', 'is-todo'];
    }
    if ($rolId === 0) {
        return ['Ninguno', 'Sin rol en Cursos', 'is-nada'];
    }
    $partes = [];
    if (strpos($n, 'instructor') !== false || strpos($n, 'docente') !== false) {
        $c = $imparte[strtolower(trim((string) $u['correo']))] ?? null;
        $partes[] = $c === null ? 'Sin ficha de instructor con su correo' : 'Imparte ' . $c . ' curso(s)';
    }
    if (!$u['ingenio_id']) {
        return ['Sin ingenio asignado', implode(' · ', $partes) ?: 'Asígnale un ingenio para que vea sus cursos', 'is-nada'];
    }
    array_unshift($partes, ($cursosPorIngenio[(int) $u['ingenio_id']] ?? 0) . ' curso(s) con participantes de su ingenio');
    return ['Cursos de ' . ($u['nombre_ingenio'] ?: 'su ingenio'), implode(' · ', $partes), 'is-ingenio'];
};

$cursosCatalogo = $db->query('SELECT id, nombre_cursos, inicio, fin, YEAR(inicio) AS anio FROM cursos ORDER BY inicio DESC, nombre_cursos')->fetchAll(PDO::FETCH_ASSOC);
$aniosCursos = array_values(array_unique(array_filter(array_map('intval', array_column($cursosCatalogo, 'anio')))));
$anioVista = (string) ($_GET['anio'] ?? date('Y'));
if ($anioVista !== 'todos' && $anioVista !== 'sin' && (!ctype_digit($anioVista) || strlen($anioVista) !== 4)) $anioVista = date('Y');
$cursoEnAnio = static function ($c) use ($anioVista) {
    return $anioVista === 'todos' || ($anioVista === 'sin' ? empty($c['inicio']) : substr((string) $c['inicio'], 0, 4) === $anioVista);
};
$cursosDisponibles = array_column(array_filter($cursosCatalogo, $cursoEnAnio), 'nombre_cursos', 'id');
$bloqueosAula = [];
try {
    foreach ($db->query('SELECT usuario_id, curso_id FROM estudiante_aula_bloqueos')->fetchAll(PDO::FETCH_ASSOC) as $b) $bloqueosAula[(int) $b['usuario_id']][(int) $b['curso_id']] = true;
} catch (PDOException $e) { error_log('Pendiente migración de acceso al aula: ' . $e->getMessage()); }
// ---------------------------------------------------------------- pestaña, filtros
$tab = ($_GET['tab'] ?? '') === 'estudiantes' ? 'estudiantes' : 'equipo';
$busqueda = trim((string) ($_GET['q'] ?? ''));
$filtroRol = (string) ($_GET['rol'] ?? '');
$filtroIngenio = (int) ($_GET['ingenio'] ?? 0);
$enTab = array_values(array_filter($todos, static function ($u) use ($tab) {
    return $tab === 'estudiantes' ? $u['es_estudiante'] : !$u['es_estudiante'];
}));
$conteoTab = ['equipo' => count($todos) - count($idsEst), 'estudiantes' => count($idsEst)];
$conteoRol = [];
foreach ($enTab as $u) {
    $clave = $u['rol_id'] !== null ? (string) (int) $u['rol_id'] : 'sin';
    $conteoRol[$clave] = ($conteoRol[$clave] ?? 0) + 1;
}
$lista = array_values(array_filter($enTab, static function ($u) use ($busqueda, $filtroRol, $filtroIngenio, $acceso) {
    if ($filtroRol !== '' && ($filtroRol === 'sin' ? $u['rol_id'] !== null : (int) $u['rol_id'] !== (int) $filtroRol)) {
        return false;
    }
    if ($filtroIngenio > 0 && (int) $u['ingenio_id'] !== $filtroIngenio) {
        return false;
    }
    if ($busqueda === '') {
        return true;
    }
    $cursos = implode(' ', array_column($acceso[(int) $u['id']] ?? [], 'nombre_cursos'));
    $texto = cengi_rm_norm($u['nombre'] . ' ' . $u['correo'] . ' ' . ($u['nombre_ingenio'] ?? '') . ' ' . $u['nombre_rol'] . ' ' . $cursos);
    return strpos($texto, cengi_rm_norm($busqueda)) !== false;
}));
$porPaginaEst = (int) ($_GET['por_pagina'] ?? 25);
$paginaEst = (int) ($_GET['pagina'] ?? 1);
$totalEst = count($lista);
$paginasEst = 1;
if ($tab === 'estudiantes') {
    [$lista, $paginaEst, $porPaginaEst, $totalEst, $paginasEst] = cengi_acc_paginar_estudiantes($lista, $paginaEst, $porPaginaEst, $_GET['ver'] ?? 0);
}
$sinCursos = count(array_filter($idsEst, static function ($id) use ($acceso) { return empty($acceso[$id]); }));
$conAjenas = count($ajenas);

$mensajes = [
    'datos_guardados' => ['ok', 'Datos de la cuenta actualizados. El correo se sincronizó con sus fichas e inscripciones vinculadas.'],
    'datos_invalidos' => ['error', 'Indica un nombre y un correo válido (máximo 100 caracteres).'],
    'correo_ocupado' => ['error', 'Ese correo pertenece a otra cuenta. No se hizo ningún cambio.'],
    'aula_suspendida' => ['ok', 'Acceso al aula suspendido. La inscripción y el historial académico se conservan.'],
    'aula_habilitada' => ['ok', 'Acceso al aula habilitado.'],
    'curso_quitado' => ['ok', 'Acceso al curso retirado. Se conserva su historial.'],
    'curso_agregado' => ['ok', 'El estudiante ya tiene acceso al curso.'],
    'curso_pendiente' => ['warn', 'Inscripción registrada. El acceso se habilitará cuando se valide el patrocinio o el pago.'],
    'password' => ['ok', 'Contraseña restablecida. Comunica la nueva contraseña al estudiante.'],
    'password_invalida' => ['error', 'Las contraseñas deben coincidir y tener al menos 8 caracteres, con letras y números.'],
    'error_guardar' => ['error', 'No se pudo guardar el cambio. Intenta de nuevo.'],
    'rol' => ['ok', 'Rol en Cursos actualizado. Se aplica desde la próxima página que abra la persona.'],
    'ingenio' => ['ok', 'Ingenio actualizado.'],
    'quitado' => ['ok', 'Se quitó el acceso a Cursos. Su cuenta se conserva.'],
    'agregado' => ['ok', 'La persona ya tiene acceso a Cursos con el rol elegido.'],
    'vinculado' => ['ok', 'Cuenta vinculada a su ficha de participante: ya ve en el aula los cursos en los que está inscrita.'],
    'corregido' => ['ok', 'Las inscripciones de su ficha quedaron ligadas a esta cuenta; la otra cuenta ya no ve esos cursos como estudiante.'],
    'ya_tiene' => ['warn', 'Esa persona ya tenía acceso a Cursos: cambia su rol desde Acciones.'],
    'no_existe' => ['warn', 'No hay ninguna cuenta con el correo ' . ($_GET['correo'] ?? '') . '. Créala con “Nuevo usuario”.'],
    'sin_permiso' => ['error', 'No se hizo el cambio: no tienes permiso para esa persona o ese rol.'],
];
[$mensajeTipo, $mensaje] = $mensajes[$_GET['msg'] ?? ''] ?? ['', ''];
$url = static function (array $cambios) use ($tab, $busqueda, $filtroRol, $filtroIngenio, $anioVista, $porPaginaEst) {
    return 'ver_usuarios.php?' . http_build_query(array_filter(array_merge(['por_pagina' => $porPaginaEst, 'anio' => $anioVista, 'tab' => $tab === 'equipo' ? '' : $tab, 'q' => $busqueda, 'rol' => $filtroRol, 'ingenio' => $filtroIngenio ?: ''], $cambios), 'strlen'));
};
$ocultos = '<input type="hidden" name="pagina" value="' . $paginaEst . '"><input type="hidden" name="por_pagina" value="' . $porPaginaEst . '"><input type="hidden" name="anio" value="' . $h($anioVista) . '"><input type="hidden" name="csrf" value="' . $h($csrf) . '"><input type="hidden" name="tab" value="' . $h($tab) . '"><input type="hidden" name="q" value="' . $h($busqueda) . '"><input type="hidden" name="f_rol" value="' . $h($filtroRol) . '"><input type="hidden" name="f_ingenio" value="' . ($filtroIngenio ?: '') . '">';
$css = static function ($archivo) {
    return (int) (@filemtime(__DIR__ . '/css/' . $archivo) ?: 1);
};

/** Menú "Acciones" común (rol, ingenio, administración central, quitar acceso) + extras. */
$menuAcciones = static function (array $u, $extras = '') use ($puedeGestionar, $esSuper, $miId, $veTodo, $rolesAsignables, $ingenios, $ocultos, $h) {
    $esSuperU = (int) $u['es_superadmin'] === 1;
    $esYo = (int) $u['id'] === $miId;
    $editable = $puedeGestionar && !$esYo && !$esSuperU && (!cengi_es_gestor_ingenio() || $u['es_estudiante']);
    if (!$puedeGestionar) {
        return '<span class="acc-solo-lectura">Solo lectura</span>';
    }
    ob_start(); ?>
    <details class="cengi-acc">
        <summary class="cengi-course-actions-toggle"><span class="course-actions-dots" aria-hidden="true">⋯</span> Acciones <span class="course-actions-chevron" aria-hidden="true"></span></summary>
        <div class="cengi-course-actions-panel cengi-acc-panel acc-menu">
            <div class="course-actions-heading"><strong>Acceso a Cursos</strong><small><?php echo $h($u['nombre']); ?></small></div>
            <?php echo $extras; ?>
            <?php if ($editable): ?>
                <form method="post" class="acc-menu-form">
                    <input type="hidden" name="accion" value="cambiar_rol"><input type="hidden" name="usuario_id" value="<?php echo (int) $u['id']; ?>"><?php echo $ocultos; ?>
                    <label>Rol en Cursos<select name="rol_id">
                        <?php foreach ($rolesAsignables as $r): ?><option value="<?php echo $r['id']; ?>" <?php echo (int) $u['rol_id'] === $r['id'] ? 'selected' : ''; ?>><?php echo $h($r['nombre_rol']); ?></option><?php endforeach; ?>
                    </select></label>
                    <button type="submit" class="ing-btn"><span class="glyphicon glyphicon-ok"></span> Cambiar rol</button>
                </form>
                <?php if ($veTodo): ?>
                    <form method="post" class="acc-menu-form">
                        <input type="hidden" name="accion" value="cambiar_ingenio"><input type="hidden" name="usuario_id" value="<?php echo (int) $u['id']; ?>"><?php echo $ocultos; ?>
                        <label>Ingenio<select name="ingenio_id">
                            <?php foreach ($ingenios as $id => $nombre): ?><option value="<?php echo (int) $id; ?>" <?php echo (int) $u['ingenio_id'] === (int) $id ? 'selected' : ''; ?>><?php echo $h($nombre); ?></option><?php endforeach; ?>
                        </select></label>
                        <button type="submit" class="ing-btn is-linea"><span class="glyphicon glyphicon-globe"></span> Cambiar ingenio</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <p class="acc-menu-nota"><?php echo $esYo ? 'No puedes cambiar tu propio rol.' : 'Superadministrador: se administra en la administración central.'; ?></p>
            <?php endif; ?>
            <?php if (!$u['es_estudiante']): ?><a class="cengi-action-btn" href="../login/usuarios/editar_usuario.php?<?php echo $h(http_build_query(['id' => (int) $u['id'], 'scope' => 'cursos'])); ?>"><span class="glyphicon glyphicon-cog"></span> Editar en administración central</a><?php endif; ?>

            <?php if ($editable): ?>
                <form method="post" class="cengi-inline-form" onsubmit="return confirm('¿Quitar el acceso a Cursos de <?php echo $h(addslashes($u['nombre'])); ?>? Su cuenta se conserva.');">
                    <input type="hidden" name="accion" value="quitar_acceso"><input type="hidden" name="usuario_id" value="<?php echo (int) $u['id']; ?>"><?php echo $ocultos; ?>
                    <button type="submit" class="cengi-action-btn is-delete"><span class="glyphicon glyphicon-log-out"></span> Quitar acceso a Cursos</button>
                </form>
            <?php endif; ?>
        </div>
    </details>
    <?php return ob_get_clean();
};
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Usuarios</title>
    <link rel="stylesheet" href="css/bootstrap.min.css">
    <link rel="stylesheet" href="css/bootstrap-theme.css">
    <link rel="stylesheet" href="css/proyecto.css?v=<?php echo $css('proyecto.css'); ?>">
    <link rel="stylesheet" href="css/cengi-tema.css?v=<?php echo $css('cengi-tema.css'); ?>">
    <link rel="stylesheet" href="css/ingenios.css?v=<?php echo $css('ingenios.css'); ?>">
    <link rel="stylesheet" href="css/accesos.css?v=<?php echo $css('accesos.css'); ?>">
    <script src="js/jquery-3.2.1.min.js"></script>
    <script src="js/bootstrap.min.js"></script>
</head>
<body class="cengi-canvas cg-tema">
<?php menu_render(); ?>

<main class="container ing-page acc-page">
    <header class="ing-hero">
        <div>
            <small>Sistema</small>
            <h1>Usuarios</h1>
            <p>Quién entra a Cursos y qué cursos puede ver<?php echo $veTodo ? '' : ' (solo los de tu ingenio)'; ?>. El equipo ve cursos según su <a href="roles.php">rol</a>;
                cada estudiante ve <b>solo los cursos en los que está inscrito</b>. Desde Estudiantes puedes gestionar accesos y restablecer contraseñas.</p>
        </div>
        <?php if ($puedeGestionar): ?>
            <div class="acc-hero-acciones">
                <a class="ing-btn is-claro" href="../login/usuarios/crear_usuario.php?scope=cursos"><span class="glyphicon glyphicon-plus"></span> Nuevo usuario</a>
                <a class="ing-btn is-linea-claro" href="../login/usuarios/usuarios.php?scope=cursos"><span class="glyphicon glyphicon-cog"></span> Administración central</a>
            </div>
        <?php endif; ?>
    </header>

    <?php if ($mensaje !== ''): ?>
        <div class="ing-aviso is-<?php echo $mensajeTipo === 'ok' ? 'ok' : ($mensajeTipo === 'warn' ? 'warn' : 'error'); ?>" role="status">
            <span class="glyphicon glyphicon-<?php echo $mensajeTipo === 'ok' ? 'ok' : 'warning-sign'; ?>"></span>
            <div><p><?php echo $h($mensaje); ?></p></div>
        </div>
    <?php endif; ?>

    <nav class="acc-tabs" aria-label="Tipo de usuario">
        <a href="ver_usuarios.php" class="<?php echo $tab === 'equipo' ? 'is-activo' : ''; ?>"><span class="glyphicon glyphicon-briefcase"></span> Equipo <b><?php echo $conteoTab['equipo']; ?></b><small>Coordinación, jefaturas, gestores e instructores</small></a>
        <a href="ver_usuarios.php?tab=estudiantes" class="<?php echo $tab === 'estudiantes' ? 'is-activo' : ''; ?>"><span class="glyphicon glyphicon-education"></span> Estudiantes <b><?php echo $conteoTab['estudiantes']; ?></b><small>Acceso por curso inscrito<?php echo $sinCursos || $conAjenas ? ' · ' . ($sinCursos + $conAjenas) . ' por revisar' : ''; ?></small></a>
    </nav>

    <?php if ($tab === 'estudiantes'): ?>
        <div class="acc-explica">
            <span class="glyphicon glyphicon-lock"></span>
            <div><b>¿Cómo funciona el acceso por curso?</b> Un estudiante solo ve en el aula los cursos donde su <b>ficha de participante</b> tiene una inscripción activa.
                Filtra los cursos por año y pulsa Gestionar. Suspender el aula conserva la inscripción y el historial. Las nuevas inscripciones requieren validar el patrocinio o pago. La cuenta se crea sola al aprobar su inscripción.
                <?php if ($inscritosSinCuenta): ?><br><span class="acc-explica-dato"><?php echo $inscritosSinCuenta; ?> participante(s) inscritos aún no tienen cuenta de aula.</span><?php endif; ?></div>
        </div>
    <?php endif; ?>

    <nav class="acc-filtro-roles" aria-label="Filtrar por rol">
        <a href="<?php echo $h($url(['rol' => ''])); ?>" class="<?php echo $filtroRol === '' ? 'is-activo' : ''; ?>">Todos <b><?php echo count($enTab); ?></b></a>
        <?php foreach ($roles as $r): if (empty($conteoRol[(string) $r['id']])) continue; ?>
            <a href="<?php echo $h($url(['rol' => (string) $r['id']])); ?>" class="<?php echo $filtroRol === (string) $r['id'] ? 'is-activo' : ''; ?>"><?php echo $h($r['nombre_rol']); ?> <b><?php echo $conteoRol[(string) $r['id']]; ?></b></a>
        <?php endforeach; ?>
        <?php if (!empty($conteoRol['sin'])): ?><a href="<?php echo $h($url(['rol' => 'sin'])); ?>" class="is-alerta<?php echo $filtroRol === 'sin' ? ' is-activo' : ''; ?>">Sin rol en Cursos <b><?php echo $conteoRol['sin']; ?></b></a><?php endif; ?>
    </nav>

    <section class="ing-panel">
        <div class="ing-toolbar">
            <form method="get" class="ing-buscar" role="search">
                <span class="glyphicon glyphicon-search" aria-hidden="true"></span>
                <input type="hidden" name="tab" value="<?php echo $tab === 'equipo' ? '' : $h($tab); ?>">
                <input type="search" name="q" value="<?php echo $h($busqueda); ?>" placeholder="<?php echo $tab === 'estudiantes' ? 'Nombre, correo, ingenio o curso…' : 'Nombre, correo, rol o ingenio…'; ?>" aria-label="Buscar usuario">
                <input type="hidden" name="rol" value="<?php echo $h($filtroRol); ?>">
                <?php if ($veTodo): ?>
                    <select name="ingenio" aria-label="Ingenio" onchange="this.form.submit()">
                        <option value="">Todos los ingenios</option>
                        <?php foreach ($ingenios as $id => $nombre): ?><option value="<?php echo (int) $id; ?>" <?php echo $filtroIngenio === (int) $id ? 'selected' : ''; ?>><?php echo $h($nombre); ?></option><?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <?php if ($tab === 'estudiantes'): ?>
                    <select name="por_pagina" aria-label="Estudiantes por página" onchange="this.form.submit()">
                        <?php foreach ([25, 50, 100] as $cantidad): ?><option value="<?php echo $cantidad; ?>" <?php echo $porPaginaEst === $cantidad ? 'selected' : ''; ?>><?php echo $cantidad; ?> por página</option><?php endforeach; ?>
                    </select>
                    <select name="anio" aria-label="Año de los cursos" onchange="this.form.submit()">
                        <option value="todos" <?php echo $anioVista === 'todos' ? 'selected' : ''; ?>>Todos los años</option>
                        <?php foreach (array_unique(array_merge([(int) date('Y')], $aniosCursos)) as $year): ?><option value="<?php echo $year; ?>" <?php echo $anioVista === (string) $year ? 'selected' : ''; ?>>Cursos <?php echo $year; ?></option><?php endforeach; ?>
                        <option value="sin" <?php echo $anioVista === 'sin' ? 'selected' : ''; ?>>Sin fecha</option>
                    </select>
                <?php endif; ?>
                <button type="submit" class="ing-btn">Buscar</button>
                <?php if ($busqueda !== '' || $filtroRol !== '' || $filtroIngenio): ?><a class="ing-btn is-linea" href="<?php echo $tab === 'estudiantes' ? 'ver_usuarios.php?tab=estudiantes' : 'ver_usuarios.php'; ?>">Limpiar</a><?php endif; ?>
            </form>
            <?php if ($puedeGestionar): ?>
                <details class="cengi-acc acc-dar">
                    <summary class="ing-btn is-linea"><span class="glyphicon glyphicon-log-in"></span> Dar acceso a una cuenta existente</summary>
                    <form method="post" class="cengi-acc-panel acc-dar-panel">
                        <input type="hidden" name="accion" value="dar_acceso"><?php echo $ocultos; ?>
                        <b>Dar acceso a Cursos</b>
                        <small>Para alguien que ya tiene una cuenta. Si no tiene cuenta, usa “Nuevo usuario”.</small>
                        <label>Correo de la cuenta<input type="email" name="correo" required placeholder="persona@cengicana.org"></label>
                        <label>Rol en Cursos<select name="rol_id" required>
                            <?php foreach ($rolesAsignables as $r): ?><option value="<?php echo $r['id']; ?>" <?php echo $tab === 'estudiantes' && cengi_acc_es_estudiante($r['nombre_rol']) ? 'selected' : ''; ?>><?php echo $h($r['nombre_rol']); ?></option><?php endforeach; ?>
                        </select></label>
                        <?php if ($veTodo): ?>
                            <label>Ingenio<select name="ingenio_id"><option value="">Conservar el que tiene</option>
                                <?php foreach ($ingenios as $id => $nombre): ?><option value="<?php echo (int) $id; ?>"><?php echo $h($nombre); ?></option><?php endforeach; ?>
                            </select></label>
                        <?php endif; ?>
                        <button type="submit" class="ing-btn"><span class="glyphicon glyphicon-ok"></span> Dar acceso</button>
                    </form>
                </details>
            <?php endif; ?>
        </div>

        <?php if (!$lista): ?>
            <div class="ing-vacio"><span class="glyphicon glyphicon-<?php echo $tab === 'estudiantes' ? 'education' : 'user'; ?>"></span>
                <h2><?php echo $enTab ? 'Nadie coincide con el filtro' : ($tab === 'estudiantes' ? 'Aún no hay estudiantes con cuenta' : 'Nadie del equipo tiene acceso a Cursos'); ?></h2>
                <p><?php echo $enTab ? '<a href="' . ($tab === 'estudiantes' ? 'ver_usuarios.php?tab=estudiantes' : 'ver_usuarios.php') . '">Quitar filtros</a>' : ($tab === 'estudiantes' ? 'Las cuentas se crean al aprobar las inscripciones.' : 'Crea el primero con “Nuevo usuario”.'); ?></p></div>

        <?php elseif ($tab === 'equipo'): ?>
            <div class="ing-lista">
                <div class="ing-fila acc-fila-equipo ing-cabecera" aria-hidden="true"><span>Persona</span><span>Rol en Cursos</span><span>Cursos que ve</span><span>Acciones</span></div>
                <?php foreach ($lista as $u):
                    [$alcTitulo, $alcDetalle, $alcClase] = $alcance($u);
                    $esSuperU = (int) $u['es_superadmin'] === 1; ?>
                    <article class="ing-fila acc-fila-equipo <?php echo $u['nombre_rol'] !== '' ? 'is-activo' : 'is-sin'; ?>" id="u-<?php echo (int) $u['id']; ?>">
                        <div class="ing-nombre">
                            <span class="ing-avatar" aria-hidden="true"><?php echo $h(cengi_acc_iniciales($u['nombre'])); ?></span>
                            <div><strong><?php echo $h($u['nombre']); ?><?php echo (int) $u['id'] === $miId ? ' <small class="acc-yo">(tú)</small>' : ''; ?></strong>
                                <small><?php echo $h($u['correo']); ?> · <?php echo $h($u['nombre_ingenio'] ?: 'sin ingenio'); ?></small></div>
                        </div>
                        <div class="ing-dato">
                            <?php if ($u['nombre_rol'] !== ''): ?><span class="acc-chip-rol<?php echo $esSuperU ? ' is-super' : ''; ?>"><?php echo $h($u['nombre_rol']); ?></span>
                            <?php else: ?><span class="ing-chip is-amarillo">Sin rol en Cursos</span><?php endif; ?>
                        </div>
                        <div class="ing-dato acc-alcance <?php echo $alcClase; ?>"><b><?php echo $h($alcTitulo); ?></b><small><?php echo $h($alcDetalle); ?></small></div>
                        <div class="ing-acciones"><?php echo $menuAcciones($u); ?></div>
                    </article>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <?php require __DIR__ . '/estudiantes_usuarios_vista.php'; ?>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
