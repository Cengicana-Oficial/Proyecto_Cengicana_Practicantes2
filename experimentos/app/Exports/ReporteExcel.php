<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

abstract class ReporteExcel extends DefaultValueBinder implements WithCustomValueBinder, WithEvents, ShouldAutoSize
{
    public function bindValue(Cell $cell, $value = null)
    {
        if (is_string($value) && str_starts_with($value, '=')) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);
            return true;
        }
        return parent::bindValue($cell, $value);
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => function (AfterSheet $event) {
            $s = $event->sheet->getDelegate();
            $rango = 'A1:' . $s->getHighestColumn() . '1';
            $s->freezePane('A2');
            $s->setAutoFilter($s->calculateWorksheetDimension());
            $s->getStyle($rango)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $s->getStyle($rango)->getFill()->setFillType('solid')->getStartColor()->setARGB('FF1F5632');
            $s->getRowDimension(1)->setRowHeight(28);
            foreach ($s->getColumnDimensions() as $dimension) {
                if ($dimension->getWidth() > 65) { $dimension->setAutoSize(false); $dimension->setWidth(65); }
            }
        }];
    }
}
