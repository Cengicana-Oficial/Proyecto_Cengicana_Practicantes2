<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\ClimaDiario;
use App\Models\Ensayo;
use App\Models\EstacionMeteo;
use App\Models\MuestraLab;
use App\Models\Programa;
use App\Models\Proyecto;
use App\Models\Respuesta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resumen que relaciona el ciclo de trabajo de un ensayo:
 * ensayos -> captura en campo (formularios) -> evaluaciones -> muestras -> resultados
 * del Laboratorio, mas el clima. Cada indicador enlaza a la pagina que lo explica.
 */
class DashboardController extends Controller
{
    const DIAS_SIN_ACTIVIDAD = 30;
    const DIAS_SIN_RECEPCION = 7;
    const DIAS_EN_LABORATORIO = 20;

    public function index()
    {
        $user = Auth::user();
        $hoy = Carbon::today();
        $hace30 = $hoy->copy()->subDays(30);

        $permisos = [
            'investigacion' => $user->can('menu_general'),
            'formularios' => $user->can('manage_formularios') || $user->can('ver_formulario_asignado'),
            'muestras' => $user->can('create_muestra') || $user->can('view_lab'),
            'generar' => $user->can('create_muestra'),
            'admin' => $user->can('admin_only'),
        ];

        $ensayos = Ensayo::visiblesPara($user)
            ->with('proyecto.programa')
            ->withCount(['parcelas', 'evaluaciones', 'muestras',
                'muestras as muestras_completadas_count' => fn ($q) => $q->where('estado', 'Completado')])
            ->withMax('evaluaciones as ultima_evaluacion', 'evaluaciones.fecha')
            ->withMax('muestras as ultimo_muestreo', 'fecha')
            ->get();
        $ids = $ensayos->pluck('id');
        $porId = $ensayos->keyBy('id');

        // Evaluaciones de los ensayos visibles (por parcela).
        $evals = DB::table('evaluaciones as v')->join('parcelas as p', 'p.id', '=', 'v.parcela_id')->whereIn('p.ensayo_id', $ids);
        $muestras = MuestraLab::whereIn('ensayo_id', $ids);
        $capturas = Respuesta::whereIn('ensayo_id', $ids);

        $meses = collect(range(11, 0))->map(fn ($i) => $hoy->copy()->startOfMonth()->subMonths($i)->format('Y-m'));
        $serie = function ($query, string $columna) use ($meses) {
            $desde = $meses->first() . '-01';
            $datos = (clone $query)->where($columna, '>=', $desde)
                ->selectRaw("DATE_FORMAT({$columna}, '%Y-%m') as mes, COUNT(*) as n")
                ->groupBy('mes')->pluck('n', 'mes');

            return $meses->map(fn ($m) => (int) ($datos[$m] ?? 0))->values();
        };

        $ultimoClima = ClimaDiario::max('fecha');
        $flujo = [
            [
                'clave' => 'ensayos', 'titulo' => 'Ensayos en campo', 'icono' => 'fas fa-seedling',
                'valor' => $ensayos->where('estado', 'En campo')->count(),
                'detalle' => $ensayos->count() . ' en total · ' . $ensayos->where('estado', 'Planificado')->count() . ' planificados · ' . $ensayos->where('estado', 'Finalizado')->count() . ' finalizados',
                'url' => $permisos['investigacion'] ? route('investigacion.ensayos.index') : null,
                'serie' => null,
            ],
            [
                'clave' => 'formularios', 'titulo' => 'Capturas por revisar', 'icono' => 'fas fa-clipboard-list',
                'valor' => (clone $capturas)->where('promovido', false)->count(),
                'detalle' => (clone $capturas)->where('created_at', '>=', $hace30)->count() . ' capturas de campo en 30 dias',
                'url' => $permisos['formularios'] ? route('soporte.formularios.index', ['tab' => 'capturas']) : null,
                'serie' => $serie($capturas->getQuery(), 'created_at'),
                'serieNombre' => 'Capturas',
            ],
            [
                'clave' => 'evaluaciones', 'titulo' => 'Valores evaluados', 'icono' => 'fas fa-clipboard-check',
                'valor' => (clone $evals)->where('v.fecha', '>=', $hace30)->count(),
                'detalle' => 'En 30 dias · ' . number_format((clone $evals)->count()) . ' en total · ' . (clone $evals)->distinct()->count('v.variable_id') . ' variables',
                'url' => $permisos['investigacion'] ? route('investigacion.evaluaciones.index') : null,
                'serie' => $serie($evals, 'v.fecha'),
                'serieNombre' => 'Valores',
            ],
            [
                'clave' => 'muestras', 'titulo' => 'Muestras en laboratorio', 'icono' => 'fas fa-vial',
                'valor' => (clone $muestras)->where('estado', 'En proceso')->count(),
                'detalle' => (clone $muestras)->count() . ' generadas · ' . (clone $muestras)->whereIn('estado', ['Recibida', 'Pendiente'])->count() . ' sin recibir',
                'url' => $permisos['muestras'] ? route('soporte.muestras-consulta.index', ['etapa' => 'proceso']) : null,
                'serie' => $serie($muestras->getQuery(), 'fecha'),
                'serieNombre' => 'Muestras',
            ],
            [
                'clave' => 'resultados', 'titulo' => 'Resultados recibidos', 'icono' => 'fas fa-file-signature',
                'valor' => (clone $muestras)->where('estado', 'Completado')->count(),
                'detalle' => (clone $muestras)->where('lab_firmado_en', '>=', $hace30)->count() . ' firmados por el Laboratorio en 30 dias',
                'url' => $permisos['muestras'] ? route('soporte.muestras-consulta.index', ['etapa' => 'completado']) : null,
                'serie' => $serie((clone $muestras)->where('estado', 'Completado')->getQuery(), 'fecha_resultado'),
                'serieNombre' => 'Resultados',
            ],
            [
                'clave' => 'clima', 'titulo' => 'Estaciones de clima', 'icono' => 'fas fa-cloud-sun-rain',
                'valor' => EstacionMeteo::where('activa', true)->count(),
                'detalle' => $ultimoClima ? 'Ultimo dato: ' . Carbon::parse($ultimoClima)->format('d/m/Y') : 'Sin datos diarios',
                'url' => $permisos['investigacion'] ? route('investigacion.clima.index') : null,
                'serie' => null,
            ],
        ];

        return view('component', [
            'title' => 'Dashboard',
            'component' => 'dashboard-index',
            'params' => [
                'resumen' => [
                    'programas' => Programa::visiblesPara($user)->count(),
                    'proyectos' => Proyecto::visiblesPara($user)->count(),
                    'ingenios' => $ensayos->pluck('ingenio_id')->filter()->unique()->count(),
                    'investigadores' => $ensayos->pluck('responsable_id')->filter()->unique()->count(),
                ],
                'meses' => $meses,
                'flujo' => $flujo,
                'atencion' => $this->atencion($ensayos, $muestras, $capturas, $ultimoClima, $permisos, $hoy),
                'enCurso' => $this->enCurso($ensayos, $permisos, $hoy),
                'porPrograma' => $ensayos
                    ->groupBy(fn ($e) => optional(optional($e->proyecto)->programa)->nombre ?? 'Sin programa')
                    ->map(fn ($g, $nombre) => [
                        'programa' => $nombre,
                        'Planificado' => $g->where('estado', 'Planificado')->count(),
                        'En campo' => $g->where('estado', 'En campo')->count(),
                        'Finalizado' => $g->where('estado', 'Finalizado')->count(),
                        'total' => $g->count(),
                    ])->sortByDesc('total')->values(),
                'actividad' => $this->actividad($ids, $porId, $evals, $permisos),
            ],
        ]);
    }

    /** Pendientes que conectan una pagina con la siguiente del flujo. */
    protected function atencion($ensayos, $muestras, $capturas, $ultimoClima, array $p, Carbon $hoy): array
    {
        $out = [];
        $add = function ($nivel, $icono, $texto, $detalle, $url) use (&$out) {
            $out[] = compact('nivel', 'icono', 'texto', 'detalle', 'url');
        };
        $nombres = fn ($c) => $c->take(4)->map(fn ($e) => $e->codigo)->implode(', ') . ($c->count() > 4 ? '…' : '');
        $ensayosUrl = $p['investigacion'] ? route('investigacion.ensayos.index') : null;

        $enCampo = $ensayos->where('estado', 'En campo');
        $quietos = $enCampo->filter(function ($e) use ($hoy) {
            $ultima = collect([$e->ultima_evaluacion, $e->ultimo_muestreo])->filter()->max();

            return ! $ultima || Carbon::parse($ultima)->lt($hoy->copy()->subDays(self::DIAS_SIN_ACTIVIDAD));
        });
        if ($quietos->count()) {
            $add('alerta', 'fas fa-hourglass-half', $quietos->count() . ' ensayo(s) en campo sin evaluaciones ni muestreos en ' . self::DIAS_SIN_ACTIVIDAD . ' dias', $nombres($quietos), $ensayosUrl);
        }

        $pendientes = (clone $capturas)->where('promovido', false)->count();
        if ($pendientes) {
            $add('alerta', 'fas fa-clipboard-list', "{$pendientes} captura(s) de formularios por pasar a Evaluaciones", 'Revise y pase las capturas para que cuenten en los analisis', $p['formularios'] ? route('soporte.formularios.index', ['tab' => 'capturas']) : null);
        }

        $sinRecibir = (clone $muestras)->whereIn('estado', ['Recibida', 'Pendiente'])->whereNull('lab_muestra_id')
            ->where('fecha', '<', $hoy->copy()->subDays(self::DIAS_SIN_RECEPCION))->count();
        if ($sinRecibir) {
            $add('alerta', 'fas fa-truck', "{$sinRecibir} muestra(s) con mas de " . self::DIAS_SIN_RECEPCION . ' dias sin registro en el Laboratorio', 'Confirme la entrega o vincule la muestra con su codigo del Laboratorio', $p['muestras'] ? route('soporte.muestras-consulta.index', ['etapa' => 'recibida']) : null);
        }

        $demoradas = (clone $muestras)->where('estado', 'En proceso')
            ->where('fecha', '<', $hoy->copy()->subDays(self::DIAS_EN_LABORATORIO))->count();
        if ($demoradas) {
            $add('aviso', 'fas fa-flask', "{$demoradas} muestra(s) en analisis desde hace mas de " . self::DIAS_EN_LABORATORIO . ' dias', 'Aun sin informe firmado del Laboratorio', $p['muestras'] ? route('soporte.muestras-consulta.index', ['etapa' => 'proceso']) : null);
        }

        $cosecha = $enCampo->filter(fn ($e) => $e->fecha_cosecha_prevista && ! $e->fecha_cosecha
            && $e->fecha_cosecha_prevista->lte($hoy->copy()->addDays(30)));
        if ($cosecha->count()) {
            $add('aviso', 'fas fa-tractor', $cosecha->count() . ' ensayo(s) con cosecha prevista en los proximos 30 dias o vencida', $cosecha->take(4)->map(fn ($e) => $e->codigo . ' (' . $e->fecha_cosecha_prevista->format('d/m/Y') . ')')->implode(', '), $ensayosUrl);
        }

        $sinSiembra = $enCampo->filter(fn ($e) => ! $e->fecha_siembra);
        if ($sinSiembra->count()) {
            $add('info', 'fas fa-calendar-plus', $sinSiembra->count() . ' ensayo(s) en campo sin fecha de siembra o corte', 'Sin ella no se calcula la edad del cultivo: ' . $nombres($sinSiembra), $ensayosUrl);
        }

        $sinParcelas = $ensayos->where('estado', '!=', 'Finalizado')->filter(fn ($e) => ! $e->parcelas_count);
        if ($sinParcelas->count()) {
            $add('info', 'fas fa-th', $sinParcelas->count() . ' ensayo(s) sin parcelas definidas', 'No se pueden registrar evaluaciones ni generar muestras por parcela: ' . $nombres($sinParcelas), $ensayosUrl);
        }

        if ($ultimoClima && Carbon::parse($ultimoClima)->lt($hoy->copy()->subDays(3))) {
            $add('aviso', 'fas fa-cloud', 'Los datos de clima no se actualizan desde el ' . Carbon::parse($ultimoClima)->format('d/m/Y'), 'Revise la sincronizacion con la API de estaciones', $p['investigacion'] ? route('investigacion.clima.index', ['vista' => 'api']) : null);
        }

        if ($p['admin']) {
            $rechazos = DB::table('lab_envios')->where('respuesta_http', '>=', 400)->where('recibido_en', '>=', $hoy->copy()->subDays(7))->count();
            if ($rechazos) {
                $add('alerta', 'fas fa-shield-alt', "{$rechazos} envio(s) del Laboratorio rechazados en 7 dias", 'Revise la bitacora de envios de la API', route('soporte.muestras-consulta.index'));
            }
        }

        $orden = ['alerta' => 0, 'aviso' => 1, 'info' => 2];

        return collect($out)->sortBy(fn ($a) => $orden[$a['nivel']])->values()->all();
    }

    /** Ensayos en campo con su avance en cada etapa del flujo. */
    protected function enCurso($ensayos, array $p, Carbon $hoy)
    {
        return $ensayos->where('estado', 'En campo')->map(function ($e) use ($p, $hoy) {
            $ultima = collect([$e->ultima_evaluacion, $e->ultimo_muestreo])->filter()->map(fn ($f) => substr($f, 0, 10))->max();

            return [
                'id' => $e->id,
                'codigo' => $e->codigo,
                'nombre' => $e->nombre,
                'proyecto' => optional($e->proyecto)->nombre,
                'edad_meses' => $e->fecha_siembra ? round($e->fecha_siembra->diffInDays($hoy) / 30.44, 1) : null,
                'cosecha_prevista' => optional($e->fecha_cosecha_prevista)->format('Y-m-d'),
                'parcelas' => $e->parcelas_count,
                'evaluaciones' => $e->evaluaciones_count,
                'muestras' => $e->muestras_count,
                'muestras_completadas' => $e->muestras_completadas_count,
                'ultima_actividad' => $ultima,
                'sin_actividad' => ! $ultima || Carbon::parse($ultima)->lt($hoy->copy()->subDays(self::DIAS_SIN_ACTIVIDAD)),
                'urls' => [
                    'detalle' => $p['investigacion'] ? route('investigacion.ensayos.index', ['ensayo' => $e->id]) : null,
                    'evaluaciones' => $p['investigacion'] ? route('investigacion.evaluaciones.index', ['ensayo' => $e->id]) : null,
                    'muestras' => $p['muestras'] ? route('soporte.muestras-consulta.index', ['buscar' => $e->codigo]) : null,
                    'clima' => $p['investigacion'] ? route('investigacion.ensayos.index', ['ensayo' => $e->id, 'tab' => 'clima']) : null,
                ],
            ];
        })->sortBy(fn ($e) => ($e['sin_actividad'] ? '0' : '1') . $e['codigo'])->values();
    }

    /** Linea de tiempo combinada: bitacora, evaluaciones, capturas, muestras y resultados. */
    protected function actividad($ids, $porId, $evals, array $p): array
    {
        $eventos = collect();
        $ensayo = fn ($id) => optional($porId->get($id))->codigo ?? '—';
        $ensayosUrl = fn ($id, $tab = null) => $p['investigacion'] ? route('investigacion.ensayos.index', array_filter(['ensayo' => $id, 'tab' => $tab])) : null;

        Bitacora::whereIn('ensayo_id', $ids)->latest('fecha')->latest('id')->limit(8)->get()
            ->each(fn ($b) => $eventos->push([
                'tipo' => 'bitacora', 'icono' => 'fas fa-book', 'cuando' => $b->fecha->format('Y-m-d') . ' 12:00:00',
                'titulo' => $b->tipo, 'detalle' => Str::limit($b->descripcion, 90), 'ensayo' => $ensayo($b->ensayo_id),
                'url' => $ensayosUrl($b->ensayo_id, 'bitacora'),
            ]));

        (clone $evals)->selectRaw('p.ensayo_id, v.fecha, COUNT(*) as n, COUNT(DISTINCT v.variable_id) as vars, MAX(v.created_at) as cuando')
            ->groupBy('p.ensayo_id', 'v.fecha')->orderByDesc('cuando')->limit(8)->get()
            ->each(fn ($r) => $eventos->push([
                'tipo' => 'evaluacion', 'icono' => 'fas fa-clipboard-check', 'cuando' => $r->cuando ?? $r->fecha . ' 12:00:00',
                'titulo' => "{$r->n} valores evaluados", 'detalle' => "{$r->vars} variable(s), evaluacion del " . Carbon::parse($r->fecha)->format('d/m/Y'),
                'ensayo' => $ensayo($r->ensayo_id),
                'url' => $p['investigacion'] ? route('investigacion.evaluaciones.index', ['ensayo' => $r->ensayo_id]) : null,
            ]));

        Respuesta::whereIn('ensayo_id', $ids)->with('formulario:id,nombre')
            ->selectRaw('ensayo_id, formulario_id, DATE(created_at) as dia, COUNT(*) as n, SUM(promovido = 0) as pendientes, MAX(created_at) as cuando')
            ->groupBy('ensayo_id', 'formulario_id', 'dia')->orderByDesc('cuando')->limit(8)->get()
            ->each(fn ($r) => $eventos->push([
                'tipo' => 'captura', 'icono' => 'fas fa-clipboard-list', 'cuando' => (string) $r->cuando,
                'titulo' => "{$r->n} captura(s) de " . (optional($r->formulario)->nombre ?? 'formulario'),
                'detalle' => $r->pendientes ? "{$r->pendientes} por pasar a Evaluaciones" : 'Pasadas a Evaluaciones',
                'ensayo' => $ensayo($r->ensayo_id),
                'url' => $p['formularios'] ? route('soporte.formularios.index', ['tab' => 'capturas']) : null,
            ]));

        MuestraLab::whereIn('ensayo_id', $ids)
            ->selectRaw('ensayo_id, tipo, fecha, COUNT(*) as n, MAX(created_at) as cuando')
            ->groupBy('ensayo_id', 'tipo', 'fecha')->orderByDesc('cuando')->limit(8)->get()
            ->each(fn ($r) => $eventos->push([
                'tipo' => 'muestra', 'icono' => 'fas fa-vial', 'cuando' => (string) $r->cuando,
                'titulo' => "{$r->n} muestra(s) de {$r->tipo} generadas", 'detalle' => 'Muestreo del ' . Carbon::parse($r->fecha)->format('d/m/Y'),
                'ensayo' => $ensayo($r->ensayo_id),
                'url' => $p['muestras'] ? route('soporte.muestras-consulta.index', ['buscar' => $ensayo($r->ensayo_id)]) : null,
            ]));

        MuestraLab::whereIn('ensayo_id', $ids)->whereNotNull('lab_firmado_en')
            ->selectRaw('ensayo_id, lab_informe, COUNT(*) as n, MAX(lab_firmado_en) as cuando, MAX(lab_firmado_por) as firmo')
            ->groupBy('ensayo_id', 'lab_informe')->orderByDesc('cuando')->limit(8)->get()
            ->each(fn ($r) => $eventos->push([
                'tipo' => 'resultado', 'icono' => 'fas fa-file-signature', 'cuando' => (string) $r->cuando,
                'titulo' => 'Informe ' . ($r->lab_informe ?: 'del Laboratorio') . " firmado ({$r->n} muestras)",
                'detalle' => $r->firmo ? "Firmado por {$r->firmo}" : 'Resultados disponibles',
                'ensayo' => $ensayo($r->ensayo_id),
                'url' => $p['muestras'] ? route('soporte.muestras-consulta.index', ['etapa' => 'completado', 'buscar' => $ensayo($r->ensayo_id)]) : null,
            ]));

        return $eventos->sortByDesc('cuando')->take(10)->values()->all();
    }
}
