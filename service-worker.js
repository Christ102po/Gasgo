const CACHE = 'gasgo-v2';
const SHELL = [
  './',
  'index.php',
  'offline.html',
  'public/assets/css/style.css',
  'manifest.webmanifest'
].map(path => new URL(path, self.registration.scope).pathname);

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE)
      .then(cache => cache.addAll(SHELL))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(key => key !== CACHE).map(key => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET') return;
  event.respondWith(
    fetch(event.request).catch(() =>
      caches.match(event.request).then(response =>
        response || caches.match(new URL('offline.html', self.registration.scope).pathname)
      )
    )
  );
});
