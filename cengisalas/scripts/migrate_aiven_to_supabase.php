<?php
declare(strict_types=1);

/**
 * Migra los datos operativos de Visitas desde Aiven/MySQL a Supabase/PostgreSQL.
 *
 * Uso:
 *   C:\xampp\php\php.exe scripts\migrate_aiven_to_supabase.php --dry-run
 *   C:\xampp\php\php.exe scripts\migrate_aiven_to_supabase.php
 *   C:\xampp\php\php.exe scripts\migrate_aiven_to_supabase.php --skip-files
 */

require dirname(__DIR__) . '/modules/visitas/config/conexion.php';

$arguments = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $arguments, true);
$skipFiles = in_array('--skip-files', $arguments, true);

function envValue(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);
    return $value === false || $value === null || $value === '' ? $default : (string) $value;
}

function loadMigrationEnv(): void
{
    $paths = [
        dirname(__DIR__) . '/.env',
        dirname(__DIR__) . '/modules/visitas/.env',
    ];
    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key !== '' && getenv($key) === false) {
                putenv($key . '=' . trim($value, " \t\n\r\0\x0B\"'"));
            }
        }
    }
}

function targetConnection(): PDO
{
    if (!extension_loaded('pdo_pgsql')) {
        throw new RuntimeException('Habilita extension=pdo_pgsql en C:\\xampp\\php\\php.ini y reinicia la terminal/Apache.');
    }

    $url = envValue('SUPABASE_DATABASE_URL');
    if ($url !== null) {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host']) || empty($parts['user'])) {
            throw new RuntimeException('SUPABASE_DATABASE_URL no es valida.');
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $parts['host'],
            $parts['port'] ?? 5432,
            ltrim((string) ($parts['path'] ?? '/postgres'), '/'),
            $query['sslmode'] ?? 'require'
        );
        $user = rawurldecode((string) $parts['user']);
        $password = rawurldecode((string) ($parts['pass'] ?? ''));
    } else {
        $host = envValue('SUPABASE_DB_HOST');
        $user = envValue('SUPABASE_DB_USER');
        if ($host === null || $user === null) {
            throw new RuntimeException('Define SUPABASE_DATABASE_URL o las variables SUPABASE_DB_* en modules/visitas/.env.');
        }
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
            $host,
            envValue('SUPABASE_DB_PORT', '5432'),
            envValue('SUPABASE_DB_NAME', 'postgres'),
            envValue('SUPABASE_DB_SSLMODE', 'require')
        );
        $password = envValue('SUPABASE_DB_PASS', '');
    }

    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('set search_path to visits, public');
    return $pdo;
}

function quoteIdentifier(string $name): string
{
    if (!preg_match('/^[a-z_][a-z0-9_]*$/i', $name)) {
        throw new InvalidArgumentException('Identificador SQL no valido: ' . $name);
    }
    return '"' . str_replace('"', '""', $name) . '"';
}

function upsertTable(PDO $source, PDO $target, string $table, string $primaryKey): int
{
    $rows = $source->query('SELECT * FROM `' . $table . '` ORDER BY `' . $primaryKey . '`')->fetchAll();
    if (!$rows) {
        return 0;
    }

    $columns = array_keys($rows[0]);
    // Estas tablas de compatibilidad no exponen contrasenas ni usuarios heredados.
    $quoted = implode(',', array_map('quoteIdentifier', $columns));
    $params = implode(',', array_map(static fn(string $column): string => ':' . $column, $columns));
    $updates = implode(',', array_map(
        static fn(string $column): string => quoteIdentifier($column) . '=excluded.' . quoteIdentifier($column),
        array_values(array_filter($columns, static fn(string $column): bool => $column !== $primaryKey))
    ));
    $sql = 'insert into visits.' . quoteIdentifier($table) . '(' . $quoted . ') values(' . $params . ') '
        . 'on conflict (' . quoteIdentifier($primaryKey) . ') do update set ' . $updates;
    $statement = $target->prepare($sql);
    foreach ($rows as $row) {
        $statement->execute($row);
    }
    return count($rows);
}

function resetSequence(PDO $target, string $table, string $primaryKey): void
{
    $sequenceLookup = $target->prepare(
        'select pg_get_serial_sequence(:table_name, :column_name)'
    );
    $sequenceLookup->execute([
        'table_name' => 'visits.' . $table,
        'column_name' => $primaryKey,
    ]);
    $sequence = (string) ($sequenceLookup->fetchColumn() ?: '');
    // Algunas tablas usan una clave natural (por ejemplo id_solicitud) y no
    // tienen una secuencia asociada.
    if ($sequence === '') {
        return;
    }

    $statement = $target->prepare(
        "select setval(pg_get_serial_sequence(:table_name,:column_name), " .
        "greatest(coalesce((select max(" . quoteIdentifier($primaryKey) . ") from visits." . quoteIdentifier($table) . "),1),1), true)"
    );
    $statement->execute(['table_name' => 'visits.' . $table, 'column_name' => $primaryKey]);
}

function uploadToStorage(string $localPath, string $objectPath, string $contentType): void
{
    $url = rtrim((string) envValue('SUPABASE_URL'), '/');
    $serviceKey = envValue('SUPABASE_SERVICE_ROLE_KEY');
    if ($url === '' || $serviceKey === null) {
        throw new RuntimeException('Para migrar documentos define SUPABASE_URL y SUPABASE_SERVICE_ROLE_KEY solo en .env.');
    }
    if (!is_file($localPath)) {
        throw new RuntimeException('No se encontro el documento: ' . $localPath);
    }

    $handle = curl_init($url . '/storage/v1/object/visit-documents/' . str_replace('%2F', '/', rawurlencode($objectPath)));
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => file_get_contents($localPath),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . $serviceKey,
            'Authorization: Bearer ' . $serviceKey,
            'Content-Type: ' . $contentType,
            'x-upsert: true',
        ],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if ($response === false || $error !== '' || $status < 200 || $status >= 300) {
        throw new RuntimeException("No se pudo subir {$objectPath} (HTTP {$status}): " . ($error ?: (string) $response));
    }
}

function migrateDocuments(PDO $source, PDO $target): array
{
    $base = dirname(__DIR__) . '/modules/visitas/uploads';
    $migrated = ['cartas' => 0, 'listados' => 0, 'agendas' => 0, 'fotos' => 0, 'missing' => 0];

    $requests = $source->query("select id_solicitud,ruta_carta_pdf,nombre_archivo_pdf from solicitudes where nombre_archivo_pdf<>''")->fetchAll();
    $updateRequest = $target->prepare('update visits.solicitudes set ruta_carta_pdf=? where id_solicitud=?');
    foreach ($requests as $request) {
        $filename = basename((string) $request['nombre_archivo_pdf']);
        $localPath = $base . '/cartas/' . $filename;
        if (!is_file($localPath)) {
            $migrated['missing']++;
            continue;
        }
        $objectPath = 'cartas/' . $request['id_solicitud'] . '/' . $filename;
        uploadToStorage($localPath, $objectPath, mime_content_type($localPath) ?: 'application/octet-stream');
        $updateRequest->execute([$objectPath, $request['id_solicitud']]);
        $migrated['cartas']++;
    }

    $lists = $source->query("select id_solicitud,ruta_carta_pdf,nombre_archivo_pdf from solicitud_museo where nombre_archivo_pdf is not null and nombre_archivo_pdf<>''")->fetchAll();
    $updateList = $target->prepare('update visits.solicitud_museo set ruta_carta_pdf=? where id_solicitud=?');
    foreach ($lists as $list) {
        $filename = basename((string) $list['nombre_archivo_pdf']);
        $localPath = $base . '/listado/' . $filename;
        if (!is_file($localPath)) {
            $migrated['missing']++;
            continue;
        }
        $objectPath = 'listados/' . $list['id_solicitud'] . '/' . $filename;
        uploadToStorage($localPath, $objectPath, 'application/pdf');
        $updateList->execute([$objectPath, $list['id_solicitud']]);
        $migrated['listados']++;
    }

    // Archivos agregados desde Gestionar visita. En Aiven se conservan con
    // una ruta relativa del tipo "ID/archivo.ext" dentro de uploads.
    $agendas = $source->query(
        "select id_solicitud,agenda_nombre,agenda_ruta
         from solicitud_gestion_visita
         where agenda_ruta is not null and agenda_ruta<>''"
    )->fetchAll();
    $updateAgenda = $target->prepare(
        'update visits.solicitud_gestion_visita set agenda_ruta=? where id_solicitud=?'
    );
    foreach ($agendas as $agenda) {
        $relative = ltrim(str_replace(['..', '\\'], '', (string) $agenda['agenda_ruta']), '/');
        $filename = basename($relative);
        $localPath = $base . '/gestion_visitas/' . $relative;
        if ($filename === '' || !is_file($localPath)) {
            $migrated['missing']++;
            continue;
        }
        $objectPath = 'agendas/' . (int) $agenda['id_solicitud'] . '/' . $filename;
        uploadToStorage($localPath, $objectPath, mime_content_type($localPath) ?: 'application/octet-stream');
        $updateAgenda->execute([$objectPath, $agenda['id_solicitud']]);
        $migrated['agendas']++;
    }

    $photos = $source->query(
        "select id_foto,id_solicitud,nombre_archivo,ruta_archivo
         from solicitud_fotos_visita
         where ruta_archivo is not null and ruta_archivo<>''"
    )->fetchAll();
    $updatePhoto = $target->prepare(
        'update visits.solicitud_fotos_visita set ruta_archivo=? where id_foto=?'
    );
    foreach ($photos as $photo) {
        $relative = ltrim(str_replace(['..', '\\'], '', (string) $photo['ruta_archivo']), '/');
        $filename = basename($relative);
        $localPath = $base . '/gestion_visitas/' . $relative;
        if ($filename === '' || !is_file($localPath)) {
            $migrated['missing']++;
            continue;
        }
        $objectPath = 'fotos/' . (int) $photo['id_solicitud'] . '/' . $filename;
        uploadToStorage($localPath, $objectPath, mime_content_type($localPath) ?: 'application/octet-stream');
        $updatePhoto->execute([$objectPath, $photo['id_foto']]);
        $migrated['fotos']++;
    }

    return $migrated;
}

loadMigrationEnv();
if (Conexion::driver() !== 'mysql') {
    throw new RuntimeException(
        'La instalacion actual esta configurada para Supabase. Este script solo conserva la migracion historica desde Aiven; no es necesario para una base nueva.'
    );
}
$source = Conexion::conectar();

$tables = [
    'aprobadores' => 'id_aprobador',
    'areas_interes' => 'id_area',
    'estado_pago' => 'id_estado_pago',
    'estados' => 'id_estado',
    'niveles_academicos' => 'id_nivel',
    'solicitantes' => 'id_solicitante',
    'solicitudes' => 'id_solicitud',
    'solicitud_gestion_visita' => 'id_solicitud',
    'solicitud_fotos_visita' => 'id_foto',
    'solicitud_eventos_visita' => 'id_evento',
    'solicitud_areas' => 'id',
    'solicitud_museo' => 'id_museo',
    'aprobacion_solicitud' => 'id_aprobacion',
    'solicitudes_ocultas' => 'id_oculta',
    'solicitud_reservas_cengisalas' => 'id',
];

if ($dryRun) {
    echo "Validacion de origen Aiven/MySQL\n";
    foreach ($tables as $table => $primaryKey) {
        $count = (int) $source->query('select count(*) from `' . $table . '`')->fetchColumn();
        echo str_pad($table, 36) . $count . " filas\n";
    }
    echo "No se escribio informacion (--dry-run).\n";
    exit(0);
}

$target = targetConnection();
$target->beginTransaction();
$counts = [];
try {
    $target->exec("insert into visits.migration_runs(source_name,status) values('aiven-mysql','running')");
    $runId = (int) $target->lastInsertId();
    foreach ($tables as $table => $primaryKey) {
        $counts[$table] = upsertTable($source, $target, $table, $primaryKey);
        resetSequence($target, $table, $primaryKey);
        echo str_pad($table, 36) . $counts[$table] . " filas migradas\n";
    }
    $target->commit();

    $documents = $skipFiles ? ['skipped' => true] : migrateDocuments($source, $target);
    $counts['documents'] = $documents;
    $statement = $target->prepare("update visits.migration_runs set status='completed',completed_at=now(),row_counts=?::jsonb where id=?");
    $statement->execute([json_encode($counts, JSON_UNESCAPED_UNICODE), $runId]);

    foreach ($tables as $table => $primaryKey) {
        $sourceCount = (int) $source->query('select count(*) from `' . $table . '`')->fetchColumn();
        $targetCount = (int) $target->query('select count(*) from visits.' . quoteIdentifier($table))->fetchColumn();
        if ($sourceCount !== $targetCount) {
            throw new RuntimeException("La validacion de {$table} fallo: Aiven={$sourceCount}, Supabase={$targetCount}.");
        }
    }
    echo "Migracion y validacion completadas. Cambia VISITS_DB_DRIVER=pgsql solo despues de probar el modulo.\n";
} catch (Throwable $error) {
    if ($target->inTransaction()) {
        $target->rollBack();
    }
    if (isset($runId)) {
        $statement = $target->prepare("update visits.migration_runs set status='failed',completed_at=now(),error_message=? where id=?");
        $statement->execute([$error->getMessage(), $runId]);
    }
    fwrite(STDERR, 'ERROR: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
