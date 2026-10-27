<?php

namespace App\Http\Controllers\Investigacion;

use App\Http\Controllers\Controller;
use App\Models\Ensayo;
use App\Models\Evaluacion;
use App\Models\Parcela;
use App\Models\Variable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EvaluacionesController extends Controller
{
    protected array $reglasValidacion = [
        'fecha' => 'required|date',
        'variable_id' => 'required|exists:variables,id',
        'parcela_id' => 'required|exists:parcelas,id',
        'valor' => 'required|string|max:150',
        'obs' => 'nullable|string|max:1000',
    ];

    public function index()
    {
        $user = Auth::user();
        $ensayoIds = Ensayo::visiblesPara($user)->pluck('id');

        $evaluaciones = Evaluacion::with(['responsable:id,name', 'respuesta.formulario:id,nombre'])
            ->whereHas('parcela', fn ($q) => $q->whereIn('ensayo_id', $ensayoIds))
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($e) => $this->fila($e));

        $parcelas = Parcela::whereIn('ensayo_id', $ensayoIds)
            ->with('tratamiento:id,codigo,descripcion')
            ->orderBy('codigo')
            ->get(['id', 'ensayo_id', 'tratamiento_id', 'codigo', 'repeticion', 'posicion'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'ensayo_id' => $p->ensayo_id,
                'codigo' => $p->codigo,
                'repeticion' => $p->repeticion,
                'posicion' => $p->posicion,
                'tratamiento' => $p->tratamiento ? $p->tratamiento->codigo : null,
                'tratamiento_desc' => $p->tratamiento ? $p->tratamiento->descripcion : null,
            ]);

        $ensayos = Ensayo::whereIn('id', $ensayoIds)
            ->orderBy('codigo')
            ->get(['id', 'codigo', 'finca', 'variedad', 'estado'])
            ->map(fn ($e) => [
                'id' => $e->id,
                'codigo' => $e->codigo,
                'finca' => $e->finca,
                'variedad' => $e->variedad,
                'estado' => $e->estado,
            ]);

        return view('component', [
            'title' => 'Evaluaciones',
            'component' => 'evaluaciones-index',
            'params' => [
                'evaluaciones' => $evaluaciones,
                'variables' => Variable::orderBy('nombre')->get(['id', 'nombre', 'unidad', 'tipo', 'categoria', 'valor_min', 'valor_max', 'decimales', 'opciones', 'activa']),
                'parcelas' => $parcelas,
                'ensayos' => $ensayos,
                'usuarioId' => $user->id,
                'puedeRegistrar' => $user->can('register_evaluacion'),
                'puedeEliminar' => $user->can('admin_only'),
                'puedeExportar' => $user->can('view_reports_menu'),
                // Capturas de formularios de campo que aun no pasan a Evaluaciones.
                'pendientesFormularios' => \App\Models\Respuesta::where('promovido', false)->whereIn('ensayo_id', $ensayoIds)->count(),
                'puedeVerFormularios' => $user->can('manage_formularios'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('register_evaluacion');

        $data = $request->validate($this->reglasValidacion);
        $this->verificarParcela($data['parcela_id']);
        $data['valor'] = $this->normalizarValor(Variable::findOrFail($data['variable_id']), $data['valor'], 'valor');
        $data['responsable_id'] = Auth::id();

        $evaluacion = Evaluacion::create($data);

        return response()->json($this->fila($evaluacion->load('responsable:id,name')), 201);
    }

    public function update(Request $request, Evaluacion $evaluacion)
    {
        Gate::authorize('register_evaluacion');

        $this->verificarParcela($evaluacion->parcela_id);
        $data = $request->validate($this->reglasValidacion);
        $this->verificarParcela($data['parcela_id']);
        $data['valor'] = $this->normalizarValor(Variable::findOrFail($data['variable_id']), $data['valor'], 'valor');

        $evaluacion->update($data);

        return response()->json($this->fila($evaluacion->load('responsable:id,name')));
    }

    /**
     * Captura en lote (matriz o planilla por fecha): cada item es una celda
     * parcela × variable para la fecha indicada. Si ya existe un registro para
     * esa celda se actualiza; si el valor viene vacio se elimina. Todo o nada.
     */
    public function lote(Request $request)
    {
        Gate::authorize('register_evaluacion');

        $request->validate([
            'fecha' => 'required|date',
            'items' => 'required|array|min:1|max:2000',
            'items.*.parcela_id' => 'required|integer',
            'items.*.variable_id' => 'required|integer',
            'items.*.valor' => 'nullable|string|max:150',
            'items.*.obs' => 'nullable|string|max:1000',
        ]);

        $fecha = substr($request->input('fecha'), 0, 10);
        $user = Auth::user();
        $items = $request->input('items');

        $visibles = Ensayo::visiblesPara($user)->pluck('id')->flip();
        $parcelas = Parcela::whereIn('id', collect($items)->pluck('parcela_id')->unique())->get(['id', 'ensayo_id'])->keyBy('id');
        $variables = Variable::whereIn('id', collect($items)->pluck('variable_id')->unique())->get()->keyBy('id');

        $errores = [];
        $plan = [];
        foreach ($items as $i => $it) {
            $parcela = $parcelas->get($it['parcela_id']);
            $variable = $variables->get($it['variable_id']);
            if (! $parcela || ! isset($visibles[$parcela->ensayo_id])) {
                $errores["items.$i.parcela_id"] = ['Parcela no disponible.'];
                continue;
            }
            if (! $variable) {
                $errores["items.$i.variable_id"] = ['Variable no encontrada.'];
                continue;
            }
            $valor = trim((string) ($it['valor'] ?? ''));
            try {
                $plan[] = [
                    'parcela_id' => $parcela->id,
                    'variable_id' => $variable->id,
                    'valor' => $valor === '' ? null : $this->normalizarValor($variable, $valor, "items.$i.valor"),
                    'obs' => $it['obs'] ?? null,
                ];
            } catch (ValidationException $e) {
                $errores += $e->errors();
            }
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        $guardadas = [];
        $eliminadas = [];
        DB::transaction(function () use ($plan, $fecha, $user, &$guardadas, &$eliminadas) {
            foreach ($plan as $p) {
                $existente = Evaluacion::where('parcela_id', $p['parcela_id'])
                    ->where('variable_id', $p['variable_id'])
                    ->whereDate('fecha', $fecha)
                    ->first();

                if ($p['valor'] === null) {
                    if ($existente && ($user->can('admin_only') || $existente->responsable_id === $user->id)) {
                        $eliminadas[] = $existente->id;
                        $existente->delete();
                    }
                    continue;
                }

                if ($existente) {
                    $cambios = ['valor' => $p['valor']];
                    if ($p['obs'] !== null) {
                        $cambios['obs'] = $p['obs'];
                    }
                    $existente->update($cambios);
                    $guardadas[] = $existente;
                } else {
                    $guardadas[] = Evaluacion::create([
                        'fecha' => $fecha,
                        'parcela_id' => $p['parcela_id'],
                        'variable_id' => $p['variable_id'],
                        'valor' => $p['valor'],
                        'obs' => $p['obs'],
                        'responsable_id' => $user->id,
                    ]);
                }
            }
        });

        return response()->json([
            'guardadas' => collect($guardadas)->map(fn ($e) => $this->fila($e->load('responsable:id,name')))->values(),
            'eliminadas' => $eliminadas,
        ]);
    }

    /**
     * Resultados numericos de laboratorio de un ensayo, por analito, para la
     * linea de tiempo de Graficos (junto a las variables de campo).
     */
    public function laboratorio(Request $request)
    {
        $ensayoId = (int) $request->validate(['ensayo_id' => 'required|integer'])['ensayo_id'];
        abort_unless(Ensayo::visiblesPara(Auth::user())->whereKey($ensayoId)->exists(), 403);

        $catalogo = \App\Models\AnaliticoPorTipo::get()->keyBy(fn ($a) => "{$a->tipo_muestra}|{$a->clave}");
        $analitos = [];
        \App\Models\MuestraLab::with(['tratamiento:id,codigo', 'parcela.tratamiento:id,codigo'])
            ->where('ensayo_id', $ensayoId)
            ->whereNotNull('analitos')
            ->orderBy('fecha')
            ->get()
            ->each(function ($m) use (&$analitos, $catalogo) {
                // Resultados del modulo Laboratorio (informe firmado): traen nombre y unidad.
                $lab = collect($m->resultados_lab ?: [])->keyBy('clave');
                foreach ($m->analitos ?: [] as $clave => $valor) {
                    $t = str_replace([' ', ','], ['', '.'], trim((string) $valor));
                    if ($t === '' || ! is_numeric($t)) {
                        continue;
                    }
                    $k = "{$m->tipo}|{$clave}";
                    if (! isset($analitos[$k])) {
                        $cat = $catalogo->get($k);
                        $r = $lab->get($clave);
                        $analitos[$k] = [
                            'tipo' => $m->tipo, 'clave' => $clave,
                            'label' => $r ? $r['nombre'] : ($cat ? $cat->label : $clave),
                            'unidad' => $r ? $r['unidad'] : ($cat ? $cat->unidad : ''),
                            'puntos' => [],
                        ];
                    }
                    $trat = $m->parcela && $m->parcela->tratamiento ? $m->parcela->tratamiento : $m->tratamiento;
                    $analitos[$k]['puntos'][] = [
                        'fecha' => $m->fecha->format('Y-m-d'),
                        'parcela' => $m->parcela ? $m->parcela->codigo : null,
                        'tratamiento' => $trat ? $trat->codigo : null,
                        'valor' => (float) $t,
                    ];
                }
            });

        return response()->json(['analitos' => array_values($analitos)]);
    }

    public function destroy(Evaluacion $evaluacion)
    {
        Gate::authorize('admin_only');

        $evaluacion->delete();

        return response()->json(['ok' => true]);
    }

    /** Registro plano para la vista (sin anidar relaciones pesadas). */
    protected function fila(Evaluacion $e): array
    {
        return [
            'id' => $e->id,
            'fecha' => $e->fecha ? $e->fecha->format('Y-m-d') : null,
            'variable_id' => $e->variable_id,
            'parcela_id' => $e->parcela_id,
            'valor' => $e->valor,
            'obs' => $e->obs,
            'responsable_id' => $e->responsable_id,
            'responsable' => $e->responsable ? $e->responsable->name : null,
            'respuesta_id' => $e->respuesta_id,
            'formulario' => $e->respuesta_id && $e->respuesta && $e->respuesta->formulario ? $e->respuesta->formulario->nombre : null,
            'creado' => $e->created_at ? $e->created_at->format('Y-m-d H:i') : null,
        ];
    }

    protected function verificarParcela($parcelaId): void
    {
        $ensayoId = Parcela::whereKey($parcelaId)->value('ensayo_id');
        abort_unless($ensayoId && Ensayo::visiblesPara(Auth::user())->whereKey($ensayoId)->exists(), 403);
    }

    /** Normaliza segun la variable (ver Variable::normalizar) como error de validacion del campo. */
    protected function normalizarValor(Variable $variable, string $valor, string $campo): string
    {
        try {
            return $variable->normalizar($valor);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([$campo => [$e->getMessage()]]);
        }
    }
}
