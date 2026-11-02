<?php
if (($_GET['vista'] ?? '') === 'recibos') { require __DIR__ . '/recibos_contables.php'; exit; }
if (($_GET['vista'] ?? '') === 'pagos') {
    define('CENGI_INS_PAGOS_INTEGRADOS', true);
    require __DIR__ . '/pagos_inscripcion.php';
    exit;
}
/**
 * Gestion de inscripciones de las convocatorias: cupo de cada curso planificado,
 * inscripciones validadas (por el ingenio o por Administracion), asignadas al curso,
 * en lista de espera y rechazadas. La asignacion es automatica al validarse el pago
 * (cengi_ins_procesar_plan); aqui se ve el avance, se da de baja, se rechaza, se
 * reactiva y, si hace falta, se abre el curso antes de completar el cupo minimo.
 * Las solicitudes directas a cursos sin planificacion siguen en solicitudes.php.
 */
require_once __DIR__ . '/inscripcion_ingreso.php';
require_once __DIR__ . '/menu.php';
require_once __DIR__ . '/administracion_nav.php';
require_once __DIR__ . '/cobranza_helpers.php';

if (!cengi_puede_ver_solicitudes() && !cengi_ins_puede_ver_pagos()) { header('Location: ver_cursos.php'); exit; }

$db = conectar();
$puedeGestionar = cengi_puede_aprobar_solicitudes() || cengi_puede_rechazar_solicitudes();
$puedeAbrirCurso = cengi_puede_gestionar();
$limitado = cengi_ins_ingenio_limitado();
$usuario = cengi_pp_usuario_nombre();
$mensaje = '';
$mensajeTipo = 'success';
if (empty($_SESSION['ins_csrf'])) $_SESSION['ins_csrf'] = bin2hex(random_bytes(32));

const CENGI_INSC_MENSAJES = [
    'autorizada' => 'Aceptación del ingenio registrada. El participante pasa a Inscritos; su cobro sigue pendiente si aún no se ha pagado.',
    'formulario' => 'Se reenvió al participante el correo con el enlace para adjuntar su boleta.',
    'autorizada_grupo' => 'Grupo validado: cada participante del grupo pasa a Inscritos con el mismo motivo registrado; el cobro sigue en la proforma del ingenio.',
    'baja' => 'Participante dado de baja del curso; se le avisó por correo.',
    'rechazada' => 'Inscripción rechazada; se avisó al participante.',
    'reactivada' => 'Inscripción reactivada.',
    'asignada' => 'Participante asignado al curso.',
    'curso' => 'Curso abierto: ya aparece en Cursos y se asignaron las inscripciones validadas.',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');
    $aviso = null;
    try {
        if (!hash_equals($_SESSION['ins_csrf'], (string) ($_POST['csrf'] ?? ''))) throw new InvalidArgumentException('Actualiza la página antes de guardar.');
        if (!$puedeGestionar && !($accion==='autorizar_ingenio' && (cengi_ins_delega_cengicana()||cengi_es_ingenio())) && !($accion==='reenviar_formulario' && (cengi_ins_delega_cengicana()||cengi_ins_puede_validar_pagos()))) {
            throw new InvalidArgumentException('No tienes permiso para gestionar inscripciones.');
        }
        $db->beginTransaction();
        if ($accion === 'abrir_curso') {
            if (!$puedeAbrirCurso) {
                throw new InvalidArgumentException('Solo quien gestiona cursos puede abrir el curso.');
            }
            $plan = cengi_pp_plan($db, (int) ($_POST['plan'] ?? 0));
            if (!$plan || !cengi_pp_convocado($plan) || $plan['curso_id']) {
                throw new InvalidArgumentException('La convocatoria no está abierta o ya tiene curso.');
            }
            cengi_pp_crear_curso($db, $plan, 'abierto manualmente por ' . $usuario);
            $GLOBALS['cengi_ins_cola'][] = ['tipo' => 'curso', 'plan' => $plan];
            cengi_ins_procesar_plan($db, $plan['id'], $usuario);
            $codigo = 'curso';
            $planId = $plan['id'];
        } else {
            $s = cengi_ins_solicitud($db, (int) ($_POST['id'] ?? 0), true);
            if (!$s || ($limitado !== null && cengi_texto_normalizado($s['nombre_ingenios']) !== cengi_texto_normalizado($limitado))) {
                throw new InvalidArgumentException('La inscripción no existe o no tienes acceso a ella.');
            }
            $planId = $s['planificacion_id'];
            $motivo = mb_substr(trim((string) ($_POST['motivo'] ?? '')), 0, 500, 'UTF-8');
            if($accion==='autorizar_ingenio') {
                if($s['tipo_pago']!=='Ingenio'||$s['estado']==='Rechazado'||$s['validacion_ingenio']==='validada'||!cengi_ins_puede_validar_ingenio($s['nombre_ingenios']))throw new InvalidArgumentException('No puedes validar este interesado.');
                $autoriza=mb_substr(trim((string)($_POST['autoriza']??'')),0,180);
                $porIngenio=($_POST['via']??'')!=='capacitacion'&&cengi_es_ingenio()&&cengi_texto_normalizado($s['nombre_ingenios'])===cengi_texto_normalizado(cengi_ingenio_nombre_actual());
                if(!$porIngenio && ($autoriza===''||$motivo===''))throw new InvalidArgumentException('Indica nombre y cargo de quien autoriza por el ingenio y una observación.');
                if($porIngenio){$autoriza=$usuario;$motivo=$motivo?:'Aceptación registrada por el ingenio.';}
                // Capacitación puede validar a todo el grupo de pago del ingenio con el mismo motivo.
                $aValidar=[$s];
                if(!$porIngenio && !empty($_POST['grupo']) && $s['grupo_pago_id']){
                    $q=$db->prepare("SELECT id_solicitud FROM solicitudes_inscripcion WHERE grupo_pago_id=? AND tipo_pago='Ingenio' AND estado<>'Rechazado' AND COALESCE(validacion_ingenio,'')<>'validada' AND id_solicitud<>? FOR UPDATE");
                    $q->execute([(int)$s['grupo_pago_id'],(int)$s['id_solicitud']]);
                    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $idGrupo){
                        $otra=cengi_ins_solicitud($db,(int)$idGrupo,true);
                        if($otra&&cengi_ins_puede_validar_ingenio($otra['nombre_ingenios']))$aValidar[]=$otra;
                    }
                }
                $enGrupo=count($aValidar)>1?'Validación en grupo ('.count($aValidar).' personas). ':'';
                $nota=mb_substr(($porIngenio?'':'Validado por Capacitación. ').$enGrupo.'Autoriza por el ingenio: '.$autoriza.'. Motivo: '.$motivo,0,500);
                foreach($aValidar as $sv){
                    $db->prepare("UPDATE solicitudes_inscripcion SET validacion_ingenio='validada',validacion_ingenio_por=?,validacion_ingenio_en=NOW(),validacion_ingenio_nota=? WHERE id_solicitud=?")->execute([$usuario,$nota,$sv['id_solicitud']]);
                    $db->prepare('INSERT INTO inscripcion_historial(solicitud_id,accion,usuario,motivo,antes,despues) VALUES(?,?,?,?,?,?)')->execute([$sv['id_solicitud'],$enGrupo?'autorizar_ingenio_grupo':'autorizar_ingenio',$usuario,$nota,json_encode($sv,JSON_UNESCAPED_UNICODE),json_encode(cengi_ins_solicitud($db,$sv['id_solicitud']),JSON_UNESCAPED_UNICODE)]);
                    if(!$sv['planificacion_id'] && $sv['estado']==='Pendiente')cengi_ins_asignar($db,cengi_ins_solicitud($db,$sv['id_solicitud']),$usuario);
                }
                $codigo=$enGrupo?'autorizada_grupo':'autorizada';
            } elseif ($accion === 'baja' || $accion === 'rechazar') {
                if ($motivo === '') {
                    throw new InvalidArgumentException('Escribe el motivo: se le comunica al participante.');
                }
                if ($s['estado'] === 'Aprobado') {
                    cengi_ins_quitar_asignacion($db, $s);
                }
                $db->prepare("UPDATE solicitudes_inscripcion SET estado = 'Rechazado', pago_nota = IF(pago_nota = '', ?, pago_nota) WHERE id_solicitud = ?")
                    ->execute(['Inscripción ' . ($accion === 'baja' ? 'dada de baja' : 'rechazada') . ': ' . $motivo, $s['id_solicitud']]);
                cengi_ins_recalcular_grupo($db, $s['grupo_pago_id']);
                $aviso = [$s, ($accion === 'baja' ? 'Baja del curso: ' : 'Inscripción no aceptada: ') . $s['plan_nombre'],
                    ($accion === 'baja' ? 'Te informamos que fuiste dado de baja de ' : 'Tu inscripción a ') . $s['plan_nombre'] . ($accion === 'baja' ? '' : ' no fue aceptada') . ".\nMotivo: " . $motivo];
                $codigo = $accion === 'baja' ? 'baja' : 'rechazada';
            } elseif ($accion === 'reenviar_formulario') {
                // Pago propio sin boleta: se reenvía al participante el correo con el enlace seguro del formulario.
                if ($s['tipo_pago'] !== 'Propio' || $s['estado'] === 'Rechazado' || !empty($s['comprobante_ruta']) || $s['pago_estado'] === 'pagado') {
                    throw new InvalidArgumentException('Este interesado ya tiene boleta o no tiene pago propio pendiente.');
                }
                if (!filter_var($s['correo'], FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('El participante no tiene un correo válido; copia el enlace y envíalo por otro medio.');
                }
                $q = $db->prepare('SELECT COALESCE(p.fecha_inicio, c.inicio) FROM solicitudes_inscripcion s LEFT JOIN planificacion_cursos p ON p.id = s.planificacion_id LEFT JOIN cursos c ON c.id = s.curso_id WHERE s.id_solicitud = ?');
                $q->execute([$s['id_solicitud']]);
                $s['fecha_inicio'] = (string) $q->fetchColumn();
                if (!cengi_cob_recordar_participante($db, $s, 'Si ya realizaste el pago, solo adjunta la boleta en el enlace.')) {
                    throw new InvalidArgumentException('No se pudo enviar el correo. Revisa la configuración de correo o copia el enlace.');
                }
                $db->prepare('INSERT INTO inscripcion_historial(solicitud_id,accion,usuario,motivo,antes,despues) VALUES(?,?,?,?,?,?)')
                    ->execute([$s['id_solicitud'], 'reenviar_formulario', $usuario, 'Se reenvió el enlace para adjuntar la boleta a ' . $s['correo'], '', '']);
                $codigo = 'formulario';
            } elseif ($accion === 'reactivar') {
                if ($s['estado'] !== 'Rechazado') {
                    throw new InvalidArgumentException('Solo se reactivan inscripciones rechazadas.');
                }
                $db->prepare("UPDATE solicitudes_inscripcion SET estado = 'Pendiente' WHERE id_solicitud = ?")->execute([$s['id_solicitud']]);
                cengi_ins_recalcular_grupo($db, $s['grupo_pago_id']);
                $codigo = 'reactivada';
            } elseif ($accion === 'asignar') {
                // Desde lista de espera: asigna aunque el curso supere el cupo maximo.
                $plan = cengi_pp_plan($db, $planId);
                if ($s['estado'] !== 'Espera' || !cengi_ins_requisito($s)['ok'] || !$plan['curso_id']) {
                    throw new InvalidArgumentException('Solo se asigna desde la lista de espera una inscripción validada con curso abierto.');
                }
                $s['curso_id'] = $plan['curso_id'];
                cengi_ins_asignar($db, $s, $usuario);
                $GLOBALS['cengi_ins_cola'][] = ['tipo' => 'asignado', 'plan' => $plan, 'solicitud' => $s];
                $codigo = 'asignada';
            } else {
                throw new InvalidArgumentException('Acción no válida.');
            }
            cengi_ins_procesar_plan($db, $planId);
        }
        $db->commit();
        cengi_ins_enviar_cola($db);
        if ($aviso) {
            [$s, $asunto, $texto] = $aviso;
            try {

                cengi_pp_enviar_comunicacion($db, ['categoria' => 'Inscripción', 'origen' => 'sistema', 'planificacion_id' => (int) $s['planificacion_id'],
                    'asunto' => $asunto, 'mensaje' => "Hola {nombre}:\n\n" . $texto . "\n\nCoordinación de Capacitación — CENGICAÑA"],
                    [['nombre' => $s['nombre_participante'], 'correo' => $s['correo']]]);
            } catch (Throwable $e) {
                error_log('Inscripciones: no se pudo avisar: ' . $e->getMessage());
            }
        }
        cengi_pp_redirigir('inscripciones.php', ['plan' => $planId, 'msg' => $codigo]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        cengi_ins_deshacer_cuentas_creadas($db);
        $GLOBALS['cengi_ins_cola'] = [];
        $mensajeTipo = 'error';
        if ($e instanceof InvalidArgumentException) {
            $mensaje = $e->getMessage();
        } else {
            error_log('Inscripciones: ' . $e->getMessage());
            $mensaje = 'No fue posible completar la acción.';
        }
    }
}

if ($mensaje === '' && isset(CENGI_INSC_MENSAJES[$_GET['msg'] ?? ''])) {
    $mensaje = cengi_pp_h(CENGI_INSC_MENSAJES[$_GET['msg']]);
}

// ---------------------------------------------------------------- datos
$planes = $db->query("SELECT p.*, c.codigo_curso,
        (SELECT COUNT(*) FROM solicitudes_inscripcion s WHERE s.planificacion_id = p.id AND s.estado <> 'Rechazado') AS inscritos
    FROM planificacion_cursos p LEFT JOIN cursos c ON c.id = p.curso_id
    WHERE (p.estado IN ('aprobado', 'reprogramado') AND p.fecha_fin >= CURDATE() - INTERVAL 60 DAY)
       OR EXISTS (SELECT 1 FROM solicitudes_inscripcion s WHERE s.planificacion_id = p.id)
    ORDER BY p.fecha_inicio DESC")->fetchAll(PDO::FETCH_ASSOC);
$planFiltro = (int) ($_GET['plan'] ?? 0);
$etapaFiltro = array_key_exists($_GET['etapa'] ?? '', CENGI_INS_ETAPAS) || ($_GET['etapa'] ?? '') === 'todas' ? $_GET['etapa'] : '';
$busqueda = trim((string) ($_GET['q'] ?? ''));
$cursoFiltro = (int) ($_GET['curso'] ?? 0);
$soloPendiente = ($_GET['cobro'] ?? '') === 'pendiente';
$vistaInteresados=($_GET['vista']??'interesados')==='interesados';
$bandeja = in_array($_GET['bandeja'] ?? '', ['por_validar','por_asignar','sin_proforma','pendientes','pagados'],true)?$_GET['bandeja']:($soloPendiente?'pendientes':'');
$origenFiltro=isset(CENGI_INS_ORIGENES[$_GET['origen']??''])?$_GET['origen']:'';
$pagadorFiltro=in_array($_GET['pagador']??'',['Ingenio','Propio','Exonerado'],true)?$_GET['pagador']:'';
if($soloPendiente && !array_key_exists('pagador',$_GET))$pagadorFiltro='Propio';
$conteo = cengi_pp_conteo_inscritos($db);

$stmt = $db->query("SELECT s.*, i.nombre_ingenios, COALESCE(p.nombre, c.nombre_cursos) AS plan_nombre, COALESCE(p.fecha_inicio,c.inicio) AS fecha_inicio, g.estado AS grupo_estado, g.orden AS grupo_orden, g.proforma_id, pf.numero AS proforma_numero, pf.vence AS proforma_vence
    FROM solicitudes_inscripcion s LEFT JOIN planificacion_cursos p ON p.id = s.planificacion_id LEFT JOIN cursos c ON c.id = s.curso_id
    LEFT JOIN ingenios i ON i.id = s.ingenio_id LEFT JOIN pagos_grupales g ON g.id = s.grupo_pago_id LEFT JOIN proformas pf ON pf.id=g.proforma_id"
    . ($planFiltro ? ' WHERE s.planificacion_id = ' . $planFiltro : ($cursoFiltro ? ' WHERE s.curso_id = ' . $cursoFiltro : '')) . ' ORDER BY fecha_inicio DESC, s.nombre_participante');
$filasBase = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Personas de cada grupo de pago del ingenio que aún esperan validación (para "aplicar a todo el grupo").
$gruposPendientes = [];
foreach ($filasBase as $r) {
    if ($r['grupo_pago_id'] && $r['tipo_pago'] === 'Ingenio' && $r['estado'] !== 'Rechazado' && ($r['validacion_ingenio'] ?? '') !== 'validada') {
        $gruposPendientes[(int) $r['grupo_pago_id']][(int) $r['id_solicitud']] = $r['nombre_participante'];
    }
}
$todas = array_values(array_filter($filasBase, static function ($s) use ($limitado,$origenFiltro,$pagadorFiltro) {
    return ($limitado === null || cengi_texto_normalizado($s['nombre_ingenios'] ?? '') === cengi_texto_normalizado($limitado))
        && ($origenFiltro==='' || ($s['origen_inscripcion']??'sin_identificar')===$origenFiltro)
        && ($pagadorFiltro==='' || $s['tipo_pago']===$pagadorFiltro);
}));
$todas=array_values(array_filter($todas,static function($s)use($vistaInteresados,$soloPendiente){return $soloPendiente ? ($s['estado']==='Aprobado'||cengi_ins_inscrito($s)) : ($vistaInteresados?(!cengi_ins_inscrito($s)&&!($s['tipo_pago']==='Propio'&&($s['pago_validado_rol']??'')==='capacitacion'&&$s['pago_estado']==='en revisión')):cengi_ins_inscrito($s));}));
// En esta pantalla se gestiona lo validado; lo que aun no se valida esta en Pagos de inscripcion.
$visibles = ['validado', 'asignado', 'espera', 'rechazado'];
$sinValidar = array_filter($todas, static function ($s) use ($visibles) {
    return !in_array(cengi_ins_etapa($s), $visibles, true);
});
$lista = array_values(array_filter($todas, static function ($s) use ($visibles, $etapaFiltro, $busqueda, $soloPendiente, $bandeja, $origenFiltro, $pagadorFiltro) {
    if(!cengi_ins_en_bandeja($s,$bandeja) || ($origenFiltro!=='' && ($s['origen_inscripcion']??'sin_identificar')!==$origenFiltro) || ($pagadorFiltro!=='' && $s['tipo_pago']!==$pagadorFiltro))return false;
    $etapa = cengi_ins_etapa($s);
    if ($etapaFiltro === 'todas') {
        $pasa = true;
    } elseif ($etapaFiltro !== '') {
        $pasa = $etapa === $etapaFiltro;
    } else {
        $pasa = true;
    }
    if (!$pasa) {
        return false;
    }
    return $busqueda === '' || strpos(cengi_texto_normalizado($s['nombre_participante'] . ' ' . $s['correo'] . ' ' . $s['cui_participante'] . ' ' . $s['nombre_ingenios'] . ' ' . $s['institucion_otra'] . ' ' . $s['telefono'] . ' ' . $s['plan_nombre']), cengi_texto_normalizado($busqueda)) !== false;
}));
$cuenta = static function ($etapa) use ($todas) {
    return count(array_filter($todas, static function ($s) use ($etapa) {
        return cengi_ins_etapa($s) === $etapa;
    }));
};
$planSel = null;
foreach ($planes as $p) {
    if ((int) $p['id'] === $planFiltro) {
        $planSel = $p;
    }
}
$solicitudesDirectas = (int) $db->query("SELECT COUNT(*) FROM solicitudes_inscripcion WHERE planificacion_id IS NULL AND estado = 'Pendiente'")->fetchColumn();
$filtros = array_filter(['vista'=>$vistaInteresados?'interesados':'inscripciones','bandeja'=>$bandeja,'origen'=>$origenFiltro,'pagador'=>$pagadorFiltro,'cobro' => $soloPendiente ? 'pendiente' : '', 'curso' => $cursoFiltro ?: '', 'plan' => $planFiltro ?: '', 'etapa' => $etapaFiltro, 'q' => $busqueda]);
$urlFiltro = static function (array $cambios) use ($filtros) {
    $q = array_filter(array_merge($filtros, $cambios), static function ($v) {
        return $v !== '' && $v !== null;
    });
    return 'inscripciones.php' . ($q ? '?' . http_build_query($q) : '');
};
$iniciales = static function ($nombre) {
    $partes = preg_split('/\s+/u', trim((string) $nombre), -1, PREG_SPLIT_NO_EMPTY);
    $ini = '';
    foreach (array_slice($partes, 0, 2) as $parte) {
        $ini .= mb_strtoupper(mb_substr($parte, 0, 1, 'UTF-8'), 'UTF-8');
    }
    return $ini ?: '?';
};
$bandejas=['por_validar'=>['Por validar','Ingenio o Administración','check','is-amarillo'],'por_asignar'=>['Validadas por asignar','Según apertura y cupo','education','is-verde'],'sin_proforma'=>['Ingenio sin proforma','Preparar cobro grupal','file','is-naranja'],'pendientes'=>['Pendientes de pago','Incluye participantes asignados','credit-card','is-gris'],'pagados'=>['Pago registrado','Ingreso confirmado','ok','is-verde']];
$kpis=[];foreach($bandejas as $key=>[$label,$detail,$icon,$tone])$kpis[]=[$key,count(array_filter($todas,static function($s)use($key){return cengi_ins_en_bandeja($s,$key);})), $label,$detail,$icon,$tone];
$pendientesMonto=['GTQ'=>0,'USD'=>0];$sinCuota=0;
foreach($todas as $s)if(cengi_ins_en_bandeja($s,'pendientes')){if($s['monto']===null)$sinCuota++;else $pendientesMonto[$s['moneda']??'GTQ']+=(float)$s['monto'];}
?>
<!DOCTYPE html>
<html lang="es">
<head><?php cengi_pp_head('Inscripciones'); ?>
    <link rel="stylesheet" href="css/inscripciones.css?v=<?php echo (int) (@filemtime(__DIR__ . '/css/inscripciones.css') ?: 1); ?>">
</head>
<body class="cengi-canvas">
<?php menu_render(); ?>

<main class="container cengi-organizations-page pp-page ins-page">
    <header class="ins-hero">
        <div class="ins-hero-text">
            <span class="ins-eyebrow"><span class="glyphicon glyphicon-inbox"></span> Inscripción por participante y curso</span>
            <h1><?= $soloPendiente?'Cobros pendientes de inscritos':($vistaInteresados?'Interesados en cursos':'Inscritos') ?></h1>
            <p>Primero se registra el interés, luego la autorización del ingenio o la revisión de boleta. El ingenio confirma la inscripción con su aceptación; para pago propio, Administración debe aceptar la boleta.</p>
        </div>
        <a class="ins-hero-btn" href="inscripcion.php" target="_blank" rel="noopener"><span class="glyphicon glyphicon-new-window"></span>Formulario público</a>
        <?php if(cengi_puede_cargar_participantes()):?><a class="ins-hero-btn" href="carga_interesados.php<?= $cursoFiltro?'?curso='.(int)$cursoFiltro:'' ?>"><span class="glyphicon glyphicon-upload"></span>Carga masiva de interesados</a><?php endif;?>
    </header>

    <?php cengi_ins_nav($soloPendiente ? 'cobros' : ($vistaInteresados?'interesados':'inscripciones')); ?>
    <?php if($soloPendiente): ?><p class="pp-sub">Inscritos con pago propio pendiente de conciliar. Los pagos grupales por ingenio se gestionan en Proformas. Puedes cambiar el filtro de quién paga para consultar otros cobros de inscritos.</p><?php endif; ?>
    <?php cengi_pp_feedback($mensaje, $mensajeTipo); ?>
    <?php if(!$vistaInteresados && !$soloPendiente){require __DIR__.'/inscritos_vista.php';exit;} ?>

    <details class="ins-flow" open>
        <summary><span class="glyphicon glyphicon-info-sign"></span>¿Cómo avanza una inscripción?</summary>
        <ol class="ins-steps">
            <li class="is-amarillo"><b>Inscripción</b><span>Un registro y una cuota por cada curso.</span></li>
            <li class="is-verde2"><b>Validación</b><span>Patrocinio del ingenio o pago propio.</span></li>
            <li class="is-verde"><b>Asignación</b><span>Después de validar, según el cupo del curso.</span></li>
            <li class="is-naranja"><b>Cobro</b><span>Revisar el pago de cada inscripción hasta liquidarlo.</span></li>
        </ol>
    </details>

    <div class="ins-kpis">
        <?php foreach ($kpis as [$etapaKpi, $valor, $etiqueta, $detalle, $icono, $tono]): ?>
            <a class="ins-kpi <?php echo $tono . ($bandeja === $etapaKpi ? ' is-current' : ''); ?>" href="<?php echo cengi_pp_h($urlFiltro(['bandeja' => $bandeja === $etapaKpi ? '' : $etapaKpi, 'etapa'=>'','cobro'=>''])); ?>" title="<?php echo $bandeja === $etapaKpi ? 'Quitar filtro' : 'Filtrar por esta etapa'; ?>">
                <span class="ins-kpi-icon"><span class="glyphicon glyphicon-<?php echo $icono; ?>"></span></span>
                <span class="ins-kpi-body">
                    <span class="ins-kpi-val"><?php echo (int) $valor; ?></span>
                    <span class="ins-kpi-label"><?php echo cengi_pp_h($etiqueta); ?></span>
                    <span class="ins-kpi-detail"><?php echo cengi_pp_h($detalle); ?></span>
                </span>
            </a>
        <?php endforeach; ?>
    </div>

<section class="pp-card ins-filters">
        <form class="ins-toolbar" method="get"><input type="hidden" name="vista" value="<?= $vistaInteresados?'interesados':'inscripciones' ?>"><input type="hidden" name="curso" value="<?php echo $cursoFiltro ?: ''; ?>"><input type="hidden" name="cobro" value="<?php echo $soloPendiente ? 'pendiente' : ''; ?>">
            <label class="ins-field">
                <span>Convocatoria</span>
                <select name="plan" onchange="this.form.submit()">
                    <option value="">Todas las convocatorias</option>
                    <?php foreach ($planes as $p): ?>
                        <option value="<?php echo (int) $p['id']; ?>" <?php echo (int) $p['id'] === $planFiltro ? 'selected' : ''; ?>><?php echo cengi_pp_h($p['nombre'] . ' · ' . ($conteo[$p['id']] ?? 0) . '/' . $p['cupo_minimo'] . ($p['curso_id'] ? ' · curso abierto' : '')); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="ins-field">
                <span>Etapa</span>
                <select name="etapa" onchange="this.form.submit()">
                    <option value="">Todas las inscripciones</option>
                    <?php foreach (array_keys(CENGI_INS_ETAPAS) as $e): ?><option value="<?php echo $e; ?>" <?php echo $etapaFiltro === $e ? 'selected' : ''; ?>><?php echo CENGI_INS_ETAPAS[$e][0] . ' (' . $cuenta($e) . ')'; ?></option><?php endforeach; ?>
                    <option value="todas" <?php echo $etapaFiltro === 'todas' ? 'selected' : ''; ?>>Todas, incluidas las sin validar</option>
                </select>
            </label>
            <label class="ins-field"><span>Bandeja</span><select name="bandeja" onchange="this.form.submit()"><option value="">Todas</option><?php foreach($bandejas as $key=>$info): ?><option value="<?= $key ?>" <?= $bandeja===$key?'selected':'' ?>><?= cengi_pp_h($info[0]) ?></option><?php endforeach; ?></select></label>
<label class="ins-field"><span>Quién paga</span><select name="pagador" onchange="this.form.submit()"><option value="">Todos</option><?php foreach(['Ingenio','Propio','Exonerado'] as $tipo): ?><option <?= $pagadorFiltro===$tipo?'selected':'' ?>><?= $tipo ?></option><?php endforeach; ?></select></label>
<label class="ins-field"><span>Origen</span><select name="origen" onchange="this.form.submit()"><option value="">Todos los ingresos</option><?php foreach(CENGI_INS_ORIGENES as $key=>$label): ?><option value="<?= $key ?>" <?= $origenFiltro===$key?'selected':'' ?>><?= cengi_pp_h($label) ?></option><?php endforeach; ?></select></label>
<label class="ins-field ins-field-search">
                <span>Buscar</span>
                <span class="ins-search">
                    <span class="glyphicon glyphicon-search" aria-hidden="true"></span>
                    <input type="search" name="q" value="<?php echo cengi_pp_h($busqueda); ?>" placeholder="Nombre, CUI, correo, teléfono o ingenio...">
                </span>
            </label>
            <div class="ins-toolbar-end"><a class="ins-btn" href="inscripciones.php?cobro=pendiente">Cobros pendientes</a>
                <span class="ins-total"><b><?php echo count($lista); ?></b> registro(s)</span>
                <?php if ($filtros): ?><a class="ins-clear" href="inscripciones.php"><span class="glyphicon glyphicon-remove"></span>Limpiar</a><?php endif; ?>
            </div>
        </form>

        <?php if ($planSel):
            $n = $conteo[$planSel['id']] ?? 0;
            [$color, $etiqueta, $detalle] = cengi_pp_semaforo_cupo($planSel, $n);
            $minimo = max(1, (int) $planSel['cupo_minimo']);
            $maximo = (int) $planSel['cupo_maximo'];
            $escala = max($minimo, $maximo, $n);
            $faltan = max(0, $minimo - $n);
        ?>
            <div class="ins-plan is-<?php echo cengi_pp_h($color); ?>">
                <div class="ins-plan-head">
                    <div>
                        <h2><?php echo cengi_pp_h($planSel['nombre']); ?></h2>
                        <span class="pp-sub"><span class="glyphicon glyphicon-calendar"></span> Inicia <?php echo cengi_pp_fecha($planSel['fecha_inicio']); ?> &nbsp;·&nbsp; <?php echo cengi_pp_badge(CENGI_PP_ESTADOS_PLAN, $planSel['estado']); ?></span>
                    </div>
                    <div class="ins-plan-status">
                        <?php echo $planSel['curso_id'] ? '<a class="ins-pill is-verde" href="ver_cursos.php?' . cengi_pp_h(http_build_query(['q' => $planSel['codigo_curso'], 'anio' => 'todos'])) . '"><span class="glyphicon glyphicon-ok-circle"></span>Curso abierto: ' . cengi_pp_h($planSel['codigo_curso']) . '</a>' : '<span class="ins-pill is-amarillo"><span class="glyphicon glyphicon-time"></span>Convocatoria abierta, sin curso aún</span>'; ?>
                        <a class="ins-pill is-gris" href="bitacora_cambios.php?plan=<?php echo (int) $planSel['id']; ?>" title="Cambios de fechas, sesiones e instructores del curso"><span class="glyphicon glyphicon-list-alt"></span>Bitácora de cambios</a>
                    </div>
                </div>
                <div class="ins-meter">
                    <div class="ins-meter-top">
                        <span class="ins-meter-num"><b><?php echo $n; ?></b> inscrita(s)</span>
                        <span class="ins-meter-tag"><?php echo cengi_pp_h($etiqueta); ?></span>
                    </div>
                    <div class="ins-meter-track" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo $escala; ?>" aria-valuenow="<?php echo $n; ?>">
                        <span class="ins-meter-fill" style="width:<?php echo min(100, round($n * 100 / $escala, 1)); ?>%"></span>
                        <span class="ins-meter-mark" style="left:<?php echo round($minimo * 100 / $escala, 1); ?>%" title="Cupo mínimo"></span>
                    </div>
                    <div class="ins-meter-legend">
                        <span><i class="is-min"></i>Mínimo <?php echo $minimo; ?></span>
                        <?php if ($maximo): ?><span><i class="is-max"></i>Máximo <?php echo $maximo; ?></span><?php endif; ?>
                        <span class="ins-meter-detail"><?php echo cengi_pp_h($planSel['curso_id'] ? $detalle : 'Faltan ' . $faltan . ' inscripción(es) validada(s) para abrir el curso.'); ?></span>
                    </div>
                </div>
                <?php if (!$planSel['curso_id'] && cengi_pp_convocado($planSel) && $puedeAbrirCurso && $puedeGestionar): ?>
                    <form method="post" class="ins-plan-action" onsubmit="return confirm('¿Abrir el curso ahora, sin completar el cupo mínimo? Pasará a Cursos y se asignarán las inscripciones validadas.');"><input type="hidden" name="csrf" value="<?php echo cengi_pp_h($_SESSION['ins_csrf']); ?>">
                        <input type="hidden" name="accion" value="abrir_curso"><input type="hidden" name="plan" value="<?php echo (int) $planSel['id']; ?>">
                        <span class="pp-sub">¿No se completará el cupo mínimo? Puedes abrir el curso igualmente.</span>
                        <button type="submit" class="btn btn-primary btn-sm"><span class="glyphicon glyphicon-play"></span> Abrir curso ahora</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($sinValidar && $etapaFiltro !== 'todas'): ?>
        <div class="ins-notice">
            <span class="ins-notice-icon"><span class="glyphicon glyphicon-credit-card"></span></span>
            <p><b><?php echo count($sinValidar); ?> inscripción(es) todavía no están validadas</b>: <?php echo count(array_filter($sinValidar, static function ($s) {
                return $s['tipo_pago'] === 'Ingenio';
            })); ?> esperan la validación de su ingenio y <?php echo count(array_filter($sinValidar, static function ($s) {
                return $s['tipo_pago'] === 'Propio';
            })); ?> la validación del pago por Administración. Ya aparecen en el listado con su estado actual.</p>
            <?php if (cengi_ins_puede_ver_pagos()): ?><a class="ins-notice-btn" href="pagos_inscripcion.php?<?php echo cengi_pp_h(http_build_query(array_filter(['plan' => $planFiltro ?: '', 'anio' => $planSel['anio'] ?? '', 'etapa' => 'por-validar']))); ?>">Ir a Pagos de inscripción <span class="glyphicon glyphicon-arrow-right"></span></a><?php endif; ?>
        </div>
    <?php endif; ?>

    <section class="ins-table-card"><div class="pp-table-wrap">
        <table class="cengi-org-table ins-table">
            <thead><tr><th>Participante</th><th>Curso</th><th>Cuota</th><th>Quién paga · cobro</th><th>Etapa y responsable</th><th class="is-actions"><span class="sr-only">Acciones</span></th></tr></thead>
            <tbody>
                <?php if (!$lista): ?><tr class="ins-empty-row"><td colspan="6"><div class="ins-empty">
                    <span class="ins-empty-icon"><span class="glyphicon glyphicon-<?php echo $busqueda !== '' ? 'search' : 'inbox'; ?>"></span></span>
                    <b><?php echo $busqueda !== '' ? 'Nadie coincide con la búsqueda' : 'No hay inscripciones con esos filtros'; ?></b>
                    <span><?php echo $busqueda !== '' ? 'Revisa también Pagos de inscripción: allí están quienes aún no se validan.' : 'Prueba con otra convocatoria o etapa.'; ?></span>
                </div></td></tr><?php endif; ?>
                <?php foreach ($lista as $s): $etapa = cengi_ins_etapa($s); ?>
                    <tr class="ins-row is-<?php echo cengi_pp_h($etapa); ?>">
                        <td data-label="Participante"><div class="ins-person">
                            <span class="ins-avatar" aria-hidden="true"><?php echo cengi_pp_h($iniciales($s['nombre_participante'])); ?></span>
                            <div><strong><?php echo cengi_pp_h($s['nombre_participante']); ?></strong>
                                <span class="pp-sub"><?php echo cengi_pp_h($s['nombre_ingenios'] ?: $s['institucion_otra']); ?></span>
                                <span class="pp-sub ins-contact"><span><span class="glyphicon glyphicon-envelope"></span><?php echo cengi_pp_h($s['correo']); ?></span>
                                    <span><span class="glyphicon glyphicon-earphone"></span><?php echo cengi_pp_h($s['telefono']); ?></span>
                                    <span>CUI <?php echo cengi_pp_h($s['cui_participante']); ?></span></span></div>
                        </div></td>
                        <td data-label="Curso"><span class="pp-sub"><?php echo cengi_pp_h(CENGI_INS_ORIGENES[$s['origen_inscripcion']??'sin_identificar']??'Registro anterior'); ?></span><b class="ins-course"><?php echo cengi_pp_h($s['plan_nombre']); ?></b><span class="pp-sub">Inicia <?php echo cengi_pp_fecha_corta($s['fecha_inicio']); ?></span></td>
                        <td data-label="Cuota"><span class="ins-money"><?php echo $s['monto'] === null ? 'Por definir' : cengi_ins_dinero($s['monto'], $s['moneda']); ?></span><span class="pp-sub"><?php echo cengi_pp_h(CENGI_INS_CUOTAS[$s['cuota']][0] ?? '—'); ?></span><span class="pp-sub">Inscripción #<?php echo (int) $s['id_solicitud']; ?></span></td>
                        <td data-label="Quién paga"><span class="ins-payer"><?php echo ['Ingenio' => 'Su ingenio', 'Exonerado' => 'Sin costo', 'Propio' => 'Pago propio'][$s['tipo_pago']]; ?></span>
                            <div class="ins-badge-line"><?php echo cengi_pp_badge(CENGI_INS_ESTADOS_PAGO, cengi_ins_estado_cobro($s)); ?></div>
                            <?php if ($s['tipo_pago'] === 'Propio'): ?>
                                <?php if (!empty($s['comprobante_ruta']) || (string) $s['boleta'] !== ''): ?>
                                    <span class="ins-boleta"><span class="glyphicon glyphicon-paperclip" aria-hidden="true"></span>
                                        <?php echo (string) $s['boleta'] !== '' ? 'Boleta ' . cengi_pp_h($s['boleta']) : 'Boleta recibida'; ?><?php echo $s['pago_fecha'] ? ' · ' . cengi_pp_fecha($s['pago_fecha']) : ''; ?><?php echo $s['monto_comprobante'] !== null ? ' · ' . cengi_ins_dinero($s['monto_comprobante'], $s['moneda']) : ''; ?>
                                        <?php if (!empty($s['comprobante_ruta'])): ?><a target="_blank" rel="noopener" href="comprobante_inscripcion.php?s=<?php echo (int) $s['id_solicitud']; ?>">Ver</a><?php endif; ?></span>
                                <?php else: ?>
                                    <span class="pp-sub">Sin boleta adjunta</span>
                                <?php endif; ?>
                            <?php elseif ($s['tipo_pago'] === 'Ingenio'): ?>
                                <span class="pp-sub"><?php echo $s['proforma_id'] ? 'Proforma N.° ' . (int) $s['proforma_numero'] . ($s['proforma_vence'] ? ' · vence ' . cengi_pp_fecha($s['proforma_vence']) : '') : 'Sin proforma aún'; ?><?php echo $s['grupo_pago_id'] && count($gruposPendientes[(int) $s['grupo_pago_id']] ?? []) > 1 ? ' · grupo de ' . count($gruposPendientes[(int) $s['grupo_pago_id']]) . ' por validar' : ''; ?></span>
                            <?php endif; ?>
</td>
                        <td data-label="Etapa"><span class="pp-sub">Validó: <?= cengi_pp_h($s['validacion_ingenio_por']?:$s['pago_verificado_por']) ?></span><span class="pp-sub"><?= cengi_pp_h($s['validacion_ingenio_nota']) ?></span><?php [$valTexto,$valColor]=cengi_ins_validacion_vista($s); ?><span class="ins-validation <?= $valColor ?>"><?= cengi_pp_h($valTexto) ?></span><?php echo cengi_pp_badge(CENGI_INS_ETAPAS, $etapa); ?>
                            <span class="pp-sub ins-detail"><?php
                                $req = cengi_ins_requisito($s);
                                echo cengi_pp_h($etapa === 'asignado' ? 'Validó: ' . ($s['validacion_ingenio_por'] ?: ($s['pago_verificado_por'] ?: 'sin costo')) . ' · Asignó: ' . $s['asignado_por'] . ' · ' . cengi_pp_fecha($s['asignado_en'])
                                    : ($etapa === 'rechazado' ? $s['pago_nota'] : $req['texto']));
                            ?></span></td>
                        <td class="is-actions"><?php if ($vistaInteresados): ?><?php require __DIR__ . '/interesado_acciones.php'; ?><?php else: ?><?php if ($puedeGestionar): ?><div class="ins-actions">
                            <?php if ($etapa === 'espera'): ?>
                                <form method="post" class="cengi-inline-form" onsubmit="return confirm('El curso tiene el cupo máximo lleno. ¿Asignar de todos modos?');"><input type="hidden" name="csrf" value="<?php echo cengi_pp_h($_SESSION['ins_csrf']); ?>"><input type="hidden" name="accion" value="asignar"><input type="hidden" name="id" value="<?php echo (int) $s['id_solicitud']; ?>"><button type="submit" class="ins-btn is-primary"><span class="glyphicon glyphicon-log-in"></span>Asignar</button></form>
                            <?php endif; ?>
                            <?php if ($etapa === 'asignado'): ?>
                                <button type="button" class="ins-btn is-warn" data-toggle="modal" data-target="#motivo-modal" data-accion="baja" data-id="<?php echo (int) $s['id_solicitud']; ?>" data-titulo="<?php echo cengi_pp_h('Dar de baja: ' . $s['nombre_participante']); ?>"><span class="glyphicon glyphicon-log-out"></span>Dar de baja</button>
                            <?php elseif ($etapa === 'rechazado'): ?>
                                <form method="post" class="cengi-inline-form"><input type="hidden" name="csrf" value="<?php echo cengi_pp_h($_SESSION['ins_csrf']); ?>"><input type="hidden" name="accion" value="reactivar"><input type="hidden" name="id" value="<?php echo (int) $s['id_solicitud']; ?>"><button type="submit" class="ins-btn"><span class="glyphicon glyphicon-repeat"></span>Reactivar</button></form>
                            <?php else: ?>
                                <button type="button" class="ins-btn is-icon is-danger" data-toggle="modal" data-target="#motivo-modal" data-accion="rechazar" data-id="<?php echo (int) $s['id_solicitud']; ?>" data-titulo="<?php echo cengi_pp_h('Rechazar inscripción: ' . $s['nombre_participante']); ?>" title="Rechazar inscripción" aria-label="Rechazar inscripción"><span class="glyphicon glyphicon-remove"></span></button>
                            <?php endif; ?>
                        </div><?php endif; ?><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div></section>
</main>

<div class="modal fade cengi-course-form-modal pp-modal" id="motivo-modal" tabindex="-1" role="dialog" aria-labelledby="motivo-title">
    <div class="modal-dialog" role="document"><div class="modal-content"><form method="post"><input type="hidden" name="csrf" value="<?php echo cengi_pp_h($_SESSION['ins_csrf']); ?>">
        <input type="hidden" name="accion" id="motivo-accion"><input type="hidden" name="id" id="motivo-id">
        <div class="modal-header"><button type="button" class="close" data-dismiss="modal" aria-label="Cerrar"><span>&times;</span></button><h4 class="modal-title" id="motivo-title"></h4></div>
        <div class="modal-body"><div class="form-group"><label for="motivo-texto">Motivo (se le comunica al participante)</label><input type="text" class="form-control" id="motivo-texto" name="motivo" maxlength="500" required></div>
            <p class="pp-sub">Si el curso tiene lista de espera, entra la primera persona validada.</p></div>
        <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">Cancelar</button><button type="submit" class="btn btn-danger">Confirmar</button></div>
    </form></div></div>
</div>
<script>
$('#motivo-modal').on('show.bs.modal', function (e) {
    var t = e.relatedTarget;
    document.getElementById('motivo-accion').value = t.getAttribute('data-accion');
    document.getElementById('motivo-id').value = t.getAttribute('data-id');
    document.getElementById('motivo-title').textContent = t.getAttribute('data-titulo');
    document.getElementById('motivo-texto').value = '';
});
</script>
</body>
</html>
