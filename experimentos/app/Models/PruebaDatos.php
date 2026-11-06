<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Tabla de datos de una prueba tecnica (ver la migracion add_pruebas_tecnicas). */
class PruebaDatos extends Model
{
    protected $table = 'prueba_datos';

    protected $fillable = ['ensayo_id', 'columnas', 'filas', 'grafico', 'actualizado_por'];

    protected $casts = [
        'columnas' => 'array',
        'filas' => 'array',
        'grafico' => 'array',
    ];
}
