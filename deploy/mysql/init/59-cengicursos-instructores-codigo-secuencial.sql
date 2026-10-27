USE cengi_cursos;
-- Normaliza una sola vez los códigos internos; conserva códigos institucionales personalizados.
-- Intencional (AUD-2026-001/M-6): los INS-nnn existentes se renumeran por orden de id. La equivalencia
-- código anterior -> nuevo queda en instructor_codigos_secuencia_auditoria para documentos ya emitidos.
CREATE TABLE IF NOT EXISTS instructor_codigos_secuencia_auditoria (
 instructor_id INT NOT NULL PRIMARY KEY, codigo_anterior VARCHAR(20) NULL, codigo_nuevo VARCHAR(20) NOT NULL, migrado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO instructor_codigos_secuencia_auditoria(instructor_id,codigo_anterior,codigo_nuevo)
SELECT id,codigo_catedratico,CONCAT('INS-',LPAD(numero,GREATEST(3,CHAR_LENGTH(numero)),'0')) FROM (
 SELECT id,codigo_catedratico,ROW_NUMBER() OVER(ORDER BY id) AS numero FROM instructores
 WHERE codigo_catedratico IS NULL OR TRIM(codigo_catedratico)='' OR codigo_catedratico REGEXP '^INS-[0-9]+$'
) enumerados WHERE NOT EXISTS (SELECT 1 FROM instructor_codigos_secuencia_auditoria);
START TRANSACTION;
UPDATE instructores i JOIN instructor_codigos_secuencia_auditoria a ON a.instructor_id=i.id
SET i.codigo_catedratico=CONCAT('TMP-INS-',i.id) WHERE i.codigo_catedratico <=> a.codigo_anterior;
UPDATE instructores i JOIN instructor_codigos_secuencia_auditoria a ON a.instructor_id=i.id
SET i.codigo_catedratico=a.codigo_nuevo WHERE i.codigo_catedratico=CONCAT('TMP-INS-',i.id);
COMMIT;