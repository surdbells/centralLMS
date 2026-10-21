/* Central LMS PWA service worker — app-shell offline + installability.
 *
 * Deliberately minimal and API-safe:
 *  - Only same-origin GETs are handled. The API lives on another origin, so its
 *    auth/data responses are never seen here and never cached.
 *  - Navigations are network-first, falling back to the cached app shell when
 *    offline, so the single-page app still opens and routes.
 *  - Other same-origin static assets (JS/CSS/fonts/images) are cache-first,
 *    filled on first fetch — no hashed-filename precache list to maintain.
 *  - Bumping CACHE drops the previous cache on activate, so a new deploy wins.
 *
 * This file is served from the site root (copied there by angular.json), so its
 * scope is the whole origin. It is registered only in the browser PWA build,
 * never inside the Capacitor native shell (see src/main.ts).
 */
const CACHE = 'central-pwa-v1';
const SHELL = './'; // index.html — the manifest's start_url/scope is '.'

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches
      .open(CACHE)
      .then((cache) => cache.add(new Request(SHELL, { cache: 'reload' })))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);

  // Only our own origin, only GET. The API (another origin), POSTs and the like
  // go straight to the network, untouched — nothing of theirs is ever cached.
  if (req.method !== 'GET' || url.origin !== self.location.origin) {
    return;
  }

  // App navigations: try the network so a fresh deploy is picked up; fall back
  // to the cached shell when offline so the app still opens and routes.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match(SHELL, { ignoreSearch: true })),
    );
    return;
  }

  // Static assets: serve from cache if present, otherwise fetch and cache.
  event.respondWith(
    caches.match(req).then(
      (hit) =>
        hit ||
        fetch(req)
          .then((res) => {
            if (res && res.status === 200 && res.type === 'basic') {
              const copy = res.clone();
              caches.open(CACHE).then((cache) => cache.put(req, copy));
            }
            return res;
          })
          .catch(() => hit),
    ),
  );
});
