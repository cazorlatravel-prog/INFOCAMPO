-- =====================================================================
-- INFOCAMPO SaaS — Schema v17: Campo "monte" en infraestructuras
-- =====================================================================
-- Permite asociar infraestructuras a un monte (zona forestal) para
-- facilitar la búsqueda y filtrado en el panel del operador.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE infraestructuras ADD COLUMN IF NOT EXISTS monte VARCHAR(200) NULL AFTER municipio;
ALTER TABLE infraestructuras ADD INDEX idx_infra_monte (empresa_id, monte);
