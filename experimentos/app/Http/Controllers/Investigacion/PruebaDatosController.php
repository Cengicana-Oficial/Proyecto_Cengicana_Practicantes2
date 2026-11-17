<?php

namespace App\Http\Controllers\Investigacion;

use App\Http\Controllers\Controller;
use App\Models\Ensayo;
use App\Models\PruebaDatos;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Datos de una prueba tecnica: una tabla libre (columnas definidas por el usuario y filas),
 * importable y exportable en Excel, con las columnas X / Y de la grafica de calibracion.
 */
class PruebaDatosController extends Controller
{
    const MAX_COLUMNAS = 30;
    const MAX_FILAS = 5000;

    /** Columnas sugeridas en la plantilla segun el subtipo de la prueba. */
    const SUGERIDAS = [
        'Calibración de sensor o equipo' => ['Muestra', 'Fecha', 'Lectura del sensor', 'Valor de laboratorio (%)', 'Observaciones'],
        'Prueba de producto o material' => ['Muestra', 'Tratamiento o dosis', 'Fecha', 'Valor medido', 'Observaciones'],
    ];

    public function guardar(Request $request, Ensayo $ensayo)
    {
        Gate::authorize('register_evaluacion');
        $this->autorizar($ensayo);

        $data = $request->validate([
            'columnas' => 'present|array|max:' . self::MAX_COLUMNAS,
            'columnas.*.clave' => ['required', 'string', 'regex:/^[a-z0-9_]{1,40}$/', 'distinct'],
            'columnas.*.nombre' => 'required|string|max:80',
            'columnas.*.unidad' => 'nullable|string|max:30',
            'columnas.*.tipo' => 'required|in:numero,texto,fecha',
            'filas' => 'present|array|max:' . self::MAX_FILAS,
            'filas.*' => 'array',
            'grafico' => 'nullable|array',
            'grafico.x' => 'nullable|string|max:40',
            'grafico.y' => 'nullable|string|max:40',
        ], ['columnas.*.clave.distinct' => 'Hay dos columnas con el mismo nombre.']);

        $columnas = collect($data['columnas'])->map(fn ($c) => [
            'clave' => $c['clave'], 'nombre' => trim($c['nombre']), 'unidad' => trim((string) ($c['unidad'] ?? '')) ?: null, 'tipo' => $c['tipo'],
        ])->values()->all();
        $tipos = collect($columnas)->pluck('tipo', 'clave');
        // Solo se guardan las columnas definidas; numeros como numero y filas vacias se descartan.
        $filas = collect($data['filas'])->map(function ($f) use ($tipos) {
            $fila = [];
            foreach ($tipos as $clave => $tipo) {
                $v = $f[$clave] ?? null;
                if (is_string($v)) {
                    $v = trim($v);
                }
                if ($v === '' || $v === null) {
                    $fila[$clave] = null;
                } elseif ($tipo === 'numero') {
                    $n = str_replace(',', '.', (string) $v);
                    $fila[$clave] = is_numeric($n) ? (float) $n : null;
                } else {
                    $fila[$clave] = Str::limit((string) $v, 255, '');
                }
            }

            return $fila;
        })->filter(fn ($f) => collect($f)->filter(fn ($v) => $v !== null)->isNotEmpty())->values()->all();

        $grafico = array_filter([
            'x' => in_array($data['grafico']['x'] ?? null, $tipos->keys()->all(), true) ? $data['grafico']['x'] : null,
            'y' => in_array($data['grafico']['y'] ?? null, $tipos->keys()->all(), true) ? $data['grafico']['y'] : null,
        ]);

        $datos = PruebaDatos::updateOrCreate(['ensayo_id' => $ensayo->id], [
            'columnas' => $columnas, 'filas' => $filas, 'grafico' => $grafico ?: null, 'actualizado_por' => Auth::id(),
        ]);

        return response()->json($datos);
    }

    /**
     * Lee un Excel: la primera fila son los encabezados ("Nombre (unidad)") y el tipo de cada
     * columna se deduce de sus valores. No guarda: devuelve columnas y filas para revisarlas.
     */
    public function importar(Request $request, Ensayo $ensayo)
    {
        Gate::authorize('register_evaluacion');
        $this->autorizar($ensayo);
        $request->validate(['archivo' => 'required|file|mimes:xlsx,xls,csv,txt|max:5120']);

        try {
            $hoja = IOFactory::load($request->file('archivo')->getRealPath())->getSheet(0);
        } catch (Throwable $e) {
            return response()->json(['message' => 'No se pudo leer el archivo. Use un Excel (.xlsx) con encabezados en la primera fila.'], 422);
        }

        $maxCol = min(self::MAX_COLUMNAS, \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($hoja->getHighestDataColumn()));
        $maxFila = min(self::MAX_FILAS + 1, $hoja->getHighestDataRow());
        $columnas = [];
        $usadas = [];
        for ($c = 1; $c <= $maxCol; $c++) {
            $enc = trim((string) $hoja->getCellByColumnAndRow($c, 1)->getFormattedValue());
            if ($enc === '') {
                continue;
            }
            $unidad = null;
            if (preg_match('/^(.*?)\s*\(([^()]{1,30})\)\s*$/u', $enc, $m)) {
                [$enc, $unidad] = [trim($m[1]), trim($m[2])];
            }
            $clave = Str::limit(Str::slug($enc, '_'), 36, '') ?: 'col';
            for ($base = $clave, $i = 2; isset($usadas[$clave]); $i++) {
                $clave = $base . '_' . $i;
            }
            $usadas[$clave] = true;
            $columnas[$c] = ['clave' => $clave, 'nombre' => Str::limit($enc, 80, ''), 'unidad' => $unidad, 'tipo' => null];
        }
        if (! $columnas) {
            return response()->json(['message' => 'La primera fila debe tener los nombres de las columnas.'], 422);
        }

        $filas = [];
        $esNumero = $esFecha = array_fill_keys(array_keys($columnas), true);
        $conValor = array_fill_keys(array_keys($columnas), false);
        for ($r = 2; $r <= $maxFila; $r++) {
            $fila = [];
            foreach ($columnas as $c => $col) {
                $celda = $hoja->getCellByColumnAndRow($c, $r);
                $v = $celda->getCalculatedValue();
                if ($v === null || $v === '') {
                    $fila[$col['clave']] = null;
                    continue;
                }
                $conValor[$c] = true;
                if (is_numeric($v) && ExcelDate::isDateTime($celda)) {
                    $v = ExcelDate::excelToDateTimeObject((float) $v)->format('Y-m-d');
                    $esNumero[$c] = false;
                } else {
                    $esFecha[$c] = false;
                    if (! is_numeric(str_replace(',', '.', (string) $v))) {
                        $esNumero[$c] = false;
                    }
                }
                $fila[$col['clave']] = $v;
            }
            if (array_filter($fila, fn ($v) => $v !== null)) {
                $filas[] = $fila;
            }
        }
        foreach ($columnas as $c => &$col) {
            $col['tipo'] = ! $conValor[$c] ? 'texto' : ($esFecha[$c] ? 'fecha' : ($esNumero[$c] ? 'numero' : 'texto'));
        }
        unset($col);

        return response()->json(['columnas' => array_values($columnas), 'filas' => $filas]);
    }

    /** Excel con los datos de la prueba, o una plantilla con columnas sugeridas (?plantilla=1). */
    public function excel(Request $request, Ensayo $ensayo)
    {
        $this->autorizar($ensayo);
        $datos = $ensayo->datosPrueba;
        $plantilla = $request->boolean('plantilla') || ! $datos || ! $datos->columnas;

        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet()->setTitle('Datos');
        if ($plantilla) {
            $encabezados = self::SUGERIDAS[$ensayo->subtipo] ?? ['Muestra', 'Fecha', 'Variable 1', 'Variable 2', 'Observaciones'];
            $hoja->fromArray($encabezados, null, 'A1');
        } else {
            $hoja->fromArray(collect($datos->columnas)->map(fn ($c) => $c['nombre'] . ($c['unidad'] ? " ({$c['unidad']})" : ''))->all(), null, 'A1');
            $r = 2;
            foreach ($datos->filas as $f) {
                $hoja->fromArray(collect($datos->columnas)->map(fn ($c) => $f[$c['clave']] ?? null)->all(), null, 'A' . $r++);
            }
        }
        $ultima = $hoja->getHighestColumn();
        $hoja->getStyle("A1:{$ultima}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F5632']],
        ]);
        for ($i = 1; $i <= \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($ultima); $i++) {
            $hoja->getColumnDimensionByColumn($i)->setWidth(20);
        }
        $hoja->freezePane('A2');
        if ($plantilla) {
            $nota = $libro->createSheet()->setTitle('Instrucciones');
            $nota->fromArray([
                ['Datos de la prueba técnica ' . $ensayo->codigo],
                [''],
                ['Use la primera fila para los nombres de las columnas; la unidad va entre paréntesis: "Humedad (%)".'],
                ['Puede cambiar, quitar o agregar columnas. Una fila por medición o muestra.'],
                ['Importe el archivo en la prueba > pestaña Datos > Importar Excel: verá los datos antes de guardarlos.'],
                ['Para una calibración elija en la gráfica la lectura del sensor (X) y el valor de laboratorio (Y): se calcula la ecuación y el R².'],
            ], null, 'A1');
            $nota->getStyle('A1')->getFont()->setBold(true)->setSize(13);
            $nota->getColumnDimension('A')->setWidth(110);
            $libro->setActiveSheetIndex(0);
        }

        $nombre = ($plantilla ? 'plantilla_datos_' : 'datos_') . $ensayo->codigo . '.xlsx';

        return response()->streamDownload(fn () => (new Xlsx($libro))->save('php://output'), $nombre,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    protected function autorizar(Ensayo $ensayo): void
    {
        abort_unless($ensayo->tipo_estudio === 'prueba', 404, 'Solo las pruebas técnicas tienen tabla de datos.');
        abort_unless(Ensayo::visiblesPara(Auth::user())->whereKey($ensayo->id)->exists(), 403, 'No tiene acceso a esta prueba.');
    }
}
