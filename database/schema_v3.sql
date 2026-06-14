-- ============================================================
-- INFOCAMPO SaaS - Migración v3
-- Unidades de obra + tipos de foto (aleatorio/comparativo)
-- ============================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------
-- 1. UNIDADES DE OBRA (gestionadas por el admin de empresa)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS unidades_obra (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    empresa_id  INT UNSIGNED    NOT NULL,
    nombre      VARCHAR(200)    NOT NULL,
    codigo      VARCHAR(50)     DEFAULT NULL,
    descripcion TEXT            DEFAULT NULL,
    activa      TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_uo_empresa (empresa_id),
    CONSTRAINT fk_uo_empresa
        FOREIGN KEY (empresa_id) REFERENCES empresas (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- 2. Nuevas columnas en REGISTROS para tipos de foto
-- -----------------------------------------------------------
-- tipo_foto: aleatorio = foto libre, comparativo = foto con ghosting
ALTER TABLE registros
    ADD COLUMN IF NOT EXISTS tipo_foto ENUM('aleatorio','comparativo') NOT NULL DEFAULT 'aleatorio' AFTER observaciones,
    ADD COLUMN IF NOT EXISTS secuencia_comparativa INT UNSIGNED DEFAULT NULL AFTER tipo_foto,
    ADD COLUMN IF NOT EXISTS nombre_archivo VARCHAR(255) DEFAULT NULL AFTER secuencia_comparativa,
    ADD COLUMN IF NOT EXISTS unidad_obra_id INT UNSIGNED DEFAULT NULL AFTER infra_id;

-- Indices
ALTER TABLE registros
    ADD INDEX idx_reg_tipo_foto (tipo_foto),
    ADD INDEX idx_reg_unidad_obra (unidad_obra_id);
