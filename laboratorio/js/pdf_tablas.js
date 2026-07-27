(function () {
  const green = {
    dark: rgb(0.1, 0.2, 0.04),
    mid: rgb(0.23, 0.43, 0.07),
    light: rgb(0.9, 0.96, 0.86),
    border: rgb(0.68, 0.79, 0.53),
    text: rgb(0.1, 0.2, 0.04),
    muted: rgb(0.34, 0.44, 0.27),
    white: rgb(1, 1, 1),
  };

  function rgb(r, g, b) {
    return window.PDFLib.rgb(r, g, b);
  }

  function normalizarTexto(value) {
    return String(value ?? "").replace(/\s+/g, " ").trim() || "-";
  }

  function limpiarTextoPdf(value) {
    return String(value ?? "")
      .replace(/\r/g, "")
      .replace(/[–—]/g, "-")
      .replace(/[“”]/g, '"')
      .replace(/[‘’]/g, "'")
      .replace(/[^\n\x20-\x7e\xa0-\xff]/g, "")
      .trim();
  }

  function nombreArchivo(value) {
    return normalizarTexto(value)
      .normalize("NFD")
      .replace(/[\u0300-\u036f]/g, "")
      .replace(/[^a-zA-Z0-9._-]+/g, "_")
      .replace(/^_+|_+$/g, "")
      .toLowerCase();
  }

  function descargar(bytes, fileName) {
    const blob = new Blob([bytes], { type: "application/pdf" });
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = fileName;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  }

  function wrapText(text, font, size, maxWidth) {
    const words = normalizarTexto(limpiarTextoPdf(text)).split(" ");
    const lines = [];
    let line = "";

    words.forEach((word) => {
      const candidate = line ? `${line} ${word}` : word;
      if (font.widthOfTextAtSize(candidate, size) <= maxWidth || !line) {
        line = candidate;
      } else {
        lines.push(line);
        line = word;
      }
    });

    if (line) lines.push(line);
    return lines;
  }

  function wrapPdfText(text, font, size, maxWidth) {
    const paragraphs = limpiarTextoPdf(text).split("\n");
    const lines = [];

    paragraphs.forEach((paragraph) => {
      const words = paragraph.split(/\s+/).filter(Boolean);

      if (!words.length) {
        lines.push("");
        return;
      }

      let line = "";
      words.forEach((word) => {
        const testLine = line ? `${line} ${word}` : word;

        if (font.widthOfTextAtSize(testLine, size) <= maxWidth) {
          line = testLine;
          return;
        }

        if (line) lines.push(line);

        if (font.widthOfTextAtSize(word, size) <= maxWidth) {
          line = word;
          return;
        }

        let fragment = "";
        Array.from(word).forEach((char) => {
          const testFragment = fragment + char;
          if (font.widthOfTextAtSize(testFragment, size) <= maxWidth) {
            fragment = testFragment;
          } else {
            if (fragment) lines.push(fragment);
            fragment = char;
          }
        });
        line = fragment;
      });

      if (line) lines.push(line);
    });

    return lines;
  }

  async function cargarLogo(pdfDoc) {
    try {
      const response = await fetch("../../assets/Marca%20Cengica%C3%B1a/SinFondo_logo_cengicana_Vertical.png", { cache: "no-store" });
      if (!response.ok) return null;
      return await pdfDoc.embedPng(await response.arrayBuffer());
    } catch (_) {
      return null;
    }
  }

  function drawLandscapeHeader({ page, logo, bold, regular, pageSize, margin, y, title, subtitle, badgeLabel = "VF", badgeValue = "005" }) {
    const pageWidth = pageSize[0];
    const textX = logo ? margin + 48 : margin;

    if (logo) {
      const logoWidth = 30;
      const logoHeight = logoWidth * (logo.height / logo.width);
      page.drawImage(logo, {
        x: margin,
        y: y - 10 - logoHeight,
        width: logoWidth,
        height: logoHeight,
      });
    }

    page.drawText("Laboratorio Agroindustrial", {
      x: textX,
      y,
      size: 14,
      font: bold,
      color: green.dark,
    });
    page.drawText(normalizarTexto(title), {
      x: textX,
      y: y - 18,
      size: 17,
      font: bold,
      color: green.mid,
    });
    if (subtitle) {
      page.drawText(normalizarTexto(subtitle), {
        x: textX,
        y: y - 32,
        size: 9,
        font: regular,
        color: green.muted,
      });
    }

    page.drawRectangle({
      x: pageWidth - margin - 78,
      y: y - 26,
      width: 78,
      height: 34,
      color: green.light,
      borderColor: green.border,
      borderWidth: 1,
    });
    page.drawText(badgeLabel, {
      x: pageWidth - margin - 64,
      y: y - 6,
      size: 8,
      font: bold,
      color: green.muted,
    });
    page.drawText(badgeValue, {
      x: pageWidth - margin - 64,
      y: y - 20,
      size: 12,
      font: bold,
      color: green.mid,
    });

    page.drawLine({
      start: { x: margin, y: y - 43 },
      end: { x: pageWidth - margin, y: y - 43 },
      thickness: 0.6,
      color: green.border,
    });

    return y - 58;
  }

  function drawLandscapeSummary({ page, bold, regular, items, y, margin, pageSize }) {
    if (!items.length) {
      return y;
    }

    const chipWidth = 180;
    const gap = 10;
    const chipsPerRow = Math.max(1, Math.floor((pageSize[0] - margin * 2 + gap) / (chipWidth + gap)));

    items.forEach((item, index) => {
      const row = Math.floor(index / chipsPerRow);
      const col = index % chipsPerRow;
      const chipX = margin + col * (chipWidth + gap);
      const chipY = y - row * 34;

      page.drawRectangle({
        x: chipX,
        y: chipY,
        width: chipWidth,
        height: 24,
        color: green.white,
        borderColor: green.border,
        borderWidth: 1,
      });
      page.drawText(`${item.label}:`, {
        x: chipX + 8,
        y: chipY + 9,
        size: 8,
        font: bold,
        color: green.dark,
      });
      page.drawText(normalizarTexto(item.value), {
        x: chipX + 68,
        y: chipY + 9,
        size: 8,
        font: regular,
        color: green.text,
      });
    });

    return y - (Math.ceil(items.length / chipsPerRow) * 34) - 8;
  }

  function drawLandscapeFooter({ page, regular, margin, pageSize, text = "Generado por TecnoBoris v2.1" }) {
    page.drawLine({
      start: { x: margin, y: 28 },
      end: { x: pageSize[0] - margin, y: 28 },
      thickness: 0.6,
      color: green.border,
    });
    page.drawText(text, {
      x: margin,
      y: 13,
      size: 8,
      font: regular,
      color: green.muted,
    });
  }

  async function crearPdf({ titulo, subtitulo, resumen = [], headers, rows, fileName }) {
    if (!window.PDFLib) {
      alert("No se pudo cargar la librería para generar PDF.");
      return;
    }

    const { PDFDocument, StandardFonts } = window.PDFLib;
    const pdfDoc = await PDFDocument.create();
    const bold = await pdfDoc.embedFont(StandardFonts.HelveticaBold);
    const regular = await pdfDoc.embedFont(StandardFonts.Helvetica);
    const logo = await cargarLogo(pdfDoc);
    const pageSize = [842, 595];
    const margin = 34;
    const rowMinHeight = 24;
    let page = pdfDoc.addPage(pageSize);
    let y = 548;

    function header() {
      page.drawRectangle({ x: margin, y: 508, width: 774, height: 54, color: green.light, borderColor: green.border, borderWidth: 1 });
      if (logo) page.drawImage(logo, { x: margin + 12, y: 516, width: 34, height: 38 });
      page.drawText("CENGICAÑA", { x: margin + 56, y: 540, size: 10, font: bold, color: green.dark });
      page.drawText(titulo, { x: margin + 56, y: 524, size: 16, font: bold, color: green.text });
      page.drawText(subtitulo, { x: margin + 56, y: 512, size: 9, font: regular, color: green.muted });
      y = 490;
    }

    function newPage() {
      page = pdfDoc.addPage(pageSize);
      header();
    }

    header();

    if (resumen.length) {
      const chipWidth = 180;
      resumen.forEach((item, index) => {
        const x = margin + (index % 4) * (chipWidth + 10);
        const chipY = y - Math.floor(index / 4) * 34;
        page.drawRectangle({ x, y: chipY, width: chipWidth, height: 24, color: green.white, borderColor: green.border, borderWidth: 1 });
        page.drawText(`${item.label}:`, { x: x + 8, y: chipY + 9, size: 8, font: bold, color: green.dark });
        page.drawText(normalizarTexto(item.value), { x: x + 70, y: chipY + 9, size: 8, font: regular, color: green.text });
      });
      y -= Math.ceil(resumen.length / 4) * 34 + 8;
    }

    const tableWidth = 774;
    const colWidth = tableWidth / headers.length;

    function drawTableHeader() {
      page.drawRectangle({ x: margin, y: y - rowMinHeight, width: tableWidth, height: rowMinHeight, color: green.light, borderColor: green.border, borderWidth: 1 });
      headers.forEach((headerText, index) => {
        page.drawText(normalizarTexto(headerText), { x: margin + index * colWidth + 7, y: y - 15, size: 8, font: bold, color: green.dark });
      });
      y -= rowMinHeight;
    }

    drawTableHeader();

    rows.forEach((row) => {
      const wrapped = row.map((cell) => wrapText(cell, regular, 8, colWidth - 14));
      const height = Math.max(rowMinHeight, Math.max(...wrapped.map((lines) => lines.length)) * 10 + 12);
      if (y - height < margin) {
        newPage();
        drawTableHeader();
      }

      page.drawRectangle({ x: margin, y: y - height, width: tableWidth, height, color: green.white, borderColor: green.border, borderWidth: 1 });
      wrapped.forEach((lines, index) => {
        lines.slice(0, 5).forEach((line, lineIndex) => {
          page.drawText(line, { x: margin + index * colWidth + 7, y: y - 15 - lineIndex * 10, size: 8, font: regular, color: green.text });
        });
      });
      y -= height;
    });

    const bytes = await pdfDoc.save();
    descargar(bytes, fileName);
  }

  async function crearPdfBoletaLote({ lote, fileName }) {
    if (!window.PDFLib) {
      alert("No se pudo cargar la libreria para generar PDF.");
      return;
    }

    const { PDFDocument, StandardFonts } = window.PDFLib;
    const pdfDoc = await PDFDocument.create();
    const bold = await pdfDoc.embedFont(StandardFonts.HelveticaBold);
    const regular = await pdfDoc.embedFont(StandardFonts.Helvetica);
    const logo = await cargarLogo(pdfDoc);
    const pageSize = [595.28, 841.89];
    const margin = 44;
    const bottom = 46;
    const contentWidth = pageSize[0] - margin * 2;
    let page = pdfDoc.addPage(pageSize);
    let y = pageSize[1] - margin;

    function addHeader(continuacion = false) {
      const logoWidth = continuacion ? 58 : 82;
      const logoX = margin;
      const logoY = y - (continuacion ? 34 : 50);
      const textX = logo ? margin + logoWidth + 18 : margin;

      if (logo) {
        const logoHeight = logoWidth * (logo.height / logo.width);
        page.drawImage(logo, { x: logoX, y: logoY, width: logoWidth, height: logoHeight });
      }

      page.drawText("Laboratorio Agroindustrial", {
        x: textX,
        y,
        size: continuacion ? 12 : 18,
        font: bold,
        color: green.dark,
      });
      page.drawText(continuacion ? "Boleta de solicitud - continuacion" : "Boleta de solicitud de analisis", {
        x: textX,
        y: y - (continuacion ? 16 : 22),
        size: continuacion ? 9 : 11,
        font: regular,
        color: green.muted,
      });

      if (!continuacion) {
        page.drawRectangle({
          x: pageSize[0] - margin - 78,
          y: y - 26,
          width: 78,
          height: 34,
          color: green.light,
          borderColor: green.border,
          borderWidth: 1,
        });
        page.drawText("VF", { x: pageSize[0] - margin - 64, y: y - 6, size: 8, font: bold, color: green.muted });
        page.drawText("005", { x: pageSize[0] - margin - 64, y: y - 20, size: 12, font: bold, color: green.mid });
      }

      y -= continuacion ? 52 : 78;
    }

    function newPage() {
      drawFooter();
      page = pdfDoc.addPage(pageSize);
      y = pageSize[1] - margin;
      addHeader(true);
    }

    function ensureSpace(needed) {
      if (y - needed < bottom) newPage();
    }

    function drawSection(title) {
      ensureSpace(32);
      page.drawText(limpiarTextoPdf(title).toUpperCase(), { x: margin, y, size: 9, font: bold, color: green.mid });
      page.drawLine({ start: { x: margin + 150, y: y + 3 }, end: { x: pageSize[0] - margin, y: y + 3 }, thickness: 0.8, color: green.border });
      y -= 20;
    }

    function drawWrapped(text, x, maxWidth, size = 9.5, font = regular, color = green.text, lineHeight = 13) {
      wrapPdfText(text, font, size, maxWidth).forEach((line) => {
        ensureSpace(lineHeight + 2);
        if (line) page.drawText(line, { x, y, size, font, color });
        y -= lineHeight;
      });
    }

    function drawInfoCell(label, value, x, topY, cellWidth) {
      page.drawRectangle({ x, y: topY - 38, width: cellWidth, height: 38, borderColor: green.border, borderWidth: 0.8 });
      page.drawText(limpiarTextoPdf(label).toUpperCase(), { x: x + 8, y: topY - 13, size: 7, font: bold, color: green.muted });
      wrapPdfText(value || "-", regular, 9.5, cellWidth - 16).slice(0, 2).forEach((line, lineIndex) => {
        page.drawText(line || "-", { x: x + 8, y: topY - 28 - lineIndex * 10, size: 9.5, font: regular, color: green.text });
      });
    }

    function drawInfoRows(rows) {
      const gap = 12;
      const cellWidth = (contentWidth - gap) / 2;

      for (let index = 0; index < rows.length; index += 2) {
        ensureSpace(46);
        const topY = y;
        drawInfoCell(rows[index][0], rows[index][1], margin, topY, cellWidth);
        if (rows[index + 1]) drawInfoCell(rows[index + 1][0], rows[index + 1][1], margin + cellWidth + gap, topY, cellWidth);
        y -= 46;
      }
    }

    async function drawSignature(title, name, email, dataUrl, x, topY, boxWidth) {
      page.drawText(limpiarTextoPdf(title).toUpperCase(), { x, y: topY, size: 8, font: bold, color: green.muted });
      page.drawText(limpiarTextoPdf(name || " "), { x, y: topY - 15, size: 9.5, font: regular, color: green.text });
      if (email && normalizarTexto(email) !== "-") {
        page.drawText(limpiarTextoPdf(email), { x, y: topY - 29, size: 8.5, font: regular, color: green.muted });
      }

      if (dataUrl) {
        try {
          const signature = await pdfDoc.embedPng(dataUrl);
          const maxImageWidth = boxWidth;
          const maxImageHeight = 54;
          const scale = Math.min(maxImageWidth / signature.width, maxImageHeight / signature.height);
          const imageWidth = signature.width * scale;
          const imageHeight = signature.height * scale;
          page.drawImage(signature, { x, y: topY - 78, width: imageWidth, height: imageHeight });
        } catch (_) {
          // La firma dibujada es opcional; si llega corrupta se mantiene la linea de firma.
        }
      }

      page.drawLine({ start: { x, y: topY - 86 }, end: { x: x + boxWidth, y: topY - 86 }, thickness: 0.8, color: green.border });
    }

    function drawFooter() {
      page.drawLine({ start: { x: margin, y: bottom + 20 }, end: { x: pageSize[0] - margin, y: bottom + 20 }, thickness: 0.6, color: green.border });
      page.drawText("Generado por TecnoBoris v2.1", { x: margin, y: bottom + 5, size: 8, font: regular, color: green.muted });
    }

    addHeader(false);

    const solicitudes = Array.isArray(lote.solicitudes) ? lote.solicitudes : [];
    if (!solicitudes.length) {
      drawSection("Datos del muestreo");
      drawInfoRows([
        ["Tipo de muestra", "-"],
        ["Numero de lote", lote.codigo_lote],
        ["Fecha de muestreo", "-"],
        ["Numero de muestras", "-"],
        ["Laboratorio inicio", lote.numeros_laboratorio],
        ["Laboratorio fin", lote.numeros_laboratorio],
        ["Fecha estimada", "-"],
      ]);
      drawSection("Analisis solicitados");
      drawWrapped("Sin analisis registrados.", margin, contentWidth, 10, regular, green.muted, 14);
      drawSection("Observaciones");
      drawWrapped("Sin observaciones.", margin, contentWidth, 10, regular, green.text, 14);
      drawSection("Responsables y firmas");
      ensureSpace(108);
      const signatureTop = y;
      const signatureWidth = (contentWidth - 24) / 2;
      await drawSignature("Ingresado por", "", "", "", margin, signatureTop, signatureWidth);
      await drawSignature("Recibido por", "", "", "", margin + signatureWidth + 24, signatureTop, signatureWidth);
      y -= 110;
    }

    for (const [index, solicitud] of solicitudes.entries()) {
      if (index > 0) newPage();
      drawSection("Datos del muestreo");
      drawInfoRows([
        ["Tipo de muestra", solicitud.tipo_muestra],
        ["Numero de lote", lote.codigo_lote],
        ["Fecha de muestreo", solicitud.fecha_muestreo],
        ["Numero de muestras", solicitud.numero_muestras],
        ["Laboratorio inicio", solicitud.laboratorio_inicio],
        ["Laboratorio fin", solicitud.laboratorio_fin],
        ["Fecha estimada", solicitud.fecha_estimada],
      ]);

      drawSection("Analisis solicitados");
      const analisis = Array.isArray(solicitud.analisis) ? solicitud.analisis : [];
      if (analisis.length) {
        analisis.forEach((item) => {
          ensureSpace(18);
          page.drawText("- ", { x: margin, y, size: 10, font: bold, color: green.mid });
          drawWrapped(item, margin + 14, contentWidth - 14, 10, regular, green.text, 14);
        });
      } else {
        drawWrapped("Sin analisis registrados.", margin, contentWidth, 10, regular, green.muted, 14);
      }

      drawSection("Observaciones");
      drawWrapped(solicitud.observaciones || "Sin observaciones.", margin, contentWidth, 10, regular, green.text, 14);

      drawSection("Responsables y firmas");
      ensureSpace(108);
      const signatureTop = y;
      const signatureWidth = (contentWidth - 24) / 2;
      await drawSignature(
        "Ingresado por",
        solicitud.ingresado_por,
        solicitud.correo_ingresado || solicitud.correo_ingresado_por,
        solicitud.firma_ingreso || "",
        margin,
        signatureTop,
        signatureWidth
      );
      await drawSignature(
        "Recibido por",
        solicitud.recibido_por,
        solicitud.correo_recibido || solicitud.correo_recibido_por,
        solicitud.firma_recibe || "",
        margin + signatureWidth + 24,
        signatureTop,
        signatureWidth
      );
      y -= 110;
    }

    drawFooter();

    const bytes = await pdfDoc.save();
    descargar(bytes, fileName);
  }

  async function crearPdfConsolidacion({ titulo, subtitulo, analisisTitulo = "Analisis solicitados", headers, rows, fileName }) {
    if (!window.PDFLib) {
      alert("No se pudo cargar la libreria para generar PDF.");
      return;
    }

    const { PDFDocument, StandardFonts, rgb } = window.PDFLib;
    const pdfDoc = await PDFDocument.create();
    const bold = await pdfDoc.embedFont(StandardFonts.HelveticaBold);
    const regular = await pdfDoc.embedFont(StandardFonts.Helvetica);
    const pageSize = [842, 595];
    const margin = 24;
    const tableWidth = pageSize[0] - margin * 2;
    const baseCols = 8;
    const baseWidths = [62, 28, 48, 62, 54, 36, 36, 44];
    const widths = headers.map((_, index) => index < baseCols ? baseWidths[index] : 42);
    widths.push(78);
    const finalHeaders = headers.concat(["Observaciones"]);
    const totalWidth = widths.reduce((sum, width) => sum + width, 0);
    const scale = Math.min(1, tableWidth / totalWidth);
    const colWidths = widths.map((width) => width * scale);
    let page = pdfDoc.addPage(pageSize);
    let y = pageSize[1] - margin;

    function drawPageHeader() {
      page.drawText("CENGICANA", { x: margin, y: y - 36, size: 14, font: bold, color: rgb(0.55, 0.55, 0.55) });
      page.drawRectangle({ x: 255, y: y - 28, width: 310, height: 22, borderColor: rgb(0, 0, 0), borderWidth: 1 });
      page.drawText(normalizarTexto(titulo).toUpperCase(), { x: 285, y: y - 21, size: 10, font: bold, color: rgb(0, 0, 0) });
      page.drawText(`${analisisTitulo}:`, { x: 328, y: y - 60, size: 9, font: regular, color: rgb(0, 0, 0) });
      page.drawRectangle({ x: 422, y: y - 66, width: 150, height: 18, borderColor: rgb(0, 0, 0), borderWidth: 1 });
      page.drawText(normalizarTexto(subtitulo), { x: 428, y: y - 61, size: 8, font: regular, color: rgb(0, 0, 0) });
      y -= 92;
    }

    function drawTableHeader() {
      const headerTop = y;
      let x = margin;
      page.drawRectangle({ x, y: headerTop - 32, width: tableWidth, height: 32, borderColor: rgb(0, 0, 0), borderWidth: 1 });
      const chemicalX = margin + colWidths.slice(0, baseCols).reduce((sum, width) => sum + width, 0);
      const chemicalWidth = colWidths.slice(baseCols, finalHeaders.length - 1).reduce((sum, width) => sum + width, 0);
      if (chemicalWidth > 0) {
        page.drawText("QUIMICO", { x: chemicalX + Math.max(3, chemicalWidth / 2 - 18), y: headerTop - 10, size: 7, font: bold, color: rgb(0, 0, 0) });
      }
      finalHeaders.forEach((headerText, index) => {
        const width = colWidths[index];
        page.drawRectangle({ x, y: headerTop - 32, width, height: 32, borderColor: rgb(0, 0, 0), borderWidth: 0.7 });
        page.drawText(normalizarTexto(headerText).slice(0, 12), { x: x + 3, y: headerTop - 25, size: 6.2, font: bold, color: rgb(0, 0, 0) });
        x += width;
      });
      y -= 32;
    }

    function newPage() {
      page = pdfDoc.addPage(pageSize);
      y = pageSize[1] - margin;
      drawPageHeader();
      drawTableHeader();
    }

    drawPageHeader();
    drawTableHeader();

    (rows.length ? rows : [["-", "-", "-", "-", "-"]]).forEach((row) => {
      const pdfRow = row.concat([""]);
      const rowHeight = 18;
      if (y - rowHeight < margin) newPage();
      let x = margin;
      pdfRow.forEach((cell, index) => {
        const width = colWidths[index];
        const value = normalizarTexto(cell).replace("Si, completado", "Si").replace("Si", "SI");
        const color = value === "SI" ? rgb(1, 0.95, 0.2) : green.white;
        page.drawRectangle({ x, y: y - rowHeight, width, height: rowHeight, color, borderColor: rgb(0, 0, 0), borderWidth: 0.5 });
        page.drawText(value.slice(0, 14), { x: x + 3, y: y - 12, size: 6.5, font: regular, color: rgb(0, 0, 0) });
        x += width;
      });
      y -= rowHeight;
    });

    const bytes = await pdfDoc.save();
    descargar(bytes, fileName);
  }

  window.LabPdfTablas = { crearPdf, crearPdfBoletaLote, crearPdfConsolidacion, nombreArchivo, normalizarTexto };
})();
