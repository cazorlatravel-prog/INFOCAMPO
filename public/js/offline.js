/**
 * INFOCAMPO - Módulo Offline
 *
 * Proporciona:
 *   1. IndexedDB para almacenamiento local de fotos pendientes y datos de formulario
 *   2. Cola de sincronización automática cuando vuelve la conexión
 *   3. Sistema de precarga de fotos comparativas para ghosting sin red
 *   4. Indicadores de estado online/offline
 */
;(function () {
    'use strict';

    const DB_NAME = 'infocampo_offline';
    const DB_VERSION = 1;
    const STORE_QUEUE = 'upload_queue';     // Fotos pendientes de subir
    const STORE_PRECACHE = 'photo_cache';   // Fotos precargadas para ghosting

    let db = null;
    let isSyncing = false;
    let syncRetryTimer = null;

    // ===================================================================
    // CALLBACKS — set by the main app
    // ===================================================================
    const callbacks = {
        onStatusChange: null,   // (online: boolean) => void
        onQueueChange: null,    // (count: number) => void
        onSyncProgress: null,   // ({ synced, total, current }) => void
        onSyncComplete: null,   // (results: []) => void
        onSyncError: null,      // (error) => void
        onPrecacheProgress: null, // ({ loaded, total }) => void
    };

    // ===================================================================
    // INIT
    // ===================================================================
    async function init(opts) {
        if (opts) Object.assign(callbacks, opts);

        db = await openDB();
        setupConnectivityListeners();
        updateOnlineStatus();

        // Check if there are pending items and we're online
        const pending = await getQueueCount();
        if (pending > 0 && navigator.onLine) {
            setTimeout(() => syncQueue(), 2000);
        }
        if (callbacks.onQueueChange) {
            callbacks.onQueueChange(pending);
        }
    }

    // ===================================================================
    // INDEXED DB
    // ===================================================================
    function openDB() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = (e) => {
                const db = e.target.result;

                // Store for upload queue
                if (!db.objectStoreNames.contains(STORE_QUEUE)) {
                    const queueStore = db.createObjectStore(STORE_QUEUE, {
                        keyPath: 'id',
                        autoIncrement: true,
                    });
                    queueStore.createIndex('timestamp', 'timestamp', { unique: false });
                    queueStore.createIndex('infra_id', 'infra_id', { unique: false });
                }

                // Store for precached photos
                if (!db.objectStoreNames.contains(STORE_PRECACHE)) {
                    const cacheStore = db.createObjectStore(STORE_PRECACHE, {
                        keyPath: 'key',
                    });
                    cacheStore.createIndex('infra_id', 'infra_id', { unique: false });
                    cacheStore.createIndex('cached_at', 'cached_at', { unique: false });
                }
            };

            request.onsuccess = (e) => resolve(e.target.result);
            request.onerror = (e) => reject(e.target.error);
        });
    }

    function dbTransaction(storeName, mode) {
        const tx = db.transaction(storeName, mode);
        return tx.objectStore(storeName);
    }

    function dbRequest(req) {
        return new Promise((resolve, reject) => {
            req.onsuccess = () => resolve(req.result);
            req.onerror = () => reject(req.error);
        });
    }

    // ===================================================================
    // CONNECTIVITY LISTENERS
    // ===================================================================
    function setupConnectivityListeners() {
        window.addEventListener('online', () => {
            updateOnlineStatus();
            // Auto-sync when back online
            setTimeout(() => syncQueue(), 1500);
        });

        window.addEventListener('offline', () => {
            updateOnlineStatus();
            clearTimeout(syncRetryTimer);
        });
    }

    function updateOnlineStatus() {
        const online = navigator.onLine;
        document.documentElement.setAttribute('data-online', online ? 'true' : 'false');
        if (callbacks.onStatusChange) {
            callbacks.onStatusChange(online);
        }
    }

    function isOnline() {
        return navigator.onLine;
    }

    // ===================================================================
    // UPLOAD QUEUE — Save photo locally for later sync
    // ===================================================================

    /**
     * Adds a photo + form data to the offline queue.
     * @param {Blob} imageBlob - The JPEG blob from the canvas
     * @param {Object} formData - All the form fields that would go to subir.php
     * @returns {Promise<number>} The queued item ID
     */
    async function enqueue(imageBlob, formData) {
        // Convert blob to ArrayBuffer for IndexedDB storage
        const arrayBuffer = await imageBlob.arrayBuffer();

        const item = {
            timestamp: Date.now(),
            imageData: arrayBuffer,
            imageMime: imageBlob.type || 'image/jpeg',
            formData: formData,
            attempts: 0,
            lastAttempt: null,
            status: 'pending', // pending | uploading | failed
        };

        const store = dbTransaction(STORE_QUEUE, 'readwrite');
        const id = await dbRequest(store.add(item));

        const count = await getQueueCount();
        if (callbacks.onQueueChange) callbacks.onQueueChange(count);

        return id;
    }

    /**
     * Get the number of pending items in the queue
     */
    async function getQueueCount() {
        const store = dbTransaction(STORE_QUEUE, 'readonly');
        return dbRequest(store.count());
    }

    /**
     * Get all items in the queue
     */
    async function getQueueItems() {
        const store = dbTransaction(STORE_QUEUE, 'readonly');
        return dbRequest(store.getAll());
    }

    /**
     * Remove a successfully synced item from the queue
     */
    async function removeFromQueue(id) {
        const store = dbTransaction(STORE_QUEUE, 'readwrite');
        await dbRequest(store.delete(id));
        const count = await getQueueCount();
        if (callbacks.onQueueChange) callbacks.onQueueChange(count);
    }

    /**
     * Update an item's status and attempt count
     */
    async function updateQueueItem(id, updates) {
        const store = dbTransaction(STORE_QUEUE, 'readwrite');
        const item = await dbRequest(store.get(id));
        if (!item) return;
        Object.assign(item, updates);
        await dbRequest(store.put(item));
    }

    // ===================================================================
    // SYNC QUEUE — Upload pending photos when online
    // ===================================================================

    /**
     * Process the entire upload queue sequentially
     */
    async function syncQueue() {
        // Guard sincronizado: marcar antes de cualquier await para evitar
        // que dos disparadores (timer de reintento, evento online, botón manual)
        // entren a la vez y suban el mismo item dos veces.
        if (isSyncing || !navigator.onLine) return;
        isSyncing = true;

        const results = [];
        try {
            const items = await getQueueItems();
            if (items.length === 0) {
                return;
            }

            let synced = 0;

            for (const item of items) {
                if (!navigator.onLine) break;

                if (callbacks.onSyncProgress) {
                    callbacks.onSyncProgress({
                        synced: synced,
                        total: items.length,
                        current: item.formData.nombre_archivo || 'foto',
                    });
                }

                try {
                    await updateQueueItem(item.id, {
                        status: 'uploading',
                        lastAttempt: Date.now(),
                        attempts: item.attempts + 1,
                    });

                    const result = await uploadSingle(item);
                    await removeFromQueue(item.id);
                    synced++;
                    results.push({ id: item.id, ok: true, result: result });
                } catch (err) {
                    console.warn('Sync failed for item', item.id, err);
                    await updateQueueItem(item.id, { status: 'failed' });
                    results.push({ id: item.id, ok: false, error: err.message });

                    // If network error, stop trying
                    if (!navigator.onLine) break;
                }
            }
        } finally {
            isSyncing = false;
        }

        if (callbacks.onSyncComplete) {
            callbacks.onSyncComplete(results);
        }

        // Check if there are still pending items (failed ones)
        const remaining = await getQueueCount();
        if (callbacks.onQueueChange) callbacks.onQueueChange(remaining);

        // If there are failures and we're still online, retry after delay
        if (remaining > 0 && navigator.onLine) {
            clearTimeout(syncRetryTimer);
            syncRetryTimer = setTimeout(() => syncQueue(), 30000);
        }
    }

    /**
     * Upload a single queued item to the server
     */
    async function uploadSingle(item) {
        const blob = new Blob([item.imageData], { type: item.imageMime });
        const fd = item.formData;

        const formData = new FormData();
        // CSRF e idempotencia (para evitar duplicados al reintentar)
        if (fd.csrf_token) formData.append('csrf_token', fd.csrf_token);
        if (fd.client_token) formData.append('client_token', fd.client_token);
        formData.append('imagen', blob, (fd.nombre_archivo || 'foto') + '.jpg');
        formData.append('infra_id', fd.infra_id);
        formData.append('usuario_id', fd.usuario_id);
        formData.append('lat_real', fd.lat_real);
        formData.append('lon_real', fd.lon_real);
        formData.append('estado_incidencia', fd.estado_incidencia || 'antes');
        formData.append('tipo_foto', fd.tipo_foto || 'aleatorio');
        formData.append('nombre_archivo', fd.nombre_archivo || '');
        formData.append('observaciones', fd.observaciones || '');

        if (fd.secuencia_comparativa != null) {
            formData.append('secuencia_comparativa', fd.secuencia_comparativa);
        }
        if (fd.unidad_obra_id) {
            formData.append('unidad_obra_id', fd.unidad_obra_id);
        }
        if (fd.tipo_trabajo_id) {
            formData.append('tipo_trabajo_id', fd.tipo_trabajo_id);
        }
        if (fd.datos_tecnicos) {
            formData.append('datos_tecnicos',
                typeof fd.datos_tecnicos === 'string'
                    ? fd.datos_tecnicos
                    : JSON.stringify(fd.datos_tecnicos)
            );
        }
        // Campos dinámicos
        if (fd.campos && typeof fd.campos === 'object') {
            for (const [campoId, valor] of Object.entries(fd.campos)) {
                formData.append(`campos[${campoId}]`, valor);
            }
        }

        const response = await fetch(fd.uploadUrl || 'subir.php', {
            method: 'POST',
            body: formData,
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const data = await response.json();
        if (!data.ok) {
            throw new Error(data.error || 'Server error');
        }

        return data;
    }

    // ===================================================================
    // PHOTO PRECACHE — Download comparative photos for offline ghosting
    // ===================================================================

    /**
     * Precache all comparative photos for a given infrastructure.
     * Downloads images from Cloudinary and stores them in IndexedDB.
     *
     * @param {number} infraId
     * @param {string} apiUrl - URL to the fotos_comparativas API endpoint
     * @returns {Promise<{ cached: number, total: number }>}
     */
    async function precachePhotos(infraId, apiUrl) {
        if (!navigator.onLine) {
            throw new Error('Se necesita conexión para precargar fotos');
        }

        // Fetch list of comparative photos from API
        const res = await fetch(`${apiUrl}?infra_id=${infraId}`);
        const data = await res.json();

        if (!data.ok || !data.fotos || data.fotos.length === 0) {
            return { cached: 0, total: 0 };
        }

        const fotos = data.fotos;
        let loaded = 0;

        for (const foto of fotos) {
            try {
                // Download the image
                const imgRes = await fetch(foto.url_cloudinary);
                const imgBlob = await imgRes.blob();
                const imgBuffer = await imgBlob.arrayBuffer();

                // Store in IndexedDB
                const cacheItem = {
                    key: `infra_${infraId}_foto_${foto.id}`,
                    infra_id: infraId,
                    foto_id: foto.id,
                    url_cloudinary: foto.url_cloudinary,
                    secuencia_comparativa: foto.secuencia_comparativa,
                    nombre_archivo: foto.nombre_archivo,
                    fecha: foto.fecha,
                    imageData: imgBuffer,
                    imageMime: imgBlob.type || 'image/jpeg',
                    cached_at: Date.now(),
                };

                const store = dbTransaction(STORE_PRECACHE, 'readwrite');
                await dbRequest(store.put(cacheItem));

                loaded++;
                if (callbacks.onPrecacheProgress) {
                    callbacks.onPrecacheProgress({ loaded, total: fotos.length });
                }
            } catch (err) {
                console.warn(`Error caching photo ${foto.id}:`, err);
            }
        }

        return { cached: loaded, total: fotos.length };
    }

    /**
     * Get cached comparative photos for an infrastructure.
     * Returns objects with a local blob URL for the image.
     *
     * @param {number} infraId
     * @returns {Promise<Array>} Array of cached photo objects with blobUrl property
     */
    async function getCachedPhotos(infraId) {
        const store = dbTransaction(STORE_PRECACHE, 'readonly');
        const index = store.index('infra_id');
        const items = await dbRequest(index.getAll(infraId));

        // Convert ArrayBuffers to blob URLs
        return items.map(item => {
            const blob = new Blob([item.imageData], { type: item.imageMime });
            return {
                id: item.foto_id,
                url_cloudinary: item.url_cloudinary,
                blobUrl: URL.createObjectURL(blob),
                secuencia_comparativa: item.secuencia_comparativa,
                nombre_archivo: item.nombre_archivo,
                fecha: item.fecha,
                cached_at: item.cached_at,
            };
        });
    }

    /**
     * Check if photos are cached for an infrastructure
     */
    async function hasCachedPhotos(infraId) {
        const store = dbTransaction(STORE_PRECACHE, 'readonly');
        const index = store.index('infra_id');
        const count = await dbRequest(index.count(infraId));
        return count > 0;
    }

    /**
     * Get list of all infrastructures that have cached photos
     */
    async function getCachedInfraIds() {
        const store = dbTransaction(STORE_PRECACHE, 'readonly');
        const items = await dbRequest(store.getAll());
        const ids = new Set();
        items.forEach(item => ids.add(item.infra_id));
        return Array.from(ids);
    }

    /**
     * Clear cached photos for an infrastructure
     */
    async function clearCachedPhotos(infraId) {
        const store = dbTransaction(STORE_PRECACHE, 'readwrite');
        const index = store.index('infra_id');
        const keys = await dbRequest(index.getAllKeys(infraId));
        for (const key of keys) {
            await dbRequest(store.delete(key));
        }
    }

    /**
     * Clear ALL cached data (queue + precache)
     */
    async function clearAll() {
        const qStore = dbTransaction(STORE_QUEUE, 'readwrite');
        await dbRequest(qStore.clear());
        const cStore = dbTransaction(STORE_PRECACHE, 'readwrite');
        await dbRequest(cStore.clear());
    }

    /**
     * Get a thumbnail blob URL for a queued item (for gallery display)
     */
    function getQueuedItemBlobUrl(item) {
        const blob = new Blob([item.imageData], { type: item.imageMime });
        return URL.createObjectURL(blob);
    }

    // ===================================================================
    // PUBLIC API
    // ===================================================================
    /**
     * Revoke an array of blob URLs to free memory.
     * Call this when cached photos are no longer needed (e.g., modal closed).
     */
    function revokeBlobUrls(items) {
        if (!Array.isArray(items)) return;
        items.forEach(item => {
            // El blob URL puede venir en blobUrl, url_cloudinary, o ser el propio string
            const url = (item && (item.blobUrl || item.url_cloudinary)) || item;
            if (typeof url === 'string' && url.startsWith('blob:')) {
                URL.revokeObjectURL(url);
            }
        });
    }

    window.InfocampoOffline = {
        init,
        isOnline,

        // Queue
        enqueue,
        getQueueCount,
        getQueueItems,
        syncQueue,
        getQueuedItemBlobUrl,

        // Precache
        precachePhotos,
        getCachedPhotos,
        hasCachedPhotos,
        getCachedInfraIds,
        clearCachedPhotos,
        clearAll,
        revokeBlobUrls,
    };
})();
