<?php
declare(strict_types=1);

final class VisitStorage
{
    private const ALLOWED_MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    public static function storeUploadedFile(array $file, string $category, array $allowedExtensions): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($file['tmp_name'])) {
            throw new RuntimeException('El archivo no se recibió correctamente.');
        }
        if ((int) ($file['size'] ?? 0) > 10 * 1024 * 1024) {
            throw new RuntimeException('El archivo excede el máximo permitido de 10 MB.');
        }

        $original = (string) ($file['name'] ?? 'documento');
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('El tipo de archivo no está permitido.');
        }
        $mime = mime_content_type((string) $file['tmp_name']) ?: 'application/octet-stream';
        // En Windows, DOCX/XLSX suelen detectarse como application/zip. Se
        // valida también la extensión permitida para conservar esos formatos
        // al guardarlos en el bucket privado.
        if ($mime === 'application/zip') {
            $mime = [
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ][$extension] ?? $mime;
        }
        if (!isset(self::ALLOWED_MIME_TYPES[$mime])) {
            throw new RuntimeException('El contenido del archivo no coincide con un PDF o imagen válida.');
        }
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $baseName = pathinfo($original, PATHINFO_FILENAME);
        $safeName = trim((string) preg_replace('/[^a-zA-Z0-9_-]+/', '_', $baseName), '_');
        $safeName = $safeName !== '' ? $safeName : 'documento';
        $filename = gmdate('YmdHis') . '_' . bin2hex(random_bytes(5)) . '_' . $safeName . '.' . $extension;

        if (Conexion::driver() !== 'pgsql') {
            $folder = dirname(__DIR__) . '/uploads/' . $category;
            if (!is_dir($folder) && !mkdir($folder, 0775, true) && !is_dir($folder)) {
                throw new RuntimeException('No se pudo crear la carpeta de documentos.');
            }
            $destination = $folder . DIRECTORY_SEPARATOR . $filename;
            if (!move_uploaded_file((string) $file['tmp_name'], $destination)) {
                throw new RuntimeException('No se pudo guardar el archivo.');
            }
            return [$destination, $filename];
        }

        $objectPath = $category . '/' . gmdate('Y/m') . '/' . $filename;
        self::request('POST', $objectPath, (string) file_get_contents((string) $file['tmp_name']), $mime, true);
        return [$objectPath, $filename];
    }

    public static function streamObject(string $objectPath, string $downloadName): void
    {
        [$body, $contentType] = self::request('GET', $objectPath);
        header('Content-Type: ' . ($contentType ?: 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . str_replace('"', '', basename($downloadName)) . '"');
        header('Content-Length: ' . strlen($body));
        header('Cache-Control: private, no-store');
        echo $body;
        exit;
    }

    public static function deleteObject(string $objectPath): void
    {
        self::request('DELETE', $objectPath);
    }

    private static function request(string $method, string $objectPath, ?string $body = null, ?string $contentType = null, bool $upsert = false): array
    {
        $url = rtrim((string) Conexion::setting('SUPABASE_URL', ''), '/');
        // Supabase nuevo usa claves secretas opacas (sb_secret_…). Para
        // instalaciones antiguas también aceptamos el service_role JWT.
        $serviceKey = (string) Conexion::setting(
            'SUPABASE_SECRET_KEY',
            Conexion::setting('SUPABASE_SERVICE_ROLE_KEY', '')
        );
        if ($url === '' || $serviceKey === '') {
            throw new RuntimeException('Falta la configuración privada de Supabase Storage.');
        }
        // La publishable/anon key no puede escribir en un bucket privado.
        if (preg_match('/(?:^|_)publishable_/i', $serviceKey) || preg_match('/^anon\b/i', $serviceKey)) {
            throw new RuntimeException(
                'La clave configurada para Storage es pública. Usa SUPABASE_SECRET_KEY (sb_secret_…) o la clave privada service_role JWT, nunca la publishable/anon.'
            );
        }
        $isSecretKey = str_starts_with($serviceKey, 'sb_secret_');
        if (!$isSecretKey && substr_count($serviceKey, '.') < 2) {
            throw new RuntimeException(
                'La clave privada de Supabase Storage no tiene un formato válido. Usa SUPABASE_SECRET_KEY (sb_secret_…) o un service_role JWT.'
            );
        }

        $encodedPath = str_replace('%2F', '/', rawurlencode(ltrim($objectPath, '/')));
        $endpoint = $url . '/storage/v1/object/' . ($method === 'GET' ? 'authenticated/' : '')
            . 'visit-documents/' . $encodedPath;
        $headers = ['apikey: ' . $serviceKey];
        // Las claves nuevas sb_secret_ no son JWT y no deben enviarse como
        // Bearer; hacerlo provoca exactamente "Invalid Compact JWS".
        if (!$isSecretKey) {
            $headers[] = 'Authorization: Bearer ' . $serviceKey;
        }
        if ($contentType !== null) {
            $headers[] = 'Content-Type: ' . $contentType;
        }
        if ($upsert) {
            $headers[] = 'x-upsert: true';
        }

        $handle = curl_init($endpoint);
        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'cengisalas-server/1.0',
        ]);
        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $responseType = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false || $error !== '' || $status < 200 || $status >= 300) {
            $responseBody = is_string($response) ? substr($response, $headerSize) : '';
            throw new RuntimeException('Supabase Storage respondió HTTP ' . $status . '. ' . $responseBody);
        }
        return [substr((string) $response, $headerSize), $responseType];
    }
}
