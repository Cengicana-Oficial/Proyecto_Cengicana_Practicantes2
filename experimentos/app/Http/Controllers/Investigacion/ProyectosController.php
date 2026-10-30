<?php

namespace App\Http\Controllers\Investigacion;

use App\Http\Controllers\Controller;
use App\Models\Ensayo;
use App\Models\Programa;
use App\Models\Proyecto;
use App\Models\ProyectoAsignacion;
use App\Models\User;
use App\Services\Codificacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use RuntimeException;

class ProyectosController extends Controller
{
    /** El codigo no se captura: lo asigna Codificacion (SIGLA-AA-NN). */
    protected array $reglasValidacion = [
        'nombre' => 'required|string|max:200',
        'programa_id' => 'required|exists:programas,id',
        'responsable_id' => 'nullable|exists:users,id',
        'objetivo' => 'nullable|string|max:2000',
        'inicio' => 'nullable|date',
        'fin' => 'nullable|date|after_or_equal:inicio',
        'estado' => 'required|in:Activo,Pausado,Finalizado',
    ];

    /** Roles del equipo del proyecto (proyecto_asignaciones). La asignacion da acceso al proyecto. */
    const ROLES_EQUIPO = ['Coordinador', 'Investigador', 'Tecnico de campo', 'Colaborador'];

    /** Igual que dashboard y programas. */
    const DIAS_SIN_ACTIVIDAD = 30;

    public function index()
    {
        $user = Auth::user();

        $proyectos = $this->enriquecer(Proyecto::visiblesPara($user)
            ->with(['programa', 'responsable'])
            ->withCount('ensayos')
            ->orderBy('codigo')
            ->get());

        return view('component', [
            'title' => 'Proyectos',
            'component' => 'proyectos-index',
            'params' => [
                'proyectos' => $proyectos,
                'programas' => Programa::visiblesPara($user)->orderBy('nombre')->get(['id', 'nombre', 'sigla', 'color']),
                'responsables' => User::orderBy('name')->get(['id', 'name']),
                'rolesEquipo' => self::ROLES_EQUIPO,
                'diasSinActividad' => self::DIAS_SIN_ACTIVIDAD,
                'puedeCrear' => $user->can('create_proyecto'),
                'puedeEditar' => $user->can('edit_proyecto'),
                'puedeEliminar' => $user->can('admin_only'),
                'puedeCrearEnsayo' => $user->can('create_ensayo'),
                'ensayosUrl' => route('investigacion.ensayos.index'),
                'evaluacionesUrl' => $user->can('menu_general') ? route('investigacion.evaluaciones.index') : null,
                'muestrasUrl' => ($user->can('create_muestra') || $user->can('view_lab')) ? route('soporte.muestras-consulta.index') : null,
                'programasUrl' => route('investigacion.programas.index'),
            ],
        ]);
    }

    /**
     * Seguimiento del proyecto: sus ensayos con avance (evaluaciones, muestras, ultima
     * actividad), su equipo y el codigo que recibira el proximo ensayo.
     */
    protected function enriquecer($proyectos)
    {
        $ids = $proyectos->pluck('id');
        if ($ids->isEmpty()) {
            return $proyectos;
        }

        $ensayos = Ensayo::whereIn('proyecto_id', $ids)
            ->with('responsable:id,name')
            ->withCount(['evaluaciones', 'muestras', 'muestras as muestras_completadas_count' => fn ($q) => $q->where('estado', 'Completado')])
            ->withMax('evaluaciones as ultima_evaluacion', 'evaluaciones.fecha')
            ->withMax('muestras as ultimo_muestreo', 'fecha')
            ->withMax('bitacora as ultima_bitacora', 'fecha')
            ->orderBy('codigo')
            ->get()
            ->groupBy('proyecto_id');
        $equipo = ProyectoAsignacion::with('usuario:id,name')->whereIn('proyecto_id', $ids)->get()->groupBy('proyecto_id');

        return $proyectos->each(function ($p) use ($ensayos, $equipo) {
            $lista = $ensayos->get($p->id, collect())->map(function ($e) {
                $ultima = collect([$e->ultima_evaluacion, $e->ultimo_muestreo, $e->ultima_bitacora])->filter()->map(fn ($f) => substr($f, 0, 10))->max();

                return [
                    'id' => $e->id, 'codigo' => $e->codigo, 'nombre' => $e->nombre, 'estado' => $e->estado,
                    'responsable' => optional($e->responsable)->name, 'finca' => $e->finca, 'lote' => $e->lote,
                    'variedad' => $e->variedad, 'fecha_siembra' => optional($e->fecha_siembra)->format('Y-m-d'),
                    'evaluaciones' => $e->evaluaciones_count, 'muestras' => $e->muestras_count,
                    'muestras_completadas' => $e->muestras_completadas_count, 'ultima_actividad' => $ultima,
                ];
            })->values();

            $p->setAttribute('ensayos', $lista);
            $p->setAttribute('ensayos_estado', [
                'Planificado' => $lista->where('estado', 'Planificado')->count(),
                'En campo' => $lista->where('estado', 'En campo')->count(),
                'Finalizado' => $lista->where('estado', 'Finalizado')->count(),
            ]);
            $p->setAttribute('evaluaciones_total', $lista->sum('evaluaciones'));
            $p->setAttribute('muestras_total', $lista->sum('muestras'));
            $p->setAttribute('muestras_completadas', $lista->sum('muestras_completadas'));
            $p->setAttribute('ultima_actividad', $lista->pluck('ultima_actividad')->filter()->max());
            $p->setAttribute('equipo', $equipo->get($p->id, collect())->map(fn ($a) => [
                'id' => $a->id, 'usuario_id' => $a->usuario_id, 'nombre' => optional($a->usuario)->name, 'rol' => $a->rol,
            ])->sortBy('nombre')->values());
            $p->setAttribute('proximo_ensayo', Codificacion::codigoEnsayo($p));
        });
    }

    protected function fila(Proyecto $proyecto): Proyecto
    {
        return $this->enriquecer(Proyecto::whereKey($proyecto->id)->with(['programa', 'responsable'])->withCount('ensayos')->get())->first();
    }

    /** Vista previa del codigo que recibira el proyecto (formulario). */
    public function codigo(Request $request)
    {
        $data = $request->validate([
            'programa_id' => 'required|exists:programas,id',
            'inicio' => 'nullable|date',
            'proyecto_id' => 'nullable|exists:proyectos,id',
        ]);
        $programa = Programa::findOrFail($data['programa_id']);
        $proyecto = isset($data['proyecto_id']) ? Proyecto::find($data['proyecto_id']) : null;
        $anio = Codificacion::anioProyecto($data['inicio'] ?? null, optional($proyecto)->created_at);

        // Si el proyecto ya tiene un codigo valido para ese programa y año, lo conserva.
        if ($proyecto && $proyecto->programa_id == $programa->id && Codificacion::anioProyecto($proyecto->inicio, $proyecto->created_at) === $anio) {
            return response()->json(['codigo' => $proyecto->codigo, 'cambia' => false]);
        }

        return response()->json([
            'codigo' => Codificacion::codigoProyecto($programa, $anio, $proyecto),
            'cambia' => (bool) $proyecto,
            'bloqueo' => $proyecto ? Codificacion::bloqueo($proyecto->ensayos()->pluck('id')) : null,
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create_proyecto');

        $data = $request->validate($this->reglasValidacion);

        $proyecto = DB::transaction(function () use ($data) {
            // Bloqueo del programa: dos altas simultaneas no toman el mismo consecutivo.
            $programa = Programa::lockForUpdate()->findOrFail($data['programa_id']);
            $data['codigo'] = Codificacion::codigoProyecto($programa, Codificacion::anioProyecto($data['inicio'] ?? null));

            return Proyecto::create($data);
        });

        return response()->json($this->fila($proyecto), 201);
    }

    public function update(Request $request, Proyecto $proyecto)
    {
        Gate::authorize('edit_proyecto');

        $data = $request->validate($this->reglasValidacion);

        // Si cambia el programa o el año de inicio, el codigo (y el de sus ensayos y muestras) se pone al dia.
        try {
            $cambios = DB::transaction(function () use ($proyecto, $data) {
                Programa::lockForUpdate()->findOrFail($data['programa_id']);
                $proyecto->update($data);

                return Codificacion::recodificarProyecto($proyecto->fresh('programa'));
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage() . ' Mantenga el programa y el año de inicio.'], 422);
        }

        return response()->json(array_merge(
            $this->fila($proyecto)->toArray(),
            ['recodificados' => count($cambios)]
        ));
    }

    public function destroy(Proyecto $proyecto)
    {
        Gate::authorize('admin_only');

        // ensayos.proyecto_id es cascadeOnDelete: borrar el proyecto borraria sus ensayos
        // con evaluaciones y muestras. Igual que en Programas, primero hay que vaciarlo.
        $ensayos = $proyecto->ensayos()->count();
        if ($ensayos) {
            return response()->json([
                'message' => "El proyecto tiene {$ensayos} ensayo(s). Para conservar su informacion no se puede eliminar: cambie su estado a \"Finalizado\", o elimine o mueva antes sus ensayos.",
            ], 422);
        }

        $proyecto->delete();

        return response()->json(['ok' => true]);
    }

    /* ------------------------------ Equipo ------------------------------ */

    /** Agrega (o cambia el rol de) una persona del equipo; la asignacion le da acceso al proyecto. */
    public function guardarIntegrante(Request $request, Proyecto $proyecto)
    {
        Gate::authorize('edit_proyecto');
        abort_unless(Proyecto::visiblesPara(Auth::user())->whereKey($proyecto->id)->exists(), 403);

        $data = $request->validate([
            'usuario_id' => 'required|exists:users,id',
            'rol' => ['required', Rule::in(self::ROLES_EQUIPO)],
        ], [
            'usuario_id.required' => 'Elija la persona.',
            'usuario_id.exists' => 'La persona no existe.',
            'rol.required' => 'Elija el rol.',
            'rol.in' => 'Elija un rol de la lista.',
        ]);

        ProyectoAsignacion::updateOrCreate(
            ['proyecto_id' => $proyecto->id, 'usuario_id' => $data['usuario_id']],
            ['rol' => $data['rol']]
        );

        return response()->json($this->fila($proyecto));
    }

    public function quitarIntegrante(Proyecto $proyecto, ProyectoAsignacion $asignacion)
    {
        Gate::authorize('edit_proyecto');
        abort_unless($asignacion->proyecto_id === $proyecto->id, 404);

        $asignacion->delete();

        return response()->json($this->fila($proyecto));
    }
}
