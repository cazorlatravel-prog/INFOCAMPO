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

// Parámetros del operador (URL > sesión > 0)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$usuarioId = isset($_GET['user']) ? (int) $_GET['user'] : (int) ($_SESSION['user_id'] ?? 0);
$empresaId = isset($_GET['empresa']) ? (int) $_GET['empresa'] : (int) ($_SESSION['empresa_id'] ?? 0);

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

// Iniciales del usuario para el avatar
$initials = '';
$nameParts = explode(' ', trim($userName));
foreach (array_slice($nameParts, 0, 2) as $part) {
    if (mb_strlen($part) > 0) $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
if ($initials === '') $initials = 'OP';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#4f6ef7">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FotoGPS">
    <title>FotoGPS.app - Operador de Campo</title>
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="css/operador.css">
</head>
<body>
    <!-- ========================================================
         PANTALLA 1: FICHA DE VISITA
         ======================================================== -->
    <div id="screen-ficha" class="screen active">
        <!-- Header -->
        <div class="ficha-header">
            <div class="ficha-brand">
                <div class="brand-logo">
                    <i class="bi bi-geo-alt-fill"></i>
                </div>
                <div class="brand-text">
                    <strong>FotoGPS</strong>
                    <span><?= htmlspecialchars($empresaName) ?></span>
                </div>
            </div>
            <div class="ficha-header-right">
                <div id="offline-indicator" class="offline-indicator online">
                    <div id="offline-dot" class="offline-dot online"></div>
                    <span id="offline-text">En linea</span>
                </div>
                <div class="user-avatar" title="<?= htmlspecialchars($userName) ?>">
                    <?= $initials ?>
                </div>
            </div>
        </div>

        <!-- Body -->
        <div class="ficha-body">

            <!-- Banner: Instalar App -->
            <div id="install-banner" class="install-banner hidden">
                <div class="install-banner-content">
                    <div class="install-banner-icon">
                        <i class="bi bi-download"></i>
                    </div>
                    <div class="install-banner-text">
                        <strong>Instalar FotoGPS</strong>
                        <small>Acceso directo desde tu pantalla de inicio</small>
                    </div>
                </div>
                <div class="install-banner-actions">
                    <button type="button" id="btn-install-app" class="install-btn">
                        <i class="bi bi-phone-fill"></i> Instalar App
                    </button>
                    <button type="button" id="btn-install-dismiss" class="install-dismiss">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
            </div>

            <!-- Card: Ubicacion -->
            <div class="card">
                <div class="card-label">
                    <i class="bi bi-pin-map-fill"></i> Ubicacion
                </div>
                <div class="filter-row">
                    <select id="filter-provincia" class="input-field">
                        <option value="">Todas las provincias</option>
                    </select>
                    <select id="filter-municipio" class="input-field" disabled>
                        <option value="">Todos los municipios</option>
                    </select>
                </div>
            </div>

            <!-- Card: Infraestructura -->
            <div class="card">
                <div class="card-label">
                    <i class="bi bi-building"></i> Infraestructura
                </div>
                <div class="search-container">
                    <div class="search-input-wrap">
                        <i class="bi bi-search search-icon-left"></i>
                        <input type="text" id="infra-search" placeholder="Buscar o crear infraestructura..."
                               autocomplete="off" spellcheck="false" class="input-field input-with-icon">
                    </div>
                    <div id="infra-results" class="search-results hidden"></div>
                </div>
                <input type="hidden" id="infra-id" value="">
                <div id="infra-selected" class="selected-badge hidden">
                    <div class="selected-badge-left">
                        <i class="bi bi-check-circle-fill"></i>
                        <span id="infra-selected-name"></span>
                    </div>
                    <button type="button" id="infra-clear" class="clear-btn">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>
                <div id="precache-indicator" class="precache-indicator hidden">
                    <i class="bi bi-cloud-check"></i> Fotos precargadas
                </div>
                <button type="button" id="btn-precache" class="precache-btn">
                    <i class="bi bi-cloud-download"></i> Precargar fotos offline
                </button>
            </div>

            <!-- Card: Unidad de Obra -->
            <div class="card">
                <div class="card-label">
                    <i class="bi bi-tools"></i> Unidad de Obra
                </div>
                <select id="unidad-obra" class="input-field">
                    <option value="">Seleccionar unidad de obra</option>
                </select>
            </div>

            <!-- Card: Fecha + Observaciones -->
            <div class="card">
                <div class="card-row">
                    <div class="card-row-item">
                        <div class="card-label"><i class="bi bi-calendar3"></i> Fecha</div>
                        <div class="fecha-display" id="fecha-display"></div>
                    </div>
                </div>
                <div class="card-separator"></div>
                <div class="card-label"><i class="bi bi-chat-text"></i> Observaciones</div>
                <textarea id="observaciones-general" placeholder="Notas generales de la visita..." rows="2" class="input-field input-textarea"></textarea>
            </div>

            <!-- Hint -->
            <div id="hint-select-infra" class="hint-box">
                <i class="bi bi-info-circle"></i>
                <span>Selecciona una infraestructura para habilitar las fotos</span>
            </div>

            <!-- Botones de accion: Fotos -->
            <div class="foto-buttons">
                <button type="button" id="btn-fotos-aleatorias" class="foto-btn foto-btn--aleatorio" disabled>
                    <div class="foto-btn-icon">
                        <i class="bi bi-camera-fill"></i>
                    </div>
                    <div class="foto-btn-text">
                        <strong>Fotos Aleatorias</strong>
                        <small>Fotos libres con GPS ETRS89</small>
                    </div>
                    <span class="foto-count" id="count-aleatorias">0</span>
                </button>

                <button type="button" id="btn-fotos-comparativas" class="foto-btn foto-btn--comparativo" disabled>
                    <div class="foto-btn-icon">
                        <i class="bi bi-layers-fill"></i>
                    </div>
                    <div class="foto-btn-text">
                        <strong>Fotos Comparativas</strong>
                        <small>Ghosting con foto anterior</small>
                    </div>
                    <span class="foto-count" id="count-comparativas">0</span>
                </button>

                <button type="button" id="btn-ver-mapa" class="foto-btn foto-btn--mapa">
                    <div class="foto-btn-icon">
                        <i class="bi bi-map-fill"></i>
                    </div>
                    <div class="foto-btn-text">
                        <strong>Mapa de Visitas</strong>
                        <small>Ver ubicaciones anteriores</small>
                    </div>
                    <span class="foto-count"><i class="bi bi-chevron-right"></i></span>
                </button>
            </div>

            <!-- Sync bar -->
            <div id="sync-bar" class="sync-bar hidden">
                <div class="sync-bar-info">
                    <i class="bi bi-cloud-arrow-up"></i>
                    <span><span id="sync-count">0</span> foto(s) pendiente(s)</span>
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

            <!-- Galeria -->
            <div id="gallery-section" class="hidden">
                <h3 class="gallery-title"><i class="bi bi-images"></i> Fotos de esta visita</h3>
                <div id="gallery-grid" class="gallery-grid"></div>
            </div>
        </div>
    </div>

    <!-- ========================================================
         PANTALLA 2: CAMARA
         ======================================================== -->
    <div id="screen-camera" class="screen">
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

        <div class="cam-gps">
            <div class="cam-gps-dot" id="cam-gps-dot"></div>
            <span id="cam-gps-text">ETRS89: --</span>
        </div>

        <video id="cam-video" autoplay playsinline></video>
        <img id="cam-ghost" src="" alt="" class="cam-ghost">
        <canvas id="cam-capture" class="hidden-canvas"></canvas>

        <div id="cam-seq-counter" class="cam-seq hidden">
            <span id="cam-seq-label">W1</span>
        </div>

        <div class="cam-situacion">
            <button type="button" id="btn-situacion" class="cam-situacion-btn" title="Cambiar situación">
                <i class="bi bi-flag"></i> <span id="situacion-label">ANTES</span>
            </button>
        </div>

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
         PANTALLA 3: PREVIEW
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
         PANTALLA 4: MAPA
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

        <!-- Navigation overlay -->
        <div id="nav-overlay" class="nav-overlay hidden">
            <div class="nav-info">
                <div class="nav-icon"><i class="bi bi-cursor-fill"></i></div>
                <div class="nav-details">
                    <span class="nav-label">Navegando a</span>
                    <strong id="nav-target-name">--</strong>
                </div>
                <div class="nav-dist-box">
                    <span id="nav-distance">--</span>
                </div>
            </div>
            <button type="button" id="btn-stop-nav" class="nav-stop-btn">
                <i class="bi bi-x-circle-fill"></i> Detener
            </button>
        </div>

        <!-- Botón Volver fijo abajo -->
        <button type="button" id="btn-mapa-volver" class="mapa-volver-btn">
            <i class="bi bi-arrow-left-circle-fill"></i> Volver a Toma de Datos
        </button>

        <div id="mapa-detail-panel" class="mapa-detail-panel hidden">
            <div class="mapa-detail-header">
                <button type="button" id="btn-close-detail" class="modal-close"><i class="bi bi-x-lg"></i></button>
                <h4 id="detail-infra-name">--</h4>
                <code id="detail-infra-code">--</code>
                <span id="detail-infra-distance" class="detail-distance hidden"></span>
            </div>
            <div class="mapa-detail-body" id="mapa-detail-body"></div>
            <div class="mapa-detail-actions">
                <button type="button" id="btn-detail-navegar" class="mapa-action-btn mapa-action--navegar">
                    <i class="bi bi-cursor-fill"></i> Ir a esta ubicación
                </button>
                <button type="button" id="btn-detail-aleatorio" class="mapa-action-btn mapa-action--aleatorio">
                    <i class="bi bi-camera"></i> Foto
                </button>
                <button type="button" id="btn-detail-comparativo" class="mapa-action-btn mapa-action--comparativo">
                    <i class="bi bi-layers"></i> Comparativa
                </button>
            </div>
        </div>
    </div>

    <!-- Overlays & Modals -->
    <div id="upload-overlay" class="overlay hidden">
        <div class="spinner"></div>
        <span>Subiendo foto...</span>
    </div>

    <div id="modal-prev-photos" class="modal-overlay hidden">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="bi bi-clock-history"></i> Fotos anteriores</h3>
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

    <div id="precache-modal" class="modal-overlay hidden">
        <div class="modal-content precache-modal-content">
            <div class="modal-header">
                <h3><i class="bi bi-cloud-download"></i> Precargando</h3>
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

    <div id="sync-notification" class="sync-notification hidden">
        <span class="sync-notif-text"></span>
        <button type="button" id="sync-notif-close" class="sync-notif-close"><i class="bi bi-x"></i></button>
    </div>

    <!-- Banner persistente de cola offline -->
    <div id="offline-queue-banner" class="offline-queue-banner hidden">
        <div class="oq-banner-left">
            <div class="oq-banner-icon">
                <i class="bi bi-cloud-arrow-up"></i>
            </div>
            <div class="oq-banner-info">
                <strong id="oq-banner-count">0</strong> <span id="oq-banner-label">fotos pendientes</span>
                <div id="oq-banner-status" class="oq-banner-status">Esperando conexión...</div>
            </div>
        </div>
        <div class="oq-banner-right">
            <div id="oq-banner-progress" class="oq-banner-progress" style="display:none;">
                <div id="oq-banner-progress-fill" class="oq-banner-progress-fill"></div>
            </div>
            <button type="button" id="oq-banner-sync" class="oq-banner-btn" onclick="InfocampoOffline.syncQueue()" title="Sincronizar ahora">
                <i class="bi bi-arrow-repeat"></i>
            </button>
        </div>
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
                capasKml: 'api/capas_kml.php',
            }
        };
    </script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="js/offline.js"></script>
    <script src="js/operador.js"></script>
</body>
</html>
