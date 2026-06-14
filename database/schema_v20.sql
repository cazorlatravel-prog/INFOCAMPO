-- ============================================================
-- INFOCAMPO SaaS — Migración v20
-- Token de idempotencia para subidas de fotos
--
-- Añade la columna `client_token` a `registros` para deduplicar
-- subidas reintentadas (cola offline / fallos de red), evitando
-- registros duplicados de la misma captura.
-- ============================================================

ALTER TABLE registros
    ADD COLUMN IF NOT EXISTS client_token VARCHAR(64) DEFAULT NULL AFTER nombre_archivo;

-- Índice único para garantizar que un mismo token solo crea un registro.
-- (En re-ejecución lanza error 1061, tolerado por migrate.php como inofensivo.)
ALTER TABLE registros
    ADD UNIQUE INDEX uq_registros_client_token (client_token);
