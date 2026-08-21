<?php

namespace App\Http\Controllers\Soporte;

use App\Http\Controllers\Controller;
use App\Models\AnaliticoPorTipo;
use App\Models\Ensayo;
use App\Models\MuestraLab;
use App\Models\Parcela;
use App\Models\Proyecto;
use App\Models\Tratamiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class LaboratorioController extends Controller
{
    public static function tipoCodigo(string $tipo): string
    {
        return [
            'Suelo' => 'SU',
            'Tejido vegetal' => 'TV',
            'Jugos' => 'JU',
            'Mieles' => 'MI',
            'Agua' => 'AG',
            'Fertilizantes' => 'FE',
        ][$tipo] ?? 'OT';
    }

    public static function generarIdMuestra(Ensayo $ensayo, string $tipo): string
    {
        $anio = now()->format('y');
        $codigo = self::tipoCodigo($tipo);

        $secuencia = MuestraLab::where('ensayo_id', $ensayo->id)
            ->where('tipo', $tipo)
            ->whereYear('fecha', now()->year)
            ->count() + 1;

        return sprintf('SIGEC-%s-%s-%03d-%s', $ensayo->codigo, $codigo, $secuencia, $anio);
    }

    protected function verificarAcceso()
    {
        abort_unless(Auth::user()->can('view_lab') || Auth::user()->can('create_muestra'), 403);
    }

    public function index()
    {
        $this->verificarAcceso();

        $user = Auth::user();
        $ensayoIds = Ensayo::visiblesPara($user)->pluck('id');

        $muestras = MuestraLab::with(['proyecto', 'ensayo', 'tratamiento', 'parcela', 'solicitante'])
            ->whereIn('ensayo_id', $ensayoIds)
            ->orderByDesc('fecha')
            ->get();

        $catalogo = AnaliticoPorTipo::orderBy('tipo_muestra')->orderBy('id')->get()
            ->groupBy('tipo_muestra')
            ->map(fn ($items) => $items->map(fn ($a) => ['clave' => $a->clave, 'label' => $a->label, 'unidad' => $a->unidad])->values());

        return view('component', [
            'title' => 'Laboratorio',
            'component' => 'laboratorio-index',
            'params' => [
                'muestras' => $muestras,
                'analitosPorTipo' => $catalogo,
                'tiposMuestra' => array_keys($catalogo->toArray()),
                'proyectos' => Proyecto::visiblesPara($user)->orderBy('codigo')->get(['id', 'codigo']),
                'ensayos' => Ensayo::visiblesPara($user)->orderBy('codigo')->get(['id', 'codigo', 'proyecto_id']),
                'tratamientos' => Tratamiento::whereIn('ensayo_id', $ensayoIds)->get(['id', 'ensayo_id', 'codigo']),
                'parcelas' => Parcela::whereIn('ensayo_id', $ensayoIds)->get(['id', 'ensayo_id', 'tratamiento_id', 'codigo']),
                'kpis' => [
                    'total' => $muestras->count(),
                    'completadas' => $muestras->where('estado', 'Completado')->count(),
                    'en_proceso' => $muestras->where('estado', 'En proceso')->count(),
                    'pendientes' => $muestras->whereIn('estado', ['Pendiente', 'Recibida'])->count(),
                ],
                'puedeCrear' => $user->can('create_muestra'),
                'puedeEliminar' => $user->can('admin_only'),
            ],
        ]);
    }

    protected function reglas(): array
    {
        return [
            'proyecto_id' => 'required|exists:proyectos,id',
            'ensayo_id' => 'required|exists:ensayos,id',
            'tipo' => 'required|string',
            'fecha' => 'required|date',
            'tratamiento_id' => 'nullable|exists:tratamientos,id',
            'parcela_id' => 'nullable|exists:parcelas,id',
            'repeticion' => 'nullable|integer|min:1',
            'estado' => 'nullable|in:Recibida,Pendiente,En proceso,Completado',
            'analitos' => 'nullable|array',
            'obs' => 'nullable|string|max:1000',
            'analistas' => 'nullable|string|max:150',
            'resultado_texto' => 'nullable|string|max:2000',
            'fecha_resultado' => 'nullable|date',
        ];
    }

    public function store(Request $request)
    {
        Gate::authorize('create_muestra');

        $data = $request->validate($this->reglas());
        $ensayo = Ensayo::findOrFail($data['ensayo_id']);

        $data['id_muestra'] = self::generarIdMuestra($ensayo, $data['tipo']);
        $data['estado'] = $data['estado'] ?? 'Pendiente';
        $data['solicitante_id'] = Auth::id();

        $muestra = MuestraLab::create($data);

        return response()->json($muestra->load(['proyecto', 'ensayo', 'tratamiento', 'parcela', 'solicitante']), 201);
    }

    public function update(Request $request, MuestraLab $muestra)
    {
        Gate::authorize('create_muestra');

        $muestra->update($request->validate($this->reglas()));

        return response()->json($muestra->load(['proyecto', 'ensayo', 'tratamiento', 'parcela', 'solicitante']));
    }

    public function destroy(MuestraLab $muestra)
    {
        Gate::authorize('admin_only');

        $muestra->delete();

        return response()->json(['ok' => true]);
    }
}
