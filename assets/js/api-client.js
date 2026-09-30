const API_BASE = "/api";

let requestContext = {
  role: "vendor",
  userId: null,
};

export function setApiContext(context = {}) {
  requestContext = {
    ...requestContext,
    ...context,
  };
}

async function apiRequest(path, options = {}) {
  const response = await fetch(`${API_BASE}${path}`, {
    credentials: "same-origin",
    headers: {
      "Content-Type": "application/json",
      "X-User-Role": requestContext.role || "vendor",
      ...(requestContext.userId ? { "X-User-Id": String(requestContext.userId) } : {}),
      ...(options.headers || {}),
    },
    ...options,
  });

  const rawText = await response.text();
  let parsed = null;
  try {
    parsed = rawText ? JSON.parse(rawText) : null;
  } catch (_) {
    parsed = null;
  }

  if (!response.ok) {
    const msg =
      parsed && typeof parsed === "object" && parsed.error
        ? parsed.error
        : `API error (${response.status}): ${rawText || response.statusText}`;
    throw new Error(msg);
  }

  return parsed;
}

export async function getDashboardStats(role) {
  return apiRequest(`/contracts.php?view=stats&role=${encodeURIComponent(role)}`);
}

export async function getPendingApprovals() {
  return apiRequest("/contracts.php?view=pending_approvals");
}

export async function getManagerPending() {
  return apiRequest("/contracts.php?view=manager_pending");
}

export async function getManagerMine() {
  return apiRequest("/contracts.php?view=manager_mine");
}

export async function postManagerReview(payload) {
  return apiRequest("/contracts.php?view=manager_review", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

export async function getContracts() {
  return apiRequest("/contracts.php?view=list");
}

export async function getPerformance() {
  return apiRequest("/contracts.php?view=performance");
}

/** CEO / Manager / Admin: vendors merged from contracts + registered vendor users. */
export async function getVendorSummary() {
  return apiRequest("/contracts.php?view=vendor_summary");
}

export async function extractContractFields(contractText) {
  return apiRequest("/contracts.php?view=nlp_extract", {
    method: "POST",
    body: JSON.stringify({ contract_text: contractText }),
  });
}

/** Send the PDF file to Python ML clause extraction (no browser-side sample loading). */
export async function extractContractFromPdf(file, extraText = "") {
  const formData = new FormData();
  if (extraText) formData.append("contract_text", extraText);
  if (file) formData.append("contract_pdf", file, file.name || "contract.pdf");
  const response = await fetch(`${API_BASE}/contracts.php?view=nlp_extract`, {
    method: "POST",
    credentials: "same-origin",
    headers: {
      "X-User-Role": requestContext.role || "vendor",
      ...(requestContext.userId ? { "X-User-Id": String(requestContext.userId) } : {}),
    },
    body: formData,
  });
  const rawText = await response.text();
  let parsed = null;
  try {
    parsed = rawText ? JSON.parse(rawText) : null;
  } catch (_) {
    parsed = null;
  }
  if (!response.ok) {
    const msg =
      parsed && typeof parsed === "object" && parsed.error
        ? parsed.error
        : `API error (${response.status}): ${rawText || response.statusText}`;
    throw new Error(msg);
  }
  return parsed;
}

export async function submitContract(payload) {
  return apiRequest("/contracts.php?view=create", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

/** Sends multipart/form-data with optional PDF file — avoids huge JSON/base64 limits so the file is saved on disk. */
export async function submitContractFormData(formData) {
  const response = await fetch(`${API_BASE}/contracts.php?view=create`, {
    method: "POST",
    credentials: "same-origin",
    headers: {
      "X-User-Role": requestContext.role || "vendor",
      ...(requestContext.userId ? { "X-User-Id": String(requestContext.userId) } : {}),
    },
    body: formData,
  });
  const rawText = await response.text();
  let parsed = null;
  try {
    parsed = rawText ? JSON.parse(rawText) : null;
  } catch (_) {
    parsed = null;
  }
  if (!response.ok) {
    const msg =
      parsed && typeof parsed === "object" && parsed.error
        ? parsed.error
        : `API error (${response.status}): ${rawText || response.statusText}`;
    throw new Error(msg);
  }
  return parsed;
}

/** Direct URL — navigation omits X-User-* headers; prefer {@link openContractPdfInNewTab}. */
export function getContractPdfUrl(contractId) {
  const id = encodeURIComponent(String(contractId ?? ""));
  return `/api/contract_pdf.php?id=${id}`;
}

/**
 * Loads the PDF with the same headers as other API calls (X-User-Role, X-User-Id), then opens it in a new tab.
 * A normal link to contract_pdf.php does not send those headers, so the server often returned 403.
 */
export async function openContractPdfInNewTab(contractId) {
  const newWin = window.open("about:blank", "_blank");
  if (!newWin) {
    throw new Error("Allow pop-ups for this site to view the PDF.");
  }
  try {
    const response = await fetch(`${API_BASE}/contract_pdf.php?id=${encodeURIComponent(String(contractId ?? ""))}`, {
      credentials: "same-origin",
      headers: {
        "X-User-Role": requestContext.role || "vendor",
        ...(requestContext.userId ? { "X-User-Id": String(requestContext.userId) } : {}),
      },
    });
    if (!response.ok) {
      const msg = await response.text();
      newWin.close();
      throw new Error(msg.trim() || `Could not load PDF (${response.status}).`);
    }
    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    newWin.location.href = url;
    setTimeout(() => URL.revokeObjectURL(url), 120000);
  } catch (e) {
    try {
      newWin.close();
    } catch (_) {
      /* ignore */
    }
    throw e instanceof Error ? e : new Error(String(e));
  }
}

export async function postContractDecision(payload) {
  return apiRequest("/contracts.php?view=decision", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

export async function getAlerts(limit = 50) {
  return apiRequest(`/alerts.php?limit=${encodeURIComponent(String(limit))}`);
}

export async function markAlertRead(id) {
  return apiRequest("/alerts.php", {
    method: "PATCH",
    body: JSON.stringify({ id, status: "read" }),
  });
}

export async function markAllAlertsRead() {
  return apiRequest("/alerts.php", {
    method: "PATCH",
    body: JSON.stringify({ mark_all: true, status: "read" }),
  });
}

export async function getUsers() {
  return apiRequest("/users.php");
}

export async function createUser(payload) {
  return apiRequest("/users.php", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

export async function updateUser(payload) {
  return apiRequest("/users.php", {
    method: "PATCH",
    body: JSON.stringify(payload),
  });
}

export async function getMaintenanceDashboard() {
  return apiRequest("/maintenance.php");
}

export async function getAuditLogs(params = {}) {
  const q = new URLSearchParams(params);
  const suffix = q.toString() ? `?${q.toString()}` : "";
  return apiRequest(`/audit.php${suffix}`);
}

export async function getRenewalNotices() {
  return apiRequest("/contracts.php?view=renewals");
}

export async function resubmitContract(id) {
  return apiRequest("/contracts.php?view=vendor_resubmit", {
    method: "POST",
    body: JSON.stringify({ id }),
  });
}

export async function archiveContract(id) {
  return apiRequest("/contracts.php?view=archive", {
    method: "POST",
    body: JSON.stringify({ id }),
  });
}

export function auditLogDownloadUrl() {
  return `${API_BASE}/audit.php?download=1`;
}

export async function getNlpUsage(params = {}) {
  const q = new URLSearchParams(params);
  const suffix = q.toString() ? `?${q.toString()}` : "";
  return apiRequest(`/nlp_usage.php${suffix}`);
}

export function nlpUsageDownloadUrl() {
  return `${API_BASE}/nlp_usage.php?download=1`;
}

/** Terms & Conditions agreement, recorded once per account. */
export async function getTermsStatus() {
  return apiRequest("/terms.php");
}

export async function acceptTerms() {
  return apiRequest("/terms.php", {
    method: "POST",
    body: JSON.stringify({ accepted: true }),
  });
}

export async function changePassword(payload) {
  return apiRequest("/account.php", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

/** The signed-in user's own profile row (no admin rights needed). */
export async function getMyProfile() {
  return apiRequest("/account.php");
}

export async function updateMyProfile(payload) {
  return apiRequest("/account.php", {
    method: "POST",
    body: JSON.stringify({ action: "profile", ...payload }),
  });
}

export async function getVendorScoreCriteria() {
  return apiRequest("/vendor_scores.php?view=criteria");
}

export async function getScoringVendors() {
  return apiRequest("/vendor_scores.php?view=vendors");
}

export async function getVendorEvaluations(vendorId) {
  const q = vendorId ? `&vendor_id=${encodeURIComponent(String(vendorId))}` : "";
  return apiRequest(`/vendor_scores.php?view=list${q}`);
}

export async function getVendorEvaluation(id) {
  return apiRequest(`/vendor_scores.php?view=get&id=${encodeURIComponent(String(id))}`);
}

export async function createVendorEvaluation(payload) {
  return apiRequest("/vendor_scores.php?view=create", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

export async function updateVendorEvaluation(payload) {
  return apiRequest("/vendor_scores.php?view=update", {
    method: "POST",
    body: JSON.stringify(payload),
  });
}

export async function deleteVendorEvaluation(id) {
  return apiRequest("/vendor_scores.php?view=delete", {
    method: "POST",
    body: JSON.stringify({ id }),
  });
}
