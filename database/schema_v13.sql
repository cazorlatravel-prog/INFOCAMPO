-- =====================================================================
-- INFOCAMPO SaaS — Schema v13: Campos configurables del operador
-- =====================================================================
-- Permite al admin decidir qué campos ve el operador en la ficha:
-- empresa, infraestructura, situación y mapa de localización.
-- Por defecto todos desactivados (0).
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE empresas ADD COLUMN IF NOT EXISTS op_mostrar_empresa      TINYINT(1) NOT NULL DEFAULT 0 AFTER formato_nombre_foto;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS op_mostrar_infraestructura TINYINT(1) NOT NULL DEFAULT 0 AFTER op_mostrar_empresa;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS op_mostrar_situacion    TINYINT(1) NOT NULL DEFAULT 0 AFTER op_mostrar_infraestructura;
ALTER TABLE empresas ADD COLUMN IF NOT EXISTS op_mostrar_mapa         TINYINT(1) NOT NULL DEFAULT 0 AFTER op_mostrar_situacion;
