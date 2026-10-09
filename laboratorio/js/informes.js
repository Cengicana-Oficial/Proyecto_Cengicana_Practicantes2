/* Informes (prototipo): filtros de la lista y, en el detalle, el informe del lote en el formato
   del laboratorio con los valores calculados por las mismas fórmulas de Validación técnica,
   la firma del jefe y las acciones aprobar-y-firmar / devolver. */
(function(){'use strict';
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const norm=v=>String(v??'').normalize('NFD').replace(/[̀-ͯ]/g,'').toLowerCase();

/* ---------- lista: chips de estado y búsqueda ---------- */
const lista=document.getElementById('inf-lista');
if(lista){
  let filtro='LISTO';
  /* 25 informes por página, sobre los que pasan el filtro de estado y la búsqueda. */
  const pager=window.LabPager.crear(document.getElementById('inf-paginas'),25);
  pager.alCambiar(()=>lista.closest('.cengi-table-wrap').scrollIntoView({behavior:'smooth',block:'start'}));
  const aplicar=()=>{const q=norm(document.getElementById('inf-buscar').value);const visibles=[];
    lista.querySelectorAll('tr[data-estado]').forEach(tr=>{const ok=(filtro==='TODOS'||tr.dataset.estado===filtro)&&(!q||norm(tr.dataset.buscar).includes(q));tr.hidden=!ok;if(ok)visibles.push(tr);});
    document.getElementById('inf-vacio').hidden=visibles.length>0;pager.aplicar(visibles);};
  document.querySelectorAll('[data-filtro]').forEach(b=>b.addEventListener('click',()=>{filtro=b.dataset.filtro;document.querySelectorAll('[data-filtro]').forEach(x=>x.classList.toggle('on',x===b));aplicar();}));
  document.getElementById('inf-buscar').addEventListener('input',aplicar);
  aplicar();
  return;
}

/* ---------- detalle ---------- */
const nodo=document.getElementById('inf-data');if(!nodo)return;
const data=JSON.parse(nodo.textContent),d=data.detalle,defs=window.LabHojas,V=window.LabVal;

/* Valor de una columna del formato para una muestra: campo k de la hoja validada (las mismas
   fórmulas de Validación técnica) o, para análisis fuera del formato, su valor principal. */
function valorColumna(idMuestra,col,celda){
  const cap=celda&&celda.id_formulario&&d.snapshots[celda.id_formulario];
  if(!cap||!defs.hojas[cap.hoja]||!(cap.filas||{})[idMuestra])return '';
  try{
    const m=V.filaCalc(cap,cap.filas[idMuestra],'M');
    let v;
    if(col.k){v=m[col.k];}
    else{const vista=defs.hojas[cap.hoja].vistas.find(x=>x.analisis.includes(col.an))||V.vistaDe(cap);
      const p=(vista.principal||{})[col.an]||{};
      if(p.texto)return p.texto(m);
      v=p.k?m[p.k]:null;}
    return typeof v==='number'&&isFinite(v)?v.toFixed(col.d??2):(v==null?'':String(v));
  }catch(e){console.error(e);return '';}
}
/* solo celdas validadas por el técnico; el servidor marca el resto como "Pend." */
const resultados={};
d.muestras.forEach(m=>{data.columnas.forEach((col,i)=>{const c=(data.celdas[m.id_muestra]||{})[i];
  if(c&&c.estado==='validado'){const v=valorColumna(m.id_muestra,col,c);if(v!=='')(resultados[m.id_muestra]??={})[col.key]=v;}});});

/* vista previa: el mismo documento que se guarda en PDF, renderizado en el servidor */
const marco=document.getElementById('inf-reporte'),cargando=document.getElementById('inf-cargando');
let peticion=0;
async function vistaPrevia(){
  const n=++peticion,obs=document.getElementById('inf-obs');
  try{
    const r=await fetch(data.endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({accion:'vista',csrf:data.csrf,id_lote:d.lote.id_lote,resultados,observaciones:obs?obs.value:undefined})});
    const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.error||'No se pudo generar la vista previa.');
    if(n!==peticion)return;
    marco.srcdoc=j.data.html;cargando.hidden=true;
  }catch(e){cargando.hidden=false;cargando.textContent=e.message;}
}
marco.addEventListener('load',()=>{try{const doc=marco.contentDocument;marco.style.height=(doc.documentElement.scrollHeight+24)+'px';}catch(e){}});
vistaPrevia();
let espera=null;
document.getElementById('inf-obs')?.addEventListener('input',()=>{clearTimeout(espera);espera=setTimeout(vistaPrevia,500);});

/* Excel (.xlsx): firmado → valores aprobados guardados; borrador → los mismos valores de pantalla */
document.getElementById('inf-excel').addEventListener('click',()=>{
  const f=document.getElementById('inf-excel-form'),obs=document.getElementById('inf-obs');
  f.resultados.value=JSON.stringify(resultados);f.observaciones.value=obs?obs.value:'';f.submit();
});

/* ---------- firma ---------- */
const canvas=document.getElementById('inf-firma');let firmado=false;
if(canvas){
  const ctx=canvas.getContext('2d');ctx.lineWidth=2.2;ctx.lineCap='round';ctx.strokeStyle='#1f2a24';
  let dibujando=false;
  const pos=e=>{const r=canvas.getBoundingClientRect();return [(e.clientX-r.left)*canvas.width/r.width,(e.clientY-r.top)*canvas.height/r.height];};
  canvas.addEventListener('pointerdown',e=>{dibujando=true;canvas.setPointerCapture(e.pointerId);ctx.beginPath();ctx.moveTo(...pos(e));});
  canvas.addEventListener('pointermove',e=>{if(!dibujando)return;ctx.lineTo(...pos(e));ctx.stroke();firmado=true;canvas.parentElement.classList.add('firmado');});
  ['pointerup','pointercancel'].forEach(t=>canvas.addEventListener(t,()=>{dibujando=false;}));
  document.getElementById('inf-limpiar').addEventListener('click',()=>{ctx.clearRect(0,0,canvas.width,canvas.height);firmado=false;canvas.parentElement.classList.remove('firmado');});
}
const estado=document.getElementById('inf-estado');
async function enviar(payload,boton){
  boton.disabled=true;estado.textContent='Guardando…';estado.className='small';
  try{
    const r=await fetch(data.endpoint,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({...payload,csrf:data.csrf,id_lote:d.lote.id_lote})});
    let j=null;try{j=await r.json();}catch(e){}
    if(!r.ok||!j||!j.ok)throw new Error((j&&j.error)||'No se pudo guardar.');
    return j.data;
  }catch(e){estado.textContent=e.message;estado.className='small vt-flag';boton.disabled=false;throw e;}
}
document.getElementById('inf-firmar')?.addEventListener('click',async e=>{
  if(!firmado){estado.textContent='Firme en el recuadro antes de aprobar.';estado.className='small vt-flag';return;}
  const r=await enviar({accion:'firmar',observaciones:document.getElementById('inf-obs').value,firma_png:canvas.toDataURL('image/png'),resultados},e.currentTarget);
  estado.textContent=r.codigo+' aprobado y firmado — el PDF se guardó en Reportes.';estado.className='small';
  setTimeout(()=>location.reload(),1200);
});
document.getElementById('inf-devolver')?.addEventListener('click',async e=>{
  const motivo=document.getElementById('inf-motivo').value.trim();
  if(motivo.length<5){estado.textContent='Escriba el motivo de la devolución.';estado.className='small vt-flag';return;}
  const r=await enviar({accion:'devolver',motivo},e.currentTarget);
  estado.textContent=r.codigo+' devuelto al técnico — '+r.formularios+' formulario(s) volvieron a Validación técnica.';estado.className='small';
  setTimeout(()=>location.reload(),1400);
});
})();
