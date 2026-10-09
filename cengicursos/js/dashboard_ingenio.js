(function () {
    'use strict';
    var input = document.getElementById('dbi-course-search');
    var table = document.getElementById('dbi-course-table');
    if (!input || !table) return;
    var rows = Array.prototype.filter.call(table.querySelectorAll('tbody tr'), function (row) { return row.querySelector('[data-curso-id]'); });
    if (!rows.length) return;
    var empty = document.createElement('tr');
    var cell = document.createElement('td');
    cell.colSpan = 5;
    cell.textContent = 'No se encontraron cursos con esa búsqueda.';
    empty.appendChild(cell);
    table.tBodies[0].appendChild(empty);
    function normalize(text) { return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim(); }
    function filter() {
        var query = normalize(input.value), count = 0;
        rows.forEach(function (row) {
            row.hidden = normalize(row.textContent).indexOf(query) < 0;
            if (!row.hidden) count++;
        });
        empty.hidden = count > 0;
        document.getElementById('dbi-course-count').textContent = count + ' de ' + rows.length + ' cursos';
    }
    input.addEventListener('input', filter);
    document.getElementById('dbi-course-filter').hidden = false;
    // El informe impreso siempre incluye todos los cursos, al igual que PDF/Excel.
    window.addEventListener('beforeprint', function () { rows.forEach(function (row) { row.hidden = false; }); empty.hidden = true; });
    window.addEventListener('afterprint', filter);
    filter();
})();
