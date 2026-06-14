-- =====================================================================
-- INFOCAMPO SaaS — Schema v10: Tipos de Trabajo
-- =====================================================================
-- Permite al administrador crear tipos de trabajo que el operador
-- selecciona al realizar inspecciones en campo.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tipos_trabajo (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    empresa_id  INT UNSIGNED    NOT NULL,
    nombre      VARCHAR(200)    NOT NULL,
    codigo      VARCHAR(50)     DEFAULT NULL,
    descripcion TEXT            DEFAULT NULL,
    activa      TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_tt_empresa (empresa_id),
    CONSTRAINT fk_tt_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Añadir columna tipo_trabajo_id a registros
ALTER TABLE registros ADD COLUMN IF NOT EXISTS tipo_trabajo_id INT UNSIGNED DEFAULT NULL AFTER unidad_obra_id;
ALTER TABLE registros ADD INDEX idx_reg_tipo_trabajo (tipo_trabajo_id);
