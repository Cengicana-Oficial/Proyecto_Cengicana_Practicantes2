<?php
/** Navegación del proceso de inscripción, con el mismo contexto en todas sus vistas. */
function cengi_ins_nav($activa='inscripciones') {
    if($activa==='pagos' && ($_GET['tab']??'')==='proformas')$activa='proformas';
    $context=[];
    foreach(['plan','curso','anio'] as $key) if(!empty($_GET[$key])) $context[$key]=(int)$_GET[$key];
    if(isset($_GET['q'])&&is_string($_GET['q'])&&$_GET['q']!=='') $context['q']=$_GET['q'];
    $tabs=[['interesados','Interesados',['vista'=>'interesados'],'user']];
    if(cengi_ins_puede_ver_pagos()) {
        $tabs[]=['pagos','Validaciones y pagos',['vista'=>'pagos'],'credit-card'];
    }
    $tabs[]=['inscripciones','Inscritos',['vista'=>'inscripciones'],'inbox'];
    if(cengi_ins_puede_ver_pagos()) {
        $tabs[]=['proformas','Proformas',['vista'=>'pagos','tab'=>'proformas'],'folder-open'];
        $tabs[]=['cobros','Cobros pendientes',['vista'=>'inscripciones','cobro'=>'pendiente','pagador'=>'Propio'],'usd'];
    }
    if (cengi_ins_puede_validar_pagos()) $tabs[]=['recibos','Recibos contables',['vista'=>'recibos'],'file'];
    echo '<nav class="pp-subnav cengi-nav-barra" aria-label="Inscripciones y pagos">';
    foreach($tabs as [$key,$label,$params,$icon]) {
        $query=http_build_query(array_merge($context,$params));
        echo '<a href="'.htmlspecialchars('inscripciones.php'.($query?'?'.$query:''),ENT_QUOTES,'UTF-8').'"'.($key===$activa?' class="is-active" aria-current="page"':'').'><span class="glyphicon glyphicon-'.$icon.'" aria-hidden="true"></span> '.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'</a>';
    }
    echo '</nav>';
}

/** Reutiliza la pantalla financiera con sus acciones y descargas en la ruta de Inscripciones. */
function cengi_ins_pago_html($html) {
    if(stripos($html,'<html')===false) return $html; // Descargas conservan sus bytes originales.
    $html=preg_replace('/((?:href|action|value)=["\'])pagos_inscripcion\.php\?/i','$1inscripciones.php?vista=pagos&amp;',$html);
    $html=str_replace('href="pagos_inscripcion.php"','href="inscripciones.php?vista=pagos"',$html);
    // Los formularios GET reconstruyen la consulta; conserva la vista financiera.
    $html=preg_replace('/(<form\b[^>]*method="get"[^>]*>)/i','$1<input type="hidden" name="vista" value="pagos">',$html);
    return cengi_ins_post_tokens($html);
}

function cengi_ins_post_tokens($html) {
    if(stripos($html,'<html')===false || empty($_SESSION['ins_csrf']))return $html;
    $token=htmlspecialchars($_SESSION['ins_csrf'],ENT_QUOTES,'UTF-8');
    return preg_replace('/(<form\b[^>]*method="post"[^>]*>)/i','$1<input type="hidden" name="csrf" value="'.$token.'">',$html);
}

function cengi_ins_pagos_directos(PDO $db, $limitado, $planFiltro, $busqueda) {
    if($planFiltro) return;
    $params=[];$where="s.planificacion_id IS NULL AND s.estado <> 'Rechazado'";
    if(!empty($_GET['curso'])) { $where.=' AND s.curso_id=?';$params[]=(int)$_GET['curso']; }
    if($busqueda!=='') { $where.=' AND (s.nombre_participante LIKE ? OR s.cui_participante LIKE ? OR c.nombre_cursos LIKE ?)';for($n=0;$n<3;$n++)$params[]='%'.$busqueda.'%'; }
    $q=$db->prepare('SELECT s.*,c.nombre_cursos,i.nombre_ingenios FROM solicitudes_inscripcion s JOIN cursos c ON c.id=s.curso_id LEFT JOIN ingenios i ON i.id=s.ingenio_id WHERE '.$where.' ORDER BY s.id_solicitud DESC');$q->execute($params);
    $rows=array_filter($q->fetchAll(PDO::FETCH_ASSOC),static function($s)use($limitado){return $limitado===null||cengi_texto_normalizado($s['nombre_ingenios']??'')===cengi_texto_normalizado($limitado);});
    if(!$rows) return;
    echo '<section class="pp-card"><h2>Pagos de cursos directos</h2><p>Cada inscripción conserva su cuota y pago. Las órdenes grupales de convocatorias se gestionan abajo.</p><div class="pp-table-wrap"><table class="cengi-org-table"><thead><tr><th>Participante</th><th>Curso</th><th>Cuota</th><th>Cobro</th><th>Gestión</th></tr></thead><tbody>';
    foreach($rows as $s) echo '<tr><td>'.cengi_pp_h($s['nombre_participante']).'</td><td>'.cengi_pp_h($s['nombre_cursos']).'</td><td>'.($s['monto']===null?'Por definir':cengi_ins_dinero($s['monto'],$s['moneda'])).'</td><td>'.cengi_pp_badge(CENGI_INS_ESTADOS_PAGO,cengi_ins_estado_cobro($s)).'</td><td><a class="btn btn-default btn-sm" href="inscripcion_detalle.php?id='.(int)$s['id_solicitud'].'">Validar / registrar pago</a></td></tr>';
    echo '</tbody></table></div></section>';
}
