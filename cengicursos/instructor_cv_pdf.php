<?php
ob_start();
require_once __DIR__.'/revisar_permisos.php';
require_once __DIR__.'/instructor_cv_helpers.php';
require_once __DIR__.'/classes/export_helpers.php';
require_once __DIR__.'/vendor/autoload.php';
cengi_require_gestionar_instructores();
date_default_timezone_set('America/Guatemala');
$db=conectar();$id=(int)($_GET['id']??0);
$q=$db->prepare('SELECT nombre FROM instructores WHERE id=?');$q->execute([$id]);$nombre=$q->fetchColumn();
$cv=cengi_cv_leer($db,$id);
if(!$nombre||!$cv){http_response_code(404);exit('No hay CV digital disponible para generar el PDF.');}
$datos=$cv['valores'];$nombre=trim($datos['nombre']??'')?:$nombre;
$h=static function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};
$dato=static function($k)use($datos){return trim((string)($datos[$k]??''));};
$foto='';$url=cengi_normalizar_url_archivo($datos['foto']??'');$base=realpath(__DIR__.'/../uploads/instructores');$path=$url?realpath(__DIR__.'/..'.$url):false;
if($base&&$path&&is_file($path)&&strpos($path,$base.DIRECTORY_SEPARATOR)===0){$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);if(in_array($mime,['image/jpeg','image/png'],true))$foto='data:'.$mime.';base64,'.base64_encode(file_get_contents($path));}
$iniciales='';foreach(array_slice(preg_split('/\s+/',$nombre),0,2) as $parte){if($parte!=='')$iniciales.=mb_strtoupper(mb_substr($parte,0,1));}
$logoRuta=__DIR__.'/img/logo-comite-capacitacion-pdf.png'; // versión liviana: el logo original agota la memoria de Dompdf
$logo=is_file($logoRuta)?'data:image/png;base64,'.base64_encode((string)file_get_contents($logoRuta)):'';
$estado=['borrador'=>'Borrador','enviado'=>'Pendiente de revisión','validado'=>'Validado por Capacitación'][$cv['estado']]??$cv['estado'];
$colorEstado=['validado'=>'#2f6a12','enviado'=>'#7a6000','borrador'=>'#b34a00'][$cv['estado']]??'#55605a';
$cursos=cengi_cv_cursos_asignados($db,$id);
$fecha=static function($v,$hora=false){return $v?date($hora?'d/m/Y H:i':'d/m/Y',strtotime((string)$v)):'';};

// Valor de lectura, igual que en la vista web: listas para varios renglones y «3 años».
$valor=static function($key,$texto)use($h){
    $texto=trim((string)$texto);
    if($texto==='')return '<div class="empty">No registrado</div>';
    $lineas=cengi_cv_lineas($texto);
    if(count($lineas)>1)return '<div class="lista">'.implode('',array_map(static function($l)use($h){return '<div class="item">&bull;&nbsp; '.$h($l).'</div>';},$lineas)).'</div>';
    if($key==='anios_docencia')return '<div class="value">'.$h(cengi_cv_anios($texto)).'</div>';
    if($key==='linkedin'&&filter_var($texto,FILTER_VALIDATE_URL))return '<div class="value"><a class="enlace" href="'.$h($texto).'">'.$h(cengi_cv_linkedin_corto($texto)).'</a></div>';
    return '<div class="value">'.$h($texto).'</div>';
};
// Sección: campos cortos de dos en dos; largos uno debajo del otro (o en columnas si $columnas > 1).
$seccion=static function($numero,$titulo,array $campos,$columnas=1)use($h,$datos,$valor){
    $cortos=[];$largos=[];foreach($campos as $k=>$m){if($m[1]<=255)$cortos[$k]=$m;else $largos[$k]=$m;}
    $html='<div class="card"><div class="card-title"><span class="num">'.sprintf('%02d',$numero).'</span>'.$h($titulo).'</div>';
    if($cortos){
        $html.='<table class="fields">';
        foreach(array_chunk($cortos,2,true) as $par){
            $html.='<tr>';foreach($par as $k=>$m)$html.='<td><div class="label">'.$h(cengi_cv_etiqueta($m[0])).'</div>'.$valor($k,$datos[$k]??'').'</td>';
            if(count($par)===1)$html.='<td></td>';$html.='</tr>';
        }
        $html.='</table>';
    }
    if($largos){
        $html.='<table class="fields"><tr>';$i=0;
        foreach($largos as $k=>$m){
            if($i>0&&$i%$columnas===0)$html.='</tr><tr>';
            $html.='<td style="width:'.floor(100/$columnas).'%"><div class="label">'.$h(cengi_cv_etiqueta($m[0])).'</div>'.$valor($k,$datos[$k]??'').'</td>';$i++;
        }
        $html.='</tr></table>';
    }
    return $html.'</div>';
};
$secciones=cengi_cv_campos();$nombres=array_keys($secciones);
$ficha=array_values(array_filter([
    $dato('titulo')!==''?$dato('titulo').($dato('institucion_egreso')!==''?' · '.$dato('institucion_egreso'):''):null,
    $dato('lugar_trabajo')!==''?$dato('lugar_trabajo'):null,
    is_numeric($dato('anios_docencia'))?cengi_cv_anios($dato('anios_docencia')).' de docencia':null,
]));
// Contacto como HTML ya escapado (el de LinkedIn es un enlace clicable en el PDF).
$contacto=array_values(array_filter([
    $dato('correo')!==''?$h($dato('correo')):'',
    $dato('telefono')!==''?$h($dato('telefono')):'',
    filter_var($dato('linkedin'),FILTER_VALIDATE_URL)?'<a href="'.$h($dato('linkedin')).'" style="color:#d6ecbd;text-decoration:none">'.$h(cengi_cv_linkedin_corto($dato('linkedin'))).'</a>':'',
    $dato('identidad')!==''?'Doc. '.$h($dato('identidad')):'',
]));
ob_start();
?>
<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><style>
@page{margin:26px 30px 38px}
body{font-family:"DejaVu Sans",sans-serif;font-size:8.6px;color:#253024;line-height:1.4}
table{border-collapse:collapse;width:100%}
.masthead td{vertical-align:middle;padding:0 0 8px}
.masthead img{height:34px}
.brand{font-size:12px;font-weight:bold;letter-spacing:1.5px;color:#1f5632}
.brand small{display:block;font-size:7px;font-weight:normal;letter-spacing:0;color:#6b7a72}
.format{text-align:right;font-size:6.8px;color:#6b7a72;letter-spacing:.8px}
.hero{background:#1f5632;color:#fff}
.hero td{padding:14px 16px;vertical-align:middle}
.hero .photo{width:92px;padding-right:0}
.photo img{width:88px;height:106px;border:2px solid #A3D300}
.iniciales{width:88px;border:2px solid #A3D300;background:#2d6b3d}.iniciales td{height:102px;padding:0;text-align:center;vertical-align:middle;color:#fff;font-size:30px;font-weight:bold}
.eyebrow{font-size:6.8px;color:#D9F2A6;letter-spacing:1.6px}
.hero h1{font-size:20px;line-height:1.15;margin:4px 0 6px;color:#fff}
.estado{font-size:7px;font-weight:bold;background:#fff;padding:3px 8px}
.ficha{margin-top:8px;padding-top:7px;border-top:1px solid #3f7a4c;font-size:8.2px}
.ficha-datos{color:#fff;font-weight:bold}
.ficha-contacto{color:#d6ecbd;margin-top:3px}
.sep{color:#A3D300}
.franja td{height:4px;padding:0;font-size:1px}
.grid{margin-top:10px}
.grid td.col{width:50%;vertical-align:top;padding:0}
.grid td.izq{padding-right:6px}.grid td.der{padding-left:6px}
.card{border:1px solid #dfe6d8;border-top:3px solid #73BC25;padding:8px 10px 6px;margin-bottom:10px;page-break-inside:avoid}
.card-title{font-size:10px;font-weight:bold;color:#1f5632;padding-bottom:5px;margin-bottom:4px;border-bottom:1px solid #edf1e9}
.num{display:inline-block;width:18px;font-size:7.5px;color:#588E21}
.fields td{vertical-align:top;padding:3px 10px 4px 0;width:50%}
.label{font-size:6.6px;font-weight:bold;color:#6b7a72;text-transform:uppercase;letter-spacing:.4px}
.value{margin-top:1px;font-size:8.8px;word-wrap:break-word}
.enlace{color:#205535;text-decoration:none}
.lista{margin-top:1px}.item{font-size:8.6px;padding:1px 0}
.empty{margin-top:1px;color:#9aa398;font-size:8px;font-style:italic}
.cursos td{padding:4px 0;border-bottom:1px dashed #dfe6d8;font-size:8.6px;vertical-align:top}
.cursos td.f{text-align:right;color:#6b7a72;white-space:nowrap;width:62px}
.revision td{vertical-align:top;padding:3px 10px 2px 0;width:50%}
.revision strong{font-size:9px}
.pie{position:fixed;bottom:-24px;left:0;right:0;color:#849080;font-size:6.8px;border-top:1px solid #CED2D5;padding-top:4px}
</style></head><body>
<table class="masthead"><tr>
    <?php if($logo):?><td style="width:96px"><img src="<?=$logo?>" alt=""></td><?php endif;?>
    <td class="brand">CENGICAÑA<small>Transferencia de Tecnología y Capacitación</small></td>
    <td class="format">REGISTRO DE COMPETENCIAS · VF004<br>CV digital del instructor</td>
</tr></table>
<table class="hero"><tr>
    <td class="photo"><?php if($foto):?><img src="<?=$h($foto)?>" alt=""><?php else:?><table class="iniciales"><tr><td><?=$h($iniciales?:'CV')?></td></tr></table><?php endif;?></td>
    <td>
        <div class="eyebrow">FORMADORES · PERFIL PROFESIONAL</div>
        <h1><?=$h($nombre)?></h1>
        <span class="estado" style="color:<?=$colorEstado?>"><?=$h($estado)?></span>
        <?php if($ficha||$contacto):?><div class="ficha">
            <?php if($ficha):?><div class="ficha-datos"><?=implode(' <span class="sep">|</span> ',array_map($h,$ficha))?></div><?php endif;?>
            <?php if($contacto):?><div class="ficha-contacto"><?=implode(' <span class="sep">|</span> ',$contacto)?></div><?php endif;?>
        </div><?php endif;?>
    </td>
</tr></table>
<table class="franja"><tr><td style="background:#73BC25;width:25%"></td><td style="background:#A3D300;width:25%"></td><td style="background:#FFCC00;width:25%"></td><td style="background:#FF6B00;width:25%"></td></tr></table>

<table class="grid">
    <tr><td class="col izq"><?=$seccion(1,$nombres[0],$secciones[$nombres[0]])?></td><td class="col der"><?=$seccion(2,$nombres[1],$secciones[$nombres[1]])?></td></tr>
    <tr><td class="col" colspan="2"><?=$seccion(3,$nombres[2],$secciones[$nombres[2]],3)?></td></tr>
    <tr><td class="col izq"><?=$seccion(4,$nombres[3],$secciones[$nombres[3]])?></td><td class="col der"><?=$seccion(5,$nombres[4],$secciones[$nombres[4]])?></td></tr>
    <tr>
        <td class="col izq"><div class="card"><div class="card-title"><span class="num">06</span>Cursos a impartir</div>
            <?php if(!$cursos):?><div class="empty">Sin cursos asignados.</div><?php else:?><table class="cursos"><?php foreach($cursos as $curso):?><tr><td><?=$h($curso['nombre'])?><?=(int)$curso['id']<0?' <span class="empty">(planificado)</span>':''?></td><td class="f"><?=$h($fecha($curso['fecha']))?></td></tr><?php endforeach;?></table><?php endif;?>
        </div></td>
        <td class="col der"><div class="card" style="border-top-color:#1f5632"><div class="card-title"><span class="num">07</span>Revisión de Capacitación</div>
            <table class="revision"><tr>
                <td><div class="label">Estado de la ficha</div><strong style="color:<?=$colorEstado?>"><?=$h($estado)?></strong></td>
                <td><div class="label">Validado por</div><strong><?=$h($cv['validado_por']?:'Pendiente de validación')?></strong><?php if(!empty($cv['validado_en'])):?><div class="empty" style="font-style:normal"><?=$h($fecha($cv['validado_en'],true))?></div><?php endif;?></td>
            </tr></table>
        </div></td>
    </tr>
</table>
<div class="pie">CENGICAÑA · Programa de Transferencia de Tecnología y Capacitación · CV digital generado el <?=date('d/m/Y H:i')?></div>
</body></html>
<?php
$html=ob_get_clean();$options=new \Dompdf\Options();$options->set('isRemoteEnabled',false);$options->set('defaultFont','DejaVu Sans');
$pdf=new \Dompdf\Dompdf($options);$pdf->loadHtml($html,'UTF-8');$pdf->setPaper('letter','portrait');$pdf->render();
$canvas=$pdf->getCanvas();$canvas->page_text($canvas->get_width()-92,$canvas->get_height()-21,'Página {PAGE_NUM} de {PAGE_COUNT}',null,6.8,[0.52,0.56,0.5]);
$archivo=trim(preg_replace('/[^a-z0-9]+/','-',strtolower((string)iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$nombre))),'-');
cengi_export_enviar_pdf($pdf->output(),'cv-'.($archivo!==''?$archivo:'instructor-'.$id).'.pdf');
