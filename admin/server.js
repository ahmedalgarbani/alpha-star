/* سرعة الإنجاز الدولية — website server + password-protected editor (no dependencies)

   On your PC:   node admin/server.js                     → http://localhost:4000/admin (no password, only this PC)
   On a server:  ADMIN_PASSWORD=your-secret node admin/server.js
                 → the public site is at  /           (everyone)
                 → the editor is at       /admin      (password required)                                      */

const http = require("http");
const fs = require("fs");
const path = require("path");
const crypto = require("crypto");
const { exec } = require("child_process");

const ROOT = path.resolve(__dirname, "..");
const ADMIN_DIR = __dirname;
const BACKUPS = path.join(__dirname, "backups");
const IMG_DIR = path.join(ROOT, "assets", "img");
const PORT = Number(process.env.PORT) || 4000;
const PASSWORD = process.env.ADMIN_PASSWORD || "";
const LOCAL = !PASSWORD;                       // no password → editor only reachable from this computer
const HOST = process.env.HOST || (LOCAL ? "127.0.0.1" : "0.0.0.0");
const SESSION_HOURS = 12;
const EDITABLE = ["index.html", "css/styles.css", "js/main.js", "sitemap.xml"];

const TYPES = {
  ".html": "text/html; charset=utf-8", ".css": "text/css; charset=utf-8", ".js": "text/javascript; charset=utf-8",
  ".json": "application/json", ".xml": "application/xml", ".txt": "text/plain; charset=utf-8", ".svg": "image/svg+xml",
  ".png": "image/png", ".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".webp": "image/webp", ".gif": "image/gif",
  ".ico": "image/x-icon", ".avif": "image/avif", ".woff2": "font/woff2"
};
const IMG_EXT = [".jpg", ".jpeg", ".png", ".webp", ".gif", ".svg", ".avif"];
const UPLOAD_EXT = [".jpg", ".jpeg", ".png", ".webp", ".gif", ".avif"];   // no SVG: it can carry scripts

if (!LOCAL && PASSWORD.length < 10) {
  console.error("ADMIN_PASSWORD must be at least 10 characters.");
  process.exit(1);
}

/* ---------------- helpers ---------------- */
function send(res, code, body, type, headers) {
  res.writeHead(code, Object.assign({ "Content-Type": type || "text/plain; charset=utf-8", "Cache-Control": "no-store" }, headers));
  res.end(body);
}
function json(res, code, obj) { send(res, code, JSON.stringify(obj), "application/json; charset=utf-8"); }
function redirect(res, to, headers) { res.writeHead(302, Object.assign({ Location: to }, headers)); res.end(); }

function readBody(req, limit) {
  return new Promise(function (resolve, reject) {
    var chunks = [], size = 0;
    req.on("data", function (c) {
      size += c.length;
      if (size > limit) { reject(new Error("Body too large")); req.destroy(); return; }
      chunks.push(c);
    });
    req.on("end", function () { resolve(Buffer.concat(chunks)); });
    req.on("error", reject);
  });
}

function backup(rel) {
  var src = path.join(ROOT, rel);
  if (!fs.existsSync(src)) return;
  fs.mkdirSync(BACKUPS, { recursive: true });
  var stamp = new Date().toISOString().replace(/[:.]/g, "-");
  fs.copyFileSync(src, path.join(BACKUPS, stamp + "__" + rel.replace(/[\\/]/g, "_")));
}

// Preview copy of the page: scripts switched off so the DOM stays identical to the source file.
function previewHtml() {
  var html = fs.readFileSync(path.join(ROOT, "index.html"), "utf8");
  return html.replace(/<script\b([^>]*)>/gi, function (m, attrs) {
    var t = attrs.match(/\stype\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/i);
    if (t) attrs = attrs.replace(t[0], " data-ed-type=" + t[1]);
    return '<script type="text/x-ed-off" data-ed-script' + attrs + ">";
  });
}

function safeName(name) {
  var ext = path.extname(name).toLowerCase();
  var base = path.basename(name, path.extname(name)).replace(/[^a-zA-Z0-9_-]+/g, "-").replace(/^-+|-+$/g, "") || "image";
  var out = base + ext, i = 1;
  while (fs.existsSync(path.join(IMG_DIR, out))) out = base + "-" + (i++) + ext;
  return { name: out, ext: ext };
}

/* ---------------- login / sessions ---------------- */
var sessions = new Map();          // token → expiry time
var failures = new Map();          // ip → { count, until }

function clientIp(req) {
  // last entry = address seen by our own proxy (earlier entries can be faked by the client)
  return (String(req.headers["x-forwarded-for"] || "").split(",").pop().trim()) || req.socket.remoteAddress;
}
function isHttps(req) { return req.headers["x-forwarded-proto"] === "https" || !!req.socket.encrypted; }
function cookies(req) {
  var out = {};
  String(req.headers.cookie || "").split(";").forEach(function (c) {
    var i = c.indexOf("="); if (i > 0) out[c.slice(0, i).trim()] = decodeURIComponent(c.slice(i + 1).trim());
  });
  return out;
}
function sameText(a, b) {
  var x = crypto.createHash("sha256").update(String(a)).digest();
  var y = crypto.createHash("sha256").update(String(b)).digest();
  return crypto.timingSafeEqual(x, y);
}
function loggedIn(req) {
  if (LOCAL) return true;
  var t = cookies(req).ed_session, exp = t && sessions.get(t);
  if (!exp) return false;
  if (exp < Date.now()) { sessions.delete(t); return false; }
  return true;
}
function sessionCookie(req, token, maxAge) {
  return "ed_session=" + token + "; Path=/; HttpOnly; SameSite=Strict; Max-Age=" + maxAge + (isHttps(req) ? "; Secure" : "");
}
// Reject cross-site form posts (CSRF): the request must come from this same site.
function sameOrigin(req) {
  var o = req.headers.origin || req.headers.referer;
  if (!o) return true;
  try { return new URL(o).host === req.headers.host; } catch (e) { return false; }
}

function loginPage(msg) {
  return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
    '<meta name="robots" content="noindex,nofollow"><title>دخول المحرر</title><style>' +
    "body{margin:0;min-height:100vh;display:grid;place-items:center;background:#0e1116;color:#e8ecf1;font:15px system-ui,'Segoe UI',sans-serif}" +
    "form{background:#151a21;border:1px solid #2a313c;border-radius:14px;padding:28px;width:min(340px,100% - 32px);box-sizing:border-box}" +
    "h1{font-size:18px;margin:0 0 18px}input{width:100%;box-sizing:border-box;padding:11px;border-radius:8px;border:1px solid #2a313c;background:#0f1318;color:inherit;font:inherit;margin-bottom:12px}" +
    "button{width:100%;padding:11px;border:0;border-radius:8px;background:#F6B100;color:#1a1400;font:inherit;font-weight:700;cursor:pointer}" +
    "p{color:#fca5a5;margin:0 0 12px}</style></head><body><form method=\"post\" action=\"/admin/login\">" +
    "<h1>🔒 محرر الموقع</h1>" + (msg ? "<p>" + msg + "</p>" : "") +
    '<input type="password" name="password" placeholder="كلمة المرور" autofocus required autocomplete="current-password">' +
    "<button>دخول</button></form></body></html>";
}

/* ---------------- routes ---------------- */
const server = http.createServer(async function (req, res) {
  try {
    var url = new URL(req.url, "http://localhost");
    var p = decodeURIComponent(url.pathname);
    var isAdminRoute = p === "/admin" || p.indexOf("/admin/") === 0 || p === "/__preview" || p.indexOf("/api/") === 0;

    if (isAdminRoute) {
      if (p === "/admin/login" && req.method === "POST") {
        if (LOCAL) return redirect(res, "/admin");
        var ip = clientIp(req), f = failures.get(ip);
        if (f && f.until > Date.now()) return send(res, 429, loginPage("محاولات كثيرة. حاول بعد ١٥ دقيقة."), TYPES[".html"]);
        if (!sameOrigin(req)) return send(res, 403, "Forbidden");
        var pw = new URLSearchParams((await readBody(req, 4096)).toString("utf8")).get("password") || "";
        if (!sameText(pw, PASSWORD)) {
          if (!f || Date.now() - f.first > 15 * 60e3) f = { count: 0, until: 0, first: Date.now() };
          f.count++;
          if (f.count >= 5) { f.until = Date.now() + 15 * 60e3; f.count = 0; f.first = Date.now(); }
          failures.set(ip, f);
          return send(res, 401, loginPage("كلمة المرور غير صحيحة."), TYPES[".html"]);
        }
        failures.delete(ip);
        var token = crypto.randomBytes(32).toString("hex");
        sessions.set(token, Date.now() + SESSION_HOURS * 3600e3);
        return redirect(res, "/admin", { "Set-Cookie": sessionCookie(req, token, SESSION_HOURS * 3600) });
      }
      if (p === "/admin/logout") {
        sessions.delete(cookies(req).ed_session);
        return redirect(res, "/admin", { "Set-Cookie": sessionCookie(req, "", 0) });
      }

      if (!loggedIn(req)) {
        if (p === "/admin" || p === "/admin/") return send(res, 200, loginPage(""), TYPES[".html"]);
        if (p.indexOf("/api/") === 0) return json(res, 401, { error: "Please log in again" });
        return redirect(res, "/admin");
      }
      if (req.method === "POST" && !sameOrigin(req)) return json(res, 403, { error: "Forbidden" });

      if (p === "/admin" || p === "/admin/") {
        var cfg = { root: "/", preview: "/__preview", file: "/api/file?path=", images: "/api/images",
                    upload: "/api/upload?name=", login: "/admin", logout: LOCAL ? null : "/admin/logout" };
        var page = fs.readFileSync(path.join(ADMIN_DIR, "editor.html"), "utf8")
          .replace("<!--ED_CONFIG-->", "<script>window.ED = " + JSON.stringify(cfg) + ";</script>");
        return send(res, 200, page, TYPES[".html"], { "X-Frame-Options": "DENY" });
      }
      if (p === "/__preview") return send(res, 200, previewHtml(), TYPES[".html"], { "X-Frame-Options": "SAMEORIGIN" });

      if (p === "/api/file") {
        var rel = url.searchParams.get("path");
        if (EDITABLE.indexOf(rel) === -1) return json(res, 400, { error: "File not editable" });
        var file = path.join(ROOT, rel);
        if (req.method === "GET") return send(res, 200, fs.readFileSync(file, "utf8"));
        if (req.method === "POST") {
          var body = (await readBody(req, 20 * 1024 * 1024)).toString("utf8");
          if (!body.trim()) return json(res, 400, { error: "Empty content" });
          backup(rel);
          fs.writeFileSync(file, body, "utf8");
          return json(res, 200, { ok: true });
        }
      }
      if (p === "/api/images" && req.method === "GET") {
        var list = fs.readdirSync(IMG_DIR)
          .filter(function (n) { return IMG_EXT.indexOf(path.extname(n).toLowerCase()) !== -1; })
          .map(function (n) { return "assets/img/" + n; });
        return json(res, 200, list);
      }
      if (p === "/api/upload" && req.method === "POST") {
        var s = safeName(url.searchParams.get("name") || "image.jpg");
        if (UPLOAD_EXT.indexOf(s.ext) === -1) return json(res, 400, { error: "Only JPG, PNG, WEBP, GIF or AVIF images" });
        var data = await readBody(req, 15 * 1024 * 1024);
        fs.writeFileSync(path.join(IMG_DIR, s.name), data);
        return json(res, 200, { path: "assets/img/" + s.name });
      }
      return send(res, 404, "Not found");
    }

    /* ---- public website ---- */
    if (req.method !== "GET" && req.method !== "HEAD") return send(res, 405, "Method not allowed");
    var target = path.normalize(path.join(ROOT, p === "/" ? "index.html" : p));
    var relPath = path.relative(ROOT, target);
    var hidden = relPath.split(path.sep).some(function (seg) { return seg.charAt(0) === "."; }) ||   // .git, .env …
                 relPath.indexOf("..") === 0 || target.indexOf(ADMIN_DIR) === 0 ||
                 /^(node_modules|site)([\\/]|$)/.test(relPath) || /\.(bat|cmd|sh|md|log|php)$|^package(-lock)?\.json$/i.test(relPath);
    if (hidden) return send(res, 404, "Not found");
    if (!fs.existsSync(target) || fs.statSync(target).isDirectory()) return send(res, 404, "Not found");
    var ext = path.extname(target).toLowerCase();
    send(res, 200, req.method === "HEAD" ? "" : fs.readFileSync(target), TYPES[ext] || "application/octet-stream",
      { "Cache-Control": ext === ".html" ? "no-cache" : "public, max-age=3600", "X-Content-Type-Options": "nosniff" });
  } catch (err) {
    json(res, 500, { error: err.message });
  }
});

server.listen(PORT, HOST, function () {
  if (LOCAL) {
    var link = "http://localhost:" + PORT + "/admin";
    console.log("\n  Site editor (this computer only) →  " + link + "\n  (Ctrl+C to stop)\n");
    var cmd = process.platform === "win32" ? 'start "" "' + link + '"' : process.platform === "darwin" ? "open " + link : "xdg-open " + link;
    if (!process.env.NO_OPEN) exec(cmd);
  } else {
    console.log("Website running on port " + PORT + " — editor at /admin (password protected)");
  }
});
