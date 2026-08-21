<?php

namespace App\Http\Controllers\Soporte;

use App\Http\Controllers\Controller;
use App\Models\Ensayo;
use App\Models\ImagenGeo;
use App\Models\Proyecto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class ImagenesGeoController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $ensayoIds = Ensayo::visiblesPara($user)->pluck('id');

        $imagenes = ImagenGeo::with(['ensayo', 'proyecto', 'subidoPor'])
            ->whereIn('ensayo_id', $ensayoIds)
            ->orderByDesc('fecha')
            ->get();

        return view('component', [
            'title' => 'Imagenes Geoespaciales',
            'component' => 'imagenes-geo-index',
            'params' => [
                'imagenes' => $imagenes,
                'proyectos' => Proyecto::visiblesPara($user)->orderBy('codigo')->get(['id', 'codigo']),
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
            'ensayo_id' => 'required|exists:ensayos,id',
            'proyecto_id' => 'nullable|exists:proyectos,id',
            'tipo' => 'required|in:NDVI,RGB,Termica,Multiespectral',
            'fecha' => 'required|date',
            'sensor' => 'nullable|string|max:100',
            'resolucion' => 'nullable|string|max:50',
            'bandas' => 'nullable|string|max:50',
            'notas' => 'nullable|string|max:1000',
            'archivo' => 'required|file|max:51200',
        ]);

        $file = $request->file('archivo');
        $path = $file->store('imagenes-geo', 'public');

        $dimensiones = @getimagesize($file->getRealPath());

        $imagen = ImagenGeo::create([
            'nombre' => $file->getClientOriginalName(),
            'ensayo_id' => $data['ensayo_id'],
            'proyecto_id' => $data['proyecto_id'] ?? null,
            'fecha' => $data['fecha'],
            'tipo' => $data['tipo'],
            'sensor' => $data['sensor'] ?? null,
            'resolucion' => $data['resolucion'] ?? null,
            'bandas' => $data['bandas'] ?? null,
            'width' => $dimensiones[0] ?? null,
            'height' => $dimensiones[1] ?? null,
            'subido_por' => Auth::id(),
            'notas' => $data['notas'] ?? null,
            'path' => $path,
        ]);

        return response()->json($imagen->load(['ensayo', 'proyecto', 'subidoPor']), 201);
    }

    public function destroy(ImagenGeo $imagen)
    {
        Gate::authorize('admin_only');

        Storage::disk('public')->delete($imagen->path);
        $imagen->delete();

        return response()->json(['ok' => true]);
    }
}
