<?php

namespace App\Services;

use App\Models\ActividadPlan;
use App\Models\Asignacion;
use App\Models\Ensayo;
use App\Models\MuestraLab;
use App\Models\Respuesta;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Campanita de notificaciones. No hay tabla de avisos: cada aviso se calcula del estado real
 * (formularios asignados, registros de campo, resultados de laboratorio, cosechas, usuarios)
 * y solo se muestra a quien puede actuar sobre el. "Leido" es la fecha en que la persona abrio
 * la campanita por ultima vez: los avisos con fecha posterior cuentan como nuevos.
 */
class Notificaciones
{
    const DIAS_POR_VENCER = 3;
    const DIAS_LABORATORIO = 30;
    const DIAS_COSECHA = 15;

    /** @return array{items: array, no_leidas: int} */
    public function para(User $user): array
    {
        $items = Cache::remember("notificaciones.{$user->id}", 120, function () use ($user) {
            $hoy = Carbon::today();
            $ensayoIds = Ensayo::visiblesPara($user)->pluck('id');
            $codigos = Ensayo::whereIn('id', $ensayoIds)->pluck('codigo', 'id');
            $items = array_merge(
                $this->misFormularios($user, $hoy),
                $this->actividadesPlan($user, $ensayoIds, $codigos, $hoy),
                $user->can('register_evaluacion') ? $this->registrosPorRevisar($ensayoIds, $codigos) : [],
                $user->can('manage_formularios') ? $this->formulariosVencidos($user, $ensayoIds, $codigos, $hoy) : [],
                $user->can('view_lab') ? $this->resultadosLaboratorio($ensayoIds, $codigos, $hoy) : [],
                $user->can('edit_ensayo') ? $this->cosechas($ensayoIds, $hoy) : [],
                $user->can('admin_only') ? $this->usuariosSinRol() : []
            );
            usort($items, fn ($a, $b) => strcmp($b['fecha'], $a['fecha']));

            return array_slice($items, 0, 40);
        });

        $visto = Cache::get("notificaciones.visto.{$user->id}");
        foreach ($items as &$item) {
            $item['nueva'] = ! $visto || $item['fecha'] > $visto;
        }
        unset($item);

        return ['items' => $items, 'no_leidas' => count(array_filter($items, fn ($i) => $i['nueva']))];
    }

    public function marcarVistas(User $user): void
    {
        Cache::forever("notificaciones.visto.{$user->id}", now()->toDateTimeString());
    }

    protected function aviso(string $clave, string $tono, string $icono, string $titulo, string $texto, string $url, $fecha): array
    {
        return ['id' => $clave, 'tono' => $tono, 'icono' => $icono, 'titulo' => $titulo, 'texto' => $texto, 'url' => $url,
            'fecha' => Carbon::parse($fecha)->toDateTimeString()];
    }

    /** Formularios asignados a la persona: nuevos, por vencer o vencidos sin llenar. */
    protected function misFormularios(User $user, Carbon $hoy): array
    {
        $url = route('soporte.formularios.index');
        $items = [];
        $asignaciones = Asignacion::with('formulario:id,nombre')->withCount('respuestas')
            ->where('usuario_id', $user->id)->where('estado', 'Activo')->get();
        foreach ($asignaciones as $a) {
            $nombre = optional($a->formulario)->nombre ?? 'Formulario';
            if (! $a->respuestas_count && $a->fecha_fin && $a->fecha_fin->lt($hoy)) {
                $items[] = $this->aviso("asig-vencida-{$a->id}", 'alerta', 'fa-exclamation-circle', 'Formulario vencido sin llenar',
                    "{$nombre} venció el {$a->fecha_fin->format('d/m/Y')}.", $url, $a->fecha_fin);
            } elseif (! $a->respuestas_count && $a->fecha_fin && $a->fecha_fin->lte($hoy->copy()->addDays(self::DIAS_POR_VENCER))) {
                $dias = $hoy->diffInDays($a->fecha_fin);
                $items[] = $this->aviso("asig-vence-{$a->id}", 'aviso', 'fa-hourglass-half', 'Formulario por vencer',
                    "{$nombre} vence " . ($dias ? "en {$dias} día(s)" : 'hoy') . '.', $url, $hoy->copy()->subDays(self::DIAS_POR_VENCER - $dias));
            } elseif ($a->created_at && $a->created_at->gte($hoy->copy()->subDays(7))) {
                $items[] = $this->aviso("asig-nueva-{$a->id}", 'info', 'fa-clipboard-list', 'Nuevo formulario asignado',
                    $nombre . ($a->fecha_fin ? " · hasta el {$a->fecha_fin->format('d/m/Y')}" : '') . '.', $url, $a->created_at);
            }
        }

        return $items;
    }

    /** Enlace que abre el ensayo (y una pestana) en la lista de ensayos. */
    protected function urlEnsayo(int $id, ?string $tab = null): string
    {
        return route('investigacion.ensayos.index', array_filter(['ensayo' => $id, 'tab' => $tab]));
    }

    /**
     * Actividades del plan de los ensayos: al responsable, desde aviso_dias antes de la fecha y
     * cuando se atrasan; las que no tienen responsable y las atrasadas de otros, a los encargados.
     */
    protected function actividadesPlan(User $user, $ensayoIds, $codigos, Carbon $hoy): array
    {
        $encargado = $user->can('edit_ensayo');

        return ActividadPlan::with('responsable:id,name')->whereIn('ensayo_id', $ensayoIds)->where('estado', 'Pendiente')
            ->where('fecha', '<=', $hoy->copy()->addDays(60))->get()
            ->map(function ($a) use ($user, $encargado, $codigos, $hoy) {
                $mia = (int) $a->responsable_id === $user->id;
                $atrasada = $a->fecha->lt($hoy);
                $avisoDesde = $a->fecha->copy()->subDays($a->aviso_dias);
                if (! $atrasada && $hoy->lt($avisoDesde)) {
                    return null; // aun no toca avisar
                }
                if (! $mia && ! ($encargado && (! $a->responsable_id || $atrasada))) {
                    return null;
                }
                $que = $a->tipo . ($a->descripcion ? ": {$a->descripcion}" : '');
                $ensayo = $codigos[$a->ensayo_id] ?? 'ensayo';
                $quien = ! $mia ? ' · ' . (optional($a->responsable)->name ?? 'sin responsable') : '';
                $dias = $hoy->diffInDays($a->fecha);
                if ($atrasada) {
                    return $this->aviso("plan-{$a->id}-atrasada", 'alerta', 'fa-calendar-times', 'Actividad atrasada',
                        "{$que} ({$ensayo}) estaba programada para el {$a->fecha->format('d/m/Y')}, hace {$dias} día(s){$quien}.",
                        $this->urlEnsayo($a->ensayo_id, 'plan'), $a->fecha->copy()->addDay()); // nueva desde que se atraso
                }

                return $this->aviso("plan-{$a->id}", $dias === 0 ? 'aviso' : 'info', 'fa-calendar-check', $dias === 0 ? 'Actividad para hoy' : 'Actividad próxima',
                    "{$que} ({$ensayo}) " . ($dias === 0 ? 'hoy' : "en {$dias} día(s), el {$a->fecha->format('d/m/Y')}") . "{$quien}.",
                    $this->urlEnsayo($a->ensayo_id, 'plan'), $avisoDesde->max($a->created_at ?? $avisoDesde));
            })->filter()->values()->all();
    }

    /** Registros de campo de los muestreadores que aun no se pasan a evaluaciones. */
    protected function registrosPorRevisar($ensayoIds, $codigos): array
    {
        $url = route('soporte.formularios.index');

        return Respuesta::whereIn('ensayo_id', $ensayoIds)->where('promovido', false)
            ->selectRaw('ensayo_id, COUNT(*) AS n, MAX(created_at) AS ultima')->groupBy('ensayo_id')->get()
            ->map(fn ($r) => $this->aviso("resp-{$r->ensayo_id}", 'info', 'fa-tasks', 'Registros de campo por revisar',
                "{$r->n} registro(s) del ensayo " . ($codigos[$r->ensayo_id] ?? $r->ensayo_id) . ' por pasar a evaluaciones.', $url, $r->ultima ?? now()))
            ->all();
    }

    /** Formularios asignados a otras personas que vencieron sin respuestas. */
    protected function formulariosVencidos(User $user, $ensayoIds, $codigos, Carbon $hoy): array
    {
        $url = route('soporte.formularios.index');

        return Asignacion::with(['formulario:id,nombre', 'usuario:id,name'])->withCount('respuestas')
            ->whereIn('ensayo_id', $ensayoIds)->where('estado', 'Activo')->where('usuario_id', '<>', $user->id)
            ->whereNotNull('fecha_fin')->where('fecha_fin', '<', $hoy)->get()
            ->filter(fn ($a) => ! $a->respuestas_count)
            ->map(fn ($a) => $this->aviso("asig-otro-{$a->id}", 'aviso', 'fa-user-clock', 'Formulario sin llenar',
                (optional($a->usuario)->name ?? 'Un responsable') . ' no llenó ' . (optional($a->formulario)->nombre ?? 'el formulario')
                . ' (' . ($codigos[$a->ensayo_id] ?? 'ensayo') . "), venció el {$a->fecha_fin->format('d/m/Y')}.", $url, $a->fecha_fin))
            ->values()->all();
    }

    /** Informes firmados por el Laboratorio en los ultimos dias, agrupados por ensayo. */
    protected function resultadosLaboratorio($ensayoIds, $codigos, Carbon $hoy): array
    {
        $url = route('soporte.muestras-consulta.index');

        return MuestraLab::whereIn('ensayo_id', $ensayoIds)->whereNotNull('lab_firmado_en')
            ->where('lab_firmado_en', '>=', $hoy->copy()->subDays(self::DIAS_LABORATORIO))
            ->selectRaw('ensayo_id, COUNT(*) AS n, MAX(lab_firmado_en) AS ultima')->groupBy('ensayo_id')->get()
            ->map(fn ($m) => $this->aviso("lab-{$m->ensayo_id}-" . Carbon::parse($m->ultima)->format('YmdHi'), 'bien', 'fa-flask',
                'Resultados de laboratorio', "Informe firmado de {$m->n} muestra(s) del ensayo " . ($codigos[$m->ensayo_id] ?? $m->ensayo_id) . '.', $url, $m->ultima))
            ->all();
    }

    /** Cosechas previstas en los proximos dias o atrasadas. */
    protected function cosechas($ensayoIds, Carbon $hoy): array
    {
        return Ensayo::whereIn('id', $ensayoIds)->whereNull('fecha_cosecha')->whereNotNull('fecha_cosecha_prevista')
            ->where('fecha_cosecha_prevista', '<=', $hoy->copy()->addDays(self::DIAS_COSECHA))->get(['id', 'codigo', 'fecha_cosecha_prevista'])
            ->map(function ($e) use ($hoy) {
                $atrasada = $e->fecha_cosecha_prevista->lt($hoy);
                $dias = $hoy->diffInDays($e->fecha_cosecha_prevista);

                return $this->aviso("cosecha-{$e->id}", $atrasada ? 'alerta' : 'aviso', 'fa-seedling', $atrasada ? 'Cosecha atrasada' : 'Cosecha próxima',
                    "Ensayo {$e->codigo}: cosecha prevista el {$e->fecha_cosecha_prevista->format('d/m/Y')}"
                    . ($atrasada ? " (hace {$dias} día(s)), aún sin registrar." : ($dias ? " (en {$dias} día(s))." : ' (hoy).')),
                    $this->urlEnsayo($e->id), $atrasada ? $e->fecha_cosecha_prevista : $hoy->copy()->subDays(self::DIAS_COSECHA - $dias));
            })->all();
    }

    /** Personas con acceso a Experimentos en el portal pero sin un rol del modulo. */
    protected function usuariosSinRol(): array
    {
        try {
            $sinRol = collect(app(RolesPortal::class)->usuarios()['conAcceso'])->filter(fn ($u) => ! $u['superadmin'] && ! $u['rol_sigec']);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
        if ($sinRol->isEmpty()) {
            return [];
        }

        return [$this->aviso('usuarios-sin-rol-' . $sinRol->count(), 'aviso', 'fa-user-tag', 'Usuarios sin rol',
            $sinRol->count() . ' persona(s) con acceso a Experimentos sin rol asignado: ' . $sinRol->take(3)->pluck('nombre')->implode(', ')
            . ($sinRol->count() > 3 ? '…' : '') . '.', route('admin.usuarios.index'), now()->startOfDay())];
    }
}
