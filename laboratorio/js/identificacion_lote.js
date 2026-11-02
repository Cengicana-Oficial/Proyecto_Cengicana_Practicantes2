(function (root) {
  "use strict";
  const esc = value => String(value ?? "").replace(/[&<>"']/g, c => ({ "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#039;" }[c]));
  const norm = value => String(value || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLocaleLowerCase("es").trim();
  const state = e => e?.info?.trim() ? (e.contenido === "solo_id" ? "solo_id" : "ok") :
    Object.entries(e?.campos || {}).some(([k,v]) => k !== "obs" && String(v).trim()) ? "ok" : "pendiente";
  const onlyId = value => !!value.trim() && value.trim().length <= 40 && !/[\n:=|;\t]/.test(value);
  const csvCell = value => {
    let text = String(value ?? "");
    if (/^[\s]*[=+\-@]/.test(text) || /^[\t\r]/.test(text)) text = "'" + text;
    return '"' + text.replace(/"/g, '""') + '"';
  };
  function parseTSV(text) {
    const rows = []; let row = [], cell = "", quoted = false;
    text = text.replace(/\r\n?/g, "\n");
    for (let i=0; i<text.length; i++) {
      const c = text[i];
      if (c === '"' && (quoted || cell === "")) {
        if (quoted && text[i+1] === '"') { cell += '"'; i++; } else quoted = !quoted;
      } else if (!quoted && (c === "\t" || c === "\n")) {
        row.push(cell); cell = "";
        if (c === "\n") { rows.push(row); row = []; }
      } else cell += c;
    }
    if (quoted) throw new Error("El bloque pegado tiene comillas sin cerrar.");
    if (cell || row.length) { row.push(cell); rows.push(row); }
    return rows;
  }
  const C128 = ['212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212','112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131','311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321','112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121','313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111','314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114','122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212','124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113','114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112'];
function code128Svg(texto, alto){
  const vals = [...texto].map(ch=>ch.charCodeAt(0)-32).filter(v=>v>=0 && v<95);
  let suma = 104; vals.forEach((v,i)=>suma += v*(i+1));
  const modulos = [104, ...vals, suma%103, 106].map(v=>C128[v]).join('');
  const total = [...modulos].reduce((a,d)=>a+ +d, 0) + 20;
  let x = 10, barra = true, rects = '';
  [...modulos].forEach(d=>{ const w = +d; if(barra) rects += `<rect x="${x}" y="0" width="${w}" height="${alto||40}"/>`; x += w; barra = !barra; });
  return `<svg class="c128" viewBox="0 0 ${total} ${alto||40}" preserveAspectRatio="none" role="img" aria-label="Código de barras ${esc(texto)}"><rect width="${total}" height="${alto||40}" fill="#fff"/><g fill="#000">${rects}</g></svg>`;
}

  root.CengiIdentificacion = { state, onlyId, csvCell, parseTSV, code128Svg };
  const app = document.getElementById("ident-workflow");
  if (!app) return;
  const $ = id => document.getElementById("iw-" + id);
  let lotes = JSON.parse($("data").textContent);
  const config = JSON.parse($("config").textContent);
  const csrf = JSON.parse($("csrf").textContent);
  const drafts = new Map();
  const estados = { ok:"Con información", solo_id:"Solo ID del solicitante", pendiente:"Sin identificar" };
  const metodos = { qr:"Código QR", barras:"Código de barras", tabular:"Tabulado", tabulado:"Tabulado", manual:"Ficha individual", pegado:"Pegado de Excel", camara:"Cámara" };
  const metodosUi = { qr:"Código QR", barras:"Código de barras", tabular:"Ingreso manual" };
  let current = null, destino = -1, saving = false, scanTimer = null;
  let ultimaLectura = null, emparejada = -1;
  let template = "", baseRows = [], printIds = new Set(), visibleIndices = [];
  const clone = value => JSON.parse(JSON.stringify(value));
  // PHP envía los campos vacíos como [] (arreglo); se usan siempre como objeto para que JSON los conserve.
  const plainObj = v => v && typeof v === "object" && !Array.isArray(v) ? v : {};
  const key = id => "lab-ident-v3:" + app.dataset.user + ":" + id;
  const msg = text => $("status").textContent = text;
  const draft = () => current ? drafts.get(current.id_solicitud) : null;
  const rows = () => draft()?.muestras || current?.muestras || [];
  const editable = () => !!draft() && !saving && !draft().conflict;
  const fields = () => config.plantillas[template] || config.campos.map(c => c.key);
  // Definición del campo para el formato vigente (etiqueta, tipo de dato, opciones, obligatorio) del Catálogo de análisis.
  const campoDef = k => (config.definiciones || {})[template]?.[k] || config.campos.find(c => c.key === k) || { label: k };
  function celda(i, k, valor, codigo, dis) {
    const d = campoDef(k), base = ' data-row-index="' + i + '" data-field="' + esc(k) + '" aria-label="' + esc(d.label + ' de ' + codigo) + '"' + (d.obligatorio ? ' data-required="1"' : '') + dis;
    if (d.tipo === 'lista' || d.tipo === 'booleano') {
      const ops = d.tipo === 'booleano' ? ['Sí', 'No'] : (d.opciones || []);
      const lista = valor && !ops.includes(valor) ? [valor, ...ops] : ops;
      return '<select' + base + '><option value=""></option>' + lista.map(o => '<option' + (o === valor ? ' selected' : '') + '>' + esc(o) + '</option>').join('') + '</select>';
    }
    const tipo = d.tipo === 'fecha' && (!valor || /^\d{4}-\d{2}-\d{2}$/.test(valor)) ? 'date' : 'text';
    return '<input type="' + tipo + '"' + base + (d.tipo === 'numero' ? ' inputmode="decimal"' : '') + ' maxlength="1000" value="' + esc(valor) + '">';
  }
  const summary = e => e?.info || config.campos.filter(c => e?.campos?.[c.key]).map(c => c.label + ": " + e.campos[c.key]).join(" · ");
  const pending = lot => lot.muestras.filter(m => state(m.etiqueta) === "pendiente").length;
  function persist() {
    const d = draft();
    if (!d) return;
    d.dirty = true;
    try { sessionStorage.setItem(key(current.id_solicitud), JSON.stringify(d)); }
    catch { msg("No se pudo conservar el borrador en esta pestaña. Guarde antes de salir."); }
    refreshSummary();
    renderList();
  }
  function createDraft(lot) {
    return { version:lot.version, metodo:lot.metodo || "tabular", plantilla:lot.plantilla || config.porTipo[lot.tipo_clave] || "Todos los campos", muestras:clone(lot.muestras), motivo:"", dirty:false };
  }
  function chooseLot(id, sample) {
    if (saving) return;
    clearTimeout(scanTimer);
    current = lotes.find(l => l.id_solicitud === Number(id)) || null;
    if (!current) return renderEditor();
    if (!drafts.has(current.id_solicitud)) {
      try {
        const stored = JSON.parse(sessionStorage.getItem(key(current.id_solicitud)) || "null");
        if (stored?.muestras && Array.isArray(stored.muestras)) {
          const oldIds = stored.muestras.map(m => m.id_muestra).sort().join(",");
          const newIds = current.muestras.map(m => m.id_muestra).sort().join(",");
          stored.conflict = stored.version !== current.version || oldIds !== newIds;
          drafts.set(current.id_solicitud, stored);
          if (stored.conflict) msg("El lote cambió desde este borrador. Revise los datos y descarte el borrador para cargar la versión vigente.");
          else msg("Borrador recuperado. Todavía no está guardado en el sistema.");
        }
      } catch { msg("No se pudo recuperar el borrador de esta pestaña."); }
      if (!drafts.has(current.id_solicitud) && current.version === 0) drafts.set(current.id_solicitud, createDraft(current));
    }
    template = draft()?.plantilla || current.plantilla || config.porTipo[current.tipo_clave] || "Todos los campos";
    $("muestra-buscar").value = sample || ""; $("muestra-estado").value = "";
    destino = sample ? rows().findIndex(m => m.codigo_lab === sample) : rows().findIndex(m => state(m.etiqueta) === "pendiente");
    ultimaLectura = null; emparejada = -1;
    $("save-msg").hidden = true; $("parcial-wrap").classList.remove("is-alert");
    showView("editor");
    renderList(); renderEditor();
  }
  // Vista de trabajo: tabla de lotes ↔ muestras del lote seleccionado.
  function showView(name) {
    $("lotes-view").hidden = name !== "lotes";
    $("editor-view").hidden = name !== "editor";
    if (name === "editor") app.scrollIntoView?.({ block:"start" });
  }
  const tipoLabel = t => { const s = String(t || "").trim(); return s ? s.charAt(0).toLocaleUpperCase("es") + s.slice(1) : "—"; };
  function estadoLote(l) {
    const d = drafts.get(l.id_solicitud), pend = pending(l);
    if (d?.dirty) return { css:"borrador", text:"Borrador sin guardar" };
    if (!l.version) return { css:"sin", text:"Sin iniciar" };
    return { css: pend ? "parcial" : "guardada", text:"Guardada v" + l.version + (pend ? " · " + pend + " pendiente" + (pend === 1 ? "" : "s") : "") };
  }
  function renderList() {
    const query = norm($("lote-buscar").value), filter = $("lote-estado").value, tipo = $("lote-tipo").value;
    const filtered = lotes.filter(l => {
      const pendientes = !l.version || pending(l) > 0;
      return (!query || norm([l.codigo_lote,l.cliente,...l.muestras.map(m=>m.codigo_lab)].join(" ")).includes(query)) &&
        (!tipo || l.tipo_clave === tipo) &&
        (!filter || (filter === "pendientes" ? pendientes : !!l.version));
    });
    $("lotes-resumen").textContent = filtered.length + " de " + lotes.length + " lotes · " +
      filtered.reduce((n, l) => n + l.muestras.length, 0) + " muestras";
    $("lotes").innerHTML = filtered.map(l => {
      const ms = drafts.get(l.id_solicitud)?.muestras || l.muestras;
      const count = ms.filter(m => state(m.etiqueta) !== "pendiente").length;
      const st = estadoLote(l), analisis = String(l.analisis || "").split(",").map(s => s.trim()).filter(Boolean);
      const rango = ms.length ? ms[0].codigo_lab + (ms.length > 1 ? " → " + ms[ms.length - 1].codigo_lab : "") : "";
      return '<tr class="' + (current?.id_solicitud === l.id_solicitud ? "on" : "") + '">' +
        '<td class="iw-lote-num"><b>' + esc(l.codigo_lote) + '</b></td>' +
        '<td>' + esc(tipoLabel(l.tipo)) + '</td>' +
        '<td>' + esc(l.cliente || "Sin cliente") + '</td>' +
        '<td class="iw-lote-analisis" title="' + esc(analisis.join(", ")) + '">' + (analisis.length ? '<span class="iw-analisis-txt">' + esc(analisis.slice(0, 2).join(", ")) + '</span>' + (analisis.length > 2 ? '<span class="iw-mas">+' + (analisis.length - 2) + ' más</span>' : "") : '<span class="iw-muted">Sin análisis</span>') + '</td>' +
        '<td><b>' + ms.length + '</b><small class="iw-rango">' + esc(rango) + '</small></td>' +
        '<td class="iw-avance"><span>' + count + ' / ' + ms.length + '</span><progress max="' + Math.max(1, ms.length) + '" value="' + count + '"></progress></td>' +
        '<td><span class="iw-chip iw-chip--' + st.css + '">' + esc(st.text) + '</span>' + (l.metodo ? '<small class="iw-rango">' + esc(metodosUi[l.metodo] || l.metodo) + '</small>' : "") + '</td>' +
        '<td><button type="button" class="ident-btn ident-btn--ghost ident-btn--small" data-lot="' + l.id_solicitud + '">Ver muestras</button></td></tr>';
    }).join("") || '<tr><td colspan="8" class="iw-muted">Sin lotes con estos filtros.</td></tr>';
  }
  function kpis() {
    const ms = lotes.flatMap(l => l.muestras);
    $("total").textContent = ms.length;
    $("con").textContent = ms.filter(m => state(m.etiqueta) === "ok").length;
    $("solo").textContent = ms.filter(m => state(m.etiqueta) === "solo_id").length;
    $("pendientes").textContent = lotes.filter(l => !l.version || pending(l) > 0).length;
  }
  function renderEditor() {
    $("empty").hidden = !!current;
    $("editor-content").hidden = !current;
    if (!current) return;
    const d = draft(), locked = !editable(), metodo = d?.metodo || current.metodo || "tabular";
    $("lote-titulo").textContent = "Lote " + current.codigo_lote + " · " + tipoLabel(current.tipo) + (d ? " · Borrador" : " · Guardada v" + current.version);
    $("lote-meta").textContent = [current.cliente || "Sin cliente", current.muestras.length + " muestras", current.analisis, current.actualizado_por, current.actualizado_en].filter(Boolean).join(" · ");
    $("edit").hidden = !!d || !current.version;
    app.querySelectorAll("[data-metodo]").forEach(b => {
      const on = b.dataset.metodo === metodo;
      b.setAttribute("aria-checked", String(on)); b.classList.toggle("on", on); b.disabled = locked;
    });
    $("plantilla-wrap").hidden = metodo !== "tabular";
    template = d?.plantilla || current.plantilla || config.porTipo[current.tipo_clave] || "Todos los campos";
    $("plantilla").value = template; $("plantilla").disabled = locked;
    $("scan-wrap").hidden = metodo === "tabular" || locked;
    $("ayuda").hidden = metodo !== "tabular" || locked;
    $("edit-controls").hidden = !d;
    $("guardar").disabled = locked || !rows().length;
    $("descartar").disabled = saving;
    $("motivo-wrap").hidden = !current.version;
    $("motivo").value = d?.motivo || "";
    $("parcial").checked = false;
    $("historial").innerHTML = current.historial.map(h => '<li><strong>v' + Number(h.version) + '</strong> · ' + esc(h.creado_en) + ' · ' + esc(h.usuario) +
      '<p>' + esc(h.motivo) + ' · ' + Number(h.cambios) + ' muestra(s) modificadas</p></li>').join("") || "<li>Sin versiones guardadas.</li>";
    renderGrid(); refreshSummary();
  }
  function renderGrid() {
    if (!current) return;
    const tabular = (draft()?.metodo || current.metodo || "tabular") === "tabular", locked = !editable(), cols = fields();
    const dis = locked ? " disabled" : "";
    $("grid-head").innerHTML = '<tr><th class="iw-sticky">Código laboratorio</th>' + (tabular ? cols.map(k => '<th>' + esc(campoDef(k).label) + (campoDef(k).obligatorio ? ' <span class="iw-req" title="Obligatorio">*</span>' : '') +
      (!locked ? ' <button type="button" data-fill="' + esc(k) + '" aria-label="Copiar a celdas vacías">↓</button>' : "") + '</th>').join("") : "<th>Información de etiqueta</th>") + "<th>Identificación</th></tr>";
    const query = norm($("muestra-buscar").value), filter = $("muestra-estado").value;
    visibleIndices = rows().map((m,i)=>i).filter(i=>(!filter || state(rows()[i].etiqueta)===filter) && (!query || norm(rows()[i].codigo_lab+" "+summary(rows()[i].etiqueta)).includes(query)));
    $("visibles").textContent = visibleIndices.length + " de " + rows().length + " muestras · El guardado incluye todo el lote.";
    $("grid-body").innerHTML = visibleIndices.map(i => {
      const m = rows()[i];
      const e = m.etiqueta || {};
      return '<tr data-row="' + i + '" class="' + (i===destino ? "iw-destino" : "") + '"><td class="iw-sticky"><b>' + esc(m.codigo_lab) +
        '</b>' + (!tabular && !locked ? '<button type="button" data-destination="' + i + '">' + (i===destino ? "Siguiente lectura" : "Usar esta fila") + '</button>' : "") + '</td>' +
        (tabular ? cols.map(k => '<td>' + celda(i, k, e.campos?.[k] || "", m.codigo_lab, dis) + '</td>').join("") :
          '<td><textarea data-row-index="' + i + '" data-info maxlength="20000" rows="' + Math.min(4, Math.max(1, String(e.info || "").split("\n").length)) + '" aria-label="Etiqueta de ' + esc(m.codigo_lab) + '"' + dis + '>' + esc(e.info || "") +
          '</textarea></td>') + '<td data-row-state="' + i + '">' + (tabular ? estados[state(e)] :
          '<select data-content="' + i + '"' + dis + ' aria-label="Identificación de ' + esc(m.codigo_lab) + '"><option value="pendiente" disabled' + (state(e)==="pendiente" ? ' selected' : '') + '>Sin identificar</option><option value="completa"' + (state(e)==="ok" ? ' selected' : '') + '>Con información</option><option value="solo_id"' + (state(e)==="solo_id" ? ' selected' : '') + '>Solo ID del solicitante</option></select>') + '</td></tr>';
    }).join("") || '<tr><td colspan="20">No hay muestras con estos filtros. Use Ver todas para continuar.</td></tr>';
    updateDestination();
  }
  function refreshSummary() {
    if (!current) return;
    const counts = {ok:0,solo_id:0,pendiente:0};
    rows().forEach((m,i) => {
      const st = state(m.etiqueta); counts[st]++;
      const node = app.querySelector('[data-row-state="' + i + '"]');
      const select = app.querySelector('[data-content="' + i + '"]');
      if (select) { select.value = st === "ok" ? "completa" : st; select.disabled = !editable() || st === "pendiente"; }
      else if (node) node.textContent = estados[st];
    });
    $("resumen").textContent = counts.ok + " con información · " + counts.solo_id + " solo ID · " + counts.pendiente + " pendientes";
    $("draft-status").textContent = draft()?.conflict ? "Borrador de una versión anterior; no se puede guardar." : draft()?.dirty ? "Cambios sin guardar" : "Sin cambios";
  }
  function updateDestination() {
    const leidas = rows().filter(m => state(m.etiqueta) !== "pendiente").length, m = rows()[destino];
    const emparejar = $("emparejar").checked, lista = emparejar ? emparejada === destino && !!m : !!m;
    $("scan-codigo").textContent = lista ? m.codigo_lab : "—";
    $("scan-modo").textContent = emparejar ? (lista ? "Etiqueta del cliente para" : "Escanee el código de laboratorio de la bolsa") : (m ? "Siguiente muestra" : "Todas leídas");
    $("scan-progreso").textContent = leidas + " de " + rows().length + " leídas";
    $("scan-destino").textContent = lista ? "Escanee la etiqueta del cliente · se asignará a " + m.codigo_lab
      : emparejar ? "Primero escanee el código de laboratorio de la bolsa (p. ej. " + (rows()[0]?.codigo_lab || "S-001") + ")"
      : "Todas las muestras tienen lectura. Revise y presione Guardar identificación, o toque una fila para corregirla.";
    $("deshacer").disabled = !ultimaLectura || !editable();
  }
  // Aviso sonoro para trabajar con el lector sin mirar la pantalla.
  let audio = null;
  function beep(ok) {
    try {
      audio ||= new (root.AudioContext || root.webkitAudioContext)();
      const o = audio.createOscillator(), g = audio.createGain();
      o.frequency.value = ok ? 880 : 220; g.gain.value = 0.05;
      o.connect(g); g.connect(audio.destination); o.start(); o.stop(audio.currentTime + (ok ? 0.08 : 0.25));
    } catch {}
  }
  function tab(name) {
    app.querySelectorAll("[data-work-tab]").forEach(b => { const active=b.dataset.workTab===name;b.setAttribute("aria-selected",String(active));b.tabIndex=active?0:-1; });
    app.querySelectorAll("[data-work-panel]").forEach(p => p.hidden = p.dataset.workPanel !== name);
    if (name === "base") { baseLotes(); renderBase(); }
    if (name === "etiquetas") renderLabels(true);
  }
  function receiveScan(raw) {
    if (!editable()) return msg("Abra el lote para editar antes de asignar lecturas.");
    if (draft().metodo === "tabular") return msg("Seleccione Código QR o Código de barras.");
    raw = String(raw || "").replace(/\r/g,"").trim();
    if (!raw) return;
    if (raw.length > 20000) return msg("La lectura supera los 20 000 caracteres.");
    $("lectura").value = "";
    const fallo = text => { beep(false); msg(text); $("lectura").focus(); };
    if (raw.startsWith("SIGELAB|")) return fallo("Este QR identifica una boleta de recepción. Escanee la etiqueta del solicitante para completar la muestra.");
    // Código de laboratorio (etiqueta impresa por el laboratorio): selecciona esa muestra.
    const foundLot = lotes.find(l=>l.muestras.some(m=>norm(m.codigo_lab)===norm(raw)));
    if (foundLot) {
      if (foundLot.id_solicitud !== current.id_solicitud) return fallo("Ese código pertenece al lote " + foundLot.codigo_lote + ".");
      destino = rows().findIndex(m=>norm(m.codigo_lab)===norm(raw)); emparejada = destino; renderGrid(); scrollRow(destino); $("lectura").focus();
      beep(true);
      return msg(rows()[destino].codigo_lab + " seleccionada. Ahora escanee la etiqueta del cliente.");
    }
    if ($("emparejar").checked && (emparejada < 0 || emparejada !== destino)) return fallo("Modo emparejar: escanee primero el código de laboratorio de la bolsa y luego la etiqueta del cliente.");
    const m = rows()[destino];
    if (!m) return fallo("Todas las muestras tienen lectura. Toque la fila que desea corregir o escanee su código de laboratorio.");
    if (m.etiqueta?.info && !root.confirm(m.codigo_lab + " ya tiene una lectura. ¿Reemplazarla?")) return $("lectura").focus();
    const duplicate = lotes.flatMap(l=>drafts.get(l.id_solicitud)?.muestras || l.muestras).find(x=>x.id_muestra!==m.id_muestra && norm(x.etiqueta?.info)===norm(raw));
    if (duplicate && !root.confirm("La misma lectura ya está en " + duplicate.codigo_lab + ". ¿Asignarla también a " + m.codigo_lab + "?")) return $("lectura").focus();
    ultimaLectura = { index:destino, etiqueta:clone(m.etiqueta || {}), destino };
    m.etiqueta = {info:raw,contenido:onlyId(raw)?"solo_id":"completa",campos:{},origen:draft().metodo};
    const asignada = destino;
    destino = rows().findIndex((x,i)=>i>asignada && state(x.etiqueta)==="pendiente");
    if (destino<0) destino=rows().findIndex(x=>state(x.etiqueta)==="pendiente");
    emparejada = -1;
    persist(); renderGrid(); if (destino >= 0) scrollRow(destino);
    beep(true);
    const quedan = rows().filter(x=>state(x.etiqueta)==="pendiente").length;
    msg(m.codigo_lab + ": " + (onlyId(raw) ? "solo ID del solicitante" : "información leída") + (quedan ? " · faltan " + quedan : " · todas leídas — presione Guardar identificación") + ".");
    $("lectura").focus();
  }
  function deshacerLectura() {
    if (!ultimaLectura || !editable()) return;
    const m = rows()[ultimaLectura.index];
    if (!m) return;
    m.etiqueta = ultimaLectura.etiqueta;
    destino = ultimaLectura.destino; emparejada = $("emparejar").checked ? destino : -1;
    ultimaLectura = null;
    persist(); renderGrid(); scrollRow(destino);
    msg("Se deshizo la lectura de " + m.codigo_lab + ". Vuelva a escanear su etiqueta.");
    $("lectura").focus();
  }
  function setMetodo(value) {
    if (!editable() || !metodosUi[value] || draft().metodo === value) return;
    if (rows().some(m=>state(m.etiqueta)!=="pendiente")&&!root.confirm("Este lote ya tiene datos con " + metodosUi[draft().metodo] + ". Al guardar con " + metodosUi[value] + " se conservarán solo sus campos correspondientes. ¿Cambiar?")) return;
    clearTimeout(scanTimer);
    rows().forEach(m=>{
      m.etiqueta ||= {campos:{}};
      if(value==="tabular")m.etiqueta.info="";
      else m.etiqueta.campos={};
      m.etiqueta.origen=value;
    });
    draft().metodo=value; ultimaLectura=null; emparejada=-1;
    destino=rows().findIndex(m=>state(m.etiqueta)==="pendiente");
    persist();renderEditor();
    if(value!=="tabular")$("lectura").focus();
  }
  // Aviso junto al botón Guardar (el estado general queda arriba y puede no estar a la vista).
  function aviso(text, kind) {
    msg(text);
    const p = $("save-msg");
    p.textContent = text; p.className = "iw-save-msg" + (kind ? " is-" + kind : ""); p.hidden = !text;
  }
  async function save() {
    if (!editable()) return;
    const d = draft();
    $("parcial-wrap").classList.remove("is-alert");
    if (current.version && $("motivo").value.trim().length<5) { $("motivo").focus(); return aviso("Escriba el motivo de la corrección (mínimo 5 caracteres).", "warn"); }
    const incomplete = rows().filter(m=>state(m.etiqueta)==="pendiente").length;
    if (incomplete===rows().length) return aviso("Ingrese información de al menos una muestra.", "warn");
    if (incomplete && !$("parcial").checked) {
      $("parcial-wrap").classList.add("is-alert"); $("parcial").focus();
      return aviso("Quedan " + incomplete + " muestra" + (incomplete === 1 ? "" : "s") + " sin identificar. Marque “Guardar aunque queden muestras pendientes” para guardar el avance, o complete todas.", "warn");
    }
    if (d.metodo === "tabular") {
      const req = fields().filter(k => campoDef(k).obligatorio);
      const faltan = rows().filter(m => state(m.etiqueta) !== "pendiente" && req.some(k => !String(m.etiqueta?.campos?.[k] || "").trim()));
      if (faltan.length) return aviso("Complete los campos obligatorios (*) en: " + faltan.slice(0, 8).map(m => m.codigo_lab).join(", ") + (faltan.length > 8 ? "…" : ""), "warn");
    }
    aviso("Guardando identificación…", "");
    d.motivo = $("motivo").value.trim();
    saving=true; $("guardar").disabled=true;
    $("editor-content").querySelectorAll("input,textarea,select,button").forEach(el=>el.disabled=true);
    try {
      const response=await fetch("../controllers/identificacion_lote.php",{method:"POST",headers:{"Content-Type":"application/json",Accept:"application/json"},
        body:JSON.stringify({id_solicitud:current.id_solicitud,version:d.version,metodo:d.metodo,plantilla:d.plantilla || template,motivo:d.motivo,muestras:d.muestras,confirmar_pendientes:$("parcial").checked,csrf_token:csrf})});
      if (response.redirected) throw new Error("La sesión venció. Recargue la página; su borrador se conserva.");
      let result;
      try { result=await response.json(); } catch { throw new Error("El servidor no devolvió una respuesta válida. Su borrador se conserva."); }
      if (!response.ok || result.error) throw new Error(result.error || "No se pudo guardar.");
      current=result.lote;
      lotes=lotes.map(l=>l.id_solicitud===current.id_solicitud?current:l);
      drafts.delete(current.id_solicitud);
      try { sessionStorage.removeItem(key(current.id_solicitud)); } catch {}
      aviso("✓ Identificación guardada · Lote " + current.codigo_lote + " · versión " + current.version + ". Para corregirla después, use Editar identificación.", "ok");
      kpis(); renderList();
    } catch(error) { aviso(error.message, "warn"); }
    finally { saving=false; $("editor-content").querySelectorAll("input,textarea,select,button").forEach(el=>el.disabled=false); renderEditor(); }
  }
  // Filtro de lote (por recepción): se limita al tipo elegido y conserva la selección si sigue disponible.
  function baseLotes() {
    const sel=$("base-lote"), previo=sel.value, tipo=$("base-tipo").value;
    const lista=lotes.filter(l=>!tipo||l.tipo_clave===tipo).slice().sort((a,b)=>String(a.codigo_lote).localeCompare(String(b.codigo_lote),"es",{numeric:true})||String(a.tipo).localeCompare(String(b.tipo),"es"));
    sel.innerHTML='<option value="">Todos los lotes ('+lista.length+')</option>'+lista.map(l=>{
      return '<option value="'+l.id_solicitud+'">Lote '+esc(l.codigo_lote)+" · "+esc(tipoLabel(l.tipo))+" · "+esc(l.cliente||"Sin cliente")+" ("+l.muestras.length+" muestras)</option>";}).join("");
    sel.value=lista.some(l=>String(l.id_solicitud)===previo)?previo:"";
  }
  function renderBase() {
    const q=norm($("base-buscar").value), tipo=$("base-tipo").value, st=$("base-estado").value, lote=$("base-lote").value;
    baseRows=lotes.flatMap(l=>l.muestras.map(m=>({l,m}))).filter(({l,m})=>
      (!lote||String(l.id_solicitud)===lote)&&(!tipo||tipo===l.tipo_clave)&&(!st||st===state(m.etiqueta))&&(!q||norm([m.codigo_lab,l.codigo_lote,l.cliente,summary(m.etiqueta)].join(" ")).includes(q)))
      .sort((a,b)=>String(a.l.codigo_lote).localeCompare(String(b.l.codigo_lote),"es",{numeric:true})||a.l.id_solicitud-b.l.id_solicitud||String(a.m.codigo_lab).localeCompare(String(b.m.codigo_lab),"es",{numeric:true}));
    const nLotes=new Set(baseRows.map(r=>r.l.id_solicitud)).size;
    $("base-resumen").textContent=baseRows.length+" muestras · "+nLotes+" lote"+(nLotes===1?"":"s")+" · datos guardados";
    $("base-body").innerHTML=baseRows.map(({l,m})=>'<tr><td><b>'+esc(m.codigo_lab)+'</b></td><td>'+esc(l.codigo_lote)+'<small>'+esc(l.cliente)+'</small></td><td>'+esc(metodos[m.etiqueta.origen]||metodos[l.metodo]||"—")+'</td><td>'+esc(summary(m.etiqueta)||"—")+'</td><td>'+estados[state(m.etiqueta)]+'</td><td>'+esc(l.analisis||"Sin análisis solicitados")+'</td><td><button type="button" class="ident-btn ident-btn--ghost" data-open-lot="'+l.id_solicitud+'" data-sample="'+esc(m.codigo_lab)+'">Abrir lote</button> <a href="listar_lotes.php?muestra='+encodeURIComponent(m.codigo_lab)+'#ficha-individual">Ficha individual</a></td></tr>').join("")||'<tr><td colspan="7">Sin muestras con estos filtros.</td></tr>';
  }
  function exportCSV() {
    renderBase();
    const header=["Código de laboratorio","Lote","Tipo","Cliente","Método","Identificación","Lectura",...config.campos.map(c=>c.label),"Análisis solicitados","Guardado por","Fecha"];
    const values=baseRows.map(({l,m})=>[m.codigo_lab,l.codigo_lote,l.tipo,l.cliente,metodos[m.etiqueta.origen]||metodos[l.metodo]||"",estados[state(m.etiqueta)],m.etiqueta.info||"",...config.campos.map(c=>m.etiqueta.campos?.[c.key]||""),l.analisis,l.actualizado_por,l.actualizado_en]);
    const blob=new Blob(["\ufeff"+[header,...values].map(row=>row.map(csvCell).join(";")).join("\r\n")],{type:"text/csv;charset=utf-8;"});
    const url=URL.createObjectURL(blob), a=document.createElement("a"); a.href=url; a.download="identificacion_muestras.csv"; a.click(); setTimeout(()=>URL.revokeObjectURL(url),1000);
  }
  function renderLabels(reset=false) {
    const select=$("etq-lote"), previous=select.value;
    select.innerHTML=lotes.map(l=>'<option value="'+l.id_solicitud+'">'+esc(l.codigo_lote+" · "+(l.cliente||"Sin cliente"))+'</option>').join("");
    select.value=previous || String(current?.id_solicitud || lotes[0]?.id_solicitud || "");
    const lot=lotes.find(l=>l.id_solicitud===Number(select.value));
    if (!lot) { $("etq-preview").textContent="Sin lotes disponibles."; $("imprimir").disabled=true; return; }
    if(reset) printIds=new Set(lot.muestras.map(m=>m.id_muestra));
    $("etq-seleccion").innerHTML=lot.muestras.map(m=>'<label><input type="checkbox" data-print-id="'+m.id_muestra+'"'+(printIds.has(m.id_muestra)?" checked":"")+'> '+esc(m.codigo_lab)+'</label>').join("");
    const chosen=lot.muestras.filter(m=>printIds.has(m.id_muestra));
    $("imprimir").disabled=!chosen.length;
    $("etq-resumen").textContent=chosen.length+" etiquetas seleccionadas · "+Math.ceil(chosen.length/2)+" filas";
    $("etq-preview").innerHTML=chosen.map(m=>{
      let qr="";
      if($("etq-qr").checked && root.qrcode){const q=root.qrcode(0,"M");q.addData(m.codigo_lab);q.make();qr='<div class="iw-label-qr">'+q.createSvgTag({cellSize:2,margin:4,scalable:true})+'</div>';}
      const barcode=$("etq-barras").checked && /^[\x20-\x7e]+$/.test(m.codigo_lab) ? code128Svg(m.codigo_lab,36) : "";
      return '<article class="iw-label"><div><strong>CENGICAÑA</strong><b>'+esc(m.codigo_lab)+'</b><small>'+esc(lot.codigo_lote+" · "+lot.tipo)+'</small>'+barcode+'</div>'+qr+'</article>';
    }).join("");
  }
  const tabButtons=[...app.querySelectorAll("[data-work-tab]")];
  tabButtons.forEach((b,i)=>{
    b.tabIndex=i===0?0:-1;
    b.addEventListener("click",()=>tab(b.dataset.workTab));
    b.addEventListener("keydown",e=>{
      const index=e.key==="Home"?0:e.key==="End"?tabButtons.length-1:e.key==="ArrowRight"?(i+1)%tabButtons.length:e.key==="ArrowLeft"?(i+tabButtons.length-1)%tabButtons.length:-1;
      if(index<0)return;e.preventDefault();tab(tabButtons[index].dataset.workTab);tabButtons[index].focus();
    });
  });
  $("lote-buscar").addEventListener("input",renderList); $("lote-estado").addEventListener("change",renderList);
  $("lotes").addEventListener("click",e=>{const b=e.target.closest("[data-lot]");if(b) chooseLot(b.dataset.lot);});
  $("plantilla").addEventListener("change",()=>{if(!editable())return;template=$("plantilla").value;draft().plantilla=template;persist();renderGrid();});
  ["muestra-buscar","muestra-estado"].forEach(id=>$(id).addEventListener(id==="muestra-buscar"?"input":"change",renderGrid));
  $("limpiar-filtros").addEventListener("click",()=>{$("muestra-buscar").value="";$("muestra-estado").value="";renderGrid();});
  function scrollRow(i) {
    app.querySelector('#iw-grid-body tr[data-row="'+i+'"]')?.scrollIntoView?.({block:"nearest",inline:"nearest"});
  }
  function focusRow(i, field) {
    const target=app.querySelector('[data-row-index="'+i+'"]'+(field?'[data-field="'+field+'"]':''));
    if(target){target.focus();target.scrollIntoView?.({block:"nearest",inline:"nearest"});}
  }
  $("siguiente-pendiente").addEventListener("click",()=>{
    const indices=rows().map((m,i)=>i).filter(i=>state(rows()[i].etiqueta)==="pendiente");
    if(!indices.length)return msg("Todas las muestras tienen identificación.");
    destino=indices.find(i=>i>destino) ?? indices[0];
    $("muestra-buscar").value="";$("muestra-estado").value="pendiente";renderGrid();
    focusRow(destino);if(draft()?.metodo!=="tabular"&&editable())$("lectura").focus();
  });
  $("grid-body").addEventListener("keydown",e=>{
    if(e.key!=="Enter"||!e.target.dataset.field||!editable())return;
    e.preventDefault();const position=visibleIndices.indexOf(Number(e.target.dataset.rowIndex));
    const next=visibleIndices[position+(e.shiftKey?-1:1)];if(next!==undefined)focusRow(next,e.target.dataset.field);
  });
  app.querySelectorAll("[data-metodo]").forEach(b=>b.addEventListener("click",()=>setMetodo(b.dataset.metodo)));
  $("deshacer").addEventListener("click",deshacerLectura);
  $("emparejar").addEventListener("change",()=>{emparejada=-1;updateDestination();$("lectura").focus();});
  $("volver").addEventListener("click",()=>{showView("lotes");renderList();});
  $("lote-tipo").addEventListener("change",renderList);
  $("edit").addEventListener("click",()=>{if(!current||saving)return;$("save-msg").hidden=true;drafts.set(current.id_solicitud,createDraft(current));renderEditor();$("motivo").focus();});
  $("motivo").addEventListener("input",()=>{if(draft()){draft().motivo=$("motivo").value;persist();}});
  $("descartar").addEventListener("click",()=>{
    if(!current||saving)return;
    if(draft()?.dirty&&!root.confirm("¿Descartar los cambios sin guardar de este lote?"))return;
    try{sessionStorage.removeItem(key(current.id_solicitud));}catch{}
    drafts.delete(current.id_solicitud);if(!current.version)drafts.set(current.id_solicitud,createDraft(current));
    msg("Se cargaron los datos guardados.");renderList();renderEditor();
  });
  $("grid-body").addEventListener("input",e=>{
    if(!editable())return;
    const i=Number(e.target.dataset.rowIndex), m=rows()[i];if(!m)return;
    m.etiqueta ||= {campos:{}};
    if(e.target.hasAttribute("data-field")) {m.etiqueta.campos = plainObj(m.etiqueta.campos);m.etiqueta.campos[e.target.dataset.field]=e.target.value;m.etiqueta.info="";m.etiqueta.origen="tabulado";}
    if(e.target.hasAttribute("data-info")) {m.etiqueta.info=e.target.value;m.etiqueta.contenido=onlyId(e.target.value)?"solo_id":"completa";m.etiqueta.campos={};m.etiqueta.origen=draft().metodo;const select=app.querySelector('[data-content="'+i+'"]');if(select)select.value=m.etiqueta.contenido;}
    persist();
  });
  $("grid-body").addEventListener("change",e=>{if(!editable()||!e.target.hasAttribute("data-content"))return;rows()[Number(e.target.dataset.content)].etiqueta.contenido=e.target.value;persist();});
  $("grid-body").addEventListener("click",e=>{const b=e.target.closest("[data-destination]");if(b&&editable()){destino=Number(b.dataset.destination);emparejada=destino;renderGrid();$("lectura").focus();}});
  $("grid-head").addEventListener("click",e=>{
    const b=e.target.closest("[data-fill]");if(!b||!editable())return;
    const k=b.dataset.fill,v=rows()[visibleIndices[0]]?.etiqueta?.campos?.[k];if(!v?.trim())return msg("Complete primero esa celda de la primera fila visible.");
    const targets=visibleIndices.filter(i=>!rows()[i].etiqueta?.campos?.[k]?.trim());
    if(!targets.length)return msg("No hay celdas vacías para rellenar.");
    if(!root.confirm("¿Copiar este valor a "+targets.length+" muestras filtradas con la celda vacía?"))return;
    targets.map(i=>rows()[i]).forEach(m=>{m.etiqueta ||= {};m.etiqueta.campos = plainObj(m.etiqueta.campos);if(!m.etiqueta.campos[k]?.trim()){m.etiqueta.campos[k]=v;m.etiqueta.info="";m.etiqueta.origen="tabulado";}});
    persist();renderGrid();
  });
  $("grid-body").addEventListener("paste",e=>{
    if(!editable()||!e.target.dataset.field)return;
    const text=e.clipboardData.getData("text/plain");if(!/[\t\n]/.test(text))return;
    e.preventDefault();
    try{
      const values=parseTSV(text), i=visibleIndices.indexOf(Number(e.target.dataset.rowIndex)), cols=fields(), start=cols.indexOf(e.target.dataset.field);
      if(i<0||i+values.length>visibleIndices.length||values.some(r=>start+r.length>cols.length))throw new Error("El bloque excede las filas filtradas o columnas disponibles. No se pegó ningún dato.");
      if(values.some(r=>r.some(v=>v.length>1000)))throw new Error("Una celda supera los 1000 caracteres.");
      values.forEach((r,ri)=>{const m=rows()[visibleIndices[i+ri]];m.etiqueta ||= {};m.etiqueta.campos = plainObj(m.etiqueta.campos);r.forEach((v,ci)=>m.etiqueta.campos[cols[start+ci]]=v.trim());m.etiqueta.info="";m.etiqueta.origen="pegado";});
      persist();renderGrid();msg(values.length+" filas pegadas. Recuerde guardar.");
    }catch(error){msg(error.message);}
  });
  $("lectura").addEventListener("keydown",e=>{if(e.key!=="Enter"||e.shiftKey)return;e.preventDefault();$("lectura").value+="\n";clearTimeout(scanTimer);scanTimer=setTimeout(()=>receiveScan($("lectura").value),180);});
  $("asignar").addEventListener("click",()=>{clearTimeout(scanTimer);receiveScan($("lectura").value);});
  let cameraLot = null, cameraRow = null;
  app.querySelector("[data-batch-camera]").addEventListener("click",()=>{cameraLot=current?.id_solicitud;cameraRow=destino;});
  document.querySelector("[data-ident-page]").addEventListener("ident:lectura",e=>{
    if(current?.id_solicitud!==cameraLot||destino!==cameraRow)return msg("El destino cambió durante la lectura. Vuelva a escanear.");
    receiveScan(e.detail);
  });
  $("guardar").addEventListener("click",save);
  $("parcial").addEventListener("change",()=>{ $("parcial-wrap").classList.remove("is-alert"); if($("parcial").checked) $("save-msg").hidden = true; });
  ["base-buscar","base-estado","base-lote"].forEach(id=>$(id).addEventListener(id==="base-buscar"?"input":"change",renderBase));
  $("base-tipo").addEventListener("change",()=>{baseLotes();renderBase();});
  $("csv").addEventListener("click",exportCSV);
  $("base-body").addEventListener("click",e=>{const b=e.target.closest("[data-open-lot]");if(b){chooseLot(b.dataset.openLot,b.dataset.sample);tab("registro");}});
  $("etq-lote").addEventListener("change",()=>renderLabels(true));
  ["etq-barras","etq-qr"].forEach(id=>$(id).addEventListener("change",()=>renderLabels()));
  $("etq-seleccion").addEventListener("change",e=>{if(!e.target.dataset.printId)return;const id=Number(e.target.dataset.printId);if(e.target.checked)printIds.add(id);else printIds.delete(id);renderLabels();});
  $("imprimir").addEventListener("click",()=>{let area=document.getElementById("iw-print-area");if(!area){area=document.createElement("div");area.id="iw-print-area";document.body.appendChild(area);}area.innerHTML='<div class="iw-label-sheet">'+$("etq-preview").innerHTML+"</div>";document.body.classList.add("iw-printing");try{root.print();}catch(error){document.body.classList.remove("iw-printing");msg("No se pudo abrir la impresión.");}});
  root.addEventListener("afterprint",()=>document.body.classList.remove("iw-printing"));
  root.addEventListener("beforeunload",e=>{if([...drafts.values()].some(d=>d.dirty)){e.preventDefault();e.returnValue="";}});
  kpis();
  const initial=lotes.find(l=>l.id_solicitud===Number(app.dataset.initialLote)||l.muestras.some(m=>m.codigo_lab===app.dataset.initialSample));
  const tipos=[...new Map(lotes.map(l=>[l.tipo_clave,tipoLabel(l.tipo)])).entries()].sort((a,b)=>a[1].localeCompare(b[1],"es"));
  $("lote-tipo").insertAdjacentHTML("beforeend",tipos.map(([k,t])=>'<option value="'+esc(k)+'">'+esc(t)+'</option>').join(""));
  if(initial)chooseLot(initial.id_solicitud,app.dataset.initialSample);else showView("lotes");
  renderList();
})(window);
