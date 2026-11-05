import {
  setApiContext,
  getPendingApprovals,
  getVendorSummary,
  openContractPdfInNewTab,
  postContractDecision,
} from "/assets/js/api-client.js";
import { contractRef } from "/assets/js/contract-intel.js";
import { renderStarRating } from "/assets/js/star-rating.js";

let rows = [];
let current = null;
/** vendor_id -> ai_score, used for the VENDOR SCORE row in the decision modal. */
let vendorScores = {};

function formatPeso(v) {
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
    maximumFractionDigits: 0,
  }).format(Number(v) || 0);
}

function riskFromValue(value) {
  const v = Number(value) || 0;
  if (v >= 5000000) return { label: "high", pill: "pill-high" };
  if (v >= 1000000) return { label: "medium", pill: "pill-med" };
  return { label: "low", pill: "pill-low" };
}

function labelRecommendedAction(action) {
  const k = String(action ?? "").trim();
  const map = {
    escalate_to_ceo: "Recommend CEO approval",
    reject: "Recommend rejection",
    renegotiation: "Recommend renegotiation",
  };
  return map[k] || k || "";
}

function managerSummary(r) {
  const note = r.manager_notes ? String(r.manager_notes).trim() : "";
  const actionLabel = labelRecommendedAction(r.recommended_action);
  const parts = [note, actionLabel].filter(Boolean);
  return parts.length ? parts.join(" · ") : "—";
}

/** Pending contracts already reviewed by a manager (CEO decision queue). */
function pendingContractsForCeo() {
  const pending = rows.filter((r) => {
    const isPending = String(r.status || "").toLowerCase() === "pending";
    return isPending && !!r.manager_reviewed_at;
  });
  return pending.sort((a, b) => {
    const ta = new Date(a.manager_reviewed_at || a.uploaded_at || 0).getTime();
    const tb = new Date(b.manager_reviewed_at || b.uploaded_at || 0).getTime();
    return tb - ta;
  });
}

function renderList(filterRisk) {
  const wrap = document.getElementById("approvals-list");
  const badge = document.querySelector(".page-header .stat-badge");
  if (!wrap) return;

  const queue = pendingContractsForCeo();
  let list = queue;
  if (filterRisk && filterRisk !== "all") {
    list = list.filter((r) => riskFromValue(r.contract_value).label === filterRisk);
  }

  const sbBadge = document.getElementById("ceo-approvals-badge");
  if (sbBadge) sbBadge.textContent = String(queue.length);
  const topNotif = document.getElementById("notif-badge");
  if (topNotif) {
    topNotif.textContent = String(queue.length);
    topNotif.style.display = queue.length ? "flex" : "none";
  }

  if (badge) badge.textContent = `${list.length} Pending`;

  if (list.length === 0) {
    const filteredEmpty = queue.length > 0 && filterRisk && filterRisk !== "all";

    const empty = filteredEmpty
      ? {
          title: "No contracts in this category",
          sub: "Try another risk filter, or choose All.",
        }
      : {
          title: "Nothing awaiting your decision",
          sub: "Contracts appear here once a manager has reviewed them.",
        };

    wrap.innerHTML = `<div style="background:#fff;border-radius:var(--radius);border:1px solid var(--gray-200);padding:48px;text-align:center;">
      <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--gray-300)" stroke-width="1.5" style="margin:0 auto 12px;display:block;"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
      <div style="font-size:15px;font-weight:600;color:var(--gray-500);margin-bottom:6px;">${escapeHtml(empty.title)}</div>
      <div style="font-size:12px;color:var(--gray-400);max-width:360px;margin:0 auto;">${escapeHtml(empty.sub)}</div>
    </div>`;
    wrap.onclick = null;
    return;
  }

  wrap.innerHTML = list
    .map((r) => {
      const risk = riskFromValue(r.contract_value);
      const riskClass = `risk-${risk.label}`;
      const mgr = managerSummary(r).slice(0, 160);
      const cid = String(r.id);
      const cidAttr = cid.replace(/&/g, "&amp;").replace(/"/g, "&quot;");
      const mgrLine = r.manager_reviewed_at
        ? `<div style="font-size:12px;color:var(--gray-600);margin-top:10px;"><strong>Manager:</strong> ${escapeHtml(mgr)}</div>`
        : `<div style="font-size:12px;color:var(--orange);margin-top:10px;font-weight:600;">Awaiting manager review — you can still open and decide.</div>`;
      return `
    <div class="approval-card ${riskClass}" data-contract-id="${cidAttr}">
      <div class="approval-card-main">
        <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;">
          <div>
            <div style="font-weight:700;color:var(--gray-900);">${escapeHtml(r.contract_title)}</div>
            <div style="font-size:12px;color:var(--gray-500);margin-top:6px;">${escapeHtml(contractRef(r))} · ${escapeHtml(r.vendor_name)} · ${formatPeso(r.contract_value)}</div>
            ${mgrLine}
          </div>
          <div style="text-align:right;">
            <span class="pill ${risk.pill}">${risk.label.toUpperCase()} RISK</span>
            <div style="margin-top:10px;font-size:11px;color:var(--gray-400);">Uploaded ${r.uploaded_at ? escapeHtml(new Date(r.uploaded_at).toLocaleString()) : ""}</div>
          </div>
        </div>
      </div>
      <div class="approval-card-actions">
        <button type="button" class="approval-btn-outline" data-open="${cidAttr}">Review &amp; decide</button>
        <button type="button" class="approve-btn approval-btn-compact" data-quick="approve" data-qid="${cidAttr}">Approve</button>
        <button type="button" class="reject-btn approval-btn-compact" data-quick="reject" data-qid="${cidAttr}">Reject</button>
      </div>
    </div>`;
    })
    .join("");

  wrap.onclick = (e) => {
    const quick = e.target.closest("[data-quick]");
    if (quick) {
      e.preventDefault();
      e.stopPropagation();
      const id = quick.getAttribute("data-qid");
      const q = quick.getAttribute("data-quick");
      if (id && (q === "approve" || q === "reject")) void quickDecision(id, q);
      return;
    }
    const openBtn = e.target.closest("[data-open]");
    if (openBtn) {
      e.preventDefault();
      e.stopPropagation();
      const id = openBtn.getAttribute("data-open");
      const row = rows.find((x) => String(x.id) === String(id));
      if (row) openApprovalModal(row);
      return;
    }
    const card = e.target.closest("[data-contract-id]");
    if (card && !e.target.closest(".approval-card-actions")) {
      const id = card.getAttribute("data-contract-id");
      const row = rows.find((x) => String(x.id) === String(id));
      if (row) openApprovalModal(row);
    }
  };
}

async function quickDecision(rawId, action) {
  const row = rows.find((x) => String(x.id) === String(rawId));
  if (!row) return;
  const ok =
    action === "approve"
      ? confirm("Approve this contract?")
      : confirm("Reject this contract?");
  if (!ok) return;
  current = row;
  const ta = document.getElementById("decision-notes");
  if (ta) ta.value = "";
  await handleDecision(action === "approve" ? "approve" : "reject");
}

function escapeHtml(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function openApprovalModal(row) {
  current = row;
  const risk = riskFromValue(row.contract_value);
  document.getElementById("m-id").textContent = contractRef(row);
  document.getElementById("m-vendor").textContent = row.vendor_name || "—";
  document.getElementById("m-value").textContent = formatPeso(row.contract_value);
  document.getElementById("m-risk").innerHTML = `<span class="pill ${risk.pill}">${risk.label.toUpperCase()}</span>`;
  const vendorScore = vendorScores[String(row.vendor_id)];
  renderStarRating(
    document.getElementById("m-score"),
    vendorScore == null || vendorScore === "" ? null : vendorScore,
    { size: 15 },
  );
  document.getElementById("m-notes").textContent = managerSummary(row);
  document.getElementById("decision-notes").value = "";
  const pdfBtn = document.getElementById("ceo-open-pdf");
  if (pdfBtn) {
    pdfBtn.hidden = false;
    pdfBtn.onclick = async () => {
      try {
        await openContractPdfInNewTab(row.id);
      } catch (e) {
        alert(e.message || "Could not open PDF.");
      }
    };
  }
  globalThis.openModal?.("modal-approval");
}

async function handleDecision(action) {
  if (!current) return;
  const status = action === "approve" ? "approved" : "rejected";
  const notes = document.getElementById("decision-notes")?.value?.trim() || "";
  try {
    await postContractDecision({
      id: current.id,
      status,
      ceo_notes: notes || null,
    });
    globalThis.closeModal?.("modal-approval");
    await reload();
  } catch (e) {
    alert(e.message || "Decision failed");
  }
}

async function reload() {
  // allSettled: a failing vendor-score call must not hide the approval queue.
  const [queueRes, summaryRes] = await Promise.allSettled([
    getPendingApprovals(),
    getVendorSummary(),
  ]);
  rows = queueRes.status === "fulfilled" && Array.isArray(queueRes.value) ? queueRes.value : [];

  vendorScores = {};
  if (summaryRes.status === "fulfilled" && Array.isArray(summaryRes.value)) {
    for (const s of summaryRes.value) {
      if (s?.vendor_id != null && s.has_manual_score) vendorScores[String(s.vendor_id)] = s.vendor_score ?? s.ai_score;
    }
  }
  renderList(window.__ceoApprovalFilter || "all");
}

globalThis.openModal = function (id) {
  document.getElementById(id)?.classList.add("open");
};
globalThis.closeModal = function (id) {
  document.getElementById(id)?.classList.remove("open");
};

globalThis.handleDecision = handleDecision;

document.addEventListener("DOMContentLoaded", async () => {
  document.getElementById("modal-approval-cancel")?.addEventListener("click", () => globalThis.closeModal?.("modal-approval"));
  document.getElementById("modal-approval-close")?.addEventListener("click", () => globalThis.closeModal?.("modal-approval"));
  document.getElementById("modal-approval-approve")?.addEventListener("click", () => handleDecision("approve"));
  document.getElementById("modal-approval-reject")?.addEventListener("click", () => handleDecision("reject"));

  const saved = JSON.parse(localStorage.getItem("contrack_user") || "{}");
  setApiContext({ role: saved.role || "ceo", userId: saved.id || null });
  window.__ceoApprovalFilter = "all";
  await reload();

  globalThis.filterApprovals = function filterApprovals(btn, risk) {
    document.querySelectorAll(".af-btn").forEach((b) => b.classList.remove("on"));
    if (btn) btn.classList.add("on");
    window.__ceoApprovalFilter = risk;
    renderList(risk);
  };

  globalThis.openApproval = openApprovalModal;
});
