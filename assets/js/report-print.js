// Printable reports: opens a clean, letter-sized report in a new tab and starts the print dialog,
// where "Save as PDF" produces the PDF file. No PDF library is needed.

function esc(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function sectionHtml(section) {
  const head = section.labels.map((l) => `<th>${esc(l)}</th>`).join("");
  const body = section.rows.length
    ? section.rows.map((row) => `<tr>${row.map((cell) => `<td>${esc(cell ?? "—")}</td>`).join("")}</tr>`).join("")
    : `<tr><td colspan="${section.labels.length}" class="empty">No records for this report.</td></tr>`;
  return `<section>
    <h2>${esc(section.title)}</h2>
    ${section.description ? `<p class="desc">${esc(section.description)}</p>` : ""}
    <table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>
    <p class="count">${section.rows.length} record(s)</p>
  </section>`;
}

/**
 * @param {string} documentTitle
 * @param {{title:string, description?:string, labels:string[], rows:any[][]}[]} sections
 * @param {{preparedBy?:string}} [opts]
 */
export function printReport(documentTitle, sections, opts = {}) {
  const win = window.open("", "_blank");
  if (!win) {
    alert("Allow pop-ups for this site to print or save the report as PDF.");
    return;
  }
  const generated = new Date().toLocaleString("en-PH", { dateStyle: "long", timeStyle: "short" });
  win.document.open();
  win.document.write(`<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<title>${esc(documentTitle)}</title>
<style>
  @page { size: letter landscape; margin: 14mm; }
  * { box-sizing: border-box; }
  body { font-family: "Segoe UI", system-ui, -apple-system, Roboto, Arial, sans-serif; color: #1f2937; margin: 0; padding: 24px; font-size: 11px; }
  header { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 3px solid #7a0c0c; padding-bottom: 10px; margin-bottom: 18px; }
  .brand { font-size: 20px; font-weight: 800; color: #111827; } .brand span { color: #7a0c0c; }
  .org { font-size: 11px; color: #6b7280; margin-top: 2px; }
  .meta { text-align: right; font-size: 10px; color: #6b7280; line-height: 1.5; }
  h1 { font-size: 16px; margin: 0 0 14px; color: #111827; }
  h2 { font-size: 13px; margin: 0 0 4px; color: #7a0c0c; text-transform: uppercase; letter-spacing: .04em; }
  section { margin-bottom: 22px; page-break-inside: auto; }
  section + section { page-break-before: always; }
  .desc { margin: 0 0 8px; color: #6b7280; }
  table { width: 100%; border-collapse: collapse; }
  thead { display: table-header-group; }
  th { background: #7a0c0c; color: #fff; text-align: left; font-weight: 700; padding: 6px 8px; font-size: 10px; text-transform: uppercase; letter-spacing: .03em; }
  td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
  tr { page-break-inside: avoid; }
  tbody tr:nth-child(even) td { background: #f9fafb; }
  .empty { text-align: center; color: #9ca3af; padding: 18px; }
  .count { margin: 6px 0 0; font-size: 10px; color: #6b7280; text-align: right; }
  footer { margin-top: 28px; font-size: 10px; color: #6b7280; display: flex; justify-content: space-between; }
  .toolbar { position: sticky; top: 0; background: #fff; padding: 0 0 12px; display: flex; gap: 8px; }
  .toolbar button { border: 0; border-radius: 8px; padding: 8px 14px; font-weight: 700; cursor: pointer; background: #7a0c0c; color: #fff; }
  .toolbar button.ghost { background: #e5e7eb; color: #111827; }
  * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  @media print { .toolbar { display: none; } body { padding: 0; } }
</style></head><body>
<div class="toolbar"><button onclick="window.print()">Print / Save as PDF</button><button class="ghost" onclick="window.close()">Close</button></div>
<header>
  <div><div class="brand">Con<span>Track</span> AI</div><div class="org">JB Calderon Events Management · Contract Management System</div></div>
  <div class="meta">Generated ${esc(generated)}${opts.preparedBy ? `<br>Prepared by ${esc(opts.preparedBy)}` : ""}</div>
</header>
<h1>${esc(documentTitle)}</h1>
${sections.map(sectionHtml).join("")}
<footer><span>Confidential — for internal use of JB Calderon Events Management.</span><span>ConTrack AI</span></footer>
<script>window.addEventListener("load", function () { setTimeout(function () { window.print(); }, 200); });<\/script>
</body></html>`);
  win.document.close();
}
