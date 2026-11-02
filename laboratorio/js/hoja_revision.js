/* Detalle de un formulario en Validación técnica — mismo formato que
   htmlDetalleFormulario() de Prototipos/laboratorio-workflow-v3.html:
   encabezado, tabla única de datos capturados + cálculo + resultado,
   chequeo contra el rango del catálogo, curva de calibración y acciones. */
(function(){'use strict';
const node=document.getElementById('wf-review');if(!node)return;
const data=JSON.parse(document.getElementById('wf-review-data').textContent);
const V=window.LabVal,defs=window.LabHojas,esc=V.esc;
V.setMotivos(data.motivos);
const cap=data.cap,f=data.formulario,h=defs.hojas[cap.hoja];
if(!h){node.innerHTML='<div class="cengi-empty">La hoja "'+esc(cap.hoja)+'" no está definida en hojas_definiciones.js.</div>';return;}
const v=V.vistaDe(cap),ctx=V.contexto(cap),rango={min:f.limite_min,max:f.limite_max,unidad:f.unidad};
const modo=data.modo;let verFormulas=true;const marcas={};

const capturadas=new Set(h.cols.filter(c=>!c.auto).map(c=>c.k));
const cols=v.cols.map(c=>Object.assign({},c,{calc:!capturadas.has(c.k)}));
const fx=(x,d)=>defs.fx(x,d);
const celda=(m,c)=>{const val=m[c.k],esNum=typeof val==='number';
  const txt=esNum?fx(val,c.d):(val==null||val===''?'—':esc(val));
  return '<td class="num'+(c.res?' res':'')+(esNum||val==null||val===''?'':' txt')+'">'+txt+'</td>';};
const thLabel=c=>esc(c.label)+(verFormulas&&c.fm?'<div class="th-fm">'+esc(c.fm)+'</div>':'');
const thClase=c=>c.calc===true?'th-calc':c.calc===false?'th-cap':'';
function thead(prefijo,sufijo){
  if(!cols.some(c=>c.g))return '<tr>'+prefijo.map(p=>'<th class="th-lbl">'+p+'</th>').join('')+cols.map(c=>'<th class="'+thClase(c)+'">'+thLabel(c)+'</th>').join('')+sufijo.map(p=>'<th class="th-lbl">'+p+'</th>').join('')+'</tr>';
  let grp='',i=0;
  while(i<cols.length){const g=cols[i].g||'';let n=0;while(i<cols.length&&(cols[i].g||'')===g){n++;i++;}grp+='<th colspan="'+n+'" class="'+(g?'grp':'')+'">'+esc(g)+'</th>';}
  return '<tr>'+prefijo.map(p=>'<th rowspan="2" class="th-lbl">'+p+'</th>').join('')+grp+sufijo.map(p=>'<th rowspan="2" class="th-lbl">'+p+'</th>').join('')+'</tr><tr>'+cols.map(c=>'<th class="'+thClase(c)+'">'+thLabel(c)+'</th>').join('')+'</tr>';
}
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
  if(reg)s+='<line x1="'+sx(x0)+'" y1="'+sy(reg.m0*x0)+'" x2="'+sx(x1)+'" y2="'+sy(reg.m0*x1)+'" stroke="#5e9b1d" stroke-width="2" stroke-dasharray="5 4"/>';
  pts.forEach(p=>{s+='<circle cx="'+sx(p.x)+'" cy="'+sy(p.y)+'" r="4" fill="#636A6F" stroke="#fff" stroke-width="1.5"><title>'+esc(p.x+' ; '+p.y)+'</title></circle>';});
  s+='<text x="'+((L+W-R)/2)+'" y="'+(H-6)+'" text-anchor="middle" font-size="10.5" font-weight="700" fill="#636A6F">Concentración ('+esc(unidadX||'ppm')+')</text>';
  s+='<text x="12" y="'+((T+H-B)/2)+'" text-anchor="middle" font-size="10.5" font-weight="700" fill="#636A6F" transform="rotate(-90 12 '+((T+H-B)/2)+')">Absorbancia</text>';
  if(reg)s+='<text x="'+(L+10)+'" y="'+(T+12)+'" font-size="11" font-weight="700" fill="#3A6312">y = '+fx(reg.m0,4)+'x   R² = '+fx(reg.r2,4)+'</text>';
  return s+'</svg>';
}
const ecuacion=reg=>reg?'y = '+fx(reg.m0,4)+'x':'Sin datos suficientes';

function render(){
  const muestras=V.muestrasCalculadas(cap,rango);
  const ctrls=(h.controles||[]).concat(cap.ctrlExtra||[]).filter(c=>c.id!=='BLANCO');
  const filasCtrl=ctrls.map(c=>{const m=V.filaCalc(cap,(cap.controles||{})[c.id],c.id,ctx);
    return '<tr class="ws-ctrl"><td class="small muted">—</td><td class="ws-lbl">'+esc(c.label)+'</td>'+cols.map(col=>celda(m,col)).join('')+'<td></td>'+(modo==='revisor'?'<td></td>':'')+'</tr>';}).join('');
  const filasM=muestras.map(x=>{
    const k=x.chk,marcado=!!marcas[x.id];
    const chequeo=k.fuera?'<span class="vt-flag" title="Rango del catálogo: '+esc(V.textoRango(k))+'">⚠ '+esc(cap.analisis)+' fuera de rango</span>'
      :'<span class="small muted">'+(k.tieneLimite?'En rango':'Sin rango en catálogo')+'</span>';
    return '<tr class="'+(marcado?'row-warn':'')+'"><td class="vt-mono small">'+esc(x.info.codigo_lote||f.lote)+'</td><td class="cell-code">'+esc(x.info.codigo_muestra||x.id)+'</td>'
      +cols.map(col=>celda(x.m,col)).join('')+'<td>'+chequeo+'</td>'
      +(modo==='revisor'?'<td><label class="ws-mark"><input type="checkbox" data-marca="'+x.id+'"'+(marcado?' checked':'')+'> Reanalizar</label></td>':'')+'</tr>';
  }).join('');

  const encVals=(h.encabezado?h.encabezado.campos:[]).map(c=>[c.label,(cap.encabezado||{})[c.k]||'—']);
  const info=encVals.concat(h.info?h.info(ctx):[]).filter((x,i,a)=>a.findIndex(y=>y[0]===x[0])===i);
  const notas=(data.notas||[]).map(n=>'<div class="ws-nota'+(n.tipo==='alerta'?' is-warn':'')+'"><div><b>'+esc(n.quien||'Sistema')+'</b> · '+esc(n.fh)+' — '+esc(n.texto)+'</div></div>').join('');
  const nMarcas=muestras.filter(x=>marcas[x.id]).length,fuera=muestras.filter(x=>x.chk.fuera).length;

  let html='<div class="ws-card"><div class="ws-card-head"><div><div class="ws-sec-title">Formulario #'+f.id+'</div><h3>'+esc(v.titulo||v.analisis.join(', '))+' · '+esc(f.tipo)+'</h3></div>'
    +'<div class="ws-chips"><span class="vt-chip">Lote '+esc(f.lote)+'</span><span class="vt-chip">Rango '+esc(f.rango)+'</span><span class="vt-chip">Analista: '+esc(f.analista||cap.analista)+'</span><span class="vt-chip">Análisis: '+esc(V.fechaTxt(f.fecha||cap.fecha))+'</span><span class="vt-chip '+(modo==='revisor'?'is-review':f.estado_clase)+'">'+esc(f.estado)+'</span></div></div>'
    +'<div class="ws-card-body"><div class="ws-info">'+info.map(([k,val])=>'<div class="kv"><b>'+esc(k)+'</b>'+esc(val)+'</div>').join('')+'<div class="kv"><b>Equipo</b>'+esc(cap.equipo||'—')+'</div>'
    +(rango.min!=null||rango.max!=null?'<div class="kv"><b>Rango esperado (catálogo)</b>'+esc(V.textoRango(V.checkRango(rango,null)))+'</div>':'')+'</div>'
    +(h.formula?'<div class="ws-formula"><b>Cálculo:</b> '+esc(h.formula)+'</div>':'')
    +(cap.obs?'<div class="ws-obs"><b>Observación del analista:</b> '+esc(cap.obs)+'</div>':'')+notas+'</div></div>';

  html+='<div class="ws-card"><div class="ws-card-head"><div><div class="ws-sec-title">Datos capturados y proceso de cálculo</div>'
    +'<div class="ws-legend"><span><i class="lg-cap"></i>Capturado por el analista</span><span><i class="lg-calc"></i>Calculado por el sistema</span><span><i class="lg-res"></i>Resultado reportado</span></div></div>'
    +'<div class="ws-chips"><span class="small muted">'+muestras.length+' muestra'+(muestras.length===1?'':'s')+(ctrls.length?' · '+ctrls.length+' fila'+(ctrls.length===1?'':'s')+' de control':'')+(fuera?' · <b style="color:var(--cengi-danger)">'+fuera+' fuera de rango</b>':'')+'</span>'
    +'<button type="button" class="cengi-btn cengi-btn-ghost cengi-btn-sm" data-act="formulas">'+(verFormulas?'Ocultar fórmulas':'Ver fórmulas')+'</button>'
    +'<button type="button" class="cengi-btn cengi-btn-ghost cengi-btn-sm" data-act="csv">Descargar a Excel (CSV)</button></div></div>'
    +'<div class="ws-scroll"><table class="ws"><thead>'+thead(['Lote','No. Lab'],modo==='revisor'?['Chequeo','Acción']:['Chequeo'])+'</thead><tbody>'+filasCtrl+filasM+'</tbody></table></div>'
    +'<div class="ws-foot-note">Columnas resaltadas en verde = resultado reportado. El blanco se aplica en sus columnas ("Fila Blanco"). El rango esperado viene del catálogo de análisis.</div></div>';

  if(v.grafico&&h.curva){
    html+='<div class="ws-card"><div class="ws-card-head"><div class="ws-sec-title">Curva de calibración</div><span class="small muted">'+ecuacion(ctx.reg)+(ctx.reg?' · R² '+fx(ctx.reg.r2,4):'')+'</span></div>'
      +'<div class="ws-card-body ws-curva"><table class="ws ws-curva-tbl"><thead><tr><th class="th-lbl">Concentración</th><th>Absorbancia</th></tr></thead><tbody>'
      +(cap.curva||[]).filter(p=>p.c!==''||p.a!=='').map(p=>'<tr><td class="num">'+esc(p.c)+'</td><td class="num">'+esc(p.a||'—')+'</td></tr>').join('')+'</tbody></table>'
      +'<div>'+graficoCurva(ctx.reg,cap.curva,h.curva.unidadX)+(ctx.reg&&ctx.reg.r2<0.99?'<span class="vt-flag" style="margin-top:6px">⚠ R² menor a 0.99 — revisar puntos de la curva</span>':'')+'</div></div></div>';
  }

  if(modo==='revisor'){
    html+='<div class="ws-card"><div class="ws-card-body"><div class="vt-field"><label for="valNota">Nota de validación (queda en el historial; si hay reanálisis se envía al analista)</label><textarea id="valNota" placeholder="Ej. Controles dentro de lo esperado; curva con R² aceptable.">'+esc(data.nota||'')+'</textarea></div>'
      +(nMarcas?'<div class="vt-field"><label for="valCategoria">Categoría del reanálisis (obligatoria · Control de calidad la agrupa)</label>'+V.selectMotivos('valCategoria')+'</div>':'')
      +'<div class="vt-actions"><button type="button" class="cengi-btn cengi-btn-primary" data-act="validar">'+(nMarcas?'Validar '+(muestras.length-nMarcas)+' y devolver '+nMarcas+' a reanálisis':'Validar')+'</button>'
      +'<button type="button" class="cengi-btn vt-btn-red" data-act="rechazar">Rechazar formulario completo</button></div></div></div>';
  }
  node.innerHTML=html;
  const cat=document.getElementById('valCategoria');if(cat)cat.value=data.categoria||'';
}

function exportarCSV(){
  const val=(m,c)=>{const x=m[c.k];return typeof x==='number'?x.toFixed(c.d==null?2:c.d):(x==null?'':String(x));};
  const q=t=>'"'+String(t).replace(/"/g,'""')+'"';
  const filas=[['Fecha','Lote','No. Lab'].concat(v.cols.map(c=>(c.g?c.g+' - ':'')+c.label))];
  (h.controles||[]).concat(cap.ctrlExtra||[]).filter(c=>c.id!=='BLANCO').forEach(c=>{const m=V.filaCalc(cap,(cap.controles||{})[c.id],c.id,ctx);filas.push([V.fechaTxt(cap.fecha),'',c.label].concat(v.cols.map(col=>val(m,col))));});
  V.muestrasCalculadas(cap,rango).forEach(x=>filas.push([V.fechaTxt(cap.fecha),x.info.codigo_lote||f.lote,x.info.codigo_muestra||x.id].concat(v.cols.map(col=>val(x.m,col)))));
  const a=document.createElement('a');
  a.href=URL.createObjectURL(new Blob(['﻿'+filas.map(r=>r.map(q).join(',')).join('\r\n')],{type:'text/csv;charset=utf-8'}));
  a.download='Formulario-'+f.id+'-'+String(v.titulo||v.analisis[0]).replace(/[^\wáéíóúñ]+/gi,'_')+'.csv';
  document.body.appendChild(a);a.click();a.remove();
  V.toast('Formulario #'+f.id+' descargado — se abre en Excel con las mismas columnas del cálculo');
}
const notaActual=()=>{const el=document.getElementById('valNota');data.categoria=(document.getElementById('valCategoria')||{}).value||data.categoria||'';return el?el.value.trim():'';};
const irALista=msg=>{V.toast(msg);setTimeout(()=>{location.href=data.volver;},1100);};

node.addEventListener('change',e=>{const id=e.target.dataset&&e.target.dataset.marca;if(!id)return;data.nota=notaActual();marcas[id]=e.target.checked;render();});
node.addEventListener('click',async e=>{
  const btn=e.target.closest('[data-act]');if(!btn)return;
  const act=btn.dataset.act;
  if(act==='formulas'){data.nota=notaActual();verFormulas=!verFormulas;render();return;}
  if(act==='csv'){exportarCSV();return;}
  if(act==='rechazar'){V.abrirRechazo(data.endpoint,f.id,'Formulario #'+f.id+' · '+(v.titulo||cap.analisis)+' · '+f.tipo+' · '+(cap.seleccion||[]).length+' muestras',notaActual(),irALista);return;}
  if(act==='validar'){
    const reanalizar=Object.keys(marcas).filter(k=>marcas[k]).map(Number);
    if(reanalizar.length&&reanalizar.length===(cap.seleccion||[]).length){V.abrirRechazo(data.endpoint,f.id,'Marcaste todas las muestras — se rechaza el formulario completo',notaActual(),irALista);return;}
    const comentario=notaActual();
    if(reanalizar.length&&!data.categoria){V.toast('Seleccione la categoría del reanálisis.',true);document.getElementById('valCategoria')?.focus();return;}
    btn.disabled=true;
    try{await V.resolver(data.endpoint,{id_formulario:f.id,accion:'aprobar',comentario,reanalizar,categoria:data.categoria});
      irALista('Formulario #'+f.id+' validado'+(reanalizar.length?' — '+reanalizar.length+' muestra(s) devuelta(s) a reanálisis':'')+'.');}
    catch(err){V.toast(err.message,true);btn.disabled=false;}
  }
});
render();
})();
