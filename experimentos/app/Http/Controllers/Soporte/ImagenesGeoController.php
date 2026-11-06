<?php

namespace App\Http\Controllers\Soporte;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Investigacion\EnsayosController;
use App\Models\Ensayo;
use App\Models\ImagenGeo;
use App\Models\NdviImagen;
use App\Models\Parcela;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ImagenesGeoController extends Controller
{
    const TIPOS = ['NDVI', 'Multiespectral', 'RGB', 'Termica'];
    const EXTENSIONES = ['tif', 'tiff', 'kmz', 'png', 'jpg', 'jpeg', 'webp'];

    /** Forma para la pagina: fecha legible, quien la subio, URL y si ya tiene NDVI por parcela. */
    protected function fila(ImagenGeo $i, $procesadas = null): array
    {
        $user = Auth::user();
        $ndvi = $procesadas ? $procesadas->get($i->id) : NdviImagen::where('imagen_geo_id', $i->id)->first();
        return [
            'id' => $i->id, 'nombre' => $i->nombre, 'ensayo_id' => $i->ensayo_id, 'tipo' => $i->tipo,
            'fecha' => optional($i->fecha)->format('Y-m-d'), 'sensor' => $i->sensor, 'resolucion' => $i->resolucion,
            'bandas' => $i->bandas, 'notas' => $i->notas, 'width' => $i->width, 'height' => $i->height,
            'extension' => strtolower(pathinfo($i->path, PATHINFO_EXTENSION)), 'path' => $i->path,
            'tamano' => Storage::disk('public')->exists($i->path) ? Storage::disk('public')->size($i->path) : null,
            'url' => url('storage/' . $i->path), 'subido_por' => optional($i->subidoPor)->name,
            'ndvi' => $ndvi ? ['id' => $ndvi->id, 'fecha' => optional($ndvi->fecha)->format('Y-m-d')] : null,
            'puede_eliminar' => $user->can('admin_only') || ($user->can('upload_files') && $i->subido_por === $user->id),
        ];
    }

    public function index()
    {
        $user = Auth::user();
        $ensayos = Ensayo::visiblesPara($user)->with('proyecto:id,codigo')->orderBy('codigo')->get(['id', 'codigo', 'nombre', 'proyecto_id', 'finca']);
        // Parcelas con poligono: sin ellas el NDVI no se puede calcular por parcela.
        $geo = Parcela::whereIn('ensayo_id', $ensayos->pluck('id'))->selectRaw('ensayo_id, COUNT(*) total, SUM(geometria IS NOT NULL) con_poligono')->groupBy('ensayo_id')->get()->keyBy('ensayo_id');
        $imagenes = ImagenGeo::with('subidoPor:id,name')->whereIn('ensayo_id', $ensayos->pluck('id'))->orderByDesc('fecha')->orderByDesc('id')->get();
        $procesadas = NdviImagen::whereIn('imagen_geo_id', $imagenes->pluck('id'))->get()->keyBy('imagen_geo_id');

        return view('component', [
            'title' => 'Imagenes Geoespaciales',
            'component' => 'imagenes-geo-index',
            'params' => [
                'imagenes' => $imagenes->map(fn ($i) => $this->fila($i, $procesadas))->values(),
                'ensayos' => $ensayos->map(fn ($e) => [
                    'id' => $e->id, 'codigo' => $e->codigo, 'nombre' => $e->nombre, 'finca' => $e->finca, 'proyecto' => optional($e->proyecto)->codigo,
                    'parcelas' => (int) optional($geo->get($e->id))->total, 'con_poligono' => (int) optional($geo->get($e->id))->con_poligono,
                ])->values(),
                'tipos' => self::TIPOS,
                'fuentes' => EnsayosController::FUENTES_NDVI,
                'ensayosUrl' => route('investigacion.ensayos.index'),
                'puedeSubir' => $user->can('upload_files'),
            ],
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('upload_files');

        $data = $request->validate([
            'ensayo_id' => 'required|exists:ensayos,id',
            'tipo' => 'required|in:' . implode(',', self::TIPOS),
            'fecha' => 'required|date_format:Y-m-d',
            'sensor' => 'nullable|string|max:100',
            'resolucion' => 'nullable|string|max:50',
            'bandas' => 'nullable|string|max:50',
            'notas' => 'nullable|string|max:1000',
            'archivo' => 'required|file|max:51200',
        ]);
        $ensayo = Ensayo::visiblesPara(Auth::user())->whereKey($data['ensayo_id'])->firstOrFail();

        $file = $request->file('archivo');
        // Conserva la extension original (un KMZ se detectaria como .zip) solo si es de la lista segura.
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, self::EXTENSIONES, true)) {
            throw ValidationException::withMessages(['archivo' => ['Formato no admitido. Usa GeoTIFF (.tif), KMZ de dron, PNG, JPG o WEBP.']]);
        }
        $path = $file->storeAs('imagenes-geo', Str::random(40) . '.' . $ext, 'public');

        $dimensiones = @getimagesize($file->getRealPath());

        $imagen = ImagenGeo::create([
            'nombre' => $file->getClientOriginalName(),
            'ensayo_id' => $ensayo->id,
            'proyecto_id' => $ensayo->proyecto_id,
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

        return response()->json($this->fila($imagen->load('subidoPor:id,name')), 201);
    }

    public function destroy(ImagenGeo $imagen)
    {
        $user = Auth::user();
        abort_unless($user->can('admin_only') || ($user->can('upload_files') && $imagen->subido_por === $user->id), 403);

        Storage::disk('public')->delete($imagen->path);
        $imagen->delete();

        return response()->json(['ok' => true]);
    }
}
