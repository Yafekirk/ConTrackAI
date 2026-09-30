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
    ["rejected", "renegotiation", "terminated"].includes(String(r.status).toLowerCase()),
  ).length;
  if (statNums[0]) statNums[0].textContent = String(total);
  if (statNums[1]) statNums[1].textContent = String(approved);
  if (statNums[2]) statNums[2].textContent = String(pendingCeo);
  if (statNums[3]) statNums[3].textContent = String(rework);

  const meta = document.querySelector("#submissions-body")?.closest(".table-card")?.querySelector(".t-showing");
  if (meta) meta.textContent = `${pending.length + mine.length} items loaded`;
}

function renderQueue() {
  const body = document.getElementById("submissions-body");
  if (!body) return;

  const seen = new Set();
  const rows = [...pending, ...mine]
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

  if (rows.length === 0) {
    body.innerHTML = `<div style="padding:60px 20px;text-align:center;">
      <div style="font-size:14px;font-weight:600;color:var(--gray-500);margin-bottom:6px;">No submissions yet</div>
      <div style="font-size:12px;color:var(--gray-400);">Vendor contracts appear here after submission.</div>
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
        <button type="button" class="mgr-decision mgr-decision-modify" data-decision="modification">Modify</button>
        <button type="button" class="mgr-decision mgr-decision-reject" data-decision="reject">Reject</button>
      </div>
      <p class="mgr-decision-hint">Approve and Reject go to the CEO. Modify sends the contract back to the vendor so they can change it and resubmit.</p>
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

document.addEventListener("DOMContentLoaded", () => reload());
