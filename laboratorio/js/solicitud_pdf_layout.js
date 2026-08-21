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
      page.drawRectangle({
        x: 0,
        y: height - 8,
        width,
        height: 8,
        color: colors.brand,
      });

      if (continuation) {
        drawLogo(margin, height - 24, 34, 30);
        page.drawText("CENGICAÑA", {
          x: margin + 44,
          y: height - 33,
          size: 11,
          font: boldFont,
          color: colors.brandDark,
        });
        page.drawText("Boleta de solicitud de analisis - continuacion", {
          x: margin + 44,
          y: height - 47,
          size: 8.5,
          font: regularFont,
          color: colors.muted,
        });
        page.drawLine({
          start: { x: margin, y: height - 61 },
          end: { x: width - margin, y: height - 61 },
          thickness: 0.8,
          color: colors.border,
        });
        y = height - 80;
        return;
      }

      drawLogo(margin, height - 22, 54, 50);
      page.drawText("CENGICAÑA", {
        x: margin + 66,
        y: height - 37,
        size: 15,
        font: boldFont,
        color: colors.brandDark,
      });
      page.drawText("Laboratorio Agroindustrial", {
        x: margin + 66,
        y: height - 53,
        size: 9,
        font: regularFont,
        color: colors.muted,
      });
      page.drawText("BOLETA DE SOLICITUD DE ANALISIS", {
        x: margin + 66,
        y: height - 72,
        size: 10.5,
        font: boldFont,
        color: colors.ink,
      });

      const documentBoxWidth = 96;
      const documentBoxX = width - margin - documentBoxWidth;
      page.drawRectangle({
        x: documentBoxX,
        y: height - 75,
        width: documentBoxWidth,
        height: 48,
        color: colors.pale,
        borderColor: colors.border,
        borderWidth: 0.8,
      });
      page.drawText("DOCUMENTO", {
        x: documentBoxX + 12,
        y: height - 43,
        size: 7,
        font: boldFont,
        color: colors.muted,
      });
      page.drawText("VF-005", {
        x: documentBoxX + 12,
        y: height - 62,
        size: 13,
        font: boldFont,
        color: colors.brandDark,
      });

      page.drawLine({
        start: { x: margin, y: height - 88 },
        end: { x: width - margin, y: height - 88 },
        thickness: 1,
        color: colors.brand,
      });
      y = height - 108;
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
      const heroHeight = 80;
      page.drawRectangle({
        x: margin,
        y: y - heroHeight,
        width: contentWidth,
        height: heroHeight,
        color: colors.brandDark,
      });
      page.drawRectangle({
        x: margin,
        y: y - heroHeight,
        width: 7,
        height: heroHeight,
        color: colors.lime,
      });

      page.drawText("LOTE", {
        x: margin + 22,
        y: y - 20,
        size: 7.5,
        font: boldFont,
        color: colors.paleStrong,
      });
      page.drawText(textoOGuion(datos.lote), {
        x: margin + 22,
        y: y - 47,
        size: 22,
        font: boldFont,
        color: colors.white,
      });

      const typeText = textoOGuion(datos.tipo).toUpperCase();
      const typeSize = 8;
      const typeWidth = Math.min(180, boldFont.widthOfTextAtSize(typeText, typeSize) + 22);
      page.drawRectangle({
        x: width - margin - typeWidth - 14,
        y: y - 27,
        width: typeWidth,
        height: 20,
        color: colors.brand,
      });
      page.drawText(typeText, {
        x: width - margin - typeWidth - 3,
        y: y - 21,
        size: typeSize,
        font: boldFont,
        color: colors.white,
      });

      const summaryX = margin + 220;
      const summaryY = y - 47;
      const drawSummary = (label, value, x) => {
        page.drawText(label, {
          x,
          y: summaryY + 13,
          size: 6.5,
          font: boldFont,
          color: colors.paleStrong,
        });
        page.drawText(textoOGuion(value), {
          x,
          y: summaryY - 2,
          size: 9.5,
          font: boldFont,
          color: colors.white,
        });
      };
      drawSummary("MUESTRAS", datos.numeroMuestras, summaryX);
      drawSummary("RANGO DE LABORATORIO", `${textoOGuion(datos.numeroLaboratorioInicio)} a ${textoOGuion(datos.numeroLaboratorioFin)}`, summaryX + 84);
      y -= heroHeight + 25;
    };

    const drawInfoGrid = rows => {
      const gap = 10;
      const cellWidth = (contentWidth - gap) / 2;
      const cellHeight = 43;

      rows.forEach((row, index) => {
        if (index % 2 === 0) ensureSpace(cellHeight + 9);
        const column = index % 2;
        const rowTop = y;
        const x = margin + column * (cellWidth + gap);

        page.drawRectangle({
          x,
          y: rowTop - cellHeight,
          width: cellWidth,
          height: cellHeight,
          color: colors.surface,
          borderColor: colors.border,
          borderWidth: 0.6,
        });
        page.drawText(limpiarTexto(row[0]).toUpperCase(), {
          x: x + 11,
          y: rowTop - 14,
          size: 6.5,
          font: boldFont,
          color: colors.muted,
        });

        const valueLines = envolverTexto(textoOGuion(row[1]), regularFont, 9.5, cellWidth - 22).slice(0, 2);
        valueLines.forEach((line, lineIndex) => {
          page.drawText(line, {
            x: x + 11,
            y: rowTop - 30 - lineIndex * 10,
            size: 9.5,
            font: regularFont,
            color: colors.ink,
          });
        });

        if (column === 1 || index === rows.length - 1) y -= cellHeight + 9;
      });
    };

    const drawAnalysisHeader = () => {
      const headerHeight = 24;
      page.drawRectangle({
        x: margin,
        y: y - headerHeight,
        width: contentWidth,
        height: headerHeight,
        color: colors.brandDark,
      });
      page.drawText("ANALISIS", {
        x: margin + 12,
        y: y - 16,
        size: 7.5,
        font: boldFont,
        color: colors.white,
      });
      page.drawText("CATEGORIA", {
        x: margin + 382,
        y: y - 16,
        size: 7.5,
        font: boldFont,
        color: colors.white,
      });
      y -= headerHeight;
    };

    const drawAnalyses = () => {
      const analyses = Array.isArray(datos.analisis) && datos.analisis.length
        ? datos.analisis
        : [{ nombre: "Sin analisis seleccionados", tipo: "-" }];

      drawAnalysisHeader();
      analyses.forEach((item, index) => {
        const nameLines = envolverTexto(textoOGuion(item.nombre), regularFont, 8.8, 350);
        const typeLines = envolverTexto(textoOGuion(item.tipo), regularFont, 8.2, 108);
        const rowHeight = Math.max(28, Math.max(nameLines.length, typeLines.length) * 11 + 12);

        if (y - rowHeight < contentBottom) {
          newPage(true);
          drawSectionTitle("Analisis solicitados");
          drawAnalysisHeader();
        }

        page.drawRectangle({
          x: margin,
          y: y - rowHeight,
          width: contentWidth,
          height: rowHeight,
          color: index % 2 === 0 ? colors.white : colors.surface,
          borderColor: colors.border,
          borderWidth: 0.45,
        });
        page.drawRectangle({
          x: margin + 10,
          y: y - 17,
          width: 5,
          height: 5,
          color: colors.brand,
        });
        nameLines.forEach((line, lineIndex) => {
          page.drawText(line, {
            x: margin + 24,
            y: y - 18 - lineIndex * 11,
            size: 8.8,
            font: regularFont,
            color: colors.ink,
          });
        });
        typeLines.forEach((line, lineIndex) => {
          page.drawText(line, {
            x: margin + 382,
            y: y - 18 - lineIndex * 10,
            size: 8.2,
            font: regularFont,
            color: colors.muted,
          });
        });
        y -= rowHeight;
      });
      y -= 18;
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
    drawSectionTitle("Datos de la solicitud");
    drawInfoGrid([
      ["Tipo de muestra", datos.tipo],
      ["Cliente / institucion", datos.institucion],
      ["Responsable del envio", datos.responsableEnvio],
      ["Codigo de muestreo", datos.codigoMuestreo],
      ["Fecha de muestreo", formatearFecha(datos.fechaMuestreo)],
      ["Fecha de ingreso", formatearFecha(datos.fechaIngreso)],
      ["Fecha estimada", formatearFecha(datos.fechaEstimada)],
      ["Estado de recepcion", "Recibida"],
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
