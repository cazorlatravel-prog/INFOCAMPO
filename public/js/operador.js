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
        countTotal: 0, // contador global por infraestructura
        prevPhotos: [], // fotos comparativas de visita anterior
        situacionIdx: 0, // 0=antes, 1=durante, 2=despues
        // Annotation
        annotation: null,          // { x, y, text, radius } — canvas pixel coords
        annotationMode: false,
        pendingFilename: null,
        baseImageData: null,       // ImageData snapshot without annotation
    };

    const SITUACIONES = ['antes', 'durante', 'despues'];
    const SITUACIONES_UI = ['ANTES', 'DURANTE', 'DESPUÉS'];

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
        visitas: $('#screen-visitas'),
        editarVisita: $('#screen-editar-visita'),
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
    const btnRetake      = $('#btn-retake');
    const btnAccept      = $('#btn-accept');
    const btnAnnotate    = $('#btn-annotate');

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

    // Visitas
    const btnMisVisitas      = $('#btn-mis-visitas');
    const btnVisitasBack     = $('#btn-visitas-back');
    const visitasBody        = $('#visitas-body');
    const guardarVisitaSection = $('#guardar-visita-section');
    const btnGuardarVisita   = $('#btn-guardar-visita');
    const btnEditarBack      = $('#btn-editar-back');
    const btnGuardarEdicion  = $('#btn-guardar-edicion');
    const btnAñadirFotoVisita = $('#btn-añadir-foto-visita');

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

        // Si hay fallos, mostrar alerta persistente de no borrar fotos
        if (fail > 0) {
            showUploadFailAlert(fail);
            // No auto-ocultar para que el operador lo vea
            setTimeout(() => notification.classList.add('hidden'), 10000);
        } else {
            setTimeout(() => notification.classList.add('hidden'), 5000);
        }
    }

    function showUploadFailAlert(failCount) {
        // Crear o actualizar alerta persistente
        let alert = $('#upload-fail-alert');
        if (!alert) {
            alert = document.createElement('div');
            alert.id = 'upload-fail-alert';
            alert.className = 'upload-fail-alert';
            document.body.appendChild(alert);
        }
        alert.innerHTML =
            '<div class="ufa-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>' +
            '<div class="ufa-content">' +
            '<strong>' + failCount + ' foto' + (failCount > 1 ? 's' : '') + ' no se ' + (failCount > 1 ? 'pudieron' : 'pudo') + ' subir</strong>' +
            '<div class="ufa-detail">NO borres las fotos de tu galería. Se reintentará automáticamente. Tu administrador ha sido notificado.</div>' +
            '</div>' +
            '<button class="ufa-close" onclick="this.parentElement.classList.add(\'hidden\')"><i class="bi bi-x"></i></button>';
        alert.classList.remove('hidden');
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
        // Reiniciar contadores al cambiar de infraestructura
        state.countAleatorias = 0;
        state.countComparativas = 0;
        state.countTotal = 0;
        state.seqComparativa = 0;
        state.photos = [];
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
        state.countAleatorias = 0;
        state.countComparativas = 0;
        state.countTotal = 0;
        state.seqComparativa = 0;
        state.photos = [];
        state.prevPhotos = [];
        state.ghostUrl = null;
        state.ghostActive = false;
        state.situacionIdx = 0;
        infraIdInput.value = '';
        infraSearch.value = '';
        infraSearch.classList.remove('hidden');
        infraSelected.classList.add('hidden');
        countAleatorias.textContent = '0';
        countComparativas.textContent = '0';
        galleryGrid.innerHTML = '';
        gallerySection.classList.add('hidden');
        // Reset situación selector in Ficha
        const sitSel = $('#situacion-selector');
        if (sitSel) {
            sitSel.querySelectorAll('.situacion-option').forEach(b => b.classList.remove('active'));
            const firstBtn = sitSel.querySelector('[data-sit="0"]');
            if (firstBtn) firstBtn.classList.add('active');
        }
        const obsField = $('#observaciones-general');
        if (obsField) obsField.value = '';
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

        // Show/hide guardar visita button (visible when infra selected and there are photos)
        if (guardarVisitaSection) {
            const hasPhotos = state.photos.length > 0;
            guardarVisitaSection.classList.toggle('hidden', !enabled || !hasPhotos);
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

        // Generate filename: NombreInfra_ALE_ANT_20260301_001
        const sitCodes = { antes: 'ANT', durante: 'DUR', despues: 'DES' };
        const sitCode = sitCodes[SITUACIONES[state.situacionIdx]] || 'ANT';
        const modeCode = state.currentMode === 'comparativo' ? 'COMP' : 'ALE';

        state.countTotal++;
        if (state.currentMode === 'comparativo') {
            state.seqComparativa++;
            state.countComparativas++;
        } else {
            state.countAleatorias++;
        }

        // Date stamp (YYYYMMDD Madrid timezone) to avoid filename collisions across visits
        const nowMadrid = new Date().toLocaleString('en-CA', { timeZone: 'Europe/Madrid', year: 'numeric', month: '2-digit', day: '2-digit' }).replace(/-/g, '');

        const seqNum = String(state.countTotal).padStart(3, '0');
        const filename = `${sanitizeFilename(state.infraName)}_${modeCode}_${sitCode}_${nowMadrid}_${seqNum}`;

        // Apply watermark directly on previewCanvas (used for blob generation)
        await applyWatermark(camCapture, previewCanvas, {
            lat: state.gps.lat,
            lon: state.gps.lon,
            infraName: state.infraName,
            infraCode: state.infraCode,
            empresaName: CFG.empresaName,
            situacion: SITUACIONES_UI[state.situacionIdx],
            filename: filename,
            mode: state.currentMode,
            seq: state.currentMode === 'comparativo' ? state.seqComparativa : null,
        });

        // Save base image for annotation overlay (before any annotation)
        const prevCtx = previewCanvas.getContext('2d');
        state.baseImageData = prevCtx.getImageData(0, 0, previewCanvas.width, previewCanvas.height);
        state.pendingFilename = filename;
        state.annotation = null;
        state.annotationMode = false;

        // Show preview screen for optional annotation before uploading
        showScreen('preview');
        if (previewFilename) previewFilename.textContent = filename;
        resetAnnotationUI();
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
            estado_incidencia: SITUACIONES[state.situacionIdx],
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
        updateButtonState();
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
        // Lines: Empresa, Infraestructura, Situación, Fecha, Coordenadas
        const fontSize = Math.max(14, Math.round(h * 0.02));
        const lineHeight = fontSize * 1.5;
        const numLines = 5;
        const padding = 16;
        const blockHeight = lineHeight * numLines + padding * 2;

        // Prepare text lines first to measure widths
        const empresaStr = meta.empresaName || '';
        const infraStr = meta.infraName || '';
        const situacionStr = meta.situacion ? `Situación: ${meta.situacion}` : '';
        const dateStr = formatDateMadrid();
        const latStr = meta.lat != null ? meta.lat.toFixed(7) : '--';
        const lonStr = meta.lon != null ? meta.lon.toFixed(7) : '--';
        const coordStr = `ETRS89: ${latStr}, ${lonStr}`;

        // Measure max text width to auto-size block
        ctx.font = `bold ${fontSize}px -apple-system, sans-serif`;
        const boldWidths = [ctx.measureText(empresaStr).width, ctx.measureText(situacionStr).width];
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

        // Line 3: Situación
        ctx.font = `bold ${fontSize}px -apple-system, sans-serif`;
        ctx.fillText(situacionStr, textX, textY);
        textY += lineHeight;

        // Line 4: Fecha
        ctx.font = `${fontSize}px -apple-system, sans-serif`;
        ctx.fillText(dateStr, textX, textY);
        textY += lineHeight;

        // Line 5: Coordenadas
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
        document.getElementById('btn-stop-nav').addEventListener('click', stopNavigation);

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

        // Preview — annotation + accept/retake
        if (btnRetake) btnRetake.addEventListener('click', retakePhoto);
        if (btnAccept) btnAccept.addEventListener('click', acceptPhoto);
        if (btnAnnotate) btnAnnotate.addEventListener('click', toggleAnnotationMode);

        // Canvas click for annotation placement
        previewCanvas.addEventListener('click', handlePreviewCanvasClick);

        // Annotation text input
        const annotationText = $('#annotation-text');
        if (annotationText) annotationText.addEventListener('input', updateAnnotationText);

        // Annotation size slider
        const annotationSize = $('#annotation-size');
        if (annotationSize) annotationSize.addEventListener('input', updateAnnotationSize);

        // Clear annotation
        const btnAnnotationClear = $('#btn-annotation-clear');
        if (btnAnnotationClear) btnAnnotationClear.addEventListener('click', clearAnnotation);

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

        // Guardar visita (finalizar y resetear)
        if (btnGuardarVisita) btnGuardarVisita.addEventListener('click', finalizarVisita);

        // Selector de situación en Ficha
        const situacionSelector = $('#situacion-selector');
        if (situacionSelector) {
            situacionSelector.querySelectorAll('.situacion-option').forEach(btn => {
                btn.addEventListener('click', () => {
                    const sitIdx = parseInt(btn.dataset.sit);
                    state.situacionIdx = sitIdx;
                    situacionSelector.querySelectorAll('.situacion-option').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                });
            });
        }

        // Mis Visitas
        if (btnMisVisitas) btnMisVisitas.addEventListener('click', openVisitasScreen);
        if (btnVisitasBack) btnVisitasBack.addEventListener('click', () => showScreen('ficha'));
        const btnVisitasVolver = $('#btn-visitas-volver');
        if (btnVisitasVolver) btnVisitasVolver.addEventListener('click', () => showScreen('ficha'));

        // Editar visita
        if (btnEditarBack) btnEditarBack.addEventListener('click', () => {
            openVisitasScreen(); // volver al listado y refrescar
        });
        if (btnGuardarEdicion) btnGuardarEdicion.addEventListener('click', guardarEdicion);
        if (btnAñadirFotoVisita) btnAñadirFotoVisita.addEventListener('click', añadirFotoDesdeVisita);
    }

    // ===================================================================
    // MAP SCREEN
    // ===================================================================
    let leafletMap = null;
    let mapMarkers = [];
    let mapSelectedInfra = null; // { id, nombre, codigo, lat, lon, registros }
    let mapUserMarker = null;
    let mapKmlLayers = []; // KML layer groups
    let mapActiveBaseLayer = null;
    const mapBaseLayers = {};

    // Navigation mode state
    let navActive = false;
    let navTarget = null;       // { lat, lon, nombre, codigo }
    let navLine = null;         // L.polyline
    let navWatchId = null;      // geolocation watchPosition ID for navigation
    let navLastBeepTime = 0;    // timestamp of last beep
    let navAudioCtx = null;     // Web Audio API context

    function getNavAudioCtx() {
        if (!navAudioCtx) navAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
        return navAudioCtx;
    }

    function playBeeps(count) {
        const ctx = getNavAudioCtx();
        for (let i = 0; i < count; i++) {
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.type = 'sine';
            osc.frequency.value = 880;
            gain.gain.value = 0.5;
            const start = ctx.currentTime + i * 0.25;
            osc.start(start);
            osc.stop(start + 0.12);
        }
    }

    async function openMapScreen() {
        showScreen('mapa');
        mapaDetailPanel.classList.add('hidden');

        // Initialize map if needed
        if (!leafletMap) {
            leafletMap = L.map('op-map', { zoomControl: false });
            L.control.zoom({ position: 'topright' }).addTo(leafletMap);

            // Base layers
            mapBaseLayers.osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OSM', maxZoom: 19,
            });
            mapBaseLayers.ortofoto = L.tileLayer.wms('https://www.juntadeandalucia.es/medioambiente/mapwms/REDIAM_Ortofoto_2020?', {
                layers: 'orto_RGBlr_2020_raster', format: 'image/png', transparent: false,
                attribution: '&copy; Junta de Andalucía', maxZoom: 20,
            });
            mapBaseLayers.topografico = L.tileLayer.wms('https://www.ideandalucia.es/wms/mta10r_2001-2013?', {
                layers: 'mta10r_2001-2013', format: 'image/png', transparent: false,
                attribution: '&copy; IDEAndalucía', maxZoom: 20,
            });

            mapActiveBaseLayer = mapBaseLayers.osm;
            mapActiveBaseLayer.addTo(leafletMap);

            // Layer switcher control (top-left)
            const layerControl = L.control({ position: 'topleft' });
            layerControl.onAdd = function() {
                const div = L.DomUtil.create('div', 'op-layer-switcher');
                div.innerHTML =
                    '<select id="op-base-layer-select" style="font-size:11px;padding:4px 6px;border-radius:6px;border:1px solid #ccc;background:#fff;box-shadow:0 2px 6px rgba(0,0,0,0.2);cursor:pointer;">' +
                    '<option value="osm">Mapa</option>' +
                    '<option value="ortofoto">Ortofoto</option>' +
                    '<option value="topografico">Topográfico</option>' +
                    '</select>';
                L.DomEvent.disableClickPropagation(div);
                return div;
            };
            layerControl.addTo(leafletMap);

            document.getElementById('op-base-layer-select').addEventListener('change', function() {
                if (mapActiveBaseLayer) leafletMap.removeLayer(mapActiveBaseLayer);
                mapActiveBaseLayer = mapBaseLayers[this.value] || mapBaseLayers.osm;
                mapActiveBaseLayer.addTo(leafletMap);
                mapActiveBaseLayer.bringToBack();
            });

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
        if (navActive) stopNavigation();
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
                'antes': '#3b82f6', 'durante': '#f59e0b', 'despues': '#22c55e',
            };

            Object.values(allInfras).forEach(infra => {
                if (!infra.lat || !infra.lon) return;
                bounds.push([infra.lat, infra.lon]);

                const hasPhotos = infra.registros.length > 0;

                if (hasPhotos) {
                    // Visited: colored circle with photo count
                    const lastState = infra.registros[0]?.estado_incidencia || 'antes';
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

            // Load KML layers from DB
            loadMapKmlLayers();

        } catch (err) {
            console.warn('Error loading map data:', err);
            const sub = $('#mapa-subtitle');
            if (sub) sub.textContent = 'Error al cargar datos del mapa';
        }
    }

    async function loadMapKmlLayers() {
        if (!CFG.endpoints.capasKml) return;
        try {
            // Remove old KML layers
            mapKmlLayers.forEach(lg => leafletMap.removeLayer(lg));
            mapKmlLayers = [];

            const res = await fetch(`${CFG.endpoints.capasKml}?empresa_id=${CFG.empresaId}`);
            const data = await res.json();
            if (!data.ok || !data.capas || data.capas.length === 0) return;

            data.capas.forEach(capa => {
                const group = L.layerGroup().addTo(leafletMap);
                mapKmlLayers.push(group);
                renderKmlToLayer(capa.contenido_kml, group, capa.color || '#8b5cf6');
            });
        } catch (err) {
            console.warn('Error loading KML layers:', err);
        }
    }

    function renderKmlToLayer(kmlText, layerGroup, color) {
        const parser = new DOMParser();
        const xmlDoc = parser.parseFromString(kmlText, 'text/xml');
        const placemarks = xmlDoc.querySelectorAll('Placemark');

        placemarks.forEach(pm => {
            const nameEl = pm.querySelector('name');
            const nombre = nameEl ? nameEl.textContent.trim() : '';
            const descEl = pm.querySelector('description');
            const desc = descEl ? descEl.textContent.trim() : '';

            let popupContent = '<div style="max-width:220px;">';
            if (nombre) popupContent += `<strong style="color:${color};">${nombre}</strong><br>`;
            if (desc) popupContent += `<small>${desc.substring(0, 120)}</small><br>`;
            popupContent += `<span style="display:inline-block;font-size:0.6rem;font-weight:700;padding:1px 6px;border-radius:4px;background:${color};color:#fff;">KML</span>`;
            popupContent += '</div>';

            // Points
            const pointEl = pm.querySelector('Point coordinates');
            if (pointEl) {
                const coords = pointEl.textContent.trim().split(',');
                if (coords.length >= 2) {
                    const lat = parseFloat(coords[1]);
                    const lon = parseFloat(coords[0]);
                    if (!isNaN(lat) && !isNaN(lon)) {
                        const icon = L.divIcon({
                            className: 'kml-marker',
                            html: `<div style="width:12px;height:12px;border-radius:50%;background:${color};border:2px solid #fff;box-shadow:0 1px 3px rgba(0,0,0,0.3);"></div>`,
                            iconSize: [12, 12],
                            iconAnchor: [6, 6],
                        });
                        L.marker([lat, lon], { icon, zIndexOffset: -100 })
                            .bindPopup(popupContent)
                            .addTo(layerGroup);
                    }
                }
            }

            // LineStrings
            const lineEl = pm.querySelector('LineString coordinates');
            if (lineEl) {
                const lineCoords = parseKmlCoords(lineEl.textContent);
                if (lineCoords.length > 0) {
                    L.polyline(lineCoords, { color, weight: 3, opacity: 0.8 })
                        .bindPopup(popupContent)
                        .addTo(layerGroup);
                }
            }

            // Polygons
            const polyEl = pm.querySelector('Polygon outerBoundaryIs LinearRing coordinates');
            if (polyEl) {
                const polyCoords = parseKmlCoords(polyEl.textContent);
                if (polyCoords.length > 0) {
                    L.polygon(polyCoords, { color, fillColor: color, fillOpacity: 0.15, weight: 2 })
                        .bindPopup(popupContent)
                        .addTo(layerGroup);
                }
            }
        });
    }

    function parseKmlCoords(text) {
        const coords = [];
        text.trim().split(/\s+/).forEach(t => {
            const parts = t.split(',');
            if (parts.length >= 2) {
                const lon = parseFloat(parts[0]);
                const lat = parseFloat(parts[1]);
                if (!isNaN(lat) && !isNaN(lon)) coords.push([lat, lon]);
            }
        });
        return coords;
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

        navTarget = {
            lat: mapSelectedInfra.lat,
            lon: mapSelectedInfra.lon,
            nombre: mapSelectedInfra.nombre,
            codigo: mapSelectedInfra.codigo,
        };
        navActive = true;
        navLastBeepTime = 0;

        // Hide detail panel
        mapaDetailPanel.classList.add('hidden');

        // Show navigation overlay
        const overlay = document.getElementById('nav-overlay');
        if (overlay) overlay.classList.remove('hidden');

        // Resume AudioContext if suspended (mobile requires user gesture)
        if (navAudioCtx && navAudioCtx.state === 'suspended') navAudioCtx.resume();

        // Start dedicated GPS watch for navigation
        if (navWatchId !== null) navigator.geolocation.clearWatch(navWatchId);
        navWatchId = navigator.geolocation.watchPosition(
            (pos) => updateNavigation(pos.coords.latitude, pos.coords.longitude),
            () => {},
            { enableHighAccuracy: true, maximumAge: 2000 }
        );

        // Do initial update if GPS already available
        if (state.gps.lat && state.gps.lon) {
            updateNavigation(state.gps.lat, state.gps.lon);
        }

        // Zoom map to show both user and target
        fitNavBounds();
    }

    function updateNavigation(lat, lon) {
        if (!navActive || !navTarget) return;

        // Update user marker
        state.gps.lat = lat;
        state.gps.lon = lon;
        updateUserPositionOnMap();

        // Draw/update line from user to target
        const userLatLng = [lat, lon];
        const targetLatLng = [navTarget.lat, navTarget.lon];

        if (navLine) {
            navLine.setLatLngs([userLatLng, targetLatLng]);
        } else {
            navLine = L.polyline([userLatLng, targetLatLng], {
                color: '#dc3545',
                weight: 3,
                dashArray: '8, 8',
                opacity: 0.8,
            }).addTo(leafletMap);
        }

        // Calculate distance
        const dist = haversineDistance(lat, lon, navTarget.lat, navTarget.lon);

        // Update overlay UI
        const distEl = document.getElementById('nav-distance');
        const nameEl = document.getElementById('nav-target-name');
        if (distEl) distEl.textContent = formatDistance(dist);
        if (nameEl) nameEl.textContent = navTarget.nombre;

        // Color based on proximity
        const overlay = document.getElementById('nav-overlay');
        if (overlay) {
            overlay.classList.remove('nav-far', 'nav-near', 'nav-close', 'nav-arrived');
            if (dist <= 5) overlay.classList.add('nav-arrived');
            else if (dist <= 10) overlay.classList.add('nav-close');
            else if (dist <= 20) overlay.classList.add('nav-near');
            else overlay.classList.add('nav-far');
        }

        // Beep logic (every 3 seconds max to avoid spam)
        const now = Date.now();
        if (now - navLastBeepTime > 3000) {
            if (dist <= 5) {
                playBeeps(3);
                navLastBeepTime = now;
            } else if (dist <= 10) {
                playBeeps(2);
                navLastBeepTime = now;
            } else if (dist <= 20) {
                playBeeps(1);
                navLastBeepTime = now;
            }
        }
    }

    function fitNavBounds() {
        if (!leafletMap || !navTarget) return;
        const bounds = [[navTarget.lat, navTarget.lon]];
        if (state.gps.lat && state.gps.lon) {
            bounds.push([state.gps.lat, state.gps.lon]);
        }
        leafletMap.fitBounds(bounds, { padding: [80, 80], maxZoom: 18 });
    }

    function stopNavigation() {
        navActive = false;
        navTarget = null;

        if (navWatchId !== null) {
            navigator.geolocation.clearWatch(navWatchId);
            navWatchId = null;
        }

        if (navLine) {
            leafletMap.removeLayer(navLine);
            navLine = null;
        }

        const overlay = document.getElementById('nav-overlay');
        if (overlay) overlay.classList.add('hidden');
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

        // Stop navigation if active
        if (navActive) stopNavigation();

        // Select the infrastructure in the ficha
        selectInfra(mapSelectedInfra.id, mapSelectedInfra.nombre, mapSelectedInfra.codigo);

        // Close map and detail panel
        mapaDetailPanel.classList.add('hidden');
        showScreen('ficha');

        // Auto-open camera after a brief delay for the screen transition
        setTimeout(() => openCamera(mode), 200);
    }

    // ===================================================================
    // ANNOTATION
    // ===================================================================

    /**
     * Reset annotation UI elements to initial state.
     */
    function resetAnnotationUI() {
        const toolbar = $('#annotation-toolbar');
        const inputWrap = $('#annotation-input-wrap');
        const sizeWrap = $('#annotation-size-wrap');
        const hint = $('#annotation-hint');
        const textInput = $('#annotation-text');
        const sizeSlider = $('#annotation-size');

        if (toolbar) toolbar.classList.add('hidden');
        if (inputWrap) inputWrap.classList.add('hidden');
        if (sizeWrap) sizeWrap.classList.add('hidden');
        if (hint) hint.classList.remove('hidden');
        if (textInput) textInput.value = '';
        if (sizeSlider) sizeSlider.value = 4;
        if (btnAnnotate) btnAnnotate.classList.remove('active');
        previewCanvas.classList.remove('annotation-active');
    }

    /**
     * Toggle annotation mode on/off.
     */
    function toggleAnnotationMode() {
        state.annotationMode = !state.annotationMode;
        const toolbar = $('#annotation-toolbar');

        if (state.annotationMode) {
            if (btnAnnotate) btnAnnotate.classList.add('active');
            if (toolbar) toolbar.classList.remove('hidden');
            previewCanvas.classList.add('annotation-active');
        } else {
            if (btnAnnotate) btnAnnotate.classList.remove('active');
            if (toolbar) toolbar.classList.add('hidden');
            previewCanvas.classList.remove('annotation-active');
        }
    }

    /**
     * Convert screen click/touch coordinates to canvas pixel coordinates,
     * accounting for object-fit: contain letterboxing.
     */
    function getCanvasCoords(canvas, clientX, clientY) {
        const rect = canvas.getBoundingClientRect();
        const canvasRatio = canvas.width / canvas.height;
        const displayRatio = rect.width / rect.height;

        let drawWidth, drawHeight, offsetX, offsetY;

        if (canvasRatio > displayRatio) {
            drawWidth = rect.width;
            drawHeight = rect.width / canvasRatio;
            offsetX = 0;
            offsetY = (rect.height - drawHeight) / 2;
        } else {
            drawHeight = rect.height;
            drawWidth = rect.height * canvasRatio;
            offsetX = (rect.width - drawWidth) / 2;
            offsetY = 0;
        }

        const x = clientX - rect.left - offsetX;
        const y = clientY - rect.top - offsetY;

        if (x < 0 || x > drawWidth || y < 0 || y > drawHeight) {
            return null;
        }

        return {
            x: (x / drawWidth) * canvas.width,
            y: (y / drawHeight) * canvas.height,
        };
    }

    /**
     * Handle click on preview canvas to place annotation circle.
     */
    function handlePreviewCanvasClick(e) {
        if (!state.annotationMode) return;

        const coords = getCanvasCoords(previewCanvas, e.clientX, e.clientY);
        if (!coords) return;

        const sizeSlider = $('#annotation-size');
        const sizeVal = sizeSlider ? parseInt(sizeSlider.value, 10) : 4;

        if (!state.annotation) {
            state.annotation = { x: coords.x, y: coords.y, text: '', radius: sizeVal };
        } else {
            state.annotation.x = coords.x;
            state.annotation.y = coords.y;
            state.annotation.radius = sizeVal;
        }

        // Show controls, hide hint
        const inputWrap = $('#annotation-input-wrap');
        const sizeWrap = $('#annotation-size-wrap');
        const hint = $('#annotation-hint');
        if (inputWrap) inputWrap.classList.remove('hidden');
        if (sizeWrap) sizeWrap.classList.remove('hidden');
        if (hint) hint.classList.add('hidden');

        redrawPreviewWithAnnotation();
    }

    /**
     * Update annotation text from input field.
     */
    function updateAnnotationText() {
        const input = $('#annotation-text');
        if (!input || !state.annotation) return;
        state.annotation.text = input.value;
        redrawPreviewWithAnnotation();
    }

    /**
     * Update annotation circle radius from size slider.
     */
    function updateAnnotationSize() {
        const slider = $('#annotation-size');
        if (!slider || !state.annotation) return;
        state.annotation.radius = parseInt(slider.value, 10);
        redrawPreviewWithAnnotation();
    }

    /**
     * Clear annotation and restore base image.
     */
    function clearAnnotation() {
        state.annotation = null;

        const input = $('#annotation-text');
        if (input) input.value = '';

        const sizeSlider = $('#annotation-size');
        if (sizeSlider) sizeSlider.value = 4;

        const inputWrap = $('#annotation-input-wrap');
        const sizeWrap = $('#annotation-size-wrap');
        const hint = $('#annotation-hint');
        if (inputWrap) inputWrap.classList.add('hidden');
        if (sizeWrap) sizeWrap.classList.add('hidden');
        if (hint) hint.classList.remove('hidden');

        // Restore base image without annotation
        if (state.baseImageData) {
            const ctx = previewCanvas.getContext('2d');
            ctx.putImageData(state.baseImageData, 0, 0);
        }
    }

    /**
     * Redraw the preview canvas with annotation overlay:
     * - Red circle at the marked point
     * - Warning badge at bottom-left with annotation text
     */
    function redrawPreviewWithAnnotation() {
        if (!state.baseImageData) return;

        const ctx = previewCanvas.getContext('2d');
        const w = previewCanvas.width;
        const h = previewCanvas.height;

        // Restore base image
        ctx.putImageData(state.baseImageData, 0, 0);

        if (!state.annotation) return;

        const { x, y, text } = state.annotation;

        // --- Red circle (size 1-10 maps to small-large radius) ---
        const sizeVal = state.annotation.radius || 4;
        const baseUnit = Math.min(w, h) * 0.01;
        const radius = Math.max(15, Math.round(baseUnit * (sizeVal + 1)));
        const lineW = Math.max(3, Math.round(h * 0.004));

        // Outer glow
        ctx.strokeStyle = 'rgba(239, 68, 68, 0.3)';
        ctx.lineWidth = lineW + 4;
        ctx.beginPath();
        ctx.arc(x, y, radius + lineW, 0, Math.PI * 2);
        ctx.stroke();

        // Main circle
        ctx.strokeStyle = '#ef4444';
        ctx.lineWidth = lineW;
        ctx.beginPath();
        ctx.arc(x, y, radius, 0, Math.PI * 2);
        ctx.stroke();

        // --- Warning badge at bottom-left (only if text exists) ---
        if (text && text.trim()) {
            const fontSize = Math.max(14, Math.round(h * 0.02));
            const padding = 14;
            const iconText = '\u26A0'; // ⚠

            ctx.font = `bold ${fontSize}px -apple-system, sans-serif`;
            const iconWidth = ctx.measureText(iconText).width;
            const textWidth = ctx.measureText(text).width;
            const gap = 8;

            const badgeWidth = Math.min(w * 0.6, padding + iconWidth + gap + textWidth + padding);
            const badgeHeight = fontSize * 1.5 + padding * 2;

            const bx = 12;
            const by = h - badgeHeight - 12;

            // Background
            ctx.fillStyle = 'rgba(239, 68, 68, 0.85)';
            roundRect(ctx, bx, by, badgeWidth, badgeHeight, 8);
            ctx.fill();

            // Warning icon
            ctx.fillStyle = '#ffffff';
            ctx.font = `${Math.round(fontSize * 1.2)}px -apple-system, sans-serif`;
            ctx.textBaseline = 'middle';
            ctx.textAlign = 'left';
            ctx.fillText(iconText, bx + padding, by + badgeHeight / 2);

            // Text (truncate if needed)
            ctx.font = `bold ${fontSize}px -apple-system, sans-serif`;
            const maxTextW = badgeWidth - padding - iconWidth - gap - padding;
            let displayText = text;
            while (ctx.measureText(displayText).width > maxTextW && displayText.length > 0) {
                displayText = displayText.slice(0, -1);
            }
            if (displayText.length < text.length) displayText += '\u2026';

            ctx.fillText(displayText, bx + padding + iconWidth + gap, by + badgeHeight / 2);

            // Reset
            ctx.textAlign = 'start';
            ctx.textBaseline = 'alphabetic';
        }
    }

    /**
     * Retake photo: undo counters, resume camera.
     */
    function retakePhoto() {
        // Undo the counters incremented in captureFrame
        state.countTotal--;
        if (state.currentMode === 'comparativo') {
            state.seqComparativa--;
            state.countComparativas--;
        } else {
            state.countAleatorias--;
        }

        state.annotation = null;
        state.annotationMode = false;
        state.pendingFilename = null;
        state.baseImageData = null;

        // Resume camera
        camVideo.play();
        showScreen('camera');
    }

    /**
     * Accept photo: apply annotation if present, then upload.
     */
    async function acceptPhoto() {
        if (!state.pendingFilename) return;

        // Ensure annotation is drawn on canvas
        if (state.annotation) {
            redrawPreviewWithAnnotation();
        }

        const filename = state.pendingFilename;

        // Clean up annotation state
        state.annotation = null;
        state.annotationMode = false;
        state.pendingFilename = null;
        state.baseImageData = null;

        await processAndUploadPhoto(filename);
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
    // FINALIZAR VISITA (Guardar y Resetear)
    // ===================================================================
    function finalizarVisita() {
        const numFotos = state.photos.length;
        const infraName = state.infraName;

        showNotification(`Visita a "${infraName}" finalizada (${numFotos} foto${numFotos !== 1 ? 's' : ''})`);

        // Reset state
        state.infraId = null;
        state.infraName = '';
        state.infraCode = '';
        state.countAleatorias = 0;
        state.countComparativas = 0;
        state.countTotal = 0;
        state.seqComparativa = 0;
        state.photos = [];
        state.prevPhotos = [];
        state.ghostUrl = null;
        state.ghostActive = false;
        state.situacionIdx = 0;
        state.unidadObraId = null;

        // Reset UI
        infraIdInput.value = '';
        infraSearch.value = '';
        infraSearch.classList.remove('hidden');
        infraSelected.classList.add('hidden');
        unidadObra.value = '';
        const obsField = $('#observaciones-general');
        if (obsField) obsField.value = '';
        countAleatorias.textContent = '0';
        countComparativas.textContent = '0';
        galleryGrid.innerHTML = '';
        gallerySection.classList.add('hidden');

        // Reset situación selector in Ficha
        const sitSel = $('#situacion-selector');
        if (sitSel) {
            sitSel.querySelectorAll('.situacion-option').forEach(b => b.classList.remove('active'));
            const firstBtn = sitSel.querySelector('[data-sit="0"]');
            if (firstBtn) firstBtn.classList.add('active');
        }

        updateButtonState();
    }

    // ===================================================================
    // MIS VISITAS - Panel de visitas del operador
    // ===================================================================
    let editingRegistroId = null;
    let editingInfraId = null;

    async function openVisitasScreen() {
        showScreen('visitas');
        visitasBody.innerHTML = '<div class="visitas-loading"><div class="spinner"></div><span>Cargando visitas...</span></div>';

        try {
            const res = await fetch(
                `${CFG.endpoints.visitas}?usuario_id=${CFG.usuarioId}&empresa_id=${CFG.empresaId}`
            );
            const data = await res.json();

            if (!data.ok) {
                visitasBody.innerHTML = '<div class="visita-empty"><i class="bi bi-exclamation-circle"></i><p>Error al cargar visitas</p></div>';
                return;
            }

            if (!data.visitas || data.visitas.length === 0) {
                visitasBody.innerHTML = '<div class="visita-empty"><i class="bi bi-journal-text"></i><p>No tienes visitas registradas</p></div>';
                return;
            }

            let html = '';
            data.visitas.forEach((visita, idx) => {
                const fechaFmt = formatFechaVisita(visita.fecha);
                html += `<div class="visita-group">
                    <div class="visita-group-header">
                        <div>
                            <strong>${escHtml(visita.infra_nombre)}</strong><br>
                            <code>${escHtml(visita.infra_codigo)}</code>
                        </div>
                        <div class="visita-group-date">
                            <i class="bi bi-calendar3"></i> ${fechaFmt}
                        </div>
                    </div>
                    <div class="visita-fotos">`;

                visita.fotos.forEach(foto => {
                    const tipoLabel = foto.tipo === 'comparativo' ? ('W' + (foto.seq || '')) : 'ALEA';
                    html += `<div class="visita-foto-item" data-registro-id="${foto.id}" onclick="window._editarRegistro(${foto.id})">
                        <img src="${escHtml(foto.url)}" alt="" loading="lazy">
                        <span class="visita-foto-badge ${foto.estado}">${foto.estado}</span>
                        <span class="visita-foto-tipo">${tipoLabel} ${foto.hora}</span>
                    </div>`;
                });

                html += `</div>
                    <button type="button" class="btn-continuar-visita" data-visita-idx="${idx}">
                        <i class="bi bi-pencil-square"></i> Continuar visita
                    </button>
                </div>`;
            });

            // Store visitas data for continuarVisita
            window._visitasData = data.visitas;

            visitasBody.innerHTML = html;

            // Bind "Continuar visita" buttons
            visitasBody.querySelectorAll('.btn-continuar-visita').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const idx = parseInt(btn.dataset.visitaIdx);
                    if (window._visitasData && window._visitasData[idx]) {
                        continuarVisita(window._visitasData[idx]);
                    }
                });
            });
        } catch (err) {
            visitasBody.innerHTML = '<div class="visita-empty"><i class="bi bi-wifi-off"></i><p>Error de conexión</p></div>';
        }
    }

    // ===================================================================
    // CONTINUAR VISITA - Load full visit into Ficha
    // ===================================================================
    function continuarVisita(visita) {
        // 1. Select the infrastructure
        selectInfra(visita.infra_id, visita.infra_nombre, visita.infra_codigo);

        // 2. Determine situación from the most recent photo
        if (visita.fotos && visita.fotos.length > 0) {
            const lastFoto = visita.fotos[0]; // fotos are ordered by most recent first
            const sitMap = { antes: 0, durante: 1, despues: 2 };
            const sitIdx = sitMap[lastFoto.estado] !== undefined ? sitMap[lastFoto.estado] : 0;
            state.situacionIdx = sitIdx;

            // Update situación selector UI
            const sitSel = $('#situacion-selector');
            if (sitSel) {
                sitSel.querySelectorAll('.situacion-option').forEach(b => b.classList.remove('active'));
                const activeBtn = sitSel.querySelector(`[data-sit="${sitIdx}"]`);
                if (activeBtn) activeBtn.classList.add('active');
            }
        }

        // 3. Set observations from the most recent photo
        const obsField = $('#observaciones-general');
        if (obsField && visita.fotos && visita.fotos.length > 0) {
            // Use the last photo's observations as a starting point
            const lastObs = visita.fotos[0].observaciones || '';
            obsField.value = lastObs;
        }

        // 4. Set work unit from the most recent photo that has one
        if (visita.fotos && visita.fotos.length > 0) {
            const fotoConUO = visita.fotos.find(f => f.unidad_obra_id);
            if (fotoConUO && fotoConUO.unidad_obra_id) {
                unidadObra.value = fotoConUO.unidad_obra_id;
                state.unidadObraId = fotoConUO.unidad_obra_id;
            }
        }

        // 5. Load all visit photos into gallery and set counters
        galleryGrid.innerHTML = '';
        state.photos = [];
        state.countAleatorias = 0;
        state.countComparativas = 0;
        state.countTotal = 0;
        state.seqComparativa = 0;

        if (visita.fotos && visita.fotos.length > 0) {
            // Reverse to show oldest first (chronological order in gallery)
            const fotosOrdenadas = [...visita.fotos].reverse();
            fotosOrdenadas.forEach(foto => {
                const tipo = foto.tipo || 'aleatorio';
                const seq = foto.seq || null;
                const nombre = foto.nombre || '';
                const url = foto.url || '';

                if (tipo === 'comparativo') {
                    state.countComparativas++;
                    if (seq && seq > state.seqComparativa) {
                        state.seqComparativa = seq;
                    }
                } else {
                    state.countAleatorias++;
                }
                state.countTotal++;

                addToGallery(url, tipo, nombre, seq);
            });
        }

        // 6. Update counters in UI
        countAleatorias.textContent = state.countAleatorias;
        countComparativas.textContent = state.countComparativas;

        // 7. Show Ficha screen
        showScreen('ficha');
        showNotification(`Visita a "${visita.infra_nombre}" cargada. Puedes editar datos y seguir tomando fotos.`);
    }

    // Global handler for clicking on a visit photo
    window._editarRegistro = async function(registroId) {
        editingRegistroId = registroId;
        showScreen('editarVisita');

        const editarBody = $('#editar-body');
        const editarImg = $('#editar-foto-img');
        const editarInfo = $('#editar-info');
        const editarEstado = $('#editar-estado');
        const editarUo = $('#editar-uo');
        const editarObs = $('#editar-observaciones');
        const editarInfraName = $('#editar-infra-name');

        try {
            const res = await fetch(
                `${CFG.endpoints.visitas}?action=detalle&registro_id=${registroId}&usuario_id=${CFG.usuarioId}&empresa_id=${CFG.empresaId}`
            );
            const data = await res.json();

            if (!data.ok || !data.registro) {
                showNotification('No se pudo cargar el registro');
                showScreen('visitas');
                return;
            }

            const r = data.registro;
            editingInfraId = parseInt(r.infra_id);

            editarImg.src = r.url_cloudinary || '';
            editarInfraName.textContent = r.infra_nombre;

            const fechaFmt = r.fecha ? new Date(r.fecha).toLocaleString('es-ES', {
                day: '2-digit', month: '2-digit', year: 'numeric',
                hour: '2-digit', minute: '2-digit'
            }) : '--';

            editarInfo.innerHTML = `
                <strong>${escHtml(r.infra_nombre)}</strong> <code>${escHtml(r.codigo_unico)}</code><br>
                <i class="bi bi-calendar3"></i> ${fechaFmt}<br>
                <i class="bi bi-camera"></i> ${r.tipo_foto === 'comparativo' ? 'Comparativa' : 'Aleatoria'}
                ${r.nombre_archivo ? ' - ' + escHtml(r.nombre_archivo) : ''}
            `;

            editarEstado.value = r.estado_incidencia || 'antes';
            editarObs.value = r.observaciones || '';

            // Load UO options
            editarUo.innerHTML = '<option value="">Sin asignar</option>';
            try {
                const uoRes = await fetch(`${CFG.endpoints.unidadesObra}?empresa_id=${CFG.empresaId}`);
                const uoData = await uoRes.json();
                if (uoData.ok && uoData.unidades) {
                    uoData.unidades.forEach(u => {
                        const label = u.codigo ? `${u.codigo} - ${u.nombre}` : u.nombre;
                        const sel = (r.unidad_obra_id && parseInt(r.unidad_obra_id) === parseInt(u.id)) ? 'selected' : '';
                        editarUo.innerHTML += `<option value="${u.id}" ${sel}>${escHtml(label)}</option>`;
                    });
                }
            } catch (e) { /* UO loading optional */ }

        } catch (err) {
            showNotification('Error al cargar registro');
            showScreen('visitas');
        }
    };

    async function guardarEdicion() {
        if (!editingRegistroId) return;

        const editarEstado = $('#editar-estado');
        const editarUo = $('#editar-uo');
        const editarObs = $('#editar-observaciones');

        const formData = new FormData();
        formData.append('action', 'editar');
        formData.append('registro_id', editingRegistroId);
        formData.append('usuario_id', CFG.usuarioId);
        formData.append('estado_incidencia', editarEstado.value);
        formData.append('unidad_obra_id', editarUo.value);
        formData.append('observaciones', editarObs.value);

        try {
            const res = await fetch(CFG.endpoints.visitas, {
                method: 'POST',
                body: formData,
            });
            const data = await res.json();

            if (data.ok) {
                showNotification('Registro actualizado correctamente');
                openVisitasScreen(); // volver al listado
            } else {
                showNotification('Error: ' + (data.error || 'No se pudo guardar'));
            }
        } catch (err) {
            showNotification('Error de conexión');
        }
    }

    function añadirFotoDesdeVisita() {
        if (!editingInfraId) {
            showNotification('No se puede determinar la infraestructura');
            return;
        }

        // We need the infra name and code - fetch them from the edit screen
        const editarInfraName = $('#editar-infra-name');
        const editarInfo = $('#editar-info');
        const infraName = editarInfraName ? editarInfraName.textContent : '';
        const codeEl = editarInfo ? editarInfo.querySelector('code') : null;
        const infraCode = codeEl ? codeEl.textContent : '';

        // Select the infrastructure and go back to ficha to take a photo
        selectInfra(editingInfraId, infraName, infraCode);
        showScreen('ficha');
        showNotification('Infraestructura seleccionada. Toma una foto.');
    }

    function formatFechaVisita(fechaStr) {
        const parts = fechaStr.split('-');
        if (parts.length === 3) {
            return `${parts[2]}/${parts[1]}/${parts[0]}`;
        }
        return fechaStr;
    }

    // ===================================================================
    // START
    // ===================================================================
    document.addEventListener('DOMContentLoaded', () => {
        init();
        initPWAInstall();
    });

})();
