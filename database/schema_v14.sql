-- =====================================================================
-- INFOCAMPO SaaS — Schema v14: Escala configurable del mapa operador
-- =====================================================================
-- Permite al admin configurar el nivel de zoom del mapa del operador.
-- Valores: 9 (1:500.000), 10 (1:250.000), 12 (1:100.000), 13 (1:50.000)
-- Por defecto: 9 (1:500.000)
-- =====================================================================

SET NAMES utf8mb4;

ALTER TABLE empresas ADD COLUMN IF NOT EXISTS op_mapa_zoom TINYINT UNSIGNED NOT NULL DEFAULT 9 AFTER op_mostrar_mapa;
