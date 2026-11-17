<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AddSiglaToProgramasYRecodificar extends Migration
{
    /** Palabras que no aportan a la sigla (copia de Codificacion::VACIAS al momento de esta migracion). */
    const VACIAS = ['de', 'del', 'la', 'las', 'el', 'los', 'y', 'e', 'en', 'para', 'por', 'a', 'al', 'con'];

    /**
     * Codigos jerarquicos (ver App\Services\Codificacion): cada programa recibe una
     * sigla y los proyectos, ensayos e IDs de muestra existentes pasan al formato
     * SIGLA-AA-NN / SIGLA-AA-NN-ENN. Las muestras ya registradas en el Laboratorio
     * bloquean el cambio de su ensayo (queda en el log y se conserva el codigo).
     *
     * Usa Query Builder (no modelos ni servicios) para que una migracion ya escrita no cambie
     * cuando evolucionen los modelos. Las equivalencias anterior -> nuevo y muestras_lab.id_muestra_anterior
     * las reconstruye 2026_11_04_090000 desde el log "[codificacion]".
     */
    public function up()
    {
        Schema::table('programas', function (Blueprint $table) {
            $table->string('sigla', 5)->nullable()->unique()->after('nombre');
        });

        foreach (DB::table('programas')->orderBy('id')->get(['id', 'nombre']) as $programa) {
            DB::table('programas')->where('id', $programa->id)->update([
                'sigla' => $this->siglaSugerida((string) $programa->nombre, (int) $programa->id),
                'updated_at' => now(),
            ]);
        }

        $siglas = DB::table('programas')->pluck('sigla', 'id');
        $proyectos = DB::table('proyectos')->orderBy('programa_id')->orderByRaw('COALESCE(inicio, DATE(created_at))')->orderBy('id')->get();
        foreach ($proyectos as $proyecto) {
            $proyecto->sigla = $siglas[$proyecto->programa_id] ?? null;
            try {
                foreach ($this->recodificarProyecto($proyecto) as $antes => $despues) {
                    Log::info("[codificacion] {$antes} -> {$despues}");
                }
            } catch (RuntimeException $e) {
                Log::warning('[codificacion] ' . $e->getMessage());
            }
        }
    }

    public function down()
    {
        // Los codigos anteriores eran libres: no se restauran.
        Schema::table('programas', function (Blueprint $table) {
            $table->dropUnique(['sigla']);
            $table->dropColumn('sigla');
        });
    }

    protected function siglaSugerida(string $nombre, int $ignorarId): string
    {
        $limpio = strtoupper(Str::ascii($nombre));
        $palabras = array_values(array_filter(preg_split('/[^A-Z0-9]+/', $limpio), fn ($p) => $p !== '' && ! in_array(strtolower($p), self::VACIAS, true)));
        $base = count($palabras) >= 2
            ? implode('', array_map(fn ($p) => $p[0], array_slice($palabras, 0, 4)))
            : substr($palabras[0] ?? 'PRG', 0, 3);
        $base = substr(str_pad($base, 2, 'X'), 0, 4);

        $sigla = $base;
        for ($i = 2; DB::table('programas')->where('sigla', $sigla)->where('id', '!=', $ignorarId)->exists(); $i++) {
            $sigla = substr($base, 0, 3) . $i;
        }

        return $sigla;
    }

    protected function anio($inicio, $creado): string
    {
        return Carbon::parse($inicio ?: ($creado ?: now()))->format('y');
    }

    /** Siguiente numero libre (2 digitos) para $prefijo en $tabla, sin contar el registro $ignorarId. */
    protected function siguiente(string $tabla, string $prefijo, int $ignorarId): string
    {
        $max = DB::table($tabla)->where('codigo', 'like', str_replace(['%', '_'], ['\%', '\_'], $prefijo) . '%')
            ->where('id', '!=', $ignorarId)
            ->pluck('codigo')
            ->map(fn ($c) => substr($c, strlen($prefijo)))
            ->filter(fn ($s) => preg_match('/^\d+$/', $s))
            ->map(fn ($s) => (int) $s)
            ->max();

        return str_pad((string) (($max ?? 0) + 1), 2, '0', STR_PAD_LEFT);
    }

    /** Muestras de los ensayos ya registradas en el Laboratorio: no se puede cambiar su codigo. */
    protected function bloqueo(iterable $ensayoIds): ?string
    {
        $ids = collect($ensayoIds)->all();
        $vinculadas = DB::table('muestras_lab')->whereIn('ensayo_id', $ids)
            ->where(fn ($q) => $q->whereNotNull('lab_muestra_id')->orWhereNotNull('lab_vinculo'))
            ->count();

        return $vinculadas
            ? "{$vinculadas} muestra(s) ya estan registradas en el Laboratorio con su ID actual; cambiar el codigo romperia ese vinculo."
            : null;
    }

    protected function recodificarProyecto(object $proyecto): array
    {
        $cambios = [];
        $prefijo = "{$proyecto->sigla}-" . $this->anio($proyecto->inicio, $proyecto->created_at) . '-';
        $alDia = Str::startsWith($proyecto->codigo, $prefijo) && preg_match('/^\d+$/', substr($proyecto->codigo, strlen($prefijo)));
        if (! $alDia) {
            if ($motivo = $this->bloqueo(DB::table('ensayos')->where('proyecto_id', $proyecto->id)->pluck('id'))) {
                throw new RuntimeException("No se puede cambiar el codigo del proyecto {$proyecto->codigo}: {$motivo}");
            }
            $nuevo = $prefijo . $this->siguiente('proyectos', $prefijo, $proyecto->id);
            if ($nuevo !== $proyecto->codigo) {
                $cambios[$proyecto->codigo] = $nuevo;
                DB::table('proyectos')->where('id', $proyecto->id)->update(['codigo' => $nuevo, 'updated_at' => now()]);
                $proyecto->codigo = $nuevo;
            }
        }

        foreach (DB::table('ensayos')->where('proyecto_id', $proyecto->id)->orderBy('id')->get() as $ensayo) {
            $cambios += $this->recodificarEnsayo($ensayo, $proyecto);
        }

        return $cambios;
    }

    protected function recodificarEnsayo(object $ensayo, object $proyecto): array
    {
        // tipo_estudio lo crea una migracion posterior: aqui todo ensayo es "E".
        $letra = ($ensayo->tipo_estudio ?? null) === 'prueba' ? 'P' : 'E';
        $prefijo = "{$proyecto->codigo}-{$letra}";
        if (Str::startsWith($ensayo->codigo, $prefijo) && preg_match('/^\d+$/', substr($ensayo->codigo, strlen($prefijo)))) {
            return [];
        }
        if ($motivo = $this->bloqueo([$ensayo->id])) {
            throw new RuntimeException("No se puede cambiar el codigo del ensayo {$ensayo->codigo}: {$motivo}");
        }

        $anterior = $ensayo->codigo;
        $nuevo = $prefijo . $this->siguiente('ensayos', $prefijo, $ensayo->id);
        if ($nuevo === $anterior) {
            return [];
        }

        DB::transaction(function () use ($ensayo, $anterior, $nuevo) {
            DB::table('ensayos')->where('id', $ensayo->id)->update(['codigo' => $nuevo, 'updated_at' => now()]);
            // Solo cambia el tramo del ensayo, en el formato actual {ensayo}-{tipo}{n}
            // y en el anterior SIGEC-{ensayo}-{tipo}-{n}-{aa}.
            $prefijos = ["SIGEC-{$anterior}-" => "SIGEC-{$nuevo}-", "{$anterior}-" => "{$nuevo}-"];
            foreach (DB::table('muestras_lab')->where('ensayo_id', $ensayo->id)->get(['id', 'id_muestra']) as $m) {
                foreach ($prefijos as $viejo => $nuevoPrefijo) {
                    if (Str::startsWith($m->id_muestra, $viejo)) {
                        DB::table('muestras_lab')->where('id', $m->id)->update([
                            'id_muestra' => $nuevoPrefijo . substr($m->id_muestra, strlen($viejo)),
                            'updated_at' => now(),
                        ]);
                        break;
                    }
                }
            }
        });

        return [$anterior => $nuevo];
    }
}
