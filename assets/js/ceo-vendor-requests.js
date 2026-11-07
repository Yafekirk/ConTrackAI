import { setApiContext, getVendorRequests, decideVendorRequest } from "/assets/js/api-client.js";

let pending = [];
let decided = [];
let busy = false;

function escapeHtml(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function formatDate(v) {
  if (!v) return "—";
  const d = new Date(v);
  return Number.isNaN(d.getTime()) ? "—" : d.toLocaleDateString("en-PH", { year: "numeric", month: "short", day: "numeric" });
}

function companyOf(r) {
  return r.company_name || r.name || "—";
}

function showMessage(text, ok) {
  const el = document.getElementById("vr-msg");
  if (!el) return;
  el.textContent = text;
  el.style.color = ok ? "var(--green)" : "var(--red)";
  el.style.display = text ? "block" : "none";
}

function matchesSearch(r) {
  const q = (document.getElementById("vr-search")?.value || "").trim().toLowerCase();
  if (!q) return true;
  return [companyOf(r), r.email, r.contact_number, r.supplier_type]
    .map((x) => String(x ?? "").toLowerCase())
    .join(" ")
    .includes(q);
}

function emptyRow(text) {
  return `<div class="t-row" style="grid-template-columns:1fr;">
    <div class="t-cell-meta" style="text-align:center;padding:28px 0;color:var(--gray-400);">${escapeHtml(text)}</div>
  </div>`;
}

function render() {
  const badge = document.getElementById("vr-pending-badge");
  if (badge) badge.textContent = `${pending.length} Pending`;
  globalThis.updateVendorRequestsBadges?.(pending.length);

  const pendingHost = document.getElementById("vr-pending-body");
  if (pendingHost) {
    const rows = pending.filter(matchesSearch);
    pendingHost.innerHTML = rows.length
      ? rows
          .map((r) => {
            const id = escapeHtml(String(r.id));
            return `<div class="t-row" style="grid-template-columns:2fr 2fr 1.3fr 1.3fr 1.1fr 1.6fr;">
              <div class="t-cell-primary">${escapeHtml(companyOf(r))}</div>
              <div class="t-cell-secondary">${escapeHtml(r.email || "—")}</div>
              <div class="t-cell-secondary">${escapeHtml(r.contact_number || "—")}</div>
              <div class="t-cell-secondary">${escapeHtml(r.supplier_type || "—")}</div>
              <div class="t-cell-meta">${escapeHtml(formatDate(r.created_at))}</div>
              <div style="display:flex;gap:6px;flex-wrap:wrap;">
                <button type="button" class="approve-btn btn-sm" data-decide="approve" data-id="${id}">✓ Approve</button>
                <button type="button" class="reject-btn btn-sm" data-decide="reject" data-id="${id}">✗ Reject</button>
              </div>
            </div>`;
          })
          .join("")
      : emptyRow(pending.length ? "No requests match your search." : "No vendor accounts are waiting for approval.");
  }

  const decidedHost = document.getElementById("vr-decided-body");
  if (decidedHost) {
    const rows = decided.filter(matchesSearch);
    decidedHost.innerHTML = rows.length
      ? rows
          .map((r) => {
            const approved = String(r.approval_status) === "approved";
            return `<div class="t-row" style="grid-template-columns:2fr 2fr 1.3fr 1.1fr 1.1fr;">
              <div class="t-cell-primary">${escapeHtml(companyOf(r))}</div>
              <div class="t-cell-secondary">${escapeHtml(r.email || "—")}</div>
              <div class="t-cell-secondary">${escapeHtml(r.supplier_type || "—")}</div>
              <div><span class="pill ${approved ? "pill-approved" : "pill-rejected"}">${approved ? "APPROVED" : "REJECTED"}</span></div>
              <div class="t-cell-meta">${escapeHtml(formatDate(r.approval_decided_at))}</div>
            </div>`;
          })
          .join("")
      : emptyRow("No decisions yet.");
  }
}

async function load() {
  try {
    const data = await getVendorRequests();
    pending = Array.isArray(data?.pending) ? data.pending : [];
    decided = Array.isArray(data?.decided) ? data.decided : [];
    render();
  } catch (e) {
    const host = document.getElementById("vr-pending-body");
    if (host) host.innerHTML = emptyRow(`Could not load vendor requests (${e.message}).`);
  }
}

async function decide(id, decision) {
  if (busy) return;
  const row = pending.find((r) => String(r.id) === String(id));
  if (!row) return;
  const company = companyOf(row);
  const question =
    decision === "approve"
      ? `Approve the vendor account for ${company}? They will be able to sign in and submit contracts.`
      : `Reject the vendor account for ${company}? They will not be able to sign in.`;
  if (!confirm(question)) return;
  busy = true;
  try {
    await decideVendorRequest(row.id, decision);
    showMessage(`${company} was ${decision === "approve" ? "approved" : "rejected"}. The vendor has been emailed.`, true);
  } catch (e) {
    showMessage(e.message || "Could not save the decision.", false);
  } finally {
    busy = false;
    await load();
  }
}

document.addEventListener("DOMContentLoaded", () => {
  const saved = JSON.parse(localStorage.getItem("contrack_user") || "{}");
  setApiContext({ role: saved.role || "ceo", userId: saved.id || null });
  document.getElementById("vr-search")?.addEventListener("input", render);
  document.getElementById("vr-pending-body")?.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-decide]");
    if (btn) void decide(btn.getAttribute("data-id"), btn.getAttribute("data-decide"));
  });
  void load();
});
