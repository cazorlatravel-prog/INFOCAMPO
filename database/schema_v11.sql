-- =====================================================================
-- INFOCAMPO SaaS — Schema v11: Formato de nombre de foto configurable
-- =====================================================================
-- Permite al administrador configurar el formato de nombre de archivo
-- de las fotos que toman los operadores.
--
-- Opciones:
--   1 = CODIGO_INFRA_NºFOTO
--   2 = CODIGO_INFRA_TIPO_TRABAJO_NºFOTO
--   3 = CODIGO_INFRA_TIPO_TRABAJO_TIPO_FOTO_NºFOTO
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE empresas ADD COLUMN IF NOT EXISTS formato_nombre_foto TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER nombre;
