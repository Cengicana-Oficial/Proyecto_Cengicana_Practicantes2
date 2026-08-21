<?php

namespace App\Http\Controllers\Analisis;

use App\Http\Controllers\Controller;
use App\Imports\FilasImport;
use App\Models\Ensayo;
use App\Models\Evaluacion;
use App\Models\Parcela;
use App\Models\Variable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;

class AnalisisController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        return view('component', [
            'title' => 'Importar y Analizar',
            'component' => 'analisis-index',
            'params' => [
                'ensayos' => Ensayo::visiblesPara($user)->orderBy('codigo')->get(['id', 'codigo']),
            ],
        ]);
    }

    /**
     * Importa un Excel/CSV con columnas Fecha/Variable/Parcela/Valor/
     * Observaciones y crea Evaluaciones reales, emparejando "Variable" por
     * nombre y "Parcela" por codigo (dentro del ensayo elegido). Filas que
     * no matchean se reportan como omitidas en vez de fallar todo el lote.
     */
    public function importar(Request $request)
    {
        Gate::authorize('run_analysis');

        $data = $request->validate([
            'ensayo_id' => 'required|exists:ensayos,id',
            'archivo' => 'required|file|mimes:xlsx,xls,csv,txt|max:5120',
        ]);

        $visibles = Ensayo::visiblesPara(Auth::user())->pluck('id');
        abort_unless($visibles->contains((int) $data['ensayo_id']), 403);

        $import = new FilasImport();
        Excel::import($import, $request->file('archivo'));

        $variables = Variable::pluck('id', 'nombre')->mapWithKeys(fn ($id, $nombre) => [mb_strtolower(trim($nombre)) => $id]);
        $parcelas = Parcela::where('ensayo_id', $data['ensayo_id'])->pluck('id', 'codigo')->mapWithKeys(fn ($id, $codigo) => [mb_strtolower(trim($codigo)) => $id]);

        $creadas = 0;
        $omitidas = [];

        foreach ($import->filas as $i => $fila) {
            $numeroFila = $i + 2; // +1 por indice base 0, +1 por la fila de encabezado
            $varNombre = mb_strtolower(trim((string) ($fila['variable'] ?? '')));
            $parcCodigo = mb_strtolower(trim((string) ($fila['parcela'] ?? '')));
            $valor = $fila['valor'] ?? null;
            $fecha = $fila['fecha'] ?? null;

            if (! $fecha || $valor === null || $valor === '') {
                $omitidas[] = ['fila' => $numeroFila, 'motivo' => 'Fecha o Valor vacio'];
                continue;
            }

            if (! isset($variables[$varNombre])) {
                $omitidas[] = ['fila' => $numeroFila, 'motivo' => "Variable \"{$fila['variable']}\" no encontrada"];
                continue;
            }

            if (! isset($parcelas[$parcCodigo])) {
                $omitidas[] = ['fila' => $numeroFila, 'motivo' => "Parcela \"{$fila['parcela']}\" no encontrada en este ensayo"];
                continue;
            }

            Evaluacion::create([
                'fecha' => $fecha,
                'variable_id' => $variables[$varNombre],
                'parcela_id' => $parcelas[$parcCodigo],
                'valor' => $valor,
                'obs' => $fila['observaciones'] ?? null,
                'responsable_id' => Auth::id(),
            ]);
            $creadas++;
        }

        return response()->json([
            'creadas' => $creadas,
            'omitidas' => $omitidas,
            'total' => $import->filas->count(),
        ]);
    }
}
