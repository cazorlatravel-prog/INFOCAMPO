-- =====================================================================
-- INFOCAMPO SaaS — Schema v5: Registro de subidas fallidas
-- =====================================================================
-- Tabla para registrar fotos que no se pudieron subir a Cloudinary.
-- Permite al admin ver qué fotos están pendientes y al operador
-- saber que no debe borrarlas de su galería.
-- =====================================================================

CREATE TABLE IF NOT EXISTS subidas_fallidas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    usuario_id INT UNSIGNED NOT NULL,
    infra_id INT UNSIGNED NOT NULL,
    nombre_archivo VARCHAR(255),
    estado_incidencia ENUM('bajo', 'medio', 'critico') DEFAULT 'bajo',
    tipo_foto ENUM('aleatorio', 'comparativo') DEFAULT 'aleatorio',
    motivo_error TEXT,
    intentos INT UNSIGNED DEFAULT 1,
    resuelta TINYINT(1) DEFAULT 0,
    fecha_fallo DATETIME DEFAULT CURRENT_TIMESTAMP,
    fecha_resolucion DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_empresa (empresa_id),
    INDEX idx_usuario (usuario_id),
    INDEX idx_resuelta (resuelta),
    FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    FOREIGN KEY (infra_id) REFERENCES infraestructuras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
