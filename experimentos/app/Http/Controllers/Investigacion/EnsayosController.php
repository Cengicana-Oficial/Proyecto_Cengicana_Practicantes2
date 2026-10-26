<?php

namespace App\Http\Controllers\Investigacion;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Soporte\BitacoraController;
use App\Models\Ensayo;
use App\Models\Evaluacion;
use App\Models\Ingenio;
use App\Models\NdviRegistro;
use App\Models\Parcela;
use App\Models\Proyecto;
use App\Models\Tratamiento;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EnsayosController extends Controller
{
    /** Disenos experimentales del prototipo SIGEC_v11 (openEnsayoModal). */
    const DISENOS = ['Bloques al azar', 'Parcelas divididas', 'Cuadro latino', 'Completamente al azar'];

    /** Fuentes de imagen para NDVI (openNdviModal del prototipo). */
    const FUENTES_NDVI = ['Sentinel-2 (Copernicus)', 'Dron / UAV', 'Landsat-8', 'Google Earth Engine'];

    /** Carpetas para adjuntos del ensayo (openArchivoEnsayoModal del prototipo). */
    const CARPETAS = ['Protocolo', 'Croquis', 'Datos', 'Informes', 'Otro'];

    protected array $reglasValidacion = [
        'codigo' => 'required|string|max:30',
        'proyecto_id' => 'required|exists:proyectos,id',
        'ingenio_id' => 'nullable|exists:ingenios,id',
        'finca' => 'nullable|string|max:150',
        'lote' => 'nullable|string|max:150',
        'diseno' => 'nullable|string|max:150',
        'cultivo' => 'nullable|string|max:150',
        'variedad' => 'nullable|string|max:150',
        'responsable_id' => 'nullable|exists:users,id',
        'estado' => 'required|in:Planificado,En campo,Finalizado',
        'lat' => 'nullable|numeric|between:-90,90',
        'lng' => 'nullable|numeric|between:-180,180',
        'num_tratamientos' => 'nullable|integer|min:0|max:50',
        'num_repeticiones' => 'nullable|integer|min:0|max:20',
        'area_parcela' => 'nullable|numeric|min:0',
    ];

    public function index()
    {
        $user = Auth::user();

        $ensayos = $this->conConteos(Ensayo::visiblesPara($user))
            ->with(['proyecto', 'ingenio', 'responsable'])
            ->orderBy('codigo')
            ->get();

        return view('component', [
            'title' => 'Ensayos experimentales',
            'component' => 'ensayos-index',
            'params' => [
                'ensayos' => $ensayos,
                'proyectos' => Proyecto::visiblesPara($user)->orderBy('codigo')->get(['id', 'codigo', 'nombre']),
                'ingenios' => Ingenio::orderBy('nombre')->get(['id', 'nombre']),
                'responsables' => User::orderBy('name')->get(['id', 'name']),
                'disenos' => self::DISENOS,
                'fuentesNdvi' => self::FUENTES_NDVI,
                'carpetas' => self::CARPETAS,
                'tiposBitacora' => BitacoraController::TIPOS,
                'usuarioId' => $user->id,
                'storageUrl' => url('storage'),
                'permisos' => [
                    'crear' => $user->can('create_ensayo'),
                    'editar' => $user->can('edit_ensayo'),
                    'eliminar' => $user->can('admin_only'),
                    'ndvi' => $user->can('register_evaluacion'),
                    'archivos' => $user->can('upload_files'),
                    'bitacora' => $user->can('write_bitacora'),
                ],
            ],
        ]);
    }

    /** Detalle completo para el modal "Ver ensayo" (pestanas del prototipo). */
    public function show(Ensayo $ensayo)
    {
        $this->autorizarVisible($ensayo);

        return response()->json($this->detalle($ensayo));
    }

    public function store(Request $request)
    {
        Gate::authorize('create_ensayo');

        $data = $this->validar($request);

        $ensayo = DB::transaction(function () use ($data) {
            $ensayo = Ensayo::create($data);

            // Configuracion inicial del diseno: T1..Tn, editables luego en "Estructura".
            $reps = (int) ($data['num_repeticiones'] ?? 0);
            for ($i = 1; $i <= (int) ($data['num_tratamientos'] ?? 0); $i++) {
                $ensayo->tratamientos()->create(['codigo' => 'T'.$i, 'descripcion' => null, 'unidades' => $reps ?: null]);
            }

            return $ensayo;
        });

        return response()->json($this->fila($ensayo), 201);
    }

    public function update(Request $request, Ensayo $ensayo)
    {
        Gate::authorize('edit_ensayo');
        $this->autorizarVisible($ensayo);

        $ensayo->update($this->validar($request, $ensayo));

        return response()->json($this->fila($ensayo));
    }

    public function destroy(Ensayo $ensayo)
    {
        Gate::authorize('admin_only');

        $ensayo->delete();

        return response()->json(['ok' => true]);
    }

    /* ----------------------- Estructura: tratamientos ----------------------- */

    public function storeTratamiento(Request $request, Ensayo $ensayo)
    {
        Gate::authorize('edit_ensayo');
        $this->autorizarVisible($ensayo);

        $data = $request->validate($this->reglasTratamiento($ensayo), $this->mensajesTratamiento());
        $ensayo->tratamientos()->create($data);
        $this->sincronizarNumTratamientos($ensayo);

        return response()->json($this->detalle($ensayo), 201);
    }

    public function updateTratamiento(Request $request, Tratamiento $tratamiento)
    {
        Gate::authorize('edit_ensayo');
        $this->autorizarVisible($tratamiento->ensayo);

        $tratamiento->update($request->validate($this->reglasTratamiento($tratamiento->ensayo, $tratamiento), $this->mensajesTratamiento()));

        return response()->json($this->detalle($tratamiento->ensayo));
    }

    public function destroyTratamiento(Tratamiento $tratamiento)
    {
        Gate::authorize('edit_ensayo');
        $ensayo = $tratamiento->ensayo;
        $this->autorizarVisible($ensayo);

        // parcelas.tratamiento_id y evaluaciones.parcela_id son cascadeOnDelete.
        $evaluaciones = Evaluacion::whereIn('parcela_id', $tratamiento->parcelas()->pluck('id'))->count();
        if ($evaluaciones > 0) {
            return response()->json([
                'message' => "No se puede eliminar {$tratamiento->codigo}: sus parcelas tienen {$evaluaciones} evaluacion(es) registrada(s).",
            ], 422);
        }

        $tratamiento->delete();
        $this->sincronizarNumTratamientos($ensayo);

        return response()->json($this->detalle($ensayo));
    }

    /* ------------------------- Estructura: parcelas ------------------------- */

    /**
     * Genera la matriz Tratamiento x Repeticion (generarParcelasAuto del
     * prototipo): reemplaza las parcelas actuales por P-01..P-nn.
     */
    public function generarParcelas(Request $request, Ensayo $ensayo)
    {
        Gate::authorize('edit_ensayo');
        $this->autorizarVisible($ensayo);

        $data = $request->validate([
            'num_repeticiones' => 'required|integer|min:1|max:20',
            'area_parcela' => 'nullable|numeric|min:0',
        ]);

        $tratamientos = $ensayo->tratamientos()->orderBy('id')->get();
        if ($tratamientos->isEmpty()) {
            return response()->json(['message' => 'Agregue al menos un tratamiento antes de generar parcelas.'], 422);
        }

        $evaluaciones = Evaluacion::whereIn('parcela_id', $ensayo->parcelas()->pluck('id'))->count();
        if ($evaluaciones > 0) {
            return response()->json([
                'message' => "No se pueden regenerar las parcelas: ya tienen {$evaluaciones} evaluacion(es) registrada(s).",
            ], 422);
        }

        DB::transaction(function () use ($ensayo, $tratamientos, $data) {
            $ensayo->parcelas()->delete();

            $n = 1;
            for ($rep = 1; $rep <= $data['num_repeticiones']; $rep++) {
                foreach ($tratamientos as $t) {
                    Parcela::create([
                        'ensayo_id' => $ensayo->id,
                        'tratamiento_id' => $t->id,
                        'codigo' => 'P-'.str_pad($n++, 2, '0', STR_PAD_LEFT),
                        'repeticion' => $rep,
                        'area' => $data['area_parcela'] ?? null,
                    ]);
                }
            }

            $ensayo->update([
                'num_tratamientos' => $tratamientos->count(),
                'num_repeticiones' => $data['num_repeticiones'],
                'area_parcela' => $data['area_parcela'] ?? null,
            ]);
        });

        return response()->json($this->detalle($ensayo->fresh()));
    }

    public function updateParcela(Request $request, Parcela $parcela)
    {
        Gate::authorize('edit_ensayo');
        $this->autorizarVisible($parcela->ensayo);

        $parcela->update($request->validate([
            'area' => 'nullable|numeric|min:0',
            'notas' => 'nullable|string|max:500',
        ]));

        return response()->json($this->detalle($parcela->ensayo));
    }

    /* --------------------------------- NDVI --------------------------------- */

    public function storeNdvi(Request $request, Ensayo $ensayo)
    {
        Gate::authorize('register_evaluacion');
        $this->autorizarVisible($ensayo);

        $data = $request->validate([
            'tratamiento_id' => ['required', Rule::exists('tratamientos', 'id')->where('ensayo_id', $ensayo->id)],
            'periodo' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])(-\d{2})?$/'],
            'valor' => 'required|numeric|between:0,1',
            'fuente' => ['nullable', Rule::in(self::FUENTES_NDVI)],
        ], [
            'periodo.regex' => 'Use el formato AAAA-MM (ej. 2026-06).',
            'valor.between' => 'El NDVI debe estar entre 0 y 1.',
        ]);

        // Un valor por tratamiento y periodo: registrar de nuevo lo actualiza.
        NdviRegistro::updateOrCreate(
            ['tratamiento_id' => $data['tratamiento_id'], 'periodo' => $data['periodo']],
            ['ensayo_id' => $ensayo->id, 'valor' => $data['valor'], 'fuente' => $data['fuente'] ?? null, 'registrado_por' => Auth::id()]
        );

        return response()->json($this->detalle($ensayo), 201);
    }

    public function destroyNdvi(NdviRegistro $registro)
    {
        Gate::authorize('register_evaluacion');
        $this->autorizarVisible($registro->ensayo);

        $ensayo = $registro->ensayo;
        $registro->delete();

        return response()->json($this->detalle($ensayo));
    }

    /* ------------------------------- Helpers ------------------------------- */

    protected function validar(Request $request, ?Ensayo $ensayo = null): array
    {
        $reglas = $this->reglasValidacion;
        $reglas['codigo'] = ['required', 'string', 'max:30', Rule::unique('ensayos', 'codigo')->ignore($ensayo)];

        $data = $request->validate($reglas, ['codigo.unique' => 'Ya existe un ensayo con ese codigo.']);

        $proyectoVisible = Proyecto::visiblesPara(Auth::user())->whereKey($data['proyecto_id'])->exists();
        abort_unless($proyectoVisible, 403, 'No tiene acceso a ese proyecto.');

        return $data;
    }

    protected function reglasTratamiento(Ensayo $ensayo, ?Tratamiento $tratamiento = null): array
    {
        return [
            'codigo' => [
                'required', 'string', 'max:20',
                Rule::unique('tratamientos', 'codigo')->where('ensayo_id', $ensayo->id)->ignore($tratamiento),
            ],
            'descripcion' => 'nullable|string|max:255',
            'unidades' => 'nullable|integer|min:1|max:50',
        ];
    }

    protected function mensajesTratamiento(): array
    {
        return [
            'codigo.required' => 'El codigo del tratamiento es obligatorio.',
            'codigo.unique' => 'Ya existe un tratamiento con ese codigo en este ensayo.',
            'unidades.integer' => 'Las unidades deben ser un numero entero.',
        ];
    }

    protected function autorizarVisible(Ensayo $ensayo): void
    {
        abort_unless(
            Ensayo::visiblesPara(Auth::user())->whereKey($ensayo->id)->exists(),
            403,
            'No tiene acceso a este ensayo.'
        );
    }

    protected function sincronizarNumTratamientos(Ensayo $ensayo): void
    {
        $ensayo->update(['num_tratamientos' => $ensayo->tratamientos()->count()]);
    }

    protected function conConteos($query)
    {
        return $query->withCount(['tratamientos', 'parcelas', 'archivos', 'bitacora']);
    }

    protected function fila(Ensayo $ensayo): Ensayo
    {
        return $this->conConteos(Ensayo::whereKey($ensayo->id))
            ->with(['proyecto', 'ingenio', 'responsable'])
            ->first();
    }

    protected function detalle(Ensayo $ensayo): array
    {
        $ensayo = $this->fila($ensayo);

        return [
            'ensayo' => $ensayo,
            'tratamientos' => $ensayo->tratamientos()->withCount('parcelas')->orderBy('id')->get(),
            'parcelas' => $ensayo->parcelas()->with('tratamiento:id,codigo')->withCount('evaluaciones')->orderBy('id')->get(),
            'ndvi' => $ensayo->ndvi()->with('tratamiento:id,codigo')->orderBy('periodo')->get(),
            'archivos' => $ensayo->archivos()->with('subidoPor:id,name')->orderByDesc('fecha')->orderByDesc('id')->get(),
            'bitacora' => $ensayo->bitacora()->with('responsable:id,name')->orderByDesc('fecha')->orderByDesc('id')->get(),
        ];
    }
}
