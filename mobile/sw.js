/* This worker is served only at the dedicated app route. Never cache private API data. */
const CACHE = 'ctai-shell-' + CTAI_VERSION;
const SHELL = CTAI_ASSETS + 'offline.html';
self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE).then(cache => cache.addAll([SHELL, CTAI_ASSETS + 'app.css?ver=' + CTAI_VERSION, CTAI_ASSETS + 'icon-192.png'])));
});
self.addEventListener('activate', event => event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k.startsWith('ctai-shell-') && k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim())));
self.addEventListener('message', event => { if (event.data === 'ACTIVATE_UPDATE') self.skipWaiting(); });
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);
  if (event.request.method !== 'GET' || url.origin !== self.location.origin) return;
  if (event.request.mode === 'navigate') { event.respondWith(fetch(event.request).catch(() => caches.match(SHELL))); return; }
  // Only explicitly cached public assets can be served offline.
  if ([SHELL, CTAI_ASSETS + 'app.css?ver=' + CTAI_VERSION, CTAI_ASSETS + 'icon-192.png'].includes(url.href)) event.respondWith(caches.match(event.request).then(cached => cached || fetch(event.request)));
});
self.addEventListener('push', event => {
  let data; try { data = event.data.json(); } catch (_) { return; }
  event.waitUntil(self.registration.showNotification('CrystalTap Agent', { body: data.test === true ? 'Test notification from CrystalTap. Push reached this device.' : 'A support conversation needs your attention.', silent: false, tag: String(data.tag || 'ctai-support'), icon: CTAI_ASSETS + 'icon-192.png', data: { conversation: Math.max(0, Number(data.conversation) || 0) } }));
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const url = new URL('./', self.registration.scope);
  if (event.notification.data.conversation) url.hash = 'conversation/' + event.notification.data.conversation;
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(async clients => {
    const client = clients.find(c => c.url.startsWith(self.registration.scope));
    if (client) { await client.navigate(url.href); return client.focus(); }
    return self.clients.openWindow(url.href);
  }));
});
