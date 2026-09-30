import {
  setApiContext,
  getContracts,
  getDashboardStats,
  getVendorSummary,
  openContractPdfInNewTab,
  postManagerReview,
} from "/assets/js/api-client.js";
import { isNet30Expiring, net30BadgeHtml } from "/assets/js/contract-flags.js";
import { contractRef } from "/assets/js/contract-intel.js";
import { askManagerConfirm } from "/assets/js/manager-confirm.js";
import { renderStarRating } from "/assets/js/star-rating.js";

/** vendor_id -> stored vendor score for the detail modal. */
let vendorScores = {};

function esc(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

function formatPeso(v) {
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
    maximumFractionDigits: 0,
  }).format(Number(v) || 0);
}

function parseDay(value) {
  if (!value) return null;
  const raw = String(value);
  const iso = raw.includes("T") ? raw : `${raw}T12:00:00`;
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? null : d;
}

function statusPillClass(st) {
  const s = String(st || "").toLowerCase();
  if (s === "approved") return "pill-active";
  if (s === "pending") return "pill-pending";
  if (s === "rejected") return "pill-rejected";
  return "pill-inactive";
}

let contractRows = [];
let openRow = null;

function setReviewVisible(show) {
  const box = document.getElementById("mgr-review-box");
  if (box) box.hidden = !show;
  ["mgr-contract-approve", "mgr-contract-modify", "mgr-contract-reject"].forEach((id) => {
    const btn = document.getElementById(id);
    if (btn) btn.hidden = !show;
  });
}

function fillModal(row) {
  openRow = row;
  const boxes = document.querySelectorAll("#modal-detail .contract-meta-box .cmb-value");
  if (boxes[0]) boxes[0].textContent = contractRef(row);
  if (boxes[1]) boxes[1].textContent = row.vendor_name || "—";
  if (boxes[2]) boxes[2].textContent = formatPeso(row.contract_value);
  const end = parseDay(row.end_date);
  if (boxes[3]) boxes[3].textContent = end ? end.toLocaleDateString() : "—";

  const clauses = document.querySelector("#modal-detail .detail-clause");
  if (clauses) {
    clauses.textContent =
      [
        row.scope_of_work,
        `Payment terms: ${row.payment_terms || "—"}`,
        row.renewal_terms ? `Renewal: ${row.renewal_terms}` : "",
        row.penalty_clause ? `Penalties: ${row.penalty_clause}` : "",
        row.financial_obligations ? `Obligations: ${row.financial_obligations}` : "",
      ]
        .filter(Boolean)
        .join("\n\n") || "—";
  }

  const detailRows = document.querySelectorAll("#modal-detail .detail-row .detail-val");
  const vendorScore = vendorScores[String(row.vendor_id)];
  if (detailRows[0]) detailRows[0].textContent = row.vendor_name || "—";
  if (detailRows[1]) detailRows[1].textContent = String(row.risk || "—").toUpperCase();
  if (detailRows[2]) renderStarRating(detailRows[2], vendorScore == null || vendorScore === "" ? null : vendorScore, { size: 15 });
  if (detailRows[3]) detailRows[3].textContent = row.ceo_notes || "—";

  const pending = String(row.status || "").toLowerCase() === "pending";
  setReviewVisible(pending);
  const notes = document.getElementById("mgr-review-notes");
  if (notes) notes.value = pending ? String(row.manager_notes || "") : "";

  const pdfBtn = document.getElementById("mgr-contract-view-pdf");
  if (pdfBtn) {
    pdfBtn.onclick = async () => {
      try {
        await openContractPdfInNewTab(row.id);
      } catch (e) {
        alert(e.message || "Could not open the PDF.");
      }
    };
  }
}

async function submitReview(action) {
  if (!openRow) return;
  const notes = document.getElementById("mgr-review-notes")?.value.trim() || "";
  if (action !== "escalate_to_ceo" && !notes) {
    alert("Add manager notes before rejecting or requesting a modification.");
    document.getElementById("mgr-review-notes")?.focus();
    return;
  }
  if (action === "modification") {
    const label = esc(openRow.contract_title || contractRef(openRow));
    const send = await askManagerConfirm({
      title: "Send to the vendor for modification?",
      message: `<strong>${label}</strong> will go back to the vendor with your notes. They can update the contract and resubmit it for review.`,
      confirmLabel: "Send to vendor",
    });
    if (!send) return;
  } else {
    const prompts = {
      escalate_to_ceo: "Send this contract to the CEO for approval?",
      reject: "Recommend that the CEO reject this contract?",
    };
    if (!confirm(prompts[action] || "Save this review?")) return;
  }
  await postManagerReview({
    id: openRow.id,
    manager_notes: notes,
    recommended_action: action,
  });
  globalThis.closeModal?.("modal-detail");
  await loadContracts();
}

globalThis.openModal = function (id) {
  document.getElementById(id)?.classList.add("open");
};
globalThis.closeModal = function (id) {
  document.getElementById(id)?.classList.remove("open");
};

globalThis.viewManagerContract = function (id) {
  const row = contractRows.find((r) => String(r.id) === String(id));
  if (!row) return;
  fillModal(row);
  globalThis.openModal("modal-detail");
};

async function loadContracts() {
  try {
    const settled = await Promise.allSettled([
      getContracts(),
      getDashboardStats("manager"),
      getVendorSummary(),
    ]);
    const contracts = settled[0].status === "fulfilled" ? settled[0].value : [];
    const stats = settled[1].status === "fulfilled" && settled[1].value ? settled[1].value : {};
    const summary = settled[2].status === "fulfilled" && Array.isArray(settled[2].value) ? settled[2].value : [];
    contractRows = Array.isArray(contracts) ? contracts : [];

    vendorScores = {};
    for (const s of summary) {
      if (s?.vendor_id != null && s.has_manual_score) vendorScores[String(s.vendor_id)] = s.vendor_score ?? s.ai_score;
    }

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    let expired = 0;
    for (const r of contractRows) {
      const end = parseDay(r.end_date);
      if (end && end < today) expired++;
    }

    const statNums = document.querySelectorAll(".stats-row .stat-number");
    if (statNums[0]) statNums[0].textContent = String(stats.total_contracts ?? contractRows.length);
    if (statNums[1]) statNums[1].textContent = String(stats.approved ?? 0);
    if (statNums[2]) statNums[2].textContent = String(stats.expiring_30_days ?? 0);
    if (statNums[3]) statNums[3].textContent = String(expired);

    const body = document.getElementById("contracts-body");
    const foot = document.querySelector("#contracts-body")?.closest(".table-card")?.querySelector(".t-showing");
    if (!body) return;

    if (contractRows.length === 0) {
      body.innerHTML = `<div style="padding:60px 20px;text-align:center;">
        <div style="font-size:14px;font-weight:600;color:var(--gray-500);margin-bottom:6px;">No contracts yet</div>
        <div style="font-size:12px;color:var(--gray-400);">Submitted vendor contracts will appear here.</div>
      </div>`;
      if (foot) foot.textContent = "0 contracts";
      return;
    }

    body.innerHTML = contractRows
      .map((r) => {
        const end = parseDay(r.end_date);
        const endLabel = end ? end.toLocaleDateString() : "—";
        const st = String(r.status || "").toUpperCase();
        const net30 = isNet30Expiring(r.end_date) ? ` ${net30BadgeHtml()}` : "";
        const idAttr = String(r.id ?? "").replace(/"/g, "&quot;");
        return `
      <div class="t-row" style="grid-template-columns:1.4fr 2fr 1.4fr 1.2fr 1fr 1fr 1fr;">
        <div class="t-cell-meta">${esc(contractRef(r))}</div>
        <div>
          <div class="t-cell-primary">${esc(r.contract_title || "Untitled")}</div>
          <div class="t-cell-meta">${esc(r.vendor_name || "")} · ${esc(r.payment_terms || "—")}</div>
        </div>
        <div>${formatPeso(r.contract_value)}</div>
        <div>${esc(endLabel)}${net30}</div>
        <div><span class="pill ${statusPillClass(r.status)}">${esc(st)}</span></div>
        <div class="t-cell-secondary">${esc(r.manager_reviewed_at ? "Reviewed" : "—")}</div>
        <div>
          <button type="button" class="btn btn-ghost btn-sm" data-view-id="${idAttr}">View</button>
        </div>
      </div>`;
      })
      .join("");

    body.querySelectorAll("[data-view-id]").forEach((btn) => {
      btn.addEventListener("click", () => globalThis.viewManagerContract(btn.getAttribute("data-view-id")));
    });

    if (foot) foot.textContent = `${contractRows.length} contracts`;
  } catch (e) {
    console.error(e);
  }
}

document.addEventListener("DOMContentLoaded", () => {
  const saved = JSON.parse(localStorage.getItem("contrack_user") || "{}");
  setApiContext({ role: saved.role || "manager", userId: saved.id || null });

  const bind = (id, action) => {
    document.getElementById(id)?.addEventListener("click", async () => {
      try {
        await submitReview(action);
      } catch (e) {
        alert(e.message || "Could not save this review.");
      }
    });
  };
  bind("mgr-contract-approve", "escalate_to_ceo");
  bind("mgr-contract-modify", "modification");
  bind("mgr-contract-reject", "reject");

  loadContracts();
});
