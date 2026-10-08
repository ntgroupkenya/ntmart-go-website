/* NTmart Go website behaviour. Every block checks that its elements exist,
 * so the same file works on every page. No dependencies.
 */
(function () {
  "use strict";

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  /* ---- Menu: shadow on scroll ---- */
  var menu = $(".menu");
  if (menu) {
    var onScroll = function () { menu.classList.toggle("menu--scrolled", window.scrollY > 8); };
    window.addEventListener("scroll", onScroll, { passive: true });
    onScroll();
  }

  /* ---- Desktop dropdowns: click support for touch screens ---- */
  $$(".menu__dropdown").forEach(function (dropdown) {
    var btn = $(".menu__dropdown-btn", dropdown);
    btn.addEventListener("click", function (e) {
      e.stopPropagation();
      var open = !dropdown.classList.contains("is-open");
      $$(".menu__dropdown.is-open").forEach(function (d) {
        d.classList.remove("is-open");
        $(".menu__dropdown-btn", d).setAttribute("aria-expanded", "false");
      });
      dropdown.classList.toggle("is-open", open);
      btn.setAttribute("aria-expanded", String(open));
    });
  });
  document.addEventListener("click", function () {
    $$(".menu__dropdown.is-open").forEach(function (d) {
      d.classList.remove("is-open");
      $(".menu__dropdown-btn", d).setAttribute("aria-expanded", "false");
    });
  });

  /* ---- Mobile menu ---- */
  var burger = $(".menu__burger");
  var mobileMenu = $("#mobile-menu");
  if (burger && mobileMenu) {
    burger.addEventListener("click", function () {
      var open = mobileMenu.hasAttribute("hidden");
      if (open) { mobileMenu.removeAttribute("hidden"); } else { mobileMenu.setAttribute("hidden", ""); }
      burger.setAttribute("aria-expanded", String(open));
      burger.setAttribute("aria-label", open ? "Close menu" : "Open menu");
      $(".mdi", burger).className = "mdi " + (open ? "mdi-close" : "mdi-menu");
    });
    $$(".mobile-menu__collapse", mobileMenu).forEach(function (btn) {
      btn.addEventListener("click", function () {
        var list = document.getElementById(btn.getAttribute("aria-controls"));
        var open = btn.getAttribute("aria-expanded") !== "true";
        btn.setAttribute("aria-expanded", String(open));
        if (open) { list.removeAttribute("hidden"); } else { list.setAttribute("hidden", ""); }
      });
    });
  }

  /* ---- Scrollspy for the sidebar (FAQ, privacy) and topbar (features) ----
   * Any nav with [data-spy] highlights the link whose section is in view.
   */
  $$("[data-spy]").forEach(function (nav) {
    var links = $$('a[href^="#"]', nav);
    var activeClass = nav.getAttribute("data-spy");
    var targets = links
      .map(function (a) { return document.getElementById(a.getAttribute("href").slice(1)); })
      .filter(Boolean);
    if (!targets.length || !("IntersectionObserver" in window)) { return; }

    var setActive = function (id) {
      links.forEach(function (a) {
        var on = a.getAttribute("href") === "#" + id;
        var el = activeClass === "item" ? a.parentElement : a;
        el.classList.toggle("active", on);
        if (on && nav.classList.contains("topbar") && a.scrollIntoView) {
          var list = a.closest(".topbar__list");
          if (list) { list.scrollTo({ left: a.offsetLeft - 16, behavior: "smooth" }); }
        }
      });
    };

    var visible = {};
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) { visible[entry.target.id] = entry.isIntersecting; });
      for (var i = 0; i < targets.length; i++) {
        if (visible[targets[i].id]) { setActive(targets[i].id); break; }
      }
    }, { rootMargin: "-25% 0px -55% 0px" });
    targets.forEach(function (t) { observer.observe(t); });
  });

  /* ---- Pricing toggle: monthly / yearly ---- */
  var billing = $("#billing");
  if (billing) {
    var updatePrices = function () {
      var yearly = billing.checked;
      $$("[data-monthly]").forEach(function (el) {
        el.textContent = el.getAttribute(yearly ? "data-yearly" : "data-monthly");
      });
    };
    billing.addEventListener("change", updatePrices);
    updatePrices();
  }

  /* ---- Prefill a select from the query string, e.g. contact.html?topic=preview ---- */
  var params = new URLSearchParams(window.location.search);
  $$("select[data-prefill]").forEach(function (select) {
    var value = params.get(select.name);
    if (value && $$("option", select).some(function (o) { return o.value === value; })) {
      select.value = value;
    }
  });

  /* ---- AJAX forms with validation (contact form) ---- */
  var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

  var validateField = function (field) {
    var group = field.closest(".form__group");
    if (!group) { return true; }
    var value = field.type === "checkbox" ? field.checked : field.value.trim();
    var ok = true;
    if (field.required && !value) { ok = false; }
    else if (field.type === "email" && value && !EMAIL.test(value)) { ok = false; }
    else if (field.pattern && value && !new RegExp("^(?:" + field.pattern + ")$").test(value)) { ok = false; }
    else if (field.minLength > 0 && value && value.length < field.minLength) { ok = false; }
    group.classList.toggle("has-error", !ok);
    field.setAttribute("aria-invalid", String(!ok));
    return ok;
  };

  $$("form[data-ajax]").forEach(function (form) {
    var status = $(".form__status", form);
    var submit = $('[type="submit"]', form);
    var fields = $$("input, select, textarea", form).filter(function (f) {
      return f.type !== "hidden" && !f.closest(".form__hp");
    });

    form.setAttribute("novalidate", "");
    fields.forEach(function (f) {
      // Re-check while typing, not on blur: hiding an error on blur shifts the
      // layout under the pointer and makes the click that caused the blur miss.
      f.addEventListener("input", function () { if (f.closest(".has-error")) { validateField(f); } });
      f.addEventListener("change", function () { if (f.closest(".has-error")) { validateField(f); } });
    });

    var showStatus = function (ok, message) {
      status.hidden = false;
      status.className = "form__status " + (ok ? "form__status--ok" : "form__status--error");
      status.textContent = message;
    };

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var firstBad = null;
      fields.forEach(function (f) { if (!validateField(f) && !firstBad) { firstBad = f; } });
      if (firstBad) { firstBad.focus(); return; }

      submit.disabled = true;
      var label = submit.textContent;
      submit.textContent = "Sending…";

      fetch(form.action, { method: "POST", body: new FormData(form), headers: { Accept: "application/json" } })
        .then(function (res) {
          return res.json().catch(function () { return { ok: false }; }).then(function (data) {
            return { ok: res.ok && data.ok, message: data.message };
          });
        })
        .then(function (result) {
          if (result.ok) {
            form.reset();
            showStatus(true, result.message || "Thanks! Your message has been sent. We'll get back to you soon.");
          } else {
            showStatus(false, result.message || "Sorry, your message could not be sent. Please try again or email us.");
          }
        })
        .catch(function () {
          showStatus(false, "Sorry, your message could not be sent. Check your connection and try again.");
        })
        .then(function () {
          submit.disabled = false;
          submit.textContent = label;
        });
    });
  });

  /* ---- Footer year ---- */
  $$("[data-year]").forEach(function (el) { el.textContent = new Date().getFullYear(); });
})();
