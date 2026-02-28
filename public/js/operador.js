/**
 * INFOCAMPO - Operador de Campo
 *
 * App principal del operador para recogida de datos en campo.
 * Soporta fotos aleatorias y comparativas con sistema Ghosting.
 * Watermarks con coordenadas ETRS89 y hora de Madrid.
 */
;(function() {
    'use strict';

    const CFG = window.INFOCAMPO;

    // ===================================================================
    // STATE
    // ===================================================================
    const state = {
        infraId: null,
        infraName: '',
        infraCode: '',
        unidadObraId: null,
        currentMode: null, // 'aleatorio' | 'comparativo'
        gps: { lat: null, lon: null },
        gpsWatchId: null,
        stream: null,
        capturedBlob: null,
        ghostUrl: null,
        ghostActive: false,
        seqComparativa: 0,
        photos: [], // { url, type, seq, name }
        countAleatorias: 0,
        countComparativas: 0,
        prevPhotos: [], // fotos comparativas de visita anterior
    };

    // ===================================================================
    // DOM REFS
    // ===================================================================
    const $ = (sel) => document.querySelector(sel);
    const $$ = (sel) => document.querySelectorAll(sel);

    const screens = {
        ficha:   $('#screen-ficha'),
        camera:  $('#screen-camera'),
        preview: $('#screen-preview'),
        mapa:    $('#screen-mapa'),
    };

    // Ficha
    const infraSearch      = $('#infra-search');
    const infraResults     = $('#infra-results');
    const infraIdInput     = $('#infra-id');
    const infraSelected    = $('#infra-selected');
    const infraSelectedName = $('#infra-selected-name');
    const infraClear       = $('#infra-clear');
    const unidadObra       = $('#unidad-obra');
    const fechaDisplay     = $('#fecha-display');
    const btnAleatorias    = $('#btn-fotos-aleatorias');
    const btnComparativas  = $('#btn-fotos-comparativas');
    const countAleatorias  = $('#count-aleatorias');
    const countComparativas = $('#count-comparativas');
    const gallerySection   = $('#gallery-section');
    const galleryGrid      = $('#gallery-grid');
    const btnVerMapa       = $('#btn-ver-mapa');

    // Camera
    const camVideo       = $('#cam-video');
    const camGhost       = $('#cam-ghost');
    const camCapture     = $('#cam-capture');
    const camInfraName   = $('#cam-infra-name');
    const camModeBadge   = $('#cam-mode-badge');
    const camTime        = $('#cam-time');
    const camGpsDot      = $('#cam-gps-dot');
    const camGpsText     = $('#cam-gps-text');
    const camSeqCounter  = $('#cam-seq-counter');
    const camSeqLabel    = $('#cam-seq-label');
    const btnCamBack     = $('#btn-cam-back');
    const btnShutter     = $('#btn-shutter');
    const btnGhostToggle = $('#btn-ghost-toggle');
    const btnLoadPrev    = $('#btn-load-prev');

    // Preview
    const previewCanvas  = $('#preview-canvas');
    const previewFilename = $('#preview-filename');
    // Preview buttons (kept for backwards compat but no longer primary flow)
    const btnRetake      = $('#btn-retake');
    const btnAccept      = $('#btn-accept');

    // Map
    const mapaDetailPanel = $('#mapa-detail-panel');
    const mapaDetailBody  = $('#mapa-detail-body');
    const detailInfraName = $('#detail-infra-name');
    const detailInfraCode = $('#detail-infra-code');
    const detailInfraDistance = $('#detail-infra-distance');
    const btnMapaBack     = $('#btn-mapa-back');
    const btnMapaVolver   = $('#btn-mapa-volver');
    const btnCloseDetail  = $('#btn-close-detail');
    const btnDetailNavegar     = $('#btn-detail-navegar');
    const btnDetailAleatorio   = $('#btn-detail-aleatorio');
    const btnDetailComparativo = $('#btn-detail-comparativo');

    // Overlay / Modal
    const uploadOverlay  = $('#upload-overlay');
    const modalPrevPhotos = $('#modal-prev-photos');
    const prevPhotosGrid = $('#prev-photos-grid');
    const btnClosePrev   = $('#btn-close-prev');
    const btnSkipPrev    = $('#btn-skip-prev');

    // ===================================================================
    // INIT
    // ===================================================================
    function init() {
        updateDate();
        setInterval(updateClock, 30000);
        loadProvincias();
        loadUnidadesObra();
        bindEvents();
        initGPS();
        initOffline();
        registerServiceWorker();
    }

    // ===================================================================
    // OFFLINE INTEGRATION
    // ===================================================================
    function initOffline() {
        if (!window.InfocampoOffline) return;

        window.InfocampoOffline.init({
            onStatusChange: (online) => {
                const indicator = $('#offline-indicator');
                const dot = $('#offline-dot');
                const text = $('#offline-text');
                if (!indicator) return;

                if (online) {
                    indicator.classList.remove('offline');
                    indicator.classList.add('online');
                    dot.className = 'offline-dot online';
                    text.textContent = 'En línea';
                } else {
                    indicator.classList.remove('online');
                    indicator.classList.add('offline');
                    dot.className = 'offline-dot offline';
                    text.textContent = 'Sin conexión';
                }

                // Actualizar banner de cola offline
                const banner = $('#offline-queue-banner');
                if (banner && !banner.classList.contains('hidden')) {
                    const statusEl = $('#oq-banner-status');
                    if (!online) {
                        banner.classList.add('offline-mode');
                        banner.classList.remove('syncing');
                        if (statusEl) statusEl.textContent = 'Sin conexión — se subirán al reconectar';
                    } else {
                        banner.classList.remove('offline-mode');
                        if (statusEl) statusEl.textContent = 'Conexión disponible — listo para sincronizar';
                    }
                }
            },
            onQueueChange: (count) => {
                const badge = $('#sync-queue-badge');
                const syncBar = $('#sync-bar');
                if (badge) {
                    badge.textContent = count;
                    badge.style.display = count > 0 ? 'inline-flex' : 'none';
                }
                if (syncBar) {
                    syncBar.classList.toggle('hidden', count === 0);
                    const syncCount = $('#sync-count');
                    if (syncCount) syncCount.textContent = count;
                }

                // Actualizar banner persistente de cola
                const banner = $('#offline-queue-banner');
                const countEl = $('#oq-banner-count');
                const labelEl = $('#oq-banner-label');
                const statusEl = $('#oq-banner-status');
                if (banner) {
                    if (count > 0) {
                        banner.classList.remove('hidden');
                        if (countEl) countEl.textContent = count;
                        if (labelEl) labelEl.textContent = count === 1 ? 'foto pendiente' : 'fotos pendientes';
                        if (statusEl && !navigator.onLine) {
                            statusEl.textContent = 'Sin conexión — se subirán al reconectar';
                            banner.classList.add('offline-mode');
                        } else if (statusEl) {
                            statusEl.textContent = 'Listo para sincronizar';
                            banner.classList.remove('offline-mode');
                        }
                    } else {
                        banner.classList.add('hidden');
                        banner.classList.remove('syncing', 'offline-mode');
                    }
                }
            },
            onSyncProgress: ({ synced, total, current }) => {
                const bar = $('#sync-progress-bar');
                const text = $('#sync-progress-text');
                if (bar) bar.style.width = ((synced / total) * 100) + '%';
                if (text) text.textContent = `Subiendo ${synced + 1}/${total}: ${current}`;

                // Actualizar banner con progreso
                const banner = $('#offline-queue-banner');
                const progressWrap = $('#oq-banner-progress');
                const progressFill = $('#oq-banner-progress-fill');
                const statusEl = $('#oq-banner-status');
                const btnSync = $('#oq-banner-sync');
                if (banner) {
                    banner.classList.add('syncing');
                    banner.classList.remove('offline-mode');
                }
                if (progressWrap) progressWrap.style.display = 'block';
                if (progressFill) progressFill.style.width = ((synced / total) * 100) + '%';
                if (statusEl) statusEl.textContent = `Subiendo ${synced + 1} de ${total}...`;
                if (btnSync) btnSync.classList.add('spinning');
            },
            onSyncComplete: (results) => {
                const ok = results.filter(r => r.ok).length;
                const fail = results.filter(r => !r.ok).length;
                const bar = $('#sync-progress-bar');
                if (bar) bar.style.width = '100%';

                showSyncNotification(ok, fail);

                // Limpiar banner de progreso
                const banner = $('#offline-queue-banner');
                const progressWrap = $('#oq-banner-progress');
                const btnSync = $('#oq-banner-sync');
                if (banner) banner.classList.remove('syncing');
                if (progressWrap) progressWrap.style.display = 'none';
                if (btnSync) btnSync.classList.remove('spinning');

                // Reload gallery with synced photos
                results.forEach(r => {
                    if (r.ok && r.result) {
                        addToGallery(r.result.url_imagen, 'synced', r.result.nombre_archivo || 'foto', null);
                    }
                });
            },
            onPrecacheProgress: ({ loaded, total }) => {
                const bar = $('#precache-progress-bar');
                const text = $('#precache-progress-text');
                if (bar) bar.style.width = ((loaded / total) * 100) + '%';
                if (text) text.textContent = `Descargando ${loaded}/${total} fotos...`;
            },
        });
    }

    function showSyncNotification(ok, fail) {
        const notification = $('#sync-notification');
        if (!notification) return;

        let msg = '';
        if (ok > 0 && fail === 0) {
            msg = `${ok} foto${ok > 1 ? 's' : ''} sincronizada${ok > 1 ? 's' : ''} correctamente`;
            notification.className = 'sync-notification success';
        } else if (ok > 0 && fail > 0) {
            msg = `${ok} subida${ok > 1 ? 's' : ''}, ${fail} con error`;
            notification.className = 'sync-notification warning';
        } else {
            msg = `Error al sincronizar ${fail} foto${fail > 1 ? 's' : ''}`;
            notification.className = 'sync-notification error';
        }

        notification.querySelector('.sync-notif-text').textContent = msg;
        notification.classList.remove('hidden');
        setTimeout(() => notification.classList.add('hidden'), 5000);
    }

    function registerServiceWorker() {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('sw.js').catch(err => {
                console.warn('SW registration failed:', err);
            });
        }
    }

    // ===================================================================
    // DATE / CLOCK
    // ===================================================================
    function getMadridDate() {
        return new Date(new Date().toLocaleString('en-US', { timeZone: 'Europe/Madrid' }));
    }

    function formatDateMadrid(date) {
        if (!date) date = new Date();
        return date.toLocaleString('es-ES', {
            timeZone: 'Europe/Madrid',
            day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
            hour12: false,
        });
    }

    function updateDate() {
        const now = new Date();
        fechaDisplay.textContent = now.toLocaleDateString('es-ES', {
            timeZone: 'Europe/Madrid',
            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
        });
    }

    function updateClock() {
        const now = new Date();
        camTime.textContent = now.toLocaleTimeString('es-ES', {
            timeZone: 'Europe/Madrid',
            hour: '2-digit', minute: '2-digit',
        });
    }

    // ===================================================================
    // GPS
    // ===================================================================
    function initGPS() {
        if (!('geolocation' in navigator)) {
            camGpsText.textContent = 'ETRS89: Sin GPS';
            return;
        }

        state.gpsWatchId = navigator.geolocation.watchPosition(
            (pos) => {
                state.gps.lat = pos.coords.latitude;
                state.gps.lon = pos.coords.longitude;
                camGpsDot.classList.add('active');
                camGpsText.textContent = `ETRS89: ${state.gps.lat.toFixed(7)}, ${state.gps.lon.toFixed(7)}`;
            },
            () => {
                camGpsText.textContent = 'ETRS89: Error GPS';
            },
            { enableHighAccuracy: true, maximumAge: 3000 }
        );
    }

    // ===================================================================
    // PROVINCIA / MUNICIPIO FILTERS
    // ===================================================================
    const filterProvincia = $('#filter-provincia');
    const filterMunicipio = $('#filter-municipio');

    async function loadProvincias() {
        if (!CFG.empresaId) return;
        try {
            const res = await fetch(
                `${CFG.endpoints.infraestructuras}?empresa_id=${CFG.empresaId}&action=provincias`
            );
            const data = await res.json();
            if (data.ok && data.provincias) {
                let html = '<option value="">-- Todas las provincias --</option>';
                data.provincias.forEach(p => {
                    html += `<option value="${escHtml(p)}">${escHtml(p)}</option>`;
                });
                filterProvincia.innerHTML = html;
            }
        } catch (err) {
            console.warn('Error loading provincias:', err);
        }
    }

    async function loadMunicipios(provincia) {
        if (!CFG.empresaId || !provincia) {
            filterMunicipio.innerHTML = '<option value="">-- Todos los municipios --</option>';
            filterMunicipio.disabled = true;
            return;
        }
        try {
            const res = await fetch(
                `${CFG.endpoints.infraestructuras}?empresa_id=${CFG.empresaId}&action=municipios&provincia=${encodeURIComponent(provincia)}`
            );
            const data = await res.json();
            if (data.ok && data.municipios) {
                let html = '<option value="">-- Todos los municipios --</option>';
                data.municipios.forEach(m => {
                    html += `<option value="${escHtml(m)}">${escHtml(m)}</option>`;
                });
                filterMunicipio.innerHTML = html;
                filterMunicipio.disabled = false;
            }
        } catch (err) {
            console.warn('Error loading municipios:', err);
        }
    }

    function getFilterParams() {
        let params = '';
        const prov = filterProvincia ? filterProvincia.value : '';
        const muni = filterMunicipio ? filterMunicipio.value : '';
        if (prov) params += `&provincia=${encodeURIComponent(prov)}`;
        if (muni) params += `&municipio=${encodeURIComponent(muni)}`;
        return params;
    }

    // ===================================================================
    // INFRASTRUCTURE SEARCH
    // ===================================================================
    let searchTimeout = null;

    function searchInfra(query) {
        clearTimeout(searchTimeout);

        searchTimeout = setTimeout(async () => {
            try {
                let url = `${CFG.endpoints.infraestructuras}?empresa_id=${CFG.empresaId}${getFilterParams()}`;
                if (query.length > 0) {
                    url += `&q=${encodeURIComponent(query)}`;
                }
                const res = await fetch(url);
                const data = await res.json();

                if (!data.ok) {
                    console.warn('API error:', data.error);
                    return;
                }

                let html = '';

                if (data.infraestructuras.length === 0 && query.length === 0) {
                    html += `<div class="result-item" style="color:#9ca3af;pointer-events:none;">
                        <i class="bi bi-info-circle"></i> No hay infraestructuras disponibles
                    </div>`;
                }

                data.infraestructuras.forEach(inf => {
                    const loc = [inf.municipio, inf.provincia].filter(Boolean).join(', ');
                    html += `<div class="result-item" data-id="${inf.id}" data-name="${escHtml(inf.nombre)}" data-code="${escHtml(inf.codigo_unico)}">
                        ${escHtml(inf.nombre)} <span class="result-code">${escHtml(inf.codigo_unico)}</span>
                        ${loc ? `<span class="result-location">${escHtml(loc)}</span>` : ''}
                    </div>`;
                });

                // Option to create new (only when user typed something)
                if (query.length > 0) {
                    html += `<div class="result-new" data-new="true">
                        <i class="bi bi-plus-circle"></i> Crear: "${escHtml(query)}"
                    </div>`;
                }

                infraResults.innerHTML = html;
                infraResults.classList.remove('hidden');

                // Bind clicks
                infraResults.querySelectorAll('.result-item[data-id]').forEach(el => {
                    el.addEventListener('click', () => selectInfra(
                        parseInt(el.dataset.id),
                        el.dataset.name,
                        el.dataset.code
                    ));
                });

                const newBtn = infraResults.querySelector('.result-new');
                if (newBtn) {
                    newBtn.addEventListener('click', () => {
                        createNewInfra(query);
                    });
                }
            } catch (err) {
                console.warn('Error searching infra:', err);
            }
        }, query.length === 0 ? 50 : 300);
    }

    function selectInfra(id, name, code) {
        state.infraId = id;
        state.infraName = name;
        state.infraCode = code || name;
        infraIdInput.value = id;
        infraSearch.classList.add('hidden');
        infraResults.classList.add('hidden');
        infraSelected.classList.remove('hidden');
        infraSelectedName.textContent = `${name} (${code || 'sin código'})`;
        updateButtonState();
        updatePrecacheIndicator();
    }

    async function createNewInfra(name) {
        try {
            const formData = new FormData();
            formData.append('empresa_id', CFG.empresaId);
            formData.append('nombre', name);
            formData.append('lat', state.gps.lat || 0);
            formData.append('lon', state.gps.lon || 0);

            const res = await fetch(CFG.endpoints.infraestructuras, {
                method: 'POST', body: formData
            });
            const data = await res.json();

            if (data.ok && data.infraestructura) {
                selectInfra(
                    data.infraestructura.id,
                    data.infraestructura.nombre,
                    data.infraestructura.codigo_unico
                );
            }
        } catch (err) {
            alert('Error al crear infraestructura: ' + err.message);
        }
    }

    function clearInfra() {
        state.infraId = null;
        state.infraName = '';
        state.infraCode = '';
        infraIdInput.value = '';
        infraSearch.value = '';
        infraSearch.classList.remove('hidden');
        infraSelected.classList.add('hidden');
        updateButtonState();
    }

    // ===================================================================
    // UNIDADES DE OBRA
    // ===================================================================
    async function loadUnidadesObra() {
        if (!CFG.empresaId) return;
        try {
            const res = await fetch(`${CFG.endpoints.unidadesObra}?empresa_id=${CFG.empresaId}`);
            const data = await res.json();
            if (data.ok && data.unidades) {
                let html = '<option value="">-- Seleccionar unidad de obra --</option>';
                data.unidades.forEach(u => {
                    const label = u.codigo ? `${u.codigo} - ${u.nombre}` : u.nombre;
                    html += `<option value="${u.id}">${escHtml(label)}</option>`;
                });
                unidadObra.innerHTML = html;
            }
        } catch (err) {
            console.warn('Error loading unidades de obra:', err);
        }
    }

    // ===================================================================
    // BUTTON STATE
    // ===================================================================
    function updateButtonState() {
        const enabled = state.infraId !== null;
        btnAleatorias.disabled = !enabled;
        btnComparativas.disabled = !enabled;

        // Show/hide hint
        const hint = $('#hint-select-infra');
        if (hint) {
            hint.classList.toggle('hidden', enabled);
        }
    }

    // ===================================================================
    // SCREEN MANAGEMENT
    // ===================================================================
    function showScreen(name) {
        Object.values(screens).forEach(s => s.classList.remove('active'));
        screens[name].classList.add('active');
    }

    // ===================================================================
    // CAMERA
    // ===================================================================
    async function openCamera(mode) {
        state.currentMode = mode;

        // Update UI
        camInfraName.textContent = state.infraName;
        camModeBadge.textContent = mode === 'aleatorio' ? 'ALEATORIO' : 'COMPARATIVO';
        camModeBadge.className = 'cam-mode-badge ' + mode;
        updateClock();

        // Comparative mode UI
        if (mode === 'comparativo') {
            camSeqCounter.classList.remove('hidden');
            state.seqComparativa = state.countComparativas;
            camSeqLabel.textContent = 'W' + (state.seqComparativa + 1);
            btnGhostToggle.classList.remove('hidden');
            btnLoadPrev.classList.remove('hidden');

            // If no ghost yet and no previous photos loaded, try loading from previous visit
            if (!state.ghostUrl && state.prevPhotos.length === 0) {
                await checkPreviousPhotos();
            }
        } else {
            camSeqCounter.classList.add('hidden');
            btnGhostToggle.classList.add('hidden');
            btnLoadPrev.classList.add('hidden');
            camGhost.classList.remove('active');
        }

        // Start camera
        try {
            if (!state.stream) {
                state.stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: { ideal: 'environment' },
                        width: { ideal: 1080 },
                        height: { ideal: 1440 },
                        aspectRatio: { ideal: 3 / 4 },
                    },
                    audio: false,
                });
            }
            camVideo.srcObject = state.stream;
            await camVideo.play();
        } catch (err) {
            alert('No se pudo acceder a la cámara: ' + err.message);
            return;
        }

        showScreen('camera');
    }

    function closeCamera() {
        stopCameraStream();
        camGhost.classList.remove('active');
        showScreen('ficha');
    }

    // ===================================================================
    // PREVIOUS COMPARATIVE PHOTOS
    // ===================================================================
    async function checkPreviousPhotos() {
        if (!state.infraId) return;

        // Try cached photos first when offline
        if (!navigator.onLine && window.InfocampoOffline) {
            try {
                const cached = await window.InfocampoOffline.getCachedPhotos(state.infraId);
                if (cached.length > 0) {
                    // Map cached photos to the expected format using blob URLs
                    state.prevPhotos = cached.map(c => ({
                        id: c.id,
                        url_cloudinary: c.blobUrl, // Use local blob URL
                        secuencia_comparativa: c.secuencia_comparativa,
                        nombre_archivo: c.nombre_archivo,
                        fecha: c.fecha,
                        _cached: true,
                    }));
                    showPrevPhotosModal(state.prevPhotos, null);
                    return;
                }
            } catch (err) {
                console.warn('Error loading cached photos:', err);
            }
            return; // No cached photos and offline
        }

        // Online — fetch from server
        try {
            const res = await fetch(
                `${CFG.endpoints.fotosComparativas}?infra_id=${state.infraId}`
            );
            const data = await res.json();
            if (data.ok && data.fotos && data.fotos.length > 0) {
                state.prevPhotos = data.fotos;
                showPrevPhotosModal(data.fotos, data.fecha_visita);
            }
        } catch (err) {
            // Network failed — try cached as fallback
            if (window.InfocampoOffline) {
                try {
                    const cached = await window.InfocampoOffline.getCachedPhotos(state.infraId);
                    if (cached.length > 0) {
                        state.prevPhotos = cached.map(c => ({
                            id: c.id,
                            url_cloudinary: c.blobUrl,
                            secuencia_comparativa: c.secuencia_comparativa,
                            nombre_archivo: c.nombre_archivo,
                            fecha: c.fecha,
                            _cached: true,
                        }));
                        showPrevPhotosModal(state.prevPhotos, null);
                        return;
                    }
                } catch (cacheErr) {
                    console.warn('Cache fallback error:', cacheErr);
                }
            }
            console.warn('Error loading previous photos:', err);
        }
    }

    function showPrevPhotosModal(fotos, fecha) {
        let html = '';
        fotos.forEach(f => {
            const label = f.nombre_archivo || ('W' + f.secuencia_comparativa);
            html += `<div class="prev-photo-item" data-url="${escHtml(f.url_cloudinary)}">
                <img src="${escHtml(f.url_cloudinary)}" alt="${label}" loading="lazy">
                <div class="prev-label">${escHtml(label)}</div>
            </div>`;
        });

        prevPhotosGrid.innerHTML = html;
        modalPrevPhotos.classList.remove('hidden');

        // Bind clicks - select photo as ghost
        prevPhotosGrid.querySelectorAll('.prev-photo-item').forEach(el => {
            el.addEventListener('click', () => {
                setGhostImage(el.dataset.url);
                modalPrevPhotos.classList.add('hidden');
            });
        });
    }

    function setGhostImage(url) {
        state.ghostUrl = url;
        state.ghostActive = true;
        camGhost.src = url;
        camGhost.classList.add('active');
        camGhost.classList.remove('off');
        btnGhostToggle.classList.add('active');
    }

    // ===================================================================
    // CAPTURE
    // ===================================================================
    async function captureFrame() {
        const vw = camVideo.videoWidth;
        const vh = camVideo.videoHeight;

        // Force 3:4 portrait crop from center of video frame
        let srcX = 0, srcY = 0, srcW = vw, srcH = vh;
        const targetRatio = 3 / 4; // width / height
        const videoRatio = vw / vh;

        if (videoRatio > targetRatio) {
            // Video is wider than 3:4 — crop sides
            srcW = Math.round(vh * targetRatio);
            srcX = Math.round((vw - srcW) / 2);
        } else if (videoRatio < targetRatio) {
            // Video is taller than 3:4 — crop top/bottom
            srcH = Math.round(vw / targetRatio);
            srcY = Math.round((vh - srcH) / 2);
        }

        camCapture.width = srcW;
        camCapture.height = srcH;

        const ctx = camCapture.getContext('2d');
        ctx.drawImage(camVideo, srcX, srcY, srcW, srcH, 0, 0, srcW, srcH);

        camVideo.pause();

        // Generate filename: InfrastructureName_AL001 or InfrastructureName_COMP001
        let filename = '';
        if (state.currentMode === 'comparativo') {
            state.seqComparativa++;
            state.countComparativas++;
            const seqNum = String(state.countComparativas).padStart(3, '0');
            filename = `${sanitizeFilename(state.infraName)}_COMP${seqNum}`;
        } else {
            state.countAleatorias++;
            const seqNum = String(state.countAleatorias).padStart(3, '0');
            filename = `${sanitizeFilename(state.infraName)}_AL${seqNum}`;
        }

        // Apply watermark directly on previewCanvas (used for blob generation)
        await applyWatermark(camCapture, previewCanvas, {
            lat: state.gps.lat,
            lon: state.gps.lon,
            infraName: state.infraName,
            infraCode: state.infraCode,
            empresaName: CFG.empresaName,
            filename: filename,
            mode: state.currentMode,
            seq: state.currentMode === 'comparativo' ? state.seqComparativa : null,
        });

        // Auto-accept: upload + save to gallery + go back to ficha
        await processAndUploadPhoto(filename);
    }

    // ===================================================================
    // SAVE TO DEVICE GALLERY
    // ===================================================================
    function saveToDeviceGallery(blob, filename) {
        try {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename + '.jpg';
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(url), 2000);
        } catch (err) {
            console.warn('Error saving to gallery:', err);
        }
    }

    // ===================================================================
    // PROCESS, UPLOAD & RETURN TO FICHA
    // ===================================================================
    async function processAndUploadPhoto(filename) {
        // Convert preview canvas to blob
        const blob = await canvasToBlob(previewCanvas, 'image/jpeg', 0.85);
        if (!blob) {
            alert('Error al procesar la foto.');
            camVideo.play();
            showScreen('ficha');
            return;
        }

        // Show upload overlay
        uploadOverlay.classList.remove('hidden');

        // Save to device gallery (non-blocking)
        saveToDeviceGallery(blob, filename);

        let seq = null;
        if (state.currentMode === 'comparativo') {
            seq = state.seqComparativa;
        }

        // Build upload data
        const uploadData = {
            infra_id: state.infraId,
            usuario_id: CFG.usuarioId,
            lat_real: state.gps.lat || 0,
            lon_real: state.gps.lon || 0,
            estado_incidencia: 'bajo',
            tipo_foto: state.currentMode,
            nombre_archivo: filename,
            observaciones: $('#observaciones-general').value || '',
            secuencia_comparativa: seq,
            unidad_obra_id: unidadObra.value || null,
            datos_tecnicos: JSON.stringify({
                timestamp: new Date().toISOString(),
                etrs89_lat: state.gps.lat,
                etrs89_lon: state.gps.lon,
                timezone: 'Europe/Madrid',
                mode: state.currentMode,
            }),
            uploadUrl: CFG.endpoints.upload,
        };

        // Stop camera stream since we return to ficha
        stopCameraStream();

        // Check connectivity — if offline, queue locally
        if (!navigator.onLine && window.InfocampoOffline) {
            try {
                await window.InfocampoOffline.enqueue(blob, uploadData);
                uploadOverlay.classList.add('hidden');

                const localUrl = URL.createObjectURL(blob);
                addToGallery(localUrl, state.currentMode + ' pending', filename, seq);
                updateCounters(seq);

                showScreen('ficha');
                showNotification('Foto guardada (pendiente de sincronizar)');
                return;
            } catch (queueErr) {
                console.error('Error saving offline:', queueErr);
                uploadOverlay.classList.add('hidden');
                alert('Error al guardar localmente: ' + queueErr.message);
                showScreen('ficha');
                return;
            }
        }

        // Online — upload directly
        const formData = new FormData();
        formData.append('imagen', blob, filename + '.jpg');
        formData.append('infra_id', uploadData.infra_id);
        formData.append('usuario_id', uploadData.usuario_id);
        formData.append('lat_real', uploadData.lat_real);
        formData.append('lon_real', uploadData.lon_real);
        formData.append('estado_incidencia', uploadData.estado_incidencia);
        formData.append('tipo_foto', uploadData.tipo_foto);
        formData.append('nombre_archivo', uploadData.nombre_archivo);
        formData.append('observaciones', uploadData.observaciones);

        if (seq !== null) {
            formData.append('secuencia_comparativa', seq);
        }
        if (unidadObra.value) {
            formData.append('unidad_obra_id', unidadObra.value);
        }
        formData.append('datos_tecnicos', uploadData.datos_tecnicos);

        try {
            const res = await fetch(CFG.endpoints.upload, { method: 'POST', body: formData });
            const data = await res.json();
            uploadOverlay.classList.add('hidden');

            if (data.ok) {
                addToGallery(data.url_imagen, state.currentMode, filename, seq);
                updateCounters(seq);
                showScreen('ficha');
                showNotification('Foto subida correctamente');
            } else {
                alert('Error: ' + (data.error || 'Error desconocido'));
                showScreen('ficha');
            }
        } catch (err) {
            uploadOverlay.classList.add('hidden');

            // Network error — try to queue offline
            if (window.InfocampoOffline) {
                try {
                    await window.InfocampoOffline.enqueue(blob, uploadData);
                    const localUrl = URL.createObjectURL(blob);
                    addToGallery(localUrl, state.currentMode + ' pending', filename, seq);
                    updateCounters(seq);
                    showScreen('ficha');
                    showNotification('Foto guardada (pendiente de sincronizar)');
                    return;
                } catch (qErr) {
                    console.error('Fallback queue error:', qErr);
                }
            }

            alert('Error de red: ' + err.message);
            showScreen('ficha');
        }
    }

    function stopCameraStream() {
        if (state.stream) {
            state.stream.getTracks().forEach(t => t.stop());
            state.stream = null;
        }
        camVideo.pause();
        camVideo.srcObject = null;
    }

    function showNotification(msg) {
        const notif = $('#sync-notification');
        if (!notif) return;
        const text = notif.querySelector('.sync-notif-text');
        if (text) text.textContent = msg;
        notif.classList.remove('hidden');
        setTimeout(() => notif.classList.add('hidden'), 3000);
    }

    function updateCounters(seq) {
        // Counters are already incremented in captureFrame for filename generation
        countAleatorias.textContent = state.countAleatorias;
        countComparativas.textContent = state.countComparativas;
    }

    // ===================================================================
    // GALLERY
    // ===================================================================
    function addToGallery(url, type, name, seq) {
        gallerySection.classList.remove('hidden');

        const isPending = type.includes('pending');
        const baseType = type.replace(' pending', '').replace(' synced', '');
        let label = baseType === 'comparativo' ? 'W' + seq : 'ALEA';
        if (isPending) label += ' *';

        const div = document.createElement('div');
        div.className = 'gallery-item';
        div.innerHTML = `
            <img src="${escHtml(url)}" alt="${escHtml(name)}" loading="lazy">
            <span class="gallery-type ${baseType}${isPending ? ' pending' : ''}">${label}</span>
            <div class="gallery-label">${escHtml(name)}</div>
        `;
        galleryGrid.appendChild(div);

        state.photos.push({ url, type: baseType, seq, name, pending: isPending });
    }

    // ===================================================================
    // WATERMARK
    // ===================================================================
    async function applyWatermark(sourceCanvas, targetCanvas, meta) {
        const w = sourceCanvas.width;
        const h = sourceCanvas.height;
        targetCanvas.width = w;
        targetCanvas.height = h;

        const ctx = targetCanvas.getContext('2d');

        // 1. Draw original photo
        ctx.drawImage(sourceCanvas, 0, 0);

        // 2. Info text block — bottom-right
        // Lines: Empresa, Infraestructura, Fecha, Coordenadas
        const fontSize = Math.max(14, Math.round(h * 0.02));
        const lineHeight = fontSize * 1.5;
        const numLines = 4;
        const padding = 16;
        const blockHeight = lineHeight * numLines + padding * 2;

        // Prepare text lines first to measure widths
        const empresaStr = meta.empresaName || '';
        const infraStr = meta.infraName || '';
        const dateStr = formatDateMadrid();
        const latStr = meta.lat != null ? meta.lat.toFixed(7) : '--';
        const lonStr = meta.lon != null ? meta.lon.toFixed(7) : '--';
        const coordStr = `ETRS89: ${latStr}, ${lonStr}`;

        // Measure max text width to auto-size block
        ctx.font = `bold ${fontSize}px -apple-system, sans-serif`;
        const boldWidths = [ctx.measureText(empresaStr).width];
        ctx.font = `${fontSize}px -apple-system, sans-serif`;
        const normalWidths = [
            ctx.measureText(infraStr).width,
            ctx.measureText(dateStr).width,
            ctx.measureText(coordStr).width,
        ];
        const maxTextWidth = Math.max(...boldWidths, ...normalWidths);
        const blockWidth = Math.min(w - 24, maxTextWidth + padding * 2);

        // Semi-transparent background block (bottom-right)
        const bx = w - blockWidth - 12;
        const by = h - blockHeight - 12;
        ctx.fillStyle = 'rgba(0, 0, 0, 0.65)';
        roundRect(ctx, bx, by, blockWidth, blockHeight, 8);
        ctx.fill();

        ctx.fillStyle = '#ffffff';
        ctx.font = `bold ${fontSize}px -apple-system, sans-serif`;
        ctx.textBaseline = 'top';
        ctx.textAlign = 'left';

        const textX = bx + padding;
        let textY = by + padding;

        // Line 1: Nombre Empresa
        ctx.fillText(empresaStr, textX, textY);
        textY += lineHeight;

        // Line 2: Infraestructura
        ctx.font = `${fontSize}px -apple-system, sans-serif`;
        ctx.fillText(infraStr, textX, textY);
        textY += lineHeight;

        // Line 3: Fecha
        ctx.fillText(dateStr, textX, textY);
        textY += lineHeight;

        // Line 4: Coordenadas
        ctx.fillText(coordStr, textX, textY);

        // Reset text align
        ctx.textAlign = 'start';

        // 3. Mini-map OSM (top-left, 1/6 of image)
        await drawMiniMap(ctx, w, h, meta.lat, meta.lon);

        // Save blob for later
        state.capturedBlob = await canvasToBlob(targetCanvas, 'image/jpeg', 0.85);
    }

    async function drawMiniMap(ctx, canvasWidth, canvasHeight, lat, lon) {
        if (lat == null || lon == null) return;

        // 1/6 of image size, positioned top-left, flush to corner
        const mapSize = Math.round(Math.min(canvasWidth, canvasHeight) / 6);
        const x = 0;
        const y = 0;

        ctx.fillStyle = 'rgba(0,0,0,0.5)';
        ctx.fillRect(x, y, mapSize, mapSize);
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = 2;
        ctx.strokeRect(x, y, mapSize, mapSize);

        try {
            const zoom = 17;
            const n = Math.pow(2, zoom);
            const xTile = Math.floor((lon + 180) / 360 * n);
            const yTile = Math.floor(
                (1 - Math.log(Math.tan(lat * Math.PI / 180) +
                1 / Math.cos(lat * Math.PI / 180)) / Math.PI) / 2 * n
            );
            const tileUrl = `https://tile.openstreetmap.org/${zoom}/${xTile}/${yTile}.png`;

            const img = await loadImage(tileUrl);
            ctx.drawImage(img, x, y, mapSize, mapSize);
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 2;
            ctx.strokeRect(x, y, mapSize, mapSize);

            // Pin
            ctx.fillStyle = '#ef4444';
            ctx.beginPath();
            ctx.arc(x + mapSize / 2, y + mapSize / 2, 4, 0, Math.PI * 2);
            ctx.fill();
        } catch {
            ctx.font = 'bold 11px sans-serif';
            ctx.fillStyle = '#fff';
            ctx.textBaseline = 'middle';
            ctx.textAlign = 'center';
            ctx.fillText('MAPA', x + mapSize / 2, y + mapSize / 2);
            ctx.textAlign = 'start';
        }
    }

    // ===================================================================
    // PRECACHE & MANUAL SYNC
    // ===================================================================
    async function precacheInfraPhotos() {
        if (!state.infraId) {
            alert('Selecciona primero una infraestructura');
            return;
        }
        if (!navigator.onLine) {
            alert('Se necesita conexión a Internet para precargar las fotos');
            return;
        }

        const modal = $('#precache-modal');
        const bar = $('#precache-progress-bar');
        const text = $('#precache-progress-text');
        if (modal) {
            modal.classList.remove('hidden');
            if (bar) bar.style.width = '0%';
            if (text) text.textContent = 'Iniciando precarga...';
        }

        try {
            const result = await window.InfocampoOffline.precachePhotos(
                state.infraId,
                CFG.endpoints.fotosComparativas
            );

            if (text) {
                if (result.cached > 0) {
                    text.textContent = `${result.cached} foto${result.cached > 1 ? 's' : ''} precargada${result.cached > 1 ? 's' : ''} correctamente`;
                } else {
                    text.textContent = 'No hay fotos comparativas para precargar';
                }
            }
            if (bar) bar.style.width = '100%';

            // Update precache indicator
            updatePrecacheIndicator();

            setTimeout(() => { if (modal) modal.classList.add('hidden'); }, 2500);
        } catch (err) {
            if (text) text.textContent = 'Error: ' + err.message;
            setTimeout(() => { if (modal) modal.classList.add('hidden'); }, 3000);
        }
    }

    async function updatePrecacheIndicator() {
        if (!window.InfocampoOffline || !state.infraId) return;
        const hasCached = await window.InfocampoOffline.hasCachedPhotos(state.infraId);
        const indicator = $('#precache-indicator');
        if (indicator) {
            indicator.classList.toggle('hidden', !hasCached);
        }
    }

    async function triggerManualSync() {
        if (!navigator.onLine) {
            alert('Se necesita conexión a Internet para sincronizar');
            return;
        }
        if (!window.InfocampoOffline) return;
        await window.InfocampoOffline.syncQueue();
    }

    // ===================================================================
    // EVENTS
    // ===================================================================
    function bindEvents() {
        // Provincia / municipio filters
        if (filterProvincia) {
            filterProvincia.addEventListener('change', () => {
                loadMunicipios(filterProvincia.value);
                clearInfra();
            });
        }
        if (filterMunicipio) {
            filterMunicipio.addEventListener('change', () => {
                clearInfra();
            });
        }

        // Infrastructure search
        infraSearch.addEventListener('input', (e) => searchInfra(e.target.value.trim()));
        infraSearch.addEventListener('focus', () => searchInfra(infraSearch.value.trim()));
        infraClear.addEventListener('click', clearInfra);

        // Close search results on outside click
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.search-container')) {
                infraResults.classList.add('hidden');
            }
        });

        // Unidad de obra change
        unidadObra.addEventListener('change', () => {
            state.unidadObraId = unidadObra.value || null;
        });

        // Photo mode buttons
        btnAleatorias.addEventListener('click', () => openCamera('aleatorio'));
        btnComparativas.addEventListener('click', () => openCamera('comparativo'));

        // Map button
        btnVerMapa.addEventListener('click', openMapScreen);

        // Map events
        btnMapaBack.addEventListener('click', closeMapScreen);
        btnMapaVolver.addEventListener('click', closeMapScreen);
        btnCloseDetail.addEventListener('click', () => mapaDetailPanel.classList.add('hidden'));
        btnDetailNavegar.addEventListener('click', navigateToInfra);
        btnDetailAleatorio.addEventListener('click', () => startVisitFromMap('aleatorio'));
        btnDetailComparativo.addEventListener('click', () => startVisitFromMap('comparativo'));

        // Camera
        btnCamBack.addEventListener('click', closeCamera);
        btnShutter.addEventListener('click', captureFrame);

        // Ghost toggle
        btnGhostToggle.addEventListener('click', () => {
            if (!state.ghostUrl) return;
            state.ghostActive = !state.ghostActive;
            camGhost.classList.toggle('off', !state.ghostActive);
            btnGhostToggle.classList.toggle('active', state.ghostActive);
        });

        // Load previous photos
        btnLoadPrev.addEventListener('click', () => checkPreviousPhotos());

        // Previous photos modal
        btnClosePrev.addEventListener('click', () => modalPrevPhotos.classList.add('hidden'));
        btnSkipPrev.addEventListener('click', () => modalPrevPhotos.classList.add('hidden'));

        // Preview (legacy — photo now auto-accepts and returns to ficha)
        if (btnRetake) btnRetake.addEventListener('click', () => showScreen('ficha'));
        if (btnAccept) btnAccept.addEventListener('click', () => showScreen('ficha'));

        // Offline: precache button
        const btnPrecache = $('#btn-precache');
        if (btnPrecache) btnPrecache.addEventListener('click', precacheInfraPhotos);

        // Offline: manual sync button
        const btnSync = $('#btn-manual-sync');
        if (btnSync) btnSync.addEventListener('click', triggerManualSync);

        // Offline: close precache modal
        const btnClosePrecache = $('#btn-close-precache');
        if (btnClosePrecache) btnClosePrecache.addEventListener('click', () => {
            const modal = $('#precache-modal');
            if (modal) modal.classList.add('hidden');
        });

        // Offline: dismiss sync notification
        const notifClose = $('#sync-notif-close');
        if (notifClose) notifClose.addEventListener('click', () => {
            const notif = $('#sync-notification');
            if (notif) notif.classList.add('hidden');
        });
    }

    // ===================================================================
    // MAP SCREEN
    // ===================================================================
    let leafletMap = null;
    let mapMarkers = [];
    let mapSelectedInfra = null; // { id, nombre, codigo, lat, lon, registros }
    let mapUserMarker = null;

    async function openMapScreen() {
        showScreen('mapa');
        mapaDetailPanel.classList.add('hidden');

        // Initialize map if needed
        if (!leafletMap) {
            leafletMap = L.map('op-map', { zoomControl: false });
            L.control.zoom({ position: 'topright' }).addTo(leafletMap);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OSM',
                maxZoom: 19,
            }).addTo(leafletMap);

            // Set initial view to current GPS or Spain center
            if (state.gps.lat && state.gps.lon) {
                leafletMap.setView([state.gps.lat, state.gps.lon], 14);
            } else {
                leafletMap.setView([40.416775, -3.703790], 6);
            }
        }

        // Show user position on map
        updateUserPositionOnMap();

        // Load data
        await loadMapData();
    }

    function closeMapScreen() {
        showScreen('ficha');
    }

    function updateUserPositionOnMap() {
        if (!leafletMap || !state.gps.lat || !state.gps.lon) return;

        const userIcon = L.divIcon({
            className: 'user-location-marker',
            html: `<div style="width:16px;height:16px;border-radius:50%;background:#4285f4;
                    border:3px solid #fff;box-shadow:0 0 0 2px rgba(66,133,244,0.3),0 2px 6px rgba(0,0,0,0.3);"></div>`,
            iconSize: [16, 16],
            iconAnchor: [8, 8],
        });

        if (mapUserMarker) {
            mapUserMarker.setLatLng([state.gps.lat, state.gps.lon]);
        } else {
            mapUserMarker = L.marker([state.gps.lat, state.gps.lon], {
                icon: userIcon, zIndexOffset: 1000,
            }).addTo(leafletMap);
            mapUserMarker.bindTooltip('Tu ubicación', { direction: 'top', offset: [0, -10] });
        }
    }

    function haversineDistance(lat1, lon1, lat2, lon2) {
        const R = 6371000;
        const toRad = (d) => d * Math.PI / 180;
        const dLat = toRad(lat2 - lat1);
        const dLon = toRad(lon2 - lon1);
        const a = Math.sin(dLat / 2) ** 2 +
                  Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) ** 2;
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function formatDistance(meters) {
        if (meters < 1000) return Math.round(meters) + ' m';
        return (meters / 1000).toFixed(1) + ' km';
    }

    async function loadMapData() {
        try {
            // Load registros AND all infrastructures in parallel
            const [regRes, infraRes] = await Promise.all([
                fetch(`${CFG.endpoints.registrosMapa}?empresa_id=${CFG.empresaId}&limit=500`).then(r => r.json()),
                fetch(`${CFG.endpoints.infraestructuras}?empresa_id=${CFG.empresaId}&q=`).then(r => r.json()),
            ]);

            // Clear old markers
            mapMarkers.forEach(m => leafletMap.removeLayer(m));
            mapMarkers = [];

            // Build visited infra map from registros
            const byInfra = {};
            if (regRes.ok && regRes.registros) {
                regRes.registros.forEach(r => {
                    if (!byInfra[r.infra_id]) {
                        byInfra[r.infra_id] = {
                            id: r.infra_id,
                            nombre: r.infra_nombre,
                            codigo: r.codigo_unico,
                            tipo: r.infra_tipo,
                            lat: parseFloat(r.lat_teorica),
                            lon: parseFloat(r.lon_teorica),
                            registros: [],
                        };
                    }
                    byInfra[r.infra_id].registros.push(r);
                });
            }

            // Build full infra list (including never-visited ones)
            const allInfras = {};
            if (infraRes.ok && infraRes.infraestructuras) {
                infraRes.infraestructuras.forEach(inf => {
                    const id = inf.id;
                    if (byInfra[id]) {
                        allInfras[id] = byInfra[id];
                    } else {
                        allInfras[id] = {
                            id: id,
                            nombre: inf.nombre,
                            codigo: inf.codigo_unico,
                            tipo: inf.tipo,
                            lat: parseFloat(inf.lat_teorica),
                            lon: parseFloat(inf.lon_teorica),
                            registros: [],
                        };
                    }
                });
            }
            // Also add visited ones that may not have been returned by infra search
            Object.keys(byInfra).forEach(id => {
                if (!allInfras[id]) allInfras[id] = byInfra[id];
            });

            const bounds = [];
            const stateColors = {
                'bajo': '#22c55e', 'medio': '#eab308', 'critico': '#ef4444',
            };

            Object.values(allInfras).forEach(infra => {
                if (!infra.lat || !infra.lon) return;
                bounds.push([infra.lat, infra.lon]);

                const hasPhotos = infra.registros.length > 0;

                if (hasPhotos) {
                    // Visited: colored circle with photo count
                    const lastState = infra.registros[0]?.estado_incidencia || 'bajo';
                    const color = stateColors[lastState] || '#9ca3af';
                    const numPhotos = infra.registros.length;

                    const icon = L.divIcon({
                        className: 'op-marker',
                        html: `<div style="width:32px;height:32px;border-radius:50%;background:${color};
                                border:3px solid rgba(255,255,255,0.9);box-shadow:0 2px 8px rgba(0,0,0,0.4);
                                display:flex;align-items:center;justify-content:center;
                                font-size:11px;font-weight:800;color:#fff;">${numPhotos}</div>`,
                        iconSize: [32, 32],
                        iconAnchor: [16, 16],
                    });

                    const marker = L.marker([infra.lat, infra.lon], { icon }).addTo(leafletMap);
                    marker.on('click', () => showInfraDetail(infra));
                    mapMarkers.push(marker);
                } else {
                    // Not visited: gray pin icon
                    const icon = L.divIcon({
                        className: 'op-marker-unvisited',
                        html: `<div style="width:28px;height:28px;border-radius:50%;background:#9ca3af;
                                border:3px solid rgba(255,255,255,0.9);box-shadow:0 2px 8px rgba(0,0,0,0.3);
                                display:flex;align-items:center;justify-content:center;
                                font-size:13px;color:#fff;">
                                <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor">
                                    <path d="M8 0a5 5 0 0 0-5 5c0 4.5 5 11 5 11s5-6.5 5-11a5 5 0 0 0-5-5zm0 7.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z"/>
                                </svg>
                            </div>`,
                        iconSize: [28, 28],
                        iconAnchor: [14, 14],
                    });

                    const marker = L.marker([infra.lat, infra.lon], { icon, zIndexOffset: -50 }).addTo(leafletMap);
                    marker.on('click', () => showInfraDetail(infra));

                    // Show name on hover
                    let tooltipText = escHtml(infra.nombre);
                    if (state.gps.lat && state.gps.lon) {
                        const dist = haversineDistance(state.gps.lat, state.gps.lon, infra.lat, infra.lon);
                        tooltipText += `<br><span style="color:#4285f4;">${formatDistance(dist)}</span>`;
                    }
                    marker.bindTooltip(tooltipText, { direction: 'top', offset: [0, -10] });

                    mapMarkers.push(marker);
                }
            });

            // Add user position to bounds
            if (state.gps.lat && state.gps.lon) {
                bounds.push([state.gps.lat, state.gps.lon]);
            }

            // Fit bounds
            if (bounds.length > 0) {
                leafletMap.fitBounds(bounds, { padding: [50, 50], maxZoom: 16 });
            }

            // Update subtitle
            const sub = $('#mapa-subtitle');
            if (sub) {
                const totalInfra = Object.keys(allInfras).length;
                const visitedCount = Object.values(allInfras).filter(i => i.registros.length > 0).length;
                const totalReg = regRes.ok ? (regRes.registros?.length || 0) : 0;
                sub.textContent = `${totalInfra} infraestructura${totalInfra !== 1 ? 's' : ''} · ${visitedCount} visitada${visitedCount !== 1 ? 's' : ''} · ${totalReg} foto${totalReg !== 1 ? 's' : ''}`;
            }

        } catch (err) {
            console.warn('Error loading map data:', err);
            const sub = $('#mapa-subtitle');
            if (sub) sub.textContent = 'Error al cargar datos del mapa';
        }
    }

    function showInfraDetail(infra) {
        mapSelectedInfra = infra;
        detailInfraName.textContent = infra.nombre;
        detailInfraCode.textContent = infra.codigo;

        // Show distance from user
        if (state.gps.lat && state.gps.lon && infra.lat && infra.lon) {
            const dist = haversineDistance(state.gps.lat, state.gps.lon, infra.lat, infra.lon);
            detailInfraDistance.textContent = `📍 A ${formatDistance(dist)} de tu ubicación`;
            detailInfraDistance.classList.remove('hidden');
        } else {
            detailInfraDistance.classList.add('hidden');
        }

        // Build detail body
        let html = '';
        const regs = infra.registros;

        // Show comparativas first, then aleatorias
        const comparativas = regs.filter(r => r.tipo_foto === 'comparativo');
        const aleatorias = regs.filter(r => r.tipo_foto !== 'comparativo');

        if (comparativas.length > 0) {
            html += `<div class="mapa-detail-meta" style="margin-top:4px;color:#c084fc;">
                <strong>${comparativas.length} foto${comparativas.length !== 1 ? 's' : ''} comparativa${comparativas.length !== 1 ? 's' : ''}</strong>
            </div>`;
            comparativas.forEach(r => { html += buildPhotoCard(r); });
        }

        if (aleatorias.length > 0) {
            html += `<div class="mapa-detail-meta" style="margin-top:8px;color:#60a5fa;">
                <strong>${aleatorias.length} foto${aleatorias.length !== 1 ? 's' : ''} aleatoria${aleatorias.length !== 1 ? 's' : ''}</strong>
            </div>`;
            aleatorias.slice(0, 6).forEach(r => { html += buildPhotoCard(r); });
            if (aleatorias.length > 6) {
                html += `<div class="mapa-detail-meta">y ${aleatorias.length - 6} más...</div>`;
            }
        }

        if (regs.length === 0) {
            html = '<div class="mapa-detail-meta" style="text-align:center;padding:16px;">Sin fotos — Infraestructura no visitada</div>';
        }

        mapaDetailBody.innerHTML = html;
        mapaDetailPanel.classList.remove('hidden');

        // Center map on infra
        if (infra.lat && infra.lon) {
            leafletMap.setView([infra.lat, infra.lon], 16, { animate: true });
        }
    }

    function navigateToInfra() {
        if (!mapSelectedInfra || !mapSelectedInfra.lat || !mapSelectedInfra.lon) return;

        const lat = mapSelectedInfra.lat;
        const lon = mapSelectedInfra.lon;
        const name = encodeURIComponent(mapSelectedInfra.nombre);

        // Open Google Maps navigation (works on Android and iOS)
        const url = `https://www.google.com/maps/dir/?api=1&destination=${lat},${lon}&travelmode=driving`;
        window.open(url, '_blank');
    }

    function buildPhotoCard(r) {
        const fecha = new Date(r.fecha).toLocaleString('es-ES', {
            timeZone: 'Europe/Madrid', day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit',
        });
        const isComp = r.tipo_foto === 'comparativo';
        const seqLabel = isComp && r.secuencia_comparativa ? `W${r.secuencia_comparativa}` : '';
        return `<div class="mapa-detail-photo">
            <img src="${escHtml(r.url_cloudinary)}" alt="${escHtml(r.nombre_archivo || '')}" loading="lazy">
            <div class="mapa-detail-photo-info">
                <span>${fecha} · ${escHtml(r.usuario_nombre)} ${seqLabel ? '· ' + seqLabel : ''}</span>
                <span class="estado-badge ${r.estado_incidencia}">${r.estado_incidencia.toUpperCase()}</span>
            </div>
        </div>
        ${r.observaciones ? '<div class="mapa-detail-obs">' + escHtml(r.observaciones) + '</div>' : ''}`;
    }

    function startVisitFromMap(mode) {
        if (!mapSelectedInfra) return;

        // Select the infrastructure in the ficha
        selectInfra(mapSelectedInfra.id, mapSelectedInfra.nombre, mapSelectedInfra.codigo);

        // Close map and detail panel
        mapaDetailPanel.classList.add('hidden');
        showScreen('ficha');

        // Auto-open camera after a brief delay for the screen transition
        setTimeout(() => openCamera(mode), 200);
    }

    // ===================================================================
    // UTILITIES
    // ===================================================================
    function escHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    function sanitizeFilename(name) {
        return name.replace(/[^a-zA-Z0-9_\-áéíóúñÁÉÍÓÚÑ]/g, '_').substring(0, 60);
    }

    function canvasToBlob(canvas, type, quality) {
        return new Promise((resolve) => {
            canvas.toBlob(resolve, type, quality);
        });
    }

    function loadImage(src) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => resolve(img);
            img.onerror = reject;
            img.src = src;
        });
    }

    function roundRect(ctx, x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.lineTo(x + w - r, y);
        ctx.quadraticCurveTo(x + w, y, x + w, y + r);
        ctx.lineTo(x + w, y + h - r);
        ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
        ctx.lineTo(x + r, y + h);
        ctx.quadraticCurveTo(x, y + h, x, y + h - r);
        ctx.lineTo(x, y + r);
        ctx.quadraticCurveTo(x, y, x + r, y);
        ctx.closePath();
    }

    // ===================================================================
    // PWA INSTALL
    // ===================================================================
    let deferredInstallPrompt = null;

    function initPWAInstall() {
        const banner = $('#install-banner');
        const btnInstall = $('#btn-install-app');
        const btnDismiss = $('#btn-install-dismiss');

        if (!banner || !btnInstall) return;

        // Listen for the browser's install prompt
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredInstallPrompt = e;

            // Only show if user hasn't dismissed recently
            const dismissed = localStorage.getItem('pwa-install-dismissed');
            if (dismissed) {
                const dismissedAt = parseInt(dismissed, 10);
                // Show again after 7 days
                if (Date.now() - dismissedAt < 7 * 24 * 60 * 60 * 1000) return;
            }

            banner.classList.remove('hidden');
        });

        btnInstall.addEventListener('click', async () => {
            if (!deferredInstallPrompt) {
                // Fallback: show instructions for iOS/Safari
                showInstallInstructions();
                return;
            }

            deferredInstallPrompt.prompt();
            const { outcome } = await deferredInstallPrompt.userChoice;
            deferredInstallPrompt = null;

            if (outcome === 'accepted') {
                banner.classList.add('hidden');
                showNotification('App instalada correctamente');
            }
        });

        if (btnDismiss) {
            btnDismiss.addEventListener('click', () => {
                banner.classList.add('hidden');
                localStorage.setItem('pwa-install-dismissed', String(Date.now()));
            });
        }

        // Detect if already installed (standalone mode)
        if (window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone === true) {
            banner.classList.add('hidden');
            return;
        }

        // For iOS where beforeinstallprompt doesn't fire
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
        if (isIOS && !window.navigator.standalone) {
            const dismissed = localStorage.getItem('pwa-install-dismissed');
            if (!dismissed || (Date.now() - parseInt(dismissed, 10)) > 7 * 24 * 60 * 60 * 1000) {
                banner.classList.remove('hidden');
            }
        }
    }

    function showInstallInstructions() {
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
        if (isIOS) {
            alert('Para instalar FotoGPS en tu iPhone:\n\n1. Pulsa el botón Compartir (cuadrado con flecha)\n2. Desplázate y pulsa "Añadir a pantalla de inicio"\n3. Confirma pulsando "Añadir"');
        } else {
            alert('Para instalar FotoGPS:\n\n1. Abre el menú del navegador (tres puntos)\n2. Pulsa "Instalar aplicación" o "Añadir a pantalla de inicio"');
        }
    }

    // ===================================================================
    // START
    // ===================================================================
    document.addEventListener('DOMContentLoaded', () => {
        init();
        initPWAInstall();
    });

})();
