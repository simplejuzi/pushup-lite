/* PUSHUP · Service Worker：离线可用 */
const CACHE = "pushup-transition-v11";
const ASSETS = [
  "./", "./index.html", "./manifest.webmanifest",
  "./icons/icon-192.png", "./icons/icon-512.png",
  "./icons/icon-maskable-512.png", "./icons/apple-touch-icon.png",
  "./icons/favicon.png", "./icons/logo.svg"
];

self.addEventListener("install", e => {
  e.waitUntil(
    caches.open(CACHE)
      .then(c => c.addAll(ASSETS))
      .catch(() => {})
      .then(() => self.skipWaiting())
  );
});

self.addEventListener("activate", e => {
  e.waitUntil(
    caches.keys()
      .then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", e => {
  const r = e.request;
  if (r.method !== "GET") return;
  let url;
  try { url = new URL(r.url); } catch (err) { return; }
  if (url.origin !== self.location.origin) return;

  // 页面导航：网络优先，离线回退到缓存首页（兼容带 sharecode 的链接）
  if (r.mode === "navigate") {
    const fallback = () => caches.match("./index.html").then(h => h || Response.error());
    e.respondWith(
      fetch(r).then(res => {
        if (res && res.status === 200) {
          const cp = res.clone();
          caches.open(CACHE).then(c => c.put("./index.html", cp));
          return res;
        }
        return fallback();          // 非 200（如访问控制页）也用已缓存的正式页面
      }).catch(fallback)
    );
    return;
  }

  // 静态资源：缓存优先 + 后台更新
  e.respondWith(
    caches.match(r).then(hit => {
      const net = fetch(r).then(res => {
        if (res && res.status === 200 && res.type === "basic") {
          const cp = res.clone();
          caches.open(CACHE).then(c => c.put(r, cp));
        }
        return res;
      }).catch(() => hit);
      return hit || net;
    })
  );
});
