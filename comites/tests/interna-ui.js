async function probarInterna(codigo) {
  const results=[];
  for(const gestionar of [true,false]){
    const nodes=new Map();
    function node(id){if(!nodes.has(id))nodes.set(id,{innerHTML:'',textContent:'',value:'',hidden:false,open:false,listeners:{},classList:{toggle(){}},addEventListener(t,fn){this.listeners[t]=fn;},showModal(){this.open=true;},close(){this.open=false;}});return nodes.get(id);}
    node('bib-data').textContent=JSON.stringify({interna:true,gestionar,hoy:'2026-09-30',tipos:{Informe:'Informe',Memoria:'Memoria',Otro:'Otro'},estados:{informativo:'Informativo'},etapas:{},carpetas:[{id:1,nombre:'Memorias y presentaciones',parent_id:null},{id:2,nombre:'Anteriores',parent_id:1}],documentos:[{id:1,carpeta_id:2,titulo:'Memoria <script>',tipo:'Memoria',autor:'Investigación',version:2,flujo:'informativo',archivo_ruta:'../uploads/comites/documentos/prueba.pdf'}],contactos:[],comites:[],segmentos:[]});
    class Params{get(){return null;}}
    new Function('document','location','history','URLSearchParams','fetch',codigo)({getElementById:node},{search:''},{replaceState(){}},Params,()=>{throw new Error('No se hacen solicitudes');});
    const assert=(ok,msg)=>{if(!ok)throw new Error(msg);results.push('OK: '+(gestionar?'Administrador':'Consulta')+' · '+msg);};
    const click=async(action,id='')=>{const b={dataset:{bib:action,id},disabled:false};await node('biblioteca-ingenios').listeners.click({target:{closest(){return b;}}});};
    assert(!node('bib-actions').innerHTML.includes('revisión')&&!node('bib-filters').innerHTML.includes('Pendientes'),'Sin acciones ni filtros de revisión');
    assert(node('bib-content').innerHTML.includes('Memorias y presentaciones'),'Carpetas de la plantilla');
    node('biblioteca-ingenios').listeners.input({target:{id:'bib-search',value:'memoria',dataset:{}}});
    assert(node('bib-content').innerHTML.includes('Memoria &lt;script&gt;')&&node('bib-count').textContent==='1 documento(s)','Búsqueda global y escape HTML');
    assert(node('bib-content').innerHTML.includes('view=biblioteca_interna&descarga=1'),'Descarga apunta a biblioteca interna');
    assert(!node('bib-content').innerHTML.includes('Seguimiento del proceso')&&!node('bib-content').innerHTML.includes('Enviar por correo'),'Sin seguimiento de aprobación ni correo');
    if(gestionar){await click('abrir','1');await click('nuevo');assert(node('bib-modal').innerHTML.includes('value="Informe" selected')&&!node('bib-modal').innerHTML.includes('requiere_aprobacion'),'Formulario interno usa Informe sin aprobación');await click('lote');assert(!node('bib-modal').innerHTML.includes('requiere_aprobacion')&&node('bib-modal').innerHTML.includes('Seleccionar archivos'),'Carga múltiple interna');}
    else assert(!node('bib-actions').innerHTML.includes('Crear carpeta')&&!node('bib-content').innerHTML.includes('data-bib="editar"'),'Consulta sin acciones de administración');
  }
  return results;
}
