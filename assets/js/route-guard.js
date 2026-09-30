const ROLE_ROOT_BY_PATH = {
  Admin: "admin",
  Manager: "manager",
  CEO: "ceo",
  VendorClient: "vendor",
};

function normalizeRole(role) {
  const value = String(role || "").toLowerCase();
  if (value === "system_admin") return "admin";
  if (value === "vendor_client" || value === "client") return "vendor";
  return value || "vendor";
}

function expectedRoleFromPath(pathname) {
  const match = pathname.match(/\/pages\/([^/]+)\//i);
  if (!match) return null;
  const folder = match[1];
  return ROLE_ROOT_BY_PATH[folder] || null;
}

async function getSessionUser() {
  const response = await fetch("/api/session.php", { method: "GET" });
  if (!response.ok) return null;
  const payload = await response.json();
  return payload.user || null;
}

function redirectToLogin() {
  window.location.href = "/index.html";
}

document.addEventListener("DOMContentLoaded", async () => {
  document.querySelectorAll(".sb-logout").forEach((element) => {
    element.addEventListener("click", async (event) => {
      event.preventDefault();
      try {
        await fetch("/api/session.php", { method: "DELETE" });
      } catch (error) {
        console.error("Logout request failed:", error);
      }
      localStorage.removeItem("contrack_user");
      redirectToLogin();
    });
  });

  const expectedRole = expectedRoleFromPath(window.location.pathname);
  if (!expectedRole) return;

  // The server session is the only source of truth — a leftover localStorage entry would
  // render the portal while every API call answers 401.
  const sessionUser = await getSessionUser();
  if (!sessionUser) {
    localStorage.removeItem("contrack_user");
    redirectToLogin();
    return;
  }

  const activeRole = normalizeRole(sessionUser.role);
  if (activeRole !== expectedRole) {
    redirectToLogin();
    return;
  }

  localStorage.setItem("contrack_user", JSON.stringify(sessionUser));
});

