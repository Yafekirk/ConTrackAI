import {
  setApiContext,
  getUsers,
  getContracts,
  getVendorSummary,
  getVendorEvaluations,
  getVendorEvaluation,
} from "/assets/js/api-client.js";
import { downloadCsv } from "/assets/js/contract-intel.js";
import { criteriaStarsHtml, renderStarRating, starRatingHtml } from "/assets/js/star-rating.js";

function formatPeso(v) {
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
    maximumFractionDigits: 0,
  }).format(Number(v) || 0);
}

function esc(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function initials(name, email) {
  const n = String(name || email || "?").trim();
  const parts = n.split(/\s+/).filter(Boolean);
  if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
  return n.slice(0, 2).toUpperCase() || "?";
}

function isVendorRole(role) {
  const r = String(role || "").toLowerCase();
  return r === "vendor" || r === "client";
}

function openVendorDetail(user, summary, contractCount) {
  const setText = (id, val) => {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
  };
  setText("vd-company", user.company_name || user.name || user.email || "Vendor");
  setText("vd-type", user.supplier_type || "—");
  setText("vd-contract-type", summary?.contract_type || "—");
  setText("vd-email", user.email || "—");
  setText("vd-phone", user.contact_number || "—");
  setText("vd-status", user.is_disabled ? "DISABLED" : "ACTIVE");
  setText("vd-risk", String(summary?.risk || "—").toUpperCase());
  setText("vd-total", String(summary?.contract_count ?? contractCount));
  setText("vd-approved", String(summary?.approved_count ?? 0));
  setText("vd-pending", String(summary?.pending_count ?? 0));
  setText("vd-value", formatPeso(summary?.total_value || 0));

  renderStarRating(
    document.getElementById("vs-overall"),
    summary?.has_manual_score ? (summary?.vendor_score ?? summary?.ai_score) : null,
    { size: 15 },
  );
  const criteriaHost = document.getElementById("vs-criteria");
  if (criteriaHost) criteriaHost.innerHTML = `<p class="star-rating--empty">Loading criteria…</p>`;

  globalThis.openModal?.("modal-vendor-detail");

  if (user?.id) {
    getVendorEvaluations(user.id)
      .then((rows) => (Array.isArray(rows) && rows[0]?.id ? getVendorEvaluation(rows[0].id) : null))
      .then((full) => {
        if (!full) {
          if (criteriaHost) criteriaHost.innerHTML = `<p class="star-rating--empty">No evaluation yet.</p>`;
          return;
        }
        renderStarRating(document.getElementById("vs-overall"), full.overall_score, { size: 15 });
        if (criteriaHost) criteriaHost.innerHTML = criteriaStarsHtml(full.criteria || full.scores || []);
      })
      .catch(() => {});
  }
}

document.addEventListener("DOMContentLoaded", async () => {
  const saved = JSON.parse(localStorage.getItem("contrack_user") || "{}");
  setApiContext({ role: saved.role || "admin", userId: saved.id || null });

  const body = document.getElementById("vendor-directory-body");
  const showing = document.querySelector(".table-card .t-showing");
  const statNums = document.querySelectorAll(".stats-row .stat-number");

  if (!body) return;

  try {
    // allSettled: the directory must still render if scoring or contracts fail.
    const [usersRes, contractsRes, summaryRes] = await Promise.allSettled([
      getUsers(),
      getContracts(),
      getVendorSummary(),
    ]);
    const allUsers = usersRes.status === "fulfilled" && Array.isArray(usersRes.value) ? usersRes.value : [];
    const contractList =
      contractsRes.status === "fulfilled" && Array.isArray(contractsRes.value) ? contractsRes.value : [];
    const summary =
      summaryRes.status === "fulfilled" && Array.isArray(summaryRes.value) ? summaryRes.value : [];
    const vendorUsers = allUsers.filter((u) => isVendorRole(u.role));

    const countByVendor = {};
    for (const c of contractList) {
      const vid = c.vendor_id;
      if (vid == null) continue;
      const k = String(vid);
      countByVendor[k] = (countByVendor[k] || 0) + 1;
    }

    const summaryByVendor = {};
    for (const s of summary) {
      if (s && s.vendor_id != null) summaryByVendor[String(s.vendor_id)] = s;
    }
    const scoreOf = (id) => {
      const s = summaryByVendor[String(id)];
      if (!s?.has_manual_score) return 0;
      return Number((s.vendor_score ?? s.ai_score) || 0);
    };

    // Average across vendors that have a stored evaluation.
    const scored = vendorUsers.map((u) => scoreOf(u.id)).filter((n) => n > 0);
    const avgScore = scored.length
      ? Math.round(scored.reduce((a, b) => a + b, 0) / scored.length)
      : 0;

    if (statNums[0]) statNums[0].textContent = String(vendorUsers.length);
    if (statNums[1])
      statNums[1].textContent = String(vendorUsers.filter((u) => !u.is_disabled).length);
    if (statNums[2]) renderStarRating(statNums[2], scored.length ? avgScore : null, { size: 18, pill: true });
    if (statNums[3]) statNums[3].textContent = String(scored.filter((n) => n < 60).length);

    if (vendorUsers.length === 0) {
      body.innerHTML = `<div class="t-empty" style="border:none;">
        <svg viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/></svg>
        No vendor accounts yet. Create users with role Vendor in User Management.
      </div>`;
      if (showing) showing.textContent = "Showing 0 of 0 vendors";
      return;
    }

    body.innerHTML = vendorUsers
      .map((u) => {
        const nContracts = countByVendor[String(u.id)] ?? 0;
        const disabled = !!u.is_disabled;
        const score = scoreOf(u.id);
        const label = u.company_name || u.name || u.email || "User";
        return `
      <div class="t-row" style="grid-template-columns:2fr 1.3fr 0.7fr 1.5fr 0.8fr 90px;">
        <div class="user-avatar-cell">
          <div class="user-avatar-mini role-vendor">${esc(initials(label, u.email))}</div>
          <div>
            <div class="t-cell-primary">${esc(label)}</div>
            <div class="t-cell-meta">${esc(u.email || "")}</div>
          </div>
        </div>
        <div class="t-cell-secondary">${esc(u.supplier_type || String(u.role || "").toUpperCase())}</div>
        <div>${nContracts}</div>
        <div>${score > 0 ? starRatingHtml(score, { size: 14 }) : "—"}</div>
        <div><span class="pill ${disabled ? "pill-rejected" : "pill-active"}">${disabled ? "DISABLED" : "ACTIVE"}</span></div>
        <div class="t-actions">
          <button type="button" class="t-action-btn" title="Info" aria-label="Vendor info" data-vendor-id="${esc(u.id)}">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
          </button>
        </div>
      </div>`;
      })
      .join("");

    document.getElementById("export-vendors-csv")?.addEventListener("click", () => {
      downloadCsv(
        "vendors.csv",
        ["Company", "Email", "Supplier Type", "Contracts", "Score", "Status"],
        vendorUsers.map((u) => [
          u.company_name || u.name || "",
          u.email || "",
          u.supplier_type || "",
          countByVendor[String(u.id)] ?? 0,
          scoreOf(u.id) || "",
          u.is_disabled ? "disabled" : "active",
        ]),
      );
    });

    body.onclick = (event) => {
      const btn = event.target.closest("[data-vendor-id]");
      if (!btn) return;
      const id = btn.getAttribute("data-vendor-id");
      const user = vendorUsers.find((u) => String(u.id) === String(id));
      if (user) openVendorDetail(user, summaryByVendor[String(id)], countByVendor[String(id)] ?? 0);
    };

    if (showing) showing.textContent = `Showing ${vendorUsers.length} vendors`;
  } catch (e) {
    console.error(e);
    body.innerHTML = `<div class="t-empty" style="border:none;">Could not load vendors.</div>`;
  }
});
