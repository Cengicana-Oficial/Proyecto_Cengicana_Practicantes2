<?php

namespace App\Http\Controllers\Soporte;

use App\Http\Controllers\Controller;
use App\Models\Asignacion;
use App\Models\Ensayo;
use App\Models\Evaluacion;
use App\Models\Formulario;
use App\Models\Parcela;
use App\Models\Proyecto;
use App\Models\Respuesta;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Formularios de campo: se disenan con las Variables del catalogo, se asignan a
 * muestreadores (parcelas y periodo) y las capturas pasan a Evaluaciones, con
 * revision previa o de forma automatica segun el formulario.
 */
class FormulariosController extends Controller
{
    const ROLES_ASIGNABLES = ['muestreador', 'investigador', 'experto', 'encargado', 'administrador'];

    const TIPO_DE_VARIABLE = ['Numerica' => 'numero', 'Categorica' => 'select', 'Texto' => 'texto'];

    public function index()
    {
        $user = Auth::user();

        if ($user->can('manage_formularios')) {
            $ensayoIds = Ensayo::visiblesPara($user)->pluck('id');

            $formularios = Formulario::with(['creadoPor:id,name', 'asignaciones.usuario:id,name'])
                ->whereIn('ensayo_id', $ensayoIds)
                ->orderByDesc('fecha')
                ->orderByDesc('id')
                ->get();

            return view('component', [
                'title' => 'Formularios de campo',
                'component' => 'formularios-index',
                'params' => [
                    'modo' => 'gestion',
                    'formularios' => $formularios->map(fn ($f) => $this->formularioDto($f))->values(),
                    'proyectos' => Proyecto::visiblesPara($user)->orderBy('codigo')->get(['id', 'codigo']),
                    'ensayos' => Ensayo::whereIn('id', $ensayoIds)->orderBy('codigo')->get(['id', 'codigo', 'proyecto_id', 'finca', 'estado']),
                    'parcelas' => $this->parcelasDto($ensayoIds),
                    'variables' => Variable::orderBy('nombre')->get(['id', 'nombre', 'unidad', 'tipo', 'categoria', 'valor_min', 'valor_max', 'decimales', 'opciones', 'activa']),
                    'usuarios' => User::with('roles:id,name')
                        ->whereHas('roles', fn ($q) => $q->whereIn('name', self::ROLES_ASIGNABLES))
                        ->orderBy('name')
                        ->get(['id', 'name'])
                        ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'rol' => $u->roles->pluck('name')->first()]),
                    'usuarioId' => $user->id,
                    'puedePromover' => $user->can('register_evaluacion'),
                    'puedeEliminar' => $user->can('admin_only'),
                ],
            ]);
        }

        abort_unless($user->can('ver_formulario_asignado'), 403);

        $asignaciones = Asignacion::with(['formulario', 'usuario:id,name'])
            ->where('usuario_id', $user->id)
            ->orderByRaw("estado = 'Activo' desc")
            ->orderByDesc('fecha_inicio')
            ->get();

        return view('component', [
            'title' => 'Mis formularios de campo',
            'component' => 'formularios-index',
            'params' => [
                'modo' => 'muestreador',
                'asignaciones' => $asignaciones->map(fn ($a) => $this->asignacionDto($a))->values(),
                'formularios' => $asignaciones->pluck('formulario')->filter()->unique('id')->map(fn ($f) => $this->formularioDto($f, false))->values(),
                'ensayos' => Ensayo::whereIn('id', $asignaciones->pluck('ensayo_id'))->get(['id', 'codigo', 'proyecto_id', 'finca', 'estado']),
                'parcelas' => $this->parcelasDto($asignaciones->pluck('ensayo_id')),
                'variables' => Variable::orderBy('nombre')->get(['id', 'nombre', 'unidad', 'tipo', 'categoria', 'valor_min', 'valor_max', 'decimales', 'opciones', 'activa']),
                'usuarioId' => $user->id,
            ],
        ]);
    }

    /* ======================= Formularios ======================= */

    public function store(Request $request)
    {
        Gate::authorize('manage_formularios');

        $data = $this->validarFormulario($request);
        $data['creado_por'] = Auth::id();
        $data['fecha'] = now();

        $formulario = Formulario::create($data);

        return response()->json($this->formularioDto($formulario->load(['creadoPor:id,name', 'asignaciones.usuario:id,name'])), 201);
    }

    public function update(Request $request, Formulario $formulario)
    {
        Gate::authorize('manage_formularios');
        $this->verificarEnsayo($formulario->ensayo_id);

        $formulario->update($this->validarFormulario($request, $formulario));

        return response()->json($this->formularioDto($formulario->fresh(['creadoPor:id,name', 'asignaciones.usuario:id,name'])));
    }

    public function destroy(Formulario $formulario)
    {
        Gate::authorize('admin_only');

        // Las evaluaciones ya promovidas se conservan (respuesta_id queda en null).
        $formulario->delete();

        return response()->json(['ok' => true]);
    }

    protected function validarFormulario(Request $request, ?Formulario $actual = null): array
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:150',
            'descripcion' => 'nullable|string|max:1000',
            'proyecto_id' => 'required|exists:proyectos,id',
            'ensayo_id' => 'required|exists:ensayos,id',
            'estado' => 'nullable|in:Activo,Inactivo',
            'promocion' => 'nullable|in:revision,automatica',
            'campos' => 'required|array|min:1|max:60',
            'campos.*.id' => 'nullable|string|max:20',
            'campos.*.variable_id' => 'nullable|integer|exists:variables,id',
            'campos.*.label' => 'nullable|string|max:150',
            'campos.*.tipo' => 'nullable|in:numero,texto,select',
            'campos.*.requerido' => 'nullable|boolean',
            'campos.*.opciones' => 'nullable|array',
            'campos.*.opciones.*' => 'nullable|string|max:100',
        ], [
            'campos.required' => 'Agregue al menos una variable o campo.',
            'campos.min' => 'Agregue al menos una variable o campo.',
        ]);

        $this->verificarEnsayo($data['ensayo_id']);
        if ((int) Ensayo::whereKey($data['ensayo_id'])->value('proyecto_id') !== (int) $data['proyecto_id']) {
            throw ValidationException::withMessages(['ensayo_id' => ['El ensayo no pertenece al proyecto seleccionado.']]);
        }

        $variables = Variable::whereIn('id', collect($data['campos'])->pluck('variable_id')->filter())->get()->keyBy('id');
        $usados = [];
        $vistos = [];
        $campos = [];
        foreach ($data['campos'] as $i => $c) {
            $id = $c['id'] ?? null;
            if (! $id || isset($usados[$id])) {
                do {
                    $id = 'c' . Str::lower(Str::random(6));
                } while (isset($usados[$id]));
            }
            $usados[$id] = true;

            if (! empty($c['variable_id'])) {
                $v = $variables->get($c['variable_id']);
                if (isset($vistos[$v->id])) {
                    throw ValidationException::withMessages(["campos.$i.variable_id" => ["La variable {$v->nombre} esta repetida."]]);
                }
                $vistos[$v->id] = true;
                $campos[] = [
                    'id' => $id,
                    'variable_id' => $v->id,
                    'label' => $v->nombre,
                    'tipo' => self::TIPO_DE_VARIABLE[$v->tipo] ?? 'texto',
                    'requerido' => (bool) ($c['requerido'] ?? false),
                    'opciones' => $v->tipo === 'Categorica' ? array_values($v->opciones ?: []) : [],
                ];
                continue;
            }

            $label = trim((string) ($c['label'] ?? ''));
            if ($label === '') {
                throw ValidationException::withMessages(["campos.$i.label" => ['Escriba el nombre del campo libre.']]);
            }
            $tipo = $c['tipo'] ?? 'texto';
            $opciones = array_values(array_filter(array_map('trim', $c['opciones'] ?? []), 'strlen'));
            if ($tipo === 'select' && ! $opciones) {
                throw ValidationException::withMessages(["campos.$i.opciones" => ["Defina las opciones de \"{$label}\"."]]);
            }
            $campos[] = [
                'id' => $id,
                'variable_id' => null,
                'label' => $label,
                'tipo' => $tipo,
                'requerido' => (bool) ($c['requerido'] ?? false),
                'opciones' => $tipo === 'select' ? $opciones : [],
            ];
        }

        return [
            'nombre' => $data['nombre'],
            'descripcion' => $data['descripcion'] ?? null,
            'proyecto_id' => $data['proyecto_id'],
            'ensayo_id' => $data['ensayo_id'],
            'estado' => $data['estado'] ?? ($actual->estado ?? 'Activo'),
            'promocion' => $data['promocion'] ?? ($actual->promocion ?? 'revision'),
            'campos' => $campos,
        ];
    }

    /* ======================= Asignaciones ======================= */

    public function storeAsignacion(Request $request, Formulario $formulario)
    {
        Gate::authorize('manage_formularios');
        $this->verificarEnsayo($formulario->ensayo_id);

        $data = $this->validarAsignacion($request, $formulario);

        $asignacion = Asignacion::create($data + [
            'formulario_id' => $formulario->id,
            'proyecto_id' => $formulario->proyecto_id,
            'ensayo_id' => $formulario->ensayo_id,
            'estado' => 'Activo',
        ]);

        return response()->json($this->asignacionDto($asignacion->load('usuario:id,name')), 201);
    }

    public function updateAsignacion(Request $request, Asignacion $asignacion)
    {
        Gate::authorize('manage_formularios');
        $this->verificarEnsayo($asignacion->ensayo_id);

        $data = $this->validarAsignacion($request, $asignacion->formulario, true);
        $asignacion->update($data);

        return response()->json($this->asignacionDto($asignacion->fresh('usuario:id,name')));
    }

    /** Asignacion propia para que quien gestiona tambien pueda capturar el formulario. */
    public function miAsignacion(Formulario $formulario)
    {
        Gate::authorize('manage_formularios');
        $this->verificarEnsayo($formulario->ensayo_id);

        $asignacion = Asignacion::firstOrCreate(
            ['formulario_id' => $formulario->id, 'usuario_id' => Auth::id()],
            ['proyecto_id' => $formulario->proyecto_id, 'ensayo_id' => $formulario->ensayo_id, 'parcelas' => [], 'estado' => 'Activo']
        );

        return response()->json($this->asignacionDto($asignacion->load('usuario:id,name')));
    }

    public function destroyAsignacion(Asignacion $asignacion)
    {
        Gate::authorize('manage_formularios');
        $this->verificarEnsayo($asignacion->ensayo_id);

        $n = $asignacion->respuestas()->count();
        if ($n) {
            return response()->json(['message' => "La asignacion tiene {$n} captura(s); finalicela en lugar de eliminarla."], 422);
        }
        $asignacion->delete();

        return response()->json(['ok' => true]);
    }

    protected function validarAsignacion(Request $request, Formulario $formulario, bool $edicion = false): array
    {
        $data = $request->validate([
            'usuario_id' => ($edicion ? 'sometimes|' : '') . 'required|exists:users,id',
            'parcelas' => 'nullable|array',
            'parcelas.*' => 'string|max:50',
            'fecha_inicio' => 'nullable|date',
            'fecha_fin' => 'nullable|date|after_or_equal:fecha_inicio',
            'estado' => 'nullable|in:Activo,Finalizado',
        ], ['fecha_fin.after_or_equal' => 'La fecha fin debe ser igual o posterior al inicio.']);

        $codigos = Parcela::where('ensayo_id', $formulario->ensayo_id)->pluck('codigo')->all();
        $parcelas = array_values(array_unique($data['parcelas'] ?? []));
        $ajenas = array_diff($parcelas, $codigos);
        if ($ajenas) {
            throw ValidationException::withMessages(['parcelas' => ['Parcelas que no son del ensayo: ' . implode(', ', $ajenas)]]);
        }
        // Todas las parcelas seleccionadas equivale a "todas" (lista vacia).
        if (count($parcelas) === count($codigos)) {
            $parcelas = [];
        }

        return array_filter([
            'usuario_id' => $data['usuario_id'] ?? null,
            'parcelas' => $parcelas,
            'fecha_inicio' => $data['fecha_inicio'] ?? null,
            'fecha_fin' => $data['fecha_fin'] ?? null,
            'estado' => $data['estado'] ?? null,
        ], fn ($v, $k) => $v !== null || in_array($k, ['fecha_inicio', 'fecha_fin'], true), ARRAY_FILTER_USE_BOTH);
    }

    /* ======================= Capturas (respuestas) ======================= */

    public function respuestasAsignacion(Asignacion $asignacion)
    {
        $this->autorizarCaptura($asignacion, false);

        return response()->json(
            $asignacion->respuestas()->with(['responsable:id,name', 'promotor:id,name'])->orderBy('fecha')->get()->map(fn ($r) => $this->respuestaDto($r))
        );
    }

    /**
     * Guarda la planilla de una fecha: una respuesta por parcela (se actualiza si
     * ya existe). Una fila vacia elimina su captura si aun no se promovio.
     */
    public function guardarRespuestas(Request $request, Asignacion $asignacion)
    {
        $this->autorizarCaptura($asignacion, true);

        $request->validate([
            'fecha' => 'required|date|before_or_equal:today',
            'filas' => 'required|array|min:1|max:1000',
            'filas.*.parcela_codigo' => 'required|string|max:50',
            'filas.*.valores' => 'nullable|array',
            'filas.*.observaciones' => 'nullable|string|max:1000',
        ], ['fecha.before_or_equal' => 'La fecha de muestreo no puede ser futura.']);

        $formulario = $asignacion->formulario;
        $fecha = substr($request->input('fecha'), 0, 10);
        $campos = collect($formulario->campos ?: []);
        $variables = Variable::whereIn('id', $campos->pluck('variable_id')->filter())->get()->keyBy('id');
        $parcelas = Parcela::where('ensayo_id', $asignacion->ensayo_id)->get(['id', 'codigo'])->keyBy('codigo');
        $permitidas = $asignacion->parcelas ?: null;

        $errores = [];
        $plan = [];
        foreach ($request->input('filas') as $i => $fila) {
            $codigo = $fila['parcela_codigo'];
            $parcela = $parcelas->get($codigo);
            if (! $parcela || ($permitidas && ! in_array($codigo, $permitidas, true))) {
                $errores["filas.$i.parcela_codigo"] = ["La parcela {$codigo} no esta asignada."];
                continue;
            }
            $entrada = $fila['valores'] ?? [];
            $valores = [];
            foreach ($campos as $c) {
                $val = trim((string) ($entrada[$c['id']] ?? ''));
                if ($val === '') {
                    continue;
                }
                try {
                    $valores[$c['id']] = $this->normalizarCampo($c, $val, $variables);
                } catch (InvalidArgumentException $e) {
                    $errores["filas.$i.valores.{$c['id']}"] = ["{$codigo}: " . $e->getMessage()];
                }
            }
            $obs = trim((string) ($fila['observaciones'] ?? ''));
            if ($valores) {
                foreach ($campos as $c) {
                    if (! empty($c['requerido']) && ! isset($valores[$c['id']]) && ! isset($errores["filas.$i.valores.{$c['id']}"])) {
                        $errores["filas.$i.valores.{$c['id']}"] = ["{$codigo}: \"{$c['label']}\" es obligatorio."];
                    }
                }
            }
            $plan[] = ['parcela' => $parcela, 'valores' => $valores, 'obs' => $obs !== '' ? $obs : null];
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        $user = Auth::user();
        $guardadas = collect();
        $eliminadas = [];
        DB::transaction(function () use ($plan, $asignacion, $formulario, $fecha, $user, &$guardadas, &$eliminadas) {
            foreach ($plan as $p) {
                $existente = Respuesta::where('asignacion_id', $asignacion->id)
                    ->where('parcela_codigo', $p['parcela']->codigo)
                    ->whereDate('fecha', $fecha)
                    ->first();

                if (! $p['valores'] && ! $p['obs']) {
                    if ($existente && ! $existente->promovido) {
                        $eliminadas[] = $existente->id;
                        $existente->delete();
                    }
                    continue;
                }

                if ($existente) {
                    $cambio = $existente->valores != $p['valores'] || $existente->observaciones !== $p['obs'];
                    if (! $cambio) {
                        $guardadas->push($existente);
                        continue;
                    }
                    // Una captura ya promovida que se corrige vuelve a quedar pendiente de revision.
                    $existente->update(['valores' => $p['valores'], 'observaciones' => $p['obs'], 'parcela_id' => $p['parcela']->id, 'promovido' => false, 'promovido_en' => null, 'promovido_por' => null]);
                    $guardadas->push($existente);
                } else {
                    $guardadas->push(Respuesta::create([
                        'formulario_id' => $formulario->id,
                        'asignacion_id' => $asignacion->id,
                        'ensayo_id' => $asignacion->ensayo_id,
                        'parcela_codigo' => $p['parcela']->codigo,
                        'parcela_id' => $p['parcela']->id,
                        'fecha' => $fecha,
                        'responsable_id' => $user->id,
                        'valores' => $p['valores'],
                        'observaciones' => $p['obs'],
                        'promovido' => false,
                    ]));
                }
            }
        });

        $resumen = null;
        if ($formulario->promocion === 'automatica') {
            $resumen = $this->promoverRespuestas($guardadas->where('promovido', false)->values(), $user);
        }

        return response()->json([
            'respuestas' => $guardadas->map(fn ($r) => $this->respuestaDto($r->fresh(['responsable:id,name', 'promotor:id,name'])))->values(),
            'eliminadas' => $eliminadas,
            'promocion' => $resumen,
        ]);
    }

    /** Capturas de un formulario para revisarlas y promoverlas. */
    public function respuestasFormulario(Formulario $formulario)
    {
        Gate::authorize('manage_formularios');
        $this->verificarEnsayo($formulario->ensayo_id);

        return response()->json(
            Respuesta::with(['responsable:id,name', 'promotor:id,name'])
                ->where('formulario_id', $formulario->id)
                ->orderByDesc('fecha')
                ->orderBy('parcela_codigo')
                ->get()
                ->map(fn ($r) => $this->respuestaDto($r))
        );
    }

    public function promover(Request $request)
    {
        Gate::authorize('register_evaluacion');

        $data = $request->validate(['ids' => 'required|array|min:1|max:2000', 'ids.*' => 'integer']);
        $visibles = Ensayo::visiblesPara(Auth::user())->pluck('id');
        $respuestas = Respuesta::with('formulario')->whereIn('id', $data['ids'])->whereIn('ensayo_id', $visibles)->get();

        $resumen = $this->promoverRespuestas($respuestas, Auth::user());
        $resumen['respuestas'] = Respuesta::with(['responsable:id,name', 'promotor:id,name'])->whereIn('id', $respuestas->pluck('id'))->get()->map(fn ($r) => $this->respuestaDto($r));

        return response()->json($resumen);
    }

    public function destroyRespuesta(Respuesta $respuesta)
    {
        $user = Auth::user();
        $propia = $respuesta->responsable_id === $user->id && ! $respuesta->promovido;
        abort_unless($propia || $user->can('manage_formularios'), 403);
        if (! $propia) {
            $this->verificarEnsayo($respuesta->ensayo_id);
        }

        $respuesta->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Pasa a Evaluaciones los campos enlazados a variables. Si ya hay un dato de la
     * misma parcela, variable y fecha se actualiza; los campos libres se quedan
     * solo en la captura.
     */
    protected function promoverRespuestas(Collection $respuestas, User $user): array
    {
        $resumen = ['creadas' => 0, 'actualizadas' => 0, 'promovidas' => 0, 'errores' => []];
        if ($respuestas->isEmpty()) {
            return $resumen;
        }

        $varIds = $respuestas->flatMap(fn ($r) => collect($r->formulario->campos ?: [])->pluck('variable_id'))->filter()->unique();
        $variables = Variable::whereIn('id', $varIds)->get()->keyBy('id');

        DB::transaction(function () use ($respuestas, $user, $variables, &$resumen) {
            foreach ($respuestas as $r) {
                $parcelaId = $r->parcela_id ?: Parcela::where('ensayo_id', $r->ensayo_id)->where('codigo', $r->parcela_codigo)->value('id');
                if (! $parcelaId) {
                    $resumen['errores'][] = "Parcela {$r->parcela_codigo} no existe en el ensayo.";
                    continue;
                }
                $fecha = $r->fecha->format('Y-m-d');
                foreach ($r->formulario->campos ?: [] as $c) {
                    $v = ! empty($c['variable_id']) ? $variables->get($c['variable_id']) : null;
                    $val = $r->valores[$c['id']] ?? null;
                    if (! $v || $val === null || $val === '') {
                        continue;
                    }
                    try {
                        $val = $v->normalizar((string) $val);
                    } catch (InvalidArgumentException $e) {
                        $resumen['errores'][] = "{$r->parcela_codigo} · " . $e->getMessage();
                        continue;
                    }
                    $obs = trim('Formulario: ' . $r->formulario->nombre . ($r->observaciones ? ' · ' . $r->observaciones : ''));
                    $ev = Evaluacion::where('parcela_id', $parcelaId)->where('variable_id', $v->id)->whereDate('fecha', $fecha)->first();
                    if ($ev) {
                        $ev->update(['valor' => $val, 'respuesta_id' => $r->id, 'obs' => Str::limit($obs, 1000, '')]);
                        $resumen['actualizadas']++;
                    } else {
                        Evaluacion::create([
                            'fecha' => $fecha,
                            'variable_id' => $v->id,
                            'parcela_id' => $parcelaId,
                            'valor' => $val,
                            'obs' => Str::limit($obs, 1000, ''),
                            'responsable_id' => $r->responsable_id,
                            'respuesta_id' => $r->id,
                        ]);
                        $resumen['creadas']++;
                    }
                }
                $r->update(['promovido' => true, 'promovido_en' => now(), 'promovido_por' => $user->id, 'parcela_id' => $parcelaId]);
                $resumen['promovidas']++;
            }
        });

        return $resumen;
    }

    /* ======================= Utilidades ======================= */

    protected function normalizarCampo(array $campo, string $valor, Collection $variables): string
    {
        if (! empty($campo['variable_id']) && $variables->has($campo['variable_id'])) {
            return $variables->get($campo['variable_id'])->normalizar($valor);
        }
        if ($campo['tipo'] === 'numero') {
            $num = str_replace([' ', ','], ['', '.'], $valor);
            if (! is_numeric($num)) {
                throw new InvalidArgumentException("\"{$valor}\" no es un numero valido para {$campo['label']}.");
            }

            return $num;
        }
        if ($campo['tipo'] === 'select' && ! empty($campo['opciones'])) {
            foreach ($campo['opciones'] as $op) {
                if (mb_strtolower($op) === mb_strtolower($valor)) {
                    return $op;
                }
            }
            throw new InvalidArgumentException("\"{$valor}\" no es una opcion de {$campo['label']}.");
        }

        return Str::limit($valor, 500, '');
    }

    protected function autorizarCaptura(Asignacion $asignacion, bool $escritura): void
    {
        $user = Auth::user();
        if ($asignacion->usuario_id === $user->id) {
            if ($escritura) {
                abort_if($asignacion->estado !== 'Activo', 422, 'La asignacion esta finalizada.');
                abort_if(optional($asignacion->formulario)->estado === 'Inactivo', 422, 'El formulario esta inactivo.');
            }

            return;
        }
        abort_unless($user->can('manage_formularios'), 403);
        $this->verificarEnsayo($asignacion->ensayo_id);
    }

    protected function verificarEnsayo($ensayoId): void
    {
        abort_unless(Ensayo::visiblesPara(Auth::user())->whereKey($ensayoId)->exists(), 403);
    }

    protected function parcelasDto($ensayoIds)
    {
        return Parcela::whereIn('ensayo_id', $ensayoIds)
            ->with('tratamiento:id,codigo')
            ->get(['id', 'ensayo_id', 'tratamiento_id', 'codigo', 'repeticion'])
            ->map(fn ($p) => [
                'id' => $p->id,
                'ensayo_id' => $p->ensayo_id,
                'codigo' => $p->codigo,
                'repeticion' => $p->repeticion,
                'tratamiento' => $p->tratamiento ? $p->tratamiento->codigo : null,
            ]);
    }

    protected function formularioDto(Formulario $f, bool $conAsignaciones = true): array
    {
        $stats = Respuesta::where('formulario_id', $f->id)
            ->selectRaw('count(*) as total, sum(promovido = 0) as pendientes, count(distinct fecha) as fechas, max(fecha) as ultima')
            ->first();

        return [
            'id' => $f->id,
            'nombre' => $f->nombre,
            'descripcion' => $f->descripcion,
            'proyecto_id' => $f->proyecto_id,
            'ensayo_id' => $f->ensayo_id,
            'campos' => $f->campos ?: [],
            'estado' => $f->estado,
            'promocion' => $f->promocion ?: 'revision',
            'fecha' => $f->fecha ? $f->fecha->format('Y-m-d') : null,
            'creado_por' => $f->creadoPor ? $f->creadoPor->name : null,
            'asignaciones' => $conAsignaciones ? $f->asignaciones->map(fn ($a) => $this->asignacionDto($a))->values() : [],
            'capturas' => (int) $stats->total,
            'pendientes' => (int) $stats->pendientes,
            'fechas' => (int) $stats->fechas,
            'ultima' => $stats->ultima ? substr($stats->ultima, 0, 10) : null,
        ];
    }

    protected function asignacionDto(Asignacion $a): array
    {
        $stats = Respuesta::where('asignacion_id', $a->id)
            ->selectRaw('count(*) as total, sum(promovido = 0) as pendientes, count(distinct fecha) as fechas, max(fecha) as ultima')
            ->first();

        return [
            'id' => $a->id,
            'formulario_id' => $a->formulario_id,
            'usuario_id' => $a->usuario_id,
            'usuario' => $a->usuario ? $a->usuario->name : null,
            'ensayo_id' => $a->ensayo_id,
            'parcelas' => $a->parcelas ?: [],
            'fecha_inicio' => $a->fecha_inicio ? $a->fecha_inicio->format('Y-m-d') : null,
            'fecha_fin' => $a->fecha_fin ? $a->fecha_fin->format('Y-m-d') : null,
            'estado' => $a->estado,
            'capturas' => (int) $stats->total,
            'pendientes' => (int) $stats->pendientes,
            'fechas' => (int) $stats->fechas,
            'ultima' => $stats->ultima ? substr($stats->ultima, 0, 10) : null,
        ];
    }

    protected function respuestaDto(Respuesta $r): array
    {
        return [
            'id' => $r->id,
            'formulario_id' => $r->formulario_id,
            'asignacion_id' => $r->asignacion_id,
            'parcela_codigo' => $r->parcela_codigo,
            'parcela_id' => $r->parcela_id,
            'fecha' => $r->fecha ? $r->fecha->format('Y-m-d') : null,
            'valores' => (object) ($r->valores ?: []),
            'observaciones' => $r->observaciones,
            'promovido' => (bool) $r->promovido,
            'promovido_en' => $r->promovido_en ? $r->promovido_en->format('Y-m-d H:i') : null,
            'promovido_por' => $r->promotor ? $r->promotor->name : null,
            'responsable' => $r->responsable ? $r->responsable->name : null,
            'responsable_id' => $r->responsable_id,
            'actualizado' => $r->updated_at ? $r->updated_at->format('Y-m-d H:i') : null,
        ];
    }
}
