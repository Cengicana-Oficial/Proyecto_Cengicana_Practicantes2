/* Validación técnica — núcleo compartido entre la lista (validacion_tecnica_view.php)
   y el detalle (hoja_revision.php). Usa las fórmulas de hojas_definiciones.js,
   idénticas a Prototipos/laboratorio-workflow-v3.html. */
(function(root){'use strict';
const defs=root.LabHojas;
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fechaTxt=iso=>{const m=String(iso||'').match(/^(\d{4})-(\d{2})-(\d{2})/);return m?m[3]+'/'+m[2]+'/'+m[1]:(iso||'—');};

/* contexto y fila calculada, igual que ctxDe()/filaCalc() del prototipo */
function contexto(cap){
  const h=defs.hojas[cap.hoja],enc={};
  (h.encabezado?h.encabezado.campos:[]).forEach(c=>enc[c.k]=(cap.encabezado||{})[c.k]);
  const curva=h.curva?(cap.curva||[]):null;
  return {enc,ctrl:cap.controles||{},blk:(cap.controles||{}).BLANCO||{},reg:curva?defs.regresion(curva):null,regL:curva?defs.regLineal(curva):null};
}
function filaCalc(cap,f,rowId,ctx){
  const h=defs.hojas[cap.hoja],base={};f=f||{};
  Object.entries(f).forEach(([k,v])=>{const n=defs.num(v);base[k]=(n==null||/[a-zA-Z]/.test(String(v)))?v:n;});
  h.cols.filter(c=>c.auto).forEach(c=>base[c.k]=c.auto(f));
  return Object.assign(base,h.calc(f,ctx||contexto(cap),rowId||'M'));
}
function vistaDe(cap){const h=defs.hojas[cap.hoja];return h.vistas.find(v=>v.analisis.includes(cap.analisis))||h.vistas[0];}
/* valor reportado de la muestra para el análisis del formulario */
function valorPrincipal(cap,m){const p=(vistaDe(cap).principal||{})[cap.analisis];return p&&p.k?m[p.k]:null;}
/* rango esperado del catálogo de análisis (tipo_analisis.limite_min/limite_max) */
function checkRango(rango,valor){
  const min=rango&&rango.min!=null&&rango.min!==''?Number(rango.min):null,max=rango&&rango.max!=null&&rango.max!==''?Number(rango.max):null;
  if(min==null&&max==null)return {tieneLimite:false,fuera:false};
  const fuera=typeof valor==='number'&&isFinite(valor)&&((min!=null&&valor<min)||(max!=null&&valor>max));
  return {tieneLimite:true,fuera,min,max,unidad:rango.unidad||''};
}
function textoRango(k){const f=v=>v==null?'…':String(+v.toFixed(6));return f(k.min)+' – '+f(k.max)+(k.unidad?' '+k.unidad:'');}
/* filas de muestra con su cálculo y chequeo de rango */
function muestrasCalculadas(cap,rango){
  const ctx=contexto(cap);
  return (cap.seleccion||[]).map(id=>{
    const info=(cap.muestras||[]).find(x=>Number(x.id_muestra)===Number(id))||{};
    const m=filaCalc(cap,(cap.filas||{})[id],'M',ctx);
    return {id:Number(id),info,m,chk:checkRango(rango,valorPrincipal(cap,m))};
  });
}

/* ---------- toast ---------- */
let toastEl=null;
function toast(msg,error){
  if(!toastEl){toastEl=document.createElement('div');toastEl.className='vt-toast';toastEl.setAttribute('role','status');document.body.appendChild(toastEl);}
  toastEl.textContent=msg;toastEl.classList.toggle('is-error',!!error);toastEl.classList.add('show');
  clearTimeout(toastEl._t);toastEl._t=setTimeout(()=>toastEl.classList.remove('show'),error?4200:2800);
}

/* ---------- acciones ---------- */
async function resolver(endpoint,payload){
  const res=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
  let data=null;try{data=await res.json();}catch(e){}
  if(!res.ok||!data||!data.ok)throw new Error((data&&data.error)||'No se pudo guardar la decisión, intenta de nuevo.');
  return data.data;
}
let modal=null,modalCtx=null,motivos=[];
/* categorías de rechazo/reanálisis (LAB_MOTIVOS_RECHAZO en PHP); Control de calidad las agrupa */
function setMotivos(lista){motivos=Array.isArray(lista)?lista:[];}
function selectMotivos(id){return '<select id="'+id+'" class="vt-select"><option value="">Seleccione…</option>'+motivos.map(m=>'<option>'+esc(m)+'</option>').join('')+'</select>';}
function abrirRechazo(endpoint,id,referencia,motivo,despues){
  if(!modal){
    modal=document.createElement('div');modal.className='vt-modal';modal.hidden=true;
    modal.innerHTML='<div class="vt-modal-box" role="dialog" aria-modal="true" aria-labelledby="vtRechTit"><h3 id="vtRechTit">Rechazar formulario completo</h3><div class="vt-modal-ref"></div>'
      +'<div class="vt-field"><label for="vtRechCategoria">Categoría del motivo (obligatoria)</label>'+selectMotivos('vtRechCategoria')+'</div>'
      +'<div class="vt-field"><label for="vtRechMotivo">Motivo del rechazo (obligatorio · se envía al analista y queda en el historial)</label><textarea id="vtRechMotivo" placeholder="Ej. Control fuera de lo esperado; repetir la corrida completa."></textarea></div>'
      +'<div class="vt-modal-error"></div><div class="vt-actions" style="justify-content:flex-end"><button type="button" class="cengi-btn cengi-btn-ghost" data-vt="cancelar">Cancelar</button><button type="button" class="cengi-btn vt-btn-red" data-vt="confirmar">Rechazar y enviar a reanálisis</button></div></div>';
    document.body.appendChild(modal);
    modal.addEventListener('click',e=>{if(e.target===modal||e.target.closest('[data-vt="cancelar"]'))modal.hidden=true;});
    document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden)modal.hidden=true;});
    modal.querySelector('[data-vt="confirmar"]').addEventListener('click',async ev=>{
      const btn=ev.currentTarget,txt=modal.querySelector('textarea'),err=modal.querySelector('.vt-modal-error');
      const motivo=txt.value.trim(),cat=modal.querySelector('#vtRechCategoria');
      if(!cat.value){err.textContent='Seleccione la categoría del motivo.';cat.focus();return;}
      if(!motivo){err.textContent='Escribe el motivo del rechazo.';txt.focus();return;}
      btn.disabled=true;err.textContent='';
      try{await resolver(modalCtx.endpoint,{id_formulario:modalCtx.id,accion:'rechazar',comentario:motivo,categoria:cat.value});modal.hidden=true;modalCtx.despues('Formulario #'+modalCtx.id+' rechazado — las muestras vuelven a la bandeja del analista para reanálisis.');}
      catch(e){err.textContent=e.message;}
      finally{btn.disabled=false;}
    });
  }
  modalCtx={endpoint,id,despues};
  modal.querySelector('.vt-modal-ref').textContent=referencia;
  modal.querySelector('textarea').value=motivo||'';
  modal.querySelector('#vtRechCategoria').value='';
  modal.querySelector('.vt-modal-error').textContent='';
  modal.hidden=false;modal.querySelector('textarea').focus();
}

/* ---------- lista: fuera de rango y acciones por línea ---------- */
function iniciarLista(){
  const nodo=document.getElementById('vt-list-data');if(!nodo)return;
  const data=JSON.parse(nodo.textContent);let total=0;
  setMotivos(data.motivos);
  Object.entries(data.formularios||{}).forEach(([id,f])=>{
    if(!f.cap||!defs.hojas[f.cap.hoja])return;
    let n=0;try{n=muestrasCalculadas(f.cap,f.rango).filter(x=>x.chk.fuera).length;}catch(e){console.error('Formulario #'+id,e);}
    total+=n;
    const slot=document.querySelector('[data-fuera-slot="'+id+'"]');
    if(slot&&n)slot.innerHTML='<span class="vt-flag">⚠ '+n+' fuera de rango</span>';
  });
  const kpi=document.getElementById('kpiValFuera');if(kpi)kpi.textContent=total;
  const recargar=msg=>{toast(msg);setTimeout(()=>location.reload(),900);};
  // Menú de acciones por fila: uno abierto a la vez, en posición fija para que la tabla no lo recorte.
  const cerrarMenus=excepto=>document.querySelectorAll('details.vt-menu[open]').forEach(m=>{if(m!==excepto)m.removeAttribute('open');});
  document.addEventListener('toggle',e=>{
    const menu=e.target;if(!menu.matches||!menu.matches('details.vt-menu')||!menu.open)return;
    cerrarMenus(menu);
    const lista=menu.querySelector('.vt-menu-list'),r=menu.querySelector('summary').getBoundingClientRect();
    lista.style.left=Math.max(8,r.right-lista.offsetWidth)+'px';
    const abajo=r.bottom+4;lista.style.top=(abajo+lista.offsetHeight>root.innerHeight-8?Math.max(8,r.top-lista.offsetHeight-4):abajo)+'px';
  },true);
  document.addEventListener('click',e=>{if(!e.target.closest('details.vt-menu')||e.target.closest('.vt-menu-list [role="menuitem"]'))cerrarMenus(null);});
  document.addEventListener('keydown',e=>{if(e.key==='Escape')cerrarMenus(null);});
  root.addEventListener('scroll',()=>cerrarMenus(null),true);
  root.addEventListener('resize',()=>cerrarMenus(null));
  document.querySelectorAll('.js-vt-accion').forEach(btn=>btn.addEventListener('click',async()=>{
    const tr=btn.closest('tr[data-id-formulario]'),id=Number(tr.dataset.idFormulario);
    if(btn.dataset.accion==='rechazar'){abrirRechazo(data.endpoint,id,tr.dataset.ref||('Formulario #'+id),'',recargar);return;}
    const botones=tr.querySelectorAll('button');botones.forEach(b=>b.disabled=true);
    try{await resolver(data.endpoint,{id_formulario:id,accion:'aprobar',comentario:''});recargar('Formulario #'+id+' validado — sus resultados pasan al informe del lote.');}
    catch(e){toast(e.message,true);botones.forEach(b=>b.disabled=false);}
  }));
}

root.LabVal={esc,fechaTxt,contexto,filaCalc,vistaDe,valorPrincipal,checkRango,textoRango,muestrasCalculadas,toast,resolver,abrirRechazo,setMotivos,selectMotivos};
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',iniciarLista);else iniciarLista();
})(window);
