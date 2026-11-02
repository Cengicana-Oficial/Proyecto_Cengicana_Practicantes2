<?php
require_once __DIR__.'/instructor_cv_helpers.php';
require_once __DIR__.'/planificacion_helpers.php';
require_once __DIR__.'/../login/config/session.php';cengi_session_start();
header('Referrer-Policy: no-referrer');header('Cache-Control: no-store, private');header('X-Robots-Tag: noindex, nofollow');
$db=conectar();$token=(string)($_GET['token']??'');$interno=$token==='';$error='';$mensaje='';
$h=static function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};
if($interno){require_once __DIR__.'/revisar_permisos.php';cengi_require_gestionar_instructores();$id=(int)($_GET['id']??0);$q=$db->prepare('SELECT id,nombre,especialidad,institucion FROM instructores WHERE id=?');$q->execute([$id]);$perfilCv=$q->fetch(PDO::FETCH_ASSOC);if(!$perfilCv){http_response_code(404);exit('Instructor no disponible.');}}
else{
 $confirmation='cv_publico_'.hash('sha256',$token);
 if(isset($_GET['enviado'])&&!empty($_SESSION['cv_confirmado_'.$confirmation])){$cvEstado='enviado';require __DIR__.'/instructor_cv_estado.php';exit;}
 $link=cengi_cv_enlace($db,$token);if(!$link){http_response_code(404);$cvEstado='no_disponible';require __DIR__.'/instructor_cv_estado.php';exit;}$id=(int)$link['instructor_id'];}
$csrfKey='cv_publico_'.hash('sha256',$token);if(empty($_SESSION[$csrfKey]))$_SESSION[$csrfKey]=bin2hex(random_bytes(32));
if(!$interno&&$_SERVER['REQUEST_METHOD']==='POST'){
 $cargasNuevas=[];
 try{
  if(!hash_equals($_SESSION[$csrfKey],(string)($_POST['csrf']??'')))throw new InvalidArgumentException('Recarga el formulario antes de guardar.');
  $final=($_POST['accion']??'')==='enviar';if(!in_array($_POST['accion']??'',['borrador','enviar'],true))throw new InvalidArgumentException('Acción inválida.');
  $db->beginTransaction();$idGuardado=cengi_cv_guardar_publico($db,$token,$_POST,$final);cengi_cv_cargar_foto($db,$idGuardado,$_FILES,$cargasNuevas,'Instructor (enlace individual)');$db->commit();$cargasNuevas=[];
  if($final){$_SESSION['cv_confirmado_'.$csrfKey]=true;header('Location: instructor_cv.php?token='.rawurlencode($token).'&enviado=1');exit;}
  header('Location: instructor_cv.php?token='.rawurlencode($token).'&guardado=1');exit;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();cengi_cv_limpiar_cargas($cargasNuevas);$error=$e instanceof InvalidArgumentException?$e->getMessage():'No fue posible guardar el formulario. Intenta de nuevo.';if(!$e instanceof InvalidArgumentException)error_log('CV instructor: '.$e->getMessage());}
}
if($interno&&$_SERVER['REQUEST_METHOD']==='POST'){
 $cargasInternas=[];
 try{
  if(!hash_equals($_SESSION[$csrfKey],(string)($_POST['csrf']??''))||($_POST['accion']??'')!=='editar_cv')throw new InvalidArgumentException('Recarga la ficha antes de guardar.');
  $db->beginTransaction();cengi_cv_editar_interno($db,$id,$_POST,$_POST['revision']??'',cengi_pp_usuario_nombre());cengi_cv_cargar_documentos($db,$id,$_FILES,$cargasInternas,cengi_pp_usuario_nombre());cengi_cv_cargar_foto($db,$id,$_FILES,$cargasInternas,cengi_pp_usuario_nombre());$db->commit();$cargasInternas=[];header('Location: instructor_cv.php?id='.$id.'&actualizado=1');exit;
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();cengi_cv_limpiar_cargas($cargasInternas);$error=$e instanceof InvalidArgumentException?$e->getMessage():'No fue posible guardar la ficha. Intenta nuevamente; si persiste, informa a Administración.';if(!$e instanceof InvalidArgumentException)error_log('CV instructor (edición interna): '.$e->getMessage());}
}
$cv=cengi_cv_leer($db,$id);$valores=$cv['valores']??[];
if(!$interno&&!empty($link['borrador_datos']))$valores=json_decode($link['borrador_datos'],true)?:$valores;
if((!$interno||!empty($_GET['editar']))&&$_SERVER['REQUEST_METHOD']==='POST')foreach(cengi_cv_campos() as $campos)foreach($campos as $key=>$meta)if(isset($_POST[$key])&&is_scalar($_POST[$key]))$valores[$key]=(string)$_POST[$key];
$editarCv=$interno&&!empty($_GET['editar'])&&(bool)$cv;
$modoEdicion=!$interno||$editarCv;
$cursosCv=$interno?cengi_cv_cursos_asignados($db,$id):[];
$documentosCv=$interno?cengi_cv_documentos($db,$id):[];
$secciones=cengi_cv_campos();
$nombreCv=trim((string)($valores['nombre']??($perfilCv['nombre']??'')));
$inicialesCv='';foreach(array_slice(preg_split('/\s+/',trim($nombreCv)),0,2) as $parte){if($parte!=='')$inicialesCv.=mb_strtoupper(mb_substr($parte,0,1));}
if($inicialesCv==='')$inicialesCv='CV';
$estadoCv=['borrador'=>'Borrador','enviado'=>'Pendiente de revisión','validado'=>'Validado por Capacitación'][$cv['estado']??'']??'Por completar';
$fotoCv=!empty($valores['foto'])?'instructor_cv_foto.php?'.($interno?'id='.$id:'token='.rawurlencode($token)):'';
$cssCv=(int)filemtime(__DIR__.'/css/instructor_cv.css');
// Vista de consulta (interna, sin editar): distribución compacta en dos columnas y ficha rápida en el encabezado.
$vistaCv=$interno&&!$modoEdicion;
$etiquetaCv=static function($label)use($vistaCv){return $vistaCv?cengi_cv_etiqueta($label):$label;};
$fechaCv=static function($v,$hora=false){return $v?date($hora?'d/m/Y H:i':'d/m/Y',strtotime((string)$v)):'';};
// Valor de solo lectura: listas para textos de varias líneas, enlaces para correo y teléfono.
$valorCv=static function($key,$valor)use($h){
    $lineas=cengi_cv_lineas($valor);
    if(count($lineas)>1)return '<ul class="cv-lista">'.implode('',array_map(static function($l)use($h){return '<li>'.$h($l).'</li>';},$lineas)).'</ul>';
    if($key==='linkedin'&&filter_var($valor,FILTER_VALIDATE_URL))return '<p><a href="'.$h($valor).'" target="_blank" rel="noopener noreferrer">'.$h(cengi_cv_linkedin_corto($valor)).' ↗</a></p>';
    if($key==='correo'&&filter_var($valor,FILTER_VALIDATE_EMAIL))return '<p><a href="mailto:'.$h($valor).'">'.$h($valor).'</a></p>';
    if(in_array($key,['telefono','telefono_laboral'],true))return '<p><a href="tel:'.$h(preg_replace('/[^\d+]/','',$valor)).'">'.$h($valor).'</a></p>';
    if($key==='anios_docencia'&&is_numeric($valor))return '<p>'.$h(cengi_cv_anios($valor)).'</p>';
    return '<p>'.$h($valor).'</p>';
};
$datoCv=static function($k)use($valores){return trim((string)($valores[$k]??''));};
$resumenCv=array_values(array_filter([
    $datoCv('titulo')!==''?['education',$datoCv('titulo').($datoCv('institucion_egreso')!==''?' · '.$datoCv('institucion_egreso'):'')]:null,
    $datoCv('lugar_trabajo')!==''?['briefcase',$datoCv('lugar_trabajo')]:null,
    is_numeric($datoCv('anios_docencia'))?['time',cengi_cv_anios($datoCv('anios_docencia')).' de docencia']:null,
]));
// Distribución de la vista: secciones cortas en media columna; competencias a lo ancho.
$anchoSeccion=['Competencias y experiencia profesional'=>true];
$iconoCv=static function($n){
    $p=['education'=>'<path d="M22 10 12 5 2 10l10 5 10-5z"/><path d="M6 12v5c3 2 9 2 12 0v-5"/>','briefcase'=>'<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
        'time'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>','mail'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'phone'=>'<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8 9.8a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2z"/>',
        'linkedin'=>'<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M8 10v7M8 7v.01M12 17v-4a2 2 0 0 1 4 0v4M12 10v7"/>',
        'id'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="9" cy="12" r="2.5"/><path d="M14 10h4M14 14h4"/>','edit'=>'<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>'][$n]??'';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$p.'</svg>';
};
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title><?=$h($nombreCv?:'Ficha profesional')?> · Cengicursos</title><link rel="stylesheet" href="css/instructor_cv.css?v=<?=$cssCv?>"></head><body>
<main class="cv-page<?=$vistaCv?' is-vista':''?>">
<div class="cv-brand"><span class="cv-brand-mark">C</span><div><strong>CENGICAÑA</strong><small>Transferencia de Tecnología y Capacitación</small></div><span class="cv-format">REGISTRO DE COMPETENCIAS · VF004</span></div>
<?php if($interno):?><nav class="cv-toolbar" aria-label="Acciones de la ficha"><a href="ficha_instructor.php?id=<?=$id?>&tab=documentos">← Volver al expediente</a><div><?php if($cv&&!$editarCv):?><a class="cv-tool-button" href="instructor_cv.php?id=<?=$id?>&editar=1"><?=$iconoCv('edit')?>Editar CV digital</a><?php elseif($editarCv):?><a class="cv-tool-button" href="instructor_cv.php?id=<?=$id?>">Cancelar edición</a><?php endif;?> <?php if($cv):?><a class="cv-pdf-button" href="instructor_cv_pdf.php?id=<?=$id?>" title="Descarga el CV con los últimos datos guardados"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M12 11v7m-3-3 3 3 3-3"/></svg><span>Generar PDF</span><small>CV DIGITAL</small></a><?php endif;?></div></nav><?php endif;?>
<header class="cv-hero"><div class="cv-hero-copy"><span class="cv-eyebrow"><?=$interno?'PERFIL PROFESIONAL':'REGISTRO DEL INSTRUCTOR'?></span><h1><?=$interno?$h($nombreCv?:'Ficha del instructor'):'Comparte tu experiencia.<br>Construyamos conocimiento.'?></h1><p><?=$interno?'Formación, trayectoria y competencias para la capacitación de la agroindustria azucarera.':'Completa tu ficha profesional para que Capacitación conozca tu formación y experiencia. Puedes guardar tus avances y continuar después.'?></p><span class="cv-status"><span></span><?=$h($estadoCv)?></span>
<?php if($vistaCv&&($resumenCv||$datoCv('correo')!==''||$datoCv('telefono')!==''||$datoCv('linkedin')!=='')):?><div class="cv-ficha">
<?php if($resumenCv):?><ul class="cv-ficha-datos"><?php foreach($resumenCv as [$ico,$txt]):?><li><?=$iconoCv($ico)?><span><?=$h($txt)?></span></li><?php endforeach;?></ul><?php endif;?>
<ul class="cv-ficha-contacto"><?php if($datoCv('correo')!==''):?><li><a href="mailto:<?=$h($datoCv('correo'))?>"><?=$iconoCv('mail')?><?=$h($datoCv('correo'))?></a></li><?php endif;?><?php if($datoCv('telefono')!==''):?><li><a href="tel:<?=$h(preg_replace('/[^\d+]/','',$datoCv('telefono')))?>"><?=$iconoCv('phone')?><?=$h($datoCv('telefono'))?></a></li><?php endif;?><?php if(filter_var($datoCv('linkedin'),FILTER_VALIDATE_URL)):?><li><a href="<?=$h($datoCv('linkedin'))?>" target="_blank" rel="noopener noreferrer" title="Perfil de LinkedIn"><?=$iconoCv('linkedin')?>LinkedIn</a></li><?php endif;?><?php if($datoCv('identidad')!==''):?><li title="Documento de identidad"><?=$iconoCv('id')?><?=$h($datoCv('identidad'))?></li><?php endif;?></ul>
</div><?php endif;?></div><div class="cv-avatar cv-profile-photo"><?php if($fotoCv):?><img src="<?=$h($fotoCv)?>" alt="Foto del instructor" data-foto-preview><?php else:?><span data-foto-iniciales><?=$h($inicialesCv)?></span><img alt="Vista previa de la foto del instructor" data-foto-preview hidden><?php endif;?></div></header>
<?php if($error):?><div class="cv-alert" role="alert"><strong>Revisa la información</strong><br><?=$h($error)?></div><?php endif;?><?php if(isset($_GET['guardado'])):?><div class="cv-ok" role="status"><strong>Tus avances están guardados.</strong> Puedes regresar con este mismo enlace para continuar.</div><?php endif;?>
<?php if(isset($_GET['actualizado'])):?><div class="cv-ok" role="status">Cambios del CV y documentos guardados correctamente.</div><?php endif;?>
<?php if($interno&&!$cv):?><div class="cv-note"><strong>Esta ficha todavía no tiene un CV digital.</strong><p>Desde el expediente puedes generar el enlace para que el instructor complete su información. El CV en PDF se conserva en Documentos.</p></div><?php endif;?>
<div class="cv-layout"><?php if(!$vistaCv):?><aside class="cv-sidebar"><div class="cv-nav-title">EN ESTA FICHA</div><nav aria-label="Secciones de la ficha"><?php $indice=0;foreach($secciones as $seccion=>$campos):$indice++;?><a href="#cv-seccion-<?=$indice?>"><span><?=str_pad((string)$indice,2,'0',STR_PAD_LEFT)?></span><?=$h($seccion)?></a><?php endforeach;?><?php if($interno):?><a href="#cv-documentos"><span>06</span>Documentos complementarios</a><a href="#cv-validacion"><span>07</span>Revisión de Capacitación</a><?php endif;?></nav><div class="cv-sidebar-note"><?=$interno?'Consulta del expediente digital. Los temas y la validación se administran desde la ficha del instructor.':'Tu información queda asociada a tu expediente y disponible para revisión de Capacitación.'?></div></aside><?php endif;?>
<div class="cv-content">
<?php if($modoEdicion):?><form method="post" enctype="multipart/form-data"><?php if($editarCv):?><input type="hidden" name="revision" value="<?=hash('sha256',$cv['datos'])?>"><?php endif;?><input type="hidden" name="csrf" value="<?=$h($_SESSION[$csrfKey])?>"><div class="cv-form-intro"><strong>Tu ficha, paso a paso</strong><span>Los campos con * son necesarios para enviar.</span></div><?php endif;?>
<?php if($modoEdicion):?><section class="cv-section cv-photo-section"><div><h2>Foto del instructor</h2><label class="cv-doc-control"><?=$fotoCv?'Cambiar foto (opcional)':'Agregar foto (opcional)'?><input type="file" name="foto_instructor" accept="image/jpeg,image/png" data-foto-input></label><small>JPG o PNG · máximo 5 MB. La vista previa aparece en el encabezado. La foto se guarda junto con el formulario.</small></div></section><?php endif;?>
<?php $indice=0;foreach($secciones as $seccion=>$campos):$indice++;?><section class="cv-section<?=!empty($anchoSeccion[$seccion])?' cv-span':''?>" id="cv-seccion-<?=$indice?>"><div class="cv-section-heading"><span class="cv-section-number"><?=str_pad((string)$indice,2,'0',STR_PAD_LEFT)?></span><h2><?=$h($seccion)?></h2></div><div class="cv-grid"><?php foreach($campos as $key=>$meta):$valorCampo=trim((string)($valores[$key]??''));?>
<div class="cv-field <?=$meta[1]>255?'cv-wide':''?>"><?php if(!$modoEdicion):?><span class="cv-field-label"><?=$h($etiquetaCv($meta[0]))?></span><?=$valorCampo!==''?$valorCv($key,$valorCampo):'<p class="cv-empty">No registrado</p>'?><?php else:?><label><span><?=$h($meta[0])?><?=$meta[2]?' <span class="cv-required">*</span>':''?></span>
<?php if($meta[1]>255):?><textarea name="<?=$key?>" maxlength="<?=$meta[1]?>" rows="4"><?=$h($valores[$key]??'')?></textarea><?php else:?><input type="<?=$key==='correo'?'email':($key==='anios_docencia'?'number':'text')?>" name="<?=$key?>" maxlength="<?=$meta[1]?>" <?=$key==='anios_docencia'?'min="0" max="80" step="0.5"':''?><?=$key==='linkedin'?' inputmode="url" autocomplete="url" placeholder="https://www.linkedin.com/in/tu-usuario"':''?> value="<?=$h($valores[$key]??'')?>"><?php endif;?></label><?php endif;?></div>
<?php endforeach;?></div></section><?php endforeach;?>
<?php if($interno):?><section class="cv-section" id="cv-documentos"><div class="cv-section-heading"><span class="cv-section-number">06</span><div><h2>Documentos de respaldo</h2><span class="cv-field-label">Opcionales después del CV digital. Puedes cargarlos o reemplazarlos al editar esta ficha.</span><a class="cv-doc-link" href="ficha_instructor.php?id=<?=$id?>&tab=documentos">Gestionar documentos →</a></div></div>
<?php if($editarCv):?><p class="cv-doc-help">Selecciona los documentos y pulsa «Guardar cambios del CV». Se guardarán en el expediente del instructor; si reemplazas un documento, la versión anterior se conserva. PDF, JPG o PNG · máximo 5 MB por documento.</p><?php endif;?>
<div class="cv-grid"><?php foreach(['carta'=>'Carta de comprobación de su formación','titulo'=>'Título o diploma'] as $tipoDoc=>$labelDoc):$documentoCv=$documentosCv[$tipoDoc]??null;?><div class="cv-doc-upload"><span class="cv-doc-tag"><?=$tipoDoc==='carta'?'FORMACIÓN':'ACREDITACIÓN'?></span><strong><?=$h($labelDoc)?></strong>
<?php if($documentoCv):?><p class="cv-doc-file">✓ <?=$h($documentoCv['nombre_original'])?></p><small>Documento registrado · <?=number_format($documentoCv['tamano']/1024,0)?> KB</small><?php if($interno):?><p><a class="cv-doc-link" href="documento_instructor.php?doc=<?=(int)$documentoCv['id']?>" target="_blank" rel="noopener">Ver documento →</a></p><?php endif;?>
<?php else:?><p class="cv-empty">Sin documento adjunto</p><?php endif;?>
<?php if($editarCv):?><label class="cv-doc-control"><?=$documentoCv?'Reemplazar documento (opcional)':'Seleccionar documento (opcional)'?><input type="file" name="<?=$tipoDoc==='carta'?'carta_formacion':'titulo_diploma'?>" accept="application/pdf,image/jpeg,image/png"></label><?php if($documentoCv):?><small>Si cargas otro archivo, se conserva la versión anterior en el historial.</small><?php endif;?><?php endif;?></div><?php endforeach;?></div></section><?php endif;?>
<?php if($editarCv):?><div class="cv-submit-card"><strong>Guardar cambios de Capacitación</strong><p>La actualización queda en el historial y vuelve a quedar pendiente de validación.</p><button type="submit" name="accion" value="editar_cv">Guardar cambios del CV</button></div></form><?php endif;?>
<?php if(!$interno):?><div class="cv-submit-card"><div><strong>¿Listo para compartir tu perfil?</strong><p>Puedes guardar un borrador o enviar tu ficha a Capacitación. Al enviarla, este enlace se cierra para proteger la información registrada.</p><small>El documento de identidad forma parte del registro de competencias.</small></div><div class="cv-actions"><button type="submit" name="accion" value="borrador" class="cv-secondary">Guardar y continuar después</button><button type="submit" name="accion" value="enviar">Enviar mi ficha a Capacitación →</button></div></div></form>
<?php else:?><section class="cv-section cv-review" id="cv-validacion"><div class="cv-section-heading"><span class="cv-section-number">07</span><div><span class="cv-eyebrow">USO INTERNO</span><h2>Revisión de Capacitación</h2></div></div><div class="cv-field"><span class="cv-field-label">Cursos a impartir · asignación automática</span><?php if(!$cursosCv):?><p class="cv-empty">Sin cursos asignados todavía. Se mostrarán automáticamente al asignarlos.</p><?php else:?><ul class="cv-cursos"><?php foreach($cursosCv as $cursoCv):?><li><?php if((int)$cursoCv['id']>0):?><a href="ver_participante_curso.php?id=<?=(int)$cursoCv['id']?>"><?=$h($cursoCv['nombre'])?></a><?php else:?><span><?=$h($cursoCv['nombre'])?> <small>(planificado)</small></span><?php endif;?><?php if($cursoCv['fecha']):?><time><?=$h($fechaCv($cursoCv['fecha']))?></time><?php endif;?></li><?php endforeach;?></ul><?php endif;?></div><div class="cv-review-footer"><div><span class="cv-field-label">Estado de la ficha</span><strong class="cv-estado-<?=$h($cv['estado']??'pendiente')?>"><?=$h($estadoCv)?></strong></div><div><span class="cv-field-label">Validado por</span><strong><?=$h($cv['validado_por']??'Pendiente de validación')?></strong><?php if(!empty($cv['validado_en'])):?><small><?=$h($fechaCv($cv['validado_en'],true))?></small><?php endif;?></div></div></section><?php endif;?>
</div></div><footer class="cv-footer">CENGICAÑA <span>Programa de Transferencia de Tecnología y Capacitación</span></footer>
</main><script>
(function(){var input=document.querySelector('[data-foto-input]');if(!input)return;var url;input.addEventListener('change',function(){var file=this.files[0];if(!file)return;if(file.size>5*1024*1024||!['image/jpeg','image/png'].includes(file.type)){alert('Selecciona una foto JPG o PNG de máximo 5 MB.');this.value='';return;}if(url)URL.revokeObjectURL(url);url=URL.createObjectURL(file);document.querySelector('[data-foto-preview]').src=url;document.querySelector('[data-foto-preview]').hidden=false;var initials=document.querySelector('[data-foto-iniciales]');if(initials)initials.hidden=true;});})();
</script></body></html>
