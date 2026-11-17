(() => {
  'use strict';
  const root = document.getElementById('directorio');
  if (!root) return;
  let data = JSON.parse(document.getElementById('dir-data').textContent);
  const $ = id => document.getElementById(id);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  const norm = value => String(value ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const date = value => value ? String(value).slice(0,10).split('-').reverse().join('/') : '—';
  const defaults = {q:'',area:'',nivel:'',comite:'todos',empresa:'',origen:'',estado:'Activo',part:'',perfil:''};
  let filters = {...defaults};
  const params = new URLSearchParams(location.search);
  Object.keys(defaults).forEach(key => { if (params.has(key)) filters[key] = params.get(key); });
  let selection = new Set(), showMap = false, showPart = false, busy = false, share = null, previewTimer, previewRequest = 0;
  const names = () => Object.fromEntries(data.comites.map(c => [c.id,c.nombre]));
  const badge = (value, tone='') => `<span class="dir-badge ${tone}">${esc(value)}</span>`;
  const icon = (action,id,name,title,danger='') => `<button class="dir-icon ${danger}" data-dir="${action}" data-id="${id}" title="${title}" aria-label="${title}"><i class="ti ti-${name}" aria-hidden="true"></i></button>`;
  const button = (action,label,primary=false,disabled=false) => `<button class="dir-btn ${primary?'primary':''}" data-dir="${action}" ${disabled?'disabled':''}>${label}</button>`;
  const option = (value,label,chosen) => `<option value="${esc(value)}" ${String(value)===String(chosen)?'selected':''}>${esc(label)}</option>`;
  const options = (values,chosen,emptyLabel) => option('',emptyLabel,chosen)+values.map(v=>option(v,v,chosen)).join('');
  function matches(c, f=filters) {
    return (f.estado==='todos'||c.estado===f.estado)
      && (!f.area||(f.area==='__sin'?!c.area:c.area===f.area))
      && (!f.nivel||(f.nivel==='__sin'?!c.nivel:c.nivel===f.nivel))
      && (!f.origen||c.origen_tipo===f.origen)
      && (!f.part||c.participacion.nivel===f.part)
      && (!f.perfil||c.participacion.perfil===f.perfil)
      && (!f.empresa||c.empresa===f.empresa)
      && (!f.comite||f.comite==='todos'||c.comites.includes(f.comite))
      && norm([c.nombre,c.cargo,c.empresa,c.email,c.area,c.origen_detalle,data.origenes[c.origen_tipo]].join(' ')).includes(norm(f.q));
  }
  const filtered = () => data.contactos.filter(c=>matches(c));
  const periodo = () => data.periodos?.[data.meses] || 'Últimos 12 meses';
  // Descripcion legible de los filtros activos (encabeza el panel y los informes).
  function filtersText() {
    const f=filters, partes=[];
    if(f.estado!=='todos') partes.push(f.estado==='Activo'?'activos':'inactivos');
    if(f.comite&&f.comite!=='todos') partes.push('comité '+(names()[f.comite]||f.comite));
    if(f.empresa) partes.push('empresa '+f.empresa);
    if(f.perfil) partes.push('perfil '+(data.perfiles[f.perfil]?.nombre||f.perfil));
    if(f.area) partes.push(f.area==='__sin'?'sin área':'área '+f.area);
    if(f.nivel) partes.push(f.nivel==='__sin'?'sin nivel':'nivel '+f.nivel);
    if(f.origen) partes.push('origen '+(data.origenes[f.origen]||f.origen));
    if(f.part) partes.push('participación '+data.participacion_niveles[f.part].toLowerCase());
    if(f.q) partes.push(`búsqueda «${f.q}»`);
    return partes.length?'Contactos '+partes.join(' · '):'Todos los contactos';
  }
  // Participacion: insignia del nivel con su indice (0-100) y barra de asistencia.
  const partBadge = p => `<span class="dir-part ${p.nivel}" title="Índice de participación ${p.indice}/100">${esc(data.participacion_niveles[p.nivel])}${p.nivel!=='sin_comite'?` · ${p.indice}`:''}</span>`;
  const meter = (pct, cls='') => `<span class="dir-meter ${cls}"><span style="width:${Math.max(0,Math.min(100,pct))}%"></span></span>`;
  const avg = list => list.length ? list.reduce((a,b)=>a+b,0)/list.length : 0;
  function notify(message,error=false) { const el=$('dir-message'); el.textContent=message; el.classList.toggle('error',error); el.hidden=!message; }
  function modalError(message) { const el=$('dir-modal-error'); el.textContent=message; el.hidden=!message; }
  function open(title,html,wide=false) {
    previewRequest++; clearTimeout(previewTimer);
    $('dir-dialog-title').textContent=title; $('dir-modal').innerHTML=html; $('dir-dialog').classList.toggle('wide',wide); modalError('');
    if (!$('dir-dialog').open) $('dir-dialog').showModal();
    const first=$('dir-modal').querySelector('input:not([type=hidden]),select,textarea,button'); first?.focus();
  }
  function close() { previewRequest++; clearTimeout(previewTimer); $('dir-dialog').close(); }
  async function request(action, values={}, file=null) {
    const form=new FormData(); form.set('dir_accion',action); form.set('csrf',data.csrf); form.set('datos',JSON.stringify({meses:data.meses,...values}));
    if(file) form.set('lista',file);
    const response=await fetch('index.php?view=contactos&directorio_api=1',{method:'POST',body:form,credentials:'same-origin'});
    if (!response.ok) { const result=await response.json().catch(()=>({error:'No se pudo completar la solicitud.'})); throw new Error(result.error); }
    if(response.headers.get('content-type')?.includes('application/json')) {
      const result=await response.json(); if(!result.ok) throw new Error(result.error); return result;
    }
    if(response.url.includes('/login/')) throw new Error('La sesión expiró. Inicia sesión nuevamente.');
    return response;
  }
  async function mutate(action,values={},file=null) {
    if(busy) return; busy=true;
    const controls=[...root.querySelectorAll('button:not(:disabled)')]; controls.forEach(b=>b.disabled=true);
    try {
      const result=await request(action,values,file); data=result.datos; selection.clear(); close(); render(); notify(result.mensaje); return result;
    } catch(error) { if($('dir-dialog').open) modalError(error.message); else notify(error.message,true); }
    finally { busy=false; controls.forEach(b=>{ if(b.isConnected) b.disabled=false; }); }
  }
  async function download(action,values,filename) {
    const response=await request(action,values); const blob=await response.blob();
    const url=URL.createObjectURL(blob), a=document.createElement('a'); a.href=url; a.download=filename; document.body.appendChild(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(url),1000);
  }
  function renderFilters() {
    const count=(field,value)=>data.contactos.filter(c=>c[field]===value).length;
    $('dir-filtros').innerHTML=`<input class="dir-input" name="q" type="search" placeholder="Buscar nombre, cargo, empresa, correo u origen..." aria-label="Buscar contactos" value="${esc(filters.q)}">
      <select class="dir-input" name="area" aria-label="Área">${option('','Todas las áreas',filters.area)}${data.areas.map(v=>option(v,`${v} (${count('area',v)})`,filters.area)).join('')}${option('__sin',`Sin clasificar (${data.contactos.filter(c=>!c.area).length})`,filters.area)}</select>
      <select class="dir-input" name="nivel" aria-label="Nivel">${option('','Todos los niveles',filters.nivel)}${data.niveles.map(v=>option(v,`${v} (${count('nivel',v)})`,filters.nivel)).join('')}${option('__sin','Sin nivel',filters.nivel)}</select>
      <select class="dir-input" name="comite" aria-label="Comité">${option('todos','Todos los comités',filters.comite)}${data.comites.filter(c=>Number(c.activo)||c.id===filters.comite).map(c=>option(c.id,c.nombre,filters.comite)).join('')}</select>
      <select class="dir-input" name="empresa" aria-label="Empresa">${options([...new Set(data.contactos.map(c=>c.empresa).filter(Boolean))].sort(),filters.empresa,'Todas las empresas')}</select>
      <select class="dir-input" name="origen" aria-label="Origen">${option('','Todos los orígenes',filters.origen)}${Object.entries(data.origenes).map(([v,l])=>option(v,`${l} (${count('origen_tipo',v)})`,filters.origen)).join('')}</select>
      <select class="dir-input" name="estado" aria-label="Estado">${option('Activo','Activos',filters.estado)}${option('Inactivo','Inactivos',filters.estado)}${option('todos','Todos',filters.estado)}</select>
      <select class="dir-input" name="part" aria-label="Participación">${option('','Toda participación',filters.part)}${Object.entries(data.participacion_niveles).map(([v,l])=>option(v,`${l} (${data.contactos.filter(c=>c.participacion.nivel===v).length})`,filters.part)).join('')}</select>
      <select class="dir-input" name="perfil" aria-label="Perfil">${option('','CENGICAÑA e ingenios',filters.perfil)}${Object.entries(data.perfiles).map(([v,pf])=>option(v,`${pf.nombre} (${data.contactos.filter(c=>c.participacion.perfil===v).length})`,filters.perfil)).join('')}</select>
      <button type="button" class="dir-link" data-dir="limpiar">Limpiar filtros</button><span id="dir-count" class="dir-badge"></span>`;
  }
  function renderSegments() {
    let html=`<div class="dir-segment-line"><strong>Segmentos</strong>${data.segmentos.map(s=>`<span class="dir-chip"><button data-dir="segmento" data-id="${s.id}"><b>${esc(s.nombre)}</b> · ${data.contactos.filter(c=>matches(c,{...defaults,...s.filtros})).length}</button><button data-dir="segmento-quitar" data-id="${s.id}" aria-label="Quitar ${esc(s.nombre)}">×</button></span>`).join('')}${button('segmento-nuevo','+ Guardar filtros actuales como segmento')}<button class="dir-link dir-map-toggle" data-dir="participacion">${showPart?'Ocultar':'Ver'} panel de participación</button><button class="dir-link" data-dir="mapa">${showMap?'Ocultar':'Ver'} mapa por área y nivel</button></div>`;
    if(showPart) html+=participationPanel();
    if(showMap) {
      html+=`<div class="dir-map"><table><thead><tr><th>Área</th>${data.niveles.map(n=>`<th>${esc(n)}</th>`).join('')}<th>Total</th></tr></thead><tbody>`;
      data.areas.forEach(a=>{
        const active=data.contactos.filter(c=>c.estado==='Activo'&&c.area===a);
        if(!active.length) return;
        html+=`<tr><th>${esc(a)}</th>${data.niveles.map(n=>{const count=active.filter(c=>c.nivel===n).length; return `<td><button data-dir="mapa-celda" data-area="${esc(a)}" data-nivel="${esc(n)}" ${count?'':'disabled'}>${count}</button></td>`;}).join('')}<td><b>${active.length}</b></td></tr>`;
      });
      html+='</tbody></table><p class="dir-warn">Los contactos sin área o nivel no tienen una clasificación completa en el mapa.</p></div>';
    }
    $('dir-segmentos').innerHTML=html;
  }
  // Panel con la participacion de los contactos filtrados: niveles, asistencia por comite,
  // quienes mas participan, quienes requieren atencion y participacion por empresa.
  function participationPanel() {
    const activos=filtered(), cmap=names();
    const cabecera=`<div class="dir-part-head"><div><h3>Análisis de participación</h3><p class="dir-sub">${esc(filtersText())} · <b>${activos.length}</b> contacto(s). Use los filtros de arriba (comité, empresa, perfil, área...) para acotar el análisis.</p></div>
      <div class="dir-part-acciones"><label class="dir-sub">Periodo <select class="dir-input" name="meses" aria-label="Periodo de análisis">${Object.entries(data.periodos||{}).map(([v,l])=>option(v,l,data.meses)).join('')}</select></label>
        ${button('panel-print','<i class="ti ti-printer"></i> Imprimir',false,!activos.length)}${button('panel-xlsx','<i class="ti ti-file-spreadsheet"></i> Excel',false,!activos.length)}${button('panel-pdf','<i class="ti ti-file-type-pdf"></i> Informe para Dirección',true,!activos.length)}</div></div>`;
    if(!activos.length) return `<div class="dir-part-panel">${cabecera}<p class="dir-sub">No hay contactos con los filtros actuales.</p></div>`;
    const niveles=Object.entries(data.participacion_niveles).map(([v,l])=>[v,l,activos.filter(c=>c.participacion.nivel===v).length]);
    const conComite=activos.filter(c=>c.participacion.convocadas>0);
    const asistGlobal=conComite.length?conComite.reduce((a,c)=>a+c.participacion.asistidas,0)/conComite.reduce((a,c)=>a+c.participacion.convocadas,0)*100:null;
    const porComite={};
    activos.forEach(c=>c.participacion.comites.forEach(g=>{ if(!g.convocadas)return; const k=g.id; porComite[k]??={nombre:g.nombre||cmap[k]||k,conv:0,asis:0,miembros:0,ausentes:0}; porComite[k].conv+=g.convocadas; porComite[k].asis+=g.asistidas; porComite[k].miembros++; if(!g.asistidas)porComite[k].ausentes++; }));
    const comites=Object.values(porComite).map(g=>({...g,pct:g.asis/g.conv*100})).sort((a,b)=>b.pct-a.pct);
    const top=[...activos].filter(c=>c.participacion.nivel!=='sin_comite').sort((a,b)=>b.participacion.indice-a.participacion.indice).slice(0,6);
    const atencion=activos.filter(c=>c.participacion.alertas.some(a=>a[0]==='mejorar')).sort((a,b)=>a.participacion.indice-b.participacion.indice).slice(0,8);
    const porEmpresa={};
    // Empresas escritas distinto (mayusculas, tildes) se agrupan con la primera grafia, como en el informe.
    activos.forEach(c=>{ const k=norm(c.empresa)||'sin empresa'; const g=porEmpresa[k]??={nombre:c.empresa||'Sin empresa',empresa:c.empresa||'',n:0,indices:[]}; g.n++; if(c.participacion.nivel!=='sin_comite')g.indices.push(c.participacion.indice); });
    const empresas=Object.values(porEmpresa).map(g=>[g.nombre,g.n,g.indices.length?avg(g.indices):null,g.empresa]).sort((a,b)=>(b[2]??-1)-(a[2]??-1)).slice(0,10);
    const medibles=activos.filter(c=>c.participacion.nivel!=='sin_comite');
    const total=activos.length;
    return `<div class="dir-part-panel">${cabecera}
      <div class="dir-part-kpis">
        <div><b>${medibles.length?Math.round(avg(medibles.map(c=>c.participacion.indice))):'—'}</b><span>Índice promedio (0-100) · ${medibles.length} medibles</span></div>
        <div><b>${asistGlobal===null?'—':Math.round(asistGlobal)+'%'}</b><span>Asistencia a comités (obligatoria)</span></div>
        <div><b>${activos.filter(c=>c.participacion.eventos>0).length}</b><span>Asistieron a eventos (opcional)</span></div>
        <div><b>${activos.filter(c=>['muy_activo','activo'].includes(c.participacion.nivel)).length}</b><span>Contactos activos o muy activos</span></div>
        <div><b>${atencion.length?activos.filter(c=>c.participacion.alertas.some(a=>a[0]==='mejorar')).length:0}</b><span>Requieren atención</span></div>
      </div>
      <div class="dir-part-grid">
        <section><h4>Nivel de participación</h4><div class="dir-part-stack">${niveles.filter(n=>n[2]).map(([v,l,n])=>`<span class="dir-part-seg ${v}" style="flex:${n}" title="${esc(l)}: ${n}"></span>`).join('')}</div>
          <ul class="dir-part-list niveles">${niveles.map(([v,l,n])=>`<li><button class="dir-link" data-dir="part-nivel" data-nivel="${v}" ${n?'':'disabled'}><span class="dir-part-dot ${v}"></span>${esc(l)}</button><b>${n}</b><small>${Math.round(n/total*100)}%</small></li>`).join('')}</ul></section>
        <section><h4>Asistencia por comité</h4>${comites.length?`<ul class="dir-part-list">${comites.map(g=>`<li title="${g.asis} asistencias de ${g.conv} posibles · ${g.miembros} integrantes"><span class="dir-part-nombre">${esc(g.nombre)}</span>${meter(g.pct)}<b>${Math.round(g.pct)}%</b>${g.ausentes?`<small class="dir-warn">${g.ausentes} sin asistir</small>`:'<small></small>'}</li>`).join('')}</ul>`:'<p class="dir-sub">Aún no hay reuniones realizadas con asistencia registrada.</p>'}</section>
        <section><h4>Quienes más participan</h4><ol class="dir-part-rank">${top.map(c=>`<li><button class="dir-link" data-dir="ficha" data-id="${c.id}">${esc(c.nombre)}</button><small>${esc(c.empresa||'')}</small>${partBadge(c.participacion)}</li>`).join('')||'<li class="dir-sub">Sin datos</li>'}</ol></section>
        <section><h4>Requieren atención</h4>${atencion.length?`<ul class="dir-part-alertas">${atencion.map(c=>`<li><button class="dir-link" data-dir="ficha" data-id="${c.id}">${esc(c.nombre)}</button><small>${esc(c.participacion.alertas.find(a=>a[0]==='mejorar')[1])}</small></li>`).join('')}</ul>`:'<p class="dir-sub">Nadie con alertas: todos participan con regularidad.</p>'}</section>
        <section><h4>Por perfil</h4><ul class="dir-part-list">${Object.entries(data.perfiles).map(([k,pf])=>{const cs=activos.filter(c=>c.participacion.perfil===k&&c.participacion.nivel!=='sin_comite'); const v=avg(cs.map(c=>c.participacion.indice)); return `<li><span class="dir-part-nombre">${esc(pf.nombre)}</span>${meter(v)}<b>${cs.length?Math.round(v):'—'}</b><small>${activos.filter(c=>c.participacion.perfil===k).length} contacto(s)</small></li>`;}).join('')}</ul><p class="dir-sub">Índice promedio por grupo; todos se miden con el mismo criterio.</p></section>
        <section class="dir-part-ancho"><h4>Participación por empresa / ingenio</h4><ul class="dir-part-list">${empresas.map(([k,n,v,emp])=>`<li><button class="dir-link dir-part-nombre" data-dir="part-empresa" data-empresa="${esc(emp)}">${esc(k)}</button>${meter(v??0)}<b>${v===null?'—':Math.round(v)}</b><small>${n} contacto(s)</small></li>`).join('')}</ul></section>
      </div>
      <p class="dir-sub">Índice (0-100) = % de asistencia a las reuniones realizadas de sus comités (obligatoria) + bono por asistir a los eventos realizados (opcional, hasta +${data.bono_eventos}; no asistir no resta). Niveles: muy activo ≥ ${data.umbrales.muy_activo}, activo ≥ ${data.umbrales.activo}, ocasional ≥ ${data.umbrales.ocasional}. ${esc(periodo())} o desde que ingresó al directorio.</p>
    </div>`;
  }
  function renderResults() {
    const rows=filtered(), cmap=names();
    $('dir-toolbar').innerHTML=`${data.gestionar?button('importar','<i class="ti ti-upload"></i> Cargar base'):''}<a class="dir-btn" href="index.php?view=contactos&accion=plantilla"><i class="ti ti-download"></i> Plantilla</a>${button('exportar',`Exportar (${rows.length})`,false,!rows.length)}${button('compartir',selection.size?`Compartir selección (${selection.size})`:`Compartir lista (${rows.length})`,false,!rows.length)}${button('reporte-part','<i class="ti ti-report-analytics"></i> Reporte de participación',false,!data.contactos.length)}${data.gestionar?button('nuevo','+ Nuevo contacto',true):''}`;
    $('dir-count').textContent=`${rows.length} contacto(s)`;
    $('dir-rows').innerHTML=rows.map(c=>`<tr class="${selection.has(c.id)?'selected':''}"><td><input type="checkbox" data-select="${c.id}" aria-label="Seleccionar ${esc(c.nombre)}" ${selection.has(c.id)?'checked':''}></td>
      <td class="dir-col-contacto"><button class="dir-name" data-dir="ficha" data-id="${c.id}">${esc(c.nombre)}</button><span class="dir-sub">${esc(c.cargo||'—')}${c.empresa?' · <b>'+esc(c.empresa)+'</b>':''}</span><span class="dir-muted dir-sub dir-clamp" title="${esc(c.email)}">${esc(c.email)}${c.telefono?' · '+esc(c.telefono):''}</span></td>
      <td data-label="Área">${c.area?badge(c.area,'green'):'<span class="dir-warn">Sin área</span>'}<span class="dir-sub ${!c.nivel?'dir-warn':''}">${esc(c.nivel||'sin nivel')} · <span title="${esc(c.origen_detalle)}">${esc(data.origenes[c.origen_tipo]||c.origen_tipo)}</span></span></td>
      <td data-label="Comités" title="${esc(c.comites.map(id=>cmap[id]||id).join(', '))}"><div class="dir-badges">${c.comites.slice(0,2).map(id=>badge(cmap[id]||id)).join('')}${c.comites.length>2?badge('+'+(c.comites.length-2)):''}${c.comites.length?'':'<span class="dir-muted">Sin comité</span>'}</div></td>
      <td data-label="Participación">${partBadge(c.participacion)}<span class="dir-sub">${c.participacion.pct_asistencia!==null?`Asistencia ${c.participacion.pct_asistencia}% (${c.participacion.asistidas}/${c.participacion.convocadas})`:(c.comites.length?'Sin reuniones realizadas':'')}${c.participacion.alertas.some(a=>a[0]==='mejorar')?' <i class="ti ti-alert-triangle dir-warn" title="'+esc(c.participacion.alertas.filter(a=>a[0]==='mejorar').map(a=>a[1]).join(' · '))+'"></i>':''}</span><span class="dir-muted dir-sub dir-clamp" title="${esc(c.ultima?.titulo||'')}">${c.ultima?'Última: '+date(c.ultima.fecha)+' · '+esc(c.ultima.titulo):'Sin interacciones registradas'}</span></td>
      <td data-label="Estado">${data.gestionar?`<button class="dir-icon" data-dir="estado" data-id="${c.id}" title="Cambiar estado">${badge(c.estado,c.estado==='Activo'?'green':'')}</button>`:badge(c.estado,c.estado==='Activo'?'green':'')}</td>
      <td><div class="dir-actions">${icon('ficha',c.id,'timeline','Ficha y trazabilidad')}${data.gestionar?icon('editar',c.id,'pencil','Editar')+icon('eliminar',c.id,'trash','Eliminar','danger'):''}</div></td></tr>`).join('');
    $('dir-empty').hidden=!!rows.length;
    $('dir-all').checked=rows.length>0&&rows.every(c=>selection.has(c.id));
    $('dir-all').indeterminate=selection.size>0&&!$('dir-all').checked;
    const missing=data.contactos.filter(c=>!c.area||!c.nivel).length;
    $('dir-clasificar').innerHTML=missing?`<div class="dir-notice"><span>${missing} contacto(s) no tienen área o nivel.</span>${data.gestionar?button('clasificar','Clasificar automáticamente por cargo'):''}<button class="dir-link" data-dir="sin-clasificar">Ver sin clasificar</button></div>`:'';
    $('dir-masivo').innerHTML=selection.size?`<div class="dir-bulk"><b>${selection.size} seleccionado(s)</b>${data.gestionar?`<label>Asignar área: <select class="dir-input" data-bulk="area">${options(data.areas,'','Elegir…')}</select></label><label>Nivel: <select class="dir-input" data-bulk="nivel">${options(data.niveles,'','Elegir…')}</select></label>`:''}<button class="dir-link" data-dir="deseleccionar">Quitar selección</button></div>`:'';
    const url=new URL(location.href); Object.entries(filters).forEach(([k,v])=>v===defaults[k]?url.searchParams.delete(k):url.searchParams.set(k,v)); history.replaceState(null,'',url);
  }
  function render() { renderFilters(); renderSegments(); renderResults(); }
  function formValues(form) { return Object.fromEntries(new FormData(form)); }
  function edit(c={}) {
    c={nombre:'',cargo:'',empresa:'',telefono:'',email:'',area:'',nivel:'',estado:'Activo',comites:filters.comite!=='todos'?[filters.comite]:[],...c};
    open(c.id?'Editar contacto':'Nuevo contacto',`<form id="dir-edit"><input type="hidden" name="id" value="${c.id||0}"><div class="dir-fields">
      ${[['nombre','Nombre completo'],['cargo','Cargo'],['empresa','Empresa / ingenio'],['telefono','Teléfono'],['email','Correo']].map(([k,l])=>`<label>${l}<input class="dir-input" name="${k}" type="${k==='email'?'email':'text'}" value="${esc(c[k])}" maxlength="${k==='telefono'?40:180}" ${k==='nombre'?'required':''}></label>`).join('')}
      <label>Área<select name="area" class="dir-input">${options(data.areas,c.area,'Sin clasificar')}</select><small id="dir-sugerencia" class="dir-sub"></small></label>
      <label>Nivel<select name="nivel" class="dir-input">${options(data.niveles,c.nivel,'Sin nivel')}</select></label>
      <label>Estado<select name="estado" class="dir-input">${['Activo','Inactivo'].map(v=>option(v,v,c.estado)).join('')}</select></label></div>
      <p style="margin-top:16px">Comités en los que participa</p><div class="dir-checks">${data.comites.filter(g=>Number(g.activo)||c.comites.includes(g.id)).map(g=>`<label><input type="checkbox" name="comites" value="${esc(g.id)}" ${c.comites.includes(g.id)?'checked':''}><span>${esc(g.nombre)}</span>${!Number(g.activo)?badge('comité inactivo'):''}${Number(g.lider_id)===c.id?badge('Líder','green'):''}</label>`).join('')}</div>
      <div class="dir-form-actions">${button('cerrar','Cancelar')}<button class="dir-btn primary" type="submit">Guardar</button></div></form>`);
    // Al guardar, el servidor deduce área/nivel del cargo si se dejan vacíos.
    $('dir-sugerencia').textContent='Si se deja vacío, se deduce del cargo cuando sea posible.';
  }
  function profile(c) {
    const types={reunion:'Reuniones',evento:'Eventos',correo:'Correos recibidos',memoria:'Memoria de resultados',paso:'Próximos pasos'};
    const glyph={origen:'●',reunion:'📅',evento:'🎤',correo:'✉',memoria:'▤',paso:'✓'};
    open('Ficha del contacto',`<div class="dir-profile"><div><h3>${esc(c.nombre)}</h3><p>${esc(c.cargo||'—')} · ${esc(c.empresa||'—')}</p><p>${badge(c.area||'Sin área','green')} ${esc(c.nivel||'sin nivel')}</p><p>${esc(c.email||'sin correo')} ${c.telefono?' · '+esc(c.telefono):''}</p><p>${c.comites.map(id=>badge(names()[id]||id)).join(' ')}</p></div><div class="dir-origin"><strong>Origen</strong><p>${badge(data.origenes[c.origen_tipo]||'Base existente','blue')}</p><p>${esc(c.origen_detalle||'Registro previo a la trazabilidad')}</p><small>Alta: ${date(c.origen_fecha||c.creado_en)} ${c.origen_por?' · '+esc(c.origen_por):''}</small></div></div>
      ${participationSection(c)}
      <div class="dir-stats">${Object.entries(types).map(([t,l])=>`<div><b>${c.historial.filter(h=>h.tipo===t).length}</b><span>${l}</span></div>`).join('')}</div><p class="dir-sub">${c.ultima?'Última interacción: '+date(c.ultima.fecha)+' · '+esc(c.ultima.titulo):'Sin interacciones registradas después de su ingreso.'}</p>
      <div class="dir-hist-head"><h3>Historial</h3><div class="dir-hist-filtros" role="group" aria-label="Filtrar historial"><button class="is-active" data-dir="hist-tipo" data-tipo="">Todo</button>${Object.entries(types).filter(([t])=>c.historial.some(h=>h.tipo===t)).map(([t,l])=>`<button data-dir="hist-tipo" data-tipo="${t}">${esc(l)}</button>`).join('')}</div></div>
      <div class="dir-timeline">${c.historial.map(h=>`<article data-tipo="${esc(h.tipo)}"><span aria-hidden="true">${glyph[h.tipo]||'●'}</span><div><time>${date(h.fecha)}</time><b>${esc(h.titulo)}</b><p class="dir-sub">${esc(h.detalle)}</p></div></article>`).join('')}</div>${data.gestionar?`<div class="dir-form-actions">${button('editar-ficha','Editar contacto')}</div>`:''}`,true);
    $('dir-modal').dataset.contactId=c.id;
  }
  // Resumen de participacion dentro de la ficha: indice y su composicion, roles,
  // asistencia por comite (ultimas reuniones), actividad de 12 meses, pasos y alertas.
  function participationSection(c) {
    const p=c.participacion, mes=m=>['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'][Number(m.slice(5,7))-1];
    const maxMes=Math.max(1,...Object.values(p.por_mes));
    return `<section class="dir-part-ficha">
      <div class="dir-part-indice ${p.nivel}">
        <div class="dir-part-anillo" style="--v:${p.indice}"><b>${p.nivel==='sin_comite'?'—':p.indice}</b><small>de 100</small></div>
        <div><span class="dir-sub">Participación (${esc(periodo().toLowerCase())}) · perfil <b>${esc(p.perfil_nombre)}</b></span>${partBadge(p)}
          <ul class="dir-part-comp">${Object.entries(p.componentes).map(([k,[v,max,aplica,tipo]])=>aplica?`<li title="${tipo}"><span>${esc(k)}</span>${meter(v/max*100)}<small>${tipo==='opcional'?'+'+v:v+'%'}</small></li>`:`<li class="no-aplica" title="No hubo ${tipo==='opcional'?'eventos':'reuniones de sus comités'} desde su ingreso"><span>${esc(k)}</span><em>${tipo==='opcional'?'sin eventos':'sin reuniones'}</em><small>—</small></li>`).join('')}</ul>
          <small class="dir-sub">Comités: ${p.convocadas?`${p.asistidas} de ${p.convocadas} reuniones`:'sin reuniones realizadas'} · Eventos: ${p.eventos_posibles||p.eventos?`${p.eventos} de ${Math.max(p.eventos_posibles,p.eventos)} realizados`:'no hubo eventos'}.</small></div>
      </div>
      <div class="dir-part-detalle">
        ${p.roles.length?`<div class="dir-part-roles">${p.roles.map(r=>badge(r,'green')).join(' ')}</div>`:''}
        <h4>Asistencia a reuniones de sus comités</h4>
        ${p.comites.length?`<ul class="dir-part-asis">${p.comites.map(g=>`<li><span class="dir-part-nombre">${esc(g.nombre||names()[g.id]||g.id)}</span>${g.convocadas?`<span class="dir-part-puntos" aria-label="Últimas reuniones">${g.ultimas.map(m=>`<i class="${m.asistio?'si':'no'}" title="${date(m.fecha)}: ${m.asistio?'asistió':'no asistió'}"></i>`).join('')}</span><b>${g.asistidas}/${g.convocadas}</b><small>${Math.round(g.pct)}%</small>`:'<small class="dir-muted">Sin reuniones realizadas desde su ingreso</small>'}</li>`).join('')}</ul>`:'<p class="dir-sub">No integra ningún comité.</p>'}
        <div class="dir-part-cifras">
          <span><b>${p.eventos}</b>eventos</span><span><b>${p.autor+p.revisor}</b>artículos (autor/revisor)</span>
          <span><b>${p.pasos_completados}/${p.pasos}</b>pasos completados</span>${p.pasos_vencidos?`<span class="dir-warn"><b>${p.pasos_vencidos}</b>pasos vencidos</span>`:''}
          <span><b>${p.dias===null?'—':p.dias}</b>días desde su última participación</span>
        </div>
        <h4>Actividad por mes</h4>
        <div class="dir-part-meses">${Object.entries(p.por_mes).map(([m,n])=>`<span title="${mes(m)} ${m.slice(0,4)}: ${n} participación(es)"><i style="height:${n?Math.max(12,n/maxMes*100):0}%"></i><small>${mes(m)}</small></span>`).join('')}</div>
      </div>
      ${p.alertas.length?`<ul class="dir-part-alertas-ficha">${p.alertas.map(([tono,txt])=>`<li class="${tono}">${esc(txt)}</li>`).join('')}</ul>`:''}
    </section>`;
  }
  function importForm() {
    open('Cargar base de contactos',`<form id="dir-import"><p>Indique de dónde proviene la base; queda registrado en cada contacto para su trazabilidad y segmentación.</p><div class="dir-fields" style="margin-top:16px">
      <label>Origen de la base<select class="dir-input" name="origen_tipo">${['carga','comite','curso','evento','seminario','servicio'].map(v=>option(v,data.origenes[v], 'curso')).join('')}</select></label><label>Nombre del curso, evento, seminario o servicio<input class="dir-input" name="origen_detalle" maxlength="200" required></label>
      <label>Área por defecto<select class="dir-input" name="area">${options(data.areas,'','Deducir del cargo')}</select></label><label>Agregar al grupo (opcional)<select class="dir-input" name="comite">${option('','(ninguno)','')}${data.comites.filter(g=>Number(g.activo)).map(g=>option(g.id,g.nombre,filters.comite)).join('')}</select></label>
      <label class="dir-wide">Archivo Excel o CSV<input class="dir-input" type="file" name="lista" accept=".csv,.xlsx,.xls" required></label></div><p class="dir-sub">Hasta 2000 contactos y 5 MB. Columnas: Nombre completo, Cargo, Empresa / ingenio, Área, Nivel, Teléfono, Correo, Comités, Estado. Los correos existentes se omiten y se vinculan a los grupos indicados.</p><div class="dir-form-actions">${button('cerrar','Cancelar')}<button class="dir-btn primary" type="submit">Cargar base</button></div></form>`);
    $('dir-import').elements.origen_tipo.addEventListener('change',e=>{ $('dir-import').elements.origen_detalle.required=!['carga','comite'].includes(e.target.value); });
  }
  const columns={nombre:'Nombre',cargo:'Cargo',empresa:'Empresa / ingenio',area:'Área',nivel:'Nivel',email:'Correo',telefono:'Teléfono',comites:'Comités',origen:'Origen'};
  function shareValues() {
    const form=$('dir-share-form');
    if(form) { const f=formValues(form); share={...share,...f,cols:['nombre',...new FormData(form).getAll('cols').filter(c=>c!=='nombre')]}; }
    return {...share};
  }
  async function preview() {
    const ticket=++previewRequest;
    try { const response=await request('preview',shareValues()); const html=await response.text(); if(ticket===previewRequest && $('dir-preview')) $('dir-preview').srcdoc=html; }
    catch(error){ if(ticket===previewRequest) modalError(error.message); }
  }
  function shareForm() {
    open('Compartir lista de contactos',`<div class="dir-share"><aside><form id="dir-share-form"><div class="dir-notice">${share.ids.length} contacto(s)</div><label class="dir-label">Título<input class="dir-input" name="titulo" maxlength="180" value="${esc(share.titulo)}"></label><label class="dir-label">Descripción (opcional)<textarea class="dir-input" name="descripcion" rows="3" maxlength="3000">${esc(share.descripcion)}</textarea></label><div class="dir-label">Columnas</div><div class="dir-share-cols">${Object.entries(columns).map(([k,l])=>`<label><input type="checkbox" name="cols" value="${k}" ${share.cols.includes(k)?'checked':''} ${k==='nombre'?'disabled':''}>${l}</label>`).join('')}</div><label class="dir-label">Agrupar por<select class="dir-input" name="agrupar">${option('','Sin agrupar',share.agrupar)}${['area','nivel','empresa','comites'].map(k=>option(k,columns[k],share.agrupar)).join('')}</select></label><label class="dir-label">Color de la marca<select class="dir-input" name="color">${[['verde','Verde principal'],['verde2','Verde secundario'],['amarillo','Amarillo'],['naranja','Naranja'],['gris','Gris']].map(([c,t])=>option(c,t,share.color)).join('')}</select></label><label class="dir-label">Agregar logo<input class="dir-input" id="dir-share-logo" type="file" accept="image/png,image/jpeg,image/webp"></label></form></aside><div><iframe id="dir-preview" title="Vista previa de la lista" sandbox="allow-same-origin allow-modals"></iframe><div id="dir-copy-result"></div><div class="dir-share-actions">${button('copiar','Copiar correos')}${button('share-excel','Excel')}${button('share-html','Descargar')}${button('share-pdf','Imprimir / PDF')}${data.enviar?button('correo','Enviar por correo',true):''}</div></div></div>`,true);
    $('dir-share-form').addEventListener('input',()=>{clearTimeout(previewTimer); previewTimer=setTimeout(preview,250);});
    $('dir-share-logo').addEventListener('change',event=>{
      const file=event.target.files[0]; if(!file)return;
      if(file.size>1000000||!['image/png','image/jpeg','image/webp'].includes(file.type)){modalError('Selecciona un logo PNG, JPG o WebP de hasta 1 MB.');return;}
      const reader=new FileReader(); reader.onload=()=>{share.logo=reader.result;preview();};reader.readAsDataURL(file);
    });
    preview();
  }
  // Dialogo del reporte de participacion: alcance (filtrados o todos los activos), detalle y formato.
  function participationReportForm() {
    const filtrados=filtered().filter(c=>!selection.size||selection.has(c.id)), activos=data.contactos.filter(c=>c.estado==='Activo');
    open('Reporte de participación',`<form id="dir-part-form">
      <div class="dir-label">Tipo de informe</div>
      <div class="dir-checks"><label><input type="radio" name="tipo" value="ejecutivo" checked><span><b>Ejecutivo para Dirección</b> — indicadores, conclusiones, acciones sugeridas, comités, empresas y quienes requieren seguimiento</span></label><label><input type="radio" name="tipo" value="completo"><span><b>Completo</b> — además por área, ranking y detalle de cada contacto</span></label></div>
      <label class="dir-label">Título<input class="dir-input" name="titulo" maxlength="180" value="Informe de participación en comités"></label>
      <label class="dir-label">Comentario inicial (opcional)<textarea class="dir-input" name="descripcion" rows="2" maxlength="3000" placeholder="Ej. Participación de los comités durante la zafra 2025-2026"></textarea></label>
      <div class="dir-label">Contactos incluidos</div>
      <div class="dir-checks"><label><input type="radio" name="alcance" value="filtrados" checked><span>${selection.size?'Selección actual':'Filtros actuales'} (${filtrados.length})</span></label><label><input type="radio" name="alcance" value="activos"><span>Todos los activos (${activos.length})</span></label></div>
      <label class="dir-check-line"><input type="checkbox" name="detalle" value="1" checked> Incluir el detalle de cada contacto (informe completo)</label>
      <p class="dir-sub">Periodo: <b>${esc(periodo())}</b> (se cambia en el panel de participación) · ${esc(filtersText())}.</p>
      <div class="dir-notice dir-part-metodo"><b>Cómo se mide la participación</b><table><tbody>
        <tr><td>Asistencia a comités</td><td>Obligatoria</td><td>% de las reuniones realizadas de sus comités. Base del índice (0-100).</td></tr>
        <tr><td>Asistencia a eventos</td><td>Opcional</td><td>Suma hasta +${data.bono_eventos} puntos; no asistir no resta.</td></tr></tbody></table>
        <small>El reporte compara también CENGICAÑA frente a ingenios y externos, con el mismo criterio para todos.</small></div>
      <div class="dir-form-actions">${button('cerrar','Cancelar')}${button('part-html','<i class="ti ti-printer"></i> Ver e imprimir')}${button('part-xlsx','<i class="ti ti-file-spreadsheet"></i> Excel')}${button('part-pdf','<i class="ti ti-file-type-pdf"></i> PDF',true)}</div></form>`,true);
  }
  function participationValues() {
    const f=new FormData($('dir-part-form'));
    const todos=f.get('alcance')==='activos';
    const lista=todos?data.contactos.filter(c=>c.estado==='Activo'):filtered().filter(c=>!selection.size||selection.has(c.id));
    const texto=todos?'Todos los contactos activos':(selection.size?`Selección de ${lista.length} contacto(s) · `:'')+filtersText();
    return {ids:lista.map(c=>c.id),titulo:f.get('titulo'),descripcion:f.get('descripcion'),detalle:f.get('detalle')?1:0,tipo:f.get('tipo'),filtros_texto:texto};
  }
  // Informe ejecutivo directo desde el panel, con los contactos filtrados.
  const panelValues = () => ({ids:filtered().map(c=>c.id),titulo:'Informe de participación en comités',descripcion:'',detalle:0,tipo:'ejecutivo',filtros_texto:filtersText()});
  async function printReport(values) {
    const ventana=window.open('','_blank');
    const response=await request('part_html',values); const html=await response.text();
    if(ventana){ventana.document.open();ventana.document.write(html);ventana.document.close();} else notify('Permita las ventanas emergentes para ver el reporte.',true);
  }
  function emailForm() {
    shareValues();
    open('Enviar lista por correo',`<form id="dir-email"><div class="dir-fields"><label class="dir-wide">Destinatarios<input name="para" class="dir-input" required placeholder="correo@empresa.com; otro@empresa.com" list="dir-email-options"></label><datalist id="dir-email-options">${data.contactos.filter(c=>c.email).map(c=>`<option value="${esc(c.email)}">${esc(c.nombre)}</option>`).join('')}</datalist><label class="dir-wide">Asunto<input name="asunto" class="dir-input" maxlength="220" required value="${esc('CENGICAÑA | '+share.titulo)}"></label><label class="dir-wide">Mensaje<textarea name="cuerpo" class="dir-input" rows="6" maxlength="15000">${esc(`Estimado/a,\n\nCompartimos la lista “${share.titulo}” (${share.ids.length} contactos).\n\nSaludos cordiales.`)}</textarea></label></div><p class="dir-sub">Adjunto: directorio_contactos.pdf</p><div class="dir-form-actions">${button('volver-compartir','Volver')}<button class="dir-btn primary" type="submit">Enviar correo</button></div></form>`,true);
  }
  root.addEventListener('click',async event=>{
    const target=event.target.closest('[data-dir]'); if(!target||target.disabled||busy)return;
    event.preventDefault();
    const action=target.dataset.dir, id=Number(target.dataset.id), contact=data.contactos.find(c=>c.id===id);
    try {
      if(action==='cerrar') close();
      else if(action==='nuevo') edit();
      else if(action==='editar'&&contact) edit(contact);
      else if(action==='editar-ficha') edit(data.contactos.find(c=>c.id===Number($('dir-modal').dataset.contactId)));
      else if(action==='ficha'&&contact) profile(contact);
      else if(action==='estado') await mutate('estado',{id});
      else if(action==='eliminar'&&contact) { if(confirm(`¿Eliminar a ${contact.nombre} del directorio? Si solo dejó de participar, es mejor marcarlo como inactivo.`)) await mutate('eliminar',{id}); }
      else if(action==='clasificar') await mutate('clasificar');
      else if(action==='deseleccionar') {selection.clear();renderResults();}
      else if(action==='sin-clasificar') {filters.area='__sin';selection.clear();render();}
      else if(action==='limpiar') {filters={...defaults};selection.clear();render();}
      else if(action==='mapa') {showMap=!showMap;renderSegments();}
      else if(action==='participacion') {showPart=!showPart;renderSegments();}
      else if(action==='part-nivel') {filters={...filters,part:target.dataset.nivel};selection.clear();render();}
      else if(action==='part-empresa') {filters={...filters,empresa:target.dataset.empresa};selection.clear();render();}
      else if(action==='hist-tipo') {
        const tipo=target.dataset.tipo;
        $('dir-modal').querySelectorAll('.dir-hist-filtros button').forEach(b=>b.classList.toggle('is-active',b===target));
        $('dir-modal').querySelectorAll('.dir-timeline article').forEach(a=>{a.hidden=!!tipo&&a.dataset.tipo!==tipo;});
      }
      else if(action==='mapa-celda') {filters={...filters,area:target.dataset.area,nivel:target.dataset.nivel,estado:'Activo'};selection.clear();render();}
      else if(action==='segmento') {const s=data.segmentos.find(s=>Number(s.id)===id);if(s){filters={...defaults,...s.filtros};selection.clear();render();}}
      else if(action==='segmento-quitar') await mutate('segmento_eliminar',{id});
      else if(action==='segmento-nuevo') open('Guardar segmento',`<form id="dir-segment-form"><p>${filtered().length} contactos con los filtros actuales.</p><label class="dir-label">Nombre del segmento<input class="dir-input" name="nombre" maxlength="100" required></label><div class="dir-form-actions">${button('cerrar','Cancelar')}<button class="dir-btn primary">Guardar segmento</button></div></form>`);
      else if(action==='importar') importForm();
      else if(action==='exportar') await download('exportar',{ids:filtered().map(c=>c.id)},'directorio_contactos.xlsx');
      else if(action==='compartir') {share={ids:filtered().filter(c=>!selection.size||selection.has(c.id)).map(c=>c.id),titulo:'Directorio de contactos',descripcion:'',cols:['nombre','cargo','empresa','area','email','telefono'],agrupar:'',color:'verde',logo:''};shareForm();}
      else if(action==='share-excel') await download('exportar',shareValues(),'directorio_contactos.xlsx');
      else if(action==='share-html') await download('html',shareValues(),'directorio_contactos.html');
      else if(action==='share-pdf') await download('pdf',shareValues(),'directorio_contactos.pdf');
      else if(action==='copiar') {
        const emails=[...new Set(data.contactos.filter(c=>share.ids.includes(c.id)&&c.email).map(c=>c.email))].join('; ');
        try {await navigator.clipboard.writeText(emails);$('dir-copy-result').textContent='Correos copiados, listos para pegar en Outlook.';}
        catch { $('dir-copy-result').innerHTML=`<label>Seleccione y copie los correos:<textarea class="dir-input" readonly>${esc(emails)}</textarea></label>`; }
      }
      else if(action==='correo') emailForm();
      else if(action==='reporte-part') participationReportForm();
      else if(action==='part-pdf') await download('part_pdf',participationValues(),'participacion_contactos.pdf');
      else if(action==='part-xlsx') await download('part_xlsx',participationValues(),'participacion_contactos.xlsx');
      else if(action==='part-html') await printReport(participationValues());
      else if(action==='panel-pdf') await download('part_pdf',panelValues(),'informe_participacion.pdf');
      else if(action==='panel-xlsx') await download('part_xlsx',panelValues(),'informe_participacion.xlsx');
      else if(action==='panel-print') await printReport(panelValues());
      else if(action==='volver-compartir') shareForm();
    } catch(error) {if($('dir-dialog').open)modalError(error.message);else notify(error.message,true);}
  });
  $('dir-filtros').addEventListener('submit',e=>e.preventDefault());
  $('dir-filtros').addEventListener('input',event=>{if(event.target.name){filters[event.target.name]=event.target.value;selection.clear();renderResults();if(showPart)renderSegments();}});
  root.addEventListener('change',async event=>{
    if(event.target.name==='meses'&&event.target.closest('.dir-part-panel')) {
      // Cambiar el periodo recalcula la participacion en el servidor.
      event.target.disabled=true;
      try { const result=await request('datos',{meses:Number(event.target.value)}); data=result.datos; render(); }
      catch(error) { notify(error.message,true); event.target.disabled=false; }
    }
    else if(event.target.dataset.select){const id=Number(event.target.dataset.select);event.target.checked?selection.add(id):selection.delete(id);renderResults();}
    else if(event.target.id==='dir-all'){selection=new Set(event.target.checked?filtered().map(c=>c.id):[]);renderResults();}
    else if(event.target.dataset.bulk&&event.target.value) mutate('masivo',{ids:[...selection],campo:event.target.dataset.bulk,valor:event.target.value});
  });
  root.addEventListener('submit',event=>{
    const form=event.target;if(!['dir-edit','dir-import','dir-segment-form','dir-email'].includes(form.id))return;event.preventDefault();
    const values=formValues(form);
    if(form.id==='dir-edit') mutate('guardar',{...values,comites:new FormData(form).getAll('comites')});
    if(form.id==='dir-import') mutate('importar',values,form.elements.lista.files[0]);
    if(form.id==='dir-segment-form') mutate('segmento_guardar',{...values,filtros:{...filters}});
    if(form.id==='dir-email') mutate('enviar',{...share,...values});
  });
  render();
  if(params.has('nuevo')&&data.gestionar)edit();
  if(params.has('editar')&&data.gestionar){const c=data.contactos.find(c=>c.id===Number(params.get('editar')));if(c)edit(c);}
})();
