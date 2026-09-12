// Caches only content-hashed build assets and the static offline page, never a server-generated
// response, so one account's data is never served from cache on a shared device.

const VERSION = 'v2';
const CACHE = `shell-${VERSION}`;

// Not content-hashed, so bump VERSION whenever offline.html changes.
const OFFLINE = '/offline.html';

const MANIFEST = '/build/manifest.json';

let eviction = null;

function isCacheable(pathname) {
    return /^\/build\/assets\/[^/]+-[A-Za-z0-9_-]{8,}\.[A-Za-z0-9]+$/.test(pathname);
}

// A deploy changes asset addresses but not this file, so stale bundles are found through the build manifest.
function evictUnreferencedAssets() {
    eviction ??= fetch(MANIFEST, { cache: 'no-store' })
        .then((response) => (response.ok ? response.json() : null))
        .then((manifest) => {
            const referenced = new Set();

            Object.values(manifest ?? {}).forEach((entry) => {
                [entry.file, ...(entry.css ?? []), ...(entry.assets ?? [])].forEach((file) => referenced.add(`/build/${file}`));
            });

            if (referenced.size === 0) {
                return;
            }

            return caches.open(CACHE).then((cache) =>
                cache.keys().then((requests) =>
                    Promise.all(
                        requests
                            .filter((request) => {
                                const pathname = new URL(request.url).pathname;

                                return isCacheable(pathname) && !referenced.has(pathname);
                            })
                            .map((request) => cache.delete(request)),
                    ),
                ),
            );
        })
        .catch(() => undefined)
        .finally(() => {
            eviction = null;
        });

    return eviction;
}

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.add(OFFLINE)));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((names) => Promise.all(names.filter((name) => name !== CACHE).map((name) => caches.delete(name))))
            .then(() => Promise.all([evictUnreferencedAssets(), self.clients.claim()])),
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

                    event.waitUntil(
                        caches
                            .open(CACHE)
                            .then((cache) => cache.put(request, copy))
                            .then(() => evictUnreferencedAssets()),
                    );
                }

                return response;
            });
        }),
    );
});
