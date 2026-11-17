<?php

namespace App\Exports;

use App\Models\Parcela;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ParcelasExport extends ReporteExcel implements FromCollection, WithHeadings, WithMapping
{
    protected Collection $ensayoIds;
    public function __construct(Collection $ensayoIds) { $this->ensayoIds = $ensayoIds; }
    public function collection()
    {
        return Parcela::with(['ensayo.proyecto.programa', 'tratamiento'])->whereIn('ensayo_id', $this->ensayoIds)->orderBy('ensayo_id')->orderBy('codigo')->get();
    }
    public function headings(): array { return ['Programa', 'Proyecto', 'Ensayo', 'Parcela', 'Tratamiento', 'Descripcion del tratamiento', 'Repeticion', 'Area (m²)', 'Notas']; }
    public function map($p): array
    {
        $e = $p->ensayo; $proyecto = optional($e)->proyecto;
        return [optional(optional($proyecto)->programa)->sigla, optional($proyecto)->codigo, optional($e)->codigo, $p->codigo, optional($p->tratamiento)->codigo, optional($p->tratamiento)->descripcion, $p->repeticion, $p->area, $p->notas];
    }
}
