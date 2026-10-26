<?php

namespace App\Http\Controllers\Soporte;

use App\Http\Controllers\Controller;
use App\Models\AnaliticoPorTipo;
use App\Models\Ensayo;
use App\Models\Evaluacion;
use App\Models\MuestraLab;
use App\Models\Variable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class GraficasController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $catalogo = AnaliticoPorTipo::orderBy('tipo_muestra')->orderBy('id')->get()
            ->groupBy('tipo_muestra')
            ->map(fn ($items) => $items->map(fn ($a) => ['clave' => $a->clave, 'label' => $a->label, 'unidad' => $a->unidad])->values());

        return view('component', [
            'title' => 'Graficas Temporales',
            'component' => 'graficas-index',
            'params' => [
                'ensayos' => Ensayo::visiblesPara($user)->orderBy('codigo')->get(['id', 'codigo']),
                'variables' => Variable::orderBy('nombre')->get(['id', 'nombre', 'unidad']),
                'analitosPorTipo' => $catalogo,
                'tiposMuestra' => array_keys($catalogo->toArray()),
            ],
        ]);
    }

    public function datosVariable(Request $request)
    {
        $data = $request->validate([
            'ensayo_id' => 'required|exists:ensayos,id',
            'variable_id' => 'required|exists:variables,id',
        ]);

        $this->autorizarEnsayo($data['ensayo_id']);

        $evaluaciones = Evaluacion::with('parcela.tratamiento')
            ->where('variable_id', $data['variable_id'])
            ->whereHas('parcela', fn ($q) => $q->where('ensayo_id', $data['ensayo_id']))
            ->orderBy('fecha')
            ->get();

        $series = $evaluaciones->groupBy(fn ($e) => optional($e->parcela->tratamiento)->codigo ?? 'Sin tratamiento')
            ->map(fn ($grupo, $trat) => [
                'name' => $trat,
                'data' => $grupo->map(fn ($e) => [$e->fecha->format('Y-m-d'), is_numeric($e->valor) ? (float) $e->valor : $e->valor])->values(),
            ])->values();

        return response()->json(['series' => $series]);
    }

    public function datosAnalito(Request $request)
    {
        $data = $request->validate([
            'ensayo_id' => 'required|exists:ensayos,id',
            'tipo' => 'required|string',
            'analito' => 'required|string',
        ]);

        $this->autorizarEnsayo($data['ensayo_id']);

        $muestras = MuestraLab::with('tratamiento')
            ->where('ensayo_id', $data['ensayo_id'])
            ->where('tipo', $data['tipo'])
            ->orderBy('fecha')
            ->get()
            ->filter(fn ($m) => isset($m->analitos[$data['analito']]));

        $series = $muestras->groupBy(fn ($m) => optional($m->tratamiento)->codigo ?? 'Sin tratamiento')
            ->map(fn ($grupo, $trat) => [
                'name' => $trat,
                'data' => $grupo->map(fn ($m) => [
                    $m->fecha->format('Y-m-d'),
                    is_numeric($m->analitos[$data['analito']]) ? (float) $m->analitos[$data['analito']] : null,
                ])->values(),
            ])->values();

        return response()->json(['series' => $series]);
    }

    protected function autorizarEnsayo(int $ensayoId): void
    {
        $visibles = Ensayo::visiblesPara(Auth::user())->pluck('id');
        abort_unless($visibles->contains($ensayoId), 403);
    }
}
