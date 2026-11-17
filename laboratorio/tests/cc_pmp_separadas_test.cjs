const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const root=path.join(__dirname,'..'),sandbox={window:{}};vm.createContext(sandbox);vm.runInContext(fs.readFileSync(path.join(root,'js/hojas_definiciones.js'),'utf8'),sandbox);
const d=sandbox.window.LabHojas,cfg=JSON.parse(fs.readFileSync(path.join(root,'config/hojas_trabajo_v3.json'),'utf8'));
const f={cc_caja:'10',cc_h:'25',cc_s:'20',pmp_caja:'12',pmp_h:'24',pmp_s:'22',olla:'P'};
const legacy=d.hojas['Suelo|CC y PMP'].calc(f);
for(const [name,pre] of [['Capacidad de campo (CC)','cc'],['Punto de marchitez permanente (PMP)','pmp']]){
 const key='Suelo|'+name,h=d.hojas[key],other=pre==='cc'?'pmp':'cc';
 assert.equal(d.alias[key],undefined);assert.equal(cfg.alias[key],undefined);
 assert.deepEqual(Array.from(h.analisis),[name]);assert.equal(h.vistas.length,1);
 assert.ok(h.cols.every(c=>!c.k.startsWith(other+'_')));
 const olla=h.cols.find(c=>c.k==='olla');assert.ok(olla.opc);assert.deepEqual(Array.from(olla.opts),['','P','G']);
 assert.ok(h.vistas[0].cols.some(c=>c.k==='olla'),'Olla visible en revisión y CSV');
 assert.equal(h.calc(f)[pre+'_pct'],legacy[pre+'_pct'],'Mismos resultados al separar');
 assert.equal(h.calc(f)[other+'_pct'],undefined);
 assert.equal(h.cols.find(c=>c.k===pre+'_m').auto(f),pre==='cc'?15:12);
 assert.equal(cfg.hojas[key].cols.find(c=>c.k===pre+'_m').auto,true);
 assert.ok(cfg.hojas[key].cols.find(c=>c.k==='olla').opc);
}
assert.equal(d.hojas['Suelo|CC y PMP'].vistas.length,2,'Consulta de formularios anteriores');
console.log('OK: hojas separadas, Olla opcional, cálculos y consulta histórica.');
