<?php
/**
 * nav_badges_helper.php
 *
 * Conexion "de solo lectura" para el sidebar (lab_shell_db()) + conteos
 * reales usados como badges numericos en el menu de navegacion
 * (Planificacion del trabajo, Bandeja del analista, Validacion tecnica).
 * Todo esto es aditivo y a prueba de fallos: si la base de datos no esta
 * disponible o alguna tabla/migracion todavia no existe, los badges
 * simplemente no se muestran (no rompen el render del sidebar).
 */

if (!function_exists('lab_shell_db')) {
    /**
     * Conexion PDO perezosa y memoizada para uso exclusivo del shell
     * (sidebar). No usa la variable global $conexion de conexion.php para
     * no forzar su inclusion completa (con su bloque de ejecucion directa)
     * en paginas que hoy no la requieren, ej. index.php o usuarios.php.
     */
    function lab_shell_db(): ?PDO
    {
        static $pdo = null;
        static $intentado = false;

        if ($intentado) {
            return $pdo;
        }
        $intentado = true;

        try {
            if (!class_exists('Conexion')) {
                require_once __DIR__ . '/../conexion.php';
            }
            $pdo = Conexion::conectar();
        } catch (Throwable $e) {
            $pdo = null;
        }

        return $pdo;
    }
}

if (!function_exists('lab_shell_tabla_existe')) {
    function lab_shell_tabla_existe(PDO $pdo, string $tabla): bool
    {
        static $cache = [];
        if (array_key_exists($tabla, $cache)) {
            return $cache[$tabla];
        }

        try {
            $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$tabla]);
            $cache[$tabla] = (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            $cache[$tabla] = false;
        }

        return $cache[$tabla];
    }
}

if (!function_exists('lab_shell_nav_badges')) {
    /**
     * Conteos reales para los badges numericos del sidebar. Cada clave se
     * calcula de forma independiente y con su propio try/catch: si una
     * consulta falla (ej. tabla `formulario` vacia en un ambiente nuevo,
     * o falta la migracion 002), esa clave simplemente queda en null (sin
     * badge) en vez de romper el resto del menu.
     */
    function lab_shell_nav_badges(): array
    {
        static $badges = null;
        if ($badges !== null) {
            return $badges;
        }

        $badges = [
            'planificacion' => null,
            'bandeja' => null,
            'validacion' => null,
        ];

        $pdo = lab_shell_db();
        if (!$pdo) {
            return $badges;
        }

        // Planificacion del trabajo: analisis (formulario) que todavia no
        // llegaron a "Aprobado" ni "Rechazado" en el tablero Kanban.
        try {
            $stmt = $pdo->query("
                SELECT COUNT(*) FROM formulario f
                LEFT JOIN estado_formulario ef ON ef.id_estado = f.id_estado
                WHERE LOWER(TRIM(COALESCE(ef.nombre, ''))) NOT IN ('aprobado', 'rechazado')
            ");
            $badges['planificacion'] = $stmt ? (int) $stmt->fetchColumn() : null;
        } catch (Throwable $e) {
            $badges['planificacion'] = null;
        }

        // Bandeja del analista: mismo conteo de grupos lote+tipo de
        // muestra pendientes de captura que usa solicitudes_pendientes_tecnico.php.
        try {
            if (!function_exists('lab_bandeja_analista_pendientes')) {
                require_once __DIR__ . '/../models/bandeja_analista_model.php';
            }
            $badges['bandeja'] = count(lab_bandeja_analista_pendientes($pdo));
        } catch (Throwable $e) {
            $badges['bandeja'] = null;
        }

        // Validacion tecnica: analisis (formulario) en un estado de
        // revision pendiente de aprobar/rechazar.
        try {
            $stmt = $pdo->query("
                SELECT COUNT(*) FROM formulario f
                LEFT JOIN estado_formulario ef ON ef.id_estado = f.id_estado
                WHERE LOWER(TRIM(COALESCE(ef.nombre, ''))) LIKE '%revis%'
            ");
            $badges['validacion'] = $stmt ? (int) $stmt->fetchColumn() : null;
        } catch (Throwable $e) {
            $badges['validacion'] = null;
        }

        return $badges;
    }
}
