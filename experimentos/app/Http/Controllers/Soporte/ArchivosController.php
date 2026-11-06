<?php

namespace App\Http\Controllers\Soporte;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Investigacion\EnsayosController;
use App\Models\Archivo;
use App\Models\Ensayo;
use App\Models\Proyecto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

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

    /** Forma comun para la lista: fecha legible, nombres en lugar de relaciones y URL publica. */
    protected function fila(Archivo $a): array
    {
        $user = Auth::user();
        return [
            'id' => $a->id, 'nombre' => $a->nombre, 'carpeta' => $a->carpeta ?: 'Otro', 'tipo' => $a->tipo, 'tamano' => (int) $a->tamano,
            'fecha' => optional($a->fecha)->format('Y-m-d'), 'creado' => optional($a->created_at)->format('Y-m-d H:i'),
            'proyecto_id' => $a->proyecto_id, 'ensayo_id' => $a->ensayo_id,
            'subido_por' => optional($a->subidoPor)->name, 'subido_por_id' => $a->subido_por,
            'url' => url('storage/' . $a->path),
            'puede_editar' => $user->can('admin_only') || ($user->can('upload_files') && $a->subido_por === $user->id),
        ];
    }

    public function index()
    {
        $user = Auth::user();
        $proyectos = Proyecto::visiblesPara($user)->with('programa:id,sigla,color')->orderBy('codigo')->get(['id', 'codigo', 'nombre', 'programa_id']);
        $ensayos = Ensayo::visiblesPara($user)->orderBy('codigo')->get(['id', 'codigo', 'nombre', 'proyecto_id']);

        $archivos = Archivo::with('subidoPor:id,name')
            ->where(function ($q) use ($proyectos, $ensayos) {
                $q->whereIn('proyecto_id', $proyectos->pluck('id'))->orWhereIn('ensayo_id', $ensayos->pluck('id'));
            })
            ->orderByDesc('fecha')->orderByDesc('id')
            ->get();

        return view('component', [
            'title' => 'Archivos',
            'component' => 'archivos-index',
            'params' => [
                'archivos' => $archivos->map(fn ($a) => $this->fila($a))->values(),
                'proyectos' => $proyectos->map(fn ($p) => ['id' => $p->id, 'codigo' => $p->codigo, 'nombre' => $p->nombre, 'programa' => optional($p->programa)->sigla, 'color' => optional($p->programa)->color])->values(),
                'ensayos' => $ensayos,
                'carpetas' => EnsayosController::CARPETAS,
                'ensayosUrl' => route('investigacion.ensayos.index'),
                'puedeSubir' => $user->can('upload_files'),
                'esAdmin' => $user->can('admin_only'),
            ],
        ]);
    }

    /** Proyecto y ensayo deben ser visibles para el usuario y el ensayo debe ser del proyecto. */
    protected function destino(array $data): void
    {
        $user = Auth::user();
        if (! Proyecto::visiblesPara($user)->whereKey($data['proyecto_id'])->exists()) {
            throw ValidationException::withMessages(['proyecto_id' => ['No tienes acceso a este proyecto.']]);
        }
        if (! empty($data['ensayo_id']) && ! Ensayo::visiblesPara($user)->whereKey($data['ensayo_id'])->where('proyecto_id', $data['proyecto_id'])->exists()) {
            throw ValidationException::withMessages(['ensayo_id' => ['El ensayo no pertenece al proyecto elegido.']]);
        }
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
        $this->destino($data);

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

        // El detalle del ensayo usa la forma del modelo; la pagina de Archivos, la de fila().
        if ($request->boolean('fila')) return response()->json($this->fila($archivo->load('subidoPor:id,name')), 201);
        return response()->json($archivo->load(['proyecto', 'ensayo', 'subidoPor']), 201);
    }

    /** Renombrar o mover (carpeta, proyecto, ensayo): administradores o quien lo subio. */
    public function update(Request $request, Archivo $archivo)
    {
        $user = Auth::user();
        abort_unless($user->can('admin_only') || ($user->can('upload_files') && $archivo->subido_por === $user->id), 403);
        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'proyecto_id' => 'required|exists:proyectos,id',
            'ensayo_id' => 'nullable|exists:ensayos,id',
            'carpeta' => 'nullable|string|max:100',
        ]);
        $this->destino($data);
        // Conserva la extension original para que la descarga siga abriendo con el programa correcto.
        $ext = pathinfo($archivo->nombre, PATHINFO_EXTENSION);
        if ($ext && strcasecmp(pathinfo($data['nombre'], PATHINFO_EXTENSION), $ext) !== 0) $data['nombre'] .= '.' . $ext;
        $archivo->update($data);

        return response()->json($this->fila($archivo->load('subidoPor:id,name')));
    }

    public function destroy(Archivo $archivo)
    {
        $user = Auth::user();
        abort_unless($user->can('admin_only') || ($user->can('upload_files') && $archivo->subido_por === $user->id), 403);

        Storage::disk('public')->delete($archivo->path);
        $archivo->delete();

        return response()->json(['ok' => true]);
    }
}
