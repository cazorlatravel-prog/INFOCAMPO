-- =====================================================================
-- INFOCAMPO SaaS — Schema v7: Capas KML persistentes por empresa
-- =====================================================================
-- Permite a los administradores subir archivos KML que se guardan en BD
-- y se muestran tanto en el mapa admin como en el mapa del operador.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS capas_kml (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    nombre VARCHAR(255) NOT NULL,
    contenido_kml LONGTEXT NOT NULL,
    color VARCHAR(7) DEFAULT '#8b5cf6',
    activa TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_empresa (empresa_id),
    INDEX idx_activa (empresa_id, activa),
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
