import { setApiContext } from "/assets/js/api-client.js";
import {
  handleAuthFailure,
  initLoginGuard,
  resetLoginGuard,
  restartLoginPage,
} from "/assets/js/login-guard.js";
import { ensureTermsAccepted, initTermsLinks } from "/assets/js/terms-modal.js";

const NOTICE_KEY = "contrack_notice";
const SIGNUP_PENDING_KEY = "contrack_signup_pending";
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

/** Popup in the same style as the login guard dialogs, closed with an OK button. */
function showApprovalDialog(title, message) {
  let overlay = document.getElementById("approvalDialog");
  if (!overlay) {
    overlay = document.createElement("div");
    overlay.id = "approvalDialog";
    overlay.className = "login-guard-overlay";
    overlay.innerHTML = `
      <div class="login-guard-dialog" role="dialog" aria-modal="true" aria-labelledby="approvalDialogTitle">
        <h3 id="approvalDialogTitle"></h3>
        <p id="approvalDialogMessage"></p>
        <div class="login-guard-actions">
          <button type="button" id="approvalDialogOk" class="login-guard-btn login-guard-btn-primary">OK</button>
        </div>
      </div>
    `;
    document.body.append(overlay);
    overlay.querySelector("#approvalDialogOk").addEventListener("click", () => {
      overlay.hidden = true;
    });
  }
  overlay.querySelector("#approvalDialogTitle").textContent = title;
  overlay.querySelector("#approvalDialogMessage").textContent = message;
  overlay.hidden = false;
  overlay.querySelector("#approvalDialogOk").focus();
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

  if (sessionStorage.getItem(SIGNUP_PENDING_KEY)) {
    sessionStorage.removeItem(SIGNUP_PENDING_KEY);
    showApprovalDialog(
      "Waiting for approval",
      "Your account was created and is waiting for CEO approval. You will receive an email once it is approved, and then you can sign in.",
    );
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

  // A second sign-in replaces the session the first one just created and can leave the
  // browser holding neither, so ignore submits while one is in flight.
  let signingIn = false;
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (signingIn) return;
    signingIn = true;
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
        signingIn = false;
        return;
      }

      localStorage.setItem("contrack_user", JSON.stringify(userSession));

      const targetRoute = ROLE_TO_ROUTE[role] || ROLE_TO_ROUTE.vendor;
      window.location.href = targetRoute;
    } catch (error) {
      signingIn = false;
      const status = Number(error.status) || 0;
      if (status === 403 && error.payload?.approval_status) {
        passwordInput.value = "";
        const rejected = error.payload.approval_status === "rejected";
        showApprovalDialog(
          rejected ? "Account not approved" : "Account not yet approved",
          rejected
            ? "Your account request was not approved by the CEO, so you cannot sign in. Please contact the company for details."
            : "You cannot sign in yet because your account is still waiting for CEO approval. You will receive an email once it is approved.",
        );
        return;
      }
      if (status === 401 || status === 429) {
        handleAuthFailure(error.payload || {});
        return;
      }
      restartLoginPage();
    }
  });
});

