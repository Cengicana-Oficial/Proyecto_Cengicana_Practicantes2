const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const root=path.join(__dirname,'..'),sandbox={window:{},document:{readyState:'loading',addEventListener(){}}};vm.createContext(sandbox);
for(const p of ['hojas_definiciones.js','validacion_tecnica.js'])vm.runInContext(fs.readFileSync(path.join(root,'js',p),'utf8'),sandbox);
const d=sandbox.window.LabHojas,V=sandbox.window.LabVal,fixtures=JSON.parse(fs.readFileSync(path.join(__dirname,'fixtures/curvas_suelos_excel_2026.json'),'utf8'));
const near=(a,b,label)=>assert.ok(Math.abs(a-b)<1e-10,label+': '+a+' != '+b);
for(const c of fixtures){
 const h=d.hojas[c.hoja],r=h.regresion(c.points),azufre=c.hoja==='Suelo|Azufre';
 if(azufre){near(r.m0,0.03932142857142856,'Pendiente con intercepto libre');near(r.b0,-0.01019047619047612,'Intercepto');near(r.r2,0.9847130505863081,'R² lineal');assert.ok(h.info({enc:c.enc,blk:{abs:0},reg:r}).some(x=>x[0]==='Curva (intercepto libre)'));}
 else{near(r.m0,0.04893956043956044,'Pendiente de la curva de Fósforo');near(r.r2,0.9995795913557924,'R² no centrado');}
 assert.ok(h.encabezado.campos.every(f=>f.k==='pend'?!f.soloVal:f.soloVal&&f.editableVal),'Solo pendiente en captura');
 for(const f of c.filas){
  // El Excel de Fósforo usa 0.0481; el sistema debe usar la pendiente de esta corrida.
  const enc=c.enc;
  const cap={hoja:c.hoja,analisis:h.analisis[0],encabezado:enc,controles:{BLANCO:{abs:f.blanco}},curva:c.points,seleccion:[1],filas:{1:{abs:f.abs}}};
  const ctx=V.contexto(cap),m=V.muestrasCalculadas(cap,{})[0].m;near(ctx.reg.r2,r.r2,'R² de validación técnica');
  if(azufre)near(m.res,f.res,'Resultado Excel fila '+f.fila);
  else{near(m.sol,(f.abs-f.blanco)/r.m0,'Solución con pendiente de esta curva');near(m.res,(f.abs-f.blanco)/r.m0*5,'Resultado con pendiente de esta curva');near((f.abs-f.blanco)/0.0481*5,f.res,'Verificar origen de la discrepancia del Excel');}
 }
 if(!azufre){const auto=h.calc({abs:c.filas[0].abs},{enc:c.enc,blk:{abs:0},reg:r});assert.ok(Math.abs(auto.res-c.filas[0].res)>1,'La prueba debe detectar la inconsistencia del Excel');assert.ok(h.encabezado.campos.some(c=>c.k==='pend'&&!c.soloVal));const otro=h.regresion(c.points.map(p=>({...p,a:p.a*2})));near(h.calc({abs:c.filas[0].abs},{enc:c.enc,blk:{abs:0},reg:otro}).res,auto.res/2,'Vacío usa la pendiente de cada curva');near(h.calc({abs:c.filas[0].abs},{enc:{pend:'0.0481',factor:'5'},blk:{abs:0},reg:r}).res,c.filas[0].res,'Pendiente digitada se usa en el cálculo');}
 console.log(`${c.hoja}: R² ${d.fx(r.r2,4)}; ${c.filas.length} resultados comprobados.`);
}
console.log('OK: R², resultados, pendiente en captura y demás parámetros en validación.');
