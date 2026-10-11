const GASGO_CACHE = 'gasgo-pwa-v3-floating-install';
const GASGO_OFFLINE_URL = '/index.php';

const APP_SHELL = [
    '/',
    '/index.php',
    '/public/manifest.webmanifest',
    '/public/icons/icon-192.png',
    '/public/icons/icon-512.png',
    '/public/icons/apple-touch-icon.png'
];

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(GASGO_CACHE).then(async function (cache) {
            await Promise.allSettled(
                APP_SHELL.map(function (url) {
                    return cache.add(url);
                })
            );
        })
    );

    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys
                    .filter(function (key) {
                        return (
                            key.startsWith('gasgo-pwa-') &&
                            key !== GASGO_CACHE
                        );
                    })
                    .map(function (key) {
                        return caches.delete(key);
                    })
            );
        })
    );

    self.clients.claim();
});

self.addEventListener('fetch', function (event) {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    // Never cache authenticated manager/admin pages or configuration paths.
    if (
        url.pathname.startsWith('/public/manager/') ||
        url.pathname.startsWith('/public/admin/') ||
        url.pathname.startsWith('/config/')
    ) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then(function (response) {
                    if (response && response.ok) {
                        const copy = response.clone();
                        caches.open(GASGO_CACHE).then(function (cache) {
                            cache.put(request, copy);
                        });
                    }
                    return response;
                })
                .catch(async function () {
                    return (
                        await caches.match(request)
                    ) || (
                        await caches.match(GASGO_OFFLINE_URL)
                    ) || (
                        await caches.match('/')
                    );
                })
        );
        return;
    }

    event.respondWith(
        caches.match(request).then(function (cached) {
            if (cached) {
                return cached;
            }

            return fetch(request).then(function (response) {
                if (!response || !response.ok) {
                    return response;
                }

                const copy = response.clone();
                caches.open(GASGO_CACHE).then(function (cache) {
                    cache.put(request, copy);
                });

                return response;
            });
        })
    );
});
