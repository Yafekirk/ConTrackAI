import {
  setApiContext,
  getManagerPending,
  getManagerMine,
  openContractPdfInNewTab,
  postManagerReview,
} from "/assets/js/api-client.js";
import { contractRef } from "/assets/js/contract-intel.js";
import { askManagerConfirm } from "/assets/js/manager-confirm.js";

let pending = [];
let mine = [];
let detailRow = null;

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

/** Maps stored API values to labels shown in the modal summary. */
function labelRecommendedAction(action) {
  const k = String(action ?? "").trim();
  const map = {
    escalate_to_ceo: "Recommend CEO approval",
    reject: "Recommend rejection",
    renegotiation: "Recommend renegotiation",
    modification: "Sent back for modification",
  };
  return map[k] || k || "";
}

globalThis.openModal = function (id) {
  document.getElementById(id)?.classList.add("open");
};
globalThis.closeModal = function (id) {
  document.getElementById(id)?.classList.remove("open");
};

function updateStats() {
  const statNums = document.querySelectorAll(".stats-row .stat-number");
  const total = mine.length;
  const pendingCeo = mine.filter((r) => String(r.status).toLowerCase() === "pending").length;
  const approved = mine.filter((r) => String(r.status).toLowerCase() === "approved").length;
  const rework = mine.filter((r) =>
    ["modification", "renegotiation"].includes(String(r.status).toLowerCase()),
  ).length;
  if (statNums[0]) statNums[0].textContent = String(total);
  if (statNums[1]) statNums[1].textContent = String(approved);
  if (statNums[2]) statNums[2].textContent = String(pendingCeo);
  if (statNums[3]) statNums[3].textContent = String(rework);
}

function matchesCeoStatus(r, pick) {
  const s = String(r.status || "").toLowerCase();
  if (pick === "Approved") return s === "approved";
  if (pick === "Pending") return s === "pending";
  if (pick === "Rejected") return s === "rejected" || s === "terminated";
  if (pick === "Revision") return s === "modification" || s === "renegotiation";
  return true;
}

/** Date filter on the time the row last moved: the manager review, else the vendor upload. */
function matchesDate(r, pick) {
  if (!pick || pick === "All Dates") return true;
  const when = new Date(r.manager_reviewed_at || r.uploaded_at || 0);
  if (Number.isNaN(when.getTime()) || when.getTime() === 0) return false;
  const start = new Date();
  start.setHours(0, 0, 0, 0);
  if (pick === "This Week") start.setDate(start.getDate() - start.getDay());
  if (pick === "This Month") start.setDate(1);
  return when >= start;
}

function filteredQueue(rows) {
  const q = (document.querySelector(".filter-search")?.value || "").trim().toLowerCase();
  const sels = document.querySelectorAll(".filter-bar .filter-select");
  const text = (i) => sels[i]?.options[sels[i].selectedIndex]?.text?.trim() || "";
  const statusPick = text(0);
  const datePick = text(1);
  return rows.filter((r) => {
    if (q) {
      const status = String(r.status || "").toLowerCase();
      const hay = [contractRef(r), r.id, r.contract_title, r.vendor_name, status, status === "modification" ? "modify" : ""]
        .map((x) => String(x ?? "").toLowerCase())
        .join(" ");
      if (!hay.includes(q)) return false;
    }
    if (statusPick && statusPick !== "All CEO Status" && !matchesCeoStatus(r, statusPick)) return false;
    return matchesDate(r, datePick);
  });
}

function renderQueue() {
  const body = document.getElementById("submissions-body");
  if (!body) return;

  const seen = new Set();
  const allQueue = [...pending, ...mine]
    .filter((r) => {
      const id = String(r.id);
      if (!id || seen.has(id)) return false;
      seen.add(id);
      return true;
    })
    .sort((a, b) => {
      const ta = new Date(a.uploaded_at || a.manager_reviewed_at || 0).getTime();
      const tb = new Date(b.uploaded_at || b.manager_reviewed_at || 0).getTime();
      return tb - ta;
    });
  const rows = filteredQueue(allQueue);
  const meta = body.closest(".table-card")?.querySelector(".t-showing");
  if (meta) meta.textContent = `Showing ${rows.length} of ${allQueue.length} submissions`;

  if (allQueue.length === 0) {
    body.innerHTML = `<div style="padding:60px 20px;text-align:center;">
      <div style="font-size:14px;font-weight:600;color:var(--gray-500);margin-bottom:6px;">No submissions yet</div>
      <div style="font-size:12px;color:var(--gray-400);">Vendor contracts appear here after submission.</div>
    </div>`;
    return;
  }
  if (rows.length === 0) {
    body.innerHTML = `<div style="padding:48px 20px;text-align:center;">
      <div style="font-size:14px;font-weight:600;color:var(--gray-500);margin-bottom:6px;">No submissions match the current filters</div>
      <div style="font-size:12px;color:var(--gray-400);">Clear the search or choose different filters.</div>
    </div>`;
    return;
  }

  body.innerHTML = rows
    .map((r) => {
      const rawStatus = String(r.status || "").toLowerCase();
      const st = rawStatus === "modification" ? "MODIFY" : String(r.status || "").toUpperCase();
      const reviewed = r.manager_reviewed_at ? new Date(r.manager_reviewed_at).toLocaleString() : "—";
      const dec = r.reviewed_at ? new Date(r.reviewed_at).toLocaleString() : "—";
      const isPending = String(r.status).toLowerCase() === "pending";
      const canReview = !r.manager_reviewed_at && isPending;
      const canReReview = !!r.manager_reviewed_at && isPending;
      const actions = canReview
        ? `<button type="button" class="btn btn-primary btn-sm" data-review="${r.id}">Review</button>`
        : canReReview
          ? `<div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
              <button type="button" class="btn btn-ghost btn-sm" data-view="${r.id}">View</button>
              <button type="button" class="btn btn-primary btn-sm" data-review="${r.id}">Re-review</button>
            </div>`
          : `<button type="button" class="btn btn-ghost btn-sm" data-view="${r.id}">View</button>`;
      return `
      <div class="t-row" style="grid-template-columns:2.2fr 1.4fr 1.3fr 1.3fr 1fr 1fr;">
        <div>
          <div class="t-cell-primary">${esc(r.contract_title)}</div>
          <div class="t-cell-meta">${esc(contractRef(r))}</div>
        </div>
        <div>${formatPeso(r.contract_value)}</div>
        <div>${esc(reviewed)}</div>
        <div><span class="pill pill-active">${esc(st)}</span></div>
        <div>${esc(dec)}</div>
        <div>${actions}</div>
      </div>`;
    })
    .join("");

  body.querySelectorAll("[data-review]").forEach((b) => {
    b.addEventListener("click", () => openReview(String(b.getAttribute("data-review") ?? ""), true));
  });
  body.querySelectorAll("[data-view]").forEach((b) => {
    b.addEventListener("click", () => openReview(String(b.getAttribute("data-view") ?? ""), false));
  });
}

function contractTerm(r) {
  const start = r.start_date ? String(r.start_date).slice(0, 10) : "";
  const end = r.end_date ? String(r.end_date).slice(0, 10) : "";
  if (!start && !end) return "";
  return `${start || "—"} to ${end || "—"}`;
}

function renderAiSummary(r) {
  const host = document.getElementById("ai-summary");
  if (!host) return;
  const risk = r.risk ? String(r.risk).toUpperCase() + (r.risk_score != null && r.risk_score !== "" ? ` (score ${r.risk_score})` : "") : "";
  const rows = [
    ["CONTRACT TITLE", r.contract_title],
    ["CLASSIFICATION", r.classification || r.contract_type],
    ["CONTRACT TERM", contractTerm(r)],
    ["PAYMENT TERMS", r.payment_terms],
    ["RENEWAL", r.renewal_terms],
    ["RISK LEVEL", risk],
    ["SCOPE OF WORK", r.scope_of_work],
    ["PENALTIES", r.penalty_clause],
    ["TERMINATION", r.termination_clause],
    ["FINANCIAL OBLIGATIONS", r.financial_obligations],
  ].filter(([, v]) => v != null && String(v).trim() !== "");
  host.innerHTML = rows.length
    ? rows
        .map(
          ([k, v]) => `<div class="ai-summary-row">
            <div class="ai-summary-key">${esc(k)}</div>
            <div class="ai-summary-val">${esc(String(v).trim())}</div>
          </div>`,
        )
        .join("")
    : `<div class="ai-summary-empty">No clauses were extracted for this contract. Open the original PDF to review it.</div>`;
}

function openReview(id, editable) {
  document.getElementById("mq-save")?.remove();
  document.getElementById("modal-view-footer")?.classList.remove("modal-footer--review");
  document.getElementById("modal-review-actions")?.remove();

  const row = [...pending, ...mine].find((x) => String(x.id) === String(id));
  if (!row) return;
  detailRow = row;
  const boxes = document.querySelectorAll("#modal-view .contract-meta-box .cmb-value");
  if (boxes[0]) boxes[0].textContent = contractRef(row);
  if (boxes[1]) boxes[1].textContent = row.vendor_name || "—";
  if (boxes[2]) boxes[2].textContent = formatPeso(row.contract_value);
  if (boxes[3]) boxes[3].textContent = String(row.status || "").toUpperCase();
  renderAiSummary(row);

  const mgrEl = document.getElementById("mgr-clause");
  const ceoEl = document.getElementById("ceo-clause");
  const notePart = row.manager_notes ? String(row.manager_notes).trim() : "";
  const actionPart = labelRecommendedAction(row.recommended_action);
  const summary = [notePart, actionPart].filter(Boolean).join(" · ");
  if (mgrEl) {
    mgrEl.textContent = summary || (editable ? "No recommendation saved yet." : "—");
  }
  if (ceoEl) ceoEl.textContent = row.ceo_notes || "—";

  const pdfBtn = document.getElementById("mgr-open-pdf");
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

  const footer = document.getElementById("modal-view-footer");
  if (editable && footer) {
    const wrap = document.createElement("div");
    wrap.id = "modal-review-actions";
    wrap.className = "mgr-review-panel";
    wrap.innerHTML = `
      <div class="mgr-review-panel-title">COMPLETE YOUR REVIEW</div>
      <div class="form-group">
        <label class="fgl" for="mq-notes">MANAGER NOTES</label>
        <textarea id="mq-notes" class="fgta" placeholder="Add context for the CEO…" rows="4"></textarea>
      </div>
      <div class="mgr-decision-label">DECISION</div>
      <div class="mgr-decision-row">
        <button type="button" class="mgr-decision mgr-decision-approve" data-decision="escalate_to_ceo">Approve</button>
        <button type="button" class="mgr-decision mgr-decision-modify" data-decision="modification">Update</button>
        <button type="button" class="mgr-decision mgr-decision-reject" data-decision="reject">Reject</button>
      </div>
      <p class="mgr-decision-hint">Approve and Reject go to the CEO. Update sends the contract back to the vendor so they can change it and resubmit.</p>
    `;
    footer.parentNode.insertBefore(wrap, footer);
    footer.classList.add("modal-footer--review");

    const notesEl = document.getElementById("mq-notes");
    if (notesEl && row.manager_notes) notesEl.value = String(row.manager_notes);

    wrap.querySelectorAll("[data-decision]").forEach((btn) => {
      btn.addEventListener("click", async () => {
        const action = btn.getAttribute("data-decision") || "";
        const notes = document.getElementById("mq-notes")?.value?.trim() || "";
        if (action !== "escalate_to_ceo" && !notes) {
          alert("Add manager notes before rejecting or requesting a modification.");
          document.getElementById("mq-notes")?.focus();
          return;
        }
        if (action === "modification") {
          const label = esc(row.contract_title || contractRef(row));
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
        btn.disabled = true;
        try {
          await postManagerReview({
            id: row.id,
            manager_notes: notes,
            recommended_action: action,
          });
          globalThis.closeModal?.("modal-view");
          await reload();
        } catch (e) {
          alert(e.message || "Save failed");
          btn.disabled = false;
        }
      });
    });
  }

  globalThis.openModal?.("modal-view");
}

async function reload() {
  const saved = JSON.parse(localStorage.getItem("contrack_user") || "{}");
  setApiContext({ role: saved.role || "manager", userId: saved.id || null });
  try {
    pending = await getManagerPending();
    mine = await getManagerMine();
    if (!Array.isArray(pending)) pending = [];
    if (!Array.isArray(mine)) mine = [];
  } catch (e) {
    console.error(e);
    pending = [];
    mine = [];
  }
  updateStats();
  renderQueue();
}

document.addEventListener("DOMContentLoaded", () => {
  const search = document.querySelector(".filter-search");
  search?.addEventListener("input", renderQueue);
  document.querySelectorAll(".filter-bar .filter-select").forEach((el) => el.addEventListener("change", renderQueue));
  // The top-bar search box feeds the same filter as the search field above the table.
  const topSearch = document.querySelector(".top-search");
  topSearch?.addEventListener("input", () => {
    if (search) search.value = topSearch.value;
    renderQueue();
  });
  reload();
});
