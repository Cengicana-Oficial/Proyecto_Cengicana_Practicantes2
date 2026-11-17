<?php

namespace App\Http\Controllers\Soporte;

use App\Http\Controllers\Controller;
use App\Models\AnaliticoPorTipo;
use App\Models\Ensayo;
use App\Models\MuestraLab;
use App\Models\Parcela;
use App\Models\Proyecto;
use App\Models\TipoMuestra;
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
    /** Codigo del tipo para el ID (AG, SU, FO, JU...), del catalogo Sistema > Catalogos > Tipos de muestra. */
    public static function tipoCodigo(string $tipo): string
    {
        return TipoMuestra::codigoDe($tipo);
    }

    /**
     * ID unico: {ensayo}-{tipo}{secuencia}, p. ej. COS-26-01-E01-AG025. El año ya va en el
     * codigo del ensayo, asi que la secuencia es por ensayo y tipo y no se reinicia.
     * Formato anterior (sigue valido para las muestras ya creadas): SIGEC-{ensayo}-{tipo}-{seq}-{aa}.
     * La secuencia sigue al mayor ID existente de ambos formatos (no al conteo): borrar una
     * muestra nunca provoca un ID repetido.
     */
    public static function generarIdMuestra(Ensayo $ensayo, string $tipo, $fecha = null): string
    {
        return sprintf('%s%03d', self::prefijoId($ensayo, $tipo), self::ultimaSecuencia($ensayo, $tipo) + 1);
    }

    public static function prefijoId(Ensayo $ensayo, string $tipo): string
    {
        return sprintf('%s-%s', $ensayo->codigo, self::tipoCodigo($tipo));
    }

    /** Mayor secuencia usada en el ensayo para ese tipo, en el formato nuevo o el anterior (0 si no hay). */
    public static function ultimaSecuencia(Ensayo $ensayo, string $tipo): int
    {
        $nuevo = '/^' . preg_quote(self::prefijoId($ensayo, $tipo), '/') . '(\d+)$/i';
        $anterior = '/^SIGEC-' . preg_quote(self::prefijoId($ensayo, $tipo), '/') . '-(\d+)-\d{2}$/i';

        return (int) MuestraLab::where('ensayo_id', $ensayo->id)->pluck('id_muestra')
            ->map(fn ($id) => preg_match($nuevo, $id, $m) || preg_match($anterior, $id, $m) ? (int) $m[1] : 0)
            ->max();
    }
}
