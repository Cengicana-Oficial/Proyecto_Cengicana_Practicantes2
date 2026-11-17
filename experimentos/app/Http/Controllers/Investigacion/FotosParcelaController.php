<?php

namespace App\Http\Controllers\Investigacion;

use App\Http\Controllers\Controller;
use App\Models\Asignacion;
use App\Models\Ensayo;
use App\Models\Formulario;
use App\Models\FotoParcela;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Registro fotografico por parcela y fecha. Lo usan el detalle del ensayo (pestaña Fotos),
 * la captura de formularios con fotos activadas y el Informe tecnico.
 */
class FotosParcelaController extends Controller
{
    /** Muestreador con una asignacion activa del ensayo: puede ver y subir fotos aunque no vea el ensayo. */
    protected function asignado(Ensayo $ensayo): bool
    {
        return Asignacion::where('ensayo_id', $ensayo->id)->where('usuario_id', Auth::id())->where('estado', 'Activo')->exists();
    }

    protected function puedeVer(Ensayo $ensayo): bool
    {
        return Ensayo::visiblesPara(Auth::user())->whereKey($ensayo->id)->exists() || $this->asignado($ensayo);
    }

    protected function puedeSubir(Ensayo $ensayo): bool
    {
        $user = Auth::user();
        $visible = Ensayo::visiblesPara($user)->whereKey($ensayo->id)->exists();
        return ($visible && ($user->can('edit_ensayo') || $user->can('register_evaluacion') || $user->can('upload_files'))) || $this->asignado($ensayo);
    }

    protected function puedeEditar(FotoParcela $f): bool
    {
        $user = Auth::user();
        return $f->subido_por === $user->id || $user->can('admin_only')
            || ($user->can('edit_ensayo') && Ensayo::visiblesPara($user)->whereKey($f->ensayo_id)->exists());
    }

    public static function dto(FotoParcela $f, ?bool $editable = null): array
    {
        return [
            'id' => $f->id, 'parcela_id' => $f->parcela_id, 'fecha' => optional($f->fecha)->format('Y-m-d'),
            'formulario_id' => $f->formulario_id, 'nombre' => $f->nombre, 'nota' => $f->nota,
            'url' => url('storage/' . $f->path), 'miniatura' => url('storage/' . ($f->miniatura ?: $f->path)),
            'ancho' => $f->ancho, 'alto' => $f->alto, 'subido_por' => optional($f->subidoPor)->name,
            'creado' => optional($f->created_at)->format('Y-m-d H:i'), 'editable' => (bool) $editable,
        ];
    }

    public function index(Request $request, Ensayo $ensayo)
    {
        abort_unless($this->puedeVer($ensayo), 403);
        $fecha = $request->validate(['fecha' => 'nullable|date_format:Y-m-d'])['fecha'] ?? null;
        $fotos = FotoParcela::with('subidoPor:id,name')->where('ensayo_id', $ensayo->id)
            ->when($fecha, fn ($q) => $q->whereDate('fecha', $fecha))
            ->orderBy('fecha')->orderBy('parcela_id')->orderBy('id')->get();

        return response()->json([
            'fotos' => $fotos->map(fn ($f) => self::dto($f, $this->puedeEditar($f)))->values(),
            'puede_subir' => $this->puedeSubir($ensayo),
        ]);
    }

    public function store(Request $request, Ensayo $ensayo)
    {
        abort_unless($this->puedeSubir($ensayo), 403);
        $data = $request->validate([
            'parcela_id' => 'required|integer',
            'fecha' => 'required|date_format:Y-m-d|before_or_equal:today',
            'formulario_id' => 'nullable|integer|exists:formularios,id',
            'nota' => 'nullable|string|max:500',
            'nombre' => 'nullable|string|max:255',
            'imagen' => 'required|file|mimes:jpg,jpeg,png,webp|max:12288',
            'miniatura' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:1024',
        ], ['fecha.before_or_equal' => 'La fecha de la foto no puede ser futura.']);
        $parcela = $ensayo->parcelas()->whereKey($data['parcela_id'])->firstOrFail();
        if (! empty($data['formulario_id'])) {
            abort_unless(Formulario::whereKey($data['formulario_id'])->where('ensayo_id', $ensayo->id)->exists(), 422, 'El formulario no es de este ensayo.');
        }

        // Nombre aleatorio con extension conocida (la imagen ya llega reducida desde el navegador).
        $carpeta = "fotos-parcela/{$ensayo->id}";
        $base = Str::random(32);
        $ext = strtolower($request->file('imagen')->getClientOriginalExtension()) ?: 'jpg';
        $ext = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) ? $ext : 'jpg';
        $path = $request->file('imagen')->storeAs($carpeta, "{$base}.{$ext}", 'public');
        $mini = $request->hasFile('miniatura') ? $request->file('miniatura')->storeAs($carpeta, "{$base}_min.jpg", 'public') : null;
        $dim = @getimagesize($request->file('imagen')->getRealPath());

        $foto = FotoParcela::create([
            'ensayo_id' => $ensayo->id, 'parcela_id' => $parcela->id, 'fecha' => $data['fecha'],
            'formulario_id' => $data['formulario_id'] ?? null, 'nota' => $data['nota'] ?? null,
            'nombre' => $data['nombre'] ?? $request->file('imagen')->getClientOriginalName(),
            'path' => $path, 'miniatura' => $mini, 'ancho' => $dim[0] ?? null, 'alto' => $dim[1] ?? null,
            'subido_por' => Auth::id(),
        ]);

        return response()->json(self::dto($foto->load('subidoPor:id,name'), true), 201);
    }

    /** Corregir la nota, la fecha o la parcela de una foto. */
    public function update(Request $request, FotoParcela $foto)
    {
        abort_unless($this->puedeEditar($foto), 403);
        $data = $request->validate([
            'nota' => 'nullable|string|max:500',
            'fecha' => 'required|date_format:Y-m-d|before_or_equal:today',
            'parcela_id' => 'required|integer',
        ]);
        abort_unless($foto->ensayo->parcelas()->whereKey($data['parcela_id'])->exists(), 422, 'La parcela no es de este ensayo.');
        $foto->update($data);

        return response()->json(self::dto($foto->load('subidoPor:id,name'), true));
    }

    public function destroy(FotoParcela $foto)
    {
        abort_unless($this->puedeEditar($foto), 403);
        Storage::disk('public')->delete(array_filter([$foto->path, $foto->miniatura]));
        $foto->delete();

        return response()->json(['ok' => true]);
    }
}
