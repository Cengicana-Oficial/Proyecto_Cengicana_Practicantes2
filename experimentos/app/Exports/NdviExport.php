<?php

namespace App\Exports;

use App\Models\NdviImagen;
use App\Models\Parcela;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class NdviExport extends ReporteExcel implements FromCollection, WithHeadings
{
    protected Collection $ensayoIds;
    protected ?string $desde;
    protected ?string $hasta;
    public function __construct(Collection $ensayoIds, ?string $desde = null, ?string $hasta = null) { $this->ensayoIds = $ensayoIds; $this->desde = $desde; $this->hasta = $hasta; }
    public function headings(): array { return ['Fecha', 'Ensayo', 'Fuente', 'Imagen', 'Parcela', 'Tratamiento', 'Repeticion', 'NDVI medio', 'Minimo', 'Maximo', 'Pixeles']; }
    public function collection()
    {
        $parcelas = Parcela::with('tratamiento')->whereIn('ensayo_id', $this->ensayoIds)->get()->keyBy('id');
        $filas = collect();
        NdviImagen::with('ensayo:id,codigo')->whereIn('ensayo_id', $this->ensayoIds)->when($this->desde, fn ($q) => $q->whereDate('fecha', '>=', $this->desde))->when($this->hasta, fn ($q) => $q->whereDate('fecha', '<=', $this->hasta))->orderBy('fecha')->get()
            ->each(function ($i) use ($parcelas, &$filas) {
                foreach ($i->valores ?: [] as $id => $v) {
                    $p = $parcelas->get($id);
                    if (! $p || $p->ensayo_id != $i->ensayo_id || ! is_array($v) || ! isset($v['media']) || ! is_numeric($v['media'])) continue;
                    $filas->push([optional($i->fecha)->format('Y-m-d'), optional($i->ensayo)->codigo, $i->fuente, $i->nombre, $p->codigo, optional($p->tratamiento)->codigo, $p->repeticion, (float) $v['media'], $v['min'] ?? null, $v['max'] ?? null, $v['n'] ?? null]);
                }
            });
        return $filas;
    }
}
