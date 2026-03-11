-- =====================================================================
-- INFOCAMPO SaaS — Schema v16: Configuración de watermark en fotos
-- =====================================================================
-- Permite al administrador configurar qué información aparece en las
-- fotos como marca de agua (fecha, coordenadas, orientación, ubicación,
-- código infra, situación, tipo foto, mini-mapa).
-- =====================================================================

SET NAMES utf8mb4;

-- Campos de watermark base (por defecto activados)
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS wm_mostrar_fecha TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS wm_mostrar_coordenadas TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS wm_mostrar_orientacion TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS wm_mostrar_ubicacion TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS wm_mostrar_pais TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS wm_mostrar_brujula TINYINT(1) NOT NULL DEFAULT 1;

-- Campos de watermark opcionales (por defecto desactivados)
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS wm_mostrar_codigo_infra TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS wm_mostrar_situacion TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS wm_mostrar_tipo_foto TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS wm_mostrar_mapa TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS wm_mapa_zoom TINYINT UNSIGNED NOT NULL DEFAULT 15;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS wm_mapa_tamano TINYINT UNSIGNED NOT NULL DEFAULT 2;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS wm_texto_tamano TINYINT UNSIGNED NOT NULL DEFAULT 2;
