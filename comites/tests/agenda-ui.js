/* Ejecutar probarAgenda(codigoFuente): DOM mínimo, sin solicitudes ni cambios de datos. */
async function probarAgenda(codigo) {
  const resultados = [];
  for (const tipo of ['reuniones', 'eventos']) {
    const nodes = new Map();
    function node(id) {
      if (!nodes.has(id)) nodes.set(id, { innerHTML: '', textContent: '', hidden: false, open: false, dataset: {}, listeners: {}, classList: { toggle() {} }, addEventListener(t, fn) { this.listeners[t] = fn; }, showModal() { this.open = true; }, close() { this.open = false; } });
      return nodes.get(id);
    }
    const r = { id: 1, nombre: 'Evento <script>', comite_id: 'riego', plan_mes: '2026-01', plan_fecha: '2026-01-10', fecha: '2026-01-11', realizada: 1, cancelada: 0, estado: 'realizada', gestionar: true, historial: [], asistentes: [], acuerdos: [], encuesta: [{ calificacion: 5, comentario: '<img onerror=x>' }], participantes: 12, ingenios: 2, temas: 'Tema <script>', plan_temas: 'Plan' };
    node('ag-data').textContent = JSON.stringify({ tipo, registros: [r], comites: [{ id: 'riego', nombre: 'Riego <script>', activo: 1, gestionar: true }], contactos: [], estados: { realizada: 'Realizada', planificada: 'Planificada' }, meses: ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'], hoy: '2026-09-30', gestionar: true });
    new Function('document', 'fetch', codigo)({ getElementById: node }, () => { throw new Error('La prueba no envía solicitudes'); });
    const assert = (value, message) => { if (!value) throw new Error(message); resultados.push('OK: ' + tipo + ' · ' + message); };
    const click = async (ag, id = '') => { const b = { disabled: false, dataset: { ag, id } }; await node('agenda').listeners.click({ target: { closest() { return b; } } }); };
    assert(node('ag-content').innerHTML.includes('Ene'), 'Calendario anual renderizado');
    assert(!node('ag-content').innerHTML.includes('<script>'), 'Nombres escapados');
    await click('tab', 'ejecucion');
    assert(node('ag-content').innerHTML.includes('Fecha real'), 'Vista de ejecución');
    await node('agenda').listeners.change({ target: { id: 'ag-status', value: 'planificada' } });
    assert(node('ag-content').innerHTML.includes('No hay registros'), 'Filtro de estado');
    await click('detalle', '1');
    assert(node('ag-modal').innerHTML.includes('Planificación') && node('ag-modal').innerHTML.includes('Ejecución'), 'Detalle compara plan y ejecución');
    if (tipo === 'reuniones') {
      await click('tab', 'analisis');
      assert(node('ag-content').innerHTML.includes('100%') && node('ag-content').innerHTML.includes('1 días'), 'Cumplimiento y desviación calculados');
      await click('planificar'); assert(node('ag-modal').innerHTML.includes('Bimestral'), 'Formulario de recurrencia');
      await click('extraordinaria'); assert(node('ag-modal').innerHTML.includes('Próximos pasos'), 'Ejecución incluye próximos pasos');
    } else {
      await click('detalle-tab', 'evaluacion');
      assert(node('ag-modal').innerHTML.includes('5.0') && node('ag-modal').innerHTML.includes('&lt;img onerror=x&gt;'), 'Promedio y comentarios escapados');
      await click('detalle-tab', 'asistentes'); assert(node('ag-modal').innerHTML.includes('Sincronizar'), 'Carga y sincronización disponibles');
      await click('nuevo-evento'); assert(node('ag-modal').innerHTML.includes('Híbrido'), 'Formulario de evento');
    }
  }
  return resultados;
}
