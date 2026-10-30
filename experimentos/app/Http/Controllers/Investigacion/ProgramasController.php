<?php

namespace App\Http\Controllers\Investigacion;

use App\Http\Controllers\Controller;
use App\Models\Programa;
use App\Models\Proyecto;
use App\Services\Codificacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use RuntimeException;

class ProgramasController extends Controller
{
    /**
     * Paleta de marca CENGICANA (verde principal, naranja, amarillo, verde
     * secundario, gris). El formulario permite elegir uno de estos colores;
     * si no se envia, se asigna de forma rotativa.
     */
    protected array $paleta = ['#73BC25', '#FF6B00', '#FFCC00', '#A3D300', '#CED2D5'];

    /** Igual que el dashboard: ensayos en campo sin movimiento en este plazo se senalan. */
    const DIAS_SIN_ACTIVIDAD = 30;

    public function index()
    {
        $user = Auth::user();
        $programas = $this->enriquecer($this->conConteos(Programa::visiblesPara($user))->orderBy('nombre')->get());

        return view('component', [
            'title' => 'Programas de investigacion',
            'component' => 'programas-index',
            'params' => [
                'programas' => $programas,
                'puedeEditar' => $user->can('admin_only'),
                'puedeCrearProyecto' => $user->can('create_proyecto'),
                'paleta' => $this->paleta,
                'proyectosUrl' => route('investigacion.proyectos.index'),
                'ensayosUrl' => route('investigacion.ensayos.index'),
                'usuariosUrl' => $user->can('admin_only') ? route('admin.usuarios.index') : null,
                'diasSinActividad' => self::DIAS_SIN_ACTIVIDAD,
            ],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('admin_only');

        $data = $this->validar($request);
        $data['color'] = $data['color'] ?? $this->paleta[Programa::count() % count($this->paleta)];

        $programa = Programa::create($data);

        return response()->json($this->recargar($programa), 201);
    }

    public function update(Request $request, Programa $programa)
    {
        Gate::authorize('admin_only');

        $data = $this->validar($request, $programa);
        if (empty($data['color'])) {
            unset($data['color']);
        }

        // La sigla encabeza el codigo de proyectos y ensayos: si cambia, se recodifican.
        try {
            $cambios = DB::transaction(function () use ($programa, $data) {
                $cambia = $data['sigla'] !== $programa->sigla;
                $programa->update($data);
                $cambios = [];
                if ($cambia) {
                    foreach ($programa->proyectos()->orderByRaw('COALESCE(inicio, DATE(created_at))')->orderBy('id')->get() as $proyecto) {
                        $proyecto->setRelation('programa', $programa);
                        $cambios += Codificacion::recodificarProyecto($proyecto);
                    }
                }

                return $cambios;
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage() . ' Conserve la sigla actual.'], 422);
        }

        return response()->json(array_merge($this->recargar($programa)->toArray(), ['recodificados' => count($cambios)]));
    }

    public function destroy(Programa $programa)
    {
        Gate::authorize('admin_only');

        // proyectos.programa_id (y ensayos.proyecto_id) son cascadeOnDelete:
        // borrar un programa con proyectos arrastraria proyectos y ensayos.
        $proyectos = $programa->proyectos()->count();
        if ($proyectos > 0) {
            return response()->json([
                'message' => "No se puede eliminar: el programa tiene {$proyectos} proyecto(s) vinculado(s). Reasigne o elimine esos proyectos primero.",
            ], 422);
        }

        $programa->delete();

        return response()->json(['ok' => true]);
    }

    protected function validar(Request $request, ?Programa $programa = null): array
    {
        $request->merge(['sigla' => strtoupper(trim((string) $request->input('sigla')))]);

        return $request->validate([
            'nombre' => 'required|string|max:150',
            'sigla' => ['required', 'regex:/^[A-Z][A-Z0-9]{1,3}$/', Rule::unique('programas', 'sigla')->ignore($programa)],
            'descripcion' => 'nullable|string|max:1000',
            'lider' => 'nullable|string|max:150',
            'color' => ['nullable', Rule::in($this->paleta)],
        ], [
            'sigla.regex' => 'La sigla debe tener de 2 a 4 letras o numeros y empezar con una letra (por ejemplo COS o MIP).',
            'sigla.unique' => 'Esa sigla ya la usa otro programa.',
        ], [
            'nombre' => 'nombre del programa',
            'sigla' => 'sigla',
            'descripcion' => 'descripcion',
            'lider' => 'lider',
        ]);
    }

    protected function conConteos($query)
    {
        return $query->withCount([
            'proyectos',
            'proyectos as proyectos_activos_count' => fn ($q) => $q->where('estado', 'Activo'),
            'ensayos',
        ]);
    }

    protected function recargar(Programa $programa): Programa
    {
        return $this->enriquecer($this->conConteos(Programa::whereKey($programa->id))->get())->first();
    }

    /**
     * Agrega a cada programa lo que relaciona sus paginas: proyectos (con responsable y
     * ensayos), ensayos por estado, muestras, evaluaciones recientes, ultima actividad,
     * personas con acceso y el codigo que recibira su proximo proyecto.
     */
    protected function enriquecer($programas)
    {
        $ids = $programas->pluck('id');
        if ($ids->isEmpty()) {
            return $programas;
        }
        $hace30 = now()->subDays(30)->toDateString();

        $proyectos = Proyecto::visiblesPara(Auth::user())->whereIn('programa_id', $ids)
            ->with('responsable:id,name')
            ->withCount(['ensayos', 'ensayos as ensayos_campo_count' => fn ($q) => $q->where('estado', 'En campo')])
            ->orderBy('codigo')
            ->get(['id', 'codigo', 'nombre', 'programa_id', 'responsable_id', 'estado', 'inicio', 'fin'])
            ->groupBy('programa_id');

        $porPrograma = fn ($q) => $q->join('proyectos as pr', 'pr.id', '=', 'en.proyecto_id')->whereIn('pr.programa_id', $ids)->groupBy('pr.programa_id');

        $ensayosEstado = $porPrograma(DB::table('ensayos as en'))->groupBy('en.estado')
            ->selectRaw('pr.programa_id, en.estado, COUNT(*) as n')->get()->groupBy('programa_id');
        $muestras = $porPrograma(DB::table('muestras_lab as m')->join('ensayos as en', 'en.id', '=', 'm.ensayo_id'))
            ->selectRaw("pr.programa_id, COUNT(*) as total, SUM(m.estado = 'Completado') as completadas, MAX(m.fecha) as ultima")
            ->get()->keyBy('programa_id');
        $evaluaciones = $porPrograma(DB::table('evaluaciones as v')->join('parcelas as pa', 'pa.id', '=', 'v.parcela_id')->join('ensayos as en', 'en.id', '=', 'pa.ensayo_id'))
            ->selectRaw('pr.programa_id, SUM(v.fecha >= ?) as recientes, MAX(v.fecha) as ultima', [$hace30])
            ->get()->keyBy('programa_id');
        $bitacora = $porPrograma(DB::table('bitacora as b')->join('ensayos as en', 'en.id', '=', 'b.ensayo_id'))
            ->selectRaw('pr.programa_id, MAX(b.fecha) as ultima')->get()->keyBy('programa_id');
        $miembros = DB::table('programa_usuario as pu')->join('users as u', 'u.id', '=', 'pu.usuario_id')
            ->whereIn('pu.programa_id', $ids)->orderBy('u.name')->get(['pu.programa_id', 'u.id', 'u.name'])->groupBy('programa_id');

        $anio = Codificacion::anioProyecto(null);

        return $programas->each(function ($p) use ($proyectos, $ensayosEstado, $muestras, $evaluaciones, $bitacora, $miembros, $anio) {
            $estados = collect($ensayosEstado->get($p->id, []))->pluck('n', 'estado');
            $p->setAttribute('ensayos_estado', [
                'Planificado' => (int) ($estados['Planificado'] ?? 0),
                'En campo' => (int) ($estados['En campo'] ?? 0),
                'Finalizado' => (int) ($estados['Finalizado'] ?? 0),
            ]);
            $m = $muestras->get($p->id);
            $p->setAttribute('muestras', ['total' => (int) optional($m)->total, 'completadas' => (int) optional($m)->completadas]);
            $p->setAttribute('evaluaciones_30d', (int) optional($evaluaciones->get($p->id))->recientes);
            $p->setAttribute('ultima_actividad', collect([
                optional($m)->ultima, optional($evaluaciones->get($p->id))->ultima, optional($bitacora->get($p->id))->ultima,
            ])->filter()->map(fn ($f) => substr($f, 0, 10))->max());
            $p->setAttribute('proyectos', $proyectos->get($p->id, collect())->map(fn ($pr) => [
                'id' => $pr->id, 'codigo' => $pr->codigo, 'nombre' => $pr->nombre, 'estado' => $pr->estado,
                'responsable' => optional($pr->responsable)->name, 'inicio' => optional($pr->inicio)->format('Y-m-d'),
                'fin' => optional($pr->fin)->format('Y-m-d'), 'ensayos' => $pr->ensayos_count, 'ensayos_campo' => $pr->ensayos_campo_count,
            ])->values());
            $p->setAttribute('miembros', $miembros->get($p->id, collect())->map(fn ($u) => ['id' => $u->id, 'nombre' => $u->name])->values());
            $p->setAttribute('proximo_codigo', $p->sigla ? Codificacion::codigoProyecto($p, $anio) : null);
        });
    }
}
