<?php
declare(strict_types=1);

// Metricas mensuales de la cuenta por API (se llama desde la sincronizacion de
// cada conexion): alcance total y pagado de la pagina, seguidores nuevos y
// totales, e inversion en pauta desde la cuenta publicitaria de Meta. Cada
// dato se pide aparte: si Meta o LinkedIn no lo entregan, se omite sin afectar
// el resto. Solo se actualizan los campos que la API devolvio, de modo que lo
// registrado a mano en los demas campos se conserva.

/** Ultimos 3 meses (incluido el actual): [YYYY-MM => [inicio, fin exclusivo]]. */
function redes_cuenta_meses_recientes(int $cantidad = 3): array
{
    $meses = [];
    $hoy = new DateTimeImmutable('today');
    for ($i = $cantidad - 1; $i >= 0; $i--) {
        $inicio = $hoy->modify('first day of this month')->modify("-$i months");
        $fin = min($inicio->modify('first day of next month'), $hoy->modify('+1 day'));
        $meses[$inicio->format('Y-m')] = [$inicio, $fin];
    }
    return $meses;
}

/** Inserta o actualiza solo los campos entregados por la API. */
function redes_cuenta_guardar_api(PDO $pdo, string $plataforma, string $periodo, array $campos, string $fuente): void
{
    $campos = array_filter($campos, static fn($v) => $v !== null);
    if (!$campos) {
        return;
    }
    $permitidos = ['alcance_total', 'alcance_organico', 'alcance_pagado', 'impresiones', 'seguidores_total', 'seguidores_nuevos', 'visitas_perfil', 'inversion_q', 'inversion_usd'];
    $campos = array_intersect_key($campos, array_flip($permitidos));
    $cols = array_keys($campos);
    $pdo->prepare(
        'INSERT INTO redes_cuenta_mensual (plataforma, periodo, ' . implode(', ', $cols) . ', fuente) VALUES (?, ?, ' . implode(', ', array_fill(0, count($cols), '?')) . ', ?)
         ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(static fn($c) => "$c = VALUES($c)", $cols)) . ', fuente = VALUES(fuente)'
    )->execute([$plataforma, $periodo, ...array_values($campos), $fuente]);
    // Con total y pagado conocidos, el organico es la diferencia.
    $pdo->prepare('UPDATE redes_cuenta_mensual SET alcance_organico = GREATEST(alcance_total - alcance_pagado, 0)
                   WHERE plataforma = ? AND periodo = ? AND alcance_total > 0 AND alcance_pagado > 0')->execute([$plataforma, $periodo]);
}

/** Serie de valores de una metrica de insights de Meta; null si la version de la API ya no la ofrece. */
function redes_meta_valores(string $version, string $objeto, string $metrica, array $params, string $token): ?array
{
    try {
        $json = redes_meta_get($version, "$objeto/insights", ['metric' => $metrica] + $params, $token);
    } catch (RuntimeException $e) {
        return null;
    }
    $d = $json['data'][0] ?? null;
    if (!$d) {
        return null;
    }
    if (isset($d['total_value']['value'])) {
        return [(int) $d['total_value']['value']];
    }
    return array_map(static fn($v) => is_array($v['value'] ?? null) ? array_sum($v['value']) : (int) ($v['value'] ?? 0), $d['values'] ?? []);
}

/** Primera metrica candidata que responda (Meta renombra metricas entre versiones). */
function redes_meta_primera(string $version, string $objeto, array $candidatas, array $params, string $token): ?array
{
    foreach ($candidatas as $m) {
        $v = redes_meta_valores($version, $objeto, $m, $params, $token);
        if ($v !== null) {
            return $v;
        }
    }
    return null;
}

/** Facebook, Instagram y pauta de Meta. Devuelve un resumen para el mensaje de sincronizacion. */
function redes_meta_cuenta(PDO $pdo, array $cfg, array $sec, string $ig): string
{
    $v = $cfg['version'] ?: 'v23.0';
    $token = (string) ($sec['token'] ?? '');
    $pagina = (string) $cfg['page_id'];
    $logrado = [];
    $meses = redes_cuenta_meses_recientes();
    $mesActual = array_key_last($meses);

    // Facebook: alcance de 28 dias al cierre de cada mes (aprox. el mes), pagado y seguidores nuevos por dia.
    foreach ($meses as $mes => [$ini, $fin]) {
        $cierre = ['period' => 'days_28', 'since' => $fin->modify('-1 day')->getTimestamp(), 'until' => $fin->getTimestamp()];
        $dias = ['period' => 'day', 'since' => $ini->getTimestamp(), 'until' => $fin->getTimestamp()];
        $total = redes_meta_primera($v, $pagina, ['page_impressions_unique', 'page_total_media_view_unique'], $cierre, $token);
        $pagado = redes_meta_primera($v, $pagina, ['page_impressions_paid_unique'], $cierre, $token);
        $nuevos = redes_meta_primera($v, $pagina, ['page_daily_follows_unique', 'page_fan_adds_unique', 'page_fan_adds'], $dias, $token);
        $campos = [
            'alcance_total' => $total !== null && $total ? (int) end($total) : null,
            'alcance_pagado' => $pagado !== null && $pagado ? (int) end($pagado) : null,
            'seguidores_nuevos' => $nuevos !== null ? array_sum($nuevos) : null,
        ];
        if ($mes === $mesActual) {
            try {
                $info = redes_meta_get($v, $pagina, ['fields' => 'followers_count,fan_count'], $token);
                $campos['seguidores_total'] = (int) ($info['followers_count'] ?? $info['fan_count'] ?? 0) ?: null;
            } catch (RuntimeException $e) {
            }
        }
        redes_cuenta_guardar_api($pdo, 'Facebook', $mes, $campos, 'Facebook (API)');
        if (array_filter($campos, static fn($x) => $x !== null)) {
            $logrado['Facebook'] = true;
        }
    }

    // Instagram: alcance del periodo (la API limita a 30 dias por consulta) y seguidores.
    if ($ig !== '') {
        $hace30 = (new DateTimeImmutable('today'))->modify('-29 days');
        foreach ($meses as $mes => [$ini, $fin]) {
            $desde = max($ini, $fin->modify('-30 days'));
            $alcance = redes_meta_valores($v, $ig, 'reach', ['period' => 'day', 'metric_type' => 'total_value', 'since' => $desde->getTimestamp(), 'until' => $fin->getTimestamp()], $token);
            $campos = ['alcance_total' => $alcance ? (int) $alcance[0] : null];
            // Seguidores nuevos por dia solo existen para los ultimos 30 dias.
            if ($fin > $hace30) {
                $nuevos = redes_meta_valores($v, $ig, 'follower_count', ['period' => 'day', 'since' => max($ini, $hace30)->getTimestamp(), 'until' => $fin->getTimestamp()], $token);
                $campos['seguidores_nuevos'] = $nuevos !== null ? array_sum($nuevos) : null;
            }
            if ($mes === $mesActual) {
                try {
                    $campos['seguidores_total'] = (int) (redes_meta_get($v, $ig, ['fields' => 'followers_count'], $token)['followers_count'] ?? 0) ?: null;
                } catch (RuntimeException $e) {
                }
            }
            redes_cuenta_guardar_api($pdo, 'Instagram', $mes, $campos, 'Instagram (API)');
            if (array_filter($campos, static fn($x) => $x !== null)) {
                $logrado['Instagram'] = true;
            }
        }
    }

    $aviso = $logrado ? ' Métricas de la cuenta: ' . implode(', ', array_keys($logrado)) . '.' : ' Meta no entregó métricas mensuales de la página (revise los permisos read_insights / instagram_manage_insights).';
    if (!empty($cfg['ad_account_id'])) {
        try {
            $aviso .= redes_meta_pauta($pdo, $v, (string) $cfg['ad_account_id'], (string) ($sec['ads_token'] ?? '') ?: $token, $meses);
        } catch (Throwable $e) {
            $aviso .= ' Pauta no disponible: ' . $e->getMessage();
        }
    }
    return $aviso;
}

/**
 * Importe gastado por mes (separado en Facebook e Instagram) y por publicacion
 * pautada, desde la cuenta publicitaria. El monto queda en Q o US$ segun la
 * moneda de la cuenta.
 */
function redes_meta_pauta(PDO $pdo, string $v, string $cuenta, string $token, array $meses): string
{
    $moneda = strtoupper((string) (redes_meta_get($v, $cuenta, ['fields' => 'currency'], $token)['currency'] ?? 'USD'));
    $campoMoneda = $moneda === 'GTQ' ? 'inversion_q' : 'inversion_usd';
    $total = 0.0;
    foreach ($meses as $mes => [$ini, $fin]) {
        $json = redes_meta_get($v, "$cuenta/insights", [
            'fields' => 'spend', 'level' => 'account', 'breakdowns' => 'publisher_platform',
            'time_range' => json_encode(['since' => $ini->format('Y-m-d'), 'until' => $fin->modify('-1 day')->format('Y-m-d')]),
        ], $token);
        $porRed = ['Facebook' => 0.0, 'Instagram' => 0.0];
        foreach ($json['data'] ?? [] as $fila) {
            $red = ($fila['publisher_platform'] ?? '') === 'instagram' ? 'Instagram' : 'Facebook';
            $porRed[$red] += (float) ($fila['spend'] ?? 0);
        }
        foreach ($porRed as $red => $monto) {
            if ($monto > 0) {
                redes_cuenta_guardar_api($pdo, $red, $mes, [$campoMoneda => round($monto, 2)], $red . ' (API)');
                $total += $monto;
            }
        }
    }

    // Gasto por publicacion: el anuncio apunta a la publicacion (story id en Facebook, media id en Instagram).
    $desde = reset($meses)[0]->format('Y-m-d');
    $hasta = (new DateTimeImmutable('today'))->format('Y-m-d');
    $json = redes_meta_get($v, "$cuenta/ads", [
        'fields' => 'creative{effective_object_story_id,effective_instagram_media_id},insights.time_range(' . json_encode(['since' => $desde, 'until' => $hasta]) . '){spend}',
        'limit' => 200,
    ], $token);
    $porPost = [];
    foreach ($json['data'] ?? [] as $ad) {
        $gasto = (float) ($ad['insights']['data'][0]['spend'] ?? 0);
        foreach (['effective_object_story_id', 'effective_instagram_media_id'] as $k) {
            if (!empty($ad['creative'][$k]) && $gasto > 0) {
                $porPost[(string) $ad['creative'][$k]] = ($porPost[(string) $ad['creative'][$k]] ?? 0) + $gasto;
            }
        }
    }
    $stmt = $pdo->prepare('UPDATE publicaciones SET pautada = 1, inversion = ? WHERE externo_id = ?');
    $marcadas = 0;
    foreach ($porPost as $externo => $gasto) {
        // La columna inversion esta en quetzales: solo se llena cuando la cuenta factura en GTQ.
        $stmt->execute([$moneda === 'GTQ' ? round($gasto, 2) : 0, $externo]);
        $marcadas += $stmt->rowCount();
    }
    return ' Pauta: ' . number_format($total, 2) . " $moneda en " . count($meses) . ' meses' . ($marcadas ? ", $marcadas publicación(es) marcadas como pautadas" : '') . '.';
}

/** LinkedIn: seguidores nuevos y alcance mensual de la pagina, y seguidores totales. */
function redes_linkedin_cuenta(PDO $pdo, string $org, string $token, string $version): string
{
    $meses = redes_cuenta_meses_recientes();
    $inicio = reset($meses)[0]->getTimestamp() * 1000;
    $fin = (new DateTimeImmutable('tomorrow'))->getTimestamp() * 1000;
    $intervalo = "(timeRange:(start:$inicio,end:$fin),timeGranularityType:MONTH)";
    $porMes = [];
    try {
        $seg = redes_linkedin_get('https://api.linkedin.com/rest/organizationalEntityFollowerStatistics?q=organizationalEntity&organizationalEntity=' . rawurlencode($org) . '&timeIntervals=' . $intervalo, $token, $version);
        foreach ($seg['elements'] ?? [] as $e) {
            $mes = date('Y-m', intdiv((int) ($e['timeRange']['start'] ?? 0), 1000));
            $g = $e['followerGains'] ?? [];
            $porMes[$mes]['seguidores_nuevos'] = (int) ($g['organicFollowerGain'] ?? 0) + (int) ($g['paidFollowerGain'] ?? 0);
        }
    } catch (RuntimeException $e) {
    }
    try {
        $alc = redes_linkedin_get('https://api.linkedin.com/rest/organizationalEntityShareStatistics?q=organizationalEntity&organizationalEntity=' . rawurlencode($org) . '&timeIntervals=' . $intervalo, $token, $version);
        foreach ($alc['elements'] ?? [] as $e) {
            $mes = date('Y-m', intdiv((int) ($e['timeRange']['start'] ?? 0), 1000));
            $s = $e['totalShareStatistics'] ?? [];
            $alcance = (int) ($s['uniqueImpressionsCount'] ?? 0) ?: (int) ($s['impressionCount'] ?? 0);
            $porMes[$mes] = ($porMes[$mes] ?? []) + ['alcance_total' => $alcance, 'alcance_organico' => $alcance, 'impresiones' => (int) ($s['impressionCount'] ?? 0)];
        }
    } catch (RuntimeException $e) {
    }
    try {
        $red = redes_linkedin_get('https://api.linkedin.com/rest/networkSizes/' . rawurlencode($org) . '?edgeType=COMPANY_FOLLOWED_BY_MEMBER', $token, $version);
        $porMes[date('Y-m')]['seguidores_total'] = (int) ($red['firstDegreeSize'] ?? 0) ?: null;
    } catch (RuntimeException $e) {
    }
    foreach ($porMes as $mes => $campos) {
        if (isset($meses[$mes])) {
            redes_cuenta_guardar_api($pdo, 'LinkedIn', $mes, $campos, 'LinkedIn (API)');
        }
    }
    return $porMes ? ' Métricas de la cuenta actualizadas.' : ' LinkedIn no entregó métricas de la página (revise el permiso r_organization_social / rw_organization_admin).';
}
