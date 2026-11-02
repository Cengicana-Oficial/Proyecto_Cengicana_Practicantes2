(function(){
'use strict';
const app=document.getElementById('wf-app');if(!app)return;
const $=id=>document.getElementById('wf-'+id), data=JSON.parse($('data').textContent), defs=window.LabHojas;
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const localDate=()=>{const d=new Date();return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');};
const norm=v=>String(v??'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
const labels={pendiente:'Pendiente',proceso:'En análisis',validacion:'En validación',validado:'Aprobado',rechazado:'Reanálisis',repetido:'Reanálisis'};
const icon={pendiente:'○',proceso:'◐',validacion:'●',validado:'✓',rechazado:'↻',repetido:'↻'};
let tasks=data.tareas, open=new Set(), soloPend=false, busy=false, cap=null, pool=[], hk='', version=0, dirty=false, tablet=false, step=0, sampleQuery='',links={};
const pending=t=>['pendiente','proceso','rechazado','repetido'].includes(t.estado.key);
const can=t=>data.manager||(data.user.id&&Number(t.usuario_id)===Number(data.user.id))||(!t.usuario_id&&data.user.nombre&&t.analista===data.user.nombre);
const message=t=>$('status').textContent=t;
const group=(list,key)=>{const o={};list.forEach(t=>(o[key(t)]??=[]).push(t));return o;};
const priority=t=>t.prioridad.rank;
const sortTasks=(a,b)=>priority(b)-priority(a)||String(a.fecha_estimada||'9999').localeCompare(String(b.fecha_estimada||'9999'))||String(a.codigo_muestra).localeCompare(String(b.codigo_muestra),undefined,{numeric:true});
const badge=t=>'<span class="wf-state '+t.estado.key+'" title="'+esc(labels[t.estado.key]+' · '+(t.analista||'Sin asignar'))+'">'+icon[t.estado.key]+' '+esc(labels[t.estado.key])+'</span>';
const prio=t=>'<span class="wf-priority '+t.prioridad.key+'">'+esc(t.prioridad.label)+'</span>';
function lotState(ts){if(ts.every(t=>t.estado.key==='validado'))return 'validado';if(ts.every(t=>t.estado.key==='pendiente'))return 'pendiente';if(!ts.some(pending))return 'validacion';return 'proceso';}
function progress(ts){const groups=group(ts,t=>t.estado.key);return '<div class="wf-progress" aria-label="'+ts.filter(t=>t.estado.key==='validado').length+' de '+ts.length+' aprobados">'+Object.entries(groups).map(([key,v])=>'<span class="'+key+'" style="width:'+v.length/ts.length*100+'%" title="'+esc(labels[key])+': '+v.length+'"></span>').join('')+'</div>';}
function users(selected='',attrs=''){return '<select '+attrs+'><option value="">Elegir analista…</option>'+data.usuarios.map(u=>'<option value="'+u.id+'"'+(String(u.id)===String(selected)?' selected':'')+'>'+esc(u.nombre)+'</option>').join('')+'</select>';}
async function api(payload, query=''){
 const r=await fetch('../controllers/workflow_trabajo.php'+query,payload?{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...payload,csrf:data.csrf})}:{headers:{Accept:'application/json'}});
 if(r.redirected)throw Error('La sesión venció. Recargue la página.');let result;try{result=await r.json();}catch{throw Error('Respuesta inválida del servidor.');}if(!r.ok)throw Error(result.error||'No se pudo guardar.');return result;
}
async function refresh(){const r=await api(null,data.modo==='plan'?'?modo=plan':'');tasks=r.tareas;if(r.borradores)data.borradores=r.borradores;if(r.capturadas_hoy)data.capturadas_hoy=r.capturadas_hoy;if(r.identificacion)data.identificacion=r.identificacion;if(r.seguimiento){data.seguimiento=r.seguimiento;eventos={};};renderList();}
function init(){
 $('type').innerHTML+=[...new Set(tasks.map(t=>t.tipo_muestra))].map(t=>'<option>'+esc(t)+'</option>').join('');
 if($('analyst')){$('analyst').innerHTML+=data.usuarios.map(u=>'<option value="'+u.id+'">'+esc(u.nombre)+'</option>').join('');if(!data.manager&&data.user.id)$('analyst').value=String(data.user.id);}
 ['search','type','state','analyst','period','entrega'].forEach(id=>$(id)?.addEventListener(id==='search'?'input':'change',renderList));
 $('expand')?.addEventListener('click',()=>{open=open.size?new Set():new Set(tasks.map(t=>String(t.id_lote)));renderList();});renderList();if(data.hoja)openSheet(data.hoja);
}
function renderList(){
 const q=norm($('search').value),type=$('type').value;
 const filtered=tasks.filter(t=>(!type||t.tipo_muestra===type)&&(!q||norm([t.codigo_lote,t.codigo_muestra,t.cliente,t.analisis_nombre].join(' ')).includes(q)));
 if(data.modo==='plan')renderPlan(filtered);else renderTray(filtered);
}
function renderPlan(filtered){
 const all=group(tasks,t=>t.id_lote),ids=Object.keys(all);
 const curso=ids.filter(id=>!finalizado(id)),fin=ids.filter(finalizado);
 const today=localDate(),mes=today.slice(0,7);
 const late=curso.filter(id=>all[id][0].fecha_estimada&&String(all[id][0].fecha_estimada).slice(0,10)<today);
 $('kpis').innerHTML=[['Lotes en curso',curso.length],['Listos para informe',curso.filter(id=>all[id].every(t=>t.estado.key==='validado')).length],['Lotes atrasados',late.length],['Finalizados este mes',fin.filter(id=>String(seg(id).informe?.fecha_firma||'').slice(0,7)===mes).length]].map(([label,n])=>'<article><small>'+label+'</small><strong>'+n+'</strong></article>').join('');
 $('n-curso').textContent='('+curso.length+')';$('n-final').textContent='('+fin.length+')';
 app.querySelectorAll('[data-plan-tab]').forEach(b=>b.setAttribute('aria-selected',String(b.dataset.planTab===planTab)));
 app.querySelectorAll('[data-solo-curso]').forEach(n=>n.hidden=planTab!=='curso');
 app.querySelectorAll('[data-solo-final]').forEach(n=>n.hidden=planTab!=='final');
 const visible=new Set(filtered.map(t=>String(t.id_lote)));
 if(planTab==='final'){renderFinalizados(fin.filter(id=>visible.has(id)),all);if(modalLot)renderModal();return;}
 const filter=$('state').value;
 const lots=Object.entries(all).filter(([id,ts])=>visible.has(id)&&!finalizado(id)&&(!filter||(filter==='informe'?ts.every(t=>t.estado.key==='validado'):lotState(ts)===filter))).sort((a,b)=>sortTasks(a[1][0],b[1][0]));
 $('count').textContent=lots.length+' lote'+(lots.length===1?'':'s')+' en curso · '+lots.reduce((n,[,ts])=>n+new Set(ts.map(x=>x.id_muestra)).size,0)+' muestras · Los lotes con informe firmado pasan a Finalizados.';
 // Un bloque por tipo de muestra; dentro, las columnas de análisis son las mismas para todos sus lotes.
 const bloques=group(lots,([,ts])=>tipoGrupo(ts[0].tipo_muestra));
 $('list').innerHTML=Object.entries(bloques).sort((a,b)=>tipoRank(a[0])-tipoRank(b[0])||a[0].localeCompare(b[0],'es')).map(([tipo,ls])=>{
  const analisis=Object.values(group(ls.flatMap(([,ts])=>ts),t=>norm(t.analisis_nombre).trim())).map(a=>({id:norm(a[0].analisis_nombre).trim(),nombre:a[0].analisis_nombre})).sort((a,b)=>a.nombre.localeCompare(b.nombre,'es'));
  const nMuestras=ls.reduce((n,[,ts])=>n+new Set(ts.map(x=>x.id_muestra)).size,0);
  const filas=ls.map(([id,ts])=>{const t=ts[0],muestras=samplesSorted(ts),approved=ts.filter(x=>x.estado.key==='validado').length,e=etapas(id,ts);
   const rango=muestras.length?muestras[0]+(muestras.length>1?' → '+muestras[muestras.length-1]:''):'—';
   const vence=t.fecha_estimada?String(t.fecha_estimada).slice(0,10):'',atrasado=vence&&vence<today;
   return '<tr><th class="wf-plan-lote"><b>'+esc(t.codigo_lote)+'</b>'+prio(t)+'</th><td>'+esc(t.cliente||'Sin cliente')+'</td><td class="wf-plan-rango">'+esc(rango)+'</td><td class="wf-plan-num">'+muestras.length+'</td>'
    +analisis.map(a=>{const at=ts.filter(x=>norm(x.analisis_nombre).trim()===a.id);if(!at.length)return '<td class="wf-plan-ns" title="No solicitado">—</td>';
     const ok=at.filter(x=>x.estado.key==='validado').length,resumen=Object.entries(group(at,x=>x.estado.key)).map(([k,v])=>labels[k]+': '+v.length).join(' · ');
     return '<td class="wf-plan-an '+lotState(at)+'" title="'+esc(a.nombre+' — '+resumen)+'"><span>'+ok+'/'+at.length+'</span>'+progress(at)+'</td>';}).join('')
    +'<td class="wf-plan-total"><b>'+Math.round(approved/ts.length*100)+'%</b>'+progress(ts)+'</td>'
    +'<td class="wf-plan-seg">'+stepper(e)+'<small class="wf-plan-sub wf-plan-etapa">'+esc(e.etiqueta)+'</small><small class="wf-plan-sub'+(atrasado?' wf-late':'')+'">'+(vence?(atrasado?'Atrasado · entrega ':'Entrega ')+esc(fdate(vence)):'Sin fecha estimada')+'</small></td>'
    +'<td class="wf-plan-acc"><button type="button" data-toggle="'+id+'">Ver muestras</button><button type="button" data-seguimiento="'+id+'">Seguimiento</button></td></tr>';}).join('');
  return '<section class="wf-plan-block">'+planHead(tipo,ls.length+' lote'+(ls.length===1?'':'s')+' · '+nMuestras+' muestras · '+analisis.length+' análisis')
   +'<div class="wf-table-wrap wf-plan-wrap"><table class="wf-plan-table"><thead><tr><th>Lote</th><th>Cliente</th><th>Números de laboratorio</th><th>Muestras</th>'
   +analisis.map(a=>'<th class="wf-plan-anh" title="'+esc(a.nombre)+'"><span>'+esc(a.nombre)+'</span></th>').join('')+'<th>Avance del lote</th><th>Seguimiento</th><th>Acciones</th></tr></thead><tbody>'+filas+'</tbody></table></div></section>';
 }).join('')||'<p class="wf-empty">No hay lotes en curso con estos filtros.</p>';
 if(modalLot)renderModal();
}
/* ---------- Seguimiento del lote: Recepción → Identificación → Análisis → Validación → Informe → Entrega ---------- */
let planTab='curso',modalTab='muestras',eventos={};
const ETAPAS=[['recepcion','Recepción'],['identificacion','Identificación'],['analisis','Análisis'],['validacion','Validación'],['informe','Informe'],['entrega','Entrega']];
const seg=id=>(data.seguimiento||{})[id]||{};
const finalizado=id=>seg(id).informe?.estado==='FIRMADO';
const fdate=v=>{const s=String(v||'');const p=s.slice(0,10).split('-');return p.length===3?p[2]+'/'+p[1]+'/'+p[0]:s;};
const fdt=v=>{const s=String(v||'');return fdate(s)+(s.length>15&&s.slice(11,16)!=='00:00'?' '+s.slice(11,16):'');};
function etapas(id,ts){
 const s=seg(id),sols=[...new Set(ts.map(t=>t.id_solicitud))].map(x=>(data.identificacion||{})[x]).filter(Boolean);
 const identOk=sols.length&&sols.every(i=>i.version&&!i.pendientes),identPar=sols.some(i=>i.version);
 const pend=ts.filter(pending).length,iniciado=ts.some(t=>t.estado.key!=='pendiente');
 const aprob=ts.filter(t=>t.estado.key==='validado').length,todo=aprob===ts.length,enVal=ts.some(t=>t.estado.key==='validacion');
 const inf=s.informe,firmado=inf?.estado==='FIRMADO';
 const st={
  recepcion:['ok',s.recepcion?'Recibido '+fdate(s.recepcion):'Recibido'],
  identificacion:[identOk?'ok':identPar?'act':'pend',identOk?'Completa':identPar?'Parcial':'Por identificar'],
  analisis:[!pend?'ok':iniciado?'act':'pend',!pend?'Capturados':iniciado?pend+' por capturar':'Por iniciar'],
  validacion:[todo?'ok':(enVal||!pend||aprob)?'act':'pend',aprob+'/'+ts.length+' aprobados'],
  informe:[firmado?'ok':todo?'act':'pend',firmado?'Firmado '+fdate(inf.fecha_firma)+(inf.codigo?' · '+inf.codigo:''):inf?.estado==='DEVUELTO'?'Devuelto al técnico':todo?'Listo para aprobación':'En preparación'],
  entrega:[s.enviado?'ok':firmado?'act':'pend',s.enviado?'Enviado '+fdate(s.enviado):firmado?'Pendiente de envío':'—'],
 };
 const actual=ETAPAS.find(([k])=>st[k][0]!=='ok');
 return {st,actual:actual?actual[0]:'',etiqueta:actual?actual[1]+': '+st[actual[0]][1]:'Entregado al cliente'};
}
function stepper(e,big){return '<ol class="wf-steps'+(big?' big':'')+'" aria-label="Seguimiento del lote">'+ETAPAS.map(([k,l])=>'<li class="'+e.st[k][0]+(k===e.actual?' cur':'')+'" title="'+esc(l+': '+e.st[k][1])+'"><span aria-hidden="true">'+(e.st[k][0]==='ok'?'✓':'')+'</span>'+(big?'<b>'+esc(l)+'</b><small>'+esc(e.st[k][1])+'</small>':'<i class="sr-only">'+esc(l+': '+e.st[k][1])+'</i>')+'</li>').join('')+'</ol>';}
/* Finalizados: lotes con informe firmado, fuera de la vista de trabajo en curso */
function renderFinalizados(ids,all){
 const dias=$('period').value,ent=$('entrega').value,hoy=new Date();
 const rows=ids.map(id=>({id,ts:all[id],s:seg(id)})).filter(r=>{const f=r.s.informe?.fecha_firma;
  if(dias&&f&&(hoy-new Date(String(f).slice(0,10)+'T00:00:00'))/864e5>Number(dias))return false;
  if(ent==='enviado'&&!r.s.enviado)return false;if(ent==='pendiente'&&r.s.enviado)return false;return true;})
  .sort((a,b)=>String(b.s.informe?.fecha_firma||'').localeCompare(String(a.s.informe?.fecha_firma||'')));
 $('count').textContent=rows.length+' lote'+(rows.length===1?'':'s')+' finalizado'+(rows.length===1?'':'s')+' (informe aprobado y firmado) · '+rows.filter(r=>!r.s.enviado).length+' pendiente'+(rows.filter(r=>!r.s.enviado).length===1?'':'s')+' de envío';
 $('list').innerHTML=rows.length?'<section class="wf-plan-block"><div class="wf-table-wrap wf-plan-wrap"><table class="wf-plan-table wf-final-table"><thead><tr><th>Lote</th><th>Tipo</th><th>Cliente</th><th>Números de laboratorio</th><th>Muestras</th><th>Recepción</th><th>Informe</th><th>Tiempo de respuesta</th><th>Entrega</th><th>Acciones</th></tr></thead><tbody>'
  +rows.map(({id,ts,s})=>{const t=ts[0],m=samplesSorted(ts),inf=s.informe||{};
   const dur=s.recepcion&&inf.fecha_firma?Math.max(0,Math.round((new Date(String(inf.fecha_firma).slice(0,10))-new Date(String(s.recepcion).slice(0,10)))/864e5)):null;
   return '<tr><th class="wf-plan-lote"><b>'+esc(t.codigo_lote)+'</b></th><td>'+esc(tipoGrupo(t.tipo_muestra))+'</td><td>'+esc(t.cliente||'Sin cliente')+'</td><td class="wf-plan-rango">'+esc(m.length?m[0]+(m.length>1?' → '+m[m.length-1]:''):'—')+'</td><td class="wf-plan-num">'+m.length+'</td><td>'+esc(fdate(s.recepcion)||'—')+'</td>'
    +'<td><b>'+esc(inf.codigo||'—')+'</b>'+(inf.version>1?' <span class="wf-priority media">v'+inf.version+'</span>':'')+'<small class="wf-plan-sub">Firmado '+esc(fdt(inf.fecha_firma))+(inf.firmado_por?' · '+esc(inf.firmado_por):'')+'</small></td>'
    +'<td>'+(dur===null?'—':dur+' día'+(dur===1?'':'s'))+'</td>'
    +'<td>'+(s.enviado?'<span class="wf-state validado">✓ Enviado</span><small class="wf-plan-sub">'+esc(fdt(s.enviado))+'</small>':'<span class="wf-state proceso">Pendiente de envío</span>')+'</td>'
    +'<td class="wf-plan-acc"><button type="button" data-seguimiento="'+id+'">Seguimiento</button><a href="documentos_view.php" title="Ver, descargar o enviar el informe">Reportes</a></td></tr>';}).join('')+'</tbody></table></div></section>'
  :'<p class="wf-empty">No hay lotes finalizados con estos filtros.</p>';
}/* Tipos de muestra en el orden del laboratorio */
const TIPO_ORDEN=[['agua','Agua','Agua'],['cana','Caña','Caña'],['foliar','Foliares','Foliar'],['suelo','Suelos','Suelo'],['miel','Mieles','Miel']];
const tipoInfo=t=>{const n=norm(t);return TIPO_ORDEN.find(([k])=>n.includes(k));};
const tipoGrupo=t=>tipoInfo(t)?.[1]||String(t||'Otros');
const tipoRank=g=>{const i=TIPO_ORDEN.findIndex(x=>x[1]===g);return i<0?99:i;};
const samplesSorted=ts=>[...new Set(ts.map(x=>x.codigo_muestra))].sort((a,b)=>String(a).localeCompare(String(b),undefined,{numeric:true}));
function planHead(tipo,sub){const k=(TIPO_ORDEN.find(x=>x[1]===tipo)||[])[2],t=TIPOS[k]||['#858C91','<circle cx="12" cy="12" r="9"/>'];
 return '<div class="wf-plan-head"><span class="wf-plan-ico" style="background:'+t[0]+'"><svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'+t[1]+'</svg></span><div><h3>'+esc(tipo)+'</h3><small>'+esc(sub)+'</small></div></div>';}
/* Ver muestras: detalle por muestra del lote en una ventana (asignación y prioridad incluidas) */
let modalLot=null;
async function cargarEventos(id){
 try{const r=await api(null,'?modo=plan&seguimiento='+encodeURIComponent(id));eventos[id]=r.eventos||[];}
 catch(error){eventos[id]={error:error.message};}
 if(String(modalLot)===String(id)&&modalTab==='seguimiento')renderModal();
}
function renderModal(){
 const ts=tasks.filter(t=>String(t.id_lote)===String(modalLot));
 if(!ts.length){closeModal();return;}
 const t=ts[0],faltan=ts.filter(x=>x.estado.key!=='validado').length;
 $('modal-title').textContent='Lote '+t.codigo_lote+' · '+(t.cliente||'Sin cliente');
 $('modal-sub').textContent=tipoGrupo(t.tipo_muestra)+' · '+new Set(ts.map(x=>x.id_muestra)).size+' muestras · '+(faltan?faltan+' análisis por aprobar':'todos los análisis aprobados');
 app.querySelectorAll('[data-modal-tab]').forEach(b=>b.setAttribute('aria-selected',String(b.dataset.modalTab===modalTab)));
 if(modalTab!=='seguimiento'){$('modal-body').innerHTML=lotDetail(String(modalLot),ts);return;}
 const e=etapas(String(modalLot),ts),ev=eventos[modalLot],nombres=Object.fromEntries(ETAPAS);
 $('modal-body').innerHTML='<div class="wf-seg">'+stepper(e,true)+'<div class="wf-seg-head"><h3>Bitácora del lote</h3><small>Cada evento con fecha, hora y usuario · más reciente primero</small></div>'
  +(!ev?'<p class="wf-empty">Cargando bitácora…</p>':ev.error?'<p class="wf-empty">'+esc(ev.error)+'</p>':ev.length
   ?'<ol class="wf-timeline">'+ev.map(x=>'<li class="'+esc(x.etapa)+'"><span class="wf-tl-dot" aria-hidden="true"></span><div><div class="wf-tl-head"><b>'+esc(x.texto)+'</b><time>'+esc(fdt(x.fecha))+'</time></div><small>'+esc(nombres[x.etapa]||x.etapa)+(x.usuario?' · '+esc(x.usuario):'')+'</small>'+(x.detalle?'<p>'+esc(x.detalle)+'</p>':'')+'</div></li>').join('')+'</ol>'
   :'<p class="wf-empty">Sin eventos registrados todavía.</p>')+'</div>';
 if(!ev)cargarEventos(modalLot);
}
function openModal(id,tab){modalLot=id;modalTab=tab||'muestras';renderModal();$('modal').hidden=false;document.body.style.overflow='hidden';$('modal-close').focus();}
function closeModal(){modalLot=null;$('modal').hidden=true;document.body.style.overflow='';}
/* Identificación del lote (prototipo): estado en la barra del lote y etiqueta bajo cada código */
function identChip(idSolicitud){const i=(data.identificacion||{})[idSolicitud];if(!i)return '';const txt=!i.version?'Por identificar':i.pendientes?'Guardada v'+i.version+' · '+i.pendientes+' sin identificar':'Guardada v'+i.version;return '<span class="wf-ident '+(!i.version?'pendiente':i.pendientes?'parcial':'ok')+'" title="Estado de la identificación de muestras">Identificación: '+esc(txt)+'</span>';}
const identLabel=t=>String(((data.identificacion||{})[t.id_solicitud]?.etiquetas||{})[t.id_muestra]||'');
function lotDetail(id,ts){const t=ts[0], analyses=group(ts,x=>x.id_tipo_analisis), samples=group(ts,x=>x.id_muestra),dis=data.manager?'':' disabled';
 return '<div class="wf-lot-detail"><div class="wf-toolbar"><label>Prioridad del lote<select data-priority="'+id+'"'+dis+'>'+['alta','media','baja'].map(p=>'<option value="'+p+'"'+(p===t.prioridad.key?' selected':'')+'>'+p[0].toUpperCase()+p.slice(1)+'</option>').join('')+'</select></label>'+identChip(t.id_solicitud)+'<a href="listar_lotes.php?id_solicitud='+t.id_solicitud+'">Identificación</a><a href="solicitud_formulario.php?id_solicitud='+t.id_solicitud+'" target="_blank" rel="noopener">Recepción / boleta</a><label class="wf-check"><input type="checkbox" data-solo-pend'+(soloPend?' checked':'')+'> Solo muestras con análisis por aprobar</label></div><p class="wf-legend">○ Pendiente · ◐ En análisis · ● En validación · ✓ Aprobado · ↻ Reanálisis · NS No solicitado</p><div class="wf-table-wrap"><table><thead><tr><th>Código de laboratorio</th>'+Object.values(analyses).map(a=>'<th>'+esc(a[0].analisis_nombre)+'<small class="wf-plan-sub">'+a.filter(x=>x.estado.key==='validado').length+'/'+a.length+' aprobadas</small></th>').join('')+'<th>Aprob.</th></tr></thead><tbody>'+(Object.values(samples).filter(ms=>!soloPend||ms.some(x=>x.estado.key!=='validado')).sort((a,b)=>String(a[0].codigo_muestra).localeCompare(String(b[0].codigo_muestra),undefined,{numeric:true})).map(ms=>{const et=identLabel(ms[0]),ap=ms.filter(x=>x.estado.key==='validado').length;return '<tr><th><a href="listar_lotes.php?muestra='+encodeURIComponent(ms[0].codigo_muestra)+'" title="Ficha de identificación">'+esc(ms[0].codigo_muestra)+'</a>'+(et?'<small class="wf-ident-label" title="'+esc(et)+'">'+esc(et.length>34?et.slice(0,34)+'…':et)+'</small>':'')+'</th>'+Object.keys(analyses).map(a=>{const x=ms.find(m=>String(m.id_tipo_analisis)===a);return '<td>'+(x?badge(x):'NS')+'</td>';}).join('')+'<td><b>'+ap+'</b>/'+ms.length+'</td></tr>';}).join('')||'<tr><td colspan="'+(Object.keys(analyses).length+2)+'" class="wf-empty">Todas las muestras de este lote tienen sus análisis aprobados.</td></tr>')+'</tbody></table></div><div class="wf-toolbar"><h3>Asignación por análisis</h3>'+ (data.manager?'<label>Asignar todo lo pendiente a '+users('','data-assign="'+id+'"')+'</label>':'')+'</div><div class="wf-assignment-grid">'+Object.entries(analyses).map(([a,at])=>{const pend=at.filter(pending), ids=[...new Set(pend.map(x=>x.usuario_id))];return '<div class="wf-card"><b>'+esc(at[0].analisis_nombre)+'</b><p>'+pend.length+' pendientes · '+(at.length-pend.length)+' en revisión/aprobados</p>'+progress(at)+(pend.length&&data.manager?users(ids.length===1?ids[0]:'','data-assign="'+id+'" data-analysis="'+a+'" aria-label="Analista para '+esc(at[0].analisis_nombre)+'"'):'<small>'+esc([...new Set(at.map(x=>x.analista||'Sin asignar'))].join(', '))+'</small>')+'</div>';}).join('')+'</div></div>';
}
/* ---------- Bandeja: cola de captura agrupada por tipo de muestra (formato del prototipo) ---------- */
const TIPOS={Suelo:['#B34B00','<path d="M2 20h20M4 20V10l4-3 4 3 4-3 4 3v10"/>'],Foliar:['#73BC25','<path d="M11 20A7 7 0 0 1 4 13V7a1 1 0 0 1 1-1h1a7 7 0 0 1 7 7v7z"/><path d="M4 12h4M14 20a7 7 0 0 0 7-7V8"/>'],Agua:['#636A6F','<path d="M12 2s7 8 7 13a7 7 0 0 1-14 0c0-5 7-13 7-13z"/>'],'Caña':['#8A6E00','<path d="M12 22V6M12 6l5-4M12 10L7 6M12 14l5-4M12 18l-5-4"/>'],Miel:['#B34B00','<path d="M7 3h10l-1 4H8z"/><path d="M8 7c-2 2-3 4-3 7a7 7 0 0 0 14 0c0-3-1-5-3-7"/>'],Fertilizantes:['#858C91','<rect x="4" y="7" width="16" height="14" rx="2"/><path d="M8 7V5a4 4 0 0 1 8 0v2"/>']};
const cubeHead=(tipo,sub)=>{const t=TIPOS[tipo]||['#858C91','<circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/>'];return '<div class="cube-head"><div class="cube-ico" style="background:'+t[0]+'"><svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'+t[1]+'</svg></div><div><div class="ctitle">'+esc(tipo)+'</div><div class="csub">'+esc(sub)+'</div></div></div>';};
const initials=n=>String(n||'?').split(/\s+/).filter(Boolean).map(w=>w[0]).slice(0,2).join('').toUpperCase();
const prioChip=key=>'<span class="vt-chip pr-'+key+'">'+esc(key[0].toUpperCase()+key.slice(1))+'</span>';
const isRea=t=>['rechazado','repetido'].includes(t.estado.key);
/* fila completa: todos los campos obligatorios que aplican a la muestra tienen dato */
const filled=(h,filas,id,ts)=>h.cols.filter(c=>!c.auto&&!c.opc&&(!c.analisis||ts.some(t=>Number(t.id_muestra)===Number(id)&&t.analisis_hoja===c.analisis))).every(c=>String(filas?.[id]?.[c.k]??'').trim());
function renderTray(filtered){
 const a=$('analyst').value;
 const pendingTasks=filtered.filter(t=>pending(t)&&(!a||(a==='sin'?!t.usuario_id&&!t.analista:String(t.usuario_id)===a||(!t.usuario_id&&t.analista===data.usuarios.find(u=>String(u.id)===a)?.nombre))));
 const top=ts=>Math.max(...ts.map(priority)),soon=ts=>ts.map(t=>String(t.fecha_estimada||'9999')).sort()[0];
 const sheets=Object.entries(group(pendingTasks,t=>t.hoja||('legacy:'+t.id_tipo_analisis))).sort((x,y)=>top(y[1])-top(x[1])||soon(x[1]).localeCompare(soon(y[1]))||x[0].localeCompare(y[0]));
 const hoy=data.capturadas_hoy||{},nombre=a&&a!=='sin'?data.usuarios.find(u=>String(u.id)===a)?.nombre:null;
 const capHoy=a==='sin'?0:nombre?(hoy[nombre]||0):Object.values(hoy).reduce((x,y)=>x+y,0);
 $('kpis').innerHTML=[['Análisis pendientes (colas)',sheets.length,''],['Con prioridad alta',sheets.filter(([,ts])=>ts.some(t=>t.prioridad.key==='alta')).length,'is-alta'],['Muestras capturadas hoy',capHoy,'is-hoy']].map(([label,n,cls])=>'<article class="'+cls+'"><small>'+label+'</small><strong>'+n+'</strong></article>').join('');
 $('count').textContent=sheets.length+' hojas de trabajo · Alta → Media → Baja';
 const porTipo=group(sheets,([,ts])=>ts[0].tipo_muestra);
 $('list').innerHTML=Object.entries(porTipo).map(([tipo,bs])=>{
  const filas=bs.map(([key,ts])=>{
   const h=defs.hojas[key],allowed=ts.some(can),samples=Object.values(group(ts,t=>t.id_muestra)),ids=new Set(samples.map(s=>Number(s[0].id_muestra)));
   const draft=(data.borradores||{})[key],enHoja=draft?draft.seleccion.filter(id=>ids.has(Number(id))):[],listas=h?enHoja.filter(id=>filled(h,draft.filas,id,ts)).length:0;
   const lots=Object.values(group(ts,t=>t.id_lote)).sort((x,y)=>sortTasks(x[0],y[0]));
   const chips=lots.map(lt=>{const n=new Set(lt.map(t=>t.id_muestra)).size,p=lt[0].prioridad;return '<span class="lm-chip" title="'+esc(lt[0].cliente+' · prioridad del lote '+p.label)+'">'+esc(lt[0].codigo_lote)+'<span class="lm-lote">'+n+' muestra'+(n===1?'':'s')+' · <b class="lm-pr lm-'+p.key+'">'+esc(p.label)+'</b></span></span>';}).join('');
   const extras=[];if(h?.controles.length)extras.push(h.controles.map(c=>c.label).join(' + '));if(h?.curva)extras.push('curva de calibración');
   const sub=!h?'Formato de captura no configurado':(h.analisis.length>1?'Incluye: '+h.analisis.join(', ')+' · ':'')+(extras.length?extras.join(' · '):(h.generico?'captura directa del resultado':h.cols.length+' datos por muestra, sin filas de control'));
   const prioKey=ts.some(t=>t.prioridad.key==='alta')?'alta':ts.some(t=>t.prioridad.key==='media')?'media':'baja';
   const rea=ts.filter(isRea).length;
   const analistas=[...new Set(ts.map(t=>t.analista||'Sin asignar'))];
   return '<tr><td><b>'+esc(h?.titulo||ts[0].analisis_nombre)+'</b>'+(rea?' <span class="vt-chip is-rea" title="Devueltas por el técnico revisor">↻ '+new Set(ts.filter(isRea).map(t=>t.id_muestra)).size+' reanálisis</span>':'')+'<div class="cell-sub">'+esc(sub)+'</div></td>'
    +'<td>'+chips+'</td><td>'+analistas.map(n=>'<span class="bd-ini" title="'+esc(n)+'">'+esc(n==='Sin asignar'?'—':initials(n))+'</span>').join('')+'</td><td>'+prioChip(prioKey)+'</td>'
    +'<td class="small muted" style="white-space:nowrap">'+samples.length+' muestra'+(samples.length===1?'':'s')+' pendiente'+(samples.length===1?'':'s')+'<div class="cell-sub">'+enHoja.length+' en hoja abierta · '+listas+' completas</div><div class="bd-pbar"><span style="width:'+Math.round(listas/samples.length*100)+'%"></span></div></td>'
    +'<td style="text-align:right"><button type="button" class="cengi-btn cengi-btn-sm '+(h&&allowed?'cengi-btn-primary':'cengi-btn-ghost')+'" data-sheet="'+esc(key)+'"'+(!h||!allowed?' disabled':'')+'>'+(!h?'Formato no disponible':!allowed?'Asignado a otro analista':enHoja.length?'Continuar →':'Abrir hoja →')+'</button></td></tr>';
  }).join('');
  return '<div class="cube-block">'+cubeHead(tipo,bs.length+' hoja'+(bs.length===1?'':'s')+' de trabajo pendiente'+(bs.length===1?'':'s'))+'<div class="cengi-table-wrap"><table class="bd-queue"><thead><tr><th>Hoja de trabajo</th><th>Lotes pendientes</th><th>Analista(s)</th><th>Prioridad</th><th>Avance</th><th></th></tr></thead><tbody>'+filas+'</tbody></table></div></div>';
 }).join('')||'<div class="cengi-empty vt-empty-ok"><span class="material-symbols-outlined">task_alt</span>Sin hojas de trabajo pendientes con los filtros actuales.</div>';
}
$('list').addEventListener('click',e=>{const t=e.target.closest('[data-toggle]'),s=e.target.closest('[data-sheet]');const sg=e.target.closest('[data-seguimiento]');if(t)openModal(t.dataset.toggle);if(sg)openModal(sg.dataset.seguimiento,'seguimiento');if(s)openSheet(s.dataset.sheet);});
app.querySelectorAll('[data-plan-tab]').forEach(b=>b.addEventListener('click',()=>{planTab=b.dataset.planTab;renderList();}));
app.querySelectorAll('[data-modal-tab]').forEach(b=>b.addEventListener('click',()=>{modalTab=b.dataset.modalTab;renderModal();}));
if($('modal')){
 $('modal-close').addEventListener('click',closeModal);
 $('modal').addEventListener('click',e=>{if(e.target===$('modal'))closeModal();});
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!$('modal').hidden)closeModal();});
 $('modal-body').addEventListener('change',e=>{if(e.target.matches('[data-solo-pend]')){soloPend=e.target.checked;renderModal();return;}planChange(e);});
}
async function planChange(e){const el=e.target;if(busy||(!el.dataset.priority&&!el.dataset.assign)||!el.value)return;const id=Number(el.dataset.priority||el.dataset.assign),t=tasks.find(t=>Number(t.id_lote)===id);busy=true;el.disabled=true;try{await api({id_lote:id,version:t.plan_version,...(el.dataset.priority?{prioridad:el.value}:{usuario_id:Number(el.value),id_tipo_analisis:Number(el.dataset.analysis||0)})},'?modo=plan');await refresh();message('Planificación guardada. La bandeja refleja la nueva asignación y prioridad.');}catch(error){message(error.message);renderList();}finally{busy=false;}}
$('list').addEventListener('change',planChange);function newCap(key){const h=defs.hojas[key];return {hoja:key,encabezado:Object.fromEntries((h.encabezado?.campos||[]).map(c=>[c.k,c.def||''])),controles:Object.fromEntries(h.controles.map(c=>[c.id,{...(c.def||{})}])),ctrlExtra:[],filas:{},seleccion:[],curva:(h.curva?.def||[]).map(c=>({c:String(c),a:''})),fecha:localDate(),analista:data.user.nombre||data.user.correo||'',equipo:(data.equiposHoja||{})[key]||defs.catalogo.find(c=>c.tipoMuestra===key.split('|')[0]&&h.analisis.includes(c.analisis))?.equipoDefault||'',obs:''};}
/* Campo "Equipo": lista de equipos activos del Catálogo de análisis (pestaña Equipos) con su estado
   de calibración. Si el catálogo no está disponible, queda el campo de texto con sugerencias. */
const ESTADO_EQUIPO={vencida:'Calibración vencida',por_vencer:'Calibración por vencer',sin:'Sin calibración registrada',vigente:'Calibración vigente'};
function equipoInfo(etq){return (data.equipos||[]).find(e=>e.etiqueta===etq)||null;}
function avisoEquipo(etq){const e=equipoInfo(etq);if(!etq)return '';
 if(!e)return '<span class="ws-eq-estado is-sin" title="Este equipo no está registrado (o está inactivo) en Catálogo de análisis › Equipos">No registrado en el catálogo</span>';
 return '<span class="ws-eq-estado is-'+esc(e.estado)+'" title="'+esc(e.estado_texto)+'">'+esc(ESTADO_EQUIPO[e.estado]||e.estado_texto)+'</span>';}
function campoEquipo(){const lista=data.equipos||[];
 if(!lista.length)return '<input class="ws-in ws-txt" data-meta="equipo" list="wf-equipos" maxlength="255" value="'+esc(cap.equipo)+'"><datalist id="wf-equipos">'+[...new Set(defs.catalogo.map(c=>c.equipoDefault))].map(e=>'<option value="'+esc(e)+'">').join('')+'</datalist>';
 const opciones=lista.map(e=>'<option value="'+esc(e.etiqueta)+'"'+(e.etiqueta===cap.equipo?' selected':'')+'>'+esc(e.etiqueta)+(e.estado==='vencida'?' — calibración vencida':'')+'</option>').join('');
 const fuera=cap.equipo&&!equipoInfo(cap.equipo)?'<option value="'+esc(cap.equipo)+'" selected>'+esc(cap.equipo)+' (no registrado)</option>':'';
 return '<select class="ws-in ws-sel" data-meta="equipo" aria-label="Equipo" aria-describedby="wf-equipo-estado"><option value="">Seleccionar equipo…</option>'+fuera+opciones+'</select><div id="wf-equipo-estado" class="ws-eq-aviso">'+avisoEquipo(cap.equipo)+'</div>';}
async function openSheet(key){if(busy)return;if(dirty&&!confirm('Hay cambios sin guardar. ¿Salir de esta hoja?'))return;busy=true;try{const r=await api(null,'?hoja='+encodeURIComponent(key));hk=key;pool=r.tareas.sort(sortTasks);version=r.borrador.version;cap=r.borrador.datos||newCap(key);links=r.vinculos||{};dirty=false;tablet=false;sampleQuery='';step=0;emptyRows=[];expandedLots=new Set();pendQuery='';$('list-view').hidden=true;$('sheet-view').hidden=false;renderSheet();if(cap.seleccion.some(id=>!pool.some(t=>Number(t.id_muestra)===id)))message('Hay muestras del borrador que cambiaron de asignación o ya se enviaron. Quite esas filas antes de guardar.');else message('');}catch(error){message(error.message);}finally{busy=false;if(cap)updateComplete();}}
const hdef=()=>defs.hojas[hk];
const sample=id=>pool.filter(t=>Number(t.id_muestra)===Number(id));
const ctrls=()=>[...hdef().controles,...cap.ctrlExtra];
const applicable=(c,id)=>!c.analisis||sample(id).some(t=>t.analisis_hoja===c.analisis);
/* ---------- Hoja de trabajo (formato del prototipo) ---------- */
let emptyRows=[],rowSeq=0,expandedLots=new Set(),pendQuery='';
const CHIPS_VISIBLES=12;
/* filtra los chips del panel de pendientes sin re-renderizar (conserva el foco del buscador) */
function applyPendFilter(){
 const q=norm(pendQuery);
 app.querySelectorAll('[data-pend-lot]').forEach(card=>{
  let visibles=0;const abierto=expandedLots.has(card.dataset.pendLot);
  card.querySelectorAll('[data-add]').forEach(chip=>{const ok=!q||chip.dataset.code.includes(q);chip.hidden=!ok||(!q&&chip.dataset.extra==='1'&&!abierto);if(ok)visibles++;});
  card.hidden=!!q&&!visibles;
  const mas=card.querySelector('[data-expand-lot]');if(mas)mas.hidden=!!q;
 });
}
function input(c,section,id,v,disabled=false){const attrs=' data-section="'+section+'" data-id="'+esc(id)+'" data-field="'+c.k+'" aria-label="'+esc(c.label)+'"';if(disabled)return '<input class="ws-in" disabled placeholder="—" aria-label="'+esc(c.label)+' (no aplica)">';if(c.auto)return '<span class="ws-auto" title="Calculado automáticamente" data-auto="'+section+':'+esc(id)+':'+c.k+'">'+esc(defs.fx(c.auto((section==='controles'?cap.controles:cap.filas)[id]||{}),2))+'</span>';if(c.tipo==='sel')return '<select class="ws-in"'+attrs+'>'+c.opts.map(o=>'<option'+(String(v??'')===o?' selected':'')+'>'+esc(o)+'</option>').join('')+'</select>';return '<input type="text" class="ws-in'+(c.tipo==='txt'?' ws-txt':'')+'"'+attrs+' inputmode="'+(c.tipo==='txt'?'text':'decimal')+'" maxlength="250" value="'+esc(v||'')+'"'+(section==='encabezado'&&c.unidad?' placeholder="'+esc(c.unidad)+'"':'')+'>';}
const thLabel=c=>esc(c.label)+(c.opc&&c.opcTxt!==false?' <span class="muted">(opcional)</span>':'')+(c.vinculo?'<span class="bd-hint">↪ se toma de '+esc(typeof c.vinculo==='object'?c.vinculo.nombre:c.vinculo)+'</span>':'');
function theadHoja(cols,prefijo,sufijo){
 if(!cols.some(c=>c.g))return '<tr>'+prefijo.map(p=>'<th>'+p+'</th>').join('')+cols.map(c=>'<th>'+thLabel(c)+'</th>').join('')+sufijo.map(p=>'<th>'+p+'</th>').join('')+'</tr>';
 let grp='',i=0;while(i<cols.length){const g=cols[i].g||'';let n=0;while(i<cols.length&&(cols[i].g||'')===g){n++;i++;}grp+='<th colspan="'+n+'" class="'+(g?'grp':'')+'">'+esc(g)+'</th>';}
 return '<tr>'+prefijo.map(p=>'<th rowspan="2">'+p+'</th>').join('')+grp+sufijo.map(p=>'<th rowspan="2">'+p+'</th>').join('')+'</tr><tr>'+cols.map(c=>'<th>'+thLabel(c)+'</th>').join('')+'</tr>';
}
const lotsOfPool=()=>Object.values(group(pool,t=>t.id_lote)).sort((a,b)=>sortTasks(a[0],b[0]));
const samplesOf=ts=>Object.values(group(ts,t=>t.id_muestra)).sort((a,b)=>String(a[0].codigo_muestra).localeCompare(String(b[0].codigo_muestra),undefined,{numeric:true}));
const reaChip=id=>sample(id).some(isRea)?'<span class="vt-chip is-rea" title="Reanálisis solicitado por el técnico revisor">↻ Reanálisis</span>':'';
/* opciones de número de laboratorio agrupadas por lote; excluye las ya colocadas en otra fila */
function labOptions(current,lote){
 return lotsOfPool().filter(lt=>!lote||String(lt[0].id_lote)===String(lote)).map(lt=>{const ops=samplesOf(lt).filter(s=>{const id=Number(s[0].id_muestra);return id===Number(current)||!cap.seleccion.includes(id);});
  return ops.length?'<optgroup label="Lote '+esc(lt[0].codigo_lote)+'">'+ops.map(s=>'<option value="'+s[0].id_muestra+'"'+(Number(s[0].id_muestra)===Number(current)?' selected':'')+'>'+esc(s[0].codigo_muestra)+(s.some(isRea)?' (reanálisis)':'')+'</option>').join('')+'</optgroup>':'';}).join('');
}
function renderHead(){
 const nPend=new Set(pool.map(t=>t.id_muestra)).size,nLots=new Set(pool.map(t=>t.id_lote)).size,sel=cap.seleccion.length;
 $('sheet-title').textContent=hdef().titulo+' · '+hk.split('|')[0];
 $('sheet-summary').textContent=nPend+' muestra'+(nPend===1?'':'s')+' pendiente'+(nPend===1?'':'s')+' en '+nLots+' lote'+(nLots===1?'':'s')+' · '+sel+' seleccionada'+(sel===1?'':'s')+' en esta hoja · '+(dirty?'Cambios sin guardar':'Sin cambios pendientes');
 $('grid-mode').className=tablet?'':'active';$('tablet-mode').className=tablet?'active':'';
}
function renderSheet(){
 const h=hdef();let html='';
 if(h.encabezado||h.referencia){
  html+='<div class="ws-card"><div class="ws-card-body ws-top">';
  if(h.encabezado)html+='<div class="ws-top-main"><div class="ws-sec-title">'+esc(h.encabezado.titulo)+'</div><div class="ws-enc">'+h.encabezado.campos.map(c=>'<div><label>'+esc(c.label)+'</label>'+input(c,'encabezado','',cap.encabezado[c.k])+'</div>').join('')+'</div></div>';
  if(h.referencia)html+='<div class="ws-ref"><div class="ws-sec-title">'+esc(h.referencia.titulo)+'</div><table>'+h.referencia.filas.map((r,i)=>'<tr>'+r.map(v=>i?'<td>'+esc(v)+'</td>':'<th>'+esc(v)+'</th>').join('')+'</tr>').join('')+'</table></div>';
  html+='</div></div>';
 }
 /* muestras pendientes por lote: las ya analizadas no aparecen, solo se cuentan. Para lotes grandes
    (p. ej. 100 muestras) solo se listan las que faltan por agregar, las primeras CHIPS_VISIBLES y el
    resto bajo "Ver más"; "Agregar rango" toma un tramo consecutivo de números de laboratorio. */
 const totalLibres=new Set(pool.filter(t=>!cap.seleccion.includes(Number(t.id_muestra))).map(t=>t.id_muestra)).size;
 const panel=lotsOfPool().map(lt=>{
  const lot=String(lt[0].id_lote),ss=samplesOf(lt),libres=ss.filter(s=>!cap.seleccion.includes(Number(s[0].id_muestra))),enHoja=ss.length-libres.length;
  const hechas=new Set(tasks.filter(t=>t.hoja===hk&&String(t.id_lote)===lot&&['validacion','validado'].includes(t.estado.key)).map(t=>t.id_muestra)).size;
  const abierto=expandedLots.has(lot);
  const chips=libres.map((s,i)=>{const rea=s.some(isRea),extra=i>=CHIPS_VISIBLES;return '<button type="button" class="ws-chip" data-add="'+s[0].id_muestra+'" data-code="'+esc(norm(s[0].codigo_muestra))+'"'+(extra?' data-extra="1"'+(abierto?'':' hidden'):'')+' title="Agregar a la hoja'+(rea?' — reanálisis':'')+'">+ '+esc(s[0].codigo_muestra)+(rea?' ↻':'')+'</button>';}).join('');
  const opciones=libres.map(s=>'<option value="'+s[0].id_muestra+'">'+esc(s[0].codigo_muestra)+'</option>').join('');
  const resumen=libres.length+' por agregar'+(enHoja?' · '+enHoja+' en la hoja':'')+(hechas?' · '+hechas+' ya analizada'+(hechas===1?'':'s'):'');
  return '<div class="ws-pend-lote'+(libres.length?'':' is-done')+'" data-pend-lot="'+lot+'"><div class="ws-pend-head"><div><b class="vt-mono">Lote '+esc(lt[0].codigo_lote)+'</b> <span class="small muted">'+esc(lt[0].cliente||'')+'</span> '+prioChip(lt[0].prioridad.key)+'<div class="small muted">'+resumen+'</div></div>'
   +(libres.length?'<div class="ws-chips"><button type="button" class="cengi-btn cengi-btn-ghost cengi-btn-sm" data-add-lot="'+lot+'">+ Agregar lote</button></div>':'<span class="vt-chip is-approved">✓ Todo en la hoja</span>')+'</div>'
   +(libres.length>1?'<div class="ws-range"><span class="small muted">Rango</span><select class="ws-in ws-sel" id="wf-rng-a-'+lot+'" aria-label="Desde">'+opciones+'</select><span class="small muted">a</span><select class="ws-in ws-sel" id="wf-rng-b-'+lot+'" aria-label="Hasta">'+opciones.replace(/(<option value="[^"]*")(>[^<]*<\/option>)$/,'$1 selected$2')+'</select><button type="button" class="cengi-btn cengi-btn-ghost cengi-btn-sm" data-add-range="'+lot+'">+ Agregar rango</button></div>':'')
   +(libres.length?'<div class="ws-chips ws-pend-chips">'+chips+'</div>'+(libres.length>CHIPS_VISIBLES?'<button type="button" class="bd-more" data-expand-lot="'+lot+'">'+(abierto?'Ver menos':'Ver '+(libres.length-CHIPS_VISIBLES)+' más')+'</button>':''):'')+'</div>';
 }).join('');
 html+='<div class="ws-card"><div class="ws-card-head"><div><div class="ws-sec-title">Muestras pendientes de este análisis</div><div class="small muted" style="margin-top:2px">Agrega un lote completo, un rango de números o toca una muestra. Las muestras ya analizadas no aparecen.</div></div><div class="ws-chips">'+(totalLibres>CHIPS_VISIBLES?'<input type="search" id="wf-pend-search" class="bd-row-search" value="'+esc(pendQuery)+'" placeholder="Buscar número de laboratorio" aria-label="Buscar muestra pendiente">':'')+(totalLibres?'<button type="button" class="cengi-btn cengi-btn-ghost cengi-btn-sm" data-action="all">Agregar todas ('+totalLibres+')</button>':'')+(tablet?botonQuitar():'')+'</div></div><div class="ws-card-body ws-pend">'+(panel||'<p class="small muted">No hay muestras pendientes asignadas a usted en esta hoja.</p>')+'</div></div>';
 if(tablet)html+=renderTablet();else{
  const filasCtrl=ctrls().map(c=>'<tr class="ws-ctrl"><td class="muted small">·</td><td class="muted small">—</td><td class="ws-lbl">'+esc(c.label)+'</td>'+h.cols.map(col=>'<td>'+input(col,'controles',c.id,cap.controles[c.id]?.[col.k],c.sin?.includes(col.k))+'</td>').join('')+'<td></td></tr>').join('');
  const filasM=cap.seleccion.map((id,i)=>{const t=sample(id)[0],match=!sampleQuery||norm((t?.codigo_muestra||id)+' '+(t?.codigo_lote||'')).includes(norm(sampleQuery));
   return '<tr data-sample-row="'+id+'"'+(t&&complete(id)?' class="ws-ok"':'')+(!match?' hidden':'')+'><td class="muted small">'+(i+1)+'</td><td class="vt-mono small">'+esc(t?.codigo_lote||'—')+'</td>'
    +'<td><div class="ws-chips" style="flex-wrap:nowrap">'+(t?'<select class="ws-in ws-sel ws-sel-lab" data-row="'+i+'" aria-label="Número de laboratorio">'+labOptions(id,'')+'</select>':'<span class="vt-flag">Muestra no disponible #'+esc(id)+'</span>')+reaChip(id)+'</div></td>'
    +h.cols.map(c=>'<td>'+(!t?'<input class="ws-in" disabled placeholder="—">':applicable(c,id)?input(c,'filas',id,cap.filas[id]?.[c.k]):'<div class="ws-ns" title="Análisis no solicitado para esta muestra">n/s</div>')+'</td>').join('')
    +'<td><button type="button" class="rm-row" data-remove="'+id+'" aria-label="Quitar fila" title="Quitar fila">×</button></td></tr>';}).join('');
  const vacias=emptyRows.length||cap.seleccion.length?emptyRows:[{rid:0,lote:''}];
  const filasV=vacias.map((r,i)=>'<tr><td class="muted small">'+(cap.seleccion.length+i+1)+'</td><td><select class="ws-in ws-sel" data-empty-lote="'+r.rid+'" aria-label="Lote" title="Filtra los números de laboratorio por lote"><option value="">Todos</option>'+lotsOfPool().map(lt=>'<option value="'+lt[0].id_lote+'"'+(String(r.lote)===String(lt[0].id_lote)?' selected':'')+'>'+esc(lt[0].codigo_lote)+'</option>').join('')+'</select></td>'
   +'<td><select class="ws-in ws-sel ws-sel-lab vacio" data-empty="'+r.rid+'" aria-label="Número de laboratorio"><option value="">Seleccionar número…</option>'+labOptions(null,r.lote)+'</select></td>'+h.cols.map(()=>'<td><input class="ws-in" disabled placeholder="—"></td>').join('')
   +'<td>'+(r.rid?'<button type="button" class="rm-row" data-remove-empty="'+r.rid+'" aria-label="Quitar fila" title="Quitar fila">×</button>':'')+'</td></tr>').join('');
  html+='<div class="ws-card"><div class="ws-card-head"><div class="ws-sec-title">Datos de análisis por muestra</div><div class="ws-chips"><input type="search" id="wf-row-search" class="bd-row-search" value="'+esc(sampleQuery)+'" placeholder="Buscar muestra o lote en la hoja" aria-label="Buscar muestra en la hoja"><button type="button" class="cengi-btn cengi-btn-ghost cengi-btn-sm" data-action="row">+ Agregar fila</button>'+botonQuitar()+''+(h.controles.length?'<button type="button" class="cengi-btn cengi-btn-ghost cengi-btn-sm" data-action="control">+ Fila de control</button>':'')+'</div></div>'
   +'<div class="ws-scroll"><table class="ws"><thead>'+theadHoja(h.cols,['#','Lote','Número de laboratorio'],[''])+'</thead><tbody>'+filasCtrl+filasM+filasV+'</tbody></table></div>'
   +'<div class="ws-foot-note">Selecciona el número de laboratorio y el lote se completa solo; el filtro de lote de las filas vacías es opcional.'+(h.controles.length?' Las filas de control ('+esc(h.controles.map(c=>c.label.toLowerCase()).join(', '))+') van al inicio.':'')+' Puedes pegar un bloque desde Excel en las celdas de muestras; Enter avanza en la misma columna.</div></div>';
 }
 if(h.curva)html+='<div class="ws-card"><div class="ws-card-head"><div class="ws-sec-title">Curva de calibración</div><button type="button" class="cengi-btn cengi-btn-ghost cengi-btn-sm" data-action="point">+ Agregar punto</button></div><div class="ws-card-body"><table class="ws ws-curva-tbl"><thead><tr><th>Punto curva ('+esc(h.curva.unidadX)+')</th><th>Absorbancia</th></tr></thead><tbody>'+cap.curva.map((p,i)=>'<tr><td>'+input({k:'c',label:'Concentración'},'curva',i,p.c)+'</td><td>'+input({k:'a',label:'Absorbancia'},'curva',i,p.a)+'</td></tr>').join('')+'</tbody></table><div class="ws-foot-note" style="padding:8px 0 0;border:0">El gráfico y la ecuación de la curva los revisa el técnico en Validación técnica.</div></div></div>';
 html+='<div class="ws-card"><div class="ws-card-body"><div class="ws-enc" style="margin:0"><div><label>Fecha de análisis *</label><input class="ws-in" type="date" data-meta="fecha" value="'+esc(cap.fecha)+'"></div><div><label>Analista *</label><input class="ws-in ws-txt" value="'+esc(cap.analista)+'" readonly title="Se toma de la sesión iniciada"></div><div><label>Equipo</label>'+campoEquipo()+'</div><div class="ws-wide"><label>Observaciones</label><textarea class="ws-in" data-meta="obs" maxlength="2000" placeholder="Opcional…">'+esc(cap.obs)+'</textarea></div></div></div></div>';
 $('sheet-body').innerHTML=html;if(pendQuery)applyPendFilter();updateComplete();
}
/* vista tablet: un registro a la vez, controles primero */
/* Quitar muestras en bloque de "Datos de análisis por muestra": todas, o solo las que coinciden
   con la búsqueda de la hoja. Así se puede agregar un lote completo y dejar solo las que se analizarán. */
function muestrasAQuitar(){return cap.seleccion.filter(id=>{const t=sample(id)[0];return !sampleQuery||norm((t?.codigo_muestra||id)+' '+(t?.codigo_lote||'')).includes(norm(sampleQuery));});}
function botonQuitar(){const n=muestrasAQuitar().length;if(!n)return '';
 return '<button type="button" class="cengi-btn cengi-btn-ghost cengi-btn-sm ws-btn-quitar" data-action="clear" title="'+(sampleQuery?'Quita de la hoja las muestras que coinciden con la búsqueda':'Quita todas las muestras de la hoja; luego agrega solo las que vas a analizar')+'">'+(sampleQuery?'Quitar las filtradas ('+n+')':'Quitar todas ('+n+')')+'</button>';}
function quitarMuestras(){const ids=muestrasAQuitar();if(!ids.length)return;
 const conDatos=ids.filter(id=>Object.values(cap.filas[id]||{}).some(v=>String(v??'').trim())).length;
 if(!confirm('Se quitarán '+ids.length+' muestra(s) de la hoja.'+(conDatos?' '+conDatos+' ya tienen datos escritos, que se perderán al guardar.':'')+' Las muestras vuelven a "Muestras pendientes" para agregarlas de nuevo. ¿Continuar?'))return;
 const quitar=new Set(ids.map(String));cap.seleccion=cap.seleccion.filter(id=>!quitar.has(String(id)));sampleQuery='';step=0;changed();renderSheet();
 message(ids.length+' muestra(s) quitadas de la hoja. Agrega desde "Muestras pendientes" las que vas a analizar.');}
function renderTablet(){
 const steps=[...ctrls().map(c=>({section:'controles',id:c.id,label:c.label,ctrl:c})),...cap.seleccion.filter(id=>sample(id).length).map(id=>({section:'filas',id,label:sample(id)[0].codigo_muestra,lote:sample(id)[0].codigo_lote}))];
 step=Math.max(0,Math.min(step,steps.length-1));const p=steps[step];if(!p)return '<div class="cengi-empty">Agrega muestras desde el panel de arriba para comenzar.</div>';
 const nCtrl=ctrls().length,last=step===steps.length-1;
 const campos=hdef().cols.filter(c=>p.ctrl?!p.ctrl.sin?.includes(c.k):applicable(c,p.id)).map(c=>'<div class="field-lg"><label>'+esc(c.label)+(c.g?' · '+esc(c.g):'')+'</label>'+input(c,p.section,p.id,cap[p.section][p.id]?.[c.k])+'</div>').join('');
 return '<div class="capture-wrap"><div class="dot-progress">'+steps.map((q,i)=>'<button type="button" class="dprog'+(i===step?' current':'')+(!q.ctrl&&complete(q.id)?' filled':'')+'" data-go="'+i+'" title="'+esc(q.label)+'">'+(q.ctrl?'C':i+1-nCtrl)+'</button>').join('')+'</div>'
  +'<div class="capture-body"><div class="capture-muestra-tag"><span>'+esc(p.label)+'</span><span class="vt-chip">'+(p.ctrl?'Fila de control':esc(p.lote))+'</span>'+(p.ctrl?'':reaChip(p.id))+'</div><div class="capture-grid">'+campos+'</div></div>'
  +'<div class="capture-foot"><button type="button" class="cengi-btn cengi-btn-ghost" data-step="-1"'+(!step?' disabled':'')+'>Anterior</button><span class="small muted">Registro '+(step+1)+' de '+steps.length+'</span>'+(last?'<button type="button" class="cengi-btn cengi-btn-primary" data-action="grid">Revisar en grilla</button>':'<button type="button" class="cengi-btn cengi-btn-primary" data-step="1">Siguiente</button>')+'</div></div>';
}
function complete(id){return hdef().cols.filter(c=>!c.auto&&!c.opc&&applicable(c,id)).every(c=>String(cap.filas[id]?.[c.k]??'').trim());}
function updateComplete(){const missing=cap.seleccion.filter(id=>!sample(id).length||!complete(id)).length;const blank=hdef().controles.find(c=>c.id==='BLANCO');const blankMissing=blank&&hdef().cols.some(c=>!c.auto&&!c.opc&&!blank.sin?.includes(c.k)&&(!c.analisis||cap.seleccion.some(id=>applicable(c,id)))&&!String(cap.controles.BLANCO?.[c.k]??'').trim());const curveMissing=hdef().curva&&!defs.regresion(cap.curva)&&!defs.num(cap.encabezado.pend);const n=cap.seleccion.length;const reason=!n?'Selecciona al menos un número de laboratorio':missing?'Faltan datos en '+missing+' muestra'+(missing===1?'':'s'):blankMissing?'Falta la fila del blanco':curveMissing?'Faltan puntos de la curva':!cap.fecha?'Falta la fecha de análisis':'';$('send').disabled=busy||!!reason;$('send').textContent=reason||'Enviar '+n+' muestra'+(n===1?'':'s')+' a validación técnica';renderHead();
 if(!tablet)app.querySelectorAll('[data-sample-row]').forEach(tr=>{tr.className=sample(tr.dataset.sampleRow).length&&complete(tr.dataset.sampleRow)?'ws-ok':'';});}
function changed(){dirty=true;updateComplete();}
function applyLinks(id){
 const row=cap.filas[id];
 hdef().cols.filter(c=>c.vinculo).forEach(c=>{
   if(String(row[c.k]??'').trim())return;
   const key=typeof c.vinculo==='object'?c.vinculo.hoja:'Suelo|Humedad residual',ref=links[key]?.[id];if(!ref)return;
   let v;
   if(typeof c.vinculo==='object')v=ref.campos[c.vinculo.campo];
   else v=defs.hojas[key].calc(ref.campos,{enc:ref.encabezado,ctrl:ref.controles,blk:ref.controles.BLANCO||{},reg:defs.regresion(ref.curva),regL:defs.regLineal(ref.curva)},'M').pct;
   if(v!==undefined&&v!==null&&String(v).trim()!=='')row[c.k]=String(v);
 });
}
function addSamples(ids){ids.forEach(id=>{id=Number(id);if(!cap.seleccion.includes(id)){cap.seleccion.push(id);cap.filas[id]??={};applyLinks(id);emptyRows.shift();}});changed();renderSheet();}
$('sheet-body').addEventListener('change',e=>{if(busy)return;const d=e.target.dataset||{};
 if(d.row!==undefined&&e.target.value){const id=Number(e.target.value);cap.seleccion[Number(d.row)]=id;cap.filas[id]??={};applyLinks(id);changed();renderSheet();}
 if(d.emptyLote!==undefined){const r=emptyRows.find(x=>String(x.rid)===d.emptyLote);if(r)r.lote=e.target.value;else emptyRows.push({rid:++rowSeq,lote:e.target.value});renderSheet();}
 if(d.empty!==undefined&&e.target.value){emptyRows=emptyRows.filter(x=>String(x.rid)!==d.empty);const id=Number(e.target.value);if(!cap.seleccion.includes(id)){cap.seleccion.push(id);cap.filas[id]??={};applyLinks(id);}changed();renderSheet();}
});
$('sheet-body').addEventListener('click',e=>{if(busy)return;const b=e.target.closest('button');if(!b)return;const a=b.dataset.action;
 if(a==='all')addSamples(pool.map(t=>t.id_muestra));
 if(a==='clear')quitarMuestras();
 if(a==='control'){const id='CONTROL_EXTRA_'+(cap.ctrlExtra.length+1);cap.ctrlExtra.push({id,label:'Control adicional '+(cap.ctrlExtra.length+1)});cap.controles[id]={};changed();renderSheet();}
 if(a==='point'){cap.curva.push({c:'',a:''});changed();renderSheet();}
 if(a==='row'){emptyRows.push({rid:++rowSeq,lote:''});renderSheet();}
 if(a==='grid'){tablet=false;renderSheet();}
 if(b.dataset.add)addSamples([b.dataset.add]);
 if(b.dataset.addLot)addSamples(pool.filter(t=>String(t.id_lote)===b.dataset.addLot).map(t=>t.id_muestra));
 if(b.dataset.remove){cap.seleccion=cap.seleccion.filter(id=>String(id)!==b.dataset.remove);changed();renderSheet();}
 if(b.dataset.expandLot){const l=b.dataset.expandLot;expandedLots.has(l)?expandedLots.delete(l):expandedLots.add(l);renderSheet();}
 if(b.dataset.addRange){
  const l=b.dataset.addRange,libres=samplesOf(pool.filter(t=>String(t.id_lote)===l)).map(s=>Number(s[0].id_muestra)).filter(id=>!cap.seleccion.includes(id));
  let i=libres.indexOf(Number(app.querySelector('#wf-rng-a-'+l).value)),j=libres.indexOf(Number(app.querySelector('#wf-rng-b-'+l).value));
  if(i>j)[i,j]=[j,i];
  if(i>=0)addSamples(libres.slice(i,j+1));
 }
 if(b.dataset.removeEmpty){emptyRows=emptyRows.filter(x=>String(x.rid)!==b.dataset.removeEmpty);renderSheet();}
 if(b.dataset.step){step+=Number(b.dataset.step);renderSheet();}
 if(b.dataset.go!==undefined){step=Number(b.dataset.go);renderSheet();}
});
$('grid-mode').addEventListener('click',()=>{tablet=false;renderSheet();});$('tablet-mode').addEventListener('click',()=>{tablet=true;renderSheet();});
$('back').addEventListener('click',()=>{if(busy)return;if(dirty&&!confirm('Hay cambios sin guardar. ¿Volver a las colas?'))return;dirty=false;$('sheet-view').hidden=true;$('list-view').hidden=false;refresh().catch(e=>message(e.message));});
$('sheet-body').addEventListener('input',e=>{
 const el=e.target;if(busy)return;
 if(el.id==='wf-pend-search'){pendQuery=el.value;applyPendFilter();return;}
 if(el.id==='wf-row-search'){sampleQuery=el.value;app.querySelectorAll('[data-sample-row]').forEach(tr=>{const t=sample(tr.dataset.sampleRow)[0];tr.hidden=!norm((t?.codigo_muestra||'')+' '+(t?.codigo_lote||'')).includes(norm(sampleQuery));});return;}
 if(el.dataset.meta){cap[el.dataset.meta]=el.value;if(el.dataset.meta==='equipo'){const av=$('equipo-estado');if(av)av.innerHTML=avisoEquipo(el.value);}changed();return;}
 const {section,id,field}=el.dataset;if(!section)return;
 if(section==='encabezado')cap.encabezado[field]=el.value;else{cap[section][id]??={};cap[section][id][field]=el.value;}
 if(['filas','controles'].includes(section))hdef().cols.filter(c=>c.auto).forEach(c=>{const output=[...app.querySelectorAll('[data-auto]')].find(n=>n.dataset.auto===section+':'+id+':'+c.k);if(output)output.textContent=defs.fx(c.auto(cap[section][id]),2);});changed();
});
async function saveSheet(send){if(busy)return;if(send&&!confirm('¿Enviar las '+cap.seleccion.length+' muestras seleccionadas a validación técnica?'))return;busy=true;app.querySelectorAll('input,select,textarea,button').forEach(n=>n.disabled=true);try{const result=await api({accion:send?'enviar':'guardar',hoja:hk,version,datos:cap});version=result.version;dirty=false;if(send){cap=newCap(hk);emptyRows=[];$('sheet-view').hidden=true;$('list-view').hidden=false;await refresh();message('Muestras enviadas a validación técnica. Las no seleccionadas siguen en la bandeja.');}else{message('Avance guardado en el sistema. Puede continuar después.');renderSheet();}}catch(e){message(e.message);}finally{busy=false;app.querySelectorAll('input,select,textarea,button').forEach(n=>n.disabled=false);if(!$('sheet-view').hidden){renderSheet();}else renderList();}}
$('save').addEventListener('click',()=>saveSheet(false));$('send').addEventListener('click',()=>saveSheet(true));
function visibleSamples(){return cap.seleccion.filter(id=>{const t=sample(id)[0];return !sampleQuery||norm((t?.codigo_muestra||id)+' '+(t?.codigo_lote||'')).includes(norm(sampleQuery));});}
$('sheet-body').addEventListener('keydown',e=>{if(e.key!=='Enter'||e.target.dataset.section!=='filas')return;e.preventDefault();const ids=visibleSamples(),i=ids.indexOf(Number(e.target.dataset.id)),next=ids[i+(e.shiftKey?-1:1)];if(next!==undefined)app.querySelector('[data-section="filas"][data-id="'+next+'"][data-field="'+e.target.dataset.field+'"]')?.focus();});
function parseTSV(text){const rows=[];let row=[],cell='',quoted=false;for(const c of text.replace(/\r\n?/g,'\n')){if(c==='"'){quoted=!quoted;}else if(!quoted&&(c==='\t'||c==='\n')){row.push(cell);cell='';if(c==='\n'){rows.push(row);row=[];}}else cell+=c;}if(quoted)throw Error('Comillas sin cerrar en el bloque.');if(cell||row.length){row.push(cell);rows.push(row);}return rows;}
$('sheet-body').addEventListener('paste',e=>{if(busy||e.target.dataset.section!=='filas')return;const text=e.clipboardData.getData('text/plain');if(!/[\t\n]/.test(text))return;e.preventDefault();try{const vals=parseTSV(text),ids=visibleSamples(),start=ids.indexOf(Number(e.target.dataset.id)),cols=hdef().cols,ci=cols.findIndex(c=>c.k===e.target.dataset.field);if(start<0||start+vals.length>ids.length||vals.some(r=>ci+r.length>cols.length))throw Error('El bloque excede las filas visibles o columnas disponibles.');const changes=[];vals.forEach((r,ri)=>r.forEach((v,j)=>{const c=cols[ci+j],id=ids[start+ri];if(c.auto||!applicable(c,id)){if(v.trim())throw Error('El bloque incluye campos calculados o no solicitados.');return;}if(v.length>250)throw Error('Una celda excede 250 caracteres.');changes.push([id,c.k,v.trim()]);}));changes.forEach(([id,k,v])=>{cap.filas[id]??={};cap.filas[id][k]=v;});changed();renderSheet();message(vals.length+' filas pegadas. Guarde el avance.');}catch(error){message(error.message);}});
window.addEventListener('beforeunload',e=>{if(dirty){e.preventDefault();e.returnValue='';}});
init();
})();