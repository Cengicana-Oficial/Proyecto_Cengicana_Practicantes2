<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Ingenio;
use App\Models\User;
use App\Services\RolesPortal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Puente de SSO desde el menu principal del compendio (login/Menu.php ->
 * login/sso_sigec.php). SIGEC corre en un contenedor/dominio distinto al
 * resto del compendio, asi que no puede leer la sesion PHP nativa
 * compartida: en vez de eso, login/sso_sigec.php firma un token de corta
 * duracion con el secreto de config('sso.secret') y esta ruta lo valida,
 * aprovisiona/actualiza el usuario local y abre una sesion Laravel normal.
 */
class SsoController extends Controller
{
    public function entrar(Request $request)
    {
        $claims = $this->verificarToken((string) $request->query('token', ''));

        if ($claims === null) {
            abort(403, 'Token de acceso invalido o expirado.');
        }

        // firstOrNew (no updateOrCreate) a proposito: la password local solo
        // se genera una vez, al crear el usuario. El login real siempre pasa
        // por este puente SSO, asi que esa password nunca se usa para
        // autenticar — pero sobreescribirla en cada visita rompería
        // cualquier password que un superadmin haya fijado a mano en SIGEC.
        $user = User::firstOrNew(['email' => $claims['correo']]);
        $esNuevo = !$user->exists;

        $user->name = $claims['nombre'];
        // El ingenio_id del portal es del catalogo de login (usuarios_menu), no de
        // la tabla ingenios de SIGEC: solo se asigna si existe aqui, para no
        // romper el login con la llave foranea users_ingenio_id_foreign.
        $ingenioId = (int) ($claims['ingenio_id'] ?? 0);
        $user->ingenio_id = $ingenioId && Ingenio::whereKey($ingenioId)->exists() ? $ingenioId : null;

        if ($esNuevo) {
            $user->password = bcrypt(bin2hex(random_bytes(16)));
        }

        $user->save();

        // Rol en Experimentos segun el portal: por nombre (config('sso.roles_portal')) y, para
        // tokens de versiones anteriores sin nombre, por rol_id. Sin rol reconocido se conserva el actual.
        $rol = !empty($claims['es_superadmin']) ? 'administrador'
            : (RolesPortal::rolSigec($claims['rol'] ?? null) ?? (config('sso.role_map')[(int) $claims['rol_id']] ?? null));
        if ($rol !== null) {
            $user->syncRoles([$rol]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended('/dashboard');
    }

    /**
     * Decodifica y valida firma+expiracion del token. Formato:
     * base64url(json_payload) . '.' . base64url(hmac_sha256(json_payload, secret)).
     * Ventana de expiracion corta (config('sso.ttl')) es la unica proteccion
     * contra replay — no hay nonce/lista de un solo uso; riesgo aceptado
     * para trafico interno de mismo origen de confianza (ver config/sso.php).
     */
    private function verificarToken(string $token): ?array
    {
        $secret = config('sso.secret');
        if (!$secret || !str_contains($token, '.')) {
            return null;
        }

        [$payloadB64, $signatureB64] = explode('.', $token, 2);

        $payloadJson = $this->base64UrlDecode($payloadB64);
        $signature = $this->base64UrlDecode($signatureB64);

        if ($payloadJson === false || $signature === false) {
            return null;
        }

        $signatureEsperada = hash_hmac('sha256', $payloadJson, $secret, true);

        if (!hash_equals($signatureEsperada, $signature)) {
            return null;
        }

        $claims = json_decode($payloadJson, true);
        if (!is_array($claims) || empty($claims['correo']) || empty($claims['exp'])) {
            return null;
        }

        if ((int) $claims['exp'] < time()) {
            return null;
        }

        return $claims;
    }

    private function base64UrlDecode(string $data)
    {
        $padded = strtr($data, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        return base64_decode($padded, true);
    }
}
