/* Ejecutar probarBiblioteca(codigoFuente); pruebas de interfaz con DOM mínimo, sin solicitudes. */
async function probarBiblioteca(codigo) {
  const nodes = new Map(), results = [];
  function node(id) {
    if (!nodes.has(id)) nodes.set(id, { innerHTML: '', textContent: '', value: '', hidden: false, open: false, dataset: {}, listeners: {}, classList: { toggle() {} }, addEventListener(t, fn) { this.listeners[t] = fn; }, showModal() { this.open = true; }, close() { this.open = false; }, querySelector() { return { elements: { cuerpo: { value: 'Mensaje de prueba' } } }; } });
    return nodes.get(id);
  }
  const common = { tipo: 'Boletin', autor: 'Autora', fecha: '2026-09-01', version: 1, requiere_aprobacion: 1, propietario: true, pendiente_desde: '2026-09-01', historial: [], cargado_por_nombre: 'Autora', revision: '1:borrador:0' };
  const fixture = { documentos: [{ ...common, id: 1, titulo: 'Riego <script>', carpeta_id: 1, flujo: 'borrador' }, { ...common, id: 2, titulo: 'Nutrición', carpeta_id: 2, flujo: 'rev1', revisar: true, historial: [{ accion: 'enviado', etapa: 'rev1', actor: 'Autora', version: 1, creado_en: '2026-09-01 10:00:00' }] }, { ...common, id: 3, titulo: 'Aprobado', carpeta_id: 2, flujo: 'aprobado', enviar: true, enlace: 'https://example.invalid/doc' }], carpetas: [{ id: 1, nombre: 'Boletines', parent_id: null }, { id: 2, nombre: 'Subcarpeta', parent_id: 1 }], comites: [{ id: 'riego', nombre: 'Riego' }], contactos: [{ id: 1, nombre: 'Ana <script>', email: 'ana@example.invalid', comites: ['riego'] }, { id: 2, nombre: 'Sin correo', email: '', comites: ['riego'] }], segmentos: [], tipos: { Boletin: 'Boletín', Otro: 'Otro', Presentacion: 'Presentación' }, estados: { borrador: 'Borrador', rev1: 'En primera revisión', aprobado: 'Aprobado' }, etapas: { rev1: { nombre: 'Primera revisión', rol: 'Jefe de Transferencia' }, rev2: { nombre: 'Segunda revisión', rol: 'Jefe de Transferencia' }, rev3: { nombre: 'Aprobación final', rol: 'Director General' } }, gestionar: true, hoy: '2026-09-30' };
  node('bib-data').textContent = JSON.stringify(fixture);
  class Params { get() { return null; } }
  new Function('document', 'location', 'history', 'URLSearchParams', 'fetch', codigo)({ getElementById: node }, { search: '' }, { replaceState() {} }, Params, () => { throw new Error('No se envían solicitudes desde esta prueba'); });
  const assert = (ok, msg) => { if (!ok) throw new Error(msg); results.push('OK: ' + msg); };
  const click = async (action, id = '') => { const b = { dataset: { bib: action, id }, disabled: false }; await node('biblioteca-ingenios').listeners.click({ target: { closest() { return b; } } }); };
  assert(node('bib-content').innerHTML.includes('Boletines') && !node('bib-content').innerHTML.includes('Riego &lt;script&gt;'), 'Inicio muestra carpetas');
  await click('abrir', '1');
  assert(node('bib-content').innerHTML.includes('Riego &lt;script&gt;') && !node('bib-content').innerHTML.includes('Riego <script>'), 'Documentos de carpeta con escape HTML');
  await click('filtro', 'revision');
  assert(node('bib-content').innerHTML.includes('Nutrición') && node('bib-count').textContent === '1 documento(s)', 'Filtro de revisión busca en todas las carpetas');
  await click('limpiar');
  node('biblioteca-ingenios').listeners.input({ target: { id: 'bib-search', value: 'nutricion', dataset: {} } });
  assert(node('bib-content').innerHTML.includes('Nutrición'), 'Búsqueda global sin sensibilidad a tildes');
  await click('revisar', '2');
  assert(node('bib-modal').innerHTML.includes('Aprobar sin anotaciones') && node('bib-modal').innerHTML.includes('Devolver con anotaciones'), 'Decisiones de primera revisión');
  await click('seguimiento', '2');
  assert(node('bib-modal').innerHTML.includes('Bitácora del proceso') && node('bib-modal').innerHTML.includes('Director General'), 'Seguimiento incluye etapas y bitácora');
  await click('lote');
  await node('biblioteca-ingenios').listeners.change({ target: { id: 'bib-batch-files', files: [{ name: 'boletin_riego.pdf', size: 200 }], value: '', dataset: {} } });
  assert(node('bib-batch-list').innerHTML.includes('boletin riego') && node('bib-batch-list').innerHTML.includes('value="Boletin" selected'), 'Carga múltiple deduce título y tipo');
  await click('correo', '3'); await click('grupos-todos');
  assert(node('bib-mail-count').textContent.includes('1 destinatario(s) · 1 sin correo'), 'Grupos distinguen destinatarios sin correo');
  await node('biblioteca-ingenios').listeners.change({ target: { dataset: { contact: '1' }, checked: false } });
  assert(node('bib-mail-count').textContent.includes('0 destinatario(s)'), 'Exclusión individual de destinatario');
  await click('restablecer');
  assert(node('bib-mail-count').textContent.includes('1 destinatario(s)'), 'Restablecer selección del comité');
  return results;
}
