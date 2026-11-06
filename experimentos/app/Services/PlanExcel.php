<?php

namespace App\Services;

use App\Http\Controllers\Soporte\BitacoraController;
use App\Imports\PrimeraHojaImport;
use App\Models\ActividadPlan;
use App\Models\Ensayo;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Plan de actividades en Excel: plantilla con listas desplegables, exportacion del plan e
 * importacion en dos pasos (vista previa con errores por fila y luego confirmar).
 * La columna Ensayo es opcional: vacia = el ensayo desde el que se importa; con codigo se
 * programa en ese ensayo (si el usuario lo ve), para planificar varios ensayos de una vez.
 */
class PlanExcel
{
    const ENCABEZADOS = ['Ensayo', 'Tipo', 'Detalle', 'Fecha', 'Responsable', 'Avisar (días)', 'Repetir cada (días)', 'Veces', 'Estado'];

    /** Libro con el plan del ensayo (o vacio para la plantilla). */
    public function libro(Ensayo $ensayo, bool $conPlan): Spreadsheet
    {
        $libro = new Spreadsheet();
        $hoja = $libro->getActiveSheet()->setTitle('Plan');
        $hoja->fromArray(self::ENCABEZADOS, null, 'A1');
        $r = 2;
        if ($conPlan) {
            foreach ($ensayo->plan()->with('responsable:id,name,email')->get() as $a) {
                $hoja->fromArray([$ensayo->codigo, $a->tipo, $a->descripcion, ExcelDate::PHPToExcel($a->fecha->copy()->startOfDay()),
                    optional($a->responsable)->email, $a->aviso_dias, null, null, $a->estado], null, 'A'.$r++);
            }
        }
        $hasta = max($r, 2) + 300;
        $hoja->getStyle("D2:D{$hasta}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $hoja->getStyle('A1:I1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F5632']],
        ]);
        $hoja->getStyle('B1')->getFill()->setStartColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF6B00'));
        $hoja->getStyle('D1')->getFill()->setStartColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF6B00'));
        foreach (['A' => 16, 'B' => 24, 'C' => 40, 'D' => 13, 'E' => 30, 'F' => 13, 'G' => 18, 'H' => 8, 'I' => 12] as $c => $w) {
            $hoja->getColumnDimension($c)->setWidth($w);
        }
        $hoja->freezePane('A2');
        $hoja->getStyle("A2:A{$hasta}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        // Listas: tipos de actividad y responsables (hoja oculta, para no topar el limite de 255 caracteres).
        $listas = $libro->createSheet()->setTitle('Listas');
        $tipos = BitacoraController::TIPOS;
        $personas = User::orderBy('name')->get(['name', 'email']);
        foreach ($tipos as $i => $t) {
            $listas->setCellValue('A'.($i + 1), $t);
        }
        foreach ($personas as $i => $u) {
            $listas->setCellValue('B'.($i + 1), $u->email);
        }
        $listas->setSheetState(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet::SHEETSTATE_HIDDEN);
        $lista = function (string $col, string $rango, string $titulo, bool $estricta) use ($hoja, $hasta) {
            $v = new DataValidation();
            $v->setType(DataValidation::TYPE_LIST)->setErrorStyle($estricta ? DataValidation::STYLE_STOP : DataValidation::STYLE_INFORMATION)
                ->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(true)->setShowInputMessage(true)
                ->setPromptTitle($titulo)->setPrompt('Elija de la lista.')->setErrorTitle('Valor no válido')->setError('Elija un valor de la lista.')
                ->setFormula1($rango);
            $hoja->setDataValidation("{$col}2:{$col}{$hasta}", $v);
        };
        $lista('B', 'Listas!$A$1:$A$'.count($tipos), 'Tipo de actividad', true);
        if ($personas->count()) {
            $lista('E', 'Listas!$B$1:$B$'.$personas->count(), 'Responsable (correo)', false);
        }
        $lista('I', '"Pendiente,Realizada,Cancelada"', 'Estado (opcional)', true);

        $ins = $libro->createSheet()->setTitle('Instrucciones');
        $ins->fromArray([
            ['Plan de actividades del ensayo ' . $ensayo->codigo . ' - SIGEC'],
            [''],
            ['Una fila por actividad. Se importa desde el ensayo > pestaña Plan > Importar Excel; antes de guardar se muestra una vista previa con los errores por fila.'],
            ['Las filas iguales a una actividad pendiente ya programada (mismo ensayo, tipo, fecha y detalle) se omiten.'],
            [''],
            ['Columna', 'Obligatoria', 'Descripción', 'Ejemplo'],
            ['Ensayo', 'No', 'Código del ensayo. Vacío = el ensayo desde el que importa. Permite planificar varios ensayos en un archivo.', $ensayo->codigo],
            ['Tipo', 'Sí', 'Tipo de actividad (lista): ' . implode(', ', $tipos) . '.', 'Muestreo'],
            ['Detalle', 'No', 'Qué se hará: tipo de muestra, evaluación, producto, dosis...', 'Muestreo foliar, 20 hojas TVD'],
            ['Fecha', 'Sí', 'Fecha programada (dd/mm/aaaa).', '15/01/2027'],
            ['Responsable', 'No', 'Correo o nombre de la persona (debe tener acceso a Experimentos).', 'tecnico@cengicana.org'],
            ['Avisar (días)', 'No', 'Días antes en que avisa la campanita (por defecto 3).', '3'],
            ['Repetir cada (días)', 'No', 'Para actividades periódicas: cada cuántos días se repite.', '30'],
            ['Veces', 'No', 'Cuántas veces en total (con Repetir cada).', '4'],
            ['Estado', 'No', 'Pendiente (por defecto), Realizada o Cancelada; útil para cargar el historial.', 'Pendiente'],
        ], null, 'A1');
        $ins->getStyle('A1')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB('1F5632');
        $ins->getStyle('A6:D6')->applyFromArray(['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F5632']]]);
        $ins->getStyle('C7:C15')->getAlignment()->setWrapText(true);
        foreach (['A' => 20, 'B' => 12, 'C' => 70, 'D' => 30] as $c => $w) {
            $ins->getColumnDimension($c)->setWidth($w);
        }
        $libro->setActiveSheetIndex(0);

        return $libro;
    }

    public function descargar(Spreadsheet $libro, string $nombre)
    {
        return response()->streamDownload(fn () => (new Xlsx($libro))->save('php://output'), $nombre,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * Lee el archivo y valida cada fila. Con $guardar crea las actividades validas.
     * Devuelve ['actividades' => [...], 'errores' => [['fila', 'motivo']], 'omitidas' => n, 'guardadas' => n].
     */
    public function importar(UploadedFile $archivo, Ensayo $ensayo, User $user, bool $guardar): array
    {
        $import = new PrimeraHojaImport();
        Excel::import($import, $archivo);
        $filas = ($import->hoja->filas ?? collect())->map(fn ($f) => $f->toArray());

        $norm = fn ($t) => Str::lower(trim(Str::ascii((string) $t)));
        $tipos = collect(BitacoraController::TIPOS)->keyBy(fn ($t) => $norm($t));
        $visibles = Ensayo::visiblesPara($user)->get(['id', 'codigo'])->keyBy(fn ($e) => $norm($e->codigo));
        $usuarios = User::get(['id', 'name', 'email']);
        $porCorreo = $usuarios->keyBy(fn ($u) => $norm($u->email));
        $porNombre = $usuarios->keyBy(fn ($u) => $norm($u->name));

        $actividades = $errores = [];
        $omitidas = 0;
        foreach ($filas as $i => $f) {
            $n = $i + 2; // fila en Excel (encabezado en la 1)
            if (! array_filter($f, fn ($v) => $v !== null && $v !== '')) {
                continue;
            }
            $codigo = $norm($f['ensayo'] ?? '');
            $destino = $codigo === '' ? $ensayo : $visibles->get($codigo);
            if (! $destino) {
                $errores[] = ['fila' => $n, 'motivo' => 'Ensayo "' . $f['ensayo'] . '" no existe o no tiene acceso.'];
                continue;
            }
            $tipo = $tipos->get($norm($f['tipo'] ?? ''));
            if (! $tipo) {
                $errores[] = ['fila' => $n, 'motivo' => 'Tipo "' . ($f['tipo'] ?? '') . '" no válido.'];
                continue;
            }
            $fecha = $this->fecha($f['fecha'] ?? null);
            if (! $fecha) {
                $errores[] = ['fila' => $n, 'motivo' => 'Fecha no válida o vacía.'];
                continue;
            }
            $responsable = null;
            if (trim((string) ($f['responsable'] ?? '')) !== '') {
                $responsable = $porCorreo->get($norm($f['responsable'])) ?? $porNombre->get($norm($f['responsable']));
                if (! $responsable) {
                    $errores[] = ['fila' => $n, 'motivo' => 'Responsable "' . $f['responsable'] . '" no encontrado.'];
                    continue;
                }
            }
            $estado = ucfirst($norm($f['estado'] ?? '') ?: 'pendiente');
            if (! in_array($estado, ['Pendiente', 'Realizada', 'Cancelada'], true)) {
                $errores[] = ['fila' => $n, 'motivo' => 'Estado "' . $f['estado'] . '" no válido.'];
                continue;
            }
            $cada = (int) ($f['repetir_cada_dias'] ?? 0);
            $veces = $cada > 0 ? max(1, min(52, (int) ($f['veces'] ?? 1))) : 1;
            $detalle = trim((string) ($f['detalle'] ?? '')) ?: null;
            $aviso = ($f['avisar_dias'] ?? null) === null || $f['avisar_dias'] === '' ? 3 : max(0, min(60, (int) $f['avisar_dias']));
            for ($k = 0; $k < $veces; $k++) {
                $dia = $fecha->copy()->addDays($k * $cada)->toDateString();
                $existe = ActividadPlan::where('ensayo_id', $destino->id)->where('tipo', $tipo)->whereDate('fecha', $dia)
                    ->where('descripcion', $detalle)->where('estado', 'Pendiente')->exists()
                    || collect($actividades)->contains(fn ($a) => $a['ensayo_id'] === $destino->id && $a['tipo'] === $tipo && $a['fecha'] === $dia && $a['descripcion'] === $detalle);
                if ($existe) {
                    $omitidas++;
                    continue;
                }
                $actividades[] = [
                    'fila' => $n, 'ensayo_id' => $destino->id, 'ensayo' => $destino->codigo, 'tipo' => $tipo, 'descripcion' => $detalle, 'fecha' => $dia,
                    'responsable_id' => $responsable ? $responsable->id : null, 'responsable' => $responsable ? $responsable->name : null,
                    'aviso_dias' => $aviso,
                    'estado' => $estado,
                ];
            }
        }

        $guardadas = 0;
        if ($guardar && $actividades) {
            DB::transaction(function () use ($actividades, $user, &$guardadas) {
                foreach ($actividades as $a) {
                    ActividadPlan::create([
                        'ensayo_id' => $a['ensayo_id'], 'tipo' => $a['tipo'], 'descripcion' => $a['descripcion'], 'fecha' => $a['fecha'],
                        'responsable_id' => $a['responsable_id'], 'aviso_dias' => $a['aviso_dias'], 'estado' => $a['estado'],
                        'fecha_realizada' => $a['estado'] === 'Realizada' ? $a['fecha'] : null, 'creado_por' => $user->id,
                    ]);
                    $guardadas++;
                }
            });
        }

        return ['actividades' => $actividades, 'errores' => $errores, 'omitidas' => $omitidas, 'guardadas' => $guardadas, 'leidas' => $filas->count()];
    }

    protected function fecha($v): ?Carbon
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $v))->startOfDay();
        }
        foreach (['d/m/Y', 'Y-m-d', 'd-m-Y', 'j/n/Y', 'd/m/y'] as $formato) {
            try {
                $c = Carbon::createFromFormat('!' . $formato, trim((string) $v));
                if ($c && $c->format($formato) === trim((string) $v)) {
                    return $c;
                }
            } catch (Throwable $e) {
                // probar el siguiente formato
            }
        }

        return null;
    }
}
