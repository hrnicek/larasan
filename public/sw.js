/*
 * App shell only (TASK-190-008).
 *
 * This worker stores build assets and fonts — files whose names carry a content hash — and
 * nothing else. It never stores an Inertia page response, a JSON payload or an HTML document,
 * and that is the whole security content of the file: a cached page is one account's data
 * served to whoever opens the browser next on a shared device.
 *
 * The rule is expressed as an allow-list rather than a block-list, because a block-list is a
 * list of the responses somebody thought of.
 */

const VERSION = 'v1';
const CACHE = `shell-${VERSION}`;

/*
 * A path is cacheable when Vite gave it a content hash. That is what makes cache-first safe:
 * a file at this address can never change, so a stale entry is impossible and the next
 * deployment asks for different addresses rather than being served the last one's bytes.
 *
 * Same origin only. A third-party response is somebody else's to cache.
 */
function isCacheable(pathname) {
    return /^\/build\/assets\/[^/]+-[A-Za-z0-9_-]{8,}\.[A-Za-z0-9]+$/.test(pathname);
}

self.addEventListener('install', (event) => {
    // Nothing is pre-cached: the shell's addresses change with every build, and a list of them
    // written here would be a second, older answer to what `manifest.json` already knows.
    event.waitUntil(caches.open(CACHE));
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

    /*
     * Not calling `respondWith` at all is deliberate: the request goes to the network exactly as
     * it would with no worker installed. A navigation, an Inertia visit and an API call are all
     * handled by this branch, which is to say they are not handled here.
     */
    if (url.origin !== self.location.origin || !isCacheable(url.pathname)) {
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
