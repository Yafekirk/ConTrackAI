import { getVendorSummary, setApiContext } from "/assets/js/api-client.js";
import { renderStarRating, starRatingHtml } from "/assets/js/star-rating.js";

const formatPeso = (value) =>
  new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
    maximumFractionDigits: 0,
  }).format(Number(value) || 0);

function escapeHtml(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

let vendors = [];

function render(rows) {
  const host = document.getElementById("ceo-vendors-table-body");
  const showing = document.querySelector(".table-card .t-showing");
  if (!host) return;

  if (!rows.length) {
    host.innerHTML = `<div class="t-row" style="grid-template-columns:1fr;">
      <div class="t-cell-meta" style="text-align:center;padding:28px 0;color:var(--gray-400);">
        No vendors found. Registered vendor accounts appear here even before their first upload.
      </div>
    </div>`;
    if (showing) showing.textContent = "Showing 0 vendors";
    return;
  }

  const grid = "2fr 1.1fr 0.7fr 1.2fr 1.7fr 0.8fr 1.1fr";
  host.innerHTML = rows
    .map((r) => {
      const name = escapeHtml(String(r.vendor_name ?? "—"));
      const typ = escapeHtml(String(r.contract_type ?? "—"));
      const cnt = String(r.contract_count ?? 0);
      const val = escapeHtml(formatPeso(r.total_value));
      const score = r.has_manual_score ? starRatingHtml(r.vendor_score ?? r.ai_score, { size: 14 }) : "—";
      const risk = String(r.risk || "low").toLowerCase();
      const rating = r.has_manual_score ? String(r.rating_label || r.rating || "—") : "Not scored";
      const vid = String(r.vendor_id ?? "");
      return `<div class="t-row" style="grid-template-columns:${grid};">
        <div class="t-cell-primary">${name}</div>
        <div class="t-cell-secondary">${typ}</div>
        <div class="t-cell-meta">${cnt}</div>
        <div class="t-cell-secondary">${val}</div>
        <div class="t-cell-meta">${score}</div>
        <div><span class="pill pill-${risk}">${risk.toUpperCase()}</span></div>
        <div>
          <div class="t-cell-meta">${escapeHtml(rating)}</div>
          <button type="button" class="btn btn-ghost btn-sm ceo-vendor-open" data-vid="${escapeHtml(vid)}">View</button>
        </div>
      </div>`;
    })
    .join("");

  if (showing) showing.textContent = `Showing ${rows.length} vendors`;

  host.querySelectorAll(".ceo-vendor-open").forEach((btn) => {
    btn.addEventListener("click", () => {
      const vid = btn.getAttribute("data-vid");
      const r = vendors.find((v) => String(v.vendor_id) === String(vid));
      if (!r) return;
      document.getElementById("v-name").textContent = r.vendor_name || "—";
      document.getElementById("v-type").textContent = r.contract_type || "—";
      document.getElementById("v-contracts").textContent = String(r.contract_count ?? 0);
      document.getElementById("v-value").textContent = formatPeso(r.total_value);
      renderStarRating(
        document.getElementById("v-score"),
        r.has_manual_score ? (r.vendor_score ?? r.ai_score) : null,
        { size: 16, pill: true },
      );
      const ratingEl = document.getElementById("v-rating");
      if (ratingEl) ratingEl.textContent = r.rating_label || "—";
      const evEl = document.getElementById("v-evaluated");
      if (evEl) evEl.textContent = r.evaluated_at ? new Date(r.evaluated_at).toLocaleString() : "—";
      const risk = String(r.risk || "low").toLowerCase();
      document.getElementById("v-risk").innerHTML = `<span class="pill pill-${risk}">${risk.toUpperCase()}</span>`;
      const rs = document.getElementById("v-risk-score");
      if (rs) rs.textContent = String(r.risk_score ?? "—");
      document.getElementById("modal-vendor")?.classList.add("open");
    });
  });

  const total = rows.length;
  const high = rows.filter((r) => Number(r.vendor_score ?? r.ai_score) >= 80 && r.has_manual_score).length;
  const risk = rows.filter((r) => r.has_manual_score && Number(r.vendor_score ?? r.ai_score) < 60).length;
  const statNums = document.querySelectorAll(".stats-row.col3 .stat-number");
  if (statNums[0]) statNums[0].textContent = String(total);
  if (statNums[1]) statNums[1].textContent = String(high);
  if (statNums[2]) statNums[2].textContent = String(risk);
}

function applyFilters() {
  const q = (document.querySelector(".filter-search")?.value || "").trim().toLowerCase();
  const typeIx = document.querySelectorAll(".filter-select")[0]?.selectedIndex ?? 0;
  const riskIx = document.querySelectorAll(".filter-select")[1]?.selectedIndex ?? 0;
  const typeOpt =
    document.querySelectorAll(".filter-select")[0]?.options[typeIx]?.text?.trim() || "All Types";
  const riskOpt =
    document.querySelectorAll(".filter-select")[1]?.options[riskIx]?.text?.trim() || "All Risk";

  let list = vendors.slice();
  if (q) {
    list = list.filter((v) => {
      const name = String(v.vendor_name ?? "").toLowerCase();
      const email = String(v.vendor_email ?? "").toLowerCase();
      return name.includes(q) || email.includes(q);
    });
  }
  if (typeOpt && typeOpt !== "All Types") {
    list = list.filter((v) => String(v.contract_type ?? "") === typeOpt);
  }
  if (riskOpt && riskOpt !== "All Risk") {
    list = list.filter((v) => String(v.risk ?? "").toLowerCase() === riskOpt.toLowerCase());
  }
  render(list);
}

if (!globalThis.openModal) {
  globalThis.openModal = function (id) {
    document.getElementById(id)?.classList.add("open");
  };
}
if (!globalThis.closeModal) {
  globalThis.closeModal = function (id) {
    document.getElementById(id)?.classList.remove("open");
  };
}

document.addEventListener("DOMContentLoaded", async () => {
  const saved = JSON.parse(localStorage.getItem("contrack_user") || "{}");
  setApiContext({ role: saved.role || "ceo", userId: saved.id || null });

  try {
    const data = await getVendorSummary();
    vendors = Array.isArray(data) ? data : [];
    render(vendors);

    document.querySelector(".filter-search")?.addEventListener("input", applyFilters);
    document.querySelectorAll(".filter-select").forEach((el) => el.addEventListener("change", applyFilters));
  } catch (e) {
    console.error("Vendor overview:", e.message);
    const host = document.getElementById("ceo-vendors-table-body");
    if (host) {
      host.innerHTML = `<div class="t-row" style="grid-template-columns:1fr;"><div class="t-cell-meta" style="text-align:center;padding:28px 0;color:var(--red);">
        Could not load vendors (${escapeHtml(e.message)}).
      </div></div>`;
    }
  }
});
