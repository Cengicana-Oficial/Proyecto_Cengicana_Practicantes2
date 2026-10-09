<?php
declare(strict_types=1);

// Conexion directa con las APIs de Meta (Facebook + Instagram), LinkedIn y
// Google Analytics 4. Las credenciales se guardan cifradas (AES-256-GCM) en
// `redes_conexiones`; la sincronizacion corre al abrir Redes sociales cuando
// vence el intervalo configurado, con el boton "Sincronizar ahora" o con
// cron/redes-sync.php.

function redes_api_proveedores(): array
{
    return [
        'meta' => ['nombre' => 'Meta (Facebook e Instagram)', 'icon' => 'ti-brand-meta', 'color' => '#1877f2'],
        'linkedin' => ['nombre' => 'LinkedIn', 'icon' => 'ti-brand-linkedin', 'color' => '#0a66c2'],
        'ga4' => ['nombre' => 'Google Analytics 4', 'icon' => 'ti-brand-google-analytics', 'color' => '#e37400'],
    ];
}

// ---------------------------------------------------------------- Secretos

function redes_api_llave(): string
{
    $base = app_env_value('COMITES_SECRET_KEY');
    if ($base === '') {
        $base = app_env_value('DB_PASS') . '|' . app_env_value('DB_USER') . '|comites-redes';
    }
    return hash('sha256', $base, true);
}

function redes_api_cifrar(array $secreto): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cifrado = openssl_encrypt((string) json_encode($secreto), 'aes-256-gcm', redes_api_llave(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cifrado === false) {
        throw new RuntimeException('No se pudieron cifrar las credenciales.');
    }
    return base64_encode($iv . $tag . $cifrado);
}

function redes_api_descifrar(?string $blob): array
{
    $raw = base64_decode((string) $blob, true);
    if ($raw === false || strlen($raw) < 29) {
        return [];
    }
    $plano = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', redes_api_llave(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plano === false ? [] : (json_decode($plano, true) ?: []);
}

/** "••••••ab12" para mostrar que un secreto existe sin revelarlo. */
function redes_api_mascara(string $valor): string
{
    return $valor === '' ? '' : '••••••' . substr($valor, -4);
}

// ---------------------------------------------------------------- Conexiones

/** Conexiones por proveedor con su configuracion, secretos descifrados y estado del ultimo sync. */
function redes_api_conexiones(PDO $pdo): array
{
    $conexiones = [];
    foreach (array_keys(redes_api_proveedores()) as $p) {
        $conexiones[$p] = ['proveedor' => $p, 'config' => [], 'secreto' => [], 'activo' => 0, 'ultimo_sync' => null, 'minutos' => null, 'ultimo_estado' => '', 'ultimo_mensaje' => ''];
    }
    $rows = $pdo->query('SELECT *, TIMESTAMPDIFF(MINUTE, ultimo_sync, NOW()) AS minutos FROM redes_conexiones')->fetchAll();
    foreach ($rows as $r) {
        if (!isset($conexiones[$r['proveedor']])) {
            continue;
        }
        $conexiones[$r['proveedor']] = [
            'proveedor' => $r['proveedor'],
            'config' => json_decode((string) $r['config_json'], true) ?: [],
            'secreto' => redes_api_descifrar($r['secreto']),
            'activo' => (int) $r['activo'],
            'ultimo_sync' => $r['ultimo_sync'],
            'minutos' => $r['minutos'] !== null ? (int) $r['minutos'] : null,
            'ultimo_estado' => (string) $r['ultimo_estado'],
            'ultimo_mensaje' => (string) $r['ultimo_mensaje'],
        ];
    }
    return $conexiones;
}

function redes_api_guardar_conexion(PDO $pdo, string $proveedor, array $config, array $secreto): void
{
    $pdo->prepare(
        'INSERT INTO redes_conexiones (proveedor, config_json, secreto, activo) VALUES (?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE config_json = VALUES(config_json), secreto = VALUES(secreto), activo = 1'
    )->execute([$proveedor, json_encode($config, JSON_UNESCAPED_UNICODE), redes_api_cifrar($secreto)]);
}

/** Guarda el formulario de una conexion. Los secretos vacios conservan el valor anterior. */
function redes_api_guardar(PDO $pdo, string $proveedor, array $post, array $files): string
{
    if (!isset(redes_api_proveedores()[$proveedor])) {
        throw new RuntimeException('Servicio no válido.');
    }
    $actual = redes_api_conexiones($pdo)[$proveedor];
    $config = $actual['config'];
    $secreto = $actual['secreto'];
    $texto = static fn(string $k): string => trim((string) ($post[$k] ?? ''));
    $config['intervalo'] = min(168, max(1, (int) ($post['intervalo'] ?? 6)));
    $nuevoSecreto = static function (string $k) use ($texto, &$secreto): void {
        if ($texto($k) !== '') {
            $secreto[$k] = $texto($k);
        }
    };

    if ($proveedor === 'meta') {
        $config['page_id'] = preg_replace('/\D/', '', $texto('page_id'));
        $config['ig_user_id'] = preg_replace('/\D/', '', $texto('ig_user_id'));
        $config['version'] = preg_match('/^v\d+\.\d+$/', $texto('version')) ? $texto('version') : 'v23.0';
        $nuevoSecreto('token');
        if ($config['page_id'] === '' || empty($secreto['token'])) {
            throw new RuntimeException('Meta: escribe el ID de la página y el token de acceso.');
        }
    } elseif ($proveedor === 'linkedin') {
        $config['org_id'] = preg_replace('/\D/', '', $texto('org_id'));
        $config['version'] = preg_match('/^\d{6}$/', $texto('version')) ? $texto('version') : '';
        $config['client_id'] = $texto('client_id');
        if ($texto('access_token') !== '') {
            $config['token_expira'] = date('Y-m-d', strtotime('+60 days'));
        }
        foreach (['access_token', 'refresh_token', 'client_secret'] as $k) {
            $nuevoSecreto($k);
        }
        if ($config['org_id'] === '' || empty($secreto['access_token'])) {
            throw new RuntimeException('LinkedIn: escribe el ID de la organización y el token de acceso.');
        }
    } else {
        $config['property_id'] = preg_replace('/\D/', '', $texto('property_id'));
        $json = $texto('service_account');
        if (($files['service_account_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && ($files['service_account_file']['size'] ?? 0) < 100000) {
            $json = (string) file_get_contents($files['service_account_file']['tmp_name']);
        }
        if ($json !== '') {
            $sa = json_decode($json, true);
            if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) {
                throw new RuntimeException('Google Analytics: el archivo JSON no es una clave de cuenta de servicio válida.');
            }
            $secreto['service_account'] = ['client_email' => $sa['client_email'], 'private_key' => $sa['private_key']];
            $config['client_email'] = $sa['client_email'];
        }
        if ($config['property_id'] === '' || empty($secreto['service_account'])) {
            throw new RuntimeException('Google Analytics: escribe el ID de la propiedad y carga la clave JSON de la cuenta de servicio.');
        }
    }
    redes_api_guardar_conexion($pdo, $proveedor, $config, $secreto);
    return redes_api_proveedores()[$proveedor]['nombre'] . ': conexión guardada.';
}

function redes_api_desconectar(PDO $pdo, string $proveedor): string
{
    $pdo->prepare('DELETE FROM redes_conexiones WHERE proveedor = ?')->execute([$proveedor]);
    return (redes_api_proveedores()[$proveedor]['nombre'] ?? 'Servicio') . ': conexión eliminada. Los datos ya sincronizados se conservan.';
}

// ---------------------------------------------------------------- HTTP

/** @return array{0:int,1:mixed} codigo HTTP y cuerpo JSON decodificado */
function redes_http(string $metodo, string $url, array $headers = [], ?string $cuerpo = null): array
{
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'CENGICANA-Comites/1.0',
    ]);
    if ($cuerpo !== null) {
        curl_setopt($c, CURLOPT_POSTFIELDS, $cuerpo);
    }
    $raw = curl_exec($c);
    $codigo = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
    $error = curl_error($c);
    curl_close($c);
    if ($raw === false) {
        throw new RuntimeException('Sin respuesta del servicio: ' . $error);
    }
    return [$codigo, json_decode((string) $raw, true)];
}

function redes_http_error(string $servicio, int $codigo, mixed $json): RuntimeException
{
    $msg = is_array($json) ? ($json['error']['message'] ?? $json['message'] ?? $json['error_description'] ?? (is_string($json['error'] ?? null) ? $json['error'] : '')) : '';
    return new RuntimeException("$servicio respondió $codigo" . ($msg !== '' ? ": $msg" : '.'));
}

/** Crea o actualiza una publicacion sincronizada (por ID externo o enlace) sin tocar los datos capturados a mano. */
function redes_api_guardar_publicacion(PDO $pdo, string $plataforma, string $externoId, ?string $url, ?string $fecha, string $titulo, ?string $tipo, array $m): string
{
    $campos = ['alcance', 'impresiones', 'interacciones', 'reacciones', 'comentarios', 'compartidos', 'clics', 'reproducciones', 'guardados'];
    $valores = array_map(static fn($c) => max(0, (int) ($m[$c] ?? 0)), $campos);
    $stmt = $pdo->prepare('SELECT id FROM publicaciones WHERE plataforma = ? AND externo_id = ? LIMIT 1');
    $stmt->execute([$plataforma, $externoId]);
    $id = $stmt->fetchColumn();
    if (!$id && $url) {
        $stmt = $pdo->prepare('SELECT id FROM publicaciones WHERE url = ? LIMIT 1');
        $stmt->execute([$url]);
        $id = $stmt->fetchColumn();
    }
    $sets = implode(', ', array_map(static fn($c) => "$c = ?", $campos));
    if ($id) {
        $pdo->prepare("UPDATE publicaciones SET $sets, externo_id = ?, url = COALESCE(url, ?), origen = 'api' WHERE id = ?")
            ->execute([...$valores, $externoId, $url, (int) $id]);
        return 'actualizada';
    }
    $titulo = trim(preg_replace('/\s+/', ' ', $titulo));
    $pdo->prepare('INSERT INTO publicaciones (fecha, plataforma, tipo, titulo, estado, url, externo_id, origen, ' . implode(', ', $campos) . ")
        VALUES (?, ?, ?, ?, 'Publicada', ?, ?, 'api', " . implode(', ', array_fill(0, count($campos), '?')) . ')')
        ->execute([$fecha, $plataforma, $tipo ? mb_substr($tipo, 0, 60) : null, mb_substr($titulo !== '' ? $titulo : "Publicación de $plataforma", 0, 220), $url, $externoId, ...$valores]);
    return 'nueva';
}

// ---------------------------------------------------------------- Meta

function redes_meta_get(string $version, string $ruta, array $params, string $token): array
{
    $url = str_starts_with($ruta, 'https://') ? $ruta : "https://graph.facebook.com/$version/$ruta?" . http_build_query($params + ['access_token' => $token]);
    [$codigo, $json] = redes_http('GET', $url);
    if ($codigo !== 200 || !is_array($json)) {
        throw redes_http_error('Meta', $codigo, $json);
    }
    return $json;
}

/**
 * Lee insights de un objeto probando metricas candidatas. Meta retira y
 * renombra metricas entre versiones: las que responde como invalidas se
 * recuerdan y se omiten en el resto de la sincronizacion.
 */
function redes_meta_insights(string $version, string $objeto, array $candidatas, string $token, array &$invalidas): array
{
    $pedir = array_values(array_diff($candidatas, $invalidas));
    if (!$pedir) {
        return [];
    }
    try {
        $json = redes_meta_get($version, "$objeto/insights", ['metric' => implode(',', $pedir)], $token);
    } catch (RuntimeException $e) {
        if (count($pedir) === 1) {
            $invalidas[] = $pedir[0];
            return [];
        }
        $resultado = [];
        foreach ($pedir as $metrica) {
            $resultado += redes_meta_insights($version, $objeto, [$metrica], $token, $invalidas);
        }
        return $resultado;
    }
    $resultado = [];
    foreach ($json['data'] ?? [] as $d) {
        $valor = $d['values'][0]['value'] ?? $d['total_value']['value'] ?? 0;
        $resultado[$d['name']] = is_array($valor) ? array_sum($valor) : (int) $valor;
    }
    return $resultado;
}

function redes_meta_probar(array $cfg, array $sec): string
{
    $pagina = redes_meta_get($cfg['version'], $cfg['page_id'], ['fields' => 'name,instagram_business_account'], $sec['token']);
    return 'Conectado a la página "' . ($pagina['name'] ?? $cfg['page_id']) . '"' . (!empty($pagina['instagram_business_account']['id']) || !empty($cfg['ig_user_id']) ? ' con Instagram vinculado.' : ' (sin cuenta de Instagram vinculada).');
}

function redes_meta_sincronizar(PDO $pdo, array $cfg, array $sec): string
{
    $v = $cfg['version'] ?: 'v23.0';
    $token = (string) ($sec['token'] ?? '');
    $desde = strtotime('-90 days');
    $invalidas = [];
    $conteo = ['Facebook' => ['nueva' => 0, 'actualizada' => 0], 'Instagram' => ['nueva' => 0, 'actualizada' => 0]];

    $json = redes_meta_get($v, $cfg['page_id'] . '/posts', [
        'fields' => 'id,message,created_time,permalink_url,status_type,shares,reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0)',
        'since' => $desde, 'limit' => 50,
    ], $token);
    $posts = $json['data'] ?? [];
    for ($pagina = 0; $pagina < 2 && !empty($json['paging']['next']); $pagina++) {
        $json = redes_meta_get($v, $json['paging']['next'], [], $token);
        $posts = array_merge($posts, $json['data'] ?? []);
    }
    foreach ($posts as $p) {
        $ins = redes_meta_insights($v, $p['id'], ['post_impressions_unique', 'post_impressions', 'post_clicks', 'post_media_view', 'post_total_media_view_unique', 'post_video_views'], $token, $invalidas);
        $m = [
            'alcance' => $ins['post_impressions_unique'] ?? $ins['post_total_media_view_unique'] ?? 0,
            'impresiones' => $ins['post_impressions'] ?? $ins['post_media_view'] ?? 0,
            'clics' => $ins['post_clicks'] ?? 0,
            'reproducciones' => $ins['post_video_views'] ?? 0,
            'reacciones' => (int) ($p['reactions']['summary']['total_count'] ?? 0),
            'comentarios' => (int) ($p['comments']['summary']['total_count'] ?? 0),
            'compartidos' => (int) ($p['shares']['count'] ?? 0),
        ];
        $m['interacciones'] = $m['reacciones'] + $m['comentarios'] + $m['compartidos'] + $m['clics'];
        $titulo = mb_strimwidth((string) ($p['message'] ?? ''), 0, 200, '…');
        $conteo['Facebook'][redes_api_guardar_publicacion($pdo, 'Facebook', $p['id'], $p['permalink_url'] ?? null, isset($p['created_time']) ? date('Y-m-d', strtotime($p['created_time'])) : null, $titulo, $p['status_type'] ?? null, $m)]++;
    }

    $avisoIg = '';
    try {
        $ig = $cfg['ig_user_id'] ?: (redes_meta_get($v, $cfg['page_id'], ['fields' => 'instagram_business_account'], $token)['instagram_business_account']['id'] ?? '');
        if ($ig !== '') {
            $media = redes_meta_get($v, "$ig/media", ['fields' => 'id,caption,media_type,media_product_type,timestamp,permalink,like_count,comments_count', 'limit' => 50], $token)['data'] ?? [];
            foreach ($media as $p) {
                if (isset($p['timestamp']) && strtotime($p['timestamp']) < $desde) {
                    continue;
                }
                $ins = redes_meta_insights($v, $p['id'], ['reach', 'views', 'impressions', 'saved', 'shares', 'total_interactions'], $token, $invalidas);
                $esVideo = in_array($p['media_type'] ?? '', ['VIDEO', 'REELS'], true) || ($p['media_product_type'] ?? '') === 'REELS';
                $m = [
                    'alcance' => $ins['reach'] ?? 0,
                    'impresiones' => $ins['views'] ?? $ins['impressions'] ?? 0,
                    'reproducciones' => $esVideo ? ($ins['views'] ?? 0) : 0,
                    'guardados' => $ins['saved'] ?? 0,
                    'compartidos' => $ins['shares'] ?? 0,
                    'reacciones' => (int) ($p['like_count'] ?? 0),
                    'comentarios' => (int) ($p['comments_count'] ?? 0),
                ];
                $m['interacciones'] = $ins['total_interactions'] ?? ($m['reacciones'] + $m['comentarios'] + $m['compartidos'] + $m['guardados']);
                $conteo['Instagram'][redes_api_guardar_publicacion($pdo, 'Instagram', $p['id'], $p['permalink'] ?? null, isset($p['timestamp']) ? date('Y-m-d', strtotime($p['timestamp'])) : null, mb_strimwidth((string) ($p['caption'] ?? ''), 0, 200, '…'), ucfirst(strtolower((string) ($p['media_type'] ?? ''))), $m)]++;
            }
        }
    } catch (RuntimeException $e) {
        $avisoIg = ' Instagram no se pudo leer: ' . $e->getMessage();
    }
    $resumen = [];
    foreach ($conteo as $red => $c) {
        if ($c['nueva'] + $c['actualizada'] > 0) {
            $resumen[] = "$red: {$c['actualizada']} actualizada(s), {$c['nueva']} nueva(s)";
        }
    }
    return ($resumen ? implode(' · ', $resumen) : 'Sin publicaciones en los últimos 90 días') . '.' . $avisoIg;
}

// ---------------------------------------------------------------- LinkedIn

function redes_linkedin_version(array $cfg): string
{
    // LinkedIn publica una version por mes y mantiene cada una alrededor de un ano.
    return ($cfg['version'] ?? '') !== '' ? $cfg['version'] : date('Ym', strtotime('first day of -2 months'));
}

/** Renueva el token con el refresh token cuando faltan menos de 5 dias para que venza. */
function redes_linkedin_token(PDO $pdo, array &$cfg, array &$sec, bool $forzar = false): string
{
    $vence = isset($cfg['token_expira']) ? strtotime($cfg['token_expira']) : null;
    if (($forzar || ($vence && $vence < strtotime('+5 days'))) && !empty($sec['refresh_token']) && !empty($cfg['client_id']) && !empty($sec['client_secret'])) {
        [$codigo, $json] = redes_http('POST', 'https://www.linkedin.com/oauth/v2/accessToken', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'refresh_token', 'refresh_token' => $sec['refresh_token'], 'client_id' => $cfg['client_id'], 'client_secret' => $sec['client_secret'],
        ]));
        if ($codigo !== 200 || empty($json['access_token'])) {
            throw redes_http_error('LinkedIn (renovación del token)', $codigo, $json);
        }
        $sec['access_token'] = $json['access_token'];
        if (!empty($json['refresh_token'])) {
            $sec['refresh_token'] = $json['refresh_token'];
        }
        $cfg['token_expira'] = date('Y-m-d', time() + (int) ($json['expires_in'] ?? 5184000));
        redes_api_guardar_conexion($pdo, 'linkedin', $cfg, $sec);
    }
    return (string) ($sec['access_token'] ?? '');
}

function redes_linkedin_get(string $url, string $token, string $version, bool $finder = false): array
{
    $headers = ["Authorization: Bearer $token", "LinkedIn-Version: $version", 'X-Restli-Protocol-Version: 2.0.0'];
    if ($finder) {
        $headers[] = 'X-RestLi-Method: FINDER';
    }
    [$codigo, $json] = redes_http('GET', $url, $headers);
    if ($codigo !== 200 || !is_array($json)) {
        throw redes_http_error('LinkedIn', $codigo, $json);
    }
    return $json;
}

function redes_linkedin_posts(string $org, string $token, string $version, int $cantidad): array
{
    return redes_linkedin_get('https://api.linkedin.com/rest/posts?q=author&author=' . rawurlencode($org) . "&count=$cantidad&sortBy=LAST_MODIFIED", $token, $version, true)['elements'] ?? [];
}

function redes_linkedin_probar(PDO $pdo, array $cfg, array $sec): string
{
    $token = redes_linkedin_token($pdo, $cfg, $sec);
    redes_linkedin_posts('urn:li:organization:' . $cfg['org_id'], $token, redes_linkedin_version($cfg), 1);
    return 'Conectado a la organización ' . $cfg['org_id'] . ' (API versión ' . redes_linkedin_version($cfg) . ').' . (!empty($cfg['token_expira']) ? ' El token vence el ' . date('d/m/Y', strtotime($cfg['token_expira'])) . '.' : '');
}

function redes_linkedin_sincronizar(PDO $pdo, array $cfg, array $sec): string
{
    $token = redes_linkedin_token($pdo, $cfg, $sec);
    $version = redes_linkedin_version($cfg);
    $org = 'urn:li:organization:' . $cfg['org_id'];
    $posts = array_filter(redes_linkedin_posts($org, $token, $version, 50), static fn($p) => ($p['lifecycleState'] ?? 'PUBLISHED') === 'PUBLISHED');
    $porTipo = ['shares' => [], 'ugcPosts' => []];
    foreach ($posts as $p) {
        $porTipo[str_starts_with((string) $p['id'], 'urn:li:ugcPost:') ? 'ugcPosts' : 'shares'][] = $p['id'];
    }
    $estadisticas = [];
    foreach ($porTipo as $param => $urns) {
        foreach (array_chunk($urns, 20) as $lote) {
            $url = 'https://api.linkedin.com/rest/organizationalEntityShareStatistics?q=organizationalEntity&organizationalEntity=' . rawurlencode($org)
                . "&$param=List(" . implode(',', array_map('rawurlencode', $lote)) . ')';
            foreach (redes_linkedin_get($url, $token, $version)['elements'] ?? [] as $e) {
                $estadisticas[$e['share'] ?? $e['ugcPost'] ?? ''] = $e['totalShareStatistics'] ?? [];
            }
        }
    }
    $conteo = ['nueva' => 0, 'actualizada' => 0];
    foreach ($posts as $p) {
        $s = $estadisticas[$p['id']] ?? [];
        $m = [
            'alcance' => (int) ($s['uniqueImpressionsCount'] ?? 0), 'impresiones' => (int) ($s['impressionCount'] ?? 0),
            'clics' => (int) ($s['clickCount'] ?? 0), 'reacciones' => (int) ($s['likeCount'] ?? 0),
            'comentarios' => (int) ($s['commentCount'] ?? 0), 'compartidos' => (int) ($s['shareCount'] ?? 0),
        ];
        $m['interacciones'] = $m['reacciones'] + $m['comentarios'] + $m['compartidos'] + $m['clics'];
        $ms = (int) ($p['publishedAt'] ?? $p['createdAt'] ?? 0);
        $conteo[redes_api_guardar_publicacion($pdo, 'LinkedIn', (string) $p['id'], 'https://www.linkedin.com/feed/update/' . $p['id'], $ms ? date('Y-m-d', intdiv($ms, 1000)) : null, mb_strimwidth((string) ($p['commentary'] ?? ''), 0, 200, '…'), null, $m)]++;
    }
    return "LinkedIn: {$conteo['actualizada']} actualizada(s), {$conteo['nueva']} nueva(s)." . (!empty($cfg['token_expira']) && strtotime($cfg['token_expira']) < strtotime('+10 days') && empty($sec['refresh_token']) ? ' El token vence el ' . date('d/m/Y', strtotime($cfg['token_expira'])) . ': genera uno nuevo.' : '');
}

// ---------------------------------------------------------------- Google Analytics 4

function redes_b64url(string $v): string
{
    return rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
}

/** Token OAuth de la cuenta de servicio (JWT firmado RS256). */
function redes_google_token(array $sa): string
{
    $ahora = time();
    $entrada = redes_b64url((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . redes_b64url((string) json_encode([
        'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
        'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $ahora, 'exp' => $ahora + 3600,
    ]));
    $firma = '';
    if (!openssl_sign($entrada, $firma, (string) $sa['private_key'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Google Analytics: la clave privada de la cuenta de servicio no es válida.');
    }
    [$codigo, $json] = redes_http('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $entrada . '.' . redes_b64url($firma),
    ]));
    if ($codigo !== 200 || empty($json['access_token'])) {
        throw redes_http_error('Google', $codigo, $json);
    }
    return $json['access_token'];
}

function redes_ga4_reporte(string $token, string $propiedad, array $cuerpo): array
{
    [$codigo, $json] = redes_http('POST', "https://analyticsdata.googleapis.com/v1beta/properties/$propiedad:runReport", ["Authorization: Bearer $token", 'Content-Type: application/json'], (string) json_encode($cuerpo));
    if ($codigo !== 200 || !is_array($json)) {
        throw redes_http_error('Google Analytics', $codigo, $json);
    }
    $filas = [];
    foreach ($json['rows'] ?? [] as $r) {
        $filas[] = [array_column($r['dimensionValues'] ?? [], 'value'), array_map('floatval', array_column($r['metricValues'] ?? [], 'value'))];
    }
    return $filas;
}

function redes_ga4_probar(array $cfg, array $sec): string
{
    $filas = redes_ga4_reporte(redes_google_token($sec['service_account']), $cfg['property_id'], [
        'dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'today']], 'metrics' => [['name' => 'activeUsers']],
    ]);
    return 'Conectado a la propiedad ' . $cfg['property_id'] . ': ' . (int) ($filas[0][1][0] ?? 0) . ' usuarios activos en los últimos 7 días.';
}

function redes_ga4_sincronizar(PDO $pdo, array $cfg, array $sec): string
{
    $token = redes_google_token($sec['service_account']);
    $rango = [['startDate' => date('Y-m-01', strtotime('first day of -12 months')), 'endDate' => 'today']];
    $totales = redes_ga4_reporte($token, $cfg['property_id'], [
        'dateRanges' => $rango, 'dimensions' => [['name' => 'yearMonth']],
        'metrics' => array_map(static fn($n) => ['name' => $n], ['activeUsers', 'newUsers', 'sessions', 'screenPageViews', 'averageSessionDuration', 'bounceRate']),
    ]);
    $canales = redes_ga4_reporte($token, $cfg['property_id'], [
        'dateRanges' => $rango, 'dimensions' => [['name' => 'yearMonth'], ['name' => 'sessionDefaultChannelGroup'], ['name' => 'sessionSource']],
        'metrics' => [['name' => 'sessions']], 'limit' => 10000,
    ]);
    $redes = $whatsapp = [];
    foreach ($canales as [$d, $m]) {
        $periodo = substr($d[0], 0, 4) . '-' . substr($d[0], 4, 2);
        $texto = normalize_text($d[1] . ' ' . $d[2]);
        if (str_contains($texto, 'whatsapp') || str_contains($texto, 'wa.me')) {
            $whatsapp[$periodo] = ($whatsapp[$periodo] ?? 0) + (int) $m[0];
        }
        if (str_contains($texto, 'social') || preg_match('/facebook|instagram|linkedin|twitter|t\.co|youtube|tiktok|whatsapp/', $texto)) {
            $redes[$periodo] = ($redes[$periodo] ?? 0) + (int) $m[0];
        }
    }
    $stmt = $pdo->prepare(
        "INSERT INTO sitio_web_metricas (periodo, usuarios, nuevos_usuarios, sesiones, vistas, duracion_seg, rebote, sesiones_redes, sesiones_whatsapp, fuente)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Google Analytics (API)')
         ON DUPLICATE KEY UPDATE usuarios = VALUES(usuarios), nuevos_usuarios = VALUES(nuevos_usuarios), sesiones = VALUES(sesiones),
            vistas = VALUES(vistas), duracion_seg = VALUES(duracion_seg), rebote = VALUES(rebote), sesiones_redes = VALUES(sesiones_redes),
            sesiones_whatsapp = VALUES(sesiones_whatsapp), fuente = VALUES(fuente)"
    );
    foreach ($totales as [$d, $m]) {
        $periodo = substr($d[0], 0, 4) . '-' . substr($d[0], 4, 2);
        $stmt->execute([$periodo, (int) $m[0], (int) $m[1], (int) $m[2], (int) $m[3], (int) round($m[4]), round($m[5] * 100, 2), $redes[$periodo] ?? 0, $whatsapp[$periodo] ?? 0]);
    }
    return 'Google Analytics: ' . count($totales) . ' mes(es) actualizado(s).';
}

// ---------------------------------------------------------------- Orquestacion

/** Prueba las credenciales sin guardar datos. */
function redes_api_probar(PDO $pdo, string $proveedor): string
{
    $c = redes_api_conexiones($pdo)[$proveedor] ?? null;
    if (!$c || !$c['activo']) {
        throw new RuntimeException('Primero guarda la conexión.');
    }
    return match ($proveedor) {
        'meta' => redes_meta_probar($c['config'] + ['version' => 'v23.0'], $c['secreto']),
        'linkedin' => redes_linkedin_probar($pdo, $c['config'], $c['secreto']),
        'ga4' => redes_ga4_probar($c['config'], $c['secreto']),
    };
}

/**
 * Sincroniza un proveedor. Con $forzar = false solo corre si vencio el
 * intervalo. Un candado de MySQL evita que dos visitas sincronicen a la vez.
 * Devuelve el mensaje, o null si no corrio.
 */
function redes_api_sincronizar(PDO $pdo, string $proveedor, bool $forzar = true): ?string
{
    $c = redes_api_conexiones($pdo)[$proveedor] ?? null;
    if (!$c || !$c['activo']) {
        if ($forzar) {
            throw new RuntimeException('Primero guarda la conexión.');
        }
        return null;
    }
    if (!$forzar && $c['minutos'] !== null && $c['minutos'] < 60 * (int) ($c['config']['intervalo'] ?? 6)) {
        return null;
    }
    $candado = 'comites_redes_' . $proveedor;
    if (!(int) $pdo->query('SELECT GET_LOCK(' . $pdo->quote($candado) . ', 0)')->fetchColumn()) {
        return null;
    }
    @set_time_limit(180);
    try {
        $mensaje = match ($proveedor) {
            'meta' => redes_meta_sincronizar($pdo, $c['config'] + ['version' => 'v23.0'], $c['secreto']),
            'linkedin' => redes_linkedin_sincronizar($pdo, $c['config'], $c['secreto']),
            'ga4' => redes_ga4_sincronizar($pdo, $c['config'], $c['secreto']),
        };
        $estado = 'ok';
    } catch (Throwable $e) {
        $mensaje = $e->getMessage();
        $estado = 'error';
    } finally {
        $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($candado) . ')');
    }
    // El intento fallido tambien cuenta, para no reintentar en cada visita.
    $pdo->prepare('UPDATE redes_conexiones SET ultimo_sync = NOW(), ultimo_estado = ?, ultimo_mensaje = ? WHERE proveedor = ?')->execute([$estado, mb_substr($mensaje, 0, 1000), $proveedor]);
    if ($estado === 'error' && $forzar) {
        throw new RuntimeException($mensaje);
    }
    return $mensaje;
}

/** Sincroniza las conexiones activas cuyo intervalo vencio. */
function redes_api_sincronizar_pendientes(PDO $pdo): array
{
    $resultados = [];
    foreach (array_keys(redes_api_proveedores()) as $p) {
        $mensaje = redes_api_sincronizar($pdo, $p, false);
        if ($mensaje !== null) {
            $resultados[$p] = $mensaje;
        }
    }
    return $resultados;
}

/** ¿Hay alguna conexion activa con el intervalo vencido? */
function redes_api_hay_pendientes(array $conexiones): bool
{
    foreach ($conexiones as $c) {
        if ($c['activo'] && ($c['minutos'] === null || $c['minutos'] >= 60 * (int) ($c['config']['intervalo'] ?? 6))) {
            return true;
        }
    }
    return false;
}

function redes_api_hace(?int $minutos): string
{
    if ($minutos === null) {
        return 'nunca';
    }
    if ($minutos < 1) {
        return 'hace un momento';
    }
    if ($minutos < 60) {
        return "hace $minutos min";
    }
    return $minutos < 1440 ? 'hace ' . intdiv($minutos, 60) . ' h' : 'hace ' . intdiv($minutos, 1440) . ' día(s)';
}
