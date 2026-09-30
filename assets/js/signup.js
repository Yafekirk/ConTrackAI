import { acceptTerms } from "/assets/js/api-client.js";
import { initTermsLinks } from "/assets/js/terms-modal.js";

const ROLE_TO_ROUTE = {
  vendor: "/pages/VendorClient/VendorDashboard.html",
};

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
  if (password.length < 8 || password.length > 64) return "Password must be 8–64 characters.";
  if (!/[A-Za-z]/.test(password) || !/[0-9]/.test(password)) {
    return "Password must include at least one letter and one number.";
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
    throw new Error(data.error || "Signup failed");
  }
  return data;
}

document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("signupForm");
  const errorEl = document.getElementById("signupError");
  if (!form || !errorEl) return;

  initTermsLinks();

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    errorEl.style.display = "none";
    errorEl.textContent = "";
    clearFieldErrors();

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

    try {
      const result = await signup({
        name,
        company_name: name,
        email,
        contact_number: contactNumber,
        supplier_type: supplierType,
        password,
        accepted_terms: true,
      });
      const user = result.user || {};

      // Fallback for older schemas that could not store terms on insert.
      try {
        await acceptTerms();
      } catch (_) {
        /* signup already required the checkbox; portal can still open */
      }

      localStorage.setItem(
        "contrack_user",
        JSON.stringify({
          id: user.id ?? null,
          role: user.role || "vendor",
          name: user.company_name || user.name || name,
          company_name: user.company_name || name,
          email: user.email || "",
        }),
      );
      window.location.href = ROLE_TO_ROUTE.vendor;
    } catch (error) {
      errorEl.textContent = error.message;
      errorEl.style.display = "block";
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
