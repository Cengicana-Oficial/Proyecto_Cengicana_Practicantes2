function probarPasos(codigo) {
  const nodes = new Map(), results = [];let csv = '';
  const node = id => { if (!nodes.has(id)) nodes.set(id, { innerHTML: '', textContent: '', value: '', listeners: {}, hidden: true, open: false, classList: { toggle() {} }, addEventListener(t, f) { this.listeners[t] = f; }, showModal() { this.open = true; }, close() { this.open = false; } }); return nodes.get(id); };
  const base = { comite_id: 'riego', comite_nombre: 'Riego', fecha: '2026-01-01', responsable: 'Ana', revision: 'v1', avance: 25, estado: 'En proceso' };
  const data = { gestionar: true, hoy: '2026-10-01', comites: [{ id: 'riego', nombre: 'Riego', activo: 1 }, { id: 'otro', nombre: 'Otro', activo: 1 }], pasos: [{ ...base, id: 1, acuerdo: 'Futuro', plazo: '2026-11-01', estado_calculado: 'En proceso' }, { ...base, id: 2, acuerdo: '=Vencido <script>', plazo: '2026-09-01', estado_calculado: 'Vencido', observaciones: 'Observación' }, { ...base, id: 3, acuerdo: 'Finalizado', plazo: '2026-01-01', estado_calculado: 'Completado', estado: 'Completado', avance: 100 }] };
  node('paso-data').textContent = JSON.stringify(data);
  new Function('document','URL','Blob','setTimeout',codigo)({ getElementById: node, createElement() { return { click() {}, remove() {} }; }, body: { appendChild() {} } }, { createObjectURL(b) { csv = b.parts.join(''); return 'blob:test'; }, revokeObjectURL() {} }, class { constructor(parts) { this.parts = parts; } }, f => f());
  const click = (paso, id = '') => node('biblioteca-ingenios').listeners.click({ target: { closest() { return { dataset: { paso, id } }; } } });
  const ok = (b,s) => { if(!b)throw new Error(s);results.push('OK: '+s); };
  ok(node('paso-tabla').innerHTML.indexOf('=Vencido &lt;script&gt;') < node('paso-tabla').innerHTML.indexOf('Futuro') && !node('paso-tabla').innerHTML.includes('Finalizado'),'Abiertos por defecto, vencidos primero y escape HTML');
  click('filtro','Vencido');ok(!node('paso-tabla').innerHTML.includes('Futuro'),'Filtro por estado');
  click('exportar');ok(csv.includes("'=Vencido <script>") && !csv.includes('Futuro') && csv.includes('Observación'),'Exportación respeta filtros y neutraliza fórmulas');
  click('editar','2');ok(node('bib-modal').innerHTML.includes('type="range"') && node('bib-modal').innerHTML.includes('Observaciones del seguimiento') && node('bib-modal').innerHTML.includes('Cancelado'),'Formulario con avance, cancelación y observaciones');
  node('biblioteca-ingenios').listeners.change({ target: { id:'paso-comite',value:'otro' } });ok(node('paso-tabla').innerHTML.includes('No hay próximos pasos'),'Filtro combinado de comité');
  return results;
}
