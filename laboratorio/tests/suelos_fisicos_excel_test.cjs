const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const root=path.join(__dirname,'..');
const sandbox={window:{},document:{readyState:'loading',addEventListener(){}}};vm.createContext(sandbox);
for(const f of ['hojas_definiciones.js','validacion_tecnica.js'])vm.runInContext(fs.readFileSync(path.join(root,'js',f),'utf8'),sandbox,{filename:f});
const defs=sandbox.window.LabHojas,V=sandbox.window.LabVal;
const fixture=JSON.parse(fs.readFileSync(path.join(__dirname,'fixtures/suelos_fisicos_excel_2026.json'),'utf8'));
const hr=defs.hojas['Suelo|Humedad residual'],tex=defs.hojas['Suelo|Textura'],ctx={enc:{peso:'50'}};
const near=(actual,expected,label)=>assert.ok(Math.abs(actual-expected)<1e-10,`${label}: ${actual} != ${expected}`);
for(const c of fixture.casos){
 const residual=hr.calc(c.humedad).pct;near(residual,c.entrada.hr,c.nombre+' HR');
 const entrada={...c.entrada,hr:residual};const r=tex.calc(entrada,ctx);
 for(const [k,v] of Object.entries(c.esperado))near(r[k],v,c.nombre+' '+k);
 assert.equal(tex.validar(entrada,ctx).length,0);
 assert.equal(r.clase,c.nombre==='CONTROL'?'Franco arenoso':'Franco');
 const cap={hoja:'Suelo|Textura',analisis:'Textura',encabezado:{peso:'50'},seleccion:[1],filas:{1:entrada}};
 const checked=V.muestrasCalculadas(cap,{});assert.equal(checked[0].chk.fuera,false);
 near(checked[0].m.arcilla,c.esperado.arcilla,'Validación técnica');
 console.log(`${c.nombre}: HR ${defs.fx(residual,2)}; arcilla ${defs.fx(r.arcilla,2)}; limo ${defs.fx(r.limo,2)}; arena ${defs.fx(r.arena,2)}`);
}
const c=fixture.casos[0],correct=tex.calc(c.entrada,ctx),rounded=tex.calc({...c.entrada,hr:Number(c.entrada.hr.toFixed(2))},ctx);
const manual=tex.calc({...c.entrada,claseManual:'Franco arenoso'},ctx);
assert.equal(manual.clase,'Franco arenoso');assert.equal(manual.usda,'sandy loam');
assert.equal(manual.claseAutomatica,'Franco');assert.equal(manual.claseOrigen,'Corrección técnica');
for(const k of ['arcilla','limo','arena','total'])assert.equal(manual[k],correct[k],'La corrección no cambia porcentajes');
assert.equal(tex.calc({...c.entrada,claseManual:''},ctx).clase,'Franco');
assert.equal(tex.calc({...c.entrada,claseManual:'Inventada'},ctx).clase,'Franco');
assert.equal(tex.calc({...c.entrada,l2:40,claseManual:'Franco'},ctx).clase,null,'La corrección no oculta fracciones imposibles');
assert.ok(Math.abs(correct.arena-rounded.arena)>1e-4,'Detectar regresión por redondear HR antes de Textura');
for(const f of [{...c.humedad,m:0},{...c.humedad,m:-1},{...c.humedad,cajaS:22},{...c.humedad,cajaS:16.18},{...c.humedad,caja:''}])assert.ok(hr.validar(f,ctx).length);
assert.equal(hr.calc({caja:16,m:5,cajaS:21}).pct,0,'HR cero es válida');
for(const f of [{...c.entrada,hr:100},{...c.entrada,hr:-1},{...c.entrada,hr:''},{...c.entrada,l2:40},{...c.entrada,l1:60},{...c.entrada,t1:''}])assert.ok(tex.validar(f,ctx).length);
assert.ok(tex.validar(c.entrada,{enc:{peso:'40'}}).length);
const bad={hoja:'Suelo|Textura',analisis:'Textura',encabezado:{peso:50},seleccion:[1],filas:{1:{...c.entrada,l2:40}}};
assert.ok(V.muestrasCalculadas(bad,{})[0].chk.fuera,'Una textura inválida debe marcarse aunque no tenga rango de catálogo');
assert.equal(tex.calc(bad.filas[1],ctx).clase,null,'No asignar clase a porcentajes imposibles');
const cfg=JSON.parse(fs.readFileSync(path.join(root,'config/hojas_trabajo_v3.json'),'utf8'));
assert.equal(cfg.hojas['Suelo|Textura'].encabezado.campos[0].soloVal,true);
assert.equal(cfg.hojas['Suelo|Textura'].controles[0].id,'CONTROL');
assert.equal(cfg.hojas['Suelo|Humedad residual'].vistas[0].principal['Humedad residual'].d,2);
// La página de revisión habilita el selector solamente en modo revisor.
for(const modo of ['revisor','lectura']){
 const listeners={},node={innerHTML:'',addEventListener(type,fn){listeners[type]=fn;}};
 const cap={hoja:'Suelo|Textura',analisis:'Textura',encabezado:{peso:50},seleccion:[1],filas:{1:{...c.entrada}},controles:{CONTROL:{...c.entrada}},muestras:[{id_muestra:1,codigo_muestra:'S-PRUEBA',codigo_lote:'Lote prueba'}]};
 const payload={modo,formularios:[{id:144,cap,tipo:'Suelo',lote:'Lote prueba',estado:'Revisar'}]};
 sandbox.document.getElementById=id=>id==='wf-review'?node:id==='wf-review-data'?{textContent:JSON.stringify(payload)}:null;
 vm.runInContext(fs.readFileSync(path.join(root,'js/hoja_revision.js'),'utf8'),sandbox);
 assert.ok(!node.innerHTML.includes('Revisión de la clase textural'),'Sin bloque separado');
 assert.ok(node.innerHTML.includes('vt-textura-celda'),'La edición está en la celda de textura');
 assert.equal(node.innerHTML.includes('data-textura="144:1"'),modo==='revisor');
 if(modo==='revisor'){
  listeners.change({target:{dataset:{textura:'144:1'},value:'Franco arenoso'}});
  assert.ok(node.innerHTML.includes('value="Franco arenoso" selected'));
  assert.ok(node.innerHTML.includes('<strong>Franco arenoso</strong>'),'Tabla de resultados actualizada');
  assert.ok(node.innerHTML.includes('data-textura-motivo="144:1"'),'Comentario junto a la clase');
 }
}
console.log('OK: Excel, precisión interna, controles, validación técnica y datos inválidos.');
