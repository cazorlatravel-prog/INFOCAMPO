/**
 * INFOCAMPO - Módulo Cámara + Ghosting + GPS
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
    let stream = null;
    let capturedBlob = null;

    const cfg = window.INFOCAMPO_CONFIG;

    // =========================================================
    // Iniciar cámara trasera
    // =========================================================
    async function initCamera() {
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: {
                    facingMode: { ideal: 'environment' },
                    width:  { ideal: 1920 },
                    height: { ideal: 1080 }
                },
                audio: false
            });
            video.srcObject = stream;
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

        navigator.geolocation.watchPosition(
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
            },
            (err) => {
                gpsDistance.textContent = 'GPS error';
            },
            { enableHighAccuracy: true, maximumAge: 3000 }
        );
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
        } catch {
            // Sin imagen ghost disponible – silencioso
        }
    }

    // =========================================================
    // Captura de frame
    // =========================================================
    function captureFrame() {
        const vw = video.videoWidth;
        const vh = video.videoHeight;
        captureCanvas.width = vw;
        captureCanvas.height = vh;

        const ctx = captureCanvas.getContext('2d');
        ctx.drawImage(video, 0, 0, vw, vh);

        // Detener el video
        video.pause();

        // Pasar al módulo de watermark
        Watermark.process(captureCanvas, {
            lat:    currentPosition.lat,
            lon:    currentPosition.lon,
            code:   cfg.infraCode,
            fecha:  new Date()
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
        loadGhostImage();
        bindEvents();
    }

    document.addEventListener('DOMContentLoaded', init);

    return { retake, getCurrentNivel: () => niveles[nivelIdx] };
})();
