import {
  getContracts,
  getDashboardStats,
  getVendorSummary,
  setApiContext,
} from "/assets/js/api-client.js";
import { contractRef, downloadCsv, formatPaymentTerms, isRenewalDue, monitorLabel } from "/assets/js/contract-intel.js";
import { renderStarRating, starRatingFromScore } from "/assets/js/star-rating.js";

function formatPeso(v) {
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
    maximumFractionDigits: 0,
  }).format(Number(v) || 0);
}

function escapeHtml(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

document.addEventListener("DOMContentLoaded", async () => {
  const saved = JSON.parse(localStorage.getItem("contrack_user") || "{}");
  setApiContext({ role: saved.role || "ceo", userId: saved.id || null });

  let stats = {};
  let contracts = [];
  let vendors = [];
  // allSettled: one failing call must not blank the whole report.
  const [statsRes, contractsRes, vendorsRes] = await Promise.allSettled([
    getDashboardStats("ceo"),
    getContracts(),
    getVendorSummary(),
  ]);
  stats = statsRes.status === "fulfilled" && statsRes.value ? statsRes.value : {};
  contracts = contractsRes.status === "fulfilled" ? contractsRes.value : [];
  vendors = vendorsRes.status === "fulfilled" ? vendorsRes.value : [];
  contracts = Array.isArray(contracts) ? contracts : [];
  vendors = Array.isArray(vendors) ? vendors : [];

  const nums = document.querySelectorAll(".stats-row.col4 .stat-number");
  const ytd = stats.total_contracts ?? contracts.length;
  const avgVal =
    contracts.length > 0
      ? contracts.reduce((s, r) => s + Number(r.contract_value || 0), 0) / contracts.length
      : 0;
  const renewal = contracts.filter(isRenewalDue).length;
  const renewalRate = ytd ? Math.round((renewal / ytd) * 100) : 0;
  const scored = vendors.filter((v) => v.has_manual_score);
  const avgScore = scored.length
    ? Math.round(scored.reduce((s, v) => s + Number(v.vendor_score ?? v.ai_score || 0), 0) / scored.length)
    : 0;
  if (nums[0]) nums[0].textContent = String(ytd);
  if (nums[1]) nums[1].textContent = formatPeso(avgVal);
  if (nums[2]) nums[2].textContent = `${renewalRate}%`;
  renderStarRating(nums[3], scored.length ? avgScore : null, { size: 18, pill: true });

  const expiring = contracts.filter(isRenewalDue).slice(0, 8);
  const expHost = document.getElementById("expiring-list");
  if (expHost) {
    if (!expiring.length) {
      expHost.innerHTML = `<div style="font-size:12px;color:var(--gray-400);text-align:center;padding:24px 0;">No contracts currently subject for renewal.</div>`;
    } else {
      expHost.innerHTML = expiring
        .map(
          (r) => `<div style="display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid var(--gray-200);">
            <div>
              <div style="font-weight:600;">${escapeHtml(r.contract_title || "Contract")}</div>
              <div style="font-size:12px;color:var(--gray-500);">${escapeHtml(r.vendor_name || "")} · ${formatPaymentTerms(r.payment_terms)} · ${monitorLabel(r)}</div>
            </div>
            <div style="font-size:12px;color:var(--orange);font-weight:600;">Ends ${r.end_date || "—"}</div>
          </div>`,
        )
        .join("");
    }
  }

  const byMonth = new Map();
  contracts.forEach((r) => {
    const d = r.uploaded_at || r.start_date;
    if (!d) return;
    const dt = new Date(d);
    const key = `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, "0")}`;
    byMonth.set(key, (byMonth.get(key) || 0) + 1);
  });
  const monthKeys = [...byMonth.keys()].sort().slice(-6);
  const monthLabels = monthKeys.map((k) => {
    const [y, m] = k.split("-");
    return new Date(Number(y), Number(m) - 1, 1).toLocaleString("en-PH", { month: "short" });
  });
  const monthData = monthKeys.map((k) => byMonth.get(k) || 0);

  const buckets = [0, 0, 0, 0, 0];
  vendors.forEach((v) => {
    if (!v.has_manual_score) return;
    const s = Number(v.vendor_score ?? v.ai_score || 0);
    if (s < 60) buckets[0]++;
    else if (s < 70) buckets[1]++;
    else if (s < 80) buckets[2]++;
    else if (s < 90) buckets[3]++;
    else buckets[4]++;
  });

  if (window.Chart) {
    const monthly = document.getElementById("monthly-chart");
    if (monthly) {
      window.Chart.getChart?.(monthly)?.destroy();
      new window.Chart(monthly, {
        type: "bar",
        data: {
          labels: monthLabels.length ? monthLabels : ["—"],
          datasets: [{ data: monthData.length ? monthData : [0], backgroundColor: "rgba(113,26,32,0.75)", borderRadius: 4 }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            y: { beginAtZero: true, grid: { color: "rgba(0,0,0,0.04)" }, ticks: { color: "#9E9E9E" } },
            x: { grid: { display: false }, ticks: { color: "#9E9E9E" } },
          },
        },
      });
    }
    const dist = document.getElementById("dist-chart");
    if (dist) {
      window.Chart.getChart?.(dist)?.destroy();
      new window.Chart(dist, {
        type: "bar",
        data: {
          labels: ["1.0–3.3", "3.4–3.7", "3.8–4.1", "4.2–4.5", "4.6–5.0"],
          datasets: [{ data: buckets, backgroundColor: ["#C0392B", "#E67E22", "#F4A63A", "#2D7135", "#1a6b2e"], borderRadius: 4 }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { display: false } },
          scales: {
            y: { beginAtZero: true, grid: { color: "rgba(0,0,0,0.04)" }, ticks: { color: "#9E9E9E" } },
            x: { grid: { display: false }, ticks: { color: "#9E9E9E" } },
          },
        },
      });
    }
  }

  function packStatusReport() {
    return downloadCsv(
      "contract-status-summary.csv",
      ["contract_id", "title", "vendor", "status", "monitor", "value", "payment_terms", "start", "end"],
      contracts.map((r) => [
        contractRef(r),
        r.contract_title,
        r.vendor_name,
        r.status,
        monitorLabel(r),
        r.contract_value,
        formatPaymentTerms(r.payment_terms),
        r.start_date,
        r.end_date,
      ]),
    );
  }
  function packVendorReport() {
    return downloadCsv(
      "vendor-performance.csv",
      ["vendor", "star_rating", "risk", "risk_score", "contracts", "value", "recommendations"],
      vendors.map((v) => [
        v.vendor_name,
        starRatingFromScore(v.vendor_score ?? v.ai_score) ?? "",
        v.risk,
        v.risk_score,
        v.contract_count,
        v.total_value,
        (v.recommendations || []).join(" | "),
      ]),
    );
  }
  function packRenewalReport() {
    return downloadCsv(
      "expiration-renewal-tracker.csv",
      ["contract_id", "title", "vendor", "end_date", "days_until_end", "renewal_terms", "payment_terms"],
      contracts.filter(isRenewalDue).map((r) => [
        contractRef(r),
        r.contract_title,
        r.vendor_name,
        r.end_date,
        r.days_until_end,
        r.renewal_terms,
        formatPaymentTerms(r.payment_terms),
      ]),
    );
  }
  function packExecReport() {
    return downloadCsv(
      "executive-summary.csv",
      ["metric", "value"],
      [
        ["Total contracts", ytd],
        ["Active", stats.active_contracts ?? ""],
        ["Pending", stats.pending ?? ""],
        ["Expiring 30 days", stats.expiring_30_days ?? ""],
        ["Archived / inactive", stats.archived_contracts ?? ""],
        ["Avg vendor rating", scored.length ? starRatingFromScore(avgScore) : ""],
        ["Renewal rate %", renewalRate],
      ],
    );
  }

  const downloads = document.querySelectorAll(".report-item .btn");
  if (downloads[0]) downloads[0].addEventListener("click", packStatusReport);
  if (downloads[1]) downloads[1].addEventListener("click", packVendorReport);
  if (downloads[2]) downloads[2].addEventListener("click", packRenewalReport);
  if (downloads[3]) downloads[3].addEventListener("click", packExecReport);
  document.querySelector(".page-header .btn-primary")?.addEventListener("click", packExecReport);
});
