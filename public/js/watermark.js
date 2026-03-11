/**
 * INFOCAMPO - Módulo Watermark
 * Procesa la imagen capturada: texto GPS Camera style + brújula gráfica.
 * Formato de referencia: fecha, UTM, rumbo, ubicación geocodificada, brújula.
 */
const Watermark = (() => {
    const previewCanvas = document.getElementById('preview-canvas');
    let processedBlob = null;

    // ===================================================================
    // UTM CONVERSION (lat/lon WGS84 → UTM)
    // ===================================================================
    function latLonToUTM(lat, lon) {
        const a = 6378137;
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

    function formatDateGPS(fecha) {
        const meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        const parts = new Intl.DateTimeFormat('es-ES', {
            timeZone: 'Europe/Madrid',
            day: 'numeric', month: 'numeric', year: 'numeric',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
            hour12: false,
        }).formatToParts(fecha);
        const get = (type) => (parts.find(p => p.type === type) || {}).value || '';
        const day = get('day');
        const month = parseInt(get('month'), 10);
        const year = get('year');
        const hour = get('hour');
        const minute = get('minute');
        const second = get('second');
        return `${day} ${meses[month - 1]} ${year} ${hour}:${minute}:${second}`;
    }

    function bearingToCardinal(deg) {
        if (deg == null) return '';
        const dirs = ['N', 'NE', 'E', 'SE', 'S', 'SO', 'O', 'NO'];
        return dirs[Math.round(deg / 45) % 8];
    }

    /**
     * Procesa la imagen del canvas de captura y la dibuja
     * en el canvas de preview con marca de agua estilo GPS Camera.
     *
     * @param {HTMLCanvasElement} sourceCanvas  Canvas con la foto original
     * @param {Object} meta  { lat, lon, code, fecha, empresaName, infraName, situacion, bearing, geoLocation }
     */
    async function process(sourceCanvas, meta) {
        const w = sourceCanvas.width;
        const h = sourceCanvas.height;

        previewCanvas.width = w;
        previewCanvas.height = h;

        const ctx = previewCanvas.getContext('2d');

        // 1. Dibujar la foto original
        ctx.drawImage(sourceCanvas, 0, 0);

        // 2. Texto info — abajo-derecha, sin fondo, blanco con sombra
        const fontSize = Math.max(16, Math.round(h * 0.022));
        const lineHeight = fontSize * 1.4;
        const margin = Math.round(w * 0.02);

        const lines = [];
        const geo = meta.geoLocation || {};

        // Bottom line first: Country
        if (geo.country) lines.push(geo.country);

        // City, Province PostalCode
        const locationParts = [];
        if (geo.city) locationParts.push(geo.city);
        if (geo.province || geo.postcode) {
            locationParts.push((geo.province || '') + (geo.postcode ? ' ' + geo.postcode : ''));
        }
        if (locationParts.length) lines.push(locationParts.join(', '));

        // Bearing
        if (meta.bearing != null) {
            lines.push(`${meta.bearing}° ${bearingToCardinal(meta.bearing)}`);
        }

        // UTM coordinates
        if (meta.lat != null && meta.lon != null) {
            const utm = latLonToUTM(meta.lat, meta.lon);
            lines.push(utm.str);
        }

        // Date and time (top line)
        lines.push(formatDateGPS(meta.fecha || new Date()));

        // Draw lines from bottom to top, right-aligned
        ctx.textBaseline = 'bottom';
        ctx.textAlign = 'right';
        ctx.shadowColor = 'rgba(0, 0, 0, 0.9)';
        ctx.shadowBlur = Math.max(4, Math.round(fontSize * 0.25));
        ctx.shadowOffsetX = 1;
        ctx.shadowOffsetY = 1;
        ctx.fillStyle = '#ffffff';

        const textX = w - margin;
        let textY = h - margin;

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

        // 3. Brújula (arriba-izquierda)
        drawCompassRose(ctx, w, h, meta.bearing);

        // 4. Convertir a JPEG blob
        processedBlob = await canvasToBlob(previewCanvas, 'image/jpeg', 0.85);
    }

    /**
     * Dibuja una rosa de los vientos en la esquina superior izquierda.
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

        // Cardinal labels
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

        // Bearing arrow
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
