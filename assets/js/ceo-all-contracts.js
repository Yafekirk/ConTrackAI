import { getContracts, getDashboardStats, setApiContext } from "/assets/js/api-client.js";
import { contractRef, bindCsvExport } from "/assets/js/contract-intel.js";

const formatPeso = (value) =>
  new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
    maximumFractionDigits: 0,
  }).format(Number(value) || 0);

function riskFromValue(value) {
  const v = Number(value) || 0;
  if (v >= 5000000) return "high";
  if (v >= 1000000) return "medium";
  return "low";
}

/** Maps DB status to dashboard pill key + display label */
function statusPill(row) {
  const s = String(row.status || "").toLowerCase();
  if (s === "approved") {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    if (row.end_date) {
      const end = new Date(String(row.end_date));
      if (end < today) return { cls: "pill-expired", label: "EXPIRED" };
      const days = (end - today) / 86400000;
      if (days >= 0 && days <= 30) return { cls: "pill-expiring", label: "NET 30" };
    }
    return { cls: "pill-active", label: "ACTIVE" };
  }
  if (s === "pending") return { cls: "pill-pending", label: "PENDING" };
  if (s === "rejected") return { cls: "pill-rejected", label: "REJECTED" };
  if (s === "terminated") return { cls: "pill-expired", label: "TERMINATED" };
  if (s === "renegotiation") return { cls: "pill-renewal", label: "RENEWAL" };
  if (s === "modification") return { cls: "pill-expiring", label: "MODIFY" };
  if (s === "archived") return { cls: "pill-expired", label: "ARCHIVED" };
  if (row.monitor_status) {
    const mon = String(row.monitor_status).toLowerCase();
    if (mon === "expired") return { cls: "pill-expired", label: "EXPIRED" };
    if (mon === "expiring") return { cls: "pill-expiring", label: "EXPIRING" };
    if (mon === "active") return { cls: "pill-active", label: "ACTIVE" };
  }
  return { cls: "pill-review", label: s ? s.toUpperCase() : "—" };
}

let allRows = [];

function escapeHtml(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function renderTable(rows) {
  const host = document.getElementById("ceo-contracts-table-body");
  const showing = document.querySelector(".table-card .t-showing");
  if (!host) return;

  if (!rows.length) {
    host.innerHTML = `<div class="t-row" style="grid-template-columns:1fr;">
      <div class="t-cell-meta" style="text-align:center;padding:28px 0;color:var(--gray-400);">
        No contracts match the current filters.
      </div>
    </div>`;
    if (showing) showing.textContent = "Showing 0 contracts";
    return;
  }

  const grid = "1.5fr 2fr 1.5fr 1fr 1fr 1fr 1fr";
  host.innerHTML = rows
    .map((r) => {
      const id = String(r.id ?? "");
      const pill = statusPill(r);
      const risk = riskFromValue(r.contract_value);
      const end = r.end_date ? String(r.end_date) : "—";
      const safeId = escapeHtml(id);
      return `<div class="t-row" style="grid-template-columns:${grid};">
        <div class="t-cell-primary">${escapeHtml(contractRef(r))}</div>
        <div class="t-cell-secondary">${escapeHtml(String(r.vendor_name ?? "—"))}</div>
        <div class="t-cell-secondary">${escapeHtml(formatPeso(r.contract_value))}</div>
        <div class="t-cell-meta">${escapeHtml(end)}</div>
        <div><span class="pill ${pill.cls}">${pill.label}</span></div>
        <div><span class="pill pill-${risk}">${risk.toUpperCase()}</span></div>
        <div><button type="button" class="btn btn-ghost btn-sm ceo-contract-view" data-contract-id="${safeId}">View</button></div>
      </div>`;
    })
    .join("");

  if (showing) showing.textContent = `Showing ${rows.length} contracts`;

  host.onclick = (e) => {
    const btn = e.target.closest(".ceo-contract-view");
    if (!btn) return;
    const id = btn.getAttribute("data-contract-id");
    const r = rows.find((x) => String(x.id) === id) || allRows.find((x) => String(x.id) === id);
    if (!r) return;
    const pill = statusPill(r);
    const risk = riskFromValue(r.contract_value);
    const setTxt = (elId, val) => {
      const el = document.getElementById(elId);
      if (el) el.textContent = val;
    };
    setTxt("d-id", contractRef(r));
    setTxt("d-vendor", String(r.vendor_name ?? "—"));
    setTxt("d-value", formatPeso(r.contract_value));
    setTxt("d-start", r.start_date ? String(r.start_date) : "—");
    setTxt("d-end", r.end_date ? String(r.end_date) : "—");
    const st = document.getElementById("d-status");
    if (st) st.innerHTML = `<span class="pill ${pill.cls}">${pill.label}</span>`;
    const rk = document.getElementById("d-risk");
    if (rk) rk.innerHTML = `<span class="pill pill-${risk}">${risk.toUpperCase()}</span>`;
    setTxt("d-payment", r.payment_terms || "—");
    setTxt("d-class", r.classification || "—");
    const clauses = document.getElementById("d-clauses");
    if (clauses) {
      clauses.innerHTML = `<strong>Renewal:</strong> ${escapeHtml(r.renewal_terms || "—")}<br>
        <strong>Penalties:</strong> ${escapeHtml(r.penalty_clause || "—")}<br>
        <strong>Financial obligations:</strong> ${escapeHtml(r.financial_obligations || formatPeso(r.contract_value))}`;
    }
    globalThis.openModal?.("modal-detail");
  };
}

function applyFilters() {
  const q = (document.querySelector(".filter-search")?.value || "").trim().toLowerCase();
  const statusOpt =
    document.querySelectorAll(".filter-select")[0]?.options[document.querySelectorAll(".filter-select")[0]?.selectedIndex]
      ?.text?.trim() || "All Status";
  const riskOpt =
    document.querySelectorAll(".filter-select")[1]?.options[document.querySelectorAll(".filter-select")[1]?.selectedIndex]
      ?.text?.trim() || "All Risk";
  const vendorSel = document.querySelectorAll(".filter-select")[2];
  const vendorPick = vendorSel?.value || "all";

  let list = allRows.slice();

  if (q) {
    list = list.filter((r) => {
      const id = String(r.id ?? "").toLowerCase();
      const code = contractRef(r).toLowerCase();
      const vn = String(r.vendor_name ?? "").toLowerCase();
      const title = String(r.contract_title ?? "").toLowerCase();
      return id.includes(q) || code.includes(q) || vn.includes(q) || title.includes(q);
    });
  }

  if (statusOpt && statusOpt !== "All Status") {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    if (statusOpt === "Active") {
      list = list.filter((r) => {
        if (String(r.status || "").toLowerCase() !== "approved") return false;
        if (!r.end_date) return true;
        return new Date(String(r.end_date)) >= today;
      });
    } else if (statusOpt === "Net 30") {
      list = list.filter((r) => {
        if (String(r.status || "").toLowerCase() !== "approved" || !r.end_date) return false;
        const end = new Date(String(r.end_date));
        const days = (end - today) / 86400000;
        return days >= 0 && days <= 30;
      });
    } else if (statusOpt === "Expired") {
      list = list.filter((r) => {
        if (!r.end_date) return String(r.status || "").toLowerCase() === "rejected";
        return new Date(String(r.end_date)) < today;
      });
    } else if (statusOpt === "For Renewal") {
      list = list.filter((r) => String(r.status || "").toLowerCase() === "renegotiation" || r.renewal_due);
    } else if (statusOpt === "Archived") {
      list = list.filter((r) => r.is_archived);
    } else if (statusOpt === "Pending") {
      list = list.filter((r) => String(r.status || "").toLowerCase() === "pending");
    }
  }

  if (riskOpt && riskOpt !== "All Risk") {
    const rl = riskOpt.toLowerCase();
    list = list.filter((r) => riskFromValue(r.contract_value) === rl);
  }

  if (vendorPick && vendorPick !== "all") {
    list = list.filter((r) => String(r.vendor_id ?? "") === vendorPick);
  }

  renderTable(list);
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
  bindCsvExport("export-contracts-csv", () => allRows, "ceo-all-contracts.csv");

  try {
    const [rowsRes, statsRes] = await Promise.allSettled([
      getContracts(),
      getDashboardStats("ceo"),
    ]);
    const rows = rowsRes.status === "fulfilled" ? rowsRes.value : [];
    const stats = statsRes.status === "fulfilled" && statsRes.value ? statsRes.value : {};
    allRows = Array.isArray(rows) ? rows : [];

    const statNums = document.querySelectorAll(".stats-row.col4 .stat-number");
    if (statNums[0]) statNums[0].textContent = String(stats.total_contracts ?? allRows.length);
    if (statNums[1]) statNums[1].textContent = String(stats.active_contracts ?? 0);
    if (statNums[2]) statNums[2].textContent = String(stats.expiring_30_days ?? 0);
    if (statNums[3]) statNums[3].textContent = String(stats.expired_approved ?? 0);

    const vendorSel = document.querySelectorAll(".filter-select")[2];
    if (vendorSel) {
      const seen = new Map();
      allRows.forEach((r) => {
        const id = String(r.vendor_id ?? "");
        if (id && !seen.has(id)) seen.set(id, String(r.vendor_name ?? "Vendor"));
      });
      const opts =
        `<option value="all">All Vendors</option>` +
        [...seen.entries()]
          .sort((a, b) => a[1].localeCompare(b[1]))
          .map(([id, name]) => `<option value="${escapeHtml(id)}">${escapeHtml(name)}</option>`)
          .join("");
      vendorSel.innerHTML = opts;
    }

    renderTable(allRows);

    document.querySelector(".filter-search")?.addEventListener("input", applyFilters);
    document.querySelectorAll(".filter-select").forEach((el) => el.addEventListener("change", applyFilters));
  } catch (e) {
    console.error("All contracts:", e.message);
    const host = document.getElementById("ceo-contracts-table-body");
    if (host) {
      host.innerHTML = `<div class="t-row" style="grid-template-columns:1fr;"><div class="t-cell-meta" style="text-align:center;padding:28px 0;color:var(--red);">
        Could not load contracts (${escapeHtml(e.message)}).
      </div></div>`;
    }
  }
});
