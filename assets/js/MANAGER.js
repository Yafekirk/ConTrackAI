// Manager portal — notifications from review queue + stored alerts + renewals.



let notifications = [];

let currentTab = "all";



function apiHeaders() {

  try {

    const u = JSON.parse(localStorage.getItem("contrack_user") || "{}");

    return {

      "Content-Type": "application/json",

      "X-User-Role": u.role || "manager",

      ...(u.id ? { "X-User-Id": String(u.id) } : {}),

    };

  } catch (_) {

    return { "Content-Type": "application/json", "X-User-Role": "manager" };

  }

}



function esc(s) {

  return String(s ?? "")

    .replace(/&/g, "&amp;")

    .replace(/</g, "&lt;")

    .replace(/>/g, "&gt;")

    .replace(/"/g, "&quot;");

}



async function refreshNotificationsFromApi() {

  try {

    const fetchJson = async (url) => {

      const res = await fetch(url, { credentials: "same-origin", headers: apiHeaders() });

      if (!res.ok) return [];

      const data = await res.json();

      return Array.isArray(data) ? data : [];

    };


    // allSettled: one failing endpoint must not empty the whole drawer.

    const [queueRes, renewalRes, listRes, alertsRes] = await Promise.allSettled([

      fetchJson("/api/contracts.php?view=manager_pending"),

      fetchJson("/api/contracts.php?view=renewals"),

      fetchJson("/api/contracts.php?view=list"),

      fetchJson("/api/alerts.php?limit=40"),

    ]);

    const rows = queueRes.status === "fulfilled" ? queueRes.value : [];

    const renewals = renewalRes.status === "fulfilled" ? renewalRes.value : [];

    const all = listRes.status === "fulfilled" ? listRes.value : [];

    const alerts = alertsRes.status === "fulfilled" ? alertsRes.value : [];



    const alertNotes = (Array.isArray(alerts) ? alerts : []).map((row) => ({

      id: `alert-${row.id}`,

      alertId: row.id,

      type: /ceo|decision|approved|rejected/i.test(String(row.title || "")) ? "success" : "info",

      title: row.title || "Alert",

      desc: row.message || "",

      time: row.created_at ? new Date(row.created_at).toLocaleString() : "",

      unread: String(row.status || "").toLowerCase() !== "read",

      archived: false,

      href: "/pages/Manager/ManagerSubmissions.html",

    }));



    const queueNotes = (Array.isArray(rows) ? rows : []).slice(0, 25).map((row) => ({

      id: `queue-${row.id}`,

      type: "warning",

      title: `Review queue: ${row.contract_title || "Contract"}`,

      desc: `${row.vendor_name || "Vendor"} · ₱${Number(row.contract_value || 0).toLocaleString()}`,

      time: row.uploaded_at ? new Date(row.uploaded_at).toLocaleString() : "",

      unread: true,

      archived: false,

      href: "/pages/Manager/ManagerSubmissions.html",

    }));



    const renewalNotes = (Array.isArray(renewals) ? renewals : []).map((row) => ({

      id: `renewal-${row.id}`,

      type: "warning",

      title: row.title || "Renewal notice",

      desc: row.desc || "Contract is subject for renewal.",

      time: row.time || "",

      unread: true,

      archived: false,

      href: "/pages/Manager/ManagerContracts.html",

    }));



    window.__mgrArchivedNotifs = (Array.isArray(all) ? all : [])

      .filter(

        (r) =>

          r.is_archived ||

          String(r.monitor_status) === "expired" ||

          ["terminated", "rejected"].includes(String(r.status || "").toLowerCase()),

      )

      .slice(0, 15)

      .map((row) => ({

        id: `arch-${row.id}`,

        type: "info",

        title: `Archived: ${row.contract_title || "Contract"}`,

        desc: "Inactive contract archived from the live queue.",

        time: row.end_date || "",

        unread: false,

        archived: true,

      }));



    notifications = [...alertNotes, ...queueNotes, ...renewalNotes];

  } catch (_) {

    notifications = [];

    window.__mgrArchivedNotifs = [];

  }

}



const iconPaths = {

  success: '<polyline points="20 6 9 17 4 12"/>',

  warning:

    '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',

  error: '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>',

  info: '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>',

};



function toggleNotif() {

  const s = document.getElementById("notif-sidebar");

  const o = document.getElementById("notif-overlay");

  if (!s || !o) return;

  if (s.classList.contains("open")) {

    closeNotif();

    return;

  }

  s.classList.add("open");

  o.classList.add("open");

  renderNotifs(currentTab);

}



function closeNotif() {

  const s = document.getElementById("notif-sidebar");

  const o = document.getElementById("notif-overlay");

  if (s) s.classList.remove("open");

  if (o) o.classList.remove("open");

}



function switchTab(el, tab) {

  document.querySelectorAll(".notif-tab").forEach((t) => t.classList.remove("active"));

  if (el) el.classList.add("active");

  currentTab = tab;

  renderNotifs(tab);

}



function renderNotifs(tab) {

  const list = document.getElementById("notif-list");

  if (!list) return;

  let items = notifications;

  if (tab === "unread") items = notifications.filter((n) => n.unread);

  if (tab === "archived") items = window.__mgrArchivedNotifs || [];

  if (!items.length) {

    list.innerHTML = `<div class="notif-empty"><svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg><div class="notif-empty-title">No notifications</div><div class="notif-empty-desc">New vendor submissions appear here.</div></div>`;

    return;

  }

  list.innerHTML = items

    .map(

      (n) => `

    <div class="notif-item${n.unread ? " unread" : ""}" data-nid="${esc(n.id)}" ${n.href ? `data-href="${esc(n.href)}"` : ""}>

      <div class="notif-item-icon ni-${n.type}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">${iconPaths[n.type] || iconPaths.info}</svg></div>

      <div class="notif-item-body">

        <div class="notif-item-title">${n.unread ? '<span class="notif-unread-dot"></span>' : ""}${esc(n.title)}</div>

        <div class="notif-item-desc">${esc(n.desc)}</div>

        <div class="notif-item-time">${esc(n.time)}</div>

      </div>

    </div>`,

    )

    .join("");



  list.querySelectorAll("[data-nid]").forEach((el) => {

    el.addEventListener("click", () => {

      const id = el.getAttribute("data-nid");

      markRead(id);

      const href = el.getAttribute("data-href");

      if (href) window.location.href = href;

    });

  });

}



function markRead(id) {

  const n = notifications.find((x) => String(x.id) === String(id));

  if (n) {

    n.unread = false;

    if (n.alertId) {

      fetch("/api/alerts.php", {

        method: "PATCH",

        credentials: "same-origin",

        headers: apiHeaders(),

        body: JSON.stringify({ id: n.alertId, status: "read" }),

      }).catch(() => {});

    }

  }

  updateBadge();

  renderNotifs(currentTab);

}



function markAllRead() {

  notifications.forEach((n) => {

    n.unread = false;

  });

  fetch("/api/alerts.php", {

    method: "PATCH",

    credentials: "same-origin",

    headers: apiHeaders(),

    body: JSON.stringify({ mark_all: true, status: "read" }),

  }).catch(() => {});

  updateBadge();

  renderNotifs(currentTab);

}



function updateBadge() {

  const c = notifications.filter((n) => n.unread).length;

  const b = document.getElementById("notif-count");

  if (b) {

    b.textContent = String(c);

    b.style.display = c > 0 ? "flex" : "none";

  }

}



document.addEventListener("DOMContentLoaded", async () => {

  const badge = document.getElementById("notif-count");

  if (badge) badge.style.display = "none";

  await refreshNotificationsFromApi();

  updateBadge();

});

