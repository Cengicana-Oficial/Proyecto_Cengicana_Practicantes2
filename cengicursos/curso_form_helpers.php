<?php

function cengi_curso_form_datos()
{
    $codigo = strtoupper(trim((string) ($_POST['codigo_curso'] ?? '')));
    $actividad = trim((string) ($_POST['actividad_tipo'] ?? 'formacion_academica'));
    if (!in_array($actividad, ['formacion_academica', 'evento_tecnico'], true)) {
        $actividad = 'formacion_academica';
    }

    $cupoTexto = trim((string) ($_POST['cupo'] ?? ''));
    $cupo = $cupoTexto === '' ? null : (int) $cupoTexto;
    $modulos = [];
    $modulosValidos = true;
    foreach ((array) ($_POST['modulos'] ?? []) as $modulo) {
        if (!is_array($modulo)) {
            continue;
        }
        $nombre = trim((string) ($modulo['nombre'] ?? ''));
        if ($nombre === '') {
            continue;
        }
        $horasTexto = trim((string) ($modulo['horas'] ?? ''));
        if ($horasTexto === '' || !is_numeric($horasTexto)) {
            $modulosValidos = false;
            continue;
        }
        $horas = (float) $horasTexto;
        if ($horas < 0 || $horas > 9999) {
            $modulosValidos = false;
            continue;
        }
        $modulos[] = [
            'id' => (int) ($modulo['id'] ?? 0),
            'nombre' => $nombre,
            'horas' => $horas,
            'pre' => !empty($modulo['pre']) ? 1 : 0,
            'post' => !empty($modulo['post']) ? 1 : 0,
        ];
    }

    return [
        'categoria_id' => (int) ($_POST['categorias_cursos'] ?? $_POST['categorias'] ?? 0),
        'ingenio_id' => (int) ($_POST['ingenio'] ?? 0),
        'instructor_id' => (int) ($_POST['instructor_id'] ?? 0) ?: null,
        'codigo' => $codigo,
        'actividad' => $actividad,
        'modalidad' => trim((string) ($_POST['tipo'] ?? '')),
        'nombre' => trim((string) ($_POST['nombre_cursos'] ?? '')),
        'area' => trim((string) ($_POST['area_tecnica'] ?? '')),
        'jornada' => trim((string) ($_POST['jornada_cursos'] ?? '')),
        'dias' => trim((string) ($_POST['dias'] ?? '')),
        'horario' => trim((string) ($_POST['horario'] ?? '')),
        'cupo' => $cupo,
        'inicio' => trim((string) ($_POST['inicio'] ?? '')),
        'fin' => trim((string) ($_POST['fin'] ?? '')),
        'modulos' => $modulos,
        'modulos_validos' => $modulosValidos,
    ];
}

function cengi_curso_form_valido(array $datos)
{
    $codigoValido = $datos['codigo'] === '' || preg_match('/^[A-Z0-9._-]{3,30}$/', $datos['codigo']);
    return $datos['categoria_id'] > 0
        && $datos['ingenio_id'] > 0
        && $datos['modalidad'] !== ''
        && $datos['nombre'] !== ''
        && $datos['jornada'] !== ''
        && $datos['dias'] !== ''
        && $datos['horario'] !== ''
        && ($datos['cupo'] === null || $datos['cupo'] > 0)
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $datos['inicio'])
        && preg_match('/^\d{4}-\d{2}-\d{2}$/', $datos['fin'])
        && $datos['fin'] >= $datos['inicio']
        && $datos['modulos_validos']
        && $codigoValido;
}

function cengi_curso_guardar_modulos(PDO $db, $cursoId, array $modulos, $actualizar)
{
    $existentes = [];
    if ($actualizar) {
        $stmt = $db->prepare('SELECT id FROM curso_modulos WHERE curso_id = ?');
        $stmt->execute([$cursoId]);
        $existentes = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    $conservados = [];
    $orden = 1;
    $stmtInsertar = $db->prepare('INSERT INTO curso_modulos (curso_id, nombre, horas, orden, acepta_pre, acepta_post) VALUES (?, ?, ?, ?, ?, ?)');
    $stmtActualizar = $db->prepare('UPDATE curso_modulos SET nombre = ?, horas = ?, orden = ?, acepta_pre = ?, acepta_post = ? WHERE id = ? AND curso_id = ?');
    foreach ($modulos as $modulo) {
        $moduloId = (int) $modulo['id'];
        if ($actualizar && $moduloId > 0 && isset($existentes[$moduloId])) {
            $stmtActualizar->execute([$modulo['nombre'], $modulo['horas'], $orden, $modulo['pre'], $modulo['post'], $moduloId, $cursoId]);
            $conservados[] = $moduloId;
        } else {
            $stmtInsertar->execute([$cursoId, $modulo['nombre'], $modulo['horas'], $orden, $modulo['pre'], $modulo['post']]);
            $conservados[] = (int) $db->lastInsertId();
        }
        $orden++;
    }

    if ($actualizar) {
        if ($conservados) {
            $marcadores = implode(',', array_fill(0, count($conservados), '?'));
            $stmt = $db->prepare("DELETE FROM curso_modulos WHERE curso_id = ? AND id NOT IN ($marcadores)");
            $stmt->execute(array_merge([$cursoId], $conservados));
        } else {
            $stmt = $db->prepare('DELETE FROM curso_modulos WHERE curso_id = ?');
            $stmt->execute([$cursoId]);
        }
    }
}
