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
        loadUnidadesObra();
        bindEvents();
        initGPS();
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
    // INFRASTRUCTURE SEARCH
    // ===================================================================
    let searchTimeout = null;

    function searchInfra(query) {
        clearTimeout(searchTimeout);
        if (query.length < 1) {
            infraResults.classList.add('hidden');
            return;
        }

        searchTimeout = setTimeout(async () => {
            try {
                const res = await fetch(
                    `${CFG.endpoints.infraestructuras}?empresa_id=${CFG.empresaId}&q=${encodeURIComponent(query)}`
                );
                const data = await res.json();

                if (!data.ok) return;

                let html = '';
                data.infraestructuras.forEach(inf => {
                    html += `<div class="result-item" data-id="${inf.id}" data-name="${escHtml(inf.nombre)}" data-code="${escHtml(inf.codigo_unico)}">
                        ${escHtml(inf.nombre)} <span class="result-code">${escHtml(inf.codigo_unico)}</span>
                    </div>`;
                });

                // Option to create new
                html += `<div class="result-new" data-new="true">
                    <i class="bi bi-plus-circle"></i> Crear: "${escHtml(query)}"
                </div>`;

                infraResults.innerHTML = html;
                infraResults.classList.remove('hidden');

                // Bind clicks
                infraResults.querySelectorAll('.result-item').forEach(el => {
                    el.addEventListener('click', () => selectInfra(
                        parseInt(el.dataset.id),
                        el.dataset.name,
                        el.dataset.code
                    ));
                });

                infraResults.querySelector('.result-new').addEventListener('click', () => {
                    createNewInfra(query);
                });
            } catch (err) {
                console.warn('Error searching infra:', err);
            }
        }, 300);
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
                    video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } },
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
        camVideo.pause();
        // Keep stream alive for reuse
        camGhost.classList.remove('active');
        showScreen('ficha');
    }

    // ===================================================================
    // PREVIOUS COMPARATIVE PHOTOS
    // ===================================================================
    async function checkPreviousPhotos() {
        if (!state.infraId) return;
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
    function captureFrame() {
        const vw = camVideo.videoWidth;
        const vh = camVideo.videoHeight;
        camCapture.width = vw;
        camCapture.height = vh;

        const ctx = camCapture.getContext('2d');
        ctx.drawImage(camVideo, 0, 0, vw, vh);

        camVideo.pause();

        // Generate filename
        let filename = '';
        if (state.currentMode === 'comparativo') {
            state.seqComparativa++;
            filename = `W${state.seqComparativa}_${sanitizeFilename(state.infraName)}`;
        } else {
            filename = `foto_${sanitizeFilename(state.infraName)}_${Date.now()}`;
        }

        // Apply watermark
        applyWatermark(camCapture, previewCanvas, {
            lat: state.gps.lat,
            lon: state.gps.lon,
            infraName: state.infraName,
            infraCode: state.infraCode,
            filename: filename,
            mode: state.currentMode,
            seq: state.currentMode === 'comparativo' ? state.seqComparativa : null,
        }).then(() => {
            previewFilename.textContent = filename;
            showScreen('preview');
        });
    }

    function retakePhoto() {
        if (state.currentMode === 'comparativo') {
            state.seqComparativa--;
        }
        camVideo.play();
        showScreen('camera');
    }

    async function acceptPhoto() {
        // Convert preview to blob
        const blob = await canvasToBlob(previewCanvas, 'image/jpeg', 0.85);
        if (!blob) { alert('Error al procesar la foto.'); return; }

        // Show upload overlay
        uploadOverlay.classList.remove('hidden');

        // Build filename
        let filename = '';
        let seq = null;
        if (state.currentMode === 'comparativo') {
            seq = state.seqComparativa;
            filename = `W${seq}_${sanitizeFilename(state.infraName)}`;
        } else {
            filename = `foto_${sanitizeFilename(state.infraName)}_${Date.now()}`;
        }

        // Upload
        const formData = new FormData();
        formData.append('imagen', blob, filename + '.jpg');
        formData.append('infra_id', state.infraId);
        formData.append('usuario_id', CFG.usuarioId);
        formData.append('lat_real', state.gps.lat || 0);
        formData.append('lon_real', state.gps.lon || 0);
        formData.append('estado_incidencia', 'bajo');
        formData.append('tipo_foto', state.currentMode);
        formData.append('nombre_archivo', filename);
        formData.append('observaciones', $('#observaciones-general').value || '');

        if (seq !== null) {
            formData.append('secuencia_comparativa', seq);
        }

        if (unidadObra.value) {
            formData.append('unidad_obra_id', unidadObra.value);
        }

        formData.append('datos_tecnicos', JSON.stringify({
            timestamp: new Date().toISOString(),
            etrs89_lat: state.gps.lat,
            etrs89_lon: state.gps.lon,
            timezone: 'Europe/Madrid',
            mode: state.currentMode,
        }));

        try {
            const res = await fetch(CFG.endpoints.upload, { method: 'POST', body: formData });
            const data = await res.json();

            uploadOverlay.classList.add('hidden');

            if (data.ok) {
                // Add to gallery
                addToGallery(data.url_imagen, state.currentMode, filename, seq);

                // Update counters
                if (state.currentMode === 'aleatorio') {
                    state.countAleatorias++;
                    countAleatorias.textContent = state.countAleatorias;
                } else {
                    state.countComparativas++;
                    countComparativas.textContent = state.countComparativas;

                    // Use this photo as ghost for next comparative shot
                    setGhostImage(data.url_imagen);
                    camSeqLabel.textContent = 'W' + (state.seqComparativa + 1);
                }

                // Go back to camera for more photos
                camVideo.play();
                showScreen('camera');
            } else {
                alert('Error: ' + (data.error || 'Error desconocido'));
                camVideo.play();
                showScreen('camera');
            }
        } catch (err) {
            uploadOverlay.classList.add('hidden');
            alert('Error de red: ' + err.message);
            camVideo.play();
            showScreen('camera');
        }
    }

    // ===================================================================
    // GALLERY
    // ===================================================================
    function addToGallery(url, type, name, seq) {
        gallerySection.classList.remove('hidden');

        const div = document.createElement('div');
        div.className = 'gallery-item';
        div.innerHTML = `
            <img src="${escHtml(url)}" alt="${escHtml(name)}" loading="lazy">
            <span class="gallery-type ${type}">${type === 'comparativo' ? 'W' + seq : 'ALEA'}</span>
            <div class="gallery-label">${escHtml(name)}</div>
        `;
        galleryGrid.appendChild(div);

        state.photos.push({ url, type, seq, name });
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

        // 2. Bottom bar
        const barHeight = Math.max(70, h * 0.08);
        ctx.fillStyle = 'rgba(0, 0, 0, 0.7)';
        ctx.fillRect(0, h - barHeight, w, barHeight);

        const fontSize = Math.max(13, Math.round(barHeight * 0.22));
        ctx.font = `bold ${fontSize}px -apple-system, sans-serif`;
        ctx.fillStyle = '#ffffff';
        ctx.textBaseline = 'middle';

        // ETRS89 coordinates
        const latStr = meta.lat != null ? meta.lat.toFixed(7) : '--';
        const lonStr = meta.lon != null ? meta.lon.toFixed(7) : '--';

        // Date/time Madrid
        const dateStr = formatDateMadrid();

        // Line 1: Date + coords
        const line1 = `${dateStr}  |  ETRS89: ${latStr}, ${lonStr}`;
        ctx.fillText(line1, 16, h - barHeight * 0.65);

        // Line 2: Infrastructure name + filename
        ctx.font = `${fontSize}px -apple-system, sans-serif`;
        ctx.fillStyle = 'rgba(255,255,255,0.8)';
        const line2 = meta.infraName + (meta.filename ? '  |  ' + meta.filename : '');
        ctx.fillText(line2, 16, h - barHeight * 0.3);

        // 3. Mode badge (top-left)
        if (meta.mode === 'comparativo' && meta.seq) {
            const badgeW = 80;
            const badgeH = 30;
            ctx.fillStyle = 'rgba(168, 85, 247, 0.8)';
            roundRect(ctx, 12, 12, badgeW, badgeH, 8);
            ctx.fill();
            ctx.fillStyle = '#fff';
            ctx.font = `bold ${Math.round(badgeH * 0.6)}px -apple-system, sans-serif`;
            ctx.textBaseline = 'middle';
            ctx.fillText('W' + meta.seq, 24, 12 + badgeH / 2);
        }

        // 4. Mini-map OSM (top-right)
        await drawMiniMap(ctx, w, meta.lat, meta.lon);

        // Save blob for later
        state.capturedBlob = await canvasToBlob(targetCanvas, 'image/jpeg', 0.85);
    }

    async function drawMiniMap(ctx, canvasWidth, lat, lon) {
        if (lat == null || lon == null) return;

        const mapSize = Math.max(80, Math.round(canvasWidth * 0.1));
        const margin = 12;
        const x = canvasWidth - mapSize - margin;
        const y = margin;

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
    // EVENTS
    // ===================================================================
    function bindEvents() {
        // Infrastructure search
        infraSearch.addEventListener('input', (e) => searchInfra(e.target.value));
        infraSearch.addEventListener('focus', (e) => {
            if (e.target.value.length > 0) searchInfra(e.target.value);
        });
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

        // Preview
        btnRetake.addEventListener('click', retakePhoto);
        btnAccept.addEventListener('click', acceptPhoto);
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
    // START
    // ===================================================================
    document.addEventListener('DOMContentLoaded', init);

})();
