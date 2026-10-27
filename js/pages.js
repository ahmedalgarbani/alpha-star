/* سرعة الإنجاز الدولية — blog / ads / FAQ pages: mobile menu only (main.js is for the home page) */
(function () {
  "use strict";
  var header = document.getElementById("header");
  var burger = document.getElementById("burger");
  var nav = document.getElementById("nav");
  if (!burger || !nav) return;

  function setNav(open) {
    nav.classList.toggle("open", open);
    burger.classList.toggle("active", open);
    header.classList.toggle("nav-active", open);
    document.body.classList.toggle("nav-open", open);
    burger.setAttribute("aria-expanded", open ? "true" : "false");
    burger.setAttribute("aria-label", open ? "إغلاق القائمة" : "فتح القائمة");
  }
  burger.addEventListener("click", function () { setNav(!nav.classList.contains("open")); });
  Array.prototype.forEach.call(nav.querySelectorAll("a"), function (a) { a.addEventListener("click", function () { setNav(false); }); });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape" && nav.classList.contains("open")) setNav(false); });
})();
