/*
 * Service worker-ul ecranelor (scope /display/). Păstrează local doar imaginile aprobate (materiale promoționale,
 * coperți de jocuri, avatare, logo), ca playerul să poată rula conținut promoțional și fără internet.
 * Limite: maximum 220 de fișiere; la fiecare stare nouă se șterg imaginile care nu mai sunt folosite.
 * Răspunsurile API (date despre jucători) și videoclipurile nu se pun niciodată în acest cache.
 */
const CACHE = 'aw-display-media-v1';
const MAX_ENTRIES = 220;
const KEEP_ALWAYS = /\/(build|media\/avatars|media\/brand)\//;

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    for (const key of await caches.keys()) if (key !== CACHE && key.startsWith('aw-display-')) await caches.delete(key);
    await self.clients.claim();
  })());
});

function cacheable(request) {
  if (request.method !== 'GET') return false;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin || url.pathname.startsWith('/api/')) return false;
  if (request.destination === 'video' || request.headers.has('range')) return false;
  return /^\/(media|storage|build)\//.test(url.pathname) && /\.(avif|webp|jpe?g|png|svg|woff2|css|js)$/i.test(url.pathname);
}

async function trim(cache) {
  const keys = await cache.keys();
  for (let i = 0; i < keys.length - MAX_ENTRIES; i++) await cache.delete(keys[i]);
}

self.addEventListener('fetch', (event) => {
  if (!cacheable(event.request)) return;
  event.respondWith((async () => {
    const cache = await caches.open(CACHE);
    const hit = await cache.match(event.request);
    if (hit) return hit;
    const response = await fetch(event.request);
    if (response.ok && response.type === 'basic') {
      await cache.put(event.request, response.clone());
      trim(cache);
    }
    return response;
  })().catch(() => caches.match(event.request).then((r) => r || Response.error())));
});

self.addEventListener('message', (event) => {
  if (event.data?.type !== 'keep' || !Array.isArray(event.data.urls)) return;
  const keep = new Set(event.data.urls);
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE);
    for (const request of await cache.keys()) {
      const path = new URL(request.url).pathname;
      if (!keep.has(request.url) && !KEEP_ALWAYS.test(path)) await cache.delete(request);
    }
  })());
});
