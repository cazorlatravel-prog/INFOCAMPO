/**
 * INFOCAMPO - Módulo Watermark
 * Procesa la imagen capturada: añade franja de datos + mini-mapa OSM.
 */
const Watermark = (() => {
    const previewCanvas = document.getElementById('preview-canvas');
    let processedBlob = null;

    /**
     * Procesa la imagen del canvas de captura y la dibuja
     * en el canvas de preview con marca de agua.
     *
     * @param {HTMLCanvasElement} sourceCanvas  Canvas con la foto original
     * @param {Object} meta  { lat, lon, code, fecha }
     */
    async function process(sourceCanvas, meta) {
        const w = sourceCanvas.width;
        const h = sourceCanvas.height;

        previewCanvas.width = w;
        previewCanvas.height = h;

        const ctx = previewCanvas.getContext('2d');

        // 1. Dibujar la foto original
        ctx.drawImage(sourceCanvas, 0, 0);

        // 2. Franja inferior semitransparente
        const barHeight = Math.max(60, h * 0.07);
        ctx.fillStyle = 'rgba(0, 0, 0, 0.6)';
        ctx.fillRect(0, h - barHeight, w, barHeight);

        // Texto de la franja
        const fontSize = Math.max(14, Math.round(barHeight * 0.32));
        ctx.font = `bold ${fontSize}px -apple-system, sans-serif`;
        ctx.fillStyle = '#ffffff';
        ctx.textBaseline = 'middle';

        const dateStr = meta.fecha.toLocaleString('es-ES', {
            day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit'
        });

        const latStr = meta.lat != null ? meta.lat.toFixed(7) : '--';
        const lonStr = meta.lon != null ? meta.lon.toFixed(7) : '--';

        const line = `${dateStr}  |  GPS: ${latStr}, ${lonStr}  |  ${meta.code}`;
        ctx.fillText(line, 16, h - barHeight / 2);

        // 3. Mini-mapa OSM (esquina superior derecha)
        await drawMiniMap(ctx, w, meta.lat, meta.lon);

        // 4. Convertir a JPEG blob (calidad 0.8)
        processedBlob = await canvasToBlob(previewCanvas, 'image/jpeg', 0.8);
    }

    /**
     * Dibuja un tile estático de OpenStreetMap en la esquina superior derecha.
     */
    async function drawMiniMap(ctx, canvasWidth, lat, lon) {
        if (lat == null || lon == null) return;

        const mapSize = Math.max(80, Math.round(canvasWidth * 0.12));
        const margin = 12;
        const x = canvasWidth - mapSize - margin;
        const y = margin;

        // Fondo mientras carga
        ctx.fillStyle = 'rgba(0,0,0,0.5)';
        ctx.fillRect(x, y, mapSize, mapSize);
        ctx.strokeStyle = '#ffffff';
        ctx.lineWidth = 2;
        ctx.strokeRect(x, y, mapSize, mapSize);

        try {
            const zoom = 17;
            const tileUrl = buildOsmTileUrl(lat, lon, zoom);

            const img = await loadImage(tileUrl);

            // Dibujar tile recortado al cuadrado
            ctx.drawImage(img, x, y, mapSize, mapSize);

            // Borde
            ctx.strokeStyle = '#ffffff';
            ctx.lineWidth = 2;
            ctx.strokeRect(x, y, mapSize, mapSize);

            // Pin central
            ctx.fillStyle = '#ef4444';
            ctx.beginPath();
            ctx.arc(x + mapSize / 2, y + mapSize / 2, 4, 0, Math.PI * 2);
            ctx.fill();
        } catch {
            // Si falla el mapa, dejar el placeholder
            ctx.font = 'bold 11px sans-serif';
            ctx.fillStyle = '#fff';
            ctx.textBaseline = 'middle';
            ctx.textAlign = 'center';
            ctx.fillText('MAPA', x + mapSize / 2, y + mapSize / 2);
            ctx.textAlign = 'start';
        }
    }

    /**
     * Construye la URL de un tile OSM para lat/lon/zoom.
     */
    function buildOsmTileUrl(lat, lon, zoom) {
        const n = Math.pow(2, zoom);
        const xTile = Math.floor((lon + 180) / 360 * n);
        const yTile = Math.floor(
            (1 - Math.log(Math.tan(lat * Math.PI / 180) +
            1 / Math.cos(lat * Math.PI / 180)) / Math.PI) / 2 * n
        );
        return `https://tile.openstreetmap.org/${zoom}/${xTile}/${yTile}.png`;
    }

    /**
     * Carga una imagen como promesa.
     */
    function loadImage(src) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => resolve(img);
            img.onerror = reject;
            img.src = src;
        });
    }

    /**
     * Canvas → Blob como promesa.
     */
    function canvasToBlob(canvas, type, quality) {
        return new Promise((resolve) => {
            canvas.toBlob(resolve, type, quality);
        });
    }

    /**
     * Devuelve el blob procesado (llamado por Upload).
     */
    function getBlob() {
        return processedBlob;
    }

    return { process, getBlob };
})();
