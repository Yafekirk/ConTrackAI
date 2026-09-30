import {
  handleAuthFailure,
  initLoginGuard,
  resetLoginGuard,
  restartLoginPage,
} from "/assets/js/login-guard.js";

const PRIVATE_ROLES = new Set(["admin", "manager", "ceo"]);
const ROLE_TO_ROUTE = {
  admin: "/pages/Admin/AdminDashboard.html",
  manager: "/pages/Manager/ManagerDashboard.html",
  ceo: "/pages/CEO/CEO_Dashboard.html",
};

function normalizeRole(role) {
  const value = String(role || "").toLowerCase();
  if (value === "system_admin") return "admin";
  return value;
}

async function login(email, password) {
  const response = await fetch("/api/auth.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ email, password }),
  });
  const raw = await response.text();
  let data = {};
  try {
    data = JSON.parse(raw);
  } catch (error) {
    throw new Error("Invalid server response.");
  }
  if (!response.ok) {
    const error = new Error("auth");
    error.status = response.status;
    error.payload = data;
    throw error;
  }
  return data;
}

document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("privateLoginForm");
  if (!form) return;

  initLoginGuard(form);

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const email = document.getElementById("privateEmail")?.value.trim() || "";
    const password = document.getElementById("privatePassword")?.value || "";
    try {
      const payload = await login(email, password);
      const role = normalizeRole(payload.user?.role);
      if (!PRIVATE_ROLES.has(role)) {
        await fetch("/api/session.php", { method: "DELETE" });
        restartLoginPage();
        return;
      }
      resetLoginGuard();
      localStorage.setItem("contrack_user", JSON.stringify(payload.user || {}));
      window.location.href = ROLE_TO_ROUTE[role];
    } catch (error) {
      const status = error.status || 0;
      if (status === 401 || status === 429) {
        handleAuthFailure(error.payload || {});
        return;
      }
      restartLoginPage();
    }
  });
});
