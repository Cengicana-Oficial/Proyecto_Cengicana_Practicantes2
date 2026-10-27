<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Campo personalizado que el muestreador llena segun el tipo de muestra. */
class CampoMuestra extends Model
{
    protected $table = 'campos_muestra';

    protected $fillable = ['tipo_muestra', 'clave', 'etiqueta', 'tipo_dato', 'unidad', 'opciones', 'ayuda', 'obligatorio', 'activo', 'orden', 'predefinido'];

    protected $casts = [
        'opciones' => 'array',
        'obligatorio' => 'boolean',
        'activo' => 'boolean',
        'orden' => 'integer',
        'predefinido' => 'boolean',
    ];

    /**
     * Valida y normaliza un valor capturado (numero con coma o punto, fecha
     * Y-m-d, opcion de la lista sin distinguir mayusculas).
     *
     * @throws InvalidArgumentException
     */
    public function normalizar(string $valor): string
    {
        $valor = trim($valor);
        if ($this->tipo_dato === 'numero') {
            $n = str_replace([' ', ','], ['', '.'], $valor);
            if (! is_numeric($n)) {
                throw new InvalidArgumentException("\"{$valor}\" no es un numero valido para {$this->etiqueta}.");
            }

            return $n;
        }
        if ($this->tipo_dato === 'fecha') {
            $t = strtotime($valor);
            if ($t === false) {
                throw new InvalidArgumentException("\"{$valor}\" no es una fecha valida para {$this->etiqueta}.");
            }

            return date('Y-m-d', $t);
        }
        if ($this->tipo_dato === 'opciones' && ! empty($this->opciones)) {
            foreach ($this->opciones as $op) {
                if (mb_strtolower($op) === mb_strtolower($valor)) {
                    return $op;
                }
            }
            throw new InvalidArgumentException("\"{$valor}\" no es una opcion de {$this->etiqueta}.");
        }

        return mb_substr($valor, 0, 255);
    }
}
