/* نجوم الفاء — local site editor server (no dependencies)
   Run:  node admin/server.js   →  http://localhost:4000/admin          */

const http = require("http");
const fs = require("fs");
const path = require("path");
const { exec } = require("child_process");

const ROOT = path.resolve(__dirname, "..");
const BACKUPS = path.join(__dirname, "backups");
const IMG_DIR = path.join(ROOT, "assets", "img");
const PORT = Number(process.env.PORT) || 4000;
const EDITABLE = ["index.html", "css/styles.css", "js/main.js"];

const TYPES = {
  ".html": "text/html; charset=utf-8", ".css": "text/css; charset=utf-8", ".js": "text/javascript; charset=utf-8",
  ".json": "application/json", ".svg": "image/svg+xml", ".png": "image/png", ".jpg": "image/jpeg",
  ".jpeg": "image/jpeg", ".webp": "image/webp", ".gif": "image/gif", ".ico": "image/x-icon", ".avif": "image/avif"
};
const IMG_EXT = [".jpg", ".jpeg", ".png", ".webp", ".gif", ".svg", ".avif"];

function send(res, code, body, type) {
  res.writeHead(code, { "Content-Type": type || "text/plain; charset=utf-8", "Cache-Control": "no-store" });
  res.end(body);
}
function json(res, code, obj) { send(res, code, JSON.stringify(obj), "application/json; charset=utf-8"); }

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

const server = http.createServer(async function (req, res) {
  try {
    var url = new URL(req.url, "http://localhost");
    var p = decodeURIComponent(url.pathname);

    if (p === "/admin" || p === "/admin/") {
      return send(res, 200, fs.readFileSync(path.join(__dirname, "editor.html")), TYPES[".html"]);
    }
    if (p === "/__preview") return send(res, 200, previewHtml(), TYPES[".html"]);

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
        .filter(function (f) { return IMG_EXT.indexOf(path.extname(f).toLowerCase()) !== -1; })
        .map(function (f) { return "assets/img/" + f; });
      return json(res, 200, list);
    }

    if (p === "/api/upload" && req.method === "POST") {
      var s = safeName(url.searchParams.get("name") || "image.jpg");
      if (IMG_EXT.indexOf(s.ext) === -1) return json(res, 400, { error: "Only image files are allowed" });
      var data = await readBody(req, 15 * 1024 * 1024);
      fs.writeFileSync(path.join(IMG_DIR, s.name), data);
      return json(res, 200, { path: "assets/img/" + s.name });
    }

    // static files
    if (req.method !== "GET") return send(res, 405, "Method not allowed");
    var target = path.normalize(path.join(ROOT, p === "/" ? "index.html" : p));
    if (target.indexOf(ROOT) !== 0 || target.indexOf(BACKUPS) === 0) return send(res, 403, "Forbidden");
    if (!fs.existsSync(target) || fs.statSync(target).isDirectory()) return send(res, 404, "Not found");
    send(res, 200, fs.readFileSync(target), TYPES[path.extname(target).toLowerCase()] || "application/octet-stream");
  } catch (err) {
    json(res, 500, { error: err.message });
  }
});

server.listen(PORT, "127.0.0.1", function () {
  var link = "http://localhost:" + PORT + "/admin";
  console.log("\n  Site editor running →  " + link + "\n  (Ctrl+C to stop)\n");
  var cmd = process.platform === "win32" ? 'start "" "' + link + '"' : process.platform === "darwin" ? "open " + link : "xdg-open " + link;
  if (!process.env.NO_OPEN) exec(cmd);
});
