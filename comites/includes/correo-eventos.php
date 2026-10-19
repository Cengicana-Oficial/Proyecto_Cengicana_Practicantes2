<?php
declare(strict_types=1);

// Notificaciones por correo según eventos del flujo de trabajo.
//
// Cada sección llama a correo_evento() cuando ocurre algo (se asigna un revisor,
// un boletín entra a revisión, etc.). El correo se guarda en `correo_cola` dentro
// de la misma transacción que el cambio: si la operación se revierte, el correo
// no sale. Al terminar la petición se envía la cola (correo_despachar) y una vez
// al día se generan los recordatorios de plazos. cron/correos.php hace lo mismo
// desde la línea de comandos para reintentos y servidores con cron.
//
// En Configuración > Notificaciones por correo se activa cada evento, se edita su
// asunto y mensaje, se definen destinatarios y los días de anticipación.

/**
 * Catálogo de eventos. `auto` indica que el sistema conoce al destinatario (revisor,
 * autor, responsable); si es false, los destinatarios se configuran como lista fija
 * (coordinación, Transferencia, Dirección, Diagramación).
 */
function correo_catalogo(): array
{
    $articulo = ['numero' => 'Número del artículo', 'titulo' => 'Título del artículo', 'autor' => 'Autor principal', 'memoria' => 'Nombre de la memoria'];
    $mpr = ['memoria' => 'Nombre de la memoria', 'version' => 'Versión del consolidado', 'fecha_inicio' => 'Inicio de la etapa', 'fecha_limite' => 'Fecha límite de la etapa'];
    $documento = ['titulo' => 'Título del documento', 'tipo' => 'Tipo (Boletín, Informe…)', 'cargado_por' => 'Quién lo cargó', 'observaciones' => 'Comentario del revisor'];
    $informe = ['area' => 'Área', 'periodo' => 'Cuatrimestre y año', 'observaciones' => 'Comentario'];

    return [
        // ---- Memoria de resultados: artículos.
        'mem_asignado' => ['grupo' => 'Memoria de resultados · artículos', 'nombre' => 'Asignación de revisor', 'cuando' => 'Coordinación asigna o reasigna un revisor a un artículo.', 'para' => 'Revisor asignado', 'auto' => true,
            'vars' => $articulo + ['fecha_limite' => 'Fecha límite de revisión', 'nota' => 'Nota de coordinación'],
            'asunto' => 'Se le asignó la revisión del artículo {numero}',
            'cuerpo' => "Estimado(a) {nombre}:\n\nSe le asignó la revisión del artículo «{titulo}» ({numero}) de la {memoria}.\n\nFecha límite de revisión: {fecha_limite}\n{nota}\n\nIngrese al sistema para descargar el artículo y el formato de criterios."],
        'mem_entregado' => ['grupo' => 'Memoria de resultados · artículos', 'nombre' => 'Nuevo artículo entregado', 'cuando' => 'Un autor entrega un artículo nuevo; queda pendiente de asignar revisor.', 'para' => 'Coordinación editorial', 'auto' => false,
            'vars' => $articulo,
            'asunto' => 'Nuevo artículo para asignar revisor: {numero}',
            'cuerpo' => "Se entregó el artículo «{titulo}» ({numero}) de {autor} para la {memoria}.\n\nEstá pendiente de asignación de revisor."],
        'mem_entregado_autor' => ['grupo' => 'Memoria de resultados · artículos', 'nombre' => 'Confirmación de entrega al autor', 'cuando' => 'El autor entrega un artículo nuevo.', 'para' => 'Autor del artículo', 'auto' => true, 'activo' => false,
            'vars' => $articulo,
            'asunto' => 'Recibimos su artículo {numero}',
            'cuerpo' => "Estimado(a) {nombre}:\n\nRecibimos su artículo «{titulo}» para la {memoria}. Se le asignó el número {numero}.\n\nLe avisaremos cuando la revisión tenga observaciones."],
        'mem_declinado' => ['grupo' => 'Memoria de resultados · artículos', 'nombre' => 'Revisor declina', 'cuando' => 'El revisor indica que no puede revisar el artículo.', 'para' => 'Coordinación editorial', 'auto' => false,
            'vars' => $articulo + ['revisor' => 'Revisor que declinó', 'motivo' => 'Motivo'],
            'asunto' => 'El revisor declinó el artículo {numero}',
            'cuerpo' => "{revisor} declinó la revisión del artículo «{titulo}» ({numero}).\n\nMotivo: {motivo}\n\nEl artículo regresó a la lista para asignar otro revisor."],
        'mem_observaciones' => ['grupo' => 'Memoria de resultados · artículos', 'nombre' => 'Observaciones al autor', 'cuando' => 'El revisor envía su revisión con observaciones.', 'para' => 'Autor del artículo', 'auto' => true,
            'vars' => $articulo + ['recomendacion' => 'Recomendación del revisor', 'comentarios' => 'Comentarios generales'],
            'asunto' => 'Su artículo {numero} tiene observaciones',
            'cuerpo' => "Estimado(a) {nombre}:\n\nLa revisión de su artículo «{titulo}» ({numero}) está disponible.\n\nRecomendación: {recomendacion}\n{comentarios}\n\nIngrese al sistema para descargar las correcciones y entregar la versión corregida."],
        'mem_corregido' => ['grupo' => 'Memoria de resultados · artículos', 'nombre' => 'Artículo listo para diagramación', 'cuando' => 'El autor entrega la versión corregida.', 'para' => 'Diagramación', 'auto' => false,
            'vars' => $articulo,
            'asunto' => 'Artículo listo para diagramación: {numero}',
            'cuerpo' => "El artículo «{titulo}» ({numero}) de {autor} ya tiene su versión corregida y está listo para diagramación."],
        'mem_recordatorio' => ['grupo' => 'Memoria de resultados · artículos', 'nombre' => 'Recordatorio de plazo de revisión', 'cuando' => 'Faltan los días configurados para el plazo de revisión de un artículo.', 'para' => 'Revisor asignado', 'auto' => true, 'dias' => 3,
            'vars' => $articulo + ['fecha_limite' => 'Fecha límite de revisión', 'dias' => 'Días restantes'],
            'asunto' => 'Recordatorio: la revisión de {numero} vence el {fecha_limite}',
            'cuerpo' => "Estimado(a) {nombre}:\n\nLe recordamos que la revisión del artículo «{titulo}» ({numero}) vence el {fecha_limite} (en {dias} día(s))."],
        'mem_vencida' => ['grupo' => 'Memoria de resultados · artículos', 'nombre' => 'Revisión vencida','cuando' => 'Pasó la fecha límite y el revisor no ha enviado la revisión (se envía una vez).', 'para' => 'Revisor asignado', 'auto' => true,
            'vars' => $articulo + ['fecha_limite' => 'Fecha límite de revisión'],
            'asunto' => 'La revisión del artículo {numero} está vencida',
            'cuerpo' => "Estimado(a) {nombre}:\n\nEl plazo para revisar el artículo «{titulo}» ({numero}) venció el {fecha_limite}. Si no puede completarla, indíquelo en el sistema para reasignar el artículo."],

        // ---- Memoria de resultados: consolidado (MPR).
        'mpr_comite' => ['grupo' => 'Memoria de resultados · consolidado (MPR)', 'nombre' => 'Inicia revisión del Comité Editorial', 'cuando' => 'El consolidado entra a la etapa del Comité Editorial.', 'para' => 'Integrantes del Comité Editorial', 'auto' => true,
            'vars' => $mpr,
            'asunto' => 'Revisión del consolidado de la {memoria}',
            'cuerpo' => "Estimado(a) {nombre}:\n\nEl consolidado (v{version}) de la {memoria} está disponible para la revisión del Comité Editorial.\n\nPeríodo de revisión: {fecha_inicio} al {fecha_limite}."],
        'mpr_transferencia' => ['grupo' => 'Memoria de resultados · consolidado (MPR)', 'nombre' => 'Inicia revisión de Transferencia', 'cuando' => 'El consolidado pasa a la revisión del Jefe de Transferencia.', 'para' => 'Jefe de Transferencia', 'auto' => false,
            'vars' => $mpr,
            'asunto' => 'Consolidado de la {memoria} listo para revisión de Transferencia',
            'cuerpo' => "El consolidado (v{version}) de la {memoria} está listo para su revisión.\n\nPeríodo de revisión: {fecha_inicio} al {fecha_limite}."],
        'mpr_direccion' => ['grupo' => 'Memoria de resultados · consolidado (MPR)', 'nombre' => 'Inicia revisión de Dirección General', 'cuando' => 'El consolidado pasa a la revisión final de Dirección.', 'para' => 'Dirección General', 'auto' => false,
            'vars' => $mpr,
            'asunto' => 'Consolidado de la {memoria} listo para aprobación de Dirección',
            'cuerpo' => "El consolidado (v{version}) de la {memoria} está listo para su revisión y aprobación de liberación.\n\nPeríodo de revisión: {fecha_inicio} al {fecha_limite}."],
        'mpr_correccion' => ['grupo' => 'Memoria de resultados · consolidado (MPR)', 'nombre' => 'Ronda de corrección del consolidado', 'cuando' => 'Se cierra una revisión con observaciones y Diagramación debe corregir.', 'para' => 'Diagramación', 'auto' => false,
            'vars' => $mpr,
            'asunto' => 'Observaciones por atender en el consolidado de la {memoria}',
            'cuerpo' => "El consolidado (v{version}) de la {memoria} tiene observaciones pendientes.\n\nCargue una nueva versión con las correcciones antes del {fecha_limite}."],
        'mpr_liberado' => ['grupo' => 'Memoria de resultados · consolidado (MPR)', 'nombre' => 'Consolidado liberado', 'cuando' => 'Dirección General aprueba la liberación del consolidado.', 'para' => 'Diagramación', 'auto' => false,
            'vars' => $mpr,
            'asunto' => 'Consolidado de la {memoria} aprobado para publicación',
            'cuerpo' => "Dirección General aprobó la liberación del consolidado (v{version}) de la {memoria}.\n\nRegistre la publicación en el sistema cuando esté disponible."],

        // ---- Biblioteca para ingenios: boletines y documentos con aprobación.
        'doc_transferencia' => ['grupo' => 'Boletines y documentos para ingenios', 'nombre' => 'Documento asignado a revisión de Transferencia', 'cuando' => 'Un boletín u otro documento entra a primera o segunda revisión.', 'para' => 'Jefe de Transferencia', 'auto' => false,
            'vars' => $documento + ['etapa' => 'Etapa de revisión'],
            'asunto' => 'Se le asignó la revisión de: {titulo}',
            'cuerpo' => "Se le asignó la {etapa} del {tipo} «{titulo}», cargado por {cargado_por}.\n\nIngrese al sistema para revisarlo."],
        'doc_direccion' => ['grupo' => 'Boletines y documentos para ingenios', 'nombre' => 'Documento asignado a aprobación final', 'cuando' => 'Transferencia aprueba y el documento pasa a Dirección General.', 'para' => 'Dirección General', 'auto' => false,
            'vars' => $documento,
            'asunto' => 'Aprobación final pendiente: {titulo}',
            'cuerpo' => "El {tipo} «{titulo}» fue aprobado por Transferencia y espera su aprobación final."],
        'doc_devuelto' => ['grupo' => 'Boletines y documentos para ingenios', 'nombre' => 'Documento devuelto con observaciones', 'cuando' => 'Un revisor devuelve el documento con observaciones.', 'para' => 'Quien cargó el documento', 'auto' => true,
            'vars' => $documento,
            'asunto' => 'Su documento «{titulo}» tiene observaciones',
            'cuerpo' => "Estimado(a) {nombre}:\n\nEl {tipo} «{titulo}» fue devuelto con observaciones:\n\n{observaciones}\n\nAtienda las observaciones y vuelva a enviarlo a revisión."],
        'doc_aprobado' => ['grupo' => 'Boletines y documentos para ingenios', 'nombre' => 'Documento aprobado', 'cuando' => 'Dirección General da la aprobación final.', 'para' => 'Quien cargó el documento', 'auto' => true,
            'vars' => $documento,
            'asunto' => 'Documento aprobado: {titulo}',
            'cuerpo' => "Estimado(a) {nombre}:\n\nEl {tipo} «{titulo}» fue aprobado. Ya puede enviarlo a los destinatarios desde la biblioteca."],
        'doc_rechazado' => ['grupo' => 'Boletines y documentos para ingenios', 'nombre' => 'Documento rechazado', 'cuando' => 'Un revisor rechaza el documento.', 'para' => 'Quien cargó el documento', 'auto' => true,
            'vars' => $documento,
            'asunto' => 'Documento rechazado: {titulo}',
            'cuerpo' => "Estimado(a) {nombre}:\n\nEl {tipo} «{titulo}» fue rechazado.\n\n{observaciones}"],

        // ---- Informes cuatrimestrales.
        'inf_entregado' => ['grupo' => 'Informes cuatrimestrales', 'nombre' => 'Informe entregado para revisión', 'cuando' => 'Un área entrega su informe o atiende los pendientes.', 'para' => 'Revisión de informes (Dirección)', 'auto' => false,
            'vars' => $informe,
            'asunto' => 'Informe listo para revisar: {area} · {periodo}',
            'cuerpo' => "El informe del área {area} ({periodo}) está listo para su revisión."],
        'inf_observado' => ['grupo' => 'Informes cuatrimestrales', 'nombre' => 'Informe con pendientes', 'cuando' => 'La revisión deja pendientes por atender.', 'para' => 'Responsable del área', 'auto' => true,
            'vars' => $informe,
            'asunto' => 'Su informe {periodo} tiene pendientes',
            'cuerpo' => "Estimado(a) {nombre}:\n\nEl informe del área {area} ({periodo}) quedó con pendientes por atender.\n\n{observaciones}"],
        'inf_revisado' => ['grupo' => 'Informes cuatrimestrales', 'nombre' => 'Informe revisado satisfactoriamente', 'cuando' => 'La revisión concluye sin pendientes.', 'para' => 'Responsable del área', 'auto' => true, 'activo' => false,
            'vars' => $informe,
            'asunto' => 'Informe {periodo} revisado',
            'cuerpo' => "Estimado(a) {nombre}:\n\nEl informe del área {area} ({periodo}) fue revisado satisfactoriamente."],
        'inf_recordatorio' => ['grupo' => 'Informes cuatrimestrales', 'nombre' => 'Recordatorio de entrega de informe', 'cuando' => 'Faltan los días configurados para el límite de entrega y el área no ha entregado.', 'para' => 'Responsable del área', 'auto' => true, 'dias' => 10,
            'vars' => $informe + ['fecha_limite' => 'Fecha límite de entrega', 'dias' => 'Días restantes'],
            'asunto' => 'Recordatorio: el informe {periodo} vence el {fecha_limite}',
            'cuerpo' => "Estimado(a) {nombre}:\n\nLe recordamos que el informe del área {area} ({periodo}) debe entregarse a más tardar el {fecha_limite} (en {dias} día(s))."],
    ];
}

/** Configuración efectiva de cada evento: valores por defecto + lo guardado. */
function correo_config(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $guardado = [];
    foreach ($pdo->query('SELECT * FROM correo_eventos') as $fila) {
        $guardado[$fila['evento']] = $fila;
    }
    $cache = [];
    foreach (correo_catalogo() as $clave => $def) {
        $g = $guardado[$clave] ?? null;
        $cache[$clave] = $def + [
            'clave' => $clave,
            // Los eventos dirigidos a un rol empiezan apagados: necesitan que se configuren sus correos.
            'activo_efectivo' => $g ? (bool) $g['activo'] : ($def['activo'] ?? $def['auto']),
            'asunto_efectivo' => $g && trim((string) $g['asunto']) !== '' ? (string) $g['asunto'] : $def['asunto'],
            'cuerpo_efectivo' => $g && trim((string) $g['cuerpo']) !== '' ? (string) $g['cuerpo'] : $def['cuerpo'],
            'destinatarios' => $g ? correo_lista_emails((string) $g['destinatarios']) : [],
            'dias_efectivo' => isset($def['dias']) ? max(0, (int) ($g['dias'] ?? $def['dias'])) : null,
            'personalizado' => $g && (trim((string) $g['asunto']) !== '' || trim((string) $g['cuerpo']) !== ''),
        ];
    }
    return $cache;
}

function correo_ajuste(PDO $pdo, string $clave, string $defecto = ''): string
{
    $q = $pdo->prepare('SELECT valor FROM correo_ajustes WHERE clave = ?');
    $q->execute([$clave]);
    $v = $q->fetchColumn();
    return $v === false ? $defecto : (string) $v;
}

function correo_guardar_ajuste(PDO $pdo, string $clave, string $valor): void
{
    $pdo->prepare('INSERT INTO correo_ajustes (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')->execute([$clave, $valor]);
}

/** @return string[] correos válidos, sin repetir, a partir de texto separado por comas, punto y coma o saltos de línea. */
function correo_lista_emails(string $texto): array
{
    $lista = [];
    foreach (preg_split('/[\s,;]+/', mb_strtolower($texto)) ?: [] as $email) {
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $lista[$email] = $email;
        }
    }
    return array_values($lista);
}

function correo_smtp_listo(): bool
{
    foreach (['MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS'] as $clave) {
        if (in_array(app_env_value($clave), ['', 'change-me'], true)) {
            return false;
        }
    }
    return true;
}

/** URL absoluta del módulo para los enlaces de los correos. */
function correo_url_base(PDO $pdo): string
{
    $base = trim(correo_ajuste($pdo, 'url_base'));
    if ($base === '' && !empty($_SERVER['HTTP_HOST'])) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $base = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . rtrim(dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/comites/index.php')), '/\\');
        correo_guardar_ajuste($pdo, 'url_base', $base); // Lo usa el cron, que no conoce el dominio.
    }
    return rtrim($base, '/');
}

/** Reemplaza {variables} en el texto. */
function correo_reemplazar(string $texto, array $vars): string
{
    return preg_replace_callback('/\{([a-z_]+)\}/', static fn($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0], $texto) ?? $texto;
}

function correo_html(string $cuerpo, string $enlace): string
{
    $parrafos = '';
    foreach (preg_split("/\n{2,}/", trim($cuerpo)) ?: [] as $p) {
        if (trim($p) !== '') {
            $parrafos .= '<p style="margin:0 0 14px;line-height:1.6">' . nl2br(htmlspecialchars(trim($p), ENT_QUOTES, 'UTF-8')) . '</p>';
        }
    }
    $boton = $enlace !== '' ? '<p style="margin:22px 0 6px"><a href="' . htmlspecialchars($enlace, ENT_QUOTES, 'UTF-8') . '" style="background:#73bc25;color:#ffffff;padding:11px 18px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block">Abrir en el sistema</a></p>' : '';
    return '<div style="background:#f3f4f6;padding:24px 12px;font-family:Arial,Helvetica,sans-serif">'
        . '<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e4e6">'
        . '<div style="background:#1f5632;color:#ffffff;padding:16px 22px;font-weight:bold;font-size:15px">CENGICAÑA · Comités, Transferencia y Comunicación</div>'
        . '<div style="height:4px;background:linear-gradient(90deg,#73bc25 0 25%,#a3d300 25% 50%,#ffcc00 50% 75%,#ff6b00 75%)"></div>'
        . '<div style="padding:22px;color:#3d4247;font-size:14px">' . $parrafos . $boton . '</div>'
        . '<div style="padding:12px 22px;background:#f8f9f9;color:#8b9298;font-size:11px">Mensaje automático del sistema. No responda a este correo.</div>'
        . '</div></div>';
}

/**
 * Encola el correo de un evento. Nunca lanza excepciones: una notificación no debe
 * impedir que se guarde el trabajo.
 *
 * @param array<int,array{email:string,nombre?:string}> $destinatarios destinatarios conocidos por el sistema (eventos `auto`).
 * @param string $ruta enlace relativo al módulo, por ejemplo "index.php?view=memorias".
 * @param string $referencia identificador para no repetir recordatorios (solo con $unico).
 * @return int correos encolados
 */
function correo_evento(PDO $pdo, string $evento, array $destinatarios, array $vars, string $ruta = '', string $referencia = '', bool $unico = false): int
{
    try {
        $cfg = correo_config($pdo)[$evento] ?? null;
        if (!$cfg || !$cfg['activo_efectivo'] || correo_ajuste($pdo, 'activo', '1') !== '1') {
            return 0;
        }
        $para = [];
        foreach ($cfg['auto'] ? $destinatarios : [] as $d) {
            $email = mb_strtolower(trim((string) ($d['email'] ?? '')));
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $para[$email] = trim((string) ($d['nombre'] ?? ''));
            }
        }
        foreach ($cfg['destinatarios'] as $email) {
            $para[$email] ??= '';
        }
        $prueba = correo_lista_emails(correo_ajuste($pdo, 'redirigir'))[0] ?? '';
        $base = correo_url_base($pdo);
        $enlace = $ruta !== '' && $base !== '' ? $base . '/' . ltrim($ruta, '/') : ''; // Sin dominio conocido (cron) el correo va sin botón.
        $existe = $pdo->prepare('SELECT 1 FROM correo_cola WHERE evento = ? AND destinatario_original = ? AND referencia = ? LIMIT 1');
        $insertar = $pdo->prepare('INSERT INTO correo_cola (evento, destinatario, destinatario_original, nombre, asunto, cuerpo_html, enlace, referencia) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $total = 0;
        foreach ($para as $email => $nombre) {
            if ($unico && $referencia !== '') {
                $existe->execute([$evento, $email, $referencia]);
                if ($existe->fetchColumn()) {
                    continue;
                }
            }
            $v = array_map(static fn($x) => is_scalar($x) || $x === null ? (string) $x : '', $vars) + ['nombre' => $nombre !== '' ? $nombre : 'colaborador(a)', 'enlace' => $enlace];
            if ($nombre !== '') {
                $v['nombre'] = $nombre;
            }
            $asunto = mb_substr(trim(correo_reemplazar($cfg['asunto_efectivo'], $v)), 0, 250);
            $cuerpo = preg_replace("/\n{3,}/", "\n\n", correo_reemplazar($cfg['cuerpo_efectivo'], $v)) ?? '';
            if ($prueba !== '') {
                $asunto = mb_substr('[Prueba → ' . $email . '] ' . $asunto, 0, 250);
            }
            $insertar->execute([$evento, $prueba !== '' ? $prueba : $email, $email, mb_substr($nombre, 0, 180), $asunto, correo_html($cuerpo, $enlace), mb_substr($enlace, 0, 500), mb_substr($referencia, 0, 190)]);
            $total++;
        }
        if ($total) {
            $GLOBALS['correo_pendientes'] = true;
        }
        return $total;
    } catch (Throwable $e) {
        error_log('Correo evento ' . $evento . ': ' . $e->getMessage());
        return 0;
    }
}

/** Correo y nombre de un usuario de la plataforma (base de datos del menú). */
function correo_usuario(int $usuarioId): ?array
{
    $menuPdo = $GLOBALS['menuPdo'] ?? null;
    if (!$menuPdo instanceof PDO || $usuarioId <= 0) {
        return null;
    }
    try {
        $q = $menuPdo->prepare('SELECT nombre, correo AS email FROM usuarios WHERE id = ?');
        $q->execute([$usuarioId]);
        $u = $q->fetch();
        return $u && filter_var($u['email'], FILTER_VALIDATE_EMAIL) ? $u : null;
    } catch (PDOException $e) {
        return null;
    }
}

/** Envía los correos pendientes. Devuelve [enviados, errores]. */
function correo_despachar(PDO $pdo, int $limite = 25): array
{
    if (!correo_smtp_listo()) {
        return [0, 0]; // Quedan pendientes hasta que se configure el SMTP.
    }
    // Reclamos abandonados (por ejemplo, un proceso que se cortó a la mitad).
    $pdo->exec("UPDATE correo_cola SET estado = 'pendiente' WHERE estado = 'enviando' AND actualizado_en < NOW() - INTERVAL 15 MINUTE");
    $ids = $pdo->query("SELECT id FROM correo_cola WHERE estado = 'pendiente' AND intentos < 3 ORDER BY id LIMIT " . max(1, $limite))->fetchAll(PDO::FETCH_COLUMN);
    $reclamar = $pdo->prepare("UPDATE correo_cola SET estado = 'enviando', intentos = intentos + 1 WHERE id = ? AND estado = 'pendiente'");
    $leer = $pdo->prepare('SELECT * FROM correo_cola WHERE id = ?');
    $cerrar = $pdo->prepare('UPDATE correo_cola SET estado = ?, error = ?, enviado_en = IF(? = \'enviado\', NOW(), enviado_en) WHERE id = ?');
    $enviados = $errores = 0;
    foreach ($ids as $id) {
        $reclamar->execute([$id]);
        if ($reclamar->rowCount() !== 1) {
            continue; // Otro proceso lo tomó.
        }
        $leer->execute([$id]);
        $c = $leer->fetch();
        $r = comites_enviar_correo((string) $c['destinatario'], (string) $c['nombre'], (string) $c['asunto'], (string) $c['cuerpo_html']);
        $estado = $r['ok'] ? 'enviado' : ((int) $c['intentos'] >= 3 ? 'error' : 'pendiente');
        $cerrar->execute([$estado, $r['ok'] ? null : mb_substr((string) $r['error'], 0, 250), $estado, $id]);
        $r['ok'] ? $enviados++ : $errores++;
    }
    return [$enviados, $errores];
}

/** Recordatorios de plazos (una vez al día). */
function correo_recordatorios(PDO $pdo): int
{
    $cfg = correo_config($pdo);
    $hoy = new DateTimeImmutable('today');
    $total = 0;
    $consulta = static function (string $sql, array $p = []) use ($pdo): array {
        try {
            $q = $pdo->prepare($sql);
            $q->execute($p);
            return $q->fetchAll();
        } catch (PDOException $e) {
            return []; // La sección aún no creó sus tablas.
        }
    };

    // Artículos en revisión: recordatorio previo y aviso de vencimiento.
    if ($cfg['mem_recordatorio']['activo_efectivo'] || $cfg['mem_vencida']['activo_efectivo']) {
        $dias = (int) $cfg['mem_recordatorio']['dias_efectivo'];
        $filas = $consulta("SELECT m.*, e.nombre AS memoria, c.nombre AS revisor_nombre, c.email AS revisor_email FROM memorias_resultados m LEFT JOIN memoria_ediciones e ON e.id = m.edicion_id LEFT JOIN contactos c ON c.id = m.revisor_contacto_id WHERE m.flujo = 'revision' AND m.limite_revision IS NOT NULL");
        foreach ($filas as $m) {
            $limite = new DateTimeImmutable($m['limite_revision']);
            $faltan = (int) $hoy->diff($limite)->format('%r%a');
            $vars = ['numero' => $m['numero_memoria'], 'titulo' => $m['titulo'], 'autor' => $m['autor'], 'memoria' => $m['memoria'] ?? 'memoria', 'fecha_limite' => $limite->format('d/m/Y'), 'dias' => $faltan];
            $para = [['email' => (string) $m['revisor_email'], 'nombre' => (string) $m['revisor_nombre']]];
            $ruta = 'index.php?view=memorias&edicion=' . (int) $m['edicion_id'];
            if ($faltan >= 0 && $faltan <= $dias) {
                $total += correo_evento($pdo, 'mem_recordatorio', $para, $vars, $ruta, 'art:' . $m['id'] . ':' . $m['limite_revision'], true);
            } elseif ($faltan < 0) {
                $total += correo_evento($pdo, 'mem_vencida', $para, $vars, $ruta, 'art:' . $m['id'] . ':' . $m['limite_revision'], true);
            }
        }
    }

    // Informes cuatrimestrales sin entregar cuyo límite se acerca.
    if ($cfg['inf_recordatorio']['activo_efectivo'] && function_exists('notif_cuatrimestres_por_vencer')) {
        $dias = (int) $cfg['inf_recordatorio']['dias_efectivo'];
        foreach (notif_cuatrimestres_por_vencer($hoy->format('Y-m-d')) as [$anio, $cuatri, $limite]) {
            $faltan = (int) $hoy->diff(new DateTimeImmutable($limite))->format('%r%a');
            if ($faltan < 0 || $faltan > $dias) {
                continue;
            }
            $periodo = ['', 'I', 'II', 'III'][$cuatri] . ' cuatrimestre ' . $anio;
            foreach ($consulta("SELECT a.id, a.nombre, a.correo FROM informe_areas a WHERE a.activo = 1 AND a.correo <> '' AND NOT EXISTS (SELECT 1 FROM informes_cuatrimestrales i WHERE i.area_id = a.id AND i.anio = ? AND i.cuatri = ? AND i.flujo <> 'pendiente')", [$anio, $cuatri]) as $a) {
                $total += correo_evento($pdo, 'inf_recordatorio', [['email' => $a['correo'], 'nombre' => '']], ['area' => $a['nombre'], 'periodo' => $periodo, 'fecha_limite' => date('d/m/Y', strtotime($limite)), 'dias' => $faltan], 'index.php?view=informes', "inf:{$a['id']}:$anio:$cuatri", true);
            }
        }
    }
    return $total;
}

/**
 * Al terminar cada petición: envía lo encolado y, una vez al día, genera los
 * recordatorios. Se registra en index.php con register_shutdown_function().
 */
function correo_al_terminar(PDO $pdo): void
{
    try {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request(); // La respuesta llega al usuario sin esperar al SMTP.
        }
        $hoy = date('Y-m-d');
        if (correo_ajuste($pdo, 'recordatorios_fecha') !== $hoy) {
            correo_guardar_ajuste($pdo, 'recordatorios_fecha', $hoy);
            correo_recordatorios($pdo);
            $GLOBALS['correo_pendientes'] = true;
        }
        if (!empty($GLOBALS['correo_pendientes'])) {
            correo_despachar($pdo, 10);
        }
    } catch (Throwable $e) {
        error_log('Correo al terminar: ' . $e->getMessage());
    }
}

/** Guarda la configuración enviada desde la vista de notificaciones. */
function correo_guardar_config(PDO $pdo, array $post, string $actor): void
{
    $catalogo = correo_catalogo();
    $guardar = $pdo->prepare('INSERT INTO correo_eventos (evento, activo, asunto, cuerpo, destinatarios, dias, actualizado_por) VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE activo = VALUES(activo), asunto = VALUES(asunto), cuerpo = VALUES(cuerpo), destinatarios = VALUES(destinatarios), dias = VALUES(dias), actualizado_por = VALUES(actualizado_por)');
    foreach ($catalogo as $clave => $def) {
        $e = (array) ($post['eventos'][$clave] ?? []);
        $asunto = trim((string) ($e['asunto'] ?? ''));
        $cuerpo = trim(str_replace("\r\n", "\n", (string) ($e['cuerpo'] ?? '')));
        // Si coincide con el texto por defecto se guarda vacío para seguir las mejoras futuras del texto base.
        $asunto = $asunto === $def['asunto'] ? '' : mb_substr($asunto, 0, 250);
        $cuerpo = $cuerpo === $def['cuerpo'] ? '' : mb_substr($cuerpo, 0, 5000);
        $dias = isset($def['dias']) ? min(60, max(0, (int) ($e['dias'] ?? $def['dias']))) : null;
        $guardar->execute([$clave, !empty($e['activo']) ? 1 : 0, $asunto, $cuerpo, implode(', ', correo_lista_emails((string) ($e['destinatarios'] ?? ''))), $dias, mb_substr($actor, 0, 180)]);
    }
    correo_guardar_ajuste($pdo, 'activo', !empty($post['activo_general']) ? '1' : '0');
    correo_guardar_ajuste($pdo, 'redirigir', correo_lista_emails((string) ($post['redirigir'] ?? ''))[0] ?? '');
    $url = trim((string) ($post['url_base'] ?? ''));
    correo_guardar_ajuste($pdo, 'url_base', filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url) ? rtrim($url, '/') : '');
}
