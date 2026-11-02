<?php
declare(strict_types=1);

// Crea y actualiza el esquema que Comités, Solicitudes internas, Laboratorio y la central de
// usuarios definen en PHP. Las peticiones web ya no hacen DDL: el usuario de la aplicación
// (modulos_user) solo tiene permisos de datos y este script corre con el de migraciones
// (modulos_migrador). AUD-2026-001/I-2.
//
// Lo llama deploy/mysql/actualizar-bd.sh, un módulo por proceso (Comités y Solicitudes
// declaran funciones con el mismo nombre):
//   MIGRADOR_PASS=... php deploy/mysql/migrar-esquema.php comites|solicitudes|laboratorio|login
//
// Es idempotente: solo agrega lo que falta. Cursos no pasa por aquí: su esquema está completo en
// cengicursos/migrations/*.sql.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const ESQUEMA_MIGRANDO = true;

$modulo = $argv[1] ?? '';
$usuario = getenv('MIGRADOR_USER') ?: 'modulos_migrador';
$clave = (string) getenv('MIGRADOR_PASS');
if ($clave === '') {
    fwrite(STDERR, "Falta MIGRADOR_PASS (contraseña de modulos_migrador, MIGRADOR_PASSWORD en deploy/env/mysql.env).\n");
    exit(2);
}
putenv("MIGRADOR_USER=$usuario");
$raiz = dirname(__DIR__, 2);

/** Variables de un .env sin cargarlas en el entorno. */
function migrar_leer_env(string $ruta): array
{
    $valores = [];
    foreach (is_file($ruta) ? file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#' || !str_contains($linea, '=')) {
            continue;
        }
        [$clave, $valor] = explode('=', $linea, 2);
        $valores[trim($clave)] = trim($valor, " \t\"'");
    }
    return $valores;
}

switch ($modulo) {
    case 'comites':
        // config/database.php crea la base, las tablas base y los datos del menú con el usuario de migraciones.
        require $raiz . '/comites/config/database.php';
        foreach (['functions', 'domain', 'catalogo', 'directorio', 'agenda', 'informes', 'memorias', 'usuarios'] as $archivo) {
            require_once $raiz . "/comites/includes/$archivo.php";
        }
        agenda_preparar($pdo); // incluye directorio_preparar() y catalogo_preparar()
        inf_preparar($pdo);
        mem_preparar($pdo);
        usuarios_preparar_programas($menuPdo);
        break;

    case 'solicitudes':
        require $raiz . '/sistema_de_solicitudes/config/database.php';
        break;

    case 'laboratorio':
        require $raiz . '/laboratorio/config/env.php'; // carga laboratorio/.env (host y base)
        putenv("DB_USER=$usuario");
        putenv("DB_PASSWORD=$clave");
        foreach (['includes/catalogo_muestras_helper.php', 'includes/catalogo_analisis_helper.php', 'includes/catalogo_campos_muestra_helper.php',
            'includes/solicitud_formulario_helpers.php', 'includes/muestra_identificacion_helper.php', 'includes/identificacion_lote_helper.php',
            'includes/formulario_revision_helper.php', 'includes/workflow_trabajo_helper.php', 'includes/catalogo_control_helper.php',
            'models/informes_model.php', 'includes/recepcion_catalogo_helper.php'] as $archivo) {
            require_once $raiz . '/laboratorio/' . $archivo;
        }
        $pdo = Conexion::conectar();
        // En orden de dependencias: catálogo, recepción, identificación, hojas de trabajo e informes.
        labCatalogoMuestrasAsegurarEsquema($pdo);
        labCatalogoAnalisisAsegurarEsquema($pdo);
        labCamposMuestraAsegurarEsquema($pdo);
        asegurarColumnasFirmasSolicitud($pdo);
        labSolicitudHistorialAsegurarEsquema($pdo);
        labCorrelativoRecepcionEsquema($pdo);
        labMuestraIdentificacionEnsureSchema($pdo);
        labIdentificacionLoteSchema($pdo);
        labFormularioEnsureSchema($pdo);
        wfSchema($pdo);
        labCatalogoControlEsquema($pdo);
        lab_informes_esquema($pdo); // incluye lab_documentos_esquema()
        labRecepcionPrepararCatalogo($pdo);
        break;

    case 'login':
        $env = migrar_leer_env($raiz . '/login/.env');
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env['DB_HOST'] ?? 'mysql', $env['DB_PORT'] ?? '3306', $env['DB_NAME'] ?? 'usuarios_menu'),
            $usuario,
            $clave,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        require $raiz . '/login/config/permisos_roles.php';
        asegurar_tablas_permisos($pdo);
        break;

    default:
        fwrite(STDERR, "Uso: php deploy/mysql/migrar-esquema.php comites|solicitudes|laboratorio|login\n");
        exit(2);
}

echo "$modulo: esquema al día\n";
