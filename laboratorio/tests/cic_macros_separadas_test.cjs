const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const root=path.join(__dirname,'..'),ctx={window:{}};vm.createContext(ctx);vm.runInContext(fs.readFileSync(path.join(root,'js/hojas_definiciones.js'),'utf8'),ctx);
const d=ctx.window.LabHojas,cfg=JSON.parse(fs.readFileSync(path.join(root,'config/hojas_trabajo_v3.json'),'utf8'));
const mk='Suelo|Macronutrientes (Ca, Mg, K, Na)',ck='Suelo|Capacidad de intercambio catiónico (CIC)',old=d.hojas['Suelo|Macronutrientes'];
const f={peso:'2',ca:'10',mg:'8',k:'4',na:'3',ml:'5'},context={enc:{fca:'4.99',fmg:'8.2264',fk:'0.2557',fna:'0.4348',n:'0.0298039'},blk:{ca:'1',mg:'2',k:'1',na:'0.5',ml:'1'}};
const legacy=old.calc(f,context),macro=d.hojas[mk].calc(f,context),cic=d.hojas[ck].calc(f,context);
for(const e of ['ca','mg','k','na'])assert.equal(macro['r_'+e],legacy['r_'+e]);
assert.equal(cic.cic,legacy.cic);assert.equal(macro.cic,undefined);
assert.equal(d.alias['Suelo|Macronutrientes'],mk);assert.equal(cfg.alias['Suelo|Macronutrientes'],mk);assert.equal(cfg.alias[ck],undefined);
for(const [key,name,forbidden]of [[mk,'Macronutrientes','ml'],[ck,'Capacidad de intercambio catiónico (CIC)','ca']]){
 const h=d.hojas[key];assert.deepEqual(Array.from(h.analisis),[name]);assert.equal(h.vistas.length,1);assert.ok(!h.cols.some(c=>c.k===forbidden));
 for(const def of [h,cfg.hojas[key]])assert.ok(def.encabezado.campos.every(c=>c.soloVal&&c.editableVal&&c.gestionVal));
}
assert.equal(old.vistas.length,2,'Conservar revisión de formularios combinados');
console.log('OK: hojas separadas, factores solo en validación, cálculos y formularios históricos.');
