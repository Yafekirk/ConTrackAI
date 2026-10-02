import {
  setApiContext,
  getUsers,
  createUser,
  updateUser,
} from "/assets/js/api-client.js";
import { downloadCsv } from "/assets/js/contract-intel.js";

function esc(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function normalizeRoleDisplay(role) {
  const r = String(role || "").toLowerCase();
  if (r === "client") return "vendor";
  if (r === "system_admin") return "admin";
  return r;
}

function roleBadgeClass(role) {
  const r = normalizeRoleDisplay(role);
  if (r === "admin") return "role-admin";
  if (r === "manager") return "role-manager";
  if (r === "ceo") return "role-ceo";
  return "role-vendor";
}

function isCompanyEmployee(role) {
  const r = String(role || "").toLowerCase();
  return r === "admin" || r === "system_admin" || r === "manager" || r === "ceo";
}

function typeLabel(role) {
  return isCompanyEmployee(role) ? "Company employee" : "External vendor";
}

document.addEventListener("DOMContentLoaded", async () => {
  const saved = JSON.parse(localStorage.getItem("contrack_user") || "{}");
  setApiContext({ role: saved.role || "admin", userId: saved.id || null });

  const tableBody = document.getElementById("users-table-body");
  const meta = document.getElementById("users-meta");
  const stTotal = document.getElementById("um-stat-total");
  const stActive = document.getElementById("um-stat-active");
  const stAdmin = document.getElementById("um-stat-admin");
  const stPending = document.getElementById("um-stat-pending");

  let usersCache = [];
  document.getElementById("export-users-csv")?.addEventListener("click", () => {
    downloadCsv(
      "users.csv",
      ["ID", "Name", "Email", "Role", "Status", "Last Login"],
      usersCache.map((u) => [
        u.id,
        u.company_name || u.name || "",
        u.email || "",
        u.role || "",
        u.is_disabled ? "disabled" : "active",
        u.last_login_at || "",
      ]),
    );
  });

  async function load() {
    try {
      usersCache = await getUsers();
      if (!Array.isArray(usersCache)) usersCache = [];
    } catch (e) {
      console.error(e);
      usersCache = [];
      alert(e.message || "Failed to load users");
    }

    if (stTotal) stTotal.textContent = String(usersCache.length);
    const employees = usersCache.filter((u) => isCompanyEmployee(u.role)).length;
    if (stAdmin) stAdmin.textContent = String(employees);
    const disabled = usersCache.filter((u) => u.is_disabled).length;
    if (stActive) stActive.textContent = String(usersCache.length - disabled);
    if (stPending) {
      const pendingLogin = usersCache.filter((u) => !u.last_login_at).length;
      stPending.textContent = String(pendingLogin);
    }

    if (!tableBody) return;

    if (usersCache.length === 0) {
      tableBody.innerHTML = "";
      const empty = document.getElementById("users-empty");
      if (empty) empty.style.display = "block";
      if (meta) meta.textContent = "0 users";
      return;
    }

    const empty = document.getElementById("users-empty");
    if (empty) empty.style.display = "none";

    const q = (document.getElementById("um-search")?.value || "").trim().toLowerCase();
    const roleFilter = document.getElementById("um-role-filter")?.value || "";
    const visible = usersCache.filter((u) => {
      const role = normalizeRoleDisplay(u.role);
      if (roleFilter === "employees" && !isCompanyEmployee(u.role)) return false;
      if (roleFilter && roleFilter !== "employees" && role !== roleFilter) return false;
      if (!q) return true;
      const blob = `${u.name || ""} ${u.email || ""}`.toLowerCase();
      return blob.includes(q);
    });

    tableBody.innerHTML = visible
      .map((u) => {
        const rDisp = normalizeRoleDisplay(u.role);
        const statusLabel = u.is_disabled ? "DISABLED" : "ACTIVE";
        const statusClass = u.is_disabled ? "pill pill-inactive" : "pill pill-active";
        const ll = u.last_login_at
          ? new Date(u.last_login_at).toLocaleString()
          : "—";
        return `
      <div class="t-row" style="grid-template-columns:2fr 2fr 100px 120px 140px 120px;">
        <div class="user-avatar-cell">
          <div class="user-avatar-mini ${roleBadgeClass(u.role)}">${esc((u.name || "?").slice(0, 2).toUpperCase())}</div>
          <div>
            <div class="t-cell-primary">${esc(u.name)}</div>
            <div class="t-cell-meta">Login: ${esc(ll)}</div>
          </div>
        </div>
        <div class="t-cell-secondary">${esc(u.email)}</div>
        <div><span class="role-badge ${roleBadgeClass(u.role)}">${esc(rDisp)}</span></div>
        <div class="t-cell-secondary">${esc(typeLabel(u.role))}</div>
        <div><span class="${statusClass}">${statusLabel}</span></div>
        <div class="t-actions">
          <button type="button" class="t-action-btn" title="Edit" data-edit="${u.id}">✎</button>
        </div>
      </div>`;
      })
      .join("");

    if (meta) meta.textContent = `${visible.length} of ${usersCache.length} users`;

    tableBody.querySelectorAll("[data-edit]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = Number(btn.getAttribute("data-edit"));
        const u = usersCache.find((x) => Number(x.id) === id);
        if (!u) return;
        document.getElementById("edit-user-id").value = String(id);
        const parts = String(u.name || "").trim().split(/\s+/);
        document.getElementById("inp-edit-fname").value = parts.shift() || "";
        document.getElementById("inp-edit-lname").value = parts.join(" ") || "";
        document.getElementById("inp-edit-email").value = u.email || "";
        let ur = normalizeRoleDisplay(u.role);
        if (ur === "client") ur = "vendor";
        document.getElementById("sel-edit-role").value = ur;
        document.getElementById("sel-edit-status").value = u.is_disabled ? "disabled" : "active";
        globalThis.openModal?.("modal-edit-user");
      });
    });
  }

  await load();

  document.getElementById("um-search")?.addEventListener("input", () => {
    load();
  });
  document.getElementById("um-role-filter")?.addEventListener("change", () => {
    load();
  });

  document.getElementById("btn-create-user")?.addEventListener("click", async () => {
    const fname = document.getElementById("inp-add-fname")?.value.trim() || "";
    const lname = document.getElementById("inp-add-lname")?.value.trim() || "";
    const email = document.getElementById("inp-add-email")?.value.trim() || "";
    const role = document.getElementById("sel-add-role")?.value || "admin";
    const pw = document.getElementById("inp-add-pass")?.value || "";
    const pw2 = document.getElementById("inp-add-pass2")?.value || "";
    if (pw !== pw2) {
      alert("Passwords do not match");
      return;
    }
    try {
      await createUser({
        name: `${fname} ${lname}`.trim(),
        email,
        password: pw,
        role,
      });
      globalThis.closeModal?.("modal-add-user");
      await load();
    } catch (e) {
      alert(e.message || "Create failed");
    }
  });

  document.getElementById("btn-save-user")?.addEventListener("click", async () => {
    const id = Number(document.getElementById("edit-user-id")?.value);
    const fname = document.getElementById("inp-edit-fname")?.value.trim() || "";
    const lname = document.getElementById("inp-edit-lname")?.value.trim() || "";
    const role = document.getElementById("sel-edit-role")?.value || "vendor";
    const status = document.getElementById("sel-edit-status")?.value || "active";
    try {
      await updateUser({
        id,
        name: `${fname} ${lname}`.trim(),
        role,
        is_disabled: status === "disabled",
      });
      globalThis.closeModal?.("modal-edit-user");
      await load();
    } catch (e) {
      alert(e.message || "Update failed");
    }
  });

});
