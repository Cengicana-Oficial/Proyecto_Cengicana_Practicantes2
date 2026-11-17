<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Lee solo la primera hoja del libro (p. ej. la plantilla de estaciones,
 * cuya segunda hoja son instrucciones). Las filas quedan en $hoja->filas.
 */
class PrimeraHojaImport implements WithMultipleSheets
{
    public FilasImport $hoja;

    public function __construct()
    {
        $this->hoja = new FilasImport();
    }

    public function sheets(): array
    {
        return [0 => $this->hoja];
    }
}
