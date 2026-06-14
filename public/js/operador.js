/**
 * INFOCAMPO - Operador de Campo
 *
 * App principal del operador para recogida de datos en campo.
 * Soporta fotos aleatorias y comparativas (solo comparativas tienen sistema Ghosting).
 * Watermarks estilo GPS Camera: UTM, brújula, geocoding, hora Madrid.
 */
;(function() {
    'use strict';

    const CFG = window.INFOCAMPO;

    // ===================================================================
    // STATE
    // ===================================================================
    const state = {
        screen: 'ficha', // current screen name
        infraId: null,
        infraName: '',
        infraCode: '',
        tipoTrabajoId: null,
        unidadObraId: null,
        currentMode: null, // 'aleatorio' | 'comparativo'
        gps: { lat: null, lon: null },
        gpsWatchId: null,
        stream: null,
        capturedBlob: null,
        capturedGps: { lat: null, lon: null }, // GPS frozen at capture moment
        ghostUrl: null,
        ghostActive: false,
        seqComparativa: 0,
        photos: [], // { url, type, seq, name }
        waypoints: [], // { lat, lon, filename, timestamp } — waypoints for comparative photos
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
        // Compass bearing (device orientation)
        bearing: null, // degrees 0-360, null if unavailable
        // Reverse geocoding cache
        geoLocation: null, // { city, province, postcode, country }
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
    const tipoTrabajo      = $('#tipo-trabajo');
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
    const ghostOpacityBar    = $('#ghost-opacity-bar');
    const ghostOpacitySlider = $('#ghost-opacity-slider');
    const ghostOpacityValue  = $('#ghost-opacity-value');

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
    const guardarVisitaSinFotoSection = $('#guardar-visita-sin-foto-section');
    const btnGuardarVisitaSinFoto = $('#btn-guardar-visita-sin-foto');
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
        loadMontes();
        loadTiposTrabajo();
        loadUnidadesObra();
        bindEvents();
        initGPS();
        initCompass();
        initOffline();
        registerServiceWorker();
        initExitConfirmation();
    }

    // ===================================================================
    // EXIT CONFIRMATION — prevent accidental close with unsaved data
    // ===================================================================
    // Marca de navegación intencional (logout, enlaces) para no mostrar el aviso
    let intentionalNavigation = false;

    // ¿Hay datos de visita sin finalizar que se perderían?
    function hasUnsavedData() {
        return Array.isArray(state.photos) && state.photos.length > 0;
    }

    function initExitConfirmation() {
        // Permitir salir sin aviso al pulsar "Cerrar sesión" u otros enlaces de salida
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a[href]');
            if (link && (link.classList.contains('user-menu-logout') || link.dataset.allowExit === '1')) {
                intentionalNavigation = true;
            }
        }, true);

        window.addEventListener('beforeunload', (e) => {
            // Solo avisar si hay fotos sin finalizar y no es una salida intencional
            if (intentionalNavigation || !hasUnsavedData()) {
                return;
            }
            e.preventDefault();
            e.returnValue = '¿Seguro que quieres salir? Tienes fotos sin finalizar.';
            return e.returnValue;
        });

        // Intercept mobile back button via popstate
        if (history.pushState) {
            history.pushState(null, '', location.href);
            window.addEventListener('popstate', () => {
                // If on a sub-screen (camera, preview, map, etc.), go back to ficha instead of leaving
                if (state.screen !== 'ficha') {
                    history.pushState(null, '', location.href);
                    showScreen('ficha');
                    return;
                }
                // En la ficha: solo preguntar si hay datos sin guardar
                if (hasUnsavedData() &&
                    !confirm('¿Quieres salir de INFOCAMPO? Tienes fotos sin finalizar.')) {
                    history.pushState(null, '', location.href);
                    return;
                }
                // Allow navigation out
                intentionalNavigation = true;
                history.back();
            });
        }
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

    // Persistent per-infrastructure photo counter (localStorage)
    function loadInfraSeq(infraId) {
        try {
            const val = localStorage.getItem('infocampo_seq_' + infraId);
            return val ? parseInt(val, 10) : 0;
        } catch (_e) { return 0; }
    }

    function saveInfraSeq(infraId, seq) {
        try { localStorage.setItem('infocampo_seq_' + infraId, String(seq)); } catch (_e) {}
    }

    function formatDateMadrid(date) {
        if (!date) date = new Date();
        // Format: "24 feb 2026 18:46:04" matching GPS Camera app style
        const meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        const parts = new Intl.DateTimeFormat('es-ES', {
            timeZone: 'Europe/Madrid',
            day: 'numeric', month: 'numeric', year: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
            hour12: false,
        }).formatToParts(date);
        const get = (type) => (parts.find(p => p.type === type) || {}).value || '';
        const day = get('day');
        const month = parseInt(get('month'), 10);
        const year = get('year');
        const hour = get('hour');
        const minute = get('minute');
        const second = get('second');
        return `${day} ${meses[month - 1]} ${year} ${hour}:${minute}:${second}`;
    }

    // ===================================================================
    // UTM CONVERSION (lat/lon WGS84 → UTM)
    // ===================================================================
    function latLonToUTM(lat, lon) {
        const a = 6378137; // WGS84 semi-major axis
        const f = 1 / 298.257223563;
        const e2 = 2 * f - f * f;
        const e_prime2 = e2 / (1 - e2);
        const k0 = 0.9996;

        const latRad = lat * Math.PI / 180;
        const zone = Math.floor((lon + 180) / 6) + 1;
        const lonOrigin = (zone - 1) * 6 - 180 + 3;
        const lonOriginRad = lonOrigin * Math.PI / 180;

        const N = a / Math.sqrt(1 - e2 * Math.sin(latRad) * Math.sin(latRad));
        const T = Math.tan(latRad) * Math.tan(latRad);
        const C = e_prime2 * Math.cos(latRad) * Math.cos(latRad);
        const A = Math.cos(latRad) * (lon * Math.PI / 180 - lonOriginRad);

        const M = a * (
            (1 - e2 / 4 - 3 * e2 * e2 / 64 - 5 * e2 * e2 * e2 / 256) * latRad
            - (3 * e2 / 8 + 3 * e2 * e2 / 32 + 45 * e2 * e2 * e2 / 1024) * Math.sin(2 * latRad)
            + (15 * e2 * e2 / 256 + 45 * e2 * e2 * e2 / 1024) * Math.sin(4 * latRad)
            - (35 * e2 * e2 * e2 / 3072) * Math.sin(6 * latRad)
        );

        let easting = k0 * N * (A + (1 - T + C) * A * A * A / 6
            + (5 - 18 * T + T * T + 72 * C - 58 * e_prime2) * A * A * A * A * A / 120) + 500000;

        let northing = k0 * (M + N * Math.tan(latRad) * (
            A * A / 2 + (5 - T + 9 * C + 4 * C * C) * A * A * A * A / 24
            + (61 - 58 * T + T * T + 600 * C - 330 * e_prime2) * A * A * A * A * A * A / 720
        ));

        if (lat < 0) northing += 10000000;

        const band = 'CDEFGHJKLMNPQRSTUVWX'.charAt(Math.floor((lat + 80) / 8));

        return {
            zone: zone,
            band: band,
            easting: Math.round(easting),
            northing: Math.round(northing),
            str: `${zone}${band} ${Math.round(easting)} ${Math.round(northing)}`
        };
    }

    // ===================================================================
    // REVERSE GEOCODING (Nominatim OpenStreetMap)
    // ===================================================================
    let _lastGeocodeLat = null;
    let _lastGeocodeLon = null;

    async function reverseGeocode(lat, lon) {
        // Only re-fetch if moved >200m from last geocode
        if (_lastGeocodeLat != null && _lastGeocodeLon != null) {
            const dist = Haversine.distance(lat, lon, _lastGeocodeLat, _lastGeocodeLon);
            if (dist < 200 && state.geoLocation) return state.geoLocation;
        }

        try {
            // Timeout after 4s to avoid blocking capture on slow networks
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 4000);

            const res = await fetch(
                `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lon}&zoom=16&addressdetails=1`,
                { headers: { 'Accept-Language': 'es' }, signal: controller.signal }
            );
            clearTimeout(timeout);

            const data = await res.json();
            const addr = data.address || {};
            state.geoLocation = {
                city: addr.city || addr.town || addr.village || addr.municipality || '',
                province: addr.province || addr.county || addr.state || '',
                postcode: addr.postcode || '',
                country: addr.country || '',
            };
            _lastGeocodeLat = lat;
            _lastGeocodeLon = lon;
            return state.geoLocation;
        } catch (e) {
            console.warn('reverseGeocode failed:', e);
            return state.geoLocation || { city: '', province: '', postcode: '', country: '' };
        }
    }

    // ===================================================================
    // COMPASS BEARING (Device Orientation)
    // ===================================================================
    function initCompass() {
        // iOS 13+ requires permission
        if (typeof DeviceOrientationEvent !== 'undefined' &&
            typeof DeviceOrientationEvent.requestPermission === 'function') {
            // Will request on first user interaction (camera start)
            return;
        }
        // Android / other browsers — start immediately
        _startCompassListener();
    }

    async function requestCompassPermission() {
        if (typeof DeviceOrientationEvent !== 'undefined' &&
            typeof DeviceOrientationEvent.requestPermission === 'function') {
            try {
                const perm = await DeviceOrientationEvent.requestPermission();
                if (perm === 'granted') _startCompassListener();
            } catch (_e) { /* permission denied */ }
        }
    }

    function _startCompassListener() {
        window.addEventListener('deviceorientationabsolute', (e) => {
            if (e.absolute && e.alpha != null) {
                state.bearing = Math.round(360 - e.alpha) % 360;
            }
        }, true);
        // Fallback to non-absolute
        window.addEventListener('deviceorientation', (e) => {
            if (state.bearing != null) return; // prefer absolute
            if (e.webkitCompassHeading != null) {
                state.bearing = Math.round(e.webkitCompassHeading);
            } else if (e.alpha != null) {
                state.bearing = Math.round(360 - e.alpha) % 360;
            }
        }, true);
    }

    function bearingToCardinal(deg) {
        if (deg == null) return '';
        const dirs = ['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO'];
        return dirs[Math.round(deg / 45) % 8];
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
            camGpsText.textContent = 'UTM: Sin GPS';
            return;
        }
        // Evitar duplicar el watch si ya está activo
        if (state.gpsWatchId !== null) {
            return;
        }

        state.gpsWatchId = navigator.geolocation.watchPosition(
            (pos) => {
                state.gps.lat = pos.coords.latitude;
                state.gps.lon = pos.coords.longitude;
                camGpsDot.classList.add('active');
                const utm = latLonToUTM(state.gps.lat, state.gps.lon);
                camGpsText.textContent = `UTM: ${utm.str}`;
                // Trigger reverse geocoding in background (throttled internally)
                reverseGeocode(state.gps.lat, state.gps.lon);
            },
            () => {
                camGpsText.textContent = 'UTM: Error GPS';
            },
            { enableHighAccuracy: true, maximumAge: 3000 }
        );
    }

    // ===================================================================
    // PROVINCIA / MUNICIPIO FILTERS
    // ===================================================================
    const filterProvincia = $('#filter-provincia');
    const filterMunicipio = $('#filter-municipio');
    const filterMonte     = $('#filter-monte');

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

    async function loadMontes() {
        if (!CFG.empresaId || !filterMonte) return;
        const prov = filterProvincia ? filterProvincia.value : '';
        const muni = filterMunicipio ? filterMunicipio.value : '';
        try {
            let url = `${CFG.endpoints.infraestructuras}?empresa_id=${CFG.empresaId}&action=montes`;
            if (prov) url += `&provincia=${encodeURIComponent(prov)}`;
            if (muni) url += `&municipio=${encodeURIComponent(muni)}`;
            const res = await fetch(url);
            const data = await res.json();
            if (data.ok && data.montes && data.montes.length > 0) {
                let html = '<option value="">Todos los montes</option>';
                data.montes.forEach(m => {
                    html += `<option value="${escHtml(m)}">${escHtml(m)}</option>`;
                });
                filterMonte.innerHTML = html;
                filterMonte.disabled = false;
            } else {
                filterMonte.innerHTML = '<option value="">Sin montes disponibles</option>';
                filterMonte.disabled = false;
            }
        } catch (err) {
            console.warn('Error loading montes:', err);
        }
    }

    function getFilterParams() {
        let params = '';
        const prov = filterProvincia ? filterProvincia.value : '';
        const muni = filterMunicipio ? filterMunicipio.value : '';
        const monte = filterMonte ? filterMonte.value : '';
        if (prov) params += `&provincia=${encodeURIComponent(prov)}`;
        if (muni) params += `&municipio=${encodeURIComponent(muni)}`;
        if (monte) params += `&monte=${encodeURIComponent(monte)}`;
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
                    const loc = [inf.monte, inf.municipio, inf.provincia].filter(Boolean).join(', ');
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
        // Load persistent counter for this infrastructure
        state.countAleatorias = 0;
        state.countComparativas = 0;
        state.countTotal = loadInfraSeq(id);
        state.seqComparativa = 0;
        state.photos = [];
        infraIdInput.value = id;
        infraSearch.classList.add('hidden');
        infraResults.classList.add('hidden');
        infraSelected.classList.remove('hidden');
        infraSelectedName.textContent = `${name} (${code || 'sin código'})`;
        updateButtonState();
        updatePrecacheIndicator();
        loadDynamicFields();
    }

    async function createNewInfra(name) {
        try {
            const formData = new FormData();
            formData.append('csrf_token', CFG.csrfToken || '');
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
        // Revocar blob URLs de fotos de galería para liberar memoria
        state.photos.forEach(function(p) {
            if (p.url && p.url.startsWith('blob:')) {
                try { URL.revokeObjectURL(p.url); } catch (_e) {}
            }
        });
        state.photos = [];
        // Revocar blob URLs de fotos previas cacheadas para liberar memoria
        if (state.prevPhotos.length > 0 && window.InfocampoOffline && window.InfocampoOffline.revokeBlobUrls) {
            window.InfocampoOffline.revokeBlobUrls(state.prevPhotos);
        }
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
        clearDynamicFields();
        updateButtonState();
    }

    // ===================================================================
    // CAMPOS DINÁMICOS DEL FORMULARIO
    // ===================================================================
    async function loadDynamicFields() {
        const container = document.getElementById('dynamic-fields');
        const card = document.getElementById('dynamic-fields-card');
        if (!container || !card) return;

        try {
            const res = await fetch(`${CFG.endpoints.campos}?empresa_id=${CFG.empresaId}`);
            const data = await res.json();

            if (!data.ok || !data.campos || data.campos.length === 0) {
                card.style.display = 'none';
                container.innerHTML = '';
                return;
            }

            let html = '';
            data.campos.forEach(campo => {
                const req = campo.obligatorio ? 'required' : '';
                const reqMark = campo.obligatorio ? '<span style="color:#ef4444;">*</span>' : '';
                html += '<div class="dyn-field" style="margin-bottom:10px;">';
                html += `<label class="card-label" style="font-size:0.78rem;margin-bottom:4px;">${campo.nombre}${reqMark}</label>`;

                switch (campo.tipo) {
                    case 'texto':
                        html += `<input type="text" name="campos[${campo.id}]" placeholder="${campo.nombre}" class="input-field" ${req}>`;
                        break;
                    case 'numero':
                        html += `<input type="number" name="campos[${campo.id}]" placeholder="0" step="any" class="input-field" ${req}>`;
                        break;
                    case 'select':
                        html += `<select name="campos[${campo.id}]" class="input-field" ${req}>`;
                        html += '<option value="">-- Seleccionar --</option>';
                        if (campo.opciones) {
                            campo.opciones.forEach(opt => {
                                html += `<option value="${opt}">${opt}</option>`;
                            });
                        }
                        html += '</select>';
                        break;
                    case 'checkbox':
                        html += `<label style="display:flex;align-items:center;gap:8px;font-size:0.85rem;"><input type="checkbox" name="campos[${campo.id}]" value="1"> ${campo.nombre}</label>`;
                        break;
                    case 'textarea':
                        html += `<textarea name="campos[${campo.id}]" placeholder="${campo.nombre}" rows="2" class="input-field input-textarea" ${req}></textarea>`;
                        break;
                    case 'fecha':
                        html += `<input type="date" name="campos[${campo.id}]" class="input-field" ${req}>`;
                        break;
                }
                html += '</div>';
            });

            container.innerHTML = html;
            card.style.display = '';
        } catch (err) {
            console.warn('Error loading dynamic fields:', err);
        }
    }

    function clearDynamicFields() {
        const container = document.getElementById('dynamic-fields');
        const card = document.getElementById('dynamic-fields-card');
        if (container) container.innerHTML = '';
        if (card) card.style.display = 'none';
    }

    function collectDynamicFields() {
        const fields = {};
        const container = document.getElementById('dynamic-fields');
        if (!container) return fields;
        container.querySelectorAll('[name^="campos["]').forEach(el => {
            const match = el.name.match(/campos\[(\d+)\]/);
            if (!match) return;
            if (el.type === 'checkbox') {
                fields[match[1]] = el.checked ? '1' : '0';
            } else {
                fields[match[1]] = el.value;
            }
        });
        return fields;
    }

    // ===================================================================
    // TIPOS DE TRABAJO
    // ===================================================================
    async function loadTiposTrabajo() {
        if (!CFG.empresaId || !tipoTrabajo) return;
        try {
            const res = await fetch(`${CFG.endpoints.tiposTrabajo}?empresa_id=${CFG.empresaId}`);
            const data = await res.json();
            if (data.ok && data.tipos) {
                let html = '<option value="">-- Seleccionar tipo de trabajo --</option>';
                data.tipos.forEach(t => {
                    const label = t.codigo ? `${t.codigo} - ${t.nombre}` : t.nombre;
                    html += `<option value="${t.id}">${escHtml(label)}</option>`;
                });
                tipoTrabajo.innerHTML = html;
            }
        } catch (err) {
            console.warn('Error loading tipos de trabajo:', err);
        }
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

        // Show/hide guardar visita sin foto (visible when infra selected, hidden when there are photos)
        if (guardarVisitaSinFotoSection) {
            const hasPhotos = state.photos.length > 0;
            guardarVisitaSinFotoSection.classList.toggle('hidden', !enabled || hasPhotos);
        }

        // Show/hide finalizar visita button (visible when infra selected and there are photos)
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
        state.screen = name;
    }

    // ===================================================================
    // CAMERA
    // ===================================================================
    async function openCamera(mode) {
        state.currentMode = mode;

        // Reactivar el seguimiento GPS (closeCamera lo detiene para ahorrar batería)
        initGPS();

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
            camGhost.style.opacity = '';
            if (ghostOpacityBar) ghostOpacityBar.classList.add('hidden');
        }

        // Request compass permission on iOS (needs user gesture context)
        requestCompassPermission();

        // Re-establish GPS watch if it was cleared by closeCamera()
        if (state.gpsWatchId === null) {
            initGPS();
        }

        // Start camera — request max resolution for high-quality watermarked photos
        try {
            if (!state.stream) {
                state.stream = await navigator.mediaDevices.getUserMedia({
                    video: {
                        facingMode: { ideal: 'environment' },
                        width: { ideal: 3264 },
                        height: { ideal: 2448 },
                        aspectRatio: { ideal: 3 / 4 },
                    },
                    audio: false,
                });
            }
            camVideo.srcObject = state.stream;
            await camVideo.play();
        } catch (err) {
            // Limpiar stream si fue adquirido pero play() falló
            if (state.stream) {
                state.stream.getTracks().forEach(t => t.stop());
                state.stream = null;
            }
            camVideo.srcObject = null;
            alert('No se pudo acceder a la cámara: ' + err.message);
            return;
        }

        showScreen('camera');
    }

    function closeCamera() {
        stopCameraStream();
        // Limpiar GPS watch para ahorrar batería
        if (state.gpsWatchId !== null) {
            navigator.geolocation.clearWatch(state.gpsWatchId);
            state.gpsWatchId = null;
        }
        camGhost.classList.remove('active');
        camGhost.style.opacity = '';
        if (ghostOpacityBar) ghostOpacityBar.classList.add('hidden');
        showScreen('ficha');
    }

    // ===================================================================
    // PREVIOUS COMPARATIVE PHOTOS
    // ===================================================================
    async function checkPreviousPhotos() {
        if (!state.infraId) return;

        // Liberar blob URLs del lote anterior para evitar fugas de memoria
        if (state.prevPhotos && state.prevPhotos.length > 0 &&
            window.InfocampoOffline && window.InfocampoOffline.revokeBlobUrls) {
            window.InfocampoOffline.revokeBlobUrls(state.prevPhotos);
        }

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
        // Apply current slider opacity and show bar
        if (ghostOpacitySlider) {
            camGhost.style.opacity = parseInt(ghostOpacitySlider.value, 10) / 100;
        }
        if (ghostOpacityBar) ghostOpacityBar.classList.remove('hidden');
    }

    // ===================================================================
    // CAPTURE
    // ===================================================================
    let _capturing = false;

    async function captureFrame() {
        // Guard: prevent double-tap while capture is in progress
        if (_capturing) return;
        _capturing = true;

        // Visual feedback: disable shutter button and show spinner
        if (btnShutter) {
            btnShutter.disabled = true;
            btnShutter.style.opacity = '0.5';
        }

        try {
            const vw = camVideo.videoWidth;
            const vh = camVideo.videoHeight;

            if (!vw || !vh) {
                console.warn('captureFrame: video not ready (dimensions 0)');
                _capturing = false;
                if (btnShutter) { btnShutter.disabled = false; btnShutter.style.opacity = ''; }
                return;
            }

            // Force 3:4 portrait crop from center of video frame
            let srcX = 0, srcY = 0, srcW = vw, srcH = vh;
            const targetRatio = 3 / 4; // width / height
            const videoRatio = vw / vh;

            if (videoRatio > targetRatio) {
                srcW = Math.round(vh * targetRatio);
                srcX = Math.round((vw - srcW) / 2);
            } else if (videoRatio < targetRatio) {
                srcH = Math.round(vw / targetRatio);
                srcY = Math.round((vh - srcH) / 2);
            }

            // Free previous canvas memory before allocating new
            state.baseImageData = null;
            state.capturedBlob = null;

            camCapture.width = srcW;
            camCapture.height = srcH;

            const ctx = camCapture.getContext('2d');
            ctx.drawImage(camVideo, srcX, srcY, srcW, srcH, 0, 0, srcW, srcH);

            camVideo.pause();

            // Freeze GPS coordinates at the exact moment of capture
            state.capturedGps.lat = state.gps.lat;
            state.capturedGps.lon = state.gps.lon;

            // Update counters
            state.countTotal++;
            if (state.currentMode === 'comparativo') {
                state.seqComparativa++;
                state.countComparativas++;
            } else {
                state.countAleatorias++;
            }

            // Persist counter for this infrastructure
            if (state.infraId) saveInfraSeq(state.infraId, state.countTotal);

            // Generate filename based on company config (formatoNombreFoto)
            const seqNum = String(state.countTotal).padStart(3, '0');
            const codInfra = sanitizeFilename(state.infraCode || state.infraName);
            const formato = CFG.formatoNombreFoto || 1;

            // Etiquetas para nombre de archivo
            const situacionLabels = { 0: 'Antes', 1: 'Durante', 2: 'Despues' };
            const situacionLabel = situacionLabels[state.situacionIdx] || 'Antes';
            const tipoFotoLabel = state.currentMode === 'comparativo' ? 'Comparativa' : 'Aleatoria';

            let filename;
            if (formato === 2) {
                // Código + Situación + N°
                filename = `${codInfra}_${situacionLabel}_${seqNum}`;
            } else if (formato === 3) {
                // Código + Situación + Tipo Foto + N°
                filename = `${codInfra}_${situacionLabel}_${tipoFotoLabel}_${seqNum}`;
            } else {
                // Código + N°
                filename = `${codInfra}_${seqNum}`;
            }

            // Freeze bearing at capture moment
            const capturedBearing = state.bearing;

            // Ensure reverse geocoding is done for capture location (with timeout)
            let geoLoc = state.geoLocation;
            if (state.capturedGps.lat != null && state.capturedGps.lon != null) {
                geoLoc = await reverseGeocode(state.capturedGps.lat, state.capturedGps.lon);
            }

            // Apply watermark directly on previewCanvas (used for blob generation)
            await applyWatermark(camCapture, previewCanvas, {
                lat: state.capturedGps.lat,
                lon: state.capturedGps.lon,
                infraName: state.infraName,
                infraCode: state.infraCode,
                empresaName: CFG.empresaName,
                situacion: SITUACIONES_UI[state.situacionIdx],
                filename: filename,
                mode: state.currentMode,
                seq: state.currentMode === 'comparativo' ? state.seqComparativa : null,
                bearing: capturedBearing,
                geoLocation: geoLoc,
            });

            // Save base image for annotation overlay (before any annotation)
            // getImageData can throw SecurityError on tainted canvas — non-critical
            state.pendingFilename = filename;
            state.annotation = null;
            state.annotationMode = false;
            try {
                const prevCtx = previewCanvas.getContext('2d');
                state.baseImageData = prevCtx.getImageData(0, 0, previewCanvas.width, previewCanvas.height);
            } catch (imgDataErr) {
                console.warn('getImageData failed (annotations disabled):', imgDataErr);
                state.baseImageData = null;
            }

            // Show preview screen for optional annotation before uploading
            showScreen('preview');
            if (previewFilename) previewFilename.textContent = filename;
            resetAnnotationUI();

        } catch (err) {
            console.error('Error en captureFrame:', err, err.stack);
            // Rollback counters on failure to avoid sequence gaps
            state.countTotal--;
            if (state.currentMode === 'comparativo') {
                state.seqComparativa--;
                state.countComparativas--;
            } else {
                state.countAleatorias--;
            }
            if (state.infraId) saveInfraSeq(state.infraId, state.countTotal);
            // Resume camera so the user can retry
            try { camVideo.play(); } catch (_) {}
            // Mostrar mensaje descriptivo con detalle del error para diagnóstico
            let userMsg = 'Error al capturar la foto. Inténtalo de nuevo.';
            if (err.message && err.message.includes('taint')) {
                userMsg = 'Error de seguridad con el mapa. Se reintentará sin mapa.';
            } else if (err.message && err.message.includes('timeout')) {
                userMsg = 'La captura tardó demasiado. Inténtalo de nuevo.';
            } else if (err.message && err.message.includes('toBlob')) {
                userMsg = 'Error al generar la imagen. Inténtalo de nuevo.';
            }
            alert(userMsg + '\n\nDetalle: ' + (err.message || String(err)));
        } finally {
            _capturing = false;
            // Restore shutter button
            if (btnShutter) {
                btnShutter.disabled = false;
                btnShutter.style.opacity = '';
            }
        }
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

        // Free large objects from memory before upload
        state.capturedBlob = null;
        state.baseImageData = null;
        // Clear capture canvas to release memory
        camCapture.width = 1;
        camCapture.height = 1;

        // Show upload overlay
        uploadOverlay.classList.remove('hidden');

        // Save to device gallery (non-blocking)
        saveToDeviceGallery(blob, filename);

        let seq = null;
        if (state.currentMode === 'comparativo') {
            seq = state.seqComparativa;
        }

        // Use GPS frozen at capture moment for accuracy
        const captureLat = state.capturedGps.lat || state.gps.lat || 0;
        const captureLon = state.capturedGps.lon || state.gps.lon || 0;

        // Token de idempotencia: identifica esta captura de forma única para
        // evitar registros duplicados si la subida se reintenta tras un fallo de red
        const clientToken = (self.crypto && crypto.randomUUID)
            ? crypto.randomUUID()
            : ('t' + Date.now() + '-' + Math.random().toString(36).slice(2));

        // Build upload data
        const uploadData = {
            csrf_token: CFG.csrfToken,
            client_token: clientToken,
            infra_id: state.infraId,
            usuario_id: CFG.usuarioId,
            lat_real: captureLat,
            lon_real: captureLon,
            estado_incidencia: SITUACIONES[state.situacionIdx],
            tipo_foto: state.currentMode,
            nombre_archivo: filename,
            observaciones: $('#observaciones-general').value || '',
            secuencia_comparativa: seq,
            tipo_trabajo_id: tipoTrabajo ? tipoTrabajo.value || null : null,
            unidad_obra_id: unidadObra.value || null,
            datos_tecnicos: JSON.stringify({
                timestamp: new Date().toISOString(),
                etrs89_lat: captureLat,
                etrs89_lon: captureLon,
                timezone: 'Europe/Madrid',
                mode: state.currentMode,
            }),
            uploadUrl: CFG.endpoints.upload,
            campos: collectDynamicFields(),
        };

        // Save waypoint for comparative photos in "antes" situation
        // GPX waypoints only for comparativas+antes, named CODIGO_W1, CODIGO_W2...
        if (state.currentMode === 'comparativo' && SITUACIONES[state.situacionIdx] === 'antes' && captureLat && captureLon) {
            const wpNum = state.waypoints.length + 1;
            const wpName = (state.infraCode || 'INF') + ' W' + wpNum;
            state.waypoints.push({
                lat: captureLat,
                lon: captureLon,
                name: wpName,
                filename: filename,
                timestamp: new Date().toISOString(),
                seq: seq,
            });
        }

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
        formData.append('csrf_token', CFG.csrfToken || '');
        formData.append('client_token', uploadData.client_token || '');
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
        if (tipoTrabajo && tipoTrabajo.value) {
            formData.append('tipo_trabajo_id', tipoTrabajo.value);
        }
        if (unidadObra.value) {
            formData.append('unidad_obra_id', unidadObra.value);
        }
        formData.append('datos_tecnicos', uploadData.datos_tecnicos);

        // Campos dinámicos
        const dynFields = collectDynamicFields();
        for (const [campoId, valor] of Object.entries(dynFields)) {
            formData.append(`campos[${campoId}]`, valor);
        }

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

        // Show/hide waypoints download button
        const btnWp = $('#btn-waypoints-ficha');
        if (btnWp) {
            if (state.countComparativas > 0) {
                btnWp.classList.remove('hidden');
            } else {
                btnWp.classList.add('hidden');
            }
        }
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
        const wmCfg = CFG.watermark || {};

        // 1. Draw original photo
        ctx.drawImage(sourceCanvas, 0, 0);

        // 2. Text info — bottom-right, white with shadow
        // Text size: 1=small(0.020), 2=medium(0.028), 3=large(0.036), 4=xlarge(0.044)
        const textSizeFactors = { 1: 0.020, 2: 0.028, 3: 0.036, 4: 0.044 };
        const sizeFactor = textSizeFactors[wmCfg.textoTamano] || 0.028;
        const fontSize = Math.max(16, Math.round(h * sizeFactor));
        const lineHeight = fontSize * 1.4;
        const margin = Math.round(w * 0.025);

        // Build text lines (bottom-up, right-aligned like GPS Camera app)
        // All fields are configurable by the admin (default: all ON for base fields)
        const lines = [];
        const geo = meta.geoLocation || {};

        // País (bottom)
        if (wmCfg.pais !== 0 && geo.country) lines.push(geo.country);

        // Municipio, provincia CP
        if (wmCfg.ubicacion !== 0) {
            const locationParts = [];
            if (geo.city) locationParts.push(geo.city);
            if (geo.province || geo.postcode) {
                locationParts.push((geo.province || '') + (geo.postcode ? ' ' + geo.postcode : ''));
            }
            if (locationParts.length) lines.push(locationParts.join(', '));
        }

        // Orientación (e.g. "99° E")
        if (wmCfg.orientacion !== 0 && meta.bearing != null) {
            lines.push(`${meta.bearing}° ${bearingToCardinal(meta.bearing)}`);
        }

        // Coordenadas UTM
        if (wmCfg.coordenadas !== 0 && meta.lat != null && meta.lon != null) {
            const utm = latLonToUTM(meta.lat, meta.lon);
            lines.push(utm.str);
        }

        // --- Campos adicionales (admin opt-in) ---

        // Tipo de foto: FOT ALE / FOT COM
        if (wmCfg.tipoFoto && meta.mode) {
            lines.push(meta.mode === 'comparativo' ? 'FOT COM' : 'FOT ALE');
        }

        // Situación de obra: ANTES / DURANTE / DESPUÉS
        if (wmCfg.situacion && meta.situacion) {
            lines.push(meta.situacion);
        }

        // Código de infraestructura
        if (wmCfg.codigoInfra && meta.infraCode) {
            lines.push(meta.infraCode);
        }

        // Fecha y hora (top line)
        if (wmCfg.fecha !== 0) {
            lines.push(formatDateMadrid());
        }

        // Draw lines from bottom to top, right-aligned with text shadow
        ctx.textBaseline = 'bottom';
        ctx.textAlign = 'right';

        const textX = w - margin;
        let textY = h - margin;

        // Text shadow settings for readability
        ctx.shadowColor = 'rgba(0, 0, 0, 0.9)';
        ctx.shadowBlur = Math.max(5, Math.round(fontSize * 0.3));
        ctx.shadowOffsetX = 1;
        ctx.shadowOffsetY = 1;
        ctx.fillStyle = '#ffffff';

        for (let i = 0; i < lines.length; i++) {
            ctx.font = `bold ${fontSize}px Arial, Helvetica, sans-serif`;
            ctx.fillText(lines[i], textX, textY);
            textY -= lineHeight;
        }

        // Reset shadow
        ctx.shadowColor = 'transparent';
        ctx.shadowBlur = 0;
        ctx.shadowOffsetX = 0;
        ctx.shadowOffsetY = 0;
        ctx.textAlign = 'start';
        ctx.textBaseline = 'alphabetic';

        // 3. Compass rose (top-left) — configurable
        if (wmCfg.brujula !== 0) {
            drawCompassRose(ctx, w, h, meta.bearing);
        }

        // 4. Mini-map (bottom-left, optional) — with timeout to prevent blocking
        if (wmCfg.mapa && meta.lat != null && meta.lon != null) {
            try {
                await Promise.race([
                    drawMiniMap(ctx, w, h, meta.lat, meta.lon, wmCfg.mapaZoom || 15, wmCfg.mapaTamano || 2),
                    new Promise((_, reject) => setTimeout(() => reject(new Error('MiniMap timeout')), 8000)),
                ]);
            } catch (e) {
                console.warn('Mini-map skipped:', e.message);
            }
        }

    }

    /**
     * Draws a compass rose graphic in the top-left corner.
     * Shows N/S/E/O cardinal points and a blue arrow pointing to the device bearing.
     */
    function drawCompassRose(ctx, canvasWidth, canvasHeight, bearing) {
        const size = Math.round(Math.min(canvasWidth, canvasHeight) / 7);
        const cx = Math.round(size * 0.6);
        const cy = Math.round(size * 0.6);
        const outerR = Math.round(size * 0.42);
        const innerR = Math.round(size * 0.32);
        const fontSize = Math.max(10, Math.round(size * 0.12));

        ctx.save();

        // Semi-transparent circle background
        ctx.beginPath();
        ctx.arc(cx, cy, outerR, 0, Math.PI * 2);
        ctx.fillStyle = 'rgba(128, 128, 128, 0.5)';
        ctx.fill();
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.6)';
        ctx.lineWidth = 2;
        ctx.stroke();

        // Inner ring
        ctx.beginPath();
        ctx.arc(cx, cy, innerR, 0, Math.PI * 2);
        ctx.strokeStyle = 'rgba(255, 255, 255, 0.4)';
        ctx.lineWidth = 1;
        ctx.stroke();

        // Cardinal direction labels
        ctx.font = `bold ${fontSize}px Arial, sans-serif`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.fillStyle = '#ffffff';
        ctx.shadowColor = 'rgba(0,0,0,0.8)';
        ctx.shadowBlur = 3;

        const labelR = outerR - fontSize * 0.7;
        ctx.fillText('N', cx, cy - labelR);
        ctx.fillText('S', cx, cy + labelR);
        ctx.fillText('E', cx + labelR, cy);
        ctx.fillText('O', cx - labelR, cy);

        ctx.shadowColor = 'transparent';
        ctx.shadowBlur = 0;

        // Bearing arrow (blue, pointing in bearing direction)
        if (bearing != null) {
            const arrowR = innerR - 4;
            const bearingRad = (bearing - 90) * Math.PI / 180;

            ctx.save();
            ctx.translate(cx, cy);
            ctx.rotate(bearingRad);

            ctx.beginPath();
            ctx.moveTo(arrowR, 0);
            ctx.lineTo(-arrowR * 0.3, -arrowR * 0.2);
            ctx.lineTo(-arrowR * 0.15, 0);
            ctx.lineTo(-arrowR * 0.3, arrowR * 0.2);
            ctx.closePath();
            ctx.fillStyle = '#00bcd4';
            ctx.fill();
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 1;
            ctx.stroke();

            ctx.restore();

            ctx.beginPath();
            ctx.arc(cx, cy, 3, 0, Math.PI * 2);
            ctx.fillStyle = '#ffffff';
            ctx.fill();
        }

        ctx.restore();
    }

    /**
     * Draws a mini OpenStreetMap tile on the bottom-left of the photo.
     * Uses static tile server to render a small location map.
     */
    async function drawMiniMap(ctx, canvasWidth, canvasHeight, lat, lon, zoom, tamano) {
        // Map size based on tamano setting (1=small, 2=medium, 3=large)
        const sizeFactors = { 1: 0.15, 2: 0.20, 3: 0.28 };
        const factor = sizeFactors[tamano] || 0.20;
        const mapSize = Math.round(Math.min(canvasWidth, canvasHeight) * factor);
        const margin = Math.round(canvasWidth * 0.025);
        const mapX = margin;
        const mapY = canvasHeight - mapSize - margin;
        const borderRadius = Math.round(mapSize * 0.06);
        const borderWidth = Math.max(2, Math.round(mapSize * 0.015));

        try {
            // Calculate tile coordinates from lat/lon/zoom
            const n = Math.pow(2, zoom);
            const latRad = lat * Math.PI / 180;
            const tileXFloat = ((lon + 180) / 360) * n;
            const tileYFloat = (1 - Math.log(Math.tan(latRad) + 1 / Math.cos(latRad)) / Math.PI) / 2 * n;
            const tileX = Math.floor(tileXFloat);
            const tileY = Math.floor(tileYFloat);

            // Pixel offset within tile (256px tiles)
            const pixelX = Math.round((tileXFloat - tileX) * 256);
            const pixelY = Math.round((tileYFloat - tileY) * 256);

            // Load a 3x3 grid of tiles for context
            const tilePromises = [];
            for (let dy = -1; dy <= 1; dy++) {
                for (let dx = -1; dx <= 1; dx++) {
                    const tx = tileX + dx;
                    const ty = tileY + dy;
                    tilePromises.push(loadImage(
                        `https://tile.openstreetmap.org/${zoom}/${tx}/${ty}.png`
                    ).catch(() => null));
                }
            }
            const tiles = await Promise.all(tilePromises);

            // Si no se cargó ningún tile, no dibujar nada
            if (tiles.every(t => t === null)) {
                console.warn('Mini-map: no tiles loaded');
                return;
            }

            // Create off-screen canvas for the composite tile area (768x768)
            const tileCanvas = document.createElement('canvas');
            tileCanvas.width = 768;
            tileCanvas.height = 768;
            const tileCtx = tileCanvas.getContext('2d');

            let idx = 0;
            for (let dy = -1; dy <= 1; dy++) {
                for (let dx = -1; dx <= 1; dx++) {
                    const tile = tiles[idx++];
                    if (tile) {
                        tileCtx.drawImage(tile, (dx + 1) * 256, (dy + 1) * 256, 256, 256);
                    }
                }
            }

            // Verificar que el tileCanvas no esté tainted (CORS)
            // antes de dibujarlo en el canvas principal de la foto
            try {
                tileCanvas.toDataURL();
            } catch (taintErr) {
                console.warn('Mini-map: canvas tainted by tiles, skipping', taintErr);
                return;
            }

            // Canvas intermedio con el mapa completo (tiles + pin + borde)
            // para no contaminar el canvas principal si algo falla
            const mapCanvas = document.createElement('canvas');
            mapCanvas.width = mapSize;
            mapCanvas.height = mapSize;
            const mapCtx = mapCanvas.getContext('2d');

            // Center point in the composite canvas
            const centerX = 256 + pixelX;
            const centerY = 256 + pixelY;

            // Verify tileCanvas is not tainted before compositing to main canvas
            try {
                tileCanvas.toDataURL();
            } catch (_taintErr) {
                console.warn('Mini-map: canvas tainted by tiles, skipping');
                return;
            }

            // Draw rounded rectangle clip path on mapCanvas
            roundRect(mapCtx, 0, 0, mapSize, mapSize, borderRadius);
            mapCtx.clip();

            // Recortar exactamente mapSize px de los tiles (escala 1:1, sin reescalado borroso)
            const srcSize = mapSize;
            mapCtx.drawImage(tileCanvas,
                centerX - srcSize / 2, centerY - srcSize / 2, srcSize, srcSize,
                0, 0, mapSize, mapSize
            );

            // Reset clip
            mapCtx.restore();
            mapCtx.save();

            // Draw border
            roundRect(mapCtx, 0, 0, mapSize, mapSize, borderRadius);
            mapCtx.strokeStyle = 'rgba(255, 255, 255, 0.85)';
            mapCtx.lineWidth = borderWidth;
            mapCtx.stroke();

            // Draw red location pin in center
            const pinCx = mapSize / 2;
            const pinCy = mapSize / 2;
            const pinSize = Math.max(6, Math.round(mapSize * 0.06));

            mapCtx.shadowColor = 'rgba(0, 0, 0, 0.6)';
            mapCtx.shadowBlur = 4;
            mapCtx.shadowOffsetY = 2;

            // Pin circle
            mapCtx.beginPath();
            mapCtx.arc(pinCx, pinCy - pinSize, pinSize, 0, Math.PI * 2);
            mapCtx.fillStyle = '#e74c3c';
            mapCtx.fill();
            mapCtx.strokeStyle = '#fff';
            mapCtx.lineWidth = Math.max(1, Math.round(pinSize * 0.3));
            mapCtx.stroke();

            // Pin point (triangle)
            mapCtx.beginPath();
            mapCtx.moveTo(pinCx - pinSize * 0.5, pinCy - pinSize * 0.3);
            mapCtx.lineTo(pinCx, pinCy + pinSize * 0.5);
            mapCtx.lineTo(pinCx + pinSize * 0.5, pinCy - pinSize * 0.3);
            mapCtx.fillStyle = '#e74c3c';
            mapCtx.fill();

            // Inner dot
            mapCtx.shadowColor = 'transparent';
            mapCtx.shadowBlur = 0;
            mapCtx.beginPath();
            mapCtx.arc(pinCx, pinCy - pinSize, pinSize * 0.35, 0, Math.PI * 2);
            mapCtx.fillStyle = '#fff';
            mapCtx.fill();

            mapCtx.restore();

            // Verificación final: el mapCanvas no debe estar tainted
            try {
                mapCanvas.toDataURL();
            } catch (taintErr2) {
                console.warn('Mini-map: final canvas tainted, skipping', taintErr2);
                return;
            }

            // Todo OK — dibujar el mini-mapa en el canvas principal de la foto
            ctx.drawImage(mapCanvas, mapX, mapY);

        } catch (err) {
            console.warn('Error drawing mini-map:', err);
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
        // Provincia / municipio / monte filters
        if (filterProvincia) {
            filterProvincia.addEventListener('change', () => {
                loadMunicipios(filterProvincia.value);
                loadMontes();
                clearInfra();
            });
        }
        if (filterMunicipio) {
            filterMunicipio.addEventListener('change', () => {
                loadMontes();
                clearInfra();
            });
        }
        if (filterMonte) {
            filterMonte.addEventListener('change', () => {
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

        // Map search
        initMapSearch();

        // Camera
        btnCamBack.addEventListener('click', closeCamera);
        btnShutter.addEventListener('click', captureFrame);

        // Ghost toggle
        btnGhostToggle.addEventListener('click', () => {
            if (!state.ghostUrl) return;
            state.ghostActive = !state.ghostActive;
            btnGhostToggle.classList.toggle('active', state.ghostActive);
            if (state.ghostActive) {
                const val = ghostOpacitySlider ? parseInt(ghostOpacitySlider.value, 10) / 100 : 0.5;
                camGhost.style.opacity = val;
                camGhost.classList.remove('off');
            } else {
                camGhost.style.opacity = '0';
            }
            if (ghostOpacityBar) {
                ghostOpacityBar.classList.toggle('hidden', !state.ghostActive);
            }
        });

        // Ghost opacity slider
        if (ghostOpacitySlider) {
            ghostOpacitySlider.addEventListener('input', () => {
                const val = parseInt(ghostOpacitySlider.value, 10);
                const opacity = val / 100;
                camGhost.style.opacity = opacity;
                if (ghostOpacityValue) ghostOpacityValue.textContent = val + '%';
                // If slider is at 0, visually treat as off but keep ghost active state
                // so the user can slide back up without re-toggling
            });
        }

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

        // Guardar visita sin foto
        if (btnGuardarVisitaSinFoto) btnGuardarVisitaSinFoto.addEventListener('click', guardarVisitaSinFoto);

        // Waypoints download from ficha
        const btnWaypointsFicha = $('#btn-waypoints-ficha');
        if (btnWaypointsFicha) {
            btnWaypointsFicha.addEventListener('click', () => {
                if (state.infraId) downloadWaypoints(state.infraId);
                else downloadMyWaypoints();
            });
        }

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
    let mapUserAccuracyCircle = null; // GPS accuracy radius
    let mapGpsWatchId = null; // dedicated GPS watch for map auto-update
    let mapKmlLayers = []; // KML layer groups
    let mapWaypointLayer = null; // GPX waypoints layer
    let mapActiveBaseLayer = null;
    const mapBaseLayers = {};
    let mapAdminPointsLayer = null; // Admin custom points layer
    let mapAllInfrasCache = [];     // Cache for search

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
            mapBaseLayers.ortofoto = L.tileLayer.wms('https://www.ign.es/wms-inspire/pnoa-ma', {
                layers: 'OI.OrthoimageCoverage', format: 'image/png', transparent: false,
                attribution: '&copy; IGN España - PNOA', maxZoom: 20,
            });
            mapBaseLayers.topografico = L.tileLayer.wms('https://www.ign.es/wms-inspire/mapa-raster', {
                layers: 'mtn_rasterizado', format: 'image/png', transparent: false,
                attribution: '&copy; IGN España - MTN', maxZoom: 20,
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

        // Invalidate map size after screen transition
        setTimeout(() => { if (leafletMap) leafletMap.invalidateSize(); }, 100);

        // Show user position on map and start auto-tracking
        updateUserPositionOnMap();
        startMapGpsTracking();

        // Load data
        await loadMapData();
    }

    // Invalidate map size on device rotation / resize
    window.addEventListener('resize', () => {
        if (leafletMap && state.screen === 'mapa') {
            leafletMap.invalidateSize();
        }
    });

    function closeMapScreen() {
        if (navActive) stopNavigation();
        stopMapGpsTracking();
        // Limpiar marcador y círculo de precisión del usuario para evitar memory leak
        if (mapUserMarker) {
            leafletMap.removeLayer(mapUserMarker);
            mapUserMarker = null;
        }
        if (mapUserAccuracyCircle) {
            leafletMap.removeLayer(mapUserAccuracyCircle);
            mapUserAccuracyCircle = null;
        }
        showScreen('ficha');
    }

    function updateUserPositionOnMap(accuracy) {
        if (!leafletMap || !state.gps.lat || !state.gps.lon) return;

        const userIcon = L.divIcon({
            className: 'user-location-marker',
            html: `<div style="width:22px;height:22px;position:relative;">
                    <div style="position:absolute;inset:0;border-radius:50%;background:rgba(66,133,244,0.2);animation:userPulse 2s ease-out infinite;"></div>
                    <div style="position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;
                         background:#4285f4;border:3px solid #fff;
                         box-shadow:0 0 0 2px rgba(66,133,244,0.4),0 2px 8px rgba(0,0,0,0.3);"></div>
                   </div>`,
            iconSize: [22, 22],
            iconAnchor: [11, 11],
        });

        if (mapUserMarker) {
            mapUserMarker.setLatLng([state.gps.lat, state.gps.lon]);
        } else {
            mapUserMarker = L.marker([state.gps.lat, state.gps.lon], {
                icon: userIcon, zIndexOffset: 1000,
            }).addTo(leafletMap);
            mapUserMarker.bindTooltip('Tu ubicación', { direction: 'top', offset: [0, -14] });
        }

        // Show accuracy circle (hide when accuracy degrades above 500m)
        if (accuracy && accuracy < 500) {
            if (mapUserAccuracyCircle) {
                mapUserAccuracyCircle.setLatLng([state.gps.lat, state.gps.lon]);
                mapUserAccuracyCircle.setRadius(accuracy);
            } else {
                mapUserAccuracyCircle = L.circle([state.gps.lat, state.gps.lon], {
                    radius: accuracy,
                    color: '#4285f4',
                    fillColor: '#4285f4',
                    fillOpacity: 0.08,
                    weight: 1,
                    opacity: 0.3,
                }).addTo(leafletMap);
            }
        } else if (mapUserAccuracyCircle) {
            leafletMap.removeLayer(mapUserAccuracyCircle);
            mapUserAccuracyCircle = null;
        }
    }

    function startMapGpsTracking() {
        if (mapGpsWatchId !== null) return;
        if (!('geolocation' in navigator)) return;

        mapGpsWatchId = navigator.geolocation.watchPosition(
            (pos) => {
                state.gps.lat = pos.coords.latitude;
                state.gps.lon = pos.coords.longitude;
                updateUserPositionOnMap(pos.coords.accuracy);
            },
            () => {},
            { enableHighAccuracy: true, maximumAge: 3000 }
        );
    }

    function stopMapGpsTracking() {
        if (mapGpsWatchId !== null) {
            navigator.geolocation.clearWatch(mapGpsWatchId);
            mapGpsWatchId = null;
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

            // Cache for map search
            mapAllInfrasCache = Object.values(allInfras);

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

            // Fit bounds (zoom configurable: 9=1:500k, 10=1:250k, 12=1:100k, 13=1:50k)
            if (bounds.length > 0) {
                leafletMap.fitBounds(bounds, { padding: [50, 50], maxZoom: CFG.opMapaZoom || 9 });
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
            // Load infrastructure GeoJSON layers (si el admin lo permite)
            if (CFG.opMostrarCapasInfra) loadMapInfraLayers();
            // Load waypoints GPX layer (comparative photos, "antes" state)
            loadMapWaypoints();
            // Load admin custom points
            loadMapAdminPoints();

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
                renderKmlToLayer(capa.contenido_kml, group, capa.color || '#8b5cf6', parseInt(capa.grosor) || 3, parseFloat(capa.opacidad) || 0.8);
            });
        } catch (err) {
            console.warn('Error loading KML layers:', err);
        }
    }

    function renderKmlToLayer(kmlText, layerGroup, color, weight, opacity) {
        weight = weight || 3;
        opacity = opacity || 0.8;
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
                    L.polyline(lineCoords, { color, weight, opacity })
                        .bindPopup(popupContent)
                        .addTo(layerGroup);
                }
            }

            // Polygons
            const polyEl = pm.querySelector('Polygon outerBoundaryIs LinearRing coordinates');
            if (polyEl) {
                const polyCoords = parseKmlCoords(polyEl.textContent);
                if (polyCoords.length > 0) {
                    L.polygon(polyCoords, { color, fillColor: color, fillOpacity: opacity * 0.2, weight, opacity })
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

    // ---------------------------------------------------------------
    // Infrastructure GeoJSON Layers (from capas_infraestructuras)
    // ---------------------------------------------------------------
    let mapInfraLayers = [];

    async function loadMapInfraLayers() {
        if (!CFG.endpoints.capasInfra) return;
        try {
            mapInfraLayers.forEach(lg => leafletMap.removeLayer(lg));
            mapInfraLayers = [];

            const res = await fetch(`${CFG.endpoints.capasInfra}?empresa_id=${CFG.empresaId}`);
            const data = await res.json();
            if (!data.ok || !data.capas || data.capas.length === 0) return;

            data.capas.forEach(capa => {
                try {
                    const geojson = typeof capa.geojson === 'string' ? JSON.parse(capa.geojson) : capa.geojson;
                    const color = capa.color || '#e74c3c';
                    const weight = parseInt(capa.grosor) || 2;
                    const opacity = parseFloat(capa.opacidad) || 0.8;
                    const campoLink = capa.campo_capa || '';

                    const layer = L.geoJSON(geojson, {
                        style: () => ({
                            color, weight, opacity,
                            fillColor: color, fillOpacity: opacity * 0.2
                        }),
                        pointToLayer: (feature, latlng) => L.circleMarker(latlng, {
                            radius: 6, fillColor: color, color: '#fff',
                            weight: 2, opacity: 1, fillOpacity: opacity
                        }),
                        onEachFeature: (feature, featureLayer) => {
                            const props = feature.properties || {};
                            const linkValue = campoLink ? (props[campoLink] || '') : '';

                            let html = `<div style="max-width:240px;">`;
                            html += `<strong style="color:${color};">${capa.nombre}</strong>`;
                            if (linkValue) html += `<br><code style="font-size:0.75rem;">${campoLink}: ${linkValue}</code>`;

                            let shown = 0;
                            Object.keys(props).forEach(k => {
                                if (shown >= 4 || k === campoLink) return;
                                html += `<br><small><b>${k}:</b> ${String(props[k]).substring(0, 60)}</small>`;
                                shown++;
                            });
                            html += '</div>';

                            featureLayer.bindPopup(html, { maxWidth: 260 });
                        }
                    }).addTo(leafletMap);

                    mapInfraLayers.push(layer);
                } catch (err) {
                    console.warn('Error rendering capa infra:', err);
                }
            });
        } catch (err) {
            console.warn('Error loading infra layers:', err);
        }
    }

    // Load GPX waypoints (comparative+antes) and render on map with route line
    async function loadMapWaypoints() {
        if (!CFG.endpoints.waypoints || !leafletMap) return;
        try {
            if (mapWaypointLayer) {
                leafletMap.removeLayer(mapWaypointLayer);
                mapWaypointLayer = null;
            }

            const url = `${CFG.endpoints.waypoints}?empresa_id=${CFG.empresaId}&usuario_id=${CFG.usuarioId}&format=json&estado=antes`;
            const res = await fetch(url);
            const data = await res.json();
            if (!data.ok || !data.waypoints || data.waypoints.length === 0) return;

            mapWaypointLayer = L.layerGroup().addTo(leafletMap);

            // Group waypoints by infrastructure to draw route lines
            const byInfra = {};
            data.waypoints.forEach(wp => {
                const key = wp.infra_id;
                if (!byInfra[key]) byInfra[key] = [];
                byInfra[key].push(wp);
            });

            Object.values(byInfra).forEach(wps => {
                // Only show waypoint markers (no route line between them)
                // Navigation line is drawn only when user requests "ir a" a specific point
                wps.forEach(wp => {
                    const icon = L.divIcon({
                        className: 'wp-marker',
                        html: `<div style="width:22px;height:22px;border-radius:50%;background:#22c55e;
                                border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,0.4);
                                display:flex;align-items:center;justify-content:center;
                                font-size:8px;font-weight:800;color:#fff;">W${wp.num}</div>`,
                        iconSize: [22, 22],
                        iconAnchor: [11, 11],
                    });

                    const fechaFmt = wp.fecha ? wp.fecha.substring(0, 16).replace('T', ' ') : '';
                    L.marker([wp.lat, wp.lon], { icon, zIndexOffset: 200 })
                        .bindTooltip(`<b>${escHtml(wp.nombre)}</b><br><small>${fechaFmt}</small>`, {
                            direction: 'top', offset: [0, -12],
                        })
                        .addTo(mapWaypointLayer);
                });
            });
        } catch (err) {
            console.warn('Error loading waypoints:', err);
        }
    }

    // ===================================================================
    // ADMIN CUSTOM POINTS (puntos_mapa)
    // ===================================================================
    const iconoMap = {
        pin: 'bi-geo-alt-fill', star: 'bi-star-fill', flag: 'bi-flag-fill',
        house: 'bi-house-fill', box: 'bi-box-fill', exclamation: 'bi-exclamation-triangle-fill',
        tools: 'bi-tools', person: 'bi-person-fill',
    };

    async function loadMapAdminPoints() {
        if (!CFG.endpoints.puntosMapa || !leafletMap) return;
        try {
            if (mapAdminPointsLayer) {
                leafletMap.removeLayer(mapAdminPointsLayer);
                mapAdminPointsLayer = null;
            }

            const res = await fetch(`${CFG.endpoints.puntosMapa}?empresa_id=${CFG.empresaId}`);
            const data = await res.json();
            if (!data.ok || !data.puntos || data.puntos.length === 0) return;

            mapAdminPointsLayer = L.layerGroup().addTo(leafletMap);

            data.puntos.forEach(pt => {
                const lat = parseFloat(pt.lat);
                const lon = parseFloat(pt.lon);
                if (!lat || !lon) return;

                const biClass = iconoMap[pt.icono] || 'bi-geo-alt-fill';
                const color = pt.color || '#e74c3c';

                const icon = L.divIcon({
                    className: 'admin-point-marker',
                    html: `<div style="width:30px;height:30px;border-radius:50%;background:${color};
                            border:3px solid rgba(255,255,255,0.95);box-shadow:0 2px 8px rgba(0,0,0,0.4);
                            display:flex;align-items:center;justify-content:center;">
                            <i class="bi ${biClass}" style="font-size:12px;color:#fff;"></i>
                           </div>`,
                    iconSize: [30, 30],
                    iconAnchor: [15, 15],
                });

                let tooltipHtml = `<b>${escHtml(pt.nombre)}</b>`;
                if (pt.descripcion) tooltipHtml += `<br><small>${escHtml(pt.descripcion)}</small>`;
                if (state.gps.lat && state.gps.lon) {
                    const dist = haversineDistance(state.gps.lat, state.gps.lon, lat, lon);
                    tooltipHtml += `<br><span style="color:#4285f4;">${formatDistance(dist)}</span>`;
                }

                L.marker([lat, lon], { icon, zIndexOffset: 100 })
                    .bindTooltip(tooltipHtml, { direction: 'top', offset: [0, -12] })
                    .addTo(mapAdminPointsLayer);
            });
        } catch (err) {
            console.warn('Error loading admin points:', err);
        }
    }

    // ===================================================================
    // MAP SEARCH / FILTER
    // ===================================================================
    function initMapSearch() {
        const toggleBtn = document.getElementById('btn-mapa-search-toggle');
        const searchBar = document.getElementById('mapa-search-bar');
        const searchInput = document.getElementById('mapa-search-input');
        const searchResults = document.getElementById('mapa-search-results');
        const closeBtn = document.getElementById('btn-mapa-search-close');
        if (!toggleBtn || !searchBar || !searchInput) return;

        let debounce = null;

        toggleBtn.addEventListener('click', () => {
            const visible = !searchBar.classList.contains('hidden');
            if (visible) {
                searchBar.classList.add('hidden');
                searchResults.classList.add('hidden');
                searchInput.value = '';
            } else {
                searchBar.classList.remove('hidden');
                setTimeout(() => searchInput.focus(), 100);
            }
        });

        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                searchBar.classList.add('hidden');
                searchResults.classList.add('hidden');
                searchInput.value = '';
            });
        }

        searchInput.addEventListener('input', () => {
            clearTimeout(debounce);
            const q = searchInput.value.trim().toLowerCase();
            if (q.length < 2) {
                searchResults.classList.add('hidden');
                return;
            }
            debounce = setTimeout(() => doMapSearch(q, searchResults), 200);
        });
    }

    function doMapSearch(query, resultsEl) {
        const matches = mapAllInfrasCache.filter(inf => {
            const nombre = (inf.nombre || '').toLowerCase();
            const codigo = (inf.codigo || '').toLowerCase();
            return nombre.includes(query) || codigo.includes(query);
        }).slice(0, 15);

        if (matches.length === 0) {
            resultsEl.innerHTML = '<div style="padding:14px;text-align:center;color:#9ca3af;font-size:0.82rem;">Sin resultados</div>';
            resultsEl.classList.remove('hidden');
            return;
        }

        let html = '';
        matches.forEach(inf => {
            const hasPhotos = inf.registros && inf.registros.length > 0;
            const dotColor = hasPhotos ? '#22c55e' : '#9ca3af';
            let distHtml = '';
            if (state.gps.lat && state.gps.lon && inf.lat && inf.lon) {
                const dist = haversineDistance(state.gps.lat, state.gps.lon, inf.lat, inf.lon);
                distHtml = `<span class="search-dist">${formatDistance(dist)}</span>`;
            }
            html += `<div class="mapa-search-item" data-infra-id="${inf.id}">
                <span class="search-dot" style="background:${dotColor};"></span>
                <div class="search-info">
                    <strong>${escHtml(inf.nombre)}</strong>
                    <small>${escHtml(inf.codigo || '')}${hasPhotos ? ' · ' + inf.registros.length + ' fotos' : ' · Sin visitar'}</small>
                </div>
                ${distHtml}
            </div>`;
        });

        resultsEl.innerHTML = html;
        resultsEl.classList.remove('hidden');

        // Click handler for results
        resultsEl.querySelectorAll('.mapa-search-item').forEach(el => {
            el.addEventListener('click', () => {
                const infraId = parseInt(el.dataset.infraId);
                const infra = mapAllInfrasCache.find(i => i.id === infraId || i.id === String(infraId));
                if (infra && infra.lat && infra.lon) {
                    leafletMap.setView([infra.lat, infra.lon], 17, { animate: true });
                    showInfraDetail(infra);
                }
                resultsEl.classList.add('hidden');
                document.getElementById('mapa-search-bar').classList.add('hidden');
                document.getElementById('mapa-search-input').value = '';
            });
        });
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
        // fitBounds necesita al menos 2 puntos distintos; con 1 solo usar setView
        if (bounds.length < 2) {
            leafletMap.setView([navTarget.lat, navTarget.lon], 16);
            return;
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
        // Undo the counters incremented in captureFrame (sin bajar de 0)
        state.countTotal = Math.max(0, state.countTotal - 1);
        if (state.currentMode === 'comparativo') {
            state.seqComparativa = Math.max(0, state.seqComparativa - 1);
            state.countComparativas = Math.max(0, state.countComparativas - 1);
        } else {
            state.countAleatorias = Math.max(0, state.countAleatorias - 1);
        }

        // Persist decremented counter
        if (state.infraId) saveInfraSeq(state.infraId, state.countTotal);

        state.annotation = null;
        state.annotationMode = false;
        state.pendingFilename = null;
        // Free large ImageData from memory
        state.baseImageData = null;
        state.capturedBlob = null;

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

    function getSelectedTipoTrabajoName() {
        if (!tipoTrabajo || !tipoTrabajo.value) return '';
        const opt = tipoTrabajo.options[tipoTrabajo.selectedIndex];
        if (!opt || !opt.value) return '';
        // Remove codigo prefix if present (e.g. "INSP - Inspección" → "Inspección")
        const text = opt.textContent.trim();
        const dashIdx = text.indexOf(' - ');
        return dashIdx >= 0 ? text.substring(dashIdx + 3) : text;
    }

    function canvasToBlob(canvas, type, quality) {
        return new Promise((resolve, reject) => {
            const timer = setTimeout(() => {
                reject(new Error('Canvas toBlob timeout'));
            }, 10000);
            try {
                canvas.toBlob((blob) => {
                    clearTimeout(timer);
                    if (!blob) {
                        reject(new Error('Canvas toBlob returned null'));
                        return;
                    }
                    resolve(blob);
                }, type, quality);
            } catch (err) {
                clearTimeout(timer);
                reject(new Error('Canvas tainted or toBlob failed: ' + err.message));
            }
        });
    }

    function loadImage(src, timeoutMs = 5000) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            const timer = setTimeout(() => {
                img.onload = img.onerror = null;
                img.src = '';
                reject(new Error('Image load timeout'));
            }, timeoutMs);
            img.onload = () => { clearTimeout(timer); resolve(img); };
            img.onerror = () => { clearTimeout(timer); reject(new Error('Image load error')); };
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

            // Always show install button in user menu
            const menuBtn = document.getElementById('btn-install-menu');
            if (menuBtn) menuBtn.style.display = '';

            // Only show banner if user hasn't dismissed recently
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
            alert('Para instalar INFOCAMPO en tu iPhone:\n\n1. Pulsa el botón Compartir (cuadrado con flecha)\n2. Desplázate y pulsa "Añadir a pantalla de inicio"\n3. Confirma pulsando "Añadir"');
        } else {
            alert('Para instalar INFOCAMPO:\n\n1. Abre el menú del navegador (tres puntos)\n2. Pulsa "Instalar aplicación" o "Añadir a pantalla de inicio"');
        }
    }

    // Global function for install button in user menu
    window.triggerInstallFromMenu = async function() {
        if (deferredInstallPrompt) {
            deferredInstallPrompt.prompt();
            const { outcome } = await deferredInstallPrompt.userChoice;
            deferredInstallPrompt = null;
            if (outcome === 'accepted') {
                const menuBtn = document.getElementById('btn-install-menu');
                if (menuBtn) menuBtn.style.display = 'none';
                banner.classList.add('hidden');
                showNotification('App instalada correctamente');
            }
        } else {
            showInstallInstructions();
        }
    };

    // Show install button in menu for iOS (beforeinstallprompt doesn't fire)
    const isIOSCheck = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    if (isIOSCheck && !isStandalone) {
        const menuBtn = document.getElementById('btn-install-menu');
        if (menuBtn) menuBtn.style.display = '';
    }

    // ===================================================================
    // FINALIZAR VISITA (Guardar y Resetear)
    // ===================================================================
    // ===================================================================
    // WAYPOINTS — Download GPX from server
    // ===================================================================
    function downloadWaypoints(infraId, fecha) {
        let url = `${CFG.endpoints.waypoints}?empresa_id=${CFG.empresaId}`;
        if (infraId) url += `&infra_id=${infraId}`;
        if (fecha) url += `&fecha=${encodeURIComponent(fecha)}`;
        // Open in new tab to trigger download
        window.open(url, '_blank');
    }

    function downloadMyWaypoints() {
        let url = `${CFG.endpoints.waypoints}?empresa_id=${CFG.empresaId}&usuario_id=${CFG.usuarioId}`;
        if (state.infraId) url += `&infra_id=${state.infraId}`;
        window.open(url, '_blank');
    }

    async function guardarVisitaSinFoto() {
        if (!state.infraId) {
            alert('Selecciona una infraestructura primero.');
            return;
        }

        // Deshabilitar botón mientras guarda
        if (btnGuardarVisitaSinFoto) {
            btnGuardarVisitaSinFoto.disabled = true;
            btnGuardarVisitaSinFoto.innerHTML = '<i class="bi bi-hourglass-split"></i> Guardando...';
        }

        try {
            const formData = new FormData();
            formData.append('csrf_token', CFG.csrfToken || '');
            formData.append('infra_id', state.infraId);
            formData.append('lat_real', state.gps.lat || 0);
            formData.append('lon_real', state.gps.lon || 0);
            formData.append('estado_incidencia', SITUACIONES[state.situacionIdx]);
            formData.append('observaciones', ($('#observaciones-general') || {}).value || '');

            if (tipoTrabajo && tipoTrabajo.value) {
                formData.append('tipo_trabajo_id', tipoTrabajo.value);
            }
            if (unidadObra.value) {
                formData.append('unidad_obra_id', unidadObra.value);
            }

            // Campos dinámicos
            const dynFields = collectDynamicFields();
            for (const [campoId, valor] of Object.entries(dynFields)) {
                formData.append(`campos[${campoId}]`, valor);
            }

            const res = await fetch(CFG.endpoints.guardarVisita, { method: 'POST', body: formData });
            const data = await res.json();

            if (data.ok) {
                showNotification(`Visita a "${state.infraName}" guardada correctamente`);
                // Resetear estado como finalizarVisita
                finalizarVisita();
            } else {
                alert('Error: ' + (data.error || 'Error desconocido'));
            }
        } catch (err) {
            console.error('Error guardando visita:', err);
            alert('Error de conexión al guardar la visita.');
        } finally {
            if (btnGuardarVisitaSinFoto) {
                btnGuardarVisitaSinFoto.disabled = false;
                btnGuardarVisitaSinFoto.innerHTML = '<i class="bi bi-save-fill"></i> Guardar visita';
            }
        }
    }

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
        // Revocar blob URLs de fotos de galería para liberar memoria
        state.photos.forEach(function(p) {
            if (p.url && p.url.startsWith('blob:')) {
                try { URL.revokeObjectURL(p.url); } catch (_e) {}
            }
        });
        state.photos = [];
        state.waypoints = [];
        if (state.prevPhotos.length > 0 && window.InfocampoOffline && window.InfocampoOffline.revokeBlobUrls) {
            window.InfocampoOffline.revokeBlobUrls(state.prevPhotos);
        }
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
                    <div style="display:flex;gap:8px;flex-wrap:wrap;padding:0 12px 8px;">
                        <button type="button" class="btn-continuar-visita" data-visita-idx="${idx}">
                            <i class="bi bi-pencil-square"></i> Continuar visita
                        </button>`;

                // Show waypoints download if there are comparative photos
                const hasComp = visita.fotos.some(f => f.tipo === 'comparativo');
                if (hasComp) {
                    html += `<button type="button" class="btn-descargar-waypoints" data-infra-id="${visita.infra_id}" data-fecha="${visita.fecha}"
                                style="flex:none;padding:6px 14px;font-size:0.8rem;font-weight:600;
                                border:none;border-radius:8px;background:#22c55e;color:#fff;cursor:pointer;
                                display:flex;align-items:center;gap:4px;">
                            <i class="bi bi-geo-alt"></i> Waypoints GPX
                        </button>`;
                }

                html += `</div></div>`;
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

            // Bind "Descargar waypoints" buttons
            visitasBody.querySelectorAll('.btn-descargar-waypoints').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const infraId = btn.dataset.infraId;
                    const fecha = btn.dataset.fecha;
                    downloadWaypoints(infraId, fecha);
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
        state.seqComparativa = 0;
        let visitPhotoCount = 0;

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
                visitPhotoCount++;

                addToGallery(url, tipo, nombre, seq);
            });
        }

        // Use persistent counter (already loaded by selectInfra), ensure it's at least visit photo count
        state.countTotal = Math.max(state.countTotal, visitPhotoCount);

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
        formData.append('csrf_token', CFG.csrfToken || '');
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
