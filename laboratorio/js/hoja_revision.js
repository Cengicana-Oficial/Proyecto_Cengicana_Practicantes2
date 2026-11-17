/* Detalle en Validación técnica — mismo formato que htmlDetalleFormulario() de
   Prototipos/laboratorio-workflow-v3.html. Una hoja de trabajo con varios
   análisis (p. ej. pH + CE) genera un formulario por análisis; aquí se muestran
   todos juntos (una tabla por análisis, mismos controles, encabezado y curva)
   para validarlos en conjunto. Las muestras se pueden devolver a reanálisis
   por análisis. */
(function(){'use strict';
const node=document.getElementById('wf-review');if(!node)return;
const data=JSON.parse(document.getElementById('wf-review-data').textContent);
const V=window.LabVal,defs=window.LabHojas,esc=V.esc;
V.setMotivos(data.motivos);
// "Lote <código>" sin repetir la palabra cuando el código ya la trae ("Lote-001").
const etiquetaLote=c=>{const s=String(c??'').trim();return /^lote\b/i.test(s)?s:'Lote '+s;};
const forms=data.formularios||[];
if(!forms.length){node.innerHTML='<div class="cengi-empty">No se encontraron formularios.</div>';return;}
const cap0=forms[0].cap,h=defs.hojas[cap0.hoja];
if(!h){node.innerHTML='<div class="cengi-empty">La hoja "'+esc(cap0.hoja)+'" no está definida en hojas_definiciones.js.</div>';return;}
let ctx0=V.contexto(cap0);const ids=forms.map(f=>f.id),multi=forms.length>1;
/* Parámetros de la corrida que el técnico puede corregir al validar (campo con editableVal en hojas_definiciones.js). */
const editables=V.esLegado(cap0)?[]:(h.encabezado?h.encabezado.campos:[]).filter(c=>c.editableVal);
const encOriginal={};editables.forEach(c=>encOriginal[c.k]=String((cap0.encabezado||{})[c.k]??''));
const encEditado=Object.assign({},encOriginal);
const modo=data.modo;let verFormulas=true;const marcas={};// clave "idFormulario:idMuestra"
const texturas={};
const fx=(x,d)=>defs.fx(x,d);
const unicos=arr=>arr.filter((x,i,a)=>x!=null&&x!==''&&a.indexOf(x)===i);
const rangoDe=f=>({min:f.limite_min,max:f.limite_max,unidad:f.unidad});
// Capturado por el analista: columnas de la hoja y parámetros de la corrida (p. ej. factor de temperatura CE).
const capturadas=new Set(h.cols.filter(c=>!c.auto).map(c=>c.k).concat((h.encabezado?h.encabezado.campos:[]).map(c=>c.k)));

/* una sección por análisis, en el orden de las vistas de la hoja */
const secciones=[];
forms.forEach(f=>{
  const nombre=f.cap.analisis||f.analisis_nombre||'Análisis';
  let s=secciones.find(x=>x.analisis===nombre);
  if(!s){const v=V.vistaDe(f.cap);s={analisis:nombre,v,cols:v.cols.map(c=>Object.assign({},c,{calc:c.cap?false:!capturadas.has(c.k)})),forms:[],filas:[]};secciones.push(s);}
  s.forms.push(f);
});
const legado=V.esLegado(cap0);
const orden=s=>{const i=V.vistasDe(cap0).indexOf(s.v);return i<0?999:i;};
secciones.sort((a,b)=>orden(a)-orden(b)||a.analisis.localeCompare(b.analisis));
let todasFilas=[],muestrasUnidas=[];
/* Calcula (o recalcula tras editar un parámetro de la corrida) los resultados de todas las muestras. */
function recalcular(){
  forms.forEach(f=>{f.cap.encabezado=Object.assign({},f.cap.encabezado||{},encEditado);});
  ctx0=V.contexto(cap0);
  secciones.forEach(s=>{s.filas=[];s.forms.forEach(f=>V.muestrasCalculadas(f.cap,rangoDe(f)).forEach(x=>s.filas.push(Object.assign(x,{fid:f.id,lote:f.lote}))));s.filas.sort(ordenMuestra);});
  todasFilas=secciones.flatMap(s=>s.filas);
  const mapa=new Map();
  secciones.forEach(s=>s.filas.forEach(x=>{let r=mapa.get(x.id);if(!r){r={id:x.id,info:x.info,lote:x.lote,por:{}};mapa.set(x.id,r);}r.por[s.analisis]=x;}));
  muestrasUnidas=[...mapa.values()].sort(ordenMuestra);
}
const encCambiado=()=>editables.filter(c=>encEditado[c.k]!==encOriginal[c.k]);
const claveMarca=x=>x.fid+':'+x.id;

const celda=(m,c,x)=>{const val=m[c.k],esNum=typeof val==='number';
  if(c.k==='clase'&&cap0.hoja==='Suelo|Textura'&&x){
    const row=forms.find(f=>f.id===x.fid).cap.filas[x.id],key=claveMarca(x);
    const comentario=row.claseManualMotivo?'<div class="vt-textura-nota">'+esc(row.claseManualMotivo)+'</div>':'';
    const autor=row.claseManualUsuario?'<div class="small muted">'+esc(row.claseManualUsuario)+' · '+esc(row.claseManualFecha||'')+'</div>':'';
    const editor=modo==='revisor'?'<details class="vt-textura-editor"'+(texturas[x.fid]?.[x.id]?' open':'')+'><summary>Editar</summary><div class="vt-textura-campos"><span class="small muted">Automática: '+esc(m.claseAutomatica||'—')+'</span><label>Clase textural<select class="vt-select" data-textura="'+key+'"><option value="">Usar automática</option>'+defs.texturas.map(t=>'<option value="'+esc(t)+'"'+(row.claseManual===t?' selected':'')+'>'+esc(t)+'</option>').join('')+'</select></label><label>Motivo de la edición<textarea rows="2" maxlength="1000" data-textura-motivo="'+key+'" placeholder="Explique por qué cambia la textura">'+esc(row.claseManualMotivo||'')+'</textarea></label><span class="small muted">Se guarda al validar.</span></div></details>':'';
    return '<td class="res txt vt-textura-celda"><strong>'+esc(val||'—')+'</strong>'+editor+comentario+autor+'</td>';
  }
  const txt=esNum?fx(val,c.d):(val==null||val===''?'—':esc(val));
  return '<td class="num'+(c.res?' res':'')+(esNum||val==null||val===''?'':' txt')+'">'+txt+'</td>';};
const thClase=c=>c.calc===true?'th-calc':c.calc===false?'th-cap':'';
/* curva: puntos, recta por el origen y R², como graficoCurva() del prototipo */
function graficoCurva(reg,puntos,unidadX){
  const pts=(puntos||[]).map(q=>({x:defs.num(q.c),y:defs.num(q.a)})).filter(q=>q.x!=null&&q.y!=null);
  const W=460,H=250,L=52,R=16,T=18,B=40;
  if(pts.length<2)return '<div class="ws-chart-empty">Sin datos suficientes para graficar</div>';
  const xs=pts.map(p=>p.x),ys=pts.map(p=>p.y);
  const x0=Math.min(0,...xs),x1=Math.max(...xs)||1,y0=Math.min(0,...ys),y1=Math.max(...ys)||1;
  const sx=x=>L+(x-x0)/(x1-x0||1)*(W-L-R),sy=y=>H-B-(y-y0)/(y1-y0||1)*(H-T-B);
  const ticks=(a,b)=>[0,.25,.5,.75,1].map(t=>a+(b-a)*t);
  let s='<svg viewBox="0 0 '+W+' '+H+'" class="ws-chart" role="img" aria-label="Curva de calibración">';
  ticks(y0,y1).forEach(y=>{s+='<line x1="'+L+'" x2="'+(W-R)+'" y1="'+sy(y)+'" y2="'+sy(y)+'" stroke="#E3F1D3"/><text x="'+(L-6)+'" y="'+(sy(y)+3)+'" text-anchor="end" font-size="9.5" fill="#636A6F">'+fx(y,3)+'</text>';});
  ticks(x0,x1).forEach(x=>{s+='<text x="'+sx(x)+'" y="'+(H-B+14)+'" text-anchor="middle" font-size="9.5" fill="#636A6F">'+fx(x,2)+'</text>';});
  s+='<line x1="'+L+'" x2="'+(W-R)+'" y1="'+(H-B)+'" y2="'+(H-B)+'" stroke="#858C91"/><line x1="'+L+'" x2="'+L+'" y1="'+T+'" y2="'+(H-B)+'" stroke="#858C91"/>';
  if(reg)s+='<line x1="'+sx(x0)+'" y1="'+sy(reg.m0*x0+(reg.b0||0))+'" x2="'+sx(x1)+'" y2="'+sy(reg.m0*x1+(reg.b0||0))+'" stroke="#5e9b1d" stroke-width="2" stroke-dasharray="5 4"/>';
  pts.forEach(p=>{s+='<circle cx="'+sx(p.x)+'" cy="'+sy(p.y)+'" r="4" fill="#636A6F" stroke="#fff" stroke-width="1.5"><title>'+esc(p.x+' ; '+p.y)+'</title></circle>';});
  s+='<text x="'+((L+W-R)/2)+'" y="'+(H-6)+'" text-anchor="middle" font-size="10.5" font-weight="700" fill="#636A6F">Concentración ('+esc(unidadX||'ppm')+')</text>';
  s+='<text x="12" y="'+((T+H-B)/2)+'" text-anchor="middle" font-size="10.5" font-weight="700" fill="#636A6F" transform="rotate(-90 12 '+((T+H-B)/2)+')">Absorbancia</text>';
  if(reg)s+='<text x="'+(L+10)+'" y="'+(T+12)+'" font-size="11" font-weight="700" fill="#3A6312">'+esc(ecuacion(reg))+'   R² = '+fx(reg.r2,4)+'</text>';
  return s+'</svg>';
}
const ecuacion=reg=>reg?'y = '+fx(reg.m0,4)+'x'+(reg.b0?' '+(reg.b0<0?'−':'+')+' '+fx(Math.abs(reg.b0),4):''):'Sin datos suficientes';
const controlesDe=cap=>(h.controles||[]).concat(cap.ctrlExtra||[]).filter(c=>c.id!=='BLANCO');

/* Filas de la tabla única: una por muestra, con el resultado de cada análisis de la hoja. */
function ordenMuestra(a,b){return String(a.info.codigo_lote||a.lote).localeCompare(String(b.info.codigo_lote||b.lote))||(Number(a.info.numero_muestra)||0)-(Number(b.info.numero_muestra)||0);}
const nombreCorto=s=>s.v.titulo||s.analisis;
const tituloCol=c=>(c.g?c.g+' · ':'')+c.label;

/* Resumen por análisis (formulario, muestras, fuera de rango y rango esperado del catálogo). */
function resumenAnalisis(){
  return '<div class="vt-resumen-analisis">'+secciones.map(s=>{
    const rango=rangoDe(s.forms[0]),fuera=s.filas.filter(x=>x.chk.fuera).length,tieneRango=rango.min!=null||rango.max!=null;
    return '<div class="vt-resumen-item"><b>'+esc(nombreCorto(s))+'</b><span class="small muted">'
      +(multi?'Form. #'+s.forms.map(f=>f.id).join(', #')+' · ':'')+s.filas.length+' muestra'+(s.filas.length===1?'':'s')
      +(tieneRango?' · Rango: '+esc(V.textoRango(V.checkRango(rango,null))):'')+'</span>'
      +(fuera?'<span class="vt-flag">⚠ '+fuera+' fuera de rango</span>':'')+'</div>';
  }).join('')+'</div>';
}

/* Tabla única: Lote y No. Lab una sola vez y cada análisis como grupo de columnas consecutivas. */
function tablaUnificada(){
  const sufijo=modo==='revisor'?['Chequeo','Acción']:['Chequeo'];
  const head1='<tr><th rowspan="2" class="th-lbl">Lote</th><th rowspan="2" class="th-lbl">No. Lab</th>'
    +secciones.map(s=>'<th colspan="'+s.cols.length+'" class="grp vt-sec-ini">'+esc(nombreCorto(s))+'</th>').join('')
    +sufijo.map(p=>'<th rowspan="2" class="th-lbl">'+p+'</th>').join('')+'</tr>';
  const head2='<tr>'+secciones.map(s=>s.cols.map((c,i)=>'<th class="'+thClase(c)+(i===0?' vt-sec-ini':'')+'">'+esc(tituloCol(c))+(verFormulas&&c.fm?'<div class="th-fm">'+esc(c.fm)+'</div>':'')+'</th>').join('')).join('')+'</tr>';
  const celdaSec=(m,s,x)=>s.cols.map((c,i)=>{const td=celda(m,c,x);return i===0?td.replace('<td class="','<td class="vt-sec-ini '):td;}).join('');
  const vacioSec=(s,titulo)=>s.cols.map((c,i)=>'<td class="num vt-na'+(i===0?' vt-sec-ini':'')+'" title="'+titulo+'">—</td>').join('');

  // Controles: mismos de la hoja; cada análisis calcula su fila con su propio formulario.
  const ctrlIds=[];secciones.forEach(s=>controlesDe(s.forms[0].cap).forEach(c=>{if(!ctrlIds.some(x=>x.id===c.id))ctrlIds.push(c);}));
  const filaCtrl=c=>'<tr class="ws-ctrl"><td class="small muted">—</td><td class="ws-lbl">'+esc(c.label)+'</td>'
    +secciones.map(s=>{const capS=s.forms[0].cap;if(!controlesDe(capS).some(x=>x.id===c.id))return vacioSec(s,'No aplica');
      return celdaSec(V.filaCalc(capS,(capS.controles||{})[c.id],c.id,V.contexto(capS)),s);}).join('')
    +'<td>'+secciones.flatMap(s=>h.validar?.((s.forms[0].cap.controles||{})[c.id]||{},V.contexto(s.forms[0].cap))||[]).map(e=>'<span class="vt-flag">'+esc(e)+'</span>').join('')+'</td>'+(modo==='revisor'?'<td></td>':'')+'</tr>';
  // Controles adicionales en la misma posición en que los capturó el analista (debajo de la muestra "despues").
  const enHoja=id=>muestrasUnidas.some(r=>String(r.id)===String(id));
  const filasCtrl=ctrlIds.filter(c=>c.despues==null||!enHoja(c.despues)).map(filaCtrl).join('');
  const ctrlTras=id=>ctrlIds.filter(c=>c.despues!=null&&String(c.despues)===String(id)).map(filaCtrl).join('');

  const filasM=muestrasUnidas.map(r=>{
    const presentes=secciones.filter(s=>r.por[s.analisis]);
    const marcado=presentes.some(s=>marcas[claveMarca(r.por[s.analisis])]);
    const fueras=presentes.filter(s=>r.por[s.analisis].chk.fuera);
    const conLimite=presentes.some(s=>r.por[s.analisis].chk.tieneLimite);
    const chequeo=fueras.length?fueras.map(s=>{const k=r.por[s.analisis].chk;return '<span class="vt-flag">⚠ '+esc(k.errores?.length?k.errores.join(' '):nombreCorto(s)+' fuera de rango')+'</span>';}).join('')
      :'<span class="small muted">'+(conLimite?'En rango':'Sin rango en catálogo')+'</span>';
    const accion=presentes.map(s=>{const x=r.por[s.analisis],k=claveMarca(x);
      return '<label class="ws-mark"><input type="checkbox" data-marca="'+k+'"'+(marcas[k]?' checked':'')+'> '+(secciones.length>1?esc(nombreCorto(s)):'Reanalizar')+'</label>';}).join('');
    return '<tr class="'+(marcado?'row-warn':'')+'"><td class="vt-mono small">'+esc(r.info.codigo_lote||r.lote)+'</td><td class="cell-code">'+esc(r.info.codigo_muestra||r.id)+'</td>'
      +secciones.map(s=>r.por[s.analisis]?celdaSec(r.por[s.analisis].m,s,r.por[s.analisis]):vacioSec(s,'No solicitado')).join('')
      +'<td>'+chequeo+'</td>'+(modo==='revisor'?'<td><div class="vt-marcas">'+accion+'</div></td>':'')+'</tr>'
      +ctrlTras(r.id);
  }).join('');
  return '<div class="ws-scroll"><table class="ws vt-ws-unificada"><thead>'+head1+head2+'</thead><tbody>'+filasCtrl+filasM+'</tbody></table></div>';
}

function render(){
  const lotes=unicos(forms.map(f=>f.lote)),tipos=unicos(forms.map(f=>f.tipo)),analistas=unicos(forms.map(f=>f.analista||f.cap.analista));
  const estados=unicos(forms.map(f=>f.estado));
  const codigos=unicos(todasFilas.slice().sort((a,b)=>String(a.info.codigo_lote||a.lote).localeCompare(String(b.info.codigo_lote||b.lote))||(Number(a.info.numero_muestra)||0)-(Number(b.info.numero_muestra)||0)).map(x=>String(x.info.codigo_muestra||x.id)));
  const rangoTxt=codigos.length?(codigos.length===1?codigos[0]:codigos[0]+' – '+codigos[codigos.length-1]):forms.map(f=>f.rango).join(', ');
  const nMuestras=unicos(todasFilas.map(x=>x.id)).length;
  const estadoChip=modo==='revisor'?'<span class="vt-chip is-review">'+esc(estados.join(' / '))+'</span>':'<span class="vt-chip '+(estados.length===1?forms[0].estado_clase:'')+'">'+esc(estados.join(' / '))+'</span>';

  const encVals=(h.encabezado?h.encabezado.campos:[]).filter(c=>!(c.editableVal&&modo==='revisor')).map(c=>[c.label,(cap0.encabezado||{})[c.k]||'—']);
  // En modo revisor, los parámetros editables se muestran como campo para corregirlos (la CE se recalcula al instante).
  const editHTML=modo==='revisor'?editables.map(c=>{const cambiado=encEditado[c.k]!==encOriginal[c.k];
    return '<div class="kv vt-kv-edit'+(cambiado?' is-changed':'')+'"><label for="enc_'+c.k+'"><b>'+esc(c.label)+'</b></label>'
      +'<input type="text" inputmode="decimal" id="enc_'+c.k+'" class="vt-input-enc" data-enc="'+c.k+'" value="'+esc(encEditado[c.k])+'" maxlength="20">'
      +'<span class="small muted">'+(cambiado?'Antes: '+esc(encOriginal[c.k]||'—')+' · se guarda al validar':(c.soloVal?'Valor del método':'Capturado por el analista'))+'</span></div>';}).join(''):'';
  const info=encVals.concat(h.info&&!legado?h.info(ctx0):[]).filter((x,i,a)=>a.findIndex(y=>y[0]===x[0])===i);
  const notas=(data.notas||[]).map(n=>'<div class="ws-nota'+(n.tipo==='alerta'?' is-warn':'')+'"><div><b>'+esc(n.quien||'Sistema')+'</b> · '+esc(n.fh)+' — '+esc(n.texto)+'</div></div>').join('');
  const nMarcas=todasFilas.filter(x=>marcas[claveMarca(x)]).length,fuera=todasFilas.filter(x=>x.chk.fuera).length;
  const titulo=multi?(h.titulo||secciones.map(s=>s.analisis).join(', ')):(secciones[0].v.titulo||secciones[0].analisis);

  let html='<div class="ws-card"><div class="ws-card-head"><div><div class="ws-sec-title">'+esc(V.etiquetaIds(ids))+'</div><h3>'+esc(titulo)+' · '+esc(tipos.join(', '))+'</h3></div>'
    +'<div class="ws-chips"><span class="vt-chip">'+esc(lotes.map(etiquetaLote).join(', '))+'</span><span class="vt-chip">Rango '+esc(rangoTxt)+'</span><span class="vt-chip">Analista: '+esc(analistas.join(', '))+'</span><span class="vt-chip">Análisis: '+esc(V.fechaTxt(forms[0].fecha||cap0.fecha))+'</span>'+estadoChip+'</div></div>'
    +'<div class="ws-card-body">'
    +(multi?'<div class="vt-analisis-list" style="margin-bottom:10px"><b class="small">Análisis de la hoja ('+secciones.length+'):</b>'+secciones.map(s=>'<span class="vt-chip">'+esc(s.analisis)+'</span>').join('')+'</div>':'')
    +(editHTML?'<div class="vt-param-head"><b>'+esc(h.encabezado.titulo)+'</b> · confirme o corrija los valores; los resultados se recalculan al instante y el cambio queda en el historial al validar.</div><div class="ws-info vt-params">'+editHTML+'</div>':'')+'<div class="ws-info">'+info.map(([k,val])=>'<div class="kv"><b>'+esc(k)+'</b>'+esc(val)+'</div>').join('')+'<div class="kv"><b>Equipo</b>'+esc(cap0.equipo||'—')+'</div></div>'
    +(legado?'<div class="ws-obs"><b>Formato anterior:</b> '+esc(h.legado.aviso)+'</div>':(h.formula?'<div class="ws-formula"><b>Cálculo:</b> '+esc(h.formula)+'</div>':''))
    +(cap0.obs?'<div class="ws-obs"><b>Observación del analista:</b> '+esc(cap0.obs)+'</div>':'')+notas+'</div></div>';

  const nCtrl=controlesDe(cap0).length;
  html+='<div class="ws-card"><div class="ws-card-head"><div><div class="ws-sec-title">Datos capturados y proceso de cálculo</div>'
    +'<div class="ws-legend"><span><i class="lg-cap"></i>Capturado por el analista</span><span><i class="lg-calc"></i>Calculado por el sistema</span><span><i class="lg-res"></i>Resultado reportado</span></div></div>'
    +'<div class="ws-chips"><span class="small muted">'+nMuestras+' muestra'+(nMuestras===1?'':'s')+(multi?' · '+secciones.length+' análisis':'')+(nCtrl?' · '+nCtrl+' fila'+(nCtrl===1?'':'s')+' de control':'')+(fuera?' · <b style="color:var(--cengi-danger)">'+fuera+' fuera de rango</b>':'')+'</span>'
    +'<button type="button" class="cengi-btn cengi-btn-ghost cengi-btn-sm" data-act="formulas">'+(verFormulas?'Ocultar fórmulas':'Ver fórmulas')+'</button>'
    +'<button type="button" class="cengi-btn cengi-btn-ghost cengi-btn-sm" data-act="csv">Descargar a Excel (CSV)</button></div></div>'
    +resumenAnalisis()+tablaUnificada()
    +'<div class="ws-foot-note">Columnas resaltadas en verde = resultado reportado. El blanco se aplica en sus columnas ("Fila Blanco"). El rango esperado viene del catálogo de análisis. "—" = análisis no solicitado para esa muestra.'+(secciones.length>1&&modo==='revisor'?' En "Acción", marca el análisis a reanalizar: la muestra vuelve a la bandeja solo para ese análisis.':'')+'</div></div>';

  if(h.curva&&secciones.some(s=>s.v.grafico)){
    html+='<div class="ws-card"><div class="ws-card-head"><div class="ws-sec-title">Curva de calibración</div><span class="small muted">'+ecuacion(ctx0.reg)+(ctx0.reg?' · R² '+fx(ctx0.reg.r2,4):'')+'</span></div>'
      +'<div class="ws-card-body ws-curva"><table class="ws ws-curva-tbl"><thead><tr><th class="th-lbl">Concentración</th><th>Absorbancia</th></tr></thead><tbody>'
      +(cap0.curva||[]).filter(p=>p.c!==''||p.a!=='').map(p=>'<tr><td class="num">'+esc(p.c)+'</td><td class="num">'+esc(p.a||'—')+'</td></tr>').join('')+'</tbody></table>'
      +'<div>'+graficoCurva(ctx0.reg,cap0.curva,h.curva.unidadX)+(ctx0.reg&&ctx0.reg.r2!=null&&ctx0.reg.r2<0.99?'<span class="vt-flag" style="margin-top:6px">⚠ R² menor a 0.99 — revisar puntos de la curva</span>':'')+'</div></div></div>';
  }

  if(modo==='revisor'){
    const total=todasFilas.length;
    const lblValidar=nMarcas?'Validar '+(total-nMarcas)+' resultado'+(total-nMarcas===1?'':'s')+' y devolver '+nMarcas+' a reanálisis':(multi?'Validar hoja completa ('+secciones.length+' análisis)':'Validar');
    html+='<div class="ws-card"><div class="ws-card-body"><div class="vt-field"><label for="valNota">Nota de validación (queda en el historial'+(multi?' de todos los análisis de la hoja':'')+'; si hay reanálisis se envía al analista)</label><textarea id="valNota" placeholder="Ej. Controles dentro de lo esperado; curva con R² aceptable.">'+esc(data.nota||'')+'</textarea></div>'
      +(nMarcas?'<div class="vt-field"><label for="valCategoria">Categoría del reanálisis (obligatoria · Control de calidad la agrupa)</label>'+V.selectMotivos('valCategoria')+'</div>':'')
      +'<div class="vt-actions"><button type="button" class="cengi-btn cengi-btn-primary" data-act="validar">'+lblValidar+'</button>'
      +'<button type="button" class="cengi-btn vt-btn-red" data-act="rechazar">'+(multi?'Rechazar hoja completa':'Rechazar formulario completo')+'</button></div></div></div>';
  }
  node.innerHTML=html;
  const cat=document.getElementById('valCategoria');if(cat)cat.value=data.categoria||'';
}

function exportarCSV(){
  const val=(m,c)=>{const x=m[c.k];return typeof x==='number'?x.toFixed(c.d==null?2:c.d):(x==null?'':String(x));};
  const q=t=>'"'+String(t).replace(/"/g,'""')+'"';
  // Misma disposición que la tabla: Lote y No. Lab una vez, columnas de cada análisis consecutivas.
  const vacias=s=>s.cols.map(()=>'');
  const filas=[['Fecha','Lote','No. Lab'].concat(...secciones.map(s=>s.cols.map(c=>nombreCorto(s)+' - '+tituloCol(c))))];
  const fecha=V.fechaTxt(cap0.fecha);
  const ctrlIds=[];secciones.forEach(s=>controlesDe(s.forms[0].cap).forEach(c=>{if(!ctrlIds.some(x=>x.id===c.id))ctrlIds.push(c);}));
  const filaCtrlCsv=c=>filas.push([fecha,'',c.label].concat(...secciones.map(s=>{const capS=s.forms[0].cap;if(!controlesDe(capS).some(x=>x.id===c.id))return vacias(s);
    const m=V.filaCalc(capS,(capS.controles||{})[c.id],c.id,V.contexto(capS));return s.cols.map(col=>val(m,col));})));
  // Mismo orden que la tabla: controles iniciales y luego cada muestra seguida de sus controles adicionales.
  const enHoja=id=>muestrasUnidas.some(r=>String(r.id)===String(id));
  ctrlIds.filter(c=>c.despues==null||!enHoja(c.despues)).forEach(filaCtrlCsv);
  muestrasUnidas.forEach(r=>{filas.push([fecha,r.info.codigo_lote||r.lote,r.info.codigo_muestra||r.id].concat(...secciones.map(s=>r.por[s.analisis]?s.cols.map(col=>val(r.por[s.analisis].m,col)):vacias(s))));
    ctrlIds.filter(c=>c.despues!=null&&String(c.despues)===String(r.id)).forEach(filaCtrlCsv);});
  const a=document.createElement('a');
  a.href=URL.createObjectURL(new Blob(['﻿'+filas.map(r=>r.map(q).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'}));
  a.download=(multi?'Hoja-'+ids.join('-'):'Formulario-'+ids[0])+'-'+String(multi?(h.titulo||'analisis'):(secciones[0].v.titulo||secciones[0].analisis)).replace(/[^\wáéíóúñ]+/gi,'_').slice(0,60)+'.csv';
  document.body.appendChild(a);a.click();a.remove();
  V.toast(V.etiquetaIds(ids)+' descargado — se abre en Excel con las mismas columnas del cálculo');
}
const notaActual=()=>{const el=document.getElementById('valNota');data.categoria=(document.getElementById('valCategoria')||{}).value||data.categoria||'';return el?el.value.trim():'';};
const irALista=msg=>{V.toast(msg);setTimeout(()=>{location.href=data.volver;},1100);};
const referencia=()=>V.etiquetaIds(ids)+' · '+secciones.map(s=>s.analisis).join(', ')+' · '+unicos(forms.map(f=>f.tipo)).join(', ')+' · '+unicos(todasFilas.map(x=>x.id)).length+' muestras';

node.addEventListener('change',e=>{const ds=e.target.dataset||{};
  if(ds.textura||ds.texturaMotivo){
    if(modo!=='revisor')return;
    const key=ds.textura||ds.texturaMotivo,[fid,id]=key.split(':').map(Number),form=forms.find(f=>f.id===fid),row=form?.cap.filas[id];if(!row)return;
    data.nota=notaActual();if(ds.textura)row.claseManual=e.target.value;else row.claseManualMotivo=e.target.value.trim();
    (texturas[fid]??={})[id]={clase:row.claseManual||'',motivo:row.claseManualMotivo||''};if(ds.textura){recalcular();render();}return;
  }
  if(ds.enc){const v=e.target.value.trim().replace(',','.');
    const campo=editables.find(c=>c.k===ds.enc)||{},minimo=campo.cero?0:Number.MIN_VALUE;
    if((v===''&&!campo.opcionalVal)||(v!==''&&!(isFinite(Number(v))&&Number(v)>=minimo))){V.toast(campo.cero?'Ingrese un número válido (0 o mayor).':'Ingrese un número válido mayor que cero.',true);e.target.value=encEditado[ds.enc];return;}
    data.nota=notaActual();encEditado[ds.enc]=v;recalcular();render();
    if(encEditado[ds.enc]!==encOriginal[ds.enc])V.toast('Resultados recalculados con el nuevo valor. Se guardará al validar.');return;}
  const k=ds.marca;if(!k)return;data.nota=notaActual();marcas[k]=e.target.checked;render();});
node.addEventListener('click',async e=>{
  const btn=e.target.closest('[data-act]');if(!btn)return;
  const act=btn.dataset.act;
  if(act==='formulas'){data.nota=notaActual();verFormulas=!verFormulas;render();return;}
  if(act==='csv'){exportarCSV();return;}
  if(act==='rechazar'){V.abrirRechazo(data.endpoint,ids,referencia(),notaActual(),irALista);return;}
  if(act==='validar'){
    const reanalizar={};let n=0;
    todasFilas.forEach(x=>{if(marcas[claveMarca(x)]){(reanalizar[x.fid]=reanalizar[x.fid]||[]).push(x.id);n++;}});
    if(n&&n===todasFilas.length){V.abrirRechazo(data.endpoint,ids,'Marcaste todas las muestras — se rechaza '+(multi?'la hoja completa':'el formulario completo'),notaActual(),irALista);return;}
    const comentario=notaActual();
    if(Object.values(texturas).some(rows=>Object.values(rows).some(x=>!x.motivo.trim()))){V.toast('Indique el motivo de cada cambio de clase textural, incluso al volver a automática.',true);return;}
    if(n&&!data.categoria){V.toast('Seleccione la categoría del reanálisis.',true);document.getElementById('valCategoria')?.focus();return;}
    if(!await V.confirmarValidacion(data.validador,referencia(),n))return;
    btn.disabled=true;
    const encabezado={};encCambiado().forEach(c=>encabezado[c.k]=encEditado[c.k]);
    try{await V.resolver(data.endpoint,{ids_formulario:ids,accion:'aprobar',comentario,reanalizar,categoria:data.categoria,encabezado,texturas,constancia:true});
      irALista(V.etiquetaIds(ids)+' validado'+(n?' — '+n+' resultado(s) devuelto(s) a reanálisis':'')+'.');}
    catch(err){V.toast(err.message,true);btn.disabled=false;}
  }
});
recalcular();render();
})();
