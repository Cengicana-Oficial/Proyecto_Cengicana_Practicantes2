/*
 * Exportación a Excel (.xlsx) de CengiVisitas.
 * Genera libros con formato institucional usando ExcelJS, que se carga solo
 * cuando el usuario descarga un archivo.
 *
 * Uso:
 *   VisitasExcel.download('archivo.xlsx', [{
 *     name: 'Hoja', title: 'Título', subtitle: 'Detalle',
 *     tables: [{ caption, columns: [{ header, key, type, width, colors }], rows, totals }]
 *   }]);
 *
 * Tipos de columna: text (predeterminado), wrap, int, money, date (yyyy-mm-dd), datetime.
 * totals: { key: 'sum' | 'Texto' } agrega una fila de totales que respeta los filtros.
 */
(function () {
  'use strict';

  var EXCELJS_URL = 'https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.4.0/exceljs.min.js';
  var COLORS = {
    deep: 'FF1F5632', deepText: 'FFFFFFFF', soft: 'FFEEF8DF', softText: 'FF3D6B12',
    header: 'FFE3F1D0', headerText: 'FF173F25', border: 'FFCAD9C8', zebra: 'FFF7FAF4',
    muted: 'FF55705F', stripe: ['FF73BC25', 'FFA3D300', 'FFFFCC00', 'FFFF6B00']
  };
  var loading = null;

  function loadLibrary() {
    if (window.ExcelJS) return Promise.resolve(window.ExcelJS);
    if (loading) return loading;
    loading = new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      script.src = EXCELJS_URL;
      script.async = true;
      script.onload = function () { window.ExcelJS ? resolve(window.ExcelJS) : reject(new Error('No se pudo iniciar el generador de Excel.')); };
      script.onerror = function () { loading = null; reject(new Error('No se pudo cargar el generador de Excel. Revisa la conexión a internet.')); };
      document.head.appendChild(script);
    });
    return loading;
  }

  function columnLetter(index) {
    var letter = '';
    while (index > 0) {
      var rest = (index - 1) % 26;
      letter = String.fromCharCode(65 + rest) + letter;
      index = Math.floor((index - 1) / 26);
    }
    return letter;
  }

  function toDate(value, withTime) {
    if (!value) return null;
    var text = String(value).trim();
    var match = text.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/);
    if (!match) return null;
    // Se usa UTC para que Excel muestre exactamente la fecha registrada, sin desfase horario.
    return new Date(Date.UTC(+match[1], +match[2] - 1, +match[3],
      withTime && match[4] ? +match[4] : 0, withTime && match[5] ? +match[5] : 0, withTime && match[6] ? +match[6] : 0));
  }

  function cellValue(column, raw) {
    if (raw === null || raw === undefined || raw === '') return null;
    switch (column.type) {
      case 'int': return Number.isFinite(Number(raw)) ? Math.round(Number(raw)) : null;
      case 'money': return Number.isFinite(Number(raw)) ? Math.round(Number(raw) * 100) / 100 : null;
      case 'date': return toDate(raw, false) || String(raw);
      case 'datetime': return toDate(raw, true) || String(raw);
      default: return String(raw);
    }
  }

  function numberFormat(type) {
    return { int: '#,##0', money: '"Q"#,##0.00', date: 'dd/mm/yyyy', datetime: 'dd/mm/yyyy hh:mm' }[type];
  }

  function textLength(value, type) {
    if (value === null || value === undefined) return 0;
    if (type === 'money') return String(Number(value).toFixed(2)).length + 4;
    if (type === 'date') return 10;
    if (type === 'datetime') return 16;
    return String(value).split('\n').reduce(function (max, line) { return Math.max(max, line.length); }, 0);
  }

  function styleBand(row, from, to, fill, font, height) {
    for (var col = from; col <= to; col++) {
      var cell = row.getCell(col);
      cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: fill } };
      cell.font = font;
      cell.alignment = { vertical: 'middle', horizontal: 'left', indent: 1 };
    }
    if (height) row.height = height;
  }

  function addTable(sheet, startRow, table, width) {
    var columns = table.columns, count = columns.length, rowIndex = startRow;
    if (table.caption) {
      var captionRow = sheet.getRow(rowIndex);
      captionRow.getCell(1).value = table.caption;
      sheet.mergeCells(rowIndex, 1, rowIndex, count);
      styleBand(captionRow, 1, count, COLORS.soft, { bold: true, size: 12, color: { argb: COLORS.softText } }, 22);
      rowIndex++;
    }

    var headerRowIndex = rowIndex, headerRow = sheet.getRow(rowIndex);
    columns.forEach(function (column, index) {
      var cell = headerRow.getCell(index + 1);
      cell.value = column.header;
      cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: COLORS.header } };
      cell.font = { bold: true, color: { argb: COLORS.headerText } };
      // Alineado a la izquierda para que el botón del filtro no tape el texto.
      cell.alignment = { vertical: 'middle', horizontal: 'left', wrapText: true };
      cell.border = { bottom: { style: 'medium', color: { argb: 'FF73BC25' } } };
    });
    headerRow.height = 24;
    rowIndex++;

    var firstData = rowIndex;
    if (!table.rows.length) {
      var emptyRow = sheet.getRow(rowIndex);
      emptyRow.getCell(1).value = table.emptyText || 'Sin datos para los filtros seleccionados.';
      emptyRow.getCell(1).font = { italic: true, color: { argb: COLORS.muted } };
      sheet.mergeCells(rowIndex, 1, rowIndex, count);
      rowIndex++;
    }
    table.rows.forEach(function (item, index) {
      var row = sheet.getRow(rowIndex);
      columns.forEach(function (column, colIndex) {
        var cell = row.getCell(colIndex + 1), raw = item[column.key], value = cellValue(column, raw);
        cell.value = value;
        if (numberFormat(column.type)) cell.numFmt = numberFormat(column.type);
        cell.alignment = { vertical: 'top', wrapText: column.type === 'wrap', horizontal: ['int', 'money'].indexOf(column.type) >= 0 ? 'right' : 'left' };
        cell.border = { bottom: { style: 'thin', color: { argb: 'FFE6ECE4' } } };
        var color = column.colors && column.colors[raw];
        if (color) {
          cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: color.fill } };
          cell.font = { bold: true, color: { argb: color.font } };
        } else if (index % 2 === 1) {
          cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: COLORS.zebra } };
        }
        width[colIndex] = Math.max(width[colIndex] || 0, textLength(value, column.type));
      });
      rowIndex++;
    });
    var lastData = rowIndex - 1;

    if (table.totals && table.rows.length) {
      var totalRow = sheet.getRow(rowIndex);
      columns.forEach(function (column, colIndex) {
        var cell = totalRow.getCell(colIndex + 1), rule = table.totals[column.key];
        if (rule === 'sum') {
          var letter = columnLetter(colIndex + 1);
          var result = table.rows.reduce(function (sum, item) { return sum + (Number(item[column.key]) || 0); }, 0);
          // SUBTOTAL(109, …) suma solo las filas visibles cuando se usa el filtro de Excel.
          cell.value = { formula: 'SUBTOTAL(109,' + letter + firstData + ':' + letter + lastData + ')', result: result };
          if (numberFormat(column.type)) cell.numFmt = numberFormat(column.type);
          cell.alignment = { horizontal: 'right' };
        } else if (rule) {
          cell.value = rule;
        }
        cell.font = { bold: true, color: { argb: COLORS.headerText } };
        cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: COLORS.soft } };
        cell.border = { top: { style: 'medium', color: { argb: 'FF73BC25' } } };
      });
      totalRow.height = 22;
      rowIndex++;
    }

    return { next: rowIndex, headerRow: headerRowIndex, firstData: firstData, lastData: lastData };
  }

  function buildSheet(workbook, spec) {
    var maxColumns = spec.tables.reduce(function (max, table) { return Math.max(max, table.columns.length); }, 1);
    var sheet = workbook.addWorksheet(spec.name.slice(0, 31), {
      pageSetup: { orientation: maxColumns > 6 ? 'landscape' : 'portrait', fitToPage: true, fitToWidth: 1, fitToHeight: 0, paperSize: 9,
        margins: { left: 0.4, right: 0.4, top: 0.5, bottom: 0.5, header: 0.2, footer: 0.2 } },
      headerFooter: { oddFooter: '&L' + (spec.title || spec.name) + '&RPágina &P de &N' }
    });

    var titleRow = sheet.getRow(1);
    titleRow.getCell(1).value = spec.title || spec.name;
    sheet.mergeCells(1, 1, 1, maxColumns);
    styleBand(titleRow, 1, maxColumns, COLORS.deep, { bold: true, size: 16, color: { argb: COLORS.deepText } }, 32);

    var stripe = sheet.getRow(2);
    for (var col = 1; col <= maxColumns; col++) {
      var tone = COLORS.stripe[Math.min(3, Math.floor((col - 1) / Math.max(1, maxColumns / 4)))];
      stripe.getCell(col).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: tone } };
    }
    stripe.height = 4;

    var subtitleRow = sheet.getRow(3);
    subtitleRow.getCell(1).value = spec.subtitle || '';
    sheet.mergeCells(3, 1, 3, maxColumns);
    subtitleRow.getCell(1).font = { italic: true, size: 10, color: { argb: COLORS.muted } };
    subtitleRow.getCell(1).alignment = { vertical: 'middle', wrapText: true, indent: 1 };

    var width = [], rowIndex = 5, firstTable = null;
    spec.tables.forEach(function (table, index) {
      var info = addTable(sheet, rowIndex, table, width);
      if (index === 0) firstTable = info;
      rowIndex = info.next + 1;
    });

    var single = spec.tables.length === 1 ? spec.tables[0] : null;
    spec.tables[0].columns.forEach(function (column, index) {
      // +5 deja espacio para el botón del filtro de Excel junto al encabezado.
      var headerWidth = textLength(column.header, 'text') + 5;
      var measured = Math.max(headerWidth, (width[index] || 0) + 2);
      sheet.getColumn(index + 1).width = column.width
        ? Math.max(column.width, headerWidth)
        : Math.min(column.type === 'wrap' ? 60 : 45, Math.max(9, measured));
    });
    // Alto del subtítulo según cuántas líneas ocupa en el ancho combinado de las columnas.
    var mergedWidth = 0;
    for (var c = 1; c <= maxColumns; c++) mergedWidth += sheet.getColumn(c).width || 9;
    var lines = Math.max(1, Math.ceil(String(spec.subtitle || '').length * 1.1 / Math.max(10, mergedWidth)));
    subtitleRow.height = 14 * lines + 6;
    if (single && firstTable) {
      sheet.views = [{ state: 'frozen', ySplit: firstTable.headerRow, xSplit: spec.freezeColumns || 0 }];
      if (single.rows.length) {
        sheet.autoFilter = { from: { row: firstTable.headerRow, column: 1 }, to: { row: firstTable.lastData, column: single.columns.length } };
      }
      sheet.pageSetup.printTitlesRow = firstTable.headerRow + ':' + firstTable.headerRow;
    }
    return sheet;
  }

  function generatedLabel() {
    return 'Generado el ' + new Intl.DateTimeFormat('es-GT', { dateStyle: 'long', timeStyle: 'short' }).format(new Date());
  }

  async function download(filename, sheets) {
    var ExcelJS = await loadLibrary();
    var workbook = new ExcelJS.Workbook();
    workbook.creator = 'CengiVisitas · Cengicaña';
    workbook.created = new Date();
    sheets.forEach(function (spec) { buildSheet(workbook, spec); });
    var buffer = await workbook.xlsx.writeBuffer();
    var blob = new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' });
    var url = URL.createObjectURL(blob), anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = /\.xlsx$/i.test(filename) ? filename : filename + '.xlsx';
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 1500);
  }

  window.VisitasExcel = { download: download, load: loadLibrary, generatedLabel: generatedLabel };
})();
