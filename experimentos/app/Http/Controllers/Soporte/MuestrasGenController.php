<?php

namespace App\Http\Controllers\Soporte;

use App\Http\Controllers\Controller;
use App\Models\AnaliticoPorTipo;
use App\Models\CampoMuestra;
use App\Models\Ensayo;
use App\Models\MuestraLab;
use App\Models\Parcela;
use App\Models\Proyecto;
use App\Models\Tratamiento;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Generacion de ID de muestras: una sesion de muestreo (ensayo, tipo y fecha)
 * produce un lote de muestras con ID unico y etiquetas QR. Las muestras quedan
 * en Laboratorio en estado "Recibida" para cargar sus resultados.
 */
class MuestrasGenController extends Controller
{
    /** Datos base de toda muestra (columnas propias), no configurables. */
    const CLAVES_BASE = ['fecha', 'finca', 'lote', 'loteCampo', 'parcela', 'tratamiento', 'repeticion', 'obs', 'ensayo'];

    /** Datos base que llena el muestreador y se pueden ajustar por tipo (mostrar, obligatorio, etiqueta). */
    const BASE_EDITABLES = ['parcela' => 'texto', 'tratamiento' => 'texto', 'repeticion' => 'numero', 'obs' => 'texto'];

    public function index()
    {
        Gate::authorize('create_muestra');

        $user = Auth::user();
        $ensayoIds = Ensayo::visiblesPara($user)->pluck('id');
        $ensayos = Ensayo::whereIn('id', $ensayoIds)->orderBy('codigo')->get(['id', 'codigo', 'proyecto_id', 'finca', 'lote', 'variedad']);

        $tipos = AnaliticoPorTipo::select('tipo_muestra')->distinct()->orderBy('tipo_muestra')->pluck('tipo_muestra');
        if ($tipos->isEmpty()) {
            $tipos = collect(['Suelo', 'Tejido vegetal', 'Jugos', 'Mieles', 'Agua', 'Fertilizantes']);
        }

        return view('component', [
            'title' => 'Generacion de ID de Muestras',
            'component' => 'muestras-gen-index',
            'params' => [
                'proyectos' => Proyecto::visiblesPara($user)->orderBy('codigo')->get(['id', 'codigo', 'nombre']),
                'ensayos' => $ensayos,
                'tratamientos' => Tratamiento::whereIn('ensayo_id', $ensayoIds)->orderBy('codigo')->get(['id', 'ensayo_id', 'codigo', 'descripcion']),
                'parcelas' => Parcela::whereIn('ensayo_id', $ensayoIds)->orderBy('codigo')->get(['id', 'ensayo_id', 'tratamiento_id', 'codigo', 'repeticion']),
                'tipos' => $tipos->map(fn ($t) => [
                    'nombre' => $t,
                    'codigo' => LaboratorioController::tipoCodigo($t),
                    'analitos' => AnaliticoPorTipo::where('tipo_muestra', $t)->count(),
                ])->values(),
                // Ultima secuencia usada por prefijo y año, para mostrar los IDs antes de guardar.
                'secuencias' => (object) $this->secuencias($ensayoIds),
                'recientes' => $this->recientes($ensayoIds),
                'campos' => CampoMuestra::orderBy('tipo_muestra')->orderBy('orden')->get(),
                'puedeConfigurar' => $user->can('admin_only'),
            ],
        ]);
    }

    /* ======================= Campos por tipo de muestra ======================= */

    public function guardarCampo(Request $request, ?CampoMuestra $campo = null)
    {
        Gate::authorize('admin_only');

        $data = $request->validate([
            'tipo_muestra' => 'required|string|max:60',
            'etiqueta' => 'required|string|max:100',
            'clave' => 'nullable|string|max:40|regex:/^[a-zA-Z][a-zA-Z0-9_]*$/',
            'tipo_dato' => 'required|in:texto,numero,fecha,opciones',
            'unidad' => 'nullable|string|max:30',
            'opciones' => 'nullable|array',
            'opciones.*' => 'nullable|string|max:80',
            'ayuda' => 'nullable|string|max:200',
            'obligatorio' => 'boolean',
            'activo' => 'boolean',
            'orden' => 'nullable|integer|min:0|max:9999',
            'predefinido' => 'boolean',
        ], ['clave.regex' => 'El nombre tecnico solo admite letras, numeros y guion bajo, y empieza con letra.']);

        // Dato base (parcela, tratamiento, repeticion, observaciones): solo se ajusta
        // si se muestra, si es obligatorio y su etiqueta. Un registro por tipo y clave.
        $esBase = $request->boolean('predefinido') || ($campo && $campo->exists && $campo->predefinido);
        if ($esBase) {
            $clave = $campo && $campo->exists ? $campo->clave : ($data['clave'] ?? '');
            $tipo = $campo && $campo->exists ? $campo->tipo_muestra : $data['tipo_muestra'];
            if (! isset(self::BASE_EDITABLES[$clave])) {
                throw ValidationException::withMessages(['clave' => ['Ese dato predefinido no se puede ajustar.']]);
            }
            $base = CampoMuestra::updateOrCreate(['tipo_muestra' => $tipo, 'clave' => $clave], [
                'etiqueta' => $data['etiqueta'],
                'tipo_dato' => self::BASE_EDITABLES[$clave],
                'obligatorio' => (bool) ($data['obligatorio'] ?? false),
                'activo' => (bool) ($data['activo'] ?? true),
                'orden' => 0,
                'predefinido' => true,
                'unidad' => null, 'opciones' => null, 'ayuda' => $data['ayuda'] ?? null,
            ]);

            return response()->json($base->fresh());
        }

        // Nombre tecnico: se deriva de la etiqueta y no cambia despues (los datos guardados lo usan).
        if ($campo && $campo->exists) {
            $data['clave'] = $campo->clave;
            $data['tipo_muestra'] = $campo->tipo_muestra;
        } else {
            $data['clave'] = ($data['clave'] ?? null) ?: lcfirst(str_replace(' ', '', ucwords(preg_replace('/[^a-z0-9 ]/', '', Str::lower(Str::ascii($data['etiqueta']))))));
            if (in_array($data['clave'], self::CLAVES_BASE, true) || CampoMuestra::where('tipo_muestra', $data['tipo_muestra'])->where('clave', $data['clave'])->exists()) {
                throw ValidationException::withMessages(['clave' => ["Ya existe un campo \"{$data['clave']}\" para {$data['tipo_muestra']}."]]);
            }
        }
        $data['opciones'] = $data['tipo_dato'] === 'opciones' ? array_values(array_filter(array_map('trim', $data['opciones'] ?? []), 'strlen')) : null;
        if ($data['tipo_dato'] === 'opciones' && ! $data['opciones']) {
            throw ValidationException::withMessages(['opciones' => ['Defina al menos una opcion.']]);
        }
        $data['orden'] = $data['orden'] ?? ((int) CampoMuestra::where('tipo_muestra', $data['tipo_muestra'])->max('orden') + 10);

        $campo = $campo && $campo->exists ? tap($campo)->update($data) : CampoMuestra::create($data);

        return response()->json($campo->fresh());
    }

    public function eliminarCampo(CampoMuestra $campo)
    {
        Gate::authorize('admin_only');

        if ($campo->predefinido) {
            return response()->json(['message' => 'Los datos predefinidos no se eliminan; puede ocultarlos (inactivo).'], 422);
        }
        $usos = MuestraLab::where('tipo', $campo->tipo_muestra)->whereNotNull("datos->{$campo->clave}")->count();
        if ($usos) {
            return response()->json(['message' => "El campo tiene datos en {$usos} muestra(s); desactivelo en lugar de eliminarlo."], 422);
        }
        $campo->delete();

        return response()->json(['ok' => true]);
    }

    /** Valida los datos personalizados de una fila contra los campos activos del tipo. */
    protected function validarDatos($campos, array $entrada, int $i, array &$errores): ?array
    {
        $datos = [];
        foreach ($campos as $c) {
            $val = trim((string) ($entrada[$c->clave] ?? ''));
            if ($val === '') {
                if ($c->obligatorio) {
                    $errores["filas.$i.datos.{$c->clave}"] = ["{$c->etiqueta} es obligatorio (fila " . ($i + 1) . ').'];
                }
                continue;
            }
            try {
                $datos[$c->clave] = $c->normalizar($val);
            } catch (\InvalidArgumentException $e) {
                $errores["filas.$i.datos.{$c->clave}"] = [$e->getMessage() . ' (fila ' . ($i + 1) . ')'];
            }
        }

        return $datos ?: null;
    }

    public function store(Request $request)
    {
        Gate::authorize('create_muestra');

        $data = $request->validate([
            'ensayo_id' => 'required|integer|exists:ensayos,id',
            'tipo' => 'required|string|max:60',
            'fecha' => 'required|date|before_or_equal:today',
            'filas' => 'required|array|min:1|max:500',
            'filas.*.tratamiento_id' => 'nullable|integer',
            'filas.*.parcela_id' => 'nullable|integer',
            'filas.*.repeticion' => 'nullable|integer|min:1|max:99',
            'filas.*.obs' => 'nullable|string|max:500',
            'filas.*.datos' => 'nullable|array',
        ], [
            'fecha.before_or_equal' => 'La fecha de muestreo no puede ser futura.',
            'filas.required' => 'Agregue al menos una muestra.',
        ]);

        $ensayo = Ensayo::visiblesPara(Auth::user())->whereKey($data['ensayo_id'])->first();
        abort_unless($ensayo, 403);

        $parcelas = Parcela::where('ensayo_id', $ensayo->id)->get(['id', 'tratamiento_id', 'repeticion', 'codigo'])->keyBy('id');
        $tratamientos = Tratamiento::where('ensayo_id', $ensayo->id)->pluck('id')->flip();
        $config = CampoMuestra::where('tipo_muestra', $data['tipo'])->orderBy('orden')->get();
        $campos = $config->where('predefinido', false)->where('activo', true)->values();
        // Datos base obligatorios para este tipo (los ocultos no se exigen).
        $baseOblig = $config->where('predefinido', true)->where('activo', true)->where('obligatorio', true)->keyBy('clave');

        $filas = [];
        $errores = [];
        foreach ($data['filas'] as $i => $f) {
            $parcela = ! empty($f['parcela_id']) ? $parcelas->get($f['parcela_id']) : null;
            if (! empty($f['parcela_id']) && ! $parcela) {
                $errores["filas.$i.parcela_id"] = ['La parcela no pertenece al ensayo.'];
                continue;
            }
            $tratId = $f['tratamiento_id'] ?? null;
            if ($tratId && ! isset($tratamientos[$tratId])) {
                $errores["filas.$i.tratamiento_id"] = ['El tratamiento no pertenece al ensayo.'];
                continue;
            }
            // La parcela define su tratamiento y repeticion.
            $fila = [
                'parcela_id' => $parcela ? $parcela->id : null,
                'tratamiento_id' => $parcela ? $parcela->tratamiento_id : $tratId,
                'repeticion' => $parcela ? $parcela->repeticion : ($f['repeticion'] ?? null),
                'obs' => trim((string) ($f['obs'] ?? '')) ?: null,
                'datos' => $this->validarDatos($campos, $f['datos'] ?? [], $i, $errores),
            ];
            $columna = ['parcela' => 'parcela_id', 'tratamiento' => 'tratamiento_id', 'repeticion' => 'repeticion', 'obs' => 'obs'];
            foreach ($baseOblig as $clave => $c) {
                if (empty($fila[$columna[$clave]])) {
                    $errores["filas.$i.{$columna[$clave]}"] = ["{$c->etiqueta} es obligatorio (fila " . ($i + 1) . ').'];
                }
            }
            $filas[] = $fila;
        }
        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        $prefijo = LaboratorioController::prefijoId($ensayo, $data['tipo']);
        $anio = Carbon::parse($data['fecha'])->format('y');

        $creadas = DB::transaction(function () use ($filas, $data, $ensayo, $prefijo, $anio) {
            // Bloquea las muestras del ensayo para que dos lotes simultaneos no tomen la misma secuencia.
            MuestraLab::where('ensayo_id', $ensayo->id)->lockForUpdate()->get(['id']);
            $sec = LaboratorioController::ultimaSecuencia($prefijo, $anio);
            $out = [];
            foreach ($filas as $f) {
                $out[] = MuestraLab::create($f + [
                    'id_muestra' => sprintf('%s%03d-%s', $prefijo, ++$sec, $anio),
                    'fecha' => $data['fecha'],
                    'tipo' => $data['tipo'],
                    'proyecto_id' => $ensayo->proyecto_id,
                    'ensayo_id' => $ensayo->id,
                    'finca' => $ensayo->finca,
                    'lote' => $ensayo->lote,
                    'estado' => 'Recibida',
                    'solicitante_id' => Auth::id(),
                ]);
            }

            return $out;
        });

        return response()->json(collect($creadas)->map(fn ($m) => $this->dto($m->load(['tratamiento:id,codigo', 'parcela:id,codigo', 'solicitante:id,name'])))->values(), 201);
    }

    /** Muestras de un lote anterior para reimprimir sus etiquetas. */
    public function lote(Request $request)
    {
        Gate::authorize('create_muestra');
        $ids = $request->validate(['ids' => 'required|array|max:500', 'ids.*' => 'integer'])['ids'];
        $ensayoIds = Ensayo::visiblesPara(Auth::user())->pluck('id');

        return response()->json(
            MuestraLab::with(['tratamiento:id,codigo', 'parcela:id,codigo', 'solicitante:id,name'])
                ->whereIn('id', $ids)->whereIn('ensayo_id', $ensayoIds)->orderBy('id_muestra')->get()
                ->map(fn ($m) => $this->dto($m))
        );
    }

    protected function secuencias($ensayoIds): array
    {
        $out = [];
        MuestraLab::whereIn('ensayo_id', $ensayoIds)->pluck('id_muestra')->each(function ($id) use (&$out) {
            if (preg_match('/^(.*-)(\d+)-(\d{2})$/', $id, $m)) {
                $k = $m[1] . '|' . $m[3];
                $out[$k] = max($out[$k] ?? 0, (int) $m[2]);
            }
        });

        return $out;
    }

    /** Ultimos lotes: muestras agrupadas por ensayo, tipo, fecha y momento de creacion. */
    protected function recientes($ensayoIds)
    {
        return MuestraLab::with(['ensayo:id,codigo', 'solicitante:id,name'])
            ->whereIn('ensayo_id', $ensayoIds)
            ->orderByDesc('created_at')
            ->limit(400)
            ->get(['id', 'id_muestra', 'ensayo_id', 'tipo', 'fecha', 'estado', 'solicitante_id', 'created_at'])
            ->groupBy(fn ($m) => $m->ensayo_id . '|' . $m->tipo . '|' . $m->fecha->format('Y-m-d') . '|' . $m->solicitante_id . '|' . $m->created_at->format('Y-m-d H:i'))
            ->take(12)
            ->map(fn ($g) => [
                'ensayo' => optional($g->first()->ensayo)->codigo,
                'tipo' => $g->first()->tipo,
                'fecha' => $g->first()->fecha->format('Y-m-d'),
                'creado' => $g->first()->created_at->format('Y-m-d H:i'),
                'solicitante' => optional($g->first()->solicitante)->name,
                'ids' => $g->pluck('id')->values(),
                'desde' => $g->min('id_muestra'),
                'hasta' => $g->max('id_muestra'),
                'n' => $g->count(),
                'con_resultado' => $g->where('estado', 'Completado')->count(),
            ])->values();
    }

    protected function dto(MuestraLab $m): array
    {
        return [
            'id' => $m->id,
            'id_muestra' => $m->id_muestra,
            'tipo' => $m->tipo,
            'fecha' => $m->fecha->format('Y-m-d'),
            'ensayo_id' => $m->ensayo_id,
            'parcela' => optional($m->parcela)->codigo,
            'tratamiento' => optional($m->tratamiento)->codigo,
            'repeticion' => $m->repeticion,
            'obs' => $m->obs,
            'datos' => (object) ($m->datos ?: []),
            'estado' => $m->estado,
            'solicitante' => optional($m->solicitante)->name,
        ];
    }
}
