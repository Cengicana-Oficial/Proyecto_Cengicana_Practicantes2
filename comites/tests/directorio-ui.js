/* Pruebas de renderizado/filtros sin navegador; ejecutar probarDirectorio(codigoFuente). */
function probarDirectorio(codigo) {
  const nodos = new Map(), mensajes = [];
  function nodo(id) {
    if (!nodos.has(id)) nodos.set(id, {innerHTML:'',textContent:'',hidden:false,dataset:{},listeners:{},classList:{toggle(){}},addEventListener(tipo,fn){this.listeners[tipo]=fn;}});
    return nodos.get(id);
  }
  const base={cargo:'Jefe de Riego',empresa:'Ingenio',telefono:'',email:'contacto@example.invalid',area:'Agrícola',nivel:'Jefatura',estado:'Activo',comites:['riego'],origen_tipo:'manual',origen_detalle:'Directorio',historial:[],ultima:null};
  nodo('dir-data').textContent=JSON.stringify({csrf:'test',contactos:[{...base,id:1,nombre:'Ana <script>'},{...base,id:2,nombre:'Beatriz',area:'Laboratorio',email:'b@example.invalid'},{...base,id:3,nombre:'Carlos',estado:'Inactivo'}],comites:[{id:'riego',nombre:'Riego',activo:1}],segmentos:[],areas:['Agrícola','Laboratorio'],niveles:['Jefatura'],origenes:{manual:'Registro manual'},gestionar:true,enviar:false});
  class Params {
    constructor(){this.values=new Map();} has(k){return this.values.has(k);}get(k){return this.values.get(k);}set(k,v){this.values.set(k,v);}delete(k){this.values.delete(k);}
  }
  class Url {constructor(){this.searchParams=new Params();}}
  const doc={getElementById:nodo};
  new Function('document','location','history','URL','URLSearchParams','fetch','navigator',codigo)(doc,{search:'',href:'http://localhost/comites/index.php?view=contactos'},{replaceState(){}},Url,Params,()=>{throw new Error('Esta prueba no envía solicitudes');},{});
  function verificar(ok,mensaje){if(!ok)throw new Error(mensaje);mensajes.push('OK: '+mensaje);}
  verificar(nodo('dir-count').textContent==='2 contacto(s)','Filtro inicial solo contactos activos');
  verificar(nodo('dir-rows').innerHTML.includes('Ana &lt;script&gt;')&&!nodo('dir-rows').innerHTML.includes('Ana <script>'),'Escape HTML en filas');
  nodo('dir-filtros').listeners.input({target:{name:'area',value:'Agrícola'}});
  verificar(nodo('dir-count').textContent==='1 contacto(s)','Área combinada con estado');
  nodo('dir-filtros').listeners.input({target:{name:'q',value:'contacto@example.invalid'}});
  verificar(nodo('dir-rows').innerHTML.includes('Ana &lt;script&gt;'),'Búsqueda por correo');
  nodo('directorio').listeners.change({target:{dataset:{select:'1'},checked:true}});
  verificar(nodo('dir-masivo').innerHTML.includes('1 seleccionado(s)'),'Selección habilita acciones masivas');
  nodo('dir-filtros').listeners.input({target:{name:'estado',value:'Inactivo'}});
  verificar(nodo('dir-masivo').innerHTML===''&&nodo('dir-rows').innerHTML.includes('Carlos'),'Cambio de filtro limpia selección');
  nodo('dir-filtros').listeners.input({target:{name:'q',value:'sin coincidencias'}});
  verificar(!nodo('dir-empty').hidden,'Estado vacío de filtros');
  return mensajes;
}
