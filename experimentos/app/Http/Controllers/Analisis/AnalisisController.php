<?php

namespace App\Http\Controllers\Analisis;

use App\Http\Controllers\Controller;
use App\Imports\PrimeraHojaImport;
use App\Models\AnaliticoPorTipo;
use App\Models\Ensayo;
use App\Models\Evaluacion;
use App\Models\MuestraLab;
use App\Models\Parcela;
use App\Models\Variable;
use App\Services\ImportacionEnsayo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AnalisisController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $ensayos = Ensayo::visiblesPara($user)->with('proyecto:id,codigo,nombre')->withCount(['parcelas', 'tratamientos', 'muestras'])
            ->orderBy('codigo')->get(['id', 'codigo', 'nombre', 'finca', 'lote', 'estado', 'proyecto_id']);
        // Resumen de evaluaciones por ensayo: variables ya medidas, total y ultima fecha (para sugerir la plantilla).
        $evaluadas = Evaluacion::join('parcelas', 'parcelas.id', '=', 'evaluaciones.parcela_id')
            ->whereIn('parcelas.ensayo_id', $ensayos->pluck('id'))
            ->groupBy('parcelas.ensayo_id', 'evaluaciones.variable_id')
            ->selectRaw('parcelas.ensayo_id, evaluaciones.variable_id, COUNT(*) n, MAX(evaluaciones.fecha) ultima')
            ->get()->groupBy('ensayo_id');
        $ensayos->each(function ($e) use ($evaluadas) {
            $filas = $evaluadas->get($e->id, collect());
            $e->setAttribute('variables_evaluadas', $filas->pluck('variable_id')->values());
            $e->setAttribute('evaluaciones_count', (int) $filas->sum('n'));
            $e->setAttribute('ultima_evaluacion', $filas->max('ultima') ? substr($filas->max('ultima'), 0, 10) : null);
        });
        return view('component', [
            'title' => 'Importación de datos', 'component' => 'analisis-index',
            'params' => [
                'ensayos' => $ensayos,
                'variables' => Variable::orderBy('nombre')->get(['id', 'nombre', 'unidad', 'tipo', 'categoria', 'activa']),
                'tipos' => collect(ImportacionEnsayo::TIPOS)->filter(fn ($t) => $this->permitido($t))->values(),
                'baseUrl' => route('analisis.index'),
                'evaluacionesUrl' => route('investigacion.evaluaciones.index'),
            ],
        ]);
    }

    private function permitido(string $tipo): bool
    {
        $user = Auth::user();
        return $user->can('run_analysis') && $user->can([
            'evaluaciones' => 'register_evaluacion', 'laboratorio' => 'create_muestra', 'parcelas' => 'edit_ensayo',
        ][$tipo]);
    }

    private function contexto(Request $request): array
    {
        Gate::authorize('run_analysis');
        $d = $request->validate(['ensayo_id' => 'required|integer', 'tipo' => 'required|in:' . implode(',', ImportacionEnsayo::TIPOS)]);
        abort_unless($this->permitido($d['tipo']), 403);
        $ensayo = Ensayo::visiblesPara(Auth::user())->whereKey($d['ensayo_id'])->firstOrFail();
        return [$ensayo, $d['tipo']];
    }

    public function plantilla(Request $request)
    {
        [$ensayo, $tipo] = $this->contexto($request);
        $d = $request->validate([
            'variable_id' => 'nullable|exists:variables,id',
            'variable_ids' => 'nullable|array|max:500',
            'variable_ids.*' => 'required|integer|distinct|exists:variables,id',
            'fecha' => 'required_if:tipo,evaluaciones|nullable|date_format:Y-m-d',
            'formato' => 'nullable|in:ancho,largo',
        ]);
        $parcelas = Parcela::with('tratamiento')->where('ensayo_id', $ensayo->id)->orderBy('codigo')->get();
        $base = fn ($p) => [$ensayo->codigo, $p->codigo, optional($p->tratamiento)->codigo, $p->repeticion];
        $filas = [];
        if ($tipo === 'evaluaciones') {
            $cabecera = ['Ensayo', 'Parcela', 'Tratamiento', 'Repeticion', 'Fecha', 'Variable', 'Unidad', 'Valor', 'Observaciones'];
            $ids = $d['variable_ids'] ?? (! empty($d['variable_id']) ? [$d['variable_id']] : []);
            if (! count($ids)) throw ValidationException::withMessages(['variable_ids' => ['Selecciona una o más variables para la plantilla.']]);
            $variables = Variable::whereIn('id', $ids)->orderBy('nombre')->get();
            if ($parcelas->count() * $variables->count() > 10000) throw ValidationException::withMessages(['variable_ids' => ['La combinación de parcelas y variables supera 10 000 filas. Selecciona menos variables.']]);
            $fecha = $d['fecha'];
            $existentes = Evaluacion::whereIn('parcela_id', $parcelas->pluck('id'))->whereIn('variable_id', $variables->pluck('id'))->whereDate('fecha', $fecha)->get()->keyBy(fn ($e) => $e->parcela_id . '|' . $e->variable_id);
            if (($d['formato'] ?? 'ancho') === 'ancho') {
                // Formato de campo: una fila por parcela y una columna por variable ("V12 · Altura (cm)").
                $cabecera = array_merge(['Ensayo', 'Parcela', 'Tratamiento', 'Repeticion', 'Fecha'], $variables->map(fn ($v) => self::columnaVariable($v))->all(), ['Observaciones']);
                foreach ($parcelas as $p) {
                    $valores = $variables->map(fn ($v) => optional($existentes->get($p->id . '|' . $v->id))->valor)->all();
                    $filas[] = array_merge($base($p), [$fecha], $valores, ['']);
                }
                $instrucciones = 'Una fila por parcela y una columna por variable: escribe cada valor bajo su variable. No cambies el texto "V<número>" del encabezado: identifica la variable. Puedes copiar las filas para otra fecha cambiando la columna Fecha. Parcela + Variable + Fecha actualiza el registro existente o crea uno nuevo. Celdas vacías no borran datos. Observaciones se aplica a todas las variables de la fila.';
            }
            foreach (($d['formato'] ?? 'ancho') === 'ancho' ? [] : $parcelas as $p) {
                foreach ($variables as $v) {
                    $e = $existentes->get($p->id . '|' . $v->id);
                    $filas[] = array_merge($base($p), [$fecha, $v->nombre, $v->unidad, optional($e)->valor, optional($e)->obs]);
                }
            }
            if (($d['formato'] ?? 'ancho') === 'largo') $instrucciones = 'Una fila por parcela y variable. Incluye todas las variables seleccionadas en este único archivo. Puedes agregar filas para otras fechas o variables del catálogo. La misma Parcela + Variable + Fecha actualiza el registro existente; de lo contrario crea una evaluación. Valor vacío no borra datos. Observaciones vacías conservan las actuales.';
        } elseif ($tipo === 'laboratorio') {
            $cabecera = ['Ensayo', 'Parcela', 'Tratamiento', 'Repeticion', 'ID_muestra', 'Tipo', 'Fecha', 'Analito', 'Unidad', 'Valor', 'Fecha_resultado'];
            $catalogo = AnaliticoPorTipo::orderBy('label')->get()->groupBy('tipo_muestra');
            $muestras = MuestraLab::where('ensayo_id', $ensayo->id)->orderBy('id_muestra')->get()->filter(fn ($m) => ImportacionEnsayo::manual($m));
            foreach ($muestras as $m) {
                $p = $parcelas->firstWhere('id', $m->parcela_id);
                if (! $p) continue;
                foreach ($catalogo->get($m->tipo, collect()) as $a) {
                    $filas[] = array_merge($base($p), [$m->id_muestra, $m->tipo, optional($m->fecha)->format('Y-m-d'), $a->clave, $a->unidad, ($m->analitos ?: [])[$a->clave] ?? '', optional($m->fecha_resultado)->format('Y-m-d')]);
                }
            }
            $instrucciones = 'Completa Valor para cada ID_muestra + Analito (clave del catálogo). Solo actualiza muestras manuales existentes asociadas a parcelas. No cambia el estado ni los informes del Laboratorio. Valor vacío conserva el resultado actual.';
        } else {
            $cabecera = ['Ensayo', 'Parcela', 'Tratamiento', 'Repeticion', 'Area', 'Notas'];
            foreach ($parcelas as $p) $filas[] = array_merge($base($p), [$p->area, $p->notas]);
            $instrucciones = 'Actualiza Área (m²) y Notas de parcelas existentes. Los campos vacíos conservan los datos actuales. El archivo no crea parcelas ni cambia tratamientos, repeticiones o códigos.';
        }
        if (! count($filas)) throw ValidationException::withMessages(['plantilla' => ['No hay filas disponibles para esta plantilla. Revisa las parcelas y, para laboratorio, las muestras manuales asociadas y su catálogo de analitos.']]);
        abort_if(count($filas) > 10000, 422, 'La plantilla supera 10 000 filas; reduce las muestras del ensayo.');
        $libro = new Spreadsheet();
        $this->hoja($libro->getActiveSheet(), 'Datos', array_merge([$cabecera], $filas));
        $this->hoja($libro->createSheet(), 'Parcelas', array_merge([['Ensayo', 'Parcela', 'Tratamiento', 'Repeticion', 'Area', 'Notas']], $parcelas->map(fn ($p) => array_merge($base($p), [$p->area, $p->notas]))->all()));
        $this->hoja($libro->createSheet(), 'Tratamientos', array_merge([['Codigo', 'Descripcion']], $ensayo->tratamientos()->orderBy('codigo')->get()->map(fn ($t) => [$t->codigo, $t->descripcion])->all()));
        if ($tipo === 'evaluaciones') {
            $this->hoja($libro->createSheet(), 'Variables', array_merge([['Nombre', 'Unidad', 'Tipo', 'Activa', 'Opciones']], Variable::orderBy('nombre')->get()->map(fn ($v) => [$v->nombre, $v->unidad, $v->tipo, $v->activa ? 'Si' : 'No', implode(' | ', $v->opciones ?: [])])->all()));
        }
        $this->hoja($libro->createSheet(), 'Instrucciones', [
            ['Experimentos · Importación de datos', 'Indicaciones'], ['Ensayo', $ensayo->codigo], ['Tipo', $tipo],
            ['Carga', $instrucciones], ['Referencias', 'Ensayo, Parcela, Tratamiento y Repeticion deben conservar su correspondencia.'],
            ['Formato', 'Solo se importa la primera hoja (Datos). Mantén sus encabezados. Fechas AAAA-MM-DD o fechas de Excel.'],
            ['Revisión', 'Sube el archivo y revisa el resumen antes de aplicar. Las filas inválidas se omiten y se indica el motivo.'],
            ['Límites', 'Excel o CSV, máximo 5 MB y 10 000 filas. Las hojas restantes son referencias y no se importan.'],
        ]);
        $libro->setActiveSheetIndex(0);
        $nombre = 'Experimentos_' . $tipo . '_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $ensayo->codigo) . '.xlsx';
        return response()->streamDownload(function () use ($libro) { (new Xlsx($libro))->save('php://output'); $libro->disconnectWorksheets(); }, $nombre, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** Encabezado de una variable en el formato ancho; el lector de Excel lo convierte en "v12_altura_cm". */
    private static function columnaVariable(Variable $v): string
    {
        return 'V' . $v->id . ' · ' . $v->nombre . ($v->unidad ? " ({$v->unidad})" : '');
    }

    /**
     * Formato ancho (una columna por variable) → una fila por parcela y variable, como la
     * plantilla larga. Conserva el numero de fila del Excel para los mensajes de la revision.
     */
    private function expandirFormatoAncho($filas)
    {
        $primera = $filas->first();
        if (! $primera || array_key_exists('variable', $primera->toArray())) return $filas;
        $columnas = collect(array_keys($primera->toArray()))->mapWithKeys(fn ($k) => preg_match('/^v(\d+)(_|$)/', (string) $k, $m) ? [$k => (int) $m[1]] : []);
        if ($columnas->isEmpty()) return $filas;
        $nombres = Variable::whereIn('id', $columnas->values())->pluck('nombre', 'id');
        $largas = collect();
        foreach ($filas as $i => $fila) {
            foreach ($columnas as $col => $id) {
                if (! $nombres->has($id)) continue;
                $largas->push(collect([
                    'ensayo' => $fila['ensayo'] ?? null, 'parcela' => $fila['parcela'] ?? null,
                    'tratamiento' => $fila['tratamiento'] ?? null, 'repeticion' => $fila['repeticion'] ?? null,
                    'fecha' => $fila['fecha'] ?? null, 'variable' => $nombres[$id], 'valor' => $fila[$col],
                    'observaciones' => $fila['observaciones'] ?? null, '_fila' => $i + 2,
                ]));
            }
        }
        return $largas;
    }

    private function hoja($hoja, string $titulo, array $filas): void
    {
        $hoja->setTitle($titulo);
        foreach ($filas as $r => $fila) foreach ($fila as $c => $valor) $hoja->setCellValueExplicitByColumnAndRow($c + 1, $r + 1, (string) ($valor ?? ''), DataType::TYPE_STRING);
        $ultima = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($filas[0]));
        $hoja->getStyle('A1:' . $ultima . '1')->getFont()->setBold(true)->getColor()->setARGB('FF1F5632');
        $hoja->getRowDimension(1)->setRowHeight(26);
        $hoja->freezePane('A2');
        if ($titulo !== 'Instrucciones') $hoja->setAutoFilter('A1:' . $ultima . count($filas));
        for ($c = 1; $c <= count($filas[0]); $c++) $hoja->getColumnDimensionByColumn($c)->setWidth($titulo === 'Instrucciones' && $c === 2 ? 100 : 24);
        $hoja->getStyle('A1:' . $ultima . count($filas))->getAlignment()->setWrapText(true)->setVertical('top');
    }

    public function importar(Request $request, ImportacionEnsayo $servicio)
    {
        [$ensayo, $tipo] = $this->contexto($request);
        $d = $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls,csv,txt|max:5120',
            'accion' => 'required|in:revisar,aplicar', 'firma' => 'required_if:accion,aplicar|nullable|string|size:64',
        ]);
        $import = new PrimeraHojaImport();
        try { Excel::import($import, $request->file('archivo')); }
        catch (\Throwable $e) { report($e); throw ValidationException::withMessages(['archivo' => ['No se pudo leer el archivo. Usa un Excel o CSV válido, sin contraseña.']]); }
        $filas = $import->hoja->filas ?? collect();
        if ($tipo === 'evaluaciones') $filas = $this->expandirFormatoAncho($filas);
        if (! $filas->count() || $filas->count() > 10000) throw ValidationException::withMessages(['archivo' => ['El archivo debe contener entre 1 y 10 000 filas de datos.']]);
        $requeridas = ['evaluaciones' => ['fecha', 'variable', 'parcela', 'valor'], 'laboratorio' => ['id_muestra', 'analito', 'valor'], 'parcelas' => ['parcela', 'area', 'notas']][$tipo];
        $faltantes = array_diff($requeridas, array_keys($filas->first()->toArray()));
        if ($faltantes) throw ValidationException::withMessages(['archivo' => ['Faltan columnas: ' . implode(', ', $faltantes) . '. Usa la plantilla del tipo de importación seleccionado.']]);
        return response()->json($servicio->procesar($ensayo, $tipo, $filas, $d['accion'] === 'aplicar' ? $d['firma'] : null));
    }
}
