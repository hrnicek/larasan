/*
 * App shell only (TASK-190-008, extended by TASK-190-009).
 *
 * This worker stores build assets and fonts — files whose names carry a content hash — and one
 * constant document, `/offline.html`, which ships with the repository and carries nothing about
 * anybody. It never stores a response the server generated for a person: not an Inertia page, not
 * a JSON payload, not a navigation.
 *
 * That distinction is the whole security content of the file. A cached page is one account's data
 * served to whoever opens the browser next on a shared device; a cached constant is a constant.
 *
 * TASK-190-008 first wrote the rule as "never an HTML document", which was the right reason and a
 * slightly wrong boundary — it also forbade the offline fallback TASK-190-009 needs, whose whole
 * point is that there is nothing in it to leak. The rule above is the sharpened version.
 */

const VERSION = 'v2';
const CACHE = `shell-${VERSION}`;

/*
 * Shown when a navigation cannot reach the network, instead of the browser's own error page.
 *
 * Precached, because the moment it is needed is the moment it cannot be fetched. It is the one
 * address here without a content hash, which is why `VERSION` above must be bumped when it
 * changes — a hashed asset gets a new address and needs no such thing.
 */
const OFFLINE = '/offline.html';

/*
 * A path is cacheable when Vite gave it a content hash. That is what makes cache-first safe:
 * a file at this address can never change, so a stale entry is impossible and the next
 * deployment asks for different addresses rather than being served the last one's bytes.
 */
function isCacheable(pathname) {
    return /^\/build\/assets\/[^/]+-[A-Za-z0-9_-]{8,}\.[A-Za-z0-9]+$/.test(pathname);
}

self.addEventListener('install', (event) => {
    // Only the fallback is precached. A list of shell addresses written here would be a second,
    // older answer to what `build/manifest.json` already knows, and they are cached on first use
    // anyway.
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

    /*
     * A navigation goes to the network every time and its response is never kept. What the worker
     * adds is the failure case: the fallback instead of the browser's error page.
     */
    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE).then((hit) => hit ?? Response.error())));

        return;
    }

    /*
     * Not calling `respondWith` at all is deliberate: the request goes to the network exactly as
     * it would with no worker installed. An Inertia visit and a JSON call are handled by this
     * branch, which is to say they are not handled here.
     */
    if (!isCacheable(url.pathname)) {
        return;
    }

    event.respondWith(
        caches.match(request).then((hit) => {
            if (hit) {
                return hit;
            }

            return fetch(request).then((response) => {
                // An opaque or failed response is not worth keeping, and caching one would hide
                // the failure behind a hit on every later load.
                if (response.ok && response.type === 'basic') {
                    const copy = response.clone();

                    caches.open(CACHE).then((cache) => cache.put(request, copy));
                }

                return response;
            });
        }),
    );
});
