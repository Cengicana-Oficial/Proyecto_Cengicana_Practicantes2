import fs from "node:fs/promises";
import { FileBlob, SpreadsheetFile } from "@oai/artifact-tool";

const inputPath = "C:/Users/acapa/Downloads/Curso_ Investigación Científica Aplicada a la Agroindustria_ Escritura y Publicación de Artículos Científicos - Informe de asistencia 7-07-26 (1).csv.xlsx";
const blob = await FileBlob.load(inputPath);
const workbook = await SpreadsheetFile.importXlsx(blob);

const summary = await workbook.inspect({
  kind: "workbook,sheet,table,region",
  maxChars: 16000,
  tableMaxRows: 20,
  tableMaxCols: 30,
  tableMaxCellChars: 120,
});
console.log(summary.ndjson);

const sheets = await workbook.inspect({ kind: "sheet", include: "id,name" });
console.log(sheets.ndjson);
