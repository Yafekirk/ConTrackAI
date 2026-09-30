import { setApiContext, getAuditLogs, auditLogDownloadUrl, getNlpUsage, nlpUsageDownloadUrl } from "/assets/js/api-client.js";
import { downloadCsv } from "/assets/js/contract-intel.js";

function esc(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

function severityFor(eventType) {
  const t = String(eventType || "").toLowerCase();
  if (t.includes("fail") || t.includes("denied")) return "crit";
  if (
    t.includes("decision") ||
    t.includes("password") ||
    t.includes("updated") ||
    t.includes("created")
  )
    return "warn";
  return "info";
}

function renderAuditRows(container, rowList) {
  if (!container) return;
  if (!rowList.length) {
    container.innerHTML = `<div class="t-empty" style="padding:40px;">No matching audit entries.</div>`;
    return;
  }
  container.innerHTML = rowList
    .map((r) => {
      const details =
        typeof r.details === "object" && r.details !== null
          ? JSON.stringify(r.details)
          : String(r.details || "");
      const sev = severityFor(r.event_type);
      return `
      <div class="alert-item ${sev}">
        <div class="alert-body">
          <div class="alert-title">${esc(r.event_type)}</div>
          <div class="alert-desc">${esc(details.slice(0, 320))}</div>
        </div>
        <div class="alert-right">
          <div class="alert-time">${r.created_at ? new Date(r.created_at).toLocaleString() : ""}</div>
        </div>
      </div>`;
    })
    .join("");
}

function fieldsLabel(fields) {
  if (Array.isArray(fields)) {
    return fields.join(", ");
  }
  if (typeof fields === "string" && fields !== "") {
    return fields;
  }
  return "—";
}

function renderNlpRows(container, rowList) {
  if (!container) return;
  if (!rowList.length) {
    container.innerHTML = `<div class="t-empty" style="background:#fff;border-radius:var(--radius);border:1px solid var(--gray-200);padding:40px 20px;">
      No NLP usage yet. Rows appear after a vendor runs NLP Auto-Fill or submits a contract PDF.
    </div>`;
    return;
  }
  container.innerHTML = rowList
    .map((r) => {
      const engine = String(r.engine || "unavailable");
      const sev = engine === "python-ml" ? "info" : engine === "php-regex" ? "warn" : "crit";
      const fields = fieldsLabel(r.fields_filled);
      const desc = [
        `source=${r.source || "—"}`,
        r.source_file_name ? `file=${r.source_file_name}` : null,
        r.duration_ms != null ? `${r.duration_ms} ms` : null,
        r.clause_count != null ? `${r.clause_count} clauses` : null,
        fields !== "—" ? `fields: ${fields}` : null,
      ]
        .filter(Boolean)
        .join(" · ");
      return `
      <div class="alert-item ${sev}">
        <div class="alert-body">
          <div class="alert-title">${esc(engine)} · ${esc(r.source || "")}</div>
          <div class="alert-desc">${esc(desc)}</div>
        </div>
        <div class="alert-right">
          <div class="alert-time">${r.created_at ? new Date(r.created_at).toLocaleString() : ""}</div>
        </div>
      </div>`;
    })
    .join("");
}

function bindCsvDownload(buttonId, urlFn, fallbackName, headers, mapper) {
  document.getElementById(buttonId)?.addEventListener("click", async () => {
    try {
      const res = await fetch(urlFn(), { credentials: "same-origin" });
      const type = res.headers.get("content-type") || "";
      if (res.ok && type.includes("csv")) {
        const blob = await res.blob();
        const href = URL.createObjectURL(blob);
        const a = document.createElement("a");
        a.href = href;
        a.download = fallbackName;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(() => URL.revokeObjectURL(href), 2000);
        return;
      }
    } catch (_) {
      /* fallback to local CSV */
    }
    downloadCsv(fallbackName, headers, mapper());
  });
}

document.addEventListener("DOMContentLoaded", async () => {
  const saved = JSON.parse(localStorage.getItem("contrack_user") || "{}");
  setApiContext({ role: saved.role || "admin", userId: saved.id || null });

  let rows = [];
  try {
    rows = await getAuditLogs({ limit: 200 });
    if (!Array.isArray(rows)) rows = [];
  } catch (e) {
    console.error(e);
    rows = [];
  }

  const crit = rows.filter((r) => severityFor(r.event_type) === "crit").length;
  const warn = rows.filter((r) => severityFor(r.event_type) === "warn").length;
  const info = rows.filter((r) => severityFor(r.event_type) === "info").length;

  const elCrit = document.querySelector(".stats-row .stat-card:nth-child(1) .stat-number");
  const elWarn = document.querySelector(".stats-row .stat-card:nth-child(2) .stat-number");
  const elInfo = document.querySelector(".stats-row .stat-card:nth-child(3) .stat-number");
  if (elCrit) elCrit.textContent = String(crit);
  if (elWarn) elWarn.textContent = String(warn);
  if (elInfo) elInfo.textContent = String(info);

  const list = document.getElementById("alerts-list");
  const nlpList = document.getElementById("nlp-usage-list");

  bindCsvDownload(
    "download-audit-log",
    auditLogDownloadUrl,
    "contrack-audit-log.csv",
    ["id", "event_type", "details", "actor_user_id", "created_at"],
    () =>
      rows.map((r) => [
        r.id,
        r.event_type,
        typeof r.details === "object" ? JSON.stringify(r.details) : String(r.details || ""),
        r.actor_user_id,
        r.created_at,
      ]),
  );

  if (list) {
    if (rows.length === 0) {
      list.innerHTML = `<div class="t-empty" style="background:#fff;border-radius:var(--radius);border:1px solid var(--gray-200);padding:60px 20px;">
        No audit events yet. Events appear after login and administrative actions.
      </div>`;
    } else {
      renderAuditRows(list, rows);
    }
  }

  globalThis.filterAlerts = function filterAlerts(type, btn) {
    document.querySelectorAll(".af-btn").forEach((b) => b.classList.remove("on"));
    if (btn) btn.classList.add("on");
    const map = { all: null, crit: "crit", warn: "warn", info: "info" };
    const want = map[type];
    const filtered = want ? rows.filter((r) => severityFor(r.event_type) === want) : rows;
    renderAuditRows(list, filtered);
  };

  let nlpRows = [];
  try {
    nlpRows = await getNlpUsage({ limit: 200 });
    if (!Array.isArray(nlpRows)) nlpRows = [];
  } catch (e) {
    console.error(e);
    nlpRows = [];
  }

  const nlpTotal = document.getElementById("nlp-stat-total");
  const nlpPython = document.getElementById("nlp-stat-python");
  const nlpRegex = document.getElementById("nlp-stat-regex");
  if (nlpTotal) nlpTotal.textContent = String(nlpRows.length);
  if (nlpPython) nlpPython.textContent = String(nlpRows.filter((r) => r.engine === "python-ml").length);
  if (nlpRegex) nlpRegex.textContent = String(nlpRows.filter((r) => r.engine === "php-regex").length);

  renderNlpRows(nlpList, nlpRows);

  bindCsvDownload(
    "download-nlp-usage",
    nlpUsageDownloadUrl,
    "contrack-nlp-usage.csv",
    [
      "id",
      "user_id",
      "contract_id",
      "source",
      "engine",
      "success",
      "duration_ms",
      "source_file_name",
      "fields_filled",
      "created_at",
    ],
    () =>
      nlpRows.map((r) => [
        r.id,
        r.user_id,
        r.contract_id,
        r.source,
        r.engine,
        r.success,
        r.duration_ms,
        r.source_file_name,
        Array.isArray(r.fields_filled) ? r.fields_filled.join("|") : String(r.fields_filled || ""),
        r.created_at,
      ]),
  );
});
