(() => {
  'use strict';
  const source = document.getElementById('mem-data'); if (!source) return;
  let data = JSON.parse(source.textContent);
  const root = document.getElementById('biblioteca-ingenios'), $ = id => document.getElementById(id), endpoint = 'index.php?view=memorias&memorias_api=1';
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const num = v => Number(v) || 0, norm = v => String(v || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const state = { folder: num(new URLSearchParams(location.search).get('edicion')), filter: 'todos', query: '', busy: false };
  if (!data.ediciones.some(e => e.id === state.folder)) state.folder = 0;
  const filters = { todos: 'Todos', mios: 'Pendientes de mí', entregado: 'Sin revisor', revision: 'En revisión', observaciones: 'Con observaciones', listo: 'Listos para diagramación', diagramacion: 'Descargados por diagramación' };
  const logs = { entregado: 'Artículo entregado', asignado: 'Revisor asignado', reasignado: 'Revisor reasignado', declinado: 'Revisor declinó', revisado: 'Revisión enviada al autor', corregido: 'Versión corregida entregada', diagramacion: 'Descarga por diagramación', cargado: 'Consolidado cargado', atendidas: 'Nueva versión del consolidado', observacion: 'Observación final', aprobado_jt: 'Aprobación de Transferencia', aprobado: 'Liberación de Dirección', publicado: 'Publicación registrada' };
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
    const response = await fetch(endpoint, { method: 'POST', body: fd }), result = await response.json(); if (!response.ok || !result.ok) throw new Error(result.error || 'No se pudo completar la operación.'); data = result.datos; render(); message(result.mensaje);
  }
  function render() {
    $('mem-actions').innerHTML = (data.permisos.coord ? btn('revisores', 'Revisores') + btn('edicion', '+ Nueva memoria', '', 'primary') : '') + (data.permisos.entregar ? btn('entregar', '↑ Entregar artículo') : '');
    $('mem-notices').innerHTML = data.avisos.map(a => `<div class="bib-notice blue"><span>${esc(a.mensaje)}<small> · ${esc(a.creado_en)}</small></span>${btn('aviso', 'Marcar leído', a.id, 'small')}</div>`).join('');
    $('mem-filters').innerHTML = Object.entries(filters).map(([f, label]) => btn('filtro', `${label} (${data.articulos.filter(r => matches(r, f)).length})`, f, 'bib-pill ' + (state.filter === f ? 'primary' : ''))).join('');
    const global = state.filter !== 'todos' || !!state.query.trim(), edition = data.ediciones.find(e => e.id === state.folder);
    const rows = data.articulos.filter(r => (global || r.edicion_id === state.folder) && matches(r, state.filter) && norm(`${r.numero_memoria} ${r.titulo} ${r.autor} ${r.area}`).includes(norm(state.query)));
    let html = '';
    if (global) html += `<p class="bib-note">Mostrando artículos de todas las memorias. ${btn('limpiar', 'Volver a memorias', '', 'small')}</p>`;
    else {
      html += `<nav class="bib-breadcrumb" aria-label="Memorias">${btn('abrir', 'Memorias', 0)}${edition ? '<span>/</span>' + btn('abrir', esc(edition.nombre), edition.id) : ''}</nav>`;
      if (!edition) html += `<div class="mem-editions">${data.ediciones.map(e => { const own = data.articulos.filter(r => r.edicion_id === e.id); return `<button class="mem-edition" data-mem="abrir" data-id="${e.id}"><strong><i class="ti ti-book"></i> ${esc(e.nombre)}</strong><span class="bib-note">${own.length} artículo(s) · entrega hasta ${esc(e.limite_entrega || 'Sin definir')} · criterios: ${e.criterios ? '✓' : 'pendiente'}</span>${e.mpr ? `<p>Consolidado: ${badge(e.mpr.estado, data.estados_mpr[e.mpr.estado])} ${e.mpr.version ? 'v' + e.mpr.version : ''}</p>` : ''}<div class="bib-row">${Object.entries(data.estados).map(([key, text]) => { const n = own.filter(r => r.flujo === key).length; return n ? badge(key, n + ' · ' + text) : ''; }).join('')}</div></button>`; }).join('')}</div>`;
      if (edition) { html += `<section class="bib-panel"><div class="bib-row"><strong>${esc(edition.nombre)}</strong>${data.permisos.coord ? btn('edicion', 'Configurar memoria', edition.id, 'small') : ''}</div><p class="bib-note">Entrega de artículos hasta ${esc(edition.limite_entrega || 'Sin definir')} · plazo de revisión: ${num(edition.dias_revision)} días</p><div class="mem-files">${linkFile(edition.criterios, 'edicion', edition.id, 'criterios', -1, 0, 'Formato de criterios')}${linkFile(edition.plantilla, 'edicion', edition.id, 'plantilla', -1, 0, 'Plantilla del artículo')}</div></section>`; if (edition.mpr) html += mprPanel(edition); }
    }
    if (global || edition) html += `<div class="bib-scroll"><table class="bib-table"><thead><tr><th>Artículo</th><th>Autor / revisor</th><th>Versión</th><th>Estado</th><th style="text-align:right">Acciones</th></tr></thead><tbody>${rows.map(row).join('') || '<tr><td colspan="5"><div class="bib-empty">No hay artículos con este filtro.</div></td></tr>'}</tbody></table></div>`;
    $('mem-content').innerHTML = html;
  }
  function row(r) {
    let action = ''; if (r.asignar) action = btn('asignar', r.flujo === 'revision' ? 'Reasignar' : 'Asignar revisor', r.id, 'small primary');
    if (r.revisar) action += btn('revisar', 'Revisar artículo', r.id, 'small primary');
    if (r.corregir) action = btn('corregir', 'Atender observaciones', r.id, 'small danger');
    if (r.diagramar) action = btn('diagramar', r.flujo === 'listo' ? `Descargar versión corregida (v${r.version})` : 'Descargar otra vez', r.id, 'small primary');
    if (!action) action = `<span class="bib-note">${r.flujo === 'listo' ? 'Le toca a Diagramación' : r.flujo === 'observaciones' ? 'Le toca al autor' : r.flujo === 'revision' ? 'Esperando revisión' : r.flujo === 'entregado' ? 'Esperando asignación' : 'Descarga registrada'}</span>`;
    const days = r.limite_revision ? Math.ceil((Date.parse(r.limite_revision) - Date.parse(data.hoy)) / 86400000) : null;
    return `<tr><td><strong>${esc(r.titulo)}</strong><span class="bib-meta">${esc(r.numero_memoria)} · ${esc(r.area || '—')} · entregado ${esc(r.fecha)}</span></td><td>${esc(r.autor)}<span class="bib-meta">Revisor: ${esc(reviewer(r)?.nombre || (r.revisor_contacto_id ? 'Asignado (fuera del grupo actual)' : 'sin asignar'))}</span></td><td>${badge('', 'v' + r.version)}</td><td>${badge(r.flujo, data.estados[r.flujo])}${r.flujo === 'revision' && days !== null ? `<p class="bib-note ${days < 0 ? 'mem-late' : ''}">${days < 0 ? 'Revisión vencida hace ' + -days : 'Plazo de revisión: ' + days} día(s)</p>` : ''}</td><td><details class="bib-menu"><summary class="bib-btn small"><i class="ti ti-dots-vertical"></i> Acciones <i class="ti ti-chevron-down"></i></summary><div class="bib-action-list" role="menu"><div class="bib-menu-main">${action}</div>${btn('track', '<i class="ti ti-history"></i> Seguimiento', r.id, 'small')}${linkFile(articleFile(r), 'articulo', r.id, 'actual', -1, 0, 'Descargar')}${data.permisos.coord ? btn('eliminar', '<i class="ti ti-trash"></i> Eliminar', r.id, 'small danger') : ''}</div></details></td></tr>`;
  }
  function mprPanel(e) {
    const m = e.mpr, pending = pendingObs(m), articles = data.articulos.filter(r => r.edicion_id === e.id), ready = articles.filter(r => ['listo', 'diagramacion'].includes(r.flujo)), waiting = articles.filter(r => !['listo', 'diagramacion'].includes(r.flujo));
    const step = ['armado', 'revision_final', 'aprobado_jt', 'aprobado', 'publicado'].indexOf(m.estado);
    let buttons = ''; if (data.permisos.diag && step >= 0 && step < 4) buttons += btn('mpr-cargar', m.version ? 'Cargar versión corregida' : 'Cargar consolidado MPR', e.id, 'small primary');
    if (canObserve() && [1, 2].includes(step)) buttons += btn('mpr-observar', 'Agregar observación final', e.id, 'small');
    if (data.permisos.jt && step === 1 || data.permisos.dir && step === 2) buttons += btn('mpr-aprobar', step === 1 ? 'Aprobar versión final' : 'Aprobar liberación', e.id, 'small primary');
    if (data.permisos.diag && step === 3) buttons += btn('mpr-publicar', 'Registrar publicación MPR', e.id, 'small primary');
    if (m.version) buttons += btn('mpr-track', 'Seguimiento', e.id, 'small');
    const corrections = m.version && step < 4 ? `<div class="bib-notice blue"><strong>Correcciones del consolidado</strong><p>${pending.length ? `${pending.length} observación(es) pendiente(s). ` : ''}${data.permisos.diag ? 'Adjunta el documento corregido, selecciona las observaciones atendidas y describe los cambios. Se conservará la versión anterior y se reiniciarán las aprobaciones.' : 'Diagramación o Administración debe cargar la versión corregida y responder a las observaciones.'}</p>${data.permisos.diag ? btn('mpr-cargar', `Cargar versión corregida (v${num(m.version) + 1})`, e.id, 'primary') : ''}</div>` : '';
    const stages = ['Artículos corregidos', 'Consolidado (diagramación)', 'Revisión final', 'Aprobación de versión final', 'Aprobación de liberación', 'Publicación'];
    return `<section class="bib-panel"><div class="bib-row"><strong>Documento consolidado (MPR)</strong>${badge(m.estado, data.estados_mpr[m.estado])}</div><p class="bib-note">Diagramación arma el consolidado. El Comité Editorial, Transferencia y Dirección pueden agregar observaciones; aprueba primero Transferencia y después Dirección General.</p><div class="bib-row">${buttons}</div><div class="mem-stage">${stages.map((name, i) => { const completed = i === 0 ? !!articles.length && !waiting.length : i === 1 ? !!m.version : i === 2 ? step > 1 : i === 3 ? step >= 2 : i === 4 ? step >= 3 : step === 4; return `<div class="${completed ? 'complete' : i === step + 2 || i === 1 && step === 0 ? 'current' : ''}"><b>${completed ? '✓' : i + 1}</b><strong>${name}</strong><span>${['', '', 'Comité Editorial', 'Jefe de Transferencia', 'Director General', 'Diagramación'][i]}</span></div>`; }).join('')}</div><div class="mem-summary"><div><strong>Artículos visibles</strong>${ready.length} de ${articles.length} listos · ${articles.filter(r => r.flujo === 'diagramacion').length} descargados${waiting.length ? `<p>Pendientes: ${waiting.map(r => esc(r.numero_memoria)).join(', ')}</p>` : ''}</div><div><strong>Consolidado vigente · v${num(m.version)}</strong>${linkFile(m.archivo, 'mpr', e.id)}${m.publicacion ? `<p>Publicado: ${esc(m.publicacion.fecha)} ${/^https?:\/\//i.test(m.publicacion.enlace) ? `<a href="${esc(m.publicacion.enlace)}" target="_blank" rel="noopener noreferrer">Ver publicación</a>` : ''}</p>` : ''}</div><div><strong>Observaciones finales</strong>${pending.length} pendientes · ${m.observaciones.filter(o => o.estado === 'atendida').length} atendidas</div></div>${corrections}${pending.map(o => `<div class="bib-notice"><b>${esc(o.autor)}</b> · ${esc(o.rol)} · ${esc(o.fecha)} · v${o.version}<p>${esc(o.seccion)} ${esc(o.texto)}</p>${o.archivo ? linkFile(o.archivo, 'mpr', e.id, 'observacion', m.observaciones.indexOf(o)) : ''}</div>`).join('')}</section>`;
  }
  function editionForm(e = {}) { modal(e.id ? 'Configurar memoria' : 'Nueva memoria', form('edicion', `<div class="bib-wide">${input('Nombre de la memoria', 'nombre', e.nombre, 'text', 'required maxlength="220"')}</div>` + input('Año', 'anio', e.anio || data.hoy.slice(0, 4), 'number', 'required min="1900" max="2199"') + input('Fecha límite de entrega', 'limite_entrega', e.limite_entrega, 'date') + input('Días para revisar', 'dias_revision', e.dias_revision || 15, 'number', 'min="1" max="365" required') + file('Formato de criterios de revisión', 'criterios') + file('Plantilla del artículo (opcional)', 'plantilla', false, '.pdf,.doc,.docx') + (e.id ? `<div class="mem-files bib-wide">${linkFile(e.criterios, 'edicion', e.id, 'criterios')}${linkFile(e.plantilla, 'edicion', e.id, 'plantilla')}</div>` : ''), e)); }
  function deliverForm() {
    modal('Entregar artículo', form('entregar', select('Memoria', 'edicion_id', data.ediciones.map(e => [e.id, e.nombre]), state.folder || data.ediciones[0]?.id) + input('Área / programa', 'area', '', 'text', 'maxlength="180"') + '<div id="mem-delivery-note" class="mem-fields-note"></div>' + `<div class="bib-wide">${input('Título del artículo', 'titulo', '', 'text', 'required maxlength="220"')}</div>` + input('Autor principal', 'autor', data.usuario.nombre, 'text', `required maxlength="180" ${data.permisos.coord ? '' : 'readonly'}`) + input('Correo del autor', 'autor_email', data.usuario.correo, 'email', `maxlength="180" ${data.permisos.coord ? '' : 'readonly'}`) + input('Coautores (separados por coma)', 'coautores') + file('Archivo del artículo (versión 1)', 'archivo', true, '.pdf,.doc,.docx') + '<p class="mem-fields-note">Al entregar se asigna un número y el artículo queda pendiente de asignación de revisor. Los datos generales ya no se editan. Los avisos se consultan dentro del módulo.</p>', {}, 'Entregar artículo'), true);
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
    if (action === 'mpr_cargar') { title = m.version ? 'Nueva versión del consolidado' : 'Cargar consolidado MPR'; label = 'Cargar y enviar a revisión final'; body += file('Consolidado MPR', 'archivo', true, '.pdf,.doc,.docx,.indd,.zip') + check('Cargar de todos modos si existen artículos pendientes de revisión.', 'aceptar_pendientes') + (pending.length ? `<div class="bib-wide"><h3>Observaciones que atiende esta versión</h3><div class="bib-checks">${pending.map(o => `<label><input type="checkbox" name="atendidas" value="${esc(o.id)}" checked><span>${esc(o.autor)}: ${esc(o.texto)}</span></label>`).join('')}</div></div>` : '') + area(pending.length ? '¿Cómo se atendieron las observaciones?' : 'Nota para la revisión final', 'observaciones') + '<p class="mem-fields-note">Una nueva versión reinicia las aprobaciones. Las observaciones no seleccionadas seguirán pendientes.</p>'; }
    if (action === 'mpr_observar') { title = 'Observación final · MPR v' + m.version; label = 'Registrar observación'; body += input('Página o sección', 'seccion', '', 'text', 'maxlength="180"') + area('Observación', 'observaciones') + file('Archivo con anotaciones (opcional)', 'archivo', false, '.pdf,.doc,.docx,.png,.jpg,.jpeg'); }
    if (action === 'mpr_aprobar') { title = m.estado === 'revision_final' ? 'Aprobar versión final · Transferencia' : 'Aprobar liberación · Dirección'; label = 'Confirmar aprobación'; body += pending.length ? check(`Hay ${pending.length} observaciones pendientes. Confirmo la aprobación de todos modos.`, 'aceptar_pendientes') : '<p class="mem-fields-note">Todas las observaciones están atendidas.</p>'; body += area('Comentario de aprobación', 'observaciones'); }
    if (action === 'mpr_publicar') { title = 'Registrar publicación MPR'; label = 'Registrar publicación'; body += input('Enlace de la publicación', 'enlace', '', 'url') + input('Fecha de publicación', 'fecha', data.hoy, 'date', 'required') + area('Nota', 'observaciones') + '<p class="mem-fields-note">Registra la publicación ya realizada. Este formulario no publica archivos en sitios externos.</p>'; }
    modal(title, form(action, body, r, label), true);
  }
  function mprTrack(e) { const m = e.mpr; modal('Seguimiento del consolidado · ' + e.nombre, `<h3>Versiones</h3><div class="bib-panel">${m.versiones.map((v, i) => `<p>MPR v${v.version} ${linkFile(v, 'mpr', e.id, 'version', i)}</p>`).join('')}<p>MPR v${m.version} (vigente) ${linkFile(m.archivo, 'mpr', e.id)}</p></div><h3>Observaciones</h3>${m.observaciones.map((o, i) => `<div class="bib-notice"><b>${esc(o.autor)}</b> · v${o.version} · ${esc(o.estado)}<p>${esc(o.seccion)} ${esc(o.texto)}</p>${o.respuesta ? `<p>Respuesta (v${o.atendida_en}): ${esc(o.respuesta)}</p>` : ''}${o.archivo ? linkFile(o.archivo, 'mpr', e.id, 'observacion', i) : ''}</div>`).join('')}<h3>Bitácora</h3>${logView(m.bitacora, 'mpr', e.id)}`, true); }
  root.addEventListener('click', async event => {
    const b = event.target.closest('[data-mem]'); if (!b || b.disabled || state.busy) return; const action = b.dataset.mem, id = b.dataset.id, r = data.articulos.find(r => r.id === num(id)), e = data.ediciones.find(e => e.id === num(id));
    try {
      if (action === 'cerrar') $('bib-dialog').close();
      else if (action === 'abrir') { state.folder = num(id); state.filter = 'todos'; state.query = ''; $('mem-search').value = ''; history.replaceState(null, '', 'index.php?view=memorias' + (state.folder ? '&edicion=' + state.folder : '')); render(); }
      else if (action === 'filtro') { state.filter = id; render(); }
      else if (action === 'limpiar') { state.filter = 'todos'; state.query = ''; $('mem-search').value = ''; render(); }
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
    const values = { id: num(f.dataset.id), revision: num(f.dataset.revision) }, files = {};
    [...f.elements].forEach(el => { if (!el.name) return; if (el.type === 'file') { if (el.files[0]) files[el.name] = el.files[0]; } else if (el.name !== 'atendidas') values[el.name] = el.type === 'checkbox' ? el.checked : el.value; });
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
  render();
})();
