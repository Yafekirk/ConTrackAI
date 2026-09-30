/**
 * Vendor portal — notification drawer: status updates + renewal notices + archived contracts.
 */
import {
  setApiContext,
  getContracts,
  getRenewalNotices,
  getAlerts,
  markAlertRead,
  markAllAlertsRead,
} from "/assets/js/api-client.js";
import { applyVendorIdentity } from "/assets/js/vendor-session.js";
import { isArchivedContract } from "/assets/js/contract-intel.js";

let vendorNotifData = [];
let vendorArchivedData = [];
let vendorNotifActiveFilter = "all";

function esc(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function getNotifIcon(type) {
  const icons = {
    success: '<polyline points="20 6 9 17 4 12"/>',
    warning:
      '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    error:
      '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',
    info:
      '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',
  };
  return icons[type] || icons.info;
}

function mapContractsToNotifications(rows) {
  if (!Array.isArray(rows)) return [];
  return rows.slice(0, 25).map((r) => {
    const st = String(r.status || "").toLowerCase();
    let type = "info";
    if (st === "pending") type = "warning";
    if (st === "approved") type = "success";
    if (st === "rejected") type = "error";
    return {
      id: r.id,
      type,
      title: r.contract_title || "Contract",
      desc: `Status: ${String(r.monitor_status || st).toUpperCase()}`,
      time: r.uploaded_at ? new Date(r.uploaded_at).toLocaleString() : "",
      unread: st === "pending",
      archived: isArchivedContract(r),
    };
  });
}

async function refreshVendorNotifications() {
  const user = applyVendorIdentity();
  if (!user?.id) {
    vendorNotifData = [];
    vendorArchivedData = [];
    return;
  }
  setApiContext({ role: "vendor", userId: user.id });
  try {
    // allSettled: a failing renewals or alerts call must not blank the drawer.
    const settled = await Promise.allSettled([
      getContracts(),
      getRenewalNotices(),
      getAlerts(40),
    ]);
    const [rows, renewals, alerts] = settled.map((r) =>
      r.status === "fulfilled" && Array.isArray(r.value) ? r.value : [],
    );
    const statusNotes = mapContractsToNotifications(rows);
    const renewalNotes = Array.isArray(renewals)
      ? renewals.map((n) => ({
          id: `renewal-${n.id}`,
          type: n.type || "warning",
          title: n.title || "Renewal notice",
          desc: n.desc || "Contract is subject for renewal.",
          time: n.time ? String(n.time) : "",
          unread: true,
          archived: false,
        }))
      : [];
    const alertNotes = (Array.isArray(alerts) ? alerts : []).map((a) => ({
      id: `alert-${a.id}`,
      alertId: a.id,
      type: /approved/i.test(String(a.title || ""))
        ? "success"
        : /reject/i.test(String(a.title || ""))
          ? "error"
          : "info",
      title: a.title || "Update",
      desc: a.message || "",
      time: a.created_at ? new Date(a.created_at).toLocaleString() : "",
      unread: String(a.status || "").toLowerCase() !== "read",
      archived: false,
    }));
    vendorNotifData = [...alertNotes, ...renewalNotes, ...statusNotes.filter((n) => !n.archived)];
    vendorArchivedData = statusNotes.filter((n) => n.archived);
  } catch (e) {
    console.error(e);
    vendorNotifData = [];
    vendorArchivedData = [];
  }
}

function renderVendorNotifications(filter) {
  if (filter !== undefined && filter !== null) {
    vendorNotifActiveFilter = filter;
  }
  const content = document.getElementById("notif-content");
  if (!content) return;

  let list = [...vendorNotifData];
  if (vendorNotifActiveFilter === "unread") list = list.filter((n) => n.unread);
  if (vendorNotifActiveFilter === "archived") list = [...vendorArchivedData];

  if (!list.length) {
    content.innerHTML = `
      <div class="notif-empty">
        <svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <div class="notif-empty-title">No notifications</div>
        <div class="notif-empty-desc">${vendorNotifActiveFilter === "archived" ? "Archived inactive contracts appear here." : "You're all caught up!"}</div>
      </div>`;
    return;
  }

  content.innerHTML = list
    .map(
      (n) => `
    <div class="notif-item ${n.unread ? "unread" : ""}" data-nid="${esc(n.id)}">
      <div class="notif-icon-wrap ${esc(n.type)}">
        <svg viewBox="0 0 24 24">${getNotifIcon(n.type)}</svg>
      </div>
      <div class="notif-body">
        <div class="notif-title">
          ${n.unread ? '<span class="notif-unread-dot"></span>' : ""}
          ${esc(n.title)}
        </div>
        <div class="notif-desc">${esc(n.desc)}</div>
        <div class="notif-time">${esc(n.time)}</div>
      </div>
    </div>`,
    )
    .join("");

  content.querySelectorAll("[data-nid]").forEach((el) => {
    el.addEventListener("click", () => globalThis.markRead(el.getAttribute("data-nid")));
  });
}

function updateVendorBadge() {
  const count = vendorNotifData.filter((n) => n.unread).length;
  const badge = document.getElementById("notif-badge");
  if (badge) {
    badge.textContent = count;
    badge.style.display = count > 0 ? "flex" : "none";
  }
}

globalThis.toggleNotifications = function toggleNotifications() {
  const sidebar = document.getElementById("notif-sidebar");
  const overlay = document.getElementById("notif-overlay");
  if (!sidebar || !overlay) return;
  if (sidebar.classList.contains("open")) {
    globalThis.closeNotifications();
    return;
  }
  sidebar.classList.add("open");
  overlay.classList.add("open");
  vendorNotifActiveFilter = "all";
  document.querySelectorAll(".notif-tab").forEach((t) => t.classList.remove("active"));
  const first = document.querySelector(".notif-tab");
  if (first) first.classList.add("active");
  renderVendorNotifications("all");
};

globalThis.closeNotifications = function closeNotifications() {
  document.getElementById("notif-sidebar")?.classList.remove("open");
  document.getElementById("notif-overlay")?.classList.remove("open");
};

globalThis.switchNotifTab = function switchNotifTab(filter, el) {
  document.querySelectorAll(".notif-tab").forEach((t) => t.classList.remove("active"));
  if (el) el.classList.add("active");
  renderVendorNotifications(filter);
};

globalThis.markRead = function markRead(id) {
  const n = vendorNotifData.find((x) => String(x.id) === String(id));
  if (!n || !n.unread) return;
  n.unread = false;
  if (n.alertId) markAlertRead(n.alertId).catch(() => {});
  updateVendorBadge();
  renderVendorNotifications(vendorNotifActiveFilter);
};

globalThis.markAllRead = function markAllRead() {
  const hadStoredAlerts = vendorNotifData.some((n) => n.unread && n.alertId);
  vendorNotifData.forEach((n) => {
    n.unread = false;
  });
  if (hadStoredAlerts) markAllAlertsRead().catch(() => {});
  updateVendorBadge();
  renderVendorNotifications(vendorNotifActiveFilter);
};

globalThis.updateBadge = updateVendorBadge;

if (!globalThis.openModal) {
  globalThis.openModal = function openModal(id) {
    document.getElementById(id)?.classList.add("open");
  };
}
if (!globalThis.closeModal) {
  globalThis.closeModal = function closeModal(id) {
    document.getElementById(id)?.classList.remove("open");
  };
}

document.addEventListener("DOMContentLoaded", async () => {
  await refreshVendorNotifications();
  updateVendorBadge();
});
