import { setApiContext, getContracts } from "/assets/js/api-client.js";
import { isNet30Expiring, net30BadgeHtml } from "/assets/js/contract-flags.js";
import { contractRef } from "/assets/js/contract-intel.js";

function esc(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function formatPeso(v) {
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
    maximumFractionDigits: 0,
  }).format(Number(v) || 0);
}

function statusPillClass(st) {
  const s = String(st || "").toLowerCase();
  if (s === "approved") return "pill-active";
  if (s === "pending") return "pill-pending";
  if (s === "rejected") return "pill-rejected";
  return "pill-inactive";
}

/** The API enriches each row with `risk`; fall back to contract value if it is absent. */
function riskLevel(row) {
  const r = String(row?.risk || "").toLowerCase();
  if (r === "low" || r === "medium" || r === "high") return r;
  const value = Number(row?.contract_value || 0);
  if (value >= 5000000) return "high";
  if (value >= 1000000) return "medium";
  return "low";
}

let adminContractRows = [];

function fillModal(row) {
  const st = String(row.status || "").toUpperCase();
  const pill = document.getElementById("modal-status-pill");
  if (pill) {
    pill.textContent = st || "—";
    pill.className = `pill ${statusPillClass(row.status)}`;
  }
  const setTxt = (id, val) => {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
  };
  setTxt("modal-contract-no", contractRef(row));
  setTxt("modal-vendor", row.vendor_name || "—");
  setTxt("modal-value", formatPeso(row.contract_value));
  const start = row.start_date ? new Date(row.start_date).toLocaleDateString() : "—";
  const end = row.end_date ? new Date(row.end_date).toLocaleDateString() : "—";
  setTxt("modal-duration", `${start} → ${end}`);
  setTxt("modal-uploader", row.vendor_name || "—");
  setTxt(
    "modal-upload-date",
    row.uploaded_at ? new Date(row.uploaded_at).toLocaleString() : "—",
  );
  const riskEl = document.getElementById("modal-risk");
  if (riskEl) {
    const risk = String(row.risk || "").toLowerCase() || (Number(row.contract_value) >= 5000000 ? "high" : Number(row.contract_value) >= 1000000 ? "medium" : "low");
    riskEl.textContent = risk.toUpperCase();
    riskEl.className = `pill pill-${risk}`;
  }
  setTxt("modal-score", String(row.classification || row.risk_score || "—"));
}

globalThis.openModal = function (id) {
  document.getElementById(id)?.classList.add("open");
};
globalThis.closeModal = function (id) {
  document.getElementById(id)?.classList.remove("open");
};

globalThis.openContractDetail = function (id) {
  const row = adminContractRows.find((r) => String(r.id) === String(id));
  if (!row) return;
  fillModal(row);
  globalThis.openModal("modal-contract-detail");
};

document.addEventListener("DOMContentLoaded", async () => {
  const saved = JSON.parse(localStorage.getItem("contrack_user") || "{}");
  setApiContext({ role: saved.role || "admin", userId: saved.id || null });

  const body = document.getElementById("admin-contracts-body");
  const showing = document.querySelector(".t-showing");
  if (!body) return;

  try {
    const contracts = await getContracts();
    adminContractRows = Array.isArray(contracts) ? contracts : [];

    if (adminContractRows.length === 0) {
      body.innerHTML = `<div class="t-empty" style="border:none;">
          <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
          No contracts found.
        </div>`;
      if (showing) showing.textContent = "Showing 0 of 0 contracts";
      return;
    }

    body.innerHTML = adminContractRows
      .map(
        (r) => `
      <div class="t-row" style="grid-template-columns:110px 2fr 1.5fr 100px 80px 80px 90px;">
        <div class="t-cell-meta">${esc(contractRef(r))}</div>
        <div class="t-cell-primary">${esc(r.contract_title || "Untitled")}${isNet30Expiring(r.end_date) ? ` ${net30BadgeHtml()}` : ""}</div>
        <div class="t-cell-secondary">${esc(r.vendor_name || "—")}</div>
        <div class="t-cell-secondary">${formatPeso(r.contract_value)}</div>
        <div><span class="pill ${statusPillClass(r.status)}">${esc(String(r.status || "").toUpperCase())}</span></div>
        <div><span class="pill pill-${riskLevel(r)}">${riskLevel(r).toUpperCase()}</span></div>
        <div class="t-actions">
          <button type="button" class="t-action-btn" title="View" data-view-id="${esc(r.id)}">
            <svg viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
          </button>
        </div>
      </div>`,
      )
      .join("");

    body.onclick = (event) => {
      const btn = event.target.closest("[data-view-id]");
      if (btn) globalThis.openContractDetail(btn.getAttribute("data-view-id"));
    };

    if (showing) showing.textContent = `Showing ${adminContractRows.length} contracts`;
  } catch (e) {
    console.error(e);
    body.innerHTML = `<div class="t-empty" style="border:none;">Could not load contracts.</div>`;
  }
});
