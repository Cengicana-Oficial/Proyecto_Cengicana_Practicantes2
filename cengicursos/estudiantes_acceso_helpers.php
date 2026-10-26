<?php
/** Suspensión de aula por cuenta y curso, independiente del expediente académico. */
function cengi_aula_acceso_sql(PDO $db, $cursoColumna, $usuarioId) {
    static $ready = [];
    $key = spl_object_id($db);
    if (!isset($ready[$key])) {
        try { $db->query('SELECT usuario_id FROM estudiante_aula_bloqueos LIMIT 0'); $ready[$key] = true; }
        catch (PDOException $e) { $ready[$key] = false; }
    }
    if (!$ready[$key]) return '1=1';
    return 'NOT EXISTS (SELECT 1 FROM estudiante_aula_bloqueos ab WHERE ab.curso_id = ' . $cursoColumna . ' AND ab.usuario_id = ' . (int) $usuarioId . ')';
}
function cengi_acc_actualizar_estudiante(PDO $m, PDO $db, array $u, $nombre, $correo, $veTodo, $ingenioId) {
    $nombre = trim((string) $nombre);
    $correo = trim((string) $correo);
    if ($nombre === '' || mb_strlen($nombre) > 100 || !filter_var($correo, FILTER_VALIDATE_EMAIL) || strlen($correo) > 100) throw new InvalidArgumentException('datos_invalidos');
    $q = $m->prepare('SELECT id FROM usuarios WHERE LOWER(correo) = LOWER(?) AND id <> ?');
    $q->execute([$correo, $u['id']]);
    if ($q->fetchColumn()) throw new InvalidArgumentException('correo_ocupado');
    // Las dos bases están en el mismo servidor: una sola transacción evita cambios parciales.
    $schema = str_replace('`', '``', (string) $db->query('SELECT DATABASE()')->fetchColumn());
    $owns = !$m->inTransaction();
    if ($owns) $m->beginTransaction();
    try {
        $q = $m->prepare("SELECT id, ingenio_id, cui_participantes FROM `$schema`.participantes WHERE usuarios_id = ? FOR UPDATE");
        $q->execute([$u['id']]);
        $fichas = $q->fetchAll(PDO::FETCH_ASSOC);
        foreach ($fichas as $p) if (!$veTodo && (int) $p['ingenio_id'] !== (int) $ingenioId) throw new InvalidArgumentException('sin_permiso');
        $m->prepare('UPDATE usuarios SET nombre = ?, correo = ? WHERE id = ?')->execute([$nombre, $correo, $u['id']]);
        $m->prepare("UPDATE `$schema`.participantes SET correo_participantes = ?, actualizado = NOW() WHERE usuarios_id = ?")->execute([$correo, $u['id']]);
        foreach ($fichas as $p) {
            if (trim((string) $p['cui_participantes']) === '') continue;
            $m->prepare("UPDATE `$schema`.solicitudes_inscripcion SET correo = ? WHERE cui_participante = ? AND ingenio_id = ? AND LOWER(correo) = LOWER(?)")->execute([$correo, $p['cui_participantes'], $p['ingenio_id'], $u['correo']]);
        }
        if (strcasecmp($correo, $u['correo']) !== 0) $m->prepare('UPDATE password_resets SET usado_en = NOW() WHERE usuario_id = ? AND usado_en IS NULL')->execute([$u['id']]);
        if ($owns) $m->commit();
    } catch (Throwable $e) {
        if ($owns && $m->inTransaction()) $m->rollBack();
        throw $e;
    }
}
function cengi_acc_reset_password(PDO $m, $usuarioId, $password, $confirmacion) {
    if ($password !== $confirmacion || strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) throw new InvalidArgumentException('password_invalida');
    $owns = !$m->inTransaction();
    if ($owns) $m->beginTransaction();
    try {
        $m->prepare('UPDATE usuarios SET contrasena = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $usuarioId]);
        $m->prepare('UPDATE password_resets SET usado_en = NOW() WHERE usuario_id = ? AND usado_en IS NULL')->execute([$usuarioId]);
        if ($owns) $m->commit();
    } catch (Throwable $e) {
        if ($owns && $m->inTransaction()) $m->rollBack();
        throw $e;
    }
}
/** Paginación en memoria del listado filtrado; el expediente seleccionado fija su página. */
function cengi_acc_paginar_estudiantes(array $lista, $pagina, $porPagina, $seleccionado = 0) {
    $porPagina = in_array((int) $porPagina, [25, 50, 100], true) ? (int) $porPagina : 25;
    $total = count($lista);
    $paginas = max(1, (int) ceil($total / $porPagina));
    $pagina = max(1, min($paginas, (int) $pagina));
    if ((int) $seleccionado > 0) {
        foreach ($lista as $index => $u) if ((int) $u['id'] === (int) $seleccionado) { $pagina = (int) floor($index / $porPagina) + 1; break; }
    }
    return [array_slice($lista, ($pagina - 1) * $porPagina, $porPagina), $pagina, $porPagina, $total, $paginas];
}