// Sprint's service worker, served by ProgressiveWebAppController with VERSION, PRECACHE and OFFLINE_URL in front.
// Built assets (their names change with every build) come from the cache first; pages always go to the server
// and only fall back to the offline page when there is no connection. Livewire requests and everything else are left alone.

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()))
})

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== VERSION).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    )
})

self.addEventListener('fetch', (event) => {
    const request = event.request
    const url = new URL(request.url)

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return
    }

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)))

        return
    }

    if (url.pathname.startsWith('/build/') || PRECACHE.includes(url.pathname)) {
        event.respondWith(
            caches.match(request).then((cached) => cached ?? fetch(request).then((response) => {
                if (response.ok) {
                    const copy = response.clone()
                    caches.open(VERSION).then((cache) => cache.put(request, copy))
                }

                return response
            })),
        )
    }
})
