<?php
require_once __DIR__.'/menu.php';
require_once __DIR__.'/planificacion_helpers.php';
require_once __DIR__.'/carga_cv_helpers.php';
cengi_require_ver_instructores();
if(!cengi_puede_gestionar_instructores()){http_response_code(403);exit('No tienes permiso para cargar CV.');}
$db=conectar();
if(empty($_SESSION['cv_masivo_csrf']))$_SESSION['cv_masivo_csrf']=bin2hex(random_bytes(32));
$error='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST')try{
    if(!hash_equals($_SESSION['cv_masivo_csrf'],(string)($_POST['csrf']??'')))throw new InvalidArgumentException('Recarga la página e intenta nuevamente.');
    $accion=$_POST['accion']??'';
    if($accion==='analizar'){
        unset($_SESSION['cv_masivo_borrador']);$f=$_FILES['archivo']??[];
        if(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($f['tmp_name']??'')||strtolower(pathinfo($f['name']??'',PATHINFO_EXTENSION))!=='xlsx')throw new InvalidArgumentException('Selecciona un archivo Excel (.xlsx) válido.');
        if(($f['size']??0)>5*1024*1024)throw new InvalidArgumentException('El archivo supera 5 MB.');
        $filas=cengi_cv_masivo_leer($f['tmp_name']);
        $_SESSION['cv_masivo_borrador']=['filas'=>$filas,'analisis'=>cengi_cv_masivo_analizar($db,$filas),'token'=>bin2hex(random_bytes(24)),'creado'=>time()];
        header('Location: carga_cv_instructores.php');exit;
    }elseif($accion==='confirmar'){
        $b=$_SESSION['cv_masivo_borrador']??null;
        if(!$b||time()-$b['creado']>1800||!hash_equals($b['token'],(string)($_POST['token']??'')))throw new InvalidArgumentException('La revisión venció o ya fue procesada. Vuelve a cargar el archivo.');
        $_SESSION['cv_masivo_resultado']=cengi_cv_masivo_guardar($db,$b['filas'],cengi_pp_usuario_nombre());unset($_SESSION['cv_masivo_borrador']);
        header('Location: carga_cv_instructores.php');exit;
    }elseif($accion==='descartar'){unset($_SESSION['cv_masivo_borrador']);header('Location: carga_cv_instructores.php');exit;}
    else throw new InvalidArgumentException('Acción no válida.');
}catch(Throwable $e){$error=$e instanceof InvalidArgumentException?$e->getMessage():'No se pudo procesar el archivo. Revisa que sea la plantilla Excel válida.';error_log('Carga CV digital: '.$e->getMessage());}
$b=$_SESSION['cv_masivo_borrador']??null;if($b&&time()-$b['creado']>1800){unset($_SESSION['cv_masivo_borrador']);$b=null;}
$resultado=$_SESSION['cv_masivo_resultado']??null;unset($_SESSION['cv_masivo_resultado']);
$detalle=$b['analisis']??($resultado['detalle']??[]);$cuentas=['lista'=>0,'error'=>0,'omitida'=>0];foreach($detalle as $r)$cuentas[$r['estado']]++;
// Campos de la plantilla, para la guía: obligatorios y opcionales (LINKEDIN es opcional aun como columna).
$camposCv=[];foreach(cengi_cv_campos() as $grupo)foreach($grupo as $k=>$m)$camposCv[strtoupper($k)]=$m;
$obligatorios=array_merge(['CODIGO_INSTRUCTOR'],array_keys(array_filter($camposCv,static function($m){return $m[2];})));
$opcionales=array_keys(array_filter($camposCv,static function($m){return !$m[2];}));
$h='cengi_pp_h';
$v=static function($archivo){return (int)(@filemtime(__DIR__.'/'.$archivo)?:1);};
?>
<!DOCTYPE html><html lang="es"><head><?php cengi_pp_head('Carga masiva de CV digital'); ?>
<link rel="stylesheet" href="css/necesidades_capacitacion.css?v=<?=$v('css/necesidades_capacitacion.css')?>"><link rel="stylesheet" href="css/carga_interesados.css?v=<?=$v('css/carga_interesados.css')?>"><link rel="stylesheet" href="css/carga_cv.css?v=<?=$v('css/carga_cv.css')?>"></head>
<body class="cengi-canvas cg-tema"><?php menu_render(); ?><main class="container nc-page ci-page ccv-page">
<?php cengi_nav_barra('carga_cv_instructores.php'); ?>
<?php cengi_banner(null,null,'Instructores · expediente digital',
    '<a class="nc-btn is-claro" href="plantilla_cv_instructores.php"><span class="glyphicon glyphicon-download-alt" aria-hidden="true"></span> Descargar plantilla</a>'); ?>
<?php if($error):?><div class="alert alert-danger" role="alert"><?=$h($error)?></div><?php endif;?>
<?php if($resultado):?><div class="ci-result ccv-ok" role="status"><span class="glyphicon glyphicon-ok-circle" aria-hidden="true"></span><div><strong><?=(int)$resultado['registrados']?> CV registrado<?=(int)$resultado['registrados']===1?'':'s'?></strong><p>Quedan como CV digitales recibidos, pendientes de validación por Capacitación.</p></div></div><?php endif;?>

<ol class="ccv-pasos" aria-label="Pasos de la carga">
    <li class="<?=!$b&&!$resultado?'is-actual':'is-hecho'?>"><b>1</b><span>Descarga la plantilla<small>Incluye los códigos del directorio</small></span></li>
    <li class="<?=!$b&&!$resultado?'is-actual':'is-hecho'?>"><b>2</b><span>Completa una fila por instructor<small>Con su código INS-000</small></span></li>
    <li class="<?=$b?'is-actual':($resultado?'is-hecho':'')?>"><b>3</b><span>Sube y revisa<small>Nada se guarda todavía</small></span></li>
    <li class="<?=$resultado?'is-hecho':''?>"><b>4</b><span>Confirma el registro<small>Quedan pendientes de validación</small></span></li>
</ol>

<div class="ccv-grid">
<section class="nc-panel">
    <h2>Sube el archivo</h2>
    <p class="ccv-muted">Una fila por instructor, identificado por su código. Hasta 500 filas y 5 MB. Si el instructor ya tiene CV digital o PDF, la fila se omite para conservarlo.</p>
    <form method="post" enctype="multipart/form-data" class="ccv-form"><input type="hidden" name="csrf" value="<?=$h($_SESSION['cv_masivo_csrf'])?>"><input type="hidden" name="accion" value="analizar">
        <label class="ci-upload ccv-upload"><span class="glyphicon glyphicon-cloud-upload" aria-hidden="true"></span><strong>Selecciona el Excel de la plantilla</strong><span>Formato .xlsx · hoja «CV digital»</span><input type="file" name="archivo" accept=".xlsx" required data-ccv-archivo><small data-ccv-nombre></small></label>
        <button class="nc-btn" type="submit"><span class="glyphicon glyphicon-search" aria-hidden="true"></span> Analizar y revisar</button>
    </form>
    <div class="ccv-aviso"><span class="glyphicon glyphicon-info-sign" aria-hidden="true"></span><p><b>El instructor debe existir en el directorio.</b> Esta carga registra su CV digital; no crea instructores. Si aún no está registrado, créalo en <a href="instructores.php">Instructores → Nuevo instructor</a> y usa el código que le asigne el sistema. No inventes códigos ni uses el de otra persona.</p></div>
</section>
<aside class="nc-panel ccv-guia">
    <h2>Columnas de la plantilla</h2>
    <h3>Obligatorias</h3>
    <p class="ccv-chips"><?php foreach($obligatorios as $c):?><code><?=$h($c)?></code><?php endforeach;?></p>
    <h3>Opcionales</h3>
    <p class="ccv-chips"><?php foreach($opcionales as $c):?><code class="is-opcional"><?=$h($c)?></code><?php endforeach;?></p>
    <div class="ccv-nota"><b>LINKEDIN</b> es opcional: escribe el enlace del perfil (por ejemplo <i>linkedin.com/in/usuario</i>). Solo se aceptan enlaces de linkedin.com. Si usas una plantilla descargada antes y no tiene esta columna, se acepta igual.</div>
    <ul class="ccv-reglas">
        <li>Documentos y teléfonos como texto, para conservar ceros iniciales.</li>
        <li>Listas (experiencia, cursos) en una misma celda, separadas con Alt + Enter.</li>
        <li>Años de docencia: número entre 0 y 80.</li>
        <li>Los cursos a impartir no se cargan: la plataforma los toma de las asignaciones.</li>
    </ul>
</aside>
</div>

<?php if($detalle):?><section class="nc-panel" id="revision">
<div class="nc-lista-head"><h2><?=$resultado?'Resultado de la carga':'Revisión por instructor'?></h2><span><?=count($detalle)?> fila<?=count($detalle)===1?'':'s'?></span></div>
<div class="ci-counters"><div class="is-green"><b><?=$cuentas['lista']?></b><span><?=$resultado?'Registradas':'Listas para registrar'?></span></div><div class="is-orange"><b><?=$cuentas['error']?></b><span>Con errores</span></div><div><b><?=$cuentas['omitida']?></b><span>Omitidas (ya tienen CV)</span></div></div>
<div class="ci-table-wrap"><table class="table ccv-tabla"><thead><tr><th>Fila</th><th>Código</th><th>Instructor</th><th>Correo del CV</th><th>LinkedIn</th><th>Revisión</th></tr></thead><tbody>
<?php foreach($detalle as $r):$li=trim((string)($r['valores']['linkedin']??($r['datos']['linkedin']??'')));?><tr class="is-<?=$h($r['estado'])?>">
    <td><?=(int)$r['fila']?></td><td><code><?=$h($r['datos']['codigo_instructor']??'')?></code></td><td><?=$h($r['nombre']??($r['datos']['nombre']??''))?></td><td><?=$h($r['datos']['correo']??'')?></td>
    <td><?=$li!==''?'<span class="ccv-li" title="'.$h($li).'">'.$h(cengi_cv_linkedin_corto($li)).'</span>':'<span class="ccv-muted">—</span>'?></td>
    <td><span class="ci-badge is-<?=$h($r['estado'])?>"><?=$h($resultado&&$r['estado']==='lista'?'CV registrado; pendiente de validación.':$r['detalle'])?></span></td></tr><?php endforeach;?></tbody></table></div>
<?php if($b):?><div class="ci-confirm"><p>Solo se registrarán las filas válidas; las que tienen errores y los CV existentes se omiten. Los datos se comprueban de nuevo al confirmar. No se envían correos ni se modifican las asignaciones de cursos.</p><div class="ci-buttons">
<form method="post"><input type="hidden" name="csrf" value="<?=$h($_SESSION['cv_masivo_csrf'])?>"><input type="hidden" name="accion" value="confirmar"><input type="hidden" name="token" value="<?=$h($b['token'])?>"><button class="nc-btn" <?=$cuentas['lista']?'':'disabled'?>>Registrar <?=$cuentas['lista']?> CV digital<?=$cuentas['lista']===1?'':'es'?></button></form>
<form method="post"><input type="hidden" name="csrf" value="<?=$h($_SESSION['cv_masivo_csrf'])?>"><input type="hidden" name="accion" value="descartar"><button class="nc-btn is-linea">Descartar revisión</button></form></div></div><?php endif;?>
</section><?php endif;?>
</main>
<script>
(function(){var i=document.querySelector('[data-ccv-archivo]'),n=document.querySelector('[data-ccv-nombre]');if(i&&n)i.addEventListener('change',function(){n.textContent=this.files[0]?'Archivo: '+this.files[0].name:'';});
<?php if($b||$resultado):?>var r=document.getElementById('revision');if(r&&r.scrollIntoView)r.scrollIntoView({block:'start'});<?php endif;?>})();
</script>
</body></html>
