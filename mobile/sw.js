/* Retirement worker for existing CrystalTap PWA installations. No push or offline fetch handlers. */
self.addEventListener('install',event=>event.waitUntil(self.skipWaiting()));
self.addEventListener('activate',event=>event.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(key=>key.startsWith('ctai-shell-')).map(key=>caches.delete(key)))).then(()=>self.registration.unregister())));
