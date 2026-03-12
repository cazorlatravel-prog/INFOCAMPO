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

// Si no hay usuario/empresa válidos, redirigir al login
if ($usuarioId <= 0 || $empresaId <= 0) {
    header('Location: /login.php');
    exit;
}

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
$formatoNombreFoto = 1;
$opMostrarEmpresa = 0;
$opMostrarInfra = 0;
$opMostrarSituacion = 0;
$opMostrarMapa = 0;
$opMostrarCapasInfra = 0;
$opMapaZoom = 9;
$wmFecha = 1; $wmCoordenadas = 1; $wmOrientacion = 1; $wmUbicacion = 1; $wmPais = 1; $wmBrujula = 1;
$wmCodigoInfra = 0; $wmSituacion = 0; $wmTipoFoto = 0;
$wmMapa = 0; $wmMapaZoom = 15; $wmMapaTamano = 2; $wmTextoTamano = 2;
if ($empresaId > 0) {
    $stmt = $pdo->prepare("SELECT nombre, formato_nombre_foto, op_mostrar_empresa, op_mostrar_infraestructura, op_mostrar_situacion, op_mostrar_mapa, op_mostrar_capas_infra, op_mapa_zoom, wm_mostrar_fecha, wm_mostrar_coordenadas, wm_mostrar_orientacion, wm_mostrar_ubicacion, wm_mostrar_pais, wm_mostrar_brujula, wm_mostrar_codigo_infra, wm_mostrar_situacion, wm_mostrar_tipo_foto, wm_mostrar_mapa, wm_mapa_zoom, wm_mapa_tamano, wm_texto_tamano FROM empresas WHERE id = :id");
    $stmt->execute([':id' => $empresaId]);
    $row = $stmt->fetch();
    if ($row) {
        $empresaName = $row['nombre'];
        $formatoNombreFoto = (int) ($row['formato_nombre_foto'] ?? 1);
        $opMostrarEmpresa = (int) ($row['op_mostrar_empresa'] ?? 0);
        $opMostrarInfra = (int) ($row['op_mostrar_infraestructura'] ?? 0);
        $opMostrarSituacion = (int) ($row['op_mostrar_situacion'] ?? 0);
        $opMostrarMapa = (int) ($row['op_mostrar_mapa'] ?? 0);
        $opMostrarCapasInfra = (int) ($row['op_mostrar_capas_infra'] ?? 0);
        $opMapaZoom = (int) ($row['op_mapa_zoom'] ?? 9);
        $wmFecha = (int) ($row['wm_mostrar_fecha'] ?? 1);
        $wmCoordenadas = (int) ($row['wm_mostrar_coordenadas'] ?? 1);
        $wmOrientacion = (int) ($row['wm_mostrar_orientacion'] ?? 1);
        $wmUbicacion = (int) ($row['wm_mostrar_ubicacion'] ?? 1);
        $wmPais = (int) ($row['wm_mostrar_pais'] ?? 1);
        $wmBrujula = (int) ($row['wm_mostrar_brujula'] ?? 1);
        $wmCodigoInfra = (int) ($row['wm_mostrar_codigo_infra'] ?? 0);
        $wmSituacion = (int) ($row['wm_mostrar_situacion'] ?? 0);
        $wmTipoFoto = (int) ($row['wm_mostrar_tipo_foto'] ?? 0);
        $wmMapa = (int) ($row['wm_mostrar_mapa'] ?? 0);
        $wmMapaZoom = (int) ($row['wm_mapa_zoom'] ?? 15);
        $wmMapaTamano = (int) ($row['wm_mapa_tamano'] ?? 2);
        $wmTextoTamano = (int) ($row['wm_texto_tamano'] ?? 2);
    }
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
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
        <!-- Banner: Instalar App (sticky top) -->
        <div id="install-banner" class="install-banner hidden">
            <div class="install-banner-inner">
                <i class="bi bi-phone-fill install-banner-pulse"></i>
                <span class="install-banner-label"><strong>Instala FotoGPS</strong> en tu movil</span>
                <button type="button" id="btn-install-app" class="install-btn">
                    <i class="bi bi-download"></i> Instalar
                </button>
                <button type="button" id="btn-install-dismiss" class="install-dismiss" aria-label="Cerrar">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>

        <!-- Header -->
        <div class="ficha-header">
            <div class="ficha-brand">
                <div class="brand-logo">
                    <i class="bi bi-geo-alt-fill"></i>
                </div>
                <div class="brand-text">
                    <strong>FotoGPS</strong>
                    <?php if ($opMostrarEmpresa): ?>
                    <span><?= htmlspecialchars($empresaName) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="ficha-header-right">
                <div id="offline-indicator" class="offline-indicator online">
                    <div id="offline-dot" class="offline-dot online"></div>
                    <span id="offline-text">En linea</span>
                </div>
                <button type="button" class="user-avatar" id="btn-user-menu" title="<?= htmlspecialchars($userName) ?>" aria-label="Menu de usuario">
                    <?= $initials ?>
                </button>
                <div id="user-menu-dropdown" class="user-menu-dropdown hidden">
                    <div class="user-menu-header">
                        <strong><?= htmlspecialchars($userName) ?></strong>
                        <small><?= htmlspecialchars($empresaName) ?></small>
                    </div>
                    <button type="button" id="btn-install-menu" class="user-menu-item" style="display:none;" onclick="triggerInstallFromMenu()">
                        <i class="bi bi-download"></i> Instalar App
                    </button>
                    <a href="/login.php?logout=1" class="user-menu-item user-menu-logout">
                        <i class="bi bi-box-arrow-left"></i> Cerrar sesion
                    </a>
                </div>
            </div>
        </div>

        <!-- Body -->
        <div class="ficha-body">

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
                    <select id="filter-monte" class="input-field">
                        <option value="">Todos los montes</option>
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

            <!-- Card: Tipo de Trabajo -->
            <div class="card">
                <div class="card-label">
                    <i class="bi bi-briefcase"></i> Tipo de Trabajo
                </div>
                <select id="tipo-trabajo" class="input-field">
                    <option value="">Seleccionar tipo de trabajo</option>
                </select>
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

            <!-- Card: Campos dinámicos del formulario -->
            <div class="card" id="dynamic-fields-card" style="display:none;">
                <div class="card-label"><i class="bi bi-ui-checks-grid"></i> Datos adicionales</div>
                <div id="dynamic-fields"></div>
            </div>

            <!-- Card: Situación de la obra -->
            <div class="card" id="card-situacion" <?= !$opMostrarSituacion ? 'style="display:none;"' : '' ?>>
                <div class="card-label"><i class="bi bi-flag-fill"></i> Situación de la obra</div>
                <div class="situacion-selector" id="situacion-selector">
                    <button type="button" class="situacion-option active" data-sit="0">
                        <i class="bi bi-clock"></i> Antes
                    </button>
                    <button type="button" class="situacion-option" data-sit="1">
                        <i class="bi bi-exclamation-triangle"></i> Durante
                    </button>
                    <button type="button" class="situacion-option" data-sit="2">
                        <i class="bi bi-check-circle"></i> Después
                    </button>
                </div>
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

                <button type="button" id="btn-mis-visitas" class="foto-btn foto-btn--visitas">
                    <div class="foto-btn-icon">
                        <i class="bi bi-journal-text"></i>
                    </div>
                    <div class="foto-btn-text">
                        <strong>Mis Visitas</strong>
                        <small>Ver y editar registros anteriores</small>
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

        <!-- Botón Guardar Visita (sin foto) — visible cuando hay infra seleccionada -->
        <div id="guardar-visita-sin-foto-section" class="guardar-visita-fixed hidden" style="bottom:72px;">
            <button type="button" id="btn-guardar-visita-sin-foto" class="btn-guardar-visita-sin-foto" style="width:100%;">
                <i class="bi bi-save-fill"></i> Guardar visita
            </button>
        </div>

        <!-- Botón Finalizar Visita (fijo abajo, con fotos) -->
        <div id="guardar-visita-section" class="guardar-visita-fixed hidden">
            <div style="display:flex;gap:8px;width:100%;">
                <button type="button" id="btn-guardar-visita" class="btn-finalizar-visita" style="flex:1;">
                    <i class="bi bi-check-circle-fill"></i> Finalizar visita
                </button>
                <button type="button" id="btn-waypoints-ficha" class="btn-finalizar-visita hidden"
                    style="flex:none;background:#22c55e;padding:0 16px;" title="Descargar waypoints GPX">
                    <i class="bi bi-geo-alt"></i>
                </button>
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

        <!-- Ghost opacity slider -->
        <div id="ghost-opacity-bar" class="ghost-opacity-bar hidden">
            <i class="bi bi-eye-slash" style="font-size:12px;opacity:0.7;"></i>
            <input type="range" id="ghost-opacity-slider" min="0" max="100" value="50" step="5"
                   class="ghost-opacity-slider" title="Transparencia ghost">
            <i class="bi bi-eye" style="font-size:12px;opacity:0.7;"></i>
            <span id="ghost-opacity-value" class="ghost-opacity-value">50%</span>
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
         PANTALLA 3: PREVIEW + ANOTACIÓN
         ======================================================== -->
    <div id="screen-preview" class="screen">
        <canvas id="preview-canvas"></canvas>
        <div id="annotation-toolbar" class="annotation-toolbar hidden">
            <div id="annotation-hint" class="annotation-hint">
                <i class="bi bi-hand-index"></i> Toca la foto para señalar un punto
            </div>
            <div id="annotation-input-wrap" class="annotation-input-wrap hidden">
                <i class="bi bi-exclamation-triangle-fill annotation-warning-icon"></i>
                <input type="text" id="annotation-text" placeholder="¿Qué quieres señalar?" maxlength="80" class="annotation-input">
                <button type="button" id="btn-annotation-clear" class="annotation-clear-btn" title="Borrar anotación">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div id="annotation-size-wrap" class="annotation-size-wrap hidden">
                <i class="bi bi-circle annotation-size-icon"></i>
                <input type="range" id="annotation-size" min="1" max="10" value="4" step="1" class="annotation-size-slider">
                <i class="bi bi-circle annotation-size-icon annotation-size-icon--lg"></i>
            </div>
        </div>
        <div class="preview-bar">
            <button type="button" id="btn-retake" class="preview-btn preview-btn--secondary">
                <i class="bi bi-arrow-repeat"></i> Repetir
            </button>
            <button type="button" id="btn-annotate" class="preview-btn preview-btn--annotate">
                <i class="bi bi-circle"></i> Anotar
            </button>
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
            <button type="button" id="btn-mapa-search-toggle" class="cam-btn-back" title="Buscar infraestructura" style="font-size:1rem;">
                <i class="bi bi-search"></i>
            </button>
        </div>

        <!-- Buscador de infraestructuras en mapa -->
        <div id="mapa-search-bar" class="mapa-search-bar hidden">
            <div class="mapa-search-input-wrap">
                <i class="bi bi-search"></i>
                <input type="text" id="mapa-search-input" placeholder="Buscar infraestructura..." autocomplete="off" spellcheck="false">
                <button type="button" id="btn-mapa-search-close" class="mapa-search-close">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <div id="mapa-search-results" class="mapa-search-results hidden"></div>
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

    <!-- ========================================================
         PANTALLA 5: MIS VISITAS
         ======================================================== -->
    <div id="screen-visitas" class="screen">
        <div class="visitas-topbar">
            <button type="button" id="btn-visitas-back" class="cam-btn-back">
                <i class="bi bi-arrow-left"></i>
            </button>
            <div class="visitas-title">
                <strong>Mis Visitas</strong>
                <span>Registros anteriores</span>
            </div>
            <div style="width:40px;"></div>
        </div>
        <div class="visitas-body" id="visitas-body">
            <div class="visitas-loading">
                <div class="spinner"></div>
                <span>Cargando visitas...</span>
            </div>
        </div>
        <div class="visitas-footer">
            <button type="button" id="btn-visitas-volver" class="btn-volver-rojo">
                <i class="bi bi-arrow-left-circle-fill"></i> Volver
            </button>
        </div>
    </div>

    <!-- ========================================================
         PANTALLA 6: EDITAR VISITA (detalle de una foto/registro)
         ======================================================== -->
    <div id="screen-editar-visita" class="screen">
        <div class="visitas-topbar">
            <button type="button" id="btn-editar-back" class="cam-btn-back">
                <i class="bi bi-arrow-left"></i>
            </button>
            <div class="visitas-title">
                <strong>Editar Registro</strong>
                <span id="editar-infra-name">--</span>
            </div>
            <div style="width:40px;"></div>
        </div>
        <div class="editar-body" id="editar-body">
            <div class="editar-foto-preview">
                <img id="editar-foto-img" src="" alt="Foto">
            </div>
            <div class="card" style="margin:12px 16px;">
                <div class="card-label"><i class="bi bi-info-circle"></i> Información</div>
                <div class="editar-info" id="editar-info"></div>
            </div>
            <div class="card" style="margin:12px 16px;">
                <div class="card-label"><i class="bi bi-flag"></i> Situación</div>
                <select id="editar-estado" class="input-field">
                    <option value="antes">Antes</option>
                    <option value="durante">Durante</option>
                    <option value="despues">Después</option>
                </select>
            </div>
            <div class="card" style="margin:12px 16px;">
                <div class="card-label"><i class="bi bi-tools"></i> Unidad de Obra</div>
                <select id="editar-uo" class="input-field">
                    <option value="">Sin asignar</option>
                </select>
            </div>
            <div class="card" style="margin:12px 16px;">
                <div class="card-label"><i class="bi bi-chat-text"></i> Observaciones</div>
                <textarea id="editar-observaciones" class="input-field input-textarea" rows="3" placeholder="Observaciones..."></textarea>
            </div>
            <div style="padding:12px 16px 24px;">
                <button type="button" id="btn-guardar-edicion" class="btn-guardar-visita">
                    <i class="bi bi-check-circle-fill"></i> Guardar cambios
                </button>
            </div>
            <div style="padding:0 16px 24px;">
                <button type="button" id="btn-añadir-foto-visita" class="foto-btn foto-btn--aleatorio" style="width:100%;">
                    <div class="foto-btn-icon"><i class="bi bi-camera-fill"></i></div>
                    <div class="foto-btn-text">
                        <strong>Añadir foto</strong>
                        <small>Tomar foto para esta infraestructura</small>
                    </div>
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
                tiposTrabajo: 'api/tipos_trabajo.php',
                fotosComparativas: 'api/fotos_comparativas.php',
                registrosMapa: 'api/registros_mapa.php',
                capasKml: 'api/capas_kml.php',
                capasInfra: 'api/capas_infra.php',
                visitas: 'api/visitas.php',
                waypoints: 'api/waypoints.php',
                puntosMapa: 'api/puntos_mapa.php',
                campos: 'api/campos.php',
                guardarVisita: 'api/guardar_visita.php',
            },
            formatoNombreFoto: <?= $formatoNombreFoto ?>,
            opMostrarEmpresa: <?= $opMostrarEmpresa ?>,
            opMostrarInfra: <?= $opMostrarInfra ?>,
            opMostrarSituacion: <?= $opMostrarSituacion ?>,
            opMostrarMapa: <?= $opMostrarMapa ?>,
            opMostrarCapasInfra: <?= $opMostrarCapasInfra ?>,
            opMapaZoom: <?= $opMapaZoom ?>,
            watermark: {
                fecha: <?= $wmFecha ?>,
                coordenadas: <?= $wmCoordenadas ?>,
                orientacion: <?= $wmOrientacion ?>,
                ubicacion: <?= $wmUbicacion ?>,
                pais: <?= $wmPais ?>,
                brujula: <?= $wmBrujula ?>,
                codigoInfra: <?= $wmCodigoInfra ?>,
                situacion: <?= $wmSituacion ?>,
                tipoFoto: <?= $wmTipoFoto ?>,
                mapa: <?= $wmMapa ?>,
                mapaZoom: <?= $wmMapaZoom ?>,
                mapaTamano: <?= $wmMapaTamano ?>,
                textoTamano: <?= $wmTextoTamano ?>,
            },
        };
    </script>
    <script>
    // User menu toggle
    (function() {
        const btn = document.getElementById('btn-user-menu');
        const menu = document.getElementById('user-menu-dropdown');
        if (!btn || !menu) return;
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            menu.classList.toggle('hidden');
        });
        document.addEventListener('click', function(e) {
            if (!menu.contains(e.target) && e.target !== btn) {
                menu.classList.add('hidden');
            }
        });
    })();
    </script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="js/offline.js"></script>
    <script src="js/operador.js"></script>
</body>
</html>
