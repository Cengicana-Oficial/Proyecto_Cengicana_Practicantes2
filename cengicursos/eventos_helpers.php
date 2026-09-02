<?php

/**
 * Funciones compartidas por la administracion y el formulario publico de eventos.
 */

function cengi_evento_generar_codigo_qr(PDO $db)
{
    do {
        $codigo = 'EVT-' . date('Y') . '-' . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $stmt = $db->prepare('SELECT COUNT(*) FROM evento_participantes WHERE codigo_qr = ?');
        $stmt->execute([$codigo]);
    } while ((int) $stmt->fetchColumn() > 0);

    return $codigo;
}

function cengi_evento_modalidad_pago($valor)
{
    return strcasecmp(trim((string) $valor), 'Pagado') === 0 ? 'Pagado' : 'Gratuito';
}

function cengi_evento_asegurar_token_inscripcion(PDO $db, $eventoId)
{
    $stmt = $db->prepare('SELECT token_inscripcion FROM eventos WHERE id = ?');
    $stmt->execute([(int) $eventoId]);
    $token = $stmt->fetchColumn();

    if (is_string($token) && preg_match('/^[a-f0-9]{32}$/', $token)) {
        return $token;
    }

    for ($intento = 0; $intento < 5; $intento++) {
        $token = bin2hex(random_bytes(16));
        try {
            $actualizar = $db->prepare('UPDATE eventos SET token_inscripcion = ? WHERE id = ? AND token_inscripcion IS NULL');
            $actualizar->execute([$token, (int) $eventoId]);

            $stmt->execute([(int) $eventoId]);
            $guardado = $stmt->fetchColumn();
            if (is_string($guardado) && $guardado !== '') {
                return $guardado;
            }
        } catch (PDOException $e) {
            // Una colision del indice UNIQUE es extremadamente improbable;
            // si ocurre, se genera otro token en la siguiente iteracion.
            if ((string) $e->getCode() !== '23000') {
                throw $e;
            }
        }
    }

    throw new RuntimeException('No fue posible generar el enlace de inscripcion.');
}

