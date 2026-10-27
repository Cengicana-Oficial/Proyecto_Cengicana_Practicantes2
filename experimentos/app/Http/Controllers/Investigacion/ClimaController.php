<?php

namespace App\Http\Controllers\Investigacion;

use App\Http\Controllers\Controller;
use App\Imports\FilasImport;
use App\Imports\PrimeraHojaImport;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\Models\ClimaDiario;
use App\Models\Configuracion;
use App\Models\EstacionMeteo;
use App\Services\ClimaCalidad;
use App\Services\IccClima;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Pestana "Clima" del detalle de ensayo: catalogo de estaciones (red ICC /
 * REDMET u otras), consulta de registros diarios por periodo e importacion
 * de los archivos que exporta el portal REDMET. Cuando una estacion no tiene
 * datos importados para el periodo, el front consulta Open-Meteo en las
 * coordenadas de la estacion (ver ensayos/detalle.vue).
 */
class ClimaController extends Controller
{
    const REDES = ['ICC REDMET', 'INSIVUMEH', 'Ingenio', 'Otra'];

    /** Estratos altitudinales y zonas de la zona cañera (tableros de clima de Cengicaña). */
    const ESTRATOS = ['ALTO', 'MEDIO', 'BAJO', 'LITORAL'];

    const ZONAS = ['OESTE', 'CENTRO-OESTE', 'CENTRO', 'CENTRO-ESTE', 'ESTE', 'ESTE-ESTE'];

    /** Variables del analisis comparativo; las "sumables" se acumulan por periodo. */
    const VARIABLES_ANALISIS = ['lluvia', 'eto', 'gdd', 'radiacion', 'tmed', 'tmax', 'tmin', 'hr', 'vpd', 'viento'];

    const SUMABLES = ['lluvia', 'eto', 'gdd'];

    /** Variables diarias almacenadas, en el orden de exportacion. */
    const VARIABLES = ['lluvia', 'tmax', 'tmin', 'tmed', 'hr', 'hr_min', 'hr_max', 'viento', 'radiacion', 'eto'];

    public function estaciones(Request $request)
    {
        $lat = $request->filled('lat') ? (float) $request->query('lat') : null;
        $lng = $request->filled('lng') ? (float) $request->query('lng') : null;

        $estaciones = EstacionMeteo::withCount('registros')
            ->withMin('registros', 'fecha')
            ->withMax('registros', 'fecha')
            ->orderBy('nombre')
            ->get()
            ->map(function ($e) use ($lat, $lng) {
                $e->distancia_km = ($lat !== null && $lng !== null && $e->lat !== null && $e->lng !== null)
                    ? round($this->distanciaKm($lat, $lng, $e->lat, $e->lng), 1) : null;
                return $e;
            });

        if ($lat !== null && $lng !== null) {
            // Las estaciones sin coordenadas (creadas desde la API) van al final.
            $estaciones = $estaciones->sortBy(fn ($e) => $e->distancia_km ?? INF)->values();
        }

        return response()->json(['estaciones' => $estaciones, 'redes' => self::REDES, 'estratos' => self::ESTRATOS, 'zonas' => self::ZONAS]);
    }

    public function store(Request $request)
    {
        Gate::authorize('admin_only');

        $estacion = EstacionMeteo::create($this->validar($request));

        return response()->json($estacion, 201);
    }

    public function update(Request $request, EstacionMeteo $estacion)
    {
        Gate::authorize('admin_only');

        $estacion->update($this->validar($request, $estacion));

        return response()->json($estacion);
    }

    public function destroy(EstacionMeteo $estacion)
    {
        Gate::authorize('admin_only');

        $estacion->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Registros diarios de la estacion en [desde, hasta]. Si falta la ETo
     * pero hay Tmax/Tmin, se estima con Hargreaves-Samani (eto_estimada).
     */
    public function datos(Request $request, EstacionMeteo $estacion, IccClima $icc)
    {
        $data = $request->validate([
            'desde' => 'required|date',
            'hasta' => 'required|date|after_or_equal:desde',
            'sincronizar' => 'nullable|boolean',
        ]);

        // Estaciones ICC: lo que falte del periodo se trae de la API antes de responder.
        $sync = ['usa_api' => $this->usaApi($estacion, $icc), 'consultada' => false];
        if ($sync['usa_api'] && $request->boolean('sincronizar', true)) {
            try {
                $sync = array_merge($sync, $this->completarIcc($icc, Carbon::parse($data['desde'])->startOfDay(), Carbon::parse($data['hasta'])->startOfDay()));
            } catch (RuntimeException $e) {
                $sync['error'] = $e->getMessage();
            }
            $estacion->refresh();
        }

        $filas = ClimaDiario::where('estacion_id', $estacion->id)
            ->whereBetween('fecha', [$data['desde'], $data['hasta']])
            ->orderBy('fecha')
            ->get(array_merge(['fecha'], self::VARIABLES))
            ->map(function ($r) use ($estacion) {
                $fila = $r->toArray();
                $fila['eto_estimada'] = false;
                if ($fila['eto'] === null && $fila['tmax'] !== null && $fila['tmin'] !== null && $estacion->lat !== null) {
                    $fila['eto'] = $this->etoHargreaves($fila['tmax'], $fila['tmin'], $fila['tmed'], $estacion->lat, Carbon::parse($fila['fecha']));
                    $fila['eto_estimada'] = true;
                }
                return $fila;
            });

        return response()->json([
            'estacion' => $estacion,
            'dias' => $filas,
            'sync' => $sync,
        ]);
    }

    /**
     * Importa un archivo exportado de REDMET (o cualquier CSV/Excel con
     * encabezados reconocibles). Acepta datos diarios o subdiarios (p. ej.
     * cada 15 min) y los agrega a un registro por dia; reimportar el mismo
     * periodo reemplaza los valores.
     */
    public function importar(Request $request, EstacionMeteo $estacion)
    {
        Gate::authorize('upload_files');

        $request->validate(['archivo' => 'required|file|mimes:xlsx,xls,csv,txt|max:20480']);

        $import = new FilasImport();
        Excel::import($import, $request->file('archivo'));
        $filas = $import->filas ?? collect();

        if ($filas->isEmpty()) {
            return response()->json(['message' => 'El archivo no tiene filas de datos.'], 422);
        }

        try {
            $res = $this->guardarFilas($estacion, $filas->map(fn ($f) => $f->toArray())->all());
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        unset($res['fechas']);
        // Control de calidad de lo importado.
        $q = app(ClimaCalidad::class)->revisar(Carbon::parse($res['desde']), Carbon::parse($res['hasta']), [$estacion->id]);
        $res['excluidos'] = $q['excluidos'];
        $res['atipicos'] = $q['atipicos'];

        return response()->json($res);
    }

    /* ----------------------------- API del ICC ----------------------------- */

    /** Estado de la conexion y cobertura descargada (dias consultados a la API). */
    public function iccEstado(IccClima $icc)
    {
        $cob = DB::table('clima_icc_fechas')->selectRaw('MIN(fecha) desde, MAX(fecha) hasta, COUNT(*) dias, MAX(consultado_en) ultima')->first();

        return response()->json(array_merge($icc->resumen(), [
            'cobertura' => $cob && $cob->dias ? (array) $cob : null,
            // Dias ya consultados a la API por año (para la descarga del historial).
            'por_anio' => DB::table('clima_icc_fechas')->selectRaw('YEAR(fecha) anio, COUNT(*) dias, SUM(estaciones > 0) con_datos')
                ->groupBy('anio')->orderBy('anio')->get()
                ->map(fn ($r) => ['anio' => (int) $r->anio, 'dias' => (int) $r->dias, 'con_datos' => (int) $r->con_datos]),
            'estaciones' => EstacionMeteo::whereNotNull('codigo_api')->count(),
            'sin_coordenadas' => EstacionMeteo::whereNotNull('codigo_api')->whereNull('lat')->count(),
        ]));
    }

    /** Configuracion de la conexion (sin secretos). */
    public function iccConfig(IccClima $icc)
    {
        Gate::authorize('admin_only');

        return response()->json($icc->configuracionPublica());
    }

    /** Guarda la conexion; token/clave vacios conservan los guardados. */
    public function iccConfigGuardar(Request $request)
    {
        Gate::authorize('admin_only');

        $data = $this->validarConexion($request);
        foreach (IccClima::CAMPOS as $k) {
            if (in_array($k, IccClima::SECRETOS, true)) {
                if ($request->boolean("borrar_{$k}")) {
                    Configuracion::poner("icc.{$k}", null);
                } elseif (! empty($data[$k])) {
                    Configuracion::poner("icc.{$k}", $data[$k], true);
                }
                continue;
            }
            Configuracion::poner("icc.{$k}", array_key_exists($k, $data) ? (is_bool($data[$k]) ? ($data[$k] ? '1' : '0') : $data[$k]) : null);
        }

        return response()->json((new IccClima())->configuracionPublica());
    }

    /** Prueba una configuracion (la del formulario, sin guardarla) pidiendo un dia de datos. */
    public function iccProbar(Request $request)
    {
        Gate::authorize('admin_only');

        $data = $this->validarConexion($request);
        $request->validate(['fecha' => 'nullable|date|before_or_equal:today']);
        foreach (IccClima::SECRETOS as $k) {
            if (empty($data[$k]) && ! $request->boolean("borrar_{$k}")) {
                unset($data[$k]); // usa el guardado
            }
        }
        $data = array_map(fn ($v) => is_bool($v) ? ($v ? '1' : '0') : $v, $data);

        try {
            $r = (new IccClima($data))->probar(Carbon::parse($request->input('fecha', Carbon::yesterday()->format('Y-m-d'))));
        } catch (RuntimeException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }

        return response()->json(['ok' => true] + $r);
    }

    protected function validarConexion(Request $request): array
    {
        return $request->validate([
            'url' => ['required', 'url', 'regex:#^https?://#i', 'max:255'],
            'auth' => 'required|in:bearer,header,basic,query,none',
            'token' => 'nullable|string|max:2000',
            'header' => 'nullable|string|max:100',
            'query_param' => 'nullable|string|max:100',
            'usuario' => 'nullable|string|max:150',
            'clave' => 'nullable|string|max:255',
            'ruta_datos' => ['required', 'string', 'max:255', 'regex:/\{desde\}.*\{hasta\}|\{hasta\}.*\{desde\}/'],
            'dias_por_consulta' => 'required|integer|min:1|max:366',
            'verificar_ssl' => 'boolean',
            'viento_unidad' => 'required|in:kmh,ms',
        ], [
            'url.url' => 'La URL del servidor no es valida (p. ej. https://servidor.icc.org.gt).',
            'ruta_datos.regex' => 'La ruta debe incluir {desde} y {hasta}.',
        ]);
    }

    /**
     * Descarga de la API un rango de fechas (todas las estaciones). Con
     * "forzar" vuelve a pedir dias ya descargados (p. ej. tras correcciones
     * del control de calidad del ICC).
     */
    public function iccSincronizar(Request $request, IccClima $icc)
    {
        $data = $request->validate([
            'desde' => 'required|date',
            'hasta' => 'required|date|after_or_equal:desde',
            'forzar' => 'nullable|boolean',
            'tipo' => 'nullable|in:actualizar,historial,redescarga,automatica',
        ]);
        $tipo = $data['tipo'] ?? 'actualizar';
        $desde = Carbon::parse($data['desde'])->startOfDay();
        $hasta = Carbon::parse($data['hasta'])->startOfDay();
        // La actualizacion automatica (ultimos dias, al abrir Clima) la puede disparar cualquier usuario.
        if ($tipo === 'automatica') {
            if ($desde->lt(Carbon::today()->subDays(10))) {
                abort(403);
            }
        } else {
            Gate::authorize('upload_files');
        }
        if ($desde->diffInDays($hasta) > 1100) {
            return response()->json(['message' => 'Sincronice como maximo 3 años por vez.'], 422);
        }

        try {
            $res = $this->completarIcc($icc, $desde, $hasta, $request->boolean('forzar'), $tipo);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json($res);
    }

    /** Pagina "Clima ICC": consulta y exportacion filtrando estaciones y fechas. */
    public function pagina(IccClima $icc)
    {
        $user = Auth::user();

        return view('component', [
            'title' => 'Clima · Red de estaciones',
            'component' => 'clima-index',
            'params' => [
                'puedeSincronizar' => $user->can('upload_files'),
                'puedeGestionar' => $user->can('admin_only'),
            ],
        ]);
    }

    /**
     * Plantilla Excel para importar el listado de estaciones: hoja
     * "Estaciones" (con las estaciones actuales, salvo ?vacia=1) con listas
     * desplegables para Estrato, Zona, Red y Activa, y hoja "Instrucciones".
     */
    public function plantillaCatalogo(Request $request)
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet()->setTitle('Estaciones');
        $enc = ['Codigo', 'Estacionid', 'Nombre', 'Latitud', 'Longitud', 'Altitud', 'Estrato', 'Zona', 'Ubicacion', 'Red', 'Activa'];
        $hoja->fromArray($enc, null, 'A1');

        $filas = $request->boolean('vacia') ? collect() : EstacionMeteo::orderBy('nombre')->get();
        $r = 2;
        foreach ($filas as $e) {
            $hoja->fromArray([
                $e->codigo, $e->codigo_api, $e->nombre, $e->lat, $e->lng, $e->altitud, $e->estrato, $e->zona, $e->ubicacion, $e->red, $e->activa ? 'si' : 'no',
            ], null, 'A'.$r++);
        }
        // El Estacionid se guarda como texto (en la API es un identificador, no un numero a sumar).
        $hoja->getStyle('B2:B'.max(2, $r + 500))->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $hoja->getStyle('D2:E'.max(2, $r + 500))->getNumberFormat()->setFormatCode('0.000000');

        // Encabezado
        $hoja->getStyle('A1:K1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F5632']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $hoja->getStyle('D1:E1')->getFill()->setStartColor(new Color('FF6B00')); // las coordenadas son obligatorias
        $hoja->getRowDimension(1)->setRowHeight(22);
        foreach (['A' => 14, 'B' => 12, 'C' => 30, 'D' => 13, 'E' => 13, 'F' => 10, 'G' => 12, 'H' => 15, 'I' => 30, 'J' => 14, 'K' => 9] as $c => $w) {
            $hoja->getColumnDimension($c)->setWidth($w);
        }
        $hoja->freezePane('A2');
        $hoja->setAutoFilter('A1:K1');

        // Listas desplegables (hasta 500 filas mas que las actuales).
        $hasta = $r + 500;
        $lista = function (string $col, array $valores, string $titulo) use ($hoja, $hasta) {
            $v = new DataValidation();
            $v->setType(DataValidation::TYPE_LIST)->setErrorStyle(DataValidation::STYLE_STOP)->setAllowBlank(true)
                ->setShowDropDown(true)->setShowErrorMessage(true)->setShowInputMessage(true)
                ->setErrorTitle('Valor no valido')->setError('Elija un valor de la lista.')
                ->setPromptTitle($titulo)->setPrompt('Elija de la lista: '.implode(', ', $valores))
                ->setFormula1('"'.implode(',', $valores).'"');
            $hoja->setDataValidation("{$col}2:{$col}{$hasta}", $v);
        };
        $lista('G', self::ESTRATOS, 'Estrato altitudinal');
        $lista('H', self::ZONAS, 'Zona');
        $lista('J', self::REDES, 'Red');
        $lista('K', ['si', 'no'], 'Activa');

        // Hoja de instrucciones
        $ins = $libro->createSheet()->setTitle('Instrucciones');
        $texto = [
            ['Plantilla de estaciones meteorologicas - SIGEC (Clima y estaciones)'],
            [''],
            ['Complete la hoja "Estaciones" (una fila por estacion) e importela en Clima y estaciones > Estaciones y categorias > Importar listado.'],
            ['Solo se lee la primera hoja. Las estaciones existentes se actualizan (por Estacionid y luego por Codigo); las demas se crean.'],
            [''],
            ['Columna', 'Obligatoria', 'Descripcion', 'Ejemplo'],
            ['Codigo', 'Si (o Estacionid)', 'Codigo de la estacion en SIGEC (max. 30 caracteres).', 'CEN-CEN'],
            ['Estacionid', 'Recomendada', 'Identificador de la estacion en la API del ICC (campo estacionid). Enlaza la estacion con sus datos.', '1'],
            ['Nombre', 'Si', 'Nombre de la estacion o finca.', 'Cengicaña'],
            ['Latitud', 'Si', 'Grados decimales (norte positivo) o grados-minutos-segundos.', "14.2909  o  14°17'27\" N"],
            ['Longitud', 'Si', 'Grados decimales (oeste NEGATIVO) o grados-minutos-segundos con O/W. Si falta el signo en Guatemala se corrige solo.', "-91.0550  o  91°03'18\" O"],
            ['Altitud', 'No', 'Metros sobre el nivel del mar.', '300'],
            ['Estrato', 'No', 'Estrato altitudinal: '.implode(', ', self::ESTRATOS).'.', 'ALTO'],
            ['Zona', 'No', 'Zona longitudinal: '.implode(', ', self::ZONAS).'.', 'CENTRO'],
            ['Ubicacion', 'No', 'Municipio, departamento o finca.', 'Santa Lucia Cotz., Escuintla'],
            ['Red', 'No', 'Red a la que pertenece: '.implode(', ', self::REDES).'. Si tiene Estacionid se asume ICC REDMET.', 'ICC REDMET'],
            ['Activa', 'No', 'si / no. Las inactivas no se sugieren a los ensayos.', 'si'],
            [''],
            ['Las filas sin coordenadas validas se omiten y el resultado de la importacion indica la fila y el motivo.'],
        ];
        $ins->fromArray($texto, null, 'A1');
        $ins->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB('1F5632');
        $ins->getStyle('A6:D6')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F5632']],
        ]);
        $ins->getStyle('A7:A17')->getFont()->setBold(true);
        $ins->getStyle('C7:C17')->getAlignment()->setWrapText(true);
        foreach (['A' => 14, 'B' => 18, 'C' => 70, 'D' => 28] as $c => $w) {
            $ins->getColumnDimension($c)->setWidth($w);
        }
        $libro->setActiveSheetIndex(0);

        $nombre = $request->boolean('vacia') ? 'plantilla_estaciones.xlsx' : 'estaciones_meteorologicas.xlsx';

        return response()->streamDownload(function () use ($libro) {
            (new Xlsx($libro))->save('php://output');
        }, $nombre, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * Importa el listado de estaciones con su ubicacion (Excel/CSV). Columnas
     * reconocidas: codigo, id de API (estacionid), nombre, latitud, longitud,
     * altitud, ubicacion, red, activa. Coincide por id de API y luego por
     * codigo: actualiza las existentes y crea las nuevas.
     */
    public function importarCatalogo(Request $request)
    {
        Gate::authorize('admin_only');

        $request->validate(['archivo' => 'required|file|mimes:xlsx,xls,csv,txt|max:5120']);
        // Solo la primera hoja: la plantilla trae una segunda hoja de instrucciones.
        $import = new PrimeraHojaImport();
        Excel::import($import, $request->file('archivo'));
        $filas = ($import->hoja->filas ?? collect())->map(fn ($f) => $f->toArray())->filter(fn ($f) => array_filter($f, fn ($v) => $v !== null && $v !== ''))->values();
        if ($filas->isEmpty()) {
            return response()->json(['message' => 'El archivo no tiene filas de datos.'], 422);
        }

        $claves = array_keys($filas->first());
        $col = $this->columnasCatalogo($claves);
        if (! isset($col['lat'], $col['lng']) || (! isset($col['codigo']) && ! isset($col['codigo_api']))) {
            return response()->json([
                'message' => 'Se requieren las columnas Codigo (o estacionid), Latitud y Longitud. Encabezados leidos: '.implode(', ', $claves),
            ], 422);
        }

        $res = ['creadas' => 0, 'actualizadas' => 0, 'corregidas' => [], 'omitidas' => []];
        foreach ($filas as $i => $f) {
            $fila = $i + 2;
            $v = fn ($k) => isset($col[$k]) ? trim((string) ($f[$col[$k]] ?? '')) : '';
            $codigo = Str::limit($v('codigo'), 30, '');
            $idApi = $v('codigo_api');
            if ($codigo === '' && $idApi === '') {
                $res['omitidas'][] = ['fila' => $fila, 'motivo' => 'Sin codigo'];
                continue;
            }

            $lat = $this->coordenada($v('lat'));
            $lng = $this->coordenada($v('lng'));
            if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
                $res['omitidas'][] = ['fila' => $fila, 'motivo' => 'Coordenadas no validas ('.$v('lat').', '.$v('lng').')'];
                continue;
            }
            // Longitud de Guatemala/El Salvador sin signo negativo.
            if ($lat > 12 && $lat < 19 && $lng > 86 && $lng < 93) {
                $lng = -$lng;
                $res['corregidas'][] = ['fila' => $fila, 'codigo' => $codigo ?: $idApi, 'motivo' => 'Longitud sin signo: se uso '.$lng];
            }

            $est = ($idApi !== '' ? EstacionMeteo::where('codigo_api', $idApi)->first() : null)
                ?: ($codigo !== '' ? EstacionMeteo::where('codigo', $codigo)->first() : null);
            $datos = array_filter([
                'codigo_api' => $idApi ?: null,
                'nombre' => $v('nombre') !== '' ? Str::limit($v('nombre'), 150, '') : null,
                'red' => in_array($v('red'), self::REDES, true) ? $v('red') : null,
                'altitud' => is_numeric(str_replace(',', '.', $v('altitud'))) ? (int) round((float) str_replace(',', '.', $v('altitud'))) : null,
                'ubicacion' => $v('ubicacion') !== '' ? Str::limit($v('ubicacion'), 150, '') : null,
                'estrato' => in_array(Str::upper($v('estrato')), self::ESTRATOS, true) ? Str::upper($v('estrato')) : null,
                // "Centro Este", "centro_este" o "Centro-Este" -> CENTRO-ESTE
                'zona' => in_array($this->normalizarZona($v('zona')), self::ZONAS, true) ? $this->normalizarZona($v('zona')) : null,
            ], fn ($x) => $x !== null) + ['lat' => round($lat, 6), 'lng' => round($lng, 6)];
            if (isset($col['activa']) && $v('activa') !== '') {
                $datos['activa'] = ! in_array(Str::lower($v('activa')), ['0', 'no', 'false', 'inactiva'], true);
            }

            if ($est) {
                $est->update($datos);
                $res['actualizadas']++;
            } else {
                $codigo = $codigo ?: 'ICC-'.$idApi;
                if (EstacionMeteo::where('codigo', $codigo)->exists()) {
                    $res['omitidas'][] = ['fila' => $fila, 'motivo' => "El codigo {$codigo} ya existe con otro id de API"];
                    continue;
                }
                EstacionMeteo::create($datos + [
                    'codigo' => $codigo,
                    'nombre' => $datos['nombre'] ?? $codigo,
                    'red' => $datos['red'] ?? ($idApi !== '' ? 'ICC REDMET' : 'Otra'),
                    'activa' => $datos['activa'] ?? true,
                ]);
                $res['creadas']++;
            }
        }

        return response()->json($res);
    }

    /** "Centro Este", "centro_este", "Centro - Este" -> "CENTRO-ESTE". */
    protected function normalizarZona(string $v): string
    {
        return preg_replace('/[\s_\-]+/', '-', Str::upper(trim($v)));
    }

    protected function columnasCatalogo(array $claves): array
    {
        $patrones = [
            'codigo_api' => '/^(estacionid|estacion_id|id_api|codigo_api|id_estacion|api_id)$/',
            'codigo' => '/^(codigo|code|clave|codigo_estacion|estacion_codigo)$/',
            'nombre' => '/^(nombre|finca|estacion|name|nombre_estacion)$/',
            'lat' => '/^(lat|latitud|latitude|y)$/',
            'lng' => '/^(lng|lon|long|longitud|longitude|x)$/',
            'altitud' => '/^(altitud|elevacion|altitude|elevation|alt|msnm|altitud_msnm)$/',
            'ubicacion' => '/^(ubicacion|municipio|departamento|location|lugar|ingenio)$/',
            'red' => '/^red$/',
            'estrato' => '/^(estrato|estrato_altitudinal)$/',
            'zona' => '/^(zona|zona_longitudinal|franja)$/',
            'activa' => '/^(activa|activo|estado)$/',
        ];
        $col = [];
        foreach ($patrones as $k => $re) {
            foreach ($claves as $c) {
                if (preg_match($re, (string) $c)) {
                    $col[$k] = $c;
                    break;
                }
            }
        }

        return $col;
    }

    /** Coordenada decimal ("14.29" / "14,29") o en grados-minutos-segundos ("14°17'30.5\" N"). */
    protected function coordenada(string $v): ?float
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        $dec = str_replace(',', '.', $v);
        if (is_numeric($dec)) {
            return (float) $dec;
        }
        if (preg_match('/^(-)?\s*(\d{1,3})\s*[°º:\s]\s*(\d{1,2})?\s*[\'′:\s]?\s*([\d.,]+)?\s*["″]?\s*([NSEWOnsewo])?$/u', $v, $m)) {
            $g = (float) $m[2] + (isset($m[3]) && $m[3] !== '' ? (float) $m[3] / 60 : 0)
                + (isset($m[4]) && $m[4] !== '' ? (float) str_replace(',', '.', $m[4]) / 3600 : 0);
            $neg = ($m[1] ?? '') === '-' || in_array(strtoupper($m[5] ?? ''), ['S', 'W', 'O'], true);

            return $neg ? -$g : $g;
        }

        return null;
    }

    /**
     * Consulta de varias estaciones a la vez: completa desde la API los dias
     * que falten y devuelve los registros filtrados.
     */
    public function consulta(Request $request, IccClima $icc)
    {
        $data = $request->validate([
            'estaciones' => 'required|array|min:1|max:60',
            'estaciones.*' => 'integer|exists:estaciones_meteo,id',
            'desde' => 'required|date',
            'hasta' => 'required|date|after_or_equal:desde',
        ]);
        $desde = Carbon::parse($data['desde'])->startOfDay();
        $hasta = Carbon::parse($data['hasta'])->startOfDay();
        if ($desde->diffInDays($hasta) > 1100) {
            return response()->json(['message' => 'Consulte como maximo 3 años por vez.'], 422);
        }

        // Solo lectura: la base se actualiza en la pestaña "Base de datos".
        $sync = ['usa_api' => $icc->configurada(), 'consultada' => false];

        $filas = ClimaDiario::whereIn('estacion_id', $data['estaciones'])
            ->whereBetween('fecha', [$desde, $hasta])
            ->orderBy('estacion_id')->orderBy('fecha')
            ->get(array_merge(['estacion_id', 'fecha'], self::VARIABLES));

        return response()->json(['filas' => $filas, 'sync' => $sync]);
    }

    /**
     * Analisis comparativo entre años, estratos y estaciones (tableros de
     * clima de Cengicaña). Por estacion-año-periodo se suma (lluvia, ETo,
     * grados dia) o se promedia (resto); despues se promedian las estaciones.
     * Semana = bloques de 7 dias desde el 1 de enero (la 53 se une a la 52).
     */
    public function analisis(Request $request, IccClima $icc)
    {
        $data = $request->validate([
            'estaciones' => 'required|array|min:1|max:150',
            'estaciones.*' => 'integer',
            'anios' => 'required|array|min:1|max:8',
            'anios.*' => 'integer|min:1990|max:'.(int) date('Y'),
            'variable' => ['required', Rule::in(self::VARIABLES_ANALISIS)],
            'agrupar' => 'required|in:semana,mes',
            'tbase' => 'nullable|numeric|between:0,40',
        ], [
            'anios.max' => 'Compare como maximo 8 años a la vez.',
        ]);
        $variable = $data['variable'];
        $tbase = (float) ($data['tbase'] ?? 18);
        $sumable = in_array($variable, self::SUMABLES, true);
        $anios = collect($data['anios'])->map(fn ($a) => (int) $a)->unique()->sort()->values();
        $estaciones = EstacionMeteo::whereIn('id', $data['estaciones'])->get(['id', 'codigo', 'nombre', 'estrato', 'zona', 'codigo_api'])->keyBy('id');

        // Solo lectura: la base se actualiza en la pestaña "Base de datos".
        $sync = ['consultada' => false];
        $hoy = Carbon::today();

        $filas = DB::table('clima_diario')
            ->whereIn('estacion_id', $estaciones->keys())
            ->whereBetween('fecha', [$anios->first().'-01-01', $anios->last().'-12-31'])
            ->get(['estacion_id', 'fecha', 'lluvia', 'eto', 'radiacion', 'tmed', 'tmax', 'tmin', 'hr', 'hr_min', 'hr_max', 'viento']);

        $anioSet = $anios->flip();
        $acc = [];   // [est][anio][periodo] => [suma, n]
        $anual = []; // [est][anio] => [suma, n]
        $diasAnio = [];
        $porMes = $data['agrupar'] === 'mes';
        foreach ($filas as $f) {
            // Sin Carbon por fila: puede haber cientos de miles de registros.
            $a = (int) substr($f->fecha, 0, 4);
            if (! isset($anioSet[$a])) {
                continue;
            }
            $v = $this->valorDerivado($variable, $f, $tbase);
            if ($v === null) {
                continue;
            }
            $m = (int) substr($f->fecha, 5, 2);
            $p = $porMes ? $m : min(52, intdiv((int) date('z', mktime(12, 0, 0, $m, (int) substr($f->fecha, 8, 2), $a)), 7) + 1);
            $e = $f->estacion_id;
            $acc[$e][$a][$p][0] = ($acc[$e][$a][$p][0] ?? 0) + $v;
            $acc[$e][$a][$p][1] = ($acc[$e][$a][$p][1] ?? 0) + 1;
            $anual[$e][$a][0] = ($anual[$e][$a][0] ?? 0) + $v;
            $anual[$e][$a][1] = ($anual[$e][$a][1] ?? 0) + 1;
            $diasAnio[$a] = ($diasAnio[$a] ?? 0) + 1;
        }

        $valor = fn ($par) => $sumable ? $par[0] : $par[0] / $par[1];
        $prom = fn ($xs) => $xs ? array_sum($xs) / count($xs) : null;
        $r = fn ($x) => $x === null ? null : round($x, 3);
        $periodos = $data['agrupar'] === 'mes' ? range(1, 12) : range(1, 52);

        // Promedio de estaciones por año y periodo, y por estrato (todas las estaciones-años del estrato).
        $porAnio = [];
        $porEstrato = [];
        foreach ($anios as $a) {
            foreach ($periodos as $p) {
                $vals = [];
                foreach ($acc as $est => $porA) {
                    if (isset($porA[$a][$p])) {
                        $x = $valor($porA[$a][$p]);
                        $vals[] = $x;
                        $estrato = $estaciones[$est]->estrato ?: 'SIN ESTRATO';
                        $porEstrato[$estrato][$p][] = $x;
                    }
                }
                $porAnio[$a][] = $r($prom($vals));
            }
        }
        $promedio = [];
        foreach ($periodos as $i => $p) {
            $promedio[] = $r($prom(array_values(array_filter(array_map(fn ($serie) => $serie[$i], $porAnio), fn ($x) => $x !== null))));
        }
        $estratos = collect(array_merge(self::ESTRATOS, ['SIN ESTRATO']))->filter(fn ($e) => isset($porEstrato[$e]))
            ->mapWithKeys(fn ($e) => [$e => array_map(fn ($p) => $r($prom($porEstrato[$e][$p] ?? [])), $periodos)]);

        // Total (o promedio diario) por estacion y año; resumen por año y por estrato.
        $estAnual = [];
        $estratoAnio = [];
        foreach ($anual as $est => $porA) {
            $e = $estaciones[$est];
            $vals = [];
            foreach ($porA as $a => $par) {
                $x = $valor($par);
                $vals[$a] = $r($x);
                $estratoAnio[$e->estrato ?: 'SIN ESTRATO'][$a][] = $x;
            }
            $estAnual[] = [
                'id' => $e->id, 'codigo' => $e->codigo, 'nombre' => $e->nombre, 'estrato' => $e->estrato, 'zona' => $e->zona,
                'anios' => $vals, 'promedio' => $r($prom(array_values($vals))),
            ];
        }
        // Cobertura: dias con dato / (estaciones con datos ese año x dias del año transcurridos).
        $resumenAnio = $anios->map(function ($a) use ($estAnual, $diasAnio, $hoy, $prom, $r) {
            $vals = array_values(array_filter(array_map(fn ($e) => $e['anios'][$a] ?? null, $estAnual), fn ($x) => $x !== null));
            $dias = $a == $hoy->year ? $hoy->dayOfYear : Carbon::create($a, 12, 31)->dayOfYear;

            return [
                'anio' => $a,
                'valor' => $r($prom($vals)),
                'estaciones' => count($vals),
                'cobertura' => count($vals) ? min(100, round(100 * ($diasAnio[$a] ?? 0) / (count($vals) * $dias), 1)) : 0,
            ];
        })->values();

        return response()->json([
            'variable' => $variable,
            'sumable' => $sumable,
            'tbase' => $tbase,
            'periodos' => $periodos,
            'por_anio' => $porAnio,
            'promedio' => $promedio,
            'por_estrato' => $estratos,
            'estrato_anio' => collect($estratoAnio)->map(fn ($porA) => collect($porA)->map(fn ($xs) => $r($prom($xs)))),
            'estaciones' => collect($estAnual)->sortByDesc('promedio')->values(),
            'resumen_anio' => $resumenAnio,
            'sync' => $sync,
        ]);
    }

    /**
     * Series agregadas en el servidor por estacion y periodo (dia, semana,
     * mes o año) para Consulta y Comparar estaciones: permite periodos
     * largos (todo el historial) sin enviar cada registro diario.
     * Lluvia, ETo y grados dia se suman; el resto se promedia.
     */
    public function serie(Request $request, IccClima $icc)
    {
        $data = $request->validate([
            'estaciones' => 'required|array|min:1|max:60',
            'estaciones.*' => 'integer',
            'desde' => 'required|date',
            'hasta' => 'required|date|after_or_equal:desde',
            'agrupar' => 'required|in:dia,semana,mes,anio',
            'tbase' => 'nullable|numeric|between:0,40',
            'referencia' => ['nullable', Rule::in(array_merge(['red'], self::ESTRATOS))],
        ]);
        $desde = Carbon::parse($data['desde'])->startOfDay();
        $hasta = Carbon::parse($data['hasta'])->startOfDay();
        $limites = ['dia' => [1100, 'Por dia se pueden consultar hasta 3 años; para periodos mas largos agrupe por semana, mes o año.'],
            'semana' => [3700, 'Por semana se pueden consultar hasta 10 años; para periodos mas largos agrupe por mes o año.'],
            'mes' => [11000, 'Consulte como maximo 30 años.'], 'anio' => [11000, 'Consulte como maximo 30 años.']];
        [$maxDias, $msg] = $limites[$data['agrupar']];
        if ($desde->diffInDays($hasta) > $maxDias) {
            return response()->json(['message' => $msg], 422);
        }
        $tbase = (float) ($data['tbase'] ?? 18);
        $estaciones = EstacionMeteo::whereIn('id', $data['estaciones'])->get(['id', 'codigo', 'nombre', 'estrato', 'zona', 'codigo_api'])->keyBy('id');

        // Solo lectura: la base se actualiza en la pestaña "Base de datos".
        $sync = ['usa_api' => $icc->configurada(), 'consultada' => false];

        $agr = $data['agrupar'];
        // Todos los periodos del rango (no solo los que tienen datos): los huecos se ven como cortes en la linea.
        $periodos = [];
        for ($t = strtotime($desde->format('Y-m-d').' 12:00:00'), $fin = strtotime($hasta->format('Y-m-d').' 12:00:00'); $t <= $fin; $t += 86400) {
            $periodos[$this->clavePeriodo(date('Y-m-d', $t), $agr)] = true;
        }
        $periodos = array_keys($periodos);
        sort($periodos);

        $out = collect($this->seriesPorEstacion($estaciones, $desde, $hasta, $agr, $tbase, $periodos))->values();

        // Referencia para comparar: promedio de toda la red o de un estrato (todas sus estaciones).
        $referencia = null;
        if (! empty($data['referencia'])) {
            $refEst = EstacionMeteo::when($data['referencia'] !== 'red', fn ($q) => $q->where('estrato', $data['referencia']))
                ->get(['id', 'codigo', 'nombre', 'estrato', 'zona', 'codigo_api'])->keyBy('id');
            $series = array_values(array_filter($this->seriesPorEstacion($refEst, $desde, $hasta, $agr, $tbase, $periodos), fn ($s) => $s['dias'] > 0));
            $prom = function (array $xs) {
                $xs = array_values(array_filter($xs, fn ($x) => $x !== null));

                return $xs ? round(array_sum($xs) / count($xs), 3) : null;
            };
            $serie = [];
            $totales = [];
            foreach (array_merge(self::VARIABLES, ['vpd', 'gdd']) as $v) {
                $serie[$v] = array_map(fn ($i) => $prom(array_map(fn ($s) => $s['serie'][$v][$i], $series)), array_keys($periodos));
                $totales[$v] = $prom(array_map(fn ($s) => $s['totales'][$v], $series));
            }
            $referencia = [
                'clave' => $data['referencia'],
                'nombre' => $data['referencia'] === 'red' ? 'Promedio de la red' : 'Promedio estrato '.$data['referencia'],
                'estaciones' => count($series),
                'dias' => $series ? (int) round(array_sum(array_column($series, 'dias')) / count($series)) : 0,
                'serie' => $serie,
                'totales' => $totales,
            ];
        }

        return response()->json([
            'agrupar' => $agr,
            'desde' => $desde->format('Y-m-d'),
            'hasta' => $hasta->format('Y-m-d'),
            'total_dias' => $desde->diffInDays($hasta) + 1,
            'tbase' => $tbase,
            'periodos' => $periodos,
            'estaciones' => $out,
            'referencia' => $referencia,
            'sync' => $sync,
        ]);
    }

    /**
     * Serie por estacion y periodo de todas las variables (mas DPV y grados
     * dia): lluvia, ETo y grados dia se suman; el resto se promedia.
     */
    protected function seriesPorEstacion($estaciones, Carbon $desde, Carbon $hasta, string $agr, float $tbase, array $periodos): array
    {
        if ($estaciones->isEmpty()) {
            return [];
        }
        $vars = array_merge(self::VARIABLES, ['vpd', 'gdd']);
        $sumables = ['lluvia', 'eto', 'gdd'];
        $claves = [];   // fecha -> clave del periodo (cache: las fechas se repiten entre estaciones)
        $acc = [];      // [est][clave][var] => [suma, n]
        $tot = [];      // [est][var] => [suma, n]
        $diasEst = [];  // [est][clave] => dias con algun dato
        $filas = DB::table('clima_diario')
            ->whereIn('estacion_id', $estaciones->keys())
            ->whereBetween('fecha', [$desde->format('Y-m-d'), $hasta->format('Y-m-d')])
            ->select(array_merge(['estacion_id', 'fecha'], self::VARIABLES))
            ->cursor();
        foreach ($filas as $f) {
            $fecha = substr($f->fecha, 0, 10);
            $k = $claves[$fecha] ?? ($claves[$fecha] = $this->clavePeriodo($fecha, $agr));
            $e = $f->estacion_id;
            $diasEst[$e][$k] = ($diasEst[$e][$k] ?? 0) + 1;
            foreach ($vars as $v) {
                $x = in_array($v, ['vpd', 'gdd'], true) ? $this->valorDerivado($v, $f, $tbase) : ($f->{$v} === null ? null : (float) $f->{$v});
                if ($x === null) {
                    continue;
                }
                $acc[$e][$k][$v][0] = ($acc[$e][$k][$v][0] ?? 0) + $x;
                $acc[$e][$k][$v][1] = ($acc[$e][$k][$v][1] ?? 0) + 1;
                $tot[$e][$v][0] = ($tot[$e][$v][0] ?? 0) + $x;
                $tot[$e][$v][1] = ($tot[$e][$v][1] ?? 0) + 1;
            }
        }
        $valor = fn ($v, $par) => $par === null ? null : round(in_array($v, $sumables, true) ? $par[0] : $par[0] / $par[1], 3);

        return $estaciones->map(function ($e) use ($vars, $periodos, $acc, $tot, $diasEst, $valor) {
            $serie = [];
            $totales = [];
            foreach ($vars as $v) {
                $serie[$v] = array_map(fn ($k) => $valor($v, $acc[$e->id][$k][$v] ?? null), $periodos);
                $totales[$v] = $valor($v, $tot[$e->id][$v] ?? null);
            }

            return [
                'id' => $e->id, 'codigo' => $e->codigo, 'nombre' => $e->nombre, 'estrato' => $e->estrato, 'zona' => $e->zona,
                'dias' => array_sum($diasEst[$e->id] ?? []),
                'dias_periodo' => array_map(fn ($k) => $diasEst[$e->id][$k] ?? 0, $periodos),
                'totales' => $totales,
                'serie' => $serie,
            ];
        })->values()->all();
    }

    /** Clave del periodo: dia (Y-m-d), semana (lunes Y-m-d), mes (Y-m) o año (Y). */
    protected function clavePeriodo(string $fecha, string $agr): string
    {
        switch ($agr) {
            case 'anio':
                return substr($fecha, 0, 4);
            case 'mes':
                return substr($fecha, 0, 7);
            case 'semana':
                $t = strtotime($fecha.' 12:00:00');

                return date('Y-m-d', $t - ((int) date('N', $t) - 1) * 86400);
            default:
                return $fecha;
        }
    }

    /**
     * Valor diario de la variable; DPV y grados dia se calculan:
     * - DPV (kPa, FAO-56): es = [e°(Tmax)+e°(Tmin)]/2; ea con HR max/min
     *   [e°(Tmin)·HRmax + e°(Tmax)·HRmin]/200, o con HR media si faltan.
     * - Grados dia (°C·dia): max(0, (Tmax+Tmin)/2 - Tbase).
     */
    protected function valorDerivado(string $variable, $f, float $tbase): ?float
    {
        $tx = $f->tmax !== null ? (float) $f->tmax : null;
        $tn = $f->tmin !== null ? (float) $f->tmin : null;
        if ($variable === 'gdd') {
            return $tx !== null && $tn !== null ? max(0, ($tx + $tn) / 2 - $tbase) : null;
        }
        if ($variable === 'vpd') {
            if ($tx === null || $tn === null) {
                return null;
            }
            $e = fn ($t) => 0.6108 * exp(17.27 * $t / ($t + 237.3));
            $es = ($e($tx) + $e($tn)) / 2;
            if ($f->hr_min !== null && $f->hr_max !== null) {
                $ea = ($e($tn) * $f->hr_max / 100 + $e($tx) * $f->hr_min / 100) / 2;
            } elseif ($f->hr !== null) {
                $ea = $es * $f->hr / 100;
            } else {
                return null;
            }

            return max(0, $es - $ea);
        }
        $v = $f->{$variable} ?? null;

        return $v === null ? null : (float) $v;
    }

    /** La estacion toma sus datos de la API (creada desde la API o con su id de API). */
    protected function usaApi(EstacionMeteo $estacion, IccClima $icc): bool
    {
        return $icc->configurada() && ! empty($estacion->codigo_api);
    }

    /**
     * Pide a la API los dias de [desde, hasta] aun no descargados (y los
     * ultimos 2 dias, que el ICC sigue completando, si la ultima consulta
     * tiene mas de refresco_min minutos). Huecos de hasta 7 dias se unen en
     * una sola solicitud.
     */
    public function completarIcc(IccClima $icc, Carbon $desde, Carbon $hasta, bool $forzar = false, string $tipo = 'consulta'): array
    {
        try {
            $res = $this->descargarIcc($icc, $desde, $hasta, $forzar);
        } catch (RuntimeException $e) {
            $this->registrarSync($tipo, $desde, $hasta, ['error' => Str::limit($e->getMessage(), 250, '')]);
            throw $e;
        }
        if ($res['consultada']) {
            $this->registrarSync($tipo, Carbon::parse($res['desde']), Carbon::parse($res['hasta']), $res);
        }

        return $res;
    }

    protected function registrarSync(string $tipo, Carbon $desde, Carbon $hasta, array $r): void
    {
        DB::table('clima_sync_log')->insert([
            'tipo' => $tipo, 'desde' => $desde->format('Y-m-d'), 'hasta' => min($hasta, Carbon::today())->format('Y-m-d'),
            'dias' => $r['dias'] ?? 0, 'registros' => $r['registros'] ?? 0, 'estaciones_nuevas' => $r['estaciones_nuevas'] ?? 0,
            'excluidos' => $r['excluidos'] ?? 0, 'error' => $r['error'] ?? null, 'user_id' => Auth::id(), 'created_at' => now(),
        ]);
    }

    protected function descargarIcc(IccClima $icc, Carbon $desde, Carbon $hasta, bool $forzar): array
    {
        $hoy = Carbon::today();
        $fin = $hasta->gt($hoy) ? $hoy->copy() : $hasta->copy();
        if ($desde->gt($fin)) {
            return ['consultada' => false, 'dias' => 0, 'registros' => 0];
        }

        $hechos = DB::table('clima_icc_fechas')->whereBetween('fecha', [$desde->format('Y-m-d'), $fin->format('Y-m-d')])
            ->pluck('consultado_en', 'fecha');
        $limite = now()->subMinutes(config('services.icc.refresco_min', 60));
        $recientes = $hoy->copy()->subDays(2);

        $tramos = [];
        for ($d = $desde->copy(); $d->lte($fin); $d->addDay()) {
            $hecho = $hechos[$d->format('Y-m-d')] ?? null;
            $pedir = $forzar || ! $hecho || ($d->gte($recientes) && Carbon::parse($hecho)->lt($limite));
            if (! $pedir) {
                continue;
            }
            $n = count($tramos);
            if ($n && $tramos[$n - 1][1]->diffInDays($d) <= 7) {
                $tramos[$n - 1][1] = $d->copy();
            } else {
                $tramos[] = [$d->copy(), $d->copy()];
            }
        }
        if (! $tramos) {
            return ['consultada' => false, 'dias' => 0, 'registros' => 0];
        }

        $total = ['consultada' => true, 'dias' => 0, 'registros' => 0, 'estaciones_nuevas' => 0, 'excluidos' => 0, 'atipicos' => 0];
        $calidad = app(ClimaCalidad::class);
        foreach ($tramos as [$ini, $finTramo]) {
            $r = $this->guardarDiariosIcc($icc->datosDiarios($ini, $finTramo), $ini, $finTramo);
            $total['dias'] += $r['dias'];
            $total['registros'] += $r['registros'];
            $total['estaciones_nuevas'] += $r['estaciones_nuevas'];
            // Control de calidad de lo recien descargado (y re-aplica exclusiones previas).
            if ($r['registros']) {
                $q = $calidad->revisar($ini, $finTramo);
                $total['excluidos'] += $q['excluidos'];
                $total['atipicos'] += $q['atipicos'];
            }
        }
        $total['desde'] = $tramos[0][0]->format('Y-m-d');
        $total['hasta'] = end($tramos)[1]->format('Y-m-d');

        return $total;
    }

    /**
     * Guarda los registros diarios de la API: crea las estaciones nuevas
     * (red ICC REDMET, sin coordenadas hasta que se editen), hace upsert de
     * clima_diario y marca los dias como descargados.
     */
    protected function guardarDiariosIcc(array $registros, Carbon $desde, Carbon $hasta): array
    {
        $estaciones = EstacionMeteo::whereNotNull('codigo_api')->get()->keyBy('codigo_api');
        $nuevas = 0;
        $porDia = [];
        $filas = [];
        $ahora = now();
        $ini = $desde->format('Y-m-d');
        $fin = $hasta->format('Y-m-d');

        foreach ($registros as $r) {
            $e = $r['estacion'];
            $est = $estaciones->get($e['id_api']);
            if (! $est) {
                $codigo = Str::limit($e['codigo'] ?: 'ICC-'.$e['id_api'], 30, '');
                // Estacion registrada a mano con el mismo codigo: se enlaza.
                $est = EstacionMeteo::where('codigo', $codigo)->whereNull('codigo_api')->first();
                if ($est) {
                    $est->update(['codigo_api' => $e['id_api']]);
                } else {
                    if (EstacionMeteo::where('codigo', $codigo)->exists()) {
                        $codigo = Str::limit($codigo, 24, '').'-'.$e['id_api'];
                    }
                    $est = EstacionMeteo::create([
                        'codigo' => $codigo,
                        'codigo_api' => $e['id_api'],
                        'nombre' => Str::limit($e['nombre'] ?: $codigo, 150, ''),
                        'red' => 'ICC REDMET',
                        'activa' => true,
                    ]);
                    $nuevas++;
                }
                $estaciones->put($e['id_api'], $est);
            }
            if ($r['fecha'] < $ini || $r['fecha'] > $fin) {
                continue;
            }
            $valores = $this->validarRangos(array_intersect_key($r, array_flip(self::VARIABLES)) + array_fill_keys(self::VARIABLES, null));
            $filas[] = ['estacion_id' => $est->id, 'fecha' => $r['fecha'], 'created_at' => $ahora, 'updated_at' => $ahora] + $valores;
            $porDia[$r['fecha']][$est->id] = true;
        }

        foreach (array_chunk($filas, 500) as $lote) {
            ClimaDiario::upsert($lote, ['estacion_id', 'fecha'], array_merge(self::VARIABLES, ['updated_at']));
        }

        $marcas = [];
        for ($d = $desde->copy(); $d->lte($hasta); $d->addDay()) {
            $k = $d->format('Y-m-d');
            $marcas[] = ['fecha' => $k, 'estaciones' => count($porDia[$k] ?? []), 'consultado_en' => $ahora];
        }
        DB::table('clima_icc_fechas')->upsert($marcas, ['fecha'], ['estaciones', 'consultado_en']);
        $ids = collect($porDia)->flatMap(fn ($x) => array_keys($x))->unique()->values();
        if ($ids->isNotEmpty()) {
            EstacionMeteo::whereIn('id', $ids)->update(['sincronizada_en' => $ahora]);
        }

        return ['dias' => count($porDia), 'registros' => count($filas), 'estaciones_nuevas' => $nuevas];
    }

    /**
     * Reconoce columnas, agrega por dia y guarda (upsert). Usado por la
     * importacion de archivos y por la API del ICC.
     */
    protected function guardarFilas(EstacionMeteo $estacion, array $filas, ?Carbon $desde = null, ?Carbon $hasta = null): array
    {
        if (! $filas) {
            throw new RuntimeException('No hay filas de datos.');
        }
        $claves = array_keys($filas[0]);
        $columnas = $this->detectarColumnas($claves);
        if (! isset($columnas['fecha'])) {
            throw new RuntimeException('No se encontro la columna de fecha. Campos leidos: '.implode(', ', $claves));
        }
        if (count($columnas) === 1) {
            throw new RuntimeException('No se reconocio ninguna variable (lluvia, temperatura, humedad, viento, radiacion o ETo). Campos leidos: '.implode(', ', $claves));
        }

        $porDia = [];
        $omitidas = [];
        foreach ($filas as $i => $fila) {
            $fecha = $this->parsearFecha($fila[$columnas['fecha']] ?? null);
            if (! $fecha) {
                if (count($omitidas) < 20) {
                    $omitidas[] = ['fila' => $i + 2, 'motivo' => 'Fecha no reconocida: '.(string) ($fila[$columnas['fecha']] ?? '')];
                }
                continue;
            }
            // La API puede devolver horas de dias vecinos: solo se guarda el rango pedido.
            if (($desde && $fecha->copy()->startOfDay()->lt($desde)) || ($hasta && $fecha->copy()->startOfDay()->gt($hasta))) {
                continue;
            }
            $dia = $fecha->format('Y-m-d');
            foreach ($columnas as $var => $col) {
                if ($var === 'fecha') {
                    continue;
                }
                $v = $this->numero($fila[$col] ?? null);
                if ($v !== null) {
                    $porDia[$dia][$var][] = $v;
                }
            }
            $porDia[$dia] = $porDia[$dia] ?? [];
        }

        if (! $porDia) {
            throw new RuntimeException('No se pudo leer ninguna fecha valida'.($omitidas ? ' (p. ej. '.$omitidas[0]['motivo'].').' : '.'));
        }

        $ahora = now();
        $registros = [];
        foreach ($porDia as $dia => $vals) {
            $registros[] = array_merge(
                ['estacion_id' => $estacion->id, 'fecha' => $dia, 'created_at' => $ahora, 'updated_at' => $ahora],
                $this->agregarDia($vals, $columnas)
            );
        }

        foreach (array_chunk($registros, 500) as $lote) {
            ClimaDiario::upsert($lote, ['estacion_id', 'fecha'], array_merge(self::VARIABLES, ['updated_at']));
        }

        $dias = array_keys($porDia);
        sort($dias);

        return [
            'dias' => count($dias),
            'desde' => $dias[0],
            'hasta' => end($dias),
            'filas_leidas' => count($filas),
            'columnas' => $columnas,
            'omitidas' => $omitidas,
            'fechas' => $dias,
        ];
    }

    /* ------------------------------- Helpers ------------------------------- */

    protected function validar(Request $request, ?EstacionMeteo $estacion = null): array
    {
        return $request->validate([
            'codigo' => ['required', 'string', 'max:30', Rule::unique('estaciones_meteo', 'codigo')->ignore($estacion)],
            'codigo_api' => 'nullable|string|max:60',
            'nombre' => 'required|string|max:150',
            'red' => ['required', Rule::in(self::REDES)],
            // Opcionales: las estaciones creadas desde la API ICC llegan sin coordenadas.
            'lat' => 'nullable|required_with:lng|numeric|between:-90,90',
            'lng' => 'nullable|required_with:lat|numeric|between:-180,180',
            'altitud' => 'nullable|integer|min:0|max:6000',
            'estrato' => ['nullable', Rule::in(self::ESTRATOS)],
            'zona' => ['nullable', Rule::in(self::ZONAS)],
            'ubicacion' => 'nullable|string|max:150',
            'activa' => 'boolean',
        ], [
            'codigo.unique' => 'Ya existe una estacion con ese codigo.',
        ], [
            'codigo' => 'codigo', 'nombre' => 'nombre', 'lat' => 'latitud', 'lng' => 'longitud',
        ]);
    }

    /**
     * Asocia los encabezados (normalizados por WithHeadingRow: minusculas,
     * sin acentos, "_" como separador) con cada variable. Excluye direccion
     * del viento y humedad de hoja, que REDMET tambien exporta.
     */
    protected function detectarColumnas(array $claves): array
    {
        $patrones = [
            'fecha' => '/^(fecha|date|dia|timestamp|fecha_?hora|fecha_y_hora)/',
            'tmax' => '/(t(emp(eratura)?)?_?(aire_)?max|maxima)/',
            'tmin' => '/(t(emp(eratura)?)?_?(aire_)?min|minima)/',
            'tmed' => '/(t(emp(eratura)?)?_?(aire_)?(med|prom|mean)|^temp(eratura)?(_aire)?(_|$)|^t_aire)/',
            'lluvia' => '/(lluvia|precip|^pp(_|$)|rain)/',
            'hr' => '/(^(hr|rh)(_|$)|humedad_rel|^humedad(_aire)?(_|$))/',
            'viento' => '/(vel(ocidad)?_?(del_)?viento|viento_?vel|^viento(_|$)|wind_?speed|^vv(_|$))/',
            'radiacion' => '/(radiacion|rad_?solar|^rad(_|$)|solar)/',
            'eto' => '/(^eto|^et0|evapotransp)/',
        ];
        $excluir = '/(dir|hoja|leaf|suelo|soil)/';

        $columnas = [];
        foreach ($patrones as $var => $re) {
            foreach ($claves as $c) {
                if (in_array($c, $columnas, true) || ($var !== 'fecha' && preg_match($excluir, $c))) {
                    continue;
                }
                if (preg_match($re, (string) $c)) {
                    $columnas[$var] = $c;
                    break;
                }
            }
        }

        return $columnas;
    }

    protected function agregarDia(array $vals, array $columnas): array
    {
        $prom = fn ($a) => $a ? array_sum($a) / count($a) : null;
        $r = array_fill_keys(self::VARIABLES, null);

        if (! empty($vals['lluvia'])) {
            $r['lluvia'] = array_sum($vals['lluvia']);
        }

        // Sin columnas de max/min (datos cada 15 min): se derivan de la temperatura.
        $tmax = $vals['tmax'] ?? ($vals['tmed'] ?? []);
        $tmin = $vals['tmin'] ?? ($vals['tmed'] ?? []);
        $r['tmax'] = $tmax ? max($tmax) : null;
        $r['tmin'] = $tmin ? min($tmin) : null;
        $r['tmed'] = isset($vals['tmed']) ? $prom($vals['tmed'])
            : (($r['tmax'] !== null && $r['tmin'] !== null) ? ($r['tmax'] + $r['tmin']) / 2 : null);

        $r['hr'] = $prom($vals['hr'] ?? []);

        $viento = $prom($vals['viento'] ?? []);
        if ($viento !== null && isset($columnas['viento']) && preg_match('/m_?s(_|$)/', $columnas['viento'])) {
            $viento *= 3.6; // m/s -> km/h
        }
        $r['viento'] = $viento;

        // Radiacion diaria en MJ/m2: si viene en MJ se suma por dia; si viene
        // en W/m2 (lo usual en estaciones automaticas) se promedia y convierte.
        if (! empty($vals['radiacion'])) {
            $r['radiacion'] = preg_match('/mj/', $columnas['radiacion'])
                ? array_sum($vals['radiacion'])
                : $prom($vals['radiacion']) * 0.0864;
        }

        if (! empty($vals['eto'])) {
            $r['eto'] = array_sum($vals['eto']);
        }

        return $this->validarRangos($r);
    }

    /** Valores fuera de rango fisico se descartan (sensores en falla); el resto se redondea. */
    protected function validarRangos(array $r): array
    {
        $rangos = ['lluvia' => [0, 500], 'tmax' => [-10, 55], 'tmin' => [-15, 45], 'tmed' => [-10, 50],
            'hr' => [0, 100], 'hr_min' => [0, 100], 'hr_max' => [0, 100], 'viento' => [0, 200], 'radiacion' => [0, 45], 'eto' => [0, 20]];
        foreach ($rangos as $k => [$min, $max]) {
            if (! isset($r[$k])) {
                $r[$k] = null;
            } elseif ($r[$k] < $min || $r[$k] > $max) {
                $r[$k] = null;
            } else {
                $r[$k] = round($r[$k], 2);
            }
        }

        return $r;
    }

    protected function parsearFecha($v): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $v));
        }
        $v = trim((string) $v);
        $formatos = ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y', 'd-m-Y H:i', 'd-m-Y', 'Y/m/d H:i', 'Y/m/d', 'j/n/Y H:i:s', 'j/n/Y H:i', 'j/n/Y', 'd/m/y H:i', 'd/m/y'];
        foreach ($formatos as $f) {
            try {
                $c = Carbon::createFromFormat('!'.$f, $v);
                if ($c && $c->format($f) === $v) {
                    return $c;
                }
            } catch (\Throwable $e) {
                // probar el siguiente formato
            }
        }
        try {
            return Carbon::parse($v);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function numero($v): ?float
    {
        if ($v === null || is_bool($v)) {
            return null;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }
        $s = str_replace([' ', ','], ['', '.'], trim((string) $v));

        return is_numeric($s) ? (float) $s : null;
    }

    protected function distanciaKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    /** ETo de Hargreaves-Samani (mm/dia) con radiacion extraterrestre FAO-56. */
    protected function etoHargreaves(float $tmax, float $tmin, ?float $tmed, float $lat, Carbon $fecha): float
    {
        $j = $fecha->dayOfYear;
        $phi = deg2rad($lat);
        $dr = 1 + 0.033 * cos(2 * M_PI * $j / 365);
        $delta = 0.409 * sin(2 * M_PI * $j / 365 - 1.39);
        $ws = acos(max(-1, min(1, -tan($phi) * tan($delta))));
        $ra = (24 * 60 / M_PI) * 0.0820 * $dr * ($ws * sin($phi) * sin($delta) + cos($phi) * cos($delta) * sin($ws));
        $tm = $tmed ?? ($tmax + $tmin) / 2;

        return round(max(0, 0.0023 * ($tm + 17.8) * sqrt(max(0, $tmax - $tmin)) * $ra * 0.408), 2);
    }
}
