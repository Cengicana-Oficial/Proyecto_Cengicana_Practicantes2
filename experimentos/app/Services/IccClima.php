<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Cliente de la API agromet del ICC (config/services.php, claves ICC_API_*).
 *
 * GET /api/agromet/datosdiarios?fecha_inicial=Y-m-d&fecha_final=Y-m-d
 * devuelve un registro diario por estacion y fecha, de todas las estaciones:
 *   {estacionid, fecha, tempavg, tempmin, tempmax, humedadmin, humedadmax,
 *    vientoavg, lluviasum, piranometro, radiaciontotal, ra, rn, eto,
 *    estacion: {estacionid, codigo, finca}}
 *
 * Unidades verificadas con FAO-56 sobre el ejemplo de la documentacion
 * (Rn y ETo calculados coinciden con rn = 10.93 y eto = 3.76):
 * piranometro = radiacion solar global en MJ/m2.dia, vientoavg en km/h.
 */
class IccClima
{
    /** Ajustes que se pueden guardar desde la pantalla (Configuracion "icc.*"). */
    const CAMPOS = ['url', 'auth', 'token', 'header', 'query_param', 'usuario', 'clave', 'ruta_datos', 'dias_por_consulta', 'verificar_ssl', 'viento_unidad'];

    const SECRETOS = ['token', 'clave'];

    protected array $cfg;

    /**
     * Configuracion: .env (config/services.php) y encima lo guardado en
     * SIGEC (Clima y estaciones -> Conexion API ICC). $prueba permite probar
     * una configuracion sin guardarla.
     */
    public function __construct(?array $prueba = null)
    {
        $guardada = array_filter(\App\Models\Configuracion::grupo('icc'), fn ($v) => $v !== null && $v !== '');
        $this->cfg = array_merge(config('services.icc', []), $guardada, $prueba ?? []);
        $this->cfg['dias_por_consulta'] = (int) ($this->cfg['dias_por_consulta'] ?? 31);
        $this->cfg['verificar_ssl'] = filter_var($this->cfg['verificar_ssl'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    public function configurada(): bool
    {
        return ! empty($this->cfg['url']);
    }

    /** Configuracion vigente sin secretos (solo si existen). */
    public function configuracionPublica(): array
    {
        $out = [];
        foreach (self::CAMPOS as $k) {
            $out[$k] = in_array($k, self::SECRETOS, true) ? null : ($this->cfg[$k] ?? null);
        }
        $out['tiene_token'] = ! empty($this->cfg['token']);
        $out['tiene_clave'] = ! empty($this->cfg['clave']);

        return $out;
    }

    /**
     * Prueba la conexion pidiendo un dia: cuantos registros llegan, cuantos
     * se reconocen y una muestra (con los campos crudos para diagnostico).
     */
    public function probar(Carbon $fecha): array
    {
        $inicio = microtime(true);
        $url = $this->url(strtr($this->cfg['ruta_datos'] ?? '', [
            '{desde}' => rawurlencode($fecha->format($this->cfg['formato_fecha'] ?? 'Y-m-d')),
            '{hasta}' => rawurlencode($fecha->format($this->cfg['formato_fecha'] ?? 'Y-m-d')),
        ]));
        $crudos = $this->pedir($url);
        $norm = array_values(array_filter(array_map(fn ($r) => $this->normalizar($r), $crudos)));

        return [
            'ms' => (int) round((microtime(true) - $inicio) * 1000),
            'url' => preg_replace('/([?&]'.preg_quote($this->cfg['query_param'] ?? 'api_key', '/').'=)[^&]+/', '$1***', $url),
            'recibidos' => count($crudos),
            'reconocidos' => count($norm),
            'estaciones' => collect($norm)->map(fn ($r) => $r['estacion'])->unique('id_api')->values()->all(),
            'muestra' => $norm[0] ?? null,
            'campos' => isset($crudos[0]) && is_array($crudos[0]) ? array_keys($crudos[0]) : [],
        ];
    }

    /** Datos publicos de la configuracion (sin credenciales). */
    public function resumen(): array
    {
        return [
            'configurada' => $this->configurada(),
            'servidor' => $this->configurada() ? parse_url($this->cfg['url'], PHP_URL_HOST) : null,
            'dias_por_consulta' => $this->cfg['dias_por_consulta'] ?? 31,
        ];
    }

    /**
     * Registros diarios normalizados de todas las estaciones entre dos fechas
     * (inclusive), pidiendo en bloques de dias_por_consulta:
     * [{estacion: {id_api, codigo, nombre}, fecha, lluvia, tmax, tmin, tmed,
     *   hr, hr_min, hr_max, viento, radiacion, eto}]
     */
    public function datosDiarios(Carbon $desde, Carbon $hasta): array
    {
        $bloque = max(1, (int) ($this->cfg['dias_por_consulta'] ?? 31));
        $out = [];
        for ($ini = $desde->copy()->startOfDay(); $ini->lte($hasta); $ini->addDays($bloque)) {
            $fin = $ini->copy()->addDays($bloque - 1);
            if ($fin->gt($hasta)) {
                $fin = $hasta->copy();
            }
            $url = $this->url(strtr($this->cfg['ruta_datos'] ?? '', [
                '{desde}' => rawurlencode($ini->format($this->cfg['formato_fecha'] ?? 'Y-m-d')),
                '{hasta}' => rawurlencode($fin->format($this->cfg['formato_fecha'] ?? 'Y-m-d')),
            ]));
            foreach ($this->pedir($url) as $r) {
                if ($n = $this->normalizar($r)) {
                    $out[] = $n;
                }
            }
        }

        return $out;
    }

    protected function normalizar($r): ?array
    {
        if (! is_array($r) || empty($r['fecha'])) {
            return null;
        }
        $est = is_array($r['estacion'] ?? null) ? $r['estacion'] : [];
        $idApi = $est['estacionid'] ?? $r['estacionid'] ?? $r['estacion_id'] ?? null;
        if ($idApi === null) {
            return null;
        }
        try {
            $fecha = Carbon::parse(substr((string) $r['fecha'], 0, 10))->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }

        $n = fn ($k) => isset($r[$k]) && is_numeric($r[$k]) ? (float) $r[$k] : null;
        $hrMin = $n('humedadmin');
        $hrMax = $n('humedadmax');
        $viento = $n('vientoavg');
        if ($viento !== null && ($this->cfg['viento_unidad'] ?? 'kmh') === 'ms') {
            $viento *= 3.6;
        }
        // Radiacion global diaria en MJ/m2: piranometro; si falta, radiaciontotal (kJ/m2).
        $rad = $n('piranometro');
        if ($rad === null && ($rt = $n('radiaciontotal')) !== null) {
            $rad = $rt > 100 ? $rt / 1000 : $rt;
        }

        return [
            'estacion' => [
                'id_api' => (string) $idApi,
                'codigo' => isset($est['codigo']) ? trim((string) $est['codigo']) : null,
                'nombre' => isset($est['finca']) ? trim((string) $est['finca']) : null,
            ],
            'fecha' => $fecha,
            'lluvia' => $n('lluviasum'),
            'tmax' => $n('tempmax'),
            'tmin' => $n('tempmin'),
            'tmed' => $n('tempavg'),
            'hr' => $hrMin !== null && $hrMax !== null ? ($hrMin + $hrMax) / 2 : ($hrMax ?? $hrMin),
            'hr_min' => $hrMin,
            'hr_max' => $hrMax,
            'viento' => $viento,
            'radiacion' => $rad,
            'eto' => $n('eto'),
        ];
    }

    /* -------------------------------- HTTP -------------------------------- */

    protected function url(string $ruta): string
    {
        if (! $this->configurada()) {
            throw new RuntimeException('La API del ICC no esta configurada (ICC_API_URL).');
        }
        $url = rtrim($this->cfg['url'], '/').'/'.ltrim($ruta, '/');
        if (($this->cfg['auth'] ?? '') === 'query' && ! empty($this->cfg['token'])) {
            $url .= (str_contains($url, '?') ? '&' : '?').rawurlencode($this->cfg['query_param'] ?? 'api_key').'='.rawurlencode($this->cfg['token']);
        }

        return $url;
    }

    protected function cliente(): PendingRequest
    {
        $c = Http::timeout($this->cfg['timeout'] ?? 30)->acceptJson()->withOptions(['verify' => $this->cfg['verificar_ssl'] ?? true]);
        $token = $this->cfg['token'] ?? null;

        switch ($this->cfg['auth'] ?? 'bearer') {
            case 'bearer':
                return $token ? $c->withToken($token) : $c;
            case 'header':
                return $token ? $c->withHeaders([$this->cfg['header'] ?? 'X-API-Key' => $token]) : $c;
            case 'basic':
                return $c->withBasicAuth((string) ($this->cfg['usuario'] ?? ''), (string) ($this->cfg['clave'] ?? ''));
            default:
                return $c;
        }
    }

    /** GET y lista de registros (respuesta [...] o {data: [...]}). */
    protected function pedir(string $url): array
    {
        try {
            $resp = $this->cliente()->get($url);
        } catch (\Throwable $e) {
            $m = $e->getMessage();
            if (preg_match('/SSL|certificate|cURL error 60/i', $m)) {
                throw new RuntimeException('El certificado SSL del servidor no se pudo verificar (posible inspeccion del firewall). Pruebe desmarcando "Verificar certificado SSL". Detalle: '.Str::limit($m, 160));
            }
            if (preg_match('/cURL error (6|7|28)/', $m)) {
                throw new RuntimeException('No se pudo llegar al servidor (nombre, red o tiempo de espera). Revise la URL y que el servidor de SIGEC tenga salida a internet. Detalle: '.Str::limit($m, 160));
            }
            throw new RuntimeException('No se pudo conectar con la API del ICC: '.Str::limit($m, 200));
        }
        $detalle = fn () => Str::limit(trim(strip_tags((string) (($resp->json('message') ?? null) ?: $resp->body()))), 160);
        if (in_array($resp->status(), [401, 403], true)) {
            throw new RuntimeException('La API del ICC rechazo las credenciales (HTTP '.$resp->status().'). Revise el tipo de autenticacion y el token/usuario. Respuesta: '.$detalle());
        }
        if ($resp->status() === 404) {
            throw new RuntimeException('La ruta no existe en ese servidor (HTTP 404). Revise la URL base y la ruta de datos. Respuesta: '.$detalle());
        }
        if (! $resp->successful()) {
            throw new RuntimeException('La API del ICC respondio con error HTTP '.$resp->status().'. Respuesta: '.$detalle());
        }
        $json = $resp->json();
        if (! is_array($json)) {
            $html = stripos((string) $resp->header('Content-Type'), 'html') !== false;
            throw new RuntimeException($html
                ? 'El servidor devolvio una pagina HTML en lugar de datos (suele ser la pagina de inicio de sesion: falta o es incorrecta la autenticacion).'
                : 'La API del ICC no devolvio JSON.');
        }

        return array_is_list($json) ? $json : (is_array($json['data'] ?? null) ? $json['data'] : []);
    }
}
