/* ================================================================
 * ConTrack AI · Portal Shell
 * Shared topbar, user dropdown, identity wiring, and sidebar
 * active-state logic for every portal (Admin / CEO / Manager /
 * VendorClient).
 *
 * How to use on any page:
 *   <link rel="stylesheet" href="/assets/css/portal-shell.css">
 *   ...
 *   <script type="module" src="/assets/js/portal-shell.js"></script>
 *
 * No additional inline JS is required. The shell will:
 *   1. Validate the active session (and redirect to /index.html
 *      when there is no logged-in user or the role doesn't match
 *      the portal folder the page lives in).
 *   2. Render the user box + dropdown inside the existing
 *      <div class="topbar"> on the page.
 *   3. Populate any sidebar `.sb-name`, `.sb-email`, `.sb-av`
 *      elements with the user's identity.
 *   4. Highlight the current sidebar link automatically based on
 *      the URL.
 *   5. Wire up "Sign out" everywhere (server session + local
 *      storage cleared).
 * ================================================================ */

const ROLE_FOLDER_MAP = {
  Admin:         "admin",
  CEO:           "ceo",
  Manager:       "manager",
  VendorClient:  "vendor",
};

const ROLE_LABEL = {
  admin:   "System Administrator",
  ceo:     "Chief Executive",
  manager: "Contract Manager",
  vendor:  "Vendor / Supplier",
};

const PROFILE_ROUTES = {
  admin:   "/pages/Admin/AdminProfile.html",
  ceo:     "/pages/CEO/CEO_Profile.html",
  manager: "/pages/Manager/ManagerProfile.html",
  vendor:  "/pages/VendorClient/VendorProfile.html",
};

const SETTINGS_ROUTES = {
  admin:   "/pages/Admin/AdminSettings.html",
  ceo:     "/pages/CEO/CEO_Settings.html",
  manager: "/pages/Manager/ManagerSettings.html",
  vendor:  "/pages/VendorClient/VendorSettings.html",
};

const LOGO_SRC = "/assets/images/Logo-mark.png";

const PORTAL_SUBTITLE = {
  admin:   "ADMIN PANEL",
  ceo:     "EXECUTIVE PANEL",
  manager: "MANAGER PORTAL",
  vendor:  "VENDOR PORTAL",
};

function normalizeRole(value) {
  const role = String(value || "").toLowerCase();
  if (role === "system_admin") return "admin";
  if (role === "vendor_client" || role === "client") return "vendor";
  return role || "vendor";
}

function expectedRoleFromPath(pathname) {
  const match = pathname.match(/\/pages\/([^/]+)\//i);
  if (!match) return null;
  return ROLE_FOLDER_MAP[match[1]] || null;
}

function readLocalUser() {
  try {
    return JSON.parse(localStorage.getItem("contrack_user") || "null");
  } catch (_) {
    return null;
  }
}

async function readSessionUser() {
  try {
    const response = await fetch("/api/session.php", { method: "GET" });
    if (!response.ok) return null;
    const payload = await response.json().catch(() => null);
    return payload?.user || null;
  } catch (_) {
    return null;
  }
}

function initials(name = "") {
  const parts = String(name).trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return "U";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

function svg(content, width = 16, height = 16) {
  return `<svg width="${width}" height="${height}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${content}</svg>`;
}

const ICON_USER  = svg('<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>');
const ICON_GEAR  = svg('<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>');
const ICON_OUT   = svg('<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>');
const ICON_CHEV  = '<polyline points="6 9 12 15 18 9"/>';

async function logout() {
  try {
    await fetch("/api/session.php", { method: "DELETE" });
  } catch (_) {
    /* best effort */
  }
  localStorage.removeItem("contrack_user");
  window.location.href = "/index.html";
}

function buildUserBox(user, role) {
  const wrap = document.createElement("div");
  wrap.className = "top-user";
  wrap.setAttribute("role", "button");
  wrap.setAttribute("tabindex", "0");
  wrap.setAttribute("aria-haspopup", "menu");
  wrap.setAttribute("aria-expanded", "false");

  const profileHref = PROFILE_ROUTES[role] || "#";
  const settingsHref = SETTINGS_ROUTES[role] || "#";
  const displayName = user?.name?.trim() || "User";
  const displayEmail = user?.email?.trim() || "";
  const displayRole = ROLE_LABEL[role] || "User";
  const avatarText = initials(displayName);

  wrap.innerHTML = `
    <div class="top-user-avatar" data-shell-initials>${avatarText}</div>
    <div class="top-user-meta">
      <div class="top-user-name" data-shell-name>${displayName}</div>
      <div class="top-user-role" data-shell-role>${displayRole}</div>
    </div>
    <svg class="top-user-chevron" viewBox="0 0 24 24">${ICON_CHEV}</svg>
    <div class="top-user-dropdown" role="menu">
      <div class="top-user-dropdown-header">
        <div class="top-user-dropdown-name" data-shell-name>${displayName}</div>
        <div class="top-user-dropdown-email" data-shell-email>${displayEmail || "&nbsp;"}</div>
      </div>
      <a href="${profileHref}" data-shell-action="profile">${ICON_USER}<span>My Profile</span></a>
      <a href="${settingsHref}" data-shell-action="settings">${ICON_GEAR}<span>Settings</span></a>
      <div class="dd-divider"></div>
      <button type="button" class="dd-signout" data-shell-action="signout">${ICON_OUT}<span>Sign out</span></button>
    </div>
  `;

  const dropdown = wrap.querySelector(".top-user-dropdown");

  function open() {
    wrap.classList.add("is-open");
    dropdown.classList.add("is-open");
    wrap.setAttribute("aria-expanded", "true");
  }
  function close() {
    wrap.classList.remove("is-open");
    dropdown.classList.remove("is-open");
    wrap.setAttribute("aria-expanded", "false");
  }
  function toggle() {
    if (wrap.classList.contains("is-open")) close(); else open();
  }

  wrap.addEventListener("click", (event) => {
    if (event.target.closest(".top-user-dropdown")) return;
    event.preventDefault();
    toggle();
  });

  wrap.addEventListener("keydown", (event) => {
    if (event.key === "Enter" || event.key === " ") {
      event.preventDefault();
      toggle();
    } else if (event.key === "Escape") {
      close();
    }
  });

  dropdown.querySelector('[data-shell-action="signout"]').addEventListener("click", (event) => {
    event.preventDefault();
    close();
    logout();
  });

  document.addEventListener("click", (event) => {
    if (!wrap.contains(event.target)) close();
  });

  return wrap;
}

function storedAvatar(user) {
  const id = user?.id;
  if (!id) return "";
  try {
    return localStorage.getItem(`contrack_avatar_${id}`) || "";
  } catch (_) {
    return "";
  }
}

function paintAvatar(el, text, src) {
  if (!el) return;
  if (src) {
    el.classList.add("has-photo");
    el.style.backgroundImage = `url("${src.replace(/"/g, "")}")`;
    el.textContent = "";
    return;
  }
  el.classList.remove("has-photo");
  el.style.backgroundImage = "";
  el.textContent = text;
}

function applyIdentity(user, role) {
  const company = String(user?.company_name || "").trim();
  const displayName = (role === "vendor" && company) || user?.name?.trim() || company || "User";
  const displayEmail = user?.email?.trim() || "";
  const avatarText = initials(displayName);
  const displayRole = ROLE_LABEL[role] || "User";
  const photo = storedAvatar(user);

  document.querySelectorAll("[data-shell-name]").forEach((el) => { el.textContent = displayName; });
  document.querySelectorAll("[data-shell-email]").forEach((el) => { el.textContent = displayEmail; });
  document.querySelectorAll("[data-shell-role]").forEach((el) => { el.textContent = displayRole; });

  document.querySelectorAll("[data-shell-initials], .sb-av, .profile-big-av, .profile-avatar, .top-user-avatar").forEach((el) => {
    paintAvatar(el, avatarText, photo);
  });

  document.querySelectorAll(".sb-name").forEach((el) => { el.textContent = displayName; });
  document.querySelectorAll(".sb-email").forEach((el) => { el.textContent = displayEmail; });

  // Profile pages often have these specific id placeholders.
  const idMap = { "disp-name": displayName, "disp-email": displayEmail, "inp-email": displayEmail };
  Object.entries(idMap).forEach(([id, value]) => {
    const el = document.getElementById(id);
    if (!el) return;
    if (el.tagName === "INPUT" || el.tagName === "TEXTAREA") {
      if (!el.value) el.value = value;
    } else {
      el.textContent = value;
    }
  });

  // Split first/last names for profile inputs that ask for them.
  const fnameInput = document.getElementById("inp-fname");
  const lnameInput = document.getElementById("inp-lname");
  if (fnameInput && !fnameInput.value) {
    const parts = displayName.split(/\s+/);
    fnameInput.value = parts.shift() || "";
    if (lnameInput && !lnameInput.value) lnameInput.value = parts.join(" ");
  }

  if (role === "vendor" && /\/pages\/vendorclient\/vendordashboard\.html$/i.test(window.location.pathname)) {
    const title = document.querySelector(".page-title");
    if (title) title.textContent = `Welcome, ${displayName}!`;
  }
}

function brandMarkup(role) {
  return `
      <img class="sb-brand-logo" src="${LOGO_SRC}" alt="ConTrack AI logo" width="38" height="38" decoding="sync" fetchpriority="high">
      <div class="sb-brand-copy">
        <div class="sb-logo-text">Con<span>Track</span> AI</div>
        <div class="sb-logo-sub">${PORTAL_SUBTITLE[role] || "PORTAL"}</div>
      </div>
    `;
}

function ensureSidebarBrand(role) {
  document.querySelectorAll(".sidebar .sb-logo-area").forEach((brand) => {
    const logo = brand.querySelector(".sb-brand-logo");
    const sub = brand.querySelector(".sb-logo-sub");
    const title = brand.querySelector(".sb-logo-text");
    if (logo && sub && title) {
      if (logo.getAttribute("src") !== LOGO_SRC) logo.src = LOGO_SRC;
      sub.textContent = PORTAL_SUBTITLE[role] || "PORTAL";
      if (!title.querySelector("span")) title.innerHTML = `Con<span>Track</span> AI`;
      return;
    }
    brand.innerHTML = brandMarkup(role);
  });
}

function applySidebarState(collapsed) {
  document.body.classList.toggle("shell-sidebar-collapsed", collapsed);
  document.querySelectorAll(".shell-sidebar-toggle").forEach((button) => {
    button.setAttribute("aria-expanded", String(!collapsed));
    button.setAttribute("title", collapsed ? "Open sidebar" : "Close sidebar");
  });
}

function wireSidebarToggle() {
  const savedState = localStorage.getItem("contrack_sidebar_collapsed") === "1";
  applySidebarState(savedState);

  document.querySelectorAll(".sidebar").forEach((sidebar) => {
    if (sidebar.querySelector(".shell-sidebar-toggle")) return;
    const toggleHost = sidebar.querySelector(".sb-bottom") || sidebar;
    const button = document.createElement("button");
    button.type = "button";
    button.className = "shell-sidebar-toggle";
    button.setAttribute("aria-label", "Toggle sidebar");
    button.innerHTML = svg('<line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/>', 18, 18);
    button.addEventListener("click", () => {
      const collapsed = !document.body.classList.contains("shell-sidebar-collapsed");
      localStorage.setItem("contrack_sidebar_collapsed", collapsed ? "1" : "0");
      applySidebarState(collapsed);
    });
    toggleHost.appendChild(button);
  });
  applySidebarState(savedState);
}

function highlightActiveNav() {
  const here = window.location.pathname.toLowerCase();
  const items = document.querySelectorAll(".sb-item");
  if (!items.length) return;

  let bestMatch = null;
  let bestLength = -1;
  items.forEach((item) => {
    const link = item.tagName === "A" ? item : item.closest("a");
    const href = (link?.getAttribute("href") || "").toLowerCase();
    if (!href || href.startsWith("#")) return;
    let path;
    try {
      path = new URL(href, window.location.origin).pathname.toLowerCase();
    } catch (_) {
      return;
    }
    if (here === path && path.length > bestLength) {
      bestMatch = item;
      bestLength = path.length;
    }
  });

  if (bestMatch) {
    items.forEach((it) => it.classList.remove("active"));
    bestMatch.classList.add("active");
  }
}

function wireSidebarLogout() {
  document.querySelectorAll(".sb-logout").forEach((el) => {
    if (el.dataset.shellWired === "1") return;
    el.dataset.shellWired = "1";
    if (el.tagName === "A") el.setAttribute("href", "#");
    el.addEventListener("click", (event) => {
      event.preventDefault();
      logout();
    });
  });
}

function ensureUserBox(user, role) {
  const topbar = document.querySelector(".shell .topbar, body > .topbar");
  if (!topbar) return;

  // Drop existing user box if a previous run inserted one.
  topbar.querySelectorAll(".top-user").forEach((node) => node.remove());

  const userBox = buildUserBox(user, role);
  topbar.appendChild(userBox);
}

function paintShellChrome(role, user) {
  ensureSidebarBrand(role);
  highlightActiveNav();
  wireSidebarToggle();
  if (!user) return;
  ensureUserBox(user, role);
  applyIdentity(user, role);
  wireSidebarLogout();
}

async function bootstrap() {
  const expectedRole = expectedRoleFromPath(window.location.pathname);
  let user = readLocalUser();
  const localRole = normalizeRole(user?.role) || expectedRole || "vendor";

  // Paint the sidebar brand immediately so navigation does not wait on session.
  paintShellChrome(localRole, user);

  const sessionUser = await readSessionUser();
  if (sessionUser) {
    user = sessionUser;
    localStorage.setItem("contrack_user", JSON.stringify(sessionUser));
  }

  if (!user) {
    if (expectedRole) {
      window.location.href = "/index.html";
    }
    return;
  }

  const role = normalizeRole(user.role);

  if (expectedRole && role !== expectedRole) {
    window.location.href = "/index.html";
    return;
  }

  paintShellChrome(role, user);

  // Heartbeat: updates last_seen_at server-side for defensible activity metrics (not faux concurrent users).
  const ping = () => {
    fetch("/api/session.php?heartbeat=1", { method: "GET", credentials: "same-origin" }).catch(() => {});
  };
  ping();
  setInterval(ping, 120000);
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", bootstrap);
} else {
  bootstrap();
}

export { bootstrap, logout };
