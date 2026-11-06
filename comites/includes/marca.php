<?php
declare(strict_types=1);
/**
 * Identidad visual de CENGICAÑA para los formatos que se comparten, imprimen o descargan
 * (planificación, directorio, informes, estadísticas y redes).
 * Paleta: Verde principal #73BC25, Verde secundario #A3D300, Amarillo #FFCC00, Naranja #FF6B00, Gris #CED2D5.
 * Los colores de la paleta son claros: con texto blanco no alcanzan el contraste mínimo, así que
 * los encabezados usan su tono claro ("tinte") con texto oscuro ("texto") y borde del color.
 */
const MARCA_OSCURO = '#1F5632';
const MARCA_TEXTO = '#2B3328';
const MARCA_GRIS = '#CED2D5';
const MARCA_COLORES = [
    'verde' => ['nombre' => 'Verde principal', 'color' => '#73BC25', 'tinte' => '#EEF7E4', 'texto' => '#2F5A0B'],
    'verde2' => ['nombre' => 'Verde secundario', 'color' => '#A3D300', 'tinte' => '#F5FAD9', 'texto' => '#4A5C00'],
    'amarillo' => ['nombre' => 'Amarillo', 'color' => '#FFCC00', 'tinte' => '#FFF8DB', 'texto' => '#6B5500'],
    'naranja' => ['nombre' => 'Naranja', 'color' => '#FF6B00', 'tinte' => '#FFF0E5', 'texto' => '#9A3F00'],
    'gris' => ['nombre' => 'Gris', 'color' => '#CED2D5', 'tinte' => '#F4F5F6', 'texto' => '#3D4247'],
];
// Estados de un registro (planificación) con colores de la paleta.
const MARCA_ESTADOS = [
    'realizada' => ['#EEF7E4', '#2F5A0B'], 'planificada' => ['#F5FAD9', '#4A5C00'], 'reprogramada' => ['#FFF8DB', '#6B5500'],
    'sin_registrar' => ['#FFF0E5', '#9A3F00'], 'cancelada' => ['#F4F5F6', '#5F666C'],
];

/** Color elegido en "Diseño"; los nombres anteriores (azul, café, violeta) caen en verde. */
function marca_color(?string $clave): array
{
    return MARCA_COLORES[$clave ?? ''] ?? MARCA_COLORES['verde'];
}

/** Franja con los cuatro colores de la marca (tabla: Dompdf no maneja bien flexbox ni degradados). */
function marca_franja(): string
{
    return '<table class="marca-franja" style="width:100%;border-collapse:collapse;table-layout:fixed;margin:0"><tr>'
        . '<td style="width:40%;height:5px;padding:0;border:0;background:#73BC25"></td><td style="width:25%;height:5px;padding:0;border:0;background:#A3D300"></td>'
        . '<td style="width:20%;height:5px;padding:0;border:0;background:#FFCC00"></td><td style="width:15%;height:5px;padding:0;border:0;background:#FF6B00"></td></tr></table>';
}

/** Logo de CENGICAÑA incrustado (los PDF se generan sin acceso a recursos remotos). */
function marca_logo(int $alto = 42): string
{
    static $uri = null;
    if ($uri === null) {
        $ruta = __DIR__ . '/../assets/img/cengicana-logo.png';
        $uri = is_file($ruta) ? 'data:image/png;base64,' . base64_encode((string) file_get_contents($ruta)) : '';
    }
    return $uri ? '<img src="' . $uri . '" alt="CENGICAÑA" style="height:' . $alto . 'px;max-width:170px">' : '';
}

/** Reglas comunes: títulos en verde oscuro, tablas con encabezado en tono claro y líneas grises. */
function marca_css(array $c): string
{
    return 'body{color:' . MARCA_TEXTO . '}h1{color:' . MARCA_OSCURO . '}h2{color:' . MARCA_OSCURO . '}'
        . 'th{background:' . $c['tinte'] . ';color:' . $c['texto'] . ';border-bottom:2px solid ' . $c['color'] . '}'
        . 'td,th{border-color:' . MARCA_GRIS . '}small,.muted{color:#5F666C}';
}

/** Opciones de "Diseño" para los formularios de compartir. */
function marca_opciones(): array
{
    return array_map(static fn($c) => $c['nombre'], MARCA_COLORES);
}
