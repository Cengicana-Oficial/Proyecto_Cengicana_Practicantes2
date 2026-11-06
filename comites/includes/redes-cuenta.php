<?php
declare(strict_types=1);

// Metricas mensuales de cada cuenta (pagina de Facebook, LinkedIn, Instagram...):
// alcance total, organico y pagado, seguidores nuevos y totales, e inversion en
// pauta. Son los datos de los informes ejecutivos mensuales; con ellos se calcula
// el % organico, el costo por cada 1,000 personas alcanzadas y por seguidor nuevo.

const REDES_CUENTA_PLATAFORMAS = ['Facebook', 'Instagram', 'LinkedIn', 'YouTube', 'TikTok', 'X'];
const REDES_CUENTA_CAMPOS = [
    'alcance_total' => 'Alcance total', 'alcance_organico' => 'Alcance orgánico', 'alcance_pagado' => 'Alcance pagado',
    'impresiones' => 'Impresiones', 'seguidores_total' => 'Seguidores totales', 'seguidores_nuevos' => 'Seguidores nuevos',
    'visitas_perfil' => 'Visitas a la página',
];

/** Guarda (o reemplaza) el mes de una cuenta desde el formulario. */
function redes_cuenta_guardar(PDO $pdo, array $d): string
{
    $plataforma = in_array($d['plataforma'] ?? '', REDES_CUENTA_PLATAFORMAS, true) ? (string) $d['plataforma'] : '';
    $periodo = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) ($d['periodo'] ?? '')) ? (string) $d['periodo'] : '';
    if ($plataforma === '' || $periodo === '') {
        throw new RuntimeException('Indica la plataforma y el mes.');
    }
    $v = [];
    foreach (array_keys(REDES_CUENTA_CAMPOS) as $campo) {
        $v[$campo] = max(0, (int) round((float) str_replace([',', ' '], '', (string) ($d[$campo] ?? '0'))));
    }
    // Si solo se conoce el total y el organico (o el pagado), se completa el que falta.
    if ($v['alcance_total'] && $v['alcance_organico'] && !$v['alcance_pagado']) {
        $v['alcance_pagado'] = max(0, $v['alcance_total'] - $v['alcance_organico']);
    } elseif ($v['alcance_total'] && $v['alcance_pagado'] && !$v['alcance_organico']) {
        $v['alcance_organico'] = max(0, $v['alcance_total'] - $v['alcance_pagado']);
    } elseif (!$v['alcance_total']) {
        $v['alcance_total'] = $v['alcance_organico'] + $v['alcance_pagado'];
    }
    $q = max(0, round((float) str_replace(',', '', (string) ($d['inversion_q'] ?? '0')), 2));
    $usd = max(0, round((float) str_replace(',', '', (string) ($d['inversion_usd'] ?? '0')), 2));
    $tc = (float) str_replace(',', '', (string) ($d['tipo_cambio'] ?? '0'));
    if ($q && !$usd && $tc > 0) {
        $usd = round($q / $tc, 2);
    }
    $pdo->prepare(
        'INSERT INTO redes_cuenta_mensual (plataforma, periodo, alcance_total, alcance_organico, alcance_pagado, impresiones, seguidores_total,
            seguidores_nuevos, visitas_perfil, inversion_q, inversion_usd, notas, fuente)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE alcance_total = VALUES(alcance_total), alcance_organico = VALUES(alcance_organico), alcance_pagado = VALUES(alcance_pagado),
            impresiones = VALUES(impresiones), seguidores_total = VALUES(seguidores_total), seguidores_nuevos = VALUES(seguidores_nuevos),
            visitas_perfil = VALUES(visitas_perfil), inversion_q = VALUES(inversion_q), inversion_usd = VALUES(inversion_usd), notas = VALUES(notas), fuente = VALUES(fuente)'
    )->execute([$plataforma, $periodo, ...array_values($v), $q, $usd, trim((string) ($d['notas'] ?? '')) ?: null, 'Registro manual']);
    return "Métricas de $plataforma para " . estadisticas_fecha_corta($periodo . '-01') . ' guardadas.';
}

/**
 * Meses de cada cuenta en el periodo, con totales, comparacion contra el periodo
 * anterior y metricas derivadas. Devuelve [] si la tabla aun no existe.
 */
function redes_cuenta_datos(PDO $pdo, array $periodo, ?array $anterior = null): array
{
    $leer = static function (?array $p) use ($pdo): array {
        if ($p === null) {
            return [];
        }
        $stmt = $pdo->prepare('SELECT * FROM redes_cuenta_mensual' . ($p['desde'] !== null ? ' WHERE periodo BETWEEN ? AND ?' : '') . ' ORDER BY periodo, plataforma');
        $stmt->execute($p['desde'] !== null ? [$p['desde_mes'], $p['hasta_mes']] : []);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };
    try {
        $filas = $leer($periodo);
        $previas = $leer($anterior);
    } catch (PDOException $e) {
        return ['filas' => [], 'plataformas' => [], 'total' => null, 'previo' => null];
    }
    $sumar = static function (array $filas): ?array {
        if (!$filas) {
            return null;
        }
        $t = ['meses' => count(array_unique(array_column($filas, 'periodo')))];
        foreach (['alcance_total', 'alcance_organico', 'alcance_pagado', 'impresiones', 'seguidores_nuevos', 'visitas_perfil', 'inversion_q', 'inversion_usd'] as $c) {
            $t[$c] = array_sum(array_map('floatval', array_column($filas, $c)));
        }
        // Seguidores totales: el ultimo valor registrado de cada plataforma.
        $ultimo = [];
        foreach ($filas as $f) {
            if ((int) $f['seguidores_total'] > 0) {
                $ultimo[$f['plataforma']] = (int) $f['seguidores_total'];
            }
        }
        $t['seguidores_total'] = array_sum($ultimo);
        return redes_cuenta_derivadas($t);
    };
    $porPlataforma = [];
    foreach ($filas as $f) {
        $porPlataforma[$f['plataforma']][] = $f;
    }
    $plats = [];
    foreach ($porPlataforma as $nombre => $fs) {
        $previasPlat = array_values(array_filter($previas, static fn($f) => $f['plataforma'] === $nombre));
        $plats[$nombre] = ['total' => $sumar($fs), 'previo' => $sumar($previasPlat), 'meses' => array_map('redes_cuenta_derivadas', $fs)];
    }
    return ['filas' => array_map('redes_cuenta_derivadas', $filas), 'plataformas' => $plats, 'total' => $sumar($filas), 'previo' => $sumar($previas)];
}

/** Agrega % organico, costo por mil alcanzados (CPM) y costo por seguidor nuevo. */
function redes_cuenta_derivadas(array $f): array
{
    $total = (float) $f['alcance_total'];
    $q = (float) $f['inversion_q'];
    $f['pct_organico'] = $total ? (float) $f['alcance_organico'] / $total * 100 : null;
    $f['cpm_q'] = $q && (float) $f['alcance_pagado'] ? $q / (float) $f['alcance_pagado'] * 1000 : null;
    $f['costo_seguidor_q'] = $q && (float) $f['seguidores_nuevos'] ? $q / (float) $f['seguidores_nuevos'] : null;
    return $f;
}

function redes_quetzales(?float $v): string
{
    return $v === null ? '—' : 'Q' . number_format($v, 2);
}
