import { setApiContext, getDashboardStats, getContracts, getPerformance } from "/assets/js/api-client.js";
import { applyVendorIdentity } from "/assets/js/vendor-session.js";
import { formatPaymentTerms, monitorLabel, monitorPillClass, isRenewalDue } from "/assets/js/contract-intel.js";
import { criteriaStarsHtml, renderStarRating } from "/assets/js/star-rating.js";

function formatPeso(v) {
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
    maximumFractionDigits: 0,
  }).format(Number(v) || 0);
}

function formatDate(value) {
  if (!value) return "—";
  return new Date(value).toLocaleDateString();
}

document.addEventListener("DOMContentLoaded", async () => {
  const user = applyVendorIdentity();
  if (!user?.id) return;
  setApiContext({ role: "vendor", userId: user.id });
  try {
    // allSettled: one failing call must not blank the whole dashboard.
    const [statsRes, contractsRes, perfRes] = await Promise.allSettled([
      getDashboardStats("vendor"),
      getContracts(),
      getPerformance(),
    ]);
    const stats = statsRes.status === "fulfilled" && statsRes.value ? statsRes.value : {};
    const contracts = contractsRes.status === "fulfilled" ? contractsRes.value : [];
    const perf = perfRes.status === "fulfilled" && perfRes.value ? perfRes.value : {};
    const rows = Array.isArray(contracts) ? contracts : [];
    const statNumbers = document.querySelectorAll(".stat-number");
    if (statNumbers[0]) renderStarRating(statNumbers[0], perf.has_manual_score ? perf.overall_score : null, { size: 18, pill: true });
    if (statNumbers[1]) statNumbers[1].textContent = String(stats.active_contracts ?? stats.approved ?? 0);
    if (statNumbers[2]) statNumbers[2].textContent = formatPeso(stats.total_contract_value || 0);
    if (statNumbers[3]) statNumbers[3].textContent = String(stats.pending || 0);

    const criteriaHost = document.getElementById("criteria-stars");
    if (criteriaHost) {
      const items = Array.isArray(perf.criteria) ? perf.criteria : [];
      criteriaHost.innerHTML = criteriaStarsHtml(
        items.map((item) => ({ ...item, score: perf.has_manual_score ? item.score : null })),
      );
    }

    const empty = document.querySelector(".table-card .empty-state");
    const showing = document.querySelector(".table-card .t-showing");
    const tableCard = document.querySelector(".table-card");
    if (tableCard && rows.length) {
      empty?.remove();
      const cols = tableCard.querySelector(".t-cols");
      const foot = tableCard.querySelector(".t-foot");
      const host = document.createElement("div");
      host.id = "vendor-dash-contracts";
      host.innerHTML = rows
        .slice(0, 8)
        .map(
          (r) => `
        <div class="t-row" style="grid-template-columns:2fr 1.5fr 2fr 1.2fr 1fr;">
          <div>
            <div class="t-cell-primary">${r.contract_title || "Untitled"}</div>
            <div class="t-cell-meta">${formatPaymentTerms(r.payment_terms)}</div>
          </div>
          <div class="t-cell-secondary">${formatPeso(r.contract_value)}</div>
          <div class="t-cell-meta">${formatDate(r.start_date)} → ${formatDate(r.end_date)}</div>
          <div><span class="pill ${monitorPillClass(r)}">${monitorLabel(r)}</span></div>
          <div><a class="btn btn-ghost btn-sm" href="/pages/VendorClient/VendorContracts.html">View</a></div>
        </div>`,
        )
        .join("");
      if (cols) cols.after(host);
      else if (foot) tableCard.insertBefore(host, foot);
      else tableCard.appendChild(host);
      if (showing) showing.textContent = `Showing ${Math.min(8, rows.length)} of ${rows.length} contracts`;
    }

    const activityCard = document.querySelectorAll(".chart-card")[2] || document.querySelector(".chart-card[style]");
    const activityEmpty = activityCard?.querySelector(".empty-state");
    if (activityCard && rows.length) {
      activityEmpty?.remove();
      const due = rows.filter(isRenewalDue);
      const items = [
        ...due.slice(0, 4).map((r) => `Renewal notice: ${r.contract_title || "Contract"} ends ${formatDate(r.end_date)}.`),
        ...rows.slice(0, 4).map((r) => `${monitorLabel(r)} · ${r.contract_title || "Contract"} · ${formatPaymentTerms(r.payment_terms)}`),
      ].slice(0, 6);
      const wrap = document.createElement("div");
      wrap.style.padding = "8px 4px 0";
      wrap.innerHTML = items
        .map(
          (t) => `<div style="padding:10px 0;border-bottom:1px solid var(--gray-200);font-size:13px;color:var(--gray-700);">${t}</div>`,
        )
        .join("");
      activityCard.appendChild(wrap);
    }

    const ctx = document.getElementById("trend-chart");
    if (ctx && window.Chart) {
      const byMonth = new Map();
      rows.forEach((r) => {
        const d = r.uploaded_at || r.start_date;
        if (!d) return;
        const key = new Date(d).toLocaleString("en-PH", { month: "short" });
        byMonth.set(key, (byMonth.get(key) || 0) + Number(r.contract_value || 0));
      });
      const labels = [...byMonth.keys()].slice(-6);
      const data = labels.map((k) => (byMonth.get(k) || 0) / 1_000_000);
      if (window.__vendorTrendChart) window.__vendorTrendChart.destroy();
      if (!labels.length) {
        // No dated contracts yet: say so rather than drawing a flat placeholder trend.
        const card = ctx.closest(".chart-card") || ctx.parentElement;
        if (card) {
          ctx.style.display = "none";
          if (!card.querySelector(".chart-empty")) {
            const note = document.createElement("div");
            note.className = "chart-empty";
            note.style.cssText =
              "padding:40px 10px;text-align:center;font-size:12px;color:var(--gray-400);";
            note.textContent = "No contract value history yet.";
            card.appendChild(note);
          }
        }
        return;
      }
      window.__vendorTrendChart = new window.Chart(ctx, {
        type: "line",
        data: {
          labels,
          datasets: [
            {
              label: "Contract Value",
              data,
              borderColor: "#711A20",
              backgroundColor: "rgba(113,26,32,0.06)",
              tension: 0.4,
              fill: true,
              pointBackgroundColor: "#711A20",
              pointRadius: 4,
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
            x: { grid: { display: false }, ticks: { color: "#9E9E9E", font: { size: 11 } } },
          },
        },
      });
    }
  } catch (error) {
    console.error(error);
  }
});
