-- ============================================================
-- INFOCAMPO SaaS - Migración v4
-- Provincia y municipio en infraestructuras
-- ============================================================

SET NAMES utf8mb4;

-- -----------------------------------------------------------
-- 1. Nuevas columnas en INFRAESTRUCTURAS
-- -----------------------------------------------------------
ALTER TABLE infraestructuras
    ADD COLUMN IF NOT EXISTS provincia VARCHAR(100) DEFAULT NULL AFTER tipo,
    ADD COLUMN IF NOT EXISTS municipio VARCHAR(150) DEFAULT NULL AFTER provincia;

-- Índice para filtrado rápido por provincia/municipio
ALTER TABLE infraestructuras
    ADD INDEX IF NOT EXISTS idx_infra_provincia (empresa_id, provincia),
    ADD INDEX IF NOT EXISTS idx_infra_municipio (empresa_id, provincia, municipio);
