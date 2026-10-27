/* سرعة الإنجاز الدولية — content dashboard: article editor, image upload / library, live SEO preview & checklist. */
(function () {
  "use strict";
  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };
  var CSRF = ($('meta[name="csrf"]') || {}).content || "";
  var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); };

  /* ---------- confirm before delete ---------- */
  $$("form[data-confirm]").forEach(function (f) {
    f.addEventListener("submit", function (e) { if (!confirm(f.dataset.confirm)) e.preventDefault(); });
  });

  /* ---------- images: upload + library ---------- */
  function upload(file) {
    return fetch("content.php?s=upload&name=" + encodeURIComponent(file.name), {
      method: "POST", body: file, credentials: "same-origin", headers: { "X-CSRF": CSRF }
    }).then(function (r) {
      return r.json().catch(function () { return { error: "خطأ في الخادم (" + r.status + ")" }; });
    }).then(function (j) {
      if (!j.path) throw new Error(j.error || "تعذر رفع الصورة");
      return j.path;
    });
  }
  function chooseFiles(multiple) {
    return new Promise(function (resolve) {
      var inp = document.createElement("input");
      inp.type = "file"; inp.accept = "image/jpeg,image/png,image/webp,image/gif,image/avif"; inp.multiple = !!multiple;
      inp.onchange = function () { resolve(Array.prototype.slice.call(inp.files)); };
      inp.click();
    });
  }
  function uploadMany(btn, multiple) {
    return chooseFiles(multiple).then(function (files) {
      if (!files.length) return [];
      var label = btn.textContent;
      btn.classList.add("upbusy"); btn.textContent = "جاري الرفع…";
      return files.reduce(function (p, f) {
        return p.then(function (acc) { return upload(f).then(function (path) { acc.push(path); return acc; }); });
      }, Promise.resolve([])).catch(function (err) { alert(err.message); return []; })
        .finally(function () { btn.classList.remove("upbusy"); btn.textContent = label; });
    });
  }
  var libCache = null;
  function library(multiple) {
    return new Promise(function (resolve) {
      var d = document.createElement("dialog");
      d.className = "lib";
      d.innerHTML = '<div class="head"><h2>مكتبة الصور</h2><div><button type="button" class="btn" data-x>إلغاء</button>' +
        (multiple ? ' <button type="button" class="btn btn--primary" data-ok>إضافة المحدد</button>' : "") + "</div></div>" +
        '<p class="muted">' + (multiple ? "اختر صورة أو أكثر." : "اختر صورة.") + '</p><div class="lib__grid">جاري التحميل…</div>';
      document.body.appendChild(d);
      d.showModal();
      var picked = [];
      var done = function (val) { d.close(); d.remove(); resolve(val); };
      d.addEventListener("cancel", function () { done([]); });
      $("[data-x]", d).onclick = function () { done([]); };
      if (multiple) $("[data-ok]", d).onclick = function () { done(picked); };
      (libCache ? Promise.resolve(libCache) : fetch("content.php?s=library", { credentials: "same-origin" }).then(function (r) { return r.json(); }))
        .then(function (list) {
          libCache = list;
          var g = $(".lib__grid", d);
          g.innerHTML = list.length ? "" : "لا توجد صور بعد.";
          list.forEach(function (p) {
            var b = document.createElement("button");
            b.type = "button";
            b.innerHTML = '<img loading="lazy" alt="" src="/' + esc(p) + '">';
            b.onclick = function () {
              if (!multiple) return done([p]);
              var i = picked.indexOf(p);
              if (i === -1) picked.push(p); else picked.splice(i, 1);
              b.classList.toggle("on", i === -1);
            };
            g.appendChild(b);
          });
        });
    });
  }
  function pick(btn, multiple) {
    return btn.dataset.pick === "library" ? library(multiple) : uploadMany(btn, multiple).then(function (l) { libCache = null; return l; });
  }

  // single image (cover)
  $$(".imgpick").forEach(function (box) {
    var input = $('input[type="hidden"]', box), prev = $(".imgpick__preview", box);
    function show() {
      prev.style.backgroundImage = input.value ? "url('/" + input.value.replace(/'/g, "%27") + "')" : "";
      prev.classList.toggle("has", !!input.value);
      input.dispatchEvent(new Event("change", { bubbles: true }));
    }
    $$("[data-pick]", box).forEach(function (b) {
      b.onclick = function () { pick(b, false).then(function (l) { if (l[0]) { input.value = l[0]; show(); } }); };
    });
    prev.title = "انقر مرتين لإزالة الصورة";
    prev.ondblclick = function () { input.value = ""; show(); };
    show();
  });

  // ad gallery (several images)
  $$(".gallery").forEach(function (g) {
    var add = $(".gallery__add", g);
    function item(path) {
      var d = document.createElement("div");
      d.className = "gallery__item";
      d.innerHTML = '<img src="/' + esc(path) + '" alt=""><input type="hidden" name="images[]" value="' + esc(path) + '">' +
        '<button type="button" data-act="first" title="اجعلها الرئيسية">★</button><button type="button" data-act="remove" title="إزالة">×</button>';
      g.insertBefore(d, add);
    }
    g.addEventListener("click", function (e) {
      var b = e.target.closest("button[data-act]"); if (!b) return;
      var it = b.closest(".gallery__item");
      if (b.dataset.act === "remove") it.remove(); else g.insertBefore(it, g.firstChild);
      g.dispatchEvent(new Event("change", { bubbles: true }));
    });
    $$("[data-pick]", add).forEach(function (b) {
      b.onclick = function () {
        pick(b, true).then(function (l) {
          var have = $$('input[name="images[]"]', g).map(function (i) { return i.value; });
          l.forEach(function (p) { if (have.indexOf(p) === -1 && have.length < 10) { item(p); have.push(p); } });
          g.dispatchEvent(new Event("change", { bubbles: true }));
        });
      };
    });
  });

  /* ---------- rich text editor ---------- */
  $$(".rte").forEach(function (rte) {
    var area = $(".rte__area", rte), src = $(".rte__src", rte), out = $('textarea[name="content"]', rte);
    var form = rte.closest("form");
    var srcMode = false;
    try { document.execCommand("defaultParagraphSeparator", false, "p"); } catch (e) {}
    if (!area.innerHTML.trim()) area.innerHTML = "<p><br></p>";
    var saved = null;
    function keep() { var s = getSelection(); if (s.rangeCount && area.contains(s.anchorNode)) saved = s.getRangeAt(0).cloneRange(); }
    function restore() { area.focus(); if (saved) { var s = getSelection(); s.removeAllRanges(); s.addRange(saved); } }
    area.addEventListener("keyup", keep); area.addEventListener("mouseup", keep); area.addEventListener("input", function () { keep(); form.dispatchEvent(new Event("change")); });

    $$(".rte__bar button", rte).forEach(function (b) {
      b.addEventListener("mousedown", function (e) { e.preventDefault(); });   // keep the text selection
      b.addEventListener("click", function () {
        var cmd = b.dataset.cmd;
        if (cmd === "source") {
          srcMode = !srcMode;
          if (srcMode) { src.value = area.innerHTML; } else { area.innerHTML = src.value; }
          src.hidden = !srcMode; area.hidden = srcMode; b.classList.toggle("on", srcMode);
          return;
        }
        if (srcMode) return;
        restore();
        if (cmd === "link") {
          var url = prompt("رابط الصفحة (مثال: /ads/ أو https://…):", "/");
          if (url) document.execCommand("createLink", false, url.trim());
        } else if (cmd === "image") {
          var fromPc = confirm("موافق = رفع صورة من جهازك\nإلغاء = اختيار من مكتبة الصور");
          (fromPc ? uploadMany(b, false) : library(false)).then(function (l) {
            if (!l[0]) return;
            var alt = prompt("وصف الصورة (مهم لجوجل):", "") || "";
            restore();
            document.execCommand("insertHTML", false, '<figure><img src="/' + esc(l[0]) + '" alt="' + esc(alt) + '">' + (alt ? "<figcaption>" + esc(alt) + "</figcaption>" : "") + "</figure><p><br></p>");
            form.dispatchEvent(new Event("change"));
          });
          return;
        } else if (cmd === "formatBlock") {
          document.execCommand("formatBlock", false, "<" + b.dataset.val + ">");
        } else {
          document.execCommand(cmd, false, null);
        }
        keep();
        form.dispatchEvent(new Event("change"));
      });
    });
    // pasting from Word / websites: keep plain text + line breaks only (clean, fast pages)
    area.addEventListener("paste", function (e) {
      var t = (e.clipboardData || window.clipboardData).getData("text/plain");
      if (!t) return;
      e.preventDefault();
      var html = t.split(/\n{2,}/).map(function (p) { return "<p>" + esc(p.trim()).replace(/\n/g, "<br>") + "</p>"; }).join("");
      document.execCommand("insertHTML", false, html);
    });
    rte.getHTML = function () { return srcMode ? src.value : area.innerHTML; };
    form.addEventListener("submit", function () { out.value = rte.getHTML(); });
  });

  /* ---------- character counters ---------- */
  $$("[data-count]").forEach(function (el) {
    var max = +el.dataset.count, c = document.createElement("small");
    c.className = "count";
    el.insertAdjacentElement("afterend", c);
    function upd() {
      var n = el.value.length;
      c.textContent = n + " / " + max + " حرف";
      c.className = "count" + (n > max ? " bad" : n > max * .9 ? " warn" : "");
    }
    el.addEventListener("input", upd); upd();
  });

  /* ---------- slug from the title ---------- */
  function slugify(t) {
    return t.toLowerCase().replace(/[ً-ٰٟـ]/g, "").replace(/[^\p{L}\p{N}]+/gu, "-").replace(/-+/g, "-").replace(/^-|-$/g, "").slice(0, 80);
  }
  var title = $("#fTitle"), slug = $("#fSlug");
  if (title && slug) {
    title.addEventListener("input", function () { if (slug.dataset.auto) slug.value = slugify(title.value); });
    slug.addEventListener("input", function () { delete slug.dataset.auto; });
  }

  /* ---------- Google preview + SEO checklist ---------- */
  var form = $("form.editor");
  if (form) {
    var kind = form.dataset.kind, serp = $("#serp"), checks = $("#checks");
    var plain = function (html) { var d = document.createElement("div"); d.innerHTML = html; return (d.textContent || "").replace(/\s+/g, " ").trim(); };
    var val = function (id) { var el = $(id); return el ? el.value.trim() : ""; };
    var cut = function (s, n) { return s.length > n ? s.slice(0, n - 1) + "…" : s; };
    var update = function () {
      var t = val("#fTitle"), mt = val("#fMetaTitle"), md = val("#fMetaDesc"), city = val("#fCity");
      var body, html = "";
      if (kind === "post") { var r = $(".rte"); html = r && r.getHTML ? r.getHTML() : ""; body = plain(html); }
      else body = val("#fDesc");
      var desc = md || (kind === "post" ? val("#fExcerpt") : "") || body;
      var shownTitle = (mt || t) + (kind === "ad" && city ? " في " + city : "") + " | سرعة الإنجاز الدولية";
      $("cite", serp).textContent = serp.dataset.base + (val("#fSlug") || slugify(t));
      $("b", serp).textContent = cut(shownTitle, 62);
      $("p", serp).textContent = cut(desc, 158);

      var list = [];
      var add = function (ok, txt) { list.push('<li class="' + (ok ? "ok" : "warn") + '">' + txt + "</li>"); };
      var tl = (mt || t).length;
      add(tl >= 25 && tl <= 65, "طول العنوان " + tl + " حرف (الأفضل ٢٥–٦٥)");
      add(desc.length >= 70, "وصف جوجل " + Math.min(desc.length, 999) + " حرف (الأفضل ٧٠–١٦٠)");
      add(!!city, city ? "مرتبط بمدينة: " + city + " (GEO)" : "اختر مدينة — يقوّي الظهور في البحث المحلي");
      if (kind === "post") {
        var words = body ? body.split(" ").length : 0;
        var h2 = (html.match(/<h2/gi) || []).length;
        var links = (html.match(/<a\s[^>]*href="(\/|https?:\/\/(www\.)?riyadh-tajir)/gi) || []).length;
        add(words >= 300, "عدد الكلمات " + words + " (٣٠٠ على الأقل)");
        add(h2 >= 2, "عناوين فرعية H2: " + h2 + " (٢ على الأقل)");
        add(!!val("#fCover"), "صورة الغلاف");
        add(!val("#fCover") || val("#fAlt").length >= 10, "وصف صورة الغلاف (Alt)");
        add(links >= 1, "رابط داخلي لصفحة في الموقع (" + links + ")");
        var kw = t.split(/\s+/).filter(function (w) { return w.length > 3; }).slice(0, 3);
        var first = plain(html).slice(0, 300);
        add(kw.some(function (w) { return first.indexOf(w) !== -1; }), "كلمات العنوان تظهر في أول فقرة");
      } else {
        var imgs = $$('input[name="images[]"]').length;
        add(imgs >= 1, "صور الإعلان: " + imgs + (imgs < 3 ? " (٣ صور أو أكثر أفضل)" : ""));
        add(body.length >= 150, "وصف الإعلان " + body.length + " حرف (١٥٠ على الأقل)");
        add(/\d/.test(t), "العنوان يحتوي رقماً (الحمولة/الارتفاع/الموديل)");
      }
      checks.innerHTML = list.join("");
    };
    form.addEventListener("input", update);
    form.addEventListener("change", update);
    update();

    // warn before leaving with unsaved changes
    var dirty = false;
    form.addEventListener("input", function () { dirty = true; });
    form.addEventListener("change", function () { dirty = true; });
    form.addEventListener("submit", function () { dirty = false; });
    window.addEventListener("beforeunload", function (e) { if (dirty) { e.preventDefault(); e.returnValue = ""; } });
    setTimeout(function () { dirty = false; }, 0);
  }
})();
