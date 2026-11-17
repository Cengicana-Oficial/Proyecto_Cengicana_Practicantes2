<?php

namespace App\Services;

use App\Models\Ensayo;
use App\Models\MuestraLab;
use App\Models\Programa;
use App\Models\Proyecto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Codigos automaticos y jerarquicos:
 *   programa  sigla           COS
 *   proyecto  SIGLA-AA-NN     COS-26-01   (año de inicio, consecutivo dentro del programa y año)
 *   ensayo    PROYECTO-ENN    COS-26-01-E03
 * El codigo del ensayo va dentro del ID de sus muestras (COS-26-01-E03-SU001; antes SIGEC-COS-26-01-E03-SU-001-26),
 * por eso al recodificar tambien se renombran las muestras, salvo que el Laboratorio
 * ya las tenga registradas (alli quedo el ID impreso en la etiqueta).
 */
class Codificacion
{
    /** Palabras que no aportan a la sigla. */
    const VACIAS = ['de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'en', 'para', 'por', 'a', 'al', 'con'];

    /** Sigla sugerida a partir del nombre: iniciales si son 2+ palabras, si no las 3 primeras letras. */
    public static function siglaSugerida(string $nombre, ?int $ignorarId = null): string
    {
        $limpio = strtoupper(Str::ascii($nombre));
        $palabras = array_values(array_filter(preg_split('/[^A-Z0-9]+/', $limpio), fn ($p) => $p !== '' && ! in_array(strtolower($p), self::VACIAS, true)));
        $base = count($palabras) >= 2
            ? implode('', array_map(fn ($p) => $p[0], array_slice($palabras, 0, 4)))
            : substr($palabras[0] ?? 'PRG', 0, 3);
        $base = substr(str_pad($base, 2, 'X'), 0, 4);

        $sigla = $base;
        for ($i = 2; static::siglaUsada($sigla, $ignorarId); $i++) {
            $sigla = substr($base, 0, 3) . $i;
        }

        return $sigla;
    }

    protected static function siglaUsada(string $sigla, ?int $ignorarId): bool
    {
        return Programa::where('sigla', $sigla)->when($ignorarId, fn ($q) => $q->whereKeyNot($ignorarId))->exists();
    }

    /** Año (2 digitos) que identifica al proyecto: su inicio o, si no tiene, la fecha de registro. */
    public static function anioProyecto($inicio, $creado = null): string
    {
        return Carbon::parse($inicio ?: ($creado ?: now()))->format('y');
    }

    /** Siguiente codigo libre de proyecto para el programa y año. */
    public static function codigoProyecto(Programa $programa, string $anio, ?Proyecto $ignorar = null): string
    {
        $prefijo = "{$programa->sigla}-{$anio}-";

        return $prefijo . static::siguiente(Proyecto::query(), $prefijo, '/^\d+$/', $ignorar);
    }

    /** Letra del codigo: E para ensayos experimentales, P para pruebas tecnicas. */
    public static function letra(?string $tipoEstudio): string
    {
        return $tipoEstudio === 'prueba' ? 'P' : 'E';
    }

    /** Siguiente codigo libre de ensayo (PROYECTO-Enn) o de prueba tecnica (PROYECTO-Pnn) del proyecto. */
    public static function codigoEnsayo(Proyecto $proyecto, ?Ensayo $ignorar = null, ?string $tipoEstudio = null): string
    {
        $tipoEstudio = $tipoEstudio ?? ($ignorar ? $ignorar->tipo_estudio : null);
        $prefijo = "{$proyecto->codigo}-" . static::letra($tipoEstudio);

        return $prefijo . static::siguiente(Ensayo::query(), $prefijo, '/^\d+$/', $ignorar);
    }

    protected static function siguiente($query, string $prefijo, string $patron, $ignorar): string
    {
        $max = (clone $query)->where('codigo', 'like', str_replace(['%', '_'], ['\%', '\_'], $prefijo) . '%')
            ->when($ignorar, fn ($q) => $q->whereKeyNot($ignorar->getKey()))
            ->pluck('codigo')
            ->map(fn ($c) => substr($c, strlen($prefijo)))
            ->filter(fn ($s) => preg_match($patron, $s))
            ->map(fn ($s) => (int) $s)
            ->max();

        return str_pad((string) (($max ?? 0) + 1), 2, '0', STR_PAD_LEFT);
    }

    /** ¿El codigo del proyecto ya corresponde a su programa y año? */
    public static function proyectoAlDia(Proyecto $proyecto): bool
    {
        $prefijo = "{$proyecto->programa->sigla}-" . static::anioProyecto($proyecto->inicio, $proyecto->created_at) . '-';

        return Str::startsWith($proyecto->codigo, $prefijo) && preg_match('/^\d+$/', substr($proyecto->codigo, strlen($prefijo)));
    }

    public static function ensayoAlDia(Ensayo $ensayo): bool
    {
        $prefijo = "{$ensayo->proyecto->codigo}-" . static::letra($ensayo->tipo_estudio);

        return Str::startsWith($ensayo->codigo, $prefijo) && preg_match('/^\d+$/', substr($ensayo->codigo, strlen($prefijo)));
    }

    /**
     * Motivo por el que no se puede cambiar el codigo de estos ensayos, o null.
     * Una muestra ya registrada en el Laboratorio quedo alli con su ID impreso.
     */
    public static function bloqueo($ensayoIds): ?string
    {
        $vinculadas = MuestraLab::whereIn('ensayo_id', $ensayoIds)
            ->where(fn ($q) => $q->whereNotNull('lab_muestra_id')->orWhereNotNull('lab_vinculo'))
            ->count();

        return $vinculadas
            ? "{$vinculadas} muestra(s) ya estan registradas en el Laboratorio con su ID actual; cambiar el codigo romperia ese vinculo."
            : null;
    }

    /**
     * Pone al dia el codigo del proyecto y, en cascada, el de sus ensayos y muestras.
     * Devuelve [codigo anterior => codigo nuevo] de lo que cambio.
     *
     * @throws RuntimeException si hay muestras ya registradas en el Laboratorio
     */
    public static function recodificarProyecto(Proyecto $proyecto, bool $forzar = false): array
    {
        $proyecto->loadMissing('programa');
        $cambios = [];
        if ($forzar || ! static::proyectoAlDia($proyecto)) {
            if ($motivo = static::bloqueo($proyecto->ensayos()->pluck('id'))) {
                throw new RuntimeException("No se puede cambiar el codigo del proyecto {$proyecto->codigo}: {$motivo}");
            }
            $nuevo = static::codigoProyecto($proyecto->programa, static::anioProyecto($proyecto->inicio, $proyecto->created_at), $proyecto);
            if ($nuevo !== $proyecto->codigo) {
                $cambios[$proyecto->codigo] = $nuevo;
                $anteriorProyecto = $proyecto->codigo;
                $proyecto->forceFill(['codigo' => $nuevo])->save();
                static::registrarEquivalencia('proyecto', $proyecto->id, $anteriorProyecto, $nuevo);
            }
        }

        foreach ($proyecto->ensayos()->orderBy('id')->get() as $ensayo) {
            $ensayo->setRelation('proyecto', $proyecto);
            $cambios += static::recodificarEnsayo($ensayo, $forzar);
        }

        return $cambios;
    }

    /** Pone al dia el codigo del ensayo y el ID de sus muestras. */
    public static function recodificarEnsayo(Ensayo $ensayo, bool $forzar = false): array
    {
        $ensayo->loadMissing('proyecto');
        if (! $forzar && static::ensayoAlDia($ensayo)) {
            return [];
        }
        if ($motivo = static::bloqueo([$ensayo->id])) {
            throw new RuntimeException("No se puede cambiar el codigo del ensayo {$ensayo->codigo}: {$motivo}");
        }

        $anterior = $ensayo->codigo;
        $nuevo = static::codigoEnsayo($ensayo->proyecto, $ensayo);
        if ($nuevo === $anterior) {
            return [];
        }

        DB::transaction(function () use ($ensayo, $anterior, $nuevo) {
            $ensayo->forceFill(['codigo' => $nuevo])->save();
            static::registrarEquivalencia('ensayo', $ensayo->id, $anterior, $nuevo);
            // Solo cambia el tramo del ensayo, en el formato actual {ensayo}-{tipo}{n}
            // y en el anterior SIGEC-{ensayo}-{tipo}-{n}-{aa}.
            $prefijos = ["SIGEC-{$anterior}-" => "SIGEC-{$nuevo}-", "{$anterior}-" => "{$nuevo}-"];
            $conAnterior = static::columnaMuestraAnterior();
            $columnas = $conAnterior ? ['id', 'id_muestra', 'id_muestra_anterior'] : ['id', 'id_muestra'];
            MuestraLab::where('ensayo_id', $ensayo->id)->get($columnas)->each(function ($m) use ($prefijos, $conAnterior) {
                foreach ($prefijos as $viejo => $nuevoPrefijo) {
                    if (Str::startsWith($m->id_muestra, $viejo)) {
                        $idViejo = $m->id_muestra;
                        $cambio = ['id_muestra' => $nuevoPrefijo . substr($idViejo, strlen($viejo))];
                        // La etiqueta impresa con el ID viejo debe seguir leyendose: se conserva el
                        // primer ID de la muestra (el que llego a imprimirse); la tabla de
                        // equivalencias guarda cada cambio.
                        if ($conAnterior && $m->id_muestra_anterior === null) {
                            $cambio['id_muestra_anterior'] = $idViejo;
                        }
                        $m->forceFill($cambio)->save();
                        static::registrarEquivalencia('muestra', $m->id, $idViejo, $cambio['id_muestra']);
                        break;
                    }
                }
            });
        });

        return [$anterior => $nuevo];
    }

    /** ¿Existe muestras_lab.id_muestra_anterior? (la crea una migracion posterior a la recodificacion original) */
    public static function columnaMuestraAnterior(): bool
    {
        return Schema::hasColumn('muestras_lab', 'id_muestra_anterior');
    }

    /** Guarda en BD la equivalencia codigo anterior -> nuevo (no solo en el log). */
    protected static function registrarEquivalencia(string $tipo, int $registroId, string $anterior, string $nuevo): void
    {
        if (! Schema::hasTable('codificacion_equivalencias')) {
            return;
        }
        DB::table('codificacion_equivalencias')->insert([
            'tipo' => $tipo,
            'registro_id' => $registroId,
            'codigo_anterior' => $anterior,
            'codigo_nuevo' => $nuevo,
            'origen' => 'recodificacion',
            'created_at' => now(),
        ]);
    }
}
