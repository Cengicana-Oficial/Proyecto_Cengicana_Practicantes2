<?php
declare(strict_types=1);

/** Extensión aditiva del catálogo: conserva los identificadores y relaciones existentes. */
function catalogo_preparar(PDO $pdo): void
{
    if (!defined('ESQUEMA_MIGRANDO')) return; // DDL solo al desplegar (deploy/mysql/migrar-esquema.php, AUD-2026-001/I-2).
    $columnas = $pdo->query('SHOW COLUMNS FROM comites')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['descripcion' => 'TEXT NULL', 'lider_id' => 'INT NULL', 'periodicidad' => "VARCHAR(40) NOT NULL DEFAULT 'Mensual'", 'logo' => 'MEDIUMTEXT NULL'] as $campo => $tipo) {
        if (!in_array($campo, $columnas, true)) {
            $pdo->exec("ALTER TABLE comites ADD COLUMN {$campo} {$tipo}");
        }
    }
    $columnas = $pdo->query('SHOW COLUMNS FROM contactos')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('area', $columnas, true)) { $pdo->exec('ALTER TABLE contactos ADD COLUMN area VARCHAR(180) NULL'); }
}

function catalogo_fila(PDO $pdo, string $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM comites WHERE id = ?');
    $stmt->execute([$id]);
    $fila = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$fila) { throw new RuntimeException('El comité no existe.'); }
    return $fila;
}

function catalogo_contacto(PDO $pdo, array $datos): int
{
    $nombre = trim((string) ($datos['nombre'] ?? ''));
    $email = strtolower(trim((string) ($datos['email'] ?? '')));
    if ($nombre === '' || mb_strlen($nombre) > 180) { throw new RuntimeException('Escribe un nombre de hasta 180 caracteres.'); }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('El correo no es válido: ' . $email); }
    if ($email !== '') {
        $stmt = $pdo->prepare('SELECT id FROM contactos WHERE LOWER(email) = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($id = $stmt->fetchColumn()) { return (int) $id; }
    }
    $stmt = $pdo->prepare("INSERT INTO contactos (nombre,email,cargo,empresa,telefono,area,estado) VALUES (?,?,?,?,?,?,'Activo')");
    $stmt->execute([$nombre, $email ?: null, trim((string) ($datos['cargo'] ?? '')), trim((string) ($datos['empresa'] ?? '')), trim((string) ($datos['telefono'] ?? '')), trim((string) ($datos['area'] ?? ''))]);
    $id = (int) $pdo->lastInsertId();
    if (function_exists('directorio_origen')) {
        directorio_origen($pdo, $id, 'comite', (string) ($datos['_origen'] ?? 'Integrante registrado en un comité'), (string) ($_SESSION['usuario'] ?? ''));
        [$area, $nivel] = directorio_clasificar((string) ($datos['cargo'] ?? ''));
        $pdo->prepare('UPDATE contactos SET area=COALESCE(NULLIF(area,?),?),nivel=? WHERE id=?')->execute(['',$area,$nivel,$id]);
    }
    return $id;
}

/** Lee la primera hoja de XLSX o CSV exportado desde Excel, sin ejecutar fórmulas. */
function catalogo_leer_lista(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('Selecciona una lista CSV o XLSX de hasta 5 MB.');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $filas = [];
    if ($ext === 'csv') {
        $handle = fopen($file['tmp_name'], 'r');
        $primera = (string) fgets($handle);
        rewind($handle);
        $delimitador = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';
        while (($fila = fgetcsv($handle, 0, $delimitador)) !== false) {
            $filas[] = $fila;
            if (count($filas) > 2001) { fclose($handle); throw new RuntimeException('La lista admite hasta 2000 contactos.'); }
        }
        fclose($handle);
    } elseif ($ext === 'xlsx') {
        $zip = new ZipArchive();
        if ($zip->open($file['tmp_name']) !== true) { throw new RuntimeException('El archivo XLSX no es válido.'); }
        try {
            $leer = static function (string $ruta) use ($zip): ?SimpleXMLElement {
                $stat = $zip->statName($ruta);
                if (!$stat) { return null; }
                if ($stat['size'] > 12 * 1024 * 1024) { throw new RuntimeException('La hoja supera el tamaño permitido.'); }
                $xml = $zip->getFromName($ruta);
                if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) { throw new RuntimeException('Contenido XML no permitido.'); }
                $doc = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
                if (!$doc) { throw new RuntimeException('La hoja no se puede leer.'); }
                return $doc;
            };
            $texto = static function (SimpleXMLElement $node): string {
                return implode('', array_map('strval', $node->xpath('.//*[local-name()="t"]')));
            };
            $strings = [];
            $shared = $leer('xl/sharedStrings.xml');
            if ($shared) { foreach ($shared->xpath('//*[local-name()="si"]') as $si) { $strings[] = $texto($si); } }
            $sheet = $leer('xl/worksheets/sheet1.xml');
            if (!$sheet) { throw new RuntimeException('No se encontró la primera hoja de Excel.'); }
            foreach ($sheet->xpath('//*[local-name()="row"]') as $row) {
                $fila = [];
                foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                    preg_match('/^([A-Z]+)/', (string) $cell['r'], $m);
                    $indice = 0;
                    foreach (str_split($m[1] ?? 'A') as $letra) { $indice = $indice * 26 + ord($letra) - 64; }
                    if ($indice > 100) { throw new RuntimeException('La hoja tiene demasiadas columnas.'); }
                    $v = $cell->xpath('./*[local-name()="v"]');
                    $valor = (string) ($v[0] ?? '');
                    $fila[$indice - 1] = (string) $cell['t'] === 's' ? ($strings[(int) $valor] ?? '') : ((string) $cell['t'] === 'inlineStr' ? $texto($cell) : $valor);
                }
                $filas[] = $fila;
                if (count($filas) > 2001) { throw new RuntimeException('La lista admite hasta 2000 contactos.'); }
            }
        } finally { $zip->close(); }
    } else { throw new RuntimeException('Usa formato XLSX o CSV.'); }
    $cabecera = array_shift($filas) ?? [];
    $campos = [];
    foreach ($cabecera as $i => $campo) {
        $campo = mb_strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string) $campo)));
        $campo = strtr($campo, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u']);
        $campos[$i] = ['nombre completo'=>'nombre','correo'=>'email','correo electronico'=>'email','empresa / ingenio'=>'empresa','ingenio'=>'empresa'][$campo] ?? $campo;
    }
    if (!in_array('nombre', $campos, true)) { throw new RuntimeException('La primera fila debe incluir la columna Nombre.'); }
    $lista = [];
    foreach ($filas as $fila) {
        $dato = [];
        foreach ($campos as $i => $campo) { $dato[$campo] = trim((string) ($fila[$i] ?? '')); }
        if (implode('', $dato) !== '') { $lista[] = $dato; }
    }
    if (!$lista) { throw new RuntimeException('La lista está vacía.'); }
    return $lista;
}

function catalogo_accion(PDO $pdo, array $post, array $files): void
{
    $accion = $post['accion'];
    $id = trim((string) ($post['id'] ?? ''));
    $pdo->beginTransaction();
    $grupo = $id !== '' ? catalogo_fila($pdo, $id) : null;
    if ($accion === 'grupo_guardar') {
        $nombre = trim((string) ($post['nombre'] ?? ''));
        if ($nombre === '' || mb_strlen($nombre) > 160) { throw new RuntimeException('Escribe un nombre de hasta 160 caracteres.'); }
        $stmt = $pdo->prepare('SELECT id FROM comites WHERE nombre = ? AND id <> ?');
        $stmt->execute([$nombre, $id]);
        if ($stmt->fetchColumn()) { throw new RuntimeException('Ya existe un comité con ese nombre.'); }
        $periodicidad = (string) ($post['periodicidad'] ?? 'Mensual');
        if (!in_array($periodicidad, ['Mensual','Bimestral','Trimestral','Semestral','Según necesidad'], true)) { throw new RuntimeException('Periodicidad no válida.'); }
        $lider = (int) ($post['lider_id'] ?? 0);
        $integrantes = array_unique(array_map('intval', (array) ($post['integrantes'] ?? [])));
        if ($lider) { $integrantes[] = $lider; }
        $validos = array_map('intval', $pdo->query("SELECT id FROM contactos WHERE estado = 'Activo'")->fetchAll(PDO::FETCH_COLUMN));
        if (array_diff($integrantes, $validos)) { throw new RuntimeException('Selecciona integrantes activos del directorio.'); }
        $logo = !empty($post['quitar_logo']) ? null : ($grupo['logo'] ?? null);
        if (($files['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $file = $files['logo'];
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 2 * 1024 * 1024) { throw new RuntimeException('El logo debe pesar menos de 2 MB.'); }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            if (!in_array($mime, ['image/png','image/jpeg','image/webp','image/svg+xml'], true)) { throw new RuntimeException('Usa un logo PNG, JPG, WebP o SVG.'); }
            $contenido = file_get_contents($file['tmp_name']);
            if ($mime === 'image/svg+xml' && preg_match('/<\s*(script|foreignObject)|\bon\w+\s*=|(?:href|src)\s*=\s*["\'](?!#)|<!ENTITY|<!DOCTYPE/i', $contenido)) { throw new RuntimeException('El SVG debe ser una imagen sin scripts ni recursos externos.'); }
            $logo = 'data:' . $mime . ';base64,' . base64_encode($contenido);
        }
        $valores = [$nombre, trim((string) ($post['descripcion'] ?? '')), $lider ?: null, $periodicidad, ($post['estado'] ?? '') === 'Inactivo' ? 0 : 1, $logo];
        if ($id === '') {
            $base = strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombre));
            $id = substr(trim((string) preg_replace('/[^a-z0-9]+/', '-', $base), '-'), 26) . '-' . bin2hex(random_bytes(5));
            $pdo->prepare('INSERT INTO comites (nombre,descripcion,lider_id,periodicidad,activo,logo,id) VALUES (?,?,?,?,?,?,?)')->execute([...$valores,$id]);
        } else { $pdo->prepare('UPDATE comites SET nombre=?,descripcion=?,lider_id=?,periodicidad=?,activo=?,logo=? WHERE id=?')->execute([...$valores,$id]); }
        $pdo->prepare('DELETE FROM contacto_comite WHERE comite_id=?')->execute([$id]);
        $stmt = $pdo->prepare('INSERT IGNORE INTO contacto_comite (contacto_id,comite_id) VALUES (?,?)');
        foreach (array_unique($integrantes) as $contacto) { $stmt->execute([$contacto,$id]); }
    } else {
        if (!$grupo) { throw new RuntimeException('Selecciona un comité.'); }
        if ($accion === 'grupo_estado') {
            $pdo->prepare('UPDATE comites SET activo = 1 - activo WHERE id=?')->execute([$id]);
        } elseif ($accion === 'grupo_eliminar') {
            foreach (['reuniones','acuerdos','documentos'] as $tabla) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$tabla} WHERE comite_id=?");
                $stmt->execute([$id]);
                if ($stmt->fetchColumn()) { throw new RuntimeException('Este comité tiene reuniones, acuerdos o documentos asociados. Márcalo como inactivo para conservar el historial.'); }
            }
            $pdo->prepare('DELETE FROM comites WHERE id=?')->execute([$id]);
        } elseif ($accion === 'grupo_integrante_quitar') {
            $contacto = (int) ($post['contacto_id'] ?? 0);
            $pdo->prepare('DELETE FROM contacto_comite WHERE comite_id=? AND contacto_id=?')->execute([$id,$contacto]);
            $pdo->prepare('UPDATE comites SET lider_id=NULL WHERE id=? AND lider_id=?')->execute([$id,$contacto]);
        } elseif (in_array($accion, ['grupo_integrante_agregar','grupo_integrante_nuevo','grupo_integrante_importar'], true)) {
            if ($accion === 'grupo_integrante_nuevo') { $contactos = [catalogo_contacto($pdo, $post + ['_origen'=>'Integrante registrado en '.$grupo['nombre']])]; }
            elseif ($accion === 'grupo_integrante_importar') {
                $contactos = [];
                foreach (catalogo_leer_lista($files['lista'] ?? []) as $datos) { $contactos[] = catalogo_contacto($pdo,$datos + ['_origen'=>'Lista de integrantes de '.$grupo['nombre']]); }
            } else {
                $contacto = (int) ($post['contacto_id'] ?? 0);
                $stmt = $pdo->prepare("SELECT id FROM contactos WHERE id=? AND estado='Activo'");
                $stmt->execute([$contacto]);
                if (!$stmt->fetchColumn()) { throw new RuntimeException('Selecciona un contacto activo.'); }
                $contactos = [$contacto];
            }
            $stmt = $pdo->prepare('INSERT IGNORE INTO contacto_comite (contacto_id,comite_id) VALUES (?,?)');
            foreach ($contactos as $contacto) { $stmt->execute([$contacto,$id]); }
        } else { throw new RuntimeException('Acción no válida.'); }
    }
    $pdo->commit();
}
