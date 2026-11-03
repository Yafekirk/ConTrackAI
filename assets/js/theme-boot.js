/**
 * Applies the saved Light/Dark appearance before the page paints.
 * Load as a classic (non-module) script in <head>. The account value lives in
 * users.theme_preference; localStorage only mirrors it so the first paint is right.
 */
(function () {
  var KEY = "contrack_theme";

  function apply(theme) {
    var dark = theme === "dark";
    if (dark) document.documentElement.setAttribute("data-theme", "dark");
    else document.documentElement.removeAttribute("data-theme");
    return dark ? "dark" : "light";
  }

  function remember(theme) {
    var value = apply(theme);
    try {
      localStorage.setItem(KEY, value);
    } catch (_) {
      /* private mode: the theme still applies for this page */
    }
    return value;
  }

  function current() {
    return document.documentElement.getAttribute("data-theme") === "dark" ? "dark" : "light";
  }

  try {
    apply(localStorage.getItem(KEY));
  } catch (_) {
    /* storage blocked: stay light */
  }

  window.ContrackTheme = { apply: apply, remember: remember, current: current };
})();
