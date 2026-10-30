<?php

namespace App\Http\Controllers\Investigacion;

use App\Http\Controllers\Controller;
use App\Models\Configuracion;
use App\Models\EstacionMeteo;
use App\Services\ClimaCalidad;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Pestaña "Base de datos" de Clima y estaciones: estado y cobertura de la
 * base climatica (2009 al presente), bitacora de sincronizaciones y control
 * de calidad (rangos por variable, revision y hallazgos).
 */
class ClimaBaseController extends Controller
{
    public function estado()
    {
        $tot = DB::table('clima_diario')->selectRaw('COUNT(*) n, MIN(fecha) desde, MAX(fecha) hasta, COUNT(DISTINCT estacion_id) est')->first();

        // Cobertura: dias con datos por estacion y año.
        $cob = DB::table('clima_diario')->selectRaw('estacion_id, YEAR(fecha) anio, COUNT(*) dias')->groupBy('estacion_id', DB::raw('YEAR(fecha)'))->get();
        $porEst = [];
        foreach ($cob as $c) {
            $porEst[$c->estacion_id][(int) $c->anio] = (int) $c->dias;
        }
        $estaciones = EstacionMeteo::orderBy('nombre')->get(['id', 'codigo', 'nombre', 'estrato', 'zona', 'activa'])
            ->map(fn ($e) => ['id' => $e->id, 'codigo' => $e->codigo, 'nombre' => $e->nombre, 'estrato' => $e->estrato, 'zona' => $e->zona,
                'activa' => $e->activa, 'anios' => (object) ($porEst[$e->id] ?? [])]);

        $calidad = DB::table('clima_calidad')->selectRaw('estado, nivel, COUNT(*) n')->groupBy('estado', 'nivel')->get();
        $ultima = DB::table('clima_sync_log')->whereNull('error')->orderByDesc('id')->first();

        return response()->json([
            'registros' => (int) $tot->n,
            'desde' => $tot->desde,
            'hasta' => $tot->hasta,
            'estaciones_con_datos' => (int) $tot->est,
            'estaciones' => $estaciones,
            'calidad' => [
                'excluidos' => (int) $calidad->where('estado', 'excluido')->sum('n'),
                'pendientes' => (int) $calidad->where('estado', 'pendiente')->sum('n'),
                'validados' => (int) $calidad->where('estado', 'validado')->sum('n'),
            ],
            'ultima_sync' => $ultima,
        ]);
    }

    /** Dias con datos por mes de una estacion y año (detalle de una celda de cobertura). */
    public function coberturaMes(Request $request)
    {
        $data = $request->validate(['estacion' => 'required|integer', 'anio' => 'required|integer|min:1990|max:2100']);
        $meses = DB::table('clima_diario')->where('estacion_id', $data['estacion'])->whereYear('fecha', $data['anio'])
            ->selectRaw('MONTH(fecha) mes, COUNT(*) dias')->groupBy(DB::raw('MONTH(fecha)'))->pluck('dias', 'mes');

        return response()->json(collect(range(1, 12))->map(fn ($m) => [
            'mes' => $m, 'dias' => (int) ($meses[$m] ?? 0), 'total' => (int) date('t', mktime(12, 0, 0, $m, 1, $data['anio'])),
        ]));
    }

    public function bitacora()
    {
        return response()->json(DB::table('clima_sync_log')->leftJoin('users', 'users.id', '=', 'clima_sync_log.user_id')
            ->orderByDesc('clima_sync_log.id')->limit(60)->get(['clima_sync_log.*', 'users.name as usuario']));
    }

    /* ---------------------------- Control de calidad ---------------------------- */

    public function rangos(ClimaCalidad $calidad)
    {
        return response()->json([
            'rangos' => DB::table('clima_rangos')->orderBy('id')->get(),
            'z' => $calidad->umbralZ(),
        ]);
    }

    public function guardarRangos(Request $request)
    {
        Gate::authorize('admin_only');

        $data = $request->validate([
            'rangos' => 'required|array',
            'rangos.*.variable' => ['required', Rule::in(ClimaCalidad::VARIABLES)],
            'rangos.*.valido_min' => 'nullable|numeric',
            'rangos.*.valido_max' => 'nullable|numeric',
            'rangos.*.alerta_min' => 'nullable|numeric',
            'rangos.*.alerta_max' => 'nullable|numeric',
            'rangos.*.estadistico' => 'boolean',
            'z' => 'required|numeric|between:2,6',
        ], ['z.between' => 'El umbral estadistico debe estar entre 2 y 6 desviaciones.']);

        foreach ($data['rangos'] as $r) {
            foreach ([['valido_min', 'valido_max'], ['alerta_min', 'alerta_max']] as [$a, $b]) {
                if (isset($r[$a], $r[$b]) && $r[$a] !== null && $r[$b] !== null && $r[$a] > $r[$b]) {
                    return response()->json(['message' => "En {$r['variable']} el minimo es mayor que el maximo."], 422);
                }
            }
            DB::table('clima_rangos')->where('variable', $r['variable'])->update([
                'valido_min' => $r['valido_min'] ?? null, 'valido_max' => $r['valido_max'] ?? null,
                'alerta_min' => $r['alerta_min'] ?? null, 'alerta_max' => $r['alerta_max'] ?? null,
                'estadistico' => (bool) ($r['estadistico'] ?? false), 'actualizado_por' => Auth::id(), 'updated_at' => now(),
            ]);
        }
        Configuracion::poner('calidad.z', (string) $data['z']);

        return response()->json(['ok' => true]);
    }

    /** Revisa un año (la pantalla recorre año por año con barra de progreso). */
    public function revisar(Request $request, ClimaCalidad $calidad)
    {
        Gate::authorize('gestionar_clima');
        $data = $request->validate(['anio' => 'required|integer|min:1990|max:'.date('Y')]);

        return response()->json($calidad->revisar(Carbon::create($data['anio'], 1, 1), Carbon::create($data['anio'], 12, 31)));
    }

    public function hallazgos(Request $request)
    {
        $data = $request->validate([
            'estado' => 'nullable|in:pendiente,excluido,validado',
            'nivel' => 'nullable|in:error,atipico',
            'variable' => ['nullable', Rule::in(ClimaCalidad::VARIABLES)],
            'estacion' => 'nullable|integer',
            'anio' => 'nullable|integer',
            'page' => 'nullable|integer|min:1',
        ]);
        $q = DB::table('clima_calidad')->join('estaciones_meteo as e', 'e.id', '=', 'clima_calidad.estacion_id')
            ->leftJoin('users as u', 'u.id', '=', 'clima_calidad.revisado_por');
        foreach (['estado', 'nivel', 'variable'] as $k) {
            if (! empty($data[$k])) {
                $q->where("clima_calidad.$k", $data[$k]);
            }
        }
        if (! empty($data['estacion'])) {
            $q->where('clima_calidad.estacion_id', $data['estacion']);
        }
        if (! empty($data['anio'])) {
            $q->whereYear('clima_calidad.fecha', $data['anio']);
        }

        $resumen = (clone $q)->selectRaw('clima_calidad.variable, clima_calidad.estado, COUNT(*) n')
            ->groupBy('clima_calidad.variable', 'clima_calidad.estado')->get();
        $pagina = $q->orderByDesc('clima_calidad.fecha')->orderBy('e.nombre')
            ->select('clima_calidad.*', 'e.nombre as estacion', 'e.codigo', 'u.name as revisor')
            ->paginate(50);

        return response()->json(['pagina' => $pagina, 'resumen' => $resumen]);
    }

    public function accion(Request $request, ClimaCalidad $calidad)
    {
        Gate::authorize('gestionar_clima');
        $data = $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
            'accion' => 'required|in:excluir,validar,pendiente',
            'nota' => 'nullable|string|max:255',
        ]);

        return response()->json(['n' => $calidad->accion($data['ids'], $data['accion'], $data['nota'] ?? null)]);
    }
}
