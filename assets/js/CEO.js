// CEO portal notifications — derived from pending approvals (real API data only).

let notifications = [];
let activeTab = "all";

function apiHeaders() {
  try {
    const u = JSON.parse(localStorage.getItem("contrack_user") || "{}");
    return {
      "Content-Type": "application/json",
      "X-User-Role": u.role || "ceo",
      ...(u.id ? { "X-User-Id": String(u.id) } : {}),
    };
  } catch (_) {
    return { "Content-Type": "application/json", "X-User-Role": "ceo" };
  }
}

function esc(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function updateApprovalsSidebarBadges(pendingCount) {
  const n = Number(pendingCount) || 0;
  document.querySelectorAll('a[href*="CEO_Approvals.html"] .sb-badge').forEach((el) => {
    el.textContent = String(n);
    el.style.display = n > 0 ? "inline-flex" : "none";
  });
}

async function fetchJson(url) {
  const res = await fetch(url, { credentials: "same-origin", headers: apiHeaders() });
  if (!res.ok) return [];
  const data = await res.json();
  return Array.isArray(data) ? data : [];
}

async function refreshNotificationsFromApi() {
  try {
    // allSettled: one failing endpoint must not empty the whole drawer.
    const [pendingRes, renewalRes, listRes, alertsRes] = await Promise.allSettled([
      fetchJson("/api/contracts.php?view=pending_approvals"),
      fetchJson("/api/contracts.php?view=renewals"),
      fetchJson("/api/contracts.php?view=list"),
      fetchJson("/api/alerts.php?limit=40"),
    ]);
    const pending = pendingRes.status === "fulfilled" ? pendingRes.value : [];
    const renewals = renewalRes.status === "fulfilled" ? renewalRes.value : [];
    const all = listRes.status === "fulfilled" ? listRes.value : [];
    const alerts = alertsRes.status === "fulfilled" ? alertsRes.value : [];
    const pendingRows = Array.isArray(pending) ? pending : [];
    updateApprovalsSidebarBadges(pendingRows.length);
    const alertNotes = (Array.isArray(alerts) ? alerts : []).map((row) => ({
      id: `alert-${row.id}`,
      alertId: row.id,
      type: /reject|terminated/i.test(String(row.title || ""))
        ? "error"
        : /approved|decision/i.test(String(row.title || ""))
          ? "success"
          : "info",
      title: row.title || "Alert",
      desc: row.message || "",
      time: row.created_at ? new Date(row.created_at).toLocaleString() : "",
      unread: String(row.status || "").toLowerCase() !== "read",
      archived: false,
    }));
    const renewalNotes = Array.isArray(renewals)
      ? renewals.map((row) => ({
          id: `renewal-${row.id}`,
          type: "warning",
          title: row.title || "Renewal notice",
          desc: row.desc || "Contract is subject for renewal.",
          time: row.time || "",
          unread: true,
          archived: false,
        }))
      : [];
    const pendingNotes = pendingRows.slice(0, 20).map((row) => ({
      id: `pending-${row.id}`,
      type: "warning",
      title: `Pending: ${row.contract_title || "Contract"}`,
      desc: `${row.vendor_name || "Vendor"} · ${row.contract_type || "—"}`,
      time: row.uploaded_at ? new Date(row.uploaded_at).toLocaleString() : "",
      unread: true,
      archived: false,
    }));
    const archivedNotes = (Array.isArray(all) ? all : [])
      .filter((r) => r.is_archived || String(r.monitor_status) === "expired" || String(r.status).toLowerCase() === "terminated")
      .slice(0, 15)
      .map((row) => ({
        id: `arch-${row.id}`,
        type: "info",
        title: `Archived: ${row.contract_title || "Contract"}`,
        desc: `Inactive contract stored in archive (${String(row.monitor_status || row.status || "").toUpperCase()}).`,
        time: row.end_date || row.uploaded_at || "",
        unread: false,
        archived: true,
      }));
    window.__ceoArchivedNotifs = archivedNotes;
    notifications = [...alertNotes, ...renewalNotes, ...pendingNotes];
  } catch (_) {
    notifications = [];
    updateApprovalsSidebarBadges(0);
    window.__ceoArchivedNotifs = [];
  }
}

const iconPaths = {
  success: '<polyline points="20 6 9 17 4 12"/>',
  warning:
    '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
  error: '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
  info: '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
};

function toggleNotifications() {
  const s = document.getElementById("notif-sidebar");
  const o = document.getElementById("notif-overlay");
  if (!s || !o) return;
  if (s.classList.contains("open")) {
    s.classList.remove("open");
    o.classList.remove("open");
  } else {
    s.classList.add("open");
    o.classList.add("open");
    renderNotifications();
  }
}

function closeNotifications() {
  const s = document.getElementById("notif-sidebar");
  const o = document.getElementById("notif-overlay");
  if (s) s.classList.remove("open");
  if (o) o.classList.remove("open");
}

function switchNotifTab(el, tab) {
  document.querySelectorAll(".notif-tab").forEach((t) => t.classList.remove("active"));
  if (el) el.classList.add("active");
  activeTab = tab;
  renderNotifications();
}

function renderNotifications() {
  const el = document.getElementById("notif-content");
  if (!el) return;
  let list = notifications;
  if (activeTab === "unread") list = list.filter((n) => n.unread);
  else if (activeTab === "archived") list = window.__ceoArchivedNotifs || [];
  if (!list.length) {
    el.innerHTML = `<div class="notif-empty"><svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg><div class="notif-empty-title">No notifications</div><div class="notif-empty-desc">Pending approvals will appear here.</div></div>`;
    return;
  }
  el.innerHTML = list
    .map(
      (n) => `
    <div class="notif-item ${n.unread ? "unread" : ""}" data-nid="${esc(n.id)}">
      <div class="notif-icon-wrap ${esc(n.type)}"><svg viewBox="0 0 24 24">${iconPaths[n.type] || iconPaths.info}</svg></div>
      <div class="notif-body">
        <div class="notif-title">${n.unread ? '<span class="notif-unread-dot"></span>' : ""}${esc(n.title)}</div>
        <div class="notif-desc">${esc(n.desc)}</div>
        <div class="notif-time">${esc(n.time)}</div>
      </div>
    </div>`,
    )
    .join("");
  el.querySelectorAll("[data-nid]").forEach((node) => {
    node.addEventListener("click", () => readNotif(node.getAttribute("data-nid")));
  });
}

function patchAlerts(body) {
  fetch("/api/alerts.php", {
    method: "PATCH",
    credentials: "same-origin",
    headers: apiHeaders(),
    body: JSON.stringify(body),
  }).catch(() => {});
}

function readNotif(id) {
  const n = notifications.find((x) => String(x.id) === String(id));
  if (!n || !n.unread) return;
  n.unread = false;
  if (n.alertId) patchAlerts({ id: n.alertId, status: "read" });
  updateBadge();
  renderNotifications();
}

function markAllRead() {
  const hadStoredAlerts = notifications.some((n) => n.unread && n.alertId);
  notifications.forEach((n) => {
    n.unread = false;
  });
  if (hadStoredAlerts) patchAlerts({ mark_all: true, status: "read" });
  updateBadge();
  renderNotifications();
}

function updateBadge() {
  const count = notifications.filter((n) => n.unread).length;
  const b = document.getElementById("notif-badge");
  if (b) {
    b.textContent = String(count);
    b.style.display = count ? "flex" : "none";
  }
}

function openModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.add("open");
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.remove("open");
}

document.addEventListener("DOMContentLoaded", async () => {
  await refreshNotificationsFromApi();
  updateBadge();
  renderNotifications();
});
