<?php
declare(strict_types=1);

// Autenticacion, permisos, CSRF y formato del modulo Inventario de precursores.
// Mismo esquema que comites/includes/functions.php: la sesion es la de la plataforma y el rol
// y los permisos del usuario EN ESTE MODULO salen de login/config/roles_modulo.php.

require_once __DIR__ . '/../../login/config/roles_modulo.php';
require_once __DIR__ . '/../../login/config/permisos_inventario.php';

/** Error de validacion o de regla de negocio cuyo mensaje se muestra tal cual al usuario. */
class InvError extends RuntimeException
{
}

function e($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function inv_normalizar(string $valor): string
{
    $valor = trim(mb_strtolower($valor, 'UTF-8'));
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $valor);
    if ($ascii !== false) {
        $valor = $ascii;
    }
    return preg_replace('/[^a-z0-9]+/', ' ', $valor) ?: '';
}

/** id del modulo en usuarios_menu.modulos (0 si aun no se desplego). */
function inv_modulo_id(PDO $menuPdo): int
{
    static $id = null;
    if ($id === null) {
        $id = cengi_rm_modulo_id($menuPdo, INVENTARIO_MODULO);
    }
    return $id;
}

/**
 * Usuario de la sesion con su rol y permisos en este modulo, su laboratorio y su firma
 * guardada. Devuelve [] si no hay sesion.
 */
function inv_usuario_actual(PDO $menuPdo, PDO $pdo): array
{
    $id = (int) ($_SESSION['id_usuario'] ?? 0);
    if ($id <= 0) {
        return [];
    }
    $stmt = $menuPdo->prepare('SELECT u.id, u.nombre, u.correo, u.es_superadmin FROM usuarios u WHERE u.id = ?');
    $stmt->execute([$id]);
    $usuario = $stmt->fetch();
    if (!$usuario) {
        return [];
    }
    $usuario['id'] = (int) $usuario['id'];
    $usuario['es_superadmin'] = (int) $usuario['es_superadmin'];

    $rol = cengi_rm_activar_modulo($menuPdo, INVENTARIO_MODULO);
    $usuario['rol_id'] = (int) ($rol['rol_id'] ?? 0);
    $usuario['nombre_rol'] = (string) ($rol['nombre_rol'] ?? '');
    $usuario['permisos'] = array_values(array_map('strval', $_SESSION['user_permissions'] ?? []));

    $moduloStmt = $menuPdo->prepare('SELECT COUNT(*) FROM usuario_modulo WHERE usuario_id = ? AND modulo_id = ?');
    $moduloStmt->execute([$id, inv_modulo_id($menuPdo)]);
    $usuario['tiene_modulo'] = (int) $moduloStmt->fetchColumn() > 0;

    $lab = $pdo->prepare('SELECT l.id, l.codigo, l.nombre FROM usuario_laboratorio ul JOIN laboratorios l ON l.id = ul.laboratorio_id WHERE ul.usuario_id = ?');
    $lab->execute([$id]);
    $fila = $lab->fetch();
    $usuario['lab_id'] = $fila ? (int) $fila['id'] : 0;
    $usuario['lab_nombre'] = $fila ? (string) $fila['nombre'] : '';

    $firma = $pdo->prepare('SELECT imagen FROM usuario_firma WHERE usuario_id = ?');
    $firma->execute([$id]);
    $usuario['firma_guardada'] = (string) ($firma->fetchColumn() ?: '');

    return $usuario;
}

/** Roles de la central vinculados explícitamente a Inventario de precursores. */
function inv_roles_asignables_modulo(PDO $menuPdo): array
{
    return array_column(array_filter(
        cengi_rm_roles_asignables($menuPdo, inv_modulo_id($menuPdo)),
        static fn(array $rol): bool => (bool) $rol['del_modulo']
    ), null, 'id');
}

function inv_tiene_acceso(array $usuario): bool
{
    return $usuario['es_superadmin'] === 1 || !empty($usuario['tiene_modulo']);
}

function inv_puede(array $usuario, string $permiso): bool
{
    if (($usuario['es_superadmin'] ?? 0) === 1) {
        return true;
    }
    return in_array($permiso, $usuario['permisos'] ?? [], true);
}

/** Laboratorio al que se limita la informacion del usuario: 0 = todos, -1 = sin laboratorio (no ve nada). */
function inv_alcance_lab(array $usuario): int
{
    if (inv_puede($usuario, 'inventario.vales.ver_todos')) {
        return 0;
    }
    return $usuario['lab_id'] > 0 ? $usuario['lab_id'] : -1;
}

// ---------------------------------------------------------------- CSRF y mensajes

function inv_csrf(): string
{
    $_SESSION['inventario_csrf'] ??= bin2hex(random_bytes(32));
    return (string) $_SESSION['inventario_csrf'];
}

function inv_csrf_campo(): string
{
    return '<input type="hidden" name="csrf" value="' . e(inv_csrf()) . '">';
}

function inv_csrf_valido(?string $token): bool
{
    return is_string($token) && $token !== '' && hash_equals(inv_csrf(), $token);
}

function inv_flash(string $tipo, string $mensaje): void
{
    $_SESSION['inventario_flash'][] = ['tipo' => $tipo, 'mensaje' => $mensaje];
}

/** Mensajes pendientes (y los borra). */
function inv_flash_tomar(): array
{
    $mensajes = $_SESSION['inventario_flash'] ?? [];
    unset($_SESSION['inventario_flash']);
    return $mensajes;
}

// ---------------------------------------------------------------- Cantidades (milesimas enteras)

/**
 * Las cantidades se manejan en milesimas enteras (12.5 -> 12500) para no acumular errores de
 * coma flotante al sumar y restar lotes. La base guarda DECIMAL(14,3).
 */
function inv_milli($valor): int
{
    return (int) round(((float) $valor) * 1000);
}

/** Texto del usuario -> milesimas (> 0). Acepta coma o punto y hasta 3 decimales; null si no es valido. */
function inv_parse_cantidad($texto): ?int
{
    $texto = trim((string) $texto);
    if (!preg_match('/^\d{1,10}(?:[.,]\d{1,3})?$/', $texto)) {
        return null;
    }
    $milli = inv_milli(str_replace(',', '.', $texto));
    return $milli > 0 ? $milli : null;
}

/** Texto del usuario -> milesimas, permitiendo 0 (stock minimo). null si no es valido. */
function inv_parse_cantidad_cero($texto): ?int
{
    $texto = trim((string) $texto);
    if ($texto === '') {
        return 0;
    }
    if (!preg_match('/^\d{1,10}(?:[.,]\d{1,3})?$/', $texto)) {
        return null;
    }
    return inv_milli(str_replace(',', '.', $texto));
}

/** Milesimas -> texto para guardar en DECIMAL(14,3). */
function inv_dec(int $milli): string
{
    $milli = max(0, $milli);
    return sprintf('%d.%03d', intdiv($milli, 1000), $milli % 1000);
}

/** Milesimas -> texto legible (12,500 -> "12.5"; miles con coma). */
function inv_fmt(int $milli): string
{
    $texto = number_format($milli / 1000, 3, '.', ',');
    return rtrim(rtrim($texto, '0'), '.');
}

/** Valor para un <input type="number">. */
function inv_input_cantidad(int $milli): string
{
    return rtrim(rtrim(inv_dec($milli), '0'), '.');
}

// ---------------------------------------------------------------- Fechas

function inv_hoy(): string
{
    return date('Y-m-d');
}

/** 'AAAA-MM-DD' -> 'DD/MM/AAAA' (acepta tambien fecha con hora). */
function inv_fecha($fecha): string
{
    $fecha = (string) $fecha;
    if ($fecha === '') {
        return '—';
    }
    $dia = substr($fecha, 0, 10);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dia, $m)) {
        return $fecha;
    }
    return $m[3] . '/' . $m[2] . '/' . $m[1] . (strlen($fecha) > 10 ? ' ' . substr($fecha, 11, 5) : '');
}

/** Valida una fecha 'AAAA-MM-DD' real; devuelve null si no lo es. */
function inv_fecha_valida($texto): ?string
{
    $texto = trim((string) $texto);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $texto, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        return null;
    }
    return $texto;
}

/** Dias de $fecha respecto a $hoy (negativo = ya paso). */
function inv_dias_hasta(string $fecha, ?string $hoy = null): int
{
    $a = new DateTimeImmutable($hoy ?? inv_hoy());
    $b = new DateTimeImmutable($fecha);
    return (int) $a->diff($b)->format('%r%a');
}

const INV_MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

// ---------------------------------------------------------------- Texto de formularios

/** Texto recortado de un campo del formulario, con limite de largo. */
function inv_texto($valor, int $max, bool $obligatorio = false, string $campo = 'El campo'): string
{
    $texto = trim((string) $valor);
    $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $texto) ?? '';
    if ($obligatorio && $texto === '') {
        throw new InvError("{$campo} es obligatorio.");
    }
    if (mb_strlen($texto, 'UTF-8') > $max) {
        throw new InvError("{$campo} no puede superar {$max} caracteres.");
    }
    return $texto;
}

/** Celda de CSV a salvo de inyeccion de formulas al abrirlo en Excel. */
function inv_csv_celda($valor): string
{
    $texto = (string) $valor;
    if ($texto !== '' && strpos("=+-@\t\r", $texto[0]) !== false) {
        $texto = "'" . $texto;
    }
    return '"' . str_replace('"', '""', $texto) . '"';
}

function inv_iniciales(string $nombre): string
{
    $nombre = preg_replace('/^(Ing\.|Lic\.|Dra\.|Dr\.)\s*/u', '', trim($nombre)) ?? '';
    $letras = '';
    foreach (array_slice(preg_split('/\s+/u', $nombre) ?: [], 0, 2) as $palabra) {
        $letras .= mb_strtoupper(mb_substr($palabra, 0, 1, 'UTF-8'), 'UTF-8');
    }
    return $letras !== '' ? $letras : '?';
}

/** Clase de la etiqueta de estado de un vale. */
function inv_pill_vale(string $estado): string
{
    $mapa = [
        'Pendiente' => ['p-warn', 'Pendiente de VoBo'],
        'Autorizado' => ['p-info', 'Autorizado · por despachar'],
        'Entregado' => ['p-ok', 'Entregado'],
        'Rechazado' => ['p-bad', 'Rechazado'],
        'Anulado' => ['p-mut', 'Anulado'],
    ];
    [$clase, $texto] = $mapa[$estado] ?? ['p-mut', $estado];
    return '<span class="pill ' . $clase . '">' . e($texto) . '</span>';
}

function inv_pill_lote(string $estado): string
{
    $clases = ['ACTIVO' => 'p-ok', 'AGOTADO' => 'p-mut', 'VENCIDO' => 'p-bad', 'PRÓXIMO A VENCER' => 'p-warn'];
    return '<span class="pill ' . ($clases[$estado] ?? 'p-mut') . '">' . e($estado) . '</span>';
}

/** Atributo data-firma de un formulario: lista de firmantes [p, titulo, nombre, fijo, campo]. */
function inv_firma_attr(array $firmantes): string
{
    return 'data-firma="' . e(json_encode($firmantes, JSON_UNESCAPED_UNICODE)) . '"';
}

/** Campos ocultos comunes de un formulario POST. */
function inv_form_campos(string $accion): string
{
    return inv_csrf_campo() . '<input type="hidden" name="accion" value="' . e($accion) . '">';
}
