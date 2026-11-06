<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ensayo extends Model
{
    protected $fillable = [
        'codigo', 'nombre', 'objetivo', 'proyecto_id', 'ingenio_id', 'finca', 'lote', 'diseno',
        'cultivo', 'variedad', 'ciclo', 'fecha_siembra', 'fecha_cosecha_prevista', 'fecha_cosecha',
        'responsable_id', 'estado', 'lat', 'lng', 'estrato', 'zona', 'area_total', 'tipo_suelo',
        'num_tratamientos', 'num_repeticiones', 'area_parcela',
        // Pruebas tecnicas (tipo_estudio = 'prueba'): sin diseno ni cosecha.
        'tipo_estudio', 'subtipo', 'fecha_inicio', 'fecha_fin', 'lugar', 'materiales',
    ];

    /** Subtipos de prueba tecnica. */
    const SUBTIPOS_PRUEBA = ['Calibración de sensor o equipo', 'Prueba de laboratorio', 'Prueba de producto o material', 'Validación de método', 'Observación o diagnóstico'];

    /** Lugares de una prueba tecnica. */
    const LUGARES_PRUEBA = ['Laboratorio', 'Campo', 'Invernadero', 'Laboratorio y campo'];

    protected $casts = [
        'fecha_inicio' => 'date:Y-m-d',
        'fecha_fin' => 'date:Y-m-d',
        'fecha_siembra' => 'date:Y-m-d',
        'fecha_cosecha_prevista' => 'date:Y-m-d',
        'fecha_cosecha' => 'date:Y-m-d',
    ];

    /**
     * Los campos ciclo / fecha_siembra / fecha_cosecha(_prevista) del ensayo son su ciclo
     * ACTUAL: al guardarlos se reflejan en el ultimo ciclo de ensayo_ciclos (o lo crean).
     */
    protected static function booted()
    {
        static::saved(function (Ensayo $ensayo) {
            if (! $ensayo->fecha_siembra) {
                return;
            }
            $actual = $ensayo->ciclos()->reorder('orden', 'desc')->first() ?? new EnsayoCiclo(['ensayo_id' => $ensayo->id, 'orden' => 1]);
            $actual->fill([
                'ciclo' => $ensayo->ciclo,
                'fecha_inicio' => $ensayo->fecha_siembra,
                'fecha_cosecha_prevista' => $ensayo->fecha_cosecha_prevista,
                'fecha_cosecha' => $ensayo->fecha_cosecha,
            ]);
            if ($actual->isDirty()) {
                $actual->save();
            }
        });
    }

    /** Ciclos de cultivo evaluados (plantia, soca 1...), del primero al actual. */
    public function ciclos()
    {
        return $this->hasMany(EnsayoCiclo::class, 'ensayo_id', 'id')->orderBy('orden');
    }

    /** Evaluaciones registradas en las parcelas del ensayo. */
    public function evaluaciones()
    {
        return $this->hasManyThrough(Evaluacion::class, Parcela::class, 'ensayo_id', 'parcela_id', 'id', 'id');
    }

    /** Muestras generadas para el ensayo (laboratorio). */
    public function muestras()
    {
        return $this->hasMany(MuestraLab::class, 'ensayo_id', 'id');
    }

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class, 'proyecto_id', 'id');
    }

    public function ingenio()
    {
        return $this->belongsTo(Ingenio::class, 'ingenio_id', 'id');
    }

    /** Responsable principal (el primero de la lista de responsables). */
    public function responsable()
    {
        return $this->belongsTo(User::class, 'responsable_id', 'id');
    }

    /** Todos los responsables del ensayo; el principal primero (orden 0). */
    public function responsables()
    {
        return $this->belongsToMany(User::class, 'ensayo_responsables', 'ensayo_id', 'user_id')
            ->withPivot('orden')->withTimestamps()->orderBy('ensayo_responsables.orden');
    }

    /** Guarda la lista de responsables ([user_id, ...], el primero es el principal). */
    public function asignarResponsables(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));
        $this->responsables()->sync(collect($ids)->mapWithKeys(fn ($id, $i) => [$id => ['orden' => $i]])->all());
        $this->forceFill(['responsable_id' => $ids[0] ?? null])->save();
    }

    public function tratamientos()
    {
        return $this->hasMany(Tratamiento::class, 'ensayo_id', 'id');
    }

    public function parcelas()
    {
        return $this->hasMany(Parcela::class, 'ensayo_id', 'id');
    }

    public function bitacora()
    {
        return $this->hasMany(Bitacora::class, 'ensayo_id', 'id');
    }

    /** Tabla de datos (solo pruebas tecnicas). */
    public function datosPrueba()
    {
        return $this->hasOne(PruebaDatos::class, 'ensayo_id', 'id');
    }

    /** Actividades programadas (plan del ensayo), por fecha. */
    public function plan()
    {
        return $this->hasMany(ActividadPlan::class, 'ensayo_id', 'id')->orderBy('fecha')->orderBy('id');
    }

    public function archivos()
    {
        return $this->hasMany(Archivo::class, 'ensayo_id', 'id');
    }

    public function ndvi()
    {
        return $this->hasMany(NdviRegistro::class, 'ensayo_id', 'id');
    }

    public function ndviImagenes()
    {
        return $this->hasMany(NdviImagen::class, 'ensayo_id', 'id');
    }

    public function croquis()
    {
        return $this->hasOne(CroquisGeo::class, 'ensayo_id', 'id');
    }

    /**
     * Ensayos visibles para el usuario, portado de ensayosVisibles() del
     * prototipo: se acota primero por los proyectos visibles y, para el
     * rol "ingenio", ademas se filtra por su ingenio asignado.
     */
    public static function visiblesPara(User $user)
    {
        $proyectoIds = Proyecto::visiblesPara($user)->pluck('id');

        // Los ensayos de sus proyectos y, ademas, aquellos de los que es responsable.
        $query = static::where(fn ($q) => $q->whereIn('proyecto_id', $proyectoIds)
            ->orWhereHas('responsables', fn ($r) => $r->where('users.id', $user->id)));

        if ($user->hasRole('ingenio') && $user->ingenio_id) {
            $query->where('ingenio_id', $user->ingenio_id);
        }

        return $query;
    }
}
