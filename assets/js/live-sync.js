/* ================================================================
 * ConTrack AI · Live sync
 * Keeps portal pages current without signing out and back in.
 *
 *  - Polls /api/changes.php (a hash of the contracts, alerts, scores
 *    and users the signed-in account can see) while the tab is visible,
 *    and right away when the tab regains focus.
 *  - When the hash moves, the page reloads its data. A reload never
 *    interrupts work: if a modal is open, a field is focused or a form
 *    has unsaved input, a "New updates" bar appears instead and the
 *    refresh waits until the user clicks it or the page is idle again.
 *  - Pages restored from the browser's back/forward cache are reloaded
 *    so the Back button never shows stale data.
 *  - Between full checks it watches /api/pulse.php, a free "something
 *    was saved" signal, so a vendor upload or a manager/CEO decision
 *    reaches the other portals within a couple of seconds.
 * ================================================================ */

const POLL_MS = 15000;
const PULSE_MS = 2000;
const IDLE_AFTER_INTERACTION_MS = 4000;

let baseline = null;
let pendingUpdate = false;
let polling = false;
let pollAgain = false;
let formDirty = false;
let lastInteraction = 0;
let timer = null;
let pulseTimer = null;
let lastPulse = null;
let pulseChecking = false;
let bar = null;

async function fetchVersion() {
  try {
    const response = await fetch("/api/changes.php", {
      credentials: "same-origin",
      cache: "no-store",
    });
    if (!response.ok) return null;
    const payload = await response.json().catch(() => null);
    return payload && payload.parts ? payload.parts : null;
  } catch (_) {
    return null;
  }
}

function changed(before, after) {
  if (!before || !after) return false;
  return Object.keys(after).some((key) => {
    const a = before[key];
    const b = after[key];
    if (a === undefined || a === "unavailable" || b === "unavailable") return false;
    return a !== b;
  });
}

function hasOpenOverlay() {
  if (document.body.classList.contains("shell-nav-open")) return true;
  const selectors = [
    ".modal-overlay.open",
    ".modal-overlay.is-open",
    ".modal.open",
    ".notif-sidebar.open",
    ".top-user.is-open",
    "#nlp-extract-overlay.is-open",
    "[role='dialog'][aria-modal='true']:not([hidden])",
  ];
  return Boolean(document.querySelector(selectors.join(",")));
}

function isEditing() {
  const el = document.activeElement;
  if (!el || el === document.body) return false;
  if (el.isContentEditable) return true;
  const tag = el.tagName;
  if (tag === "TEXTAREA" || tag === "SELECT") return true;
  if (tag === "INPUT") {
    const type = String(el.type || "").toLowerCase();
    return !["button", "submit", "reset", "checkbox", "radio"].includes(type);
  }
  return false;
}

function hasTextSelection() {
  const selection = window.getSelection?.();
  return Boolean(selection && String(selection).trim());
}

function safeToReload() {
  if (document.visibilityState !== "visible") return false;
  if (formDirty) return false;
  if (Date.now() - lastInteraction < IDLE_AFTER_INTERACTION_MS) return false;
  return !hasOpenOverlay() && !isEditing() && !hasTextSelection();
}

function reloadNow() {
  window.location.reload();
}

function showUpdateBar() {
  if (bar) return;
  bar = document.createElement("button");
  bar.type = "button";
  bar.className = "live-sync-bar";
  bar.setAttribute("role", "status");
  bar.textContent = "New updates available — click to refresh";
  bar.style.cssText = [
    "position:fixed",
    "left:50%",
    "bottom:20px",
    "transform:translateX(-50%)",
    "z-index:10000",
    "padding:10px 18px",
    "border:none",
    "border-radius:999px",
    "background:#7A0C0C",
    "color:#fff",
    "font:600 13px/1.2 inherit",
    "box-shadow:0 6px 20px rgba(0,0,0,0.18)",
    "cursor:pointer",
  ].join(";");
  bar.addEventListener("click", reloadNow);
  document.body.appendChild(bar);
}

function applyPending() {
  if (!pendingUpdate) return;
  if (safeToReload()) {
    reloadNow();
  } else {
    showUpdateBar();
  }
}

async function poll() {
  if (document.visibilityState !== "visible") return;
  if (polling) {
    pollAgain = true;
    return;
  }
  polling = true;
  try {
    const parts = await fetchVersion();
    if (!parts) return;
    if (!baseline) {
      baseline = parts;
      return;
    }
    if (changed(baseline, parts)) {
      pendingUpdate = true;
    }
    applyPending();
  } finally {
    polling = false;
    if (pollAgain) {
      pollAgain = false;
      poll();
    }
  }
}

async function checkPulse() {
  if (pulseChecking || document.visibilityState !== "visible") return;
  pulseChecking = true;
  try {
    const response = await fetch("/api/pulse.php", { credentials: "same-origin", cache: "no-store" });
    if (!response.ok) return;
    const payload = await response.json().catch(() => null);
    const pulse = payload && typeof payload.pulse === "string" ? payload.pulse : null;
    if (pulse === null) return;
    const moved = lastPulse !== null && pulse !== lastPulse;
    lastPulse = pulse;
    if (moved) poll();
  } catch (_) {
    /* the regular poll still covers this tab */
  } finally {
    pulseChecking = false;
  }
}

function schedule() {
  if (timer) window.clearInterval(timer);
  timer = window.setInterval(poll, POLL_MS);
  if (pulseTimer) window.clearInterval(pulseTimer);
  pulseTimer = window.setInterval(checkPulse, PULSE_MS);
}

function markInteraction() {
  lastInteraction = Date.now();
}

export function startLiveSync() {
  if (window.__contrackLiveSync) return;
  window.__contrackLiveSync = true;

  document.addEventListener("input", (event) => {
    // Typing in a search/filter box is not unsaved work.
    const type = String(event.target?.type || "").toLowerCase();
    if (type === "search" || event.target?.classList?.contains("top-search")) return;
    formDirty = true;
  }, true);
  document.addEventListener("submit", () => {
    formDirty = false;
  }, true);
  ["pointerdown", "keydown", "wheel", "touchstart"].forEach((name) => {
    document.addEventListener(name, markInteraction, { capture: true, passive: true });
  });

  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible") poll();
  });
  window.addEventListener("focus", poll);

  // Closing a modal or finishing typing is when a waiting refresh can run.
  document.addEventListener("click", () => {
    if (pendingUpdate) window.setTimeout(applyPending, IDLE_AFTER_INTERACTION_MS + 100);
  });

  poll();
  checkPulse();
  schedule();
}

// Back/forward cache restores a frozen copy of the page; fetch fresh data instead.
window.addEventListener("pageshow", (event) => {
  if (event.persisted) reloadNow();
});
