<?php
/**
 * Roles del flujo de Laboratorio: definición única (nombre, descripción, qué notifica la
 * campanita y permisos "laboratorio.*") usada por:
 *   - view/roles_view.php (pantalla "Roles y permisos" del módulo), y
 *   - login/config/roles_laboratorio.php (script de mantenimiento por CLI/navegador).
 * Los roles y sus permisos viven en la base central (roles / permisos / rol_permiso).
 */
require_once __DIR__ . '/../../login/config/permisos_roles.php';

if (!function_exists('lab_roles_definicion')) {
    /**
     * Permisos de cada rol, tomados del catálogo vigente de Cengilabs
     * (login/config/permisos_roles.php::permisos_laboratorio_catalogo()).
     * @param string[] $todosLab todos los permisos vigentes de Cengilabs
     */
    function lab_roles_definicion(array $todosLab): array
    {
        $recepcionista = [
            'laboratorio.solicitudes.ver', 'laboratorio.solicitudes.crear', 'laboratorio.solicitudes.editar',
            'laboratorio.solicitudes.editar.foliar', 'laboratorio.solicitudes.editar.suelo', 'laboratorio.solicitudes.editar.aguas',
            'laboratorio.solicitudes.editar.cana', 'laboratorio.lotes.ver', 'laboratorio.documentos.ver', 'laboratorio.documentos.gestionar',
            'laboratorio.trazabilidad.ver',
        ];
        $analista = [
            'laboratorio.lotes.ver', 'laboratorio.analisis.ver', 'laboratorio.formularios_pendientes.ver',
            'laboratorio.blanco_control.ver', 'laboratorio.kanban.ver', 'laboratorio.trazabilidad.ver',
        ];
        $tecnico = array_values(array_unique(array_merge($analista, [
            'laboratorio.solicitudes.ver', 'laboratorio.blanco_control.gestionar', 'laboratorio.validacion_tecnica.ver',
            'laboratorio.validacion_tecnica.aprobar', 'laboratorio.consolidacion.ver', 'laboratorio.consolidacion.aprobar',
            'laboratorio.formularios_erroneos.ver', 'laboratorio.formularios.guardar_corregidos', 'laboratorio.formularios.guardar_errores',
            'laboratorio.informes.ver', 'laboratorio.documentos.ver', 'laboratorio.catalogo_analisis.ver', 'laboratorio.catalogo_muestras.ver',
        ])));
        $jefe = array_values(array_diff($todosLab, ['laboratorio.usuarios.gestionar', 'laboratorio.roles.gestionar']));

        return [
            'Superadmin' => [
                'descripcion' => 'Acceso total al módulo, incluida la administración de usuarios y roles.',
                'notifica' => 'Todo lo que notifican los demás roles.',
                'permisos' => $todosLab,
            ],
            'Jefe de laboratorio' => [
                'descripcion' => 'Dirige el flujo: planifica y asigna analistas, valida resultados, aprueba y firma los informes, y mantiene el catálogo de análisis.',
                'notifica' => 'Análisis sin analista asignado, análisis por validar, informes por aprobar y firmar, reportes por enviar.',
                'permisos' => $jefe,
            ],
            'Técnico' => [
                'descripcion' => 'Técnico revisor: valida o rechaza los análisis capturados, lleva el control de calidad (blancos y controles) y revisa los informes antes de la firma. También puede capturar.',
                'notifica' => 'Análisis por validar, informes devueltos por el jefe, análisis asignados por capturar.',
                'permisos' => $tecnico,
            ],
            'Analista' => [
                'descripcion' => 'Captura en la Bandeja del analista los análisis que le asignan; consulta la planificación, la identificación de muestras y el historial.',
                'notifica' => 'Análisis asignados por capturar y análisis devueltos para repetir.',
                'permisos' => $analista,
            ],
            'Recepcionista' => [
                'descripcion' => 'Registra y edita las recepciones de muestras, identifica e imprime etiquetas, y envía al cliente los reportes firmados.',
                'notifica' => 'Reportes firmados que aún no se han enviado al cliente.',
                'permisos' => $recepcionista,
            ],
        ];
    }

    /** Filas de la matriz "qué puede hacer cada rol": [sección, acción, permiso que la habilita]. */
    function lab_roles_matriz(): array
    {
        return [
            ['Recepción', 'Registrar recepciones de muestras', 'laboratorio.solicitudes.crear'],
            ['Recepción', 'Editar recepciones registradas', 'laboratorio.solicitudes.editar'],
            ['Recepción', 'Identificación de muestras y etiquetas', 'laboratorio.lotes.ver'],
            ['Planificación', 'Ver la planificación del trabajo', 'laboratorio.kanban.ver'],
            ['Planificación', 'Asignar analistas y prioridad a los lotes', 'laboratorio.kanban.gestionar'],
            ['Análisis', 'Capturar en la Bandeja del analista', 'laboratorio.analisis.ver'],
            ['Validación', 'Ver validación técnica', 'laboratorio.validacion_tecnica.ver'],
            ['Validación', 'Aprobar o rechazar análisis', 'laboratorio.validacion_tecnica.aprobar'],
            ['Validación', 'Control de calidad (indicadores ISO 17025)', 'laboratorio.consolidacion.ver'],
            ['Entrega', 'Ver informes por lote', 'laboratorio.informes.ver'],
            ['Entrega', 'Aprobar y firmar informes', 'laboratorio.firma.gestionar'],
            ['Entrega', 'Reportes: ver, descargar y enviar al cliente', 'laboratorio.documentos.ver'],
            ['Entrega', 'Historial de versiones', 'laboratorio.trazabilidad.ver'],
            ['Sistema', 'Catálogo de análisis y de muestras', 'laboratorio.catalogo_analisis.ver'],
            ['Sistema', 'Editar catálogo de análisis', 'laboratorio.analisis.editar'],
            ['Sistema', 'Asignar usuarios y roles del laboratorio', 'laboratorio.usuarios.gestionar'],
        ];
    }

    /** Permisos vigentes de Cengilabs (los obsoletos ya no se asignan). */
    function lab_roles_permisos_lab(?PDO $central = null): array
    {
        return array_keys(permisos_laboratorio_vigentes());
    }

    /** Estado real de cada rol en la base central: id, usuarios y permisos de laboratorio asignados. */
    function lab_roles_estado(PDO $central): array
    {
        $estado = [];
        $stmt = $central->prepare("
            SELECT r.id,
                   (SELECT COUNT(*) FROM usuario_modulo um INNER JOIN modulos m ON m.id = um.modulo_id AND LOWER(m.nombre) = 'laboratorio'
                      WHERE um.rol_id = r.id) AS usuarios,
                   (SELECT GROUP_CONCAT(p.nombre_permiso SEPARATOR ',') FROM rol_permiso rp
                      INNER JOIN permisos p ON p.id = rp.permiso_id
                      WHERE rp.rol_id = r.id AND p.nombre_permiso LIKE 'laboratorio.%') AS permisos
            FROM roles r WHERE r.nombre_rol = ? LIMIT 1
        ");
        foreach (array_keys(lab_roles_definicion([])) as $nombre) {
            $stmt->execute([$nombre]);
            $fila = $stmt->fetch(PDO::FETCH_ASSOC);
            $estado[$nombre] = $fila ? [
                'id' => (int) $fila['id'],
                'usuarios' => (int) $fila['usuarios'],
                'permisos' => array_values(array_filter(explode(',', (string) $fila['permisos']))),
            ] : null;
        }
        return $estado;
    }

    /**
     * Crea los roles que falten y les asigna sus permisos (INSERT IGNORE). Con $sincronizar
     * también quita los permisos "laboratorio.*" que no estén en la definición. Da acceso al
     * módulo Laboratorio a los usuarios con estos roles. Devuelve un resumen por rol.
     */
    function lab_roles_aplicar(PDO $central, bool $sincronizar = false): array
    {
        asegurar_tablas_permisos($central);
        $roles = lab_roles_definicion(lab_roles_permisos_lab($central));

        $crearRol = $central->prepare('INSERT IGNORE INTO roles (nombre_rol) VALUES (?)');
        $buscarRol = $central->prepare('SELECT id FROM roles WHERE nombre_rol = ?');
        $asignar = $central->prepare('INSERT IGNORE INTO rol_permiso (rol_id, permiso_id) SELECT ?, id FROM permisos WHERE nombre_permiso = ?');
        $moduloLab = $central->query("SELECT id FROM modulos WHERE LOWER(nombre) = 'laboratorio' LIMIT 1")->fetchColumn();
        // Acceso al laboratorio con su rol (rol por modulo) para quienes tienen ese rol de plataforma;
        // si ya tenian un rol en el laboratorio se respeta.
        $darModulo = $central->prepare('INSERT INTO usuario_modulo (usuario_id, modulo_id, rol_id) SELECT id, ?, rol_id FROM usuarios WHERE rol_id = ?
            ON DUPLICATE KEY UPDATE rol_id = COALESCE(usuario_modulo.rol_id, VALUES(rol_id))');
        // Los roles del laboratorio (salvo Superadmin, que es generico) quedan como roles de este modulo.
        $rolDelModulo = $central->prepare('INSERT IGNORE INTO rol_modulo (rol_id, modulo_id) VALUES (?, ?)');

        $resumen = [];
        foreach ($roles as $nombre => $rol) {
            $crearRol->execute([$nombre]);
            $creado = $crearRol->rowCount() > 0;
            $buscarRol->execute([$nombre]);
            $rolId = (int) $buscarRol->fetchColumn();

            $nuevos = 0;
            foreach ($rol['permisos'] as $permiso) {
                $asignar->execute([$rolId, $permiso]);
                $nuevos += $asignar->rowCount();
            }

            $quitados = 0;
            if ($sincronizar && $rol['permisos']) {
                $marcadores = implode(',', array_fill(0, count($rol['permisos']), '?'));
                $quitar = $central->prepare("
                    DELETE rp FROM rol_permiso rp INNER JOIN permisos p ON p.id = rp.permiso_id
                    WHERE rp.rol_id = ? AND p.nombre_permiso LIKE 'laboratorio.%' AND p.nombre_permiso NOT IN ($marcadores)
                ");
                $quitar->execute(array_merge([$rolId], $rol['permisos']));
                $quitados = $quitar->rowCount();
            }

            $usuarios = 0;
            if ($moduloLab) {
                if (mb_strtolower($nombre, 'UTF-8') !== 'superadmin') {
                    $rolDelModulo->execute([$rolId, (int) $moduloLab]);
                }
                $darModulo->execute([(int) $moduloLab, $rolId]);
                $usuarios = $darModulo->rowCount();
            }

            $resumen[$nombre] = ['id' => $rolId, 'creado' => $creado, 'definidos' => count($rol['permisos']),
                'nuevos' => $nuevos, 'quitados' => $quitados, 'usuarios_modulo' => $usuarios];
        }
        return ['roles' => $resumen, 'modulo_laboratorio' => (bool) $moduloLab];
    }
}

if (!function_exists('lab_usuarios_laboratorio')) {
    /** id del módulo "Laboratorio" en la base central (lo crea si se pide y no existe). */
    function lab_modulo_laboratorio_id(PDO $central, bool $crear = false): int
    {
        $id = (int) $central->query("SELECT id FROM modulos WHERE LOWER(nombre) = 'laboratorio' LIMIT 1")->fetchColumn();
        if (!$id && $crear) {
            $central->prepare('INSERT IGNORE INTO modulos (nombre) VALUES (?)')->execute(['Laboratorio']);
            $id = (int) $central->query("SELECT id FROM modulos WHERE LOWER(nombre) = 'laboratorio' LIMIT 1")->fetchColumn();
        }
        return $id;
    }

    /** Roles que se pueden asignar desde Laboratorio: [id => nombre] (Superadmin solo para un superadmin). */
    function lab_roles_asignables(PDO $central, bool $actorSuperadmin): array
    {
        $roles = [];
        $stmt = $central->prepare('SELECT id, nombre_rol FROM roles WHERE nombre_rol = ?');
        foreach (array_keys(lab_roles_definicion([])) as $nombre) {
            if ($nombre === 'Superadmin' && !$actorSuperadmin) continue;
            $stmt->execute([$nombre]);
            if ($fila = $stmt->fetch(PDO::FETCH_ASSOC)) $roles[(int) $fila['id']] = (string) $fila['nombre_rol'];
        }
        return $roles;
    }

    /** Usuarios con acceso al laboratorio y, aparte, los que aún no lo tienen. */
    function lab_usuarios_laboratorio(PDO $central): array
    {
        $modulo = lab_modulo_laboratorio_id($central);
        // Rol de cada usuario EN EL LABORATORIO (usuario_modulo.rol_id); el superadmin conserva el suyo.
        $stmtFilas = $central->prepare('
            SELECT u.id, u.nombre, u.correo, COALESCE(u.es_superadmin, 0) AS es_superadmin,
                   CASE WHEN u.es_superadmin = 1 THEN u.rol_id ELSE um.rol_id END AS rol_id,
                   COALESCE(r.nombre_rol, \'\') AS rol
            FROM usuarios u
            LEFT JOIN usuario_modulo um ON um.usuario_id = u.id AND um.modulo_id = ?
            LEFT JOIN roles r ON r.id = CASE WHEN u.es_superadmin = 1 THEN u.rol_id ELSE um.rol_id END
            ORDER BY u.nombre
        ');
        $stmtFilas->execute([(int) $modulo]);
        $filas = $stmtFilas->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $conAcceso = [];
        if ($modulo) {
            $stmt = $central->prepare('SELECT usuario_id FROM usuario_modulo WHERE modulo_id = ?');
            $stmt->execute([$modulo]);
            $conAcceso = array_flip(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }
        $con = $sin = [];
        foreach ($filas as $f) {
            $f['id'] = (int) $f['id'];
            $f['rol_id'] = (int) $f['rol_id'];
            $f['es_superadmin'] = (int) $f['es_superadmin'] === 1;
            if (isset($conAcceso[$f['id']]) || $f['es_superadmin']) $con[] = $f; else $sin[] = $f;
        }
        return ['con_acceso' => $con, 'sin_acceso' => $sin, 'modulo' => $modulo];
    }

    /**
     * Asigna rol y/o acceso al laboratorio a un usuario. $accion: 'rol' (cambiar rol),
     * 'agregar' (dar acceso + rol) o 'quitar' (quitar acceso al módulo; el usuario no se borra).
     * Reglas: nadie se modifica a sí mismo; solo un superadmin modifica a otro superadmin o asigna
     * el rol Superadmin; solo se asignan roles del laboratorio. Devuelve un mensaje de resultado.
     */
    function lab_usuario_asignar(PDO $central, int $actorId, bool $actorSuperadmin, int $usuarioId, string $accion, int $rolId = 0): string
    {
        if (!in_array($accion, ['rol', 'agregar', 'quitar'], true)) throw new InvalidArgumentException('Acción no válida.');
        if ($usuarioId <= 0) throw new InvalidArgumentException('Seleccione un usuario.');
        if ($usuarioId === $actorId) throw new InvalidArgumentException('No puede cambiar su propio rol ni su acceso; pídaselo a otro administrador.');

        $stmt = $central->prepare('SELECT u.id, u.nombre, COALESCE(u.es_superadmin, 0) AS es_superadmin, r.nombre_rol FROM usuarios u LEFT JOIN roles r ON r.id = u.rol_id WHERE u.id = ?');
        $stmt->execute([$usuarioId]); // r.nombre_rol: solo para reconocer a un Superadmin
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$usuario) throw new InvalidArgumentException('El usuario ya no existe.');
        $esSuper = (int) $usuario['es_superadmin'] === 1 || mb_strtolower((string) $usuario['nombre_rol'], 'UTF-8') === 'superadmin';
        if ($esSuper && !$actorSuperadmin) throw new InvalidArgumentException('Solo un superadmin puede modificar a otro superadmin.');

        $asignables = lab_roles_asignables($central, $actorSuperadmin);
        if ($accion !== 'quitar' && !isset($asignables[$rolId])) throw new InvalidArgumentException('Seleccione un rol del laboratorio válido.');

        $central->beginTransaction();
        try {
            if ($accion === 'quitar') {
                $modulo = lab_modulo_laboratorio_id($central);
                if ($modulo) $central->prepare('DELETE FROM usuario_modulo WHERE usuario_id = ? AND modulo_id = ?')->execute([$usuarioId, $modulo]);
                $mensaje = $usuario['nombre'] . ' ya no tiene acceso al laboratorio.';
            } else {
                // Rol solo en el laboratorio (login/config/roles_modulo.php): su rol en Cursos,
                // Comites, etc. y su rol de plataforma no cambian.
                require_once __DIR__ . '/../../login/config/roles_modulo.php';
                $modulo = lab_modulo_laboratorio_id($central, true);
                cengi_rm_asignar($central, $usuarioId, $modulo, $rolId);
                $mensaje = $usuario['nombre'] . ($accion === 'agregar' ? ' ahora tiene acceso al laboratorio como ' : ' ahora tiene el rol ') . $asignables[$rolId] . '.';
            }
            $central->commit();
        } catch (Throwable $e) {
            $central->rollBack();
            throw $e;
        }
        return $mensaje . ' El cambio se aplica cuando el usuario vuelva a iniciar sesión.';
    }
}

if (!function_exists('lab_roles_db_central')) {
    /** Conexión a la base central de usuarios (mismas credenciales que login/). */
    function lab_roles_db_central(): PDO
    {
        $env = parse_ini_file(__DIR__ . '/../../login/.env', false, INI_SCANNER_RAW);
        if (!$env) {
            throw new RuntimeException('No se pudo leer la configuración de la base central de usuarios.');
        }
        return new PDO(
            'mysql:host=' . $env['DB_HOST'] . ';port=' . ($env['DB_PORT'] ?? 3306) . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4',
            $env['DB_USER'], $env['DB_PASS'] ?? $env['DB_PASSWORD'] ?? '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}
