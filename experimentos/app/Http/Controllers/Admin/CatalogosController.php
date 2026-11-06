<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DisenoExperimental;
use App\Models\Ensayo;
use App\Models\Ingenio;
use App\Models\TipoMuestra;
use App\Models\User;
use App\Services\CatalogosPortal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Sistema > Catalogos: disenos experimentales (se administran aqui) e ingenios y responsables
 * (vienen del portal; aqui solo se consultan y se actualizan).
 */
class CatalogosController extends Controller
{
    public function index(CatalogosPortal $portal)
    {
        Gate::authorize('admin_only');
        $sync = $portal->sincronizarSiCorresponde(0);

        return view('component', [
            'title' => 'Catalogos',
            'component' => 'catalogos-index',
            'params' => [
                'disenos' => $this->disenos(),
                'tiposMuestra' => $this->tiposMuestra(),
                'tiposLaboratorio' => $this->tiposLaboratorio(),
                'formatosInforme' => array_keys(config('laboratorio.formatos', [])),
                'ingenios' => $this->ingenios(),
                'responsables' => $this->responsables(),
                'sincronizacion' => $sync,
                'portalIngeniosUrl' => '/login/ingenios/ingenios.php',
                'portalUsuariosUrl' => '/login/usuarios/usuarios.php',
            ],
        ]);
    }

    public function sincronizar(CatalogosPortal $portal)
    {
        Gate::authorize('admin_only');

        return response()->json([
            'sincronizacion' => $portal->sincronizar(),
            'ingenios' => $this->ingenios(),
            'responsables' => $this->responsables(),
        ]);
    }

    public function guardarDiseno(Request $request, ?DisenoExperimental $diseno = null)
    {
        Gate::authorize('admin_only');
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:150', Rule::unique('disenos_experimentales', 'nombre')->ignore($diseno && $diseno->exists ? $diseno->id : null)],
            'descripcion' => 'nullable|string|max:500',
            'usa_bloques' => 'boolean',
            'activo' => 'boolean',
        ], ['nombre.unique' => 'Ya existe un diseno con ese nombre.']);

        if ($diseno && $diseno->exists) {
            // El nombre se guarda en los ensayos: si cambia, se actualiza tambien en ellos.
            if ($data['nombre'] !== $diseno->nombre) {
                Ensayo::where('diseno', $diseno->nombre)->update(['diseno' => $data['nombre']]);
            }
            $diseno->update($data);
        } else {
            $diseno = DisenoExperimental::create($data + ['orden' => (int) DisenoExperimental::max('orden') + 1]);
        }

        return response()->json(['disenos' => $this->disenos()]);
    }

    public function eliminarDiseno(DisenoExperimental $diseno)
    {
        Gate::authorize('admin_only');
        $n = Ensayo::where('diseno', $diseno->nombre)->count();
        if ($n) {
            return response()->json(['message' => "Lo usan {$n} ensayo(s): no se puede eliminar. Desactivelo para que no se ofrezca en ensayos nuevos."], 422);
        }
        $diseno->delete();

        return response()->json(['disenos' => $this->disenos()]);
    }

    /* ======================= Tipos de muestra ======================= */

    public function guardarTipo(Request $request, ?TipoMuestra $tipo = null)
    {
        Gate::authorize('admin_only');
        $existe = $tipo && $tipo->exists;
        $request->merge(['codigo' => strtoupper(trim((string) $request->input('codigo')))]);
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:60', Rule::unique('tipos_muestra', 'nombre')->ignore($existe ? $tipo->id : null)],
            'codigo' => ['required', 'regex:/^[A-Z]{2,3}$/', Rule::unique('tipos_muestra', 'codigo')->ignore($existe ? $tipo->id : null)],
            'tipo_lab' => 'nullable|string|max:60',
            'formato_informe' => 'nullable|string|max:60',
            'icono' => 'nullable|string|max:40',
            'activo' => 'boolean',
        ], [
            'nombre.unique' => 'Ya existe un tipo con ese nombre.',
            'codigo.regex' => 'El codigo debe tener 2 o 3 letras (va en el ID de la etiqueta, p. ej. AG en COS-26-01-E01-AG025).',
            'codigo.unique' => 'Ese codigo ya lo usa otro tipo.',
        ]);

        DB::transaction(function () use (&$tipo, $existe, $data) {
            if ($existe) {
                // El tipo se guarda por nombre en muestras, analitos y campos: si cambia, se actualiza en todos.
                if ($data['nombre'] !== $tipo->nombre) {
                    DB::table('muestras_lab')->where('tipo', $tipo->nombre)->update(['tipo' => $data['nombre']]);
                    DB::table('analitos_por_tipo')->where('tipo_muestra', $tipo->nombre)->update(['tipo_muestra' => $data['nombre']]);
                    DB::table('campos_muestra')->where('tipo_muestra', $tipo->nombre)->update(['tipo_muestra' => $data['nombre']]);
                }
                $tipo->update($data);
            } else {
                $tipo = TipoMuestra::create($data + ['orden' => (int) TipoMuestra::max('orden') + 1]);
            }
        });

        return response()->json(['tiposMuestra' => $this->tiposMuestra()]);
    }

    public function eliminarTipo(TipoMuestra $tipo)
    {
        Gate::authorize('admin_only');
        $n = DB::table('muestras_lab')->where('tipo', $tipo->nombre)->count();
        if ($n) {
            return response()->json(['message' => "Hay {$n} muestra(s) de este tipo: no se puede eliminar. Desactivelo para que no se ofrezca al generar etiquetas."], 422);
        }
        DB::transaction(function () use ($tipo) {
            DB::table('analitos_por_tipo')->where('tipo_muestra', $tipo->nombre)->delete();
            DB::table('campos_muestra')->where('tipo_muestra', $tipo->nombre)->delete();
            $tipo->delete();
        });

        return response()->json(['tiposMuestra' => $this->tiposMuestra()]);
    }

    protected function tiposMuestra()
    {
        $muestras = DB::table('muestras_lab')->selectRaw('tipo, COUNT(*) n')->groupBy('tipo')->pluck('n', 'tipo');
        $analitos = DB::table('analitos_por_tipo')->selectRaw('tipo_muestra, COUNT(*) n')->groupBy('tipo_muestra')->pluck('n', 'tipo_muestra');

        return TipoMuestra::orderBy('orden')->orderBy('nombre')->get()
            ->map(fn ($t) => $t->toArray() + ['muestras' => (int) ($muestras[$t->nombre] ?? 0), 'analitos' => (int) ($analitos[$t->nombre] ?? 0)]);
    }

    /** Tipos de muestra del catalogo del Laboratorio (lectura directa; vacio si no hay conexion). */
    protected function tiposLaboratorio(): array
    {
        try {
            return DB::connection(\App\Services\LaboratorioModulo::CONEXION)->table('tipo_muestra')->orderBy('nombre')->pluck('nombre')->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function disenos()
    {
        $uso = Ensayo::whereNotNull('diseno')->selectRaw('diseno, COUNT(*) n')->groupBy('diseno')->pluck('n', 'diseno');

        return DisenoExperimental::orderBy('orden')->orderBy('nombre')->get()
            ->map(fn ($d) => $d->toArray() + ['ensayos' => (int) ($uso[$d->nombre] ?? 0)]);
    }

    protected function ingenios()
    {
        $uso = Ensayo::whereNotNull('ingenio_id')->selectRaw('ingenio_id, COUNT(*) n')->groupBy('ingenio_id')->pluck('n', 'ingenio_id');

        return Ingenio::orderBy('nombre')->get(['id', 'nombre'])->map(fn ($i) => ['id' => $i->id, 'nombre' => $i->nombre, 'ensayos' => (int) ($uso[$i->id] ?? 0)]);
    }

    protected function responsables()
    {
        $uso = \Illuminate\Support\Facades\DB::table('ensayo_responsables')->selectRaw('user_id, COUNT(*) n')->groupBy('user_id')->pluck('n', 'user_id');

        return User::with(['ingenio:id,nombre', 'roles:id,name'])->orderBy('name')->get()->map(fn ($u) => [
            'id' => $u->id, 'nombre' => $u->name, 'correo' => $u->email, 'ingenio' => optional($u->ingenio)->nombre,
            'rol' => $u->roles->pluck('name')->implode(', ') ?: null, 'ensayos' => (int) ($uso[$u->id] ?? 0),
        ]);
    }
}
