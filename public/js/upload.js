/**
 * INFOCAMPO - Módulo Upload
 * Envía la imagen procesada + metadatos al backend PHP via AJAX.
 */
const Upload = (() => {
    const statusEl = document.getElementById('upload-status');

    /**
     * Envía la captura al servidor.
     * @param {Object} meta  { infraId, usuarioId, lat, lon, incidencia, observaciones, infraCode }
     */
    async function send(meta) {
        const blob = Watermark.getBlob();
        if (!blob) {
            alert('No hay imagen para enviar.');
            return;
        }

        statusEl.classList.remove('hidden');

        const formData = new FormData();
        formData.append('imagen', blob, `inspeccion_${Date.now()}.jpg`);
        formData.append('infra_id',           meta.infraId);
        formData.append('usuario_id',         meta.usuarioId);
        formData.append('lat_real',           meta.lat);
        formData.append('lon_real',           meta.lon);
        formData.append('estado_incidencia',  meta.incidencia);
        formData.append('observaciones',      meta.observaciones || '');
        formData.append('codigo_infra',       meta.infraCode);

        // datos_tecnicos como JSON
        formData.append('datos_tecnicos', JSON.stringify({
            timestamp_captura: new Date().toISOString(),
            user_agent: navigator.userAgent,
            screen: `${screen.width}x${screen.height}`
        }));

        try {
            const res = await fetch(window.INFOCAMPO_CONFIG.uploadEndpoint, {
                method: 'POST',
                body: formData
            });

            const data = await res.json();

            statusEl.classList.add('hidden');

            if (data.ok) {
                alert('Registro guardado correctamente.');
                // Volver a la cámara
                Camera.retake();
            } else {
                alert('Error: ' + (data.error || 'Error desconocido'));
            }
        } catch (err) {
            statusEl.classList.add('hidden');
            alert('Error de red: ' + err.message);
        }
    }

    return { send };
})();
