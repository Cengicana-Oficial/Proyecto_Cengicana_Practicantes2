<?php

namespace App\Exports;

use App\Models\Bitacora;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** Anotaciones de bitacora de los ensayos (opcional: rango de fechas). */
class BitacoraExport extends ReporteExcel implements FromCollection, WithHeadings, WithMapping
{
    protected Collection $ensayoIds;

    protected ?string $desde;

    protected ?string $hasta;

    public function __construct(Collection $ensayoIds, ?string $desde = null, ?string $hasta = null)
    {
        $this->ensayoIds = $ensayoIds;
        $this->desde = $desde;
        $this->hasta = $hasta;
    }

    public function collection()
    {
        return Bitacora::with(['ensayo.proyecto.programa', 'responsable'])
            ->whereIn('ensayo_id', $this->ensayoIds)
            ->when($this->desde, fn ($q) => $q->whereDate('fecha', '>=', $this->desde))
            ->when($this->hasta, fn ($q) => $q->whereDate('fecha', '<=', $this->hasta))
            ->orderBy('fecha')->orderBy('id')
            ->get();
    }

    public function headings(): array
    {
        return ['Fecha', 'Programa', 'Proyecto', 'Ensayo', 'Tipo de actividad', 'Descripcion', 'Registrado por'];
    }

    public function map($b): array
    {
        $proyecto = optional($b->ensayo)->proyecto;

        return [
            optional($b->fecha)->format('Y-m-d'),
            optional(optional($proyecto)->programa)->sigla,
            optional($proyecto)->codigo,
            optional($b->ensayo)->codigo,
            $b->tipo,
            $b->descripcion,
            optional($b->responsable)->name,
        ];
    }
}
