import {
  getDashboardStats,
  getPendingApprovals,
  getVendorSummary,
  setApiContext,
} from "/assets/js/api-client.js";
import { contractRef } from "/assets/js/contract-intel.js";

const formatPeso = (value) =>
  new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
    maximumFractionDigits: 0,
  }).format(value || 0);

function riskFromValue(value) {
  const v = Number(value) || 0;
  if (v >= 5000000) return { label: "high", pill: "pill-high" };
  if (v >= 1000000) return { label: "medium", pill: "pill-med" };
  return { label: "low", pill: "pill-low" };
}

function millionsPhp(n) {
  return (Number(n) || 0) / 1_000_000;
}

function escapeHtml(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function renderPendingRows(pending) {
  const host = document.getElementById("ceo-dashboard-pending-rows");
  if (!host) return;

  const foot = document.getElementById("ceo-pending-foot");
  if (!pending.length) {
    host.innerHTML = `<div class="t-row" style="grid-template-columns:1fr;">
      <div class="t-cell-meta" style="grid-column:1/-1;text-align:center;padding:20px 0;color:var(--gray-400);">
        No pending approvals right now.
      </div>
    </div>`;
    if (foot) foot.textContent = "Showing 0 pending approvals";
    return;
  }

  const grid = "1.5fr 2fr 1.5fr 1fr 1fr 1.2fr";
  const slice = pending.slice(0, 12);
  host.innerHTML = slice
    .map((r) => {
      const risk = riskFromValue(r.contract_value);
      const id = escapeHtml(contractRef(r));
      const vendor = escapeHtml(String(r.vendor_name ?? "—"));
      const val = escapeHtml(formatPeso(r.contract_value));
      return `<div class="t-row" style="grid-template-columns:${grid};">
        <div class="t-cell-primary">${id}</div>
        <div class="t-cell-secondary">${vendor}</div>
        <div class="t-cell-secondary">${val}</div>
        <div><span class="pill ${risk.pill}">${risk.label.toUpperCase()}</span></div>
        <div><span class="pill pill-review">PENDING</span></div>
        <div><a class="btn btn-ghost btn-sm" href="/pages/CEO/CEO_Approvals.html">Open</a></div>
      </div>`;
    })
    .join("");

  if (foot) foot.textContent = `Showing ${slice.length} pending approvals`;
}

function renderTopVendors(rows) {
  const host = document.getElementById("ceo-top-vendors-list");
  if (!host) return;
  const top = (rows || []).slice(0, 6);
  if (!top.length) {
    host.innerHTML = `<div style="font-size:12px;color:var(--gray-400);text-align:center;padding:20px 0;">No vendor data yet.</div>`;
    return;
  }
  host.innerHTML = top
    .map((v) => {
      const name = escapeHtml(String(v.vendor_name ?? "—"));
      const val = escapeHtml(formatPeso(v.total_value));
      const n = String(v.contract_count ?? 0);
      return `<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--gray-200);">
        <div><div style="font-weight:600;color:var(--gray-900);font-size:13px;">${name}</div>
        <div style="font-size:11px;color:var(--gray-500);">${n} contract(s)</div></div>
        <div class="ceo-vendor-value">${val}</div>
      </div>`;
    })
    .join("");
}

document.addEventListener("DOMContentLoaded", async () => {
  document.getElementById("today-date").textContent = new Date().toLocaleDateString("en-PH", {
    year: "numeric",
    month: "long",
    day: "numeric",
  });

  const saved = JSON.parse(localStorage.getItem("contrack_user") || "{}");
  setApiContext({
    role: saved.role || "ceo",
    userId: saved.id || null,
  });

  try {
    // allSettled: one failing call must not blank the whole dashboard.
    const [statsRes, pendingRes, vendorsRes] = await Promise.allSettled([
      getDashboardStats("ceo"),
      getPendingApprovals(),
      getVendorSummary(),
    ]);
    const stats = statsRes.status === "fulfilled" && statsRes.value ? statsRes.value : {};
    const pending = pendingRes.status === "fulfilled" ? pendingRes.value : [];
    const vendors = vendorsRes.status === "fulfilled" ? vendorsRes.value : [];

    const pend = Array.isArray(pending) ? pending : [];
    const vend = Array.isArray(vendors) ? vendors : [];

    const statNumbers = document.querySelectorAll(".stat-number");
    if (statNumbers[0]) statNumbers[0].textContent = String(stats.total_contracts ?? 0);
    if (statNumbers[1]) statNumbers[1].textContent = String(stats.active_contracts ?? 0);
    if (statNumbers[2]) statNumbers[2].textContent = String(pend.length);
    if (statNumbers[3])
      statNumbers[3].textContent = formatPeso(stats.total_contract_value_active ?? stats.total_contract_value ?? 0);

    renderPendingRows(pend);
    renderTopVendors(vend);

    const active = stats.active_contracts ?? 0;
    const exp30 = stats.expiring_30_days ?? 0;
    const expTot = stats.expired_approved ?? 0;
    const pendN = stats.pending ?? pend.length;

    const statusCtx = document.getElementById("status-chart");
    if (statusCtx && window.Chart) {
      new window.Chart(statusCtx, {
        type: "doughnut",
        data: {
          labels: ["Active (running)", "Net 30 (≤30d left)", "Expired", "Pending"],
          datasets: [
            {
              data: [active, exp30, expTot, pendN],
              backgroundColor: ["#7A0C0C", "#F4C396", "#5a1218", "#FCE8EC"],
              borderColor: "#fff",
              borderWidth: 3,
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
    }

    const va = millionsPhp(stats.total_contract_value_active);
    const vp = millionsPhp(stats.value_pending);
    const vr = millionsPhp(stats.value_rejected);
    const ve = millionsPhp(stats.value_expiring);

    const valueCtx = document.getElementById("value-chart");
    if (valueCtx && window.Chart) {
      new window.Chart(valueCtx, {
        type: "bar",
        data: {
          labels: ["Active portfolio", "Pending queue", "Rejected (historical)", "Net 30 (≤30d left)"],
          datasets: [
            {
              data: [va, vp, vr, ve],
              backgroundColor: ["#7A0C0C", "#991B1B", "#5a1218", "#F4C396"],
              borderRadius: 8,
              borderSkipped: false,
            },
          ],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label(ctx) {
                  const v = Number(ctx.raw) || 0;
                  return `₱${(v * 1_000_000).toLocaleString("en-PH", { maximumFractionDigits: 0 })}`;
                },
              },
            },
          },
          scales: {
            y: {
              grid: { color: "rgba(122,12,12,0.06)" },
              ticks: {
                color: "#8a6a6e",
                font: { size: 11 },
                callback(v) {
                  return `₱${v}M`;
                },
              },
            },
            x: {
              grid: { display: false },
              ticks: { color: "#6b5558", font: { size: 10 }, maxRotation: 45, minRotation: 0 },
            },
          },
        },
      });
    }
  } catch (error) {
    console.error("CEO dashboard data unavailable:", error.message);
  }
});

globalThis.openModal = function (id) {
  document.getElementById(id)?.classList.add("open");
};
globalThis.closeModal = function (id) {
  document.getElementById(id)?.classList.remove("open");
};
