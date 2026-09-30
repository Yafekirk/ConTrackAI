import {
  setApiContext,
  extractContractFields,
  extractContractFromPdf,
  submitContractFormData,
} from "/assets/js/api-client.js";

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
  const money = value ? `${currency} ${value}` : "";
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
  const extractBtn = document.getElementById("extractContractBtn");
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
  if (extractBtn) extractBtn.disabled = true;
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
    if (extractBtn) extractBtn.disabled = false;
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
  updateExtractBrief(fields);
}

document.addEventListener("DOMContentLoaded", () => {
  const user = getSessionUser();
  const fields = getFieldElements();
  const extractBtn = document.getElementById("extractContractBtn");
  const contractTextInput = document.getElementById("contractTextInput");
  const submitBtn = document.getElementById("submitContractBtn");

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
          status.textContent = "No clauses could be read from this PDF. Try another file or paste the contract text.";
        }
      }
    } catch (error) {
      if (loader) await loader.finish(false);
      throw error;
    }
  };

  extractBtn?.addEventListener("click", async () => {
    try {
      await runNlpAutofill();
    } catch (error) {
      console.error("NLP extraction failed", error);
    }
  });

  const originalShowFile = window.showFile;
  window.showFile = async (file) => {
    if (!isPdfFile(file)) {
      alert("Only PDF files are accepted. Word documents (.doc, .docx) are not allowed.");
      lastPdfFile = null;
      document.getElementById("file-input") && (document.getElementById("file-input").value = "");
      return;
    }
    if (typeof originalShowFile === "function") {
      originalShowFile(file);
    }
    lastPdfFile = file && isPdfFile(file) ? file : null;
    if (!file || !isPdfFile(file)) return;
    try {
      await runNlpAutofill();
    } catch (error) {
      console.error("PDF NLP extraction failed", error);
    }
  };

  const originalClearFile = window.clearFile;
  window.clearFile = () => {
    if (typeof originalClearFile === "function") originalClearFile();
    lastPdfFile = null;
    if (contractTextInput) contractTextInput.value = "";
    resetExtractBrief();
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

  submitBtn?.addEventListener("click", async (event) => {
    event.preventDefault();
    if (!user?.id) {
      requireLogin();
      return;
    }
    try {
      await runNlpAutofill({ showLoader: false });
    } catch (error) {
      console.error("Submit NLP extraction failed", error);
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
    try {
      const fileName = document.getElementById("file-name")?.textContent?.trim() || "";
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
        source_file_name: fileName === "—" ? "" : fileName,
        vendor_name: fields.vendorName?.value || user.company_name || user.name || "",
        client_name: fields.clientName?.value || "",
        vendor_address: fields.vendorAddress?.value || "",
        currency: fields.currency?.value || "PHP",
        termination_clause: fields.termination?.value || "",
        client_signatory: fields.clientSignatory?.value || "",
        vendor_signatory: fields.vendorSignatory?.value || "",
        signed_date: fields.signedDate?.value || "",
      };

      const usePdfUpload = lastPdfFile && isPdfFile(lastPdfFile);

      if (usePdfUpload) {
        const fd = new FormData();
        Object.entries(commonFields).forEach(([k, v]) => fd.append(k, v));
        fd.append("contract_pdf", lastPdfFile, lastPdfFile.name);
        await submitContractFormData(fd);
      } else {
        alert("A PDF file is required. Word documents are not accepted.");
        return;
      }
      if (typeof window.openModal === "function") {
        window.openModal("modal-success");
      } else {
        window.location.href = "/pages/VendorClient/VendorContracts.html";
      }
    } catch (error) {
      const msg = error?.message || "Failed to submit contract";
      alert(msg);
    } finally {
      submitBtn.disabled = false;
    }
  });
});
