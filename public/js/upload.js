/**
 * INFOCAMPO - Módulo Upload
 * Envía la imagen procesada + metadatos al backend PHP via AJAX.
 * Soporta campos dinámicos definidos por el superadmin.
 */
const Upload = (() => {
    const statusEl = document.getElementById('upload-status');

    /**
     * Carga los campos dinámicos de la empresa desde la API
     * y los renderiza en el contenedor #dynamic-fields.
     */
    async function loadDynamicFields(empresaId) {
        const container = document.getElementById('dynamic-fields');
        if (!container || !empresaId) return;

        try {
            const res = await fetch(`api/campos.php?empresa_id=${empresaId}`);
            const data = await res.json();

            if (!data.ok || !data.campos || data.campos.length === 0) {
                container.innerHTML = '';
                return;
            }

            let html = '<div class="dynamic-fields-title">Datos adicionales</div>';

            data.campos.forEach(campo => {
                const req = campo.obligatorio ? 'required' : '';
                const reqMark = campo.obligatorio ? '<span class="req">*</span>' : '';
                html += `<div class="dyn-field">`;
                html += `<label>${campo.nombre}${reqMark}</label>`;

                switch (campo.tipo) {
                    case 'texto':
                        html += `<input type="text" name="campos[${campo.id}]" placeholder="${campo.nombre}" ${req}>`;
                        break;
                    case 'numero':
                        html += `<input type="number" name="campos[${campo.id}]" placeholder="0" step="any" ${req}>`;
                        break;
                    case 'select':
                        html += `<select name="campos[${campo.id}]" ${req}>`;
                        html += `<option value="">-- Seleccionar --</option>`;
                        if (campo.opciones) {
                            campo.opciones.forEach(opt => {
                                html += `<option value="${opt}">${opt}</option>`;
                            });
                        }
                        html += `</select>`;
                        break;
                    case 'checkbox':
                        html += `<label class="chk-label"><input type="checkbox" name="campos[${campo.id}]" value="1"> ${campo.nombre}</label>`;
                        break;
                    case 'textarea':
                        html += `<textarea name="campos[${campo.id}]" placeholder="${campo.nombre}" rows="2" ${req}></textarea>`;
                        break;
                    case 'fecha':
                        html += `<input type="date" name="campos[${campo.id}]" ${req}>`;
                        break;
                }

                html += `</div>`;
            });

            container.innerHTML = html;
        } catch (err) {
            console.warn('No se pudieron cargar campos dinámicos:', err);
        }
    }

    /**
     * Recopila los valores de los campos dinámicos del DOM.
     */
    function collectDynamicFields() {
        const fields = {};
        const container = document.getElementById('dynamic-fields');
        if (!container) return fields;

        container.querySelectorAll('[name^="campos["]').forEach(el => {
            const match = el.name.match(/campos\[(\d+)\]/);
            if (!match) return;
            const campoId = match[1];

            if (el.type === 'checkbox') {
                fields[campoId] = el.checked ? '1' : '0';
            } else {
                fields[campoId] = el.value;
            }
        });
        return fields;
    }

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

        // Campos dinámicos
        const dynFields = collectDynamicFields();
        for (const [campoId, valor] of Object.entries(dynFields)) {
            formData.append(`campos[${campoId}]`, valor);
        }

        try {
            const res = await fetch(window.INFOCAMPO_CONFIG.uploadEndpoint, {
                method: 'POST',
                body: formData
            });

            const data = await res.json();

            statusEl.classList.add('hidden');

            if (data.ok) {
                alert('Registro guardado correctamente.');
                Camera.retake();
            } else {
                alert('Error: ' + (data.error || 'Error desconocido'));
            }
        } catch (err) {
            statusEl.classList.add('hidden');
            alert('Error de red: ' + err.message);
        }
    }

    return { send, loadDynamicFields };
})();
