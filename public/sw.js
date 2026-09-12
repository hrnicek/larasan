// Caches only content-hashed build assets and the static offline page, never a server-generated
// response, so one account's data is never served from cache on a shared device.

const VERSION = 'v2';
const CACHE = `shell-${VERSION}`;

// Not content-hashed, so bump VERSION whenever offline.html changes.
const OFFLINE = '/offline.html';

function isCacheable(pathname) {
    return /^\/build\/assets\/[^/]+-[A-Za-z0-9_-]{8,}\.[A-Za-z0-9]+$/.test(pathname);
}

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.add(OFFLINE)));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((names) => Promise.all(names.filter((name) => name !== CACHE).map((name) => caches.delete(name))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE).then((hit) => hit ?? Response.error())));

        return;
    }

    if (!isCacheable(url.pathname)) {
        return;
    }

    event.respondWith(
        caches.match(request).then((hit) => {
            if (hit) {
                return hit;
            }

            return fetch(request).then((response) => {
                if (response.ok && response.type === 'basic') {
                    const copy = response.clone();

                    caches.open(CACHE).then((cache) => cache.put(request, copy));
                }

                return response;
            });
        }),
    );
});
