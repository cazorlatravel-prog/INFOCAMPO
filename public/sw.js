/**
 * INFOCAMPO - Service Worker
 *
 * Provides:
 *   - Offline caching of static assets (CSS, JS, icons)
 *   - Network-first strategy for API calls
 *   - Fallback page when completely offline
 */

const CACHE_NAME = 'infocampo-v1';
const STATIC_ASSETS = [
    'css/operador.css',
    'js/operador.js',
    'js/offline.js',
];

// Install: pre-cache static assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS);
        }).then(() => self.skipWaiting())
    );
});

// Activate: clean old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch: network-first for API, cache-first for static assets
self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);

    // Skip non-GET requests (uploads go straight to network)
    if (event.request.method !== 'GET') return;

    // API calls: network-first
    if (url.pathname.includes('/api/') || url.pathname.includes('subir.php')) {
        event.respondWith(
            fetch(event.request).catch(() => {
                return new Response(
                    JSON.stringify({ ok: false, error: 'Sin conexión', offline: true }),
                    { headers: { 'Content-Type': 'application/json' } }
                );
            })
        );
        return;
    }

    // Static assets: cache-first, then network
    if (
        url.pathname.endsWith('.css') ||
        url.pathname.endsWith('.js') ||
        url.pathname.endsWith('.woff2') ||
        url.pathname.endsWith('.png') ||
        url.pathname.endsWith('.ico')
    ) {
        event.respondWith(
            caches.match(event.request).then((cached) => {
                if (cached) return cached;
                return fetch(event.request).then((response) => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
                    }
                    return response;
                }).catch(() => new Response('', { status: 503 }));
            })
        );
        return;
    }

    // Everything else: network-first with cache fallback
    event.respondWith(
        fetch(event.request).then((response) => {
            if (response.ok && event.request.url.startsWith(self.location.origin)) {
                const clone = response.clone();
                caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
            }
            return response;
        }).catch(() => {
            return caches.match(event.request).then((cached) => {
                return cached || new Response('Offline', { status: 503 });
            });
        })
    );
});
