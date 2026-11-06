<?php
/**
 * Conexion directa con el modulo Experimentos (antes SIGEC), sin API.
 *
 * Las dos bases viven en el mismo servidor MySQL y el usuario del Laboratorio puede leer
 * la de Experimentos, asi que se consulta con la misma conexion PDO calificando las tablas
 * con el nombre de esa base (EXPERIMENTOS_DB_NAME, por defecto "sigec").
 *
 * Reglas:
 *  - El Laboratorio SOLO LEE la base de Experimentos (muestras, ensayos, proyectos, programas).
 *  - Experimentos SOLO LEE la base del Laboratorio. Para que tome los resultados de un lote,
 *    el Laboratorio lo marca como compartido en lab_experimentos_compartido (boton
 *    "Compartir con Experimentos" del informe firmado).
 */

/**
 * Patron del ID que imprime Experimentos en la etiqueta QR. Formato actual {ensayo}-{tipo}{n}
 * (COS-26-01-E01-AG025) y anterior SIGEC-{ensayo}-{tipo}-{n}-{aa} (SIGEC-COS-26-01-E01-SU-003-26).
 * Debe coincidir con App\Services\LaboratorioModulo::PATRON_ID de Experimentos.
 */
const LAB_EXP_PATRON_ID = '/SIGEC-[A-Z0-9-]+-\d{2}|\b[A-Z][A-Z0-9]{1,3}-\d{2}-\d{2,}-E\d{2,}-[A-Z]{2,3}\d{3,}\b/i';
/** Filtro SQL previo (REGEXP de MySQL) con el que se buscan lecturas que pueden ser de Experimentos. */
const LAB_EXP_REGEXP_SQL = 'SIGEC-|-E[0-9]{2,}-[A-Za-z]{2,3}[0-9]{3}';

/** Nombre de la base de Experimentos, validado para poder usarlo dentro del SQL. */
function labExpBase(): string
{
    $base = trim((string) (getenv('EXPERIMENTOS_DB_NAME') ?: ($_ENV['EXPERIMENTOS_DB_NAME'] ?? 'sigec')));
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $base)) {
        throw new RuntimeException('EXPERIMENTOS_DB_NAME no es un nombre de base valido.');
    }
    return '`' . $base . '`';
}

/** Extrae el ID de Experimentos de una lectura de QR o codigo de barras (o null). */
function labExpIdDeLectura(?string $lectura): ?string
{
    return preg_match(LAB_EXP_PATRON_ID, (string) $lectura, $m) ? mb_strtoupper($m[0]) : null;
}

/** ¿La base de Experimentos esta disponible para este usuario? (no rompe el Laboratorio si no lo esta) */
function labExpDisponible(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        $pdo->query('SELECT 1 FROM ' . labExpBase() . '.muestras_lab LIMIT 1');
        return $ok = true;
    } catch (Throwable $e) {
        error_log('[laboratorio][experimentos] base no disponible: ' . $e->getMessage());
        return $ok = false;
    }
}

/**
 * Datos de las muestras de Experimentos por su ID, listos para la identificacion del Laboratorio.
 * Devuelve [ID => datos]; los IDs que no existen no aparecen.
 */
function labExpMuestras(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map(static fn($id) => labExpIdDeLectura((string) $id), $ids))));
    if (!$ids || !labExpDisponible($pdo)) return [];
    $ids = array_slice($ids, 0, 500);
    $b = labExpBase();
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT ml.id_muestra, ml.tipo, ml.fecha, ml.finca AS m_finca, ml.lote AS m_lote, ml.repeticion AS m_rep,
            ml.obs, ml.datos, ml.estado,
            e.codigo AS ensayo, e.nombre AS ensayo_nombre, e.finca AS e_finca, e.lote AS e_lote, e.variedad, e.estrato,
            pr.codigo AS proyecto, pr.nombre AS proyecto_nombre, pg.sigla AS programa_sigla, pg.nombre AS programa,
            pa.codigo AS parcela, pa.repeticion AS p_rep, t.codigo AS trat_codigo, t.descripcion AS trat_desc
        FROM {$b}.muestras_lab ml
        LEFT JOIN {$b}.ensayos e ON e.id = ml.ensayo_id
        LEFT JOIN {$b}.proyectos pr ON pr.id = e.proyecto_id
        LEFT JOIN {$b}.programas pg ON pg.id = pr.programa_id
        LEFT JOIN {$b}.parcelas pa ON pa.id = ml.parcela_id
        LEFT JOIN {$b}.tratamientos t ON t.id = ml.tratamiento_id
        WHERE UPPER(ml.id_muestra) IN ({$marcas})");
    $stmt->execute($ids);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $datos = json_decode((string) ($r['datos'] ?? ''), true) ?: [];
        $rep = $r['m_rep'] ?? $r['p_rep'];
        $trat = $r['trat_codigo'] ? trim($r['trat_codigo'] . ($r['trat_desc'] ? ' - ' . $r['trat_desc'] : '')) : null;
        $out[mb_strtoupper($r['id_muestra'])] = [
            'id' => $r['id_muestra'],
            'tipo' => $r['tipo'],
            'fecha_muestreo' => $r['fecha'] ? substr($r['fecha'], 0, 10) : null,
            'programa' => $r['programa_sigla'] ? $r['programa_sigla'] . ' · ' . $r['programa'] : null,
            'proyecto' => $r['proyecto'] ? $r['proyecto'] . ' — ' . $r['proyecto_nombre'] : null,
            'ensayo' => $r['ensayo'],
            'ensayo_nombre' => $r['ensayo_nombre'],
            'finca' => $r['m_finca'] ?: $r['e_finca'],
            'lote_campo' => $r['m_lote'] ?: $r['e_lote'],
            'estrato' => $r['estrato'],
            // Mismas columnas de muestra_identificacion.
            'identificacion' => [
                'parcela' => $r['parcela'],
                'bloque' => isset($datos['bloque']) && $datos['bloque'] !== '' ? (string) $datos['bloque'] : null,
                'tratamiento' => $trat,
                'repeticion' => $rep !== null && $rep !== '' ? (string) $rep : null,
                'variedad' => isset($datos['variedad']) && $datos['variedad'] !== '' ? (string) $datos['variedad'] : $r['variedad'],
                'observaciones' => $r['obs'],
            ],
        ];
    }
    return $out;
}

/** Texto corto para mostrar al escanear: "COS-26-01-E01 · P-01 · T1 · Rep. 1". */
function labExpResumen(array $m): string
{
    $i = $m['identificacion'];
    return implode(' · ', array_filter([
        $m['ensayo'], $i['parcela'] ? 'Parcela ' . $i['parcela'] : null,
        $i['tratamiento'] ? 'Trat. ' . $i['tratamiento'] : null, $i['repeticion'] ? 'Rep. ' . $i['repeticion'] : null,
    ]));
}

/**
 * Ensayos de Experimentos para la lista "Nombre del ensayo" de la recepcion, agrupados por
 * programa y proyecto: [['grupo' => 'COS · Cosecha › COS-26-01 Nombre', 'ensayos' => [...]], ...].
 */
function labExpEnsayosAgrupados(PDO $pdo): array
{
    if (!labExpDisponible($pdo)) return [];
    $b = labExpBase();
    try {
        $filas = $pdo->query("SELECT e.codigo, e.nombre, e.finca, e.estado,
                pr.codigo AS proyecto, pr.nombre AS proyecto_nombre, pg.sigla, pg.nombre AS programa
            FROM {$b}.ensayos e
            JOIN {$b}.proyectos pr ON pr.id = e.proyecto_id
            JOIN {$b}.programas pg ON pg.id = pr.programa_id
            ORDER BY pg.sigla, pr.codigo, e.codigo")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[laboratorio][experimentos] ensayos: ' . $e->getMessage());
        return [];
    }
    $grupos = [];
    foreach ($filas as $f) {
        $clave = $f['sigla'] . ' · ' . $f['programa'] . ' › ' . $f['proyecto'] . ' ' . $f['proyecto_nombre'];
        $grupos[$clave][] = [
            'codigo' => $f['codigo'],
            'texto' => $f['codigo'] . ($f['nombre'] ? ' — ' . $f['nombre'] : '') . ($f['finca'] ? ' (' . $f['finca'] . ')' : '') . ($f['estado'] === 'Finalizado' ? ' · finalizado' : ''),
        ];
    }
    return array_map(static fn($g, $e) => ['grupo' => $g, 'ensayos' => $e], array_keys($grupos), $grupos);
}

/* ===================== Compartir resultados con Experimentos ===================== */

/** Tabla del Laboratorio con los lotes que Experimentos puede leer (DDL solo al migrar el esquema). */
function labExpEsquema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS lab_experimentos_compartido (
        id_lote INT NOT NULL PRIMARY KEY,
        compartido TINYINT(1) NOT NULL DEFAULT 1,
        compartido_por VARCHAR(255) DEFAULT NULL,
        compartido_en DATETIME DEFAULT NULL,
        retirado_por VARCHAR(255) DEFAULT NULL,
        retirado_en DATETIME DEFAULT NULL,
        CONSTRAINT fk_lab_exp_comp_lote FOREIGN KEY (id_lote) REFERENCES lote (id_lote)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function labExpTablaCompartido(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    return $ok = (bool) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lab_experimentos_compartido'")->fetchColumn();
}

/** Ensayos de Experimentos elegidos en "Nombre del ensayo" de las recepciones del lote. */
function labExpEnsayosDelLote(PDO $pdo, int $idLote): array
{
    if (!labExpDisponible($pdo)) return [];
    $stmt = $pdo->prepare('SELECT DISTINCT e.codigo FROM solicitud s
        JOIN ' . labExpBase() . '.ensayos e
            ON UPPER(TRIM(e.codigo)) COLLATE utf8mb4_general_ci = UPPER(TRIM(s.codigo_muestreo)) COLLATE utf8mb4_general_ci
        WHERE s.id_lote = ?');
    $stmt->execute([$idLote]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * ¿El lote es de Experimentos? Si en la recepcion se eligio un ensayo de Experimentos o si sus
 * muestras tienen etiqueta QR SIGEC-. Incluye cuantas muestras estan ligadas por QR y si ya se compartio.
 */
function labExpEstadoLote(PDO $pdo, int $idLote): array
{
    $stmt = $pdo->prepare("SELECT mi.lectura_codigo FROM muestra m
        JOIN solicitud s ON s.id_solicitud = m.id_solicitud
        JOIN muestra_identificacion mi ON mi.id_muestra = m.id_muestra
        WHERE s.id_lote = ? AND mi.lectura_codigo REGEXP ?");
    $stmt->execute([$idLote, LAB_EXP_REGEXP_SQL]);
    $muestras = count(array_filter($stmt->fetchAll(PDO::FETCH_COLUMN), static fn($l) => labExpIdDeLectura($l) !== null));
    $total = $pdo->prepare('SELECT COUNT(*) FROM muestra m JOIN solicitud s ON s.id_solicitud = m.id_solicitud WHERE s.id_lote = ?');
    $total->execute([$idLote]);
    $ensayos = labExpEnsayosDelLote($pdo, $idLote);

    $estado = [
        'es_experimentos' => $muestras > 0 || (bool) $ensayos,
        'ensayos' => $ensayos,
        'muestras' => $muestras,
        'total_muestras' => (int) $total->fetchColumn(),
        'compartido' => false, 'por' => null, 'en' => null, 'disponible' => labExpTablaCompartido($pdo),
    ];
    if ($estado['disponible']) {
        $c = $pdo->prepare('SELECT compartido, compartido_por, compartido_en, retirado_por, retirado_en FROM lab_experimentos_compartido WHERE id_lote = ?');
        $c->execute([$idLote]);
        if ($fila = $c->fetch(PDO::FETCH_ASSOC)) {
            $estado['compartido'] = (bool) $fila['compartido'];
            $estado['por'] = $fila['compartido'] ? $fila['compartido_por'] : $fila['retirado_por'];
            $estado['en'] = $fila['compartido'] ? $fila['compartido_en'] : $fila['retirado_en'];
        }
    }
    return $estado;
}

/** [id_lote => true] de los lotes compartidos (para la lista de informes). */
function labExpLotesCompartidos(PDO $pdo): array
{
    if (!labExpTablaCompartido($pdo)) return [];
    return array_fill_keys(array_map('intval', $pdo->query('SELECT id_lote FROM lab_experimentos_compartido WHERE compartido = 1')->fetchAll(PDO::FETCH_COLUMN)), true);
}

/** Comparte (o retira) los resultados firmados del lote con Experimentos. */
function labExpCompartir(PDO $pdo, int $idLote, bool $compartir, string $usuario): array
{
    if (!labExpTablaCompartido($pdo)) throw new RuntimeException('Falta la tabla lab_experimentos_compartido: ejecute la migracion del esquema del Laboratorio.');
    $inf = $pdo->prepare('SELECT estado, codigo FROM lab_informe WHERE id_lote = ?');
    $inf->execute([$idLote]);
    $informe = $inf->fetch(PDO::FETCH_ASSOC);
    if (!$informe || $informe['estado'] !== 'FIRMADO') throw new InvalidArgumentException('Solo se comparten informes aprobados y firmados.');
    $estado = labExpEstadoLote($pdo, $idLote);
    if ($compartir && !$estado['es_experimentos']) {
        throw new InvalidArgumentException('El lote no es de Experimentos: en la recepcion no se eligio un ensayo de Experimentos y ninguna muestra tiene etiqueta SIGEC-.');
    }

    if ($compartir) {
        $pdo->prepare('INSERT INTO lab_experimentos_compartido (id_lote, compartido, compartido_por, compartido_en) VALUES (?, 1, ?, NOW())
            ON DUPLICATE KEY UPDATE compartido = 1, compartido_por = VALUES(compartido_por), compartido_en = NOW(), retirado_por = NULL, retirado_en = NULL')
            ->execute([$idLote, $usuario]);
    } else {
        $pdo->prepare('UPDATE lab_experimentos_compartido SET compartido = 0, retirado_por = ?, retirado_en = NOW() WHERE id_lote = ?')
            ->execute([$usuario, $idLote]);
    }
    $pdo->prepare('INSERT INTO lab_informe_historial (id_lote, accion, detalle, usuario) VALUES (?, ?, ?, ?)')
        ->execute([$idLote, $compartir ? 'Enviado a Experimentos' : 'Retirado de Experimentos',
            $informe['codigo'] . ($estado['ensayos'] ? ' · ensayo ' . implode(', ', $estado['ensayos']) : '')
                . ' · ' . $estado['muestras'] . ' de ' . $estado['total_muestras'] . ' muestra(s) con etiqueta de Experimentos', $usuario]);

    return labExpEstadoLote($pdo, $idLote) + ['codigo' => $informe['codigo']];
}
