<?php
declare(strict_types=1);

require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/public_urls.php';

/** Utilidades para emitir y validar el enlace público de visitantes. */
final class VisitGuestForm
{
    public static function issue(PDO $conn, int $requestId): string
    {
        if ($requestId <= 0) {
            throw new InvalidArgumentException('Solicitud inválida.');
        }

        $token = bin2hex(random_bytes(24));
        $hash = hash('sha256', $token);
        $hint = substr($token, 0, 8);
        $expiration = self::expiration($conn, $requestId);

        if (Conexion::driver() === 'pgsql') {
            $stmt = $conn->prepare(
                'insert into solicitud_formularios_visitantes
                    (id_solicitud, token_hash, token_hint, fecha_envio, fecha_expiracion)
                 values (?, ?, ?, current_timestamp, ?)
                 on conflict (id_solicitud) do update set
                    token_hash = excluded.token_hash,
                    token_hint = excluded.token_hint,
                    fecha_envio = current_timestamp,
                    fecha_expiracion = excluded.fecha_expiracion'
            );
        } else {
            $stmt = $conn->prepare(
                'insert into solicitud_formularios_visitantes
                    (id_solicitud, token_hash, token_hint, fecha_envio, fecha_expiracion)
                 values (?, ?, ?, NOW(), ?)
                 on duplicate key update
                    token_hash = values(token_hash),
                    token_hint = values(token_hint),
                    fecha_envio = NOW(),
                    fecha_expiracion = values(fecha_expiracion)'
            );
        }
        $stmt->execute([$requestId, $hash, $hint, $expiration]);

        return self::publicUrl($token);
    }

    public static function find(PDO $conn, string $token): ?array
    {
        $token = trim($token);
        if (!preg_match('/^[a-f0-9]{48}$/i', $token)) {
            return null;
        }

        $nowCondition = Conexion::driver() === 'pgsql'
            ? '(f.fecha_expiracion is null or f.fecha_expiracion >= current_timestamp)'
            : '(f.fecha_expiracion is null or f.fecha_expiracion >= NOW())';
        $stmt = $conn->prepare(
            "select f.id_formulario, f.id_solicitud, f.fecha_expiracion, f.fecha_completado,
                    f.listado_nombre, f.listado_ruta,
                    s.fecha_visita, s.hora_visita, s.cantidad_visitantes, s.tipo_visita,
                    s.id_estado, e.nombre_estado,
                    so.nombre_solicitante, so.nombre_institucion, so.correo, so.telefono,
                    coalesce(n.nombre_nivel, '') as nombre_nivel
             from solicitud_formularios_visitantes f
             join solicitudes s on s.id_solicitud = f.id_solicitud
             join solicitantes so on so.id_solicitante = s.id_solicitante
             join estados e on e.id_estado = s.id_estado
             left join niveles_academicos n on n.id_nivel = s.id_nivel
             where f.token_hash = ? and {$nowCondition}
             limit 1"
        );
        $stmt->execute([hash('sha256', $token)]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            return null;
        }

        $names = $conn->prepare(
            'select id_visitante, nombre_completo, orden
             from solicitud_visitantes
             where id_solicitud = ? order by orden, id_visitante'
        );
        $names->execute([(int) $request['id_solicitud']]);
        $request['visitantes'] = $names->fetchAll(PDO::FETCH_ASSOC);
        return $request;
    }

    public static function publicUrl(string $token): string
    {
        return visitas_public_url('registro_visitantes.php') . '?token=' . rawurlencode($token);
    }

    private static function expiration(PDO $conn, int $requestId): ?string
    {
        $stmt = $conn->prepare('select fecha_visita from solicitudes where id_solicitud = ? limit 1');
        $stmt->execute([$requestId]);
        $date = (string) ($stmt->fetchColumn() ?: '');
        if ($date === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($date . ' 23:59:59', new DateTimeZone('America/Guatemala')))
                ->modify('+1 day')->format('Y-m-d H:i:sP');
        } catch (Throwable $e) {
            return null;
        }
    }
}
