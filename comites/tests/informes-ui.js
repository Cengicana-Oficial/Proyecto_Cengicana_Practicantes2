async function probarInformes(codigo) {
  const results = [];
  const fixture = { hoy: '2026-10-01', permisos: { gestionar: true, revisar: true }, estados: { pendiente: 'Pendiente de entrega', entregado: 'Entregado · por revisar', observado: 'Revisado con pendientes', atendido: 'Pendientes atendidos', revisado: 'Revisado' }, areas: [{ id: 1, nombre: 'Riego <script>', activo: 1, entregar: true, correo: 'head@example.invalid' }, { id: 2, nombre: 'Variedades', activo: 1, entregar: false }], informes: [{ id: 1, anio: 2026, cuatri: 2, area_id: 1, area: 'Riego <script>', flujo: 'entregado', revision: 2, pendientes: [], bitacora: [], soportes: [] }], poa: [{ anio: 2026, revision: 1, fuente: 'Archivo Excel', filas: [{ area: 'Riego <script>', cuatrimestre: 2, actividad: 'Uniformidad', meta: 10, logro: 8, unidad: 'número' }] }], revision_areas: 1 };
  function boot(data) {
    const nodes = new Map(); const node = id => { if (!nodes.has(id)) nodes.set(id, { innerHTML: '', textContent: '', value: '', hidden: true, open: false, listeners: {}, classList: { toggle() {} }, addEventListener(t, fn) { this.listeners[t] = fn; }, showModal() { this.open = true; }, close() { this.open = false; } }); return nodes.get(id); };
    node('inf-data').textContent = JSON.stringify(data); new Function('document', 'fetch', codigo)({ getElementById: node }, () => { throw new Error('No se esperan solicitudes'); });
    const click = (inf, id = '') => node('biblioteca-ingenios').listeners.click({ target: { closest() { return { dataset: { inf, id } }; } } }); return { node, click };
  }
  const ok = (b, s) => { if (!b) throw new Error(s); results.push('OK: ' + s); };
  let { node, click } = boot(fixture);
  ok(node('inf-nav').innerHTML.includes('II Cuatrimestre') && node('inf-content').innerHTML.includes('2026-09-15'), 'Cuatrimestre anterior y fecha límite según plantilla');
  ok(node('inf-content').innerHTML.includes('Riego &lt;script&gt;') && !node('inf-content').innerHTML.includes('Riego <script>'), 'Escape HTML de nombres');
  ok(node('inf-content').innerHTML.includes('80%'), 'Cumplimiento POA calculado por área y cuatrimestre');
  click('annual'); ok(node('inf-content').innerHTML.includes('III Cuatrimestre') && node('inf-content').innerHTML.includes('POA: 80%'), 'Resumen anual con estados y POA');
  click('term', '2'); click('entregar', '1');ok(node('bib-modal').innerHTML.includes('Guardar sin entregar') && node('bib-modal').innerHTML.includes('Entregar para revisión'), 'Entrega distingue borrador y envío');
  click('revisar', '1'); node('inf-pending-text').value = 'Adjuntar <evidencia>';click('add-pending');ok(node('inf-pending-list').innerHTML.includes('Adjuntar &lt;evidencia&gt;'), 'Lista de pendientes con escape');click('remove-pending','0');ok(!node('inf-pending-list').innerHTML,'Se pueden quitar pendientes antes de enviar');
  click('areas');ok(node('bib-modal').innerHTML.includes('correo_1'), 'Responsable de área ligado a correo real');
  click('poa');ok(node('bib-modal').innerHTML.includes('Sincronizar ahora') && node('bib-modal').innerHTML.includes('Plantilla de columnas'), 'POA ofrece enlace, archivo y plantilla');
  const readOnly = JSON.parse(JSON.stringify(fixture));readOnly.permisos = { gestionar: false, revisar: false };readOnly.areas.forEach(a => a.entregar = false);({ node, click } = boot(readOnly));ok(!node('inf-actions').innerHTML.includes('data-inf="areas"') && !node('inf-content').innerHTML.includes('data-inf="entregar"'), 'Consulta sin administración ni entrega');click('poa');ok(!node('bib-modal').innerHTML.includes('<form'), 'Consulta del POA sin modificar fuente');
  return results;
}
