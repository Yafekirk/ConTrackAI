/**
 * Manual vendor scoring workbench (Manager Evaluation page).
 * Overall totals come from the API; the live preview mirrors the same formula.
 */
import {
  setApiContext,
  getVendorScoreCriteria,
  getScoringVendors,
  getVendorEvaluations,
  getVendorEvaluation,
  createVendorEvaluation,
  updateVendorEvaluation,
  deleteVendorEvaluation,
} from "/assets/js/api-client.js";
import { bindStarPicker, renderStarRating, starRatingFromScore, starPickerHtml, starRatingHtml, syncStarPicker } from "/assets/js/star-rating.js";

let criteria = [];
let vendors = [];
let history = [];
let selectedVendorId = "";
let editingId = null;
let sessionUser = {};

function esc(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function setStatus(msg, kind = "info") {
  const el = document.getElementById("score-status");
  if (!el) return;
  el.textContent = msg || "";
  el.dataset.kind = kind;
  el.style.color = kind === "error" ? "#b42318" : kind === "success" ? "#027a48" : "#475467";
}

function ratingLabel(rating, score) {
  if (rating) {
    const map = {
      excellent: "Excellent",
      good: "Good",
      satisfactory: "Satisfactory",
      needs_improvement: "Needs improvement",
    };
    return map[rating] || rating;
  }
  const n = Number(score);
  if (!Number.isFinite(n)) return "—";
  if (n >= 90) return "Excellent";
  if (n >= 80) return "Good";
  if (n >= 60) return "Satisfactory";
  return "Needs improvement";
}

function previewTotals() {
  const weightSum = criteria.reduce((s, c) => s + Number(c.weight || 0), 0) || 1;
  let weightedSum = 0;
  const missing = [];
  for (const c of criteria) {
    const input = document.getElementById(`crit-${c.id}`);
    const raw = input?.value;
    if (raw === "" || raw == null) {
      missing.push(c.name);
      continue;
    }
    const score = Number(raw);
    if (!Number.isFinite(score) || score < 0 || score > 100) {
      missing.push(c.name);
      continue;
    }
    weightedSum += score * Number(c.weight || 0);
  }
  const overall = Math.max(0, Math.min(100, Math.round((weightedSum / weightSum) * 100) / 100));
  const num = document.getElementById("eval-score-num");
  const rec = document.getElementById("eval-rec-text");
  renderStarRating(num, missing.length ? null : overall, { size: 28, pill: true });
  if (rec) rec.textContent = missing.length ? "Rate every criterion." : ratingLabel("", overall);
  return { overall, missing };
}

function selectedVendor() {
  return vendors.find((v) => String(v.id) === String(selectedVendorId)) || null;
}

function renderVendorMeta() {
  const v = selectedVendor();
  const meta = document.getElementById("eval-vendor-meta");
  if (!meta) return;
  if (!v) {
    meta.hidden = true;
    return;
  }
  meta.hidden = false;
  document.getElementById("eval-meta-name").textContent = v.name || "—";
  document.getElementById("eval-meta-email").textContent = v.email || "—";
  document.getElementById("eval-meta-type").textContent = v.supplier_type || "—";
  const scoreEl = document.getElementById("eval-meta-score");
  if (scoreEl) {
    scoreEl.innerHTML =
      v.latest_score != null
        ? `${starRatingHtml(v.latest_score, { size: 14 })} <span style="margin-left:6px;color:#6b625f;">${esc(v.latest_rating_label || "")}</span>`
        : "Not scored yet";
  }
}

function renderCriteria() {
  const host = document.getElementById("eval-criteria");
  if (!host) return;
  if (!criteria.length) {
    host.innerHTML = `<p class="eval-selection-hint">No scoring criteria are configured.</p>`;
    return;
  }
  host.innerHTML = criteria
    .map((c) => {
      const pct = Math.round(Number(c.weight || 0) * 1000) / 10;
      return `<div class="form-group score-crit">
        <div class="eval-slider-head">
          <label class="fgl">${esc(c.name)} <span class="score-weight">Weight ${pct}%</span></label>
        </div>
        <p class="score-crit-desc">${esc(c.description || "")}</p>
        ${starPickerHtml(esc(c.id))}
      </div>`;
    })
    .join("");
  host.querySelectorAll(".star-pick").forEach((pick) => bindStarPicker(pick, previewTotals));
  previewTotals();
}

function renderVendorList() {
  const q = (document.getElementById("eval-vendor-search")?.value || "").trim().toLowerCase();
  const listEl = document.getElementById("eval-vendor-list");
  if (!listEl) return;
  const matches = vendors.filter((v) => {
    const blob = `${v.name || ""} ${v.email || ""} ${v.supplier_type || ""}`.toLowerCase();
    return !q || blob.includes(q);
  });
  if (!matches.length) {
    listEl.innerHTML = `<div class="eval-contract-empty">${vendors.length ? "No vendors matched that search." : "No vendor accounts found."}</div>`;
    return;
  }
  listEl.innerHTML = matches
    .map((v) => {
      const score = v.latest_score != null ? starRatingHtml(v.latest_score, { size: 12 }) : "—";
      const sel = String(v.id) === String(selectedVendorId) ? " is-selected" : "";
      return `<button type="button" class="eval-contract-item${sel}" data-vendor-id="${esc(v.id)}">
        <span class="eval-contract-item-main">${esc(v.name || v.email || "Vendor")}</span>
        <span class="eval-contract-item-sub">${esc(v.email || "")}${v.supplier_type ? " · " + esc(v.supplier_type) : ""}</span>
        <span class="eval-contract-badge">${score}</span>
      </button>`;
    })
    .join("");
}

function fillForm(evalRow) {
  editingId = evalRow?.id ? String(evalRow.id) : null;
  const remarks = document.getElementById("eval-remarks");
  const dateEl = document.getElementById("eval-date");
  if (remarks) remarks.value = evalRow?.remarks || "";
  if (dateEl) {
    const d = evalRow?.evaluated_at ? new Date(evalRow.evaluated_at) : new Date();
    if (!Number.isNaN(d.getTime())) dateEl.value = d.toISOString().slice(0, 10);
  }
  const byId = {};
  (evalRow?.scores || []).forEach((s) => {
    byId[String(s.criterion_id)] = s.score;
  });
  criteria.forEach((c) => {
    const input = document.getElementById(`crit-${c.id}`);
    if (!input) return;
    const stored = byId[String(c.id)];
    input.value = stored == null || stored === "" ? "" : String(stored);
    syncStarPicker(input.closest(".star-pick"));
  });
  const title = document.getElementById("eval-form-mode");
  if (title) title.textContent = editingId ? "Edit evaluation" : "New evaluation";
  const del = document.getElementById("eval-delete");
  if (del) del.hidden = !editingId;
  previewTotals();
}

function resetForm() {
  fillForm(null);
  setStatus("");
}

async function loadHistory() {
  const host = document.getElementById("eval-history-body");
  if (!host) return;
  if (!selectedVendorId) {
    history = [];
    host.innerHTML = `<div class="eval-contract-empty eval-contract-empty--soft">Select a vendor to see saved evaluations.</div>`;
    return;
  }
  host.innerHTML = `<div class="eval-contract-empty eval-contract-empty--soft">Loading evaluations…</div>`;
  try {
    const rows = await getVendorEvaluations(selectedVendorId);
    history = Array.isArray(rows) ? rows : [];
  } catch (e) {
    host.innerHTML = `<div class="eval-contract-empty">${esc(e.message || "Could not load evaluations.")}</div>`;
    return;
  }
  if (!history.length) {
    host.innerHTML = `<div class="eval-contract-empty">No evaluations stored for this vendor yet.</div>`;
    return;
  }
  host.innerHTML = history
    .map((r) => {
      const when = r.evaluated_at ? new Date(r.evaluated_at).toLocaleString() : "—";
      return `<div class="t-row score-hist-row" style="grid-template-columns:1.2fr 1fr 1.2fr 1.4fr 90px;">
        <div>${esc(when)}</div>
        <div>${starRatingHtml(r.overall_score, { size: 14 })}</div>
        <div>${esc(r.rating_label || ratingLabel(r.rating, r.overall_score))}</div>
        <div class="t-cell-meta">${esc(r.evaluator_name || "—")}</div>
        <div><button type="button" class="btn btn-ghost btn-sm" data-open-eval="${esc(r.id)}">View</button></div>
      </div>`;
    })
    .join("");
}

async function selectVendor(id) {
  selectedVendorId = String(id || "");
  renderVendorList();
  renderVendorMeta();
  resetForm();
  await loadHistory();
}

function collectScores() {
  const scores = [];
  for (const c of criteria) {
    const input = document.getElementById(`crit-${c.id}`);
    scores.push({ criterion_id: Number(c.id), score: input?.value });
  }
  return scores;
}

async function saveEvaluation() {
  const v = selectedVendor();
  if (!v) {
    setStatus("Select a vendor first.", "error");
    return;
  }
  const { missing } = previewTotals();
  if (missing.length) {
    setStatus(`Choose a star rating for: ${missing.join(", ")}.`, "error");
    return;
  }
  const payload = {
    vendor_id: v.id,
    scores: collectScores(),
    remarks: document.getElementById("eval-remarks")?.value || "",
    evaluated_at: document.getElementById("eval-date")?.value || "",
  };
  const btn = document.getElementById("eval-save");
  if (btn) btn.disabled = true;
  setStatus(editingId ? "Updating…" : "Saving…");
  try {
    const saved = editingId
      ? await updateVendorEvaluation({ id: editingId, ...payload })
      : await createVendorEvaluation(payload);
    const stars = starRatingFromScore(saved.overall_score);
    setStatus(`Saved. Rating ${stars == null ? "—" : stars.toFixed(1)} out of 5 (${saved.rating_label}).`, "success");
    const list = await getScoringVendors();
    vendors = Array.isArray(list) ? list : vendors;
    await selectVendor(v.id);
    if (saved?.id) {
      const full = await getVendorEvaluation(saved.id);
      fillForm(full);
    }
  } catch (e) {
    setStatus(e.message || "Could not save the evaluation.", "error");
  } finally {
    if (btn) btn.disabled = false;
  }
}

async function removeEvaluation() {
  if (!editingId) return;
  if (!window.confirm("Delete this evaluation? This cannot be undone.")) return;
  try {
    await deleteVendorEvaluation(editingId);
    setStatus("Evaluation deleted.", "success");
    const vid = selectedVendorId;
    const list = await getScoringVendors();
    vendors = Array.isArray(list) ? list : vendors;
    await selectVendor(vid);
  } catch (e) {
    setStatus(e.message || "Could not delete the evaluation.", "error");
  }
}

document.addEventListener("DOMContentLoaded", async () => {
  try {
    sessionUser = JSON.parse(localStorage.getItem("contrack_user") || "{}");
  } catch (_) {
    sessionUser = {};
  }
  setApiContext({ role: sessionUser.role || "manager", userId: sessionUser.id || null });

  document.getElementById("eval-vendor-search")?.addEventListener("input", renderVendorList);
  document.getElementById("eval-vendor-list")?.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-vendor-id]");
    if (btn) selectVendor(btn.getAttribute("data-vendor-id"));
  });
  document.getElementById("eval-history-body")?.addEventListener("click", async (e) => {
    const btn = e.target.closest("[data-open-eval]");
    if (!btn) return;
    setStatus("Loading evaluation…");
    try {
      const row = await getVendorEvaluation(btn.getAttribute("data-open-eval"));
      fillForm(row);
      setStatus("Loaded for viewing/editing.", "info");
    } catch (err) {
      setStatus(err.message || "Could not open that evaluation.", "error");
    }
  });
  document.getElementById("eval-save")?.addEventListener("click", saveEvaluation);
  document.getElementById("eval-reset")?.addEventListener("click", resetForm);
  document.getElementById("eval-delete")?.addEventListener("click", removeEvaluation);

  try {
    const [c, v] = await Promise.all([getVendorScoreCriteria(), getScoringVendors()]);
    criteria = (Array.isArray(c) ? c : []).filter((x) => x.is_active !== false);
    vendors = Array.isArray(v) ? v : [];
    renderCriteria();
    renderVendorList();
    await loadHistory();
  } catch (e) {
    setStatus(e.message || "Could not load vendor scoring.", "error");
    const listEl = document.getElementById("eval-vendor-list");
    if (listEl) listEl.innerHTML = `<div class="eval-contract-empty">${esc(e.message || "Failed to load.")}</div>`;
  }
});
