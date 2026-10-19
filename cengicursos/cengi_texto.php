<?php
/**
 * Funciones sin dependencias de sesion ni de permisos, compartidas por revisar_permisos.php
 * (paginas con login) y por las paginas publicas y el cron (inscripcion.php,
 * guardar_inscripcion.php, cron_comunicaciones.php via planificacion_helpers.php), que no
 * pueden incluir revisar_permisos.php porque este redirige al login.
 */

// Nota final minima para aprobar un curso (post-evaluacion / promedio de modulos).
// Unico lugar donde se define: lo usan Seguimiento por curso, Participantes por curso,
// Certificacion, constancia de notas, Pagos de inscripcion e Informe de gestion.
const CENGI_NOTA_APROBACION = 70;

function cengi_texto_normalizado($valor)
{
    $valor = trim((string) $valor);
    $valor = mb_strtolower($valor, 'UTF-8');
    $valor = strtr($valor, [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ñ' => 'n',
        'Á' => 'a',
        'É' => 'e',
        'Í' => 'i',
        'Ó' => 'o',
        'Ú' => 'u',
        'Ñ' => 'n',
    ]);

    return preg_replace('/\s+/', '', $valor);
}

function cengi_usuario_actual_id()
{
    return isset($_SESSION['id_usuario']) ? (int) $_SESSION['id_usuario'] : 0;
}

/** Numero a letras para los recibos (ppLetras). */
function cengi_acu_letras($n)
{
    $n = round((float) $n, 2);
    $ent = (int) floor($n);
    $cent = (int) round(($n - $ent) * 100);
    $U = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve',
        'veinte', 'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve'];
    $D = ['', '', '', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
    $C = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];
    $c3 = static function ($x) use ($U, $D, $C) {
        if ($x === 0) {
            return '';
        }
        if ($x === 100) {
            return 'cien';
        }
        $t = $C[intdiv($x, 100)];
        $r = $x % 100;
        if ($r) {
            $t .= ($t ? ' ' : '') . ($r < 30 ? $U[$r] : $D[intdiv($r, 10)] . ($r % 10 ? ' y ' . $U[$r % 10] : ''));
        }
        return $t;
    };
    $mill = intdiv($ent, 1000000);
    $mil = intdiv($ent, 1000) % 1000;
    $t = '';
    if ($mill) {
        $t .= ($mill === 1 ? 'un millón' : preg_replace('/uno$/', 'un', $c3($mill)) . ' millones') . ' ';
    }
    if ($mil) {
        $t .= ($mil === 1 ? 'mil' : preg_replace('/uno$/', 'un', $c3($mil)) . ' mil') . ' ';
    }
    $t = trim($t . $c3($ent % 1000)) ?: 'cero';
    $t = preg_replace(['/veintiuno$/', '/ uno$/', '/^uno$/'], ['veintiún', ' un', 'un'], $t);
    if (preg_match('/(millón|millones)$/', $t)) {
        $t .= ' de';
    }
    return mb_strtoupper(mb_substr($t, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($t, 1, null, 'UTF-8') . ($ent === 1 ? ' quetzal ' : ' quetzales ') . ($cent ? 'con ' . str_pad((string) $cent, 2, '0', STR_PAD_LEFT) . '/100' : 'exactos');
}
