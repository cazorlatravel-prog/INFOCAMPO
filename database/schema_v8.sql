-- =====================================================================
-- INFOCAMPO SaaS — Schema v8: Login por teléfono para operadores
-- =====================================================================
-- Añade columna telefono a usuarios y hace email opcional,
-- permitiendo que operadores sin correo electrónico inicien sesión
-- con su número de teléfono + contraseña.
-- =====================================================================

SET NAMES utf8mb4;

-- Añadir columna teléfono
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS telefono VARCHAR(20) DEFAULT NULL AFTER email;

-- Hacer email opcional (operadores pueden no tener correo)
ALTER TABLE usuarios MODIFY email VARCHAR(255) DEFAULT NULL;

-- Índice único para teléfono (permite múltiples NULL)
-- Usar procedimiento para evitar error si el índice ya existe
SET @existe_idx = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND INDEX_NAME = 'uq_usuarios_telefono');
SET @sql_idx = IF(@existe_idx = 0,
    'ALTER TABLE usuarios ADD UNIQUE INDEX uq_usuarios_telefono (telefono)',
    'SELECT 1');
PREPARE stmt_idx FROM @sql_idx;
EXECUTE stmt_idx;
DEALLOCATE PREPARE stmt_idx;
