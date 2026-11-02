// Ejecutar: node laboratorio/tests/identificacion_lote.test.js
const fs = require("node:fs"), path = require("node:path");
const source = fs.readFileSync(path.join(__dirname, "../js/identificacion_lote.js"), "utf8");
const config = JSON.parse(fs.readFileSync(path.join(__dirname, "../config/identificacion_v3.json"), "utf8"));
const assert = (value, message) => { if (!value) throw new Error(message); };
async function run() {
  const lib = {};
  new Function("window", "document", source)(lib, {getElementById:()=>null});
  const api = lib.CengiIdentificacion;
  assert(api.onlyId("SOL-123") && !api.onlyId("Finca: Uno\nParcela: 2"), "Clasificación de lecturas");
  assert(api.state({campos:{obs:"Nota"}})==="pendiente", "Las observaciones solas no identifican una muestra");
  assert(api.state({campos:{parcela:"0"}})==="ok", "El valor cero debe conservarse");
  assert(JSON.stringify(api.parseTSV('uno\t"dos\nlíneas"\n"tres ""cuatro"""\t5\n')) === JSON.stringify([["uno","dos\nlíneas"],['tres "cuatro"',"5"]]), "Pegado Excel multilínea");
  assert(api.csvCell("=1+1") === '"\'=1+1"', "Escape de fórmulas CSV");
  assert(api.code128Svg("S-001-10-26",36).includes("<svg"), "Código de barras");

  class Node {
    constructor() { this.value="";this.textContent="";this.innerHTML="";this.dataset={};this.listeners={};this.checked=false;this.disabled=false;this.hidden=false;this.classList={add(){},remove(){}}; }
    addEventListener(name, fn) { (this.listeners[name] ||= []).push(fn); }
    async emit(name, event={}) { for(const fn of this.listeners[name] || []) await fn({target:this,currentTarget:this,...event}); }
    setAttribute(name,value) { this[name]=value; }
    hasAttribute(name) { return name==="data-info" ? !!this.dataset.info : name==="data-field" ? !!this.dataset.field : false; }
    querySelectorAll() { return []; }
    focus() {} click() {} appendChild() {}
  }
  const nodes=new Map(), el=id=>{if(!nodes.has(id))nodes.set(id,new Node());return nodes.get(id);};
  const tabs=["registro","base","etiquetas"].map(t=>{const b=new Node();b.dataset.workTab=t;return b;});
  const panels=tabs.map(b=>{const p=new Node();p.dataset.workPanel=b.dataset.workTab;return p;});
  const app=el("ident-workflow"), page=new Node(), camera=new Node();
  app.dataset={user:"test",initialSample:"",initialLote:""};
  app.querySelectorAll=selector=>selector==="[data-work-tab]"?tabs:selector==="[data-work-panel]"?panels:[];
  app.querySelector=selector=>selector==="[data-batch-camera]"?camera:null;
  const document={getElementById:el,querySelector:()=>page,createElement:()=>new Node(),body:new Node()};
  const sample=(id,codigo)=>({id_muestra:id,codigo_lab:codigo,etiqueta:{info:"",contenido:"completa",campos:{},origen:""}});
  const lots=[
    {id_solicitud:1,codigo_lote:"S-1",cliente:"Cliente de prueba",tipo:"Suelos",tipo_clave:"suelos",version:0,metodo:null,historial:[],muestras:[sample(10,"S-001-10-26"),sample(11,"S-002-10-26")]},
    {id_solicitud:2,codigo_lote:"F-2",cliente:"Otro cliente",tipo:"Foliares",tipo_clave:"foliares",version:0,metodo:null,historial:[],muestras:[sample(20,"F-001-10-26")]},
  ];
  lots.push({id_solicitud:3,codigo_lote:"S-100",cliente:"Captura masiva",tipo:"Suelos",tipo_clave:"suelos",version:0,metodo:null,historial:[],muestras:Array.from({length:100},(_,i)=>sample(100+i,"MAS-"+String(i+1).padStart(3,"0")))});
  el("iw-data").textContent=JSON.stringify(lots);
  el("iw-config").textContent=JSON.stringify(config);
  el("iw-csrf").textContent=JSON.stringify("test-csrf");
  el("iw-lote-estado").value="pendientes";
  el("iw-etq-barras").checked=true;
  const storage=new Map(), requests=[];
  const sessionStorage={getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)};
  const window={addEventListener(){},confirm:()=>true,print(){}};
  const fetch=async(url,options)=>{
    const data=JSON.parse(options.body);requests.push(data);
    const lot={...lots.find(l=>l.id_solicitud===data.id_solicitud),plantilla:data.plantilla,version:data.version+1,metodo:data.metodo,muestras:data.muestras,historial:[]};
    return {ok:true,redirected:false,json:async()=>({lote:lot})};
  };
  new Function("window","document","sessionStorage","fetch",source)(window,document,sessionStorage,fetch);
  assert(el("iw-total").textContent===103,"KPI incluye todas las muestras");
  assert(el("iw-grid-body").innerHTML.includes("S-001-10-26"),"Carga inicial del lote");
  const input=new Node();input.dataset={rowIndex:"0",field:"finca"};input.value="El Roble";
  await el("iw-grid-body").emit("input",{target:input});
  assert(JSON.parse(storage.get("lab-ident-v3:test:1")).muestras[0].etiqueta.campos.finca==="El Roble","Borrador recuperable");
  const before=storage.get("lab-ident-v3:test:1");
  await el("iw-grid-body").emit("paste",{target:input,clipboardData:{getData:()=>"a\nb\nc\n"},preventDefault(){}});
  assert(storage.get("lab-ident-v3:test:1")===before,"Pegado fuera de rango no debe modificar parcialmente");
  el("iw-metodo").value="qr";await el("iw-metodo").emit("change");
  el("iw-lectura").value="F-001-10-26";await el("iw-asignar").emit("click");
  assert(el("iw-status").textContent.includes("otro")||el("iw-status").textContent.includes("pertenece"),"Detecta códigos de otro lote");
  el("iw-lectura").value="SOL-123";await el("iw-asignar").emit("click");
  await el("iw-guardar").emit("click");
  assert(requests.length===0,"No guarda pendientes sin confirmación");
  el("iw-lectura").value="Finca: El Roble\nParcela: 2";await el("iw-asignar").emit("click");
  await el("iw-guardar").emit("click");
  assert(requests.length===1 && requests[0].csrf_token==="test-csrf" && requests[0].version===0,"Guardado autenticado y versionado");
  assert(requests[0].muestras.length===2,"Guardado del lote completo");
  assert(!storage.has("lab-ident-v3:test:1"),"Limpia borrador al guardar");
  assert(el("iw-lote-titulo").textContent.includes("Guardada v1"),"Bloquea lote guardado");
  await el("iw-edit").emit("click");
  await el("iw-guardar").emit("click");
  assert(requests.length===1,"Corrección requiere motivo");
  await tabs[2].emit("click");
  assert(el("iw-etq-preview").innerHTML.includes("S-001-10-26")&&el("iw-etq-preview").innerHTML.includes("<svg"),"Vista previa de etiquetas y barras");
  assert(!el("iw-grid-head").innerHTML.includes("<th>Contenido</th>")&&!el("iw-grid-head").innerHTML.includes("<th>Estado</th>"),"Clasificación sin columnas duplicadas");
  await el("iw-lotes").emit("click",{target:{closest:()=>({dataset:{lot:"3"}})}});
  assert(el("iw-plantilla").value==="Muestreo de suelos","Formato automático según tipo");
  input.dataset={rowIndex:"0",field:"finca"};
  await el("iw-grid-body").emit("paste",{target:input,clipboardData:{getData:()=>Array.from({length:100},(_,i)=>"Finca "+(i+1)).join("\n")},preventDefault(){}});
  let batch=JSON.parse(storage.get("lab-ident-v3:test:3"));
  assert(batch.muestras[99].etiqueta.campos.finca==="Finca 100","Pega las 100 muestras");
  el("iw-muestra-buscar").value="MAS-050";await el("iw-muestra-buscar").emit("input");
  assert(el("iw-visibles").textContent.startsWith("1 de 100")&&!el("iw-grid-body").innerHTML.includes("MAS-049"),"Búsqueda específica en lote de 100");
  input.dataset={rowIndex:"49",field:"finca"};input.value="Corregida";await el("iw-grid-body").emit("input",{target:input});
  const snapshot=storage.get("lab-ident-v3:test:3");
  await el("iw-grid-body").emit("paste",{target:input,clipboardData:{getData:()=>"Uno\nDos"},preventDefault(){}});
  assert(storage.get("lab-ident-v3:test:3")===snapshot,"Filtro impide pegar sobre filas ocultas");
  el("iw-muestra-buscar").value="MAS-05";await el("iw-muestra-buscar").emit("input");
  input.dataset={rowIndex:"49",field:"profundidad"};input.value="20 cm";await el("iw-grid-body").emit("input",{target:input});
  await el("iw-grid-head").emit("click",{target:{closest:()=>({dataset:{fill:"profundidad"}})}});
  batch=JSON.parse(storage.get("lab-ident-v3:test:3"));
  assert(batch.muestras[58].etiqueta.campos.profundidad==="20 cm"&&!batch.muestras[59].etiqueta.campos.profundidad,"Rellena solo celdas filtradas");
  let focused="";app.querySelector=selector=>selector.startsWith('[data-row-index=')?{focus(){focused=selector;}}:null;
  await el("iw-grid-body").emit("keydown",{target:input,key:"Enter",preventDefault(){}});
  assert(focused.includes('"50"')&&focused.includes('"profundidad"'),"Enter avanza en la misma columna filtrada");
  el("iw-plantilla").value="Todos los campos";await el("iw-plantilla").emit("change");
  await el("iw-guardar").emit("click");
  assert(requests[1].muestras.length===100 && requests[1].muestras[49].etiqueta.campos.finca==="Corregida" && requests[1].muestras[50].etiqueta.campos.finca==="Finca 51","Guarda todo el lote conservando filas ocultas");
  assert(requests[1].plantilla==="Todos los campos" && el("iw-plantilla").disabled,"Formato guardado y bloqueado con el lote");
  console.log("OK: estados, Excel, CSV, captura, borradores, guardado, motivo y etiquetas.");
}
run().catch(error=>{console.error(error);process.exitCode=1;});
