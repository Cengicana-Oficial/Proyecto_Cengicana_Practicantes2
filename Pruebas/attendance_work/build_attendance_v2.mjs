import fs from "node:fs/promises";
import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";

const inputPath = "C:/Users/acapa/Downloads/Curso_ Investigaci\u00f3n Cient\u00edfica Aplicada a la Agroindustria_ Escritura y Publicaci\u00f3n de Art\u00edculos Cient\u00edficos - Informe de asistencia 7-07-26 (1).csv.xlsx";
const outputDir = "C:/xampp/htdocs/EncuestasSatisfacci\u00f3n/outputs/listado_asistencia_curso";
const outputPath = `${outputDir}/Listado_de_asistencia_curso.xlsx`;

function normalizeName(value) {
  return value.replace(/^CENGICA\u00d1A\s*-\s*/iu, "").replace(/^(?:Ing(?:a)?\.?|Lic\.?|Dr\.?)\s+/iu, "").replace(/\s+/g, " ").trim().normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLocaleLowerCase("es");
}
function displayName(value) {
  return value.replace(/^CENGICA\u00d1A\s*-\s*/iu, "").replace(/^(?:Ing(?:a)?\.?|Lic\.?|Dr\.?)\s+/iu, "").replace(/\s+/g, " ").trim();
}

const blob = await FileBlob.load(inputPath);
const workbook = await SpreadsheetFile.importXlsx(blob);
const sourceValues = workbook.worksheets.getItemAt(0).getUsedRange(true).values;
const sessionDates = sourceValues[0].map((value) => String(value ?? "").trim());
const participants = new Map();
for (let sessionIndex = 0; sessionIndex < sessionDates.length; sessionIndex += 1) {
  for (let rowIndex = 1; rowIndex < sourceValues.length; rowIndex += 1) {
    const raw = sourceValues[rowIndex][sessionIndex];
    if (typeof raw !== "string") continue;
    const name = raw.trim();
    if (!name || /\(No comprobado\)/iu.test(name) || /^read\.ai\b/iu.test(name)) continue;
    const key = normalizeName(name);
    if (!participants.has(key)) participants.set(key, { name: displayName(name), attendance: Array(sessionDates.length).fill("") });
    participants.get(key).attendance[sessionIndex] = "P";
  }
}
const roster = [...participants.values()].sort((a, b) => a.name.localeCompare(b.name, "es"));
const sheet = workbook.worksheets.add("Listado de asistencia");
sheet.showGridLines = false;
const startRow = 4;
const endRow = startRow + roster.length - 1;
const totalRow = endRow + 1;

sheet.mergeCells("A1:L1");
sheet.getRange("A1").values = [["Listado de asistencia"]];
sheet.getRange("A2").values = [["Curso: Investigaci\u00f3n Cient\u00edfica Aplicada a la Agroindustria - Escritura y Publicaci\u00f3n de Art\u00edculos Cient\u00edficos"]];
sheet.getRange("K2:L2").values = [["Sesiones programadas", sessionDates.length]];
sheet.getRange("A3:L3").values = [["No.", "Participante", ...sessionDates, "Sesiones asistidas", "% de asistencia"]];
const dataRows = roster.map((participant, index) => [index + 1, participant.name, ...participant.attendance]);
sheet.getRange(`A${startRow}:J${endRow}`).values = dataRows;
sheet.getRange(`K${startRow}`).formulas = [[`=COUNTIF(C${startRow}:J${startRow},"P")`]];
sheet.getRange(`K${startRow}:K${endRow}`).fillDown();
sheet.getRange(`L${startRow}`).formulas = [[`=K${startRow}/$L$2`]];
sheet.getRange(`L${startRow}:L${endRow}`).fillDown();
sheet.getRange(`A${totalRow}:J${totalRow}`).merge();
sheet.getRange(`A${totalRow}`).values = [["Promedio general de asistencia"]];
sheet.getRange(`K${totalRow}`).formulas = [[`=SUM(K${startRow}:K${endRow})`]];
sheet.getRange(`L${totalRow}`).formulas = [[`=AVERAGE(L${startRow}:L${endRow})`]];
sheet.getRange(`A${totalRow + 2}:L${totalRow + 2}`).merge();
sheet.getRange(`A${totalRow + 2}`).values = [["Nota: se excluyeron los registros identificados como No comprobado y read.ai meeting notes. P = presencia registrada."]];

sheet.getRange("A1:L1").format = { fill: "#1F4E78", font: { bold: true, color: "#FFFFFF", size: 16 }, horizontalAlignment: "center", verticalAlignment: "center" };
sheet.getRange("A2:L2").format = { fill: "#D9EAF7", font: { italic: true, color: "#1F2937" }, verticalAlignment: "center" };
sheet.getRange("K2:L2").format = { fill: "#B7DEE8", font: { bold: true, color: "#17365D" }, horizontalAlignment: "center", verticalAlignment: "center" };
sheet.getRange("A3:L3").format = { fill: "#5B9BD5", font: { bold: true, color: "#FFFFFF" }, horizontalAlignment: "center", verticalAlignment: "center", wrapText: true, borders: { preset: "all", style: "thin", color: "#D9E2F3" } };
sheet.getRange(`A${startRow}:L${endRow}`).format = { borders: { preset: "inside", style: "thin", color: "#D9E2F3" }, verticalAlignment: "center" };
sheet.getRange(`A${startRow}:A${endRow}`).format.horizontalAlignment = "center";
sheet.getRange(`C${startRow}:K${endRow}`).format.horizontalAlignment = "center";
sheet.getRange(`L${startRow}:L${endRow}`).format = { numberFormat: "0%", horizontalAlignment: "center" };
sheet.getRange(`A${totalRow}:L${totalRow}`).format = { fill: "#D9EAD3", font: { bold: true, color: "#274E13" }, borders: { preset: "outside", style: "medium", color: "#70AD47" }, verticalAlignment: "center" };
sheet.getRange(`L${totalRow}`).format.numberFormat = "0%";
sheet.getRange(`A${totalRow + 2}:L${totalRow + 2}`).format = { font: { italic: true, color: "#666666", size: 9 }, wrapText: true };
sheet.getRange(`C${startRow}:J${endRow}`).conditionalFormats.add("containsText", { text: "P", format: { fill: "#E2F0D9", font: { bold: true, color: "#2E7D32" } } });
sheet.getRange(`L${startRow}:L${endRow}`).conditionalFormats.add("cellIs", { operator: "greaterThanOrEqual", formula: 0.75, format: { fill: "#E2F0D9", font: { bold: true, color: "#2E7D32" } } });
sheet.getRange(`L${startRow}:L${endRow}`).conditionalFormats.add("cellIs", { operator: "lessThan", formula: 0.75, format: { fill: "#FCE4D6", font: { color: "#C00000" } } });
sheet.getRange("A1:L1").format.rowHeight = 28;
sheet.getRange("A2:L2").format.rowHeight = 24;
sheet.getRange("A3:L3").format.rowHeight = 34;
sheet.getRange(`A${startRow}:L${endRow}`).format.rowHeight = 20;
sheet.getRange(`A${totalRow + 2}:L${totalRow + 2}`).format.rowHeight = 28;
sheet.getRange("A:A").format.columnWidth = 7;
sheet.getRange("B:B").format.columnWidth = 32;
sheet.getRange("C:J").format.columnWidth = 14;
sheet.getRange("K:K").format.columnWidth = 18;
sheet.getRange("L:L").format.columnWidth = 16;
sheet.freezePanes.freezeRows(3);
sheet.freezePanes.freezeColumns(2);
sheet.tables.add(`A3:L${endRow}`, true, "ListadoAsistencia");

const summary = await workbook.inspect({ kind: "table", range: `Listado de asistencia!A1:L${totalRow + 2}`, include: "values,formulas", tableMaxRows: 80, tableMaxCols: 12 });
console.log(summary.ndjson);
const errors = await workbook.inspect({ kind: "match", searchTerm: "#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A", options: { useRegex: true, maxResults: 100 }, summary: "formula errors" });
console.log(errors.ndjson);
await fs.mkdir(outputDir, { recursive: true });
const preview = await workbook.render({ sheetName: "Listado de asistencia", range: `A1:L${totalRow + 2}`, scale: 1.2, format: "png" });
await fs.writeFile(`${outputDir}/preview.png`, new Uint8Array(await preview.arrayBuffer()));
const output = await SpreadsheetFile.exportXlsx(workbook);
await output.save(outputPath);
console.log(JSON.stringify({ outputPath, participants: roster.length, sessions: sessionDates.length, totalRow }));
