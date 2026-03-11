-- =====================================================================
-- INFOCAMPO SaaS — Schema v12: Capas de Infraestructuras (SHP/KML/KMZ)
-- =====================================================================
-- Permite al admin subir capas geográficas (Shapefile, KML, KMZ) con
-- datos de infraestructuras. Los features se vinculan a la tabla
-- infraestructuras a través de un campo configurable (ej: codigo_unico).
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS capas_infraestructuras (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    empresa_id      INT UNSIGNED    NOT NULL,
    nombre          VARCHAR(255)    NOT NULL,
    geojson         LONGTEXT        NOT NULL,
    campo_capa      VARCHAR(100)    NOT NULL,
    campo_tabla     VARCHAR(100)    NOT NULL DEFAULT 'codigo_unico',
    color           VARCHAR(7)      DEFAULT '#e74c3c',
    grosor          TINYINT UNSIGNED DEFAULT 2,
    opacidad        DECIMAL(3,2)    DEFAULT 0.80,
    activa          TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_ci_empresa (empresa_id),
    CONSTRAINT fk_ci_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
