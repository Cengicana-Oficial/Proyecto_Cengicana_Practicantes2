<?php

namespace App\Http\Controllers\Soporte;

use App\Http\Controllers\Controller;
use App\Models\Archivo;
use App\Models\Ensayo;
use App\Models\Proyecto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ArchivosController extends Controller
{
    /**
     * Mapa de extension -> tipo, portado de los tipos usados en el
     * prototipo SIGEC_v12.html (PDF/Imagen/Excel/Word/ZIP/Otro).
     */
    protected function tipoPorExtension(string $ext): string
    {
        $ext = strtolower($ext);

        if ($ext === 'pdf') return 'PDF';
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) return 'Imagen';
        if (in_array($ext, ['xls', 'xlsx', 'csv'])) return 'Excel';
        if (in_array($ext, ['doc', 'docx'])) return 'Word';
        if (in_array($ext, ['zip', 'rar', '7z'])) return 'ZIP';

        return 'Otro';
    }

    public function index()
    {
        $user = Auth::user();
        $proyectoIds = Proyecto::visiblesPara($user)->pluck('id');
        $ensayoIds = Ensayo::visiblesPara($user)->pluck('id');

        $archivos = Archivo::with(['proyecto', 'ensayo', 'subidoPor'])
            ->where(function ($q) use ($proyectoIds, $ensayoIds) {
                $q->whereIn('proyecto_id', $proyectoIds)->orWhereIn('ensayo_id', $ensayoIds);
            })
            ->orderByDesc('fecha')
            ->get();

        return view('component', [
            'title' => 'Archivos',
            'component' => 'archivos-index',
            'params' => [
                'archivos' => $archivos,
                'proyectos' => Proyecto::visiblesPara($user)->orderBy('codigo')->get(['id', 'codigo', 'nombre']),
                'ensayos' => Ensayo::visiblesPara($user)->orderBy('codigo')->get(['id', 'codigo', 'proyecto_id']),
                'puedeSubir' => $user->can('upload_files'),
                'puedeEliminar' => $user->can('admin_only'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('upload_files');

        $data = $request->validate([
            'proyecto_id' => 'required|exists:proyectos,id',
            'ensayo_id' => 'nullable|exists:ensayos,id',
            'carpeta' => 'nullable|string|max:100',
            'archivo' => 'required|file|max:20480',
        ]);

        $file = $request->file('archivo');
        $path = $file->store('archivos', 'public');

        $archivo = Archivo::create([
            'nombre' => $file->getClientOriginalName(),
            'proyecto_id' => $data['proyecto_id'],
            'ensayo_id' => $data['ensayo_id'] ?? null,
            'carpeta' => $data['carpeta'] ?? null,
            'tipo' => $this->tipoPorExtension($file->getClientOriginalExtension()),
            'tamano' => $file->getSize(),
            'fecha' => now(),
            'subido_por' => Auth::id(),
            'path' => $path,
        ]);

        return response()->json($archivo->load(['proyecto', 'ensayo', 'subidoPor']), 201);
    }

    public function destroy(Archivo $archivo)
    {
        Gate::authorize('admin_only');

        \Illuminate\Support\Facades\Storage::disk('public')->delete($archivo->path);
        $archivo->delete();

        return response()->json(['ok' => true]);
    }
}
