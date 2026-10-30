<?php

namespace App\Http\Controllers\Investigacion;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Soporte\BitacoraController;
use App\Models\CroquisGeo;
use App\Models\Ensayo;
use App\Models\Evaluacion;
use App\Models\ImagenGeo;
use App\Models\Ingenio;
use App\Models\NdviImagen;
use App\Models\NdviRegistro;
use App\Models\Parcela;
use App\Models\Proyecto;
use App\Models\Tratamiento;
use App\Models\User;
use App\Services\Codificacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

class EnsayosController extends Controller
{
    /** Disenos experimentales del prototipo SIGEC_v11 (openEnsayoModal). */
    const DISENOS = ['Bloques al azar', 'Parcelas divididas', 'Cuadro latino', 'Completamente al azar'];

    /** Fuentes de imagen para NDVI (openNdviModal del prototipo). */
    const FUENTES_NDVI = ['Sentinel-2 (Copernicus)', 'Dron / UAV', 'Landsat-8', 'Google Earth Engine'];

    /** Carpetas para adjuntos del ensayo (openArchivoEnsayoModal del prototipo). */
    const CARPETAS = ['Protocolo', 'Croquis', 'Datos', 'Informes', 'Otro'];

    protected array $reglasValidacion = [
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
        'nombre' => 'nullable|string|max:200',
        'objetivo' => 'nullable|string|max:2000',
        'ciclo' => 'nullable|string|max:30',
        'fecha_siembra' => 'nullable|date',
        'fecha_cosecha_prevista' => 'nullable|date|after_or_equal:fecha_siembra',
        'fecha_cosecha' => 'nullable|date|after_or_equal:fecha_siembra',
        'estrato' => 'nullable|in:ALTO,MEDIO,BAJO,LITORAL',
        'zona' => 'nullable|in:OESTE,CENTRO-OESTE,CENTRO,CENTRO-ESTE,ESTE,ESTE-ESTE',
        'area_total' => 'nullable|numeric|min:0|max:100000',
        'tipo_suelo' => 'nullable|string|max:100',
    ];

    /** Ciclos del cultivo de caña. */
    const CICLOS = ['Plantia', 'Soca 1', 'Soca 2', 'Soca 3', 'Soca 4', 'Soca 5', 'Soca 6 o mas'];

    public function index()
    {
        $user = Auth::user();

        $ensayos = $this->conHitos($this->conConteos(Ensayo::visiblesPara($user))
            ->with(['proyecto.programa:id,nombre,sigla,color', 'ingenio', 'responsable'])
            ->orderBy('codigo')
            ->get());

        return view('component', [
            'title' => 'Ensayos experimentales',
            'component' => 'ensayos-index',
            'params' => [
                'ensayos' => $ensayos,
                'proyectos' => Proyecto::visiblesPara($user)->orderBy('codigo')->get(['id', 'codigo', 'nombre']),
                'ingenios' => Ingenio::orderBy('nombre')->get(['id', 'nombre']),
                'responsables' => User::orderBy('name')->get(['id', 'name']),
                'disenos' => self::DISENOS,
                'ciclos' => self::CICLOS,
                'estratos' => ClimaController::ESTRATOS,
                'zonas' => ClimaController::ZONAS,
                'fuentesNdvi' => self::FUENTES_NDVI,
                'carpetas' => self::CARPETAS,
                'tiposBitacora' => BitacoraController::TIPOS,
                'usuarioId' => $user->id,
                'storageUrl' => url('storage'),
                'evaluacionesUrl' => $user->can('menu_general') ? route('investigacion.evaluaciones.index') : null,
                'muestrasUrl' => ($user->can('create_muestra') || $user->can('view_lab')) ? route('soporte.muestras-consulta.index') : null,
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
            // Codigo PROYECTO-ENN; el bloqueo evita que dos altas tomen el mismo numero.
            $data['codigo'] = Codificacion::codigoEnsayo(Proyecto::lockForUpdate()->findOrFail($data['proyecto_id']));
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

        $data = $this->validar($request, $ensayo);

        // Al pasar a otro proyecto, el codigo y los IDs de sus muestras se ponen al dia.
        try {
            $cambios = DB::transaction(function () use ($ensayo, $data) {
                Proyecto::lockForUpdate()->findOrFail($data['proyecto_id']);
                $ensayo->update($data);

                return Codificacion::recodificarEnsayo($ensayo->fresh('proyecto'));
            });
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage() . ' Mantenga el proyecto actual.'], 422);
        }

        return response()->json(array_merge($this->fila($ensayo)->toArray(), ['recodificado' => (bool) $cambios]));
    }

    /** Vista previa del codigo que recibira el ensayo (formulario). */
    public function codigo(Request $request)
    {
        $data = $request->validate([
            'proyecto_id' => 'required|exists:proyectos,id',
            'ensayo_id' => 'nullable|exists:ensayos,id',
        ]);
        $proyecto = Proyecto::visiblesPara(Auth::user())->findOrFail($data['proyecto_id']);
        $ensayo = isset($data['ensayo_id']) ? Ensayo::find($data['ensayo_id']) : null;

        if ($ensayo && $ensayo->proyecto_id == $proyecto->id) {
            return response()->json(['codigo' => $ensayo->codigo, 'cambia' => false]);
        }

        return response()->json([
            'codigo' => Codificacion::codigoEnsayo($proyecto, $ensayo),
            'cambia' => (bool) $ensayo,
            'bloqueo' => $ensayo ? Codificacion::bloqueo([$ensayo->id]) : null,
        ]);
    }

    public function destroy(Ensayo $ensayo)
    {
        Gate::authorize('admin_only');

        // Un ensayo con datos de campo o de laboratorio no se borra (el cascade los perderia):
        // se finaliza para conservar la informacion.
        $evaluaciones = $ensayo->evaluaciones()->count();
        $muestras = $ensayo->muestras()->count();
        if ($evaluaciones || $muestras) {
            $partes = array_filter([$evaluaciones ? "{$evaluaciones} evaluacion(es)" : null, $muestras ? "{$muestras} muestra(s) de laboratorio" : null]);

            return response()->json([
                'message' => 'El ensayo tiene ' . implode(' y ', $partes) . '. Para conservar esa informacion no se puede eliminar; cambie su estado a "Finalizado".',
            ], 422);
        }

        // El cascade borra la fila del croquis, no el PDF en disco.
        if ($ensayo->croquis) {
            Storage::disk('public')->delete($ensayo->croquis->path);
        }
        Storage::disk('public')->deleteDirectory("ndvi/{$ensayo->id}");
        $ensayo->delete();

        return response()->json(['ok' => true]);
    }

    /* ----------------------- Estructura: tratamientos ----------------------- */

    public function storeTratamiento(Request $request, Ensayo $ensayo)
    {
        Gate::authorize('edit_ensayo');
        $this->autorizarVisible($ensayo);

        $data = $request->validate($this->reglasTratamiento($ensayo), $this->mensajesTratamiento());
        DB::transaction(function () use ($ensayo, $data) {
            $tratamiento = $ensayo->tratamientos()->create($data);
            $this->sincronizarParcelasTratamiento($tratamiento);
            $this->sincronizarNumTratamientos($ensayo);
        });

        return response()->json($this->detalle($ensayo), 201);
    }

    public function updateTratamiento(Request $request, Tratamiento $tratamiento)
    {
        Gate::authorize('edit_ensayo');
        $this->autorizarVisible($tratamiento->ensayo);

        $data = $request->validate($this->reglasTratamiento($tratamiento->ensayo, $tratamiento), $this->mensajesTratamiento());
        DB::transaction(function () use ($tratamiento, $data) {
            $tratamiento->update($data);
            $this->sincronizarParcelasTratamiento($tratamiento);
        });

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
            'aleatorizar' => 'nullable|boolean',
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
        // muestras_lab.parcela_id es nullOnDelete: regenerar dejaria las muestras sin parcela sin avisar.
        $muestras = $ensayo->muestras()->whereNotNull('parcela_id')->count();
        if ($muestras > 0) {
            return response()->json([
                'message' => "No se pueden regenerar las parcelas: {$muestras} muestra(s) de laboratorio estan asociadas a ellas.",
            ], 422);
        }

        DB::transaction(function () use ($ensayo, $tratamientos, $data) {
            $ensayo->parcelas()->delete();
            $ensayo->tratamientos()->update(['unidades' => $data['num_repeticiones']]);

            // Bloques completos al azar: cada repeticion recibe su propio orden
            // de tratamientos; los codigos P-nn siguen el orden fisico en campo.
            $n = 1;
            for ($rep = 1; $rep <= $data['num_repeticiones']; $rep++) {
                $orden = ! empty($data['aleatorizar']) ? $tratamientos->shuffle() : $tratamientos;
                foreach ($orden->values() as $i => $t) {
                    Parcela::create([
                        'ensayo_id' => $ensayo->id,
                        'tratamiento_id' => $t->id,
                        'codigo' => 'P-'.str_pad($n++, 2, '0', STR_PAD_LEFT),
                        'repeticion' => $rep,
                        'posicion' => $i + 1,
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

    /**
     * Re-sortea los tratamientos dentro de cada repeticion sin tocar las
     * parcelas fisicas (codigo, posicion y poligono se conservan).
     */
    public function aleatorizarParcelas(Ensayo $ensayo)
    {
        Gate::authorize('edit_ensayo');
        $this->autorizarVisible($ensayo);

        $parcelas = $ensayo->parcelas()->orderBy('posicion')->orderBy('id')->get();
        if ($parcelas->isEmpty()) {
            return response()->json(['message' => 'El ensayo no tiene parcelas para aleatorizar.'], 422);
        }
        if ($error = $this->bloqueoPorEvaluaciones($parcelas->pluck('id'), 'aleatorizar')) {
            return $error;
        }

        DB::transaction(function () use ($parcelas) {
            foreach ($parcelas->groupBy('repeticion') as $bloque) {
                $actual = $bloque->pluck('tratamiento_id')->all();
                $nuevo = $actual;
                // Evita devolver el mismo orden cuando hay mas de un tratamiento distinto.
                for ($i = 0; $i < 10 && $nuevo === $actual && count(array_unique($actual)) > 1; $i++) {
                    shuffle($nuevo);
                }
                foreach ($bloque->values() as $k => $p) {
                    if ($p->tratamiento_id !== $nuevo[$k]) {
                        $p->update(['tratamiento_id' => $nuevo[$k]]);
                    }
                }
            }
        });

        return response()->json($this->detalle($ensayo));
    }

    /** Intercambia los tratamientos de dos parcelas de la misma repeticion. */
    public function intercambiarParcelas(Request $request, Ensayo $ensayo)
    {
        Gate::authorize('edit_ensayo');
        $this->autorizarVisible($ensayo);

        $data = $request->validate([
            'a' => ['required', 'different:b', Rule::exists('parcelas', 'id')->where('ensayo_id', $ensayo->id)],
            'b' => ['required', Rule::exists('parcelas', 'id')->where('ensayo_id', $ensayo->id)],
        ]);
        $a = Parcela::findOrFail($data['a']);
        $b = Parcela::findOrFail($data['b']);

        if ((int) $a->repeticion !== (int) $b->repeticion) {
            return response()->json(['message' => 'Solo se pueden intercambiar parcelas de la misma repeticion.'], 422);
        }
        if ($error = $this->bloqueoPorEvaluaciones([$a->id, $b->id], 'intercambiar')) {
            return $error;
        }

        DB::transaction(function () use ($a, $b) {
            $t = $a->tratamiento_id;
            $a->update(['tratamiento_id' => $b->tratamiento_id]);
            $b->update(['tratamiento_id' => $t]);
        });

        return response()->json($this->detalle($ensayo));
    }

    protected function bloqueoPorEvaluaciones($ids, string $accion)
    {
        $n = Evaluacion::whereIn('parcela_id', $ids)->count();

        return $n > 0
            ? response()->json(['message' => "No se puede {$accion}: las parcelas tienen {$n} evaluacion(es) registrada(s) con su tratamiento actual."], 422)
            : null;
    }

    /* ------------------- Croquis georreferenciado (GeoPDF) ------------------- */

    /**
     * Sube (o reemplaza) el croquis del ensayo. El georreferenciado lo extrae
     * el navegador del propio GeoPDF (diccionarios /VP /Measure /GPTS, como
     * los que exporta Avenza Maps o QGIS) o lo define el usuario con puntos
     * de control; aqui solo se valida y se guarda.
     */
    public function storeCroquis(Request $request, Ensayo $ensayo)
    {
        Gate::authorize('edit_ensayo');
        $this->autorizarVisible($ensayo);

        $request->validate([
            'archivo' => 'required|file|mimes:pdf,png,jpg,jpeg|max:65536',
            'pagina' => 'nullable|integer|min:1|max:500',
            'georef' => 'nullable|json',
        ], ['archivo.max' => 'El croquis no puede superar 64 MB.']);

        $georef = $request->filled('georef') ? $this->validarGeoref(json_decode($request->input('georef'), true)) : null;
        $file = $request->file('archivo');
        $path = $file->store('croquis', 'public');

        $anterior = $ensayo->croquis;
        $croquis = CroquisGeo::updateOrCreate(['ensayo_id' => $ensayo->id], [
            'nombre' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getMimeType(),
            'tamano' => $file->getSize(),
            'pagina' => (int) $request->input('pagina', 1),
            'georef' => $georef,
            'subido_por' => Auth::id(),
        ]);
        if ($anterior && $anterior->path !== $path) {
            Storage::disk('public')->delete($anterior->path);
        }

        return response()->json($this->detalle($ensayo), $croquis->wasRecentlyCreated ? 201 : 200);
    }

    /** Guarda el georreferenciado manual (puntos de control) del croquis. */
    public function updateCroquis(Request $request, Ensayo $ensayo)
    {
        Gate::authorize('edit_ensayo');
        $this->autorizarVisible($ensayo);

        $croquis = $ensayo->croquis;
        abort_unless($croquis, 404, 'El ensayo no tiene croquis.');

        $data = $request->validate(['georef' => 'required|array']);
        $croquis->update(['georef' => $this->validarGeoref($data['georef'])]);

        return response()->json($this->detalle($ensayo));
    }

    public function destroyCroquis(Ensayo $ensayo)
    {
        Gate::authorize('edit_ensayo');
        $this->autorizarVisible($ensayo);

        if ($croquis = $ensayo->croquis) {
            Storage::disk('public')->delete($croquis->path);
            $croquis->delete();
        }

        return response()->json($this->detalle($ensayo));
    }

    /**
     * Guarda los poligonos de varias parcelas a la vez (dibujados sobre el
     * croquis, generados como rejilla o importados de GeoJSON/KML).
     * geometria = null borra el poligono de esa parcela.
     */
    public function guardarGeometrias(Request $request, Ensayo $ensayo)
    {
        Gate::authorize('edit_ensayo');
        $this->autorizarVisible($ensayo);

        $data = $request->validate([
            'parcelas' => 'required|array|max:1000',
            'parcelas.*.id' => ['required', 'integer', Rule::exists('parcelas', 'id')->where('ensayo_id', $ensayo->id)],
            'parcelas.*.geometria' => 'nullable|array',
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['parcelas'] as $p) {
                Parcela::whereKey($p['id'])->update([
                    'geometria' => $p['geometria'] === null ? null : json_encode($this->validarPoligono($p['geometria'])),
                ]);
            }
        });

        return response()->json($this->detalle($ensayo));
    }

    protected function validarGeoref($g): array
    {
        $ok = is_array($g) && in_array($g['metodo'] ?? null, ['geopdf', 'manual'], true)
            && is_array($g['puntos'] ?? null) && count($g['puntos']) >= 3 && count($g['puntos']) <= 50;
        if ($ok) {
            foreach ($g['puntos'] as $p) {
                $ok = $ok && is_numeric($p['x'] ?? null) && is_numeric($p['y'] ?? null)
                    && is_numeric($p['lat'] ?? null) && is_numeric($p['lng'] ?? null)
                    && abs($p['lat']) <= 90 && abs($p['lng']) <= 180
                    && $p['x'] > -1 && $p['x'] < 2 && $p['y'] > -1 && $p['y'] < 2;
            }
        }
        abort_unless($ok, 422, 'Georreferenciado invalido: se requieren al menos 3 puntos de control con coordenadas validas.');

        return [
            'metodo' => $g['metodo'],
            'puntos' => array_map(fn ($p) => [
                'x' => (float) $p['x'], 'y' => (float) $p['y'], 'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'],
            ], array_values($g['puntos'])),
        ];
    }

    protected function validarPoligono($geo): array
    {
        $anillo = $geo['coordinates'][0] ?? null;
        $ok = ($geo['type'] ?? null) === 'Polygon' && is_array($anillo) && count($anillo) >= 4 && count($anillo) <= 500;
        if ($ok) {
            foreach ($anillo as $c) {
                $ok = $ok && is_array($c) && is_numeric($c[0] ?? null) && is_numeric($c[1] ?? null)
                    && abs($c[0]) <= 180 && abs($c[1]) <= 90;
            }
        }
        abort_unless($ok, 422, 'Poligono de parcela invalido.');

        return ['type' => 'Polygon', 'coordinates' => [array_map(fn ($c) => [round((float) $c[0], 8), round((float) $c[1], 8)], $anillo)]];
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

    /* --------------------- Imagenes NDVI por fecha (mapa) --------------------- */

    /**
     * Guarda una imagen NDVI ya procesada en el navegador (ver ndvi-raster.js):
     * PNG coloreado + esquinas para el Mapa GPS y, si vino de un GeoTIFF, el
     * raster reducido (Uint8) y el NDVI medio por parcela.
     */
    public function storeNdviImagen(Request $request, Ensayo $ensayo)
    {
        Gate::authorize('register_evaluacion');
        $this->autorizarVisible($ensayo);

        $data = $request->validate([
            'fecha' => 'required|date',
            'fuente' => ['nullable', Rule::in(self::FUENTES_NDVI)],
            'tipo' => 'required|in:geotiff,imagen',
            'nombre' => 'required|string|max:255',
            'imagen_geo_id' => ['nullable', Rule::exists('imagenes_geo', 'id')->where('ensayo_id', $ensayo->id)],
            'imagen' => 'required|file|mimes:png,jpg,jpeg|max:30720',
            'imagen_ancho' => 'required|integer|min:1|max:20000',
            'imagen_alto' => 'required|integer|min:1|max:20000',
            'raster' => 'nullable|file|max:20480',
            'esquinas' => 'required|json',
            'meta' => 'nullable|json',
            'valores' => 'nullable|json',
        ], [
            'imagen.mimes' => 'La imagen NDVI debe ser PNG o JPG.',
            'imagen.max' => 'La imagen NDVI no debe superar 30 MB.',
        ]);

        $esquinas = $this->validarEsquinas(json_decode($data['esquinas'], true));
        $dir = "ndvi/{$ensayo->id}";

        NdviImagen::create([
            'ensayo_id' => $ensayo->id,
            'imagen_geo_id' => $data['imagen_geo_id'] ?? null,
            'fecha' => $data['fecha'],
            'fuente' => $data['fuente'] ?? null,
            'tipo' => $data['tipo'],
            'nombre' => $data['nombre'],
            'imagen_path' => $request->file('imagen')->store($dir, 'public'),
            'imagen_ancho' => $data['imagen_ancho'],
            'imagen_alto' => $data['imagen_alto'],
            'raster_path' => $request->hasFile('raster') ? $request->file('raster')->storeAs($dir, Str::random(40).'.bin', 'public') : null,
            'esquinas' => $esquinas,
            'meta' => isset($data['meta']) ? json_decode($data['meta'], true) : null,
            'valores' => isset($data['valores']) ? $this->limpiarValoresNdvi($ensayo, json_decode($data['valores'], true)) : null,
            'subido_por' => Auth::id(),
        ]);

        return response()->json($this->detalle($ensayo), 201);
    }

    /** Fecha/fuente y valores por parcela (recalculados o capturados a mano). */
    public function updateNdviImagen(Request $request, NdviImagen $imagen)
    {
        Gate::authorize('register_evaluacion');
        $this->autorizarVisible($imagen->ensayo);

        $data = $request->validate([
            'fecha' => 'sometimes|required|date',
            'fuente' => ['sometimes', 'nullable', Rule::in(self::FUENTES_NDVI)],
            'valores' => 'sometimes|nullable|array',
        ]);
        if (array_key_exists('valores', $data)) {
            $data['valores'] = $data['valores'] === null ? null : $this->limpiarValoresNdvi($imagen->ensayo, $data['valores']);
        }
        $imagen->update($data);

        return response()->json($this->detalle($imagen->ensayo));
    }

    public function destroyNdviImagen(NdviImagen $imagen)
    {
        Gate::authorize('register_evaluacion');
        $ensayo = $imagen->ensayo;
        $this->autorizarVisible($ensayo);

        Storage::disk('public')->delete(array_filter([$imagen->imagen_path, $imagen->raster_path]));
        $imagen->delete();

        return response()->json($this->detalle($ensayo));
    }

    /** Promedia el NDVI de las parcelas por tratamiento y lo registra en la serie NDVI. */
    public function ndviImagenATratamientos(NdviImagen $imagen)
    {
        Gate::authorize('register_evaluacion');
        $ensayo = $imagen->ensayo;
        $this->autorizarVisible($ensayo);

        $valores = collect($imagen->valores ?: []);
        $porTrat = $ensayo->parcelas()->get(['id', 'tratamiento_id'])
            ->filter(fn ($p) => isset($valores[$p->id]['media']))
            ->groupBy('tratamiento_id')
            ->map(fn ($ps) => $ps->avg(fn ($p) => (float) $valores[$p->id]['media']));
        if ($porTrat->isEmpty()) {
            return response()->json(['message' => 'La imagen no tiene valores NDVI por parcela.'], 422);
        }

        $periodo = $imagen->fecha->format('Y-m-d');
        foreach ($porTrat as $tratamientoId => $media) {
            NdviRegistro::updateOrCreate(
                ['tratamiento_id' => $tratamientoId, 'periodo' => $periodo],
                ['ensayo_id' => $ensayo->id, 'valor' => round(max(0, min(1, $media)), 3), 'fuente' => $imagen->fuente, 'registrado_por' => Auth::id()]
            );
        }

        return response()->json($this->detalle($ensayo));
    }

    protected function validarEsquinas($esq): array
    {
        $out = [];
        foreach (['tl', 'tr', 'bl', 'br'] as $k) {
            $lat = $esq[$k]['lat'] ?? null;
            $lng = $esq[$k]['lng'] ?? null;
            if (! is_numeric($lat) || ! is_numeric($lng) || abs($lat) > 90 || abs($lng) > 180) {
                abort(response()->json(['message' => 'Las esquinas de la imagen NDVI no son coordenadas validas.'], 422));
            }
            $out[$k] = ['lat' => (float) $lat, 'lng' => (float) $lng];
        }

        return $out;
    }

    /** Solo parcelas del ensayo y valores NDVI en -1..1. */
    protected function limpiarValoresNdvi(Ensayo $ensayo, $valores): array
    {
        $ids = $ensayo->parcelas()->pluck('id')->all();
        $out = [];
        foreach ((array) $valores as $id => $v) {
            $v = is_array($v) ? $v : ['media' => $v];
            if (! in_array((int) $id, $ids, true) || ! is_numeric($v['media'] ?? null)) {
                continue;
            }
            $r = fn ($x) => is_numeric($x) ? round(max(-1, min(1, (float) $x)), 4) : null;
            $out[(int) $id] = array_filter([
                'media' => $r($v['media']),
                'min' => $r($v['min'] ?? null),
                'max' => $r($v['max'] ?? null),
                'n' => isset($v['n']) ? (int) $v['n'] : null,
            ], fn ($x) => $x !== null);
        }

        return $out;
    }

    /* ------------------------------- Helpers ------------------------------- */

    protected function validar(Request $request, ?Ensayo $ensayo = null): array
    {
        $reglas = $this->reglasValidacion;

        $data = $request->validate($reglas, [
            'fecha_cosecha_prevista.after_or_equal' => 'La cosecha prevista no puede ser anterior a la siembra.',
            'fecha_cosecha.after_or_equal' => 'La cosecha no puede ser anterior a la siembra.',
        ]);

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

    /**
     * Ajusta las parcelas de un tratamiento a sus unidades (repeticiones):
     * crea las que faltan con el siguiente codigo P-nn libre del ensayo y
     * elimina las repeticiones sobrantes (las mas altas) si no tienen
     * evaluaciones registradas.
     */
    protected function sincronizarParcelasTratamiento(Tratamiento $tratamiento): void
    {
        $objetivo = (int) $tratamiento->unidades;
        if ($objetivo < 1) {
            return;
        }

        $ensayo = $tratamiento->ensayo;
        $actuales = $tratamiento->parcelas()->orderBy('repeticion')->orderBy('id')->get();

        if ($actuales->count() > $objetivo) {
            $sobrantes = $actuales->slice($objetivo);
            $evaluaciones = Evaluacion::whereIn('parcela_id', $sobrantes->pluck('id'))->count();
            if ($evaluaciones > 0) {
                abort(response()->json([
                    'message' => "No se pueden quitar repeticiones de {$tratamiento->codigo}: las parcelas a eliminar tienen {$evaluaciones} evaluacion(es) registrada(s).",
                ], 422));
            }
            Parcela::whereIn('id', $sobrantes->pluck('id'))->delete();
        } elseif ($actuales->count() < $objetivo) {
            $siguiente = $ensayo->parcelas()->pluck('codigo')
                ->map(fn ($c) => preg_match('/(\d+)\s*$/', (string) $c, $m) ? (int) $m[1] : 0)
                ->max() + 1;
            $usadas = $actuales->pluck('repeticion')->map(fn ($r) => (int) $r)->all();

            $rep = 1;
            for ($i = $actuales->count(); $i < $objetivo; $i++) {
                while (in_array($rep, $usadas, true)) {
                    $rep++;
                }
                $usadas[] = $rep;
                Parcela::create([
                    'ensayo_id' => $ensayo->id,
                    'tratamiento_id' => $tratamiento->id,
                    'codigo' => 'P-'.str_pad($siguiente++, 2, '0', STR_PAD_LEFT),
                    'repeticion' => $rep,
                    // Se agrega al final del bloque; luego puede intercambiarse en el croquis.
                    'posicion' => (int) $ensayo->parcelas()->where('repeticion', $rep)->max('posicion') + 1,
                    'area' => $ensayo->area_parcela,
                ]);
            }
        }

        $maxRep = (int) $ensayo->parcelas()->max('repeticion');
        if ($maxRep > (int) $ensayo->num_repeticiones) {
            $ensayo->update(['num_repeticiones' => $maxRep]);
        }
    }

    protected function sincronizarNumTratamientos(Ensayo $ensayo): void
    {
        $ensayo->update(['num_tratamientos' => $ensayo->tratamientos()->count()]);
    }

    protected function conConteos($query)
    {
        // Avance: evaluaciones registradas (y la mas reciente) y muestras de laboratorio.
        return $query->withCount([
            'tratamientos', 'parcelas', 'archivos', 'bitacora', 'evaluaciones', 'muestras',
            'muestras as muestras_completadas_count' => fn ($q) => $q->where('estado', 'Completado'),
        ])
            ->withMax('evaluaciones as ultima_evaluacion', 'evaluaciones.fecha')
            ->withMax('muestras as ultimo_muestreo', 'fecha');
    }

    protected function fila(Ensayo $ensayo): Ensayo
    {
        return $this->conHitos($this->conConteos(Ensayo::whereKey($ensayo->id))
            ->with(['proyecto.programa:id,nombre,sigla,color', 'ingenio', 'responsable'])
            ->get())->first();
    }

    /**
     * Fechas de evaluacion y de muestreo de cada ensayo (vista "Ciclo del cultivo"):
     * una entrada por dia, con cuantos valores / muestras se registraron.
     */
    protected function conHitos($ensayos)
    {
        $ids = $ensayos->pluck('id');
        $evaluaciones = DB::table('evaluaciones as v')->join('parcelas as p', 'p.id', '=', 'v.parcela_id')
            ->whereIn('p.ensayo_id', $ids)->groupBy('p.ensayo_id', 'v.fecha')
            ->selectRaw('p.ensayo_id, v.fecha, COUNT(*) as n, COUNT(DISTINCT v.variable_id) as variables')
            ->orderBy('v.fecha')->get()->groupBy('ensayo_id');
        $muestreos = DB::table('muestras_lab')->whereIn('ensayo_id', $ids)->groupBy('ensayo_id', 'fecha', 'tipo')
            ->selectRaw('ensayo_id, fecha, tipo, COUNT(*) as n')
            ->orderBy('fecha')->get()->groupBy('ensayo_id');

        return $ensayos->each(fn ($e) => $e->setAttribute('hitos', [
            'evaluaciones' => $evaluaciones->get($e->id, collect())->map(fn ($r) => ['fecha' => substr($r->fecha, 0, 10), 'n' => (int) $r->n, 'variables' => (int) $r->variables])->values(),
            'muestreos' => $muestreos->get($e->id, collect())->map(fn ($r) => ['fecha' => substr($r->fecha, 0, 10), 'tipo' => $r->tipo, 'n' => (int) $r->n])->values(),
        ]));
    }

    protected function detalle(Ensayo $ensayo): array
    {
        $ensayo = $this->fila($ensayo);

        return [
            'ensayo' => $ensayo,
            'tratamientos' => $ensayo->tratamientos()->withCount('parcelas')->orderBy('id')->get(),
            'parcelas' => $ensayo->parcelas()->with('tratamiento:id,codigo')->withCount('evaluaciones')->orderBy('repeticion')->orderBy('posicion')->orderBy('id')->get(),
            'ndvi' => $ensayo->ndvi()->with('tratamiento:id,codigo')->orderBy('periodo')->get(),
            'ndvi_imagenes' => $ensayo->ndviImagenes()->orderBy('fecha')->orderBy('id')->get(),
            // Imagenes ya cargadas en "Imagenes Geoespaciales" para este ensayo,
            // que se pueden procesar como NDVI sin volver a subirlas.
            'imagenes_geo' => ImagenGeo::where('ensayo_id', $ensayo->id)->orderByDesc('fecha')->orderByDesc('id')
                ->get(['id', 'nombre', 'tipo', 'fecha', 'sensor', 'resolucion', 'bandas', 'path'])
                ->map(fn ($g) => array_merge($g->toArray(), ['fecha' => optional($g->fecha)->format('Y-m-d')])),
            'archivos' => $ensayo->archivos()->with('subidoPor:id,name')->orderByDesc('fecha')->orderByDesc('id')->get(),
            'bitacora' => $ensayo->bitacora()->with('responsable:id,name')->orderByDesc('fecha')->orderByDesc('id')->get(),
            'croquis' => $ensayo->croquis,
        ];
    }
}
