import {
  setApiContext,
  checkDuplicatePdf,
  extractContractFields,
  extractContractFromPdf,
  submitContractFormData,
} from "/assets/js/api-client.js";

function showAlert(id, message, label = "DUPLICATE CONTRACT BLOCKED") {
  const box = document.getElementById(id);
  if (!box) return;
  const labelEl = box.querySelector("[data-alert-label]");
  const bodyEl = box.querySelector("[data-alert-body]");
  if (labelEl) labelEl.textContent = label;
  if (bodyEl) bodyEl.textContent = message;
  box.style.display = "flex";
  box.scrollIntoView({ behavior: "smooth", block: "center" });
}

const SESSION_EXPIRED_MESSAGE =
  "Your session has expired (this happens when the site is updated). Sign out, sign in again, then re-upload the PDF.";

function isSessionExpired(error) {
  return error?.status === 401;
}

/** Plain-language reason for a failed request, shown to the vendor instead of a generic failure. */
function describeRequestError(error) {
  if (isSessionExpired(error)) return SESSION_EXPIRED_MESSAGE;
  if (error?.status === 502 || error?.status === 503 || error?.status === 504) {
    return "The server took too long or is restarting. Wait a minute, then upload the PDF again.";
  }
  if (error instanceof TypeError) {
    return "Could not reach the server. Check your connection, then upload the PDF again.";
  }
  return error?.message || "Something went wrong. Please upload the PDF again.";
}

function hideAlerts() {
  ["upload-alert", "submit-alert"].forEach((id) => {
    const box = document.getElementById(id);
    if (box) box.style.display = "none";
  });
}

/** Hex SHA-256 of the file, or null where Web Crypto is unavailable (non-HTTPS origins). */
async function sha256Hex(file) {
  if (!globalThis.crypto?.subtle || typeof file?.arrayBuffer !== "function") return null;
  const digest = await crypto.subtle.digest("SHA-256", await file.arrayBuffer());
  return Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, "0")).join("");
}

function isPdfFile(file) {
  if (!file) return false;
  const name = String(file.name || "").toLowerCase();
  const type = String(file.type || "").toLowerCase();
  return name.endsWith(".pdf") || type === "application/pdf";
}

function getSessionUser() {
  try {
    return JSON.parse(localStorage.getItem("contrack_user") || "null");
  } catch (error) {
    return null;
  }
}

function getFieldElements() {
  return {
    title: document.getElementById("vendor-contract-title"),
    value: document.getElementById("vendor-contract-value"),
    startDate: document.getElementById("vendor-contract-start"),
    endDate: document.getElementById("vendor-contract-end"),
    contractType: document.getElementById("vendor-contract-type"),
    currency: document.getElementById("vendor-contract-currency"),
    paymentTerms: document.getElementById("vendor-contract-payment"),
    renewalTerms: document.getElementById("vendor-contract-renewal"),
    penalty: document.getElementById("vendor-contract-penalty"),
    scope: document.getElementById("vendor-contract-scope"),
    clientName: document.getElementById("vendor-contract-client"),
    vendorName: document.getElementById("vendor-contract-vendor"),
    vendorAddress: document.getElementById("vendor-contract-vendor-address"),
    termination: document.getElementById("vendor-contract-termination"),
    clientSignatory: document.getElementById("vendor-contract-client-signatory"),
    vendorSignatory: document.getElementById("vendor-contract-vendor-signatory"),
    signedDate: document.getElementById("vendor-contract-signed-date"),
  };
}

function parseValue(text) {
  return Number(String(text || "").replace(/[^\d.]/g, "")) || 0;
}

function normalizeCurrency(extracted, selectEl) {
  const raw = String(extracted || "PHP").toUpperCase();
  const code = raw.includes("USD") ? "USD" : raw.includes("EUR") ? "EUR" : "PHP";
  if (selectEl) {
    const opt = Array.from(selectEl.options).find((o) => String(o.value).toUpperCase() === code);
    if (opt) return opt.value;
  }
  return code;
}

function currencySymbol(currency) {
  const code = String(currency || "PHP").toUpperCase();
  return code === "USD" ? "$" : code === "EUR" ? "€" : "₱";
}

function formatMoney(value, currency) {
  const n = parseValue(value);
  const digits = Number.isInteger(n) ? 0 : 2;
  return `${currencySymbol(currency)}${n.toLocaleString("en-PH", { minimumFractionDigits: digits, maximumFractionDigits: 2 })}`;
}

/** As-you-type version of formatMoney: keeps a trailing "." or partial decimals the user is still typing. */
function formatMoneyInput(raw, currency) {
  const cleaned = String(raw || "").replace(/[^\d.]/g, "");
  if (!cleaned) return "";
  const dot = cleaned.indexOf(".");
  const intPart = (dot === -1 ? cleaned : cleaned.slice(0, dot)).replace(/^0+(?=\d)/, "") || "0";
  const decPart = dot === -1 ? null : cleaned.slice(dot + 1).replace(/\./g, "").slice(0, 2);
  const grouped = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
  return `${currencySymbol(currency)}${grouped}${decPart === null ? "" : `.${decPart}`}`;
}

function refreshValueField(fields) {
  if (!fields.value || !fields.value.value.trim()) return;
  fields.value.value = formatMoney(fields.value.value, fields.currency?.value);
}

function bindValueFormatting(fields) {
  const input = fields.value;
  if (!input) return;
  input.addEventListener("input", () => {
    const caret = input.selectionStart ?? input.value.length;
    const significantBefore = input.value.slice(0, caret).replace(/[^\d.]/g, "").length;
    input.value = formatMoneyInput(input.value, fields.currency?.value);
    let pos = 0;
    let seen = 0;
    while (pos < input.value.length && seen < significantBefore) {
      if (/[\d.]/.test(input.value[pos])) seen += 1;
      pos += 1;
    }
    if (significantBefore === 0) pos = input.value ? 1 : 0;
    input.setSelectionRange(pos, pos);
  });
  input.addEventListener("blur", () => refreshValueField(fields));
  fields.currency?.addEventListener("change", () => {
    refreshValueField(fields);
    updateExtractBrief(fields);
  });
}

function setBrief(key, value) {
  const el = document.querySelector(`[data-brief="${key}"]`);
  if (!el) return;
  const text = String(value || "").trim();
  el.textContent = text || "—";
  el.classList.toggle("is-empty", !text);
}

function updateExtractBrief(fields) {
  const title = fields.title?.value || "";
  const type = fields.contractType?.value || "";
  const client = fields.clientName?.value || "";
  const vendor = fields.vendorName?.value || "";
  const start = fields.startDate?.value || "";
  const end = fields.endDate?.value || "";
  const currency = fields.currency?.value || "PHP";
  const value = fields.value?.value || "";
  const payment = fields.paymentTerms?.value || "";
  const renewal = fields.renewalTerms?.value || "";
  const parties = [client, vendor].filter(Boolean).join(" / ");
  const term = start && end ? `${start} to ${end}` : start || end;
  const money = value ? formatMoney(value, currency) : "";
  setBrief("title", title);
  setBrief("type", type);
  setBrief("parties", parties);
  setBrief("term", term);
  setBrief("value", money);
  setBrief("payment", payment);
  setBrief("renewal", renewal);
}

function resetExtractBrief() {
  ["title", "type", "parties", "term", "value", "payment", "renewal"].forEach((key) => {
    setBrief(key, "");
  });
}

function normalizeTypeValue(extractedType, selectEl) {
  if (!extractedType || !selectEl) return "";
  const raw = String(extractedType).trim();
  const normalized = raw.toLowerCase();
  const opts = Array.from(selectEl.options);
  const exact = opts.find(
    (opt) => String(opt.value || opt.textContent || "").trim().toLowerCase() === normalized,
  );
  if (exact) return exact.value || exact.textContent || "";

  const candidates = opts
    .map((opt) => String(opt.value || opt.textContent || "").trim().toLowerCase())
    .map((t, i) => ({ t, opt: opts[i] }))
    .filter(({ t }) => t.length > 0 && (normalized.startsWith(t) || normalized.startsWith(`${t} `)));
  if (candidates.length) {
    candidates.sort((a, b) => b.t.length - a.t.length);
    const { opt } = candidates[0];
    return opt.value || opt.textContent || "";
  }

  return raw;
}

/** Map free-text payment line (e.g. "Net 30 after invoice") to duration options such as "30 Days". */
function normalizePaymentTerms(extracted, selectEl) {
  if (!extracted || !selectEl) return "";
  const lower = String(extracted).trim().toLowerCase();
  const opts = Array.from(selectEl.options);
  const net = lower.match(/\bnet\s*(\d{1,3})\b/) || lower.match(/\b(\d{1,3})\s*days?\b/);
  if (net) {
    const label = `${Number(net[1])} Days`;
    const opt = opts.find((o) => String(o.textContent || "").trim() === label || String(o.value || "").trim() === label);
    if (opt) return opt.value || opt.textContent || label;
    return label;
  }
  const ranked = ["15 Days", "30 Days", "60 Days", "90 Days", "Milestone-based", "Upfront", "Monthly"];
  for (const label of ranked) {
    if (lower.includes(label.toLowerCase()) || lower.includes(label.replace(" Days", "").toLowerCase())) {
      const opt = opts.find((o) => String(o.textContent || "").trim() === label);
      if (opt) return opt.value || opt.textContent || "";
    }
  }
  return normalizeTypeValue(extracted, selectEl);
}

function normalizeRenewal(extracted, selectEl) {
  if (!extracted || !selectEl) return extracted || "";
  const lower = String(extracted).trim().toLowerCase();
  const opts = Array.from(selectEl.options);
  if (lower.includes("auto") && lower.includes("6")) {
    const opt = opts.find((o) => /6 month/i.test(o.textContent || ""));
    if (opt) return opt.value || opt.textContent || "";
  }
  if (lower.includes("auto")) {
    const opt = opts.find((o) => /12 month|auto-renew/i.test(o.textContent || ""));
    if (opt) return opt.value || opt.textContent || "";
  }
  if (lower.includes("no renewal")) {
    const opt = opts.find((o) => /no renewal/i.test(o.textContent || ""));
    if (opt) return opt.value || opt.textContent || "";
  }
  if (lower.includes("manual")) {
    const opt = opts.find((o) => /manual/i.test(o.textContent || ""));
    if (opt) return opt.value || opt.textContent || "";
  }
  return normalizeTypeValue(extracted, selectEl);
}

const NLP_STAGE_LABELS = [
  "Reading the uploaded PDF…",
  "Extracting contract header…",
  "Identifying parties…",
  "Parsing scope of work…",
  "Reading financial terms…",
  "Mapping renewal and signatures…",
];

function startNlpLoader() {
  const overlay = document.getElementById("nlp-extract-overlay");
  const dropZone = document.getElementById("drop-zone");
  const status = document.getElementById("nlp-extract-status");
  const started = performance.now();
  let stage = 0;
  let timer = null;

  if (status) status.textContent = NLP_STAGE_LABELS[0];
  if (overlay) {
    overlay.classList.remove("is-error", "is-finishing");
    overlay.classList.add("is-open");
    overlay.setAttribute("aria-hidden", "false");
  }
  dropZone?.classList.add("is-extracting");
  timer = window.setInterval(() => {
    if (stage < NLP_STAGE_LABELS.length - 1) {
      stage += 1;
      if (status) status.textContent = NLP_STAGE_LABELS[stage];
    }
  }, 780);

  const finish = async (ok) => {
    if (timer) window.clearInterval(timer);
    if (overlay) overlay.classList.toggle("is-error", !ok);
    if (overlay) overlay.classList.add("is-finishing");
    if (status) {
      status.textContent = ok
        ? "Clauses mapped to the template"
        : "Extraction could not finish";
    }
    const elapsed = performance.now() - started;
    const wait = Math.max(280, 900 - elapsed);
    await new Promise((resolve) => window.setTimeout(resolve, wait));
    if (overlay) {
      overlay.classList.remove("is-open", "is-error", "is-finishing");
      overlay.setAttribute("aria-hidden", "true");
    }
    dropZone?.classList.remove("is-extracting");
  };

  return { finish };
}

function applyExtracted(fields, extracted, contractTextInput) {
  if (!extracted) return;
  if (extracted.extracted && typeof extracted.extracted === "object") {
    extracted = extracted.extracted;
  }
  if (contractTextInput && extracted.contract_text) {
    contractTextInput.value = String(extracted.contract_text).slice(0, 50000);
  }
  if (fields.title && extracted.contract_title) fields.title.value = extracted.contract_title;
  if (fields.contractType && extracted.contract_type) {
    fields.contractType.value = normalizeTypeValue(extracted.contract_type, fields.contractType);
  }
  if (fields.value && extracted.contract_value != null && extracted.contract_value !== "") {
    fields.value.value = String(extracted.contract_value);
  }
  if (fields.startDate && extracted.start_date) fields.startDate.value = extracted.start_date;
  if (fields.endDate && extracted.end_date) fields.endDate.value = extracted.end_date;
  if (fields.paymentTerms && extracted.payment_terms) {
    fields.paymentTerms.value = normalizePaymentTerms(extracted.payment_terms, fields.paymentTerms);
  }
  if (fields.renewalTerms && extracted.renewal_terms) {
    fields.renewalTerms.value = normalizeRenewal(extracted.renewal_terms, fields.renewalTerms);
  }
  if (fields.penalty && extracted.penalty_clause) fields.penalty.value = extracted.penalty_clause;
  if (fields.scope && extracted.scope) fields.scope.value = extracted.scope;
  if (fields.clientName && extracted.client_name) fields.clientName.value = extracted.client_name;
  if (fields.vendorName && extracted.vendor_name) fields.vendorName.value = extracted.vendor_name;
  if (fields.vendorAddress && extracted.vendor_address) fields.vendorAddress.value = extracted.vendor_address;
  if (fields.currency && extracted.currency) {
    fields.currency.value = normalizeCurrency(extracted.currency, fields.currency);
  }
  if (fields.termination && extracted.termination_clause) fields.termination.value = extracted.termination_clause;
  if (fields.clientSignatory && extracted.client_signatory) fields.clientSignatory.value = extracted.client_signatory;
  if (fields.vendorSignatory && extracted.vendor_signatory) fields.vendorSignatory.value = extracted.vendor_signatory;
  if (fields.signedDate && extracted.signed_date) fields.signedDate.value = extracted.signed_date;
  refreshValueField(fields);
  updateExtractBrief(fields);
}

document.addEventListener("DOMContentLoaded", () => {
  const user = getSessionUser();
  const fields = getFieldElements();
  const contractTextInput = document.getElementById("contractTextInput");
  const submitBtn = document.getElementById("submitContractBtn");
  bindValueFormatting(fields);

  const requireLogin = () => {
    alert("Please log in as a vendor (session missing). Open the login page and sign in again.");
  };

  if (user?.id) {
    setApiContext({ role: user.role || "vendor", userId: user.id });
    if (fields.vendorName && !fields.vendorName.value) {
      fields.vendorName.value = user.company_name || user.name || "";
    }
  } else {
    setApiContext({ role: "vendor", userId: null });
  }

  /** Last PDF chosen by the vendor — submitted with the contract so managers/CEO can open the full document. */
  let lastPdfFile = null;
  /** Extraction result for lastPdfFile; lets Submit skip a second extractor run. */
  let lastExtraction = null;

  const runNlpAutofill = async (opts = {}) => {
    const text = contractTextInput?.value.trim() || "";
    const usePdf = lastPdfFile && isPdfFile(lastPdfFile);
    if (!usePdf && !text) return;
    const showLoader = opts.showLoader !== false;
    const loader = showLoader ? startNlpLoader() : null;
    try {
      const result = usePdf
        ? await extractContractFromPdf(lastPdfFile, text)
        : await extractContractFields(text);
      const extracted = result?.extracted || result || {};
      applyExtracted(fields, extracted, contractTextInput);
      lastExtraction = { file: usePdf ? lastPdfFile : null, data: extracted };
      const filled = [
        extracted.contract_title,
        extracted.contract_type,
        extracted.contract_value,
        extracted.start_date,
        extracted.end_date,
        extracted.client_name,
        extracted.vendor_name,
      ].filter((v) => v !== null && v !== undefined && String(v).trim() !== "");
      if (loader) await loader.finish(filled.length > 0);
      if (filled.length === 0) {
        console.warn("NLP returned no fields", extracted);
        const status = document.getElementById("nlp-extract-status");
        if (status) {
          status.textContent = "No clauses could be read from this PDF. Try another file or fill in the fields below.";
        }
      }
    } catch (error) {
      if (loader) await loader.finish(false);
      throw error;
    }
  };

  /** Increments per chosen file so a slow duplicate check for an older file is ignored. */
  let fileSelectionToken = 0;
  /** Object URL of lastPdfFile for the PDF viewer modal. */
  let pdfUrl = null;
  let isSubmitted = false;
  const submitBtnDefaultHtml = submitBtn?.innerHTML || "";
  const lockableInputs = () => [...Object.values(fields), contractTextInput].filter(Boolean);

  const UPLOAD_VIEW_TEXT = {
    drop: ["UPLOAD DOCUMENT", "PDF only, max 25MB — labeled template files extract cleanly"],
    ready: ["UPLOADED DOCUMENT", "Review the extracted fields below, then submit"],
    submitted: ["SUBMITTED DOCUMENT", "This contract was sent for manager review"],
  };

  /** "drop" shows the upload box; "ready" and "submitted" replace it with the document ticket. */
  const setUploadView = (view) => {
    const hasFile = view !== "drop";
    const dropZone = document.getElementById("drop-zone");
    const ticket = document.getElementById("doc-ticket");
    const aiInfo = document.getElementById("upload-ai-info");
    if (dropZone) dropZone.style.display = hasFile ? "none" : "block";
    if (ticket) {
      ticket.style.display = hasFile ? "grid" : "none";
      if (hasFile) ticket.dataset.state = view;
    }
    if (aiInfo) aiInfo.style.display = hasFile ? "none" : "flex";
    const [kicker, hint] = UPLOAD_VIEW_TEXT[view] || UPLOAD_VIEW_TEXT.drop;
    const kickerEl = document.getElementById("upload-card-kicker");
    const hintEl = document.getElementById("upload-card-hint");
    if (kickerEl) kickerEl.textContent = kicker;
    if (hintEl) hintEl.textContent = hint;
  };

  const formatBytes = (bytes) => {
    const n = Number(bytes) || 0;
    if (n >= 1024 * 1024) return `${(n / (1024 * 1024)).toFixed(1)} MB`;
    return `${Math.max(1, Math.round(n / 1024))} KB`;
  };

  const renderTicket = (file, statusText, verb) => {
    const time = new Date().toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });
    const nameEl = document.getElementById("doc-ticket-name");
    const metaEl = document.getElementById("doc-ticket-meta");
    const statusEl = document.getElementById("doc-ticket-status-text");
    if (nameEl) {
      nameEl.textContent = file?.name || "contract.pdf";
      nameEl.title = file?.name || "";
    }
    if (metaEl) metaEl.textContent = `${formatBytes(file?.size)} · ${verb} ${time}`;
    if (statusEl) statusEl.textContent = statusText;
  };

  const pdfFrame = document.getElementById("pdf-modal-frame");
  const closePdfViewer = () => {
    if (typeof window.closeModal === "function") window.closeModal("modal-pdf");
    pdfFrame?.removeAttribute("src");
  };
  const openPdfViewer = () => {
    if (!pdfUrl) return;
    const nameEl = document.getElementById("pdf-modal-name");
    const openLink = document.getElementById("pdf-modal-open");
    if (nameEl) nameEl.textContent = lastPdfFile?.name || "contract.pdf";
    if (openLink) openLink.href = pdfUrl;
    if (pdfFrame) pdfFrame.src = pdfUrl;
    if (typeof window.openModal === "function") window.openModal("modal-pdf");
  };
  document.getElementById("show-pdf-btn")?.addEventListener("click", openPdfViewer);
  document.getElementById("pdf-modal-close")?.addEventListener("click", closePdfViewer);
  document.getElementById("modal-pdf")?.addEventListener("click", (event) => {
    if (event.target === event.currentTarget) closePdfViewer();
  });
  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && document.getElementById("modal-pdf")?.classList.contains("open")) {
      closePdfViewer();
    }
  });

  const originalClearFile = window.clearFile;
  const clearSelectedFile = () => {
    fileSelectionToken += 1;
    if (typeof originalClearFile === "function") originalClearFile();
    lastPdfFile = null;
    lastExtraction = null;
    if (contractTextInput) contractTextInput.value = "";
    resetExtractBrief();
    closePdfViewer();
    if (pdfUrl) {
      URL.revokeObjectURL(pdfUrl);
      pdfUrl = null;
    }
    setUploadView("drop");
  };
  window.clearFile = () => {
    clearSelectedFile();
    hideAlerts();
  };

  const originalShowFile = window.showFile;
  window.showFile = async (file) => {
    if (!isPdfFile(file)) {
      alert("Only PDF files are accepted. Word documents (.doc, .docx) are not allowed.");
      lastPdfFile = null;
      document.getElementById("file-input") && (document.getElementById("file-input").value = "");
      return;
    }
    hideAlerts();
    const token = ++fileSelectionToken;
    if (typeof originalShowFile === "function") {
      originalShowFile(file);
    }
    lastPdfFile = file;
    lastExtraction = null;

    if (user?.id) {
      try {
        const hash = await sha256Hex(file);
        const check = hash ? await checkDuplicatePdf(hash, file.size) : null;
        if (token !== fileSelectionToken) return;
        if (check?.duplicate) {
          clearSelectedFile();
          showAlert("upload-alert", check.error || "This PDF has already been submitted. Make a change to the contract before submitting it again.");
          return;
        }
      } catch (error) {
        if (token !== fileSelectionToken) return;
        if (isSessionExpired(error)) {
          clearSelectedFile();
          showAlert("upload-alert", SESSION_EXPIRED_MESSAGE, "SIGN IN REQUIRED");
          return;
        }
        // The submit endpoint repeats this check, so a failed pre-check only delays the block.
        console.warn("Duplicate pre-check failed", error);
      }
    }
    if (token !== fileSelectionToken) return;

    try {
      await runNlpAutofill();
    } catch (error) {
      console.error("PDF NLP extraction failed", error);
      if (token !== fileSelectionToken) return;
      if (isSessionExpired(error)) {
        clearSelectedFile();
        showAlert("upload-alert", SESSION_EXPIRED_MESSAGE, "SIGN IN REQUIRED");
        return;
      }
      showAlert(
        "upload-alert",
        `${describeRequestError(error)} You can also fill in the fields below by hand.`,
        "EXTRACTION FAILED",
      );
    }
    if (token !== fileSelectionToken) return;
    if (pdfUrl) URL.revokeObjectURL(pdfUrl);
    pdfUrl = URL.createObjectURL(file);
    renderTicket(file, "Uploaded · Ready to submit", "Uploaded");
    setUploadView("ready");
  };

  window.handleFile = async (input) => {
    const file = input?.files?.[0];
    if (!file) return;
    if (!isPdfFile(file)) {
      alert("Only PDF files are accepted. Word documents (.doc, .docx) are not allowed.");
      if (input) input.value = "";
      lastPdfFile = null;
      return;
    }
    if (typeof window.showFile === "function") {
      await window.showFile(file);
    }
  };

  const enterSubmittedState = (file, row) => {
    isSubmitted = true;
    if (!pdfUrl) pdfUrl = URL.createObjectURL(file);
    const code = String(row?.contract_code || "").trim();
    renderTicket(
      file,
      code ? `Submitted as ${code} · Awaiting manager review` : "Submitted · Awaiting manager review",
      "Submitted",
    );
    setUploadView("submitted");
    lockableInputs().forEach((el) => { el.disabled = true; });
    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = "Submitted";
    }
  };

  window.resetUploadForm = () => {
    isSubmitted = false;
    clearSelectedFile();
    hideAlerts();
    lockableInputs().forEach((el) => {
      el.disabled = false;
      el.value = "";
    });
    if (fields.currency) fields.currency.value = "PHP";
    if (fields.vendorName && user) fields.vendorName.value = user.company_name || user.name || "";
    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.innerHTML = submitBtnDefaultHtml;
    }
    window.scrollTo({ top: 0, behavior: "smooth" });
  };

  document.getElementById("new-contract-btn")?.addEventListener("click", () => {
    if (!isSubmitted && lastPdfFile && !window.confirm("Discard this contract and start a new one?")) return;
    window.resetUploadForm();
  });

  submitBtn?.addEventListener("click", async (event) => {
    event.preventDefault();
    if (isSubmitted) return;
    if (!user?.id) {
      requireLogin();
      return;
    }
    hideAlerts();
    // Only extract when this PDF has not been read yet; re-running would also overwrite the vendor's edits.
    const alreadyExtracted = lastExtraction && lastExtraction.file === lastPdfFile;
    if (!alreadyExtracted) {
      try {
        await runNlpAutofill({ showLoader: false });
      } catch (error) {
        console.error("Submit NLP extraction failed", error);
      }
    }

    const title = fields.title?.value.trim() || "";
    const ctype = String(fields.contractType?.value || "").trim();
    const val = parseValue(fields.value?.value);
    const endD = fields.endDate?.value?.trim() || "";
    const missing = [];
    if (!title) missing.push("Contract title");
    if (!ctype) missing.push("Contract type");
    if (!val || val <= 0) missing.push("Contract value");
    if (!endD) missing.push("End date");
    const usePdf = lastPdfFile && isPdfFile(lastPdfFile);
    if (!usePdf) missing.push("Contract PDF (PDF only)");
    if (missing.length) {
      alert(
        `Cannot submit yet. Fill these fields or add contract text so NLP can extract them:\n\n• ${missing.join("\n• ")}`,
      );
      return;
    }

    submitBtn.disabled = true;
    let submittedRow = null;
    try {
      const fileName = lastPdfFile?.name || "";
      const commonFields = {
        contract_title: title,
        contract_type: ctype,
        contract_value: String(val),
        start_date: fields.startDate?.value || "",
        end_date: endD,
        payment_terms: fields.paymentTerms?.value || "",
        renewal_terms: fields.renewalTerms?.value || "",
        penalty_clause: fields.penalty?.value || "",
        scope: fields.scope?.value || "",
        contract_text: contractTextInput?.value || "",
        source_file_name: fileName,
        vendor_name: fields.vendorName?.value || user.company_name || user.name || "",
        client_name: fields.clientName?.value || "",
        vendor_address: fields.vendorAddress?.value || "",
        currency: fields.currency?.value || "PHP",
        termination_clause: fields.termination?.value || "",
        client_signatory: fields.clientSignatory?.value || "",
        vendor_signatory: fields.vendorSignatory?.value || "",
        signed_date: fields.signedDate?.value || "",
      };
      const extractedForFile = lastExtraction && lastExtraction.file === lastPdfFile ? lastExtraction.data : null;
      if (extractedForFile?.engine) {
        commonFields.nlp_engine = String(extractedForFile.engine);
        commonFields.financial_obligations = extractedForFile.financial_obligations || "";
        commonFields.classification = extractedForFile.classification || "";
      }

      const usePdfUpload = lastPdfFile && isPdfFile(lastPdfFile);

      if (usePdfUpload) {
        const fd = new FormData();
        Object.entries(commonFields).forEach(([k, v]) => fd.append(k, v));
        fd.append("contract_pdf", lastPdfFile, lastPdfFile.name);
        submittedRow = await submitContractFormData(fd);
      } else {
        alert("A PDF file is required. Word documents are not accepted.");
        return;
      }
    } catch (error) {
      const isDuplicate = error?.status === 409 || error?.data?.duplicate === true;
      const label = isDuplicate ? "DUPLICATE CONTRACT BLOCKED" : isSessionExpired(error) ? "SIGN IN REQUIRED" : "SUBMISSION FAILED";
      showAlert("submit-alert", isDuplicate ? error.message : describeRequestError(error), label);
      return;
    } finally {
      if (!isSubmitted) submitBtn.disabled = false;
    }
    enterSubmittedState(lastPdfFile, submittedRow);
    if (typeof window.openModal === "function") {
      window.openModal("modal-success");
    } else {
      window.location.href = "/pages/VendorClient/VendorContracts.html";
    }
  });
});
