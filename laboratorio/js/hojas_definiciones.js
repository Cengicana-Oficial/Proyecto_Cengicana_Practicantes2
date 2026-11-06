/* Definiciones extraídas de Prototipos/laboratorio-workflow-v3.html. */
(function(root){'use strict';let CATALOGO_ANALISIS = [
  {tipoMuestra:'Suelo', analisis:'pH', metodo:'pH en agua-suelo 1:2.5', norma:'ISO 10390', equipoDefault:'pH-metro de mesa (EQ-021)', unidad:'', decimales:2, limiteMin:4.5, limiteMax:7.5, tiempoEstimadoMin:15},
  {tipoMuestra:'Suelo', analisis:'Conductividad eléctrica', metodo:'Conductimetría 1:2.5, corregida a 25 °C', norma:'ISO 11265', equipoDefault:'Conductímetro (EQ-011)', unidad:'dS/m', decimales:3, limiteMin:0, limiteMax:4, tiempoEstimadoMin:10},
  {tipoMuestra:'Suelo', analisis:'Materia orgánica', metodo:'Walkley-Black', norma:'ISO 14235', equipoDefault:'Balanza analítica (EQ-006)', unidad:'%', decimales:2, limiteMin:1, limiteMax:8, tiempoEstimadoMin:45},
  {tipoMuestra:'Suelo', analisis:'Nitrógeno total', metodo:'Kjeldahl', norma:'AOAC 984.13', equipoDefault:'Digestor/destilador Kjeldahl (EQ-003)', unidad:'%', decimales:2, limiteMin:0.05, limiteMax:0.6, tiempoEstimadoMin:40},
  {tipoMuestra:'Suelo', analisis:'Fósforo disponible', metodo:'Olsen modificado (colorimetría)', norma:'AOAC 967.10', equipoDefault:'Espectrofotómetro UV-Vis (EQ-014)', unidad:'ppm', decimales:2, limiteMin:5, limiteMax:60, tiempoEstimadoMin:35},
  {tipoMuestra:'Suelo', analisis:'Macronutrientes', metodo:'Acetato de amonio 1 N · absorción atómica (Ca, Mg, K, Na)', norma:'AOAC 985.01', equipoDefault:'Absorción atómica (EQ-002)', unidad:'meq/100g', decimales:2, limiteMin:null, limiteMax:null, tiempoEstimadoMin:50},
  {tipoMuestra:'Suelo', analisis:'Capacidad de intercambio catiónico (CIC)', metodo:'Acetato de amonio 1 N · titulación', norma:'—', equipoDefault:'Digestor/destilador Kjeldahl (EQ-003)', unidad:'meq/100g', decimales:2, limiteMin:5, limiteMax:40, tiempoEstimadoMin:60},
  {tipoMuestra:'Suelo', analisis:'Micronutrientes', metodo:'Extracción DTPA · absorción atómica (Cu, Zn, Fe, Mn)', norma:'ISO 14870', equipoDefault:'Absorción atómica (EQ-002)', unidad:'ppm', decimales:2, limiteMin:null, limiteMax:null, tiempoEstimadoMin:50},
  {tipoMuestra:'Suelo', analisis:'Azufre', metodo:'Turbidimetría con BaCl₂', norma:'—', equipoDefault:'Espectrofotómetro UV-Vis (EQ-014)', unidad:'ppm SO₄', decimales:2, limiteMin:2, limiteMax:60, tiempoEstimadoMin:40},
  {tipoMuestra:'Suelo', analisis:'Boro', metodo:'Azometina-H (colorimetría)', norma:'—', equipoDefault:'Espectrofotómetro UV-Vis (EQ-014)', unidad:'ppm', decimales:2, limiteMin:0.1, limiteMax:2, tiempoEstimadoMin:40},
  {tipoMuestra:'Suelo', analisis:'Humedad residual', metodo:'Gravimetría (105 °C), ~5 g de suelo seco al aire', norma:'—', equipoDefault:'Estufa de secado (EQ-009)', unidad:'%', decimales:2, limiteMin:0.2, limiteMax:6, tiempoEstimadoMin:60},
  {tipoMuestra:'Suelo', analisis:'Textura', metodo:'Bouyoucos (hidrómetro)', norma:'ASTM D422', equipoDefault:'Hidrómetro Bouyoucos (EQ-030)', unidad:'clase', decimales:0, limiteMin:null, limiteMax:null, tiempoEstimadoMin:120},
  {tipoMuestra:'Suelo', analisis:'Humedad gravimétrica', metodo:'Gravimetría (105 °C)', norma:'ASTM D2216', equipoDefault:'Estufa de secado (EQ-009)', unidad:'%', decimales:2, limiteMin:2, limiteMax:60, tiempoEstimadoMin:60},
  {tipoMuestra:'Suelo', analisis:'Densidad aparente', metodo:'Probeta / suelo tamizado', norma:'—', equipoDefault:'Balanza analítica (EQ-006)', unidad:'g/cm³', decimales:2, limiteMin:0.8, limiteMax:1.6, tiempoEstimadoMin:30},
  {tipoMuestra:'Suelo', analisis:'Capacidad de campo (CC)', metodo:'Olla de presión (1/3 bar)', norma:'ASTM D2325', equipoDefault:'Ollas de presión (EQ-025)', unidad:'%', decimales:2, limiteMin:15, limiteMax:45, tiempoEstimadoMin:180},
  {tipoMuestra:'Suelo', analisis:'Punto de marchitez permanente (PMP)', metodo:'Olla de presión (15 bar)', norma:'ASTM D2325', equipoDefault:'Ollas de presión (EQ-025)', unidad:'%', decimales:2, limiteMin:5, limiteMax:25, tiempoEstimadoMin:180},
  {tipoMuestra:'Foliar', analisis:'Macronutrientes', metodo:'Digestión vía seca · HCl 6 M · absorción atómica (Ca, Mg, K, Na)', norma:'AOAC 985.01', equipoDefault:'Absorción atómica (EQ-002)', unidad:'%', decimales:3, limiteMin:null, limiteMax:null, tiempoEstimadoMin:50},
  {tipoMuestra:'Foliar', analisis:'Micronutrientes', metodo:'Digestión húmeda · absorción atómica (Cu, Zn, Fe, Mn)', norma:'AOAC 985.01', equipoDefault:'Absorción atómica (EQ-002)', unidad:'ppm', decimales:2, limiteMin:null, limiteMax:null, tiempoEstimadoMin:50},
  {tipoMuestra:'Foliar', analisis:'Fósforo', metodo:'Colorimetría (azul de molibdeno)', norma:'AOAC 965.17', equipoDefault:'Espectrofotómetro UV-Vis (EQ-014)', unidad:'%', decimales:3, limiteMin:0.1, limiteMax:0.5, tiempoEstimadoMin:35},
  {tipoMuestra:'Foliar', analisis:'Nitrógeno', metodo:'Kjeldahl', norma:'AOAC 984.13', equipoDefault:'Digestor/destilador Kjeldahl (EQ-003)', unidad:'%', decimales:2, limiteMin:1.5, limiteMax:4.0, tiempoEstimadoMin:40},
  {tipoMuestra:'Foliar', analisis:'Boro', metodo:'Azometina-H (colorimetría)', norma:'—', equipoDefault:'Espectrofotómetro UV-Vis (EQ-014)', unidad:'ppm', decimales:2, limiteMin:5, limiteMax:60, tiempoEstimadoMin:40},
  {tipoMuestra:'Foliar', analisis:'Humedad', metodo:'Gravimetría', norma:'—', equipoDefault:'Estufa de secado (EQ-009)', unidad:'%', decimales:2, limiteMin:null, limiteMax:null, tiempoEstimadoMin:60},
  {tipoMuestra:'Agua', analisis:'Conductividad eléctrica', metodo:'Conductimetría (corrección 0.9985)', norma:'Standard Methods 23 ed.', equipoDefault:'Conductímetro (EQ-011)', unidad:'dS/m', decimales:3, limiteMin:0, limiteMax:3, tiempoEstimadoMin:10},
  {tipoMuestra:'Agua', analisis:'pH', metodo:'Potenciometría', norma:'Standard Methods 23 ed.', equipoDefault:'pH-metro de mesa (EQ-021)', unidad:'', decimales:2, limiteMin:6.5, limiteMax:8.5, tiempoEstimadoMin:10},
  {tipoMuestra:'Agua', analisis:'Sólidos totales disueltos', metodo:'Potenciometría (TDS)', norma:'Standard Methods 23 ed.', equipoDefault:'Conductímetro (EQ-011)', unidad:'mg/L', decimales:1, limiteMin:0, limiteMax:1000, tiempoEstimadoMin:10},
  {tipoMuestra:'Agua', analisis:'Cloruros', metodo:'Argentometría (Mohr)', norma:'Standard Methods 23 ed.', equipoDefault:'Balanza analítica (EQ-006)', unidad:'mg/L', decimales:2, limiteMin:0, limiteMax:250, tiempoEstimadoMin:20},
  {tipoMuestra:'Agua', analisis:'Dureza', metodo:'Titulación con EDTA', norma:'Standard Methods 23 ed.', equipoDefault:'Balanza analítica (EQ-006)', unidad:'mg CaCO₃/L', decimales:0, limiteMin:0, limiteMax:300, tiempoEstimadoMin:20},
  {tipoMuestra:'Agua', analisis:'Alcalinidad total', metodo:'Titulación con H₂SO₄ (método B)', norma:'Standard Methods 23 ed.', equipoDefault:'Balanza analítica (EQ-006)', unidad:'mg CaCO₃/L', decimales:1, limiteMin:0, limiteMax:400, tiempoEstimadoMin:20},
  {tipoMuestra:'Agua', analisis:'Carbonatos', metodo:'Titulación con H₂SO₄', norma:'Standard Methods 23 ed.', equipoDefault:'Balanza analítica (EQ-006)', unidad:'mg/L', decimales:1, limiteMin:null, limiteMax:null, tiempoEstimadoMin:15},
  {tipoMuestra:'Agua', analisis:'Bicarbonatos', metodo:'Titulación con H₂SO₄', norma:'Standard Methods 23 ed.', equipoDefault:'Balanza analítica (EQ-006)', unidad:'mg/L', decimales:1, limiteMin:null, limiteMax:null, tiempoEstimadoMin:15},
  {tipoMuestra:'Agua', analisis:'Razón de adsorción de sodio (RAS)', metodo:'Cálculo matemático (Na, Ca, Mg)', norma:'—', equipoDefault:'Absorción atómica (EQ-002)', unidad:'Un', decimales:2, limiteMin:0, limiteMax:10, tiempoEstimadoMin:5},
  {tipoMuestra:'Agua', analisis:'Resistividad', metodo:'Potenciometría', norma:'Standard Methods 23 ed.', equipoDefault:'Conductímetro (EQ-011)', unidad:'kΩ·cm', decimales:3, limiteMin:null, limiteMax:null, tiempoEstimadoMin:10},
  {tipoMuestra:'Agua', analisis:'Salinidad', metodo:'Potenciometría', norma:'Standard Methods 23 ed.', equipoDefault:'Conductímetro (EQ-011)', unidad:'UPS', decimales:3, limiteMin:null, limiteMax:null, tiempoEstimadoMin:10},
  {tipoMuestra:'Agua', analisis:'Fósforo', metodo:'Espectrofotometría', norma:'Standard Methods 23 ed.', equipoDefault:'Espectrofotómetro UV-Vis (EQ-014)', unidad:'mg/L', decimales:3, limiteMin:null, limiteMax:null, tiempoEstimadoMin:35},
  {tipoMuestra:'Agua', analisis:'Boro', metodo:'Espectrofotometría', norma:'Standard Methods 23 ed.', equipoDefault:'Espectrofotómetro UV-Vis (EQ-014)', unidad:'mg/L', decimales:3, limiteMin:0, limiteMax:2, tiempoEstimadoMin:35},
  {tipoMuestra:'Agua', analisis:'Macronutrientes', metodo:'Absorción atómica (Ca, Mg, K, Na)', norma:'Standard Methods 23 ed.', equipoDefault:'Absorción atómica (EQ-002)', unidad:'mg/L', decimales:3, limiteMin:null, limiteMax:null, tiempoEstimadoMin:40},
  {tipoMuestra:'Agua', analisis:'Micronutrientes', metodo:'Absorción atómica (Cu, Zn, Fe, Mn)', norma:'Standard Methods 23 ed.', equipoDefault:'Absorción atómica (EQ-002)', unidad:'mg/L', decimales:3, limiteMin:null, limiteMax:null, tiempoEstimadoMin:40},
  {tipoMuestra:'Caña', analisis:'Brix', metodo:'Refractometría', norma:'ICUMSA GS1/3/4/7/8-22', equipoDefault:'Refractómetro digital (EQ-017)', unidad:'°Brix', decimales:1, limiteMin:14, limiteMax:24, tiempoEstimadoMin:10},
  {tipoMuestra:'Caña', analisis:'Pol', metodo:'Polarimetría (sacarosa aparente)', norma:'ICUMSA GS1/2/3-1', equipoDefault:'Polarímetro (EQ-018)', unidad:'%', decimales:2, limiteMin:10, limiteMax:20, tiempoEstimadoMin:20},
  {tipoMuestra:'Caña', analisis:'Rendimiento', metodo:'Cálculo: pureza, jugo %, pol % caña y rendimiento', norma:'—', equipoDefault:'Polarímetro (EQ-018)', unidad:'lb/tc', decimales:1, limiteMin:null, limiteMax:null, tiempoEstimadoMin:15},
  {tipoMuestra:'Caña', analisis:'pH', metodo:'Potenciometría en jugo', norma:'—', equipoDefault:'pH-metro de mesa (EQ-021)', unidad:'', decimales:2, limiteMin:4.5, limiteMax:6, tiempoEstimadoMin:10},
  {tipoMuestra:'Caña', analisis:'% Humedad', metodo:'Gravimetría (secado)', norma:'—', equipoDefault:'Estufa de secado (EQ-009)', unidad:'%', decimales:2, limiteMin:60, limiteMax:80, tiempoEstimadoMin:120},
  {tipoMuestra:'Caña', analisis:'% Fibra', metodo:'Método Tanimoto', norma:'—', equipoDefault:'Balanza analítica (EQ-006)', unidad:'%', decimales:2, limiteMin:10, limiteMax:18, tiempoEstimadoMin:60},
  {tipoMuestra:'Miel', analisis:'Brix', metodo:'Refractometría', norma:'ICUMSA GS4/3-13', equipoDefault:'Refractómetro digital (EQ-017)', unidad:'°Brix', decimales:2, limiteMin:75, limiteMax:92, tiempoEstimadoMin:10},
  {tipoMuestra:'Miel', analisis:'Pol', metodo:'Polarimetría', norma:'ICUMSA GS4/3-13', equipoDefault:'Polarímetro (EQ-018)', unidad:'%', decimales:2, limiteMin:25, limiteMax:45, tiempoEstimadoMin:20},
  {tipoMuestra:'Miel', analisis:'Pureza', metodo:'Cálculo Pol ÷ Brix', norma:'ICUMSA', equipoDefault:'Polarímetro (EQ-018)', unidad:'%', decimales:2, limiteMin:30, limiteMax:60, tiempoEstimadoMin:5},
  {tipoMuestra:'Miel', analisis:'Azúcares reductores', metodo:'Lane y Eynon', norma:'ICUMSA GS4/3-3', equipoDefault:'Balanza analítica (EQ-006)', unidad:'%', decimales:2, limiteMin:5, limiteMax:25, tiempoEstimadoMin:40},
  {tipoMuestra:'Miel', analisis:'Cenizas', metodo:'Gravimetría (conductimétrica)', norma:'ICUMSA GS4/3-11', equipoDefault:'Estufa de secado (EQ-009)', unidad:'%', decimales:2, limiteMin:6, limiteMax:16, tiempoEstimadoMin:60},
  {tipoMuestra:'Fertilizantes', analisis:'N total', metodo:'Kjeldahl', norma:'AOAC 2006.03', equipoDefault:'Digestor/destilador Kjeldahl (EQ-003)', unidad:'%', decimales:1, limiteMin:0, limiteMax:46, tiempoEstimadoMin:40},
  {tipoMuestra:'Fertilizantes', analisis:'P2O5', metodo:'Colorimetría', norma:'AOAC 2006.03', equipoDefault:'Espectrofotómetro UV-Vis (EQ-014)', unidad:'%', decimales:1, limiteMin:0, limiteMax:46, tiempoEstimadoMin:40},
  {tipoMuestra:'Fertilizantes', analisis:'K2O', metodo:'Absorción atómica', norma:'AOAC 2006.03', equipoDefault:'Absorción atómica (EQ-002)', unidad:'%', decimales:1, limiteMin:0, limiteMax:60, tiempoEstimadoMin:40},
  {tipoMuestra:'Fertilizantes', analisis:'Humedad', metodo:'Gravimetría (secado)', norma:'AOAC 925.10', equipoDefault:'Estufa de secado (EQ-009)', unidad:'%', decimales:1, limiteMin:0, limiteMax:15, tiempoEstimadoMin:120},
  {tipoMuestra:'Otro', analisis:'Parámetro personalizado', metodo:'A definir', norma:'—', equipoDefault:'Balanza analítica (EQ-006)', unidad:'', decimales:2, limiteMin:null, limiteMax:null, tiempoEstimadoMin:30},
];

function catEntry(t,a){return CATALOGO_ANALISIS.find(c=>c.tipoMuestra===t&&c.analisis===a);}
function num(v){ if(v===''||v==null) return null; const x=parseFloat(String(v).replace(',','.')); return isNaN(x)?null:x; }
/* Formato con el mismo redondeo que Excel: se limpia el ruido de punto flotante a 15 cifras significativas y
   se redondea la mitad hacia arriba (12.375 → 12.38; con toFixed directo daba 12.37 porque el cálculo queda
   en 12.37499999…). */
function fx(v,d){
  if(v==null||typeof v!=='number'||!isFinite(v)) return '—';
  d=d==null?2:d; const limpio=Number(v.toPrecision(15));
  // Desplazar el exponente (1.005e2) en lugar de multiplicar por 10^d evita reintroducir el error (1.005 × 100 = 100.4999…).
  // Números que JS escribe en notación científica (1e-7, 1e21) no admiten ese desplazamiento: se multiplican.
  const a=Math.abs(limpio), s=String(a);
  const r=s.includes('e')?Math.round(a*Math.pow(10,d)):Math.round(Number(s+'e'+d));
  return (Math.sign(limpio)*r/Math.pow(10,d)).toFixed(d);
}
function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/"/g,'&quot;'); }
function jsq(s){ return String(s).replace(/\\/g,'\\\\').replace(/'/g,"\\'"); }
function safeId(s){ return String(s).replace(/[^a-zA-Z0-9]/g,'_'); }
function fechaTxt(iso){ const m=String(iso||'').match(/^(\d{4})-(\d{2})-(\d{2})$/); return m?`${m[3]}/${m[2]}/${m[1]}`:(iso||'—'); }
function hoyISO(){ return new Date().toISOString().slice(0,10); }
function ahoraTxt(){ return new Date().toLocaleString('es-GT',{day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}); }

function regresion(puntos){
  const p = (puntos||[]).map(q=>({x:num(q.c), y:num(q.a)})).filter(q=>q.x!=null && q.y!=null);
  if(p.length<2) return null;
  const n=p.length; let sx=0,sy=0,sxx=0,sxy=0;
  p.forEach(q=>{ sx+=q.x; sy+=q.y; sxx+=q.x*q.x; sxy+=q.x*q.y; });
  if(!sxx) return null;
  const m0 = sxy/sxx;                                   // y = m·x (por el origen)
  const ym = sy/n; let ssr=0, sst=0;
  p.forEach(q=>{ ssr+=Math.pow(q.y-m0*q.x,2); sst+=Math.pow(q.y-ym,2); });
  const r2 = sst ? 1-ssr/sst : 1;
  return {m0, r2, n, puntos:p};
}
function ecuacion(reg){ return reg ? `y = ${fx(reg.m0,4)}x`+(reg.b0?` ${reg.b0<0?'−':'+'} ${fx(Math.abs(reg.b0),4)}`:'') : 'Sin datos suficientes'; }
function esND(v){ return /^\s*n\s*\/?\s*d\s*$/i.test(String(v==null?'':v)); }

const TEXTURA_USDA = {'Arena':'sand','Arena franca':'loamy sand','Franco arenoso':'sandy loam','Franco':'loam','Franco limoso':'silt loam','Limo':'silt',
  'Franco arcillo arenoso':'sandy clay loam','Franco arcilloso':'clay loam','Franco arcillo limoso':'silty clay loam','Arcillo arenoso':'sandy clay','Arcillo limoso':'silty clay','Arcilla':'clay'};
/* Clase textural (triángulo USDA) */
function claseTextural(arena, limo, arcilla){
  if([arena,limo,arcilla].some(v=>v==null||!isFinite(v)||v<0||v>100)||Math.abs(arena+limo+arcilla-100)>1e-8) return null;
  if(limo + 1.5*arcilla < 15) return 'Arena';
  if(limo + 2*arcilla < 30) return 'Arena franca';
  if(arcilla>=40 && limo>=40) return 'Arcillo limoso';
  if(arcilla>=40 && arena<=45) return 'Arcilla';
  if(arcilla>=35 && arena>45) return 'Arcillo arenoso';
  if(arcilla>=27 && arena<=20) return 'Franco arcillo limoso';
  if(arcilla>=27 && arena<=45) return 'Franco arcilloso';
  if(arcilla>=20 && limo<28 && arena>45) return 'Franco arcillo arenoso';
  if(limo>=80 && arcilla<12) return 'Limo';
  if(limo>=50) return 'Franco limoso';
  if(arcilla>=7 && limo>=28 && arena<=52) return 'Franco';
  return 'Franco arenoso';
}

const PESO_EQ = {ca:20.04, mg:12.15, k:39.10, na:22.99};

/* ---------- Constructores de formatos reutilizables ---------- */
/* Colorimetría con curva de calibración.
   o.calcRes(net, ctx) calcula el resultado a partir de (Abs − blanco), tal como la hoja del laboratorio. */
function hojaCurva(o){
  const dRes = o.dRes==null?2:o.dRes;
  const vcols = [{k:'blk',label:o.blkLabel||'Abs. blanco',d:3,fm:'Fila Blanco'},{k:'abs',label:'Absorbancia',d:3}];
  if(o.conSol) vcols.push({k:'sol',label:'ppm en sol.',d:3,fm:o.fmSol});
  vcols.push({k:'res',label:o.resLabel,d:dRes,res:true,fm:o.fmRes});
  return {
    titulo:o.titulo, analisis:[o.analisis],
    encabezado:{titulo:'Parámetros de la corrida', campos:o.enc},
    controles:[{id:'BLANCO',label:'Blanco'},{id:'CONTROL',label:'Control'}],
    cols:[{k:'abs',label:'Absorbancia'}],
    curva:{def:o.curvaDef, unidadX:o.unidadCurva||'ppm'},
    calc:(f,ctx)=>{
      const abs=num(f.abs), blk=num(ctx.blk.abs)||0;
      const net = abs!=null ? abs-blk : null;
      return Object.assign({blk}, net==null ? {} : o.calcRes(net, ctx));
    },
    info:ctx=>[['Absorbancia del blanco', fx(num(ctx.blk.abs),3)], [ctx.reg?.ajuste==='lineal'?'Curva (intercepto libre)':'Curva (por el origen)', ecuacion(ctx.reg)], ['R²', ctx.reg?fx(ctx.reg.r2,4):'—']].concat(o.info?o.info(ctx):[]),
    formula:o.formula,
    vistas:[{analisis:[o.analisis], grafico:true, cols:vcols, principal:{[o.analisis]:{k:'res',d:dRes}}}]
  };
}
function curvaFosforo(titulo, analisis, factorDef, resLabel, dRes){
  return hojaCurva({titulo, analisis, resLabel, dRes, conSol:true, curvaDef:[1,1,2,2,4,4,5,5,10,10,20,20],
    enc:[{k:'pend',label:'Pendiente de la curva (m) — vacío = calculada',def:''},{k:'factor',label:'Factor ppm sol. → resultado',def:factorDef}],
    calcRes:(net,ctx)=>{ const m = num(ctx.enc.pend) || (ctx.reg && ctx.reg.m0); const fac=num(ctx.enc.factor);
      const sol = m ? net/m : null; return {sol, res:(sol!=null&&fac!=null)?sol*fac:null}; },
    fmSol:'(Abs − blanco) ÷ m', fmRes:'ppm en sol. × factor',
    info:ctx=>[['Pendiente usada', num(ctx.enc.pend) ? fx(num(ctx.enc.pend),4)+' (digitada)' : ctx.reg ? fx(ctx.reg.m0,4)+' (de la curva)' : '—']],
    formula:'ppm en sol. = (Abs − blanco) ÷ pendiente de la curva · resultado = ppm en sol. × factor'});
}
function curvaBoro(titulo, analisis, resLabel, dRes=3){
  return hojaCurva({titulo, analisis, resLabel, dRes, curvaDef:[0,0,0.25,0.25,0.5,0.5,1,1,2,2],
    enc:[{k:'vol',label:'Volumen de extracción (mL)',def:'40'},{k:'alic',label:'Alícuota (mL)',def:'20'},{k:'corr',label:'Factor de corrección',def:'1.408'}],
    calcRes:(net,ctx)=>{ const v=num(ctx.enc.vol), a=num(ctx.enc.alic), c=num(ctx.enc.corr);
      return {res:(v!=null&&a&&c!=null)? net*v*(100+c)/(a*100) : null}; },
    fmRes:'(Abs − blanco) × vol × (100 + corr.) ÷ (alícuota × 100)',
    formula:'La curva se registra para controlar la linealidad; el resultado usa el factor de dilución, igual que la hoja del laboratorio.'});
}
function hojaKjeldahl(o){
  return {
    titulo:o.titulo, analisis:[o.analisis],
    encabezado:{titulo:'Parámetros de la corrida', campos:[{k:'nhcl',label:'Normalidad del ácido clorhídrico',unidad:'N',def:'0.0099779'},{k:'fac',label:'Factor miliequivalente de N (× 100)',def:'1.408'}]},
    controles:[{id:'BLANCO',label:'Blanco',sin:['peso']},{id:'CONTROL',label:'Control'}],
    cols:[{k:'peso',label:'Peso (g)'},{k:'ml',label:'mL HCl'}],
    calc:(f,ctx)=>{
      const blk=num(ctx.blk.ml), ml=num(f.ml), p=num(f.peso), N=num(ctx.enc.nhcl), fac=num(ctx.enc.fac);
      return {blk, n:(ml!=null && blk!=null && p && N!=null && fac!=null) ? (ml-blk)*N*fac/p : null};
    },
    info:ctx=>[['mL HCl del blanco', fx(num(ctx.blk.ml),2)]],
    formula:'% N = (mL HCl muestra − mL HCl blanco) × N HCl × 1.408 ÷ peso',
    vistas:[{analisis:[o.analisis],
      cols:[{k:'peso',label:'Peso (g)',d:4},{k:'blk',label:'mL HCl blanco',d:2,fm:'Fila Blanco'},{k:'ml',label:'mL HCl muestra',d:2},{k:'n',label:'% N',d:2,res:true,fm:'(mL muestra − mL blanco) × N × factor ÷ peso'}],
      principal:{[o.analisis]:{k:'n',d:2}}}]
  };
}
function hojaAA(o){
  const els=o.elementos, d=o.dRes==null?2:o.dRes;
  const conv = (base,e)=> base==null?null : (o.modo==='meq' ? base/(PESO_EQ[e.k]*10) : o.modo==='pct' ? base/10000 : base);
  const vcols = [];
  if(o.pesoEnValidacion) vcols.push({k:'peso',label:'Peso (g)',d:3});
  els.forEach(e=>vcols.push({k:e.k,label:o.inLabel(e),d:3,g:'Lectura'}));
  els.forEach(e=>vcols.push({k:'blk_'+e.k,label:'Blk '+e.sym,d:3,g:'Blanco',fm:'Fila Blanco'}));
  const fmRes = o.modo==='meq' ? '(ppm − blk) × vol ÷ peso ÷ (peq × 10)' : o.modo==='pct' ? '(conc − blk) × vol ÷ peso ÷ 10 000' : '(conc − blk) × vol ÷ peso';
  els.forEach(e=>vcols.push({k:'r_'+e.k,label:e.sym+' ('+o.unidad+')',d,g:'Resultado',res:true,fm:fmRes}));
  if(o.cic) vcols.push({k:'cic',label:'CIC (meq/100g)',d:2,g:'Resultado',res:true,fm:'Ca + Mg + K + Na'});
  return {
    titulo:o.titulo, analisis:[o.analisis],
    encabezado:{titulo:'Parámetros de la corrida', campos:[{k:'vol',label:'Volumen de extracción (mL)',def:o.volDef}]},
    referencia:o.referencia||null,
    controles:[{id:'BLANCO',label:'Blanco',sin:['peso']},{id:'CONTROL',label:'Control'}],
    cols:[{k:'peso',label:'Peso (g)'}].concat(els.map(e=>({k:e.k,label:o.inLabel(e),opc:!!e.opc}))),
    calc:(f,ctx)=>{
      const out={}, p=num(f.peso), v=num(ctx.enc.vol); let suma=0, completo=true;
      els.forEach(e=>{
        const blk=num(ctx.blk[e.k])||0, c=num(f[e.k]);
        const base=(c!=null && p && v) ? (c-blk)*v/p : null;
        out['blk_'+e.k]=blk; out['r_'+e.k]=conv(base,e);
        if(out['r_'+e.k]==null){ if(!e.opc) completo=false; } else suma+=out['r_'+e.k];
      });
      if(o.cic) out.cic = completo ? suma : null;
      return out;
    },
    info:ctx=>(els.map(e=>['Blanco '+e.sym, fx(num(ctx.blk[e.k]),3)])),
    formula:o.formula,
    vistas:[{analisis:[o.analisis], cols:vcols,
      principal:{[o.analisis]:{k:o.cic?'cic':null, texto:m=>els.filter(e=>!e.opc||m['r_'+e.k]!=null).map(e=>e.sym+' '+fx(m['r_'+e.k],d)).join(' · ')+(o.cic?' · CIC '+fx(m.cic,2):'')}}}]
  };
}
function hojaGenerica(tipo, analisis){
  const c = catEntry(tipo, analisis) || {}; const u=c.unidad||''; const d=c.decimales==null?2:c.decimales;
  const lbl = 'Resultado'+(u?' ('+u+')':'');
  return {titulo:analisis, analisis:[analisis], generico:true, controles:[], cols:[{k:'valor',label:lbl}],
    calc:()=>({}), vistas:[{analisis:[analisis], cols:[{k:'valor',label:lbl,d,res:true}], principal:{[analisis]:{k:'valor',d}}}]};
}

/* ---------- Formatos específicos (revisión de Suelos) ---------- */
const HOJAS = {
  'Suelo|pH': {
    titulo:'pH y conductividad eléctrica', analisis:['pH','Conductividad eléctrica'],
    encabezado:{titulo:'Observaciones de temperatura (°C) — aplican a toda la corrida', campos:[
      {k:'t1a',label:'T₁ · lectura 1',unidad:'°C'},{k:'t1b',label:'T₁ · lectura 2',unidad:'°C'},
      {k:'t2a',label:'T₂ · lectura 1',unidad:'°C'},{k:'t2b',label:'T₂ · lectura 2',unidad:'°C'},
      {k:'fAgua',label:'Factor de temperatura — agua',def:'0.9985'},{k:'fMuestra',label:'Factor de temperatura — control y muestras',def:'0.994'}]},
    controles:[{id:'AGUA',label:'Agua'},{id:'CONTROL',label:'Control'}],
    cols:[{k:'ph',label:'pH',analisis:'pH'},{k:'ce',label:'Lectura conductividad (µS/cm)',analisis:'Conductividad eléctrica'}],
    calc:(f,ctx,rowId)=>{
      const ts=['t1a','t1b','t2a','t2b'].map(k=>num(ctx.enc[k])).filter(v=>v!=null);
      const T = ts.length ? ts.reduce((a,b)=>a+b,0)/ts.length : null;
      const ce=num(f.ce), agua=num((ctx.ctrl.AGUA||{}).ce), fa=num(ctx.enc.fAgua), fm=num(ctx.enc.fMuestra);
      let ceDs=null;
      if(ce!=null){
        if(rowId==='AGUA') ceDs = fa!=null ? ce*fa/1000 : null;
        else ceDs = (agua!=null && fm!=null) ? (ce-agua)*fm/1000 : null;
      }
      return {temp:T, agua: rowId==='AGUA' ? null : agua, ceDs};
    },
    info:ctx=>{
      const ts=['t1a','t1b','t2a','t2b'].map(k=>num(ctx.enc[k])).filter(v=>v!=null);
      return [['Temperaturas registradas', ts.length?ts.map(t=>fx(t,1)).join(' · ')+' °C':'—'],['Temperatura promedio', ts.length?fx(ts.reduce((a,b)=>a+b,0)/ts.length,1)+' °C':'25.0 °C (sin registro)']];
    },
    formula:'Agua: CE = lectura × factor agua ÷ 1000 · Control y muestras: CE = (lectura − lectura del agua) × factor ÷ 1000',
    vistas:[
      {titulo:'pH', analisis:['pH'], cols:[{k:'ph',label:'pH (adimensional)',d:2,res:true}], principal:{'pH':{k:'ph',d:2}}},
      {titulo:'Conductividad eléctrica (CE)', analisis:['Conductividad eléctrica'], cols:[{k:'ce',label:'Lectura conductividad (µS/cm)',d:3},{k:'temp',label:'Temperatura (°C)',d:1,fm:'Promedio T₁ y T₂'},{k:'agua',label:'Lectura del agua',d:3,fm:'Fila Agua'},{k:'ceDs',label:'CE (dS m⁻¹)',d:3,res:true,fm:'(Lectura − agua) × 0.994 ÷ 1000'}], principal:{'Conductividad eléctrica':{k:'ceDs',d:3}}}
    ]
  },
  'Suelo|Nitrógeno total': hojaKjeldahl({titulo:'Nitrógeno total', analisis:'Nitrógeno total'}),
  'Suelo|Materia orgánica': {
    titulo:'Materia orgánica', analisis:['Materia orgánica'],
    encabezado:{titulo:'Valoración de la solución de sulfato ferroso 1.0 N', campos:[
      {k:'dic1',label:'M1 · mL consumidos de dicromato de potasio 1 N'},{k:'dic2',label:'M2 · mL consumidos de dicromato de potasio 1 N'},
      {k:'mlFe',label:'mL utilizados de sulfato ferroso 1 N',def:'1'},{k:'nDic',label:'Normalidad del dicromato de potasio',def:'1'}]},
    controles:[{id:'BLANCO',label:'Blanco',sin:['peso']},{id:'CONTROL_ALTO',label:'Control alto',def:{peso:'0.50'}},{id:'CONTROL_MEDIO',label:'Control medio',def:{peso:'0.50'}},{id:'CONTROL_BAJO',label:'Control bajo',def:{peso:'0.50'}}],
    cols:[{k:'peso',label:'Peso muestra (g)'},{k:'fe',label:'Sulfato ferroso consumido (mL)'}],
    calc:(f,ctx)=>{
      const vb=num(ctx.blk.fe), fe=num(f.fe), p=num(f.peso);
      const d1=num(ctx.enc.dic1), d2=num(ctx.enc.dic2), mlFe=num(ctx.enc.mlFe), nDic=num(ctx.enc.nDic);
      const prom = (d1!=null&&d2!=null) ? (d1+d2)/2 : (d1!=null?d1:d2);
      const nfe = (prom && mlFe!=null && nDic!=null) ? mlFe*nDic/prom : null;
      const c = (vb!=null && fe!=null && p && nfe!=null) ? (vb-fe)*nfe*0.39/p : null;
      return {c, mo: c!=null ? c*1.724 : null};
    },
    info:ctx=>{ const vb=num(ctx.blk.fe), d1=num(ctx.enc.dic1), d2=num(ctx.enc.dic2), prom=(d1!=null&&d2!=null)?(d1+d2)/2:null, mlFe=num(ctx.enc.mlFe), nDic=num(ctx.enc.nDic);
      return [['Sulfato ferroso del blanco', fx(vb,2)+' mL'],['Dicromato consumido (promedio M1-M2)', fx(prom,3)+' mL'],['Normalidad FeSO₄ = mL FeSO₄ × N dicromato ÷ promedio', (prom&&mlFe!=null&&nDic!=null)?fx(mlFe*nDic/prom,4)+' N':'—']]; },
    formula:'% C = (mL blanco − mL muestra) ÷ peso × N FeSO₄ × 0.39 · % MO = % C × 1.724',
    vistas:[{analisis:['Materia orgánica'], cols:[{k:'peso',label:'Peso muestra (g)',d:2},{k:'fe',label:'Sulfato ferroso consumido (mL)',d:1},{k:'c',label:'% Carbono orgánico',d:2,fm:'(mL blanco − mL muestra) × N FeSO₄ × 0.39 ÷ peso'},{k:'mo',label:'% Materia orgánica',d:2,res:true,fm:'% C × 1.724'}], principal:{'Materia orgánica':{k:'mo',d:2}}}]
  },
  'Suelo|Fósforo disponible': curvaFosforo('Fósforo disponible','Fósforo disponible','5','ppm P en suelo',2),
  'Suelo|Boro': curvaBoro('Boro','Boro','ppm B muestra',2),
  'Suelo|Azufre': hojaCurva({titulo:'Azufre', analisis:'Azufre', resLabel:'ppm SO₄', dRes:2, curvaDef:[0,0,2,2,4,4,6,6,8,8,10,10],
    enc:[{k:'vol',label:'Volumen de extracción (mL)',def:'25'},{k:'alic',label:'Peso / alícuota',def:'10'},{k:'corr',label:'Factor de corrección',def:'1.408'}],
    calcRes:(net,ctx)=>{ const v=num(ctx.enc.vol), a=num(ctx.enc.alic), c=num(ctx.enc.corr);
      return {res:(v!=null&&a&&c!=null)? net*v/a*(100+c/100) : null}; },
    fmRes:'(Abs − blanco) × vol ÷ alícuota × (100 + corr. ÷ 100)',
    formula:'Fórmula tal como está en la hoja del laboratorio. La curva se registra para controlar la linealidad.'}),
  'Suelo|Macronutrientes': {
    titulo:'Macronutrientes (Ca, Mg, K, Na intercambiables) y CIC', analisis:['Macronutrientes','Capacidad de intercambio catiónico (CIC)'],
    encabezado:{titulo:'Factores de cálculo (dilución y peso equivalente) y titulante de CIC', campos:[
      {k:'fca',label:'Factor Ca',def:'4.99'},{k:'fmg',label:'Factor Mg',def:'8.2264'},{k:'fk',label:'Factor K',def:'0.2557'},{k:'fna',label:'Factor Na',def:'0.4348'},
      {k:'n',label:'Normalidad del titulante (CIC)',unidad:'N',def:'0.0298039'}]},
    controles:[{id:'BLANCO',label:'Blanco',sin:['peso']},{id:'CONTROL',label:'Control'}],
    cols:[{k:'peso',label:'Peso (g)'},{k:'ca',label:'ppm Ca',analisis:'Macronutrientes'},{k:'mg',label:'ppm Mg',analisis:'Macronutrientes'},{k:'k',label:'ppm K',analisis:'Macronutrientes'},{k:'na',label:'ppm Na',analisis:'Macronutrientes'},
      {k:'ml',label:'CIC · mL titulante',analisis:'Capacidad de intercambio catiónico (CIC)'}],
    calc:(f,ctx)=>{
      const o={}, p=num(f.peso);
      ['ca','mg','k','na'].forEach(e=>{ const c=num(f[e]), b=num(ctx.blk[e])||0, fac=num(ctx.enc['f'+e]);
        o['blk_'+e]=b; o['r_'+e]=(c!=null&&p&&fac!=null)?(c-b)*fac/p:null; });
      const bml=num(ctx.blk.ml), ml=num(f.ml), N=num(ctx.enc.n);
      o.blk=bml; o.cic=(ml!=null&&bml!=null&&p&&N!=null)?(ml-bml)*N*1000/p:null;
      return o;
    },
    info:ctx=>['ca','mg','k','na'].map(e=>['Blanco '+e.toUpperCase().replace('CA','Ca').replace('MG','Mg').replace('NA','Na'), fx(num(ctx.blk[e]),3)]).concat([['Blanco CIC (mL)', fx(num(ctx.blk.ml),2)]]),
    formula:'meq/100 g = (ppm − blk) × factor del elemento ÷ peso · CIC (meq/100 g) = (mL muestra − mL blanco) × N × 1000 ÷ peso',
    vistas:[{titulo:'Macronutrientes', analisis:['Macronutrientes'], cols:[{k:'peso',label:'Peso',d:2}]
      .concat([['ca','Ca'],['mg','Mg'],['k','K'],['na','Na']].map(([k,s])=>({k,label:'ppm '+s,d:3,g:'Lectura'})))
      .concat([['ca','Ca'],['mg','Mg'],['k','K'],['na','Na']].map(([k,s])=>({k:'blk_'+k,label:'blk '+s,d:3,g:'Blanco',fm:'Fila Blanco'})))
      .concat([['ca','Ca','4.99'],['mg','Mg','8.2264'],['k','K','0.2557'],['na','Na','0.4348']].map(([k,s,fc])=>({k:'r_'+k,label:s+' (meq/100g)',d:3,g:'Intercambiables',res:true,fm:`(ppm − blk) × ${fc} ÷ peso`}))),
      principal:{'Macronutrientes':{k:null, texto:m=>`Ca ${fx(m.r_ca,2)} · Mg ${fx(m.r_mg,2)} · K ${fx(m.r_k,2)} · Na ${fx(m.r_na,2)}`}}},
      {titulo:'Capacidad de intercambio catiónico (CIC)', analisis:['Capacidad de intercambio catiónico (CIC)'], cols:[{k:'peso',label:'Peso (g)',d:2},{k:'blk',label:'mL blanco',d:2,fm:'Fila Blanco'},{k:'ml',label:'mL muestra',d:2},{k:'cic',label:'meq/100 g',d:2,res:true,fm:'(mL muestra − mL blanco) × N × 1000 ÷ peso'}],
        principal:{'Capacidad de intercambio catiónico (CIC)':{k:'cic',d:2}}}]
  },
  /* Hoja independiente de CIC; conserva compatibilidad con sus capturas anteriores. */
  'Suelo|Capacidad de intercambio catiónico (CIC)': {
    titulo:'Capacidad de intercambio catiónico (CIC)', analisis:['Capacidad de intercambio catiónico (CIC)'],
    encabezado:{titulo:'Parámetros de la corrida', campos:[{k:'n',label:'Normalidad del titulante',unidad:'N',def:'0.0298039'}]},
    controles:[{id:'BLANCO',label:'Blanco',sin:['peso']},{id:'CONTROL',label:'Control'}],
    cols:[{k:'peso',label:'Peso (g)'},{k:'ml',label:'mL titulante'}],
    calc:(f,ctx)=>{ const b=num(ctx.blk.ml), ml=num(f.ml), p=num(f.peso), N=num(ctx.enc.n);
      return {blk:b, cic:(ml!=null&&b!=null&&p&&N!=null)?(ml-b)*N*1000/p:null}; },
    info:ctx=>[['mL del blanco', fx(num(ctx.blk.ml),2)]],
    formula:'CIC (meq/100 g) = (mL muestra − mL blanco) × N × 1000 ÷ peso',
    vistas:[{analisis:['Capacidad de intercambio catiónico (CIC)'], cols:[{k:'peso',label:'Peso (g)',d:2},{k:'blk',label:'mL blanco',d:2,fm:'Fila Blanco'},{k:'ml',label:'mL muestra',d:2},{k:'cic',label:'meq/100 g',d:2,res:true,fm:'(mL muestra − mL blanco) × N × 1000 ÷ peso'}],
      principal:{'Capacidad de intercambio catiónico (CIC)':{k:'cic',d:2}}}]
  },
  'Suelo|Micronutrientes': hojaAA({titulo:'Micronutrientes', analisis:'Micronutrientes', modo:'ppm', volDef:'25', unidad:'ppm', pesoEnValidacion:true,
    elementos:[{k:'cu',sym:'Cu'},{k:'zn',sym:'Zn'},{k:'fe',sym:'Fe'},{k:'mn',sym:'Mn'}], inLabel:e=>'Conc. '+e.sym,
    formula:'ppm = (concentración − blk) × volumen ÷ peso · K es opcional',
    referencia:{titulo:'Patrones — puntos de la curva (referencia)', filas:[['Patrón','Cu – Zn','Fe – Mn'],['# 01','0.5 – 0.5','3.0 – 6.0'],['# 02','1.5 – 1.5','6.0 – 12.0'],['# 03','3.00 – 3.0','12.0 – 18.0']]}}),
  'Suelo|Humedad gravimétrica': {
    titulo:'Humedad gravimétrica', analisis:['Humedad gravimétrica'], controles:[],
    cols:[{k:'nocaja',label:'No. caja',tipo:'txt'},{k:'caja',label:'Peso caja (g)'},{k:'cajaH',label:'Peso caja + muestra húmeda (g)'},{k:'cajaS',label:'Peso caja + muestra seca (g)'}],
    calc:f=>{
      const c=num(f.caja), h=num(f.cajaH), s=num(f.cajaS);
      const seco=(s!=null&&c!=null)?s-c:null, humedo=(h!=null&&c!=null)?h-c:null;
      return {seco, humedo, pct:(seco&&humedo!=null)?(humedo-seco)/seco*100:null};
    },
    formula:'% H. grav. = (peso húmedo − peso seco) ÷ peso seco × 100',
    vistas:[{analisis:['Humedad gravimétrica'], cols:[{k:'nocaja',label:'No. caja'},{k:'caja',label:'Peso caja',d:2},{k:'cajaH',label:'Peso caja + muestra húmeda (g)',d:2},{k:'cajaS',label:'Peso caja + muestra seca (g)',d:2},{k:'seco',label:'Peso seco (g)',d:2,fm:'Caja + m. seca − caja'},{k:'pct',label:'% H. grav.',d:2,res:true,fm:'(Caja + m. húmeda − caja + m. seca) × 100 ÷ peso seco'},{k:'humedo',label:'Peso húmedo',d:2,fm:'Caja + m. húmeda − caja'}], principal:{'Humedad gravimétrica':{k:'pct',d:2}}}]
  },
  'Suelo|Humedad residual': {
    titulo:'% Humedad residual', analisis:['Humedad residual'], controles:[{id:'CONTROL',label:'Control'}],
    cols:[{k:'caja',label:'Peso de caja (g)'},{k:'m',label:'Peso muestra húmeda (g)'},
      {k:'cajaH',label:'Peso caja + muestra húmeda (g)',auto:f=>{const c=num(f.caja),m=num(f.m);return (c!=null&&m!=null)?c+m:null;}},
      {k:'cajaS',label:'Peso caja + muestra seca (g)'}],
    calc:f=>{ const c=num(f.caja), m=num(f.m), s=num(f.cajaS); const h=(c!=null&&m!=null)?c+m:null; return {cajaH:h, pct:(h!=null&&s!=null&&c>=0&&m>0&&s>c&&s<=h+1e-9)?(h-s)*100/m:null}; },
    formula:'HR = (caja + muestra húmeda − caja + muestra seca) × 100 ÷ peso muestra húmeda. Se muestra con 2 decimales y se conserva la precisión completa para Textura, como Excel.',
    vistas:[{analisis:['Humedad residual'], cols:[{k:'caja',label:'Peso de caja (g)',d:2},{k:'m',label:'Peso muestra húmeda (g)',d:2},{k:'cajaH',label:'Peso caja + muestra húmeda (g)',d:2,fm:'Peso de caja + peso muestra húmeda'},{k:'cajaS',label:'Peso caja + muestra seca (g)',d:2},{k:'pct',label:'% Humedad residual',d:2,res:true,fm:'(Caja + m. húmeda − caja + m. seca) × 100 ÷ peso muestra húmeda'}],
      principal:{'Humedad residual':{k:'pct',d:2}}}]
  },
  'Suelo|Densidad aparente': {
    titulo:'Densidad aparente', analisis:['Densidad aparente'], controles:[{id:'CONTROL',label:'Control'}],
    cols:[{k:'ncaja',label:'# Caja',tipo:'txt',opc:true},{k:'vi',label:'Volumen inicial',opc:true},{k:'vf',label:'Volumen final'},{k:'caja',label:'Peso de caja (g)'},{k:'cajaH',label:'Peso caja + M. húmeda (g)',opc:true},{k:'cajaS',label:'Peso caja + M. seca (g)'}],
    calc:f=>{ const c=num(f.caja), s=num(f.cajaS), vf=num(f.vf); const seco=(s!=null&&c!=null)?s-c:null; return {seco, da:(seco!=null&&vf)?seco/vf:null}; },
    formula:'Da (g/cm³) = peso de suelo seco ÷ volumen final',
    vistas:[{analisis:['Densidad aparente'], cols:[{k:'caja',label:'Peso de caja',d:2},{k:'cajaS',label:'Peso de caja + muestra seca',d:2},{k:'seco',label:'Peso de suelo seco',d:2,fm:'Caja + m. seca − caja'},{k:'vf',label:'Volumen final',d:0},{k:'da',label:'Da (g/cm³)',d:2,res:true,fm:'Peso de suelo seco ÷ volumen final'}], principal:{'Densidad aparente':{k:'da',d:2}}}]
  },
  'Suelo|CC y PMP': (function(){
    const P='Punto de marchitez permanente (PMP)', C='Capacidad de campo (CC)';
    const bloque=(pre,g,an)=>[
      {k:pre+'_caja',label:'Peso de caja',g,analisis:an},
      {k:pre+'_h',label:'Peso de caja + muestra húmeda',g,analisis:an},
      {k:pre+'_m',label:'Peso de muestra',g,analisis:an,auto:f=>{const h=num(f[pre+'_h']),c=num(f[pre+'_caja']);return (h!=null&&c!=null)?h-c:null;}},
      {k:pre+'_s',label:'Peso de caja + muestra seca',g,analisis:an}];
    return {
      titulo:'Capacidad de campo y punto de marchitez permanente', analisis:[P,C], controles:[{id:'CONTROL',label:'Control'}],
      cols:bloque('pmp','Punto de marchitez permanente',P).concat([{k:'olla',label:'Olla (P o G)',g:'Punto de marchitez permanente',analisis:P,tipo:'sel',opts:['','P','G'],opc:true}]).concat(bloque('cc','Capacidad de campo',C)),
      calc:f=>{
        const o={};
        ['pmp','cc'].forEach(pre=>{
          const c=num(f[pre+'_caja']), h=num(f[pre+'_h']), s=num(f[pre+'_s']);
          const m=(h!=null&&c!=null)?h-c:null;
          const hum=(m&&s!=null)?(h-s)/m*100:null;
          o[pre+'_m']=m; o[pre+'_hum']=hum; o[pre+'_ss']=hum!=null?100-hum:null;
          o[pre+'_pct']=(hum!=null&&o[pre+'_ss'])?hum/o[pre+'_ss']*100:null;
        });
        return o;
      },
      formula:'% humedad = (caja+húmeda − caja+seca) ÷ peso muestra × 100 · % PMP / % CC = % humedad ÷ % suelo seco × 100',
      vistas:[
        {titulo:'Punto de marchitez permanente (PMP)', analisis:[P], cols:[
          {k:'pmp_caja',label:'Peso de caja',d:2,g:'Punto de marchitez permanente'},{k:'pmp_h',label:'Caja + m. húmeda',d:2,g:'Punto de marchitez permanente'},{k:'pmp_m',label:'Peso de muestra',d:2,g:'Punto de marchitez permanente',fm:'Caja + m. húmeda − caja'},{k:'pmp_s',label:'Caja + m. seca',d:2,g:'Punto de marchitez permanente'},{k:'pmp_hum',label:'% Humedad',d:2,g:'Punto de marchitez permanente',fm:'(Caja + m. húmeda − caja + m. seca) × 100 ÷ peso muestra'},{k:'pmp_ss',label:'% Suelo seco',d:2,g:'Punto de marchitez permanente',fm:'100 − % humedad'},{k:'pmp_pct',label:'% PMP',d:2,g:'Punto de marchitez permanente',res:true,fm:'% humedad ÷ % suelo seco × 100'},{k:'olla',label:'Olla P o G',g:'Punto de marchitez permanente'}],
          principal:{[P]:{k:'pmp_pct',d:2}}},
        {titulo:'Capacidad de campo (CC)', analisis:[C], cols:[
          {k:'cc_caja',label:'Peso de caja',d:2,g:'Capacidad de campo'},{k:'cc_h',label:'Caja + m. húmeda',d:2,g:'Capacidad de campo'},{k:'cc_m',label:'Peso de muestra',d:2,g:'Capacidad de campo',fm:'Caja + m. húmeda − caja'},{k:'cc_s',label:'Caja + m. seca',d:2,g:'Capacidad de campo'},{k:'cc_hum',label:'% Humedad',d:2,g:'Capacidad de campo',fm:'(Caja + m. húmeda − caja + m. seca) × 100 ÷ peso muestra'},{k:'cc_ss',label:'% Suelo seco',d:2,g:'Capacidad de campo',fm:'100 − % humedad'},{k:'cc_pct',label:'% CC',d:2,g:'Capacidad de campo',res:true,fm:'% humedad ÷ % suelo seco × 100'}],
          principal:{[C]:{k:'cc_pct',d:2}}}]
    };
  })(),
  'Suelo|Textura': {
    titulo:'Textura (Bouyoucos)', analisis:['Textura'],
    encabezado:{titulo:'Parámetros de la corrida', campos:[{k:'peso',label:'Peso de muestra (g)',def:'50',soloVal:true}]},
    controles:[{id:'CONTROL',label:'Control'}],
    cols:[{k:'hr',label:'% H.R.',vinculo:'Humedad residual'},{k:'l1',label:'Lectura hidrómetro 40 s'},{k:'t1',label:'Temperatura (°F)'},{k:'l2',label:'Lectura hidrómetro 2 h'},{k:'t2',label:'Temperatura (°F)'}],
    calc:(f,ctx)=>{
      const hr=num(f.hr), p=num(ctx.enc.peso??50), l1=num(f.l1), t1=num(f.t1), l2=num(f.l2), t2=num(f.t2);
      const factor=(hr!=null&&p===50&&hr>=0&&hr<100)?200/(100-hr):null;
      const lc1=(l1!=null&&t1!=null)?l1+0.2*(t1-60):null, lc2=(l2!=null&&t2!=null)?l2+0.2*(t2-60):null;
      const la=(lc1!=null&&factor)?lc1*factor:null, arcilla=(lc2!=null&&factor)?lc2*factor:null;
      const arena=la!=null?100-la:null, limo=(la!=null&&arcilla!=null)?la-arcilla:null;
      const claseAutomatica = claseTextural(arena,limo,arcilla);
      const clase = claseAutomatica&&Object.hasOwn(TEXTURA_USDA,f.claseManual)?f.claseManual:claseAutomatica;
      return {factor, lc1, lc2, la, arcilla, arena, limo, total:(arena!=null&&limo!=null)?arena+limo+arcilla:null, clase, claseAutomatica, claseOrigen:claseAutomatica&&Object.hasOwn(TEXTURA_USDA,f.claseManual)?'Corrección técnica':'Automática', usda:TEXTURA_USDA[clase]||null,
        hrOrigen: f.hr==null||f.hr==='' ? null : 'Humedad residual'};
    },
    formula:'Método Excel (50 g): factor = 200 ÷ (100 − HR); lectura corregida = lectura + (T °F − 60) × 0.2; arcilla = 2ª corregida × factor; limo = 1ª corregida × factor − arcilla; arena = 100 − 1ª corregida × factor. HR se toma del análisis previo sin redondeos intermedios; se muestran 2 decimales.',
    vistas:[{analisis:['Textura'], cols:[
      {k:'hr',label:'% H.R.',d:2},{k:'hrOrigen',label:'Origen % H.R.'},
      {k:'factor',label:'Factor',d:4,fm:'200 ÷ (100 − % H.R.)'},
      {k:'l1',label:'1ª lec.',d:2},{k:'t1',label:'1ª T °F',d:1},
      {k:'lc1',label:'Lec. cor.',d:2,fm:'(1ª T °F − 60) × 0.2 + 1ª lec.'},
      {k:'la',label:'% L/A',d:2,fm:'Lec. cor. × factor'},
      {k:'l2',label:'2ª lec.',d:2},{k:'t2',label:'2ª T °F',d:1},
      {k:'lc2',label:'Lec. cor.',d:2,fm:'(2ª T °F − 60) × 0.2 + 2ª lec.'},
      {k:'total',label:'Total',d:0,fm:'% arcilla + % limo + % arena'},
      {k:'arcilla',label:'% Arcilla',d:2,res:true,fm:'Lec. cor. 2 h × factor'},
      {k:'limo',label:'% Limo',d:2,res:true,fm:'% L/A − % arcilla'},
      {k:'arena',label:'% Arena',d:2,res:true,fm:'100 − % L/A'},
      {k:'clase',label:'Textura',res:true,fm:'Triángulo textural USDA'}],
      principal:{'Textura':{k:null, texto:m=>m.clase?`${m.clase} (Ar ${fx(m.arena,1)} · L ${fx(m.limo,1)} · Arc ${fx(m.arcilla,1)} %)`:'—'}}}]
  },
  /* ---------- Foliares ---------- */
  'Foliar|Nitrógeno': hojaKjeldahl({titulo:'Nitrógeno foliar', analisis:'Nitrógeno'}),
  'Foliar|Fósforo': curvaFosforo('Fósforo foliar','Fósforo','0.01','% P',3),
  'Foliar|Boro': curvaBoro('Boro foliar','Boro','ppm B'),
  'Foliar|Macronutrientes': hojaAA({titulo:'Macronutrientes foliares', analisis:'Macronutrientes', modo:'pct', volDef:'50', unidad:'%', pesoEnValidacion:true, dRes:3,
    elementos:[{k:'ca',sym:'Ca'},{k:'mg',sym:'Mg'},{k:'k',sym:'K'}], inLabel:e=>'Conc. '+e.sym, formula:'% = (concentración − blk) × volumen ÷ peso ÷ 10 000'}),
  'Foliar|Micronutrientes': hojaAA({titulo:'Micronutrientes foliares', analisis:'Micronutrientes', modo:'ppm', volDef:'50', unidad:'ppm', pesoEnValidacion:true,
    elementos:[{k:'cu',sym:'Cu'},{k:'zn',sym:'Zn'},{k:'fe',sym:'Fe'},{k:'mn',sym:'Mn'}], inLabel:e=>'Conc. '+e.sym, formula:'ppm = (concentración − blk) × volumen ÷ peso'}),
};
/* análisis solicitables que se capturan dentro de otra hoja (misma corrida) */
const HOJA_ALIAS = {
  'Suelo|Conductividad eléctrica':'Suelo|pH',
  'Suelo|Macronutrientes':'Suelo|Macronutrientes (Ca, Mg, K, Na)',
};

/* ============================================================
   AGUAS · FOLIARES · CAÑA — hojas de trabajo según las hojas de
   cálculo del laboratorio (Cálculo Químico Aguas 2026 y Cálculo
   Foliares 2026). El analista llena las columnas de lectura; en
   Validación técnica se ven esas lecturas más los cálculos.
   ============================================================ */
function regLineal(puntos){
  const p = (puntos||[]).map(q=>({x:num(q.c), y:num(q.a)})).filter(q=>q.x!=null && q.y!=null);
  if(p.length<2) return null;
  const n=p.length; let sx=0,sy=0,sxx=0,sxy=0; p.forEach(q=>{ sx+=q.x; sy+=q.y; sxx+=q.x*q.x; sxy+=q.x*q.y; });
  const den = n*sxx-sx*sx; if(!den) return null;
  const m=(n*sxy-sx*sy)/den, b=(sy-m*sx)/n; return {m,b};
}
/* colorimetría con peso de muestra (foliares): resultado = (Abs − blanco) ÷ m × factor ÷ peso */
function curvaPeso(o){
  return {
    titulo:o.titulo, analisis:[o.analisis],
    encabezado:{titulo:'Parámetros de la corrida', campos:[{k:'pend',label:'Pendiente de la curva (m) — vacío = calculada',def:''},{k:'factor',label:o.factorLabel||'Factor',def:o.factorDef}]},
    controles:[{id:'BLANCO',label:'Blanco',sin:['peso']},{id:'CONTROL',label:'Control'}],
    cols:(o.crisol?[{k:'crisol',label:'# Crisol',tipo:'txt',opc:true}]:[]).concat([{k:'peso',label:'Peso de muestra (g)', vinculo:o.vinculoPeso||undefined},{k:'abs',label:o.absLabel||'Absorbancia'}]),
    curva:{def:o.curvaDef, unidadX:'ppm'},
    calc:(f,ctx)=>{ const abs=num(f.abs), blk=num(ctx.blk.abs)||0, p=num(f.peso), F=num(ctx.enc.factor);
      const m = num(ctx.enc.pend) || (ctx.reg && ctx.reg.m0);
      const sol = (abs!=null && m) ? (abs-blk)/m : null;
      return {blk, sol, res:(sol!=null && p && F!=null) ? sol*F/p : null}; },
    info:ctx=>[['Absorbancia del blanco', fx(num(ctx.blk.abs),3)], ['Pendiente usada', num(ctx.enc.pend) ? fx(num(ctx.enc.pend),4)+' (digitada)' : ctx.reg ? fx(ctx.reg.m0,4)+' (de la curva)' : '—'], ['R² de la curva', ctx.reg?fx(ctx.reg.r2,4):'—']],
    formula:o.formula,
    vistas:[{analisis:[o.analisis], grafico:true,
      cols:[{k:'peso',label:'Peso de muestra',d:4},{k:'blk',label:'Abs. blanco',d:3,fm:'Fila Blanco'},{k:'abs',label:'Absorbancia',d:3},{k:'sol',label:o.solLabel,d:3,fm:'(Abs − blanco) ÷ m'},{k:'res',label:o.resLabel,d:o.dRes,res:true,fm:o.fmRes}],
      principal:{[o.analisis]:{k:'res',d:o.dRes}}}]
  };
}
/* absorción atómica en aguas: mg/L = (µg/mL − blanco) × factor de dilución (vacío = 1) */
function aaAgua(titulo, analisis, els, d, extra){
  const vcols = [{k:'fd',label:'Factor de dilución',d:0}]
    .concat(els.map(e=>({k:e.k,label:e.s+' µg/mL',d:3,g:'Lectura'})))
    .concat(els.map(e=>({k:'blk_'+e.k,label:'Blanco '+e.s,d:3,g:'Blanco',fm:'Fila Blanco'})))
    .concat(els.map(e=>({k:'r_'+e.k,label:e.s+' mg/L',d,g:'Resultado',res:true,fm:'(µg/mL − blanco) × factor'})));
  const h = {
    titulo, analisis:[analisis].concat(extra?extra.analisis:[]),
    controles:[{id:'BLANCO',label:'Blanco',sin:['fd']}],
    cols:[{k:'fd',label:'Factor de dilución',opc:true}].concat(els.map(e=>({k:e.k,label:e.s+' (µg/mL)'}))),
    calc:(f,ctx)=>{ const o={}, fd=num(f.fd)||1;
      els.forEach(e=>{ const c=num(f[e.k]), b=num(ctx.blk[e.k])||0; o['blk_'+e.k]=b; o['r_'+e.k]= c!=null ? (c-b)*fd : null; });
      return extra ? Object.assign(o, extra.calc(o)) : o; },
    info:ctx=>els.map(e=>['Blanco '+e.s, fx(num(ctx.blk[e.k]),3)]),
    formula:'mg/L = (lectura µg/mL − blanco) × factor de dilución (vacío = 1)',
    vistas:[{titulo, analisis:[analisis], cols:vcols,
      principal:{[analisis]:{k:null, texto:m=>els.map(e=>e.s+' '+fx(m['r_'+e.k],d)).join(' · ')}}}].concat(extra?[extra.vista]:[])
  };
  return h;
}
Object.assign(HOJAS, {
  /* ---------------- AGUAS ---------------- */
  'Agua|Lecturas': {
    titulo:'Parámetros fisicoquímicos · Parte 1 (pH, CE, TDS, salinidad, resistividad)',
    analisis:['pH','Conductividad eléctrica','Sólidos totales disueltos','Salinidad','Resistividad'],
    encabezado:{titulo:'Parámetros de la corrida', campos:[{k:'fce',label:'Factor de temperatura CE',def:'0.9985',editableVal:true}]},
    controles:[],
    cols:[{k:'tph',label:'°C muestra',opc:true,opcTxt:false,analisis:'pH'},{k:'ph',label:'pH (Un)',analisis:'pH'},{k:'tce',label:'°C muestra',opc:true,opcTxt:false,analisis:'Conductividad eléctrica'},{k:'ce',label:'Conductividad (µS/cm)',analisis:'Conductividad eléctrica'},
      {k:'tdsl',label:'TDS (mg/L)',analisis:'Sólidos totales disueltos'},{k:'sal',label:'Salinidad (PSU)',analisis:'Salinidad'},{k:'rk',label:'Resistividad (Kohm-cm)',analisis:'Resistividad'}],
    calc:(f,ctx)=>{ const ce=num(f.ce), fce=num(ctx.enc.fce); return {fce, ceDs:(ce!=null&&fce!=null)?ce*fce/1000:null, tds:num(f.tdsl)}; },
    formula:'CE (dS/m) = lectura × factor de temperatura CE (parámetros de la corrida) ÷ 1000 · Sólidos totales (mg/L) = lectura (ppm) · pH, salinidad y resistividad: lectura directa',
    vistas:[
      {titulo:'pH', analisis:['pH'], cols:[{k:'tph',label:'°C muestra',d:1},{k:'ph',label:'pH (Un)',d:2,res:true}], principal:{'pH':{k:'ph',d:2}}},
      {titulo:'Conductividad eléctrica', analisis:['Conductividad eléctrica'], cols:[{k:'tce',label:'°C muestra',d:1},{k:'ce',label:'Lectura conductividad (µS/cm)',d:1},{k:'fce',label:'Factor temp. CE',d:4,fm:'Parámetros de la corrida'},{k:'ceDs',label:'CE (dS m⁻¹)',d:3,res:true,fm:'Lectura × factor ÷ 1000'}], principal:{'Conductividad eléctrica':{k:'ceDs',d:3}}},
      {titulo:'Sólidos totales disueltos', analisis:['Sólidos totales disueltos'], cols:[{k:'tdsl',label:'Lectura (ppm)',d:2},{k:'tds',label:'TDS (mg/L)',d:2,res:true,fm:'= lectura'}], principal:{'Sólidos totales disueltos':{k:'tds',d:2}}},
      {titulo:'Salinidad', analisis:['Salinidad'], cols:[{k:'sal',label:'Lectura (PSU)',d:3,res:true}], principal:{'Salinidad':{k:'sal',d:3}}},
      {titulo:'Resistividad', analisis:['Resistividad'], cols:[{k:'rk',label:'Lectura (kΩ·cm)',d:3,res:true}], principal:{'Resistividad':{k:'rk',d:3}}},
    ]
  },
  'Agua|Titulaciones': {
    titulo:'Parámetros fisicoquímicos · Parte 2 (alcalinidad, carbonatos, bicarbonatos, dureza y cloruros)',
    analisis:['Alcalinidad total','Carbonatos','Bicarbonatos','Dureza','Cloruros'],
    /* Cálculo de "Cálculo Químico Aguas 2026" (hojas Alcalinidad Total, Carbonatos, Bicarbonatos, Dureza y Cloruros):
       el analista captura los mL gastados en cada titulación; el sistema calcula los mg/L.
       Columnas en el mismo orden que el formato en papel VF 001 "Registro de Parámetros Fisicoquímicos del
       Agua (Parte 2)": Alcalinidad · Carbonatos · Bicarbonatos · Dureza · Cloruros (blanco y muestra).
       Bicarbonatos usa su propia lectura de H₂SO₄ (Tb) y la de carbonatos (P): (Tb − 2P) × N × 50 000 ÷ mL. */
    /* Parámetros del método: no están en el formato VF 001, así que el analista no los captura (soloVal).
       Se guardan con su valor por defecto (los del Excel) y el técnico los confirma o corrige en Validación
       técnica (editableVal; se permiten en LAB_VALIDACION_ENCABEZADO_EDITABLE). cero: admite 0. */
    encabezado:{titulo:'Reactivos y volúmenes del método', campos:[
      {k:'nh',label:'Normalidad del H₂SO₄',unidad:'N',def:'0.02',soloVal:true,editableVal:true},
      {k:'va',label:'mL de muestra (alcalinidad, carbonatos y bicarbonatos)',def:'100',soloVal:true,editableVal:true},
      {k:'ve',label:'mL de muestra (dureza)',def:'50',soloVal:true,editableVal:true},
      {k:'be',label:'mL de EDTA del blanco (dureza)',def:'0',soloVal:true,editableVal:true,cero:true},
      {k:'fe',label:'Factor del EDTA (mg CaCO₃ por mL)',def:'1',soloVal:true,editableVal:true},
      {k:'na',label:'Normalidad del AgNO₃',unidad:'N',def:'0.0141',soloVal:true,editableVal:true},
      {k:'vc',label:'mL de muestra (cloruros)',def:'100',soloVal:true,editableVal:true}]},
    controles:[],
    cols:[{k:'alcT',label:'Alcalinidad (mL H₂SO₄)',analisis:'Alcalinidad total'},
      {k:'alcP',label:'Carbonatos (mL H₂SO₄)',analisis:['Carbonatos','Bicarbonatos']},
      {k:'bicT',label:'Bicarbonatos (mL H₂SO₄)',analisis:'Bicarbonatos'},
      {k:'edta',label:'Dureza (mL EDTA)',analisis:'Dureza'},
      {k:'clb',label:'Blanco (mL AgNO₃)',analisis:'Cloruros',g:'Cloruros'},{k:'clm',label:'Muestra (mL AgNO₃)',analisis:'Cloruros',g:'Cloruros'}],
    calc:(f,ctx)=>{
      const e=ctx.enc, nh=num(e.nh), va=num(e.va), ve=num(e.ve), be=num(e.be)??0, fe=num(e.fe), na=num(e.na), vc=num(e.vc);
      const T=num(f.alcT), P=num(f.alcP), Tb=num(f.bicT), ed=num(f.edta), cb=num(f.clb), cm=num(f.clm);
      const kH=(nh!=null&&va)?nh*50000/va:null;
      const r={
        alc: (T!=null&&kH!=null)?T*kH:null,                       // (T × N × 50 000) ÷ mL muestra
        co3: (P!=null&&kH!=null)?2*P*kH:null,                     // 2 × P × N × 50 000 ÷ mL muestra
        hco3:(Tb!=null&&P!=null&&kH!=null)?(Tb-2*P)*kH:null,      // (Tb − 2P) × N × 50 000 ÷ mL muestra
        dur: (ed!=null&&fe!=null&&ve)?(ed-be)*fe*1000/ve:null,    // (EDTA − blanco) × factor × 1000 ÷ mL muestra
        cl:  (cm!=null&&cb!=null&&na!=null&&vc)?(cm-cb)*na*35450/vc:null};
      // Hojas enviadas con el formato anterior (resultado directo en mg/L): se conservan sus valores.
      if(f.alcT==null&&f.alcR!=null)r.alc=num(f.alcR);
      if(f.alcP==null&&f.co3!=null)r.co3=num(f.co3);
      if(f.bicT==null&&f.hco3!=null)r.hco3=num(f.hco3);
      if(f.edta==null&&f.dur!=null)r.dur=num(f.dur);
      return r; },
    info:ctx=>{ const e=ctx.enc, nh=num(e.nh), va=num(e.va), ve=num(e.ve), fe=num(e.fe), na=num(e.na), vc=num(e.vc);
      return [['Factor H₂SO₄ = N × 50 000 ÷ mL muestra', (nh!=null&&va)?fx(nh*50000/va,4)+' mg/L por mL':'—'],
        ['Factor EDTA = factor × 1000 ÷ mL muestra', (fe!=null&&ve)?fx(fe*1000/ve,4)+' mg/L por mL':'—'],
        ['Factor AgNO₃ = N × 35 450 ÷ mL muestra', (na!=null&&vc)?fx(na*35450/vc,4)+' mg/L por mL':'—']]; },
    formula:'Alcalinidad = mL H₂SO₄ × N × 50 000 ÷ mL muestra · Carbonatos = 2 × P × N × 50 000 ÷ mL · Bicarbonatos = (mL H₂SO₄ bicarbonatos − 2 × P) × N × 50 000 ÷ mL · Dureza = (mL EDTA − blanco) × factor × 1000 ÷ mL · Cloruros = (muestra − blanco) × N AgNO₃ × 35 450 ÷ mL',
    vistas:[
      {titulo:'Alcalinidad', analisis:['Alcalinidad total'], cols:[{k:'alcT',label:'mL H₂SO₄',d:2},{k:'alc',label:'Alcalinidad (mg/L CaCO₃)',d:1,res:true,fm:'mL × N × 50 000 ÷ mL muestra'}], principal:{'Alcalinidad total':{k:'alc',d:1}}},
      {titulo:'Carbonatos', analisis:['Carbonatos'], cols:[{k:'alcP',label:'mL H₂SO₄ (P)',d:2},{k:'co3',label:'Carbonatos (mg/L)',d:1,res:true,fm:'2 × P × N × 50 000 ÷ mL muestra'}], principal:{'Carbonatos':{k:'co3',d:1}}},
      {titulo:'Bicarbonatos', analisis:['Bicarbonatos'], cols:[{k:'bicT',label:'mL H₂SO₄',d:2},{k:'alcP',label:'mL H₂SO₄ carbonatos (P)',d:2},{k:'hco3',label:'Bicarbonatos (mg/L)',d:1,res:true,fm:'(mL − 2 × P) × N × 50 000 ÷ mL muestra'}], principal:{'Bicarbonatos':{k:'hco3',d:1}}},
      {titulo:'Dureza', analisis:['Dureza'], cols:[{k:'edta',label:'mL EDTA',d:2},{k:'dur',label:'Dureza (mg/L CaCO₃)',d:1,res:true,fm:'(EDTA − blanco) × factor × 1000 ÷ mL muestra'}], principal:{'Dureza':{k:'dur',d:1}}},
      {titulo:'Cloruros', analisis:['Cloruros'], cols:[{k:'clb',label:'Blanco (mL AgNO₃)',d:2},{k:'clm',label:'Muestra (mL AgNO₃)',d:2},{k:'cl',label:'Cloruros (mg/L)',d:2,res:true,fm:'(muestra − blanco) × N × 35 450 ÷ mL muestra'}], principal:{'Cloruros':{k:'cl',d:2}}},
    ],
    /* Hojas enviadas antes del cálculo por titulación: el analista capturó directamente los mg/L
       (alcR, co3, hco3, dur). Se muestran tal cual se capturaron (cap:true = dato del analista). */
    legado:{
      es:cap=>Object.values(cap.filas||{}).some(f=>f&&f.alcR!=null&&f.alcT==null),
      aviso:'Hoja capturada con el formato anterior: alcalinidad, carbonatos, bicarbonatos y dureza se registraron directamente en mg/L (sin los mL de titulación).',
      vistas:[
        {titulo:'Alcalinidad total', analisis:['Alcalinidad total'], cols:[{k:'alcR',label:'Alcalinidad (mg/L CaCO₃) · capturado',d:1,res:true,cap:true}], principal:{'Alcalinidad total':{k:'alcR',d:1}}},
        {titulo:'Carbonatos', analisis:['Carbonatos'], cols:[{k:'co3',label:'Carbonatos (mg/L) · capturado',d:1,res:true,cap:true}], principal:{'Carbonatos':{k:'co3',d:1}}},
        {titulo:'Bicarbonatos', analisis:['Bicarbonatos'], cols:[{k:'hco3',label:'Bicarbonatos (mg/L) · capturado',d:1,res:true,cap:true}], principal:{'Bicarbonatos':{k:'hco3',d:1}}},
        {titulo:'Dureza', analisis:['Dureza'], cols:[{k:'dur',label:'Dureza (mg/L) · capturado',d:0,res:true,cap:true}], principal:{'Dureza':{k:'dur',d:0}}},
        {titulo:'Cloruros', analisis:['Cloruros'], cols:[{k:'clb',label:'mL AgNO₃ blanco',d:2},{k:'clm',label:'mL AgNO₃ muestra',d:2},{k:'cl',label:'Cloruros (mg/L)',d:2,res:true,fm:'(muestra − blanco) × N × 35 450 ÷ mL muestra'}], principal:{'Cloruros':{k:'cl',d:2}}},
      ]
    }
  },
  'Agua|Macronutrientes': aaAgua('Macronutrientes en aguas (Ca, Mg, K, Na)', 'Macronutrientes', [{k:'ca',s:'Ca'},{k:'mg',s:'Mg'},{k:'k',s:'K'},{k:'na',s:'Na'}], 3, {
    analisis:['Razón de adsorción de sodio (RAS)'],
    calc:o=>{ const na=o.r_na, ca=o.r_ca, mg=o.r_mg; const nam=na!=null?na/22.99:null, cam=ca!=null?ca/20.04:null, mgm=mg!=null?mg/12.16:null;
      return {na_meq:nam, ca_meq:cam, mg_meq:mgm, ras:(nam!=null&&cam!=null&&mgm!=null&&(cam+mgm)>0)?nam/Math.sqrt((cam+mgm)/2):null}; },
    vista:{titulo:'Razón de adsorción de sodio (RAS)', analisis:['Razón de adsorción de sodio (RAS)'], cols:[
      {k:'r_na',label:'Na (mg/L)',d:3},{k:'r_ca',label:'Ca (mg/L)',d:3},{k:'r_mg',label:'Mg (mg/L)',d:3},
      {k:'na_meq',label:'Na (meq/L)',d:4,fm:'Na ÷ 22.99'},{k:'ca_meq',label:'Ca (meq/L)',d:4,fm:'Ca ÷ 20.04'},{k:'mg_meq',label:'Mg (meq/L)',d:4,fm:'Mg ÷ 12.16'},
      {k:'ras',label:'RAS (Un)',d:2,res:true,fm:'Na ÷ √((Ca + Mg) ÷ 2)'}], principal:{'Razón de adsorción de sodio (RAS)':{k:'ras',d:2}}}
  }),
  'Agua|Micronutrientes': aaAgua('Micronutrientes en aguas (Cu, Zn, Fe, Mn)', 'Micronutrientes', [{k:'cu',s:'Cu'},{k:'zn',s:'Zn'},{k:'fe',s:'Fe'},{k:'mn',s:'Mn'}], 3),
  'Agua|Fósforo': Object.assign(curvaFosforo('Fósforo en aguas · Parte 3','Fósforo','5','P (mg/L)',3), {controles:[{id:'BLANCO',label:'Blanco'}], curva:{def:[2,2,4,4,8,8,16,16,32,32], unidadX:'mg/L'}}),
  'Agua|Boro': {
    titulo:'Boro en aguas · Parte 3', analisis:['Boro'],
    encabezado:{titulo:'Curva de calibración', campos:[{k:'pend',label:'Pendiente (m) — vacío = calculada',def:''},{k:'inter',label:'Intercepto (b) — vacío = calculado',def:''}]},
    controles:[{id:'BLANCO',label:'Blanco'}],
    cols:[{k:'abs',label:'Absorbancia'}],
    curva:{def:[0,0,0.25,0.25,0.5,0.5,1,1,2,2], unidadX:'mg/L'},
    calc:(f,ctx,rowId)=>{ const abs=num(f.abs), blk=num(ctx.blk.abs)||0; const rl = ctx.cap ? null : null;
      const m = num(ctx.enc.pend) ?? (ctx.regL ? ctx.regL.m : null), b = num(ctx.enc.inter) ?? (ctx.regL ? ctx.regL.b : 0);
      return {blk, m, b, res:(abs!=null && m) ? ((abs-blk)-b)/m : null}; },
    info:ctx=>[['Pendiente usada', num(ctx.enc.pend)!=null ? fx(num(ctx.enc.pend),4)+' (digitada)' : ctx.regL ? fx(ctx.regL.m,4)+' (de la curva)' : '—'], ['Intercepto usado', num(ctx.enc.inter)!=null ? fx(num(ctx.enc.inter),5) : ctx.regL ? fx(ctx.regL.b,5) : '—']],
    formula:'Boro (mg/L) = ((Abs − blanco) − intercepto) ÷ pendiente',
    vistas:[{analisis:['Boro'], grafico:true, cols:[{k:'blk',label:'Abs. blanco',d:3,fm:'Fila Blanco'},{k:'abs',label:'Absorbancia',d:3},{k:'m',label:'Pendiente',d:4},{k:'b',label:'Intercepto',d:5},{k:'res',label:'Boro (mg/L)',d:3,res:true,fm:'((Abs − blanco) − b) ÷ m'}], principal:{'Boro':{k:'res',d:3}}}]
  },

  /* ---------------- FOLIARES ---------------- */
  'Foliar|Macronutrientes': {
    titulo:'Macronutrientes en tejido foliar (K, Ca, Mg)', analisis:['Macronutrientes'],
    // El factor no lo captura el analista (soloVal): toma 0.05 y el técnico lo confirma o corrige en Validación técnica.
    encabezado:{titulo:'Parámetros de la corrida', campos:[{k:'f',label:'Factor (volumen de dilución ÷ 1000)',def:'0.05',soloVal:true,editableVal:true}]},
    referencia:{titulo:'Patrones — puntos de la curva (referencia)', filas:[['Patrón','Ca – Mg – K'],['# 01','3.0 – 3.0 – 20.0'],['# 02','6.0 – 6.0 – 40.0'],['# 03','12.0 – 9.0 – 60.0']]},
    controles:[{id:'BLANCO',label:'Blanco',sin:['peso']},{id:'CONTROL',label:'Control'}],
    cols:[{k:'peso',label:'Peso (g)'},{k:'k',label:'K'},{k:'ca',label:'Ca'},{k:'mg',label:'Mg'}],
    calc:(f,ctx)=>{ const o={}, p=num(f.peso), F=num(ctx.enc.f); o.fac=F;
      ['k','ca','mg'].forEach(e2=>{ const c=num(f[e2]), b=num(ctx.blk[e2])||0; o['blk_'+e2]=b; o['r_'+e2]=(c!=null&&p&&F!=null)?(c-b)*F/p:null; }); return o; },
    info:ctx=>[['k','K'],['ca','Ca'],['mg','Mg']].map(([k2,s])=>['Blanco '+s, fx(num(ctx.blk[k2]),3)]),
    formula:'% = (lectura − blanco) × factor ÷ peso',
    // Validación técnica: resultados con 2 decimales y el factor de la corrida como parte del cálculo.
    vistas:[{analisis:['Macronutrientes'], cols:[{k:'peso',label:'Peso (g)',d:4}]
      .concat([['k','K'],['ca','Ca'],['mg','Mg']].map(([k2,s])=>({k:k2,label:'Conc. '+s,d:3,g:'Lectura'})))
      .concat([['k','K'],['ca','Ca'],['mg','Mg']].map(([k2,s])=>({k:'blk_'+k2,label:'Blk '+s,d:3,g:'Blanco',fm:'Fila Blanco'})))
      .concat([{k:'fac',label:'Factor',d:4,fm:'Parámetros de la corrida',cap:true}])
      .concat([['k','K'],['ca','Ca'],['mg','Mg']].map(([k2,s])=>({k:'r_'+k2,label:'% '+s,d:2,g:'Resultado',res:true,fm:'(conc − blk) × factor ÷ peso'}))),
      principal:{'Macronutrientes':{k:null, texto:m=>`K ${fx(m.r_k,3)} · Ca ${fx(m.r_ca,3)} · Mg ${fx(m.r_mg,3)} %`}}}]
  },
  'Foliar|Fósforo': curvaPeso({titulo:'Fósforo en tejido foliar', analisis:'Fósforo', absLabel:'Absorbancia 660 nm', vinculoPeso:{hoja:'Foliar|Macronutrientes', campo:'peso', nombre:'Macronutrientes'}, factorDef:'0.005', factorLabel:'Factor (volumen ÷ 1000 ÷ 10)', curvaDef:[2,2,4,4,8,8,16,16,32,32], solLabel:'ppm P en solución', resLabel:'% P muestra', dRes:3, fmRes:'ppm sol. × 0.005 ÷ peso', formula:'ppm P sol. = (Abs − blanco) ÷ pendiente · % P = ppm sol. × 0.005 ÷ peso'}),
  'Foliar|Boro': curvaPeso({titulo:'Boro en tejido foliar', analisis:'Boro', absLabel:'Absorbancia 420 nm', vinculoPeso:{hoja:'Foliar|Micronutrientes', campo:'peso', nombre:'Micronutrientes'}, crisol:true, factorDef:'50', factorLabel:'Volumen de dilución (mL)', curvaDef:[0,0,0.25,0.25,0.5,0.5,1,1,2,2], solLabel:'ppm B en solución', resLabel:'ppm B muestra', dRes:2, fmRes:'ppm sol. × 50 ÷ peso', formula:'ppm B sol. = (Abs − blanco) ÷ pendiente · ppm B muestra = ppm sol. × 50 ÷ peso'}),
  'Foliar|Humedad': {
    titulo:'Humedad foliar', analisis:['Humedad'], controles:[],
    cols:[{k:'caja',label:'Peso caja (g)'},{k:'cajaH',label:'Peso caja + muestra húmeda (g)'},{k:'cajaS',label:'Peso caja + muestra seca (g)'}],
    calc:f=>{ const c=num(f.caja), h=num(f.cajaH), s=num(f.cajaS); const hum=(h!=null&&c!=null)?h-c:null; return {humedo:hum, pct:(hum&&s!=null)?(h-s)/hum*100:null}; },
    formula:'% Humedad = (caja + húmeda − caja + seca) ÷ peso húmedo × 100 (base húmeda)',
    vistas:[{analisis:['Humedad'], cols:[{k:'caja',label:'Peso caja',d:2},{k:'cajaH',label:'Caja + m. húmeda',d:2},{k:'cajaS',label:'Caja + m. seca',d:2},{k:'humedo',label:'Peso húmedo',d:2,fm:'Caja + húmeda − caja'},{k:'pct',label:'% Humedad',d:2,res:true,fm:'(húmeda − seca) ÷ peso húmedo × 100'}], principal:{'Humedad':{k:'pct',d:2}}}]
  },

  /* ---------------- CAÑA ---------------- */
  'Caña|Jugo': {
    titulo:'Brix y Pol en jugo de caña', analisis:['Brix','Pol','Rendimiento'],
    /* Cálculo del Excel "Cálculo General Análisis de Caña" (SAL-LAG): el analista captura solo °Brix, Pol y
       peso de torta. Las constantes del método no las captura (soloVal): toman su valor por defecto y el
       técnico las confirma o corrige en Validación técnica (LAB_VALIDACION_ENCABEZADO_EDITABLE).
       Antes Pol % caña = Pol × Jugo % ÷ 100 y los rendimientos usaban factores de recuperación (0.85 / 0.90,
       × 20, × 22.046, × 0.4536) que el Excel no usa: los resultados no coincidían. */
    encabezado:{titulo:'Constantes del método', campos:[
      {k:'pm',label:'Peso de muestra de caña en prensa (g)',def:'500',soloVal:true,editableVal:true},
      {k:'fcom',label:'Factor rendimiento comercial (lb/t por % Pol)',def:'11.8',soloVal:true,editableVal:true},
      {k:'freal',label:'Factor rendimiento real (lb/t por % Pol)',def:'16.44',soloVal:true,editableVal:true}]},
    controles:[],
    // Dependencias: Pureza usa el °Brix (también si solo se pidió Pol) y el Rendimiento usa el Pol.
    cols:[{k:'brix',label:'°Brix',analisis:['Brix','Pol']},{k:'pol',label:'Pol',analisis:['Pol','Rendimiento']},{k:'torta',label:'Peso torta (g)',analisis:'Rendimiento'}],
    calc:(f,ctx)=>{ const b=num(f.brix), p=num(f.pol), t=num(f.torta), e=ctx.enc||{};
      const pm=num(e.pm)??500, fcom=num(e.fcom)??11.8, freal=num(e.freal)??16.44;   // hojas anteriores no traen fcom/freal
      const pureza=(b&&p!=null)?p/b*100:null;                    // (Pol ÷ Brix) × 100
      const jugo=(pm&&t!=null)?(pm-t)*100/pm:null;                // (500 − torta) × 100 ÷ 500
      const rcl=p!=null?p*fcom:null, rrl=p!=null?p*freal:null;    // Pol × 11.8 · Pol × 16.44
      return {pureza, jugo, rcl, rck:rcl!=null?rcl*0.5:null, rrl, rrk:rrl!=null?rrl*0.5:null, polc:rrl!=null?rrl/20:null}; },
    formula:'Pureza = Pol ÷ Brix × 100 · % Jugo = (500 − peso torta) × 100 ÷ 500 · Rend. comercial (lb/t) = Pol × 11.8 · kg/tm = lb/t × 0.5 · Rend. real (lb/t) = Pol × 16.44 · kg/tm = lb/t × 0.5 · Pol % caña = Rend. real (lb/t) ÷ 20',
    vistas:[
      {titulo:'Brix', analisis:['Brix'], cols:[{k:'brix',label:'°Brix jugo',d:2,res:true}], principal:{'Brix':{k:'brix',d:2}}},
      // Orden pedido por el laboratorio en todas las vistas de caña: Brix, Pol, Pureza.
      {titulo:'Pol', analisis:['Pol'], cols:[{k:'brix',label:'°Brix',d:2},{k:'pol',label:'Pol jugo (%)',d:2,res:true},{k:'pureza',label:'Pureza jugo (%)',d:2,fm:'Pol ÷ Brix × 100'}], principal:{'Pol':{k:'pol',d:2}}},
      {titulo:'Rendimiento', analisis:['Rendimiento'], cols:[{k:'torta',label:'Peso torta (g)',d:2},{k:'jugo',label:'% Jugo',d:2,fm:'(500 − torta) × 100 ÷ 500'},{k:'pol',label:'Pol jugo (%)',d:2},
        {k:'rcl',label:'Rend. comercial (lb/t)',d:1,res:true,fm:'Pol × 11.8'},{k:'rck',label:'Rend. comercial (kg/tm)',d:1,res:true,fm:'lb/t × 0.5'},
        {k:'rrl',label:'Rend. real (lb/t)',d:1,res:true,fm:'Pol × 16.44'},{k:'rrk',label:'Rend. real (kg/tm)',d:1,res:true,fm:'lb/t × 0.5'},
        {k:'polc',label:'Pol % caña',d:2,fm:'Rend. real (lb/t) ÷ 20'}],
        principal:{'Rendimiento':{k:'rcl', texto:m=>`${fx(m.rcl,1)} lb/t · ${fx(m.rck,1)} kg/tm`}}},
    ]
  },
  'Caña|% Humedad': {
    titulo:'% Humedad en fibra de caña', analisis:['% Humedad'], controles:[],
    cols:[{k:'nb',label:'# de bandeja',tipo:'txt',opc:true},{k:'band',label:'Peso de bandeja (g)'},{k:'mues',label:'Peso muestra (g)'},{k:'bandS',label:'Peso bandeja + muestra seca (g)'}],
    calc:f=>{ const b=num(f.band), m=num(f.mues), s=num(f.bandS); const seca=(s!=null&&b!=null)?s-b:null; return {seca, pct:(m&&seca!=null)?(m-seca)/m*100:null}; },
    formula:'% Humedad = (peso muestra − peso seco) ÷ peso muestra × 100 · peso seco = bandeja + seca − bandeja',
    vistas:[{analisis:['% Humedad'], cols:[{k:'band',label:'Peso bandeja',d:2},{k:'mues',label:'Peso muestra',d:2},{k:'bandS',label:'Bandeja + seca',d:2},{k:'seca',label:'Peso seco',d:2,fm:'bandeja + seca − bandeja'},{k:'pct',label:'% Humedad',d:2,res:true,fm:'(muestra − seco) ÷ muestra × 100'}], principal:{'% Humedad':{k:'pct',d:2}}}]
  },
  'Caña|% Fibra': {
    titulo:'% Fibra en caña (Tanimoto)', analisis:['% Fibra'],
    /* Excel "Cálculo de Peso Seco de Torta y % de Fibra método Tanimoto":
       1) Peso de torta seca = (bandeja + muestra seca) − bandeja; torta húmeda = peso de muestra (150 g).
       2) % Fibra = (100 × torta seca − Brix jugo × torta húmeda) ÷ (5 × (100 − Brix jugo)); 5 = 500 g de caña ÷ 100.
       El °Brix del jugo se toma de la hoja "Brix y Pol" de la misma muestra (editable si hace falta).
       Antes: % Fibra = torta seca ÷ 500 × 100, sin el Brix (79.5 g daba 15.90 % en lugar de 12.375 %). */
    encabezado:{titulo:'Constantes del método', campos:[{k:'pm',label:'Peso de muestra de caña (g)',def:'500',soloVal:true,editableVal:true}]},
    controles:[],
    cols:[{k:'nb',label:'# de bandeja',tipo:'txt',opc:true},{k:'band',label:'Peso de bandeja (g)'},{k:'mues',label:'Peso torta húmeda (g)'},{k:'bandS',label:'Peso bandeja + torta seca (g)'},
      {k:'brix',label:'°Brix jugo',vinculo:{hoja:'Caña|Jugo',campo:'brix',nombre:'Brix y Pol'}}],
    calc:(f,ctx)=>{ const b=num(f.band), m=num(f.mues), s=num(f.bandS), bx=num(f.brix), pm=num((ctx.enc||{}).pm)??500;
      const seca=(s!=null&&b!=null)?s-b:null, bandH=(b!=null&&m!=null)?b+m:null;
      if(bx==null){ // Hojas anteriores (sin °Brix): se conserva su cálculo para no alterar resultados ya validados.
        return {seca, bandH, fibra:(pm&&seca!=null)?seca/pm*100:null, legado:true}; }
      return {seca, bandH, fibra:(seca!=null&&m!=null&&pm&&bx!==100)?(100*seca-bx*m)/((pm/100)*(100-bx)):null}; },
    formula:'Torta seca = (bandeja + torta seca) − bandeja · % Fibra Tanimoto = (100 × torta seca − °Brix jugo × torta húmeda) ÷ (5 × (100 − °Brix jugo)) · 5 = 500 g de caña ÷ 100',
    vistas:[{analisis:['% Fibra'], cols:[{k:'band',label:'Peso bandeja (g)',d:2},{k:'mues',label:'Torta húmeda (g)',d:2},{k:'bandH',label:'Bandeja + torta húmeda (g)',d:2,fm:'bandeja + torta húmeda'},
      {k:'bandS',label:'Bandeja + torta seca (g)',d:2},{k:'seca',label:'Torta seca (g)',d:2,fm:'(bandeja + seca) − bandeja'},{k:'brix',label:'°Brix jugo',d:2,fm:'Hoja Brix y Pol'},
      {k:'fibra',label:'% Fibra Tanimoto',d:2,res:true,fm:'(100 × seca − Brix × húmeda) ÷ (5 × (100 − Brix))'}], principal:{'% Fibra':{k:'fibra',d:2}}}],
    legado:{
      es:cap=>Object.values(cap.filas||{}).some(f=>f&&(f.brix==null||String(f.brix).trim()==='')),
      aviso:'Hoja capturada antes del método Tanimoto: no tiene °Brix del jugo y su % Fibra se calculó como torta seca ÷ peso de caña × 100. Para corregirla, devuélvala a reanálisis.',
      vistas:[{analisis:['% Fibra'], cols:[{k:'band',label:'Peso bandeja (g)',d:2},{k:'mues',label:'Peso muestra (g)',d:2},{k:'bandS',label:'Bandeja + seca (g)',d:2},{k:'seca',label:'Peso seco (g)',d:2,fm:'(bandeja + seca) − bandeja'},
        {k:'fibra',label:'% Fibra (cálculo anterior)',d:2,res:true,fm:'seco ÷ peso caña × 100'}], principal:{'% Fibra':{k:'fibra',d:2}}}]
    }
  },
  'Caña|pH': {
    titulo:'pH en jugo de caña', analisis:['pH'],
    encabezado:{titulo:'Temperatura observada (°C)', campos:[{k:'t1',label:'T₁',unidad:'°C'},{k:'t2',label:'T₂',unidad:'°C'}]},
    controles:[],
    cols:[{k:'pachon',label:'No. de pachón',tipo:'txt',opc:true},{k:'ph',label:'pH'}],
    calc:()=>({}),
    info:ctx=>[['Temperatura observada', [ctx.enc.t1,ctx.enc.t2].filter(Boolean).join(' · ')+' °C']],
    vistas:[{analisis:['pH'], cols:[{k:'pachon',label:'No. pachón'},{k:'ph',label:'pH',d:2,res:true}], principal:{'pH':{k:'ph',d:2}}}]
  },
});
HOJAS['Foliar|Micronutrientes'] = (function(){
  const h = hojaAA({titulo:'Micronutrientes en tejido foliar (Cu, Zn, Mn, Fe)', analisis:'Micronutrientes', modo:'ppm', volDef:'50', unidad:'ppm', pesoEnValidacion:true,
    elementos:[{k:'cu',sym:'Cu'},{k:'zn',sym:'Zn'},{k:'mn',sym:'Mn'},{k:'fe',sym:'Fe'}], inLabel:e2=>e2.sym, formula:'ppm = (lectura − blanco) × 50 ÷ peso · el peso de esta hoja lo usa también Boro',
    referencia:{titulo:'Patrones — puntos de la curva (referencia)', filas:[['Patrón','Cu – Zn','Fe – Mn'],['# 01','0.25 – 0.25','3.0 – 3.0'],['# 02','1.5 – 1.5','6.0 – 6.0'],['# 03','3.00 – 3.00','12.0 – 12.0']]}});
  h.cols = [{k:'crisol',label:'# Crisol',tipo:'txt',opc:true}].concat(h.cols.map(c=>c.k==='peso'?Object.assign({},c,{label:'Peso muestra (g)'}):c));
  h.controles = [{id:'BLANCO',label:'Blanco',sin:['peso']},{id:'CONTROL',label:'Control foliar'},{id:'CONTROL_BORO',label:'Control boro'}];
  /* Validación técnica igual al Excel "Calculo Foliares 2026" (hoja Micros): peso con 4 decimales (con 3 se
     redondeaba 0.2025 → 0.203 y el recálculo manual no coincidía) y elementos en el orden Cu, Zn, Fe, Mn.
     La captura en la bandeja no cambia (h.cols conserva su orden). */
  const ordenExcel=['cu','zn','fe','mn'], grupo={'':0,Lectura:1,Blanco:2,Resultado:3,Revisar:4};
  const elem=k=>{const m=String(k).match(/^(?:blk_|r_)?(cu|zn|fe|mn)$/);return m?ordenExcel.indexOf(m[1]):-1;};
  const v=h.vistas[0];
  v.cols=v.cols.map(c=>c.k==='peso'?Object.assign({},c,{label:'Peso (g)',d:4}):c)
    .sort((a,b)=>(grupo[a.g||'']??9)-(grupo[b.g||'']??9)||elem(a.k)-elem(b.k));
  // Lectura menor que el blanco: el resultado sale negativo. Se señala para que el técnico decida.
  v.cols.push({k:'bajoBlk',label:'Lectura < blanco',g:'Revisar',fm:'El resultado sale negativo'});
  const calcBase=h.calc;
  h.calc=(f,ctx)=>{const o=calcBase(f,ctx);
    o.bajoBlk=ordenExcel.filter(k=>{const c=num(f[k]),b=num(ctx.blk[k]);return c!=null&&b!=null&&c<b;}).map(k=>k[0].toUpperCase()+k.slice(1)).join(', ');
    return o;};
  return h;
})();
/* Nitrógeno foliar: en la bandeja solo queda la normalidad del HCl, que el analista verifica (o edita)
   antes de enviar; el factor miliequivalente de N no lo captura el analista: toma su valor por defecto y lo
   confirma o corrige el técnico en Validación técnica (LAB_VALIDACION_ENCABEZADO_EDITABLE). Solo Foliar:
   Nitrógeno total de suelo usa el mismo constructor y no cambia. */
(function(h){
  h.encabezado={titulo:'Parámetros de la corrida', campos:[
    {k:'nhcl',label:'Normalidad del ácido clorhídrico',unidad:'N',def:'0.0099779',verificarAlEnviar:true},
    {k:'fac',label:'Factor miliequivalente de N (× 100)',def:'1.408',soloVal:true,editableVal:true}]};
  h.formula='% N = (mL HCl muestra − mL HCl blanco) × N HCl × factor miliequivalente ÷ peso';
  h.info=ctx=>[['mL HCl del blanco', fx(num(ctx.blk.ml),2)]];
  h.vistas=[{analisis:h.vistas[0].analisis,
    cols:[{k:'peso',label:'Peso (g)',d:4},{k:'blk',label:'mL HCl blanco',d:2,fm:'Fila Blanco'},{k:'ml',label:'mL HCl muestra',d:2},
      {k:'nN',label:'N HCl',d:7,fm:'Parámetros de la corrida'},{k:'facN',label:'Factor meq',d:3,fm:'Validación técnica'},
      {k:'n',label:'% N',d:2,res:true,fm:'(mL muestra − mL blanco) × N × factor ÷ peso'}],
    principal:h.vistas[0].principal}];
  const calc=h.calc;
  h.calc=(f,ctx)=>Object.assign(calc(f,ctx),{nN:num(ctx.enc.nhcl),facN:num(ctx.enc.fac)});
})(HOJAS['Foliar|Nitrógeno']);
/* Curvas de Boro y Fósforo foliar igual que el Excel "Calculo Foliares 2026", cuya línea de tendencia es
   lineal con intercepto fijo en 0:
   - pendiente redondeada a 4 decimales, la que muestra la ecuación (Boro 0.1414, Fósforo 0.0329) y que el
     Excel usa en la fórmula; sin redondear el resultado variaba en el 3.er–6.º decimal;
   - R² como lo calcula Excel con intercepto 0: 1 − SSres ÷ Σy² (el R² centrado daba 0.9757 en lugar de
     0.9911 en Fósforo y activaba el aviso "R² menor a 0.99").
   La hoja declara h.regresion; Validación técnica (contexto) y la bandeja la usan en lugar de la general.
   Una pendiente digitada en la hoja se usa tal cual. */
function regExcel(r){
  if(!r||r.excel) return r;
  const sy2=r.puntos.reduce((s,q)=>s+q.y*q.y,0), ssr=r.puntos.reduce((s,q)=>s+Math.pow(q.y-r.m0*q.x,2),0);
  return Object.assign({},r,{m0:Math.round(r.m0*1e4)/1e4, r2:sy2?1-ssr/sy2:1, excel:true});
}
['Foliar|Boro','Foliar|Fósforo'].forEach(k=>{
  const h=HOJAS[k], calc=h.calc, info=h.info;
  const ctxExcel=ctx=>(ctx&&ctx.reg&&!ctx.reg.excel)?Object.assign({},ctx,{reg:regExcel(ctx.reg)}):ctx;
  h.regresion=puntos=>regExcel(regresion(puntos));
  h.calc=(f,ctx)=>calc(f,ctxExcel(ctx));
  h.info=ctx=>info(ctxExcel(ctx));
  h.formula+=' · pendiente de la curva redondeada a 4 decimales (la de la ecuación) · R² con intercepto 0, como Excel';
});
Object.assign(HOJA_ALIAS, {
  'Agua|pH':'Agua|Lecturas', 'Agua|Conductividad eléctrica':'Agua|Lecturas', 'Agua|Sólidos totales disueltos':'Agua|Lecturas', 'Agua|Salinidad':'Agua|Lecturas', 'Agua|Resistividad':'Agua|Lecturas',
  'Agua|Alcalinidad total':'Agua|Titulaciones', 'Agua|Carbonatos':'Agua|Titulaciones', 'Agua|Bicarbonatos':'Agua|Titulaciones', 'Agua|Dureza':'Agua|Titulaciones', 'Agua|Cloruros':'Agua|Titulaciones',
  'Agua|Razón de adsorción de sodio (RAS)':'Agua|Macronutrientes',
  'Caña|Brix':'Caña|Jugo', 'Caña|Pol':'Caña|Jugo', 'Caña|Rendimiento':'Caña|Jugo',
});

CATALOGO_ANALISIS.forEach(c=>{const k=c.tipoMuestra+'|'+c.analisis;if(!HOJA_ALIAS[k]&&!HOJAS[k])HOJAS[k]=hojaGenerica(c.tipoMuestra,c.analisis);});
// Excel Químico Suelos, Boro: tendencia con intercepto fijo en 0.
// Solo se corrige R² (no centrado); se conserva la pendiente y la fórmula del resultado.
function regSueloOrigen(puntos){
 const r=regresion(puntos);if(!r)return null;
 const sy2=r.puntos.reduce((s,p)=>s+p.y*p.y,0);
 const ssr=r.puntos.reduce((s,p)=>s+(p.y-r.m0*p.x)**2,0);
 return {...r,r2:sy2?1-ssr/sy2:null};
}
HOJAS['Suelo|Boro'].regresion=regSueloOrigen;
HOJAS['Suelo|Fósforo disponible'].regresion=regSueloOrigen;
// Excel Azufre: ajuste lineal sin fijar el intercepto; la curva verifica linealidad.
HOJAS['Suelo|Azufre'].regresion=puntos=>{
 const p=(puntos||[]).map(q=>({x:num(q.c),y:num(q.a)})).filter(q=>Number.isFinite(q.x)&&Number.isFinite(q.y));
 const r=regLineal(p.map(q=>({c:q.x,a:q.y})));if(!r)return null;
 const media=p.reduce((s,q)=>s+q.y,0)/p.length;
 const sse=p.reduce((s,q)=>s+(q.y-r.m*q.x-r.b)**2,0),sst=p.reduce((s,q)=>s+(q.y-media)**2,0);
 return {m0:r.m,b0:r.b,r2:sst?1-sse/sst:null,n:p.length,puntos:p,ajuste:'lineal'};
};
// Captura independiente de macronutrientes; la hoja combinada se conserva para revisar formularios anteriores.
{
 const base=HOJAS['Suelo|Macronutrientes'];
 base.encabezado.campos.forEach(c=>Object.assign(c,{soloVal:true,editableVal:true,gestionVal:true}));
 const vista=base.vistas.find(v=>v.analisis.includes('Macronutrientes'));
 HOJAS['Suelo|Macronutrientes (Ca, Mg, K, Na)']={...base,
  titulo:'Macronutrientes (Ca, Mg, K, Na)',analisis:['Macronutrientes'],
  encabezado:{titulo:'Parámetros de la corrida',campos:base.encabezado.campos.filter(c=>c.k!=='n').map(c=>({...c}))},
  cols:base.cols.filter(c=>c.k!=='ml').map(c=>({...c})),
  calc:(f,ctx)=>Object.fromEntries(Object.entries(base.calc(f,ctx)).filter(([k])=>k!=='cic'&&k!=='blk')),
  info:ctx=>base.info(ctx).filter(([label])=>label!=='Blanco CIC (mL)'),
  formula:'meq/100 g = (ppm − blanco) × factor del elemento ÷ peso',
  vistas:[{...vista,cols:vista.cols.map(c=>c.k.startsWith('r_')?{...c,fm:'(ppm − blanco) × factor '+c.k.slice(2).toUpperCase()+' ÷ peso'}:{...c})}]
 };
}
// Pendiente e intercepto se capturan donde corresponda; los demás parámetros se confirman en validación.
Object.entries(HOJAS).forEach(([key,h])=>{
 if(h.encabezado?.titulo!=='Parámetros de la corrida'||key==='Suelo|Textura')return;
 h.encabezado.campos.forEach(c=>{c.soloVal=c.k!=='pend'&&c.k!=='inter';c.editableVal=true;c.gestionVal=c.soloVal;delete c.verificarAlEnviar;if(c.k==='pend'||c.k==='inter'){c.opcionalVal=true;}});
});
// Nuevas capturas independientes; la definición combinada conserva los formularios históricos.
['Capacidad de campo (CC)','Punto de marchitez permanente (PMP)'].forEach(analisis=>{
 const base=HOJAS['Suelo|CC y PMP'],cc=analisis==='Capacidad de campo (CC)',pre=cc?'cc':'pmp';
 const vista=base.vistas.find(v=>v.analisis.includes(analisis));
 const cols=base.cols.filter(c=>c.k.startsWith(pre+'_')).map(c=>({...c}));
 cols.push({k:'olla',label:'Olla (P o G)',tipo:'sel',opts:['','P','G'],opc:true,analisis});
 const vcols=vista.cols.map(c=>({...c}));if(cc)vcols.push({k:'olla',label:'Olla (P o G)'});
 HOJAS['Suelo|'+analisis]={...base,titulo:analisis,analisis:[analisis],cols,
  calc:f=>Object.fromEntries(Object.entries(base.calc(f)).filter(([k])=>k.startsWith(pre+'_'))),
  formula:base.formula.replace('% PMP / % CC',cc?'% CC':'% PMP'),vistas:[{...vista,cols:vcols}]};
});
HOJAS['Suelo|Humedad residual'].validar=(f,ctx)=>HOJAS['Suelo|Humedad residual'].calc(f).pct==null?['Revise los pesos: muestra húmeda > 0; caja + seca > caja y <= caja + húmeda.']:[];
HOJAS['Suelo|Textura'].validar=(f,ctx)=>{
 if(num(ctx.enc.peso??50)!==50)return ['El procedimiento de Textura del Excel utiliza 50 g de muestra.'];
 const r=HOJAS['Suelo|Textura'].calc(f,ctx);
 if(['factor','lc1','lc2','arcilla','limo','arena'].some(k=>r[k]==null||!Number.isFinite(r[k])))return ['Complete HR (0 <= HR < 100), lecturas y temperaturas en °F.'];
 if(['arcilla','limo','arena'].some(k=>r[k]<-1e-9||r[k]>100+1e-9))return ['Arena, limo y arcilla deben estar entre 0 y 100 %. Revise lecturas y temperaturas.'];
 return Math.abs(r.total-100)>1e-8?['La suma de arena, limo y arcilla debe ser 100 %.']:[];
};
root.LabHojas={hojas:HOJAS,alias:HOJA_ALIAS,catalogo:CATALOGO_ANALISIS,num,fx,regresion,regLineal,texturas:Object.keys(TEXTURA_USDA)};
})(typeof window!=='undefined'?window:globalThis);
// Catálogo heredado: utiliza la hoja genérica del prototipo.
[["Agua","Coliformes totales y fecales"],["Agua","Nitratos / Nitritos"],["Suelo","Densidad real"],["Suelo","Porosidad total"],["Suelo","Potasio intercambiable"]].forEach(([tipo,analisis])=>{const k=tipo+'|'+analisis;const h={titulo:analisis,analisis:[analisis],generico:true,controles:[],cols:[{k:'valor',label:'Resultado'}],calc:()=>({}),vistas:[{analisis:[analisis],cols:[{k:'valor',label:'Resultado',d:2,res:true}],principal:{[analisis]:{k:'valor',d:2}}}]};window.LabHojas.hojas[k]=h;});
