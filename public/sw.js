const CACHE_NAME = 'news-ticker-v1';
const SHELL_ASSETS = [
  '/',
  '/manifest.json',
  '/img/icon-192.png',
  '/img/icon-512.png',
  '/alert.mp3',
];

// Install: cache app shell
self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_ASSETS))
  );
  self.skipWaiting();
});

// Activate: clean old caches
self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

// Fetch: network-first for API calls, cache-first for shell assets
self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);

  // API calls (feed, predictions): always network, no cache
  if (url.pathname === '/feed.php' || url.pathname === '/predictions.php') {
    e.respondWith(fetch(e.request));
    return;
  }

  // Stats page: always network
  if (url.pathname === '/stats.html') {
    e.respondWith(fetch(e.request));
    return;
  }

  // Everything else: cache-first, fallback to network
  e.respondWith(
    caches.match(e.request).then((cached) => {
      if (cached) return cached;
      return fetch(e.request).then((resp) => {
        // Cache same-origin successful responses
        if (resp.ok && url.origin === self.location.origin) {
          const clone = resp.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(e.request, clone));
        }
        return resp;
      });
    }).catch(() => {
      // Offline fallback for navigation
      if (e.request.mode === 'navigate') {
        return caches.match('/');
      }
    })
  );
});
