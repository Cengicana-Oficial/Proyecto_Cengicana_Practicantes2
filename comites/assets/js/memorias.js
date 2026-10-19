(() => {
  'use strict';
  const source = document.getElementById('mem-data'); if (!source) return;
  let data = JSON.parse(source.textContent);
  const root = document.getElementById('biblioteca-ingenios'), $ = id => document.getElementById(id), endpoint = 'index.php?view=memorias&memorias_api=1';
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const num = v => Number(v) || 0, norm = v => String(v || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const params = new URLSearchParams(location.search);
  const state = { folder: num(params.get('edicion')), filter: 'todos', query: '', tab: params.get('tab') === 'mpr' ? 'mpr' : 'articulos', year: 0, busy: false };
  if (!data.ediciones.some(e => e.id === state.folder)) state.folder = 0;
  let snapshot = JSON.stringify(data);
  const fmt = v => v ? esc(String(v).slice(0, 10).split('-').reverse().join('/')) : 'Sin definir';
  const editionYear = e => num(e.anio) || num(data.hoy.slice(0, 4));
  const syncUrl = () => { try { history.replaceState(null, '', 'index.php?view=memorias' + (state.folder ? '&edicion=' + state.folder + (state.tab === 'mpr' ? '&tab=mpr' : '') : '')); } catch (_) { /* La URL es solo una comodidad. */ } };
  state.year = num(data.ediciones.find(e=>e.id===state.folder)?.anio);
  const filters = { todos: 'Todos', mios: 'Pendientes de mí', entregado: 'Sin revisor', revision: 'En revisión', observaciones: 'Con observaciones', listo: 'Listos para diagramación', diagramacion: 'Descargados' };
  const logs = { plazos: 'Calendario de revisión actualizado', participacion_editorial: 'Participación editorial finalizada', revision_cerrada: 'Revisión cerrada', plazo_correccion: 'Plazo de corrección configurado', cierre_calendario: 'Cierre de etapa por calendario', editorial_automatico: 'Envío automático a Transferencia', aprobado_comite: 'Aprobación editorial', aprobado_transferencia: 'Validación de Transferencia', aprobado_direccion: 'Liberación de Dirección', editorial_cerrado: 'Revisión editorial cerrada', entregado: 'Artículo entregado', asignado: 'Revisor asignado', reasignado: 'Revisor reasignado', declinado: 'Revisor declinó', revisado: 'Revisión enviada al autor', corregido: 'Versión corregida entregada', diagramacion: 'Descarga por diagramación', cargado: 'Consolidado cargado', atendidas: 'Nueva versión del consolidado', observacion: 'Observación final', aprobado_jt: 'Aprobación de Transferencia', aprobado: 'Liberación de Dirección', publicado: 'Publicación registrada' };
  const btn = (action, text, id = '', cls = '', attrs = '') => `<button type="button" class="bib-btn ${cls}" data-mem="${action}" data-id="${esc(id)}" ${attrs}>${text}</button>`;
  const input = (label, name, value = '', type = 'text', attrs = '') => `<label>${label}<input class="bib-input" type="${type}" name="${name}" value="${esc(value)}" ${attrs}></label>`;
  const area = (label, name, value = '') => `<label class="bib-wide">${label}<textarea class="bib-input" name="${name}" rows="3">${esc(value)}</textarea></label>`;
  const opts = (list, value) => list.map(([id, name]) => `<option value="${esc(id)}" ${String(id) === String(value) ? 'selected' : ''}>${esc(name)}</option>`).join('');
  const select = (label, name, list, value) => `<label>${label}<select class="bib-input" name="${name}">${opts(list, value)}</select></label>`;
  const check = (label, name, yes = false) => `<label class="bib-wide"><input type="checkbox" name="${name}" ${yes ? 'checked' : ''}> ${label}</label>`;
  const file = (label, name, required = false, accept = '.pdf,.doc,.docx,.xls,.xlsx') => input(label + ' (hasta 20 MB)', name, '', 'file', `accept="${accept}" ${required ? 'required' : ''}`);
  const badge = (state, text) => `<span class="bib-badge ${esc(state)}">${esc(text)}</span>`;
  const form = (action, content, r = {}, label = 'Guardar') => `<form data-form="${action}" data-id="${num(r.id)}" data-revision="${num(r.revision_control || r.revision)}"><div class="bib-fields">${content}</div><div class="bib-form-actions">${btn('cerrar', 'Cancelar')}<button class="bib-btn primary" type="submit">${label}</button></div></form>`;
  const urlFile = (scope, id, key = 'actual', index = -1, attachment = 0) => `index.php?view=memorias&descarga=1&scope=${scope}&id=${num(id)}&archivo=${key}&indice=${index}&adjunto=${attachment}`;
  const linkFile = (f, scope, id, key = 'actual', index = -1, attachment = 0, label = '') => f?.ruta ? `<a class="bib-btn small" href="${urlFile(scope, id, key, index, attachment)}"><i class="ti ti-download"></i> ${esc(label || f.nombre || 'Descargar')}</a>` : '<span class="bib-note">Pendiente de cargar</span>';
  const articleFile = r => ({ nombre: r.archivo_nombre, ruta: r.archivo_ruta });
  const isMine = r => r.asignar && r.flujo === 'entregado' || r.revisar || r.corregir || r.diagramar && r.flujo === 'listo';
  const matches = (r, f) => f === 'todos' || (f === 'mios' ? isMine(r) : r.flujo === f);
  const reviewer = r => data.revisores.find(v => v.id === num(r.revisor_contacto_id));
  const pendingObs = m => m.observaciones.filter(o => o.estado !== 'atendida');
  const canObserve = () => data.permisos.admin || data.permisos.jt || data.permisos.dir || data.revisores.some(r => norm(r.email).trim() === norm(data.usuario.correo).trim() && r.email);
  function modal(title, html, wide = false) { $('bib-title').textContent = title; $('bib-modal').innerHTML = html; $('bib-error').hidden = true; $('bib-dialog').classList.toggle('wide', wide); if (!$('bib-dialog').open) $('bib-dialog').showModal(); }
  function message(text, error = false) { $('bib-message').textContent = text; $('bib-message').hidden = !text; $('bib-message').classList.toggle('error', error); }
  function fail(e) { if ($('bib-dialog').open) { $('bib-error').textContent = e.message; $('bib-error').hidden = false; } else message(e.message, true); }
  async function request(action, values = {}, files = {}) {
    const fd = new FormData(); fd.set('mem_accion', action); fd.set('csrf', data.csrf); fd.set('datos', JSON.stringify(values));
    let total = 0; Object.entries(files).forEach(([key, f]) => { if (f?.size) { if (f.size > 20 * 1048576) throw new Error('Cada archivo admite hasta 20 MB.'); total += f.size; fd.set(key, f); } }); if (total > 24 * 1048576) throw new Error('Los archivos del formulario superan 24 MB en conjunto. Reduce su tamaño.');
    const response = await fetch(endpoint, { method: 'POST', body: fd }), result = await response.json(); if (!response.ok || !result.ok) throw new Error(result.error || 'No se pudo completar la operación.'); data = result.datos; snapshot = JSON.stringify(data); render(); message(result.mensaje);
  }
  function render() {
    const edition = data.ediciones.find(e => e.id === state.folder), year = edition ? editionYear(edition) : state.year;
    // Dentro de una memoria, filtros y búsqueda se limitan a sus artículos; fuera de ella buscan en todas.
    const base = edition ? data.articulos.filter(r => r.edicion_id === edition.id) : data.articulos;
    const global = !edition && (state.filter !== 'todos' || !!state.query.trim());
    const showMpr = !!(edition?.mpr && state.tab === 'mpr');
    const canDeliver = data.permisos.entregar && data.ediciones.some(e => e.admite_articulos !== false) && edition?.admite_articulos !== false;
    $('mem-actions').innerHTML = (data.permisos.coord ? btn('revisores', '<i class="ti ti-users"></i> Revisores') + (edition ? '' : btn('carpeta-anio', '<i class="ti ti-folder-plus"></i> Carpeta por año') + btn('edicion', '<i class="ti ti-plus"></i> Nueva memoria', '', 'primary')) : '') + (canDeliver ? btn('entregar', '<i class="ti ti-upload"></i> Entregar artículo', '', edition ? 'primary' : '') : '');
    $('mem-crumbs').innerHTML = btn('abrir', '<i class="ti ti-books"></i> Memorias', 0) + (year ? '<span>/</span>' + btn('abrir-anio', year, year) : '') + (edition ? '<span>/</span><b aria-current="page">' + esc(edition.nombre) + '</b>' : global ? '<span>/</span><b aria-current="page">Resultados de búsqueda</b>' : '');
    $('mem-notices').innerHTML = data.avisos.map(a => `<div class="bib-notice blue"><span>${esc(a.mensaje)}<small> · ${esc(a.creado_en)}</small></span>${btn('aviso', 'Marcar leído', a.id, 'small')}</div>`).join('');
    $('mem-filters').innerHTML = Object.entries(filters).map(([f, label]) => { const n = base.filter(r => matches(r, f)).length; return btn('filtro', `${label} <span class="mem-chip-count">${n}</span>`, f, 'bib-pill mem-chip ' + (state.filter === f ? 'primary' : n ? '' : 'is-empty'), `aria-pressed="${state.filter === f}"`); }).join('');
    $('mem-search').placeholder = edition ? 'Buscar en esta memoria…' : 'Buscar en todas las memorias…';
    $('mem-filterbar').hidden = showMpr;
    const rows = base.filter(r => matches(r, state.filter) && norm(`${r.numero_memoria} ${r.titulo} ${r.autor} ${r.area}`).includes(norm(state.query)));
    let html = '';
    if (global) html += `<div class="mem-section-head"><h2>Resultados en todas las memorias <span class="mem-count">${rows.length}</span></h2>${btn('limpiar', '<i class="ti ti-x"></i> Limpiar búsqueda', '', 'small')}</div>`;
    else if (!edition && !state.year) {
      const years = [...new Set([...(data.carpetas_anio || []), ...data.ediciones.map(editionYear)])].sort((a, b) => b - a);
      html += `<div class="mem-folders">${years.map(y => { const eds = data.ediciones.filter(e => editionYear(e) === y), arts = data.articulos.filter(r => eds.some(e => e.id === r.edicion_id)); return `<button type="button" class="mem-folder" data-mem="abrir-anio" data-id="${y}"><i class="ti ti-folder"></i><span><strong>${y}</strong><small>${eds.length} memoria(s) · ${arts.length} artículo(s)</small></span></button>`; }).join('') || '<div class="bib-empty">Aún no hay carpetas. Crea una carpeta por año o una nueva memoria.</div>'}</div>`;
    } else if (!edition) {
      const eds = data.ediciones.filter(e => editionYear(e) === state.year);
      html += `<div class="mem-section-head"><h2>Memorias ${state.year} <span class="mem-count">${eds.length}</span></h2></div><div class="mem-editions">${eds.map(editionCard).join('') || '<div class="bib-empty">Esta carpeta no tiene memorias.</div>'}</div>`;
    } else {
      const own = base.length, tabs = [['articulos', `<i class="ti ti-file-text"></i> Artículos <span class="mem-chip-count">${own}</span>`], ['mpr', `<i class="ti ti-book-upload"></i> Consolidación y publicación ${edition.mpr ? badge(edition.mpr.estado, data.estados_mpr[edition.mpr.estado]) : ''}`]];
      html += `<section class="mem-edition-head"><div class="mem-edition-top"><div class="mem-edition-title"><span class="mem-eyebrow">Memoria ${esc(year)}</span><h2>${esc(edition.nombre)}</h2><div class="mem-meta"><span class="${edition.limite_entrega && edition.limite_entrega < data.hoy ? 'mem-late' : ''}"><i class="ti ti-calendar-due"></i> Entrega hasta ${fmt(edition.limite_entrega)}</span><span><i class="ti ti-clock"></i> Revisión: ${num(edition.dias_revision)} días</span>${edition.admite_articulos === false ? '<span><i class="ti ti-lock"></i> Cerrada a nuevas entregas</span>' : ''}</div></div><div class="mem-edition-tools">${edition.criterios?.ruta ? linkFile(edition.criterios, 'edicion', edition.id, 'criterios', -1, 0, 'Criterios') : ''}${edition.plantilla?.ruta ? linkFile(edition.plantilla, 'edicion', edition.id, 'plantilla', -1, 0, 'Plantilla') : ''}${data.permisos.coord ? btn('edicion', '<i class="ti ti-settings"></i> Configurar', edition.id, 'small') : ''}</div></div>${edition.mpr ? `<div class="mem-tabs" role="tablist" aria-label="Secciones de la memoria">${tabs.map(([key, label]) => `<button type="button" id="mem-tab-${key}" role="tab" aria-selected="${showMpr === (key === 'mpr')}" aria-controls="mem-panel-${key}" data-mem="pestana" data-id="${key}" class="mem-tab">${label}</button>`).join('')}</div>` : ''}</section>`;
      if (edition.mpr) html += `<section id="mem-panel-mpr" role="tabpanel" aria-labelledby="mem-tab-mpr" ${showMpr ? '' : 'hidden'}>${showMpr ? mprPanel(edition) : ''}</section>`;
    }
    if (global || edition) {
      const filtered = state.filter !== 'todos' || state.query.trim();
      const empty = filtered ? `No hay artículos con este filtro. ${btn('limpiar', 'Ver todos', '', 'small')}` : 'Esta memoria aún no tiene artículos entregados.';
      html += `<section id="mem-panel-articulos" ${edition?.mpr ? 'role="tabpanel" aria-labelledby="mem-tab-articulos"' : ''} ${showMpr ? 'hidden' : ''}><div class="bib-scroll"><table class="bib-table mem-table"><thead><tr><th>Artículo</th><th>Autor / revisor</th><th>Estado</th><th class="mem-col-actions">Acciones</th></tr></thead><tbody>${rows.map(r => row(r, !edition)).join('') || `<tr><td colspan="4"><div class="bib-empty">${empty}</div></td></tr>`}</tbody></table></div></section>`;
    }
    $('mem-content').innerHTML = html;
  }
  function editionCard(e) {
    const own = data.articulos.filter(r => r.edicion_id === e.id), late = e.limite_entrega && e.limite_entrega < data.hoy;
    const states = Object.entries(data.estados).map(([key, text]) => { const n = own.filter(r => r.flujo === key).length; return n ? badge(key, n + ' · ' + text) : ''; }).join('');
    return `<button type="button" class="mem-edition" data-mem="abrir" data-id="${e.id}"><span class="mem-edition-name"><i class="ti ti-book"></i> ${esc(e.nombre)}</span><span class="mem-meta"><span><i class="ti ti-file-text"></i> ${own.length} artículo(s)</span><span class="${late ? 'mem-late' : ''}"><i class="ti ti-calendar-due"></i> ${fmt(e.limite_entrega)}</span><span><i class="ti ti-${e.criterios ? 'circle-check' : 'alert-circle'}"></i> Criterios ${e.criterios ? 'cargados' : 'pendientes'}</span></span>${e.mpr ? `<span class="mem-edition-mpr">MPR ${badge(e.mpr.estado, data.estados_mpr[e.mpr.estado] + (e.mpr.version ? ' · v' + num(e.mpr.version) : ''))}</span>` : ''}${states ? `<span class="mem-edition-states">${states}</span>` : ''}</button>`;
  }
  function row(r, showEdition = false) {
    let action = ''; if (r.asignar) action = btn('asignar', r.flujo === 'revision' ? 'Reasignar' : 'Asignar revisor', r.id, 'small primary');
    if (r.revisar) action += btn('revisar', 'Revisar artículo', r.id, 'small primary');
    if (r.corregir) action = btn('corregir', 'Atender observaciones', r.id, 'small danger');
    if (r.diagramar) action = btn('diagramar', r.flujo === 'listo' ? `Descargar versión corregida (v${r.version})` : 'Descargar otra vez', r.id, 'small primary');
    if (!action) action = `<span class="bib-note">${r.flujo === 'listo' ? 'Le toca a Diagramación' : r.flujo === 'observaciones' ? 'Le toca al autor' : r.flujo === 'revision' ? 'Esperando revisión' : r.flujo === 'entregado' ? 'Esperando asignación' : 'Descarga registrada'}</span>`;
    const days = r.limite_revision ? Math.ceil((Date.parse(r.limite_revision) - Date.parse(data.hoy)) / 86400000) : null;
    const edition = showEdition ? data.ediciones.find(e => e.id === r.edicion_id) : null;
    const deadline = r.flujo === 'revision' && days !== null ? `<span class="mem-deadline ${days < 0 ? 'mem-late' : days <= 3 ? 'is-soon' : ''}"><i class="ti ti-clock"></i> ${days < 0 ? 'Vencida hace ' + -days + ' día(s)' : days === 0 ? 'Vence hoy' : 'Vence en ' + days + ' día(s)'}</span>` : '';
    return `<tr><td><strong>${esc(r.titulo)}</strong><span class="bib-meta">${esc(r.numero_memoria)} · ${esc(r.area || '—')} · entregado ${fmt(r.fecha)}${edition ? ' · ' + esc(edition.nombre) : ''}</span></td><td>${esc(r.autor)}<span class="bib-meta">Revisor: ${esc(reviewer(r)?.nombre || (r.revisor_contacto_id ? 'Asignado (fuera del grupo actual)' : 'sin asignar'))}</span></td><td><span class="mem-state">${badge(r.flujo, data.estados[r.flujo])}<span class="mem-version">v${num(r.version)}</span></span>${deadline}</td><td class="mem-col-actions"><details class="bib-menu"><summary class="bib-btn small"><i class="ti ti-dots-vertical"></i> Acciones <i class="ti ti-chevron-down"></i></summary><div class="bib-action-list" role="menu"><div class="bib-menu-main">${action}</div>${btn('track', '<i class="ti ti-history"></i> Seguimiento', r.id, 'small')}${linkFile(articleFile(r), 'articulo', r.id, 'actual', -1, 0, 'Descargar')}${data.permisos.coord ? btn('eliminar', '<i class="ti ti-trash"></i> Eliminar', r.id, 'small danger') : ''}</div></details></td></tr>`;
  }
  function calendarStage(m) {
    if (m.fase_revision) return ['aprobado','publicado'].includes(m.estado) || m.fase_revision === 'correccion' ? null : m.fase_revision;
    if (!m.fechas_etapa || !['revision_final','aprobado_jt'].includes(m.estado)) return null;
    if (m.revisiones?.transferencia?.fin) return 'direccion';
    if (m.revisiones?.comite?.fin && data.hoy <= m.fechas_etapa.transferencia.limite) return 'transferencia';
    return data.hoy <= m.fechas_etapa.comite.limite ? 'comite' : data.hoy <= m.fechas_etapa.transferencia.limite ? 'transferencia' : 'direccion';
  }
  function mprPanel(e) {
    const m = e.mpr, pending = pendingObs(m), articles = data.articulos.filter(r => r.edicion_id === e.id);
    const ready = articles.filter(r => ['listo', 'diagramacion'].includes(r.flujo)), downloaded = articles.filter(r => r.flujo === 'diagramacion'), waiting = articles.length - ready.length;
    const step = ['armado', 'revision_final', 'aprobado_jt', 'aprobado', 'publicado'].indexOf(m.estado);
    let buttons = '';

    if (canObserve() && [1, 2].includes(step)) buttons += btn('mpr-observar', 'Agregar observación final', e.id);
    if (data.permisos.jt && step === 1 && (!m.dias_etapa || m.revisiones?.comite?.fin) || data.permisos.dir && step === 2) buttons += btn('mpr-aprobar', step === 1 ? 'Aprobar versión final' : 'Aprobar liberación', e.id, 'primary');
    if (data.permisos.diag && step === 3) buttons += btn('mpr-publicar', 'Registrar publicación MPR', e.id, 'primary');
    if (data.permisos.coord && step === 1 && m.dias_etapa && !m.revisiones?.comite?.fin) buttons += btn('mpr-cerrar_editorial', 'Cerrar revisión editorial y enviar a Transferencia', e.id, 'primary');
    if (m.fechas_etapa) {
      buttons = '';
      if (data.permisos.diag && step === 3) buttons += btn('mpr-publicar', 'Registrar publicación MPR', e.id, 'primary');
    }
    const canUpload = data.permisos.diag && step >= 0 && step < 4 && (!m.fase_revision || m.fase_revision === 'correccion' || m.estado === 'aprobado');
    const next = [
      ['Diagramación', 'Descargar los artículos corregidos del listado, armar el documento fuera del sistema y cargar el consolidado MPR.'],
      ['Comité Editorial → Transferencia', pending.length ? 'Revisar las observaciones pendientes. Diagramación puede cargar una nueva versión con las correcciones; Transferencia aprueba la versión final.' : 'Revisar el documento vigente y registrar observaciones si corresponde. Después, Transferencia aprueba la versión final.'],
      ['Dirección General', 'Revisar la versión aprobada por Transferencia y aprobar su liberación.'],
      ['Diagramación', 'Publicar el documento aprobado y registrar aquí la fecha y el enlace de publicación.'],
      ['Proceso completado', 'La publicación del MPR está registrada. Puedes consultar el documento y su historial.']
    ][step] || ['Seguimiento', 'Consulta el historial del consolidado.'];
    if (step === 1 && m.dias_etapa) { next[0] = m.revisiones?.comite?.fin ? 'Transferencia · revisión y validación' : 'Comité Editorial · observaciones'; next[1] = m.revisiones?.comite?.fin ? 'Revisar el documento y validar la versión final para iniciar el plazo de Dirección General.' : 'El Comité Editorial registra sus observaciones. Después de atenderlas, coordinación cierra esta revisión e inicia el plazo de Transferencia.'; }
    if (m.fechas_etapa && calendarStage(m)) {
      const key=calendarStage(m), p=m.fechas_etapa[key];
      next[0]={comite:'Comité Editorial',transferencia:'Jefe de Transferencia',direccion:'Dirección General'}[key];
      next[1]=data.hoy<(m.revisiones?.[key]?.habilitada_desde || p.inicio)?'Esta revisión se habilita el '+p.inicio.split('-').reverse().join('/')+'.':data.hoy>p.limite?'La fecha de esta revisión terminó. Coordinación puede reprogramar el calendario.':'Observaciones y aprobación disponibles hasta el '+p.limite.split('-').reverse().join('/')+' inclusive. '+(key==='direccion'?'La aprobación final autoriza la liberación.':'Al aprobar se habilita inmediatamente la siguiente etapa. El calendario también permite avanzar al vencer el plazo.');
    }
    if(m.fase_revision){
      const phase=calendarStage(m);
      if(m.fase_revision==='correccion'){next[0]='Diagramación · correcciones';next[1]='Atender las observaciones y cargar una nueva versión. El siguiente revisor permanece bloqueado hasta que no queden pendientes.';}
      else if(phase==='comite'){next[0]='Comité Editorial · participación de integrantes';next[1]='Cada integrante puede registrar observaciones. Cuando todos registren al menos una, o venza la fecha límite, se cierra la ronda y se corrige antes de enviarla a Transferencia.';}
      else if(phase==='transferencia'){next[0]='Transferencia';next[1]='Revisar la versión recibida. Sin observaciones puede aprobar y enviar a Dirección; al registrar observaciones comienza una corrección antes de habilitar Dirección.';}
      else if(phase==='direccion'){next[0]='Dirección General';next[1]='Revisión final. Si hay observaciones, Diagramación corrige y Dirección recibe la nueva versión para validar la liberación.';}
    }
    const stage = m.fechas_etapa && calendarStage(m) ? {comite:1,transferencia:2,direccion:3}[calendarStage(m)] : step === 0 ? 0 : step === 1 ? (m.revisiones?.comite?.fin ? 2 : 1) : step + 1;
    const stages = ['Archivo diagramado', 'Comité Editorial', 'Transferencia', 'Dirección General', 'Liberación', 'Publicación'];
    const progress = `<ol class="mem-mpr-stages" aria-label="Etapas del consolidado">${stages.map((name, i) => `<li class="${i < stage ? 'complete' : i === stage ? 'current' : ''}" ${i === stage ? 'aria-current="step"' : ''}><b>${i < stage ? '✓' : i + 1}</b><span>${name}<small>${i < stage ? (m.fechas_etapa && [1,2].includes(i) ? 'Plazo cerrado' : 'Completado') : i === stage ? (m.fase_revision==='correccion'?'En corrección':stage === 5 ? 'Registrada' : 'Etapa actual') : 'Pendiente'}</small></span></li>`).join('')}</ol>`;
    const observations = mprObservations(e);
    return `<section class="bib-panel mem-mpr" aria-labelledby="mem-mpr-title">
      <div class="mem-mpr-heading"><div><h2 id="mem-mpr-title">Documento consolidado (MPR)</h2><p class="bib-note">Un documento reúne los artículos corregidos de esta memoria.</p></div>${badge(m.estado, m.fase_revision === 'correccion' ? 'En corrección · Diagramación' : m.fechas_etapa && calendarStage(m) ? "En revisión · " + ({comite:"Comité Editorial",transferencia:"Transferencia",direccion:"Dirección General"}[calendarStage(m)]) : data.estados_mpr[m.estado])}</div>
      ${progress}${mprDeadlines(e)}${mprCorrection(e)}
      <div class="mem-mpr-grid"><div class="mem-mpr-document"><span class="mem-eyebrow">Documento vigente</span><h3>${m.version ? 'Consolidado · v' + num(m.version) : 'Consolidado por cargar'}</h3>${m.version ? linkFile(m.archivo, 'mpr', e.id) : '<p class="bib-note">Diagramación cargará aquí el archivo que arme con los artículos.</p>'}${canUpload ? `<div class="mem-document-upload">${btn('mpr-cargar', m.version ? 'Cargar nueva versión' : 'Cargar consolidado MPR', e.id, 'primary')}<p class="bib-note">Una versión puede atender observaciones de varios revisores.</p></div>` : ''}${m.version ? `<div class="mem-mpr-history">${btn('mpr-track', 'Ver versiones y seguimiento', e.id, 'small')}</div>` : ''}${m.publicacion ? `<p class="bib-note">Publicado: ${esc(m.publicacion.fecha)} ${/^https?:\/\//i.test(m.publicacion.enlace) ? `<a href="${esc(m.publicacion.enlace)}" target="_blank" rel="noopener noreferrer">Ver publicación</a>` : ''}</p>` : ''}</div>
      <div class="mem-mpr-next"><span class="mem-eyebrow">${step === 4 ? 'Publicación registrada' : 'Siguiente paso'}</span><h3>${next[0]}</h3><p>${next[1]}</p>${buttons ? `<div class="bib-row">${buttons}</div>` : ''}${m.version && step < 4 ? help('Cada versión conserva el historial. Una corrección completa habilita al siguiente revisor; la liberación final requiere aprobación de Dirección.') : ''}</div></div>
      <div class="mem-mpr-bridge"><div><h3>De los artículos al MPR</h3><p>Autor corrige → Diagramación descarga → arma el MPR → carga el consolidado.</p>${help('La descarga registra la entrega a Diagramación. La incorporación se realiza al armar el archivo; el sistema no confirma qué artículos contiene cada versión.')}</div><div class="mem-mpr-counts"><span><b>${ready.length}/${articles.length}</b> listos</span><span><b>${downloaded.length}</b> descargados</span><span class="${waiting ? 'is-warn' : ''}"><b>${waiting}</b> en proceso</span></div></div>
      <div class="mem-mpr-observation-count">${badge(pending.length ? 'observaciones' : 'listo', pending.length + ' observaciones pendientes')}<span class="bib-note">${m.observaciones.filter(o => o.estado === 'atendida').length} atendidas</span></div>${observations}
    </section>`;
  }
  const obsCode = (m,o) => 'OBS-' + String(m.observaciones.indexOf(o)+1).padStart(3,'0');
  function mprObservations(e) {
    const m=e.mpr;
    if(!m.observaciones.length)return '<p class="bib-note">Sin observaciones registradas. Cada integrante puede registrar varias observaciones sobre la misma versión.</p>';
    const groups=new Map();
    m.observaciones.forEach(o=>{const key=o.autor_usuario_id ? 'u:'+o.autor_usuario_id : o.autor_email ? 'e:'+norm(o.autor_email).trim() : 'n:'+o.autor; if(!groups.has(key))groups.set(key,{name:o.autor,items:[]});groups.get(key).items.push(o);});
    return `<div class="mem-observation-groups"><h3>Observaciones por revisor</h3><p class="bib-note">${groups.size} persona(s) con observaciones · ${m.observaciones.length} observaciones en total. Cada observación conserva su número y las versiones de origen y atención.</p>${[...groups.values()].map(g=>`<details class="mem-mpr-observations"><summary>${esc(g.name)} · ${g.items.filter(o=>o.estado!=='atendida').length} pendientes · ${g.items.filter(o=>o.estado==='atendida').length} atendidas</summary>${g.items.map(o=>`<article><div class="bib-row"><strong>${obsCode(m,o)}</strong>${badge(o.estado==='atendida'?'listo':'observaciones',o.estado==='atendida'?'Atendida en v'+num(o.atendida_en):'Pendiente')}<span class="bib-note">Sobre v${num(o.version)} · ${esc(o.fecha)}</span></div><span class="bib-note">${esc(o.rol)}${o.etapa?' · '+esc({comite:'Comité Editorial',transferencia:'Transferencia',direccion:'Dirección General'}[o.etapa]||o.etapa):''}</span>${o.seccion?`<p><strong>${esc(o.seccion)}</strong></p>`:''}<p>${esc(o.texto)}</p>${o.respuesta?`<div class="mem-observation-response"><strong>Respuesta · v${num(o.atendida_en)}</strong><p>${esc(o.respuesta)}</p>${o.atendida_por?`<small>${esc(o.atendida_por)} · ${esc(o.atendida_fecha)}</small>`:''}</div>`:''}${o.archivo?linkFile(o.archivo,'mpr',e.id,'observacion',m.observaciones.indexOf(o)):''}</article>`).join('')}</details>`).join('')}</div>`;
  }
  const help = (text, label = '¿Cómo funciona?') => `<details class="mem-help"><summary><i class="ti ti-info-circle"></i> ${label}</summary><p>${text}</p></details>`;
  const reviewKeys = ['comite', 'transferencia', 'direccion'];
  // Una etapa ya pasó cuando el consolidado está liberado o cuando la etapa activa es posterior.
  const stagePast = (m, key, active) => ['aprobado', 'publicado'].includes(m.estado) || (reviewKeys.indexOf(active) > reviewKeys.indexOf(key));
  const range = (a, b) => `<p class="mem-range"><i class="ti ti-calendar"></i> <strong>${a}</strong> <span>→</span> <strong>${b}</strong></p>`;
  const phaseName = key =>({comite:'Comité Editorial',transferencia:'Transferencia',direccion:'Dirección General'}[key]||key);
  function mprCorrection(e){
    const m=e.mpr,c=m.correccion;if(m.fase_revision!=='correccion'||!c)return '';
    const date=v=>v?esc(v.split('-').reverse().join('/')):'Por definir';
    const siguiente=m.fechas_etapa?.[c.destino];
    return `<section class="mem-correction-panel"><div class="bib-row"><h3>Corrección de observaciones · ${phaseName(c.origen)}</h3>${badge('observaciones',pendingObs(m).length+' pendientes')}</div><p>Responsable: <strong>Diagramación</strong> · límite: <strong>${date(c.limite)}</strong>${c.limite&&c.limite<data.hoy?' · Plazo vencido':''}</p><p><strong>${phaseName(c.destino)} · calendario previsto:</strong> ${date(siguiente?.inicio)} al ${date(siguiente?.limite)}. Si Diagramación termina antes, ambas fechas se adelantan conservando los días de revisión.</p><p>${phaseName(c.destino)} recibirá la versión corregida cuando todas las observaciones estén atendidas. Las cargas parciales conservan el bloqueo.</p>${data.permisos.coord?btn('mpr-plazo_correccion',c.limite?'Reprogramar plazo de corrección':'Definir días de corrección',e.id,'small'):''}${!c.limite?'<p class="bib-note">Coordinación debe definir los días de esta ronda antes de cargar la versión corregida.</p>':''}</section>`;
  }
  const statusChip = (text, tone) => `<span class="mem-status ${tone}">${text}</span>`;
  function mprWorkflowCards(e){
    const m=e.mpr,active=calendarStage(m),roster=m.integrantes_editorial||[],submitted={...(m.participaciones_editorial||{})};
    roster.forEach(v=>{if(m.observaciones.some(o=>o.etapa==='comite'&&num(o.version)>=num(m.editorial_version_inicio||m.version)&&o.autor_email&&norm(o.autor_email).trim()===norm(v.email).trim()))submitted[v.id]={nombre:v.nombre};});
    const member=roster.find(r=>r.email&&norm(r.email).trim()===norm(data.usuario.correo).trim());
    const date=v=>v?fmt(v):'—';
    return `<div class="mem-deadlines"><div class="mem-deadlines-heading"><h3>Revisiones y entrega de versión corregida</h3>${data.permisos.coord&&m.estado!=='publicado'?btn('mpr-plazos','<i class="ti ti-calendar-cog"></i> Configurar fechas y correcciones',e.id,'small'):''}</div><div class="mem-deadlines-grid">${reviewKeys.map((key,i)=>{
      const p=m.fechas_etapa[key],r=m.revisiones?.[key],current=active===key,done=!!r?.fin,past=!current&&stagePast(m,key,active);
      const start=r?.habilitada_desde||p.inicio,limit=r?.limite||p.limite;
      const authorized=key==='comite'?!!member:(data.permisos.admin||(key==='transferencia'?data.permisos.jt:data.permisos.dir));
      const enabled=!!(current&&start<=data.hoy&&data.hoy<=limit&&authorized);
      const status=done?statusChip(key==='comite'?'Participación cerrada':'Revisión finalizada','done'):past?statusChip(key==='comite'?'Participación cerrada':'Etapa cerrada','done'):current?(data.hoy>limit?statusChip('Plazo vencido','late'):data.hoy<start?statusChip('Aún no inicia',''):statusChip('Revisión activa','active')):m.fase_revision==='correccion'?statusChip('Espera versión corregida',''):statusChip('Pendiente de su turno','');
      const attrs=enabled?'':'disabled';
      const action=key==='comite'?'':btn('mpr-aprobar_etapa',key==='direccion'?'Aprobar liberación':'Aprobar y enviar a Dirección',e.id,'small primary',enabled&&!pendingObs(m).length?'':'disabled');
      const count=Object.keys(submitted).length;
      const participation=key==='comite'?`<details class="mem-editorial-participation" ${current?'open':''}><summary><strong>${count}/${roster.length}</strong> integrantes con observaciones</summary>${roster.map(v=>`<small>${submitted[v.id]?'✓':'○'} ${esc(v.nombre)} · ${submitted[v.id]?'Registró observaciones':'Sin observaciones'}</small>`).join('')}${!roster.length?'<small>Sin integrantes registrados. Configura el grupo de revisores.</small>':''}</details>`:'';
      return `<div class="mem-review-card ${enabled?'is-active':''} ${done||past?'is-done':''}" data-review-stage="${key}"><div class="mem-review-top"><span class="mem-eyebrow">${i+1}. ${phaseName(key)}</span>${status}</div>${range(date(start),date(limit))}${participation}${r?.cambio_estado?`<div class="mem-approval-note"><strong><i class="ti ti-circle-check"></i> ${esc(r.cambio_estado)}</strong><small>${esc(r.aprobado_por)} · ${esc(r.aprobado_fecha)}</small></div>`:''}${current?`<div class="mem-review-actions">${btn('mpr-observar','Agregar observaciones',e.id,'small',attrs)}${action}</div>`:''}</div>`;
    }).join('')}</div>${help('El Comité reúne las observaciones de todos; no hay aprobación individual que cierre la revisión del grupo. Las observaciones abren un plazo de corrección. El siguiente revisor solo recibe el MPR cuando se cargó la versión corregida sin pendientes.')}</div>`;
  }
  function mprDeadlines(e) {
    if(e.mpr.fase_revision)return mprWorkflowCards(e);
    const m = e.mpr, active = calendarStage(m);
    const date = v => v ? fmt(v) : '—';
    return `<div class="mem-deadlines"><div class="mem-deadlines-heading"><h3>Calendario de revisión por etapa</h3>${data.permisos.coord && m.estado !== 'publicado' ? btn('mpr-plazos', '<i class="ti ti-calendar-cog"></i> Configurar fechas', e.id, 'small') : ''}</div><div class="mem-deadlines-grid">${reviewKeys.map((key,i)=>{
      const p=m.fechas_etapa?.[key], review=m.revisiones?.[key], done=review?.fin, current=active===key, start=review?.habilitada_desde || p?.inicio, past=!current&&!!p&&(stagePast(m,key,active)||data.hoy>p.limite);
      const status=done?statusChip('Aprobada','done'):!p?statusChip('Fechas por definir',''):past?statusChip('Etapa cerrada','done'):!current?statusChip('Pendiente de su turno',''):data.hoy<start?statusChip('Aún no inicia',''):data.hoy>p.limite?statusChip('Plazo finalizado','late'):statusChip('Revisión activa','active');
      const member=data.revisores.some(r=>r.email&&norm(r.email).trim()===norm(data.usuario.correo).trim());
      const authorized=data.permisos.admin||(key==='comite'&&member)||(key==='transferencia'&&data.permisos.jt)||(key==='direccion'&&data.permisos.dir);
      const enabled=!!(m.fechas_etapa&&current&&p&&start<=data.hoy&&data.hoy<=p.limite&&authorized);
      const pending=pendingObs(m).length;
      const reason=!p?'Configura las fechas de esta etapa':data.hoy<start?'Se habilita en la fecha de inicio':data.hoy>p.limite?'El plazo de esta etapa terminó':!authorized?'Acciones disponibles para el responsable de esta etapa':'';
      const attrs=enabled?'':`disabled title="${esc(reason)}"`;
      const approvalAttrs=enabled&&pending? 'disabled title="Atiende las observaciones pendientes antes de aprobar"':attrs;
      const actions=btn('mpr-observar','Agregar observaciones',e.id,'small',attrs)+btn('mpr-aprobar_etapa',key==='direccion'?'Aprobar liberación':'Aprobar revisión',e.id,'small primary',approvalAttrs);
      return `<div class="mem-review-card ${enabled?'is-active':''} ${done||past?'is-done':''}" data-review-stage="${key}"><div class="mem-review-top"><span class="mem-eyebrow">${i+1}. ${phaseName(key)}</span>${status}</div>${range(date(p?.inicio),date(p?.limite))}${review?.cambio_estado ? `<div class="mem-approval-note"><strong><i class="ti ti-circle-check"></i> ${esc(review.cambio_estado)}</strong><small>${esc(review.aprobado_por)} · ${esc(review.aprobado_fecha)}</small>${review.comentario?`<p>${esc(review.comentario)}</p>`:''}</div>` : ''}${review?.habilitada_desde && p && review.habilitada_desde<p.inicio ? `<small class="bib-note">Habilitada anticipadamente el ${date(review.habilitada_desde)}.</small>` : ''}${current?`<div class="mem-review-actions">${actions}</div><small class="bib-note">${enabled?(pending?'Hay observaciones pendientes de atender.':'Acciones habilitadas para esta revisión.'):esc(reason)}</small>`:''}</div>`;
    }).join('')}</div>${help('Las fechas incluyen el día límite completo. Los permisos pasan del Comité Editorial a Transferencia y después a Dirección al vencer cada plazo. Aprobar habilita inmediatamente la siguiente fase, aunque aún no llegue su fecha de inicio; se conserva su fecha límite. Las observaciones pendientes se conservan y bloquean la aprobación y liberación.')}</div>`;
  }
  function editionForm(e = {}) { modal(e.id ? 'Configurar memoria' : 'Nueva memoria', form('edicion', `<div class="bib-wide">${input('Nombre de la memoria', 'nombre', e.nombre, 'text', 'required maxlength="220"')}</div>` + input('Año', 'anio', e.anio || state.year || data.hoy.slice(0, 4), 'number', 'required min="1900" max="2199"') + input('Fecha límite de entrega', 'limite_entrega', e.limite_entrega, 'date') + input('Días para revisar', 'dias_revision', e.dias_revision || 15, 'number', 'min="1" max="365" required') + file('Formato de criterios de revisión', 'criterios') + file('Plantilla del artículo (opcional)', 'plantilla', false, '.pdf,.doc,.docx') + (e.id ? `<div class="mem-files bib-wide">${linkFile(e.criterios, 'edicion', e.id, 'criterios')}${linkFile(e.plantilla, 'edicion', e.id, 'plantilla')}</div>` : ''), e)); }
  function deliverForm() {
    const abiertas=data.ediciones.filter(e=>e.admite_articulos!==false);
    if(!abiertas.length)throw new Error('No hay memorias abiertas para entregar artículos.');
    modal('Entregar artículo', form('entregar', select('Memoria', 'edicion_id', abiertas.map(e => [e.id, e.nombre]), abiertas.some(e=>e.id===state.folder)?state.folder:abiertas[0]?.id) + input('Área / programa', 'area', '', 'text', 'maxlength="180"') + '<div id="mem-delivery-note" class="mem-fields-note"></div>' + `<div class="bib-wide">${input('Título del artículo', 'titulo', '', 'text', 'required maxlength="220"')}</div>` + input('Autor principal', 'autor', data.usuario.nombre, 'text', `required maxlength="180" ${data.permisos.coord ? '' : 'readonly'}`) + input('Correo del autor', 'autor_email', data.usuario.correo, 'email', `maxlength="180" ${data.permisos.coord ? '' : 'readonly'}`) + input('Coautores (separados por coma)', 'coautores') + file('Archivo del artículo (versión 1)', 'archivo', true, '.pdf,.doc,.docx') + '<p class="mem-fields-note">Al entregar se asigna un número y el artículo queda pendiente de asignación de revisor. Los datos generales ya no se editan. Los avisos se consultan dentro del módulo.</p>', {}, 'Entregar artículo'), true);
    deliveryNote();
  }
  function deliveryNote() { const f = $('bib-modal').querySelector('form'), e = data.ediciones.find(e => e.id === num(f.elements.edicion_id.value)); if (e) $('mem-delivery-note').innerHTML = `Entrega hasta ${esc(e.limite_entrega || 'Sin definir')}${e.limite_entrega && e.limite_entrega < data.hoy ? ' (vencida)' : ''} · ${linkFile(e.plantilla, 'edicion', e.id, 'plantilla', -1, 0, 'Plantilla del artículo')}`; }
  function assignForm(r) {
    const e = data.ediciones.find(e => e.id === r.edicion_id), date = new Date(data.hoy + 'T12:00:00Z'); date.setUTCDate(date.getUTCDate() + num(e?.dias_revision || 15));
    modal(r.flujo === 'revision' ? 'Reasignar revisor' : 'Asignar revisor', form('asignar', `<p class="bib-wide"><strong>${esc(r.titulo)}</strong><br>${esc(r.numero_memoria)} · ${esc(r.autor)}</p>` + select('Revisor activo del grupo', 'revisor_id', [['', 'Selecciona un revisor'], ...data.revisores.map(v => [v.id, `${v.nombre} · ${v.email || 'sin correo'} · ${v.carga} en revisión`])], r.revisor_contacto_id || '') + input('Fecha límite de revisión', 'limite_revision', r.limite_revision || date.toISOString().slice(0, 10), 'date', `required min="${data.hoy}"`) + area('Nota para el revisor', 'observaciones') + check('Confirmo la asignación si el revisor coincide con el autor o un coautor (posible conflicto de interés).', 'confirmar_conflicto') + '<p class="mem-fields-note">Se creará un aviso en el módulo para el revisor asignado.</p>', r, 'Asignar y notificar'));
  }
  function reviewForm(r) {
    const e = data.ediciones.find(e => e.id === r.edicion_id);
    modal('Revisión · ' + r.numero_memoria, form('revisar', `<div class="bib-panel bib-wide"><h3>1. Descargue los documentos</h3><div class="mem-files">${linkFile(articleFile(r), 'articulo', r.id)}${linkFile(e?.criterios, 'edicion', r.edicion_id, 'criterios', -1, 0, 'Formato de criterios')}</div><p class="bib-note">Plazo: ${esc(r.limite_revision)}. Corrija el artículo y llene el formato de criterios.</p></div><h3 class="bib-wide">2. Cargue los dos archivos</h3>` + file('Artículo con correcciones', 'revision_archivo', true, '.pdf,.doc,.docx') + file('Formato de criterios lleno', 'criterios', true) + select('Recomendación', 'recomendacion', [['', 'Selecciona una recomendación'], ...['Aceptar sin cambios', 'Cambios menores', 'Cambios mayores'].map(v => [v, v])], '') + area('Comentarios generales para el autor', 'observaciones') + `<div class="bib-wide">${btn('declinar', 'No puedo revisar este artículo', r.id, 'danger')}</div>`, r, 'Enviar revisión al autor'), true);
  }
  function correctForm(r) { modal('Atender observaciones · ' + r.numero_memoria, form('corregir', `<div class="bib-notice red bib-wide"><strong>${esc(r.recomendacion)}</strong><p>${esc(r.observaciones_revisor)}</p><div class="mem-files">${linkFile({ nombre: r.revision_nombre, ruta: r.revision_archivo }, 'articulo', r.id, 'revision')}${linkFile({ nombre: r.criterios_archivo_nombre, ruta: r.criterios_archivo_ruta }, 'articulo', r.id, 'criterios')}</div></div>` + file(`Versión corregida (v${num(r.version) + 1})`, 'archivo', true, '.pdf,.doc,.docx') + area('¿Cómo atendió las observaciones?', 'observaciones') + '<p class="mem-fields-note">La nueva versión quedará lista para diagramación.</p>', r, 'Entregar versión corregida'), true); }
  function logView(items, scope, id) { return `<div class="bib-log">${[...items].reverse().map(h => `<article><strong>${esc(logs[h.accion] || h.accion)}</strong> · v${num(h.version)} <small>${esc(h.fecha)}</small><div><small>${esc(h.actor)} · ${esc(h.rol)}</small></div>${h.observaciones ? `<p>${esc(h.observaciones)}</p>` : ''}<div class="mem-files">${scope === 'articulo' ? (h.archivos || []).map((a, i) => linkFile(a, 'articulo', id, 'historial', items.indexOf(h), i)).join('') : (h.archivos || []).map(a => `<span class="bib-note">${esc(a.nombre)}</span>`).join('')}</div></article>`).join('') || '<article>Sin movimientos registrados.</article>'}</div>`; }
  function trackArticle(r) { modal('Seguimiento · ' + r.numero_memoria, `<strong>${esc(r.titulo)}</strong><p>${badge(r.flujo, data.estados[r.flujo])} · v${r.version}</p><div class="mem-stage">${Object.entries(data.estados).map(([s, label], i) => `<div class="${s === r.flujo ? 'current' : Object.keys(data.estados).indexOf(r.flujo) > i ? 'complete' : ''}"><b>${i + 1}</b><strong>${esc(label)}</strong></div>`).join('')}</div><h3>Expediente y versiones</h3>${logView(r.historial, 'articulo', r.id)}${r.anterior?.length ? `<details class="bib-panel"><summary>Registro histórico conservado</summary>${r.anterior.map(a => `<p class="bib-note">${esc(a.fecha || a.creado_en)} · ${esc(a.etapa)} · ${esc(a.estado)}<br>${esc(a.observaciones)}</p>`).join('')}</details>` : ''}`, true); }
  function reviewersModal() {
    modal('Revisores de la memoria', `<div class="bib-notice blue">Los revisores son los integrantes activos del grupo seleccionado en el Directorio de contactos. También se administran en <a href="index.php?view=comites">Comités</a>.</div>${form('grupo', select('Comité o grupo de revisores', 'comite_id', data.comites.map(c => [c.id, c.nombre]), data.comite), {}, 'Usar este grupo')}<h3>Revisores (${data.revisores.length})</h3><div class="bib-panel">${data.revisores.map(r => `<div class="mem-reviewer"><span>${esc(r.nombre)}<small>${esc(r.cargo)} · ${esc(r.email)} · ${r.carga} en revisión</small></span>${data.permisos.contactos ? btn('quitar-revisor', 'Quitar del grupo', r.id, 'small danger') : ''}</div>`).join('') || '<p class="bib-note">Sin integrantes activos.</p>'}</div>${data.permisos.contactos ? `<input id="mem-find-reviewer" class="bib-input" placeholder="Buscar contacto por nombre, cargo o empresa…" aria-label="Buscar contacto"><div id="mem-reviewer-results"></div><div class="bib-row">${btn('nuevo-revisor', '+ Registrar nuevo revisor')}</div>` : '<p class="bib-note">Para modificar integrantes necesitas permiso de contactos o configuración.</p>'}`, true);
  }
  function mprForm(action, e) {
    const m = e.mpr, pending = pendingObs(m), r = { id: e.id, revision: e.revision }; let body = `<div class="bib-wide">${linkFile(m.archivo, 'mpr', e.id)} · v${num(m.version)}</div>`, title = '', label = '';
    if (action === 'mpr_plazos') { title = 'Fechas de revisión por etapa'; label = 'Guardar fechas'; body = '<p class="mem-fields-note">Cada etapa tiene fecha de inicio y límite. La siguiente empieza después de la fecha límite anterior.</p>' + [['comite','Comité Editorial'],['transferencia','Transferencia'],['direccion','Dirección General']].map(([key,name]) => input(name + ' · inicio', key+'_inicio', m.fechas_etapa?.[key]?.inicio, 'date', 'required') + input(name + ' · fecha límite', key+'_limite', m.fechas_etapa?.[key]?.limite, 'date', 'required')).join(''); }
    if (action === 'mpr_plazos') { body += '<h3 class="bib-wide">Plazos para corregir · días calendario</h3>' + ['comite','transferencia','direccion'].map(key=>input('Después de '+phaseName(key),'correccion_'+key,m.dias_correccion?.[key],'number','min="1" max="365" step="1"')).join('') + '<p class="mem-fields-note">Si no defines días de corrección, coordinación deberá asignar una fecha límite al abrirse esa ronda.</p>'; }
    if (action === 'mpr_plazo_correccion') {title='Días para esta ronda de corrección';label='Guardar plazo';body=input('Días calendario a partir de hoy','dias',m.correccion?.dias,'number','required min="1" max="365" step="1"')+'<p class="mem-fields-note">Aplica solo a esta ronda. El límite se calcula desde hoy. Las etapas siguientes se reprograman después de ese límite, conservando su duración. Si la corrección se entrega antes, se adelantan a la fecha de entrega.</p>';}
    if (action === 'mpr_aprobar_etapa') { title = 'Aprobar revisión'; label = 'Aprobar revisión'; body += '<p class="mem-fields-note">Confirma la revisión de esta versión. Solo puedes aprobar si no quedan observaciones pendientes. Al aprobar, la siguiente etapa se habilita de inmediato y el cambio queda registrado en el seguimiento. Dirección autoriza la liberación.</p>' + area('Comentario de aprobación (opcional)', 'observaciones'); }
    if (action === 'mpr_cerrar_editorial') { title = 'Cerrar revisión editorial'; label = 'Enviar a Transferencia'; body += '<p class="mem-fields-note">Confirma que concluyó la revisión del Comité Editorial. No debe haber observaciones pendientes. Al confirmar empieza el plazo de Transferencia.</p>'; }
    if (action === 'mpr_cargar') { title = m.version ? 'Nueva versión del consolidado' : 'Cargar consolidado MPR'; label = 'Cargar y enviar a revisión final'; body += file('Consolidado MPR', 'archivo', true, '.pdf,.doc,.docx,.indd,.zip') + check('Cargar de todos modos si existen artículos pendientes de revisión.', 'aceptar_pendientes') + (pending.length ? `<div class="bib-wide"><h3>Observaciones que atiende esta versión</h3><div class="bib-checks">${pending.map(o => `<div class="mem-correction-item"><label><input type="checkbox" name="atendidas" value="${esc(o.id)}"><span><strong>${obsCode(m,o)} · ${esc(o.autor)} · sobre v${num(o.version)}</strong><br>${esc(o.seccion)} ${esc(o.texto)}</span></label><label>Respuesta específica (opcional)<textarea class="bib-input" name="respuesta:${esc(o.id)}" rows="2" maxlength="5000" placeholder="Si lo dejas vacío se usará la respuesta general."></textarea></label></div>`).join('')}</div></div>` : '') + area(pending.length ? '¿Cómo se atendieron las observaciones?' : 'Nota para la revisión final', 'observaciones') + '<p class="mem-fields-note">Las observaciones no seleccionadas seguirán pendientes. En una ronda de corrección, la siguiente revisión permanece bloqueada hasta completar todas.</p>'; }
    if (action === 'mpr_observar') { title = 'Observación final · MPR v' + m.version; label = 'Registrar observación'; body += input('Página o sección', 'seccion', '', 'text', 'maxlength="180"') + area('Observación', 'observaciones') + file('Archivo con anotaciones (opcional)', 'archivo', false, '.pdf,.doc,.docx,.png,.jpg,.jpeg'); }
    if (action === 'mpr_aprobar') { title = m.estado === 'revision_final' ? 'Aprobar versión final · Transferencia' : 'Aprobar liberación · Dirección'; label = 'Confirmar aprobación'; body += pending.length ? check(`Hay ${pending.length} observaciones pendientes. Confirmo la aprobación de todos modos.`, 'aceptar_pendientes') : '<p class="mem-fields-note">Todas las observaciones están atendidas.</p>'; body += area('Comentario de aprobación', 'observaciones'); }
    if (action === 'mpr_publicar') { title = 'Registrar publicación MPR'; label = 'Registrar publicación'; body += input('Enlace de la publicación', 'enlace', '', 'url') + input('Fecha de publicación', 'fecha', data.hoy, 'date', 'required') + area('Nota', 'observaciones') + '<p class="mem-fields-note">Registra la publicación ya realizada. Este formulario no publica archivos en sitios externos.</p>'; }
    if(action==='mpr_cargar'&&m.fase_revision==='correccion'){label='Cargar versión corregida';body+='<p class="mem-fields-note">La siguiente revisión se habilita al atender todas las observaciones. No se requiere aprobación de quien las registró.</p>';}
    modal(title, form(action, body, r, label), true);
  }
  function mprTrack(e) {
    const m=e.mpr, versions=[...m.versiones.map((v,i)=>({v,index:i})),...(m.version?[{v:{...m.archivo,version:m.version},index:-1}]:[])];
    modal('Seguimiento del consolidado · '+e.nombre,`<h3>Versiones del documento</h3><p class="bib-note">Cada carga crea una versión del MPR. Una misma versión puede resolver observaciones de varias personas.</p><div class="bib-panel">${versions.map(({v,index})=>{const created=m.observaciones.filter(o=>num(o.version)===num(v.version)),resolved=m.observaciones.filter(o=>o.estado==='atendida'&&num(o.atendida_en)===num(v.version));return `<article class="mem-version-entry"><h3>MPR v${num(v.version)} ${index===-1?'· vigente':''}</h3>${linkFile(v,'mpr',e.id,index===-1?'actual':'version',index)}<p class="bib-note">${created.length} observaciones recibidas · ${resolved.length} atendidas en esta versión</p>${resolved.length?`<ul>${resolved.map(o=>`<li><strong>${obsCode(m,o)}</strong> · ${esc(o.autor)} · origen v${num(o.version)}<p>${esc(o.respuesta)}</p></li>`).join('')}</ul>`:''}</article>`;}).join('')||'<p class="bib-note">Sin versiones cargadas.</p>'}</div>${(m.rondas_correccion||[]).length ? `<h3>Rondas de corrección completadas</h3><div class="bib-panel">${m.rondas_correccion.map(c=>`<p>${phaseName(c.origen)} → ${phaseName(c.destino)} · v${num(c.version_origen)} → v${num(c.version_entregada)}<br><small>Inicio: ${esc(c.inicio)} · límite: ${esc(c.limite)} · entregada: ${esc(c.entregada_en)}</small></p>`).join('')}</div>` : ''}${mprObservations(e)}<h3>Bitácora</h3>${logView(m.bitacora,'mpr',e.id)}`,true);
  }
  root.addEventListener('click', async event => {
    const b = event.target.closest('[data-mem]'); if (!b || b.disabled || state.busy) return; const action = b.dataset.mem, id = b.dataset.id, r = data.articulos.find(r => r.id === num(id)), e = data.ediciones.find(e => e.id === num(id));
    try {
      if (action === 'cerrar') $('bib-dialog').close();
      else if (action === 'abrir') { state.folder = num(id); state.year = num(data.ediciones.find(e=>e.id===state.folder)?.anio); state.filter = 'todos'; state.query = ''; state.tab = 'articulos'; $('mem-search').value = ''; syncUrl(); render(); }
      else if (action === 'pestana') { state.tab = id === 'mpr' ? 'mpr' : 'articulos'; syncUrl(); render(); }
      else if (action === 'filtro') { state.filter = id; render(); }
      else if (action === 'limpiar') { state.filter = 'todos'; state.query = ''; $('mem-search').value = ''; render(); }
      else if (action === 'abrir-anio') { state.year=num(id); state.folder=0; state.filter='todos'; state.query=''; state.tab='articulos'; $('mem-search').value=''; syncUrl(); render(); }
      else if (action === 'carpeta-anio') modal('Crear carpeta por año',form('carpeta_anio',input('Año','anio',state.year||data.hoy.slice(0,4),'number','required min="1900" max="2199"'),{},'Crear carpeta'));
      else if (action === 'edicion') editionForm(e);
      else if (action === 'entregar') deliverForm();
      else if (action === 'asignar') assignForm(r);
      else if (action === 'revisar') reviewForm(r);
      else if (action === 'corregir') correctForm(r);
      else if (action === 'declinar') modal('Declinar revisión', form('declinar', area('Motivo por el que no puede revisar', 'observaciones') + '<p class="mem-fields-note">El artículo regresará a coordinación para asignar otro revisor.</p>', r, 'Declinar revisión'));
      else if (action === 'track') trackArticle(r);
      else if (action === 'revisores') reviewersModal();
      else if (action === 'nuevo-revisor') modal('Registrar en el directorio y agregar', form('revisor_nuevo', input('Nombre', 'nombre', '', 'text', 'required maxlength="180"') + input('Correo', 'email', '', 'email', 'required maxlength="180"') + input('Cargo', 'cargo', 'Revisor científico') + input('Institución', 'empresa')));
      else if (action === 'mpr-track') mprTrack(e);
      else if (action.startsWith('mpr-')) mprForm(action.replace('-', '_'), e);
      else if (['agregar-revisor', 'quitar-revisor', 'aviso', 'diagramar', 'eliminar'].includes(action)) {
        if (action === 'eliminar' && !confirm('¿Eliminar este artículo y su expediente? Esta acción no se puede deshacer.')) return;
        if (action === 'quitar-revisor' && !confirm('¿Quitar a esta persona del grupo de revisores? Se conservan sus asignaciones existentes.')) return;
        state.busy = true; b.disabled = true;
        await request({ 'agregar-revisor': 'revisor_agregar', 'quitar-revisor': 'revisor_quitar', aviso: 'aviso_leido', diagramar: 'diagramar', eliminar: 'eliminar' }[action], { id: num(id), contacto_id: num(id), ...(r ? { revision: r.revision_control } : {}) });
        if (action.includes('revisor')) reviewersModal();
        if (action === 'diagramar') { const a = document.createElement('a'); a.href = urlFile('articulo', id); document.body.appendChild(a); a.click(); a.remove(); }
      }
    } catch (error) { fail(error); } finally { state.busy = false; b.disabled = false; }
  });
  root.addEventListener('input', event => {
    const el = event.target;
    if (el.id === 'mem-search') { state.query = el.value; render(); }
    else if (el.id === 'mem-find-reviewer') { const query = norm(el.value); $('mem-reviewer-results').innerHTML = query ? data.contactos.filter(c => !data.revisores.some(r => r.id === num(c.id)) && norm(`${c.nombre} ${c.cargo} ${c.empresa}`).includes(query)).slice(0, 8).map(c => `<div class="mem-reviewer"><span>${esc(c.nombre)}<small>${esc(c.cargo)} · ${esc(c.empresa)}</small></span>${btn('agregar-revisor', 'Agregar', c.id, 'small')}</div>`).join('') || '<p class="bib-note">Sin coincidencias.</p>' : ''; }
  });
  root.addEventListener('change', event => { if (event.target.name === 'edicion_id') deliveryNote(); });
  root.addEventListener('submit', async event => {
    const f = event.target.closest('[data-form]'); if (!f) return; event.preventDefault(); if (state.busy) return; const submit = f.querySelector('[type=submit]');
    const values = { id: num(f.dataset.id), revision: num(f.dataset.revision), respuestas: {} }, files = {};
    [...f.elements].forEach(el => { if (!el.name) return; if (el.type === 'file') { if (el.files[0]) files[el.name] = el.files[0]; } else if (el.name.startsWith('respuesta:')) values.respuestas[el.name.slice(10)] = el.value; else if (el.name !== 'atendidas') values[el.name] = el.type === 'checkbox' ? el.checked : el.value; });
    values.atendidas = [...f.querySelectorAll('[name=atendidas]:checked')].map(el => el.value);
    try { state.busy = true; submit.disabled = true; $('bib-error').hidden = true; await request(f.dataset.form, values, files); if (['grupo', 'revisor_nuevo'].includes(f.dataset.form)) reviewersModal(); else $('bib-dialog').close(); } catch (error) { fail(error); } finally { state.busy = false; submit.disabled = false; }
  });
  // Menu desplegable de acciones: posicion fija para no quedar recortado por el scroll de la tabla.
  const closeMenus = except => root.querySelectorAll('details.bib-menu[open]').forEach(d => { if (d !== except) d.open = false; });
  root.addEventListener('toggle', event => {
    const menu = event.target; if (!menu.matches || !menu.matches('details.bib-menu') || !menu.open) return;
    closeMenus(menu);
    const box = menu.querySelector('summary').getBoundingClientRect(), list = menu.querySelector('.bib-action-list');
    list.style.left = Math.max(8, box.right - list.offsetWidth) + 'px';
    const below = box.bottom + 4, height = list.offsetHeight;
    list.style.top = (below + height > window.innerHeight - 8 ? Math.max(8, box.top - height - 4) : below) + 'px';
  }, true);
  document.addEventListener('click', event => { if (!event.target.closest('details.bib-menu') || event.target.closest('.bib-action-list [data-mem], .bib-action-list a')) closeMenus(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') closeMenus(); });
  window.addEventListener('scroll', () => closeMenus(), true);
  window.addEventListener('resize', () => closeMenus());
  setInterval(async () => {
    if (state.busy || $('bib-dialog').open || document.hidden) return;
    try { const response = await fetch(endpoint); const result = await response.json(); if (!response.ok || !result.ok || state.busy || $('bib-dialog').open) return; const fresh = JSON.stringify(result.datos); if (fresh !== snapshot && !root.querySelector('details.bib-menu[open]')) { data = result.datos; snapshot = fresh; render(); } } catch (_) { /* Reintenta en la próxima actualización. */ }
  }, 60000);
  render();
})();
