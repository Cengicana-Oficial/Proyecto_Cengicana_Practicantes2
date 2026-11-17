<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class Variable extends Model
{
    protected $table = 'variables';

    protected $fillable = ['nombre', 'descripcion', 'unidad', 'tipo', 'categoria', 'valor_min', 'valor_max', 'decimales', 'opciones', 'activa'];

    protected $casts = [
        'valor_min' => 'float',
        'valor_max' => 'float',
        'decimales' => 'integer',
        'opciones' => 'array',
        'activa' => 'boolean',
    ];

    public function evaluaciones()
    {
        return $this->hasMany(Evaluacion::class, 'variable_id', 'id');
    }

    /**
     * Valida y normaliza un valor segun el tipo: numericas con punto decimal
     * (acepta coma) y redondeo a los decimales definidos; categoricas contra
     * sus opciones (sin distinguir mayusculas). El rango esperado no bloquea.
     *
     * @throws InvalidArgumentException con un mensaje para el usuario
     */
    public function normalizar(string $valor): string
    {
        $valor = trim($valor);

        if ($this->tipo === 'Numerica') {
            $num = str_replace([' ', ','], ['', '.'], $valor);
            if (! is_numeric($num)) {
                throw new InvalidArgumentException("\"{$valor}\" no es un numero valido para {$this->nombre}.");
            }
            $num = (float) $num;
            if ($this->decimales !== null) {
                return number_format($num, $this->decimales, '.', '');
            }

            return rtrim(rtrim(number_format($num, 6, '.', ''), '0'), '.');
        }

        if ($this->tipo === 'Categorica' && ! empty($this->opciones)) {
            foreach ($this->opciones as $op) {
                if (mb_strtolower($op) === mb_strtolower($valor)) {
                    return $op;
                }
            }
            throw new InvalidArgumentException("\"{$valor}\" no es una opcion de {$this->nombre} (" . implode(', ', $this->opciones) . ').');
        }

        return $valor;
    }
}
