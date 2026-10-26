<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // SIGEC corre detras de un reverse proxy interno que lo publica bajo
        // modulos.cengicana.org/sigec (ver dockerfiles/modulos-app/sigec-proxy.conf),
        // descartando el prefijo antes de reenviar la peticion. Sin esto,
        // url()/asset()/route() generarian enlaces sin el prefijo /sigec
        // (calculado a partir del request tal como lo ve este contenedor,
        // que no sabe nada del proxy). APP_URL (config('app.url')) debe
        // incluir el prefijo completo, ej. https://modulos.cengicana.org/sigec.
        if ($url = config('app.url')) {
            URL::forceRootUrl($url);

            if ($scheme = parse_url($url, PHP_URL_SCHEME)) {
                URL::forceScheme($scheme);
            }
        }
    }
}
