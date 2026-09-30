/** Shared contract display helpers: payment terms, monitoring, archive, risk. */

/** Short id such as CT-1001. The uuid stays internal and is not shown. */
export function contractRef(row) {
  const code = String(row?.contract_code || "").trim();
  return code || "—";
}

export function formatPaymentTerms(raw) {
  const text = String(raw || "").trim();
  if (!text) return "—";
  const net = text.match(/\bnet\s*(\d{1,3})\b/i);
  if (net) return `${Number(net[1])} Days`;
  const days = text.match(/\b(\d{1,3})\s*days?\b/i);
  if (days) return `${Number(days[1])} Days`;
  return text;
}

export function daysUntilEnd(endDate) {
  if (!endDate) return null;
  const raw = String(endDate);
  const iso = raw.includes("T") ? raw : `${raw}T12:00:00`;
  const end = new Date(iso);
  if (Number.isNaN(end.getTime())) return null;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  end.setHours(0, 0, 0, 0);
  return Math.round((end.getTime() - today.getTime()) / 86400000);
}

export function monitorLabel(row) {
  if (row?.monitor_status) {
    const map = {
      pending: "PENDING",
      active: "ACTIVE",
      expiring: "EXPIRING",
      expired: "EXPIRED",
      rejected: "REJECTED",
      terminated: "TERMINATED",
      renewal: "FOR RENEWAL",
      archived: "ARCHIVED",
      approved: "ACTIVE",
      modification: "NEEDS CHANGES",
    };
    return map[String(row.monitor_status).toLowerCase()] || String(row.monitor_status).toUpperCase();
  }
  const s = String(row?.status || "").toLowerCase();
  const days = daysUntilEnd(row?.end_date);
  if (s === "approved") {
    if (days !== null && days < 0) return "EXPIRED";
    if (days !== null && days <= 30) return "EXPIRING";
    return "ACTIVE";
  }
  if (s === "renegotiation") return "FOR RENEWAL";
  if (s === "modification") return "NEEDS CHANGES";
  return s ? s.toUpperCase() : "—";
}

export function monitorPillClass(row) {
  const label = monitorLabel(row).toLowerCase();
  if (label === "active") return "pill-active";
  if (label === "pending") return "pill-pending";
  if (label === "expiring" || label === "for renewal") return "pill-expiring";
  if (label === "expired" || label === "terminated" || label === "archived") return "pill-expired";
  if (label === "rejected") return "pill-rejected";
  if (label === "needs changes") return "pill-expiring";
  return "pill-review";
}

export function isArchivedContract(row) {
  // The API already derives is_archived; status is never "archived" (see the DB CHECK).
  if (row?.is_archived) return true;
  const s = String(row?.status || "").toLowerCase();
  const mon = String(row?.monitor_status || "").toLowerCase();
  return s === "terminated" || s === "rejected" || mon === "expired";
}

export function isRenewalDue(row) {
  if (row?.renewal_due) return true;
  const days = daysUntilEnd(row?.end_date);
  const s = String(row?.status || "").toLowerCase();
  return (s === "approved" || s === "renegotiation") && days !== null && days >= 0 && days <= 90;
}

export function riskFromRow(row) {
  const named = String(row?.risk || "").toLowerCase();
  if (named === "high" || named === "medium" || named === "low") return named;
  const v = Number(row?.contract_value) || 0;
  if (v >= 5000000) return "high";
  if (v >= 1000000) return "medium";
  return "low";
}

export function downloadCsv(filename, headers, rows) {
  const escape = (val) => {
    const s = String(val ?? "");
    if (/[",\n]/.test(s)) return `"${s.replace(/"/g, '""')}"`;
    return s;
  };
  const lines = [headers.map(escape).join(",")];
  rows.forEach((row) => lines.push(row.map(escape).join(",")));
  const blob = new Blob([lines.join("\n")], { type: "text/csv;charset=utf-8;" });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 2000);
}
