const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const root=path.join(__dirname,'..'),sandbox={window:{},document:{readyState:'loading',addEventListener(){}}};vm.createContext(sandbox);
for(const p of ['hojas_definiciones.js','validacion_tecnica.js'])vm.runInContext(fs.readFileSync(path.join(root,'js',p),'utf8'),sandbox);
const d=sandbox.window.LabHojas,V=sandbox.window.LabVal,h=d.hojas['Suelo|Boro'];
const fixture=JSON.parse(fs.readFileSync(path.join(__dirname,'fixtures/boro_suelo_excel_2026.json'),'utf8'));
const r=h.regresion(fixture.curva),anterior=d.regresion(fixture.curva);
assert.equal(r.m0,anterior.m0,'La pendiente y la ecuación y se conservan');
assert.ok(Math.abs(r.m0-0.026988235294117647)<1e-14);
assert.ok(Math.abs(r.r2-0.9278115898079649)<1e-12,'R² no centrado de la curva Excel Boro F7:G16');
assert.ok(Math.abs(anterior.r2-0.899647750968856)<1e-12,'Diferencia con cálculo centrado anterior');
assert.equal(r.puntos.length,10,'Conservar ceros y absorbancias negativas de la curva');
assert.equal(h.regresion([{c:0,a:0},{c:1,a:0}]).r2,null,'Curva sin señal no equivale a R² perfecto');
assert.equal(h.regresion([{c:0,a:0}]),null,'Datos insuficientes');
assert.equal(h.vistas[0].cols.find(c=>c.k==='res').d,2);
assert.equal(h.vistas[0].principal.Boro.d,2);
assert.ok(h.encabezado.campos.every(c=>c.soloVal&&c.editableVal&&c.gestionVal),'Parámetros reservados al revisor');
for(const f of fixture.casos){
 const cap={hoja:'Suelo|Boro',analisis:'Boro',encabezado:{vol:'40',alic:'20',corr:'1.408'},controles:{BLANCO:{abs:f.blanco}},curva:fixture.curva,seleccion:[1],filas:{1:{abs:f.abs}}};
 const ctx=V.contexto(cap),res=V.muestrasCalculadas(cap,{});
 assert.equal(ctx.reg.r2,r.r2,'Validación técnica usa R² corregido');
 assert.ok(Math.abs(res[0].m.res-f.res)<1e-12,'Resultado Excel E'+f.fila);
 assert.match(d.fx(res[0].m.res,2),/^\d+\.\d{2}$/);
}
const cfg=JSON.parse(fs.readFileSync(path.join(root,'config/hojas_trabajo_v3.json'),'utf8'));
assert.equal(cfg.hojas['Suelo|Boro'].vistas[0].cols.find(c=>c.k==='res').d,2);
assert.equal(cfg.catalogo.find(c=>c.tipoMuestra==='Suelo'&&c.analisis==='Boro').decimales,2);
console.log(`OK: R² ${d.fx(r.r2,4)}; y = ${d.fx(r.m0,4)}x sin cambios; 12 resultados Excel; 2 decimales; parámetros en validación.`);
