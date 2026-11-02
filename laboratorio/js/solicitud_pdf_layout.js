(function (root, factory) {
  const api = factory();

  if (typeof module === "object" && module.exports) {
    module.exports = api;
  }

  if (root) {
    root.CengiSolicitudPdf = api;
  }
})(typeof window !== "undefined" ? window : globalThis, function () {
  "use strict";

  function limpiarTexto(value) {
    return String(value ?? "")
      .replace(/\r/g, "")
      .replace(/[\u2010-\u2015]/g, "-")
      .replace(/[\u2018\u2019]/g, "'")
      .replace(/[\u201c\u201d]/g, '"')
      .replace(/[^\n\x20-\x7e\xa0-\xff]/g, "")
      .trim();
  }

  function textoOGuion(value) {
    return limpiarTexto(value) || "-";
  }

  function formatearFecha(value) {
    const text = limpiarTexto(value);
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text);
    return match ? `${match[3]}/${match[2]}/${match[1]}` : (text || "-");
  }

  function envolverTexto(text, font, size, maxWidth) {
    const lines = [];
    const paragraphs = limpiarTexto(text).split("\n");

    paragraphs.forEach(paragraph => {
      const words = paragraph.split(/\s+/).filter(Boolean);
      if (!words.length) {
        lines.push("");
        return;
      }

      let line = "";
      words.forEach(word => {
        const candidate = line ? `${line} ${word}` : word;
        if (font.widthOfTextAtSize(candidate, size) <= maxWidth) {
          line = candidate;
          return;
        }

        if (line) lines.push(line);

        if (font.widthOfTextAtSize(word, size) <= maxWidth) {
          line = word;
          return;
        }

        let fragment = "";
        Array.from(word).forEach(character => {
          const fragmentCandidate = fragment + character;
          if (font.widthOfTextAtSize(fragmentCandidate, size) <= maxWidth) {
            fragment = fragmentCandidate;
          } else {
            if (fragment) lines.push(fragment);
            fragment = character;
          }
        });
        line = fragment;
      });

      if (line) lines.push(line);
    });

    return lines;
  }

  async function crearPdfSolicitud({ pdfDoc, PDFLib, datos, logo = null }) {
    if (!pdfDoc || !PDFLib || !datos) {
      throw new Error("No se recibieron los datos necesarios para construir la boleta.");
    }

    const { StandardFonts, rgb } = PDFLib;
    const regularFont = await pdfDoc.embedFont(StandardFonts.Helvetica);
    const boldFont = await pdfDoc.embedFont(StandardFonts.HelveticaBold);
    const pageSize = [595.28, 841.89];
    const width = pageSize[0];
    const height = pageSize[1];
    const margin = 40;
    const contentWidth = width - margin * 2;
    const contentBottom = 55;

    const colors = {
      brand: rgb(0.45, 0.75, 0.24),
      brandDark: rgb(0.12, 0.30, 0.16),
      brandDeep: rgb(0.08, 0.22, 0.12),
      lime: rgb(0.66, 0.83, 0.18),
      pale: rgb(0.95, 0.98, 0.93),
      paleStrong: rgb(0.90, 0.95, 0.86),
      ink: rgb(0.12, 0.16, 0.19),
      muted: rgb(0.38, 0.43, 0.46),
      border: rgb(0.82, 0.87, 0.79),
      surface: rgb(0.975, 0.98, 0.97),
      white: rgb(1, 1, 1),
    };

    let qr = null;
    if (datos.idSolicitud && typeof globalThis.qrcode === "function") {
      qr = globalThis.qrcode(0, "M");
      qr.addData(["SIGELAB", datos.lote, datos.numeroLaboratorioInicio, datos.numeroLaboratorioFin, datos.idSolicitud].join("|"));
      qr.make();
    }
    let page;
    let y;

    const drawLogo = (x, topY, boxWidth, boxHeight) => {
      if (!logo) return;

      const scale = Math.min(boxWidth / logo.width, boxHeight / logo.height);
      const imageWidth = logo.width * scale;
      const imageHeight = logo.height * scale;
      page.drawImage(logo, {
        x: x + (boxWidth - imageWidth) / 2,
        y: topY - boxHeight + (boxHeight - imageHeight) / 2,
        width: imageWidth,
        height: imageHeight,
      });
    };

    const addHeader = continuation => {
      drawLogo(margin, height - 26, 42, 42);
      page.drawText("CENGICAÑA", { x: margin + 52, y: height - 40, size: 14, font: boldFont, color: colors.brandDark });
      page.drawText("Laboratorio Agroindustrial", { x: margin + 52, y: height - 55, size: 8.5, font: regularFont, color: colors.muted });
      const metaX = width - margin - (qr ? 200 : 140);
      [
        ["Codigo", "CG-R-SLA-001"],
        ["Version", "001"],
        ["Fecha", formatearFecha(datos.fechaIngreso)],
      ].forEach(([label, value], index) => {
        const top = height - 32 - index * 13;
        page.drawText(label, { x: metaX, y: top, size: 7.5, font: regularFont, color: colors.muted });
        page.drawText(textoOGuion(value), { x: metaX + 47, y: top, size: 7.5, font: boldFont, color: colors.ink });
      });
      if (qr) {
        const cell = 46 / (qr.getModuleCount() + 8);
        for (let row = 0; row < qr.getModuleCount(); row++) {
          for (let col = 0; col < qr.getModuleCount(); col++) {
            if (qr.isDark(row, col)) page.drawRectangle({
              x: width - margin - 46 + (col + 4) * cell,
              y: height - 24 - (row + 5) * cell,
              width: cell, height: cell, color: rgb(0, 0, 0),
            });
          }
        }
      }
      page.drawLine({ start: { x: margin, y: height - 78 }, end: { x: width - margin, y: height - 78 }, thickness: 1, color: colors.brand });
      const titulo = "Registro de recepcion de muestras" + (continuation ? " - continuacion" : "");
      page.drawText(titulo, { x: (width - boldFont.widthOfTextAtSize(titulo, 12)) / 2, y: height - 101, size: 12, font: boldFont, color: colors.ink });
      const estado = datos.preview ? "Vista previa - cambios sin guardar" : "Recepcion registrada";
      page.drawText(estado, { x: (width - regularFont.widthOfTextAtSize(estado, 8)) / 2, y: height - 116, size: 8, font: regularFont, color: colors.muted });
      y = height - 140;
    };
    const newPage = continuation => {
      page = pdfDoc.addPage(pageSize);
      addHeader(continuation);
    };

    const ensureSpace = needed => {
      if (y - needed < contentBottom) {
        newPage(true);
        return true;
      }
      return false;
    };

    const drawSectionTitle = title => {
      ensureSpace(29);
      page.drawRectangle({
        x: margin,
        y: y - 2,
        width: 4,
        height: 14,
        color: colors.brand,
      });
      page.drawText(limpiarTexto(title).toUpperCase(), {
        x: margin + 12,
        y,
        size: 9,
        font: boldFont,
        color: colors.brandDark,
      });
      y -= 22;
    };

    const drawHero = () => {
      page.drawRectangle({ x: margin, y: y - 82, width: contentWidth, height: 82, color: colors.pale, borderColor: colors.border, borderWidth: 0.6 });
      const claves = [["Numero de lote", datos.lote], ["Tipo de muestra", datos.tipo], ["Muestras", datos.numeroMuestras]];
      claves.forEach(([label, value], index) => {
        const x = margin + 12 + index * (contentWidth / 3);
        page.drawText(label, { x, y: y - 16, size: 7, font: regularFont, color: colors.muted });
        const text = textoOGuion(value);
        const size = Math.min(10, (contentWidth / 3 - 20) / Math.max(1, boldFont.widthOfTextAtSize(text, 1)));
        page.drawText(text, { x, y: y - 32, size, font: boldFont, color: colors.ink });
      });
      page.drawText("Numero de laboratorio", { x: margin + 12, y: y - 53, size: 7, font: regularFont, color: colors.muted });
      page.drawText(textoOGuion(datos.numeroLaboratorioInicio) + " a " + textoOGuion(datos.numeroLaboratorioFin), { x: margin + 12, y: y - 68, size: 9, font: boldFont, color: colors.ink });
      y -= 105;
    };
    const drawInfoGrid = rows => {
      const cellWidth = (contentWidth - 16) / 2;
      for (let index = 0; index < rows.length; index += 2) {
        const pair = rows.slice(index, index + 2).map(([label, value]) => ({
          label, lines: envolverTexto(textoOGuion(value), regularFont, 9, cellWidth - 12),
        }));
        const rowHeight = Math.max(...pair.map(cell => cell.lines.length * 12 + 24));
        ensureSpace(rowHeight);
        pair.forEach((cell, column) => {
          const x = margin + column * (cellWidth + 16);
          page.drawText(cell.label, { x, y: y - 8, size: 7.5, font: regularFont, color: colors.muted });
          cell.lines.forEach((line, i) => page.drawText(line, { x, y: y - 23 - i * 12, size: 9, font: regularFont, color: colors.ink }));
        });
        y -= rowHeight;
      }
      y -= 10;
    };

    const drawAnalyses = () => {
      const analyses = Array.isArray(datos.analisis) && datos.analisis.length ? datos.analisis : [];
      if (!analyses.length) {
        page.drawText("Sin analisis seleccionados.", { x: margin, y, size: 9, font: regularFont, color: colors.muted });
        y -= 25;
        return;
      }
      const cellWidth = (contentWidth - 14) / 2;
      for (let index = 0; index < analyses.length; index += 2) {
        const pair = analyses.slice(index, index + 2).map(item => envolverTexto(textoOGuion(item.nombre), regularFont, 9, cellWidth - 22));
        const rowHeight = Math.max(...pair.map(lines => lines.length * 12 + 8));
        if (ensureSpace(rowHeight + 10)) drawSectionTitle("Analisis solicitados - continuacion");
        pair.forEach((lines, column) => {
          const x = margin + column * (cellWidth + 14);
          page.drawRectangle({ x, y: y - 9, width: 8, height: 8, borderColor: colors.brand, borderWidth: 0.7 });
          page.drawLine({ start: { x: x + 1.5, y: y - 5 }, end: { x: x + 3.5, y: y - 7 }, thickness: 0.8, color: colors.brandDark });
          page.drawLine({ start: { x: x + 3.5, y: y - 7 }, end: { x: x + 7, y: y - 2.5 }, thickness: 0.8, color: colors.brandDark });
          lines.forEach((line, i) => page.drawText(line, { x: x + 16, y: y - 8 - i * 12, size: 9, font: regularFont, color: colors.ink }));
        });
        y -= rowHeight;
      }
      y -= 15;
    };
    const drawObservations = () => {
      const pendingLines = envolverTexto(datos.observaciones || "Sin observaciones.", regularFont, 8.8, contentWidth - 28);
      let firstChunk = true;

      while (pendingLines.length) {
        ensureSpace(76);
        drawSectionTitle(firstChunk ? "Observaciones" : "Observaciones - continuacion");

        const availableLines = Math.max(1, Math.floor((y - contentBottom - 24) / 12));
        const chunk = pendingLines.splice(0, availableLines);
        const boxHeight = Math.max(42, chunk.length * 12 + 24);

        page.drawRectangle({
          x: margin,
          y: y - boxHeight,
          width: contentWidth,
          height: boxHeight,
          color: colors.pale,
          borderColor: colors.border,
          borderWidth: 0.6,
        });
        chunk.forEach((line, index) => {
          page.drawText(line, {
            x: margin + 14,
            y: y - 21 - index * 12,
            size: 8.8,
            font: regularFont,
            color: colors.ink,
          });
        });
        y -= boxHeight + (pendingLines.length ? 0 : 20);
        firstChunk = false;
      }
    };

    const embedSignature = async dataUrl => {
      if (!dataUrl) return null;
      try {
        return await pdfDoc.embedPng(dataUrl);
      } catch {
        return null;
      }
    };

    const drawSignatures = async () => {
      const cardHeight = 116;
      ensureSpace(cardHeight + 32);
      drawSectionTitle("Responsables y firmas");

      const signatureInputs = [
        {
          role: "INGRESADO POR",
          name: datos.ingresadoPor,
          email: datos.correoIngresadoPor,
          image: await embedSignature(datos.firmaIngreso),
        },
        {
          role: "RECIBIDO POR",
          name: datos.recibidoPor,
          email: datos.correoRecibidoPor,
          image: await embedSignature(datos.firmaRecibe),
        },
      ];
      const gap = 14;
      const cardWidth = (contentWidth - gap) / 2;

      signatureInputs.forEach((signature, index) => {
        const x = margin + index * (cardWidth + gap);
        page.drawRectangle({
          x,
          y: y - cardHeight,
          width: cardWidth,
          height: cardHeight,
          color: colors.white,
          borderColor: colors.border,
          borderWidth: 0.7,
        });
        page.drawRectangle({
          x,
          y: y - 24,
          width: cardWidth,
          height: 24,
          color: colors.pale,
        });
        page.drawText(signature.role, {
          x: x + 12,
          y: y - 16,
          size: 7.2,
          font: boldFont,
          color: colors.brandDark,
        });

        if (signature.image) {
          const maxWidth = cardWidth - 36;
          const maxHeight = 42;
          const scale = Math.min(maxWidth / signature.image.width, maxHeight / signature.image.height);
          const imageWidth = signature.image.width * scale;
          const imageHeight = signature.image.height * scale;
          page.drawImage(signature.image, {
            x: x + (cardWidth - imageWidth) / 2,
            y: y - 74 + (42 - imageHeight) / 2,
            width: imageWidth,
            height: imageHeight,
          });
        } else {
          page.drawText("Firma pendiente", {
            x: x + 12,
            y: y - 56,
            size: 7.5,
            font: regularFont,
            color: colors.muted,
          });
        }

        page.drawLine({
          start: { x: x + 12, y: y - 78 },
          end: { x: x + cardWidth - 12, y: y - 78 },
          thickness: 0.6,
          color: colors.border,
        });

        const nameLines = envolverTexto(textoOGuion(signature.name), boldFont, 8.5, cardWidth - 24).slice(0, 1);
        page.drawText(nameLines[0] || "-", {
          x: x + 12,
          y: y - 94,
          size: 8.5,
          font: boldFont,
          color: colors.ink,
        });
        const emailLines = envolverTexto(textoOGuion(signature.email), regularFont, 7.3, cardWidth - 24).slice(0, 1);
        page.drawText(emailLines[0] || "-", {
          x: x + 12,
          y: y - 108,
          size: 7.3,
          font: regularFont,
          color: colors.muted,
        });
      });
      y -= cardHeight + 12;
    };

    const drawFooters = () => {
      const pages = pdfDoc.getPages();
      pages.forEach((pdfPage, index) => {
        pdfPage.drawLine({
          start: { x: margin, y: 37 },
          end: { x: width - margin, y: 37 },
          thickness: 0.55,
          color: colors.border,
        });
        pdfPage.drawText("Km 92.5 Carretera a Santa Lucia Cotzumalguapa, Escuintla, Guatemala", {
          x: margin,
          y: 23,
          size: 6.8,
          font: regularFont,
          color: colors.muted,
        });
        const pageText = `Pagina ${index + 1} de ${pages.length}`;
        pdfPage.drawText(pageText, {
          x: width - margin - regularFont.widthOfTextAtSize(pageText, 6.8),
          y: 23,
          size: 6.8,
          font: regularFont,
          color: colors.muted,
        });
      });
    };

    newPage(false);
    drawHero();
    drawSectionTitle("Datos del muestreo");
    drawInfoGrid([
      ["Cliente / institucion", datos.institucion],
      ["Responsable del envio", datos.responsableEnvio],
      ["Nombre del ensayo", datos.codigoMuestreo],
      ["Fecha de muestreo", formatearFecha(datos.fechaMuestreo)],
      ["Fecha de ingreso", formatearFecha(datos.fechaIngreso)],
      ["Fecha estimada", formatearFecha(datos.fechaEstimada)],

    ]);
    y -= 5;
    drawSectionTitle("Analisis solicitados");
    drawAnalyses();
    drawObservations();
    await drawSignatures();
    drawFooters();

    return pdfDoc.save();
  }

  return {
    crearPdfSolicitud,
    envolverTexto,
    limpiarTexto,
  };
});
