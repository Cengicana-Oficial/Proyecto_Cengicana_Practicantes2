<?php
$connUsuarios = Conexion::conectarUsuariosMenu();
$moduloObjetivo = 'solicitud de visitas';

$sql = "
    SELECT
        u.id,
        u.nombre,
        u.correo,
        COALESCE(r.nombre_rol, 'Sin rol en el módulo') AS nombre_rol,
        COALESCE(i.nombre_ingenio, 'Sin ingenio') AS ingenio,
        COALESCE(GROUP_CONCAT(DISTINCT m.nombre ORDER BY m.nombre SEPARATOR ', '), 'Sin modulo') AS modulos
    FROM usuarios u
    -- rol del usuario EN Solicitud de visitas (rol por modulo); el superadmin conserva el suyo
    LEFT JOIN usuario_modulo umv ON umv.usuario_id = u.id
        AND umv.modulo_id = (SELECT mv.id FROM modulos mv WHERE LOWER(mv.nombre) = 'solicitud de visitas' LIMIT 1)
    LEFT JOIN roles r ON r.id = CASE WHEN u.es_superadmin = 1 THEN u.rol_id ELSE umv.rol_id END
    LEFT JOIN ingenios i ON i.id = u.ingenio_id
    LEFT JOIN usuario_modulo um ON um.usuario_id = u.id
    LEFT JOIN modulos m ON m.id = um.modulo_id
    WHERE EXISTS (
        SELECT 1
        FROM usuario_modulo umf
        INNER JOIN modulos mf ON mf.id = umf.modulo_id
        WHERE umf.usuario_id = u.id
          AND LOWER(mf.nombre) = ?
    )
    GROUP BY u.id, u.nombre, u.correo, r.nombre_rol, i.nombre_ingenio
    ORDER BY u.nombre
";

$stmt = $connUsuarios->prepare($sql);
$stmt->execute([$moduloObjetivo]);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<h2>Usuarios del modulo Solicitud de visitas</h2>

<a href="../../../login/usuarios/crear_usuario.php?scope=visitas" class="btn-save">
    + Agregar usuario
</a>

<div class="card-table">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Nombre</th>
                <th>Correo</th>
                <th>Ingenio</th>
                <th>Rol</th>
                <th>Modulos</th>
                <th>Acciones</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($usuarios as $usuario): ?>
            <tr>
                <td><?= (int) $usuario['id'] ?></td>
                <td><?= htmlspecialchars($usuario['nombre']) ?></td>
                <td><?= htmlspecialchars($usuario['correo']) ?></td>
                <td><?= htmlspecialchars($usuario['ingenio']) ?></td>
                <td><?= htmlspecialchars($usuario['nombre_rol']) ?></td>
                <td><?= htmlspecialchars($usuario['modulos']) ?></td>
                <td>
                    <div class="action-cell">
                        <a class="btn-edit" href="../../../login/usuarios/editar_usuario.php?id=<?= (int) $usuario['id'] ?>&scope=visitas">
                            Editar
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
