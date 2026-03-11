-- =====================================================================
-- INFOCAMPO SaaS — Schema v16: Configuración de watermark en fotos
-- =====================================================================
-- Permite al administrador configurar qué información aparece en las
-- fotos como marca de agua (fecha, coordenadas, orientación, ubicación,
-- código infra, situación, tipo foto, mini-mapa).
-- =====================================================================

SET NAMES utf8mb4;

-- Campos de watermark opcionales (los básicos siempre aparecen)
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS wm_mostrar_codigo_infra TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS wm_mostrar_situacion TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS wm_mostrar_tipo_foto TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS wm_mostrar_mapa TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS wm_mapa_zoom TINYINT UNSIGNED NOT NULL DEFAULT 15;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS wm_mapa_tamano TINYINT UNSIGNED NOT NULL DEFAULT 2;
