-- =============================================
-- INFOCAMPO SaaS — Migración v18
-- Permitir visitas sin foto (url_cloudinary nullable)
-- y añadir columna es_visita_sin_foto
-- =============================================

-- 1. Permitir que url_cloudinary sea NULL (visitas sin foto)
ALTER TABLE registros
    MODIFY COLUMN url_cloudinary VARCHAR(512) DEFAULT NULL COMMENT 'URL de la imagen (NULL si visita sin foto)';

-- 2. Añadir flag para distinguir visitas sin foto
ALTER TABLE registros
    ADD COLUMN IF NOT EXISTS es_visita_sin_foto TINYINT(1) NOT NULL DEFAULT 0 AFTER nombre_archivo;

-- 3. Permitir lat/lon con defaults para visitas sin GPS
ALTER TABLE registros
    MODIFY COLUMN lat_real DECIMAL(10,7) DEFAULT 0 COMMENT 'Latitud GPS real del operador',
    MODIFY COLUMN lon_real DECIMAL(10,7) DEFAULT 0 COMMENT 'Longitud GPS real del operador';
