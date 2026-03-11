-- =====================================================================
-- INFOCAMPO SaaS — Schema v15: Puntos de mapa para operadores
-- =====================================================================
-- Permite al administrador crear puntos personalizados en el mapa
-- que los operadores pueden ver (puntos de interés, referencias, etc.)
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS puntos_mapa (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    nombre VARCHAR(200) NOT NULL,
    descripcion TEXT NULL,
    lat DECIMAL(10,7) NOT NULL,
    lon DECIMAL(10,7) NOT NULL,
    icono VARCHAR(50) NOT NULL DEFAULT 'pin',
    color VARCHAR(20) NOT NULL DEFAULT '#e74c3c',
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_puntos_mapa_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    INDEX idx_puntos_mapa_empresa (empresa_id),
    INDEX idx_puntos_mapa_activo (empresa_id, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
