(() => {
  'use strict';
  const root = document.getElementById('biblioteca-ingenios');
  if (!root) return;
  let data = JSON.parse(document.getElementById('bib-data').textContent);
  const interna = !!data.interna, view = interna ? 'biblioteca_interna' : 'documentos';
  const endpoint = 'index.php?view=' + view + '&biblioteca_api=1', $ = id => document.getElementById(id);
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const number = v => Number(v) || 0, norm = v => String(v || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const state = { folder: number(new URLSearchParams(location.search).get('carpeta')), filter: 'todos', query: '', busy: false };
  if (!data.carpetas.some(c => number(c.id) === state.folder)) state.folder = 0;
  const labels = interna ? { todos: 'Todos' } : { todos: 'Todos', mios: 'Pendientes de mí', revision: 'En revisión', devuelto: 'Devueltos', aprobado: 'Aprobados', rechazado: 'Rechazados', borrador: 'Borradores' };
  const actions = { creado: 'Documento registrado', enviado: 'Enviado a revisión', aprobado: 'Aprobado', devuelto: 'Devuelto con observaciones', rechazado: 'Rechazado', reenviado: 'Observaciones atendidas y reenviado', nueva_version: 'Nueva versión creada', compartido: 'Compartido con ingenios', omitido: 'Segunda revisión no requerida', editado: 'Datos actualizados' };
  const reviewing = r => !!data.etapas[r.flujo], shared = r => r.historial.some(h => h.accion === 'compartido');
  const owner = r => r.cargado_por_nombre || r.autor || 'Administración (registro anterior)';
  const waiting = r => data.etapas[r.flujo]?.rol || (['borrador', 'devuelto', 'rechazado', 'aprobado'].includes(r.flujo) ? owner(r) : '');
  const days = value => value ? Math.max(0, Math.floor((Date.parse(data.hoy) - Date.parse(value.slice(0, 10))) / 86400000)) : 0;
  const badge = r => `<span class="bib-badge ${esc(r.flujo)}">${esc(r.flujo === 'devuelto' && r.devuelto_en === 'rev1' ? 'Devuelto con anotaciones' : data.estados[r.flujo])}</span>`;
  const btn = (action, label, id = '', cls = '', attrs = '') => `<button type="button" class="bib-btn ${cls}" data-bib="${action}" data-id="${esc(id)}" ${attrs}>${label}</button>`;
  const input = (label, name, value = '', type = 'text', attrs = '') => `<label>${label}<input class="bib-input" type="${type}" name="${name}" value="${esc(value)}" ${attrs}></label>`;
  const textarea = (label, name, value = '') => `<label class="bib-wide">${label}<textarea class="bib-input" name="${name}" rows="3">${esc(value)}</textarea></label>`;
  const options = (list, value) => list.map(([v, text]) => `<option value="${esc(v)}" ${String(v) === String(value) ? 'selected' : ''}>${esc(text)}</option>`).join('');
  const select = (label, name, list, value) => `<label>${label}<select class="bib-input" name="${name}">${options(list, value)}</select></label>`;
  const check = (text, name, yes, attrs = '') => `<label class="bib-wide"><input type="checkbox" name="${name}" ${yes ? 'checked' : ''} ${attrs}> ${text}</label>`;
  const form = (action, body, r = {}, text = 'Guardar') => `<form data-form="${action}" data-id="${number(r.id)}" data-revision="${esc(r.revision)}"><div class="bib-fields">${body}</div><div class="bib-form-actions">${btn('cerrar', 'Cancelar')}<button type="submit" class="bib-btn primary">${text}</button></div></form>`;
  const fileInput = (label = 'Archivo del documento (hasta 20 MB)') => input(label, 'archivo', '', 'file', 'accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.png,.jpg,.jpeg,.webp,.mp4,.txt"');
  const download = (r, h = null) => (h ? h.archivo_ruta : r.archivo_ruta) ? `<a class="bib-btn small" href="index.php?view=${view}&descarga=${r.id}${h ? '&historial=' + number(h.id) : ''}"><i class="ti ti-download"></i> ${esc(h ? h.archivo_nombre : 'Descargar')}</a>` : '';
  const link = url => /^https?:\/\//i.test(url || '') ? `<a class="bib-btn small" href="${esc(url)}" target="_blank" rel="noopener noreferrer"><i class="ti ti-external-link"></i> Abrir enlace</a>` : '';
  function message(text, error = false) { $('bib-message').textContent = text; $('bib-message').hidden = !text; $('bib-message').classList.toggle('error', error); }
  function fail(e) { if ($('bib-dialog').open) { $('bib-error').textContent = e.message; $('bib-error').hidden = false; } else message(e.message, true); }
  function modal(title, content, wide = false) { $('bib-title').textContent = title; $('bib-modal').innerHTML = content; $('bib-error').hidden = true; $('bib-dialog').classList.toggle('wide', wide); if (!$('bib-dialog').open) $('bib-dialog').showModal(); }
  async function request(action, values, files = {}) {
    const fd = new FormData(); fd.set('bib_accion', action); fd.set('csrf', data.csrf); fd.set('datos', JSON.stringify(values));
    Object.entries(files).forEach(([key, file]) => { if (file?.size) fd.set(key, file); });
    const response = await fetch(endpoint, { method: 'POST', body: fd }), result = await response.json();
    if (!response.ok || !result.ok) throw new Error(result.error || 'No se pudo completar la operación.');
    data = result.datos; render(); message(result.mensaje);
  }
  function matches(r, filter) {
    if (filter === 'todos') return true;
    if (filter === 'mios') return r.revisar || r.propietario && (['borrador', 'devuelto'].includes(r.flujo) || r.flujo === 'aprobado' && !shared(r));
    if (filter === 'revision') return reviewing(r);
    return r.flujo === filter;
  }
  function help() {
    return `<div class="bib-panel"><div class="bib-help-grid"><div><b>1. Carga y envío</b><small>Quien carga el documento lo envía a revisión.</small></div><div><b>2. Primera revisión</b><small>Jefe de Transferencia: revisa y hace anotaciones.</small></div><div><b>3. Segunda revisión</b><small>Jefe de Transferencia: verifica las correcciones.</small></div><div><b>4. Aprobación final</b><small>Director General: aprueba la versión institucional.</small></div><div><b>5. Envío a ingenios</b><small>Quien cargó el documento lo comparte por correo.</small></div></div><p class="bib-note">La primera revisión puede devolver el documento con anotaciones; al corregirlo pasa a segunda revisión. Si se aprueba sin anotaciones, pasa directamente al Director General. Las siguientes devoluciones regresan a su misma etapa. Un rechazo cierra la versión; para retomarlo se crea una nueva versión desde el inicio. Todo queda en el seguimiento.</p></div>`;
  }
  function render() {
    $('bib-actions').innerHTML = (interna ? '' : btn('ayuda', 'Cómo funciona la revisión')) + (data.gestionar ? btn('carpeta', '+ Crear carpeta', '', 'primary') + (state.folder ? btn('nuevo', '↑ Cargar documento') + btn('lote', '↑ Cargar varios archivos') : '') : '');
    $('bib-help').innerHTML = interna ? '' : help();
    $('bib-filters').innerHTML = Object.entries(labels).map(([key, text]) => btn('filtro', `${text} (${data.documentos.filter(r => matches(r, key)).length})`, key, 'bib-pill ' + (state.filter === key ? 'primary' : ''))).join('');
    const global = state.filter !== 'todos' || state.query.trim().length > 0;
    const rows = data.documentos.filter(r => (global || r.carpeta_id === state.folder) && matches(r, state.filter) && norm(`${r.titulo} ${r.autor} ${data.tipos[r.tipo]} ${r.descripcion}`).includes(norm(state.query)));
    $('bib-count').textContent = `${global || state.folder ? rows.length : data.documentos.length} documento(s)`;
    let html = !data.gestionar ? '<p class="bib-note">Acceso de consulta: puede ver y descargar documentos. ' + (interna ? 'La carga y edición la realiza el Administrador.' : 'Las decisiones de revisión se habilitan según su responsabilidad.') + '</p>' : '';
    if (global) html += `<p class="bib-note">Mostrando resultados de todas las carpetas. ${btn('limpiar', 'Volver a carpetas', '', 'small')}</p>`;
    else {
      const crumbs = [], visited = new Set(); let current = data.carpetas.find(c => number(c.id) === state.folder);
      while (current && !visited.has(current.id)) { visited.add(current.id); crumbs.unshift(current); current = data.carpetas.find(c => number(c.id) === number(current.parent_id)); }
      html += `<nav class="bib-breadcrumb" aria-label="Carpetas">${btn('abrir', 'Biblioteca', 0)}${crumbs.map(c => `<span>/</span>${btn('abrir', esc(c.nombre), c.id)}`).join('')}</nav><div class="bib-folders">${data.carpetas.filter(c => number(c.parent_id) === state.folder).map(c => `<button class="bib-folder" data-bib="abrir" data-id="${number(c.id)}"><i class="ti ti-folder"></i><span><strong>${esc(c.nombre)}</strong><small>${data.documentos.filter(r => r.carpeta_id === number(c.id)).length} documento(s)</small></span></button>`).join('')}</div>`;
      if (!state.folder) html += `<div class="bib-empty">${data.carpetas.length ? 'Abra una carpeta para ver y cargar documentos, o use la búsqueda para consultar toda la biblioteca.' : 'La primera acción es crear una carpeta. Los documentos se cargan únicamente dentro de una carpeta.'}</div>`;
      if (state.folder && data.gestionar) html += `<div class="bib-drop" data-drop>Arrastre aquí uno o varios archivos para cargarlos en <b>${esc(data.carpetas.find(c => number(c.id) === state.folder)?.nombre)}</b>, o use “Cargar varios archivos”.</div>`;
    }
    if (global || state.folder) html += `<div class="bib-scroll"><table class="bib-table"><thead><tr><th>Documento</th><th>Versión</th><th>${interna ? 'Tipo' : 'Proceso de revisión'}</th><th style="text-align:right">Acciones</th></tr></thead><tbody>${rows.map(r => row(r, global)).join('') || '<tr><td colspan="4"><div class="bib-empty">No hay documentos con este filtro o en esta carpeta.</div></td></tr>'}</tbody></table></div>`;
    $('bib-content').innerHTML = html;
  }
  function row(r, global) {
    if (interna) return `<tr><td><strong>${esc(r.titulo)}</strong><span class="bib-meta">${esc(data.tipos[r.tipo])} · ${esc(r.autor || '—')} · cargado por ${esc(owner(r))} · ${esc(r.fecha || '—')}${r.archivo_nombre ? ' · ' + esc(r.archivo_nombre) : ''}${global ? ' · ' + esc(data.carpetas.find(c => number(c.id) === r.carpeta_id)?.nombre) : ''}</span></td><td><span class="bib-badge">v${number(r.version)}</span></td><td>${esc(data.tipos[r.tipo])}</td><td><details class="bib-menu"><summary class="bib-btn small"><i class="ti ti-dots-vertical"></i> Acciones <i class="ti ti-chevron-down"></i></summary><div class="bib-action-list" role="menu">${download(r)}${link(r.enlace)}${data.gestionar ? btn('editar', '<i class="ti ti-edit"></i> Editar', r.id, 'small') + btn('eliminar', '<i class="ti ti-trash"></i> Eliminar', r.id, 'small danger') : ''}</div></details></td></tr>`;
    const obs = [...r.historial].reverse().find(h => ['devuelto', 'rechazado'].includes(h.accion)), index = ['rev1', 'rev2', 'rev3'].indexOf(r.flujo);
    let action = '';
    if (r.revisar) action = btn('revisar', 'Revisar', r.id, 'primary small');
    else if (reviewing(r)) action = `<span class="bib-note">Esperando a ${esc(waiting(r))}</span>`;
    else if (r.propietario && data.gestionar && r.flujo === 'borrador') action = btn('enviar-revision', 'Enviar a revisión', r.id, 'primary small');
    else if (r.propietario && data.gestionar && r.flujo === 'devuelto') action = btn('corregir', r.devuelto_en === 'rev1' ? 'Corregir anotaciones' : 'Atender observaciones', r.id, 'small danger');
    else if (r.propietario && data.gestionar && r.flujo === 'rechazado') action = btn('nueva-version', 'Crear nueva versión', r.id, 'small');
    else if (r.enviar) action = btn('correo', 'Enviar por correo', r.id, 'primary small');
    else if (r.flujo === 'devuelto' || r.flujo === 'aprobado') action = `<span class="bib-note">${r.flujo === 'devuelto' ? 'Correcciones' : 'Envío'} a cargo de ${esc(owner(r))}</span>`;
    return `<tr><td><strong>${esc(r.titulo)}</strong><span class="bib-meta">${esc(data.tipos[r.tipo])} · ${esc(r.autor || '—')} · cargado por ${esc(owner(r))} · ${esc(r.fecha || '—')}${r.archivo_nombre ? ' · ' + esc(r.archivo_nombre) + (r.archivo_bytes ? ` (${(number(r.archivo_bytes) / 1048576).toFixed(2)} MB)` : '') : ''}${global ? ' · ' + esc(data.carpetas.find(c => number(c.id) === r.carpeta_id)?.nombre) : ''}</span></td><td><span class="bib-badge">v${number(r.version)}</span></td><td style="min-width:230px">${badge(r)}${reviewing(r) ? `<div class="bib-steps" aria-label="Etapa ${index + 1} de 3">${[0, 1, 2].map(i => `<span class="${i < index ? 'passed' : i === index ? 'current' : ''}"></span>`).join('')}</div>` : ''}<span class="bib-meta">${r.flujo === 'aprobado' ? (shared(r) ? 'Versión aprobada · envío registrado' : 'Versión final aprobada · pendiente de envío') : waiting(r) ? 'Le toca a ' + esc(waiting(r)) + (days(r.pendiente_desde) ? ` · ${days(r.pendiente_desde)} día(s)` : '') : ''}</span>${r.flujo === 'devuelto' && (obs?.observaciones || r.observaciones) ? `<div class="bib-notice red">${esc(obs?.observaciones || r.observaciones)}</div>` : ''}</td><td><details class="bib-menu"><summary class="bib-btn small"><i class="ti ti-dots-vertical"></i> Acciones <i class="ti ti-chevron-down"></i></summary><div class="bib-action-list" role="menu">${action ? `<div class="bib-menu-main">${action}</div>` : ''}${btn('seguimiento', '<i class="ti ti-history"></i> Seguimiento del proceso', r.id, 'small')}${download(r)}${link(r.enlace)}${data.gestionar ? btn('editar', '<i class="ti ti-edit"></i> Editar', r.id, 'small', `title="${reviewing(r) ? 'Bloqueado durante la revisión' : 'Editar'}" ${reviewing(r) ? 'disabled' : ''}`) + btn('eliminar', '<i class="ti ti-trash"></i> Eliminar', r.id, 'small danger') : ''}</div></details></td></tr>`;
  }
  function editForm(r = {}) {
    const fresh = !r.id;
    modal(fresh ? 'Nuevo documento' : 'Editar documento', form('guardar', select('Carpeta', 'carpeta_id', data.carpetas.map(c => [c.id, c.nombre]), r.carpeta_id || state.folder) + select('Tipo', 'tipo', Object.entries(data.tipos), r.tipo || (interna ? 'Informe' : 'Boletin')) + input('Fecha', 'fecha', r.fecha || data.hoy, 'date') + input('Versión', 'version_label', 'v' + (r.version || 1), 'text', 'readonly') + `<div class="bib-wide">${input('Título', 'titulo', r.titulo, 'text', 'required maxlength="220"')}</div>` + input('Autor / responsable', 'autor', r.autor, 'text', 'maxlength="180"') + textarea('Descripción', 'descripcion', r.descripcion) + fileInput() + input('Enlace externo (opcional)', 'enlace', r.enlace, 'url', 'maxlength="500" placeholder="https://…"') + (r.archivo_nombre ? `<p class="bib-note bib-wide">Archivo actual: ${esc(r.archivo_nombre)} ${download(r)}</p>` : '') + (interna ? '' : `<div class="bib-notice blue bib-wide">${check('Requiere revisión y aprobación antes de compartirse', 'requiere_aprobacion', fresh || number(r.requiere_aprobacion), !fresh && number(r.requiere_aprobacion) && r.flujo !== 'borrador' ? 'disabled' : '')}${fresh ? check('Enviar a primera revisión al guardar (si no, queda como borrador)', 'enviar_ahora', true) : ''}<p class="bib-note">Primera revisión → corrección → segunda revisión → aprobación final. La versión aumenta al reemplazar el archivo o el enlace.</p></div>`), r), true);
  }
  function reviewForm(r) {
    const stage = data.etapas[r.flujo], obs = r.historial.filter(h => h.observaciones && ['devuelto', 'reenviado', 'aprobado', 'rechazado'].includes(h.accion));
    const decisions = r.flujo === 'rev1' ? [['devuelto', 'Devolver con anotaciones', 'Regresa a quien lo cargó. La corrección pasa a segunda revisión.'], ['aprobado', 'Aprobar sin anotaciones', 'Pasa directamente al Director General.'], ['rechazado', 'Rechazar', 'Se cierra esta versión.']] : [['aprobado', r.flujo === 'rev2' ? 'Correcciones conformes' : 'Aprobar', r.flujo === 'rev2' ? 'Pasa a la aprobación final.' : 'Habilita el envío a ingenios.'], ['devuelto', 'Devolver con observaciones', 'La corrección regresa a esta misma etapa.'], ['rechazado', 'Rechazar', 'Se cierra esta versión.']];
    modal('Revisar · ' + stage.nombre, form('revisar', `<div class="bib-notice bib-wide"><b>${esc(stage.nombre)} · ${esc(stage.rol)}</b><p>${esc(r.titulo)} · v${r.version} · cargado por ${esc(owner(r))}</p>${download(r)} ${link(r.enlace)}</div>${obs.length ? `<div class="bib-panel bib-wide"><h3>Observaciones anteriores</h3>${obs.map(h => `<p class="bib-note"><b>${esc(h.actor)}</b> · v${number(h.version)} · ${esc(h.creado_en)}<br>${esc(h.observaciones)} ${download(r, h)}</p>`).join('')}</div>` : ''}<div class="bib-decision bib-wide">${decisions.map(([value, title, description]) => `<label><input type="radio" name="decision" value="${value}" required> <b>${title}</b><small>${description}</small></label>`).join('')}</div>${textarea('Anotaciones / observaciones (obligatorias al devolver o rechazar)', 'observaciones')}${fileInput('Documento con anotaciones (opcional)')}<p class="bib-note bib-wide">La decisión se registra con su usuario, fecha, hora y versión.</p>`, r, 'Confirmar decisión'), true);
  }
  function correctionForm(r) {
    const last = [...r.historial].reverse().find(h => h.accion === 'devuelto');
    modal(r.devuelto_en === 'rev1' ? 'Corregir anotaciones' : 'Atender observaciones', form('corregir', `<div class="bib-notice red bib-wide"><b>${esc(last?.actor || 'Revisor')} · ${esc(data.etapas[r.devuelto_en]?.nombre)}</b><p>${esc(last?.observaciones || r.observaciones || 'Sin observaciones registradas')}</p>${last ? download(r, last) : ''}</div>${fileInput('Versión corregida (quedará como v' + (number(r.version) + 1) + ')')}${input('Enlace (si está en línea)', 'enlace', r.enlace, 'url')}${textarea('¿Cómo se atendieron las observaciones?', 'observaciones')}<p class="bib-note bib-wide">Se enviará a ${esc(data.etapas[r.reenviar_a || (r.devuelto_en === 'rev1' ? 'rev2' : r.devuelto_en)]?.nombre || 'Primera revisión')}.</p>`, r, 'Enviar corrección'));
  }
  function tracking(r) {
    const sent = r.historial.find(h => h.accion === 'enviado'), approved = [...r.historial].reverse().find(h => h.accion === 'aprobado' && h.etapa === 'rev3');
    const circle = (title, who, status, note) => `<div class="bib-stage ${status}"><b>${status === 'complete' ? '✓' : status === 'current' ? '●' : status === 'returned' ? '↩' : '○'}</b><strong>${esc(title)}</strong><small>${esc(who)}</small><small>${esc(note)}</small></div>`;
    const timeline = circle('Envío a revisión', owner(r), sent ? 'complete' : 'current', sent ? sent.creado_en : 'Borrador sin enviar') + Object.entries(data.etapas).map(([key, st]) => { const restart = r.historial.reduce((n, h, i) => h.accion === 'enviado' || h.accion === 'nueva_version' && h.estado === 'Borrador' ? i : n, 0), logs = r.historial.slice(restart).filter(h => h.etapa === key), latest = logs[logs.length - 1], isCurrent = r.flujo === key; let status = isCurrent ? 'current' : (r.devuelto_en === key || r.flujo === 'rechazado' && latest?.accion === 'rechazado') ? 'returned' : logs.some(h => ['aprobado', 'omitido'].includes(h.accion)) ? 'complete' : ''; return circle(st.nombre, st.rol, status, isCurrent ? `Pendiente hace ${days(r.pendiente_desde)} días` : latest ? (actions[latest.accion] || latest.estado) + ' · ' + latest.actor : 'Pendiente'); }).join('') + circle('Envío a ingenios', owner(r), shared(r) ? 'complete' : r.flujo === 'aprobado' ? 'current' : '', shared(r) ? `${r.historial.filter(h => h.accion === 'compartido').length} envío(s)` : r.flujo === 'aprobado' ? 'Habilitado, sin enviar' : 'Se habilita al aprobar');
    modal('Seguimiento · ' + r.titulo, `<div class="bib-notice">${badge(r)} · v${number(r.version)} · Le toca a: ${esc(waiting(r) || '—')}<br>${r.historial.filter(h => h.accion === 'devuelto').length} devolución(es)${sent ? ' · Tiempo en revisión: ' + Math.max(0, Math.round((Date.parse((approved?.creado_en || data.hoy).slice(0, 10)) - Date.parse(sent.creado_en.slice(0, 10))) / 86400000)) + ' días' : ''}</div><div class="bib-timeline">${timeline}</div><h3>Bitácora del proceso</h3><div class="bib-log">${[...r.historial].reverse().map(h => `<article><strong>${esc(actions[h.accion] || h.estado)}</strong> ${h.etapa ? ' · ' + esc(data.etapas[h.etapa]?.nombre || h.etapa) : ''} <small>v${number(h.version) || '—'} · ${esc(h.creado_en)}</small><div><small>${esc(h.actor)}${h.rol ? ' · ' + esc(h.rol) : ''}</small></div>${h.observaciones ? `<p>${esc(h.observaciones)}</p>` : ''}${download(r, h)} ${link(h.enlace)}</article>`).join('') || '<article>Sin movimientos registrados.</article>'}</div>`, true);
  }
  let batch = [], batchKey = 0;
  function guessType(name) { const n = norm(name); return /boletin/.test(n) ? 'Boletin' : /memoria/.test(n) ? 'Memoria' : /informe/.test(n) ? 'Informe' : /\.(ppt|pptx)$/.test(n) ? 'Presentacion' : /\.(jpg|jpeg|png|webp)$/.test(n) ? 'Fotografia' : /\.mp4$/.test(n) ? 'Video' : 'Otro'; }
  function addFiles(files) {
    if (batch.length + files.length > 20) throw new Error('Cada lote admite hasta 20 archivos.');
    for (const file of files) { if (file.size > 20 * 1048576) throw new Error(file.name + ' supera 20 MB.'); }
    if (batch.reduce((s, f) => s + f.file.size, 0) + [...files].reduce((s, f) => s + f.size, 0) > 24 * 1048576) throw new Error('El lote supera 24 MB. Divide los archivos en varios lotes.');
    batch.push(...[...files].map(file => ({ key: ++batchKey, file, titulo: file.name.replace(/\.[^.]+$/, '').replace(/[_-]/g, ' '), tipo: guessType(file.name) }))); renderBatch();
  }
  function batchForm(files = []) {
    batch = [];
    modal('Cargar varios archivos · ' + (data.carpetas.find(c => number(c.id) === state.folder)?.nombre || ''), form('lote', `<div class="bib-drop bib-wide" data-drop="batch">Arrastre los archivos aquí o <label class="bib-btn">Seleccionar archivos<input type="file" id="bib-batch-files" multiple hidden></label><p class="bib-note">Hasta 20 archivos; 20 MB por archivo y 24 MB por lote.</p></div><div class="bib-wide" id="bib-batch-list"></div><h3 class="bib-wide">Datos comunes para todos los archivos</h3>${input('Fecha', 'fecha', data.hoy, 'date')}${input('Autor / área', 'autor', '', 'text', 'maxlength="180"')}${textarea('Descripción (opcional)', 'descripcion')}${interna ? '' : check('Requieren revisión y aprobación (quedan como borrador)', 'requiere_aprobacion', true)}`, {}, 'Cargar documentos'), true);
    if (files.length) addFiles(files); else renderBatch();
  }
  function renderBatch() {
    $('bib-batch-list').innerHTML = `<div class="bib-row"><b>${batch.length} archivo(s) · ${(batch.reduce((s, f) => s + f.file.size, 0) / 1048576).toFixed(2)} MB</b>${btn('vaciar-lote', 'Quitar todos', '', 'small danger')}</div><div class="bib-log">${batch.map(f => `<div class="bib-batch-row"><div><input class="bib-input" data-batch-title="${f.key}" value="${esc(f.titulo)}" required maxlength="220" aria-label="Título"><small>${esc(f.file.name)} · ${(f.file.size / 1048576).toFixed(2)} MB</small></div><select class="bib-input" data-batch-type="${f.key}" aria-label="Tipo">${options(Object.entries(data.tipos), f.tipo)}</select>${btn('quitar-lote', '×', f.key, 'small danger', 'aria-label="Quitar archivo"')}</div>`).join('')}</div>`;
  }
  let mail = null;
  const validEmail = c => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(c.email || '');
  function segmentMatches(c, f) {
    return Object.entries(f).every(([key, value]) => { if (!value || ['todos', 'todas'].includes(value)) return true; if (key === 'q') return norm(`${c.nombre} ${c.email} ${c.cargo} ${c.empresa}`).includes(norm(value)); if (key === 'comite') return c.comites.includes(value); if (key === 'origen') return c.origen_tipo === value; if (['area', 'nivel', 'empresa', 'estado'].includes(key)) return c[key] === value; return true; });
  }
  function selectedContacts() {
    return data.contactos.filter(c => !mail.excluded.has(c.id) && (mail.added.has(c.id) || c.comites.some(id => mail.groups.has(id)) || data.segmentos.some(s => mail.segments.has(number(s.id)) && segmentMatches(c, s.filtros))));
  }
  function emailForm(r) {
    mail = { groups: new Set(), segments: new Set(), added: new Set(), excluded: new Set(), query: '', groupQuery: '', all: false, logo: '' };
    modal('Enviar documento por correo', `<form data-form="correo" data-id="${r.id}" data-revision="${esc(r.revision)}"><div class="bib-notice blue"><b>Correo estándar CENGICAÑA</b><p>Seleccione comités o segmentos y ajuste los destinatarios. Solo se incluyen contactos activos con correo válido. El envío queda registrado.</p></div><div class="bib-mail-grid"><div>${input('Documento', 'referencia', r.titulo, 'text', 'readonly')}${input('Asunto', 'asunto', `CENGICAÑA | ${r.titulo}`, 'text', 'required maxlength="220"')}${input('Logo institucional / del área (opcional)', 'logo_archivo', '', 'file', 'accept="image/png,image/jpeg,image/webp"')}${textarea('Mensaje', 'cuerpo', `Estimado/a,\n\nCENGICAÑA comparte con usted el siguiente documento: ${r.titulo}.\n\nSaludos cordiales.`)}${select('Compartir como', 'modo', [...(r.archivo_ruta ? [['archivo', 'Archivo adjunto']] : []), ...(r.enlace ? [['enlace', 'Enlace externo']] : [])], r.archivo_ruta ? 'archivo' : 'enlace')}<div class="bib-panel"><h3>Vista previa</h3><img id="bib-mail-logo" class="bib-logo" alt="Logo" hidden><strong>${esc(r.titulo)}</strong><p id="bib-mail-preview" class="bib-preview"></p><small>${esc(r.archivo_nombre || r.enlace)}</small></div></div><div><section class="bib-panel"><h3>1. Comités destinatarios</h3><div class="bib-row">${btn('grupos-todos', 'Todos', '', 'small')}${btn('grupos-ninguno', 'Ninguno', '', 'small')}</div><input class="bib-input" id="bib-group-search" placeholder="Filtrar comités…" aria-label="Filtrar comités"><div id="bib-mail-groups" class="bib-checks"></div></section>${data.segmentos.length ? `<section class="bib-panel"><h3>Segmentos del directorio</h3><div class="bib-checks">${data.segmentos.map(s => `<label><input type="checkbox" data-segment="${number(s.id)}"> ${esc(s.nombre)} · ${data.contactos.filter(c => segmentMatches(c, s.filtros)).length}</label>`).join('')}</div></section>` : ''}<section class="bib-panel"><h3>2. Destinatarios</h3><div class="bib-row">${btn('otros-contactos', 'Agregar otros contactos', '', 'small')}${btn('restablecer', 'Restablecer excluidos', '', 'small')}</div><input class="bib-input" id="bib-mail-search" placeholder="Buscar contacto para agregar o quitar…" aria-label="Buscar destinatarios"><div id="bib-mail-contacts" class="bib-checks"></div><p id="bib-mail-count" class="bib-note"></p></section></div></div><div class="bib-form-actions">${btn('cerrar', 'Cancelar')}<button class="bib-btn primary" type="submit">Enviar y registrar</button></div></form>`, true);
    renderRecipients(); updatePreview();
  }
  function renderRecipients() {
    $('bib-mail-groups').innerHTML = data.comites.filter(c => norm(c.nombre).includes(norm(mail.groupQuery))).map(c => { const n = data.contactos.filter(p => p.comites.includes(c.id)).length; return `<label><input type="checkbox" data-group="${esc(c.id)}" ${mail.groups.has(c.id) ? 'checked' : ''} ${!n ? 'disabled' : ''}><span>${esc(c.nombre)}<small>${n} integrantes activos</small></span></label>`; }).join('');
    const chosen = selectedContacts(), selected = new Set(chosen.map(c => c.id)), list = (mail.all || mail.query ? data.contactos : chosen).filter(c => norm(`${c.nombre} ${c.email} ${c.cargo} ${c.empresa}`).includes(norm(mail.query)));
    $('bib-mail-contacts').innerHTML = list.map(c => `<label><input type="checkbox" data-contact="${c.id}" ${selected.has(c.id) && validEmail(c) ? 'checked' : ''} ${!validEmail(c) ? 'disabled' : ''}><span>${esc(c.nombre)}<small>${validEmail(c) ? esc(c.email) : 'Sin correo válido: no recibirá el envío'}</small></span></label>`).join('') || '<p class="bib-note">Seleccione comités o agregue contactos.</p>';
    $('bib-mail-count').textContent = `${chosen.filter(validEmail).length} destinatario(s) · ${chosen.filter(c => !validEmail(c)).length} sin correo válido · ${mail.excluded.size} excluido(s)`;
  }
  function updatePreview() { const f = $('bib-modal').querySelector('form'); $('bib-mail-preview').textContent = f.elements.cuerpo.value; }
  function values(f) { const result = {}; [...f.elements].forEach(el => { if (!el.name || el.type === 'file' || el.type === 'radio' && !el.checked) return; result[el.name] = el.type === 'checkbox' ? el.checked : el.value; }); return result; }
  root.addEventListener('click', async event => {
    const b = event.target.closest('[data-bib]'); if (!b || b.disabled || state.busy) return;
    const action = b.dataset.bib, id = b.dataset.id, r = data.documentos.find(d => d.id === number(id));
    try {
      if (action === 'cerrar') $('bib-dialog').close();
      else if (action === 'ayuda') $('bib-help').hidden = !$('bib-help').hidden;
      else if (action === 'abrir') { state.folder = number(id); state.filter = 'todos'; state.query = ''; $('bib-search').value = ''; history.replaceState(null, '', 'index.php?view=' + view + (state.folder ? '&carpeta=' + state.folder : '')); render(); }
      else if (action === 'filtro') { state.filter = id; render(); }
      else if (action === 'limpiar') { state.filter = 'todos'; state.query = ''; $('bib-search').value = ''; render(); }
      else if (action === 'carpeta') modal('Nueva carpeta', form('carpeta', input('Nombre de la carpeta', 'nombre', '', 'text', 'required maxlength="160"'), {}, 'Crear carpeta'));
      else if (action === 'nuevo') editForm();
      else if (action === 'editar') editForm(r);
      else if (action === 'revisar') reviewForm(r);
      else if (action === 'corregir') correctionForm(r);
      else if (action === 'seguimiento') tracking(r);
      else if (action === 'correo') emailForm(r);
      else if (action === 'lote') batchForm();
      else if (action === 'vaciar-lote') { batch = []; renderBatch(); }
      else if (action === 'quitar-lote') { batch = batch.filter(f => f.key !== number(id)); renderBatch(); }
      else if (action === 'otros-contactos') { mail.all = !mail.all; b.textContent = mail.all ? 'Ver solo seleccionados' : 'Agregar otros contactos'; renderRecipients(); }
      else if (action === 'grupos-todos') { mail.groups = new Set(data.comites.filter(c => data.contactos.some(p => p.comites.includes(c.id))).map(c => c.id)); renderRecipients(); }
      else if (action === 'grupos-ninguno') { mail.groups.clear(); renderRecipients(); }
      else if (action === 'restablecer') { mail.excluded.clear(); renderRecipients(); }
      else if (['enviar-revision', 'nueva-version', 'eliminar'].includes(action)) {
        if (action === 'eliminar' && !confirm('¿Eliminar este documento y su historial? Esta acción no se puede deshacer.')) return;
        if (action === 'nueva-version' && !confirm(`Se creará la versión v${number(r.version) + 1} en borrador. ¿Continuar?`)) return;
        state.busy = true; b.disabled = true; await request({ 'enviar-revision': 'enviar_revision', 'nueva-version': 'nueva_version', eliminar: 'eliminar' }[action], { id: r.id, revision: r.revision });
      }
    } catch (e) { fail(e); } finally { state.busy = false; b.disabled = false; }
  });
  root.addEventListener('input', event => {
    const el = event.target;
    if (el.id === 'bib-search') { state.query = el.value; render(); }
    else if (el.dataset.batchTitle) { const f = batch.find(f => f.key === number(el.dataset.batchTitle)); if (f) f.titulo = el.value; }
    else if (el.id === 'bib-mail-search') { mail.query = el.value; renderRecipients(); }
    else if (el.id === 'bib-group-search') { mail.groupQuery = el.value; renderRecipients(); }
    else if (el.name === 'cuerpo' && mail && $('bib-mail-preview')) updatePreview();
  });
  root.addEventListener('change', async event => {
    const el = event.target;
    try {
      if (el.id === 'bib-batch-files') { addFiles(el.files); el.value = ''; }
      else if (el.dataset.batchType) { const f = batch.find(f => f.key === number(el.dataset.batchType)); if (f) f.tipo = el.value; }
      else if (el.dataset.group) { el.checked ? mail.groups.add(el.dataset.group) : mail.groups.delete(el.dataset.group); renderRecipients(); }
      else if (el.dataset.segment) { el.checked ? mail.segments.add(number(el.dataset.segment)) : mail.segments.delete(number(el.dataset.segment)); renderRecipients(); }
      else if (el.dataset.contact) { const id = number(el.dataset.contact); if (el.checked) { mail.added.add(id); mail.excluded.delete(id); } else { mail.added.delete(id); mail.excluded.add(id); } renderRecipients(); }
      else if (el.name === 'logo_archivo') { const file = el.files[0]; if (!file) { mail.logo = ''; $('bib-mail-logo').hidden = true; return; } if (file.size > 1048576 || !['image/png', 'image/jpeg', 'image/webp'].includes(file.type)) throw new Error('Usa PNG, JPG o WebP de hasta 1 MB.'); mail.logo = await new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(reader.result); reader.onerror = () => reject(new Error('No se pudo leer la imagen.')); reader.readAsDataURL(file); }); $('bib-mail-logo').src = mail.logo; $('bib-mail-logo').hidden = false; }
    } catch (e) { fail(e); }
  });
  for (const type of ['dragover', 'dragleave', 'drop']) root.addEventListener(type, event => {
    const drop = event.target.closest('[data-drop]'); if (!drop || state.busy) return; event.preventDefault(); drop.classList.toggle('over', type === 'dragover');
    if (type === 'drop') try { if (drop.dataset.drop === 'batch') addFiles(event.dataTransfer.files); else batchForm(event.dataTransfer.files); } catch (e) { fail(e); }
  });
  root.addEventListener('submit', async event => {
    const f = event.target.closest('[data-form]'); if (!f) return; event.preventDefault(); if (state.busy) return;
    const submit = f.querySelector('[type=submit]'), action = f.dataset.form, d = { ...values(f), id: number(f.dataset.id), revision: f.dataset.revision }, files = {};
    try {
      f.querySelectorAll('input[type=file]').forEach(el => { if (el.name && el.name !== 'logo_archivo' && el.files[0]) files[el.name] = el.files[0]; });
      if (action === 'carpeta') d.parent_id = state.folder;
      if (action === 'guardar') { const r = data.documentos.find(r => r.id === d.id); if (!interna && r?.flujo === 'aprobado' && (files.archivo || (r.enlace || '') !== d.enlace) && !confirm(`Reemplazar el archivo o enlace crea v${number(r.version) + 1} y reinicia la revisión. ¿Continuar?`)) return; }
      if (action === 'lote') { if (!batch.length) throw new Error('Agrega al menos un archivo.'); d.carpeta_id = state.folder; d.filas = batch.map((item, i) => { files['archivo_' + i] = item.file; return { titulo: item.titulo, tipo: item.tipo }; }); }
      if (action === 'correo') { d.contactos = selectedContacts().filter(validEmail).map(c => c.id); d.comites = [...mail.groups]; d.logo = mail.logo; if (!d.contactos.length) throw new Error('Selecciona al menos un destinatario con correo válido.'); if (!confirm(`Se enviará “${d.referencia}” a ${d.contactos.length} destinatario(s). ¿Enviar?`)) return; }
      state.busy = true; submit.disabled = true; $('bib-error').hidden = true; await request(action, d, files); $('bib-dialog').close();
    } catch (e) { fail(e); } finally { state.busy = false; submit.disabled = false; }
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
  document.addEventListener('click', event => { if (!event.target.closest('details.bib-menu') || event.target.closest('.bib-action-list [data-bib], .bib-action-list a')) closeMenus(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape') closeMenus(); });
  window.addEventListener('scroll', () => closeMenus(), true);
  window.addEventListener('resize', () => closeMenus());
  render();
})();
