/* Admin pages: confirm destructive actions, and fill the licence price from the plan. */
(function () {
  "use strict";

  document.querySelectorAll("form[data-confirm]").forEach(function (form) {
    form.addEventListener("submit", function (e) {
      if (!window.confirm(form.getAttribute("data-confirm"))) { e.preventDefault(); }
    });
  });

  // Changing the plan updates the price, unless it was edited to something custom.
  var plan = document.getElementById("plan");
  var price = document.getElementById("licence_price");
  if (plan && price && plan.dataset.planPrices) {
    var prices = JSON.parse(plan.dataset.planPrices);
    var previous = plan.value;
    plan.addEventListener("change", function () {
      var current = String(price.value).replace(/[,\s]/g, "");
      if (current === "" || current === String(prices[previous])) { price.value = prices[plan.value]; }
      previous = plan.value;
    });
  }
})();
