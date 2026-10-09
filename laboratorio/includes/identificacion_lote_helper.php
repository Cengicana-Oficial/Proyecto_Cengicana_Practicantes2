<?php
require_once __DIR__ . '/muestra_identificacion_helper.php';
require_once __DIR__ . '/catalogo_muestras_helper.php';

function labIdentificacionConfig(?array $reemplazo = null): array
{
    static $config;
    if ($reemplazo !== null) return $config = $reemplazo;
    return $config ??= json_decode(file_get_contents(__DIR__ . '/../config/identificacion_v3.json'), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * Formato de etiqueta por tipo de muestra desde el Catálogo de análisis ("Campos por tipo de muestra"):
 * los campos activos de cada tipo, en su orden y con su etiqueta, tipo de dato y obligatoriedad, pasan a
 * ser el formato asignado por defecto ("Campos de Suelos", …). Los formatos del archivo de configuración
 * siguen disponibles para lotes ya guardados con ellos.
 */
function labIdentificacionCargarCamposTipo(PDO $pdo): void
{
    $config = json_decode(file_get_contents(__DIR__ . '/../config/identificacion_v3.json'), true, 512, JSON_THROW_ON_ERROR);
    try {
        require_once __DIR__ . '/catalogo_campos_muestra_helper.php';
        labCamposMuestraSincronizarPredefinidos($pdo);
        $filas = $pdo->query("SELECT c.nombre, c.etiqueta, c.tipo_dato, c.unidad, c.obligatorio, c.opciones, c.activo, tm.nombre AS tipo_nombre, tm.prefijo
            FROM tipo_muestra_campo c JOIN tipo_muestra tm ON tm.id_tipo = c.id_tipo_muestra ORDER BY c.id_tipo_muestra, c.orden, c.id_campo")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[laboratorio][identificacion:campos] ' . $e->getMessage());
        labIdentificacionConfig($config);
        return;
    }
    $conocidos = array_column($config['campos'], null, 'key');
    $config['definiciones'] = [];
    $config['obligatorios'] = [];
    foreach ($filas as $f) {
        $clave = labCatalogoMuestrasClaveDesdePrefijo($f['prefijo'], $f['tipo_nombre']);
        $nombrePlantilla = 'Campos de ' . mb_convert_case(trim((string) $f['tipo_nombre']), MB_CASE_TITLE, 'UTF-8');
        $config['plantillas'][$nombrePlantilla] ??= [];
        $config['porTipo'][$clave] = $nombrePlantilla;
        // Todo campo conocido (activo o no) se acepta al guardar, para no perder datos ya capturados.
        if (!isset($conocidos[$f['nombre']])) {
            $conocidos[$f['nombre']] = ['key' => $f['nombre'], 'label' => $f['etiqueta']];
        }
        if (!(int) $f['activo']) continue;
        $config['plantillas'][$nombrePlantilla][] = $f['nombre'];
        $opciones = json_decode((string) ($f['opciones'] ?? ''), true);
        $config['definiciones'][$nombrePlantilla][$f['nombre']] = [
            'label' => $f['etiqueta'] . ($f['unidad'] ? ' (' . $f['unidad'] . ')' : ''),
            'tipo' => $f['tipo_dato'],
            'opciones' => is_array($opciones) ? array_values($opciones) : [],
            'obligatorio' => (int) $f['obligatorio'] === 1,
        ];
        if ((int) $f['obligatorio'] === 1) $config['obligatorios'][$nombrePlantilla][] = $f['nombre'];
    }
    $config['campos'] = array_values($conocidos);
    $config['definiciones'] = (object) $config['definiciones'];
    labIdentificacionConfig($config);
}

function labIdentificacionLoteSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS lab_identificacion_lote (
        id_solicitud INT NOT NULL PRIMARY KEY,
        metodo VARCHAR(16) NOT NULL,
        version INT NOT NULL DEFAULT 1,
        etiquetas_json LONGTEXT NOT NULL,
        actualizado_por VARCHAR(180) NOT NULL,
        actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_ident_lote_solicitud FOREIGN KEY (id_solicitud) REFERENCES solicitud(id_solicitud) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS lab_identificacion_historial (
        id_historial INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        id_solicitud INT NOT NULL,
        version INT NOT NULL,
        metodo VARCHAR(16) NOT NULL,
        motivo VARCHAR(1000) NOT NULL,
        usuario VARCHAR(180) NOT NULL,
        cambios INT NOT NULL DEFAULT 0,
        etiquetas_json LONGTEXT NOT NULL,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_ident_hist_version(id_solicitud, version),
        CONSTRAINT fk_ident_hist_solicitud FOREIGN KEY (id_solicitud) REFERENCES solicitud(id_solicitud) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function labIdentificacionEstado(array $etiqueta): string
{
    if (trim((string) ($etiqueta['info'] ?? '')) !== '') {
        return ($etiqueta['contenido'] ?? '') === 'solo_id' ? 'solo_id' : 'ok';
    }
    foreach ($etiqueta['campos'] ?? [] as $campo => $valor) {
        if ($campo !== 'obs' && trim((string) $valor) !== '') return 'ok';
    }
    return 'pendiente';
}

function labIdentificacionLotes(PDO $pdo, ?int $idSolicitud = null): array
{
    $stmt = $pdo->prepare("SELECT s.id_solicitud, s.id_lote, s.institucion AS cliente, l.codigo_lote,
        tm.nombre AS tipo, tm.prefijo, il.metodo, il.version, il.etiquetas_json, il.actualizado_por, il.actualizado_en,
        (SELECT GROUP_CONCAT(ta.nombre ORDER BY ta.nombre SEPARATOR ', ')
         FROM solicitud_analisis sa JOIN tipo_analisis ta ON ta.id_tipo=sa.id_tipo_analisis
         WHERE sa.id_solicitud=s.id_solicitud) AS analisis
        FROM solicitud s JOIN lote l ON l.id_lote=s.id_lote JOIN tipo_muestra tm ON tm.id_tipo=s.id_tipo
        LEFT JOIN lab_identificacion_lote il ON il.id_solicitud=s.id_solicitud
        WHERE (? IS NULL OR s.id_solicitud=?) ORDER BY s.id_solicitud DESC");
    $stmt->execute([$idSolicitud, $idSolicitud]);
    $lotes = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $row['id_solicitud'] = (int) $row['id_solicitud'];
        $row['version'] = (int) ($row['version'] ?? 0);
        $row['tipo_clave'] = labCatalogoMuestrasClaveDesdePrefijo($row['prefijo'], $row['tipo']);
        $row['etiquetas'] = json_decode((string) ($row['etiquetas_json'] ?? '{}'), true) ?: [];
        $row['plantilla'] = $row['etiquetas']['_plantilla'] ?? (labIdentificacionConfig()['porTipo'][$row['tipo_clave']] ?? 'Todos los campos');
        unset($row['etiquetas']['_plantilla']);
        unset($row['etiquetas_json']);
        $row['muestras'] = [];
        $row['historial'] = [];
        $lotes[$row['id_solicitud']] = $row;
    }
    $stmt = $pdo->prepare("SELECT m.id_muestra, m.id_solicitud, m.codigo_lab,
        mi.lectura_codigo, mi.parcela, mi.bloque, mi.tratamiento, mi.repeticion, mi.variedad, mi.observaciones
        FROM muestra m LEFT JOIN muestra_identificacion mi ON mi.id_muestra=m.id_muestra
        WHERE (? IS NULL OR m.id_solicitud=?) ORDER BY m.numero_muestra, m.id_muestra");
    $stmt->execute([$idSolicitud, $idSolicitud]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $id = (int) $m['id_solicitud'];
        if (!isset($lotes[$id])) continue;
        $legacy = [];
        foreach (['parcela','bloque','tratamiento','repeticion','variedad','observaciones'] as $campo) {
            if (trim((string) ($m[$campo] ?? '')) !== '') $legacy[$campo === 'observaciones' ? 'obs' : $campo] = $m[$campo];
        }
        $lectura = ($m['lectura_codigo'] ?? '') !== $m['codigo_lab'] ? (string) ($m['lectura_codigo'] ?? '') : '';
        $etiqueta = $lotes[$id]['etiquetas'][$m['id_muestra']] ?? [
            'info' => $lectura, 'contenido' => preg_match('/[\n:=|;\t]/', $lectura) || mb_strlen($lectura) > 40 ? 'completa' : 'solo_id',
            'campos' => $legacy, 'origen' => $legacy || $lectura ? 'manual' : '',
        ];
        if (!isset($lotes[$id]['etiquetas'][$m['id_muestra']]) && $legacy && $lectura !== '') $etiqueta['contenido'] = 'completa';
        $lotes[$id]['muestras'][] = [
            'id_muestra' => (int) $m['id_muestra'], 'codigo_lab' => $m['codigo_lab'],
            'etiqueta' => $etiqueta, 'estado' => labIdentificacionEstado($etiqueta),
        ];
    }
    $stmt = $pdo->prepare('SELECT id_solicitud, version, metodo, motivo, usuario, cambios, creado_en FROM lab_identificacion_historial WHERE (? IS NULL OR id_solicitud=?) ORDER BY version DESC');
    $stmt->execute([$idSolicitud, $idSolicitud]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
        if (isset($lotes[$h['id_solicitud']])) $lotes[$h['id_solicitud']]['historial'][] = $h;
    }
    foreach ($lotes as &$lote) unset($lote['etiquetas']);
    unset($lote);
    return array_values($lotes);
}

function labIdentificacionNormalizarEtiqueta(array $raw, string $metodo): array
{
    if (!is_scalar($raw['info'] ?? '') || !is_array($raw['campos'] ?? [])) throw new InvalidArgumentException('Lectura o campos de etiqueta inválidos.');
    $info = trim((string) ($raw['info'] ?? ''));
    if (mb_strlen($info) > 20000) throw new InvalidArgumentException('Una lectura supera los 20 000 caracteres.');
    $campos = [];
    foreach (labIdentificacionConfig()['campos'] as $campo) {
        $value = $raw['campos'][$campo['key']] ?? '';
        if (!is_scalar($value)) throw new InvalidArgumentException('El valor de una celda no es válido.');
        $value = trim((string) $value);
        $limite = ['parcela'=>120,'bloque'=>120,'tratamiento'=>255,'repeticion'=>120,'variedad'=>180][$campo['key']] ?? 1000;
        if (mb_strlen($value) > $limite) throw new InvalidArgumentException($campo['label'] . ' supera los ' . $limite . ' caracteres.');
        if ($value !== '') $campos[$campo['key']] = $value;
    }
    // Un único método vigente: el cliente conserva el borrador antes de cambiarlo.
    if ($metodo === 'tabular') $info = '';
    else $campos = [];
    $contenido = (string) ($raw['contenido'] ?? '');
    if (!in_array($contenido, ['solo_id', 'completa'], true)) {
        $contenido = $info !== '' && mb_strlen($info) <= 40 && !preg_match('/[\n:=|;\t]/', $info) ? 'solo_id' : 'completa';
    }
    $origen = (string) ($raw['origen'] ?? $metodo);
    if (!in_array($origen, ['qr','barras','tabular','tabulado','pegado','camara','manual'], true)) $origen = $metodo;
    return ['info' => $info, 'contenido' => $contenido, 'campos' => $campos, 'origen' => $origen];
}

/** Guardado atómico con bloqueo de la solicitud y control de versión optimista. */
function labIdentificacionGuardarLote(PDO $pdo, array $data, string $usuario): array
{
    $id = (int) ($data['id_solicitud'] ?? 0);
    $version = filter_var($data['version'] ?? null, FILTER_VALIDATE_INT);
    $metodo = (string) ($data['metodo'] ?? '');
    $motivo = trim((string) ($data['motivo'] ?? ''));
    if (!$id || $version === false || $version < 0 || !in_array($metodo, ['qr','barras','tabular'], true)) {
        throw new InvalidArgumentException('Seleccione un lote y un método válidos.');
    }
    if (!is_array($data['muestras'] ?? null)) throw new InvalidArgumentException('No se recibieron las etiquetas.');
    if ($version > 0 && mb_strlen($motivo) < 5) throw new InvalidArgumentException('Indique el motivo de la corrección (mínimo 5 caracteres).');
    if (mb_strlen($motivo) > 1000) throw new InvalidArgumentException('El motivo supera los 1000 caracteres.');

    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id_solicitud FROM solicitud WHERE id_solicitud=? FOR UPDATE');
        $lock->execute([$id]);
        if (!$lock->fetchColumn()) throw new InvalidArgumentException('La recepción ya no existe.');
        $lote = labIdentificacionLotes($pdo, $id)[0] ?? null;
        if (!$lote || $version !== $lote['version']) throw new RuntimeException('Otra persona actualizó este lote. Recargue para revisar la nueva versión; su borrador se conserva.', 409);
        $plantilla = $data['plantilla'] ?? $lote['plantilla'];
        if (!is_string($plantilla) || !isset(labIdentificacionConfig()['plantillas'][$plantilla])) throw new InvalidArgumentException('Seleccione un formato de etiqueta válido.');
        $permitidas = array_column($lote['muestras'], null, 'id_muestra');
        $etiquetas = [];
        $cambios = 0;
        $pendientes = 0;
        foreach ($data['muestras'] as $m) {
            if (!is_array($m)) throw new InvalidArgumentException('Etiqueta inválida.');
            $idM = (int) ($m['id_muestra'] ?? 0);
            if (!isset($permitidas[$idM]) || isset($etiquetas[$idM])) throw new InvalidArgumentException('Hay muestras repetidas o de otro lote.');
            if (!is_array($m['etiqueta'] ?? [])) throw new InvalidArgumentException('Etiqueta inválida.');
            $e = labIdentificacionNormalizarEtiqueta($m['etiqueta'] ?? [], $metodo);
            $etiquetas[$idM] = $e;
            if (labIdentificacionEstado($e) === 'pendiente') $pendientes++;
            if ($e != $permitidas[$idM]['etiqueta']) $cambios++;
        }
        if (count($etiquetas) !== count($permitidas) || !$permitidas) throw new InvalidArgumentException('La lista de muestras cambió. Recargue el lote antes de guardar.');
        // Campos obligatorios del formato (Catálogo de análisis): exigidos en cada muestra con datos.
        $requeridos = labIdentificacionConfig()['obligatorios'][$plantilla] ?? [];
        if ($metodo === 'tabular' && $requeridos) {
            $etiquetasCampo = array_column(labIdentificacionConfig()['campos'], 'label', 'key');
            $faltan = [];
            foreach ($etiquetas as $idM => $e) {
                if (labIdentificacionEstado($e) === 'pendiente') continue;
                $vacios = array_filter($requeridos, static fn($k) => trim((string) ($e['campos'][$k] ?? '')) === '');
                if ($vacios) $faltan[] = $permitidas[$idM]['codigo_lab'] . ' (' . implode(', ', array_map(static fn($k) => $etiquetasCampo[$k] ?? $k, $vacios)) . ')';
            }
            if ($faltan) throw new InvalidArgumentException('Complete los campos obligatorios: ' . implode('; ', array_slice($faltan, 0, 8)) . (count($faltan) > 8 ? '…' : ''));
        }
        if ($pendientes === count($permitidas)) throw new InvalidArgumentException('Ingrese información en al menos una muestra.');
        if ($pendientes && ($data['confirmar_pendientes'] ?? false) !== true) throw new InvalidArgumentException('Hay muestras pendientes; confirme el guardado parcial.');
        if (!$cambios && $metodo === $lote['metodo'] && $plantilla === $lote['plantilla']) {
            if ($own) $pdo->commit();
            return $lote;
        }
        $etiquetas['_plantilla'] = $plantilla;
        $json = json_encode((object) $etiquetas, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $newVersion = $version + 1;
        $pdo->prepare('INSERT INTO lab_identificacion_lote (id_solicitud,metodo,version,etiquetas_json,actualizado_por)
            VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE metodo=VALUES(metodo),version=VALUES(version),etiquetas_json=VALUES(etiquetas_json),actualizado_por=VALUES(actualizado_por),actualizado_en=CURRENT_TIMESTAMP')
            ->execute([$id,$metodo,$newVersion,$json,$usuario]);
        $pdo->prepare('INSERT INTO lab_identificacion_historial (id_solicitud,version,metodo,motivo,usuario,cambios,etiquetas_json) VALUES (?,?,?,?,?,?,?)')
            ->execute([$id,$newVersion,$metodo,$motivo ?: 'Registro inicial',$usuario,$cambios,$json]);
        // Conserva la ficha técnica y sincroniza los campos que también están en la etiqueta.
        $marca = $pdo->prepare('INSERT INTO muestra_identificacion (id_muestra,lectura_codigo,actualizado_por) VALUES (?,?,?)
            ON DUPLICATE KEY UPDATE lectura_codigo=VALUES(lectura_codigo),actualizado_por=VALUES(actualizado_por)');
        $compartidos = $pdo->prepare('UPDATE muestra_identificacion SET parcela=?, bloque=?, tratamiento=?, repeticion=?, variedad=?, observaciones=? WHERE id_muestra=?');
        unset($etiquetas['_plantilla']);
        foreach ($etiquetas as $idM => $e) {
            if (labIdentificacionEstado($e) !== 'pendiente' || $version > 0) {
                $lectura = $e['info'] !== '' && mb_strlen($e['info']) <= 255 ? $e['info'] : $permitidas[$idM]['codigo_lab'];
                $marca->execute([$idM,$lectura,$usuario]);
                if ($metodo === 'tabular') {
                    $c = $e['campos'];
                    $compartidos->execute([$c['parcela'] ?? '',$c['bloque'] ?? '',$c['tratamiento'] ?? '',$c['repeticion'] ?? '',$c['variedad'] ?? '',$c['obs'] ?? '',$idM]);
                }
            }
        }
        if ($own) $pdo->commit();
        return labIdentificacionLotes($pdo, $id)[0];
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function labIdentificacionFichaProtegida(PDO $pdo, int $idMuestra, array $datos): void
{
    $lock = $pdo->prepare('SELECT s.id_solicitud FROM solicitud s JOIN muestra m ON m.id_solicitud=s.id_solicitud WHERE m.id_muestra=? FOR UPDATE');
    $lock->execute([$idMuestra]);
    $stmt = $pdo->prepare('SELECT mi.* FROM muestra m JOIN lab_identificacion_lote il ON il.id_solicitud=m.id_solicitud
        JOIN muestra_identificacion mi ON mi.id_muestra=m.id_muestra WHERE m.id_muestra=?');
    $stmt->execute([$idMuestra]);
    $guardada = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$guardada) return;
    foreach (['lectura_codigo','parcela','bloque','tratamiento','repeticion','variedad','observaciones'] as $campo) {
        if (trim((string) ($datos[$campo] ?? '')) !== trim((string) ($guardada[$campo] ?? ''))) {
            throw new InvalidArgumentException('Corrija los datos de etiqueta desde Registrar etiquetas, con un motivo y una nueva versión.');
        }
    }
}