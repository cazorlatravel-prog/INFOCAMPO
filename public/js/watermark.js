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
     * @param {Object} meta  { lat, lon, code, fecha, empresaName, infraName, situacion }
     */
    async function process(sourceCanvas, meta) {
        const w = sourceCanvas.width;
        const h = sourceCanvas.height;

        previewCanvas.width = w;
        previewCanvas.height = h;

        const ctx = previewCanvas.getContext('2d');

        // 1. Dibujar la foto original
        ctx.drawImage(sourceCanvas, 0, 0);

        // 2. Bloque de info abajo-derecha
        const fontSize = Math.max(14, Math.round(h * 0.02));
        const lineHeight = fontSize * 1.5;
        const numLines = 5;
        const padding = 16;
        const blockHeight = lineHeight * numLines + padding * 2;

        // Prepare text lines to measure widths
        const empresaStr = meta.empresaName || '';
        const infraStr = meta.infraName || meta.code || '';
        const situacionStr = meta.situacion ? `Situación: ${meta.situacion}` : '';
        const dateStr = meta.fecha.toLocaleString('es-ES', {
            timeZone: 'Europe/Madrid',
            day: '2-digit', month: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
            hour12: false,
        });
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

        const bx = w - blockWidth - 12;
        const by = h - blockHeight - 12;
        ctx.fillStyle = 'rgba(0, 0, 0, 0.65)';
        ctx.beginPath();
        const r = 8;
        ctx.moveTo(bx + r, by);
        ctx.lineTo(bx + blockWidth - r, by);
        ctx.quadraticCurveTo(bx + blockWidth, by, bx + blockWidth, by + r);
        ctx.lineTo(bx + blockWidth, by + blockHeight - r);
        ctx.quadraticCurveTo(bx + blockWidth, by + blockHeight, bx + blockWidth - r, by + blockHeight);
        ctx.lineTo(bx + r, by + blockHeight);
        ctx.quadraticCurveTo(bx, by + blockHeight, bx, by + blockHeight - r);
        ctx.lineTo(bx, by + r);
        ctx.quadraticCurveTo(bx, by, bx + r, by);
        ctx.closePath();
        ctx.fill();

        ctx.fillStyle = '#ffffff';
        ctx.textBaseline = 'top';
        ctx.textAlign = 'left';

        const textX = bx + padding;
        let textY = by + padding;

        // Line 1: Empresa
        ctx.font = `bold ${fontSize}px -apple-system, sans-serif`;
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

        ctx.textAlign = 'start';

        // 3. Mini-mapa OSM (arriba-izquierda, 1/8 de imagen)
        await drawMiniMap(ctx, w, h, meta.lat, meta.lon);

        // 4. Convertir a JPEG blob (calidad 0.8)
        processedBlob = await canvasToBlob(previewCanvas, 'image/jpeg', 0.8);
    }

    /**
     * Dibuja un tile estático de OpenStreetMap en la esquina superior izquierda (1/6 de imagen).
     */
    async function drawMiniMap(ctx, canvasWidth, canvasHeight, lat, lon) {
        if (lat == null || lon == null) return;

        const mapSize = Math.round(Math.min(canvasWidth, canvasHeight) / 6);
        const x = 0;
        const y = 0;

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
