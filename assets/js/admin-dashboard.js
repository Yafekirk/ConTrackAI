import {
  setApiContext,
  getMaintenanceDashboard,
  getDashboardStats,
  getContracts,
} from "/assets/js/api-client.js";
import { contractRef } from "/assets/js/contract-intel.js";

function formatPhp(n) {
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
    maximumFractionDigits: 0,
  }).format(Number(n) || 0);
}

function esc(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

document.addEventListener("DOMContentLoaded", async () => {
  const saved = JSON.parse(localStorage.getItem("contrack_user") || "{}");
  setApiContext({ role: saved.role || "admin", userId: saved.id || null });

  try {
    const m = await getMaintenanceDashboard();
    const totalEl = document.getElementById("stat-total");
    const activeEl = document.getElementById("stat-active");
    const failEl = document.getElementById("stat-expiring");
    const queueEl = document.getElementById("stat-risk");
    const rb = document.getElementById("role-breakdown");

    if (totalEl) totalEl.textContent = String(m.total_users ?? 0);
    if (activeEl) activeEl.textContent = String(m.active_users_recent_15m ?? 0);
    if (failEl) failEl.textContent = String(m.failed_logins_24h ?? 0);
    if (queueEl) queueEl.textContent = String(m.pending_contracts ?? 0);
    if (rb && m.users_by_role) {
      rb.textContent = Object.entries(m.users_by_role)
        .map(([k, v]) => `${k}: ${v}`)
        .join(" · ");
    }

    const nlpNote = document.getElementById("nlp-runs-note");
    if (nlpNote) {
      const runs = Number(m.nlp_runs_24h ?? 0);
      const py = Number(m.nlp_python_24h ?? 0);
      nlpNote.textContent = `NLP runs (24h): ${runs} · Python ML: ${py}`;
    }

    const act = document.getElementById("activity-list");
    if (act && Array.isArray(m.recent_audit)) {
      if (m.recent_audit.length === 0) {
        act.innerHTML =
          '<div style="text-align:center;padding:20px 0;color:var(--gray-400);font-size:13px;">No audit events yet</div>';
      } else {
        act.innerHTML = m.recent_audit
          .map(
            (row) => `
          <div style="padding:10px 0;border-bottom:1px solid var(--gray-200);font-size:12px;">
            <div style="font-weight:600;color:var(--gray-800);">${esc(row.event_type)}</div>
            <div style="color:var(--gray-500);margin-top:4px;">${esc(
              row.created_at ? new Date(row.created_at).toLocaleString() : "",
            )}</div>
          </div>`,
          )
          .join("");
      }
    }
  } catch (e) {
    console.error("Maintenance dashboard:", e);
  }

  let stats = null;
  try {
    stats = await getDashboardStats("admin");
  } catch (e) {
    console.error("Contract stats:", e);
  }

  const approved = stats?.approved ?? 0;
  const pending = stats?.pending ?? 0;
  const rejected = stats?.rejected ?? 0;
  const expiring = stats?.expiring_30_days ?? 0;

  const statusCtx = document.getElementById("status-chart");
  if (statusCtx && window.Chart) {
    const chart = new window.Chart(statusCtx, {
      type: "doughnut",
      data: {
        labels: ["Approved", "Pending", "Rejected", "Net 30"],
        datasets: [
          {
            data: [approved, pending, rejected, expiring],
            backgroundColor: ["#2D7135", "#E67E22", "#C0392B", "#6D28D9"],
            borderWidth: 0,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        cutout: "70%",
      },
    });
    const legend = document.getElementById("status-legend");
    const labels = [
      { label: "Approved", color: "#2D7135", v: approved },
      { label: "Pending", color: "#E67E22", v: pending },
      { label: "Rejected", color: "#C0392B", v: rejected },
      { label: "Net 30 (≤30d left)", color: "#6D28D9", v: expiring },
    ];
    if (legend) {
      legend.innerHTML = labels
        .map(
          (l) => `
        <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--gray-600);">
          <span style="width:10px;height:10px;border-radius:50%;background:${l.color};flex-shrink:0;"></span>
          ${l.label} (${l.v})
        </div>`,
        )
        .join("");
    }
    void chart;
  }

  const volCtx = document.getElementById("volume-chart");
  if (volCtx && window.Chart) {
    new window.Chart(volCtx, {
      type: "bar",
      data: {
        labels: ["Approved", "Pending", "Rejected", "Net 30"],
        datasets: [
          {
            data: [approved, pending, rejected, expiring],
            backgroundColor: "rgba(113,26,32,0.75)",
            borderRadius: 4,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: {
            grid: { color: "rgba(0,0,0,0.04)" },
            ticks: { color: "#9E9E9E", font: { size: 11 } },
          },
          x: {
            grid: { display: false },
            ticks: { color: "#9E9E9E", font: { size: 11 } },
          },
        },
      },
    });
  }

  try {
    const rows = await getContracts();
    const body = document.getElementById("recent-contracts-body");
    const empty = document.getElementById("recent-contracts-empty");
    const meta = document.getElementById("recent-contracts-meta");
    if (Array.isArray(rows) && rows.length > 0) {
      if (empty) empty.style.display = "none";
      const slice = rows.slice(0, 8);
      if (body) {
        body.innerHTML = slice
          .map(
            (r) => `
        <div class="t-row" style="grid-template-columns:2fr 2fr 1fr 1fr 80px;">
          <div>
            <div class="t-cell-primary">${esc(r.contract_title)}</div>
            <div class="t-cell-meta">${esc(contractRef(r))}</div>
          </div>
          <div class="t-cell-secondary">${esc(r.vendor_name)}</div>
          <div>${formatPhp(r.contract_value)}</div>
          <div><span class="pill pill-active">${esc((r.status || "").toUpperCase())}</span></div>
          <div></div>
        </div>`,
          )
          .join("");
      }
      if (meta) meta.textContent = `Showing ${slice.length} of ${rows.length}`;
    }
  } catch (e) {
    console.error("Recent contracts:", e);
  }
});
