<?php
/**
 * INFOCAMPO SaaS - Interfaz del Operador de Campo
 *
 * Pantalla principal del operador para recogida de datos:
 * 1. Selección de infraestructura (buscar existente o crear nueva)
 * 2. Selección de unidad de obra
 * 3. Dos modos de fotos: Aleatorias y Comparativas (con ghosting)
 * 4. Watermark con coordenadas ETRS89, nombre infraestructura, fecha/hora Madrid
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';

// Parámetros del operador (pasados por URL o sesión)
$usuarioId = isset($_GET['user']) ? (int) $_GET['user'] : 0;
$empresaId = isset($_GET['empresa']) ? (int) $_GET['empresa'] : 0;

$pdo = getDB();

// Obtener nombre del usuario y empresa
$userName = 'Operador';
$empresaName = '';
if ($usuarioId > 0) {
    $stmt = $pdo->prepare("SELECT nombre FROM usuarios WHERE id = :id");
    $stmt->execute([':id' => $usuarioId]);
    $row = $stmt->fetch();
    if ($row) $userName = $row['nombre'];
}
if ($empresaId > 0) {
    $stmt = $pdo->prepare("SELECT nombre FROM empresas WHERE id = :id");
    $stmt->execute([':id' => $empresaId]);
    $row = $stmt->fetch();
    if ($row) $empresaName = $row['nombre'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>INFOCAMPO - Operador de Campo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="css/operador.css">
</head>
<body>
    <!-- ========================================================
         PANTALLA 1: FICHA DE VISITA
         ======================================================== -->
    <div id="screen-ficha" class="screen active">
        <div class="ficha-header">
            <div class="ficha-brand">
                <strong>INFOCAMPO</strong>
                <span><?= htmlspecialchars($empresaName) ?></span>
            </div>
            <div class="ficha-header-right">
                <div id="offline-indicator" class="offline-indicator online">
                    <div id="offline-dot" class="offline-dot online"></div>
                    <span id="offline-text">En línea</span>
                </div>
                <div class="ficha-user">
                    <i class="bi bi-person-circle"></i>
                    <span><?= htmlspecialchars($userName) ?></span>
                </div>
            </div>
        </div>

        <div class="ficha-body">
            <!-- Infraestructura -->
            <div class="ficha-field">
                <label><i class="bi bi-geo-alt"></i> Infraestructura</label>
                <div class="search-container">
                    <input type="text" id="infra-search" placeholder="Buscar o escribir nombre..."
                           autocomplete="off" spellcheck="false">
                    <div id="infra-results" class="search-results hidden"></div>
                </div>
                <input type="hidden" id="infra-id" value="">
                <div id="infra-selected" class="selected-badge hidden">
                    <span id="infra-selected-name"></span>
                    <button type="button" id="infra-clear" class="clear-btn"><i class="bi bi-x"></i></button>
                </div>
                <!-- Precache indicator + button -->
                <div id="precache-indicator" class="precache-indicator hidden">
                    <i class="bi bi-cloud-check"></i> Fotos precargadas
                </div>
                <button type="button" id="btn-precache" class="precache-btn">
                    <i class="bi bi-cloud-download"></i> Precargar fotos para modo offline
                </button>
            </div>

            <!-- Unidad de obra -->
            <div class="ficha-field">
                <label><i class="bi bi-tools"></i> Unidad de Obra</label>
                <select id="unidad-obra">
                    <option value="">-- Seleccionar unidad de obra --</option>
                </select>
            </div>

            <!-- Fecha -->
            <div class="ficha-field">
                <label><i class="bi bi-calendar3"></i> Fecha</label>
                <div class="fecha-display" id="fecha-display"></div>
            </div>

            <!-- Observaciones generales -->
            <div class="ficha-field">
                <label><i class="bi bi-chat-text"></i> Observaciones</label>
                <textarea id="observaciones-general" placeholder="Notas generales de la visita..." rows="2"></textarea>
            </div>

            <!-- Aviso: seleccionar infraestructura -->
            <div id="hint-select-infra" class="hint-box">
                <i class="bi bi-info-circle"></i>
                <span>Busca o crea una infraestructura arriba para habilitar las fotos</span>
            </div>

            <!-- Botones de fotos -->
            <div class="foto-buttons">
                <button type="button" id="btn-fotos-aleatorias" class="foto-btn foto-btn--aleatorio" disabled>
                    <div class="foto-btn-icon"><i class="bi bi-camera"></i></div>
                    <div class="foto-btn-text">
                        <strong>Fotos Aleatorias</strong>
                        <small>Fotos libres con coordenadas ETRS89</small>
                    </div>
                    <span class="foto-count" id="count-aleatorias">0</span>
                </button>

                <button type="button" id="btn-fotos-comparativas" class="foto-btn foto-btn--comparativo" disabled>
                    <div class="foto-btn-icon"><i class="bi bi-layers"></i></div>
                    <div class="foto-btn-text">
                        <strong>Fotos Comparativas</strong>
                        <small>Ghosting con foto anterior al 50%</small>
                    </div>
                    <span class="foto-count" id="count-comparativas">0</span>
                </button>

                <button type="button" id="btn-ver-mapa" class="foto-btn foto-btn--mapa">
                    <div class="foto-btn-icon"><i class="bi bi-map"></i></div>
                    <div class="foto-btn-text">
                        <strong>Ver Mapa de Visitas</strong>
                        <small>Ubicación de fotos anteriores</small>
                    </div>
                    <span class="foto-count"><i class="bi bi-chevron-right"></i></span>
                </button>
            </div>

            <!-- Barra de sincronización offline -->
            <div id="sync-bar" class="sync-bar hidden">
                <div class="sync-bar-info">
                    <i class="bi bi-cloud-arrow-up"></i>
                    <span><span id="sync-count">0</span> foto(s) pendiente(s) de subir</span>
                </div>
                <div class="sync-bar-actions">
                    <div class="sync-progress">
                        <div id="sync-progress-bar" class="sync-progress-fill"></div>
                    </div>
                    <span id="sync-progress-text" class="sync-progress-text"></span>
                    <button type="button" id="btn-manual-sync" class="sync-btn">
                        <i class="bi bi-arrow-repeat"></i> Sincronizar
                    </button>
                </div>
            </div>

            <!-- Galería de fotos tomadas -->
            <div id="gallery-section" class="hidden">
                <h3 class="gallery-title"><i class="bi bi-images"></i> Fotos de esta visita</h3>
                <div id="gallery-grid" class="gallery-grid"></div>
            </div>
        </div>
    </div>

    <!-- ========================================================
         PANTALLA 2: CÁMARA
         ======================================================== -->
    <div id="screen-camera" class="screen">
        <!-- Barra superior -->
        <div class="cam-topbar">
            <button type="button" id="btn-cam-back" class="cam-btn-back">
                <i class="bi bi-arrow-left"></i>
            </button>
            <div class="cam-info">
                <span id="cam-infra-name">--</span>
                <span id="cam-mode-badge" class="cam-mode-badge">ALEATORIO</span>
            </div>
            <span id="cam-time">--:--</span>
        </div>

        <!-- GPS Indicator -->
        <div class="cam-gps">
            <div class="cam-gps-dot" id="cam-gps-dot"></div>
            <span id="cam-gps-text">ETRS89: --</span>
        </div>

        <!-- Video feed -->
        <video id="cam-video" autoplay playsinline></video>

        <!-- Ghost overlay (comparative mode) -->
        <img id="cam-ghost" src="" alt="" class="cam-ghost">

        <!-- Canvas de captura (oculto) -->
        <canvas id="cam-capture" class="hidden-canvas"></canvas>

        <!-- Contador de fotos comparativas -->
        <div id="cam-seq-counter" class="cam-seq hidden">
            <span id="cam-seq-label">W1</span>
        </div>

        <!-- Controles inferiores -->
        <div class="cam-controls">
            <div class="cam-controls-left">
                <button type="button" id="btn-ghost-toggle" class="cam-ctrl hidden" title="Toggle Ghost">
                    <i class="bi bi-layers-half"></i>
                </button>
            </div>
            <button type="button" id="btn-shutter" class="cam-shutter">
                <div class="shutter-ring"><div class="shutter-inner"></div></div>
            </button>
            <div class="cam-controls-right">
                <button type="button" id="btn-load-prev" class="cam-ctrl hidden" title="Cargar fotos anteriores">
                    <i class="bi bi-clock-history"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================
         PANTALLA 3: PREVIEW (tras captura)
         ======================================================== -->
    <div id="screen-preview" class="screen">
        <canvas id="preview-canvas"></canvas>
        <div class="preview-bar">
            <button type="button" id="btn-retake" class="preview-btn preview-btn--secondary">
                <i class="bi bi-arrow-repeat"></i> Repetir
            </button>
            <div class="preview-info">
                <span id="preview-filename"></span>
            </div>
            <button type="button" id="btn-accept" class="preview-btn preview-btn--primary">
                <i class="bi bi-check-lg"></i> Aceptar
            </button>
        </div>
    </div>

    <!-- ========================================================
         PANTALLA 4: MAPA DE VISITAS
         ======================================================== -->
    <div id="screen-mapa" class="screen">
        <div class="mapa-topbar">
            <button type="button" id="btn-mapa-back" class="cam-btn-back">
                <i class="bi bi-arrow-left"></i>
            </button>
            <div class="mapa-title">
                <strong>Mapa de Visitas</strong>
                <span id="mapa-subtitle">Todas las infraestructuras</span>
            </div>
            <div style="width:40px;"></div>
        </div>
        <div id="op-map" class="op-map"></div>

        <!-- Panel lateral de detalle -->
        <div id="mapa-detail-panel" class="mapa-detail-panel hidden">
            <div class="mapa-detail-header">
                <button type="button" id="btn-close-detail" class="modal-close"><i class="bi bi-x-lg"></i></button>
                <h4 id="detail-infra-name">--</h4>
                <code id="detail-infra-code">--</code>
            </div>
            <div class="mapa-detail-body" id="mapa-detail-body">
                <!-- Se rellena dinámicamente -->
            </div>
            <div class="mapa-detail-actions">
                <button type="button" id="btn-detail-aleatorio" class="mapa-action-btn mapa-action--aleatorio">
                    <i class="bi bi-camera"></i> Nueva Foto Aleatoria
                </button>
                <button type="button" id="btn-detail-comparativo" class="mapa-action-btn mapa-action--comparativo">
                    <i class="bi bi-layers"></i> Nueva Foto Comparativa
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================
         OVERLAY: Subiendo foto
         ======================================================== -->
    <div id="upload-overlay" class="overlay hidden">
        <div class="spinner"></div>
        <span>Subiendo foto...</span>
    </div>

    <!-- ========================================================
         MODAL: Seleccionar foto anterior para ghosting
         ======================================================== -->
    <div id="modal-prev-photos" class="modal-overlay hidden">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="bi bi-clock-history"></i> Fotos comparativas anteriores</h3>
                <button type="button" id="btn-close-prev" class="modal-close"><i class="bi bi-x-lg"></i></button>
            </div>
            <div id="prev-photos-grid" class="prev-photos-grid">
                <p class="text-muted">Cargando...</p>
            </div>
            <div class="modal-footer">
                <button type="button" id="btn-skip-prev" class="preview-btn preview-btn--secondary">
                    Empezar sin ghost
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================
         MODAL: Precargando fotos
         ======================================================== -->
    <div id="precache-modal" class="modal-overlay hidden">
        <div class="modal-content precache-modal-content">
            <div class="modal-header">
                <h3><i class="bi bi-cloud-download"></i> Precargando fotos</h3>
                <button type="button" id="btn-close-precache" class="modal-close"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="precache-body">
                <div class="precache-progress">
                    <div id="precache-progress-bar" class="precache-progress-fill"></div>
                </div>
                <p id="precache-progress-text" class="precache-text">Preparando...</p>
            </div>
        </div>
    </div>

    <!-- ========================================================
         NOTIFICACIÓN: Resultado de sincronización
         ======================================================== -->
    <div id="sync-notification" class="sync-notification hidden">
        <span class="sync-notif-text"></span>
        <button type="button" id="sync-notif-close" class="sync-notif-close"><i class="bi bi-x"></i></button>
    </div>

    <!-- Config -->
    <script>
        window.INFOCAMPO = {
            usuarioId: <?= $usuarioId ?>,
            empresaId: <?= $empresaId ?>,
            userName: <?= json_encode($userName) ?>,
            empresaName: <?= json_encode($empresaName) ?>,
            endpoints: {
                upload: 'subir.php',
                infraestructuras: 'api/infraestructuras.php',
                unidadesObra: 'api/unidades_obra.php',
                fotosComparativas: 'api/fotos_comparativas.php',
                registrosMapa: 'api/registros_mapa.php',
            }
        };
    </script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="js/offline.js"></script>
    <script src="js/operador.js"></script>
</body>
</html>
