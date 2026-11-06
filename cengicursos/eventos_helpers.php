<?php

/**
 * Funciones compartidas por la administracion y el formulario publico de eventos.
 */

// Numero de contacto para solicitar el numero de cuenta / realizar el pago de un
// evento de modalidad_pago = 'Pagado', mostrado en el panel lateral del formulario
// publico de inscripcion (cengicursos/inscripcion_evento.php). Placeholder: actualizar
// con el numero real cuando el comite lo confirme.
if (!defined('CENGI_EVENTO_TELEFONO_PAGO')) {
    define('CENGI_EVENTO_TELEFONO_PAGO', 'XXXXXXXX');
}

// Soporte de pagos mostrado en el recuadro "¿A qué cuenta deposito?" del formulario
// publico de inscripcion a eventos pagados (cengicursos/inscripcion_evento.php).
if (!defined('CENGI_EVENTO_SOPORTE_PAGO_NOMBRE')) {
    define('CENGI_EVENTO_SOPORTE_PAGO_NOMBRE', 'Soporte de pagos — Administración CENGICAÑA');
}
if (!defined('CENGI_EVENTO_SOPORTE_PAGO_HORARIO')) {
    define('CENGI_EVENTO_SOPORTE_PAGO_HORARIO', 'Lunes a viernes, 8:00 a 16:30');
}
if (!defined('CENGI_EVENTO_CORREO_PAGO')) {
    define('CENGI_EVENTO_CORREO_PAGO', 'aadmon@cengicana.org');
}

/**
 * Contacto de soporte de pagos (formulario publico, recordatorios de pago y gafetes):
 * lo edita Administracion desde Control QR de eventos (tabla eventos_config); si aun no
 * se configuro se usan las constantes anteriores.
 */
function cengi_evento_soporte_pagos(PDO $db)
{
    $soporte = [
        'nombre' => CENGI_EVENTO_SOPORTE_PAGO_NOMBRE,
        'whatsapp' => preg_match('/\d{8}/', preg_replace('/\D/', '', CENGI_EVENTO_TELEFONO_PAGO)) ? CENGI_EVENTO_TELEFONO_PAGO : '',
        'correo' => CENGI_EVENTO_CORREO_PAGO,
        'horario' => CENGI_EVENTO_SOPORTE_PAGO_HORARIO,
    ];
    try {
        $guardado = json_decode((string) $db->query("SELECT valor FROM eventos_config WHERE clave = 'soporte_pagos'")->fetchColumn(), true);
    } catch (PDOException $e) {
        $guardado = null;
    }
    if (is_array($guardado)) {
        foreach ($soporte as $k => $v) {
            if (isset($guardado[$k]) && is_string($guardado[$k])) {
                $soporte[$k] = $guardado[$k];
            }
        }
    }
    return $soporte;
}

/**
 * Imagen o logo del evento ("Editar evento"): llega como data URL ya reducida en el
 * navegador; se valida el contenido real (finfo) y se guarda en uploads/eventos/.
 * Devuelve la ruta /uploads/... o lanza InvalidArgumentException.
 */
function cengi_evento_guardar_imagen($dataUrl, $prefijo)
{
    if (!preg_match('#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$#', (string) $dataUrl, $m)) {
        throw new InvalidArgumentException('La imagen del evento no es válida (usa PNG, JPG o WebP).');
    }
    $bytes = base64_decode($m[2], true);
    if ($bytes === false || strlen($bytes) > 3 * 1024 * 1024) {
        throw new InvalidArgumentException('La imagen del evento supera 3 MB.');
    }
    $tipos = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    if (!isset($tipos[$mime])) {
        throw new InvalidArgumentException('La imagen del evento no es válida (usa PNG, JPG o WebP).');
    }
    // No es un archivo subido (llega en el formulario como texto), asi que se escribe
    // directamente en uploads/eventos/ en vez de usar cengi_guardar_archivo_subido().
    $directorio = __DIR__ . '/../uploads/eventos';
    if (!is_dir($directorio) && !@mkdir($directorio, 0775, true) && !is_dir($directorio)) {
        throw new RuntimeException('No se pudo crear uploads/eventos.');
    }
    $nombre = $prefijo . '-' . bin2hex(random_bytes(12)) . '.' . $tipos[$mime];
    if (@file_put_contents($directorio . '/' . $nombre, $bytes) === false) {
        throw new RuntimeException('No se pudo escribir en uploads/eventos (revisar permisos del volumen).');
    }
    return '/uploads/eventos/' . $nombre;
}

/** Ruta absoluta de una imagen de uploads/eventos/ (null si no existe o esta fuera de la carpeta). */
function cengi_evento_imagen_archivo($ruta)
{
    $url = cengi_normalizar_url_archivo($ruta);
    $base = realpath(__DIR__ . '/../uploads/eventos');
    $archivo = $url !== '' ? realpath(__DIR__ . '/..' . $url) : false;
    return $base && $archivo && strpos($archivo, $base . DIRECTORY_SEPARATOR) === 0 && is_file($archivo) ? $archivo : null;
}

/** Imagen del evento como data URI (gafete PDF y diseño de constancias). */
function cengi_evento_imagen_data_uri($ruta)
{
    $archivo = cengi_evento_imagen_archivo($ruta);
    if (!$archivo) {
        return null;
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo);
    return in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) ? 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($archivo)) : null;
}

/** Borra una imagen anterior del evento (solo dentro de uploads/eventos/). */
function cengi_evento_borrar_imagen($ruta)
{
    $archivo = cengi_evento_imagen_archivo($ruta);
    if ($archivo) {
        @unlink($archivo);
    }
}

function cengi_evento_generar_codigo_qr(PDO $db)
{
    do {
        $codigo = 'EVT-' . date('Y') . '-' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $stmt = $db->prepare('SELECT COUNT(*) FROM evento_participantes WHERE codigo_qr = ?');
        $stmt->execute([$codigo]);
    } while ((int) $stmt->fetchColumn() > 0);

    return $codigo;
}

function cengi_evento_modalidad_pago($valor)
{
    return strcasecmp(trim((string) $valor), 'Pagado') === 0 ? 'Pagado' : 'Gratuito';
}

function cengi_evento_asegurar_token_inscripcion(PDO $db, $eventoId)
{
    $stmt = $db->prepare('SELECT token_inscripcion FROM eventos WHERE id = ?');
    $stmt->execute([(int) $eventoId]);
    $token = $stmt->fetchColumn();

    if (is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token)) {
        return $token;
    }

    for ($intento = 0; $intento < 5; $intento++) {
        $token = bin2hex(random_bytes(16));
        try {
            $actualizar = $db->prepare('UPDATE eventos SET token_inscripcion = ? WHERE id = ? AND token_inscripcion IS NULL');
            $actualizar->execute([$token, (int) $eventoId]);

            $stmt->execute([(int) $eventoId]);
            $guardado = $stmt->fetchColumn();
            if (is_string($guardado) && $guardado !== '') {
                return $guardado;
            }
        } catch (PDOException $e) {
            // Una colision del indice UNIQUE es extremadamente improbable;
            // si ocurre, se genera otro token en la siguiente iteracion.
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    throw new RuntimeException('No fue posible generar el enlace de inscripcion.');
}

/* ==================== Editar evento, colaboracion, difusion y encuestas ==================== */

// Preguntas de la encuesta de colaboracion (empresas aliadas), mismas del prototipo SIGEC v29.
// La clave es tambien el nombre de la columna en evento_encuesta_colab; 'na' admite "No aplica".
const CENGI_EVT_COLAB_ITEMS = [
    ['k' => 'coord', 'g' => 'Coordinación', 't' => 'Claridad en la coordinación con el Centro', 'na' => false],
    ['k' => 'cumpl', 'g' => 'Coordinación', 't' => 'Cumplimiento de lo acordado por el Centro', 'na' => false],
    ['k' => 'difus', 'g' => 'Aporte del Centro', 't' => 'Difusión e invitación a la red de contactos', 'na' => false],
    ['k' => 'organ', 'g' => 'Aporte del Centro', 't' => 'Apoyo en la organización (agenda y dinámica)', 'na' => false],
    ['k' => 'audit', 'g' => 'Aporte del Centro', 't' => 'Uso y condiciones del auditorio', 'na' => true],
    ['k' => 'valor', 'g' => 'Valor percibido', 't' => 'Valor agregado de la participación del Centro', 'na' => false],
    ['k' => 'util', 'g' => 'Valor percibido', 't' => 'Utilidad del evento para su empresa', 'na' => false],
];
const CENGI_EVT_COLAB_GRUPOS = ['Coordinación', 'Aporte del Centro', 'Valor percibido'];

const CENGI_EVT_COLAB_MODALIDADES = [
    1 => 'Invitación abierta a la red de contactos',
    2 => 'Evento organizado con colaboración de CENGICAÑA',
    3 => 'Taller o curso con apoyo integral',
];
const CENGI_EVT_COLAB_FINANCIA = [
    'empresa' => 'La empresa aliada (gratis para participantes)',
    'compartido' => 'Compartido (participantes pagan una cuota)',
    'centro' => 'CENGICAÑA (alianza estratégica)',
];

const CENGI_EVT_DIF_CANALES = [
    'redes' => 'Redes sociales',
    'whatsapp' => 'Comunidad de WhatsApp',
    'correo' => 'Correo electrónico',
    'web' => 'Sitio web / landing page',
    'impreso' => 'Material impreso',
    'aliado' => 'Canales de la empresa aliada',
    'otro' => 'Otro',
];

const CENGI_EVT_TIPOS = ['Capacitación', 'Evento técnico', 'Seminario', 'Taller', 'Feria', 'Congreso', 'Día de campo'];
const CENGI_EVT_ESTADOS = ['Planificado', 'En curso', 'Finalizado', 'Cancelado'];

/* Formato de la encuesta de percepcion. Los de transferencia piden datos de quien responde
   (para registrar su asistencia) y preguntas propias del tipo de evento. */
const CENGI_EVT_ENCUESTA_FORMATOS = [
    'general' => 'General: calificación y comentario (anónima)',
    'transferencia' => 'Transferencia: tercios de zafra, simposio y similares',
    'investigacion' => 'Presentación y avances de resultados de investigación',
];
const CENGI_EVT_PERMANENCIA = ['completo' => 'Todo el evento', 'mitad' => 'Más de la mitad', 'parcial' => 'Menos de la mitad'];

/* ---------- datos escritos por el participante: institucion, nombre y cargo ---------- */

// Palabras que no distinguen a un ingenio ("Ingenio Trinidad" = "trinidad" = "Ing. Trinidad, S.A.").
const CENGI_INGENIO_PALABRAS_VACIAS = ['ingenio', 'ingenios', 'ing', 'corporacion', 'corp', 'sociedad', 'anonima', 's', 'a', 'sa', 'de', 'del', 'la', 'el', 'los', 'las'];
// Quien no trabaja en una institucion escribe cualquiera de estas: se guarda "Particular".
const CENGI_INSTITUCION_PARTICULAR = ['particular', 'ninguna', 'ninguno', 'independiente', 'personal', 'no aplica', 'na', 'n a', 'n/a', 'n', 'ninguna institucion'];

/** Clave de comparacion de un nombre de institucion: sin tildes, mayusculas, puntuacion ni palabras vacias. */
function cengi_ingenio_clave($texto)
{
    $t = strtr(mb_strtolower(trim((string) $texto), 'UTF-8'), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $palabras = preg_split('/[^a-z0-9]+/', $t, -1, PREG_SPLIT_NO_EMPTY);
    return implode(' ', array_diff($palabras, CENGI_INGENIO_PALABRAS_VACIAS));
}

/**
 * Institucion escrita libremente -> ingenio del catalogo cuando corresponde: "trinidad",
 * "Ingenio Trinidad", "ING. TRINIDAD S.A." o una abreviatura inequivoca ("cengi" -> Cengicana).
 * Devuelve ['ingenio_id' => int|null, 'institucion' => texto a guardar].
 */
function cengi_institucion_normalizar(PDO $db, $texto)
{
    static $catalogo = null;
    if ($catalogo === null) {
        $catalogo = [];
        foreach ($db->query('SELECT id, nombre_ingenios FROM ingenios')->fetchAll(PDO::FETCH_ASSOC) as $i) {
            $clave = cengi_ingenio_clave($i['nombre_ingenios']);
            if ($clave !== '') {
                $catalogo[$clave] = ['id' => (int) $i['id'], 'nombre' => $i['nombre_ingenios']];
            }
        }
    }
    $texto = trim(preg_replace('/\s+/u', ' ', (string) $texto));
    $clave = cengi_ingenio_clave($texto);
    if ($clave === '' || in_array($clave, CENGI_INSTITUCION_PARTICULAR, true) || in_array(mb_strtolower($texto, 'UTF-8'), CENGI_INSTITUCION_PARTICULAR, true)) {
        return ['ingenio_id' => null, 'institucion' => $texto === '' ? '' : 'Particular'];
    }
    if (isset($catalogo[$clave])) {
        return ['ingenio_id' => $catalogo[$clave]['id'], 'institucion' => $catalogo[$clave]['nombre']];
    }
    // Abreviatura o nombre con agregados ("Magdalena Escuintla"): solo si coincide con un unico ingenio.
    $candidatos = array_filter($catalogo, static function ($ingenio, $c) use ($clave) {
        return strlen($c) >= 4 && strlen($clave) >= 4 && (strpos($c, $clave) === 0 || strpos($clave, $c . ' ') === 0);
    }, ARRAY_FILTER_USE_BOTH);
    if (count($candidatos) === 1) {
        $ingenio = reset($candidatos);
        return ['ingenio_id' => $ingenio['id'], 'institucion' => $ingenio['nombre']];
    }
    return ['ingenio_id' => null, 'institucion' => preg_replace('/\s(Sa|S\.a\.?)$/u', ' S.A.', cengi_texto_propio($texto))];
}

/** "JUAN PEREZ" o "juan perez" -> "Juan Perez"; lo escrito con mayusculas y minusculas se respeta. */
function cengi_texto_propio($texto)
{
    $texto = trim(preg_replace('/\s+/u', ' ', (string) $texto));
    if ($texto === '' || ($texto !== mb_strtoupper($texto, 'UTF-8') && $texto !== mb_strtolower($texto, 'UTF-8'))) {
        return $texto;
    }
    $propio = mb_convert_case(mb_strtolower($texto, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    return preg_replace_callback('/(?<=\s)(De|Del|La|Las|Los|Y|E|En)(?=\s)/u', static function ($m) { return mb_strtolower($m[1], 'UTF-8'); }, $propio);
}

/** Cargo en mayusculas o minusculas -> "Jefe de campo" (solo la primera letra en mayuscula). */
function cengi_texto_cargo($texto)
{
    $texto = trim(preg_replace('/\s+/u', ' ', (string) $texto));
    if ($texto === '' || ($texto !== mb_strtoupper($texto, 'UTF-8') && $texto !== mb_strtolower($texto, 'UTF-8'))) {
        return $texto;
    }
    $texto = mb_strtolower($texto, 'UTF-8');
    return mb_strtoupper(mb_substr($texto, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($texto, 1, null, 'UTF-8');
}

/* Datos ya registrados de un correo: inscritos del evento, catalogo de participantes y
   directorio de Comites (lectura directa). Null si no se conoce. */
function cengi_evento_buscar_por_correo(PDO $db, $eventoId, $correo)
{
    $correo = strtolower(trim((string) $correo));
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        return null;
    }
    $stmt = $db->prepare("SELECT ep.participante_id, COALESCE(NULLIF(p.nombre_participantes, ''), ep.nombre_invitado) AS nombre,
            COALESCE(ip.nombre_ingenios, ie.nombre_ingenios, NULLIF(ep.institucion_invitado, ''), '') AS institucion
        FROM evento_participantes ep LEFT JOIN participantes p ON p.id = ep.participante_id
        LEFT JOIN ingenios ip ON ip.id = p.ingenio_id LEFT JOIN ingenios ie ON ie.id = ep.ingenio_id
        WHERE ep.evento_id = ? AND LOWER(TRIM(COALESCE(NULLIF(p.correo_participantes, ''), ep.correo_invitado, ''))) = ? LIMIT 1");
    $stmt->execute([(int) $eventoId, $correo]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fila) {
        $stmt = $db->prepare("SELECT p.id AS participante_id, p.nombre_participantes AS nombre, COALESCE(i.nombre_ingenios, '') AS institucion
            FROM participantes p LEFT JOIN ingenios i ON i.id = p.ingenio_id
            WHERE LOWER(TRIM(p.correo_participantes)) = ? AND p.nombre_participantes <> '' ORDER BY p.id DESC LIMIT 1");
        $stmt->execute([$correo]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$fila) {
        $env = cengicursos_env();
        $base = str_replace('`', '``', $env['DB_COMITES_NAME'] ?? $env['COMITES_DB_NAME'] ?? 'sistema_comites');
        try {
            $stmt = $db->prepare("SELECT NULL AS participante_id, nombre, COALESCE(empresa, '') AS institucion FROM `{$base}`.contactos
                WHERE LOWER(TRIM(email)) = ? AND nombre <> '' LIMIT 1");
            $stmt->execute([$correo]);
            $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Directorio de Comites desde la encuesta: ' . $e->getMessage());
        }
    }
    return $fila ? ['participante_id' => $fila['participante_id'] !== null ? (int) $fila['participante_id'] : null,
        'nombre' => trim((string) $fila['nombre']), 'institucion' => trim((string) $fila['institucion'])] : null;
}

/* Registra la asistencia de quien responde la encuesta por el enlace general: si ya estaba
   inscrito (por correo o nombre) se le marca el ingreso; si no, se crea su registro en el evento.
   En ambos casos ingreso_via = 'encuesta'. Devuelve el id de evento_participantes. */
function cengi_evento_asistencia_encuesta(PDO $db, $eventoId, array $d)
{
    require_once __DIR__ . '/cengi_texto.php';
    $stmt = $db->prepare("SELECT ep.id, ep.ingreso_en, ep.correo_invitado, ep.institucion_invitado,
            COALESCE(NULLIF(p.nombre_participantes, ''), ep.nombre_invitado) AS nombre,
            LOWER(TRIM(COALESCE(NULLIF(p.correo_participantes, ''), ep.correo_invitado, ''))) AS correo
        FROM evento_participantes ep LEFT JOIN participantes p ON p.id = ep.participante_id
        WHERE ep.evento_id = ? FOR UPDATE");
    $stmt->execute([(int) $eventoId]);
    $correo = strtolower($d['correo']);
    $nombre = cengi_texto_normalizado(preg_replace('/\s+/u', ' ', $d['nombre']));
    $encontrado = null;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $ep) {
        if (($correo !== '' && $ep['correo'] === $correo) || cengi_texto_normalizado(preg_replace('/\s+/u', ' ', $ep['nombre'])) === $nombre) {
            $encontrado = $ep;
            if ($correo !== '' && $ep['correo'] === $correo) {
                break;
            }
        }
    }
    if ($encontrado) {
        $db->prepare("UPDATE evento_participantes SET ingreso_via = IF(ingreso_en IS NULL, 'encuesta', ingreso_via), ingreso_en = COALESCE(ingreso_en, NOW()),
                correo_invitado = COALESCE(NULLIF(correo_invitado, ''), ?), institucion_invitado = COALESCE(NULLIF(institucion_invitado, ''), ?),
                cargo_invitado = COALESCE(NULLIF(cargo_invitado, ''), ?) WHERE id = ?")
            ->execute([$correo !== '' ? $correo : null, $d['institucion'] !== '' ? $d['institucion'] : null, ($d['cargo'] ?? '') !== '' ? $d['cargo'] : null, (int) $encontrado['id']]);
        return (int) $encontrado['id'];
    }
    $institucion = cengi_institucion_normalizar($db, $d['institucion']);
    $ingenioId = $institucion['ingenio_id'];
    $d['institucion'] = $institucion['institucion'];
    $db->prepare("INSERT INTO evento_participantes (evento_id, participante_id, nombre_invitado, correo_invitado, ingenio_id, institucion_invitado, cargo_invitado, codigo_qr,
            ingreso_en, ingreso_via, confirmado_en, confirmado_por) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'encuesta', NOW(), 'Encuesta de percepción')")
        ->execute([(int) $eventoId, $d['participante_id'] ?? null, $d['nombre'], $correo !== '' ? $correo : null, $ingenioId, $ingenioId ? null : ($d['institucion'] !== '' ? $d['institucion'] : null),
            ($d['cargo'] ?? '') !== '' ? $d['cargo'] : null, cengi_evento_generar_codigo_qr($db)]);
    return (int) $db->lastInsertId();
}

/* Eventos de transferencia de Comites (misma instancia MySQL, lectura directa) para vincular
   un evento: Comites copia como asistentes a los participantes con ingreso registrado. */
function cengi_evento_transferencias(PDO $db)
{
    $env = cengicursos_env();
    $base = str_replace('`', '``', $env['DB_COMITES_NAME'] ?? $env['COMITES_DB_NAME'] ?? 'sistema_comites');
    try {
        return $db->query("SELECT id, nombre, plan_mes, COALESCE(fecha, plan_fecha) AS fecha FROM `{$base}`.eventos_transferencia
            WHERE (cancelada = 0 AND plan_mes >= '" . (date('Y') - 1) . "-01')
               OR id IN (SELECT transferencia_id FROM eventos WHERE transferencia_id IS NOT NULL)
            ORDER BY plan_mes, id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Eventos de transferencia (Comites): ' . $e->getMessage());
        return [];
    }
}

/* Token aleatorio de 32 caracteres hex (enlaces publicos de encuestas). */
function cengi_evento_token()
{
    return bin2hex(random_bytes(16));
}

/* Token del enlace general / QR de la encuesta de percepcion de un evento (se crea la
   primera vez que se pide). */
function cengi_evento_asegurar_token_percepcion(PDO $db, $eventoId)
{
    $stmt = $db->prepare('SELECT token_percepcion FROM eventos WHERE id = ?');
    $stmt->execute([(int) $eventoId]);
    $token = $stmt->fetchColumn();
    if (is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token)) {
        return $token;
    }
    $token = cengi_evento_token();
    $db->prepare('UPDATE eventos SET token_percepcion = ? WHERE id = ? AND token_percepcion IS NULL')->execute([$token, (int) $eventoId]);
    $stmt->execute([(int) $eventoId]);
    return (string) $stmt->fetchColumn();
}

/* URL base del sitio para los enlaces que se envian por correo: APP_URL del .env si
   existe; si no, el esquema y host de la peticion actual (solo se usa desde acciones
   del panel de administracion). */
function cengi_evento_url_base()
{
    $env = function_exists('cengicursos_env') ? cengicursos_env() : [];
    if (!empty($env['APP_URL'])) {
        return rtrim((string) $env['APP_URL'], '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

function cengi_evento_url_encuesta($token)
{
    return cengi_evento_url_base() . '/cengicursos/encuesta_evento.php?t=' . rawurlencode((string) $token);
}

/* Cuerpo HTML de los correos de encuestas (mismo layout que los correos de gafete):
   $parrafos es una lista de textos planos; $url y $boton arman el boton principal. */
function cengi_evento_correo_encuesta_html($saludo, array $parrafos, $url, $boton)
{
    $e = static function ($t) {
        return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
    };
    $cuerpo = '';
    foreach ($parrafos as $p) {
        $cuerpo .= "<p style='margin:0 0 14px;font-size:15px;line-height:1.6;color:#333333;'>" . $e($p) . '</p>';
    }
    return "<!DOCTYPE html><html lang='es'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'></head>
<body style='margin:0;padding:0;background-color:#f4f6f8;font-family:Arial, Helvetica, sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background-color:#f4f6f8;padding:40px 15px;'><tr><td align='center'>
<table width='600' cellpadding='0' cellspacing='0' style='width:100%;max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);'>
<tr><td style='background:#176b3a;padding:30px;text-align:center;color:#ffffff;'>
<table cellpadding='0' cellspacing='0' style='margin:0 auto;'><tr><td style='background:#ffffff;border-radius:12px;padding:12px 22px;'><img src='cid:logo_cengicana' alt='CENGICAÑA' style='display:block;max-width:170px;height:auto;'></td></tr></table>
</td></tr>
<tr><td style='padding:32px 34px;'>
<p style='margin:0 0 16px;font-size:16px;color:#222222;'>" . $e($saludo) . "</p>
{$cuerpo}
<p style='margin:24px 0;text-align:center;'><a href='" . $e($url) . "' style='display:inline-block;background:#176b3a;color:#ffffff;text-decoration:none;font-weight:bold;padding:13px 26px;border-radius:8px;'>" . $e($boton) . "</a></p>
<p style='margin:0;font-size:12px;color:#777777;word-break:break-all;'>Si el botón no funciona, copia este enlace: " . $e($url) . "</p>
</td></tr>
<tr><td style='background:#f1f3f5;padding:18px;text-align:center;font-size:12px;color:#777777;'>CENGICAÑA · Centro Guatemalteco de Investigación y Capacitación de la Caña de Azúcar</td></tr>
</table></td></tr></table></body></html>";
}

