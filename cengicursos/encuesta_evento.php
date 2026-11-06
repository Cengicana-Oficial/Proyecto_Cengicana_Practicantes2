<?php
/**
 * Encuesta publica de un evento (prototipo SIGEC v29, "Encuestas y evaluacion"):
 *
 *   ?t=<evento_aliados.token>          encuesta de colaboracion de una empresa aliada
 *   ?t=<eventos.token_percepcion>      percepcion de participantes, QR / enlace general (anonima)
 *   ?t=<evento_participantes.token_encuesta>  percepcion, enlace personal de un asistente
 *
 * Los enlaces se generan y envian desde "Encuestas y evaluacion" en eventos_qr.php.
 */
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/eventos_helpers.php';

$db = conectar();
$token = strtolower(trim((string) ($_GET['t'] ?? $_POST['t'] ?? '')));

$tipo = null;        // 'colab' | 'percep'
$evento = null;
$aliado = null;
$participante = null;
$yaRespondida = false;

if (preg_match('/^[a-f0-9]{32}$/', $token)) {
    $stmt = $db->prepare('SELECT a.*, e.id AS evento_id FROM evento_aliados a INNER JOIN eventos e ON e.id = a.evento_id WHERE a.token = ? LIMIT 1');
    $stmt->execute([$token]);
    $aliado = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($aliado) {
        $tipo = 'colab';
        $eventoId = (int) $aliado['evento_id'];
        $stmt = $db->prepare('SELECT COUNT(*) FROM evento_encuesta_colab WHERE aliado_id = ?');
        $stmt->execute([(int) $aliado['id']]);
        $yaRespondida = (int) $stmt->fetchColumn() > 0;
    } else {
        $stmt = $db->prepare('SELECT id FROM eventos WHERE token_percepcion = ? LIMIT 1');
        $stmt->execute([$token]);
        $eventoId = (int) ($stmt->fetchColumn() ?: 0);
        if ($eventoId > 0) {
            $tipo = 'percep';
        } else {
            $stmt = $db->prepare('SELECT id, evento_id, nombre_invitado FROM evento_participantes WHERE token_encuesta = ? LIMIT 1');
            $stmt->execute([$token]);
            $participante = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($participante) {
                $tipo = 'percep';
                $eventoId = (int) $participante['evento_id'];
                $stmt = $db->prepare('SELECT COUNT(*) FROM evento_encuesta_percep WHERE evento_participante_id = ?');
                $stmt->execute([(int) $participante['id']]);
                $yaRespondida = (int) $stmt->fetchColumn() > 0;
            }
        }
    }
    if ($tipo !== null) {
        $stmt = $db->prepare('SELECT id, nombre, fecha, color, encuesta_formato FROM eventos WHERE id = ?');
        $stmt->execute([$eventoId]);
        $evento = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($evento === null) {
            $tipo = null;
        }
    }
}

function cengi_enc_html($valor)
{
    return htmlspecialchars((string) ($valor ?? ''), ENT_QUOTES, 'UTF-8');
}

function cengi_enc_fecha($fecha)
{
    $ts = $fecha ? strtotime((string) $fecha) : false;
    if ($ts === false) {
        return 'Fecha por confirmar';
    }
    $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    return date('d', $ts) . ' ' . $meses[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
}

$errores = [];
$enviado = false;
$v = [];
// Formato de la encuesta de percepcion; por el enlace general de un formato de transferencia
// se piden los datos de quien responde para registrar su asistencia.
$formato = $tipo === 'percep' && isset(CENGI_EVT_ENCUESTA_FORMATOS[$evento['encuesta_formato'] ?? '']) ? $evento['encuesta_formato'] : 'general';
$identifica = $tipo === 'percep' && $formato !== 'general' && !$participante;
// Busqueda del correo mientras se llena la encuesta: solo primer nombre e institucion.
if ($identifica && isset($_GET['correo'])) {
    $conocido = cengi_evento_buscar_por_correo($db, (int) $evento['id'], (string) $_GET['correo']);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($conocido ? ['encontrado' => true, 'nombre' => explode(' ', $conocido['nombre'])[0], 'institucion' => $conocido['institucion']] : ['encontrado' => false], JSON_UNESCAPED_UNICODE);
    exit;
}
$preguntaGeneral = $formato === 'investigacion' ? '¿Cómo califica los resultados de investigación presentados?'
    : ($formato === 'transferencia' ? '¿Cómo califica el evento?' : 'En general, ¿cómo calificas el evento?');

if ($tipo !== null && !$yaRespondida && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $texto = static function ($campo, $max) {
        $valor = trim((string) ($_POST[$campo] ?? ''));
        // Un cliente que no envie UTF-8 (p. ej. Windows-1252) no debe hacer fallar el INSERT.
        if (!mb_check_encoding($valor, 'UTF-8')) {
            $valor = mb_convert_encoding($valor, 'UTF-8', 'Windows-1252');
        }
        return mb_substr($valor, 0, $max, 'UTF-8');
    };
    $nota = static function ($campo, $admiteNa = false) {
        $valor = (string) ($_POST[$campo] ?? '');
        if ($admiteNa && $valor === 'na') {
            return 'na';
        }
        $n = (int) $valor;
        return ($n >= 1 && $n <= 5 && (string) $n === $valor) ? $n : null;
    };

    if ($tipo === 'percep') {
        $v['estrellas'] = $nota('estrellas');
        $v['mejora'] = $texto('mejora', 2000);
        $v['logistica'] = $formato === 'investigacion' ? $nota('logistica') : null;
        $v['permanencia'] = $formato === 'transferencia' && isset(CENGI_EVT_PERMANENCIA[$_POST['permanencia'] ?? '']) ? $_POST['permanencia'] : '';
        foreach (['nombre' => 255, 'correo' => 255, 'institucion' => 255, 'cargo' => 150, 'area' => 150] as $campo => $max) {
            $v[$campo] = $identifica ? $texto($campo, $max) : '';
        }
        $v['nombre'] = preg_replace('/\s+/u', ' ', $v['nombre']);
        if ($v['correo'] !== '' && !filter_var($v['correo'], FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'El correo electrónico no es válido.';
        }
        // Correo ya registrado: el nombre y la institucion se toman del registro.
        $conocido = $identifica && $v['correo'] !== '' ? cengi_evento_buscar_por_correo($db, (int) $evento['id'], $v['correo']) : null;
        if ($conocido) {
            $v['nombre'] = mb_substr($conocido['nombre'], 0, 255, 'UTF-8');
            $v['institucion'] = $v['institucion'] !== '' ? $v['institucion'] : $conocido['institucion'];
            $v['participante_id'] = $conocido['participante_id'];
        }
        if ($identifica && mb_strlen($v['nombre'], 'UTF-8') < 5) {
            $errores[] = $v['correo'] !== '' && !$conocido ? 'No encontramos tu correo: escribe tu nombre y apellido.' : 'Escribe tu correo o tu nombre y apellido.';
        }
        if ($identifica && $v['institucion'] === '' && !$conocido) {
            $errores[] = $formato === 'investigacion' ? 'Indica tu institución.' : 'Indica tu ingenio o institución.';
        }
        if ($v['estrellas'] === null) {
            $errores[] = 'Califica: ' . $preguntaGeneral;
        }
        if ($formato === 'investigacion' && $v['logistica'] === null) {
            $errores[] = 'Califica la logística del evento.';
        }
        if ($formato === 'transferencia' && $v['permanencia'] === '') {
            $errores[] = 'Indica tu permanencia en el evento.';
        }
        if (!$errores) {
            try {
                $db->beginTransaction();
                $participanteId = $participante ? (int) $participante['id'] : null;
                if ($identifica) {
                    $v['nombre'] = cengi_texto_propio($v['nombre']);
                    $v['cargo'] = cengi_texto_cargo($v['cargo']);
                    $v['institucion'] = cengi_institucion_normalizar($db, $v['institucion'])['institucion'];
                    // Asistencia registrada por la encuesta (inscrito sin ingreso o persona nueva).
                    $participanteId = cengi_evento_asistencia_encuesta($db, (int) $evento['id'], $v);
                }
                $db->prepare('INSERT INTO evento_encuesta_percep (evento_id, evento_participante_id, via, estrellas, mejora, nombre, correo, institucion, cargo, area, logistica, permanencia)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([
                        (int) $evento['id'],
                        $participanteId,
                        $participante ? 'enlace personal' : 'QR / enlace general',
                        $v['estrellas'],
                        $v['mejora'] !== '' ? $v['mejora'] : null,
                        $v['nombre'] ?: null, $v['correo'] !== '' ? strtolower($v['correo']) : null, $v['institucion'] ?: null,
                        $v['cargo'] ?: null, $v['area'] ?: null, $v['logistica'], $v['permanencia'] ?: null,
                    ]);
                $db->commit();
                $enviado = true;
            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                if ((string) $e->getCode() === '23000') {
                    $yaRespondida = true;
                } else {
                    error_log('Encuesta de percepcion, evento ' . $evento['id'] . ': ' . $e->getMessage());
                    $errores[] = 'No fue posible guardar tu respuesta. Intenta nuevamente.';
                }
            }
        }
    } else {
        $v['empresa'] = $texto('empresa', 255);
        $v['nombre'] = $texto('nombre', 255);
        $v['fortalecio'] = in_array($_POST['fortalecio'] ?? '', ['si', 'parcialmente', 'no'], true) ? $_POST['fortalecio'] : '';
        $v['participaria'] = in_array($_POST['participaria'] ?? '', ['si', 'no'], true) ? $_POST['participaria'] : '';
        $v['mejorar'] = $texto('mejorar', 2000);
        $v['apoyo'] = $texto('apoyo', 2000);
        $faltan = [];
        foreach (CENGI_EVT_COLAB_ITEMS as $item) {
            $v[$item['k']] = $nota($item['k'], $item['na']);
            if ($v[$item['k']] === null) {
                $faltan[] = $item['t'];
            }
        }
        if ($v['empresa'] === '') {
            $errores[] = 'Indique el nombre de la empresa.';
        }
        if ($faltan) {
            $errores[] = 'Califique: ' . implode('; ', $faltan) . '.';
        }
        if ($v['fortalecio'] === '') {
            $errores[] = 'Responda si la participación del Centro fortaleció el evento.';
        }
        if ($v['participaria'] === '') {
            $errores[] = 'Indique si participaría nuevamente.';
        }
        if (!$errores) {
            try {
                $db->prepare('
                    INSERT INTO evento_encuesta_colab
                      (evento_id, aliado_id, empresa, nombre_responde, coord, cumpl, difus, organ, audit, valor, util, fortalecio, participaria, mejorar, apoyo)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ')->execute([
                    (int) $evento['id'], (int) $aliado['id'], $v['empresa'], $v['nombre'] !== '' ? $v['nombre'] : null,
                    $v['coord'], $v['cumpl'], $v['difus'], $v['organ'], $v['audit'] === 'na' ? null : $v['audit'], $v['valor'], $v['util'],
                    $v['fortalecio'], $v['participaria'], $v['mejorar'] !== '' ? $v['mejorar'] : null, $v['apoyo'] !== '' ? $v['apoyo'] : null,
                ]);
                $db->prepare('UPDATE evento_aliados SET enviado_en = COALESCE(enviado_en, NOW()) WHERE id = ?')->execute([(int) $aliado['id']]);
                $enviado = true;
            } catch (PDOException $e) {
                if ((string) $e->getCode() === '23000') {
                    $yaRespondida = true;
                } else {
                    error_log('Encuesta de colaboracion, evento ' . $evento['id'] . ': ' . $e->getMessage());
                    $errores[] = 'No fue posible guardar la encuesta. Intente nuevamente.';
                }
            }
        }
    }
} elseif ($tipo === 'colab') {
    $v['empresa'] = $aliado['nombre'];
    $v['nombre'] = (string) ($aliado['contacto'] ?? '');
}

if (!$enviado && !empty($conocido)) {
    $v['nombre'] = $v['institucion'] = ''; // el formulario no muestra los datos del registro
}
$ingeniosLista = $identifica ? $db->query('SELECT nombre_ingenios FROM ingenios ORDER BY nombre_ingenios')->fetchAll(PDO::FETCH_COLUMN) : [];
$colorEvento = ($evento && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $evento['color'])) ? $evento['color'] : '#2F6B12';
$titulo = $tipo === 'colab' ? 'Encuesta de colaboración' : ($tipo === 'percep' ? '¿Cómo fue tu experiencia?' : 'Encuesta');
$etiquetas = ['', 'Muy insatisfecho', 'Insatisfecho', 'Regular', 'Satisfecho', 'Muy satisfecho'];

/* Fila de estrellas de 1 a 5 (con "No aplica" opcional), como ppEsStars del prototipo. */
function cengi_enc_estrellas($clave, $etiqueta, $valor, $admiteNa = false)
{
    global $etiquetas;
    $html = '<div class="es-q"><div class="es-lbl">' . cengi_enc_html($etiqueta) . '</div>'
        . '<div class="es-stars" role="radiogroup" aria-label="' . cengi_enc_html($etiqueta) . '" data-clave="' . cengi_enc_html($clave) . '">';
    for ($n = 1; $n <= 5; $n++) {
        $html .= '<button type="button" class="' . (is_int($valor) && $valor >= $n ? 'on' : '') . '" data-valor="' . $n . '" aria-label="' . $n . ' de 5">★</button>';
    }
    if ($admiteNa) {
        $html .= '<button type="button" class="es-na' . ($valor === 'na' ? ' sel' : '') . '" data-valor="na">No aplica</button>';
    }
    $texto = is_int($valor) ? $etiquetas[$valor] : ($valor === 'na' ? 'No aplica' : '');
    $html .= '<span class="es-val">' . cengi_enc_html($texto) . '</span></div>'
        . '<input type="hidden" name="' . cengi_enc_html($clave) . '" value="' . cengi_enc_html($valor === null ? '' : (string) $valor) . '"></div>';
    return $html;
}

function cengi_enc_opciones($clave, $etiqueta, array $opciones, $valor)
{
    $html = '<div class="es-q"><div class="es-lbl">' . cengi_enc_html($etiqueta) . '</div><div class="ep-radios">';
    foreach ($opciones as $val => $texto) {
        $html .= '<label><input type="radio" name="' . cengi_enc_html($clave) . '" value="' . cengi_enc_html($val) . '"' . ($valor === $val ? ' checked' : '') . '> ' . cengi_enc_html($texto) . '</label>';
    }
    return $html . '</div></div>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo cengi_enc_html($titulo); ?> | CENGICAÑA</title>
    <link rel="icon" type="image/png" href="img/logo-comite-capacitacion.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700;800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/eventos_publico.css">
</head>
<body>
<div class="ep-hero" style="padding-bottom:100px;background:linear-gradient(120deg,#0F2A1C,<?php echo cengi_enc_html($colorEvento); ?>);">
    <div class="ep-wrap" style="max-width:760px;">
        <div class="ep-kicker">CENGICAÑA · EVENTOS</div>
        <h1 class="ep-h1"><?php echo cengi_enc_html($titulo); ?></h1>
        <div class="ep-sub"><?php echo cengi_enc_html($evento['nombre'] ?? 'Enlace de encuesta'); ?></div>
    </div>
</div>
<div class="ep-wrap" style="max-width:760px;margin-top:-64px;padding:0 20px 40px;">
    <div class="ep-card">
        <?php if ($tipo === null): ?>
            <div class="ep-ok"><h2 class="ep-h2">Encuesta no disponible</h2><p>El enlace no es válido o la encuesta ya no está disponible.</p></div>
        <?php elseif ($enviado): ?>
            <div class="ep-ok"><div class="ep-ok-i">✓</div><h2 class="ep-h2">¡Gracias por su respuesta!</h2>
                <p><?php echo $tipo === 'colab' ? 'Su opinión nos ayuda a mejorar nuestras alianzas y el apoyo que brindamos en cada evento.' : 'Tu opinión nos ayuda a mejorar la experiencia en próximos eventos.'; ?></p></div>
        <?php elseif ($yaRespondida): ?>
            <div class="ep-ok"><div class="ep-ok-i">✓</div><h2 class="ep-h2">Esta encuesta ya fue respondida</h2>
                <p>Gracias. Si necesita cambiar alguna respuesta, escriba a capacitacion@cengicana.org.</p></div>
        <?php else: ?>
            <form method="POST" id="esForm" novalidate>
                <input type="hidden" name="t" value="<?php echo cengi_enc_html($token); ?>">
                <?php if ($tipo === 'percep'): ?>
                    <?php if ($participante): ?><p class="es-hola">Hola, <b><?php echo cengi_enc_html(explode(' ', trim((string) $participante['nombre_invitado']))[0]); ?></b>. Gracias por asistir.</p><?php endif; ?>
                    <?php if ($identifica): ?>
                        <?php // Correo primero: si ya esta registrado no se piden mas datos; si no, se completan. ?>
                        <div class="ep-f"><label for="esCorreo">Correo electrónico</label>
                            <div style="display:flex;gap:8px;"><input id="esCorreo" name="correo" type="email" maxlength="255" autocomplete="email" inputmode="email" style="flex:1;min-width:0;" value="<?php echo cengi_enc_html($v['correo'] ?? ''); ?>">
                                <button type="button" class="ep-btn is-secundario" id="esBuscar" style="width:auto;margin:0;padding:0 16px;">Continuar</button></div></div>
                        <p class="es-hola" id="esConocido" role="status" style="margin-top:10px;" hidden></p>
                        <p class="ep-legal" id="esAyudaCorreo" style="text-align:left;margin:6px 0 14px;">Con tu correo registramos tu asistencia. <button type="button" id="esSinCorreo" style="border:0;background:none;padding:0;color:#3E7A12;font:inherit;text-decoration:underline;cursor:pointer;">No tengo correo</button></p>
                        <div id="esDatos"<?php echo trim((string) ($v['nombre'] ?? '')) !== '' || ($errores && ($v['correo'] ?? '') === '') ? '' : ' hidden'; ?>>
                            <p class="es-hola" id="esNoEncontrado" hidden>No encontramos tu correo. Completa tus datos, por favor.</p>
                            <div class="ep-grid2">
                                <div class="ep-f"><label for="esNombre">Nombre y apellido</label><input id="esNombre" name="nombre" maxlength="255" autocomplete="name" value="<?php echo cengi_enc_html($v['nombre'] ?? ''); ?>"></div>
                                <div class="ep-f"><label for="esInstitucion"><?php echo $formato === 'investigacion' ? 'Institución' : 'Ingenio o institución'; ?></label><input id="esInstitucion" name="institucion" maxlength="255" list="esIngenios" autocomplete="organization" value="<?php echo cengi_enc_html($v['institucion'] ?? ''); ?>">
                                    <datalist id="esIngenios"><?php foreach ($ingeniosLista as $ingenioNombre): ?><option value="<?php echo cengi_enc_html($ingenioNombre); ?>"><?php endforeach; ?></datalist></div>
                                <?php if ($formato === 'investigacion'): ?>
                                    <div class="ep-f"><label for="esCargo">Puesto o cargo <span>(opcional)</span></label><input id="esCargo" name="cargo" maxlength="150" autocomplete="organization-title" value="<?php echo cengi_enc_html($v['cargo'] ?? ''); ?>"></div>
                                    <div class="ep-f"><label for="esArea">Área a la que pertenece <span>(opcional)</span></label><input id="esArea" name="area" maxlength="150" value="<?php echo cengi_enc_html($v['area'] ?? ''); ?>"></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php echo cengi_enc_estrellas('estrellas', $preguntaGeneral, $v['estrellas'] ?? null); ?>
                    <?php if ($formato === 'investigacion'): ?>
                        <?php echo cengi_enc_estrellas('logistica', '¿Cómo califica la logística del evento?', $v['logistica'] ?? null); ?>
                    <?php elseif ($formato === 'transferencia'): ?>
                        <?php echo cengi_enc_opciones('permanencia', '¿Cuánto tiempo permaneció en el evento?', CENGI_EVT_PERMANENCIA, $v['permanencia'] ?? ''); ?>
                    <?php endif; ?>
                    <div class="es-q"><div class="es-lbl"><?php echo $formato === 'general' ? 'Oportunidades de mejora' : 'Comentario de mejora'; ?> <span>(opcional)</span></div><textarea name="mejora" id="esMejora" maxlength="2000" placeholder="¿Qué podríamos mejorar para tu próxima experiencia?"><?php echo cengi_enc_html($v['mejora'] ?? ''); ?></textarea></div>
                    <div class="pp-form-msg<?php echo $errores ? ' err' : ''; ?>" id="esMsg" role="alert"><?php echo implode('<br>', array_map('cengi_enc_html', $errores)); ?></div>
                    <button type="submit" class="ep-btn">Enviar</button>
                    <div class="ep-legal"><?php echo $identifica ? 'Menos de 1 minuto · tus datos solo se usan para el registro del evento' : 'Respuesta confidencial · menos de 1 minuto'; ?></div>
                <?php else: ?>
                    <div class="ep-grid2">
                        <div class="ep-f"><label for="esEmpresa">Empresa</label><input id="esEmpresa" name="empresa" maxlength="255" value="<?php echo cengi_enc_html($v['empresa'] ?? ''); ?>"></div>
                        <div class="ep-f"><label>Evento</label><input value="<?php echo cengi_enc_html($evento['nombre']); ?>" disabled></div>
                        <div class="ep-f"><label>Fecha</label><input value="<?php echo cengi_enc_html(cengi_enc_fecha($evento['fecha'])); ?>" disabled></div>
                        <div class="ep-f"><label for="esNombreR">Nombre y cargo de quien responde <span>(opcional)</span></label><input id="esNombreR" name="nombre" maxlength="255" value="<?php echo cengi_enc_html($v['nombre'] ?? ''); ?>"></div>
                    </div>
                    <?php foreach (CENGI_EVT_COLAB_GRUPOS as $grupo): ?>
                        <div class="es-grupo"><div class="es-gt"><?php echo cengi_enc_html($grupo); ?></div>
                            <?php foreach (CENGI_EVT_COLAB_ITEMS as $item): if ($item['g'] !== $grupo) { continue; } ?>
                                <?php echo cengi_enc_estrellas($item['k'], $item['t'], $v[$item['k']] ?? null, $item['na']); ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="es-grupo"><div class="es-gt">Resultado de la colaboración</div>
                        <?php echo cengi_enc_opciones('fortalecio', '¿La participación del Centro fortaleció el evento?', ['si' => 'Sí', 'parcialmente' => 'Parcialmente', 'no' => 'No'], $v['fortalecio'] ?? ''); ?>
                        <?php echo cengi_enc_opciones('participaria', '¿Participaría nuevamente en eventos bajo esta modalidad de colaboración?', ['si' => 'Sí', 'no' => 'No'], $v['participaria'] ?? ''); ?>
                        <div class="es-q"><div class="es-lbl">¿Qué debería mejorar el Centro en su participación?</div><textarea name="mejorar" maxlength="2000"><?php echo cengi_enc_html($v['mejorar'] ?? ''); ?></textarea></div>
                        <div class="es-q"><div class="es-lbl">¿Qué tipo de apoyo o valor le gustaría recibir en futuras colaboraciones?</div><textarea name="apoyo" maxlength="2000"><?php echo cengi_enc_html($v['apoyo'] ?? ''); ?></textarea></div>
                    </div>
                    <div class="pp-form-msg<?php echo $errores ? ' err' : ''; ?>" id="esMsg" role="alert"><?php echo implode('<br>', array_map('cengi_enc_html', $errores)); ?></div>
                    <button type="submit" class="ep-btn">Enviar encuesta</button>
                    <div class="ep-legal">Toma unos 3 minutos · sus respuestas se usan solo para mejorar nuestras colaboraciones</div>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</div>
<script>
(function () {
    'use strict';
    var ETIQUETAS = ['', 'Muy insatisfecho', 'Insatisfecho', 'Regular', 'Satisfecho', 'Muy satisfecho'];
    var tipo = <?php echo json_encode($tipo); ?>;
    var form = document.getElementById('esForm');
    if (!form) return;

    // Estrellas: cada fila guarda su valor en el <input type="hidden"> que le sigue.
    Array.prototype.forEach.call(form.querySelectorAll('.es-stars'), function (fila) {
        var oculto = fila.parentNode.querySelector('input[type="hidden"]');
        fila.addEventListener('click', function (ev) {
            var boton = ev.target.closest('button');
            if (!boton) return;
            var valor = boton.getAttribute('data-valor');
            oculto.value = valor;
            var n = valor === 'na' ? 0 : Number(valor);
            Array.prototype.forEach.call(fila.querySelectorAll('button[data-valor]'), function (b) {
                var v = b.getAttribute('data-valor');
                if (v === 'na') b.classList.toggle('sel', valor === 'na');
                else b.classList.toggle('on', Number(v) <= n);
            });
            fila.querySelector('.es-val').textContent = valor === 'na' ? 'No aplica' : ETIQUETAS[n];
        });
    });

    // Correo primero: si esta registrado se saluda y no se piden mas datos; si no, se muestran los campos.
    var datos = document.getElementById('esDatos'), conocido = false;
    if (datos) {
        var campoCorreo = document.getElementById('esCorreo'), saludo = document.getElementById('esConocido');
        var mostrarDatos = function (sinRegistro) {
            datos.hidden = false;
            document.getElementById('esNoEncontrado').hidden = !sinRegistro;
            document.getElementById('esNombre').focus();
        };
        var buscar = function () {
            var correo = campoCorreo.value.trim();
            if (!correo || !campoCorreo.checkValidity()) { campoCorreo.focus(); return; }
            fetch('?t=' + encodeURIComponent(form.querySelector('[name="t"]').value) + '&correo=' + encodeURIComponent(correo), {credentials: 'same-origin'})
                .then(function (r) { return r.json(); })
                .then(function (r) {
                    conocido = !!r.encontrado;
                    saludo.hidden = !conocido;
                    if (conocido) {
                        saludo.innerHTML = '';
                        saludo.append('Hola, ', Object.assign(document.createElement('b'), {textContent: r.nombre}), (r.institucion ? ' · ' + r.institucion : '') + '. Ya tenemos tus datos.');
                        datos.hidden = true;
                        document.getElementById('esNombre').value = '';
                        document.getElementById('esInstitucion').value = '';
                    } else mostrarDatos(true);
                })
                .catch(function () { mostrarDatos(false); });
        };
        document.getElementById('esBuscar').addEventListener('click', buscar);
        campoCorreo.addEventListener('change', buscar);
        campoCorreo.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); buscar(); } });
        campoCorreo.addEventListener('input', function () { conocido = false; saludo.hidden = true; });
        document.getElementById('esSinCorreo').addEventListener('click', function () { campoCorreo.value = ''; conocido = false; saludo.hidden = true; mostrarDatos(false); });
        if (campoCorreo.value && datos.hidden) buscar();
    }

    // Validacion previa, con los mismos mensajes que ppEncEnviar del prototipo.
    form.addEventListener('submit', function (ev) {
        var errs = [];
        var valor = function (n) { var i = form.querySelector('[name="' + n + '"]'); return i ? i.value : ''; };
        if (tipo === 'percep') {
            var correo = form.querySelector('[name="correo"]');
            if (correo && correo.value && !correo.checkValidity()) errs.push('El correo electrónico no es válido.');
            if (datos && !datos.hidden) {
                if (valor('nombre').trim().length < 5) errs.push('Escribe tu nombre y apellido.');
                if (!valor('institucion').trim()) errs.push('Indica tu ingenio o institución.');
            } else if (datos && !conocido) errs.push('Escribe tu correo y pulsa “Continuar”, o elige “No tengo correo”.');
            Array.prototype.forEach.call(form.querySelectorAll('.es-stars'), function (fila) {
                if (!valor(fila.getAttribute('data-clave'))) errs.push('Califica: ' + fila.getAttribute('aria-label'));
            });
            if (form.querySelector('[name="permanencia"]') && !form.querySelector('[name="permanencia"]:checked')) errs.push('Indica tu permanencia en el evento.');
        } else {
            var faltan = [];
            Array.prototype.forEach.call(form.querySelectorAll('.es-stars'), function (fila) {
                if (!valor(fila.getAttribute('data-clave'))) faltan.push(fila.getAttribute('aria-label'));
            });
            if (!valor('empresa').trim()) errs.push('Indique el nombre de la empresa.');
            if (faltan.length) errs.push('Califique: ' + faltan.join('; ') + '.');
            if (!form.querySelector('[name="fortalecio"]:checked')) errs.push('Responda si la participación del Centro fortaleció el evento.');
            if (!form.querySelector('[name="participaria"]:checked')) errs.push('Indique si participaría nuevamente.');
        }
        var msg = document.getElementById('esMsg');
        if (errs.length) {
            ev.preventDefault();
            msg.className = 'pp-form-msg err';
            msg.textContent = '';
            errs.forEach(function (t, k) { if (k) msg.appendChild(document.createElement('br')); msg.appendChild(document.createTextNode(t)); });
        }
    });
}());
</script>
</body>
</html>
