import { setApiContext, getContracts, getDashboardStats, openContractPdfInNewTab } from "/assets/js/api-client.js";
import { applyVendorIdentity } from "/assets/js/vendor-session.js";
import { starRatingHtml } from "/assets/js/star-rating.js";
import {
  contractRef,
  formatPaymentTerms,
  isArchivedContract,
  isRenewalDue,
  monitorLabel,
  monitorPillClass,
  riskFromRow,
} from "/assets/js/contract-intel.js";

function formatDate(value) {
  if (!value) return "—";
  return new Date(value).toLocaleDateString();
}

function formatPeso(v) {
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
    maximumFractionDigits: 0,
  }).format(Number(v) || 0);
}

let allContracts = [];
let filters = { q: "", status: "", type: "" };

function fillModal(row) {
  const pill = document.getElementById("modal-status-pill");
  if (pill) {
    pill.textContent = monitorLabel(row);
    pill.className = `pill ${monitorPillClass(row)}`;
  }
  const idEl = document.getElementById("modal-contract-id");
  if (idEl) idEl.textContent = `Contract ID: ${contractRef(row)}`;
  const title = document.getElementById("modal-title");
  if (title) title.textContent = row.contract_title || "—";
  const val = document.getElementById("modal-value");
  if (val) val.textContent = formatPeso(row.contract_value);
  const start = document.getElementById("modal-start");
  if (start) start.textContent = formatDate(row.start_date);
  const end = document.getElementById("modal-end");
  if (end) end.textContent = formatDate(row.end_date);

  const clauses = document.querySelectorAll("#modal-detail .detail-clause");
  if (clauses[0]) {
    clauses[0].innerHTML = `<strong>Renewal Clause:</strong> ${row.renewal_terms || "No renewal clause extracted."}`;
  }
  if (clauses[1]) {
    clauses[1].innerHTML = `<strong>Payment Terms:</strong> ${formatPaymentTerms(row.payment_terms)}${row.penalty_clause ? ` · Penalty: ${row.penalty_clause}` : ""}`;
  }
  if (clauses[2]) {
    clauses[2].innerHTML = `<strong>Financial Obligations:</strong> ${row.financial_obligations || formatPeso(row.contract_value)}`;
  }

  const detailVals = document.querySelectorAll("#modal-detail .detail-row .detail-val");
  const risk = riskFromRow(row);
  if (detailVals[0]) detailVals[0].innerHTML = `<span class="pill pill-${risk}">${risk.toUpperCase()}</span>`;
  if (detailVals[1]) {
    const kind = String(row.classification || "—")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");
    const scored = row.risk_score != null && row.risk_score !== "";
    detailVals[1].innerHTML = `${kind} · ${scored ? starRatingHtml(row.risk_score, { size: 14 }) : "—"}`;
  }
  if (detailVals[2]) detailVals[2].textContent = row.uploaded_at ? new Date(row.uploaded_at).toLocaleString() : "—";

  const note = document.getElementById("modal-modify-note");
  const submitAnother = document.getElementById("modal-submit-another");
  const needsChanges = String(row.status || "").toLowerCase() === "modification";
  if (note) {
    note.hidden = !needsChanges;
    note.textContent = needsChanges
      ? `The manager asked for changes${row.manager_notes ? `: ${row.manager_notes}` : "."} Submit another contract for review.`
      : "";
  }
  if (submitAnother) submitAnother.hidden = !needsChanges;

  const pdfBtn = document.getElementById("vendor-view-pdf");
  if (pdfBtn) {
    pdfBtn.onclick = async () => {
      try {
        await openContractPdfInNewTab(row.id);
      } catch (e) {
        alert(e.message || "Could not open PDF.");
      }
    };
  }
}

function applyFilters() {
  let list = allContracts.slice();
  const q = filters.q.trim().toLowerCase();
  if (q) {
    list = list.filter((r) => {
      const blob = `${contractRef(r)} ${r.contract_title} ${r.vendor_name} ${r.classification || ""}`.toLowerCase();
      return blob.includes(q);
    });
  }
  if (filters.status) {
    if (filters.status === "archived") list = list.filter(isArchivedContract);
    else if (filters.status === "expiring") {
      list = list.filter((r) => String(r.monitor_status) === "expiring" || (isRenewalDue(r) && !isArchivedContract(r)));
    } else if (filters.status === "expired") {
      list = list.filter((r) => monitorLabel(r) === "EXPIRED" || isArchivedContract(r));
    } else if (filters.status === "active") {
      list = list.filter((r) => monitorLabel(r) === "ACTIVE");
    } else if (filters.status === "pending") {
      list = list.filter((r) => String(r.status).toLowerCase() === "pending");
    } else if (filters.status === "modification") {
      list = list.filter((r) => String(r.status).toLowerCase() === "modification");
    } else if (filters.status === "renewal") {
      list = list.filter(isRenewalDue);
    }
  }
  if (filters.type) {
    list = list.filter((r) => String(r.contract_type || r.classification || "").toLowerCase().includes(filters.type));
  }
  return list;
}

function renderTable() {
  const body = document.getElementById("contracts-table-body");
  const empty = document.getElementById("contracts-empty");
  const showing = document.querySelector(".t-showing");
  if (!body) return;
  const list = applyFilters();
  body.innerHTML = "";
  if (!list.length) {
    if (empty) empty.style.display = "";
    if (showing) showing.textContent = "Showing 0 contracts";
    return;
  }
  // Hide rather than remove: a later filter may need to show the empty state again.
  if (empty) empty.style.display = "none";
  list.forEach((item) => {
    const risk = riskFromRow(item);
    const row = document.createElement("div");
    row.className = "t-row";
    row.style.gridTemplateColumns = "2fr 1.5fr 2fr 1.2fr 1fr 1fr";
    row.innerHTML = `
      <div><div class="t-cell-primary">${item.contract_title || "Untitled"}</div><div class="t-cell-meta">${contractRef(item)} · ${formatPaymentTerms(item.payment_terms)}</div></div>
      <div class="t-cell-secondary">₱${Number(item.contract_value || 0).toLocaleString()}</div>
      <div class="t-cell-meta">${formatDate(item.start_date)} → ${formatDate(item.end_date)}</div>
      <div><span class="pill ${monitorPillClass(item)}">${monitorLabel(item)}</span></div>
      <div><span class="pill pill-${risk}">${risk.toUpperCase()}</span></div>
      <div><button class="btn btn-ghost btn-sm" data-view="${item.id}">View</button></div>
    `;
    body.appendChild(row);
  });
  body.querySelectorAll("[data-view]").forEach((btn) => {
    btn.addEventListener("click", () => {
      const row = allContracts.find((r) => String(r.id) === String(btn.getAttribute("data-view")));
      if (!row) return;
      fillModal(row);
      globalThis.openModal?.("modal-detail");
    });
  });
  if (showing) showing.textContent = `Showing ${list.length} of ${allContracts.length} contracts`;
}

globalThis.filterContracts = function filterContracts(q) {
  filters.q = q || "";
  renderTable();
};
globalThis.filterByStatus = function filterByStatus(s) {
  filters.status = s || "";
  renderTable();
};
globalThis.filterByType = function filterByType(t) {
  filters.type = t || "";
  renderTable();
};

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

async function loadContracts() {
  const [contractsRes, statsRes] = await Promise.allSettled([
    getContracts(),
    getDashboardStats("vendor"),
  ]);
  const contracts = contractsRes.status === "fulfilled" ? contractsRes.value : [];
  const stats = statsRes.status === "fulfilled" && statsRes.value ? statsRes.value : {};
  allContracts = Array.isArray(contracts) ? contracts : [];
  const nums = document.querySelectorAll(".stat-number");
  if (nums[0]) nums[0].textContent = String(stats.total_contracts || allContracts.length);
  if (nums[1]) nums[1].textContent = String(stats.approved || 0);
  if (nums[2]) nums[2].textContent = String(stats.pending || 0);
  if (nums[3]) nums[3].textContent = String(stats.expiring_30_days || stats.renewal_due || 0);

  const alertDesc = document.querySelector(".alert-item.warn .alert-desc");
  if (alertDesc) {
    const due = allContracts.filter(isRenewalDue).length;
    const archived = allContracts.filter(isArchivedContract).length;
    alertDesc.textContent = `${due} contract(s) subject for renewal. ${archived} inactive contract(s) are archived.`;
  }
  renderTable();
}

document.addEventListener("DOMContentLoaded", async () => {
  const user = applyVendorIdentity();
  if (!user?.id) return;
  setApiContext({ role: "vendor", userId: user.id });

  try {
    await loadContracts();
  } catch (error) {
    console.error(error);
  }
});
