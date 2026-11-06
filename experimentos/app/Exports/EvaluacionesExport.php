<?php

namespace App\Exports;

use App\Models\Evaluacion;
use App\Models\Parcela;
use App\Services\ResultadosLaboratorio;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/** Evaluaciones de campo y resultados de laboratorio (categoria "Laboratorio") de los ensayos. */
class EvaluacionesExport extends ReporteExcel implements FromCollection, WithHeadings, WithMapping
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
        $campo = Evaluacion::with(['variable', 'parcela.ensayo', 'parcela.tratamiento', 'responsable'])
            ->whereHas('parcela', fn ($q) => $q->whereIn('ensayo_id', $this->ensayoIds))
            ->when($this->desde, fn ($q) => $q->whereDate('fecha', '>=', $this->desde))
            ->when($this->hasta, fn ($q) => $q->whereDate('fecha', '<=', $this->hasta))
            ->orderBy('fecha')
            ->get()
            ->map(fn ($e) => [
                optional($e->fecha)->format('Y-m-d'),
                optional($e->parcela->ensayo ?? null)->codigo,
                optional($e->parcela)->codigo,
                optional(optional($e->parcela)->tratamiento)->codigo,
                optional($e->parcela)->repeticion,
                'Campo',
                optional($e->variable)->categoria,
                optional($e->variable)->nombre,
                $e->valor,
                optional($e->variable)->unidad,
                optional($e->responsable)->name,
                $e->obs,
            ]);

        $lab = app(ResultadosLaboratorio::class)->porEnsayos($this->ensayoIds);
        $vars = collect($lab['variables'])->keyBy('id');
        $parcelas = Parcela::with(['ensayo:id,codigo', 'tratamiento:id,codigo'])
            ->whereIn('id', collect($lab['registros'])->pluck('parcela_id')->unique())->get()->keyBy('id');
        $enRango = fn ($r) => (! $this->desde || $r['fecha'] >= $this->desde) && (! $this->hasta || $r['fecha'] <= $this->hasta);
        $laboratorio = collect($lab['registros'])->filter($enRango)->map(function ($r) use ($vars, $parcelas) {
            $p = $parcelas->get($r['parcela_id']);
            $v = $vars->get($r['variable_id']);

            return [
                $r['fecha'], optional(optional($p)->ensayo)->codigo, optional($p)->codigo, optional(optional($p)->tratamiento)->codigo,
                optional($p)->repeticion, 'Laboratorio', $v['tipo_muestra'] ?? null, $v['nombre'] ?? null, $r['valor'], $v['unidad'] ?? null,
                $r['responsable'], trim("Muestra {$r['muestra']}" . ($r['informe'] ? " · informe {$r['informe']}" : '')),
            ];
        });

        return $campo->concat($laboratorio)->sortBy(fn ($r) => $r[0] . '|' . $r[1] . '|' . $r[2])->values();
    }

    public function headings(): array
    {
        return ['Fecha', 'Ensayo', 'Parcela', 'Tratamiento', 'Repeticion', 'Origen', 'Categoria / tipo de muestra', 'Variable', 'Valor', 'Unidad', 'Responsable', 'Observaciones'];
    }

    public function map($fila): array
    {
        return $fila;
    }
}
