-- Versiones de las liquidaciones de pago a instructores (pago_instructores.php).
--
-- Una liquidación enviada o aprobada se puede corregir (tarifas, observaciones, quitar
-- filas, agregar sesiones impartidas pendientes del período, número, destino y nota).
-- Antes de aplicar cada corrección se guarda aquí una copia completa del estado anterior
-- (encabezado + detalle en JSON), con el motivo, el resumen de cambios, el usuario y los
-- totales antes / después. La liquidación siempre muestra la versión vigente; las
-- anteriores se consultan y descargan desde "Historial de versiones".
--
-- version = número de la versión guardada (1 = la liquidación tal como se generó).
--
-- Idempotente. Espejo en deploy/mysql/init/30-cengicursos-liquidacion-versiones.sql.
-- pago_instructores.php también la crea si falta (CREATE TABLE IF NOT EXISTS).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS liquidacion_versiones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  liquidacion_id INT UNSIGNED NOT NULL,
  version SMALLINT UNSIGNED NOT NULL,
  motivo VARCHAR(500) NOT NULL DEFAULT '',
  cambios TEXT NULL,
  datos LONGTEXT NOT NULL,
  total_antes DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_despues DECIMAL(12,2) NOT NULL DEFAULT 0,
  usuario VARCHAR(255) NOT NULL DEFAULT '',
  creado TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_liquidacion_version (liquidacion_id, version),
  CONSTRAINT fk_liquidacion_versiones_liq FOREIGN KEY (liquidacion_id) REFERENCES liquidaciones (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
