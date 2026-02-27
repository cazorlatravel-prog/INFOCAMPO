-- ============================================================
-- INFOCAMPO SaaS - Inspección de Infraestructuras
-- Schema MySQL 8.0+
-- Autor: DBA Senior
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------
-- Base de datos
-- En Hostinger la BD ya existe: u919343704_infocampo_saas
-- Selecciónala en phpMyAdmin antes de importar este archivo
-- -----------------------------------------------------------
-- CREATE DATABASE IF NOT EXISTS infocampo_saas
--     CHARACTER SET utf8mb4
--     COLLATE utf8mb4_unicode_ci;
-- USE infocampo_saas;

-- -----------------------------------------------------------
-- 1. EMPRESAS  (tenant principal del SaaS)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS empresas (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    nombre          VARCHAR(200)    NOT NULL,
    plan_suscripcion ENUM('free','basic','professional','enterprise')
                                    NOT NULL DEFAULT 'free',
    nif             VARCHAR(20)     DEFAULT NULL,
    email_contacto  VARCHAR(255)    DEFAULT NULL,
    activa          TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_empresas_plan (plan_suscripcion),
    INDEX idx_empresas_activa (activa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- 2. USUARIOS  (cada usuario pertenece a una empresa)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    empresa_id      INT UNSIGNED    NOT NULL,
    nombre          VARCHAR(150)    NOT NULL,
    email           VARCHAR(255)    NOT NULL,
    password        VARCHAR(255)    NOT NULL COMMENT 'Hash bcrypt/argon2',
    rol             ENUM('admin','supervisor','operador')
                                    NOT NULL DEFAULT 'operador',
    activo          TINYINT(1)      NOT NULL DEFAULT 1,
    ultimo_login    DATETIME        DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuarios_email (email),
    INDEX idx_usuarios_empresa (empresa_id),
    INDEX idx_usuarios_rol (rol),
    CONSTRAINT fk_usuarios_empresa
        FOREIGN KEY (empresa_id) REFERENCES empresas (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- 3. INFRAESTRUCTURAS  (cada infraestructura pertenece a una empresa)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS infraestructuras (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    empresa_id      INT UNSIGNED    NOT NULL,
    nombre          VARCHAR(250)    NOT NULL,
    codigo_unico    VARCHAR(50)     NOT NULL COMMENT 'Código visible en campo (ej: TORRE-0421)',
    lat_teorica     DECIMAL(10,7)   NOT NULL COMMENT 'Latitud de referencia',
    lon_teorica     DECIMAL(10,7)   NOT NULL COMMENT 'Longitud de referencia',
    tipo            VARCHAR(100)    DEFAULT NULL COMMENT 'Tipo libre: torre, poste, puente…',
    descripcion     TEXT            DEFAULT NULL,
    activa          TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_infra_codigo (empresa_id, codigo_unico),
    INDEX idx_infra_empresa (empresa_id),
    INDEX idx_infra_coords (lat_teorica, lon_teorica),
    CONSTRAINT fk_infra_empresa
        FOREIGN KEY (empresa_id) REFERENCES empresas (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- 4. REGISTROS  (cada inspección / foto de campo)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS registros (
    id                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    infra_id            INT UNSIGNED    NOT NULL,
    usuario_id          INT UNSIGNED    NOT NULL,
    fecha               DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lat_real            DECIMAL(10,7)   NOT NULL COMMENT 'Latitud GPS real del operador',
    lon_real            DECIMAL(10,7)   NOT NULL COMMENT 'Longitud GPS real del operador',
    url_cloudinary      VARCHAR(512)    NOT NULL COMMENT 'URL de la imagen en Cloudinary',
    datos_tecnicos      JSON            DEFAULT NULL COMMENT 'Payload libre: temperatura, presión, notas…',
    estado_incidencia   ENUM('bajo','medio','critico')
                                        NOT NULL DEFAULT 'bajo',
    observaciones       TEXT            DEFAULT NULL,
    created_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_reg_infra (infra_id),
    INDEX idx_reg_usuario (usuario_id),
    INDEX idx_reg_fecha (fecha),
    INDEX idx_reg_estado (estado_incidencia),
    CONSTRAINT fk_reg_infra
        FOREIGN KEY (infra_id) REFERENCES infraestructuras (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_reg_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios (id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- VISTA: aislamiento multi-tenant
-- Útil para queries de admin donde se filtra siempre por empresa
-- -----------------------------------------------------------
CREATE OR REPLACE VIEW v_registros_empresa AS
SELECT
    r.id            AS registro_id,
    r.fecha,
    r.lat_real,
    r.lon_real,
    r.url_cloudinary,
    r.datos_tecnicos,
    r.estado_incidencia,
    r.observaciones,
    i.id            AS infra_id,
    i.nombre        AS infra_nombre,
    i.codigo_unico,
    i.lat_teorica,
    i.lon_teorica,
    u.id            AS usuario_id,
    u.nombre        AS usuario_nombre,
    u.email         AS usuario_email,
    e.id            AS empresa_id,
    e.nombre        AS empresa_nombre,
    e.plan_suscripcion
FROM registros r
    INNER JOIN infraestructuras i ON r.infra_id   = i.id
    INNER JOIN usuarios u         ON r.usuario_id = u.id
    INNER JOIN empresas e         ON i.empresa_id = e.id;

-- -----------------------------------------------------------
-- Datos de ejemplo (seed)
-- -----------------------------------------------------------
INSERT IGNORE INTO empresas (nombre, plan_suscripcion, email_contacto) VALUES
    ('Energía del Sur S.A.', 'professional', 'admin@energiasur.com'),
    ('Torres Norte SL',      'basic',        'info@torresnorte.es');

INSERT IGNORE INTO usuarios (empresa_id, nombre, email, password, rol) VALUES
    (1, 'Carlos Ruiz',   'carlos@energiasur.com',  '$2y$12$placeholder_hash_1', 'admin'),
    (1, 'Ana López',     'ana@energiasur.com',      '$2y$12$placeholder_hash_2', 'operador'),
    (2, 'Pedro García',  'pedro@torresnorte.es',    '$2y$12$placeholder_hash_3', 'admin');

INSERT IGNORE INTO infraestructuras (empresa_id, nombre, codigo_unico, lat_teorica, lon_teorica, tipo) VALUES
    (1, 'Torre Alta Tensión KM-42', 'TORRE-0042', 37.3890531, -5.9844589, 'torre_electrica'),
    (1, 'Subestación Río Verde',    'SUB-0012',   37.4012345, -5.9701234, 'subestacion'),
    (2, 'Poste Comunicaciones P-7', 'POSTE-0007', 43.2630126, -2.9349852, 'poste_telecom');

SET FOREIGN_KEY_CHECKS = 1;
