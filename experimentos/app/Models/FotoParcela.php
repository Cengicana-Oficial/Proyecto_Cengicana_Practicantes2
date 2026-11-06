<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Foto de una parcela en una fecha de muestreo (evidencia del efecto del tratamiento). */
class FotoParcela extends Model
{
    protected $table = 'fotos_parcela';

    protected $fillable = [
        'ensayo_id', 'parcela_id', 'fecha', 'formulario_id', 'nombre', 'path', 'miniatura',
        'ancho', 'alto', 'nota', 'subido_por',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function ensayo()
    {
        return $this->belongsTo(Ensayo::class);
    }

    public function parcela()
    {
        return $this->belongsTo(Parcela::class);
    }

    public function subidoPor()
    {
        return $this->belongsTo(User::class, 'subido_por');
    }
}
