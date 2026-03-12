-- =============================================
-- INFOCAMPO SaaS – Schema v19: Performance indexes
-- =============================================
-- Añade índices para optimizar las consultas más frecuentes
-- y reducir el consumo de recursos del hosting.

-- Índice compuesto para consultas de registros por infraestructura + fecha
CREATE INDEX IF NOT EXISTS idx_registros_infra_fecha
    ON registros (infra_id, fecha DESC);

-- Índice para filtrar registros por estado_incidencia (antes/durante/despues)
CREATE INDEX IF NOT EXISTS idx_registros_infra_estado
    ON registros (infra_id, estado_incidencia);

-- Índice para consultas de registros por usuario
CREATE INDEX IF NOT EXISTS idx_registros_usuario_fecha
    ON registros (usuario_id, fecha DESC);

-- Índice para búsqueda de infraestructuras por empresa + activa
CREATE INDEX IF NOT EXISTS idx_infra_empresa_activa
    ON infraestructuras (empresa_id, activa);

-- Índice para filtrar infraestructuras por provincia/municipio
CREATE INDEX IF NOT EXISTS idx_infra_provincia_municipio
    ON infraestructuras (empresa_id, provincia, municipio);

-- Índice para usuarios por empresa + rol
CREATE INDEX IF NOT EXISTS idx_usuarios_empresa_rol
    ON usuarios (empresa_id, rol, activo);

-- Índice para valores_campo por registro
CREATE INDEX IF NOT EXISTS idx_valores_campo_registro
    ON valores_campo (registro_id);
