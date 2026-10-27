<?php

namespace App\Http\Controllers\Investigacion;

use App\Http\Controllers\Controller;
use App\Models\Variable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Catalogo de variables evaluadas en los ensayos (Desarrollo / Cosecha):
 * ficha de cada variable, uso en evaluaciones y catalogo sugerido para caña.
 */
class VariablesController extends Controller
{
    /** Variables comunes en ensayos de caña de azucar (se agregan desde "Agregar variables comunes"). */
    const SUGERIDAS = [
        ['nombre' => 'Altura de tallo', 'unidad' => 'cm', 'tipo' => 'Numerica', 'categoria' => 'Desarrollo', 'valor_min' => 0, 'valor_max' => 600, 'decimales' => 0, 'descripcion' => 'Desde el suelo hasta el dewlap de la hoja +1.'],
        ['nombre' => 'Diámetro de tallo', 'unidad' => 'mm', 'tipo' => 'Numerica', 'categoria' => 'Desarrollo', 'valor_min' => 5, 'valor_max' => 45, 'decimales' => 1, 'descripcion' => 'Medido en el tercio medio del tallo.'],
        ['nombre' => 'Población de tallos', 'unidad' => 'tallos/m', 'tipo' => 'Numerica', 'categoria' => 'Desarrollo', 'valor_min' => 0, 'valor_max' => 30, 'decimales' => 1, 'descripcion' => 'Tallos molederos por metro lineal de surco.'],
        ['nombre' => 'Germinación', 'unidad' => '%', 'tipo' => 'Numerica', 'categoria' => 'Desarrollo', 'valor_min' => 0, 'valor_max' => 100, 'decimales' => 0, 'descripcion' => 'Yemas brotadas respecto de las sembradas.'],
        ['nombre' => 'Número de entrenudos', 'unidad' => 'entrenudos', 'tipo' => 'Numerica', 'categoria' => 'Desarrollo', 'valor_min' => 0, 'valor_max' => 40, 'decimales' => 0],
        ['nombre' => 'Hojas verdes', 'unidad' => 'hojas/tallo', 'tipo' => 'Numerica', 'categoria' => 'Desarrollo', 'valor_min' => 0, 'valor_max' => 20, 'decimales' => 0],
        ['nombre' => 'Incidencia de plaga', 'unidad' => '%', 'tipo' => 'Numerica', 'categoria' => 'Desarrollo', 'valor_min' => 0, 'valor_max' => 100, 'decimales' => 1, 'descripcion' => 'Tallos o entrenudos dañados respecto del total evaluado.'],
        ['nombre' => 'Severidad de enfermedad', 'unidad' => 'escala 1-9', 'tipo' => 'Categorica', 'categoria' => 'Desarrollo', 'opciones' => ['1', '2', '3', '4', '5', '6', '7', '8', '9'], 'descripcion' => '1 = sin síntomas, 9 = máxima severidad.'],
        ['nombre' => 'Floración', 'unidad' => null, 'tipo' => 'Categorica', 'categoria' => 'Desarrollo', 'opciones' => ['Sin floración', 'Baja', 'Media', 'Alta']],
        ['nombre' => 'TCH', 'unidad' => 't/ha', 'tipo' => 'Numerica', 'categoria' => 'Cosecha', 'valor_min' => 20, 'valor_max' => 250, 'decimales' => 1, 'descripcion' => 'Toneladas de caña por hectárea.'],
        ['nombre' => 'Brix', 'unidad' => '°Bx', 'tipo' => 'Numerica', 'categoria' => 'Cosecha', 'valor_min' => 10, 'valor_max' => 26, 'decimales' => 2, 'descripcion' => 'Sólidos solubles en el jugo.'],
        ['nombre' => 'Pol en caña', 'unidad' => '%', 'tipo' => 'Numerica', 'categoria' => 'Cosecha', 'valor_min' => 8, 'valor_max' => 20, 'decimales' => 2],
        ['nombre' => 'Pureza', 'unidad' => '%', 'tipo' => 'Numerica', 'categoria' => 'Cosecha', 'valor_min' => 60, 'valor_max' => 95, 'decimales' => 2],
        ['nombre' => 'Fibra', 'unidad' => '%', 'tipo' => 'Numerica', 'categoria' => 'Cosecha', 'valor_min' => 8, 'valor_max' => 20, 'decimales' => 2],
        ['nombre' => 'Azúcar recuperable', 'unidad' => 'kg/t', 'tipo' => 'Numerica', 'categoria' => 'Cosecha', 'valor_min' => 60, 'valor_max' => 160, 'decimales' => 1, 'descripcion' => 'Kilogramos de azúcar por tonelada de caña.'],
        ['nombre' => 'TAH', 'unidad' => 't/ha', 'tipo' => 'Numerica', 'categoria' => 'Cosecha', 'valor_min' => 2, 'valor_max' => 30, 'decimales' => 2, 'descripcion' => 'Toneladas de azúcar por hectárea.'],
        ['nombre' => 'Peso de tallo', 'unidad' => 'kg', 'tipo' => 'Numerica', 'categoria' => 'Cosecha', 'valor_min' => 0, 'valor_max' => 5, 'decimales' => 2],
    ];

    public function index()
    {
        return view('component', [
            'title' => 'Variables',
            'component' => 'variables-index',
            'params' => [
                'variables' => $this->listado(),
                'sugeridas' => self::SUGERIDAS,
                'puedeEditar' => Auth::user()->can('admin_only'),
            ],
        ]);
    }

    /** Variables con su uso: registros, ensayos y ultima fecha. */
    protected function listado()
    {
        $uso = DB::table('evaluaciones as e')->leftJoin('parcelas as p', 'p.id', '=', 'e.parcela_id')
            ->selectRaw('e.variable_id, COUNT(*) registros, COUNT(DISTINCT p.ensayo_id) ensayos, MAX(e.fecha) ultima')
            ->groupBy('e.variable_id')->get()->keyBy('variable_id');

        return Variable::orderBy('categoria')->orderBy('nombre')->get()->map(function ($v) use ($uso) {
            $u = $uso[$v->id] ?? null;
            $v->registros = $u ? (int) $u->registros : 0;
            $v->ensayos = $u ? (int) $u->ensayos : 0;
            $v->ultima = $u->ultima ?? null;

            return $v;
        });
    }

    /** Ficha de una variable: estadisticas, evolucion mensual, ensayos y ultimos registros. */
    public function resumen(Variable $variable)
    {
        $filas = DB::table('evaluaciones as e')
            ->leftJoin('parcelas as p', 'p.id', '=', 'e.parcela_id')
            ->leftJoin('ensayos as en', 'en.id', '=', 'p.ensayo_id')
            ->where('e.variable_id', $variable->id)
            ->orderBy('e.fecha')
            ->get(['e.fecha', 'e.valor', 'p.codigo as parcela', 'en.id as ensayo_id', 'en.codigo as ensayo']);

        $num = fn ($x) => is_numeric(str_replace(',', '.', (string) $x)) ? (float) str_replace(',', '.', (string) $x) : null;
        $valores = $filas->map(fn ($f) => $num($f->valor))->filter(fn ($x) => $x !== null)->values();
        $fuera = $variable->tipo === 'Numerica' ? $valores->filter(fn ($x) => ($variable->valor_min !== null && $x < $variable->valor_min) || ($variable->valor_max !== null && $x > $variable->valor_max))->count() : 0;

        $estad = null;
        if ($variable->tipo === 'Numerica' && $valores->count()) {
            $media = $valores->avg();
            $sd = $valores->count() > 1 ? sqrt($valores->map(fn ($x) => ($x - $media) ** 2)->sum() / ($valores->count() - 1)) : 0;
            $estad = ['n' => $valores->count(), 'min' => $valores->min(), 'max' => $valores->max(), 'media' => $media, 'sd' => $sd, 'cv' => $media ? $sd / $media * 100 : null];
        }

        $mensual = $filas->groupBy(fn ($f) => substr($f->fecha, 0, 7))->map(function ($g, $mes) use ($num) {
            $vs = $g->map(fn ($f) => $num($f->valor))->filter(fn ($x) => $x !== null);

            return ['mes' => $mes, 'n' => $g->count(), 'media' => $vs->count() ? $vs->avg() : null];
        })->values();

        $porEnsayo = $filas->filter(fn ($f) => $f->ensayo_id)->groupBy('ensayo_id')->map(function ($g) use ($num) {
            $vs = $g->map(fn ($f) => $num($f->valor))->filter(fn ($x) => $x !== null);

            return ['ensayo_id' => $g->first()->ensayo_id, 'ensayo' => $g->first()->ensayo, 'n' => $g->count(),
                'media' => $vs->count() ? $vs->avg() : null, 'ultima' => $g->max('fecha')];
        })->sortByDesc('n')->values()->take(15);

        $distribucion = $variable->tipo !== 'Numerica'
            ? $filas->groupBy(fn ($f) => trim((string) $f->valor))->map(fn ($g, $k) => ['valor' => $k, 'n' => $g->count()])->sortByDesc('n')->values()->take(20)
            : [];

        return response()->json([
            'registros' => $filas->count(),
            'ensayos' => $filas->pluck('ensayo_id')->filter()->unique()->count(),
            'primera' => $filas->min('fecha'),
            'ultima' => $filas->max('fecha'),
            'fuera_rango' => $fuera,
            'estadisticas' => $estad,
            'mensual' => $mensual,
            'por_ensayo' => $porEnsayo,
            'distribucion' => $distribucion,
            'ultimos' => $filas->sortByDesc('fecha')->take(10)->values(),
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('admin_only');

        $variable = Variable::create($this->validar($request));

        return response()->json($this->fila($variable), 201);
    }

    public function update(Request $request, Variable $variable)
    {
        Gate::authorize('admin_only');

        $variable->update($this->validar($request, $variable));

        return response()->json($this->fila($variable));
    }

    public function destroy(Variable $variable)
    {
        Gate::authorize('admin_only');

        $n = $variable->evaluaciones()->count();
        if ($n > 0) {
            return response()->json([
                'message' => "No se puede eliminar \"{$variable->nombre}\": tiene {$n} registro(s) en evaluaciones. Puede marcarla como inactiva.",
            ], 422);
        }
        $variable->delete();

        return response()->json(['ok' => true]);
    }

    /** Agrega variables del catalogo sugerido (omite las que ya existen por nombre). */
    public function lote(Request $request)
    {
        Gate::authorize('admin_only');

        $data = $request->validate(['nombres' => 'required|array|min:1', 'nombres.*' => 'string']);
        $existentes = Variable::pluck('nombre')->map(fn ($n) => mb_strtolower(trim($n)))->all();
        $creadas = [];
        foreach (self::SUGERIDAS as $s) {
            if (in_array($s['nombre'], $data['nombres'], true) && ! in_array(mb_strtolower($s['nombre']), $existentes, true)) {
                $creadas[] = $this->fila(Variable::create($s + ['activa' => true]));
            }
        }

        return response()->json(['creadas' => $creadas], 201);
    }

    protected function validar(Request $request, ?Variable $variable = null): array
    {
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:150', function ($attr, $valor, $fail) use ($variable) {
                $q = Variable::whereRaw('LOWER(TRIM(nombre)) = ?', [mb_strtolower(trim($valor))]);
                if ($variable) {
                    $q->where('id', '!=', $variable->id);
                }
                if ($q->exists()) {
                    $fail('Ya existe una variable con ese nombre.');
                }
            }],
            'descripcion' => 'nullable|string|max:1000',
            'unidad' => 'nullable|string|max:50',
            'tipo' => ['required', Rule::in(['Numerica', 'Categorica', 'Texto'])],
            'categoria' => ['required', Rule::in(['Desarrollo', 'Cosecha'])],
            'valor_min' => 'nullable|numeric',
            'valor_max' => 'nullable|numeric',
            'decimales' => 'nullable|integer|min:0|max:6',
            'opciones' => 'nullable|array|max:50',
            'opciones.*' => 'nullable|string|max:60',
            'activa' => 'boolean',
        ], [], ['valor_min' => 'minimo', 'valor_max' => 'maximo']);

        if (isset($data['valor_min'], $data['valor_max']) && $data['valor_min'] > $data['valor_max']) {
            abort(response()->json(['message' => 'El minimo del rango no puede ser mayor que el maximo.', 'errors' => ['valor_min' => ['El minimo no puede ser mayor que el maximo.']]], 422));
        }
        // Solo se guarda lo que aplica al tipo.
        if ($data['tipo'] !== 'Numerica') {
            $data['valor_min'] = $data['valor_max'] = $data['decimales'] = null;
        }
        $data['opciones'] = $data['tipo'] === 'Categorica'
            ? array_values(array_unique(array_filter(array_map(fn ($o) => trim((string) $o), $data['opciones'] ?? []), 'strlen')))
            : null;

        return $data;
    }

    protected function fila(Variable $v)
    {
        return $this->listado()->firstWhere('id', $v->id);
    }
}
