<?php
declare(strict_types=1);

// Modulo Inventario de precursores (SICIR): control de inventario de precursores quimicos y
// reactivos de Bodega Central. Router por ?view= (mismo patron que comites/index.php); las
// escrituras llegan por POST con token CSRF y responden con redireccion (POST/Redirect/GET).

require_once __DIR__ . '/../login/config/session.php';
cengi_session_start();

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/dominio.php';
require __DIR__ . '/includes/acciones.php';
require __DIR__ . '/includes/exportar.php';
require __DIR__ . '/includes/vale_pdf.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

if (isset($_GET['logout'])) {
    cengi_session_destroy();
    header('Location: ../login/login.php');
    exit;
}

$user = inv_usuario_actual($menuPdo, $pdo);
if (!$user) {
    header('Location: ../login/login.php');
    exit;
}
if (!inv_tiene_acceso($user)) {
    header('Location: ../login/Menu.php');
    exit;
}

/** URL relativa del modulo: inv_url(['view' => 'vale', 'id' => 3]). */
function inv_url(array $params = []): string
{
    return 'index.php' . ($params ? '?' . http_build_query($params) : '');
}

// Vistas: clave => [titulo del menu, permiso que la habilita, grupo, icono Material Symbols]
$secciones = [
    'dashboard' => ['Tablero', 'inventario.tablero.ver', 'Operación', 'space_dashboard'],
    'vales' => ['Vales de salida', 'inventario.vales.ver', 'Operación', 'receipt_long'],
    'nuevo' => ['Nuevo vale', 'inventario.vales.crear', 'Operación', 'note_add'],
    'entradas' => ['Entradas a bodega', 'inventario.entradas.registrar', 'Operación', 'move_to_inbox'],
    'lotes' => ['Control de lotes (FEFO)', 'inventario.lotes.ver', 'Operación', 'stacks'],
    'salidas' => ['Control de salidas', 'inventario.informes.salidas', 'Informes', 'outbox'],
    'disponibilidad' => ['Disponibilidad de reactivos', 'inventario.informes.disponibilidad', 'Informes', 'donut_large'],
    'cierre' => ['Cierre mensual regulatorio', 'inventario.informes.cierre', 'Informes', 'event_available'],
    'catalogo' => ['Catálogo y matriz', 'inventario.catalogo.gestionar', 'Configuración', 'science'],
    'laboratorios' => ['Laboratorios', 'inventario.laboratorios.gestionar', 'Configuración', 'domain'],
    'usuarios' => ['Usuarios del módulo', 'inventario.usuarios.gestionar', 'Configuración', 'group'],
];
// Vistas que no aparecen en el menu y heredan el permiso de otra.
$permisoExtra = ['vale' => 'inventario.vales.ver'];

$permitidas = array_filter($secciones, static fn($s) => inv_puede($user, $s[1]));
$view = (string) ($_GET['view'] ?? '');
if ($view === 'vale') {
    $view = inv_puede($user, $permisoExtra['vale']) ? 'vale' : '';
}
if ($view === '' || ($view !== 'vale' && !isset($permitidas[$view]))) {
    if ($view !== '' && isset($secciones[$view]) && $_SERVER['REQUEST_METHOD'] === 'GET') {
        inv_flash('error', 'Su usuario no tiene el permiso «' . (permisos_inventario_todos()[$secciones[$view][1]][0] ?? $secciones[$view][1]) . '».');
    }
    $view = $permitidas ? (string) array_key_first($permitidas) : 'sin_permisos';
}

// ---------------------------------------------------------------- Escrituras (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $destino = ['view' => $view];
    try {
        if (!inv_csrf_valido($_POST['csrf'] ?? null)) {
            throw new InvError('El formulario expiró. Recargue la página e intente de nuevo.');
        }
        $resultado = null;
        switch ($accion) {
            case 'vale_crear': $resultado = inv_vale_crear($pdo, $menuPdo, $user, $_POST); break;
            case 'vale_autorizar': $resultado = inv_vale_autorizar($pdo, $user, $_POST); break;
            case 'vale_rechazar': $resultado = inv_vale_cerrar($pdo, $user, $_POST, false); break;
            case 'vale_anular': $resultado = inv_vale_cerrar($pdo, $user, $_POST, true); break;
            case 'vale_despachar': $resultado = inv_vale_despachar($pdo, $user, $_POST); break;
            case 'entrada_registrar': $resultado = inv_entrada_registrar($pdo, $user, $_POST); break;
            case 'entrada_corregir': $resultado = inv_entrada_corregir($pdo, $user, $_POST); break;
            case 'entrada_anular': $resultado = inv_entrada_anular($pdo, $user, $_POST); break;
            case 'producto_guardar': $resultado = inv_producto_guardar($pdo, $user, $_POST); break;
            case 'matriz_alternar': $resultado = inv_matriz_alternar($pdo, $user, $_POST); break;
            case 'lab_guardar': $resultado = inv_lab_guardar($pdo, $menuPdo, $user, $_POST); break;
            case 'lab_baja': $resultado = inv_lab_baja($pdo, $user, $_POST); break;
            case 'lab_reactivar': $resultado = inv_lab_reactivar($pdo, $user, $_POST); break;
            case 'lab_eliminar': $resultado = inv_lab_eliminar($pdo, $user, $_POST); break;
            case 'usuario_rol_guardar': $resultado = inv_usuario_rol_guardar($menuPdo, $user, $_POST); break;
            case 'usuario_lab_guardar': $resultado = inv_usuario_lab_guardar($pdo, $menuPdo, $user, $_POST); break;
            case 'cierre_firmar': $resultado = inv_cierre_firmar($pdo, $user, $_POST); break;
            default: throw new InvError('Acción no reconocida.');
        }
        inv_flash('ok', (string) $resultado['mensaje']);
        parse_str((string) $resultado['ir'], $destino);
        unset($_SESSION['inventario_borrador'], $_SESSION['inventario_entrada_borrador']);
        if ($accion === 'entrada_registrar' && !empty($_POST['otro'])) {
            // "Registrar otro de la misma factura": reabre el formulario con los datos del documento.
            $_SESSION['inventario_entrada_borrador'] = inv_entrada_borrador($_POST, false);
        }
    } catch (InvError $e) {
        inv_flash('error', $e->getMessage());
        if ($accion === 'vale_crear') {
            // Conserva lo digitado del vale para no perderlo.
            $_SESSION['inventario_borrador'] = [
                'laboratorio_id' => (int) ($_POST['laboratorio_id'] ?? 0),
                'proyecto' => (string) ($_POST['proyecto'] ?? ''),
                'jefe_id' => (int) ($_POST['jefe_id'] ?? 0),
                'items' => array_values(array_filter((array) ($_POST['items'] ?? []), 'is_array')),
            ];
            $destino = ['view' => 'nuevo'];
        } elseif ($accion === 'entrada_registrar') {
            $_SESSION['inventario_entrada_borrador'] = inv_entrada_borrador($_POST, true);
            $destino = ['view' => 'entradas'];
        } elseif (in_array($accion, ['vale_autorizar', 'vale_rechazar', 'vale_anular', 'vale_despachar'], true)) {
            $destino = ['view' => 'vale', 'id' => (int) ($_POST['id'] ?? 0)];
        } elseif (isset($_POST['volver_cierre'])) {
            $destino = ['view' => 'cierre', 'anio' => (int) ($_POST['anio'] ?? 0), 'mes' => (int) ($_POST['mes'] ?? 0), 'lab' => (int) ($_POST['laboratorio_id'] ?? 0), 'fisc' => !empty($_POST['solo_fiscalizados']) ? 1 : 0];
        }
    } catch (Throwable $e) {
        error_log('inventario_precursores: ' . $e->getMessage() . ' en ' . $e->getFile() . ':' . $e->getLine());
        inv_flash('error', 'No se pudo completar la operación. Intente de nuevo; si se repite, avise al administrador.');
    }
    header('Location: ' . inv_url($destino));
    exit;
}

// ---------------------------------------------------------------- Lectura (GET)
$archivoVista = __DIR__ . '/vistas/' . $view . '.php';
if (!preg_match('/^[a-z_]+$/', $view) || !is_file($archivoVista)) {
    $view = 'sin_permisos';
    $archivoVista = __DIR__ . '/vistas/sin_permisos.php';
}

ob_start();
require $archivoVista; // Las exportaciones (Excel/PDF) terminan aqui con exit.
$contenido = (string) ob_get_clean();

$pendientes = 0;
if (isset($permitidas['vales'])) {
    $pendientes = count(inv_tareas($pdo, $user));
}
$mensajes = inv_flash_tomar();
require __DIR__ . '/vistas/layout.php';
