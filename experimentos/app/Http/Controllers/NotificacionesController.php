<?php

namespace App\Http\Controllers;

use App\Services\Notificaciones;
use Illuminate\Support\Facades\Auth;

/** Campanita del encabezado (ver App\Services\Notificaciones). */
class NotificacionesController extends Controller
{
    public function index(Notificaciones $notificaciones)
    {
        return response()->json($notificaciones->para(Auth::user()));
    }

    public function vistas(Notificaciones $notificaciones)
    {
        $notificaciones->marcarVistas(Auth::user());

        return response()->json(['ok' => true]);
    }
}
