<?php
declare(strict_types=1);

// Campanita de notificaciones del modulo Comites. Los avisos se calculan con
// lo que ya registra cada seccion, segun los permisos y el programa de cada
// usuario; no hay una cola aparte. Cada aviso tiene una clave estable que
// incluye su estado: cuando algo cambia de etapa vuelve a aparecer como nuevo.
// Las leidas se guardan en `notificaciones_leidas`.

const NOTIF_DIAS = 30;

function notif_rol(array $u): string
{
    return normalize_text((string) ($u['nombre_rol'] ?? ''));
}

/** @return array<int,array{clave:string,icon:string,tono:string,texto:string,detalle:string,url:string,fecha:string}> */
function notif_calcular(PDO $pdo, array $u): array
{
    $avisos = [];
    $agregar = static function (string $clave, string $icon, string $tono, string $texto, string $url, ?string $fecha, string $detalle = '') use (&$avisos): void {
        $avisos[] = ['clave' => mb_substr($clave, 0, 190), 'icon' => $icon, 'tono' => $tono, 'texto' => $texto, 'detalle' => $detalle, 'url' => $url, 'fecha' => $fecha ?: date('Y-m-d H:i:s')];
    };
    $consulta = static function (string $sql, array $params = []) use ($pdo): array {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return []; // La seccion aun no creo sus tablas o columnas.
        }
    };
    $id = (int) ($u['id'] ?? 0);
    $correo = mb_strtolower(trim((string) ($u['correo'] ?? '')));
    $hoy = date('Y-m-d');

    // ---- Biblioteca para ingenios: documentos que esperan la revision de este usuario.
    if (can_view_resource($u, 'documentos')) {
        $etapas = [];
        if (has_permission($u, 'comites.documentos.revisar_transferencia')) {
            $etapas += ['rev1' => 'primera revisión', 'rev2' => 'segunda revisión'];
        }
        if (has_permission($u, 'comites.documentos.aprobar_direccion')) {
            $etapas['rev3'] = 'aprobación final';
        }
        if ($etapas) {
            $marcas = implode(',', array_fill(0, count($etapas), '?'));
            foreach ($consulta("SELECT id, titulo, flujo, COALESCE(pendiente_desde, actualizado_en) AS desde FROM documentos WHERE ambito = 'ingenios' AND flujo IN ($marcas) ORDER BY desde DESC LIMIT 10", array_keys($etapas)) as $d) {
                $agregar("doc:{$d['id']}:{$d['flujo']}:{$d['desde']}", 'ti-file-search', 'proceso', 'Documento para tu ' . $etapas[$d['flujo']], 'index.php?view=documentos', $d['desde'], $d['titulo']);
            }
        }
        // Documentos propios que cambiaron de estado.
        $estados = ['devuelto' => ['Tu documento fue devuelto con observaciones', 'ti-arrow-back-up', 'alerta'], 'rechazado' => ['Tu documento fue rechazado', 'ti-circle-x', 'alerta'], 'aprobado' => ['Tu documento fue aprobado: ya se puede enviar', 'ti-circle-check', 'ok']];
        foreach ($consulta("SELECT id, titulo, flujo, actualizado_en FROM documentos WHERE ambito = 'ingenios' AND cargado_por_id = ? AND flujo IN ('devuelto','rechazado','aprobado') AND actualizado_en >= ? ORDER BY actualizado_en DESC LIMIT 10", [$id, date('Y-m-d', strtotime('-' . NOTIF_DIAS . ' days'))]) as $d) {
            [$texto, $icon, $tono] = $estados[$d['flujo']];
            $agregar("doc:{$d['id']}:{$d['flujo']}:{$d['actualizado_en']}", $icon, $tono, $texto, 'index.php?view=documentos', $d['actualizado_en'], $d['titulo']);
        }
    }

    // ---- Informes cuatrimestrales.
    if (can_view_resource($u, 'informes')) {
        $terminos = [1 => 'I', 2 => 'II', 3 => 'III'];
        if (has_permission($u, 'comites.informes.revisar') || notif_rol($u) === 'director general') {
            foreach ($consulta("SELECT id, area, anio, cuatri, flujo, revision, actualizado_en FROM informes_cuatrimestrales WHERE flujo IN ('entregado','atendido') ORDER BY actualizado_en DESC LIMIT 10") as $r) {
                $agregar("inf:{$r['id']}:{$r['flujo']}:{$r['revision']}", 'ti-report-search', 'proceso', $r['flujo'] === 'atendido' ? 'Pendientes atendidos: informe listo para revisar' : 'Informe entregado: listo para revisar', 'index.php?view=informes', $r['actualizado_en'], $r['area'] . ' · ' . ($terminos[(int) $r['cuatri']] ?? '') . ' cuatrimestre ' . $r['anio']);
            }
        }
        // Areas a cargo del usuario (por su programa o por el correo del responsable).
        $areas = $consulta('SELECT id, nombre FROM informe_areas WHERE activo = 1 AND ((programa_id IS NOT NULL AND programa_id = ?) OR (correo <> \'\' AND LOWER(correo) = ?))', [(int) ($u['programa_id'] ?? 0), $correo]);
        foreach ($areas as $a) {
            foreach ($consulta("SELECT id, anio, cuatri, revision, actualizado_en FROM informes_cuatrimestrales WHERE area_id = ? AND flujo = 'observado'", [$a['id']]) as $r) {
                $agregar("inf:{$r['id']}:observado:{$r['revision']}", 'ti-alert-circle', 'alerta', 'Tu informe quedó con pendientes por atender', 'index.php?view=informes', $r['actualizado_en'], $a['nombre'] . ' · ' . ($terminos[(int) $r['cuatri']] ?? '') . ' cuatrimestre ' . $r['anio']);
            }
            // Entrega proxima: el cuatrimestre que vence en los proximos 20 dias y aun no se entrega.
            foreach (notif_cuatrimestres_por_vencer($hoy) as [$anio, $cuatri, $limite]) {
                $entregado = $consulta("SELECT 1 FROM informes_cuatrimestrales WHERE area_id = ? AND anio = ? AND cuatri = ? AND flujo <> 'pendiente' LIMIT 1", [$a['id'], $anio, $cuatri]);
                if (!$entregado) {
                    $dias = (int) round((strtotime($limite) - strtotime($hoy)) / 86400);
                    $agregar("inf-plazo:{$a['id']}:$anio:$cuatri:" . ($dias < 0 ? 'vencido' : ($dias <= 5 ? 'cerca' : 'aviso')), 'ti-calendar-due', $dias <= 5 ? 'alerta' : 'proceso', $dias < 0 ? 'La entrega de tu informe está vencida' : "Tu informe vence en $dias día(s)", 'index.php?view=informes', $hoy . ' 00:00:00', $a['nombre'] . ' · ' . $terminos[$cuatri] . " cuatrimestre $anio · límite " . date('d/m/Y', strtotime($limite)));
                }
            }
        }
    }

    // ---- Memoria de resultados.
    if (can_view_resource($u, 'memorias')) {
        foreach ($consulta("SELECT id, titulo, flujo, actualizado_en FROM memorias_resultados WHERE flujo IN ('observaciones','listo') AND (autor_usuario_id = ? OR (autor_email <> '' AND LOWER(autor_email) = ?)) ORDER BY actualizado_en DESC LIMIT 10", [$id, $correo]) as $m) {
            $agregar("mem:{$m['id']}:{$m['flujo']}:{$m['actualizado_en']}", $m['flujo'] === 'observaciones' ? 'ti-message-exclamation' : 'ti-circle-check', $m['flujo'] === 'observaciones' ? 'alerta' : 'ok', $m['flujo'] === 'observaciones' ? 'Tu artículo tiene observaciones del revisor' : 'Tu artículo fue aprobado para diagramación', 'index.php?view=memorias', $m['actualizado_en'], $m['titulo']);
        }
        $coordina = can_manage_resource($u, 'memorias') || has_permission($u, 'comites.memorias.aprobar_transferencia');
        if ($coordina) {
            $filas = $consulta("SELECT COUNT(*) AS n, MAX(actualizado_en) AS ultimo FROM memorias_resultados WHERE flujo = 'entregado'");
            if ((int) ($filas[0]['n'] ?? 0) > 0) {
                $agregar("mem-asignar:{$filas[0]['n']}:{$filas[0]['ultimo']}", 'ti-user-question', 'proceso', $filas[0]['n'] . ' artículo(s) esperan revisor', 'index.php?view=memorias', $filas[0]['ultimo']);
            }
        }
        if (has_permission($u, 'comites.memorias.diagramar')) {
            $filas = $consulta("SELECT COUNT(*) AS n, MAX(actualizado_en) AS ultimo FROM memorias_resultados WHERE flujo = 'listo'");
            if ((int) ($filas[0]['n'] ?? 0) > 0) {
                $agregar("mem-diag:{$filas[0]['n']}:{$filas[0]['ultimo']}", 'ti-layout', 'proceso', $filas[0]['n'] . ' artículo(s) listos para diagramación', 'index.php?view=memorias', $filas[0]['ultimo']);
            }
        }
    }

    // ---- Seguimiento a proximos pasos.
    if (can_view_resource($u, 'acuerdos')) {
        $nombre = trim((string) ($u['nombre'] ?? ''));
        if (mb_strlen($nombre) >= 4) {
            foreach ($consulta("SELECT id, acuerdo, plazo FROM acuerdos WHERE estado IN ('Pendiente','En proceso') AND plazo IS NOT NULL AND plazo <= ? AND responsable LIKE ? ORDER BY plazo LIMIT 10", [date('Y-m-d', strtotime('+3 days')), '%' . $nombre . '%']) as $a) {
                $vencido = $a['plazo'] < $hoy;
                $agregar("paso:{$a['id']}:" . ($vencido ? 'vencido' : 'cerca') . ":{$a['plazo']}", $vencido ? 'ti-alarm' : 'ti-hourglass', $vencido ? 'alerta' : 'proceso', $vencido ? 'Tienes un próximo paso vencido' : 'Tu próximo paso vence el ' . date('d/m/Y', strtotime($a['plazo'])), 'index.php?view=acuerdos', $a['plazo'] . ' 00:00:00', mb_strimwidth((string) $a['acuerdo'], 0, 90, '…'));
            }
        }
        if (can_manage_resource($u, 'acuerdos')) {
            $filas = $consulta("SELECT COUNT(*) AS n FROM acuerdos WHERE estado IN ('Pendiente','En proceso') AND plazo IS NOT NULL AND plazo < ?", [$hoy]);
            if ((int) ($filas[0]['n'] ?? 0) > 0) {
                $agregar("pasos-vencidos:$hoy:{$filas[0]['n']}", 'ti-alarm', 'alerta', $filas[0]['n'] . ' próximo(s) paso(s) vencido(s) en los comités', 'index.php?view=acuerdos', $hoy . ' 00:00:00');
            }
        }
    }

    // ---- Agenda: reuniones y eventos de los proximos 3 dias.
    $limite = date('Y-m-d', strtotime('+3 days'));
    $cuando = static fn(string $f): string => $f === $hoy ? 'hoy' : ($f === date('Y-m-d', strtotime('+1 day')) ? 'mañana' : 'el ' . date('d/m/Y', strtotime($f)));
    if (can_view_resource($u, 'reuniones')) {
        foreach ($consulta('SELECT r.id, COALESCE(r.plan_fecha, r.fecha) AS f, c.nombre AS comite FROM reuniones r LEFT JOIN comites c ON c.id = r.comite_id WHERE r.realizada = 0 AND r.cancelada = 0 AND COALESCE(r.plan_fecha, r.fecha) BETWEEN ? AND ? ORDER BY f', [$hoy, $limite]) as $r) {
            $agregar("reunion:{$r['id']}:{$r['f']}", 'ti-calendar-event', 'nueva', 'Reunión ' . $cuando($r['f']), 'index.php?view=reuniones', $r['f'] . ' 00:00:00', $r['comite'] ?: 'Reunión de comité');
        }
    }
    if (can_view_resource($u, 'eventos')) {
        foreach ($consulta('SELECT id, nombre, COALESCE(plan_fecha, fecha) AS f FROM eventos_transferencia WHERE realizada = 0 AND cancelada = 0 AND COALESCE(plan_fecha, fecha) BETWEEN ? AND ? ORDER BY f', [$hoy, $limite]) as $e) {
            $agregar("evento:{$e['id']}:{$e['f']}", 'ti-presentation', 'nueva', 'Evento de transferencia ' . $cuando($e['f']), 'index.php?view=eventos', $e['f'] . ' 00:00:00', $e['nombre']);
        }
    }

    // ---- Redes sociales: conexiones por API con error.
    if (can_manage_resource($u, 'publicaciones')) {
        foreach ($consulta("SELECT proveedor, ultimo_sync, ultimo_mensaje FROM redes_conexiones WHERE activo = 1 AND ultimo_estado = 'error'") as $c) {
            $agregar("redes:{$c['proveedor']}:{$c['ultimo_sync']}", 'ti-plug-x', 'alerta', 'Falló la sincronización de ' . (redes_api_proveedores()[$c['proveedor']]['nombre'] ?? $c['proveedor']), 'index.php?view=publicaciones&tab=conexiones', $c['ultimo_sync'], mb_strimwidth((string) $c['ultimo_mensaje'], 0, 90, '…'));
        }
    }

    usort($avisos, static fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
    return array_slice($avisos, 0, 25);
}

/** Cuatrimestres cuyo limite de entrega cae entre hace 15 dias y dentro de 20: [anio, cuatrimestre, limite]. */
function notif_cuatrimestres_por_vencer(string $hoy): array
{
    $y = (int) substr($hoy, 0, 4);
    $candidatos = [[$y - 1, 3, "$y-01-15"], [$y, 1, "$y-05-15"], [$y, 2, "$y-09-15"], [$y, 3, ($y + 1) . '-01-15']];
    return array_values(array_filter($candidatos, static function ($c) use ($hoy): bool {
        $dias = (strtotime($c[2]) - strtotime($hoy)) / 86400;
        return $dias <= 20 && $dias >= -15;
    }));
}

/** Avisos con su estado de lectura y el total sin leer. */
function notif_obtener(PDO $pdo, array $u): array
{
    $avisos = notif_calcular($pdo, $u);
    $leidas = [];
    if ($avisos) {
        $marcas = implode(',', array_fill(0, count($avisos), '?'));
        $stmt = $pdo->prepare("SELECT clave FROM notificaciones_leidas WHERE usuario_id = ? AND clave IN ($marcas)");
        $stmt->execute([(int) $u['id'], ...array_column($avisos, 'clave')]);
        $leidas = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    foreach ($avisos as &$a) {
        $a['leida'] = isset($leidas[$a['clave']]);
    }
    unset($a);
    return ['items' => $avisos, 'sin_leer' => count(array_filter($avisos, static fn($a) => !$a['leida']))];
}

function notif_marcar(PDO $pdo, array $u, array $claves): void
{
    $stmt = $pdo->prepare('INSERT IGNORE INTO notificaciones_leidas (usuario_id, clave) VALUES (?, ?)');
    foreach ($claves as $clave) {
        $stmt->execute([(int) $u['id'], mb_substr((string) $clave, 0, 190)]);
    }
    // Limpieza: lo leido hace mas de 90 dias ya no puede volver a mostrarse.
    $pdo->prepare('DELETE FROM notificaciones_leidas WHERE usuario_id = ? AND leido_en < ?')->execute([(int) $u['id'], date('Y-m-d', strtotime('-90 days'))]);
}

function notif_hace(string $fecha): string
{
    $s = time() - strtotime($fecha);
    if ($s < 0) {
        $dias = (int) ceil(-$s / 86400);
        return $dias <= 1 ? 'próximamente' : "en $dias días";
    }
    if ($s < 3600) {
        return $s < 60 ? 'hace un momento' : 'hace ' . intdiv($s, 60) . ' min';
    }
    if ($s < 86400) {
        return intdiv($s, 3600) === 1 ? 'hace 1 hora' : 'hace ' . intdiv($s, 3600) . ' horas';
    }
    $dias = intdiv($s, 86400);
    return $dias < 7 ? ($dias === 1 ? 'ayer' : "hace $dias días") : date('d/m/Y', strtotime($fecha));
}
