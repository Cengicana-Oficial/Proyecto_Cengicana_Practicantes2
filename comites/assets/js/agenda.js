/* Planificación y ejecución de reuniones y eventos de transferencia. */
(() => {
  'use strict';
  const root = document.getElementById('agenda');
  if (!root) return;
  let data = JSON.parse(document.getElementById('ag-data').textContent);
  const eventos = data.tipo === 'eventos', endpoint = `index.php?view=${data.tipo}&agenda_api=1`;
  const state = { year: Number(data.hoy.slice(0, 4)), tab: 'plan', comite: 'todos', status: 'todos', detail: 0, detailTab: 'resumen' };
  const $ = id => document.getElementById(id), esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const num = v => Number(v) || 0, done = r => num(r.realizada) === 1, canceled = r => num(r.cancelada) === 1;
  const month = r => r.plan_mes || (r.fecha || '').slice(0, 7), name = r => eventos ? r.nombre : (data.comites.find(c => c.id === r.comite_id)?.nombre || r.comite_id);
  const rows = () => data.registros.filter(r => month(r).slice(0, 4) === String(state.year) && (eventos || state.comite === 'todos' || r.comite_id === state.comite));
  const badge = r => `<span class="ag-state ${esc(r.estado)}">${esc(data.estados[r.estado])}</span>`;
  const button = (action, label, id = '', cls = '', attrs = '') => `<button type="button" class="ag-btn ${cls}" data-ag="${action}" data-id="${esc(id)}" ${attrs}>${label}</button>`;
  const options = (items, value) => items.map(([v, label]) => `<option value="${esc(v)}" ${String(v) === String(value) ? 'selected' : ''}>${esc(label)}</option>`).join('');
  const field = (label, key, value = '', type = 'text', attrs = '') => `<label>${label}<input class="ag-input" name="${key}" type="${type}" value="${esc(value)}" ${attrs}></label>`;
  const area = (label, key, value = '') => `<label class="ag-wide">${label}<textarea class="ag-input" name="${key}" rows="3">${esc(value)}</textarea></label>`;
  const select = (label, key, items, value) => `<label>${label}<select class="ag-input" name="${key}">${options(items, value)}</select></label>`;
  const check = (label, key, value = false) => `<label class="ag-wide"><input type="checkbox" name="${key}" ${value ? 'checked' : ''}> ${label}</label>`;
  const committeeOptions = (manageable = false) => data.comites.filter(c => !manageable || c.gestionar && num(c.activo)).map(c => [c.id, c.nombre]);
  const form = (action, body, id = 0, label = 'Guardar') => `<form data-form="${action}" data-id="${id}"><div class="ag-fields">${body}</div><div class="ag-form-actions">${button('cerrar', 'Cancelar')}<button class="ag-btn primary" type="submit">${label}</button></div></form>`;
  const stat = (title, value, note = '') => `<div class="ag-stat"><span>${title}</span><b>${esc(value)}</b><small>${esc(note)}</small></div>`;
  const empty = text => `<div class="ag-empty">${text}</div>`;
  const documentLink = r => {
    const path = eventos ? r.informe_ruta : r.ayuda_memoria_ruta;
    return path && /^\.\.\/uploads\/comites\/[\w/.-]+$/.test(path) ? `<a class="ag-btn small" href="${esc(path)}" target="_blank" rel="noopener">${eventos ? 'Ver informe' : 'Ayuda memoria'}</a>` : '<span class="ag-muted">Sin documento</span>';
  };
  function message(text, error = false) { const el = $('ag-message'); el.textContent = text; el.hidden = !text; el.classList.toggle('error', error); }
  function modal(title, body, wide = false) { $('ag-title').textContent = title; $('ag-modal').innerHTML = body; $('ag-error').hidden = true; $('ag-dialog').classList.toggle('wide', wide); if (!$('ag-dialog').open) $('ag-dialog').showModal(); }
  function failure(error) { if ($('ag-dialog').open) { $('ag-error').textContent = error.message; $('ag-error').hidden = false; } else message(error.message, true); }
  async function request(action, values = {}, files = {}) {
    const fd = new FormData(); fd.set('agenda_accion', action); fd.set('csrf', data.csrf); fd.set('datos', JSON.stringify(values));
    Object.entries(files).forEach(([k, f]) => { if (f?.size) fd.set(k, f); });
    const response = await fetch(endpoint, { method: 'POST', body: fd });
    if (['preview', 'html', 'pdf', 'csv', 'informe_html', 'informe_pdf'].includes(action) && response.ok) return response;
    const result = await response.json(); if (!response.ok || !result.ok) throw new Error(result.error || 'No se pudo completar la operación.');
    data = result.datos; render(); message(result.mensaje); return result;
  }
  function metrics(list) {
    const planned = list.filter(r => r.plan_mes && (!eventos || !num(r.sobre_la_marcha))), due = planned.filter(r => !canceled(r) && (r.plan_fecha ? r.plan_fecha <= data.hoy : r.plan_mes < data.hoy.slice(0, 7)));
    const completed = due.filter(done), same = planned.filter(r => done(r) && r.fecha?.slice(0, 7) === r.plan_mes);
    return { planned, due, completed, same, pct: due.length ? Math.round(completed.length / due.length * 100) : null };
  }
  function render() {
    const canPlan = data.gestionar || !eventos && data.comites.some(c => c.gestionar);
    $('ag-actions').innerHTML = (!eventos && data.gestionar ? button('importar', '↑ Cargar planificación') : '') + button('compartir', 'Compartir / exportar') + (canPlan ? button(eventos ? 'nuevo-evento' : 'planificar', eventos ? '+ Nuevo evento' : '+ Planificar reunión', '', 'primary') : '');
    $('ag-navigation').innerHTML = `<div class="ag-row">${['plan', 'ejecucion', ...(!eventos ? ['analisis'] : [])].map(t => button('tab', { plan: 'Planificación anual', ejecucion: 'Ejecución', analisis: 'Planificado vs. ejecutado' }[t], t, state.tab === t ? 'primary' : '')).join('')}<label>Año <input id="ag-year" class="ag-input ag-year" type="number" min="1900" max="2199" value="${state.year}"></label>${!eventos ? `<select id="ag-comite" class="ag-input" aria-label="Filtrar por comité">${options([['todos', 'Todos los comités'], ...committeeOptions()], state.comite)}</select>` : ''}</div>`;
    $('ag-content').innerHTML = state.tab === 'plan' ? renderPlan() : state.tab === 'ejecucion' ? renderExecution() : renderAnalysis();
  }
  function item(r) { return `<button class="ag-item ${esc(r.estado)}" data-ag="detalle" data-id="${r.id}">${eventos ? `<strong>${esc(r.nombre)}</strong><br>` : ''}${esc(done(r) ? r.fecha : r.plan_fecha || 'Fecha por definir')}<br>${esc(data.estados[r.estado])}${eventos ? `<span class="ag-sub">${num(r.fijo) ? 'Fijo' : num(r.sobre_la_marcha) ? 'Sobre la marcha' : 'Adicional'}</span>` : ''}</button>`; }
  function renderPlan() {
    const list = rows(), m = metrics(list);
    const legend = `<div class="ag-row ag-note">${Object.entries(data.estados).map(([k, v]) => `<span class="ag-state ${k}">${esc(v)}</span>`).join('')}</div>`;
    if (!eventos) return `<div class="ag-stats">${stat('Planificadas', m.planned.length)}${stat('Realizadas', list.filter(done).length)}${stat('Cumplimiento', m.pct === null ? '—' : m.pct + '%', 'De las reuniones ya exigibles')}${stat('Sin registrar', list.filter(r => r.estado === 'sin_registrar').length)}</div>${legend}<div class="ag-scroll"><table class="ag-table ag-calendar"><thead><tr><th>Comité</th>${data.meses.map((x, i) => `<th class="${`${state.year}-${String(i + 1).padStart(2, '0')}` === data.hoy.slice(0, 7) ? 'ag-current' : ''}">${esc(x.slice(0, 3))}</th>`).join('')}<th>Cumpl.</th></tr></thead><tbody>${data.comites.filter(c => (state.comite === 'todos' || c.id === state.comite) && (num(c.activo) || list.some(r => r.comite_id === c.id))).map(c => { const own = list.filter(r => r.comite_id === c.id), cm = metrics(own); return `<tr><td><div class="ag-comite">${c.logo && /^(data:image\/(?:png|jpeg|webp|svg\+xml);base64,|\.\.\/uploads\/|assets\/)/.test(c.logo) ? `<img src="${esc(c.logo)}" alt="">` : ''}${esc(c.nombre)}</div></td>${data.meses.map((_, i) => { const mon = `${state.year}-${String(i + 1).padStart(2, '0')}`, rs = own.filter(r => month(r) === mon); return `<td>${rs.map(item).join('')}${c.gestionar && num(c.activo) ? `<button class="ag-empty-cell" data-ag="planificar" data-comite="${esc(c.id)}" data-mes="${mon}" aria-label="Planificar ${esc(c.nombre)} en ${esc(data.meses[i])}">+</button>` : !rs.length ? '—' : ''}</td>`; }).join('')}<td>${cm.pct === null ? '—' : cm.pct + '%'}</td></tr>`; }).join('')}</tbody></table></div><p class="ag-note">Selecciona una reunión para ver su detalle o + para planificar. Las reuniones extraordinarias conservan su fecha de ejecución.</p>`;
    const responses = list.flatMap(r => r.encuesta), pending = list.reduce((n, r) => n + r.asistentes.filter(a => a.estado === 'nuevo').length, 0);
    return `<div class="ag-stats">${stat('Eventos planificados', list.length, `${list.filter(r => num(r.fijo)).length} fijos · ${list.filter(r => num(r.sobre_la_marcha)).length} sobre la marcha`)}${stat('Cumplimiento', m.pct === null ? '—' : m.pct + '%', `${list.filter(done).length} realizados`)}${stat('Participantes', list.filter(done).reduce((n, r) => n + num(r.participantes), 0))}${stat('Calificación promedio', responses.length ? (responses.reduce((n, r) => n + num(r.calificacion), 0) / responses.length).toFixed(1) + ' / 5' : '—', `${list.filter(r => r.informe_ruta).length} informes disponibles`)}</div>${pending ? `<div class="ag-alert">${pending} asistentes nuevos pendientes de integrar al directorio. Revisa la pestaña Asistentes de cada evento.</div>` : ''}<div class="ag-row">${data.gestionar ? button('fijos', 'Agregar eventos fijos del año') : ''}${legend}</div><div class="ag-scroll ag-panel"><div class="ag-events">${data.meses.map((mon, i) => { const key = `${state.year}-${String(i + 1).padStart(2, '0')}`; return `<section class="ag-month"><h3>${esc(mon.slice(0, 3))}</h3>${list.filter(r => month(r) === key).map(item).join('')}${data.gestionar ? button('nuevo-evento', '+', '', 'small', `data-mes="${key}" aria-label="Nuevo evento en ${esc(mon)}"`) : ''}</section>`; }).join('')}</div></div>`;
  }
  function renderExecution() {
    let list = rows(); if (state.status !== 'todos') list = list.filter(r => r.estado === state.status);
    return `<div class="ag-row"><select id="ag-status" class="ag-input" aria-label="Estado">${options([['todos', 'Todos los estados'], ...Object.entries(data.estados)], state.status)}</select>${!eventos && (data.gestionar || data.comites.some(c => c.gestionar)) ? button('extraordinaria', '+ Reunión extraordinaria') : ''}<span class="ag-note">${list.length} registros</span></div><div class="ag-scroll"><table class="ag-table"><thead><tr><th>${eventos ? 'Evento' : 'Comité'}</th><th>Planificación</th><th>Fecha real</th><th>Asistentes</th>${eventos ? '<th>Evaluación</th>' : '<th>Temas / próximos pasos</th>'}<th>Documento</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>${list.map(r => `<tr><td><strong>${esc(name(r))}</strong>${eventos ? `<span class="ag-sub">${esc(r.lugar)} · ${esc(r.modalidad)}</span>` : ''}</td><td>${esc(r.plan_fecha || r.plan_mes || 'Extraordinaria')}</td><td>${done(r) ? esc(r.fecha) : '—'}${eventos && r.gestionar && done(r) ? `<input type="date" class="ag-inline-date" data-date="${r.id}" value="${esc(r.fecha)}" max="${data.hoy}" aria-label="Fecha real de ${esc(name(r))}">` : ''}</td><td>${eventos ? num(r.participantes) : r.asistentes.length}${eventos ? `<span class="ag-sub">${num(r.ingenios)} ingenios</span>` : ''}</td><td>${eventos ? (r.encuesta.length ? (r.encuesta.reduce((s, a) => s + num(a.calificacion), 0) / r.encuesta.length).toFixed(1) + ' ★' : '—') : `${esc(r.temas || r.plan_temas || '—')}<span class="ag-sub">${r.acuerdos.length} próximos pasos</span>`}</td><td>${documentLink(r)}</td><td>${badge(r)}</td><td>${button('detalle', 'Ver', r.id, 'small')}${r.gestionar && !canceled(r) ? button('ejecutar', done(r) ? 'Editar ejecución' : 'Registrar', r.id, 'small') : ''}</td></tr>`).join('') || '<tr><td colspan="8">No hay registros para estos filtros.</td></tr>'}</tbody></table></div>`;
  }
  function renderAnalysis() {
    const list = rows(), m = metrics(list), plannedDone = m.planned.filter(done), extra = list.filter(r => !r.plan_mes), maximum = Math.max(1, ...data.meses.map((_, i) => Math.max(list.filter(r => r.plan_mes?.slice(5) === String(i + 1).padStart(2, '0')).length, list.filter(r => done(r) && r.fecha?.slice(0, 7) === `${state.year}-${String(i + 1).padStart(2, '0')}`).length)));
    return `<div class="ag-stats">${stat('Planificadas', m.planned.length)}${stat('Cumplimiento exigible', m.pct === null ? '—' : m.pct + '%', `${m.completed.length} de ${m.due.length}`)}${stat('En el mes previsto', plannedDone.length ? Math.round(m.same.length / plannedDone.length * 100) + '%' : '—')}${stat('Reprogramadas', list.filter(r => r.historial.length).length)}${stat('Canceladas', list.filter(canceled).length)}${stat('Sin registrar / extraordinarias', list.filter(r => r.estado === 'sin_registrar').length + ' / ' + extra.length)}</div><section class="ag-panel"><h3>Planificado vs. ejecutado por mes</h3><p class="ag-note">Azul: planificadas · Verde: ejecutadas (incluye extraordinarias)</p><div class="ag-bars">${data.meses.map((mon, i) => { const key = `${state.year}-${String(i + 1).padStart(2, '0')}`, p = list.filter(r => r.plan_mes === key).length, d = list.filter(r => done(r) && r.fecha?.slice(0, 7) === key).length; return `<div class="ag-bar-column"><div class="ag-bar-pair" title="${esc(mon)}: ${p} planificadas, ${d} ejecutadas"><span style="height:${p / maximum * 100}%"></span><span style="height:${d / maximum * 100}%"></span></div>${esc(mon.slice(0, 3))}<br>${p} / ${d}</div>`; }).join('')}</div></section><div class="ag-scroll"><table class="ag-table"><thead><tr>${['Comité', 'Plan.', 'Exigibles', 'Realizadas', 'En mes', 'Canceladas', 'Sin registrar', 'Extraord.', 'Desviación media', 'Cumplimiento'].map(x => `<th>${x}</th>`).join('')}</tr></thead><tbody>${data.comites.filter(c => state.comite === 'todos' || c.id === state.comite).map(c => { const rs = list.filter(r => r.comite_id === c.id), cm = metrics(rs), dated = rs.filter(r => done(r) && r.plan_fecha), deviation = dated.length ? Math.round(dated.reduce((s, r) => s + Math.abs(Date.parse(r.fecha) - Date.parse(r.plan_fecha)) / 86400000, 0) / dated.length) + ' días' : '—'; return `<tr><td>${esc(c.nombre)}</td><td>${cm.planned.length}</td><td>${cm.due.length}</td><td>${rs.filter(done).length}</td><td>${cm.same.length}</td><td>${rs.filter(canceled).length}</td><td>${rs.filter(r => r.estado === 'sin_registrar').length}</td><td>${rs.filter(r => !r.plan_mes).length}</td><td>${deviation}</td><td>${cm.pct === null ? '—' : cm.pct + '%'}</td></tr>`; }).join('')}</tbody></table></div><p class="ag-note">Exigibles: fecha tentativa alcanzada o mes completo transcurrido; excluye canceladas. La desviación compara fechas tentativas y reales disponibles.</p>`;
  }
  function planForm(cid = '', mon = `${state.year}-01`) {
    modal('Planificar reunión', form('planificar', select('Comité', 'comite_id', committeeOptions(true), cid || (state.comite === 'todos' ? '' : state.comite)) + field('Mes', 'plan_mes', mon, 'month', 'required') + field('Fecha tentativa (opcional)', 'plan_fecha', '', 'date') + select('Repetir hasta diciembre', 'repetir', [[0, 'No repetir'], [1, 'Mensual'], [2, 'Bimestral'], [3, 'Trimestral'], [6, 'Semestral']], 0) + area('Temas a revisar', 'plan_temas') + field('Responsable', 'responsable', '', 'text', 'maxlength="180"') + check('Permitir otra reunión en el mismo mes', 'permitir_duplicados')));
  }
  function eventForm(r = {}, mon = `${state.year}-01`) {
    modal(r.id ? 'Editar evento' : 'Nuevo evento de transferencia', form('guardar_evento', field('Nombre del evento', 'nombre', r.nombre, 'text', `required maxlength="220" ${num(r.fijo) ? 'readonly' : ''}`) + field('Mes planificado', 'plan_mes', r.plan_mes || mon, 'month', 'required') + field('Fecha tentativa (opcional)', 'plan_fecha', r.plan_fecha, 'date') + field('Lugar', 'lugar', r.lugar || 'Auditorio CENGICAÑA', 'text', 'maxlength="255"') + select('Modalidad', 'modalidad', ['Presencial', 'Virtual', 'Híbrido'].map(x => [x, x]), r.modalidad || 'Presencial') + area('Público objetivo', 'publico', r.publico || 'Gerentes agrícolas y personal técnico de ingenios') + area('Objetivo', 'objetivo', r.objetivo) + (!num(r.fijo) ? check('Evento sobre la marcha (no planificado originalmente)', 'sobre_la_marcha', num(r.sobre_la_marcha)) : ''), r.id || 0));
  }
  function step(p = {}) { return `<section class="ag-step" data-step><input type="hidden" name="id" value="${num(p.id)}"><header><b>Próximo paso</b>${button('quitar-fila', 'Quitar', '', 'small danger')}</header><div class="ag-fields">${area('Acción / acuerdo', 'acuerdo', p.acuerdo)}${field('Responsable', 'responsable', p.responsable)}${field('Plazo', 'plazo', p.plazo, 'date')}${field('Seguimiento', 'fecha_seguimiento', p.fecha_seguimiento, 'date')}${field('Avance (%)', 'avance', num(p.avance), 'number', 'min="0" max="100"')}${select('Estado', 'estado', ['Pendiente', 'En proceso', 'Completado', 'Cancelado'].map(x => [x, x]), p.estado || 'Pendiente')}${area('Observaciones', 'observaciones', p.observaciones)}</div></section>`; }
  function executionForm(r = {}) {
    if (eventos) { modal('Registrar ejecución del evento', form('ejecutar_evento', `<div class="ag-wide"><strong>${esc(r.nombre)}</strong></div>` + field('Fecha real', 'fecha', done(r) ? r.fecha : data.hoy, 'date', `required max="${data.hoy}"`) + field('Participantes', 'participantes', num(r.participantes), 'number', 'min="0" required') + field('Ingenios representados', 'ingenios', num(r.ingenios), 'number', 'min="0" required') + field('Informe (opcional, hasta 20 MB)', 'documento', '', 'file', 'accept=".pdf,.doc,.docx,.xls,.xlsx"') + (r.informe_ruta ? `<div class="ag-wide">${documentLink(r)}</div>` + check('Quitar documento actual', 'quitar_documento') : '') + area('Notas de ejecución', 'notas', r.notas), r.id)); return; }
    const ids = r.asistentes || [];
    modal(r.id ? 'Registrar / editar ejecución' : 'Reunión extraordinaria', form('guardar_reunion', select('Comité', 'comite_id', committeeOptions(true), r.comite_id || (state.comite === 'todos' ? '' : state.comite)) + field('Fecha de la reunión', 'fecha', done(r) ? r.fecha : data.hoy, 'date', `required max="${data.hoy}"`) + check('Reunión realizada', 'realizada', r.id ? done(r) || !canceled(r) : true) + field('Responsable', 'responsable', r.responsable, 'text', 'maxlength="180"') + area('Temas tratados', 'temas', r.temas || r.plan_temas) + `<div class="ag-wide"><h3>Asistentes</h3><input id="ag-find-contact" class="ag-input" placeholder="Buscar por nombre, correo o empresa" aria-label="Buscar asistentes"><div class="ag-checks" id="ag-contact-list">${data.contactos.filter(c => c.estado === 'Activo' || ids.includes(num(c.id))).map(c => `<label data-contact="${esc(`${c.nombre} ${c.email} ${c.empresa}`.toLowerCase())}"><input type="checkbox" name="asistentes" value="${num(c.id)}" ${ids.includes(num(c.id)) ? 'checked' : ''}> <span>${esc(c.nombre)}<small class="ag-sub">${esc(c.empresa)} · ${esc(c.email)}</small></span></label>`).join('')}</div><div id="ag-new-contacts"></div>${data.gestionar_contactos ? button('nuevo-asistente', '+ Nuevo contacto', '', 'small') : ''}</div>` + field('Ayuda memoria (opcional, hasta 20 MB)', 'documento', '', 'file', 'accept=".pdf,.doc,.docx,.xls,.xlsx"') + (r.ayuda_memoria_ruta ? `<div class="ag-wide">${documentLink(r)}</div>` + check('Quitar documento actual', 'quitar_documento') : '') + `<div class="ag-wide"><a class="ag-btn small" href="index.php?view=reuniones&accion=plantilla">Descargar plantilla de ayuda memoria</a><h3>Próximos pasos</h3><div id="ag-steps">${(r.acuerdos || []).map(step).join('')}</div>${button('paso', '+ Agregar próximo paso', '', 'small')}</div>`, r.id || 0), true);
  }
  function history(r) { return `${r.historial.length ? `<div class="ag-panel"><h3>Historial de reprogramaciones</h3>${r.historial.map(h => `<p class="ag-history">${esc(h.de)} → ${esc(h.a)} · ${esc(h.motivo)}<span class="ag-sub">${esc(h.por)} · ${esc(h.fecha)}</span></p>`).join('')}</div>` : ''}${canceled(r) ? `<p class="ag-alert">Cancelada: ${esc(r.motivo_cancelacion)}</p>` : ''}`; }
  function detail(id, tab = 'resumen') {
    const r = data.registros.find(x => x.id === num(id)); if (!r) return;
    state.detail = r.id; state.detailTab = tab;
    const actions = r.gestionar ? `<div class="ag-form-actions">${!canceled(r) ? (eventos ? button('editar-evento', 'Editar evento', r.id) : !done(r) ? button('editar-plan', 'Editar planificación', r.id) : '') + button('ejecutar', done(r) ? 'Editar ejecución' : 'Registrar ejecución', r.id, 'primary') + (!done(r) ? button('reprogramar', 'Reprogramar', r.id) + button('cancelar', 'Cancelar', r.id, 'danger') : '') : ''}${data.gestionar && !(eventos && num(r.fijo)) ? button('eliminar', 'Eliminar', r.id, 'danger') : ''}</div>` : '';
    const tabs = eventos ? `<div class="ag-row">${['resumen', 'asistentes', 'evaluacion'].map(t => button('detalle-tab', { resumen: 'Resumen', asistentes: `Asistentes (${r.asistentes.length})`, evaluacion: `Evaluación (${r.encuesta.length})` }[t], t, tab === t ? 'primary' : '')).join('')}</div>` : '';
    let content;
    if (eventos && tab === 'asistentes') content = attendeeView(r);
    else if (eventos && tab === 'evaluacion') content = surveyView(r);
    else content = `<div class="ag-row">${badge(r)}<span>${esc(r.responsable)}</span></div><div class="ag-detail-grid"><section class="ag-panel"><h3>Planificación</h3><strong>${esc(r.plan_fecha || r.plan_mes || 'Extraordinaria')}</strong><p class="ag-textarea">${esc(eventos ? r.objetivo : r.plan_temas || 'Sin temas registrados')}</p>${eventos ? `<p>${esc(r.lugar)} · ${esc(r.modalidad)}</p><p>${esc(r.publico)}</p>` : ''}</section><section class="ag-panel"><h3>Ejecución</h3><strong>${done(r) ? esc(r.fecha) : 'Pendiente de registrar'}</strong><p class="ag-textarea">${esc(eventos ? r.notas : r.temas)}</p><p>${eventos ? `${num(r.participantes)} participantes · ${num(r.ingenios)} ingenios` : `${r.asistentes.length} asistentes`}</p>${documentLink(r)}</section></div>${!eventos ? `<section class="ag-panel"><h3>Asistentes</h3><p>${r.asistentes.map(id => esc(data.contactos.find(c => num(c.id) === id)?.nombre || '')).join(' · ') || 'Sin asistentes registrados'}</p><h3>Próximos pasos</h3>${r.acuerdos.map(p => `<p><strong>${esc(p.acuerdo)}</strong><span class="ag-sub">${esc(p.responsable)} · Plazo: ${esc(p.plazo || 'Sin definir')} · ${esc(p.estado)} · ${num(p.avance)}%</span>${esc(p.observaciones)}</p>`).join('') || '<p>Sin próximos pasos.</p>'}</section>` : ''}${history(r)}${actions}`;
    modal(name(r), tabs + content, true);
  }
  // Asistentes: lista compacta (una línea por persona) con filtros por resultado en el directorio,
  // búsqueda y acciones en lote; la edición se abre por persona solo cuando hace falta.
  const ATT_GROUPS = [['todos', 'Todos'], ['nuevo', 'Nuevos'], ['posible', 'Coincidencias'], ['directorio', 'En directorio'], ['incompleto', 'Datos incompletos'], ['descartado', 'Descartados']];
  const ATT_FIELDS = [['email', 'correo'], ['telefono', 'teléfono'], ['cargo', 'cargo'], ['empresa', 'institución']];
  const attState = id => { if (state.att?.evento !== id) state.att = { evento: id, filtro: 'todos', abierto: 0, crear: {} }; return state.att; };
  const attLinked = a => a.estado === 'existente' || a.estado === 'integrado';
  const attMissing = a => a.estado === 'nuevo' ? ATT_FIELDS.filter(([k]) => !String(a[k] ?? '').trim()).map(([, label]) => label) : [];
  const attGroup = (a, g) => g === 'todos' || (g === 'nuevo' ? a.estado === 'nuevo' && !a.coincidencia : g === 'posible' ? a.estado === 'nuevo' && !!a.coincidencia : g === 'directorio' ? attLinked(a)
    : g === 'incompleto' ? attMissing(a).length > 0 || !!a.contacto?.faltan.length : a.estado === 'descartado');
  function attendeeView(r) {
    const editable = r.gestionar && !canceled(r), canEdit = editable && data.gestionar_contactos, st = attState(r.id);
    const cursos = r.cursos ? `<div class="ag-alert ag-cursos"><span><strong>Vinculado con el Control QR de Cengicursos:</strong> ${esc(r.cursos.nombre)}${r.cursos.fecha ? ' · ' + esc(r.cursos.fecha) : ''} · ${num(r.cursos.ingresos)} con ingreso registrado. Los asistentes se actualizan al abrir esta página.</span>${editable ? button('cursos-sincronizar', '↻ Actualizar ahora', r.id) : ''}</div>` : '';
    const tools = editable ? `<details class="ag-att-tools"><summary>Cargar asistentes por archivo o enlace CSV</summary><div class="ag-row">${button('cargar-asistentes', '↑ Cargar asistentes', r.id)}<a class="ag-btn" href="${endpoint}&plantilla=asistentes">Plantilla CSV</a></div><form data-form="sincronizar" data-id="${r.id}"><label class="ag-label">Enlace público CSV (hoja publicada)<div class="ag-sync"><input class="ag-input" type="url" name="sync_url" value="${esc(r.sync_url)}" placeholder="https://…" required><button class="ag-btn" type="submit">Sincronizar</button></div></label><p class="ag-note">El enlace debe permitir su lectura desde el navegador. También puedes descargar la hoja y cargar el archivo.</p></form></details>` : '';
    const source = `<p class="ag-note">${esc(r.fuente_asistentes || 'Sin importaciones')} ${r.sincronizado ? '· ' + esc(r.sincronizado) : ''}</p>`;
    if (!r.asistentes.length) return cursos + tools + source + empty('Sin asistentes cargados.');
    const tabs = `<div class="ag-att-tabs" role="tablist">${ATT_GROUPS.map(([g, label]) => { const n = r.asistentes.filter(a => attGroup(a, g)).length; return n || g === 'todos' || g === st.filtro ? `<button type="button" role="tab" aria-selected="${g === st.filtro}" class="ag-att-tab ${g}${g === st.filtro ? ' on' : ''}" data-ag="att-filtro" data-id="${r.id}" data-filtro="${g}">${label} <b>${n}</b></button>` : ''; }).join('')}</div>`;
    const bulk = canEdit ? `<div class="ag-att-bulk"><input type="search" id="ag-att-q" class="ag-input" placeholder="Buscar nombre, correo o institución" aria-label="Buscar asistente"><span id="ag-att-sel" class="ag-muted">0 seleccionados</span><select id="ag-att-comite" class="ag-input" aria-label="Comité para los integrados">${options([['', 'Sin comité'], ...committeeOptions()], '')}</select>${button('att-integrar', 'Integrar', r.id, 'primary')}${button('att-completar', 'Completar contactos', r.id)}${button('att-descartar', 'Descartar', r.id, 'danger')}</div>`
      : '<div class="ag-att-bulk"><input type="search" id="ag-att-q" class="ag-input" placeholder="Buscar nombre, correo o institución" aria-label="Buscar asistente"></div>';
    const list = r.asistentes.filter(a => attGroup(a, st.filtro));
    return `${cursos}${tools}${source}${tabs}${bulk}<div class="ag-scroll ag-att-scroll"><table class="ag-table ag-att"><thead><tr><th>${canEdit ? '<input type="checkbox" id="ag-att-all" aria-label="Seleccionar todos los visibles">' : ''}</th><th>Nombre</th><th>Institución</th><th>Directorio</th><th>Faltan</th><th></th></tr></thead><tbody>${list.map(a => attRow(r, a, canEdit, st)).join('') || `<tr><td colspan="6" class="ag-muted">Sin asistentes en este filtro.</td></tr>`}</tbody></table></div>${canEdit ? '<p class="ag-note">Integrar: los nuevos se crean en el directorio y las coincidencias se vinculan al contacto encontrado (completando sus datos vacíos). Completar contactos: agrega a los ya registrados los datos que les falten; nunca se sobrescribe nada.</p>' : ''}`;
  }
  function attRow(r, a, canEdit, st) {
    const c = a.coincidencia, crear = !!st.crear[a.id], missing = attMissing(a), faltan = a.contacto?.faltan || [];
    const selectable = canEdit && (a.estado === 'nuevo' || faltan.length);
    const dir = a.estado === 'nuevo' ? (c && !crear ? `<span class="ag-dir posible" title="${esc(c.motivo)}: ${esc([c.nombre, c.email, c.empresa].filter(Boolean).join(' · '))}">≈ ${esc(c.nombre)}</span>` : `<span class="ag-dir nuevo">Nuevo${c ? ' (otra persona)' : ''}</span>`)
      : attLinked(a) ? `<span class="ag-dir integrado" title="${esc(a.contacto ? [a.contacto.nombre, a.contacto.email].filter(Boolean).join(' · ') : '')}${a.contacto?.otro_correo ? ' · en el evento usó otro correo' : ''}">${a.estado === 'integrado' ? 'Integrado' : 'En directorio'}${a.contacto?.otro_correo ? ' ✉' : ''}</span>` : '<span class="ag-dir descartado">Descartado</span>';
    const gaps = faltan.length ? `<span class="ag-dir posible" title="${esc(faltan.map(f => `${f.campo}: ${f.valor}`).join(' · '))}">Completar: ${esc(faltan.map(f => f.campo).join(', '))}</span>` : missing.length ? `<span class="ag-warn">${esc(missing.join(', '))}</span>` : '<span class="ag-good">✓</span>';
    const q = esc([a.nombre, a.email, a.empresa, a.telefono].join(' ').toLowerCase());
    const row = `<tr data-q="${q}"${a.id === st.abierto ? ' class="open"' : ''}><td>${selectable ? `<input type="checkbox" data-att="${a.id}" data-tipo="${a.estado === 'nuevo' ? 'pendiente' : 'completar'}" aria-label="Seleccionar ${esc(a.nombre)}">` : ''}</td><td><b>${esc(a.nombre)}</b><span class="ag-sub">${[a.email, a.telefono].filter(Boolean).map(esc).join(' · ') || '—'}</span></td><td>${esc(a.empresa) || '<span class="ag-muted">—</span>'}</td><td>${dir}</td><td>${gaps}</td><td>${canEdit && a.estado === 'nuevo' ? `<button type="button" class="ag-icon" data-ag="att-editar" data-id="${r.id}" data-att-id="${a.id}" aria-label="Editar datos de ${esc(a.nombre)}" title="Editar">✎</button>` : ''}</td></tr>`;
    if (a.id !== st.abierto) return row;
    const input = (k, label, type = 'text') => `<label>${label}<input class="ag-input" name="${k}" type="${type}" value="${esc(a[k])}"${k === 'empresa' ? ' list="ag-empresas"' : ''}></label>`;
    const companies = [...new Set(data.contactos.map(x => x.empresa).filter(Boolean))];
    return row + `<tr class="ag-att-edit" data-edit="${a.id}"><td></td><td colspan="5"><div class="ag-att-fields">${input('nombre', 'Nombre')}${input('email', 'Correo', 'email')}${input('telefono', 'Teléfono', 'tel')}${input('cargo', 'Cargo')}${input('empresa', 'Institución')}</div><datalist id="ag-empresas">${companies.map(x => `<option value="${esc(x)}">`).join('')}</datalist>${c ? `<label class="ag-note"><input type="checkbox" name="crear" ${crear ? 'checked' : ''}> No es ${esc(c.nombre)} (${esc(c.email || c.empresa || 'directorio')}): crear contacto nuevo</label>` : ''}<div class="ag-row">${button('att-guardar', 'Guardar datos', r.id, 'primary small', `data-att-id="${a.id}"`)}${button('att-cerrar', 'Cancelar', r.id, 'small')}</div></td></tr>`;
  }
  function surveyView(r) {
    const n = r.encuesta.length, avg = n ? (r.encuesta.reduce((s, a) => s + num(a.calificacion), 0) / n).toFixed(1) : '—';
    const editable = r.gestionar && !canceled(r), importadas = r.encuesta.filter(a => !a.origen_id).length;
    const sync = r.cursos ? `<div class="ag-alert ag-cursos"><span>Respuestas de la encuesta de Cengicursos (${esc(r.cursos.nombre)}) con los datos de contacto de quien responde. Se actualizan al abrir esta página.</span>${editable ? button('cursos-sincronizar', '↻ Actualizar ahora', r.id) : ''}</div>` : '';
    const report = `<div class="ag-row ag-report-row"><span class="ag-note">Informe de evaluación del evento</span>${button('informe', 'Ver informe', r.id, '', 'data-formato="html"')}${button('informe', '↓ Descargar PDF', r.id, 'primary', 'data-formato="pdf"')}</div>`;
    return `${sync}${report}${editable ? `<div class="ag-row">${button('cargar-encuesta', '↑ Importar evaluación', r.id)}<a class="ag-btn" href="${endpoint}&plantilla=encuesta">Plantilla CSV</a>${importadas ? button('encuesta-quitar', 'Limpiar respuestas importadas', r.id, 'danger') : ''}</div>` : ''}<div class="ag-survey"><div class="ag-panel"><div class="ag-survey-score">${avg}</div><p class="ag-note">Promedio / 5 · ${n} respuestas</p></div><div>${[5, 4, 3, 2, 1].map(v => { const count = r.encuesta.filter(a => num(a.calificacion) === v).length; return `<div class="ag-rating"><span>${v} ★</span><div class="ag-progress"><span style="width:${n ? count / n * 100 : 0}%"></span></div><span>${count}</span></div>`; }).join('')}</div></div>${comments(r, editable)}`;
  }
  // Comentarios: tabla compacta (una línea por comentario) con filtros, clasificación automática o
  // manual, seguimiento con nota de retroalimentación y acciones en lote.
  const COM_GROUPS = [['todos', 'Todos'], ['atencion', 'Requieren atención'], ['pendiente', 'Seguimiento pendiente'], ['atendido', 'Atendidos'], ['sin', 'Sin clasificar'], ['auto', 'Clasificación automática']];
  const COM_STARS = [['todas', 'Todas las calificaciones'], ['bajas', '1-2 ★'], ['medias', '3 ★'], ['altas', '4-5 ★']];
  const comState = id => { if (state.com?.evento !== id) state.com = { evento: id, filtro: 'todos', estrellas: 'todas', abierto: 0 }; return state.com; };
  const comNeeds = a => (data.requieren_seguimiento || []).includes(a.categoria);
  const comFollow = a => a.seguimiento_estado || (comNeeds(a) ? 'pendiente' : '');
  const comGroup = (a, g) => g === 'todos' ? true : g === 'atencion' ? comNeeds(a) : g === 'pendiente' ? ['pendiente', 'en_proceso'].includes(comFollow(a))
    : g === 'atendido' ? comFollow(a) === 'atendido' : g === 'sin' ? !a.categoria : g === 'auto' ? a.categoria_por === 'Automático' : a.categoria === g;
  const comStars = (a, f) => f === 'todas' || (f === 'bajas' ? num(a.calificacion) <= 2 : f === 'medias' ? num(a.calificacion) === 3 : num(a.calificacion) >= 4);
  function comments(r, editable) {
    const st = comState(r.id), cats = Object.entries(data.categorias || {}), conTexto = a => String(a.comentario || '').trim();
    const visibles = r.encuesta.filter(conTexto), ocultos = (r.encuesta_ocultos || []).filter(conTexto), enOcultos = st.filtro === 'ocultos';
    const list = enOcultos ? ocultos : visibles;
    if (!visibles.length && !ocultos.length) return `<h3 class="ag-com-title">Comentarios</h3>${empty('Sin comentarios.')}`;
    const tabs = [...COM_GROUPS, ...cats, ['ocultos', 'Ocultos']].map(([g, label]) => { const n = g === 'ocultos' ? ocultos.length : visibles.filter(a => comGroup(a, g)).length; return n || g === 'todos' || g === st.filtro ? `<button type="button" class="ag-att-tab ${g}${g === st.filtro ? ' on' : ''}" aria-pressed="${g === st.filtro}" data-ag="com-filtro" data-id="${r.id}" data-filtro="${g}">${esc(label)} <b>${n}</b></button>` : ''; }).join('');
    const tools = `<div class="ag-att-bulk"><input type="search" id="ag-com-q" class="ag-input" placeholder="Buscar en comentarios, nombre o institución" aria-label="Buscar comentario"><select id="ag-com-stars" class="ag-input" data-id="${r.id}" aria-label="Filtrar por calificación">${options(COM_STARS, st.estrellas)}</select>${editable ? '<span class="ag-muted">Marca comentarios para clasificarlos o gestionarlos en grupo.</span>' : ''}</div>`;
    const bulk = editable ? `<div class="ag-com-bulk" id="ag-com-bulk" role="region" aria-label="Acciones para los comentarios seleccionados" hidden><b id="ag-com-sel">0 seleccionados</b>${enOcultos ? button('com-ocultar', '↺ Restaurar', r.id, 'primary', 'data-ocultar="0"') : `<select id="ag-com-cat" class="ag-input" data-id="${r.id}" aria-label="Clasificar los seleccionados como">${options([['', 'Clasificar como…'], ...cats], '')}</select>${button('com-estado', 'En proceso', r.id, '', 'data-estado="en_proceso"')}${button('com-estado', '✓ Atendido', r.id, '', 'data-estado="atendido"')}${button('com-ocultar', 'Ocultar', r.id, '', 'data-ocultar="1" title="No cuenta en el promedio ni en el informe; se puede restaurar"')}`}${button('com-eliminar', 'Eliminar', r.id, 'danger', 'title="Solo comentarios cargados por archivo CSV"')}<button type="button" class="ag-btn small" data-ag="com-limpiar" data-id="${r.id}">Quitar selección</button></div>` : '';
    const shown = list.filter(a => (enOcultos || comGroup(a, st.filtro)) && comStars(a, st.estrellas));
    const auto = !enOcultos && visibles.filter(a => !a.categoria).length && editable ? button('com-auto', 'Clasificar automáticamente', r.id, 'small') : '';
    return `<div class="ag-row ag-com-head"><h3 class="ag-com-title">Comentarios</h3>${auto}</div><div class="ag-att-tabs">${tabs}</div>${tools}${bulk}${enOcultos ? '<p class="ag-note">Los comentarios ocultos no cuentan en el promedio, los conteos ni el informe. Selecciónalos para restaurarlos.</p>' : ''}<div class="ag-scroll ag-att-scroll"><table class="ag-table ag-att ag-com"><thead><tr><th>${editable ? '<input type="checkbox" id="ag-com-all" aria-label="Seleccionar todos los visibles">' : ''}</th><th>★</th><th>Comentario</th><th>Participante</th><th>Contacto</th><th>Clasificación</th><th>Seguimiento</th><th></th></tr></thead><tbody>${shown.map(a => commentRow(r, a, editable && !enOcultos, st, editable)).join('') || '<tr><td colspan="8" class="ag-muted">Sin comentarios en este filtro.</td></tr>'}</tbody></table></div>`;
  }
  function commentRow(r, a, editable, st, selectable = editable) {
    const cal = num(a.calificacion), follow = comFollow(a), isAuto = a.categoria_por === 'Automático';
    const stars = `<span class="ag-stars ${cal <= 2 ? 'low' : cal === 3 ? 'mid' : 'high'}">${cal}★</span>`;
    const contact = [a.email ? `<a class="ag-icon" href="mailto:${esc(a.email)}" title="${esc(a.email)}" aria-label="Escribir a ${esc(a.email)}">✉</a>` : '', a.telefono ? `<a class="ag-icon" href="tel:${esc(String(a.telefono).replace(/[^\d+]/g, ''))}" title="${esc(a.telefono)}" aria-label="Llamar al ${esc(a.telefono)}">☎</a>` : ''].join('') || '<span class="ag-muted">—</span>';
    const cat = editable ? `<select class="ag-input ag-com-select${a.categoria ? ' cat-' + esc(a.categoria) : ''}" data-categoria="${a.id}" data-id="${r.id}" aria-label="Clasificación del comentario de ${esc(a.nombre || 'Anónimo')}">${options([['', 'Sin clasificar'], ...Object.entries(data.categorias || {})], a.categoria || '')}</select>${isAuto ? `<button type="button" class="ag-auto" data-ag="com-confirmar" data-id="${r.id}" data-respuesta="${a.id}" title="Propuesta automática: pulsa para confirmarla">auto ✓</button>` : ''}`
      : `<span class="ag-dir">${esc(data.categorias?.[a.categoria] || 'Sin clasificar')}</span>`;
    const seg = follow ? `<span class="ag-seg ${follow}">${esc(data.seguimientos?.[follow] || follow)}</span>${a.seguimiento_nota ? `<span class="ag-sub" title="${esc(a.seguimiento_nota)}">${esc(a.seguimiento_nota)}</span>` : ''}` : '<span class="ag-muted">—</span>';
    const q = esc([a.comentario, a.nombre, a.institucion, a.email].join(' ').toLowerCase());
    const row = `<tr data-q="${q}"${a.id === st.abierto ? ' class="open"' : ''}><td>${selectable ? `<input type="checkbox" data-com="${a.id}"${a.origen_id ? ' data-sincronizado="1"' : ''} aria-label="Seleccionar comentario de ${esc(a.nombre || 'Anónimo')}">` : ''}</td><td>${stars}</td><td><div class="ag-com-text" title="${esc(a.comentario)}">${esc(a.comentario)}</div>${a.detalle ? `<span class="ag-sub">${esc(a.detalle)}</span>` : ''}</td><td><b>${esc(a.nombre || 'Anónimo')}</b><span class="ag-sub">${esc(a.institucion || '')}</span></td><td class="ag-com-contact">${contact}</td><td>${cat}</td><td>${seg}</td><td>${editable ? `<button type="button" class="ag-icon" data-ag="com-editar" data-id="${r.id}" data-respuesta="${a.id}" title="Anotar seguimiento" aria-label="Anotar seguimiento de ${esc(a.nombre || 'Anónimo')}">✎</button>` : ''}</td></tr>`;
    if (a.id !== st.abierto) return row;
    return row + `<tr class="ag-att-edit" data-com-edit="${a.id}"><td></td><td colspan="7"><p class="ag-com-full">“${esc(a.comentario)}”</p><div class="ag-com-follow"><label>Estado<select class="ag-input" name="estado">${options(Object.entries(data.seguimientos || {}), follow || 'pendiente')}</select></label><label class="ag-wide">Seguimiento o retroalimentación<textarea class="ag-input" name="nota" rows="2" maxlength="2000" placeholder="Ej. Se le envió la presentación por correo el 05/11.">${esc(a.seguimiento_nota || '')}</textarea></label></div>${a.seguimiento_por ? `<p class="ag-note">Última actualización: ${esc(a.seguimiento_por)} · ${esc(a.seguimiento_en)}</p>` : ''}<div class="ag-row">${button('com-guardar', 'Guardar seguimiento', r.id, 'primary small', `data-respuesta="${a.id}"`)}${button('com-cerrar', 'Cancelar', r.id, 'small')}</div></td></tr>`;
  }
  function importForm(action, id = 0) {
    const kind = action === 'importar_plan' ? 'plan' : action === 'asistentes_cargar' ? 'asistentes' : 'encuesta';
    modal({ plan: 'Cargar planificación anual', asistentes: 'Importar asistentes', encuesta: 'Importar evaluaciones' }[kind], form(action, `<p class="ag-wide ag-note">CSV, XLSX o XLS, hasta 5 MB y 2000 filas. Usa los encabezados de la plantilla.</p><div class="ag-wide"><a class="ag-btn" href="${endpoint}&plantilla=${kind}&anio=${state.year}">Descargar plantilla</a></div>` + field('Archivo', 'lista', '', 'file', 'accept=".csv,.xlsx,.xls" required') + (kind === 'plan' ? field('Año', 'anio', state.year, 'number', 'min="1900" max="2199" required') + check('Permitir otra reunión en el mismo mes', 'permitir_duplicados') : ''), id, 'Cargar'));
  }
  let shareLogo = '';
  function shareForm() {
    shareLogo = '';
    modal('Compartir planificación anual', `<form data-form="compartir"><div class="ag-share"><aside>${field('Título', 'titulo', `Planificación de ${data.tipo} ${state.year}`)}${area('Descripción', 'descripcion')}${area('Nota al pie', 'nota', 'Las fechas están sujetas a reprogramación.')}${select('Color de la marca', 'color', [['verde', 'Verde principal'], ['verde2', 'Verde secundario'], ['amarillo', 'Amarillo'], ['naranja', 'Naranja'], ['gris', 'Gris']], 'verde')}${field('Logo (PNG, JPG o WebP)', 'logo_archivo', '', 'file', 'accept="image/png,image/jpeg,image/webp"')}<div class="ag-row">${button('reporte', 'Vista previa', 'preview')}${button('reporte', 'CSV', 'csv')}${button('reporte', 'HTML', 'html')}${button('reporte', 'PDF', 'pdf')}</div></aside><iframe id="ag-preview" title="Vista previa de planificación" sandbox=""></iframe></div>${data.enviar ? `<details class="ag-panel"><summary>Enviar PDF por correo</summary><div class="ag-fields">${field('Destinatarios separados por coma', 'para')}${field('Asunto', 'asunto', `Planificación de ${data.tipo} ${state.year}`)}${area('Mensaje', 'cuerpo')}</div><div class="ag-form-actions"><button type="submit" class="ag-btn primary">Enviar correo</button></div></details>` : ''}</form>`, true);
    report('preview').catch(failure);
  }
  function readValues(f) { const out = {}; [...f.elements].forEach(el => { if (el.name && el.type !== 'file' && !el.closest('[data-step],[data-new]')) out[el.name] = el.type === 'checkbox' ? el.checked : el.value; }); return out; }
  async function report(action) {
    const f = $('ag-modal').querySelector('form'), d = { ...readValues(f), anio: state.year, comite: state.comite, logo: shareLogo };
    const response = await request(action, d);
    if (action === 'preview') $('ag-preview').srcdoc = await response.text();
    else { const blob = await response.blob(), url = URL.createObjectURL(blob), a = document.createElement('a'); a.href = url; a.download = `planificacion_${data.tipo}_${state.year}.${action}`; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); }
  }
  function parseCSV(text) {
    text = text.replace(/^\uFEFF/, ''); const first = text.split(/\r?\n/, 1)[0], delimiter = (first.match(/;/g) || []).length > (first.match(/,/g) || []).length ? ';' : ',';
    const result = []; let row = [], cell = '', quoted = false;
    for (let i = 0; i < text.length; i++) { const c = text[i]; if (c === '"') { if (quoted && text[i + 1] === '"') { cell += '"'; i++; } else quoted = !quoted; } else if (!quoted && (c === delimiter || c === '\n')) { row.push(cell.replace(/\r$/, '')); cell = ''; if (c === '\n') { result.push(row); row = []; } } else cell += c; }
    if (quoted) throw new Error('El CSV contiene comillas sin cerrar.'); if (cell || row.length) { row.push(cell.replace(/\r$/, '')); result.push(row); }
    const headers = result.shift() || []; return result.filter(r => r.some(v => v.trim())).map(r => Object.fromEntries(headers.map((k, i) => [k, r[i] || ''])));
  }
  root.addEventListener('click', async event => {
    const b = event.target.closest('[data-ag]'); if (!b || b.disabled) return;
    const action = b.dataset.ag, id = b.dataset.id, r = data.registros.find(x => x.id === num(id));
    try {
      if (action === 'cerrar') $('ag-dialog').close();
      else if (action === 'tab') { state.tab = id; render(); }
      else if (action === 'planificar') planForm(b.dataset.comite, b.dataset.mes);
      else if (action === 'nuevo-evento') eventForm({}, b.dataset.mes);
      else if (action === 'editar-evento') eventForm(r);
      else if (action === 'editar-plan') modal('Editar planificación', form('guardar_plan', area('Temas a revisar', 'plan_temas', r.plan_temas) + field('Responsable', 'responsable', r.responsable, 'text', 'maxlength=180'), r.id));
      else if (action === 'ejecutar' || action === 'extraordinaria') executionForm(r);
      else if (action === 'detalle') detail(id);
      else if (action === 'detalle-tab') detail(state.detail, id);
      else if (action === 'paso') $('ag-steps').insertAdjacentHTML('beforeend', step());
      else if (action === 'quitar-fila') b.closest('.ag-step').remove();
      else if (action === 'nuevo-asistente') $('ag-new-contacts').insertAdjacentHTML('beforeend', `<section class="ag-step" data-new><header><b>Nuevo contacto</b>${button('quitar-fila', 'Quitar', '', 'small danger')}</header><div class="ag-fields">${field('Nombre completo', 'nombre', '', 'text', 'required maxlength="180"')}${field('Correo', 'email', '', 'email')}${field('Cargo', 'cargo')}${field('Empresa / ingenio', 'empresa')}${field('Teléfono', 'telefono')}</div></section>`);
      else if (action === 'reprogramar') modal('Reprogramar', form('reprogramar', field('Nuevo mes', 'plan_mes', r.plan_mes || month(r), 'month', 'required') + field('Nueva fecha (opcional)', 'plan_fecha', r.plan_fecha, 'date') + area('Motivo (obligatorio)', 'motivo'), r.id));
      else if (action === 'cancelar') modal('Cancelar ' + (eventos ? 'evento' : 'reunión'), form('cancelar', area('Motivo de cancelación (obligatorio)', 'motivo'), r.id, 'Confirmar cancelación'));
      else if (action === 'eliminar' && confirm('¿Eliminar este registro y sus datos asociados? Esta acción no se puede deshacer.')) { await request('eliminar', { id: r.id }); $('ag-dialog').close(); }
      else if (action === 'fijos') await request('fijos', { anio: state.year });
      else if (action === 'importar') importForm('importar_plan');
      else if (action === 'cargar-asistentes') importForm('asistentes_cargar', r.id);
      else if (action === 'cursos-sincronizar') { b.disabled = true; const tab = state.detailTab; await request('cursos_sincronizar', { id: r.id }); detail(r.id, tab); }
      else if (action === 'cargar-encuesta') importForm('encuesta_cargar', r.id);
      else if (action === 'encuesta-quitar' && confirm('¿Eliminar las respuestas importadas por archivo? Las que vienen de Cengicursos se conservan.')) { await request('encuesta_quitar', { id: r.id }); detail(r.id, 'evaluacion'); }
      else if (action === 'informe') {
        // La ventana se abre antes de pedir el informe para que el navegador no la bloquee.
        const pdf = b.dataset.formato === 'pdf', win = pdf ? null : window.open('', '_blank');
        b.disabled = true;
        try {
          const blob = await (await request(pdf ? 'informe_pdf' : 'informe_html', { id: r.id })).blob(), url = URL.createObjectURL(blob);
          if (pdf) { const a = document.createElement('a'); a.href = url; a.download = `informe_${r.nombre.replace(/[^\w]+/g, '_')}.pdf`; a.click(); }
          else if (win) win.location.href = url;
          setTimeout(() => URL.revokeObjectURL(url), 60000);
        } catch (error) { if (win) win.close(); throw error; }
      }
      else if (action === 'att-filtro') { attState(r.id).filtro = b.dataset.filtro; detail(r.id, 'asistentes'); }
      else if (action === 'com-filtro') { comState(r.id).filtro = b.dataset.filtro; detail(r.id, 'evaluacion'); }
      else if (action === 'com-editar') { const st = comState(r.id), rid = num(b.dataset.respuesta); st.abierto = st.abierto === rid ? 0 : rid; detail(r.id, 'evaluacion'); }
      else if (action === 'com-cerrar') { comState(r.id).abierto = 0; detail(r.id, 'evaluacion'); }
      else if (action === 'com-guardar') {
        const rid = num(b.dataset.respuesta), tr = $('ag-modal').querySelector(`[data-com-edit="${rid}"]`);
        b.disabled = true; await request('encuesta_seguimiento', { id: r.id, respuesta: rid, estado: tr.querySelector('[name="estado"]').value, nota: tr.querySelector('[name="nota"]').value });
        comState(r.id).abierto = 0; detail(r.id, 'evaluacion');
      } else if (action === 'com-confirmar') {
        const a = r.encuesta.find(x => x.id === num(b.dataset.respuesta));
        b.disabled = true; await request('encuesta_categoria', { id: r.id, respuesta: a.id, categoria: a.categoria }); detail(r.id, 'evaluacion');
      } else if (action === 'com-auto') { b.disabled = true; await request('encuesta_autoclasificar', { id: r.id }); detail(r.id, 'evaluacion'); }
      else if (action === 'com-limpiar') { $('ag-modal').querySelectorAll('[data-com]:checked').forEach(c => { c.checked = false; }); listCount('com'); }
      else if (['com-estado', 'com-ocultar', 'com-eliminar'].includes(action)) {
        const boxes = comSelected(), ids = boxes.map(c => num(c.dataset.com));
        if (!ids.length) throw new Error('Selecciona al menos un comentario.');
        if (action === 'com-eliminar') {
          // Las respuestas de la encuesta de Cengicursos volverían al sincronizar: se ocultan.
          const sync = boxes.filter(c => c.dataset.sincronizado).length;
          if (sync === ids.length) throw new Error('Estos comentarios vienen de la encuesta de Cengicursos y volverían al sincronizar: usa “Ocultar”.');
          if (!confirm(`¿Eliminar definitivamente ${ids.length - sync} comentario(s) cargados por archivo?` + (sync ? ` Los ${sync} de la encuesta de Cengicursos no se eliminan (ocúltalos).` : ''))) return;
          b.disabled = true; await request('encuesta_eliminar', { id: r.id, respuestas: ids });
        } else if (action === 'com-ocultar') { b.disabled = true; await request('encuesta_ocultar', { id: r.id, respuestas: ids, ocultar: b.dataset.ocultar === '1' }); }
        else { b.disabled = true; await request('encuesta_seguimiento', { id: r.id, respuestas: ids, estado: b.dataset.estado }); }
        detail(r.id, 'evaluacion');
      }
      else if (action === 'att-editar') { const st = attState(r.id), aid = num(b.dataset.attId); st.abierto = st.abierto === aid ? 0 : aid; detail(r.id, 'asistentes'); }
      else if (action === 'att-cerrar') { attState(r.id).abierto = 0; detail(r.id, 'asistentes'); }
      else if (action === 'att-guardar') {
        // Guarda las correcciones sin integrar (acción "omitir"); la integración se hace en lote.
        const aid = num(b.dataset.attId), st = attState(r.id), tr = $('ag-modal').querySelector(`[data-edit="${aid}"]`), v = k => (tr.querySelector(`[name="${k}"]`)?.value || '').trim();
        const correo = tr.querySelector('[name="email"]'); if (!correo.checkValidity()) { correo.focus(); throw new Error('Revisa el correo.'); }
        if (v('nombre').length < 2) throw new Error('Escribe el nombre.');
        const crear = tr.querySelector('[name="crear"]'); if (crear) st.crear[aid] = crear.checked;
        b.disabled = true; await request('integrar', { id: r.id, filas: [{ id: aid, accion: 'omitir', nombre: v('nombre'), email: v('email'), telefono: v('telefono'), cargo: v('cargo'), empresa: v('empresa') }] });
        st.abierto = 0; detail(r.id, 'asistentes');
      } else if (['att-integrar', 'att-completar', 'att-descartar'].includes(action)) {
        const st = attState(r.id), selected = tipo => [...$('ag-modal').querySelectorAll(`[data-att][data-tipo="${tipo}"]:checked`)].filter(el => !el.closest('tr').hidden).map(el => num(el.dataset.att));
        if (action === 'att-completar') {
          const ids = selected('completar'); if (!ids.length) throw new Error('Selecciona contactos marcados con “Completar”.');
          b.disabled = true; await request('completar_contacto', { id: r.id, asistentes: ids });
        } else {
          const ids = selected('pendiente'); if (!ids.length) throw new Error('Selecciona asistentes nuevos o con coincidencia.');
          if (action === 'att-descartar' && !confirm(`¿Descartar ${ids.length} asistente(s)? No se agregarán al directorio.`)) return;
          const filas = ids.map(id => { const a = r.asistentes.find(x => x.id === id), link = a.coincidencia && !st.crear[id]; return { id, accion: action === 'att-descartar' ? 'descartar' : link ? 'vincular' : 'crear', contacto_id: link ? a.coincidencia.id : 0 }; });
          b.disabled = true; await request('integrar', { id: r.id, comite_id: $('ag-att-comite').value, filas });
        }
        detail(r.id, 'asistentes');
      } else if (action === 'compartir') shareForm();
      else if (action === 'reporte') { b.disabled = true; await report(id); }
    } catch (error) { failure(error); } finally { b.disabled = false; }
  });
  root.addEventListener('change', async event => {
    const el = event.target;
    try {
      if (el.id === 'ag-year') { if (!el.checkValidity()) return; state.year = num(el.value); render(); }
      else if (el.id === 'ag-comite') { state.comite = el.value; render(); }
      else if (el.id === 'ag-status') { state.status = el.value; render(); }
      else if (el.id === 'ag-com-stars') { comState(num(el.dataset.id)).estrellas = el.value; detail(num(el.dataset.id), 'evaluacion'); }
      else if (el.id === 'ag-com-all') { $('ag-modal').querySelectorAll('[data-com]').forEach(c => { if (!c.closest('tr').hidden) c.checked = el.checked; }); listCount('com'); }
      else if (el.dataset.com) listCount('com');
      else if (el.id === 'ag-com-cat') {
        // Clasificación en lote: se aplica al elegirla.
        const ids = comSelected().map(c => num(c.dataset.com)); if (!el.value || !ids.length) return;
        el.disabled = true; await request('encuesta_categoria', { id: num(el.dataset.id), respuestas: ids, categoria: el.value }); detail(num(el.dataset.id), 'evaluacion');
      }
      else if (el.id === 'ag-att-all') { $('ag-modal').querySelectorAll('[data-att]').forEach(c => { if (!c.closest('tr').hidden) c.checked = el.checked; }); attCount(); }
      else if (el.dataset.att) attCount();
      else if (el.dataset.categoria) { el.disabled = true; await request('encuesta_categoria', { id: num(el.dataset.id), respuesta: num(el.dataset.categoria), categoria: el.value }); detail(num(el.dataset.id), 'evaluacion'); }
      else if (el.dataset.date) { const r = data.registros.find(x => x.id === num(el.dataset.date)); await request('ejecutar_evento', { ...r, fecha: el.value }); }
      else if (el.name === 'logo_archivo') { const f = el.files[0]; if (!f) shareLogo = ''; else { if (f.size > 1000000 || !['image/png', 'image/jpeg', 'image/webp'].includes(f.type)) throw new Error('Elige una imagen PNG, JPG o WebP de hasta 1 MB.'); shareLogo = await new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(reader.result); reader.onerror = reject; reader.readAsDataURL(f); }); } await report('preview'); }
    } catch (error) { failure(error); }
  });
  // Listas de asistentes ('att') y comentarios ('com'): búsqueda en pantalla sin recargar ni perder la selección.
  function listCount(kind) {
    const boxes = [...$('ag-modal').querySelectorAll(`[data-${kind}]`)].filter(c => !c.closest('tr').hidden), n = boxes.filter(c => c.checked).length;
    if ($(`ag-${kind}-sel`)) $(`ag-${kind}-sel`).textContent = `${n} seleccionado${n === 1 ? '' : 's'}`;
    const all = $(`ag-${kind}-all`); if (all) { all.checked = !!boxes.length && n === boxes.length; all.indeterminate = n > 0 && n < boxes.length; }
    if ($(`ag-${kind}-bulk`)) $(`ag-${kind}-bulk`).hidden = n === 0;
  }
  const comSelected = () => [...$('ag-modal').querySelectorAll('[data-com]:checked')].filter(c => !c.closest('tr').hidden);
  const attCount = () => listCount('att');
  root.addEventListener('input', event => {
    const kind = { 'ag-att-q': 'att', 'ag-com-q': 'com' }[event.target.id]; if (!kind) return;
    const q = event.target.value.trim().toLowerCase();
    $('ag-modal').querySelectorAll(`.ag-${kind === 'att' ? 'att:not(.ag-com)' : 'com'} tr[data-q]`).forEach(tr => { tr.hidden = !!q && !tr.dataset.q.includes(q); });
    listCount(kind);
  });
  root.addEventListener('input', event => { if (event.target.id === 'ag-find-contact') { const q = event.target.value.toLowerCase(); $('ag-contact-list').querySelectorAll('[data-contact]').forEach(el => { el.hidden = !el.dataset.contact.includes(q); }); } });
  root.addEventListener('submit', async event => {
    const f = event.target.closest('[data-form]'); if (!f) return; event.preventDefault();
    const submit = f.querySelector('[type=submit]'); if (submit.disabled) return; submit.disabled = true; $('ag-error').hidden = true;
    let action = f.dataset.form, values = { ...readValues(f), id: num(f.dataset.id) }, files = {};
    try {
      f.querySelectorAll('input[type=file]').forEach(el => { if (el.files[0]) files[el.name] = el.files[0]; });
      if (action === 'guardar_reunion') {
        values.asistentes = [...f.querySelectorAll('[name=asistentes]:checked')].map(el => num(el.value));
        const nested = selector => [...f.querySelectorAll(selector)].map(row => Object.fromEntries([...row.querySelectorAll('[name]')].map(el => [el.name, el.value])));
        values.acuerdos = nested('[data-step]'); values.nuevos = nested('[data-new]');
      }
      if (action === 'sincronizar') {
        const url = new URL(values.sync_url); if (!['https:', 'http:'].includes(url.protocol)) throw new Error('Usa un enlace HTTP o HTTPS.');
        const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 20000);
        try { const response = await fetch(url.href, { credentials: 'omit', signal: controller.signal }); if (!response.ok) throw new Error('No se pudo leer el enlace. Descarga el CSV y usa Cargar asistentes.'); const content = await response.text(); if (content.length > 5000000) throw new Error('El CSV supera 5 MB.'); values.filas = parseCSV(content); } finally { clearTimeout(timer); }
        if (!values.filas.length || values.filas.length > 2000) throw new Error('El archivo debe tener entre 1 y 2000 asistentes.'); action = 'asistentes_cargar';
      }
      if (action === 'compartir') { action = 'enviar'; values = { ...values, anio: state.year, comite: state.comite, logo: shareLogo }; }
      await request(action, values, files);
      if (['asistentes_cargar', 'encuesta_cargar'].includes(action)) detail(values.id, action === 'asistentes_cargar' ? 'asistentes' : 'evaluacion'); else $('ag-dialog').close();
    } catch (error) { failure(error); } finally { submit.disabled = false; }
  });
  render();
})();
