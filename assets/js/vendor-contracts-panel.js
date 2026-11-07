/** Read-only list of one vendor's contracts, shared by the CEO vendor profile and the manager scoring page. */
import { openContractPdfInNewTab } from "/assets/js/api-client.js";
import { contractRef, monitorLabel, monitorPillClass } from "/assets/js/contract-intel.js";

const GRID = "2.2fr 1.2fr 1fr 74px";

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

function formatDay(value) {
  if (!value) return "—";
  const raw = String(value);
  const d = new Date(raw.includes("T") ? raw : `${raw}T12:00:00`);
  return Number.isNaN(d.getTime()) ? raw : d.toLocaleDateString();
}

export function contractsForVendor(rows, vendorId) {
  const id = String(vendorId ?? "");
  if (!id || !Array.isArray(rows)) return [];
  return rows
    .filter((r) => String(r.vendor_id ?? "") === id)
    .sort((a, b) => (new Date(b.uploaded_at || 0).getTime() || 0) - (new Date(a.uploaded_at || 0).getTime() || 0));
}

function showMessage(host, text, color = "var(--gray-400)") {
  host.innerHTML = `<div class="t-cell-meta" style="text-align:center;padding:22px 12px;color:${color};">${esc(text)}</div>`;
}

export function renderVendorContractsLoading(host) {
  if (host) showMessage(host, "Loading contracts…");
}

export function renderVendorContractsError(host, message) {
  if (host) showMessage(host, message || "Could not load contracts.", "var(--red)");
}

export function renderVendorContracts(host, rows) {
  if (!host) return;
  if (!rows.length) {
    showMessage(host, "This vendor has not submitted any contracts yet.");
    return;
  }
  const head = `<div class="t-cols" style="grid-template-columns:${GRID};">
    <div class="t-col">CONTRACT</div>
    <div class="t-col">VALUE</div>
    <div class="t-col">STATUS</div>
    <div class="t-col"></div>
  </div>`;
  const body = rows
    .map((r) => {
      const meta = [contractRef(r), r.end_date ? `ends ${formatDay(r.end_date)}` : ""].filter((x) => x && x !== "—").join(" · ");
      return `<div class="t-row" style="grid-template-columns:${GRID};">
        <div style="min-width:0;">
          <div class="t-cell-primary">${esc(r.contract_title || "Untitled")}</div>
          <div class="t-cell-meta">${esc(meta || "—")}</div>
        </div>
        <div class="t-cell-secondary">${esc(formatPeso(r.contract_value))}</div>
        <div><span class="pill ${monitorPillClass(r)}">${esc(monitorLabel(r))}</span></div>
        <div><button type="button" class="btn btn-ghost btn-sm" data-vc-pdf="${esc(r.id)}">PDF</button></div>
      </div>`;
    })
    .join("");
  host.innerHTML = head + body;
  host.onclick = async (e) => {
    const btn = e.target.closest("[data-vc-pdf]");
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    try {
      await openContractPdfInNewTab(btn.getAttribute("data-vc-pdf"));
    } catch (err) {
      alert(err.message || "Could not open the PDF.");
    } finally {
      btn.disabled = false;
    }
  };
}
