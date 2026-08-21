<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Import generico: parsea la hoja y normaliza los encabezados
 * (Fecha, Variable, Parcela, Valor, Observaciones -> fecha, variable,
 * parcela, valor, observaciones) sin crear modelos automaticamente. El
 * matching contra Variable/Parcela reales y la creacion de Evaluaciones
 * ocurre en AnalisisController, para poder reportar filas omitidas.
 */
class FilasImport implements ToCollection, WithHeadingRow
{
    public Collection $filas;

    public function collection(Collection $filas)
    {
        $this->filas = $filas;
    }
}
