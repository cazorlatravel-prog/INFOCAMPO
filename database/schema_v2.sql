-- ============================================================
-- INFOCAMPO SaaS - Migración v2
-- Super Administrador + Licencias + Campos Dinámicos
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- -----------------------------------------------------------
-- 1. Modificar tabla USUARIOS: añadir rol superadmin
-- -----------------------------------------------------------
ALTER TABLE usuarios
    MODIFY COLUMN rol ENUM('superadmin','admin','supervisor','operador')
    NOT NULL DEFAULT 'operador';

-- -----------------------------------------------------------
-- 2. Modificar tabla EMPRESAS: añadir campos de licencia
-- -----------------------------------------------------------
ALTER TABLE empresas
    ADD COLUMN licencia_inicio DATE DEFAULT NULL AFTER activa,
    ADD COLUMN licencia_fin DATE DEFAULT NULL AFTER licencia_inicio,
    ADD COLUMN max_usuarios INT UNSIGNED NOT NULL DEFAULT 10 AFTER licencia_fin,
    ADD COLUMN max_infraestructuras INT UNSIGNED NOT NULL DEFAULT 50 AFTER max_usuarios,
    ADD COLUMN logo_url VARCHAR(512) DEFAULT NULL AFTER max_infraestructuras;

-- -----------------------------------------------------------
-- 3. Tabla CAMPOS_FORMULARIO (campos dinámicos por empresa)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS campos_formulario (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    empresa_id      INT UNSIGNED    NOT NULL,
    nombre          VARCHAR(150)    NOT NULL COMMENT 'Nombre visible del campo',
    slug            VARCHAR(100)    NOT NULL COMMENT 'Identificador único snake_case',
    tipo            ENUM('texto','numero','select','checkbox','textarea','fecha')
                                    NOT NULL DEFAULT 'texto',
    opciones        JSON            DEFAULT NULL COMMENT 'Para select: ["Opción A","Opción B"]',
    obligatorio     TINYINT(1)      NOT NULL DEFAULT 0,
    orden           INT UNSIGNED    NOT NULL DEFAULT 0,
    activo          TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_campo_empresa_slug (empresa_id, slug),
    INDEX idx_campo_empresa (empresa_id),
    INDEX idx_campo_orden (orden),
    CONSTRAINT fk_campo_empresa
        FOREIGN KEY (empresa_id) REFERENCES empresas (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- 4. Tabla VALORES_CAMPO (valores de campos dinámicos por registro)
-- -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS valores_campo (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    registro_id     INT UNSIGNED    NOT NULL,
    campo_id        INT UNSIGNED    NOT NULL,
    valor           TEXT            DEFAULT NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_valor_registro_campo (registro_id, campo_id),
    INDEX idx_valor_registro (registro_id),
    INDEX idx_valor_campo (campo_id),
    CONSTRAINT fk_valor_registro
        FOREIGN KEY (registro_id) REFERENCES registros (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_valor_campo
        FOREIGN KEY (campo_id) REFERENCES campos_formulario (id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------
-- 5. Seed: Super Administrador por defecto
--    Password: InfoCampo2024! (bcrypt hash)
-- -----------------------------------------------------------
INSERT INTO empresas (id, nombre, plan_suscripcion, email_contacto, activa, licencia_inicio, licencia_fin, max_usuarios, max_infraestructuras)
VALUES (9999, 'INFOCAMPO Platform', 'enterprise', 'admin@infocampo.app', 1, '2024-01-01', '2099-12-31', 999, 9999)
ON DUPLICATE KEY UPDATE nombre = VALUES(nombre);

INSERT INTO usuarios (empresa_id, nombre, email, password, rol, activo)
VALUES (9999, 'Super Administrador', 'superadmin@infocampo.app',
        '$2y$12$LJ3m4ys3Gz8y5N9xKv.8XOdFmDkjQ4vBfE5J7g8yN2wRqH1sMzKXi',
        'superadmin', 1)
ON DUPLICATE KEY UPDATE rol = 'superadmin';

SET FOREIGN_KEY_CHECKS = 1;
