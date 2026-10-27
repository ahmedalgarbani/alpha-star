/* سرعة الإنجاز الدولية — main.js
   Header · hero slideshow · quote builder
   · gallery filter + lightbox · scroll effects                       */

(function () {
  "use strict";

  var root = document.documentElement;
  var reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var WA = "966503516218";
  var $ = function (s, c) { return (c || document).querySelector(s); };
  var $$ = function (s, c) { return Array.prototype.slice.call((c || document).querySelectorAll(s)); };
  var ar = function (n) { return Number(n).toLocaleString("ar-SA"); };
  var clamp = function (v, a, b) { return Math.min(b, Math.max(a, v)); };
  var pad = function (n) { return (n < 10 ? "0" : "") + n; };

  /* ---------- Split hero words ---------- */
  var wi = 0;
  $$(".split").forEach(function (el) {
    el.innerHTML = el.textContent.trim().split(/\s+/).map(function (w) {
      return '<span class="w"><span style="--i:' + (wi++) + '">' + w + "</span></span>";
    }).join(" ");
  });

  /* ---------- Intro animation ---------- */
  requestAnimationFrame(function () {
    requestAnimationFrame(function () {
      root.classList.add("loaded");
      startHeroCounters();
    });
  });

  /* ---------- Header / nav ---------- */
  var header = $("#header");
  var burger = $("#burger");
  var nav = $("#nav");

  function setNav(open) {
    nav.classList.toggle("open", open);
    burger.classList.toggle("active", open);
    header.classList.toggle("nav-active", open);
    document.body.classList.toggle("nav-open", open);
    burger.setAttribute("aria-expanded", open ? "true" : "false");
    burger.setAttribute("aria-label", open ? "إغلاق القائمة" : "فتح القائمة");
  }
  burger.addEventListener("click", function () { setNav(!nav.classList.contains("open")); });
  $$("a", nav).forEach(function (a) { a.addEventListener("click", function () { setNav(false); }); });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape" && nav.classList.contains("open")) setNav(false); });

  // highlight current section
  var navLinks = $$('.nav > a[href^="#"]');
  if ("IntersectionObserver" in window) {
    var sio = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        navLinks.forEach(function (a) { a.classList.toggle("is-current", a.getAttribute("href") === "#" + en.target.id); });
      });
    }, { rootMargin: "-45% 0px -50% 0px" });
    $$("main section[id]").forEach(function (s) { sio.observe(s); });
  }

  /* ---------- Hero slideshow ---------- */
  var slides = $$(".hero__slide");
  var cur = 0;
  if (!reduced && slides.length > 1) {
    setInterval(function () {
      slides[cur].classList.remove("is-active");
      cur = (cur + 1) % slides.length;
      slides[cur].classList.add("is-active");
    }, 6000);
  }

  // cursor spotlight + magnetic CTA
  if (!reduced && window.matchMedia("(hover: hover)").matches) {
    $$(".magnetic").forEach(function (el) {
      el.addEventListener("mousemove", function (e) {
        var r = el.getBoundingClientRect();
        var x = (e.clientX - r.left - r.width / 2) * .25;
        var y = (e.clientY - r.top - r.height / 2) * .35;
        el.style.transform = "translate(" + x + "px," + y + "px)";
      });
      el.addEventListener("mouseleave", function () { el.style.transform = ""; });
    });
    $$(".tile").forEach(function (t) {
      t.addEventListener("mousemove", function (e) {
        var r = t.getBoundingClientRect();
        t.style.setProperty("--mx", (e.clientX - r.left) + "px");
        t.style.setProperty("--my", (e.clientY - r.top) + "px");
      });
    });
  }

  /* ---------- Counters ---------- */
  function animateCounter(el) {
    var target = parseInt(el.getAttribute("data-count"), 10);
    if (reduced) { el.textContent = ar(target); return; }
    var start = null;
    function tick(ts) {
      if (!start) start = ts;
      var p = Math.min((ts - start) / 1800, 1);
      el.textContent = ar(Math.round((1 - Math.pow(1 - p, 4)) * target));
      if (p < 1) requestAnimationFrame(tick);
    }
    el.textContent = ar(0);
    requestAnimationFrame(tick);
  }
  function startHeroCounters() { $$(".hero .counter").forEach(animateCounter); }

  /* ---------- Scroll reveal ---------- */
  var revealEls = $$(".reveal");
  revealEls.forEach(function (el) {
    var sibs = $$(":scope > .reveal", el.parentNode);
    var i = sibs.indexOf(el);
    if (i > 0) el.style.setProperty("--d", (i % 6) * .08 + "s");
  });
  if ("IntersectionObserver" in window) {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) {
        if (!en.isIntersecting) return;
        en.target.classList.add("in");
        $$(".counter", en.target).forEach(animateCounter);
        io.unobserve(en.target);
      });
    }, { threshold: .15, rootMargin: "0px 0px -40px 0px" });
    revealEls.forEach(function (el) { io.observe(el); });
  } else {
    revealEls.forEach(function (el) { el.classList.add("in"); });
  }

  /* ---------- Scroll-driven bits (one rAF loop) ---------- */
  var progress = $("#progress");
  var steps = $("#steps");
  var lastY = window.scrollY, ticking = false;

  function onScroll() {
    var y = window.scrollY;
    var docH = document.documentElement.scrollHeight - window.innerHeight;
    progress.style.setProperty("--p", docH > 0 ? y / docH : 0);

    header.classList.toggle("scrolled", y > 30);
    if (!nav.classList.contains("open")) {
      if (y > window.innerHeight && y > lastY + 6) header.classList.add("hidden");
      else if (y < lastY - 6 || y < window.innerHeight) header.classList.remove("hidden");
    }
    lastY = y;

    if (steps) {
      var r = steps.getBoundingClientRect();
      var sp = clamp((window.innerHeight * .8 - r.top) / (r.height + window.innerHeight * .3), 0, 1);
      steps.style.setProperty("--sp", sp.toFixed(3));
    }
    ticking = false;
  }
  window.addEventListener("scroll", function () {
    if (!ticking) { requestAnimationFrame(onScroll); ticking = true; }
  }, { passive: true });

  onScroll();

  /* ---------- Quote builder ---------- */

  var form = $("#qform");

  // Equipment types come from the radio buttons in the form (data-* attributes),
  // so new types can be added from the site editor without touching this file.
  // (FALLBACK covers pages saved before these attributes existed.)
  var FALLBACK = {
    crane:     { metric: "الحمولة المطلوبة", unit: "طن", min: 5, max: 175, step: 5, def: 50, img: "assets/img/photo-13.jpg" },
    lift:      { metric: "ارتفاع العمل", unit: "متر", min: 12, max: 45, step: 1, def: 20, img: "assets/img/photo-11.jpg" },
    excavator: { kinds: "1", img: "assets/img/photo-20.jpg" },
    forklift:  { metric: "الحمولة المطلوبة", unit: "طن", min: 3, max: 16, step: 1, def: 5, img: "assets/img/photo-05.jpg" }
  };
  var TYPES = {};
  // A step that doesn't divide the range (e.g. 10→80 by 6) makes the maximum unreachable and the
  // bar jump oddly — use the nearest smaller step that fits exactly.
  function fitStep(min, max, step) {
    var span = max - min;
    step = Math.max(1, Math.min(step, span));
    if (span % 1 || step % 1) return step;
    while (span % step) step--;
    return step;
  }
  function readType(r) {
    var d = r.hasAttribute("data-img") || r.hasAttribute("data-metric") || r.hasAttribute("data-kinds") ? r.dataset : (FALLBACK[r.value] || {});
    var label = r.parentNode.textContent.replace(/\s+/g, " ").trim();
    if (d.kinds) return { label: label, kinds: true, img: d.img };
    var min = Number(d.min) || 0, max = Number(d.max) || 100;
    if (max <= min) max = min + 100;
    var step = fitStep(min, max, Number(d.step) || 1);
    var def = Number(d.def);
    def = isNaN(def) || d.def === undefined || d.def === "" ? min : Math.min(max, Math.max(min, def));
    def = min + Math.round((def - min) / step) * step;
    return { label: label, metric: d.metric || "الحمولة المطلوبة", unit: d.unit || "طن", min: min, max: max, step: step, def: def, img: d.img };
  }
  $$('input[name="type"]', form).forEach(function (r) { TYPES[r.value] = readType(r); });
  function typeOf(key) {
    if (!TYPES[key]) {   // added after page load (or unknown) — read it now instead of failing
      var r = form.querySelector('input[name="type"][value="' + key + '"]');
      TYPES[key] = r ? readType(r) : TYPES[Object.keys(TYPES)[0]];
    }
    return TYPES[key];
  }
  var cap = $("#qCap");
  var capField = $("#qCapField"), kindField = $("#qKindField");
  var qMetric = $("#qMetric"), qMin = $("#qMin"), qMax = $("#qMax");
  var qCity = $("#qCity"), qOp = $("#qOp");
  var qPhoto = $("#qPhoto"), qMsg = $("#qMsg"), qSend = $("#qSend");
  var gauge = $(".gauge"), gFill = $("#gFill"), gNeedle = $("#gNeedle");
  var gVal = $("#gVal"), gUnit = $("#gUnit"), gType = $("#gType"), gRead = $(".gauge__read");
  var currentType = null;

  // Phones: the gauge is far below the slider, so show the value right on the field + − / + buttons.
  var capVal = document.createElement("output");
  capVal.className = "qrange__val";
  capVal.setAttribute("for", "qCap");
  capVal.setAttribute("aria-live", "polite");
  qMetric.parentNode.appendChild(capVal);
  var capRow = document.createElement("div");
  capRow.className = "qrange__row";
  cap.parentNode.insertBefore(capRow, cap);
  function stepBtn(sign, text, label) {
    var b = document.createElement("button");
    b.type = "button"; b.className = "qrange__btn"; b.textContent = text; b.setAttribute("aria-label", label);
    var hold = null, rep = null;
    function bump() {
      var t = typeOf(currentType); if (!t || t.kinds) return;
      var v = Math.min(t.max, Math.max(t.min, Number(cap.value) + sign * t.step));
      if (v === Number(cap.value)) return stop();
      cap.value = v;
      updateQuote();
    }
    function stop() { clearTimeout(hold); clearInterval(rep); hold = rep = null; }
    b.addEventListener("pointerdown", function (e) {
      e.preventDefault();
      bump();
      hold = setTimeout(function () { rep = setInterval(bump, 80); }, 380);   // press and hold = fast change
    });
    ["pointerup", "pointerleave", "pointercancel", "blur"].forEach(function (ev) { b.addEventListener(ev, stop); });
    b.addEventListener("click", function (e) { if (e.detail === 0) bump(); });   // keyboard (Enter / Space)
    return b;
  }
  var capMinus = stepBtn(-1, "−", "إنقاص"), capPlus = stepBtn(1, "+", "زيادة");
  capRow.appendChild(capMinus); capRow.appendChild(cap); capRow.appendChild(capPlus);   // RTL: − on the right (low end), + on the left

  // gauge ticks
  (function () {
    var g = $("#gTicks"), out = "";
    for (var i = 0; i <= 24; i++) {
      var a = (-120 + i * 10) * Math.PI / 180;
      var major = i % 6 === 0;
      var r1 = major ? 100 : 105, r2 = 113;
      out += '<line class="' + (major ? "major" : "") + '" x1="' + (120 + r1 * Math.sin(a)).toFixed(2) + '" y1="' + (120 - r1 * Math.cos(a)).toFixed(2) +
        '" x2="' + (120 + r2 * Math.sin(a)).toFixed(2) + '" y2="' + (120 - r2 * Math.cos(a)).toFixed(2) + '"/>';
    }
    g.innerHTML = out;
  })();

  function val(name) { var el = form.querySelector('input[name="' + name + '"]:checked'); return el ? el.value : ""; }

  function setGauge(f) {
    gFill.style.setProperty("--g", (f * 100).toFixed(1));
    gNeedle.style.setProperty("--r", (-120 + 240 * f).toFixed(1) + "deg");
  }

  function applyType(key) {
    var t = typeOf(key);
    if (key !== currentType) {
      currentType = key;
      capField.hidden = !!t.kinds;
      kindField.hidden = !t.kinds;
      if (!t.kinds) {
        cap.min = t.min; cap.max = t.max; cap.step = t.step; cap.value = t.def;
        cap.setAttribute("aria-label", t.metric);
        qMetric.textContent = t.metric;
        qMin.textContent = ar(t.min) + " " + t.unit;
        qMax.textContent = ar(t.max) + " " + t.unit;
      }
      qPhoto.classList.add("swap");
      setTimeout(function () {
        if (t.img) qPhoto.style.backgroundImage = "url('" + t.img + "')";
        qPhoto.classList.remove("swap");
      }, 250);
    }
  }

  function updateQuote() {
    var key = val("type") || Object.keys(TYPES)[0];
    applyType(key);
    var t = typeOf(key);
    var lines = ["السلام عليكم، أرغب بعرض سعر لتأجير:", "• المعدة: " + t.label];

    if (t.kinds) {
      var kind = val("kind");
      var kinds = $$('input[name="kind"]', form).map(function (i) { return i.value; });
      setGauge((kinds.indexOf(kind) + .5) / kinds.length);
      gRead.classList.add("is-text");
      gVal.textContent = kind.replace(/\s*\(.*\)/, "");
      gUnit.textContent = "";
      lines.push("• النوع: " + kind);
    } else {
      var v = Number(cap.value);
      var f = Math.min(1, Math.max(0, (v - t.min) / (t.max - t.min)));
      cap.style.setProperty("--f", (f * 100) + "%");
      setGauge(f);
      if (capVal.value !== ar(v) + " " + t.unit) {
        capVal.value = ar(v) + " " + t.unit;
        capVal.classList.remove("bump"); void capVal.offsetWidth; capVal.classList.add("bump");
      }
      capMinus.disabled = v <= t.min;
      capPlus.disabled = v >= t.max;
      gRead.classList.remove("is-text");
      gVal.textContent = ar(v);
      gUnit.textContent = t.unit;
      lines.push("• " + t.metric.replace(" المطلوبة", "") + ": " + ar(v) + " " + t.unit);
    }
    gType.textContent = t.label;
    gauge.setAttribute("aria-label", t.label);

    lines.push("• المدة: " + val("dur"));
    lines.push("• الموقع: " + qCity.value);
    lines.push("• التشغيل: " + (qOp.checked ? "مع مشغل" : "بدون مشغل"));

    var msg = lines.join("\n");
    qMsg.textContent = msg;
    qSend.href = "https://wa.me/" + WA + "?text=" + encodeURIComponent(msg);
  }

  form.addEventListener("input", updateQuote);
  form.addEventListener("change", updateQuote);
  form.addEventListener("submit", function (e) { e.preventDefault(); });

  // "اطلب السعر" in fleet panels pre-selects the type
  $$("[data-pick]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var r = form.querySelector('input[name="type"][value="' + btn.getAttribute("data-pick") + '"]');
      if (r) { r.checked = true; updateQuote(); }
    });
  });
  updateQuote();

  /* ---------- Gallery filter ---------- */
  var filterBtns = $$(".filter");
  var items = $$(".g-item");

  filterBtns.forEach(function (btn) {
    btn.addEventListener("click", function () {
      filterBtns.forEach(function (b) {
        var on = b === btn;
        b.classList.toggle("is-active", on);
        b.setAttribute("aria-selected", on ? "true" : "false");
      });
      var f = btn.getAttribute("data-filter");
      var n = 0;
      items.forEach(function (it) {
        var show = f === "all" || it.getAttribute("data-cat") === f;
        it.classList.toggle("hide", !show);
        it.classList.remove("pop");
        if (show) {
          void it.offsetWidth;
          it.style.setProperty("--d", (n++ * .05) + "s");
          it.classList.add("pop");
        }
      });
    });
  });

  /* ---------- Lightbox ---------- */
  var lb = $("#lightbox"), lbImg = $("#lbImg"), lbCaption = $("#lbCaption"), lbCount = $("#lbCount");
  var lbClose = $("#lbClose");
  var list = [], li = 0, lastFocus = null;

  function visible() { return items.filter(function (el) { return !el.classList.contains("hide"); }); }
  function openAt(i) {
    if (lb.hidden) { list = visible(); lastFocus = document.activeElement; }
    li = (i + list.length) % list.length;
    var fig = list[li], img = $("img", fig);
    lbImg.src = img.src;
    lbImg.alt = img.alt;
    lbCaption.textContent = img.alt;
    lbCount.textContent = pad(li + 1) + " / " + pad(list.length);
    if (lb.hidden) {
      lb.hidden = false;
      document.body.style.overflow = "hidden";
      lbClose.focus();
    }
  }
  function closeLb() {
    lb.hidden = true;
    document.body.style.overflow = "";
    if (lastFocus) lastFocus.focus();
  }

  items.forEach(function (fig) {
    fig.setAttribute("tabindex", "0");
    fig.setAttribute("role", "button");
    fig.addEventListener("click", function () { openAt(visible().indexOf(fig)); });
    fig.addEventListener("keydown", function (e) {
      if (e.key === "Enter" || e.key === " ") { e.preventDefault(); openAt(visible().indexOf(fig)); }
    });
  });
  lbClose.addEventListener("click", closeLb);
  $("#lbPrev").addEventListener("click", function () { openAt(li - 1); });
  $("#lbNext").addEventListener("click", function () { openAt(li + 1); });
  lb.addEventListener("click", function (e) { if (e.target === lb) closeLb(); });
  document.addEventListener("keydown", function (e) {
    if (lb.hidden) return;
    if (e.key === "Escape") closeLb();
    if (e.key === "ArrowLeft") openAt(li + 1);  // RTL: left = next
    if (e.key === "ArrowRight") openAt(li - 1); // RTL: right = prev
  });
  var tx = null;
  lb.addEventListener("touchstart", function (e) { tx = e.touches[0].clientX; }, { passive: true });
  lb.addEventListener("touchend", function (e) {
    if (tx === null) return;
    var dx = e.changedTouches[0].clientX - tx;
    if (Math.abs(dx) > 50) openAt(dx > 0 ? li + 1 : li - 1); // swipe right = next in RTL
    tx = null;
  });
})();
