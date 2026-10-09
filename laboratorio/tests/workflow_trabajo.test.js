// node laboratorio/tests/workflow_trabajo.test.js
const fs=require('node:fs'),path=require('node:path');
const engine=fs.readFileSync(path.join(__dirname,'../js/hojas_definiciones.js'),'utf8');
const ui=fs.readFileSync(path.join(__dirname,'../js/workflow_trabajo.js'),'utf8');
const schema=JSON.parse(fs.readFileSync(path.join(__dirname,'../config/hojas_trabajo_v3.json'),'utf8'));
const assert=(v,m)=>{if(!v)throw Error(m);};
async function run(){
 const window={};new Function('window',engine)(window);const defs=window.LabHojas;
 for(const [key,h] of Object.entries(defs.hojas)){
  assert(JSON.stringify(h.cols,(k,v)=>typeof v==='function'?true:v)===JSON.stringify(schema.hojas[key].cols),'Campos distintos al esquema: '+key);
  const enc=Object.fromEntries((h.encabezado?.campos||[]).map(c=>[c.k,c.def||'1']));
  const ctrl=Object.fromEntries(h.controles.map(c=>[c.id,Object.fromEntries(h.cols.filter(col=>!c.sin?.includes(col.k)).map(col=>[col.k,'1']))]));
  const f=Object.fromEntries(h.cols.map(c=>[c.k,c.tipo==='sel'?'P':c.tipo==='txt'?'Referencia':'10']));
  const curve=[{c:'1',a:'0.1'},{c:'2',a:'0.2'}];
  assert(typeof h.calc(f,{enc,ctrl,blk:ctrl.BLANCO||{},reg:defs.regresion(curve),regL:defs.regLineal(curve)},'M')==='object','Falla cálculo: '+key);
 }
 assert(defs.hojas['Suelo|Nitrógeno total'].calc({peso:'2',ml:'3'},{enc:{nhcl:'1',fac:'1.408'},blk:{ml:'1'}}).n===1.408,'Kjeldahl');
 assert(defs.hojas['Caña|% Humedad'].calc({band:'10',mues:'20',bandS:'20'}).pct===50,'Humedad caña');
 class Node{constructor(){this.value='';this.innerHTML='';this.textContent='';this.hidden=false;this.disabled=false;this.dataset={};this.listeners={};}addEventListener(n,f){(this.listeners[n]??=[]).push(f);}async emit(n,e={}){for(const f of this.listeners[n]||[])await f({target:this,preventDefault(){},...e});}querySelectorAll(){return [];}querySelector(){return null;}focus(){}}
 const nodes=new Map(),el=id=>{if(!nodes.has(id))nodes.set(id,new Node());return nodes.get(id);};
 const tasks=Array.from({length:100},(_,i)=>({id_muestra:i+1,id_lote:1,id_solicitud:1,id_tipo_analisis:1,codigo_muestra:'M-'+String(i+1).padStart(3,'0'),codigo_lote:'L-1',tipo_muestra:'Miel',cliente:'Prueba',hoja:'Miel|Brix',analisis_hoja:'Brix',analisis_nombre:'Brix',estado:{key:'pendiente'},prioridad:{key:'alta',rank:3,label:'Alta'},usuario_id:1,analista:'Analista',plan_version:0}));
 el('wf-data').textContent=JSON.stringify({tareas:tasks,usuarios:[{id:1,nombre:'Analista'}],modo:'bandeja',user:{id:1,nombre:'Analista'},manager:true,csrf:'csrf-test'});
 const doc={getElementById:el};const requests=[];
 const fetch=async(url,options)=>{if(options?.method==='POST'){const d=JSON.parse(options.body);requests.push(d);return {ok:true,json:async()=>({version:d.version+1,datos:d.datos,formularios:[]})};}return {ok:true,json:async()=>url.includes('hoja=')?{tareas:tasks,borrador:{version:0,datos:null},vinculos:{}}:{tareas:tasks,usuarios:[]}};};
 window.addEventListener=()=>{};
 new Function('window','document','fetch','confirm',ui)(window,doc,fetch,()=>true);
 assert(el('wf-list').innerHTML.includes('100 muestras'),'Cola de 100 muestras');
 await el('wf-list').emit('click',{target:{closest:q=>q==='[data-sheet]'?{dataset:{sheet:'Miel|Brix'}}:null}});
 await new Promise(resolve=>setTimeout(resolve,0));
 assert(!el('wf-sheet-view').hidden,'Abre hoja');
 await el('wf-sheet-body').emit('click',{target:{closest:()=>({dataset:{action:'all'}})}});
 const input={dataset:{section:'filas',id:'1',field:'valor'}};
 await el('wf-sheet-body').emit('paste',{target:input,clipboardData:{getData:()=>Array.from({length:100},(_,i)=>String(80+i/100)).join('\n')}});
 assert(!el('wf-send').disabled,'Habilita envío tras completar 100 filas');
 await el('wf-save').emit('click');
 assert(requests[0].datos.seleccion.length===100&&requests[0].datos.filas[100].valor==='80.99','Guarda las 100 lecturas');
 assert(requests[0].csrf==='csrf-test'&&requests[0].accion==='guardar','Protección y acción de guardado');
 await el('wf-sheet-body').emit('input',{target:{id:'wf-row-search',value:'M-050'}});
 await el('wf-sheet-body').emit('paste',{target:{dataset:{section:'filas',id:'50',field:'valor'}},clipboardData:{getData:()=>"10\n20"}});
 assert(el('wf-status').textContent.includes('excede'),'Impide pegar sobre muestras ocultas');
 await el('wf-send').emit('click');
 assert(requests[1].accion==='enviar'&&requests[1].version===1,'Envío usa versión del avance');
 console.log('OK: formatos, cálculos, 100 muestras, pegado, filtro, guardado y envío versionado.');
}
run().catch(e=>{console.error(e);process.exitCode=1;});
