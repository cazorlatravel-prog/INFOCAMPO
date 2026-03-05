-- =====================================================================
-- INFOCAMPO SaaS — Schema v9: Grosor y opacidad para capas KML
-- =====================================================================
-- Permite configurar el grosor de línea y la opacidad de cada capa KML.
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE capas_kml ADD COLUMN IF NOT EXISTS grosor TINYINT UNSIGNED DEFAULT 3 AFTER color;
ALTER TABLE capas_kml ADD COLUMN IF NOT EXISTS opacidad DECIMAL(3,2) DEFAULT 0.80 AFTER grosor;
