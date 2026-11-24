<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        // Sin sesion (expirada o cerrada) se vuelve al login del portal: a SIGEC solo se
        // entra por el SSO del menu principal, su formulario propio no se usa.
        if (! $request->expectsJson()) {
            $app = parse_url((string) config('app.url'));
            $origen = isset($app['host'])
                ? ($app['scheme'] ?? 'https') . '://' . $app['host'] . (isset($app['port']) ? ':' . $app['port'] : '')
                : $request->getSchemeAndHttpHost();

            return $origen . config('sso.login_url');
        }
    }
}
