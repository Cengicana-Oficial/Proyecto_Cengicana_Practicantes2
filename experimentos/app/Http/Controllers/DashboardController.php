<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\Ensayo;
use App\Models\Programa;
use App\Models\Proyecto;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $ensayos = Ensayo::visiblesPara($user)->with('proyecto.programa')->get();
        $proyectosCount = Proyecto::visiblesPara($user)->count();
        $programasCount = Programa::visiblesPara($user)->count();

        $kpis = [
            'proyectos' => $proyectosCount,
            'programas' => $programasCount,
            'ensayos_activos' => $ensayos->where('estado', 'En campo')->count(),
            'ensayos_finalizados' => $ensayos->where('estado', 'Finalizado')->count(),
            'ingenios' => $ensayos->pluck('ingenio_id')->filter()->unique()->count(),
            'investigadores' => $ensayos->pluck('responsable_id')->filter()->unique()->count(),
        ];

        $ensayosPorPrograma = $ensayos
            ->groupBy(fn ($e) => optional($e->proyecto->programa)->nombre ?? 'Sin programa')
            ->map->count()
            ->sortDesc();

        $estadoEnsayos = $ensayos->groupBy('estado')->map->count();

        $actividadReciente = Bitacora::with('ensayo')
            ->whereIn('ensayo_id', $ensayos->pluck('id'))
            ->latest('fecha')
            ->limit(6)
            ->get()
            ->map(fn ($b) => [
                'titulo' => $b->tipo,
                'detalle' => \Illuminate\Support\Str::limit($b->descripcion, 80),
                'fecha' => $b->fecha->format('d/m/Y'),
            ]);

        return view('component', [
            'title' => 'Dashboard',
            'component' => 'dashboard-index',
            'params' => [
                'kpis' => $kpis,
                'ensayosPorPrograma' => $ensayosPorPrograma,
                'estadoEnsayos' => $estadoEnsayos,
                'actividadReciente' => $actividadReciente,
            ],
        ]);
    }
}
