/* Paginación en el navegador para listas ya cargadas (Informes y Reportes): recibe las filas que
   pasan los filtros, muestra solo las de la página actual y pinta el paginador (.lab-pager). */
(function (root) {
  'use strict';
  function crear(nav, porPagina) {
    let pagina = 1, filas = [], alCambiar = null;
    const rango = nav.querySelector('[data-pager-rango]');
    const info = nav.querySelector('[data-pager-info]');
    const prev = nav.querySelector('[data-pager-prev]');
    const next = nav.querySelector('[data-pager-next]');
    function pintar() {
      const paginas = Math.max(1, Math.ceil(filas.length / porPagina));
      pagina = Math.min(Math.max(1, pagina), paginas);
      const inicio = (pagina - 1) * porPagina;
      filas.forEach((fila, i) => { fila.hidden = i < inicio || i >= inicio + porPagina; });
      nav.hidden = paginas <= 1;
      rango.textContent = filas.length ? (inicio + 1) + '–' + Math.min(inicio + porPagina, filas.length) + ' de ' + filas.length : '';
      info.textContent = 'Página ' + pagina + ' de ' + paginas;
      prev.disabled = pagina <= 1;
      next.disabled = pagina >= paginas;
    }
    function mover(paso) {
      pagina += paso;
      pintar();
      if (alCambiar) alCambiar();
    }
    prev.addEventListener('click', () => mover(-1));
    next.addEventListener('click', () => mover(1));
    return {
      /** Filas visibles tras los filtros; reinicia a la página 1 salvo que se indique conservarla. */
      aplicar(nuevas, conservar) { filas = nuevas; if (!conservar) pagina = 1; pintar(); },
      alCambiar(fn) { alCambiar = fn; },
    };
  }
  root.LabPager = { crear };
})(window);
