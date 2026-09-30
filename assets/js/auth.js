import { setApiContext } from "/assets/js/api-client.js";
import {
  handleAuthFailure,
  initLoginGuard,
  resetLoginGuard,
  restartLoginPage,
} from "/assets/js/login-guard.js";
import { ensureTermsAccepted, initTermsLinks } from "/assets/js/terms-modal.js";

const NOTICE_KEY = "contrack_notice";
const TERMS_DECLINED_NOTICE =
  "You must agree to the Terms & Conditions and Privacy Policy before signing in.";

const ROLE_TO_ROUTE = {
  admin: "/pages/Admin/AdminDashboard.html",
  manager: "/pages/Manager/ManagerDashboard.html",
  ceo: "/pages/CEO/CEO_Dashboard.html",
  vendor: "/pages/VendorClient/VendorDashboard.html",
  client: "/pages/VendorClient/VendorDashboard.html",
};

function normalizeRole(role) {
  const value = String(role || "").toLowerCase();
  if (value === "system_admin") return "admin";
  if (value === "vendor_client") return "vendor";
  return value || "vendor";
}

async function login(email, password) {
  const response = await fetch("/api/auth.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ email, password }),
  });

  const raw = await response.text();
  let result;
  try {
    result = JSON.parse(raw);
  } catch (error) {
    const fail = new Error("auth");
    fail.status = 0;
    throw fail;
  }
  if (!response.ok) {
    const error = new Error("auth");
    error.status = response.status;
    error.payload = result;
    throw error;
  }
  return result;
}

async function getSessionUser() {
  const response = await fetch("/api/session.php", { method: "GET" });
  if (!response.ok) return null;
  const raw = await response.text();
  let payload;
  try {
    payload = JSON.parse(raw);
  } catch (error) {
    return null;
  }
  return payload.user || null;
}

async function endSession() {
  try {
    await fetch("/api/session.php?action=logout", { method: "POST" });
  } catch (error) {
    /* the session cookie is dropped on the next login attempt anyway */
  }
  localStorage.removeItem("contrack_user");
}

function showNotice(message) {
  const el = document.getElementById("loginNotice");
  if (!el) return;
  el.textContent = message;
  el.hidden = !message;
}

document.addEventListener("DOMContentLoaded", () => {
  initTermsLinks();

  const form = document.getElementById("loginForm");
  const emailInput = document.getElementById("emailInput");
  const passwordInput = document.getElementById("passwordInput");
  if (!form || !emailInput || !passwordInput) return;

  initLoginGuard(form);

  const carried = sessionStorage.getItem(NOTICE_KEY);
  if (carried) {
    sessionStorage.removeItem(NOTICE_KEY);
    showNotice(carried);
  }

  getSessionUser().then(async (sessionUser) => {
    if (!sessionUser) return;
    if (!(await ensureTermsAccepted())) {
      await endSession();
      showNotice(TERMS_DECLINED_NOTICE);
      return;
    }
    const role = normalizeRole(sessionUser.role);
    localStorage.setItem(
      "contrack_user",
      JSON.stringify({
        ...sessionUser,
        company_name: sessionUser.company_name || "",
      }),
    );
    const targetRoute = ROLE_TO_ROUTE[role] || ROLE_TO_ROUTE.vendor;
    window.location.href = targetRoute;
  });

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    showNotice("");

    try {
      const payload = await login(emailInput.value.trim(), passwordInput.value);
      const role = normalizeRole(payload.user?.role);

      const userSession = {
        id: payload.user?.id ?? null,
        role,
        name: payload.user?.company_name || payload.user?.name || "",
        company_name: payload.user?.company_name || "",
        email: payload.user?.email ?? "",
      };
      resetLoginGuard();
      setApiContext({ role: userSession.role, userId: userSession.id });

      // Credentials are valid; the agreement is the last gate before the portal.
      if (!(await ensureTermsAccepted())) {
        await endSession();
        passwordInput.value = "";
        showNotice(TERMS_DECLINED_NOTICE);
        return;
      }

      localStorage.setItem("contrack_user", JSON.stringify(userSession));

      const targetRoute = ROLE_TO_ROUTE[role] || ROLE_TO_ROUTE.vendor;
      window.location.href = targetRoute;
    } catch (error) {
      const status = Number(error.status) || 0;
      if (status === 401 || status === 429) {
        handleAuthFailure(error.payload || {});
        return;
      }
      restartLoginPage();
    }
  });
});

