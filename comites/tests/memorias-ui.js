async function probarMemorias(codigo) {
  const nodes = new Map(), results = [];
  function node(id) {
    if (!nodes.has(id)) nodes.set(id, { innerHTML: '', textContent: '', value: '', hidden: false, open: false, listeners: {}, classList: { toggle() {} }, addEventListener(t, fn) { this.listeners[t] = fn; }, showModal() { this.open = true; }, close() { this.open = false; }, querySelector() { return { elements: { edicion_id: { value: '1' } } }; } });
    return nodes.get(id);
  }
  const fixture = { articulos: [{ id: 1, edicion_id: 1, numero_memoria: 'MR-2026-001', titulo: 'Nutrición <script>', autor: 'Autora', flujo: 'revision', version: 1, revisar: true, historial: [], anterior: [] }], ediciones: [{ id: 1, nombre: 'Memoria 2026', dias_revision: 15, revision: 1, mpr: { estado: 'revision_final', version: 1, archivo: { nombre: 'MPR.pdf', ruta: 'test' }, versiones: [], observaciones: [], bitacora: [] } }], estados: { entregado: 'Sin revisor', revision: 'En revisión', observaciones: 'Con observaciones', listo: 'Listo', diagramacion: 'Diagramación' }, estados_mpr: { revision_final: 'Revisión final' }, permisos: { coord: true, admin: true, diag: true, jt: true, dir: true, entregar: true, contactos: true }, usuario: { nombre: 'Admin', correo: 'admin@example.invalid' }, revisores: [], contactos: [], comites: [], avisos: [], hoy: '2026-09-30' };
  node('mem-data').textContent = JSON.stringify(fixture);
  node('bib-error').hidden = true;
  class Params { get() { return null; } }
  new Function('document', 'location', 'history', 'URLSearchParams', 'fetch', codigo)({ getElementById: node }, { search: '' }, { replaceState() {} }, Params, () => { throw new Error('Sin solicitudes en esta prueba'); });
  const ok = (condition, label) => { if (!condition) throw new Error(label); results.push('OK: ' + label); };
  const click = async (mem, id = '') => { const b = { dataset: { mem, id } }; await node('biblioteca-ingenios').listeners.click({ target: { closest() { return b; } } }); if (!node('bib-error').hidden) throw new Error(node('bib-error').textContent); };
  ok(node('mem-content').innerHTML.includes('Memoria 2026'), 'Ediciones al iniciar');
  await click('abrir', '1');ok(node('mem-content').innerHTML.includes('Nutrición &lt;script&gt;'), 'Escapado HTML de títulos');
  ok(node('mem-content').innerHTML.includes('Aprobar versión final') && !node('mem-content').innerHTML.includes('Aprobar liberación'), 'Aprobación de Transferencia antes de Dirección');
  await click('filtro', 'mios');ok(node('mem-content').innerHTML.includes('MR-2026-001'), 'Filtro pendientes de mí incluye revisiones asignadas');
  await click('revisar', '1');ok(node('bib-modal').innerHTML.includes('name="revision_archivo"') && node('bib-modal').innerHTML.includes('name="criterios"'), 'Revisión contiene los dos archivos obligatorios');
  await click('entregar');ok(node('bib-modal').innerHTML.includes('name="coautores"'), 'Entrega incluye autor y coautores');
  await click('mpr-cargar', '1');ok(node('bib-modal').innerHTML.includes('aceptar_pendientes'), 'Carga consolidada pide confirmar artículos pendientes');
  await click('mpr-observar', '1');ok(node('bib-modal').innerHTML.includes('name="seccion"'), 'Observación final incluye página o sección');
  await click('revisores');ok(node('bib-modal').innerHTML.includes('Directorio de contactos'), 'Revisores integrados con directorio');
  return results;
}
