const CACHE = "sola-bestellungen-v15";
const SHELL = ["/pwa/", "/pwa/index.html", "/pwa/styles.css", "/pwa/app.js", "/pwa/manifest.json"];

self.addEventListener("install", (event) => {
  event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)));
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
  );
  self.clients.claim();
});

self.addEventListener("fetch", (event) => {
  const url = new URL(event.request.url);

  // Never cache API calls - always go to network.
  if (url.pathname.startsWith("/api/")) {
    return;
  }

  // Eine umgeleitete Antwort darf einen Seitenaufruf nicht beantworten: der
  // Browser bricht sonst mit ERR_FAILED ab.
  event.respondWith(
    caches.match(event.request).then((cached) => {
      if (cached && !cached.redirected) {
        return cached;
      }
      return (
        fetch(event.request).then((response) => {
          if (response.ok && !response.redirected && event.request.method === "GET") {
            const copy = response.clone();
            caches.open(CACHE).then((cache) => cache.put(event.request, copy));
          }
          return response;
        })
      );
    })
  );
});
