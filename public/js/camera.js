/**
 * INFOCAMPO - Módulo Cámara + Ghosting + GPS + Brújula
 */
const Camera = (() => {
    // ---- Elementos del DOM ----
    const video         = document.getElementById('camera-feed');
    const ghostOverlay  = document.getElementById('ghost-overlay');
    const captureCanvas = document.getElementById('capture-canvas');
    const btnGhost      = document.getElementById('btn-ghost');
    const btnCapture    = document.getElementById('btn-capture');
    const btnIncidencia = document.getElementById('btn-incidencia');
    const incLabel      = document.getElementById('inc-label');
    const gpsCircle     = document.getElementById('gps-circle');
    const gpsDistance   = document.getElementById('gps-distance');
    const infraCode     = document.getElementById('infra-code');
    const currentTime   = document.getElementById('current-time');
    const cameraContainer  = document.getElementById('camera-container');
    const previewContainer = document.getElementById('preview-container');

    // ---- Estado ----
    const niveles = ['antes', 'durante', 'despues'];
    const nivelesUI = ['ANTES', 'DURANTE', 'DESPUÉS'];
    let nivelIdx = 0;
    let ghostActive = false;
    let currentPosition = { lat: null, lon: null };
    let currentBearing = null;
    let geoLocation = null;
    let stream = null;
    let capturedBlob = null;
    let gpsWatchId = null;

    const cfg = window.INFOCAMPO_CONFIG;

    // =========================================================
    // Iniciar cámara trasera — máxima resolución
    // =========================================================
    async function initCamera() {
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: { ideal: 'environment' },
                    width:  { ideal: 3264 },
                    height: { ideal: 2448 }
                },
                audio: false
            });
            video.srcObject = stream;
            // Ensure iOS Safari plays inline
            video.setAttribute('playsinline', '');
            video.setAttribute('autoplay', '');
            video.setAttribute('muted', '');
        } catch (err) {
            alert('No se pudo acceder a la cámara: ' + err.message);
        }
    }

    // =========================================================
    // GPS – watchPosition
    // =========================================================
    function initGPS() {
        if (!('geolocation' in navigator)) {
            gpsDistance.textContent = 'Sin GPS';
            return;
        }

        gpsWatchId = navigator.geolocation.watchPosition(
            (pos) => {
                currentPosition.lat = pos.coords.latitude;
                currentPosition.lon = pos.coords.longitude;

                const d = Haversine.distance(
                    currentPosition.lat, currentPosition.lon,
                    cfg.targetLat, cfg.targetLon
                );

                const color = Haversine.colorForDistance(d);
                gpsCircle.className = color;
                gpsDistance.textContent = Haversine.formatDistance(d);

                // Reverse geocode in background
                reverseGeocode(currentPosition.lat, currentPosition.lon);
            },
            (err) => {
                gpsDistance.textContent = 'GPS error';
            },
            { enableHighAccuracy: true, maximumAge: 3000 }
        );
    }

    // =========================================================
    // Compass bearing (Device Orientation)
    // =========================================================
    function initCompass() {
        if (typeof DeviceOrientationEvent !== 'undefined' &&
            typeof DeviceOrientationEvent.requestPermission === 'function') {
            // iOS — request on user gesture (capture button)
            return;
        }
        _startCompassListener();
    }

    function _startCompassListener() {
        window.addEventListener('deviceorientationabsolute', (e) => {
            if (e.absolute && e.alpha != null) {
                currentBearing = Math.round(360 - e.alpha) % 360;
            }
        }, true);
        window.addEventListener('deviceorientation', (e) => {
            if (currentBearing != null) return;
            if (e.webkitCompassHeading != null) {
                currentBearing = Math.round(e.webkitCompassHeading);
            } else if (e.alpha != null) {
                currentBearing = Math.round(360 - e.alpha) % 360;
            }
        }, true);
    }

    // =========================================================
    // Reverse geocoding (throttled, Nominatim)
    // =========================================================
    let _lastGeoLat = null;
    let _lastGeoLon = null;

    async function reverseGeocode(lat, lon) {
        if (_lastGeoLat != null && _lastGeoLon != null) {
            const dist = Haversine.distance(lat, lon, _lastGeoLat, _lastGeoLon);
            if (dist < 200 && geoLocation) return;
        }
        try {
            const res = await fetch(
                `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lon}&zoom=16&addressdetails=1`,
                { headers: { 'Accept-Language': 'es' } }
            );
            const data = await res.json();
            const addr = data.address || {};
            geoLocation = {
                city: addr.city || addr.town || addr.village || addr.municipality || '',
                province: addr.state || addr.province || addr.county || '',
                postcode: addr.postcode || '',
                country: addr.country || '',
            };
            _lastGeoLat = lat;
            _lastGeoLon = lon;
        } catch (_e) { /* silencioso */ }
    }

    // =========================================================
    // Ghosting – cargar última foto
    // =========================================================
    async function loadGhostImage() {
        try {
            const res = await fetch(
                `${cfg.ghostImageEndpoint}?infra_id=${cfg.infraId}`
            );
            const data = await res.json();
            if (data.url) {
                ghostOverlay.src = data.url;
                ghostOverlay.classList.add('active');
                ghostActive = true;
                btnGhost.classList.add('active');
            }
        } catch (_e) {
            // Sin imagen ghost disponible – silencioso
        }
    }

    // =========================================================
    // Captura de frame
    // =========================================================
    async function captureFrame() {
        // iOS compass permission (needs user gesture)
        if (typeof DeviceOrientationEvent !== 'undefined' &&
            typeof DeviceOrientationEvent.requestPermission === 'function') {
            try {
                const perm = await DeviceOrientationEvent.requestPermission();
                if (perm === 'granted') _startCompassListener();
            } catch (_e) { /* denied */ }
        }

        const vw = video.videoWidth;
        const vh = video.videoHeight;

        if (!vw || !vh) {
            console.warn('captureFrame: video not ready (dimensions 0)');
            return;
        }

        captureCanvas.width = vw;
        captureCanvas.height = vh;

        const ctx = captureCanvas.getContext('2d');
        ctx.drawImage(video, 0, 0, vw, vh);

        // Detener el video
        video.pause();

        // Ensure geocoding for current position
        if (currentPosition.lat != null && currentPosition.lon != null) {
            await reverseGeocode(currentPosition.lat, currentPosition.lon);
        }

        // Pasar al módulo de watermark con datos completos
        Watermark.process(captureCanvas, {
            lat:         currentPosition.lat,
            lon:         currentPosition.lon,
            code:        cfg.infraCode,
            fecha:       new Date(),
            bearing:     currentBearing,
            geoLocation: geoLocation,
        });

        // Mostrar preview
        cameraContainer.classList.add('hidden');
        previewContainer.classList.remove('hidden');
    }

    // =========================================================
    // Repetir (volver a cámara)
    // =========================================================
    function retake() {
        previewContainer.classList.add('hidden');
        cameraContainer.classList.remove('hidden');
        video.play();
    }

    // =========================================================
    // Reloj
    // =========================================================
    function updateClock() {
        const now = new Date();
        currentTime.textContent = now.toLocaleTimeString('es-ES', {
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    // =========================================================
    // Event listeners
    // =========================================================
    function bindEvents() {
        // Toggle ghosting
        btnGhost.addEventListener('click', () => {
            if (!ghostOverlay.src || ghostOverlay.src === location.href) return;
            ghostActive = !ghostActive;
            ghostOverlay.classList.toggle('off', !ghostActive);
            btnGhost.classList.toggle('active', ghostActive);
        });

        // Capturar
        btnCapture.addEventListener('click', captureFrame);

        // Ciclar situación (antes/durante/después)
        btnIncidencia.addEventListener('click', () => {
            nivelIdx = (nivelIdx + 1) % niveles.length;
            incLabel.textContent = nivelesUI[nivelIdx];
        });

        // Repetir
        document.getElementById('btn-retake').addEventListener('click', retake);

        // Enviar
        document.getElementById('btn-upload').addEventListener('click', () => {
            Upload.send({
                infraId:    cfg.infraId,
                usuarioId:  cfg.usuarioId,
                lat:        currentPosition.lat,
                lon:        currentPosition.lon,
                incidencia: niveles[nivelIdx],
                observaciones: document.getElementById('observaciones').value,
                infraCode: cfg.infraCode
            });
        });
    }

    // =========================================================
    // Init
    // =========================================================
    function init() {
        infraCode.textContent = cfg.infraCode;
        updateClock();
        setInterval(updateClock, 30000);

        initCamera();
        initGPS();
        initCompass();
        loadGhostImage();
        bindEvents();
    }

    document.addEventListener('DOMContentLoaded', init);

    function stopCamera() {
        if (stream) {
            stream.getTracks().forEach(function(t) { t.stop(); });
            stream = null;
        }
        video.pause();
        video.srcObject = null;
        if (gpsWatchId != null) {
            navigator.geolocation.clearWatch(gpsWatchId);
            gpsWatchId = null;
        }
    }

    return { retake, getCurrentNivel: () => niveles[nivelIdx], stopCamera };
})();
