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

/**
 * Codificacion de los ID de muestra de SIGEC. La pagina Laboratorio de SIGEC se retiro:
 * el trabajo de laboratorio se hace en el modulo Laboratorio del portal y SIGEC lee
 * sus resultados (App\Services\LaboratorioModulo).
 */
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

    /**
     * ID unico: SIGEC-{ensayo}-{tipo}-{secuencia}-{aa}, con el año de la fecha de
     * muestreo. La secuencia sigue al mayor ID existente (no al conteo), asi
     * borrar una muestra nunca provoca un ID repetido.
     */
    public static function generarIdMuestra(Ensayo $ensayo, string $tipo, $fecha = null): string
    {
        $prefijo = self::prefijoId($ensayo, $tipo);
        $anio = \Illuminate\Support\Carbon::parse($fecha ?: now())->format('y');

        return sprintf('%s%03d-%s', $prefijo, self::ultimaSecuencia($prefijo, $anio) + 1, $anio);
    }

    public static function prefijoId(Ensayo $ensayo, string $tipo): string
    {
        return sprintf('SIGEC-%s-%s-', $ensayo->codigo, self::tipoCodigo($tipo));
    }

    /** Mayor secuencia usada con ese prefijo y año (0 si no hay). */
    public static function ultimaSecuencia(string $prefijo, string $anio): int
    {
        return (int) MuestraLab::where('id_muestra', 'like', str_replace(['%', '_'], ['\%', '\_'], $prefijo) . '%-' . $anio)
            ->pluck('id_muestra')
            ->map(fn ($id) => preg_match('/-(\d+)-\d{2}$/', $id, $m) ? (int) $m[1] : 0)
            ->max();
    }
}
