/* Admin portal — notifications from stored alerts + audit_logs (no hardcoded demo lists). */

let currentTab = "all";
let notifications = [];
let archivedNotifications = [];

function esc(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function toggleNotifications() {
  const sidebar = document.getElementById("notif-sidebar");
  const overlay = document.getElementById("notif-overlay");
  if (!sidebar || !overlay) return;
  if (sidebar.classList.contains("open")) {
    closeNotifications();
    return;
  }
  sidebar.classList.add("open");
  overlay.classList.add("open");
  void refreshNotifications().then(() => {
    updateBadge();
    renderNotifications(currentTab);
  });
}

function closeNotifications() {
  const sidebar = document.getElementById("notif-sidebar");
  const overlay = document.getElementById("notif-overlay");
  if (sidebar) sidebar.classList.remove("open");
  if (overlay) overlay.classList.remove("open");
}

function switchNotifTab(tab) {
  currentTab = tab;
  document.querySelectorAll(".notif-tab").forEach((t) => t.classList.remove("active"));
  const active = document.querySelector(`[data-tab="${tab}"]`);
  if (active) active.classList.add("active");
  renderNotifications(tab);
}

function alertToNotif(row) {
  const title = String(row?.title || "Alert");
  let type = "info";
  if (/reject|fail|denied|terminated/i.test(title)) type = "error";
  else if (/approved|submitted|decision/i.test(title)) type = "success";
  else if (/review|renewal|waiting|pending/i.test(title)) type = "warning";
  return {
    id: `alert-${row?.id}`,
    alertId: row?.id,
    type,
    title,
    desc: String(row?.message || ""),
    time: row?.created_at ? new Date(row.created_at).toLocaleString() : "",
    unread: String(row?.status || "").toLowerCase() !== "read",
  };
}

function auditToNotif(row) {
  const type = String(row?.event_type || "info");
  let uiType = "info";
  if (type.includes("fail") || type.includes("denied")) uiType = "error";
  else if (type.includes("warning")) uiType = "warning";
  else if (type === "login_success" || type === "contract_submitted") uiType = "success";

  const details = row?.details || {};
  return {
    id: `audit-${row?.id}`,
    type: uiType,
    title: type.replace(/_/g, " "),
    desc: typeof details === "object" ? JSON.stringify(details).slice(0, 180) : String(details),
    time: row?.created_at ? new Date(row.created_at).toLocaleString() : "",
    unread: false,
  };
}

async function fetchJson(url) {
  const res = await fetch(url, { credentials: "same-origin" });
  if (!res.ok) return [];
  const data = await res.json();
  return Array.isArray(data) ? data : [];
}

async function refreshNotifications() {
  // allSettled: a single failing endpoint must not blank the whole drawer.
  const [alertsRes, auditRes, listRes] = await Promise.allSettled([
    fetchJson("/api/alerts.php?limit=40"),
    fetchJson("/api/audit.php?limit=40"),
    fetchJson("/api/contracts.php?view=list"),
  ]);

  const alerts = alertsRes.status === "fulfilled" ? alertsRes.value : [];
  const audit = auditRes.status === "fulfilled" ? auditRes.value : [];
  const contracts = listRes.status === "fulfilled" ? listRes.value : [];

  notifications = [...alerts.map(alertToNotif), ...audit.map(auditToNotif)];

  archivedNotifications = contracts
    .filter(
      (r) =>
        r.is_archived ||
        String(r.monitor_status) === "expired" ||
        ["terminated", "rejected"].includes(String(r.status || "").toLowerCase()),
    )
    .slice(0, 20)
    .map((row) => ({
      id: `arch-${row.id}`,
      type: "info",
      title: `Archived: ${row.contract_title || "Contract"}`,
      desc: `${row.vendor_name || "Vendor"} — inactive contract.`,
      time: row.end_date || "",
      unread: false,
    }));
}

const icons = {
  success: `<svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>`,
  warning: `<svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`,
  error: `<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>`,
  info: `<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`,
};

function renderNotifications(tab) {
  const list = document.getElementById("notif-list");
  if (!list) return;

  let items = notifications;
  if (tab === "unread") items = notifications.filter((n) => n.unread);
  if (tab === "archived") items = archivedNotifications;

  if (items.length === 0) {
    const desc =
      tab === "archived"
        ? "Expired, rejected, and terminated contracts appear here."
        : "Alerts and audit events will appear here as the system records them.";
    list.innerHTML = `
      <div class="notif-empty">
        <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <div class="notif-empty-title">No notifications</div>
        <div class="notif-empty-desc">${desc}</div>
      </div>`;
    return;
  }

  list.innerHTML = items
    .map(
      (n) => `
    <div class="notif-item${n.unread ? " unread" : ""}" data-nid="${esc(n.id)}">
      <div class="notif-icon-wrap ${esc(n.type)}">${icons[n.type] || icons.info}</div>
      <div class="notif-body">
        <div class="notif-item-title">${esc(n.title)}</div>
        <div class="notif-desc">${esc(n.desc)}</div>
        <div class="notif-time">${esc(n.time)}</div>
      </div>
    </div>`,
    )
    .join("");

  list.querySelectorAll("[data-nid]").forEach((el) => {
    el.addEventListener("click", () => markRead(el.getAttribute("data-nid")));
  });
}

function markRead(id) {
  const n = notifications.find((x) => String(x.id) === String(id));
  if (!n || !n.unread) return;
  n.unread = false;
  if (n.alertId) {
    fetch("/api/alerts.php", {
      method: "PATCH",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: n.alertId, status: "read" }),
    }).catch(() => {});
  }
  updateBadge();
  renderNotifications(currentTab);
}

function markAllRead() {
  const hadUnread = notifications.some((n) => n.unread && n.alertId);
  notifications.forEach((n) => {
    n.unread = false;
  });
  if (hadUnread) {
    fetch("/api/alerts.php", {
      method: "PATCH",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ mark_all: true, status: "read" }),
    }).catch(() => {});
  }
  updateBadge();
  renderNotifications(currentTab);
}

function updateBadge() {
  const count = notifications.filter((n) => n.unread).length;
  const badge = document.getElementById("notif-badge");
  if (badge) {
    badge.textContent = String(count);
    badge.style.display = count > 0 ? "flex" : "none";
  }
}

function setActiveSidebarItem(id) {
  document.querySelectorAll(".sb-item").forEach((el) => el.classList.remove("active"));
  const el = document.getElementById(id);
  if (el) el.classList.add("active");
}

function openModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.add("open");
}

function closeModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.remove("open");
}

document.addEventListener("DOMContentLoaded", async () => {
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape") closeNotifications();
  });
  await refreshNotifications();
  updateBadge();
});
