import { initTermsLinks } from "/assets/js/terms-modal.js";

const LOGIN_ROUTE = "/index.html";

const PH_MOBILE_RE = /^(09[0-9]{9}|\+639[0-9]{9}|639[0-9]{9})$/;
const SUPPLIER_TYPES = ["Supply", "Lease", "Service", "Other"];

function normalizeContact(value) {
  return String(value || "").trim().replace(/\s+/g, "");
}

function setFieldError(id, message) {
  const el = document.getElementById(id);
  if (!el) return;
  if (message) {
    el.textContent = message;
    el.hidden = false;
  } else {
    el.textContent = "";
    el.hidden = true;
  }
}

function clearFieldErrors() {
  [
    "signupNameError",
    "signupEmailError",
    "signupContactError",
    "signupSupplierError",
    "signupPasswordError",
    "signupConfirmError",
    "signupTermsError",
  ].forEach((id) => setFieldError(id, ""));
}

function validateName(name) {
  const trimmed = name.trim();
  if (!trimmed) return "Company name is required.";
  if (trimmed.length < 2 || trimmed.length > 120) return "Company name must be 2–120 characters.";
  if (/[\u0000-\u001F\u007F]/.test(trimmed)) return "Company name cannot include line breaks.";
  return "";
}

function validateEmail(email) {
  const trimmed = email.trim().toLowerCase();
  if (!trimmed) return "Email is required.";
  if (trimmed.length > 254) return "Email must be 254 characters or fewer.";
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(trimmed) || !trimmed.includes("@")) {
    return "Enter a valid email address.";
  }
  return "";
}

function validateContact(raw) {
  const contact = normalizeContact(raw);
  if (!contact) return "Contact number is required.";
  if (/[A-Za-z]/.test(contact) || !/^\+?[0-9]+$/.test(contact)) {
    return "Contact number may contain digits only, with an optional leading +.";
  }
  if (/^\+?0+$/.test(contact)) return "Contact number cannot be all zeros.";
  if (!PH_MOBILE_RE.test(contact)) {
    return "Enter a Philippine mobile number (09XXXXXXXXX, 639XXXXXXXXX, or +639XXXXXXXXX).";
  }
  return "";
}

function validateSupplier(value) {
  if (!value || value === "Select supplier type") return "Select a supplier type.";
  if (!SUPPLIER_TYPES.includes(value)) return "Supplier type must be Supply, Lease, Service, or Other.";
  return "";
}

function validatePassword(password) {
  if (!password) return "Password is required.";
  if (password.length < 8 || password.length > 24) return "Password must be 8–24 characters.";
  if (!/[0-9]/.test(password) || !/[A-Z]/.test(password) || !/[^A-Za-z0-9]/.test(password)) {
    return "Password must include at least 1 number, 1 uppercase letter, and 1 special character.";
  }
  return "";
}

async function signup(payload) {
  const response = await fetch("/api/signup.php", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload),
  });
  const raw = await response.text();
  let data = {};
  try {
    data = JSON.parse(raw);
  } catch (error) {
    throw new Error("Server returned invalid response.");
  }
  if (!response.ok) {
    const error = new Error(data.error || "Signup failed");
    error.status = response.status;
    throw error;
  }
  return data;
}

/** Emails a 6-digit code to prove the vendor owns the address; the account is only created once it comes back. */
function sendVerificationCode(email) {
  return signup({ action: "send_code", email });
}

document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("signupForm");
  const errorEl = document.getElementById("signupError");
  if (!form || !errorEl) return;

  initTermsLinks();

  const codeGroup = document.getElementById("signupCodeGroup");
  const codeInput = document.getElementById("signupCode");
  const codeHint = document.getElementById("signupCodeHint");
  const resendBtn = document.getElementById("signupResend");
  const submitBtn = document.getElementById("signupSubmit");
  const submitLabel = submitBtn?.textContent || "SIGN UP →";
  let codeSentTo = "";
  let busy = false;
  let resendTimer = null;

  const showError = (message) => {
    errorEl.textContent = message;
    errorEl.style.display = message ? "block" : "none";
  };

  const startResendCountdown = (seconds) => {
    if (!resendBtn) return;
    window.clearInterval(resendTimer);
    let left = Math.max(0, Number(seconds) || 0);
    const paint = () => {
      resendBtn.disabled = left > 0;
      resendBtn.textContent = left > 0 ? `Resend code (${left}s)` : "Resend code";
    };
    paint();
    resendTimer = window.setInterval(() => {
      left -= 1;
      paint();
      if (left <= 0) window.clearInterval(resendTimer);
    }, 1000);
  };

  const resetVerification = () => {
    codeSentTo = "";
    if (codeGroup) codeGroup.hidden = true;
    if (codeInput) codeInput.value = "";
    setFieldError("signupCodeError", "");
    if (submitBtn) submitBtn.textContent = submitLabel;
    window.clearInterval(resendTimer);
  };

  const requestCode = async (email) => {
    const result = await sendVerificationCode(email);
    codeSentTo = email;
    if (codeGroup) codeGroup.hidden = false;
    if (codeHint) codeHint.textContent = `We sent a 6-digit code to ${email}. Check your inbox (and spam folder), then enter it here.`;
    if (submitBtn) submitBtn.textContent = "VERIFY & SIGN UP →";
    startResendCountdown(result?.resend_after ?? 60);
    codeInput?.focus();
  };

  document.getElementById("signupEmail")?.addEventListener("input", () => {
    const current = (document.getElementById("signupEmail")?.value.trim() || "").toLowerCase();
    if (codeSentTo && current !== codeSentTo) resetVerification();
  });

  codeInput?.addEventListener("input", () => {
    const digits = codeInput.value.replace(/\D/g, "").slice(0, 6);
    if (digits !== codeInput.value) codeInput.value = digits;
  });

  resendBtn?.addEventListener("click", async () => {
    if (!codeSentTo || busy) return;
    busy = true;
    showError("");
    setFieldError("signupCodeError", "");
    try {
      await requestCode(codeSentTo);
    } catch (error) {
      setFieldError("signupCodeError", error.message);
    } finally {
      busy = false;
    }
  });

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (busy) return;
    errorEl.style.display = "none";
    errorEl.textContent = "";
    clearFieldErrors();
    setFieldError("signupCodeError", "");

    const name = document.getElementById("signupName")?.value.trim() || "";
    const email = (document.getElementById("signupEmail")?.value.trim() || "").toLowerCase();
    const contactNumber = normalizeContact(document.getElementById("signupContact")?.value || "");
    const supplierType = document.getElementById("signupSupplierType")?.value || "";
    const password = document.getElementById("signupPassword")?.value || "";
    const confirmPassword = document.getElementById("signupConfirmPassword")?.value || "";
    const acceptedTerms = Boolean(document.getElementById("signupTerms")?.checked);

    const nameErr = validateName(name);
    const emailErr = validateEmail(email);
    const contactErr = validateContact(contactNumber);
    const supplierErr = validateSupplier(supplierType);
    const passwordErr = validatePassword(password);
    const confirmErr = !confirmPassword
      ? "Confirm password is required."
      : password !== confirmPassword
        ? "Passwords do not match."
        : "";
    const termsErr = acceptedTerms ? "" : "You must agree to the terms to continue.";

    setFieldError("signupNameError", nameErr);
    setFieldError("signupEmailError", emailErr);
    setFieldError("signupContactError", contactErr);
    setFieldError("signupSupplierError", supplierErr);
    setFieldError("signupPasswordError", passwordErr);
    setFieldError("signupConfirmError", confirmErr);
    setFieldError("signupTermsError", termsErr);

    // Do not create an account until the Terms checkbox is checked.
    if (nameErr || emailErr || contactErr || supplierErr || passwordErr || confirmErr || termsErr) {
      return;
    }

    busy = true;
    try {
      // First press: prove the email address is real by sending it a code.
      if (codeSentTo !== email) {
        try {
          await requestCode(email);
        } catch (error) {
          setFieldError("signupEmailError", error.message);
        }
        return;
      }

      const code = (codeInput?.value || "").replace(/\D/g, "");
      if (code.length !== 6) {
        setFieldError("signupCodeError", "Enter the 6-digit code we emailed you.");
        codeInput?.focus();
        return;
      }

      await signup({
        name,
        company_name: name,
        email,
        contact_number: contactNumber,
        supplier_type: supplierType,
        password,
        confirm_password: confirmPassword,
        accepted_terms: true,
        verification_code: code,
      });
      localStorage.removeItem("contrack_user");
      sessionStorage.setItem("contrack_signup_pending", "1");
      window.location.href = LOGIN_ROUTE;
    } catch (error) {
      if (error.status === 422 || error.status === 429) {
        setFieldError("signupCodeError", error.message);
        codeInput?.focus();
      } else {
        showError(error.message);
      }
    } finally {
      busy = false;
    }
  });

  const nameInput = document.getElementById("signupName");
  const contactInput = document.getElementById("signupContact");

  contactInput?.addEventListener("beforeinput", (event) => {
    if (event.data && /[A-Za-z]/.test(event.data)) event.preventDefault();
  });
  contactInput?.addEventListener("input", () => {
    const cleaned = contactInput.value.replace(/[A-Za-z]/g, "");
    if (cleaned !== contactInput.value) contactInput.value = cleaned;
  });
  contactInput?.addEventListener("paste", (event) => {
    event.preventDefault();
    const text = (event.clipboardData || window.clipboardData)?.getData("text") || "";
    const cleaned = text.replace(/[A-Za-z]/g, "");
    const start = contactInput.selectionStart ?? contactInput.value.length;
    const end = contactInput.selectionEnd ?? contactInput.value.length;
    contactInput.value = contactInput.value.slice(0, start) + cleaned + contactInput.value.slice(end);
    const caret = start + cleaned.length;
    contactInput.setSelectionRange(caret, caret);
  });

  nameInput?.addEventListener("blur", () => {
    setFieldError("signupNameError", validateName(nameInput.value || ""));
  });
  document.getElementById("signupEmail")?.addEventListener("blur", () => {
    setFieldError("signupEmailError", validateEmail(document.getElementById("signupEmail")?.value || ""));
  });
  contactInput?.addEventListener("blur", () => {
    setFieldError("signupContactError", validateContact(contactInput.value || ""));
  });
  document.getElementById("signupSupplierType")?.addEventListener("change", () => {
    setFieldError("signupSupplierError", validateSupplier(document.getElementById("signupSupplierType")?.value || ""));
  });
  document.getElementById("signupPassword")?.addEventListener("blur", () => {
    setFieldError("signupPasswordError", validatePassword(document.getElementById("signupPassword")?.value || ""));
  });
  document.getElementById("signupConfirmPassword")?.addEventListener("blur", () => {
    const password = document.getElementById("signupPassword")?.value || "";
    const confirmPassword = document.getElementById("signupConfirmPassword")?.value || "";
    const confirmErr = !confirmPassword
      ? "Confirm password is required."
      : password !== confirmPassword
        ? "Passwords do not match."
        : "";
    setFieldError("signupConfirmError", confirmErr);
  });
});
