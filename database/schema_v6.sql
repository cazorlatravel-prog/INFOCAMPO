-- =============================================
-- INFOCAMPO SaaS — Migración v6
-- Cambiar "Incidencia" (bajo/medio/critico)
-- por "Situación" (antes/durante/despues)
-- =============================================

-- 1. Cambiar ENUM de estado_incidencia en registros
ALTER TABLE registros
    MODIFY COLUMN estado_incidencia ENUM('bajo','medio','critico','antes','durante','despues')
    NOT NULL DEFAULT 'antes';

-- 2. Migrar datos existentes
UPDATE registros SET estado_incidencia = 'antes'   WHERE estado_incidencia = 'bajo';
UPDATE registros SET estado_incidencia = 'durante'  WHERE estado_incidencia = 'medio';
UPDATE registros SET estado_incidencia = 'despues'  WHERE estado_incidencia = 'critico';

-- 3. Quitar valores antiguos del ENUM
ALTER TABLE registros
    MODIFY COLUMN estado_incidencia ENUM('antes','durante','despues')
    NOT NULL DEFAULT 'antes';

-- 4. Actualizar subidas_fallidas si tiene el mismo campo
ALTER TABLE subidas_fallidas
    MODIFY COLUMN estado_incidencia ENUM('bajo','medio','critico','antes','durante','despues')
    NOT NULL DEFAULT 'antes';

UPDATE subidas_fallidas SET estado_incidencia = 'antes'   WHERE estado_incidencia = 'bajo';
UPDATE subidas_fallidas SET estado_incidencia = 'durante'  WHERE estado_incidencia = 'medio';
UPDATE subidas_fallidas SET estado_incidencia = 'despues'  WHERE estado_incidencia = 'critico';

ALTER TABLE subidas_fallidas
    MODIFY COLUMN estado_incidencia ENUM('antes','durante','despues')
    NOT NULL DEFAULT 'antes';
